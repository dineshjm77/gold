<?php
session_start();
$currentPage = 'overall-collection-reports';
$pageTitle = 'Overall Collection Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : 'all';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Get branches for filter
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get employees for filter
$employees_query = "SELECT id, name, role FROM users WHERE status = 'active' ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Build WHERE clause based on filters
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

if (!empty($from_date) && !empty($to_date)) {
    $where_conditions[] = "p.payment_date BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $param_types .= "ss";
}

if ($branch_id > 0) {
    $where_conditions[] = "u.branch_id = ?";
    $params[] = $branch_id;
    $param_types .= "i";
}

if ($employee_id > 0) {
    $where_conditions[] = "p.employee_id = ?";
    $params[] = $employee_id;
    $param_types .= "i";
}

if ($payment_method != 'all') {
    $where_conditions[] = "p.payment_mode = ?";
    $params[] = $payment_method;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// ==================== SUMMARY STATISTICS ====================
$summary_query = "SELECT 
    COUNT(DISTINCT p.id) as total_payments,
    COUNT(DISTINCT p.loan_id) as loans_serviced,
    COUNT(DISTINCT c.id) as unique_customers,
    COUNT(DISTINCT p.employee_id) as collectors,
    SUM(p.principal_amount) as total_principal_collected,
    SUM(p.interest_amount) as total_interest_collected,
    SUM(p.overdue_charge) as total_overdue_charges,
    SUM(p.total_amount) as total_collected,
    AVG(p.total_amount) as avg_payment_amount,
    MAX(p.total_amount) as max_payment,
    MIN(p.total_amount) as min_payment,
    SUM(CASE WHEN p.payment_mode = 'cash' THEN p.total_amount ELSE 0 END) as cash_collected,
    SUM(CASE WHEN p.payment_mode = 'bank' THEN p.total_amount ELSE 0 END) as bank_collected,
    SUM(CASE WHEN p.payment_mode = 'upi' THEN p.total_amount ELSE 0 END) as upi_collected,
    SUM(CASE WHEN p.payment_mode = 'cheque' THEN p.total_amount ELSE 0 END) as cheque_collected,
    SUM(CASE WHEN p.payment_mode = 'other' THEN p.total_amount ELSE 0 END) as other_collected
FROM payments p
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN customers c ON l.customer_id = c.id
LEFT JOIN users u ON p.employee_id = u.id
WHERE $where_clause";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $summary_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $summary_result = mysqli_stmt_get_result($stmt);
        $summary = mysqli_fetch_assoc($summary_result);
        mysqli_stmt_close($stmt);
    } else {
        $summary = [];
    }
} else {
    $summary_result = mysqli_query($conn, $summary_query);
    $summary = mysqli_fetch_assoc($summary_result);
}

// ==================== DAILY COLLECTION TRENDS ====================
$daily_trends_query = "SELECT 
    p.payment_date,
    DAYNAME(p.payment_date) as day_name,
    COUNT(*) as payment_count,
    COUNT(DISTINCT p.loan_id) as loans_count,
    COUNT(DISTINCT p.employee_id) as collectors,
    SUM(p.principal_amount) as principal,
    SUM(p.interest_amount) as interest,
    SUM(p.overdue_charge) as charges,
    SUM(p.total_amount) as total,
    SUM(CASE WHEN p.payment_mode = 'cash' THEN p.total_amount ELSE 0 END) as cash,
    SUM(CASE WHEN p.payment_mode = 'bank' THEN p.total_amount ELSE 0 END) as bank,
    SUM(CASE WHEN p.payment_mode = 'upi' THEN p.total_amount ELSE 0 END) as upi
FROM payments p
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN users u ON p.employee_id = u.id
WHERE $where_clause
GROUP BY p.payment_date
ORDER BY p.payment_date DESC
LIMIT 30";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $daily_trends_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $daily_trends_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $daily_trends_result = false;
    }
} else {
    $daily_trends_result = mysqli_query($conn, $daily_trends_query);
}

