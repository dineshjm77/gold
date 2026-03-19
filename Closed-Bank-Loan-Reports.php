<?php
session_start();
$currentPage = 'closed-bank-loan-reports';
$pageTitle = 'Closed Bank Loan Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'closed';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get customers who have closed loans
$customers_query = "SELECT DISTINCT c.id, c.customer_name, c.mobile_number 
                    FROM customers c 
                    JOIN bank_loans bl ON c.id = bl.customer_id 
                    WHERE bl.status IN ('closed', 'defaulted')
                    ORDER BY c.customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Build WHERE clause based on filters
$where_conditions = ["bl.status IN ('closed', 'defaulted')"];
$params = [];
$param_types = "";

if ($bank_id > 0) {
    $where_conditions[] = "bl.bank_id = ?";
    $params[] = $bank_id;
    $param_types .= "i";
}

if ($customer_id > 0) {
    $where_conditions[] = "bl.customer_id = ?";
    $params[] = $customer_id;
    $param_types .= "i";
}

if (!empty($from_date) && !empty($to_date)) {
    if ($report_type == 'closed') {
        $where_conditions[] = "bl.close_date BETWEEN ? AND ?";
    } else {
        $where_conditions[] = "bl.loan_date BETWEEN ? AND ?";
    }
    $params[] = $from_date;
    $params[] = $to_date;
    $param_types .= "ss";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get summary statistics for closed loans
$summary_query = "SELECT 
                    COUNT(*) as total_loans,
                    SUM(CASE WHEN bl.status = 'closed' THEN 1 ELSE 0 END) as fully_closed,
                    SUM(CASE WHEN bl.status = 'defaulted' THEN 1 ELSE 0 END) as defaulted,
                    SUM(bl.loan_amount) as total_loan_amount,
                    SUM(bl.total_interest) as total_interest,
                    SUM(bl.document_charge + bl.processing_fee) as total_charges,
                    SUM(bl.total_payable) as total_payable,
                    AVG(bl.interest_rate) as avg_interest_rate,
                    AVG(bl.tenure_months) as avg_tenure,
                    MAX(bl.loan_amount) as max_loan,
                    MIN(bl.loan_amount) as min_loan,
                    SUM(DATEDIFF(bl.close_date, bl.loan_date)) as total_days,
                    AVG(DATEDIFF(bl.close_date, bl.loan_date)) as avg_days
                  FROM bank_loans bl
                  $where_clause";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $summary_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $summary_result = mysqli_stmt_get_result($stmt);
} else {
    $summary_result = mysqli_query($conn, $summary_query);
}
$summary = mysqli_fetch_assoc($summary_result);

// Get payment summary for closed loans
$payment_query = "SELECT 
                    COUNT(DISTINCT bp.id) as total_payments,
                    SUM(bp.payment_amount) as total_collected,
                    AVG(bp.payment_amount) as avg_payment,
                    SUM(CASE WHEN bp.payment_method = 'cash' THEN bp.payment_amount ELSE 0 END) as cash_collected,
                    SUM(CASE WHEN bp.payment_method = 'bank' THEN bp.payment_amount ELSE 0 END) as bank_collected,
                    SUM(CASE WHEN bp.payment_method = 'upi' THEN bp.payment_amount ELSE 0 END) as upi_collected,
                    SUM(CASE WHEN bp.payment_method = 'cheque' THEN bp.payment_amount ELSE 0 END) as cheque_collected
                  FROM bank_loan_payments bp
                  JOIN bank_loans bl ON bp.bank_loan_id = bl.id
                  $where_clause";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $payment_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $payment_result = mysqli_stmt_get_result($stmt);
} else {
    $payment_result = mysqli_query($conn, $payment_query);
}
$payment_summary = mysqli_fetch_assoc($payment_result);

