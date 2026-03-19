<?php
session_start();
$currentPage = 'investment-reports';
$pageTitle = 'Investment Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$export = isset($_GET['export']);

// Set date range based on selection
if ($date_range != 'custom') {
    switch ($date_range) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to = date('Y-m-d');
            break;
        case 'yesterday':
            $date_from = date('Y-m-d', strtotime('-1 day'));
            $date_to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to = date('Y-m-d');
            break;
        case 'last_week':
            $date_from = date('Y-m-d', strtotime('monday last week'));
            $date_to = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('first day of last month'));
            $date_to = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $quarter = ceil(date('n') / 3);
            $date_from = date('Y-m-01', strtotime('first day of January ' . date('Y') . ' + ' . (($quarter - 1) * 3) . ' months'));
            $date_to = date('Y-m-d');
            break;
        case 'last_quarter':
            $quarter = ceil(date('n') / 3) - 1;
            $year = date('Y');
            if ($quarter == 0) {
                $quarter = 4;
                $year = $year - 1;
            }
            $date_from = date('Y-m-01', strtotime('first day of January ' . $year . ' + ' . (($quarter - 1) * 3) . ' months'));
            $date_to = date('Y-m-t', strtotime('last day of ' . $date_from . ' + 2 months'));
            break;
        case 'this_year':
            $date_from = date('Y-01-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_year':
            $date_from = date('Y-01-01', strtotime('-1 year'));
            $date_to = date('Y-12-31', strtotime('-1 year'));
            break;
    }
}

// Build WHERE conditions based on filters
$where_conditions = ["1=1"];

if ($status != 'all') {
    $where_conditions[] = "i.status = " . ($status == 'active' ? 1 : 0);
}

if ($report_type != 'summary' && $report_type != 'returns') {
    $where_conditions[] = "DATE(i.investment_date) BETWEEN '$date_from' AND '$date_to'";
}

$where_clause = implode(' AND ', $where_conditions);

// ==================== SUMMARY STATISTICS ====================

// Overall investment summary
$summary_query = "SELECT 
                    COUNT(*) as total_investments,
                    COUNT(CASE WHEN status = 1 THEN 1 END) as active_investments,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as closed_investments,
                    COALESCE(SUM(investment_amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN status = 1 THEN investment_amount ELSE 0 END), 0) as active_amount,
                    COALESCE(SUM(CASE WHEN status = 0 THEN investment_amount ELSE 0 END), 0) as closed_amount,
                    COALESCE(AVG(investment_amount), 0) as avg_investment,
                    COALESCE(SUM(interest), 0) as total_interest,
                    COALESCE(AVG(interest), 0) as avg_interest_rate
                  FROM investments i
                  WHERE $where_clause";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

// Returns summary
$returns_summary_query = "SELECT 
                            COUNT(*) as total_returns,
                            COALESCE(SUM(payable_investment), 0) as total_principal_returned,
                            COALESCE(SUM(payable_interest), 0) as total_interest_paid,
                            COALESCE(SUM(payable_investment + payable_interest), 0) as total_amount_paid,
                            COALESCE(AVG(total_days), 0) as avg_days_held
                          FROM investment_returns
                          WHERE DATE(return_date) BETWEEN '$date_from' AND '$date_to'";
$returns_summary_result = mysqli_query($conn, $returns_summary_query);
$returns_summary = mysqli_fetch_assoc($returns_summary_result);

// ==================== MONTHLY TREND ====================

$monthly_query = "SELECT 
                    DATE_FORMAT(investment_date, '%Y-%m') as month,
                    COUNT(*) as investment_count,
                    COALESCE(SUM(investment_amount), 0) as total_amount,
                    COALESCE(AVG(investment_amount), 0) as avg_amount,
                    COALESCE(SUM(interest), 0) as total_interest
                  FROM investments
                  WHERE investment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                  GROUP BY DATE_FORMAT(investment_date, '%Y-%m')
                  ORDER BY month DESC";
$monthly_result = mysqli_query($conn, $monthly_query);

$monthly_data = [];
$months = [];
$monthly_counts = [];
$monthly_amounts = [];
$monthly_interests = [];