// ==================== WEEKLY COLLECTION SUMMARY ====================
$weekly_summary_query = "SELECT 
    YEAR(p.payment_date) as year,
    WEEK(p.payment_date) as week,
    MIN(p.payment_date) as week_start,
    MAX(p.payment_date) as week_end,
    COUNT(*) as payment_count,
    COUNT(DISTINCT p.loan_id) as loans_serviced,
    SUM(p.total_amount) as total_collected,
    SUM(p.principal_amount) as total_principal,
    SUM(p.interest_amount) as total_interest,
    SUM(p.overdue_charge) as total_charges
FROM payments p
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN users u ON p.employee_id = u.id
WHERE $where_clause
GROUP BY YEAR(p.payment_date), WEEK(p.payment_date)
ORDER BY year DESC, week DESC
LIMIT 12";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $weekly_summary_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $weekly_summary_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $weekly_summary_result = false;
    }
} else {
    $weekly_summary_result = mysqli_query($conn, $weekly_summary_query);
}

// ==================== PAYMENT METHOD BREAKDOWN ====================
$method_breakdown_query = "SELECT 
    p.payment_mode,
    COUNT(*) as payment_count,
    COUNT(DISTINCT p.loan_id) as loans_serviced,
    SUM(p.total_amount) as total_amount,
    SUM(p.principal_amount) as total_principal,
    SUM(p.interest_amount) as total_interest,
    AVG(p.total_amount) as avg_amount
FROM payments p
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN users u ON p.employee_id = u.id
WHERE $where_clause
GROUP BY p.payment_mode
ORDER BY total_amount DESC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $method_breakdown_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $method_breakdown_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $method_breakdown_result = false;
    }
} else {
    $method_breakdown_result = mysqli_query($conn, $method_breakdown_query);
}

// ==================== EMPLOYEE PERFORMANCE ====================
// FIXED: This query doesn't use the same parameters as others
$employee_performance_query = "SELECT 
    u.id,
    u.name,
    u.role,
    b.branch_name,
    COUNT(DISTINCT p.id) as payments_made,
    COUNT(DISTINCT p.loan_id) as loans_serviced,
    COUNT(DISTINCT l.customer_id) as unique_customers,
    SUM(p.principal_amount) as principal_collected,
    SUM(p.interest_amount) as interest_collected,
    SUM(p.overdue_charge) as charges_collected,
    SUM(p.total_amount) as total_collected,
    AVG(p.total_amount) as avg_collection,
    MAX(p.total_amount) as max_collection,
    MIN(p.total_amount) as min_collection
FROM users u
LEFT JOIN payments p ON u.id = p.employee_id AND p.payment_date BETWEEN ? AND ?
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN branches b ON u.branch_id = b.id
WHERE u.status = 'active'
GROUP BY u.id
HAVING payments_made > 0
ORDER BY total_collected DESC";

$emp_stmt = mysqli_prepare($conn, $employee_performance_query);
if ($emp_stmt) {
    mysqli_stmt_bind_param($emp_stmt, 'ss', $from_date, $to_date);
    mysqli_stmt_execute($emp_stmt);
    $employee_performance_result = mysqli_stmt_get_result($emp_stmt);
    mysqli_stmt_close($emp_stmt);
} else {
    $employee_performance_result = false;
}

// ==================== CUSTOMER COLLECTION SUMMARY ====================
$customer_summary_query = "SELECT 
    c.id,
    c.customer_name,
    c.mobile_number,
    COUNT(DISTINCT p.id) as payments_made,
    COUNT(DISTINCT l.id) as loans_count,
    SUM(p.principal_amount) as principal_paid,
    SUM(p.interest_amount) as interest_paid,
    SUM(p.total_amount) as total_paid,
    MAX(p.payment_date) as last_payment_date,
    MIN(p.payment_date) as first_payment_date,
    DATEDIFF(MAX(p.payment_date), MIN(p.payment_date)) as payment_span
FROM customers c
JOIN loans l ON c.id = l.customer_id
JOIN payments p ON l.id = p.loan_id
WHERE $where_clause
GROUP BY c.id
ORDER BY total_paid DESC
LIMIT 20";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $customer_summary_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $customer_summary_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $customer_summary_result = false;
    }
} else {
    $customer_summary_result = mysqli_query($conn, $customer_summary_query);
}

