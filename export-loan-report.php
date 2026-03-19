<?php
session_start();
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

// Get filter parameters (same as in loan-reports.php)
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$product_type = isset($_GET['product_type']) ? $_GET['product_type'] : 'all';
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'daily';

// Build where conditions (same as in loan-reports.php)
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

// Start Excel output
echo '<html>';
echo '<head>';
echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
echo '<style>';
echo 'body { font-family: Arial, sans-serif; }';
echo 'table { border-collapse: collapse; width: 100%; }';
echo 'th { background-color: #667eea; color: white; font-weight: bold; padding: 10px; text-align: left; border: 1px solid #5a67d8; }';
echo 'td { padding: 8px; border: 1px solid #e2e8f0; }';
echo '.text-right { text-align: right; }';
echo '.text-center { text-align: center; }';
echo '.amount { mso-number-format:"\#\#0.00"; }';
echo '.weight { mso-number-format:"\#\#0.000"; }';
echo '.integer { mso-number-format:"\#\#0"; }';
echo '.total-row { background-color: #f7fafc; font-weight: bold; }';
echo '.section-header { background-color: #e2e8f0; font-weight: bold; }';
echo '.report-title { font-size: 18px; font-weight: bold; margin: 10px 0; }';
echo '.report-subtitle { color: #718096; margin-bottom: 20px; }';
echo '</style>';
echo '</head>';
echo '<body>';

// Report Header
echo '<table border="0" cellpadding="5" cellspacing="0" style="width: 100%; margin-bottom: 20px;">';
echo '<tr><td colspan="100" class="report-title">LOAN REPORT - ' . strtoupper(str_replace('_', ' ', $report_type)) . '</td></tr>';
echo '<tr><td colspan="100" class="report-subtitle">';
echo 'Period: ' . date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to));
echo ' | Generated: ' . date('d-m-Y H:i:s');
echo '</td></tr>';
echo '</table>';

// Handle different report types
$report_data = [];
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

    // Output summary as a table
    echo '<h3>Summary Statistics</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<tr><th>Metric</th><th class="text-right">Value</th></tr>';
    foreach ($summary_stats as $key => $value) {
        $formatted_value = $value;
        if (strpos($key, 'amount') !== false || strpos($key, 'disbursed') !== false || strpos($key, 'recovered') !== false) {
            $formatted_value = '₹ ' . number_format($value, 2);
        } elseif (strpos($key, 'weight') !== false) {
            $formatted_value = number_format($value, 3) . ' g';
        } elseif (strpos($key, 'count') !== false || strpos($key, 'loans') !== false) {
            $formatted_value = number_format($value);
        }
        echo '<tr><td>' . ucwords(str_replace('_', ' ', $key)) . '</td><td class="text-right">' . $formatted_value . '</td></tr>';
    }
    echo '</table>';

} elseif ($report_type == 'disbursement') {
    // Disbursement Report
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
                    l.receipt_number,
                    DATE_FORMAT(l.receipt_date, '%d-%m-%Y') as receipt_date,
                    c.customer_name,
                    c.mobile_number,
                    l.loan_amount,
                    l.gross_weight,
                    l.net_weight,
                    l.product_value,
                    l.interest_amount as interest_rate,
                    l.interest_type,
                    l.receipt_charge,
                    l.status,
                    u.name as created_by,
                    COALESCE(b.branch_name, 'Main Branch') as branch_name,
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
    // Collection Report
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
                    p.receipt_number,
                    DATE_FORMAT(p.payment_date, '%d-%m-%Y') as payment_date,
                    l.receipt_number as loan_receipt,
                    c.customer_name,
                    c.mobile_number,
                    p.principal_amount,
                    p.interest_amount,
                    p.total_amount,
                    p.payment_mode,
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

} elseif ($report_type == 'outstanding') {
    // Outstanding Report
    $base_condition = "l.status = 'open'";
    $date_condition = "DATE(l.receipt_date) <= ?";
    
    // Build filter conditions
    $filter_conditions = [];
    $filter_params = [];
    $filter_types = '';
    
    foreach ($where_conditions as $index => $condition) {
        if (strpos($condition, 'l.status') === false) {
            $filter_conditions[] = $condition;
        }
    }
    
    $param_index = 0;
    $type_chars = str_split($types);
    
    foreach ($where_conditions as $index => $condition) {
        if (strpos($condition, 'l.status') === false && isset($type_chars[$param_index])) {
            $filter_params[] = $params[$param_index];
            $filter_types .= $type_chars[$param_index];
        }
        $param_index++;
    }
    
    // Main query
    $query = "SELECT 
                l.receipt_number,
                DATE_FORMAT(l.receipt_date, '%d-%m-%Y') as receipt_date,
                c.customer_name,
                c.mobile_number,
                l.loan_amount,
                l.gross_weight,
                l.net_weight,
                l.product_value,
                l.interest_amount as interest_rate,
                l.interest_type,
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
              WHERE $base_condition AND $date_condition";
    
    foreach ($filter_conditions as $condition) {
        $query .= " AND $condition";
    }
    
    $query .= " ORDER BY l.receipt_date ASC";
    
    // Prepare parameters
    $main_params = [];
    $main_types = '';
    
    foreach ($filter_params as $param) {
        $main_params[] = $param;
    }
    $main_types .= $filter_types;
    
    for ($i = 0; $i < 4; $i++) {
        $main_params[] = $date_to;
        $main_types .= 's';
    }
    
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

} elseif ($report_type == 'interest') {
    // Interest Report
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
}