while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_data[] = $row;
    $months[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthly_counts[] = $row['investment_count'];
    $monthly_amounts[] = $row['total_amount'];
    $monthly_interests[] = $row['total_interest'];
}

// ==================== INVESTMENT TYPES BREAKDOWN ====================

$type_query = "SELECT 
                investment_type,
                COUNT(*) as count,
                COALESCE(SUM(investment_amount), 0) as total_amount,
                COALESCE(AVG(investment_amount), 0) as avg_amount,
                COALESCE(SUM(interest), 0) as total_interest,
                COUNT(CASE WHEN status = 1 THEN 1 END) as active_count,
                COUNT(CASE WHEN status = 0 THEN 1 END) as closed_count
              FROM investments
              WHERE $where_clause
              GROUP BY investment_type
              ORDER BY total_amount DESC";
$type_result = mysqli_query($conn, $type_query);

$type_data = [];
$type_labels = [];
$type_amounts = [];

while ($row = mysqli_fetch_assoc($type_result)) {
    $type_data[] = $row;
    $type_labels[] = $row['investment_type'];
    $type_amounts[] = $row['total_amount'];
}

// ==================== TOP INVESTORS ====================

$investor_query = "SELECT 
                    investor_name,
                    COUNT(*) as investment_count,
                    COALESCE(SUM(investment_amount), 0) as total_invested,
                    COALESCE(AVG(investment_amount), 0) as avg_investment,
                    COUNT(CASE WHEN status = 1 THEN 1 END) as active_count,
                    COUNT(CASE WHEN status = 0 THEN 1 END) as closed_count,
                    (SELECT COALESCE(SUM(payable_interest), 0) 
                     FROM investment_returns r 
                     WHERE r.investor_name = i.investor_name) as total_interest_earned
                  FROM investments i
                  WHERE $where_clause
                  GROUP BY investor_name
                  ORDER BY total_invested DESC
                  LIMIT 10";
$investor_result = mysqli_query($conn, $investor_query);

// ==================== RETURNS ANALYSIS ====================

$returns_analysis_query = "SELECT 
                            DATE_FORMAT(return_date, '%Y-%m') as month,
                            COUNT(*) as return_count,
                            COALESCE(SUM(payable_investment), 0) as principal_returned,
                            COALESCE(SUM(payable_interest), 0) as interest_paid,
                            COALESCE(SUM(payable_investment + payable_interest), 0) as total_paid,
                            COALESCE(AVG(total_days), 0) as avg_days_held
                          FROM investment_returns
                          WHERE return_date BETWEEN '$date_from' AND '$date_to'
                          GROUP BY DATE_FORMAT(return_date, '%Y-%m')
                          ORDER BY month DESC";
$returns_analysis_result = mysqli_query($conn, $returns_analysis_query);

// ==================== DETAILED INVESTMENTS LIST ====================

$detailed_query = "SELECT 
                    i.*,
                    (SELECT COUNT(*) FROM investment_returns r WHERE r.investment_no = i.investment_no) as return_count,
                    (SELECT COALESCE(SUM(payable_interest), 0) FROM investment_returns r WHERE r.investment_no = i.investment_no) as interest_earned
                  FROM investments i
                  WHERE $where_clause
                  ORDER BY i.investment_date DESC";
$detailed_result = mysqli_query($conn, $detailed_query);

// ==================== EXPORT FUNCTION ====================

if ($export) {
    exportInvestmentReport($conn, $report_type, $date_from, $date_to);
}