// ==================== DETAILED COLLECTION REPORT ====================
$detailed_query = "SELECT 
    p.id,
    p.receipt_number,
    p.payment_date,
    DATE_FORMAT(p.payment_date, '%d-%m-%Y') as formatted_date,
    DAYNAME(p.payment_date) as day_name,
    p.principal_amount,
    p.interest_amount,
    p.overdue_charge,
    p.total_amount,
    p.payment_mode,
    p.remarks,
    l.id as loan_id,
    l.receipt_number as loan_receipt,
    l.loan_amount,
    c.id as customer_id,
    c.customer_name,
    c.mobile_number,
    u.id as employee_id,
    u.name as employee_name,
    b.branch_name
FROM payments p
JOIN loans l ON p.loan_id = l.id
JOIN customers c ON l.customer_id = c.id
LEFT JOIN users u ON p.employee_id = u.id
LEFT JOIN branches b ON u.branch_id = b.id
WHERE $where_clause
ORDER BY p.payment_date DESC, p.id DESC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $detailed_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $detailed_result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $detailed_result = false;
    }
} else {
    $detailed_result = mysqli_query($conn, $detailed_query);
}

// ==================== MONTHLY COMPARISON ====================
// FIXED: This query doesn't use the same parameters
$monthly_comparison_query = "SELECT 
    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
    YEAR(p.payment_date) as year,
    MONTH(p.payment_date) as month_num,
    COUNT(*) as payment_count,
    SUM(p.total_amount) as total_collected,
    SUM(p.principal_amount) as total_principal,
    SUM(p.interest_amount) as total_interest
FROM payments p
LEFT JOIN loans l ON p.loan_id = l.id
LEFT JOIN users u ON p.employee_id = u.id
WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
ORDER BY month DESC";

$monthly_comparison_result = mysqli_query($conn, $monthly_comparison_query);

// Calculate percentages for payment methods
$total_collected_amount = floatval($summary['total_collected'] ?? 1);

// Format currency function
function formatCurrency($amount) {
    return '₹ ' . number_format($amount, 2);
}

// Format percentage function
function formatPercentage($value) {
    return number_format($value, 1) . '%';
}

// Get status badge for growth
function getGrowthBadge($growth) {
    if ($growth > 0) {
        return '<span class="badge badge-success">+' . number_format($growth, 1) . '%</span>';
    } elseif ($growth < 0) {
        return '<span class="badge badge-danger">' . number_format($growth, 1) . '%</span>';
    } else {
        return '<span class="badge badge-secondary">0%</span>';
    }
}

