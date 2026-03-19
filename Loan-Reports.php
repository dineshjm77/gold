<?php
session_start();
$currentPage = 'loan-reports';
$pageTitle = 'Loan Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'accountant', 'manager'])) {
    header('Location: index.php');
    exit();
}

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$product_type = isset($_GET['product_type']) ? $_GET['product_type'] : 'all';
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'daily';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Build where conditions
$where_conditions = [];
$params = [];
$types = '';

// Date range filter
if (!empty($date_from) && !empty($date_to)) {
    if ($report_type == 'disbursement') {
        $where_conditions[] = "DATE(l.receipt_date) BETWEEN ? AND ?";
    } elseif ($report_type == 'collection') {
        $where_conditions[] = "DATE(p.payment_date) BETWEEN ? AND ?";
    } elseif ($report_type == 'outstanding') {
        // For outstanding, we don't add date range here
    } else {
        $where_conditions[] = "DATE(l.receipt_date) BETWEEN ? AND ?";
    }
    
    if ($report_type != 'outstanding') {
        $params[] = $date_from;
        $params[] = $date_to;
        $types .= 'ss';
    }
}

// Branch filter
if ($branch_id > 0) {
    $where_conditions[] = "l.branch_id = ?";
    $params[] = $branch_id;
    $types .= 'i';
}