// Output report data table
if (!empty($report_data)) {
    echo '<h3>' . ucfirst($report_type) . ' Report Details</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    
    // Headers
    echo '<tr>';
    foreach (array_keys($report_data[0]) as $header) {
        $align_class = (strpos($header, 'amount') !== false || strpos($header, 'total') !== false || 
                       strpos($header, 'weight') !== false || strpos($header, 'interest') !== false || 
                       strpos($header, 'principal') !== false || strpos($header, 'collection') !== false) 
                       ? ' class="text-right"' : '';
        echo '<th' . $align_class . '>' . ucwords(str_replace('_', ' ', $header)) . '</th>';
    }
    echo '</tr>';
    
    // Data rows
    $totals = [];
    foreach ($report_data as $row) {
        echo '<tr>';
        foreach ($row as $key => $value) {
            $align_class = (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || 
                           strpos($key, 'weight') !== false || strpos($key, 'interest') !== false || 
                           strpos($key, 'principal') !== false || strpos($key, 'collection') !== false) 
                           ? ' class="text-right"' : '';
            
            // Format value
            if (strpos($key, 'amount') !== false || strpos($key, 'total') !== false || 
                strpos($key, 'interest') !== false || strpos($key, 'principal') !== false || 
                strpos($key, 'collection') !== false || strpos($key, 'disbursed') !== false) {
                if (is_numeric($value)) {
                    $value = '₹ ' . number_format($value, 2);
                    // Collect totals for footer
                    if (!isset($totals[$key])) $totals[$key] = 0;
                    $totals[$key] += floatval(str_replace(['₹', ','], '', $value));
                }
            } elseif (strpos($key, 'weight') !== false) {
                if (is_numeric($value)) {
                    $value = number_format($value, 3) . ' g';
                }
            } elseif (strpos($key, 'count') !== false || strpos($key, 'loans') !== false || 
                      strpos($key, 'customers') !== false || strpos($key, 'transactions') !== false) {
                if (is_numeric($value)) {
                    $value = number_format($value);
                }
            } elseif (strpos($key, 'date') !== false && !empty($value)) {
                if (strlen($value) == 10 && strpos($value, '-') !== false) {
                    // Already formatted
                } else {
                    $value = date('d-m-Y', strtotime($value));
                }
            }
            
            echo '<td' . $align_class . '>' . $value . '</td>';
        }
        echo '</tr>';
    }
    
    // Footer with totals
    if (!empty($totals)) {
        echo '<tr class="total-row">';
        $col_count = count($row);
        $col_index = 0;
        foreach (array_keys($report_data[0]) as $key) {
            if (isset($totals[$key])) {
                echo '<td class="text-right"><strong>₹ ' . number_format($totals[$key], 2) . '</strong></td>';
            } else {
                if ($col_index == 0) {
                    echo '<td><strong>GRAND TOTAL</strong></td>';
                } else {
                    echo '<td></td>';
                }
            }
            $col_index++;
        }
        echo '</tr>';
    }
    
    echo '</table>';
}

// Close HTML
echo '</body>';
echo '</html>';
exit();
?>