// Handle export
if ($export == 'excel' || $export == 'csv') {
    $filename = 'collection_report_' . date('Ymd_His') . '.' . ($export == 'excel' ? 'xls' : 'csv');
    
    header('Content-Type: ' . ($export == 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($export == 'csv') {
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date', 'Receipt No', 'Loan Receipt', 'Customer', 'Mobile', 'Principal', 'Interest', 'Charges', 'Total', 'Method', 'Employee', 'Branch']);
    } else {
        echo "<table border='1'>";
        echo "<tr><th>Date</th><th>Receipt No</th><th>Loan Receipt</th><th>Customer</th><th>Mobile</th><th>Principal</th><th>Interest</th><th>Charges</th><th>Total</th><th>Method</th><th>Employee</th><th>Branch</th></tr>";
    }
    
    if ($detailed_result && mysqli_num_rows($detailed_result) > 0) {
        mysqli_data_seek($detailed_result, 0);
        while ($row = mysqli_fetch_assoc($detailed_result)) {
            $row_data = [
                $row['formatted_date'],
                $row['receipt_number'],
                $row['loan_receipt'],
                $row['customer_name'],
                $row['mobile_number'],
                formatCurrency($row['principal_amount']),
                formatCurrency($row['interest_amount']),
                formatCurrency($row['overdue_charge']),
                formatCurrency($row['total_amount']),
                ucfirst($row['payment_mode']),
                $row['employee_name'],
                $row['branch_name'] ?? 'Main'
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Same CSS as before - keeping it consistent */
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
            flex-wrap: wrap;
        }

        .search-box {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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

        .badge-purple {
            background: #9f7aea;
            color: white;
        }

        .badge-secondary {
            background: #a0aec0;
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

        /* Pagination */
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s;
            cursor: pointer;
            background: white;
        }

        .page-link:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .table-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            font-size: 13px;
            color: #718096;
        }

        .entries-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .entries-selector select {
            padding: 6px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-section {
                grid-template-columns: 1fr;
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
                            <i class="bi bi-cash-coin"></i>
                            Overall Collection Reports
                        </h1>
                    </div>

                    <!-- Report Type Tabs -->
                    <div class="report-tabs">
                        <a href="?report_type=summary&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>" class="tab-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-grid"></i> Summary View
                        </a>
                        <a href="?report_type=daily&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>" class="tab-btn <?php echo $report_type == 'daily' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-day"></i> Daily Breakdown
                        </a>
                        <a href="?report_type=employee&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>" class="tab-btn <?php echo $report_type == 'employee' ? 'active' : ''; ?>">
                            <i class="bi bi-people"></i> Employee Performance
                        </a>
                        <a href="?report_type=customer&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>" class="tab-btn <?php echo $report_type == 'customer' ? 'active' : ''; ?>">
                            <i class="bi bi-person-badge"></i> Top Customers
                        </a>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Reports
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
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

                                <div class="form-group">
                                    <label class="form-label">Employee</label>
                                    <div class="input-group">
                                        <i class="bi bi-person-badge input-icon"></i>
                                        <select class="form-select" name="employee_id">
                                            <option value="0">All Employees</option>
                                            <?php 
                                            if ($employees_result && mysqli_num_rows($employees_result) > 0) {
                                                mysqli_data_seek($employees_result, 0);
                                                while($emp = mysqli_fetch_assoc($employees_result)): 
                                            ?>
                                                <option value="<?php echo $emp['id']; ?>" <?php echo $employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($emp['name']); ?> (<?php echo ucfirst($emp['role']); ?>)
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Method</label>
                                    <div class="input-group">
                                        <i class="bi bi-credit-card input-icon"></i>
                                        <select class="form-select" name="payment_method">
                                            <option value="all">All Methods</option>
                                            <option value="cash" <?php echo $payment_method == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                            <option value="bank" <?php echo $payment_method == 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                            <option value="upi" <?php echo $payment_method == 'upi' ? 'selected' : ''; ?>>UPI</option>
                                            <option value="cheque" <?php echo $payment_method == 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                            <option value="other" <?php echo $payment_method == 'other' ? 'selected' : ''; ?>>Other</option>
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
                                <a href="?report_type=<?php echo $report_type; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>&payment_method=<?php echo $payment_method; ?>&export=excel" class="btn btn-success">
                                    <i class="bi bi-file-excel"></i> Export Excel
                                </a>
                                <a href="?report_type=<?php echo $report_type; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&branch_id=<?php echo $branch_id; ?>&employee_id=<?php echo $employee_id; ?>&payment_method=<?php echo $payment_method; ?>&export=csv" class="btn btn-info">
                                    <i class="bi bi-file-spreadsheet"></i> Export CSV
                                </a>
                            </div>
                        </form>
                    </div>

                    <?php if ($report_type == 'summary'): ?>
                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Collections</div>
                                <div class="stat-value"><?php echo formatCurrency($summary['total_collected'] ?? 0); ?></div>
                                <div class="stat-sub"><?php echo intval($summary['total_payments'] ?? 0); ?> payments</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #48bb78;">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Interest Collected</div>
                                <div class="stat-value" style="color: #48bb78;"><?php echo formatCurrency($summary['total_interest_collected'] ?? 0); ?></div>
                                <div class="stat-sub"><?php echo intval($summary['loans_serviced'] ?? 0); ?> loans serviced</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #ecc94b;">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Unique Customers</div>
                                <div class="stat-value" style="color: #ecc94b;"><?php echo intval($summary['unique_customers'] ?? 0); ?></div>
                                <div class="stat-sub">Avg payment: <?php echo formatCurrency($summary['avg_payment_amount'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #9f7aea;">
                                <i class="bi bi-credit-card"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Cash Collection</div>
                                <div class="stat-value" style="color: #9f7aea;"><?php echo formatCurrency($summary['cash_collected'] ?? 0); ?></div>
                                <div class="stat-sub">Bank: <?php echo formatCurrency($summary['bank_collected'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="charts-row">
                        <!-- Daily Trends Chart -->
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-graph-up"></i>
                                Daily Collection Trends
                            </div>
                            <div class="chart-container">
                                <canvas id="dailyTrendsChart"></canvas>
                            </div>
                        </div>

                        <!-- Payment Method Chart -->
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart"></i>
                                Payment Method Distribution
                            </div>
                            <div class="chart-container">
                                <canvas id="paymentMethodChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Sections -->
                    <div class="summary-section">
                        <!-- Payment Method Breakdown -->
                        <div class="summary-card">
                            <div class="summary-header">
                                <i class="bi bi-credit-card"></i>
                                <h3>Payment Methods</h3>
                            </div>
                            <?php 
                            if ($method_breakdown_result && mysqli_num_rows($method_breakdown_result) > 0):
                                mysqli_data_seek($method_breakdown_result, 0);
                                while($method = mysqli_fetch_assoc($method_breakdown_result)):
                                    $percentage = ($method['total_amount'] / $total_collected_amount) * 100;
                            ?>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo ucfirst($method['payment_mode']); ?></span>
                                <span class="summary-value"><?php echo formatCurrency($method['total_amount']); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                            </div>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <div class="summary-row">
                                <span class="summary-label">No data available</span>
                            </div>
                            <?php endif; ?>
                            <div class="summary-row" style="border-top: 2px solid #667eea; margin-top: 10px; padding-top: 10px;">
                                <span class="summary-label">Total</span>
                                <span class="summary-value"><?php echo formatCurrency($total_collected_amount); ?></span>
                            </div>
                        </div>

                        <!-- Weekly Summary -->
                        <div class="summary-card">
                            <div class="summary-header">
                                <i class="bi bi-calendar-week"></i>
                                <h3>Weekly Summary</h3>
                            </div>
                            <?php if ($weekly_summary_result && mysqli_num_rows($weekly_summary_result) > 0): ?>
                                <?php 
                                mysqli_data_seek($weekly_summary_result, 0);
                                while($week = mysqli_fetch_assoc($weekly_summary_result)): 
                                ?>
                                <div class="summary-row">
                                    <span class="summary-label">Week <?php echo $week['week']; ?> (<?php echo date('d/m', strtotime($week['week_start'])); ?>)</span>
                                    <span class="summary-value"><?php echo formatCurrency($week['total_collected']); ?> (<?php echo $week['payment_count']; ?> payments)</span>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="summary-row">
                                    <span class="summary-label">No weekly data available</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Monthly Comparison -->
                        <div class="summary-card">
                            <div class="summary-header">
                                <i class="bi bi-calendar-month"></i>
                                <h3>Monthly Comparison</h3>
                            </div>
                            <?php if ($monthly_comparison_result && mysqli_num_rows($monthly_comparison_result) > 0): ?>
                                <?php 
                                $prev_total = null;
                                mysqli_data_seek($monthly_comparison_result, 0);
                                while($month = mysqli_fetch_assoc($monthly_comparison_result)): 
                                    $growth = $prev_total ? (($month['total_collected'] - $prev_total) / $prev_total * 100) : 0;
                                    $prev_total = $month['total_collected'];
                                ?>
                                <div class="summary-row">
                                    <span class="summary-label"><?php echo date('M Y', strtotime($month['month'] . '-01')); ?></span>
                                    <span class="summary-value">
                                        <?php echo formatCurrency($month['total_collected']); ?>
                                        <?php if ($prev_total): ?>
                                            <?php echo getGrowthBadge($growth); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="summary-row">
                                    <span class="summary-label">No monthly data available</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php elseif ($report_type == 'employee'): ?>
                    <!-- Employee Performance Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-person-badge"></i>
                                Employee Performance
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Role</th>
                                        <th>Branch</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Loans</th>
                                        <th class="text-right">Customers</th>
                                        <th class="text-right">Principal</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Charges</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Average</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($employee_performance_result && mysqli_num_rows($employee_performance_result) > 0): ?>
                                        <?php while($emp = mysqli_fetch_assoc($employee_performance_result)): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($emp['name']); ?></strong></td>
                                            <td><?php echo ucfirst($emp['role']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['branch_name'] ?? 'Main'); ?></td>
                                            <td class="text-right"><?php echo intval($emp['payments_made']); ?></td>
                                            <td class="text-right"><?php echo intval($emp['loans_serviced']); ?></td>
                                            <td class="text-right"><?php echo intval($emp['unique_customers']); ?></td>
                                            <td class="text-right amount"><?php echo formatCurrency($emp['principal_collected']); ?></td>
                                            <td class="text-right positive"><?php echo formatCurrency($emp['interest_collected']); ?></td>
                                            <td class="text-right"><?php echo formatCurrency($emp['charges_collected']); ?></td>
                                            <td class="text-right amount"><?php echo formatCurrency($emp['total_collected']); ?></td>
                                            <td class="text-right"><?php echo formatCurrency($emp['avg_collection']); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="11" class="text-center" style="padding: 40px;">
                                                No employee performance data found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($report_type == 'customer'): ?>
                    <!-- Top Customers Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-trophy"></i>
                                Top Customers by Payment
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Loans</th>
                                        <th class="text-right">Principal Paid</th>
                                        <th class="text-right">Interest Paid</th>
                                        <th class="text-right">Total Paid</th>
                                        <th>Last Payment</th>
                                        <th>First Payment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($customer_summary_result && mysqli_num_rows($customer_summary_result) > 0): 
                                        $sno = 1;
                                        mysqli_data_seek($customer_summary_result, 0);
                                        while($cust = mysqli_fetch_assoc($customer_summary_result)): 
                                    ?>
                                    <tr>
                                        <td><?php echo $sno++; ?></td>
                                        <td><strong><?php echo htmlspecialchars($cust['customer_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($cust['mobile_number']); ?></td>
                                        <td class="text-right"><?php echo intval($cust['payments_made']); ?></td>
                                        <td class="text-right"><?php echo intval($cust['loans_count']); ?></td>
                                        <td class="text-right amount"><?php echo formatCurrency($cust['principal_paid']); ?></td>
                                        <td class="text-right positive"><?php echo formatCurrency($cust['interest_paid']); ?></td>
                                        <td class="text-right amount"><?php echo formatCurrency($cust['total_paid']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($cust['last_payment_date'])); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($cust['first_payment_date'])); ?></td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else: 
                                    ?>
                                    <tr>
                                        <td colspan="10" class="text-center" style="padding: 40px;">
                                            No customer payment data found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Detailed Collection Table (Always Show) -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Detailed Collections
                                <span class="badge badge-info" style="margin-left: 10px;" id="recordCount">
                                    <?php echo $detailed_result ? mysqli_num_rows($detailed_result) : 0; ?> records
                                </span>
                            </span>
                            <div>
                                <input type="text" id="tableSearch" class="search-box" placeholder="Search in table...">
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="report-table" id="detailedTable">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Loan Receipt</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Principal</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Charges</th>
                                        <th class="text-right">Total</th>
                                        <th>Method</th>
                                        <th>Employee</th>
                                        <th>Branch</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <?php if ($detailed_result && mysqli_num_rows($detailed_result) > 0): ?>
                                        <?php mysqli_data_seek($detailed_result, 0); ?>
                                        <?php while($row = mysqli_fetch_assoc($detailed_result)): ?>
                                        <tr>
                                            <td><?php echo $row['formatted_date']; ?><br><small><?php echo $row['day_name']; ?></small></td>
                                            <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                            <td><a href="view-loan.php?id=<?php echo $row['loan_id']; ?>" style="color: #667eea;"><?php echo htmlspecialchars($row['loan_receipt']); ?></a></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                            <td class="text-right amount"><?php echo formatCurrency($row['principal_amount']); ?></td>
                                            <td class="text-right positive"><?php echo formatCurrency($row['interest_amount']); ?></td>
                                            <td class="text-right"><?php echo formatCurrency($row['overdue_charge']); ?></td>
                                            <td class="text-right amount"><?php echo formatCurrency($row['total_amount']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $row['payment_mode'] == 'cash' ? 'success' : 
                                                        ($row['payment_mode'] == 'bank' ? 'info' : 
                                                        ($row['payment_mode'] == 'upi' ? 'purple' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($row['payment_mode']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['employee_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($row['branch_name'] ?? 'Main'); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-cash" style="font-size: 48px; color: #cbd5e0;"></i>
                                                <p style="margin-top: 10px;">No collection records found for the selected filters</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination Controls -->
                        <div class="table-info">
                            <div class="entries-selector">
                                <label>Show</label>
                                <select id="entriesPerPage" onchange="changeEntriesPerPage()">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="-1">All</option>
                                </select>
                                <label>entries</label>
                            </div>
                            <div id="tableInfo"></div>
                        </div>
                        <div class="pagination" id="pagination"></div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script>
        // ========== CHART INITIALIZATION ==========
        
        <?php if ($report_type == 'summary' && $daily_trends_result && mysqli_num_rows($daily_trends_result) > 0): ?>
        // Daily Trends Chart
        const dailyCtx = document.getElementById('dailyTrendsChart').getContext('2d');
        const dailyData = <?php 
            $dates = [];
            $totals = [];
            $principals = [];
            $interests = [];
            mysqli_data_seek($daily_trends_result, 0);
            while($row = mysqli_fetch_assoc($daily_trends_result)) {
                $dates[] = date('d/m', strtotime($row['payment_date']));
                $totals[] = floatval($row['total']);
                $principals[] = floatval($row['principal']);
                $interests[] = floatval($row['interest']);
            }
            echo json_encode(['dates' => $dates, 'totals' => $totals, 'principals' => $principals, 'interests' => $interests]);
        ?>;

        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.dates,
                datasets: [{
                    label: 'Total Collection',
                    data: dailyData.totals,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Principal',
                    data: dailyData.principals,
                    borderColor: '#48bb78',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4
                }, {
                    label: 'Interest',
                    data: dailyData.interests,
                    borderColor: '#ecc94b',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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

        // Payment Method Chart
        const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
        const methodData = <?php 
            $methods = [];
            $methodAmounts = [];
            if ($method_breakdown_result && mysqli_num_rows($method_breakdown_result) > 0) {
                mysqli_data_seek($method_breakdown_result, 0);
                while($row = mysqli_fetch_assoc($method_breakdown_result)) {
                    $methods[] = ucfirst($row['payment_mode']);
                    $methodAmounts[] = floatval($row['total_amount']);
                }
            }
            echo json_encode(['labels' => $methods, 'data' => $methodAmounts]);
        ?>;

        new Chart(methodCtx, {
            type: 'doughnut',
            data: {
                labels: methodData.labels,
                datasets: [{
                    data: methodData.data,
                    backgroundColor: [
                        '#48bb78',
                        '#4299e1',
                        '#9f7aea',
                        '#f56565',
                        '#ecc94b'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
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

        // ========== TABLE PAGINATION AND SEARCH ==========
        
        let currentPage = 1;
        let entriesPerPage = 25;
        let tableRows = [];
        let filteredRows = [];

        // Get all table rows
        function getTableRows() {
            const tbody = document.getElementById('tableBody');
            const rows = [];
            if (tbody) {
                for (let i = 0; i < tbody.children.length; i++) {
                    rows.push(tbody.children[i]);
                }
            }
            return rows;
        }

        // Initialize table rows
        function initializeTable() {
            tableRows = getTableRows();
            filteredRows = [...tableRows];
            // Filter out the "no records" row if it exists
            filteredRows = filteredRows.filter(row => !(row.children.length === 1 && row.children[0].colSpan === 12));
            updateTable();
        }

        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            if (searchTerm === '') {
                filteredRows = [...tableRows];
            } else {
                filteredRows = tableRows.filter(row => {
                    const cells = row.getElementsByTagName('td');
                    for (let i = 0; i < cells.length; i++) {
                        const cellText = cells[i].textContent || cells[i].innerText;
                        if (cellText.toLowerCase().includes(searchTerm)) {
                            return true;
                        }
                    }
                    return false;
                });
            }
            
            // Filter out the "no records" row if it exists
            filteredRows = filteredRows.filter(row => !(row.children.length === 1 && row.children[0].colSpan === 12));
            
            currentPage = 1;
            updateTable();
            updateRecordCount();
        });

        // Change entries per page
        function changeEntriesPerPage() {
            const select = document.getElementById('entriesPerPage');
            entriesPerPage = parseInt(select.value);
            if (isNaN(entriesPerPage)) entriesPerPage = filteredRows.length;
            currentPage = 1;
            updateTable();
        }

        // Update table display with pagination
        function updateTable() {
            const tbody = document.getElementById('tableBody');
            if (!tbody) return;

            // Hide all rows
            tableRows.forEach(row => row.style.display = 'none');
            
            if (filteredRows.length === 0) {
                // Show no records message
                const noRecordRow = tableRows.find(row => row.children.length === 1 && row.children[0].colSpan === 12);
                if (noRecordRow) {
                    noRecordRow.style.display = '';
                }
                updatePagination();
                updateInfo();
                return;
            }
            
            // Show filtered and paginated rows
            const start = (currentPage - 1) * entriesPerPage;
            const end = entriesPerPage === -1 ? filteredRows.length : start + entriesPerPage;
            
            for (let i = start; i < end && i < filteredRows.length; i++) {
                filteredRows[i].style.display = '';
            }
            
            updatePagination();
            updateInfo();
        }

        // Update pagination controls
        function updatePagination() {
            const totalPages = entriesPerPage === -1 ? 1 : Math.ceil(filteredRows.length / entriesPerPage);
            const pagination = document.getElementById('pagination');
            if (!pagination) return;
            
            if (filteredRows.length === 0) {
                pagination.innerHTML = '';
                return;
            }
            
            let html = '';
            
            // Previous button
            html += `<button class="page-link ${currentPage === 1 ? 'disabled' : ''}" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>Previous</button>`;
            
            // Page numbers
            const maxVisible = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                html += `<button class="page-link" onclick="changePage(1)">1</button>`;
                if (startPage > 2) html += `<span class="page-link disabled">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="page-link ${currentPage === i ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="page-link disabled">...</span>`;
                html += `<button class="page-link" onclick="changePage(${totalPages})">${totalPages}</button>`;
            }
            
            // Next button
            html += `<button class="page-link ${currentPage === totalPages ? 'disabled' : ''}" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Next</button>`;
            
            pagination.innerHTML = html;
        }

        // Change page
        function changePage(page) {
            const totalPages = entriesPerPage === -1 ? 1 : Math.ceil(filteredRows.length / entriesPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            updateTable();
        }

        // Update table info
        function updateInfo() {
            const info = document.getElementById('tableInfo');
            if (!info) return;
            
            if (filteredRows.length === 0) {
                info.textContent = 'Showing 0 to 0 of 0 entries';
                return;
            }
            
            const start = filteredRows.length === 0 ? 0 : (currentPage - 1) * entriesPerPage + 1;
            const end = Math.min(currentPage * entriesPerPage, filteredRows.length);
            
            info.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
        }

        // Update record count badge
        function updateRecordCount() {
            const countBadge = document.getElementById('recordCount');
            if (countBadge) {
                countBadge.textContent = filteredRows.length + ' records';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeTable();
        });

        // Reset filters
        function resetFilters() {
            window.location.href = 'overall-collection-reports.php';
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>