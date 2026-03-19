<?php
session_start();
$currentPage = 'interest-reports';
$pageTitle = 'Interest Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'accountant', 'manager'])) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$interest_type = isset($_GET['interest_type']) ? $_GET['interest_type'] : 'all';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
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

// Validate dates
if (empty($date_from) || $date_from == '1970-01-01') {
    $date_from = date('Y-m-01');
}
if (empty($date_to) || $date_to == '1970-01-01') {
    $date_to = date('Y-m-d');
}

// Get customers for dropdown
$customers_query = "SELECT id, customer_name, mobile_number FROM customers ORDER BY customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get active loans for dropdown
$loans_query = "SELECT l.id, l.receipt_number, c.customer_name 
                FROM loans l 
                JOIN customers c ON l.customer_id = c.id 
                WHERE l.status = 'open' 
                ORDER BY l.receipt_number DESC";
$loans_result = mysqli_query($conn, $loans_query);

// Get interest types from settings
$interest_types_query = "SELECT DISTINCT interest_type FROM interest_settings WHERE is_active = 1";
$interest_types_result = mysqli_query($conn, $interest_types_query);

// ==================== INTEREST SUMMARY STATISTICS ====================

// Build base WHERE clause for filtering
$where_conditions = ["DATE(p.payment_date) BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$types = "ss";

if ($interest_type != 'all') {
    $where_conditions[] = "l.interest_type = ?";
    $params[] = $interest_type;
    $types .= "s";
}

if ($customer_id > 0) {
    $where_conditions[] = "l.customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

if ($loan_id > 0) {
    $where_conditions[] = "p.loan_id = ?";
    $params[] = $loan_id;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// 1. Total Interest Collected
$total_interest_query = "SELECT 
                            COALESCE(SUM(p.interest_amount), 0) as total_interest,
                            COUNT(p.id) as total_transactions,
                            COUNT(DISTINCT p.loan_id) as loans_with_interest,
                            AVG(p.interest_amount) as avg_interest,
                            MAX(p.interest_amount) as max_interest,
                            MIN(p.interest_amount) as min_interest
                        FROM payments p
                        JOIN loans l ON p.loan_id = l.id
                        WHERE $where_clause AND p.interest_amount > 0";

$stmt = mysqli_prepare($conn, $total_interest_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $interest_summary = mysqli_fetch_assoc($result);
} else {
    $interest_summary = [
        'total_interest' => 0,
        'total_transactions' => 0,
        'loans_with_interest' => 0,
        'avg_interest' => 0,
        'max_interest' => 0,
        'min_interest' => 0
    ];
}

// 2. Interest by Type
$interest_by_type_query = "SELECT 
                            l.interest_type,
                            COALESCE(SUM(p.interest_amount), 0) as total_interest,
                            COUNT(p.id) as transaction_count,
                            COUNT(DISTINCT p.loan_id) as loan_count
                          FROM payments p
                          JOIN loans l ON p.loan_id = l.id
                          WHERE $where_clause AND p.interest_amount > 0
                          GROUP BY l.interest_type
                          ORDER BY total_interest DESC";

$stmt = mysqli_prepare($conn, $interest_by_type_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $interest_by_type_result = mysqli_stmt_get_result($stmt);
    $interest_by_type = [];
    while ($row = mysqli_fetch_assoc($interest_by_type_result)) {
        $interest_by_type[] = $row;
    }
} else {
    $interest_by_type = [];
}

// 3. Daily Interest Collection
$daily_interest_query = "SELECT 
                            DATE(p.payment_date) as date,
                            COALESCE(SUM(p.interest_amount), 0) as daily_interest,
                            COUNT(p.id) as transaction_count
                         FROM payments p
                         JOIN loans l ON p.loan_id = l.id
                         WHERE $where_clause AND p.interest_amount > 0
                         GROUP BY DATE(p.payment_date)
                         ORDER BY date DESC
                         LIMIT 30";

$stmt = mysqli_prepare($conn, $daily_interest_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $daily_interest_result = mysqli_stmt_get_result($stmt);
    $daily_interest = [];
    $chart_dates = [];
    $chart_amounts = [];
    while ($row = mysqli_fetch_assoc($daily_interest_result)) {
        $daily_interest[] = $row;
        $chart_dates[] = date('d M', strtotime($row['date']));
        $chart_amounts[] = $row['daily_interest'];
    }
} else {
    $daily_interest = [];
    $chart_dates = [];
    $chart_amounts = [];
}

// 4. Top Interest Paying Customers
$top_customers_query = "SELECT 
                            c.id,
                            c.customer_name,
                            c.mobile_number,
                            COUNT(p.id) as payment_count,
                            COALESCE(SUM(p.interest_amount), 0) as total_interest,
                            MAX(p.payment_date) as last_payment
                        FROM payments p
                        JOIN loans l ON p.loan_id = l.id
                        JOIN customers c ON l.customer_id = c.id
                        WHERE $where_clause AND p.interest_amount > 0
                        GROUP BY c.id, c.customer_name, c.mobile_number
                        ORDER BY total_interest DESC
                        LIMIT 10";

$stmt = mysqli_prepare($conn, $top_customers_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $top_customers_result = mysqli_stmt_get_result($stmt);
    $top_customers = [];
    while ($row = mysqli_fetch_assoc($top_customers_result)) {
        $top_customers[] = $row;
    }
} else {
    $top_customers = [];
}

// 5. Monthly Interest Trend
$monthly_trend_query = "SELECT 
                            DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                            COALESCE(SUM(p.interest_amount), 0) as monthly_interest,
                            COUNT(p.id) as transaction_count,
                            COUNT(DISTINCT p.loan_id) as loan_count
                        FROM payments p
                        JOIN loans l ON p.loan_id = l.id
                        WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                        ORDER BY month DESC";

$monthly_trend_result = mysqli_query($conn, $monthly_trend_query);
$monthly_trend = [];
$month_labels = [];
$monthly_amounts = [];
while ($row = mysqli_fetch_assoc($monthly_trend_result)) {
    $monthly_trend[] = $row;
    $month_labels[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthly_amounts[] = $row['monthly_interest'];
}

// 6. Interest Payment Details
$interest_details_query = "SELECT 
                            p.id,
                            p.payment_date,
                            p.receipt_number,
                            p.interest_amount,
                            p.principal_amount,
                            p.total_amount,
                            p.payment_mode,
                            l.receipt_number as loan_receipt,
                            l.interest_type,
                            l.loan_amount,
                            c.customer_name,
                            c.mobile_number
                          FROM payments p
                          JOIN loans l ON p.loan_id = l.id
                          JOIN customers c ON l.customer_id = c.id
                          WHERE $where_clause AND p.interest_amount > 0
                          ORDER BY p.payment_date DESC
                          LIMIT 100";

$stmt = mysqli_prepare($conn, $interest_details_query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $interest_details_result = mysqli_stmt_get_result($stmt);
    $interest_details = [];
    while ($row = mysqli_fetch_assoc($interest_details_result)) {
        $interest_details[] = $row;
    }
} else {
    $interest_details = [];
}

// 7. Overdue Interest
$overdue_interest_query = "SELECT 
                            COUNT(DISTINCT l.id) as overdue_loans,
                            COALESCE(SUM(l.total_overdue_amount), 0) as total_overdue,
                            COALESCE(SUM(CASE WHEN l.last_interest_paid_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN l.loan_amount * (l.interest_amount/100)/30 * DATEDIFF(CURDATE(), l.last_interest_paid_date) ELSE 0 END), 0) as estimated_overdue_interest
                          FROM loans l
                          WHERE l.status = 'open' 
                          AND (l.last_interest_paid_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR l.last_interest_paid_date IS NULL)";

$overdue_interest_result = mysqli_query($conn, $overdue_interest_query);
$overdue_interest = mysqli_fetch_assoc($overdue_interest_result);

// 8. Interest Rate Distribution
$rate_distribution_query = "SELECT 
                            l.interest_amount as interest_rate,
                            COUNT(DISTINCT l.id) as loan_count,
                            COALESCE(SUM(l.loan_amount), 0) as total_loan_amount
                          FROM loans l
                          WHERE l.status = 'open'
                          GROUP BY l.interest_amount
                          ORDER BY l.interest_amount";

$rate_distribution_result = mysqli_query($conn, $rate_distribution_query);
$rate_distribution = [];
while ($row = mysqli_fetch_assoc($rate_distribution_result)) {
    $rate_distribution[] = $row;
}

// Handle export
if ($export) {
    exportInterestReport($date_from, $date_to, $interest_details, $interest_summary);
}

function exportInterestReport($from, $to, $details, $summary) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="interest_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['INTEREST COLLECTION REPORT']);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, []);
    
    // Summary Section
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Interest Collected', '₹ ' . number_format($summary['total_interest'], 2)]);
    fputcsv($output, ['Number of Transactions', $summary['total_transactions']]);
    fputcsv($output, ['Loans with Interest', $summary['loans_with_interest']]);
    fputcsv($output, ['Average Interest', '₹ ' . number_format($summary['avg_interest'], 2)]);
    fputcsv($output, ['Maximum Interest', '₹ ' . number_format($summary['max_interest'], 2)]);
    fputcsv($output, ['Minimum Interest', '₹ ' . number_format($summary['min_interest'], 2)]);
    fputcsv($output, []);
    
    // Details Section
    fputcsv($output, ['DETAILED TRANSACTIONS']);
    fputcsv($output, ['Date', 'Receipt #', 'Customer', 'Loan #', 'Interest Type', 'Interest Amount', 'Payment Mode']);
    
    foreach ($details as $row) {
        fputcsv($output, [
            $row['payment_date'],
            $row['receipt_number'],
            $row['customer_name'],
            $row['loan_receipt'],
            $row['interest_type'],
            '₹ ' . number_format($row['interest_amount'], 2),
            ucfirst($row['payment_mode'])
        ]);
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

        .interest-container {
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
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

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .summary-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .summary-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .summary-card.warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .summary-card.danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .summary-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-sub {
            font-size: 13px;
            opacity: 0.8;
        }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .stats-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
        }

        .stats-list {
            list-style: none;
        }

        .stats-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .stats-item:last-child {
            border-bottom: none;
        }

        .stats-label {
            color: #4a5568;
        }

        .stats-value {
            font-weight: 600;
            color: #2d3748;
        }

        /* Tables */
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-title i {
            color: #667eea;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .interest-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .interest-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .interest-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .interest-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #feebc8;
            color: #744210;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-purple {
            background: #e9d8fd;
            color: #553c9a;
        }

        .text-right {
            text-align: right;
        }

        .text-success {
            color: #48bb78;
            font-weight: 600;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        /* Overdue Alert */
        .overdue-alert {
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .overdue-icon {
            width: 50px;
            height: 50px;
            border-radius: 25px;
            background: #fed7d7;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #c53030;
            font-size: 24px;
        }

        .overdue-content {
            flex: 1;
        }

        .overdue-title {
            font-size: 16px;
            font-weight: 600;
            color: #c53030;
            margin-bottom: 5px;
        }

        .overdue-stats {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }

        .overdue-stat {
            font-size: 14px;
        }

        .overdue-stat strong {
            font-size: 18px;
            margin-right: 5px;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .overdue-stats {
                flex-direction: column;
                gap: 10px;
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
                <div class="interest-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-percent"></i>
                            Interest Reports
                        </h1>
                        <div class="export-buttons">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-excel"></i> Export CSV
                            </a>
                            <button class="btn btn-primary btn-sm" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Display Error Message -->
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <strong>Error:</strong> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Interest Report
                        </div>

                        <form method="GET" action="" id="filterForm">
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
                                    <label class="form-label">Interest Type</label>
                                    <select class="form-select" name="interest_type">
                                        <option value="all">All Types</option>
                                        <?php 
                                        if ($interest_types_result) {
                                            while ($type = mysqli_fetch_assoc($interest_types_result)): 
                                        ?>
                                        <option value="<?php echo $type['interest_type']; ?>" <?php echo $interest_type == $type['interest_type'] ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($type['interest_type']); ?>
                                        </option>
                                        <?php 
                                            endwhile;
                                        } 
                                        ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id">
                                        <option value="0">All Customers</option>
                                        <?php 
                                        if ($customers_result) {
                                            while ($cust = mysqli_fetch_assoc($customers_result)): 
                                        ?>
                                        <option value="<?php echo $cust['id']; ?>" <?php echo $customer_id == $cust['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cust['customer_name'] . ' - ' . $cust['mobile_number']); ?>
                                        </option>
                                        <?php 
                                            endwhile;
                                        } 
                                        ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Loan</label>
                                    <select class="form-select" name="loan_id">
                                        <option value="0">All Loans</option>
                                        <?php 
                                        if ($loans_result) {
                                            while ($loan = mysqli_fetch_assoc($loans_result)): 
                                        ?>
                                        <option value="<?php echo $loan['id']; ?>" <?php echo $loan_id == $loan['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loan['receipt_number'] . ' - ' . $loan['customer_name']); ?>
                                        </option>
                                        <?php 
                                            endwhile;
                                        } 
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Generate Report
                                </button>
                                <a href="interest-reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Overdue Interest Alert -->
                    <?php if ($overdue_interest['overdue_loans'] > 0): ?>
                    <div class="overdue-alert">
                        <div class="overdue-icon">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <div class="overdue-content">
                            <div class="overdue-title">Overdue Interest Alert</div>
                            <div class="overdue-stats">
                                <div class="overdue-stat">
                                    <strong><?php echo $overdue_interest['overdue_loans']; ?></strong> Loans Overdue
                                </div>
                                <div class="overdue-stat">
                                    <strong>₹ <?php echo number_format($overdue_interest['total_overdue'], 2); ?></strong> Total Overdue Amount
                                </div>
                                <div class="overdue-stat">
                                    <strong>₹ <?php echo number_format($overdue_interest['estimated_overdue_interest'], 2); ?></strong> Estimated Overdue Interest
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Summary Cards -->
                    <div class="summary-grid">
                        <div class="summary-card primary">
                            <div class="summary-label">Total Interest Collected</div>
                            <div class="summary-value">₹ <?php echo number_format($interest_summary['total_interest'], 2); ?></div>
                            <div class="summary-sub">For selected period</div>
                        </div>

                        <div class="summary-card success">
                            <div class="summary-label">Transactions</div>
                            <div class="summary-value"><?php echo number_format($interest_summary['total_transactions']); ?></div>
                            <div class="summary-sub">Interest payments</div>
                        </div>

                        <div class="summary-card warning">
                            <div class="summary-label">Loans with Interest</div>
                            <div class="summary-value"><?php echo number_format($interest_summary['loans_with_interest']); ?></div>
                            <div class="summary-sub">Unique loans</div>
                        </div>

                        <div class="summary-card">
                            <div class="summary-label">Average Interest</div>
                            <div class="summary-value">₹ <?php echo number_format($interest_summary['avg_interest'], 2); ?></div>
                            <div class="summary-sub">Per transaction</div>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="chart-grid">
                        <div class="chart-card">
                            <div class="chart-title">Daily Interest Collection</div>
                            <div class="chart-container">
                                <canvas id="dailyInterestChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">Interest by Type</div>
                            <div class="chart-container">
                                <canvas id="interestTypeChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Section -->
                    <div class="stats-grid">
                        <div class="stats-card">
                            <div class="stats-title">Monthly Interest Trend</div>
                            <div class="table-responsive">
                                <table class="interest-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-right">Interest (₹)</th>
                                            <th class="text-right">Transactions</th>
                                            <th class="text-right">Loans</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_trend as $trend): ?>
                                        <tr>
                                            <td><?php echo date('M Y', strtotime($trend['month'] . '-01')); ?></td>
                                            <td class="text-right amount">₹ <?php echo number_format($trend['monthly_interest'], 2); ?></td>
                                            <td class="text-right"><?php echo $trend['transaction_count']; ?></td>
                                            <td class="text-right"><?php echo $trend['loan_count']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="stats-card">
                            <div class="stats-title">Interest Rate Distribution</div>
                            <div class="table-responsive">
                                <table class="interest-table">
                                    <thead>
                                        <tr>
                                            <th>Rate (%)</th>
                                            <th class="text-right">Loans</th>
                                            <th class="text-right">Loan Amount (₹)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rate_distribution as $rate): ?>
                                        <tr>
                                            <td><?php echo $rate['interest_rate']; ?>%</td>
                                            <td class="text-right"><?php echo $rate['loan_count']; ?></td>
                                            <td class="text-right amount">₹ <?php echo number_format($rate['total_loan_amount'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top Customers -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-trophy"></i>
                                Top Interest Paying Customers
                            </h3>
                        </div>
                        <div class="table-responsive">
                            <table class="interest-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Total Interest</th>
                                        <th>Last Payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    foreach ($top_customers as $customer): 
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $rank++; ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['mobile_number']); ?></td>
                                        <td class="text-right"><?php echo $customer['payment_count']; ?></td>
                                        <td class="text-right amount">₹ <?php echo number_format($customer['total_interest'], 2); ?></td>
                                        <td><?php echo date('d M Y', strtotime($customer['last_payment'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Detailed Interest Transactions -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Interest Payment Details
                            </h3>
                            <span><?php echo count($interest_details); ?> transactions</span>
                        </div>
                        <div class="table-responsive">
                            <table class="interest-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt #</th>
                                        <th>Customer</th>
                                        <th>Loan #</th>
                                        <th>Interest Type</th>
                                        <th class="text-right">Interest (₹)</th>
                                        <th class="text-right">Principal (₹)</th>
                                        <th class="text-right">Total (₹)</th>
                                        <th>Payment Mode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($interest_details)): ?>
                                        <?php foreach ($interest_details as $item): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($item['payment_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($item['receipt_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($item['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['loan_receipt']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $item['interest_type'] == 'daily' ? 'warning' : 
                                                        ($item['interest_type'] == 'monthly' ? 'info' : 
                                                        ($item['interest_type'] == 'fixed' ? 'success' : 'purple')); 
                                                ?>">
                                                    <?php echo ucfirst($item['interest_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right amount">₹ <?php echo number_format($item['interest_amount'], 2); ?></td>
                                            <td class="text-right">₹ <?php echo number_format($item['principal_amount'], 2); ?></td>
                                            <td class="text-right amount">₹ <?php echo number_format($item['total_amount'], 2); ?></td>
                                            <td>
                                                <span class="badge badge-info"><?php echo ucfirst($item['payment_mode']); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center" style="padding: 40px; color: #a0aec0;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                                No interest transactions found for the selected period
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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

        // Daily Interest Chart
        <?php if (!empty($chart_dates) && !empty($chart_amounts)): ?>
        new Chart(document.getElementById('dailyInterestChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_reverse($chart_dates)); ?>,
                datasets: [{
                    label: 'Daily Interest',
                    data: <?php echo json_encode(array_reverse($chart_amounts)); ?>,
                    borderColor: '#667eea',
                    backgroundColor: '#667eea20',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php else: ?>
        document.getElementById('dailyInterestChart').parentNode.innerHTML = '<div style="text-align: center; padding: 50px; color: #718096;">No daily data available</div>';
        <?php endif; ?>

        // Interest by Type Chart
        <?php if (!empty($interest_by_type)): 
            $type_labels = [];
            $type_amounts = [];
            foreach ($interest_by_type as $type) {
                $type_labels[] = ucfirst($type['interest_type']);
                $type_amounts[] = $type['total_interest'];
            }
        ?>
        new Chart(document.getElementById('interestTypeChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($type_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($type_amounts); ?>,
                    backgroundColor: ['#667eea', '#48bb78', '#ecc94b', '#f56565', '#9f7aea']
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
        <?php else: ?>
        document.getElementById('interestTypeChart').parentNode.innerHTML = '<div style="text-align: center; padding: 50px; color: #718096;">No type data available</div>';
        <?php endif; ?>

        // Auto-submit form when filters change
        document.querySelector('select[name="interest_type"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.querySelector('select[name="loan_id"]')?.addEventListener('change', function() {
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