function exportInvestmentReport($conn, $type, $from, $to) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="investment_report_' . $type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add report header
    fputcsv($output, ['INVESTMENT REPORT - ' . strtoupper($type)]);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, ['Generated on', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    switch ($type) {
        case 'summary':
            // Summary report
            fputcsv($output, ['SUMMARY STATISTICS']);
            fputcsv($output, ['Metric', 'Value']);
            
            $summary_query = "SELECT 
                                COUNT(*) as total,
                                SUM(investment_amount) as total_amount,
                                AVG(investment_amount) as avg_amount,
                                SUM(interest) as total_interest
                              FROM investments";
            $summary = mysqli_fetch_assoc(mysqli_query($conn, $summary_query));
            
            fputcsv($output, ['Total Investments', $summary['total']]);
            fputcsv($output, ['Total Amount', '₹' . number_format($summary['total_amount'], 2)]);
            fputcsv($output, ['Average Amount', '₹' . number_format($summary['avg_amount'], 2)]);
            fputcsv($output, ['Total Interest (Annual)', '₹' . number_format($summary['total_interest'], 2)]);
            fputcsv($output, []);
            
            // Returns summary
            $returns_query = "SELECT 
                                COUNT(*) as total,
                                SUM(payable_investment) as total_principal,
                                SUM(payable_interest) as total_interest
                              FROM investment_returns";
            $returns = mysqli_fetch_assoc(mysqli_query($conn, $returns_query));
            
            fputcsv($output, ['RETURNS SUMMARY']);
            fputcsv($output, ['Total Returns', $returns['total']]);
            fputcsv($output, ['Principal Returned', '₹' . number_format($returns['total_principal'], 2)]);
            fputcsv($output, ['Interest Paid', '₹' . number_format($returns['total_interest'], 2)]);
            fputcsv($output, ['Total Paid', '₹' . number_format($returns['total_principal'] + $returns['total_interest'], 2)]);
            break;
            
        case 'investments':
            // Detailed investments list
            fputcsv($output, ['INVESTMENT DETAILS']);
            fputcsv($output, ['ID', 'Inv No', 'Date', 'Investor', 'Type', 'Amount', 'Interest %', 'Status']);
            
            $query = "SELECT * FROM investments ORDER BY investment_date DESC";
            $result = mysqli_query($conn, $query);
            
            while ($row = mysqli_fetch_assoc($result)) {
                fputcsv($output, [
                    $row['id'],
                    $row['investment_no'],
                    $row['investment_date'],
                    $row['investor_name'],
                    $row['investment_type'],
                    $row['investment_amount'],
                    $row['interest'],
                    $row['status'] == 1 ? 'Active' : 'Closed'
                ]);
            }
            break;
            
        case 'returns':
            // Returns details
            fputcsv($output, ['RETURN DETAILS']);
            fputcsv($output, ['Receipt No', 'Return Date', 'Investor', 'Inv No', 'Principal', 'Interest', 'Total', 'Days', 'Payment Mode']);
            
            $query = "SELECT r.*, i.investor_name 
                      FROM investment_returns r
                      JOIN investments i ON r.investment_no = i.investment_no
                      WHERE r.return_date BETWEEN '$from' AND '$to'
                      ORDER BY r.return_date DESC";
            $result = mysqli_query($conn, $query);
            
            while ($row = mysqli_fetch_assoc($result)) {
                fputcsv($output, [
                    $row['investment_return_id'],
                    $row['return_date'],
                    $row['investor_name'],
                    $row['investment_no'],
                    $row['payable_investment'],
                    $row['payable_interest'],
                    $row['payable_investment'] + $row['payable_interest'],
                    $row['total_days'],
                    $row['payment_method']
                ]);
            }
            break;
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            background: #f8fafc;
        }

        .page-content {
            padding: 30px;
        }

        .reports-container {
            max-width: 1600px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #a0aec0;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Report Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: white;
            color: #4a5568;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #667eea;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-sub {
            font-size: 13px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .report-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .report-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .report-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-active {
            background: #48bb78;
            color: white;
        }

        .badge-closed {
            background: #a0aec0;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        .amount-highlight {
            color: #48bb78;
            font-weight: 600;
        }

        .interest-highlight {
            color: #ecc94b;
            font-weight: 600;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .report-tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                width: 100%;
                justify-content: center;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .export-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main-content">
            <?php include 'includes/topbar.php'; ?>

            <div class="page-content">
                <div class="reports-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-pie-chart"></i>
                            Investment Reports
                        </h1>
                        <div class="export-buttons">
                            <a href="?report_type=<?php echo $report_type; ?>&export=1&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-excel"></i> Export CSV
                            </a>
                            <button class="btn btn-primary btn-sm" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Report Type Tabs -->
                    <div class="report-tabs">
                        <a href="?report_type=summary" class="tab-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-pie-chart"></i> Summary
                        </a>
                        <a href="?report_type=investments" class="tab-btn <?php echo $report_type == 'investments' ? 'active' : ''; ?>">
                            <i class="bi bi-list-ul"></i> Investments
                        </a>
                        <a href="?report_type=returns" class="tab-btn <?php echo $report_type == 'returns' ? 'active' : ''; ?>">
                            <i class="bi bi-arrow-return-left"></i> Returns
                        </a>
                        <a href="?report_type=types" class="tab-btn <?php echo $report_type == 'types' ? 'active' : ''; ?>">
                            <i class="bi bi-tags"></i> By Type
                        </a>
                        <a href="?report_type=investors" class="tab-btn <?php echo $report_type == 'investors' ? 'active' : ''; ?>">
                            <i class="bi bi-people"></i> Top Investors
                        </a>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Reports
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                            
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Date Range</label>
                                    <select class="form-select" name="date_range" onchange="toggleCustomDates()">
                                        <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
                                        <option value="yesterday" <?php echo $date_range == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                        <option value="this_week" <?php echo $date_range == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                        <option value="last_week" <?php echo $date_range == 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                                        <option value="this_month" <?php echo $date_range == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                        <option value="last_month" <?php echo $date_range == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                        <option value="this_quarter" <?php echo $date_range == 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                                        <option value="last_quarter" <?php echo $date_range == 'last_quarter' ? 'selected' : ''; ?>>Last Quarter</option>
                                        <option value="this_year" <?php echo $date_range == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                                        <option value="last_year" <?php echo $date_range == 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                                        <option value="custom" <?php echo $date_range == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                    </select>
                                </div>

                                <div class="form-group" id="dateFromGroup" style="<?php echo $date_range == 'custom' ? 'display:block' : 'display:none'; ?>">
                                    <label class="form-label">Date From</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                    </div>
                                </div>

                                <div class="form-group" id="dateToGroup" style="<?php echo $date_range == 'custom' ? 'display:block' : 'display:none'; ?>">
                                    <label class="form-label">Date To</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Investments</option>
                                        <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active Only</option>
                                        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed Only</option>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="investment-reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <?php if ($report_type == 'summary'): ?>
                        <!-- Summary Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-pie-chart"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Total Investments</div>
                                    <div class="stat-value"><?php echo number_format($summary['total_investments']); ?></div>
                                    <div class="stat-sub">Amount: ₹<?php echo number_format($summary['total_amount'], 2); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Active Investments</div>
                                    <div class="stat-value"><?php echo number_format($summary['active_investments']); ?></div>
                                    <div class="stat-sub">Amount: ₹<?php echo number_format($summary['active_amount'], 2); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Closed Investments</div>
                                    <div class="stat-value"><?php echo number_format($summary['closed_investments']); ?></div>
                                    <div class="stat-sub">Amount: ₹<?php echo number_format($summary['closed_amount'], 2); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-percent"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Avg Interest Rate</div>
                                    <div class="stat-value"><?php echo number_format($summary['avg_interest_rate'], 2); ?>%</div>
                                    <div class="stat-sub">Total Interest: ₹<?php echo number_format($summary['total_interest'], 2); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Returns Summary -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-arrow-return-left"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Total Returns</div>
                                    <div class="stat-value"><?php echo number_format($returns_summary['total_returns']); ?></div>
                                    <div class="stat-sub">Processed returns</div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Principal Returned</div>
                                    <div class="stat-value">₹<?php echo number_format($returns_summary['total_principal_returned'], 2); ?></div>
                                    <div class="stat-sub">Total amount</div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-percent"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Interest Paid</div>
                                    <div class="stat-value">₹<?php echo number_format($returns_summary['total_interest_paid'], 2); ?></div>
                                    <div class="stat-sub">Total interest</div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-calendar"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Avg Days Held</div>
                                    <div class="stat-value"><?php echo number_format($returns_summary['avg_days_held'], 0); ?></div>
                                    <div class="stat-sub">Days</div>
                                </div>
                            </div>
                        </div>

                        <!-- Charts -->
                        <div class="chart-grid">
                            <div class="chart-card">
                                <div class="chart-title">Monthly Investment Trend</div>
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-card">
                                <div class="chart-title">Investment by Type</div>
                                <div class="chart-container">
                                    <canvas id="typeChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <script>
                            // Trend Chart
                            new Chart(document.getElementById('trendChart'), {
                                type: 'line',
                                data: {
                                    labels: <?php echo json_encode(array_reverse($months)); ?>,
                                    datasets: [{
                                        label: 'Investment Count',
                                        data: <?php echo json_encode(array_reverse($monthly_counts)); ?>,
                                        borderColor: '#4299e1',
                                        backgroundColor: '#4299e120',
                                        yAxisID: 'y',
                                        tension: 0.4
                                    }, {
                                        label: 'Amount (₹)',
                                        data: <?php echo json_encode(array_reverse($monthly_amounts)); ?>,
                                        borderColor: '#48bb78',
                                        backgroundColor: '#48bb7820',
                                        yAxisID: 'y1',
                                        tension: 0.4
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: {
                                        y: {
                                            type: 'linear',
                                            display: true,
                                            position: 'left',
                                            title: {
                                                display: true,
                                                text: 'Number of Investments'
                                            }
                                        },
                                        y1: {
                                            type: 'linear',
                                            display: true,
                                            position: 'right',
                                            title: {
                                                display: true,
                                                text: 'Amount (₹)'
                                            },
                                            grid: {
                                                drawOnChartArea: false
                                            }
                                        }
                                    }
                                }
                            });

                            // Type Chart
                            new Chart(document.getElementById('typeChart'), {
                                type: 'doughnut',
                                data: {
                                    labels: <?php echo json_encode($type_labels); ?>,
                                    datasets: [{
                                        data: <?php echo json_encode($type_amounts); ?>,
                                        backgroundColor: [
                                            '#4299e1', '#48bb78', '#ecc94b', '#9f7aea', '#f56565',
                                            '#667eea', '#38b2ac', '#ed8936', '#fc8181', '#b794f4'
                                        ]
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            position: 'bottom'
                                        }
                                    },
                                    cutout: '60%'
                                }
                            });
                        </script>

                    <?php elseif ($report_type == 'investments'): ?>
                        <!-- Detailed Investments List -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Investment Details</span>
                                <span class="text-muted">Total: <?php echo mysqli_num_rows($detailed_result); ?> investments</span>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Inv No</th>
                                            <th>Date</th>
                                            <th>Investor</th>
                                            <th>Type</th>
                                            <th class="text-right">Amount (₹)</th>
                                            <th class="text-right">Interest %</th>
                                            <th class="text-right">Annual Interest</th>
                                            <th>Status</th>
                                            <th class="text-right">Returns</th>
                                            <th class="text-right">Interest Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        mysqli_data_seek($detailed_result, 0);
                                        while ($inv = mysqli_fetch_assoc($detailed_result)): 
                                            $annual_interest = ($inv['investment_amount'] * $inv['interest'] / 100);
                                        ?>
                                        <tr>
                                            <td><strong>#<?php echo $inv['investment_no']; ?></strong></td>
                                            <td><?php echo date('d-m-Y', strtotime($inv['investment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($inv['investor_name']); ?></td>
                                            <td><?php echo htmlspecialchars($inv['investment_type']); ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($inv['investment_amount'], 2); ?></td>
                                            <td class="text-right interest-highlight"><?php echo $inv['interest']; ?>%</td>
                                            <td class="text-right">₹<?php echo number_format($annual_interest, 2); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $inv['status'] == 1 ? 'active' : 'closed'; ?>">
                                                    <?php echo $inv['status'] == 1 ? 'Active' : 'Closed'; ?>
                                                </span>
                                            </td>
                                            <td class="text-right"><?php echo $inv['return_count']; ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($inv['interest_earned'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'returns'): ?>
                        <!-- Returns Analysis -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Returns Analysis</span>
                                <span class="text-muted">Period: <?php echo date('d-m-Y', strtotime($date_from)); ?> to <?php echo date('d-m-Y', strtotime($date_to)); ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-right">Returns</th>
                                            <th class="text-right">Principal (₹)</th>
                                            <th class="text-right">Interest (₹)</th>
                                            <th class="text-right">Total (₹)</th>
                                            <th class="text-right">Avg Days</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_returns = 0;
                                        $total_principal = 0;
                                        $total_interest = 0;
                                        $total_paid = 0;
                                        
                                        while ($row = mysqli_fetch_assoc($returns_analysis_result)): 
                                            $total_returns += $row['return_count'];
                                            $total_principal += $row['principal_returned'];
                                            $total_interest += $row['interest_paid'];
                                            $total_paid += $row['total_paid'];
                                        ?>
                                        <tr>
                                            <td><strong><?php echo date('M Y', strtotime($row['month'] . '-01')); ?></strong></td>
                                            <td class="text-right"><?php echo $row['return_count']; ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($row['principal_returned'], 2); ?></td>
                                            <td class="text-right interest-highlight">₹<?php echo number_format($row['interest_paid'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($row['total_paid'], 2); ?></td>
                                            <td class="text-right"><?php echo number_format($row['avg_days_held'], 0); ?> days</td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background: #f7fafc; font-weight: 600;">
                                            <td class="text-right">TOTALS:</td>
                                            <td class="text-right"><?php echo $total_returns; ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($total_principal, 2); ?></td>
                                            <td class="text-right interest-highlight">₹<?php echo number_format($total_interest, 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($total_paid, 2); ?></td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'types'): ?>
                        <!-- Investment Types Breakdown -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Investment by Type</span>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Investment Type</th>
                                            <th class="text-right">Count</th>
                                            <th class="text-right">Total Amount (₹)</th>
                                            <th class="text-right">Average (₹)</th>
                                            <th class="text-right">Total Interest</th>
                                            <th class="text-right">Active</th>
                                            <th class="text-right">Closed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($type_data as $type): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($type['investment_type']); ?></strong></td>
                                            <td class="text-right"><?php echo $type['count']; ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($type['total_amount'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($type['avg_amount'], 2); ?></td>
                                            <td class="text-right interest-highlight">₹<?php echo number_format($type['total_interest'], 2); ?></td>
                                            <td class="text-right"><?php echo $type['active_count']; ?></td>
                                            <td class="text-right"><?php echo $type['closed_count']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'investors'): ?>
                        <!-- Top Investors -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Top Investors</span>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Investor Name</th>
                                            <th class="text-right">Investments</th>
                                            <th class="text-right">Total Invested (₹)</th>
                                            <th class="text-right">Average (₹)</th>
                                            <th class="text-right">Active</th>
                                            <th class="text-right">Closed</th>
                                            <th class="text-right">Interest Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($investor = mysqli_fetch_assoc($investor_result)): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($investor['investor_name']); ?></strong></td>
                                            <td class="text-right"><?php echo $investor['investment_count']; ?></td>
                                            <td class="text-right amount-highlight">₹<?php echo number_format($investor['total_invested'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($investor['avg_investment'], 2); ?></td>
                                            <td class="text-right"><?php echo $investor['active_count']; ?></td>
                                            <td class="text-right"><?php echo $investor['closed_count']; ?></td>
                                            <td class="text-right interest-highlight">₹<?php echo number_format($investor['total_interest_earned'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Toggle custom date inputs
        function toggleCustomDates() {
            const dateRange = document.querySelector('select[name="date_range"]').value;
            const dateFromGroup = document.getElementById('dateFromGroup');
            const dateToGroup = document.getElementById('dateToGroup');
            
            if (dateRange === 'custom') {
                dateFromGroup.style.display = 'block';
                dateToGroup.style.display = 'block';
            } else {
                dateFromGroup.style.display = 'none';
                dateToGroup.style.display = 'none';
            }
        }

        // Auto-submit form when filters change
        document.querySelector('select[name="status"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>