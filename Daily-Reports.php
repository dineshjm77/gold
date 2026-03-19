<?php
session_start();
$currentPage = 'daily-reports';
$pageTitle = 'Daily Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get report date
$report_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$report_type = isset($_GET['type']) ? $_GET['type'] : 'summary';
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get branches for filter
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Build WHERE clause for branch filter
$branch_where = "";
if ($branch_id > 0) {
    $branch_where = " AND branch_id = $branch_id";
}

// ==================== LOAN STATISTICS ====================
// New loans created today
$new_loans_query = "SELECT 
                    COUNT(*) as total_loans,
                    SUM(loan_amount) as total_amount,
                    SUM(net_weight) as total_weight,
                    COUNT(DISTINCT customer_id) as unique_customers
                    FROM loans 
                    WHERE DATE(created_at) = '$report_date' $branch_where";
$new_loans_result = mysqli_query($conn, $new_loans_query);
$new_loans = mysqli_fetch_assoc($new_loans_result);

// Loans closed today
$closed_loans_query = "SELECT 
                       COUNT(*) as total_closed,
                       SUM(loan_amount) as closed_amount,
                       SUM(payable_interest) as total_interest,
                       SUM(loan_amount + payable_interest - COALESCE(discount, 0) + COALESCE(round_off, 0)) as total_received
                       FROM loans 
                       WHERE DATE(close_date) = '$report_date' AND status = 'closed' $branch_where";
$closed_loans_result = mysqli_query($conn, $closed_loans_query);
$closed_loans = mysqli_fetch_assoc($closed_loans_result);

// ==================== PAYMENT COLLECTIONS ====================
// Interest collections today
$interest_query = "SELECT 
                   COUNT(*) as payment_count,
                   SUM(interest_amount) as total_interest,
                   SUM(overdue_charge) as total_charges,
                   COUNT(DISTINCT loan_id) as loans_serviced
                   FROM payments 
                   WHERE DATE(payment_date) = '$report_date' 
                   AND interest_amount > 0 $branch_where";
$interest_result = mysqli_query($conn, $interest_query);
$interest_data = mysqli_fetch_assoc($interest_result);

// Principal collections today
$principal_query = "SELECT 
                    COUNT(*) as payment_count,
                    SUM(principal_amount) as total_principal,
                    COUNT(DISTINCT loan_id) as loans_serviced
                    FROM payments 
                    WHERE DATE(payment_date) = '$report_date' 
                    AND principal_amount > 0 $branch_where";
$principal_result = mysqli_query($conn, $principal_query);
$principal_data = mysqli_fetch_assoc($principal_result);

// ==================== CUSTOMER STATISTICS ====================
// New customers registered today
$new_customers_query = "SELECT 
                        COUNT(*) as total_new,
                        COUNT(DISTINCT referral_person) as referred_count
                        FROM customers 
                        WHERE DATE(created_at) = '$report_date' $branch_where";
$new_customers_result = mysqli_query($conn, $new_customers_query);
$new_customers = mysqli_fetch_assoc($new_customers_result);

// ==================== EXPENSE STATISTICS ====================
// Expenses for today
$expenses_query = "SELECT 
                   COUNT(*) as expense_count,
                   SUM(amount) as total_expenses,
                   COUNT(DISTINCT expense_type) as expense_types
                   FROM expense_details 
                   WHERE DATE(date) = '$report_date' $branch_where";
$expenses_result = mysqli_query($conn, $expenses_query);
$expenses_data = mysqli_fetch_assoc($expenses_result);

// ==================== INVESTMENT STATISTICS ====================
// New investments today
$investments_query = "SELECT 
                      COUNT(*) as investment_count,
                      SUM(investment_amount) as total_investment
                      FROM investments 
                      WHERE DATE(investment_date) = '$report_date' $branch_where";
$investments_result = mysqli_query($conn, $investments_query);
$investments_data = mysqli_fetch_assoc($investments_result);