// Status filter (except for outstanding which always shows open)
if ($status != 'all' && $report_type != 'outstanding') {
    $where_conditions[] = "l.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Customer filter
if ($customer_id > 0) {
    $where_conditions[] = "l.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

// Employee filter
if ($employee_id > 0) {
    if ($report_type == 'collection') {
        $where_conditions[] = "p.employee_id = ?";
    } else {
        $where_conditions[] = "l.employee_id = ?";
    }
    $params[] = $employee_id;
    $types .= 'i';
}

// Product type filter
if ($product_type != 'all') {
    $where_conditions[] = "EXISTS (SELECT 1 FROM loan_items li WHERE li.loan_id = l.id AND li.product_type = ?)";
    $params[] = $product_type;
    $types .= 's';
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch branches for filter
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);
$branches = [];
while ($row = mysqli_fetch_assoc($branches_result)) {
    $branches[] = $row;
}

// Fetch employees for filter
$employees_query = "SELECT id, name FROM users WHERE role IN ('sale', 'manager') AND status = 'active' ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);
$employees = [];
while ($row = mysqli_fetch_assoc($employees_result)) {
    $employees[] = $row;
}

// Fetch customers for filter
$customers_query = "SELECT id, customer_name, mobile_number FROM customers ORDER BY customer_name LIMIT 100";
$customers_result = mysqli_query($conn, $customers_query);
$customers = [];
while ($row = mysqli_fetch_assoc($customers_result)) {
    $customers[] = $row;
}

// Fetch product types
$product_types_query = "SELECT product_type FROM product_types WHERE status = 1 ORDER BY product_type";
$product_types_result = mysqli_query($conn, $product_types_query);
$product_types = [];
while ($row = mysqli_fetch_assoc($product_types_result)) {
    $product_types[] = $row['product_type'];
}

// Handle different report types
$report_data = [];
$chart_data = [];
$summary_stats = [];

if ($report_type == 'summary') {
    // Summary Report - Overall loan statistics
    $query = "SELECT 
                COUNT(*) as total_loans,
                COUNT(CASE WHEN l.status = 'open' THEN 1 END) as active_loans,
                COUNT(CASE WHEN l.status = 'closed' THEN 1 END) as closed_loans,
                COUNT(CASE WHEN l.status = 'auctioned' THEN 1 END) as auctioned_loans,
                COUNT(CASE WHEN l.status = 'defaulted' THEN 1 END) as defaulted_loans,
                COALESCE(SUM(l.loan_amount), 0) as total_disbursed,
                COALESCE(SUM(CASE WHEN l.status = 'open' THEN l.loan_amount ELSE 0 END), 0) as outstanding_amount,
                COALESCE(SUM(CASE WHEN l.status = 'closed' THEN l.loan_amount ELSE 0 END), 0) as recovered_amount,
                COALESCE(AVG(l.loan_amount), 0) as avg_loan_amount,
                COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                COALESCE(SUM(l.product_value), 0) as total_product_value
              FROM loans l
              $where_clause";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $summary_stats = mysqli_fetch_assoc($result);
    }

    // Get monthly trend data for chart
    $trend_query = "SELECT 
                        DATE_FORMAT(l.receipt_date, '%Y-%m') as month,
                        COUNT(*) as loan_count,
                        COALESCE(SUM(l.loan_amount), 0) as disbursed_amount,
                        COALESCE(SUM(CASE WHEN l.status = 'closed' THEN l.loan_amount ELSE 0 END), 0) as recovered_amount
                    FROM loans l
                    $where_clause
                    GROUP BY DATE_FORMAT(l.receipt_date, '%Y-%m')
                    ORDER BY month DESC
                    LIMIT 12";
    
    $trend_stmt = mysqli_prepare($conn, $trend_query);
    if ($trend_stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($trend_stmt, $types, ...$params);
        }
        mysqli_stmt_execute($trend_stmt);
        $trend_result = mysqli_stmt_get_result($trend_stmt);
        while ($row = mysqli_fetch_assoc($trend_result)) {
            $chart_data['months'][] = $row['month'];
            $chart_data['disbursed'][] = $row['disbursed_amount'];
            $chart_data['recovered'][] = $row['recovered_amount'];
            $chart_data['counts'][] = $row['loan_count'];
        }
    }

} elseif ($report_type == 'disbursement') {
    // Disbursement Report - Loans created in date range
    if ($group_by == 'daily') {
        $query = "SELECT 
                    DATE(l.receipt_date) as date,
                    COUNT(*) as loan_count,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                    COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM loans l
                  $where_clause
                  GROUP BY DATE(l.receipt_date)
                  ORDER BY date DESC";
    } elseif ($group_by == 'monthly') {
        $query = "SELECT 
                    DATE_FORMAT(l.receipt_date, '%Y-%m') as month,
                    COUNT(*) as loan_count,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                    COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM loans l
                  $where_clause
                  GROUP BY DATE_FORMAT(l.receipt_date, '%Y-%m')
                  ORDER BY month DESC";
    } elseif ($group_by == 'branch') {
        $query = "SELECT 
                    COALESCE(b.branch_name, 'Main Branch') as branch_name,
                    COUNT(*) as loan_count,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                    COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM loans l
                  LEFT JOIN branches b ON l.branch_id = b.id
                  $where_clause
                  GROUP BY l.branch_id
                  ORDER BY total_amount DESC";
    } elseif ($group_by == 'employee') {
        $query = "SELECT 
                    u.name as employee_name,
                    COUNT(*) as loan_count,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                    COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM loans l
                  JOIN users u ON l.employee_id = u.id
                  $where_clause
                  GROUP BY l.employee_id
                  ORDER BY total_amount DESC";
    } else {
        // Detailed view
        $query = "SELECT 
                    l.id,
                    l.receipt_number,
                    l.receipt_date,
                    l.loan_amount,
                    l.gross_weight,
                    l.net_weight,
                    l.product_value,
                    l.interest_amount as interest_rate,
                    l.interest_type,
                    l.receipt_charge,
                    l.status,
                    c.id as customer_id,
                    c.customer_name,
                    c.mobile_number,
                    u.name as created_by,
                    b.branch_name,
                    (SELECT COUNT(*) FROM loan_items WHERE loan_id = l.id) as item_count
                  FROM loans l
                  JOIN customers c ON l.customer_id = c.id
                  JOIN users u ON l.employee_id = u.id
                  LEFT JOIN branches b ON l.branch_id = b.id
                  $where_clause
                  ORDER BY l.receipt_date DESC, l.id DESC";
    }
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $report_data[] = $row;
        }
    }

} elseif ($report_type == 'collection') {
    // Collection Report - Payments received in date range
    if ($group_by == 'daily') {
        $query = "SELECT 
                    DATE(p.payment_date) as date,
                    COUNT(*) as payment_count,
                    COALESCE(SUM(p.principal_amount), 0) as total_principal,
                    COALESCE(SUM(p.interest_amount), 0) as total_interest,
                    COALESCE(SUM(p.total_amount), 0) as total_collection,
                    COUNT(DISTINCT p.loan_id) as unique_loans,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  $where_clause
                  GROUP BY DATE(p.payment_date)
                  ORDER BY date DESC";
    } elseif ($group_by == 'monthly') {
        $query = "SELECT 
                    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                    COUNT(*) as payment_count,
                    COALESCE(SUM(p.principal_amount), 0) as total_principal,
                    COALESCE(SUM(p.interest_amount), 0) as total_interest,
                    COALESCE(SUM(p.total_amount), 0) as total_collection,
                    COUNT(DISTINCT p.loan_id) as unique_loans,
                    COUNT(DISTINCT l.customer_id) as unique_customers
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  $where_clause
                  GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                  ORDER BY month DESC";
    } elseif ($group_by == 'mode') {
        $query = "SELECT 
                    p.payment_mode,
                    COUNT(*) as payment_count,
                    COALESCE(SUM(p.principal_amount), 0) as total_principal,
                    COALESCE(SUM(p.interest_amount), 0) as total_interest,
                    COALESCE(SUM(p.total_amount), 0) as total_collection,
                    COUNT(DISTINCT p.loan_id) as unique_loans
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  $where_clause
                  GROUP BY p.payment_mode
                  ORDER BY total_collection DESC";
    } else {
        // Detailed view
        $query = "SELECT 
                    p.id,
                    p.receipt_number,
                    p.payment_date,
                    p.principal_amount,
                    p.interest_amount,
                    p.total_amount,
                    p.payment_mode,
                    l.receipt_number as loan_receipt,
                    l.loan_amount,
                    c.customer_name,
                    c.mobile_number,
                    u.name as collected_by
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  JOIN customers c ON l.customer_id = c.id
                  JOIN users u ON p.employee_id = u.id
                  $where_clause
                  ORDER BY p.payment_date DESC, p.id DESC";
    }
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $report_data[] = $row;
        }
    }

    // Get summary stats for collection
    $summary_query = "SELECT 
                        COUNT(*) as total_transactions,
                        COUNT(DISTINCT p.loan_id) as unique_loans,
                        COUNT(DISTINCT l.customer_id) as unique_customers,
                        COALESCE(SUM(p.principal_amount), 0) as total_principal,
                        COALESCE(SUM(p.interest_amount), 0) as total_interest,
                        COALESCE(SUM(p.total_amount), 0) as total_collection,
                        COALESCE(AVG(p.total_amount), 0) as avg_transaction,
                        COUNT(CASE WHEN p.payment_mode = 'cash' THEN 1 END) as cash_count,
                        COALESCE(SUM(CASE WHEN p.payment_mode = 'cash' THEN p.total_amount ELSE 0 END), 0) as cash_amount,
                        COUNT(CASE WHEN p.payment_mode = 'bank' THEN 1 END) as bank_count,
                        COALESCE(SUM(CASE WHEN p.payment_mode = 'bank' THEN p.total_amount ELSE 0 END), 0) as bank_amount,
                        COUNT(CASE WHEN p.payment_mode = 'upi' THEN 1 END) as upi_count,
                        COALESCE(SUM(CASE WHEN p.payment_mode = 'upi' THEN p.total_amount ELSE 0 END), 0) as upi_amount
                      FROM payments p
                      JOIN loans l ON p.loan_id = l.id
                      $where_clause";
    
    $summary_stmt = mysqli_prepare($conn, $summary_query);
    if ($summary_stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
        }
        mysqli_stmt_execute($summary_stmt);
        $summary_result = mysqli_stmt_get_result($summary_stmt);
        $summary_stats = mysqli_fetch_assoc($summary_result);
    }

} elseif ($report_type == 'outstanding') {
    // ============== FIXED OUTSTANDING REPORT SECTION ==============
    
    // For outstanding report, we only use open loans
    $base_condition = "l.status = 'open'";
    
    // Add date condition
    $date_condition = "DATE(l.receipt_date) <= ?";
    
    // Build filter conditions (excluding status since we already have it)
    $filter_conditions = [];
    $filter_params = [];
    $filter_types = '';
    
    // Copy filter parameters (excluding any status filters)
    foreach ($where_conditions as $index => $condition) {
        // Skip status conditions
        if (strpos($condition, 'l.status') === false) {
            $filter_conditions[] = $condition;
        }
    }
    
    // Copy parameters for filters (excluding status parameters)
    // We need to rebuild params carefully based on the original types
    $param_index = 0;
    $type_chars = str_split($types);
    
    foreach ($where_conditions as $index => $condition) {
        if (strpos($condition, 'l.status') === false && isset($type_chars[$param_index])) {
            // This is a filter we want to keep
            $filter_params[] = $params[$param_index];
            $filter_types .= $type_chars[$param_index];
        }
        $param_index++;
    }
    
    // ===== MAIN OUTSTANDING QUERY =====
    // The query has 4 placeholders for date (in DATEDIFF and WHERE clause)
    $query = "SELECT 
                l.id,
                l.receipt_number,
                l.receipt_date,
                l.loan_amount,
                l.gross_weight,
                l.net_weight,
                l.product_value,
                l.interest_amount as interest_rate,
                l.interest_type,
                c.customer_name,
                c.mobile_number,
                u.name as created_by,
                DATEDIFF(?, l.receipt_date) as days_passed,
                CASE 
                    WHEN l.interest_type = 'daily' THEN 
                        ROUND(l.loan_amount * (l.interest_amount/100) * DATEDIFF(?, l.receipt_date), 2)
                    WHEN l.interest_type = 'monthly' THEN 
                        ROUND(l.loan_amount * (l.interest_amount/100) * FLOOR(DATEDIFF(?, l.receipt_date)/30), 2)
                    ELSE 0
                END as accrued_interest,
                (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as paid_principal,
                (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as paid_interest,
                (SELECT COALESCE(MAX(payment_date), l.receipt_date) FROM payments WHERE loan_id = l.id) as last_payment_date
              FROM loans l
              JOIN customers c ON l.customer_id = c.id
              JOIN users u ON l.employee_id = u.id
              WHERE $base_condition";
    
    // Add date condition
    $query .= " AND $date_condition";
    
    // Add filter conditions
    foreach ($filter_conditions as $condition) {
        $query .= " AND $condition";
    }
    
    $query .= " ORDER BY l.receipt_date ASC";
    
    // Prepare parameters for binding
    $main_params = [];
    $main_types = '';
    
    // Add filter parameters first
    foreach ($filter_params as $param) {
        $main_params[] = $param;
    }
    $main_types .= $filter_types;
    
    // Add 4 date parameters
    for ($i = 0; $i < 4; $i++) {
        $main_params[] = $date_to;
        $main_types .= 's';
    }
    
    // Debug output (remove in production)
    // echo "<!-- Main Query Types: $main_types (" . strlen($main_types) . "), Params: " . count($main_params) . " -->";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if (!empty($main_params)) {
            mysqli_stmt_bind_param($stmt, $main_types, ...$main_params);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $row['outstanding_principal'] = $row['loan_amount'] - $row['paid_principal'];
            $row['total_due'] = $row['outstanding_principal'] + $row['accrued_interest'] - $row['paid_interest'];
            $report_data[] = $row;
        }
    }
    
    // ===== SUMMARY STATS QUERY =====
    // This query has 5 placeholders for date
    $summary_query = "SELECT 
                        COUNT(*) as total_loans,
                        COALESCE(SUM(l.loan_amount), 0) as total_disbursed,
                        COALESCE(SUM(l.gross_weight), 0) as total_gross_weight,
                        COALESCE(SUM(l.net_weight), 0) as total_net_weight,
                        COALESCE(SUM(l.product_value), 0) as total_product_value,
                        COUNT(DISTINCT l.customer_id) as unique_customers,
                        COALESCE(SUM(CASE WHEN DATEDIFF(?, l.receipt_date) > 90 THEN 1 ELSE 0 END), 0) as overdue_90_plus,
                        COALESCE(SUM(CASE WHEN DATEDIFF(?, l.receipt_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END), 0) as overdue_61_90,
                        COALESCE(SUM(CASE WHEN DATEDIFF(?, l.receipt_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END), 0) as overdue_31_60,
                        COALESCE(SUM(CASE WHEN DATEDIFF(?, l.receipt_date) BETWEEN 1 AND 30 THEN 1 ELSE 0 END), 0) as overdue_1_30
                      FROM loans l
                      WHERE $base_condition";
    
    // Add date condition
    $summary_query .= " AND $date_condition";
    
    // Add filter conditions
    foreach ($filter_conditions as $condition) {
        $summary_query .= " AND $condition";
    }
    
    // Prepare parameters for summary
    $summary_params = [];
    $summary_types = '';
    
    // Add filter parameters first
    foreach ($filter_params as $param) {
        $summary_params[] = $param;
    }
    $summary_types .= $filter_types;
    
    // Add 5 date parameters
    for ($i = 0; $i < 5; $i++) {
        $summary_params[] = $date_to;
        $summary_types .= 's';
    }
    
    // Debug output (remove in production)
    // echo "<!-- Summary Query Types: $summary_types (" . strlen($summary_types) . "), Params: " . count($summary_params) . " -->";
    
    $summary_stmt = mysqli_prepare($conn, $summary_query);
    if ($summary_stmt) {
        if (!empty($summary_params)) {
            mysqli_stmt_bind_param($summary_stmt, $summary_types, ...$summary_params);
        }
        mysqli_stmt_execute($summary_stmt);
        $summary_result = mysqli_stmt_get_result($summary_stmt);
        $summary_stats = mysqli_fetch_assoc($summary_result);
    }

} elseif ($report_type == 'interest') {
    // Interest Report - Interest earned in date range
    if ($group_by == 'monthly') {
        $query = "SELECT 
                    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                    COUNT(*) as payment_count,
                    COALESCE(SUM(p.interest_amount), 0) as interest_collected,
                    COALESCE(AVG(p.interest_amount), 0) as avg_interest,
                    COUNT(DISTINCT p.loan_id) as unique_loans
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  WHERE p.interest_amount > 0 $where_clause
                  GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                  ORDER BY month DESC";
    } else {
        $query = "SELECT 
                    DATE(p.payment_date) as date,
                    COUNT(*) as payment_count,
                    COALESCE(SUM(p.interest_amount), 0) as interest_collected,
                    COALESCE(AVG(p.interest_amount), 0) as avg_interest,
                    COUNT(DISTINCT p.loan_id) as unique_loans
                  FROM payments p
                  JOIN loans l ON p.loan_id = l.id
                  WHERE p.interest_amount > 0 $where_clause
                  GROUP BY DATE(p.payment_date)
                  ORDER BY date DESC
                  LIMIT 30";
    }
    
    // Replace WHERE with proper placement
    $query = str_replace('WHERE p.interest_amount > 0 WHERE', 'WHERE p.interest_amount > 0 AND', $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $report_data[] = $row;
        }
    }

    // Get summary stats for interest
    $summary_query = "SELECT 
                        COUNT(*) as total_transactions,
                        COALESCE(SUM(p.interest_amount), 0) as total_interest,
                        COALESCE(AVG(p.interest_amount), 0) as avg_interest,
                        COUNT(DISTINCT p.loan_id) as unique_loans,
                        MAX(p.interest_amount) as max_interest,
                        MIN(p.interest_amount) as min_interest
                      FROM payments p
                      JOIN loans l ON p.loan_id = l.id
                      WHERE p.interest_amount > 0 $where_clause";
    
    $summary_query = str_replace('WHERE p.interest_amount > 0 WHERE', 'WHERE p.interest_amount > 0 AND', $summary_query);
    
    $summary_stmt = mysqli_prepare($conn, $summary_query);
    if ($summary_stmt) {
        if (!empty($params)) {
            mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
        }
        mysqli_stmt_execute($summary_stmt);
        $summary_result = mysqli_stmt_get_result($summary_stmt);
        $summary_stats = mysqli_fetch_assoc($summary_result);
    }
}

// Handle export
if ($export == 'excel') {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="loan_report_' . $report_type . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');
    
    // Create Excel content with UTF-8 BOM
    echo "\xEF\xBB\xBF";
    
    // Excel output (keep your existing Excel export code)
    echo '<html>';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>';
    echo '.text-right { text-align: right; }';
    echo '.amount { mso-number-format:"\#\#0.00"; }';
    echo '.weight { mso-number-format:"\#\#0.000"; }';
    echo '.text-center { text-align: center; }';
    echo 'th { background-color: #f0f0f0; font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Report title
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<tr>';
    echo '<th colspan="100" style="background-color: #667eea; color: white; font-size: 16px; padding: 10px; text-align: center;">';
    echo 'Loan Report - ' . ucfirst(str_replace('_', ' ', $report_type)) . ' (' . date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to)) . ')';
    echo '</th>';
    echo '</tr>';
    
    if (!empty($report_data)) {
        // Output headers
        echo '<tr>';
        foreach (array_keys($report_data[0]) as $header) {
            $header_class = '';
            if (strpos($header, 'amount') !== false || strpos($header, 'total') !== false || strpos($header, 'collection') !== false || 
                strpos($header, 'disbursed') !== false || strpos($header, 'interest') !== false || strpos($header, 'weight') !== false) {
                $header_class = ' class="text-right"';
            }
            echo '<th' . $header_class . '>' . ucwords(str_replace('_', ' ', $header)) . '</th>';
        }
        echo '</tr>';
        
        // Output data
        foreach ($report_data as $row) {
            echo '<tr>';
            foreach ($row as $key => $value) {
                $cell_class = '';
                $cell_value = $value;
                
                if (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || strpos($key, 'collection') !== false || 
                    strpos($key, 'disbursed') !== false || strpos($key, 'interest') !== false) {
                    if (is_numeric($value)) {
                        $cell_class = ' class="text-right amount"';
                        $cell_value = number_format($value, 2);
                    }
                } elseif (strpos($key, 'weight') !== false) {
                    if (is_numeric($value)) {
                        $cell_class = ' class="text-right weight"';
                        $cell_value = number_format($value, 3);
                    }
                } elseif (strpos($key, 'count') !== false || strpos($key, 'loans') !== false || strpos($key, 'customers') !== false || 
                          strpos($key, 'transactions') !== false) {
                    if (is_numeric($value)) {
                        $cell_class = ' class="text-right"';
                        $cell_value = number_format($value);
                    }
                } elseif (strpos($key, 'date') !== false && !empty($value)) {
                    $cell_value = date('d-m-Y', strtotime($value));
                } elseif ($key == 'payment_mode' || $key == 'status' || $key == 'interest_type') {
                    $cell_value = ucwords(str_replace('_', ' ', $value));
                }
                
                echo '<td' . $cell_class . '>' . $cell_value . '</td>';
            }
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="100" style="text-align: center; padding: 20px;">No data found</td></tr>';
    }
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit();
} elseif ($export == 'pdf') {
    $params = http_build_query($_GET);
    header('Location: generate-pdf-report.php?' . $params);
    exit();
}

