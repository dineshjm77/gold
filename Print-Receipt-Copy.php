<?php
session_start();
$currentPage = 'print-receipt-copy';
$pageTitle = 'Print Receipt Copy';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters with default values
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : 'all';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Validate limit
if ($limit <= 0) $limit = 50;
if ($offset < 0) $offset = 0;

// Build the query based on filters
$where_conditions = [];
$params = [];
$types = '';

// Date range filter
if (!empty($date_from) && !empty($date_to)) {
    $where_conditions[] = "DATE(p.payment_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

// Search filter
if (!empty($search_term)) {
    $search_term = "%$search_term%";
    
    if ($filter_type == 'receipt') {
        $where_conditions[] = "p.receipt_number LIKE ?";
        $params[] = $search_term;
        $types .= 's';
    } elseif ($filter_type == 'name') {
        $where_conditions[] = "c.customer_name LIKE ?";
        $params[] = $search_term;
        $types .= 's';
    } elseif ($filter_type == 'mobile') {
        $where_conditions[] = "c.mobile_number LIKE ?";
        $params[] = $search_term;
        $types .= 's';
    } elseif ($filter_type == 'loan_receipt') {
        $where_conditions[] = "l.receipt_number LIKE ?";
        $params[] = $search_term;
        $types .= 's';
    } elseif ($filter_type == 'all') {
        $where_conditions[] = "(p.receipt_number LIKE ? OR c.customer_name LIKE ? OR c.mobile_number LIKE ? OR l.receipt_number LIKE ?)";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'ssss';
    }
}

// Payment type filter - ADDED OVERDUE COLLECTION OPTION
if ($payment_type != 'all') {
    if ($payment_type == 'principal') {
        $where_conditions[] = "p.principal_amount > 0 AND p.interest_amount = 0";
    } elseif ($payment_type == 'interest') {
        $where_conditions[] = "p.interest_amount > 0 AND p.principal_amount = 0";
    } elseif ($payment_type == 'both') {
        $where_conditions[] = "p.principal_amount > 0 AND p.interest_amount > 0";
    } elseif ($payment_type == 'advance') {
        $where_conditions[] = "p.receipt_number LIKE 'ADV%'";
    } elseif ($payment_type == 'closure') {
        $where_conditions[] = "p.receipt_number LIKE 'CLS%'";
    } elseif ($payment_type == 'overdue') {
        $where_conditions[] = "p.includes_overdue = 1 OR p.overdue_amount_paid > 0";
    } elseif ($payment_type == 'overdue_collection') {
        $where_conditions[] = "p.includes_overdue = 1 AND p.overdue_amount_paid > 0";
    }
}

// Build WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total 
                FROM payments p
                JOIN loans l ON p.loan_id = l.id
                JOIN customers c ON l.customer_id = c.id
                $where_clause";

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
    $error = "Database error: " . mysqli_error($conn);
}
$total_pages = $limit > 0 ? ceil($total_records / $limit) : 1;
$current_page = $limit > 0 ? floor($offset / $limit) + 1 : 1;

// Get receipts with pagination - ADDED overdue fields
$query = "SELECT 
            p.id as payment_id,
            p.receipt_number,
            p.payment_date,
            p.principal_amount,
            p.interest_amount,
            p.total_amount,
            p.payment_mode,
            p.remarks,
            p.includes_overdue,
            p.overdue_amount_paid,
            l.id as loan_id,
            l.receipt_number as loan_receipt,
            l.loan_amount,
            l.interest_amount as loan_interest_rate,
            l.total_overdue_amount,
            c.id as customer_id,
            c.customer_name,
            c.mobile_number,
            c.whatsapp_number,
            c.guardian_name,
            c.guardian_type,
            u.name as collected_by
          FROM payments p
          JOIN loans l ON p.loan_id = l.id
          JOIN customers c ON l.customer_id = c.id
          JOIN users u ON p.employee_id = u.id
          $where_clause
          ORDER BY p.payment_date DESC, p.id DESC
          LIMIT ? OFFSET ?";

// Prepare parameters for main query
$main_params = $params;
$main_types = $types;