// Investment returns today
$returns_query = "SELECT 
                  COUNT(*) as return_count,
                  SUM(payable_investment) as principal_returned,
                  SUM(payable_interest) as interest_paid
                  FROM investment_returns 
                  WHERE DATE(return_date) = '$report_date' $branch_where";
$returns_result = mysqli_query($conn, $returns_query);
$returns_data = mysqli_fetch_assoc($returns_result);

// ==================== BANK LOAN STATISTICS ====================
// Bank loans created today
$bank_loans_query = "SELECT 
                     COUNT(*) as loan_count,
                     SUM(loan_amount) as total_amount
                     FROM bank_loans 
                     WHERE DATE(loan_date) = '$report_date' $branch_where";
$bank_loans_result = mysqli_query($conn, $bank_loans_query);
$bank_loans = mysqli_fetch_assoc($bank_loans_result);

// Bank EMI collections today
$bank_emi_query = "SELECT 
                   COUNT(*) as payment_count,
                   SUM(payment_amount) as total_collected
                   FROM bank_loan_payments 
                   WHERE DATE(payment_date) = '$report_date' $branch_where";
$bank_emi_result = mysqli_query($conn, $bank_emi_query);
$bank_emi = mysqli_fetch_assoc($bank_emi_result);

// ==================== DETAILED REPORTS BASED ON TYPE ====================
switch ($report_type) {
    case 'loans':
        // Detailed new loans
        $details_query = "SELECT l.*, c.customer_name, c.mobile_number,
                          u.name as employee_name,
                          COUNT(li.id) as item_count
                          FROM loans l
                          LEFT JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON l.employee_id = u.id
                          LEFT JOIN loan_items li ON l.id = li.loan_id
                          WHERE DATE(l.created_at) = '$report_date' $branch_where
                          GROUP BY l.id
                          ORDER BY l.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'collections':
        // Detailed payment collections
        $details_query = "SELECT p.*, l.receipt_number, c.customer_name, c.mobile_number,
                          u.name as employee_name
                          FROM payments p
                          JOIN loans l ON p.loan_id = l.id
                          JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON p.employee_id = u.id
                          WHERE DATE(p.payment_date) = '$report_date' $branch_where
                          ORDER BY p.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'closed':
        // Detailed closed loans
        $details_query = "SELECT l.*, c.customer_name, c.mobile_number,
                          u.name as employee_name,
                          DATEDIFF(l.close_date, l.receipt_date) as duration_days
                          FROM loans l
                          LEFT JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON l.employee_id = u.id
                          WHERE DATE(l.close_date) = '$report_date' AND l.status = 'closed' $branch_where
                          ORDER BY l.close_date DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'customers':
        // Detailed new customers
        $details_query = "SELECT * FROM customers 
                          WHERE DATE(created_at) = '$report_date' $branch_where
                          ORDER BY created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'expenses':
        // Detailed expenses
        $details_query = "SELECT e.*, et.expense_type as category_name
                          FROM expense_details e
                          LEFT JOIN expense_types et ON e.expense_type = et.expense_type
                          WHERE DATE(e.date) = '$report_date' $branch_where
                          ORDER BY e.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'investments':
        // Detailed investments
        $details_query = "SELECT i.*, it.investment_type_name, inv.investor_name
                          FROM investments i
                          LEFT JOIN investment_types it ON i.investment_type = it.investment_type_name
                          LEFT JOIN investors inv ON i.investor_name = inv.investor_name
                          WHERE DATE(i.investment_date) = '$report_date' $branch_where
                          ORDER BY i.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'bank':
        // Detailed bank transactions
        $details_query = "SELECT bl.*, b.bank_short_name, c.customer_name
                          FROM bank_loans bl
                          LEFT JOIN bank_master b ON bl.bank_id = b.id
                          LEFT JOIN customers c ON bl.customer_id = c.id
                          WHERE DATE(bl.loan_date) = '$report_date' $branch_where
                          UNION ALL
                          SELECT bp.*, b.bank_short_name, c.customer_name
                          FROM bank_loan_payments bp
                          JOIN bank_loans bl ON bp.bank_loan_id = bl.id
                          LEFT JOIN bank_master b ON bl.bank_id = b.id
                          LEFT JOIN customers c ON bl.customer_id = c.id
                          WHERE DATE(bp.payment_date) = '$report_date' $branch_where
                          ORDER BY created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    default:
        // Summary - no detailed query needed
        $details_result = null;
}