// HTML output continues...
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
        /* Keep all your existing CSS styles */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .app-wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; background: #f8fafc; }
        .page-content { padding: 30px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-title { font-size: 32px; font-weight: 700; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0; }
        .btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); }
        .btn-success { background: #48bb78; color: white; }
        .btn-success:hover { background: #38a169; }
        .btn-info { background: #4299e1; color: white; }
        .btn-info:hover { background: #3182ce; }
        .btn-warning { background: #ecc94b; color: #744210; }
        .btn-warning:hover { background: #d69e2e; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-secondary { background: #a0aec0; color: white; }
        .btn-secondary:hover { background: #718096; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #48bb78; color: white; }
        .badge-warning { background: #ecc94b; color: #744210; }
        .badge-danger { background: #f56565; color: white; }
        .badge-info { background: #4299e1; color: white; }
        .report-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .report-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px 30px; color: white; }
        .report-header h2 { font-size: 20px; font-weight: 600; margin: 0; display: flex; align-items: center; gap: 10px; }
        .report-body { padding: 30px; }
        .filter-section { background: #f7fafc; border-radius: 12px; padding: 20px; margin-bottom: 30px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: #4a5568; margin-bottom: 5px; }
        .form-control, .form-select { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: all 0.3s; }
        .form-control:focus, .form-select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .filter-actions { display: flex; gap: 15px; justify-content: flex-end; flex-wrap: wrap; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%); border-radius: 12px; padding: 20px; border: 1px solid #667eea30; }
        .stat-label { font-size: 14px; color: #718096; margin-bottom: 5px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #2d3748; }
        .stat-sub { font-size: 12px; color: #a0aec0; margin-top: 5px; }
        .chart-container { height: 300px; margin-bottom: 30px; padding: 20px; background: white; border-radius: 12px; border: 1px solid #e2e8f0; }
        .table-responsive { overflow-x: auto; }
        .report-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .report-table th { background: #f7fafc; padding: 12px; text-align: left; font-weight: 600; color: #4a5568; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .report-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .report-table tbody tr:hover { background: #f7fafc; }
        .report-table tfoot { background: #f7fafc; font-weight: 600; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .amount { font-weight: 600; color: #2d3748; }
        .export-buttons { display: flex; gap: 10px; }
        .report-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid #e2e8f0; background: white; color: #4a5568; transition: all 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .tab-btn:hover { background: #f7fafc; border-color: #667eea; }
        .tab-btn.active { background: #667eea; color: white; border-color: #667eea; }
        .group-by { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .group-by label { font-weight: 600; color: #4a5568; }
        .group-by select { padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 14px; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filter-grid { grid-template-columns: 1fr; }
            .filter-actions { flex-direction: column; }
            .export-buttons { flex-direction: column; }
            .report-tabs { flex-direction: column; }
            .tab-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main-content">
            <?php include 'includes/topbar.php'; ?>

            <div class="page-content">
                <div class="container">
                    <!-- Page Header -->
<!-- Page Header -->
<div class="page-header">
    <h1 class="page-title">
        <i class="bi bi-graph-up"></i>
        Loan Reports
    </h1>
    <div class="export-buttons">
        <?php if (!empty($report_data) || !empty($summary_stats)): ?>
        <a href="export-loan-report.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success" target="_blank">
            <i class="bi bi-file-excel"></i> Export to Excel
        </a>
        <?php else: ?>
        <button class="btn btn-success" disabled style="opacity: 0.5; cursor: not-allowed;">
            <i class="bi bi-file-excel"></i> Export to Excel
        </button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
        </button>
    </div>
</div>
                    <!-- Report Tabs -->
                    <div class="report-tabs">
                        <a href="?report_type=summary&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                           class="tab-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-pie-chart"></i> Summary
                        </a>
                        <a href="?report_type=disbursement&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                           class="tab-btn <?php echo $report_type == 'disbursement' ? 'active' : ''; ?>">
                            <i class="bi bi-cash-stack"></i> Disbursement
                        </a>
                        <a href="?report_type=collection&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                           class="tab-btn <?php echo $report_type == 'collection' ? 'active' : ''; ?>">
                            <i class="bi bi-bank"></i> Collection
                        </a>
                        <a href="?report_type=outstanding&date_to=<?php echo $date_to; ?>" 
                           class="tab-btn <?php echo $report_type == 'outstanding' ? 'active' : ''; ?>">
                            <i class="bi bi-clock-history"></i> Outstanding
                        </a>
                        <a href="?report_type=interest&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
                           class="tab-btn <?php echo $report_type == 'interest' ? 'active' : ''; ?>">
                            <i class="bi bi-percent"></i> Interest
                        </a>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                            
                            <div class="filter-grid">
                                <?php if ($report_type != 'outstanding'): ?>
                                <div class="form-group">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>" required>
                                </div>
                                <?php endif; ?>
                                
                                <div class="form-group">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" name="branch_id">
                                        <option value="0">All Branches</option>
                                        <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $branch_id == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="open" <?php echo $status == 'open' ? 'selected' : ''; ?>>Open</option>
                                        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        <option value="auctioned" <?php echo $status == 'auctioned' ? 'selected' : ''; ?>>Auctioned</option>
                                        <option value="defaulted" <?php echo $status == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Employee</label>
                                    <select class="form-select" name="employee_id">
                                        <option value="0">All Employees</option>
                                        <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo $employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id">
                                        <option value="0">All Customers</option>
                                        <?php foreach ($customers as $cust): ?>
                                        <option value="<?php echo $cust['id']; ?>" <?php echo $customer_id == $cust['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cust['customer_name'] . ' - ' . $cust['mobile_number']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Product Type</label>
                                    <select class="form-select" name="product_type">
                                        <option value="all">All Products</option>
                                        <?php foreach ($product_types as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $product_type == $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <?php if (in_array($report_type, ['disbursement', 'collection'])): ?>
                                <div class="form-group">
                                    <label class="form-label">Group By</label>
                                    <select class="form-select" name="group_by">
                                        <option value="daily" <?php echo $group_by == 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="monthly" <?php echo $group_by == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        <option value="branch" <?php echo $group_by == 'branch' ? 'selected' : ''; ?>>Branch</option>
                                        <option value="employee" <?php echo $group_by == 'employee' ? 'selected' : ''; ?>>Employee</option>
                                        <?php if ($report_type == 'collection'): ?>
                                        <option value="mode" <?php echo $group_by == 'mode' ? 'selected' : ''; ?>>Payment Mode</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Generate Report
                                </button>
                                <a href="loan-reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Statistics -->
                    <?php if (!empty($summary_stats)): ?>
                    <div class="stats-grid">
                        <?php foreach ($summary_stats as $key => $value): ?>
                        <div class="stat-card">
                            <div class="stat-label"><?php echo ucwords(str_replace('_', ' ', $key)); ?></div>
                            <div class="stat-value">
                                <?php 
                                if (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || strpos($key, 'collection') !== false || strpos($key, 'disbursed') !== false || strpos($key, 'interest') !== false) {
                                    echo '₹ ' . number_format($value, 2);
                                } elseif (strpos($key, 'weight') !== false) {
                                    echo number_format($value, 3) . ' g';
                                } elseif (strpos($key, 'count') !== false || strpos($key, 'loans') !== false || strpos($key, 'customers') !== false || strpos($key, 'transactions') !== false) {
                                    echo number_format($value);
                                } elseif (strpos($key, 'percentage') !== false || strpos($key, 'rate') !== false) {
                                    echo number_format($value, 2) . '%';
                                } else {
                                    echo number_format($value, 2);
                                }
                                ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Chart for Summary Report -->
                    <?php if ($report_type == 'summary' && !empty($chart_data)): ?>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <script>
                        const ctx = document.getElementById('trendChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: <?php echo json_encode($chart_data['months'] ?? []); ?>,
                                datasets: [
                                    { label: 'Disbursed Amount', data: <?php echo json_encode($chart_data['disbursed'] ?? []); ?>, borderColor: '#4299e1', backgroundColor: 'rgba(66, 153, 225, 0.1)', tension: 0.4, fill: true },
                                    { label: 'Recovered Amount', data: <?php echo json_encode($chart_data['recovered'] ?? []); ?>, borderColor: '#48bb78', backgroundColor: 'rgba(72, 187, 120, 0.1)', tension: 0.4, fill: true }
                                ]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: true, position: 'bottom' }, title: { display: true, text: 'Monthly Trend - Disbursement vs Recovery' } },
                                scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return '₹' + value.toLocaleString(); } } } }
                            }
                        });
                    </script>
                    <?php endif; ?>

                    <!-- Report Data Table -->
                    <?php if (!empty($report_data)): ?>
                    <div class="report-card">
                        <div class="report-header">
                            <h2>
                                <i class="bi bi-table"></i>
                                <?php echo ucfirst($report_type); ?> Report
                                <?php if ($group_by != 'daily' && $group_by != 'detail'): ?>
                                - Grouped by <?php echo ucfirst($group_by); ?>
                                <?php endif; ?>
                            </h2>
                        </div>
                        <div class="report-body">
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <?php foreach (array_keys($report_data[0]) as $header): ?>
                                            <th class="<?php echo (strpos($header, 'amount') !== false || strpos($header, 'total') !== false || strpos($header, 'weight') !== false) ? 'text-right' : ''; ?>">
                                                <?php echo ucwords(str_replace('_', ' ', $header)); ?>
                                            </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $key => $value): ?>
                                            <td class="<?php echo (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || strpos($key, 'weight') !== false) ? 'text-right' : ''; ?>">
                                                <?php 
                                                if (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || strpos($key, 'collection') !== false || strpos($key, 'disbursed') !== false || strpos($key, 'interest') !== false) {
                                                    echo is_numeric($value) ? '₹ ' . number_format($value, 2) : $value;
                                                } elseif (strpos($key, 'weight') !== false) {
                                                    echo is_numeric($value) ? number_format($value, 3) . ' g' : $value;
                                                } elseif (strpos($key, 'count') !== false || strpos($key, 'loans') !== false || strpos($key, 'customers') !== false) {
                                                    echo is_numeric($value) ? number_format($value) : $value;
                                                } elseif (strpos($key, 'date') !== false && !empty($value)) {
                                                    echo date('d-m-Y', strtotime($value));
                                                } else {
                                                    echo $value;
                                                }
                                                ?>
                                            </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['report_type'])): ?>
                    <div class="report-card">
                        <div class="report-body" style="text-align: center; padding: 50px;">
                            <i class="bi bi-inbox" style="font-size: 48px; color: #a0aec0;"></i>
                            <h3 style="margin: 20px 0; color: #4a5568;">No data found</h3>
                            <p style="color: #718096;">Try adjusting your filter criteria or date range.</p>
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
        flatpickr("input[type=date]", { dateFormat: "Y-m-d", maxDate: "today" });
        document.querySelector('select[name="group_by"]')?.addEventListener('change', function() { document.getElementById('filterForm').submit(); });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>