// Add pagination parameters
$main_params[] = $limit;
$main_params[] = $offset;
$main_types .= 'ii';

$receipts = [];
$total_principal = 0;
$total_interest = 0;
$total_amount = 0;
$total_overdue_paid = 0; // Track total overdue paid
$total_overdue_collected = 0; // Track total overdue collected

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    if (!empty($main_params)) {
        mysqli_stmt_bind_param($stmt, $main_types, ...$main_params);
    }
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $receipts[] = $row;
            $total_principal += $row['principal_amount'];
            $total_interest += $row['interest_amount'];
            $total_amount += $row['total_amount'];
            $total_overdue_paid += $row['overdue_amount_paid'];
            if ($row['includes_overdue'] == 1 && $row['overdue_amount_paid'] > 0) {
                $total_overdue_collected += $row['overdue_amount_paid'];
            }
        }
    } else {
        $error = "Query execution failed: " . mysqli_stmt_error($stmt);
    }
} else {
    $error = "Query preparation failed: " . mysqli_error($conn);
}

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_receipts,
                    COALESCE(SUM(p.principal_amount), 0) as sum_principal,
                    COALESCE(SUM(p.interest_amount), 0) as sum_interest,
                    COALESCE(SUM(p.total_amount), 0) as sum_total,
                    COALESCE(SUM(p.overdue_amount_paid), 0) as sum_overdue,
                    COUNT(DISTINCT p.loan_id) as unique_loans,
                    COUNT(DISTINCT l.customer_id) as unique_customers,
                    SUM(CASE WHEN p.includes_overdue = 1 THEN 1 ELSE 0 END) as overdue_count,
                    SUM(CASE WHEN p.includes_overdue = 1 AND p.overdue_amount_paid > 0 THEN p.overdue_amount_paid ELSE 0 END) as total_overdue_collected
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  JOIN customers c ON l.customer_id = c.id
                  $where_clause";

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
        'total_receipts' => 0,
        'sum_principal' => 0,
        'sum_interest' => 0,
        'sum_total' => 0,
        'sum_overdue' => 0,
        'unique_loans' => 0,
        'unique_customers' => 0,
        'overdue_count' => 0,
        'total_overdue_collected' => 0
    ];
}

// Get quick stats for dashboard
$stats_query = "SELECT 
                    COUNT(CASE WHEN DATE(p.payment_date) = CURDATE() THEN 1 END) as today_count,
                    COALESCE(SUM(CASE WHEN DATE(p.payment_date) = CURDATE() THEN p.total_amount ELSE 0 END), 0) as today_amount,
                    COUNT(CASE WHEN YEARWEEK(p.payment_date) = YEARWEEK(CURDATE()) THEN 1 END) as week_count,
                    COALESCE(SUM(CASE WHEN YEARWEEK(p.payment_date) = YEARWEEK(CURDATE()) THEN p.total_amount ELSE 0 END), 0) as week_amount,
                    COUNT(CASE WHEN MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE()) THEN 1 END) as month_count,
                    COALESCE(SUM(CASE WHEN MONTH(p.payment_date) = MONTH(CURDATE()) AND YEAR(p.payment_date) = YEAR(CURDATE()) THEN p.total_amount ELSE 0 END), 0) as month_amount,
                    COUNT(CASE WHEN p.includes_overdue = 1 AND DATE(p.payment_date) = CURDATE() THEN 1 END) as today_overdue_count,
                    COALESCE(SUM(CASE WHEN p.includes_overdue = 1 AND DATE(p.payment_date) = CURDATE() THEN p.overdue_amount_paid ELSE 0 END), 0) as today_overdue_amount,
                    COUNT(CASE WHEN p.includes_overdue = 1 AND p.overdue_amount_paid > 0 AND DATE(p.payment_date) = CURDATE() THEN 1 END) as today_overdue_collection_count,
                    COALESCE(SUM(CASE WHEN p.includes_overdue = 1 AND p.overdue_amount_paid > 0 AND DATE(p.payment_date) = CURDATE() THEN p.overdue_amount_paid ELSE 0 END), 0) as today_overdue_collection_amount
                FROM payments p";