// Handle export
if ($export == 'excel' || $export == 'csv') {
    $filename = 'daily_report_' . $report_date . '.' . ($export == 'excel' ? 'xls' : 'csv');
    
    header('Content-Type: ' . ($export == 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($export == 'csv') {
        $output = fopen('php://output', 'w');
    } else {
        echo "<table border='1'>";
    }
    
    // Export headers based on report type
    if ($report_type == 'loans') {
        $headers = ['Receipt No', 'Customer', 'Mobile', 'Loan Amount', 'Interest Type', 'Status', 'Employee', 'Items'];
    } elseif ($report_type == 'collections') {
        $headers = ['Receipt No', 'Customer', 'Mobile', 'Payment Type', 'Principal', 'Interest', 'Total', 'Method', 'Employee'];
    } elseif ($report_type == 'closed') {
        $headers = ['Receipt No', 'Customer', 'Mobile', 'Loan Amount', 'Interest', 'Total Received', 'Duration (Days)', 'Employee'];
    } elseif ($report_type == 'customers') {
        $headers = ['Name', 'Mobile', 'Email', 'Address', 'Guardian', 'Aadhaar', 'Created'];
    } elseif ($report_type == 'expenses') {
        $headers = ['Date', 'Expense Type', 'Details', 'Amount', 'Payment Method'];
    } else {
        $headers = ['Summary Report for ' . $report_date];
    }
    
    if ($export == 'csv') {
        fputcsv($output, $headers);
    } else {
        echo "<tr>";
        foreach ($headers as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
    }
    
    // Output data
    if ($details_result && mysqli_num_rows($details_result) > 0) {
        while ($row = mysqli_fetch_assoc($details_result)) {
            $row_data = [];
            
            if ($report_type == 'loans') {
                $row_data = [
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    $row['interest_type'] ?? '',
                    ucfirst($row['status'] ?? ''),
                    $row['employee_name'] ?? '',
                    $row['item_count'] ?? 0
                ];
            } elseif ($report_type == 'collections') {
                $row_data = [
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    $row['principal_amount'] > 0 ? 'Principal' : 'Interest',
                    safeNumberFormat($row['principal_amount'] ?? 0),
                    safeNumberFormat($row['interest_amount'] ?? 0),
                    safeNumberFormat($row['total_amount'] ?? 0),
                    ucfirst($row['payment_mode'] ?? 'cash'),
                    $row['employee_name'] ?? ''
                ];
            } elseif ($report_type == 'closed') {
                $row_data = [
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    safeNumberFormat($row['payable_interest'] ?? 0),
                    safeNumberFormat(($row['loan_amount'] ?? 0) + ($row['payable_interest'] ?? 0) - ($row['discount'] ?? 0)),
                    $row['duration_days'] ?? 0,
                    $row['employee_name'] ?? ''
                ];
            } elseif ($report_type == 'customers') {
                $row_data = [
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    $row['email'] ?? '',
                    $row['street_name'] . ', ' . $row['city'] . ', ' . $row['district'],
                    $row['guardian_name'] ?? '',
                    $row['aadhaar_number'] ?? '',
                    date('d-m-Y H:i', strtotime($row['created_at']))
                ];
            } elseif ($report_type == 'expenses') {
                $row_data = [
                    date('d-m-Y', strtotime($row['date'])),
                    $row['category_name'] ?? $row['expense_type'],
                    $row['detail'] ?? '',
                    safeNumberFormat($row['amount'] ?? 0),
                    ucfirst($row['payment_method'] ?? 'cash')
                ];
            }
            
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

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
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

        /* Date Selection Card */
        .date-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .date-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
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

        .date-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Stats Grid */
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
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
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

        /* Report Type Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            background: #f7fafc;
            color: #4a5568;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .tab-btn:hover {
            background: #ebf4ff;
            color: #667eea;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        /* Summary Cards */
        .summary-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .summary-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .summary-header i {
            font-size: 20px;
            color: #667eea;
        }

        .summary-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: #718096;
            font-size: 14px;
        }

        .summary-value {
            font-weight: 600;
            color: #2d3748;
        }

        .positive {
            color: #48bb78;
        }

        .negative {
            color: #f56565;
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

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-warning {
            background: #ecc94b;
            color: #744210;
        }

        .badge-danger {
            background: #f56565;
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

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .date-grid {
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
            
            .report-tabs {
                flex-direction: column;
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
                            <i class="bi bi-calendar-day"></i>
                            Daily Reports
                        </h1>
                    </div>

                    <!-- Date Selection -->
                    <div class="date-card">
                        <div class="date-title">
                            <i class="bi bi-calendar-range"></i>
                            Select Date
                        </div>
                        
                        <form method="GET" action="" id="reportForm">
                            <div class="date-grid">
                                <div class="form-group">
                                    <label class="form-label">Report Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date" value="<?php echo $report_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <div class="input-group">
                                        <i class="bi bi-building input-icon"></i>
                                        <select class="form-select" name="branch_id">
                                            <option value="0">All Branches</option>
                                            <?php 
                                            if ($branches_result && mysqli_num_rows($branches_result) > 0) {
                                                mysqli_data_seek($branches_result, 0);
                                                while($branch = mysqli_fetch_assoc($branches_result)): 
                                            ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $branch_id == $branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="date-actions">
                                    <button type="button" class="btn btn-secondary" onclick="setToday()">
                                        <i class="bi bi-calendar-check"></i> Today
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> View Report
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Report Type Tabs -->
                    <div class="report-tabs">
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=summary" 
                           class="tab-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-grid"></i> Summary
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=loans" 
                           class="tab-btn <?php echo $report_type == 'loans' ? 'active' : ''; ?>">
                            <i class="bi bi-plus-circle"></i> New Loans
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=collections" 
                           class="tab-btn <?php echo $report_type == 'collections' ? 'active' : ''; ?>">
                            <i class="bi bi-cash-coin"></i> Collections
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=closed" 
                           class="tab-btn <?php echo $report_type == 'closed' ? 'active' : ''; ?>">
                            <i class="bi bi-check2-circle"></i> Closed Loans
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=customers" 
                           class="tab-btn <?php echo $report_type == 'customers' ? 'active' : ''; ?>">
                            <i class="bi bi-people"></i> New Customers
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=expenses" 
                           class="tab-btn <?php echo $report_type == 'expenses' ? 'active' : ''; ?>">
                            <i class="bi bi-wallet2"></i> Expenses
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=investments" 
                           class="tab-btn <?php echo $report_type == 'investments' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up-arrow"></i> Investments
                        </a>
                        <a href="?date=<?php echo $report_date; ?>&branch_id=<?php echo $branch_id; ?>&type=bank" 
                           class="tab-btn <?php echo $report_type == 'bank' ? 'active' : ''; ?>">
                            <i class="bi bi-bank"></i> Bank
                        </a>
                    </div>

                    <?php if ($report_type == 'summary'): ?>
                        <!-- Summary View - All Statistics -->
                        
                        <!-- Key Metrics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-plus-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">New Loans</div>
                                    <div class="stat-value"><?php echo intval($new_loans['total_loans'] ?? 0); ?></div>
                                    <div class="stat-sub">Amount: ₹<?php echo safeNumberFormat($new_loans['total_amount'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-check2-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Closed Loans</div>
                                    <div class="stat-value"><?php echo intval($closed_loans['total_closed'] ?? 0); ?></div>
                                    <div class="stat-sub">Received: ₹<?php echo safeNumberFormat($closed_loans['total_received'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-cash-coin"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Collections</div>
                                    <div class="stat-value">₹<?php echo safeNumberFormat(($interest_data['total_interest'] ?? 0) + ($principal_data['total_principal'] ?? 0)); ?></div>
                                    <div class="stat-sub">Interest: ₹<?php echo safeNumberFormat($interest_data['total_interest'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">New Customers</div>
                                    <div class="stat-value"><?php echo intval($new_customers['total_new'] ?? 0); ?></div>
                                    <div class="stat-sub">Referred: <?php echo intval($new_customers['referred_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Detailed Summary Sections -->
                        <div class="summary-section">
                            <!-- Loan Summary -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-cash-stack"></i>
                                    <h3>Loan Summary</h3>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">New Loans:</span>
                                    <span class="summary-value"><?php echo intval($new_loans['total_loans'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Loan Amount:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($new_loans['total_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Weight:</span>
                                    <span class="summary-value"><?php echo safeNumberFormat($new_loans['total_weight'] ?? 0, 3); ?> g</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Unique Customers:</span>
                                    <span class="summary-value"><?php echo intval($new_loans['unique_customers'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Closed Loans:</span>
                                    <span class="summary-value"><?php echo intval($closed_loans['total_closed'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Closed Amount:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($closed_loans['closed_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Interest Earned:</span>
                                    <span class="summary-value positive">₹<?php echo safeNumberFormat($closed_loans['total_interest'] ?? 0); ?></span>
                                </div>
                            </div>

                            <!-- Collection Summary -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-cash-coin"></i>
                                    <h3>Collection Summary</h3>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Interest Payments:</span>
                                    <span class="summary-value"><?php echo intval($interest_data['payment_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Interest Collected:</span>
                                    <span class="summary-value positive">₹<?php echo safeNumberFormat($interest_data['total_interest'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Overdue Charges:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($interest_data['total_charges'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Loans Serviced:</span>
                                    <span class="summary-value"><?php echo intval($interest_data['loans_serviced'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row" style="border-top: 2px solid #e2e8f0; margin-top: 10px; padding-top: 10px;">
                                    <span class="summary-label">Principal Payments:</span>
                                    <span class="summary-value"><?php echo intval($principal_data['payment_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Principal Collected:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($principal_data['total_principal'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row total">
                                    <span class="summary-label">Total Collection:</span>
                                    <span class="summary-value amount positive">₹<?php echo safeNumberFormat(($interest_data['total_interest'] ?? 0) + ($principal_data['total_principal'] ?? 0) + ($interest_data['total_charges'] ?? 0)); ?></span>
                                </div>
                            </div>

                            <!-- Other Transactions -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <h3>Other Transactions</h3>
                                </div>
                                
                                <h4 style="font-size: 14px; margin: 10px 0; color: #4a5568;">Expenses</h4>
                                <div class="summary-row">
                                    <span class="summary-label">Total Expenses:</span>
                                    <span class="summary-value negative">₹<?php echo safeNumberFormat($expenses_data['total_expenses'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Expense Count:</span>
                                    <span class="summary-value"><?php echo intval($expenses_data['expense_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Categories:</span>
                                    <span class="summary-value"><?php echo intval($expenses_data['expense_types'] ?? 0); ?></span>
                                </div>

                                <h4 style="font-size: 14px; margin: 15px 0 10px; color: #4a5568;">Investments</h4>
                                <div class="summary-row">
                                    <span class="summary-label">New Investments:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($investments_data['total_investment'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Investment Count:</span>
                                    <span class="summary-value"><?php echo intval($investments_data['investment_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Returns Processed:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($returns_data['principal_returned'] ?? 0); ?></span>
                                </div>

                                <h4 style="font-size: 14px; margin: 15px 0 10px; color: #4a5568;">Bank</h4>
                                <div class="summary-row">
                                    <span class="summary-label">New Bank Loans:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($bank_loans['total_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Bank EMI Collected:</span>
                                    <span class="summary-value positive">₹<?php echo safeNumberFormat($bank_emi['total_collected'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Daily Chart -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">
                                    <i class="bi bi-graph-up"></i>
                                    Daily Activity Overview
                                </span>
                            </div>
                            <div style="height: 300px;">
                                <canvas id="dailyChart"></canvas>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Detailed View with Table -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">
                                    <i class="bi bi-list-ul"></i>
                                    <?php 
                                    switch($report_type) {
                                        case 'loans': echo 'New Loans - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'collections': echo 'Collections - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'closed': echo 'Closed Loans - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'customers': echo 'New Customers - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'expenses': echo 'Expenses - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'investments': echo 'Investments - ' . date('d-m-Y', strtotime($report_date)); break;
                                        case 'bank': echo 'Bank Transactions - ' . date('d-m-Y', strtotime($report_date)); break;
                                    }
                                    ?>
                                    <span class="badge badge-info" style="margin-left: 10px;">
                                        <?php echo $details_result ? mysqli_num_rows($details_result) : 0; ?> records
                                    </span>
                                </span>
                                <div class="export-buttons">
                                    <button class="btn btn-success btn-sm" onclick="exportReport('excel')">
                                        <i class="bi bi-file-excel"></i> Excel
                                    </button>
                                    <button class="btn btn-info btn-sm" onclick="exportReport('csv')">
                                        <i class="bi bi-file-spreadsheet"></i> CSV
                                    </button>
                                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <?php if ($report_type == 'loans'): ?>
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th>Interest Type</th>
                                            <th>Status</th>
                                            <th>Employee</th>
                                            <th class="text-center">Items</th>
                                        </tr>
                                        <?php elseif ($report_type == 'collections'): ?>
                                        <tr>
                                            <th>Time</th>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th>Type</th>
                                            <th class="text-right">Principal</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">Total</th>
                                            <th>Method</th>
                                            <th>Employee</th>
                                        </tr>
                                        <?php elseif ($report_type == 'closed'): ?>
                                        <tr>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">Total Received</th>
                                            <th class="text-right">Duration</th>
                                            <th>Employee</th>
                                        </tr>
                                        <?php elseif ($report_type == 'customers'): ?>
                                        <tr>
                                            <th>Time</th>
                                            <th>Name</th>
                                            <th>Mobile</th>
                                            <th>Email</th>
                                            <th>Guardian</th>
                                            <th>Address</th>
                                            <th>Aadhaar</th>
                                        </tr>
                                        <?php elseif ($report_type == 'expenses'): ?>
                                        <tr>
                                            <th>Time</th>
                                            <th>Expense Type</th>
                                            <th>Details</th>
                                            <th class="text-right">Amount</th>
                                            <th>Payment Method</th>
                                        </tr>
                                        <?php elseif ($report_type == 'investments'): ?>
                                        <tr>
                                            <th>Time</th>
                                            <th>Investment No</th>
                                            <th>Investor</th>
                                            <th>Type</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">Interest</th>
                                            <th>Payment Method</th>
                                        </tr>
                                        <?php elseif ($report_type == 'bank'): ?>
                                        <tr>
                                            <th>Time</th>
                                            <th>Transaction</th>
                                            <th>Reference</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th class="text-right">Amount</th>
                                            <th>Type</th>
                                        </tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody>
                                        <?php if ($details_result && mysqli_num_rows($details_result) > 0): ?>
                                            <?php while($row = mysqli_fetch_assoc($details_result)): ?>
                                                <?php if ($report_type == 'loans'): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td><?php echo ucfirst($row['interest_type']); ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $row['status'] == 'open' ? 'success' : ($row['status'] == 'closed' ? 'info' : 'warning'); ?>">
                                                            <?php echo ucfirst($row['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                    <td class="text-center"><?php echo intval($row['item_count']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'collections'): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td>
                                                        <?php if ($row['principal_amount'] > 0 && $row['interest_amount'] > 0): ?>
                                                            Both
                                                        <?php elseif ($row['principal_amount'] > 0): ?>
                                                            Principal
                                                        <?php else: ?>
                                                            Interest
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right">₹<?php echo safeNumberFormat($row['principal_amount']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['interest_amount']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_amount']); ?></td>
                                                    <td><?php echo ucfirst($row['payment_mode']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'closed'): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['payable_interest']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat(($row['loan_amount'] ?? 0) + ($row['payable_interest'] ?? 0) - ($row['discount'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo intval($row['duration_days']); ?> days</td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'customers'): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['guardian_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['street_name'] . ', ' . $row['city']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['aadhaar_number']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'expenses'): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                                                    <td><?php echo htmlspecialchars($row['category_name'] ?? $row['expense_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['detail']); ?></td>
                                                    <td class="text-right negative">₹<?php echo safeNumberFormat($row['amount']); ?></td>
                                                    <td><?php echo ucfirst($row['payment_method']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'investments'): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                                                    <td><strong>INV-<?php echo str_pad($row['investment_no'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['investor_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['investment_type_name']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['investment_amount']); ?></td>
                                                    <td class="text-right"><?php echo safeNumberFormat($row['interest']); ?>%</td>
                                                    <td><?php echo ucfirst($row['payment_method']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'bank'): ?>
                                                <tr>
                                                    <td><?php echo date('H:i', strtotime($row['created_at'])); ?></td>
                                                    <td>
                                                        <?php if (isset($row['loan_reference'])): ?>
                                                            <strong><?php echo htmlspecialchars($row['loan_reference']); ?></strong>
                                                        <?php else: ?>
                                                            <strong>EMI Payment</strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (isset($row['receipt_number'])): ?>
                                                            <?php echo htmlspecialchars($row['receipt_number']); ?>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($row['loan_reference'] ?? ''); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($row['bank_short_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount'] ?? $row['payment_amount'] ?? 0); ?></td>
                                                    <td>
                                                        <?php if (isset($row['loan_reference'])): ?>
                                                            <span class="badge badge-success">New Loan</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-info">EMI</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center" style="padding: 40px;">
                                                    No records found for <?php echo date('d-m-Y', strtotime($report_date)); ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
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

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date picker
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Set to today's date
        function setToday() {
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('input[name="date"]').value = today;
            document.getElementById('reportForm').submit();
        }

        // Export report
        function exportReport(format) {
            const url = new URL(window.location.href);
            url.searchParams.set('export', format);
            window.location.href = url.toString();
        }

        <?php if ($report_type == 'summary'): ?>
        // Daily Chart
        const ctx = document.getElementById('dailyChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['New Loans', 'Closed Loans', 'Interest', 'Principal', 'Expenses', 'Investments'],
                datasets: [{
                    label: 'Amount (₹)',
                    data: [
                        <?php echo $new_loans['total_amount'] ?? 0; ?>,
                        <?php echo $closed_loans['total_received'] ?? 0; ?>,
                        <?php echo $interest_data['total_interest'] ?? 0; ?>,
                        <?php echo $principal_data['total_principal'] ?? 0; ?>,
                        <?php echo $expenses_data['total_expenses'] ?? 0; ?>,
                        <?php echo $investments_data['total_investment'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#4299e1',
                        '#48bb78',
                        '#ecc94b',
                        '#9f7aea',
                        '#f56565',
                        '#ed8936'
                    ],
                    borderWidth: 0
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
        <?php endif; ?>

        // Show success/error messages
        <?php if (!empty($message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>