// Get monthly breakdown
$monthly_query = "SELECT 
                    DATE_FORMAT(bl.close_date, '%Y-%m') as month,
                    COUNT(*) as closed_count,
                    SUM(bl.loan_amount) as total_amount,
                    SUM(bl.total_interest) as total_interest,
                    SUM(bl.total_payable) as total_payable,
                    AVG(bl.interest_rate) as avg_rate,
                    SUM(DATEDIFF(bl.close_date, bl.loan_date)) as total_days,
                    AVG(DATEDIFF(bl.close_date, bl.loan_date)) as avg_days
                  FROM bank_loans bl
                  $where_clause AND bl.close_date IS NOT NULL
                  GROUP BY DATE_FORMAT(bl.close_date, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 12";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $monthly_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $monthly_result = mysqli_stmt_get_result($stmt);
} else {
    $monthly_result = mysqli_query($conn, $monthly_query);
}

// Get bank-wise breakdown
$bankwise_query = "SELECT 
                    b.id,
                    b.bank_short_name,
                    b.bank_full_name,
                    COUNT(bl.id) as loan_count,
                    SUM(bl.loan_amount) as total_amount,
                    SUM(bl.total_interest) as total_interest,
                    SUM(bl.total_payable) as total_payable,
                    AVG(bl.interest_rate) as avg_rate,
                    SUM(CASE WHEN bl.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                    SUM(CASE WHEN bl.status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_count,
                    AVG(DATEDIFF(bl.close_date, bl.loan_date)) as avg_days
                  FROM bank_loans bl
                  JOIN bank_master b ON bl.bank_id = b.id
                  $where_clause
                  GROUP BY bl.bank_id
                  ORDER BY total_amount DESC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $bankwise_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $bankwise_result = mysqli_stmt_get_result($stmt);
} else {
    $bankwise_result = mysqli_query($conn, $bankwise_query);
}

// Get detailed closed loans list
$details_query = "SELECT 
                    bl.*,
                    b.bank_short_name,
                    b.bank_full_name,
                    ba.account_holder_no,
                    ba.bank_account_no,
                    c.customer_name,
                    c.mobile_number,
                    c.email,
                    u.name as employee_name,
                    (SELECT COUNT(*) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as payment_count,
                    (SELECT SUM(payment_amount) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as total_paid,
                    (SELECT COUNT(*) FROM bank_loan_items WHERE bank_loan_id = bl.id) as items_count,
                    DATEDIFF(bl.close_date, bl.loan_date) as loan_duration
                  FROM bank_loans bl
                  LEFT JOIN bank_master b ON bl.bank_id = b.id
                  LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                  LEFT JOIN customers c ON bl.customer_id = c.id
                  LEFT JOIN users u ON bl.employee_id = u.id
                  $where_clause
                  ORDER BY bl.close_date DESC, bl.loan_date DESC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $details_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $details_result = mysqli_stmt_get_result($stmt);
} else {
    $details_result = mysqli_query($conn, $details_query);
}

// Handle export
if ($export == 'excel' || $export == 'csv') {
    $filename = 'closed_bank_loans_' . date('Ymd_His') . '.' . ($export == 'excel' ? 'xls' : 'csv');
    
    header('Content-Type: ' . ($export == 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($export == 'csv') {
        $output = fopen('php://output', 'w');
    } else {
        echo "<table border='1'>";
    }
    
    // Export headers
    $headers = [
        'Close Date', 'Loan Ref', 'Bank', 'Customer', 'Mobile',
        'Loan Amount', 'Interest', 'Charges', 'Total Payable', 'Total Paid',
        'Duration (Days)', 'Status', 'Payment Count', 'Items'
    ];
    
    if ($export == 'csv') {
        fputcsv($output, $headers);
    } else {
        echo "<tr>";
        foreach ($headers as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
    }
    
    // Export data
    if (mysqli_num_rows($details_result) > 0) {
        mysqli_data_seek($details_result, 0);
        while ($row = mysqli_fetch_assoc($details_result)) {
            $row_data = [
                date('d-m-Y', strtotime($row['close_date'])),
                $row['loan_reference'] ?? '',
                $row['bank_short_name'] ?? '',
                $row['customer_name'] ?? '',
                $row['mobile_number'] ?? '',
                safeNumberFormat($row['loan_amount'] ?? 0),
                safeNumberFormat($row['total_interest'] ?? 0),
                safeNumberFormat(($row['document_charge'] ?? 0) + ($row['processing_fee'] ?? 0)),
                safeNumberFormat($row['total_payable'] ?? 0),
                safeNumberFormat($row['total_paid'] ?? 0),
                $row['loan_duration'] ?? 0,
                ucfirst($row['status'] ?? ''),
                $row['payment_count'] ?? 0,
                $row['items_count'] ?? 0
            ];
            
            if ($export == 'csv') {
                fputcsv($output, $row_data);
            } else {
                echo "<tr>";
                foreach ($row_data as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
        }
    }
    
    if ($export == 'excel') {
        echo "</table>";
    } else {
        fclose($output);
    }
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
            display: flex;
            align-items: center;
            gap: 10px;
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
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

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 13px;
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
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
            flex-wrap: wrap;
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
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
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
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Charts Row */
        .charts-row {
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            vertical-align: middle;
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

        .badge-closed {
            background: #48bb78;
            color: white;
        }

        .badge-defaulted {
            background: #f56565;
            color: white;
        }

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        .positive {
            color: #48bb78;
            font-weight: 600;
        }

        .negative {
            color: #f56565;
            font-weight: 600;
        }

        .profit {
            color: #48bb78;
            font-weight: 600;
        }

        .loss {
            color: #f56565;
            font-weight: 600;
        }

        .search-box {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Summary Cards */
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .summary-item {
            text-align: center;
            padding: 10px;
            border-right: 1px solid #e2e8f0;
        }

        .summary-item:last-child {
            border-right: none;
        }

        .summary-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-row {
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
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .export-buttons {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .search-box {
                width: 100%;
            }
            
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-item {
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
                padding: 10px 0;
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
                            <i class="bi bi-archive"></i>
                            Closed Bank Loan Reports
                        </h1>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Closed Loans
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">From Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="from_date" value="<?php echo $from_date; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">To Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="to_date" value="<?php echo $to_date; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id">
                                            <option value="0">All Banks</option>
                                            <?php 
                                            if ($banks_result && mysqli_num_rows($banks_result) > 0) {
                                                mysqli_data_seek($banks_result, 0);
                                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>" <?php echo $bank_id == $bank['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <div class="input-group">
                                        <i class="bi bi-person input-icon"></i>
                                        <select class="form-select" name="customer_id">
                                            <option value="0">All Customers</option>
                                            <?php 
                                            if ($customers_result && mysqli_num_rows($customers_result) > 0) {
                                                mysqli_data_seek($customers_result, 0);
                                                while($customer = mysqli_fetch_assoc($customers_result)): 
                                            ?>
                                                <option value="<?php echo $customer['id']; ?>" <?php echo $customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($customer['customer_name']); ?> - <?php echo htmlspecialchars($customer['mobile_number']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Report Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-file-text input-icon"></i>
                                        <select class="form-select" name="report_type">
                                            <option value="closed" <?php echo $report_type == 'closed' ? 'selected' : ''; ?>>Closed Date</option>
                                            <option value="loan" <?php echo $report_type == 'loan' ? 'selected' : ''; ?>>Loan Date</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                                    <i class="bi bi-file-excel"></i> Export Excel
                                </button>
                                <button type="button" class="btn btn-info" onclick="exportReport('csv')">
                                    <i class="bi bi-file-spreadsheet"></i> Export CSV
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-archive"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Closed Loans</div>
                                <div class="stat-value"><?php echo safeNumberFormat($summary['total_loans'] ?? 0, 0); ?></div>
                                <div class="stat-sub">
                                    Fully Closed: <?php echo safeNumberFormat($summary['fully_closed'] ?? 0, 0); ?> | 
                                    Defaulted: <?php echo safeNumberFormat($summary['defaulted'] ?? 0, 0); ?>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Loan Amount</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($summary['total_loan_amount'] ?? 0); ?></div>
                                <div class="stat-sub">Min: ₹<?php echo safeNumberFormat($summary['min_loan'] ?? 0); ?> | Max: ₹<?php echo safeNumberFormat($summary['max_loan'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Interest & Charges</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat(($summary['total_interest'] ?? 0) + ($summary['total_charges'] ?? 0)); ?></div>
                                <div class="stat-sub">Avg Rate: <?php echo safeNumberFormat($summary['avg_interest_rate'] ?? 0, 2); ?>%</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Average Duration</div>
                                <div class="stat-value"><?php echo safeNumberFormat($summary['avg_days'] ?? 0, 0); ?> days</div>
                                <div class="stat-sub">Total: <?php echo safeNumberFormat($summary['total_days'] ?? 0, 0); ?> days</div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="summary-card">
                        <div class="filter-title" style="margin-bottom: 15px;">
                            <i class="bi bi-piggy-bank"></i>
                            Payment Collection Summary
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Total Payments</div>
                                <div class="summary-value"><?php echo safeNumberFormat($payment_summary['total_payments'] ?? 0, 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Collected</div>
                                <div class="summary-value profit">₹<?php echo safeNumberFormat($payment_summary['total_collected'] ?? 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Average Payment</div>
                                <div class="summary-value">₹<?php echo safeNumberFormat($payment_summary['avg_payment'] ?? 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Cash</div>
                                <div class="summary-value">₹<?php echo safeNumberFormat($payment_summary['cash_collected'] ?? 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Bank Transfer</div>
                                <div class="summary-value">₹<?php echo safeNumberFormat($payment_summary['bank_collected'] ?? 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">UPI</div>
                                <div class="summary-value">₹<?php echo safeNumberFormat($payment_summary['upi_collected'] ?? 0); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Cheque</div>
                                <div class="summary-value">₹<?php echo safeNumberFormat($payment_summary['cheque_collected'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <?php if (mysqli_num_rows($monthly_result) > 0 && mysqli_num_rows($bankwise_result) > 0): ?>
                    <?php 
                        // Prepare monthly chart data
                        $monthly_labels = [];
                        $monthly_counts = [];
                        $monthly_amounts = [];
                        $monthly_days = [];
                        
                        mysqli_data_seek($monthly_result, 0);
                        while($row = mysqli_fetch_assoc($monthly_result)) {
                            $monthly_labels[] = $row['month'];
                            $monthly_counts[] = intval($row['closed_count']);
                            $monthly_amounts[] = floatval($row['total_amount']);
                            $monthly_days[] = floatval($row['avg_days']);
                        }
                        
                        // Prepare bankwise chart data
                        $bankwise_labels = [];
                        $bankwise_amounts = [];
                        $bankwise_counts = [];
                        
                        mysqli_data_seek($bankwise_result, 0);
                        while($row = mysqli_fetch_assoc($bankwise_result)) {
                            $bankwise_labels[] = $row['bank_short_name'];
                            $bankwise_amounts[] = floatval($row['total_amount']);
                            $bankwise_counts[] = intval($row['loan_count']);
                        }
                    ?>
                    <div class="charts-row">
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-graph-up"></i>
                                Monthly Closed Loans
                            </div>
                            <div class="chart-container">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart"></i>
                                Bank-wise Distribution
                            </div>
                            <div class="chart-container">
                                <canvas id="bankwiseChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Bank-wise Summary Table -->
                    <?php if (mysqli_num_rows($bankwise_result) > 0): ?>
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-bank"></i>
                                Bank-wise Summary
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Bank</th>
                                        <th class="text-right">Total Loans</th>
                                        <th class="text-right">Closed</th>
                                        <th class="text-right">Defaulted</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Total Payable</th>
                                        <th class="text-right">Avg Rate</th>
                                        <th class="text-right">Avg Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($bankwise_result, 0);
                                    while($row = mysqli_fetch_assoc($bankwise_result)): 
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['bank_short_name']); ?></strong><br><small><?php echo htmlspecialchars($row['bank_full_name']); ?></small></td>
                                        <td class="text-right"><?php echo intval($row['loan_count']); ?></td>
                                        <td class="text-right positive"><?php echo intval($row['closed_count']); ?></td>
                                        <td class="text-right negative"><?php echo intval($row['defaulted_count']); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_amount']); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($row['total_interest']); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_payable']); ?></td>
                                        <td class="text-right"><?php echo safeNumberFormat($row['avg_rate'], 2); ?>%</td>
                                        <td class="text-right"><?php echo safeNumberFormat($row['avg_days'], 0); ?> days</td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Detailed Closed Loans Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Closed Loans Details
                                <span class="badge badge-info" style="margin-left: 10px;">
                                    <?php echo mysqli_num_rows($details_result); ?> records
                                </span>
                            </span>
                            <div>
                                <input type="text" id="tableSearch" class="search-box" placeholder="Search in table...">
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="report-table" id="reportTable">
                                <thead>
                                    <tr>
                                        <th>Close Date</th>
                                        <th>Loan Ref</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Charges</th>
                                        <th class="text-right">Total Payable</th>
                                        <th class="text-right">Total Paid</th>
                                        <th class="text-right">Profit/Loss</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th class="text-center">Items</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($details_result) > 0): ?>
                                        <?php while($row = mysqli_fetch_assoc($details_result)): 
                                            $total_paid = floatval($row['total_paid'] ?? 0);
                                            $total_payable = floatval($row['total_payable'] ?? 0);
                                            $profit_loss = $total_paid - $row['loan_amount'];
                                            $collection_ratio = $total_payable > 0 ? ($total_paid / $total_payable) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($row['close_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['loan_reference']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['bank_short_name']); ?><br><small><?php echo htmlspecialchars($row['account_holder_no']); ?></small></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                            <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                            <td class="text-right">₹<?php echo safeNumberFormat($row['total_interest']); ?></td>
                                            <td class="text-right">₹<?php echo safeNumberFormat(($row['document_charge'] ?? 0) + ($row['processing_fee'] ?? 0)); ?></td>
                                            <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_payable']); ?></td>
                                            <td class="text-right <?php echo $total_paid >= $total_payable ? 'positive' : 'negative'; ?>">
                                                ₹<?php echo safeNumberFormat($total_paid); ?>
                                                <br><small><?php echo safeNumberFormat($collection_ratio, 1); ?>%</small>
                                            </td>
                                            <td class="text-right <?php echo $profit_loss >= 0 ? 'profit' : 'loss'; ?>">
                                                ₹<?php echo safeNumberFormat($profit_loss); ?>
                                            </td>
                                            <td>
                                                <?php echo intval($row['loan_duration']); ?> days
                                                <br><small><?php echo safeNumberFormat($row['loan_duration'] / 30, 1); ?> months</small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $row['status']; ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-info"><?php echo intval($row['items_count']); ?></span>
                                                <br><small><?php echo intval($row['payment_count']); ?> payments</small>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="14" class="text-center" style="padding: 40px;">
                                                No closed loans found for the selected filters
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (mysqli_num_rows($details_result) > 0): ?>
                                <?php 
                                    // Calculate totals
                                    mysqli_data_seek($details_result, 0);
                                    $total_loan = 0;
                                    $total_interest = 0;
                                    $total_charges = 0;
                                    $total_payable = 0;
                                    $total_paid = 0;
                                    $total_profit = 0;
                                    
                                    while($row = mysqli_fetch_assoc($details_result)) {
                                        $total_loan += floatval($row['loan_amount'] ?? 0);
                                        $total_interest += floatval($row['total_interest'] ?? 0);
                                        $total_charges += (floatval($row['document_charge'] ?? 0) + floatval($row['processing_fee'] ?? 0));
                                        $total_payable += floatval($row['total_payable'] ?? 0);
                                        $total_paid += floatval($row['total_paid'] ?? 0);
                                    }
                                    $total_profit = $total_paid - $total_loan;
                                ?>
                                <tfoot style="background: #f7fafc; font-weight: 600;">
                                    <tr>
                                        <td colspan="5" class="text-right">Totals:</td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($total_loan); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_interest); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_charges); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($total_payable); ?></td>
                                        <td class="text-right positive">₹<?php echo safeNumberFormat($total_paid); ?></td>
                                        <td class="text-right <?php echo $total_profit >= 0 ? 'profit' : 'loss'; ?>">
                                            ₹<?php echo safeNumberFormat($total_profit); ?>
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Reset filters
        function resetFilters() {
            document.querySelector('input[name="from_date"]').value = '<?php echo date('Y-m-01'); ?>';
            document.querySelector('input[name="to_date"]').value = '<?php echo date('Y-m-d'); ?>';
            document.querySelector('select[name="bank_id"]').value = '0';
            document.querySelector('select[name="customer_id"]').value = '0';
            document.querySelector('select[name="report_type"]').value = 'closed';
            document.getElementById('filterForm').submit();
        }

        // Export report with SweetAlert
        function exportReport(format) {
            Swal.fire({
                title: 'Export Report',
                text: 'Do you want to export the current report as ' + format.toUpperCase() + '?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Export',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Exporting...',
                        text: 'Please wait while we generate your report',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        willOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Redirect to export URL
                    const form = document.getElementById('filterForm');
                    const url = new URL(window.location.href);
                    url.searchParams.set('export', format);
                    
                    // Preserve all current parameters
                    const params = new URLSearchParams(new FormData(form));
                    params.forEach((value, key) => {
                        url.searchParams.set(key, value);
                    });
                    
                    window.location.href = url.toString();
                }
            });
        }

        // Simple table search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const table = document.getElementById('reportTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                let found = false;
                const cells = rows[i].getElementsByTagName('td');
                
                for (let j = 0; j < cells.length; j++) {
                    const cellValue = cells[j].textContent || cells[j].innerText;
                    if (cellValue.toLowerCase().indexOf(searchValue) > -1) {
                        found = true;
                        break;
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        });

        // Initialize Charts
        <?php if (mysqli_num_rows($monthly_result) > 0): ?>
        // Monthly Chart
        new Chart(document.getElementById('monthlyChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($monthly_labels); ?>,
                datasets: [{
                    label: 'Number of Loans',
                    data: <?php echo json_encode($monthly_counts); ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.5)',
                    borderColor: '#667eea',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Average Duration (days)',
                    data: <?php echo json_encode($monthly_days); ?>,
                    type: 'line',
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Loans'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Days'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if (mysqli_num_rows($bankwise_result) > 0): ?>
        // Bankwise Chart
        new Chart(document.getElementById('bankwiseChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($bankwise_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($bankwise_amounts); ?>,
                    backgroundColor: [
                        '#667eea', '#48bb78', '#f56565', '#ecc94b', 
                        '#4299e1', '#9f7aea', '#ed8936', '#f687b3'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ₹${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Show success message if export was successful
        <?php if (isset($_GET['export']) && $_GET['export'] != ''): ?>
        Swal.fire({
            icon: 'success',
            title: 'Export Successful',
            text: 'Your report has been exported successfully',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        // Show error message if any
        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>