$stats_result = mysqli_query($conn, $stats_query);
$stats = $stats_result ? mysqli_fetch_assoc($stats_result) : [
    'today_count' => 0, 'today_amount' => 0,
    'week_count' => 0, 'week_amount' => 0,
    'month_count' => 0, 'month_amount' => 0,
    'today_overdue_count' => 0, 'today_overdue_amount' => 0,
    'today_overdue_collection_count' => 0, 'today_overdue_collection_amount' => 0
];

// Get recent payment types breakdown
$type_query = "SELECT 
                    CASE 
                        WHEN p.receipt_number LIKE 'ADV%' THEN 'Advance'
                        WHEN p.receipt_number LIKE 'CLS%' THEN 'Closure'
                        WHEN p.includes_overdue = 1 AND p.overdue_amount_paid > 0 THEN 'Overdue Collection'
                        WHEN p.includes_overdue = 1 THEN 'Overdue Payment' 
                        WHEN p.principal_amount > 0 AND p.interest_amount > 0 THEN 'Principal + Interest'
                        WHEN p.interest_amount > 0 THEN 'Interest Only'
                        WHEN p.principal_amount > 0 THEN 'Principal Only'
                        ELSE 'Other'
                    END as payment_type,
                    COUNT(*) as count,
                    COALESCE(SUM(p.total_amount), 0) as total
                FROM payments p
                JOIN loans l ON p.loan_id = l.id
                JOIN customers c ON l.customer_id = c.id
                $where_clause
                GROUP BY payment_type
                ORDER BY total DESC";

