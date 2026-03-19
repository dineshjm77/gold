<?php
session_start();
$currentPage = 'principle-reports';
$pageTitle = 'Principal Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'accountant', 'manager'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'all';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Get branches for filter
$branches_query = "SELECT id, branch_name, branch_code FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);
$branches = [];
if ($branches_result) {
    while ($row = mysqli_fetch_assoc($branches_result)) {
        $branches[] = $row;
    }
}

// Get customers for filter
$customers_query = "SELECT id, customer_name, mobile_number FROM customers ORDER BY customer_name LIMIT 100";
$customers_result = mysqli_query($conn, $customers_query);
$customers = [];
if ($customers_result) {
    while ($row = mysqli_fetch_assoc($customers_result)) {
        $customers[] = $row;
    }
}

// Build filter conditions with proper table aliases
$where_conditions = [];
$params = [];
$types = '';

// Date range filter - explicitly use loans.created_at
if (!empty($date_from) && !empty($date_to)) {
    $where_conditions[] = "DATE(loans.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

// Branch filter
if ($branch_id > 0) {
    $where_conditions[] = "users.branch_id = ?";
    $params[] = $branch_id;
    $types .= 'i';
}

// Customer filter
if ($customer_id > 0) {
    $where_conditions[] = "loans.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

// Report type filter (these don't need parameters as they're literals)
if ($report_type != 'all') {
    if ($report_type == 'active') {
        $where_conditions[] = "loans.status = 'open'";
    } elseif ($report_type == 'closed') {
        $where_conditions[] = "loans.status = 'closed'";
    } elseif ($report_type == 'overdue') {
        $where_conditions[] = "loans.status = 'open' AND loans.total_overdue_amount > 0";
    } elseif ($report_type == 'high_value') {
        $where_conditions[] = "loans.loan_amount > 100000";
    }
}

// Build WHERE clause for prepared statements
$prep_where_clause = '';
if (!empty($where_conditions)) {
    $prep_where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// For regular queries, create a version with values embedded
// IMPORTANT: For these, we DON'T include table prefixes as they cause ambiguity in some queries
$string_conditions = [];

if (!empty($date_from) && !empty($date_to)) {
    $string_conditions[] = "DATE(loans.created_at) BETWEEN '$date_from' AND '$date_to'";
}

if ($branch_id > 0) {
    $string_conditions[] = "users.branch_id = $branch_id";
}

if ($customer_id > 0) {
    $string_conditions[] = "loans.customer_id = $customer_id";
}

if ($report_type != 'all') {
    if ($report_type == 'active') {
        $string_conditions[] = "loans.status = 'open'";
    } elseif ($report_type == 'closed') {
        $string_conditions[] = "loans.status = 'closed'";
    } elseif ($report_type == 'overdue') {
        $string_conditions[] = "loans.status = 'open' AND loans.total_overdue_amount > 0";
    } elseif ($report_type == 'high_value') {
        $string_conditions[] = "loans.loan_amount > 100000";
    }
}

$string_where_clause = '';
if (!empty($string_conditions)) {
    $string_where_clause = 'WHERE ' . implode(' AND ', $string_conditions);
}

// Get principal summary statistics
$summary_query = "SELECT 
                    COUNT(DISTINCT loans.id) as total_loans,
                    COALESCE(SUM(loans.loan_amount), 0) as total_principal,
                    COALESCE(SUM(loans.net_weight), 0) as total_weight,
                    COALESCE(AVG(loans.loan_amount), 0) as avg_principal,
                    COUNT(DISTINCT CASE WHEN loans.status = 'open' THEN loans.id END) as active_loans,
                    COALESCE(SUM(CASE WHEN loans.status = 'open' THEN loans.loan_amount ELSE 0 END), 0) as active_principal,
                    COUNT(DISTINCT CASE WHEN loans.status = 'closed' THEN loans.id END) as closed_loans,
                    COALESCE(SUM(CASE WHEN loans.status = 'closed' THEN loans.loan_amount ELSE 0 END), 0) as closed_principal,
                    COUNT(DISTINCT CASE WHEN loans.status = 'open' AND loans.total_overdue_amount > 0 THEN loans.id END) as overdue_loans,
                    COALESCE(SUM(CASE WHEN loans.status = 'open' AND loans.total_overdue_amount > 0 THEN loans.total_overdue_amount ELSE 0 END), 0) as total_overdue,
                    COUNT(DISTINCT loans.customer_id) as unique_customers,
                    MAX(loans.loan_amount) as max_loan,
                    MIN(loans.loan_amount) as min_loan
                  FROM loans
                  LEFT JOIN users ON loans.employee_id = users.id
                  $prep_where_clause";

$summary_stmt = mysqli_prepare($conn, $summary_query);
if ($summary_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($summary_stmt);
    $summary_result = mysqli_stmt_get_result($summary_stmt);
    $summary = mysqli_fetch_assoc($summary_result);
} else {
    $summary = [
        'total_loans' => 0, 'total_principal' => 0, 'total_weight' => 0, 'avg_principal' => 0,
        'active_loans' => 0, 'active_principal' => 0,
        'closed_loans' => 0, 'closed_principal' => 0,
        'overdue_loans' => 0, 'total_overdue' => 0,
        'unique_customers' => 0, 'max_loan' => 0, 'min_loan' => 0
    ];
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total 
                FROM loans
                LEFT JOIN users ON loans.employee_id = users.id
                $prep_where_clause";

$count_stmt = mysqli_prepare($conn, $count_query);
if ($count_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
} else {
    $total_records = 0;
}

// Pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$offset = ($page - 1) * $limit;
$total_pages = $total_records > 0 ? ceil($total_records / $limit) : 1;

// Get loans with details
$loans_query = "SELECT 
                    loans.id,
                    loans.receipt_number,
                    loans.receipt_date,
                    loans.created_at,
                    loans.customer_id,
                    loans.gross_weight,
                    loans.net_weight,
                    loans.product_value,
                    loans.loan_amount,
                    loans.interest_type,
                    loans.interest_amount as interest_rate,
                    loans.receipt_charge,
                    loans.status,
                    loans.close_date,
                    loans.payable_interest,
                    loans.payable_loan_amount,
                    loans.discount,
                    loans.round_off,
                    loans.total_overdue_amount,
                    loans.last_interest_paid_date,
                    customers.customer_name,
                    customers.mobile_number,
                    customers.guardian_name,
                    customers.guardian_type,
                    users.name as created_by,
                    branches.branch_name,
                    (SELECT COALESCE(SUM(payments.principal_amount), 0) FROM payments WHERE payments.loan_id = loans.id) as principal_paid,
                    (SELECT COALESCE(SUM(payments.interest_amount), 0) FROM payments WHERE payments.loan_id = loans.id) as interest_paid,
                    (SELECT COALESCE(SUM(payments.overdue_amount_paid), 0) FROM payments WHERE payments.loan_id = loans.id) as overdue_paid,
                    (SELECT COUNT(*) FROM payments WHERE payments.loan_id = loans.id) as payment_count
                FROM loans
                JOIN customers ON loans.customer_id = customers.id
                JOIN users ON loans.employee_id = users.id
                LEFT JOIN branches ON users.branch_id = branches.id
                $prep_where_clause
                ORDER BY loans.created_at DESC
                LIMIT ? OFFSET ?";

// Prepare parameters for main query
$main_params = $params;
$main_types = $types;
$main_params[] = $limit;
$main_params[] = $offset;
$main_types .= 'ii';

$loans = [];
$total_principal_amount = 0;
$total_principal_paid = 0;
$total_outstanding = 0;
$total_overdue_amount = 0;
$total_weight_sum = 0;

$loans_stmt = mysqli_prepare($conn, $loans_query);
if ($loans_stmt) {
    if (!empty($main_params)) {
        mysqli_stmt_bind_param($loans_stmt, $main_types, ...$main_params);
    }
    if (mysqli_stmt_execute($loans_stmt)) {
        $loans_result = mysqli_stmt_get_result($loans_stmt);
        while ($row = mysqli_fetch_assoc($loans_result)) {
            $loans[] = $row;
            $total_principal_amount += $row['loan_amount'];
            $total_principal_paid += $row['principal_paid'];
            $outstanding = $row['loan_amount'] - $row['principal_paid'];
            $total_outstanding += $outstanding;
            $total_overdue_amount += $row['total_overdue_amount'];
            $total_weight_sum += $row['net_weight'];
        }
    } else {
        $error = "Query execution failed: " . mysqli_stmt_error($loans_stmt);
    }
} else {
    $error = "Query preparation failed: " . mysqli_error($conn);
}

// Get monthly principal trend - FIXED: Use string WHERE clause with table prefixes
$monthly_query = "SELECT 
                    DATE_FORMAT(loans.created_at, '%Y-%m') as month,
                    COUNT(*) as loan_count,
                    COALESCE(SUM(loans.loan_amount), 0) as total_principal,
                    COALESCE(AVG(loans.loan_amount), 0) as avg_principal,
                    COALESCE(SUM(loans.net_weight), 0) as total_weight
                FROM loans
                LEFT JOIN users ON loans.employee_id = users.id
                $string_where_clause
                GROUP BY DATE_FORMAT(loans.created_at, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12";

$monthly_result = mysqli_query($conn, $monthly_query);
$monthly_data = [];
if ($monthly_result) {
    while ($row = mysqli_fetch_assoc($monthly_result)) {
        $monthly_data[] = $row;
    }
}

// Get top customers by principal - FIXED: Use string WHERE clause with table prefixes
$top_customers_query = "SELECT 
                            customers.id,
                            customers.customer_name,
                            customers.mobile_number,
                            COUNT(loans.id) as loan_count,
                            COALESCE(SUM(loans.loan_amount), 0) as total_principal,
                            COALESCE(SUM(loans.net_weight), 0) as total_weight,
                            MAX(loans.loan_amount) as max_loan
                        FROM customers
                        JOIN loans ON customers.id = loans.customer_id
                        LEFT JOIN users ON loans.employee_id = users.id
                        $string_where_clause
                        GROUP BY customers.id, customers.customer_name, customers.mobile_number
                        ORDER BY total_principal DESC
                        LIMIT 10";

$top_customers_result = mysqli_query($conn, $top_customers_query);
$top_customers = [];
if ($top_customers_result) {
    while ($row = mysqli_fetch_assoc($top_customers_result)) {
        $top_customers[] = $row;
    }
}

// Get branch-wise principal distribution - FIXED: Create a separate condition string without table prefixes
$branch_conditions = [];

if (!empty($date_from) && !empty($date_to)) {
    $branch_conditions[] = "DATE(loans.created_at) BETWEEN '$date_from' AND '$date_to'";
}

if ($branch_id > 0) {
    $branch_conditions[] = "users.branch_id = $branch_id";
}

if ($customer_id > 0) {
    $branch_conditions[] = "loans.customer_id = $customer_id";
}

if ($report_type != 'all') {
    if ($report_type == 'active') {
        $branch_conditions[] = "loans.status = 'open'";
    } elseif ($report_type == 'closed') {
        $branch_conditions[] = "loans.status = 'closed'";
    } elseif ($report_type == 'overdue') {
        $branch_conditions[] = "loans.status = 'open' AND loans.total_overdue_amount > 0";
    } elseif ($report_type == 'high_value') {
        $branch_conditions[] = "loans.loan_amount > 100000";
    }
}

$branch_where_clause = '';
if (!empty($branch_conditions)) {
    $branch_where_clause = 'WHERE ' . implode(' AND ', $branch_conditions);
}

$branch_query = "SELECT 
                    branches.id,
                    branches.branch_name,
                    branches.branch_code,
                    COUNT(loans.id) as loan_count,
                    COALESCE(SUM(loans.loan_amount), 0) as total_principal,
                    COALESCE(AVG(loans.loan_amount), 0) as avg_principal,
                    COALESCE(SUM(loans.net_weight), 0) as total_weight
                FROM branches
                LEFT JOIN users ON branches.id = users.branch_id
                LEFT JOIN loans ON users.id = loans.employee_id
                $branch_where_clause
                GROUP BY branches.id, branches.branch_name, branches.branch_code
                HAVING loan_count > 0
                ORDER BY total_principal DESC";

$branch_result = mysqli_query($conn, $branch_query);
$branch_stats = [];
if ($branch_result) {
    while ($row = mysqli_fetch_assoc($branch_result)) {
        $branch_stats[] = $row;
    }
}

// Handle export to Excel
if ($export == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="principal_report_' . date('Y-m-d') . '.xls"');
    
    echo "Principal Report\n";
    echo "Date Range: " . date('d-m-Y', strtotime($date_from)) . " to " . date('d-m-Y', strtotime($date_to)) . "\n\n";
    
    echo "Loan Receipt\tDate\tCustomer\tMobile\tWeight (g)\tLoan Amount\tPrincipal Paid\tOutstanding\tOverdue\tStatus\tCreated By\tBranch\n";
    
    foreach ($loans as $loan) {
        $outstanding = $loan['loan_amount'] - $loan['principal_paid'];
        echo $loan['receipt_number'] . "\t";
        echo date('d-m-Y', strtotime($loan['created_at'])) . "\t";
        echo $loan['customer_name'] . "\t";
        echo $loan['mobile_number'] . "\t";
        echo number_format($loan['net_weight'], 3) . "\t";
        echo number_format($loan['loan_amount'], 2) . "\t";
        echo number_format($loan['principal_paid'], 2) . "\t";
        echo number_format($outstanding, 2) . "\t";
        echo number_format($loan['total_overdue_amount'], 2) . "\t";
        echo ucfirst($loan['status']) . "\t";
        echo $loan['created_by'] . "\t";
        echo ($loan['branch_name'] ?? 'Main') . "\n";
    }
    
    echo "\n";
    echo "Summary\n";
    echo "Total Loans: " . $summary['total_loans'] . "\n";
    echo "Total Principal: " . number_format($summary['total_principal'], 2) . "\n";
    echo "Total Weight: " . number_format($summary['total_weight'], 3) . " g\n";
    echo "Average Principal: " . number_format($summary['avg_principal'], 2) . "\n";
    echo "Active Loans: " . $summary['active_loans'] . " (₹" . number_format($summary['active_principal'], 2) . ")\n";
    echo "Closed Loans: " . $summary['closed_loans'] . " (₹" . number_format($summary['closed_principal'], 2) . ")\n";
    echo "Overdue Loans: " . $summary['overdue_loans'] . " (₹" . number_format($summary['total_overdue'], 2) . ")\n";
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
    <style>
        /* All your existing CSS remains exactly the same */
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

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
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

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .section-title i {
            color: #667eea;
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

        .report-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .report-table tfoot td {
            padding: 12px;
            border-top: 2px solid #e2e8f0;
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

        .amount {
            font-weight: 600;
        }

        .amount-principal {
            color: #4299e1;
        }

        .amount-interest {
            color: #ecc94b;
        }

        .amount-overdue {
            color: #f56565;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            display: block;
            padding: 8px 12px;
            border-radius: 6px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
        }

        /* Summary Box */
        .summary-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #667eea30;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 600;
            color: #4a5568;
        }

        .summary-value {
            font-weight: 700;
        }

        /* Progress Bar */
        .progress {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        /* Overdue row */
        .overdue-row {
            background-color: #fff5f5;
        }
        
        .overdue-row:hover {
            background-color: #ffe5e5 !important;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media print {
            .sidebar, .topbar, .filter-card, .export-buttons, .btn, .pagination, footer {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-content {
                padding: 20px;
            }
            
            .stat-card, .section-card, .summary-box {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
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
                            <i class="bi bi-cash-stack"></i>
                            Principal Reports
                        </h1>
                        <div class="export-buttons">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-excel"></i> Export to Excel
                            </a>
                            <button class="btn btn-primary btn-sm" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print Report
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
                            Filter Principal Report
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Date From</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Date To</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" name="branch_id">
                                        <option value="0">All Branches</option>
                                        <?php foreach ($branches as $branch): ?>
                                            <option value="<?php echo $branch['id']; ?>" <?php echo $branch_id == $branch['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo $branch['branch_code']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id">
                                        <option value="0">All Customers</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo $customer['id']; ?>" <?php echo $customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($customer['customer_name']); ?> (<?php echo $customer['mobile_number']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Report Type</label>
                                    <select class="form-select" name="report_type">
                                        <option value="all" <?php echo $report_type == 'all' ? 'selected' : ''; ?>>All Loans</option>
                                        <option value="active" <?php echo $report_type == 'active' ? 'selected' : ''; ?>>Active Loans</option>
                                        <option value="closed" <?php echo $report_type == 'closed' ? 'selected' : ''; ?>>Closed Loans</option>
                                        <option value="overdue" <?php echo $report_type == 'overdue' ? 'selected' : ''; ?>>Overdue Loans</option>
                                        <option value="high_value" <?php echo $report_type == 'high_value' ? 'selected' : ''; ?>>High Value (>₹1L)</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Show</label>
                                    <select class="form-select" name="limit">
                                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200 per page</option>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Generate Report
                                </button>
                                <a href="principle-reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Principal</div>
                                <div class="stat-value">₹ <?php echo number_format($summary['total_principal'], 2); ?></div>
                                <div class="stat-sub"><?php echo $summary['total_loans']; ?> Loans</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Weight</div>
                                <div class="stat-value"><?php echo number_format($summary['total_weight'], 3); ?> g</div>
                                <div class="stat-sub">Avg: <?php echo number_format($summary['total_weight'] / max($summary['total_loans'], 1), 3); ?> g</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bar-chart"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Average Principal</div>
                                <div class="stat-value">₹ <?php echo number_format($summary['avg_principal'], 2); ?></div>
                                <div class="stat-sub">Min: ₹<?php echo number_format($summary['min_loan'], 2); ?> | Max: ₹<?php echo number_format($summary['max_loan'], 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Unique Customers</div>
                                <div class="stat-value"><?php echo $summary['unique_customers']; ?></div>
                                <div class="stat-sub">Active: <?php echo $summary['active_loans']; ?> loans</div>
                            </div>
                        </div>
                    </div>

                    <!-- Second Row Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Loans</div>
                                <div class="stat-value"><?php echo $summary['active_loans']; ?></div>
                                <div class="stat-sub amount-principal">₹ <?php echo number_format($summary['active_principal'], 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-x-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Closed Loans</div>
                                <div class="stat-value"><?php echo $summary['closed_loans']; ?></div>
                                <div class="stat-sub">₹ <?php echo number_format($summary['closed_principal'], 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card" style="background: #fff5f5;">
                            <div class="stat-icon" style="background: #f5656520; color: #f56565;">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Overdue Loans</div>
                                <div class="stat-value"><?php echo $summary['overdue_loans']; ?></div>
                                <div class="stat-sub amount-overdue">₹ <?php echo number_format($summary['total_overdue'], 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Outstanding</div>
                                <div class="stat-value amount-principal">₹ <?php echo number_format($total_outstanding, 2); ?></div>
                                <div class="stat-sub"><?php echo count($loans); ?> loans shown</div>
                            </div>
                        </div>
                    </div>

                    <!-- Branch-wise Distribution -->
                    <?php if (!empty($branch_stats) && $branch_id == 0): ?>
                    <div class="section-card">
                        <h3 class="section-title">
                            <i class="bi bi-building"></i>
                            Branch-wise Principal Distribution
                        </h3>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Branch</th>
                                        <th class="text-right">Loan Count</th>
                                        <th class="text-right">Total Principal</th>
                                        <th class="text-right">Average Principal</th>
                                        <th class="text-right">Total Weight (g)</th>
                                        <th class="text-right">% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($branch_stats as $branch): 
                                        $percentage = $summary['total_principal'] > 0 ? ($branch['total_principal'] / $summary['total_principal']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($branch['branch_name']); ?></strong> (<?php echo $branch['branch_code']; ?>)</td>
                                        <td class="text-right"><?php echo $branch['loan_count']; ?></td>
                                        <td class="text-right amount-principal">₹ <?php echo number_format($branch['total_principal'], 2); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($branch['avg_principal'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($branch['total_weight'], 3); ?></td>
                                        <td class="text-right">
                                            <?php echo number_format($percentage, 2); ?>%
                                            <div class="progress">
                                                <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Monthly Trend -->
                    <?php if (!empty($monthly_data)): ?>
                    <div class="section-card">
                        <h3 class="section-title">
                            <i class="bi bi-calendar-range"></i>
                            Monthly Principal Trend (Last 12 Months)
                        </h3>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-right">Loan Count</th>
                                        <th class="text-right">Total Principal</th>
                                        <th class="text-right">Average Principal</th>
                                        <th class="text-right">Total Weight (g)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_data as $month): ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                        <td class="text-right"><?php echo $month['loan_count']; ?></td>
                                        <td class="text-right amount-principal">₹ <?php echo number_format($month['total_principal'], 2); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($month['avg_principal'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($month['total_weight'], 3); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Top Customers -->
                    <?php if (!empty($top_customers)): ?>
                    <div class="section-card">
                        <h3 class="section-title">
                            <i class="bi bi-trophy"></i>
                            Top Customers by Principal
                        </h3>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Loan Count</th>
                                        <th class="text-right">Total Principal</th>
                                        <th class="text-right">Total Weight (g)</th>
                                        <th class="text-right">Max Loan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_customers as $customer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo $customer['mobile_number']; ?></td>
                                        <td class="text-right"><?php echo $customer['loan_count']; ?></td>
                                        <td class="text-right amount-principal">₹ <?php echo number_format($customer['total_principal'], 2); ?></td>
                                        <td class="text-right"><?php echo number_format($customer['total_weight'], 3); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($customer['max_loan'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Detailed Principal Report -->
                    <div class="section-card">
                        <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3 class="section-title" style="margin-bottom: 0; border-bottom: none;">
                                <i class="bi bi-table"></i>
                                Detailed Principal Report
                            </h3>
                            <span class="table-info">
                                Showing <?php echo count($loans); ?> of <?php echo $total_records; ?> loans
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Loan Receipt</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Weight (g)</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Principal Paid</th>
                                        <th class="text-right">Outstanding</th>
                                        <th class="text-right">Overdue</th>
                                        <th>Status</th>
                                        <th>Branch</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sno = $offset + 1;
                                    foreach ($loans as $loan): 
                                        $outstanding = $loan['loan_amount'] - $loan['principal_paid'];
                                        $is_overdue = $loan['total_overdue_amount'] > 0;
                                        $row_class = $is_overdue ? 'overdue-row' : '';
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><?php echo $sno++; ?></td>
                                        <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                                        <td><?php echo date('d-m-Y', strtotime($loan['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                        <td><?php echo $loan['mobile_number']; ?></td>
                                        <td class="text-right"><?php echo number_format($loan['net_weight'], 3); ?></td>
                                        <td class="text-right amount-principal">₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td class="text-right" style="color: #38a169;">₹ <?php echo number_format($loan['principal_paid'], 2); ?></td>
                                        <td class="text-right amount">₹ <?php echo number_format($outstanding, 2); ?></td>
                                        <td class="text-right <?php echo $is_overdue ? 'amount-overdue' : ''; ?>">
                                            <?php echo $loan['total_overdue_amount'] > 0 ? '₹ ' . number_format($loan['total_overdue_amount'], 2) : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $loan['status'] == 'open' ? 'success' : 
                                                    ($loan['status'] == 'closed' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['branch_name'] ?? 'Main'); ?></td>
                                        <td>
                                            <a href="view-loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-info btn-sm" target="_blank" style="padding: 3px 8px; font-size: 11px;">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($loans)): ?>
                                    <tr>
                                        <td colspan="13" class="text-center" style="padding: 50px; color: #718096;">
                                            <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                            No loans found matching your criteria
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="6" class="text-right"><strong>Totals:</strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_principal_amount, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_principal_paid, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_outstanding, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_overdue_amount, 2); ?></strong></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Summary Box -->
                    <div class="summary-box">
                        <h3 style="margin-bottom: 15px; color: #4a5568;">
                            <i class="bi bi-calculator"></i>
                            Principal Summary
                        </h3>
                        <div class="summary-row">
                            <span class="summary-label">Total Principal (All Loans):</span>
                            <span class="summary-value amount-principal">₹ <?php echo number_format($summary['total_principal'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Total Principal Paid:</span>
                            <span class="summary-value" style="color: #38a169;">₹ <?php echo number_format($total_principal_paid, 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Total Outstanding:</span>
                            <span class="summary-value amount-principal">₹ <?php echo number_format($total_outstanding, 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Total Overdue Amount:</span>
                            <span class="summary-value amount-overdue">₹ <?php echo number_format($summary['total_overdue'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Active Principal:</span>
                            <span class="summary-value">₹ <?php echo number_format($summary['active_principal'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Closed Principal:</span>
                            <span class="summary-value">₹ <?php echo number_format($summary['closed_principal'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Collection Ratio:</span>
                            <span class="summary-value">
                                <?php 
                                $collection_ratio = $summary['total_principal'] > 0 ? 
                                    ($total_principal_paid / $summary['total_principal']) * 100 : 0;
                                echo number_format($collection_ratio, 2); ?>%
                            </span>
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

        // Auto-submit form when limit changes
        document.querySelector('select[name="limit"]')?.addEventListener('change', function() {
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