$type_stmt = mysqli_prepare($conn, $type_query);
if ($type_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($type_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($type_stmt);
    $type_result = mysqli_stmt_get_result($type_stmt);
    $payment_types = [];
    while ($row = mysqli_fetch_assoc($type_result)) {
        $payment_types[] = $row;
    }
} else {
    $payment_types = [];
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

        .receipt-container {
            max-width: 1400px;
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

        .table-info {
            color: #718096;
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .receipt-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .receipt-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .receipt-table tbody tr:hover {
            background: #f7fafc;
        }

        .receipt-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .receipt-table tfoot td {
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

        .badge-principal {
            background: #4299e1;
            color: white;
        }

        .badge-interest {
            background: #ecc94b;
            color: #744210;
        }

        .badge-both {
            background: #667eea;
            color: white;
        }

        .badge-advance {
            background: #9f7aea;
            color: white;
        }

        .badge-closure {
            background: #48bb78;
            color: white;
        }

        .badge-overdue {
            background: #f56565;
            color: white;
        }

        .badge-overdue-collection {
            background: #ed8936;
            color: white;
        }

        .badge-cash {
            background: #48bb78;
            color: white;
        }

        .badge-bank {
            background: #4299e1;
            color: white;
        }

        .badge-upi {
            background: #9f7aea;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            text-decoration: none;
        }

        .action-btn.reprint {
            background: #667eea;
            color: white;
        }

        .action-btn.view {
            background: #48bb78;
            color: white;
        }

        .action-btn.edit {
            background: #ecc94b;
            color: #744210;
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

        .amount-principal {
            color: #4299e1;
        }

        .amount-interest {
            color: #ecc94b;
        }

        .amount-overdue {
            color: #f56565;
        }

        .amount-overdue-collection {
            color: #ed8936;
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

        /* Summary Cards */
        .summary-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #667eea30;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .summary-label {
            color: #718096;
        }

        .summary-value {
            font-weight: 600;
            color: #2d3748;
        }

        .summary-total {
            font-size: 16px;
            font-weight: 700;
            color: #667eea;
            border-top: 1px solid #667eea30;
            padding-top: 8px;
            margin-top: 8px;
        }

        /* Error Box */
        .error-box {
            background: #fee;
            border: 1px solid #fcc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            color: #c00;
        }

        /* Overdue highlight */
        .overdue-row {
            background-color: #fff5f5;
        }

        .overdue-row:hover {
            background-color: #ffe5e5 !important;
        }

        /* Overdue collection highlight */
        .overdue-collection-row {
            background-color: #fffaF0;
        }

        .overdue-collection-row:hover {
            background-color: #feebc8 !important;
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
            
            .receipt-table {
                font-size: 12px;
            }
            
            .action-buttons {
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
                <div class="receipt-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-receipt"></i>
                            Print Receipt Copy
                        </h1>
                        <div class="export-buttons">
                            <button class="btn btn-success btn-sm" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i> Export to Excel
                            </button>
                            <button class="btn btn-primary btn-sm" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print List
                            </button>
                        </div>
                    </div>

                    <!-- Display Error Message -->
                    <?php if (!empty($error)): ?>
                        <div class="error-box">
                            <strong>Error:</strong> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>

                    <!-- Quick Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Today's Receipts</div>
                                <div class="stat-value"><?php echo $stats['today_count']; ?></div>
                                <div class="stat-sub">₹ <?php echo number_format($stats['today_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-calendar-week"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">This Week</div>
                                <div class="stat-value"><?php echo $stats['week_count']; ?></div>
                                <div class="stat-sub">₹ <?php echo number_format($stats['week_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-calendar-month"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">This Month</div>
                                <div class="stat-value"><?php echo $stats['month_count']; ?></div>
                                <div class="stat-sub">₹ <?php echo number_format($stats['month_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <!-- Overdue Collection Today Card -->
                        <div class="stat-card" style="background: #fffaF0;">
                            <div class="stat-icon" style="background: #ed893620; color: #ed8936;">
                                <i class="bi bi-exclamation-diamond"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Overdue Collection Today</div>
                                <div class="stat-value"><?php echo $stats['today_overdue_collection_count']; ?></div>
                                <div class="stat-sub amount-overdue-collection">₹ <?php echo number_format($stats['today_overdue_collection_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Receipts
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Search By</label>
                                    <select class="form-select" name="filter_type">
                                        <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Fields</option>
                                        <option value="receipt" <?php echo $filter_type == 'receipt' ? 'selected' : ''; ?>>Payment Receipt No</option>
                                        <option value="loan_receipt" <?php echo $filter_type == 'loan_receipt' ? 'selected' : ''; ?>>Loan Receipt No</option>
                                        <option value="name" <?php echo $filter_type == 'name' ? 'selected' : ''; ?>>Customer Name</option>
                                        <option value="mobile" <?php echo $filter_type == 'mobile' ? 'selected' : ''; ?>>Mobile Number</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Search Term</label>
                                    <div class="input-group">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Enter search term...">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Type</label>
                                    <select class="form-select" name="payment_type">
                                        <option value="all" <?php echo $payment_type == 'all' ? 'selected' : ''; ?>>All Payments</option>
                                        <option value="principal" <?php echo $payment_type == 'principal' ? 'selected' : ''; ?>>Principal Only</option>
                                        <option value="interest" <?php echo $payment_type == 'interest' ? 'selected' : ''; ?>>Interest Only</option>
                                        <option value="both" <?php echo $payment_type == 'both' ? 'selected' : ''; ?>>Principal + Interest</option>
                                        <option value="advance" <?php echo $payment_type == 'advance' ? 'selected' : ''; ?>>Advance Payments</option>
                                        <option value="closure" <?php echo $payment_type == 'closure' ? 'selected' : ''; ?>>Loan Closures</option>
                                        <option value="overdue" <?php echo $payment_type == 'overdue' ? 'selected' : ''; ?>>Overdue Payments</option>
                                        <option value="overdue_collection" <?php echo $payment_type == 'overdue_collection' ? 'selected' : ''; ?>>Overdue Collection</option>
                                    </select>
                                </div>

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
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="print-receipt-copy.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Clear Filters
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Payment Types Breakdown -->
                    <?php if (!empty($payment_types)): ?>
                    <div class="stats-grid" style="grid-template-columns: repeat(<?php echo min(count($payment_types), 4); ?>, 1fr);">
                        <?php foreach ($payment_types as $type): ?>
                        <div class="stat-card" <?php 
                            echo $type['payment_type'] == 'Overdue Collection' ? 'style="background: #fffaF0;"' : 
                                ($type['payment_type'] == 'Overdue Payment' ? 'style="background: #fff5f5;"' : ''); 
                        ?>>
                            <div class="stat-icon" <?php 
                                echo $type['payment_type'] == 'Overdue Collection' ? 'style="background: #ed893620; color: #ed8936;"' : 
                                    ($type['payment_type'] == 'Overdue Payment' ? 'style="background: #f5656520; color: #f56565;"' : ''); 
                            ?>>
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label"><?php echo $type['payment_type']; ?></div>
                                <div class="stat-value"><?php echo $type['count']; ?></div>
                                <div class="stat-sub <?php 
                                    echo $type['payment_type'] == 'Overdue Collection' ? 'amount-overdue-collection' : 
                                        ($type['payment_type'] == 'Overdue Payment' ? 'amount-overdue' : ''); 
                                ?>">₹ <?php echo number_format($type['total'], 2); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Receipts Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <div>
                                <span class="table-title">Receipt List</span>
                                <span class="table-info">
                                    Showing <?php echo count($receipts); ?> of <?php echo $total_records; ?> receipts
                                    <?php if ($summary['overdue_count'] > 0): ?>
                                        <span class="badge badge-overdue" style="margin-left: 10px;">
                                            <?php echo $summary['overdue_count']; ?> Overdue Payments
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($summary['total_overdue_collected'] > 0): ?>
                                        <span class="badge badge-overdue-collection" style="margin-left: 5px;">
                                            ₹<?php echo number_format($summary['total_overdue_collected'], 2); ?> Collected
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="summary-card" style="width: 450px;">
                                <div class="summary-row">
                                    <span class="summary-label">Total Principal:</span>
                                    <span class="summary-value amount-principal">₹ <?php echo number_format($total_principal, 2); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Interest:</span>
                                    <span class="summary-value amount-interest">₹ <?php echo number_format($total_interest, 2); ?></span>
                                </div>
                                <?php if ($total_overdue_paid > 0): ?>
                                <div class="summary-row">
                                    <span class="summary-label">Total Overdue Paid:</span>
                                    <span class="summary-value amount-overdue">₹ <?php echo number_format($total_overdue_paid, 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($total_overdue_collected > 0): ?>
                                <div class="summary-row">
                                    <span class="summary-label">Overdue Collected:</span>
                                    <span class="summary-value amount-overdue-collection">₹ <?php echo number_format($total_overdue_collected, 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="summary-total">
                                    <span>Grand Total:</span>
                                    <span>₹ <?php echo number_format($total_amount, 2); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="receipt-table">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Loan Receipt</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th class="text-right">Principal (₹)</th>
                                        <th class="text-right">Interest (₹)</th>
                                        <th class="text-right">Overdue Collection (₹)</th>
                                        <th class="text-right">Total (₹)</th>
                                        <th>Mode</th>
                                        <th>Type</th>
                                        <th>Collected By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sno = $offset + 1;
                                    foreach ($receipts as $receipt): 
                                        // Determine if this is an overdue payment or collection
                                        $is_overdue = $receipt['includes_overdue'] == 1;
                                        $is_overdue_collection = $is_overdue && $receipt['overdue_amount_paid'] > 0;
                                        
                                        // Determine badge class
                                        if ($is_overdue_collection) {
                                            $badge_class = 'badge-overdue-collection';
                                            $badge_text = 'Overdue Collection';
                                        } elseif ($is_overdue) {
                                            $badge_class = 'badge-overdue';
                                            $badge_text = 'Overdue';
                                        } elseif ($receipt['principal_amount'] > 0 && $receipt['interest_amount'] > 0) {
                                            $badge_class = 'badge-both';
                                            $badge_text = 'P + I';
                                        } elseif ($receipt['interest_amount'] > 0) {
                                            $badge_class = 'badge-interest';
                                            $badge_text = 'Interest';
                                        } elseif ($receipt['principal_amount'] > 0) {
                                            $badge_class = 'badge-principal';
                                            $badge_text = 'Principal';
                                        } elseif (strpos($receipt['receipt_number'], 'ADV') === 0) {
                                            $badge_class = 'badge-advance';
                                            $badge_text = 'Advance';
                                        } elseif (strpos($receipt['receipt_number'], 'CLS') === 0) {
                                            $badge_class = 'badge-closure';
                                            $badge_text = 'Closure';
                                        } else {
                                            $badge_class = 'badge-principal';
                                            $badge_text = 'Payment';
                                        }
                                        
                                        // Payment mode badge
                                        $mode_class = 'badge-cash';
                                        if ($receipt['payment_mode'] == 'bank') {
                                            $mode_class = 'badge-bank';
                                        } elseif ($receipt['payment_mode'] == 'upi') {
                                            $mode_class = 'badge-upi';
                                        }
                                        
                                        // Row class
                                        $row_class = '';
                                        if ($is_overdue_collection) {
                                            $row_class = 'overdue-collection-row';
                                        } elseif ($is_overdue) {
                                            $row_class = 'overdue-row';
                                        }
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><?php echo $sno++; ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($receipt['payment_date'])); ?></td>
                                        <td><strong><?php echo $receipt['receipt_number']; ?></strong></td>
                                        <td><?php echo $receipt['loan_receipt']; ?></td>
                                        <td><?php echo htmlspecialchars($receipt['customer_name']); ?></td>
                                        <td><?php echo $receipt['mobile_number']; ?></td>
                                        <td class="text-right amount-principal"><?php echo $receipt['principal_amount'] > 0 ? number_format($receipt['principal_amount'], 2) : '-'; ?></td>
                                        <td class="text-right amount-interest"><?php echo $receipt['interest_amount'] > 0 ? number_format($receipt['interest_amount'], 2) : '-'; ?></td>
                                        <td class="text-right <?php echo $is_overdue_collection ? 'amount-overdue-collection' : 'amount-overdue'; ?>">
                                            <?php echo $receipt['overdue_amount_paid'] > 0 ? number_format($receipt['overdue_amount_paid'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-right amount"><strong>₹ <?php echo number_format($receipt['total_amount'], 2); ?></strong></td>
                                        <td>
                                            <span class="badge <?php echo $mode_class; ?>">
                                                <?php echo strtoupper($receipt['payment_mode']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo $badge_text; ?>
                                            </span>
                                            <?php if ($is_overdue_collection): ?>
                                            <small class="d-block text-muted" style="font-size: 9px;">
                                                Collected: ₹<?php echo number_format($receipt['overdue_amount_paid'], 2); ?>
                                            </small>
                                            <?php elseif ($is_overdue && $receipt['overdue_amount_paid'] > 0): ?>
                                            <small class="d-block text-muted" style="font-size: 9px;">
                                                Overdue: ₹<?php echo number_format($receipt['overdue_amount_paid'], 2); ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($receipt['collected_by']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="bill-receipt.php?receipt=<?php echo $receipt['receipt_number']; ?>&print=1" class="action-btn reprint" target="_blank" title="Reprint Receipt">
                                                    <i class="bi bi-printer"></i> Print
                                                </a>
                                                <a href="view-receipt.php?id=<?php echo $receipt['payment_id']; ?>" class="action-btn view" title="View Details">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <?php if ($_SESSION['user_role'] == 'admin'): ?>
                                                <a href="edit-receipt.php?id=<?php echo $receipt['payment_id']; ?>" class="action-btn edit" title="Edit Receipt">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($receipts)): ?>
                                    <tr>
                                        <td colspan="14" class="text-center" style="padding: 50px; color: #718096;">
                                            <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                            No receipts found matching your criteria
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="6" class="text-right"><strong>Totals:</strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_principal, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_interest, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_overdue_paid, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹ <?php echo number_format($total_amount, 2); ?></strong></td>
                                        <td colspan="4"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($current_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => 0])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => max(0, ($current_page - 2) * $limit)])); ?>" class="page-link">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                                $page_offset = ($i - 1) * $limit;
                                $params = array_merge($_GET, ['offset' => $page_offset]);
                            ?>
                            <a href="?<?php echo http_build_query($params); ?>" 
                               class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>

                            <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => $current_page * $limit])); ?>" class="page-link">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => ($total_pages - 1) * $limit])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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

        // Export to Excel function
        function exportToExcel() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.location.href = 'export-receipts.php?' + params.toString();
        }

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