<?php
session_start();
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

// Get filter parameters (same as in print-receipt-copy.php)
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_type = isset($_GET['filter_type']) ? $_GET['filter_type'] : 'all';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$payment_type = isset($_GET['payment_type']) ? $_GET['payment_type'] : 'all';

// Validate dates
if (empty($date_from) || $date_from == '1970-01-01') {
    $date_from = date('Y-m-d', strtotime('-30 days'));
}
if (empty($date_to) || $date_to == '1970-01-01') {
    $date_to = date('Y-m-d');
}

// Build the query based on filters (same as in print-receipt-copy.php)
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

// Payment type filter
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

// Get all receipts for export (no pagination limit)
$query = "SELECT 
            p.id as payment_id,
            p.receipt_number,
            DATE_FORMAT(p.payment_date, '%d-%m-%Y') as formatted_date,
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
            l.status as loan_status,
            c.id as customer_id,
            c.customer_name,
            c.mobile_number,
            c.whatsapp_number,
            c.email,
            c.guardian_name,
            c.guardian_type,
            c.door_no,
            c.street_name,
            c.location,
            c.district,
            c.pincode,
            u.name as collected_by,
            u.role as collector_role
          FROM payments p
          JOIN loans l ON p.loan_id = l.id
          JOIN customers c ON l.customer_id = c.id
          JOIN users u ON p.employee_id = u.id
          $where_clause
          ORDER BY p.payment_date DESC, p.id DESC";

// Prepare and execute query
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $receipts = [];
    $total_principal = 0;
    $total_interest = 0;
    $total_amount = 0;
    $total_overdue = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $receipts[] = $row;
        $total_principal += $row['principal_amount'];
        $total_interest += $row['interest_amount'];
        $total_amount += $row['total_amount'];
        $total_overdue += $row['overdue_amount_paid'];
    }
} else {
    die("Query preparation failed: " . mysqli_error($conn));
}

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_receipts,
                    COALESCE(SUM(p.principal_amount), 0) as sum_principal,
                    COALESCE(SUM(p.interest_amount), 0) as sum_interest,
                    COALESCE(SUM(p.total_amount), 0) as sum_total,
                    COALESCE(SUM(p.overdue_amount_paid), 0) as sum_overdue,
                    COUNT(DISTINCT p.loan_id) as unique_loans,
                    COUNT(DISTINCT l.customer_id) as unique_customers
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
        'unique_customers' => 0
    ];
}

// Determine export format
$format = isset($_GET['format']) ? $_GET['format'] : 'excel';

if ($format == 'excel') {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="receipts_export_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Create Excel/HTML table
    echo '<html>';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th { background-color: #667eea; color: white; padding: 10px; text-align: left; }';
    echo 'td { padding: 8px; border: 1px solid #ddd; }';
    echo 'tr:nth-child(even) { background-color: #f2f2f2; }';
    echo '.total-row { background-color: #e2e8f0; font-weight: bold; }';
    echo '.overdue { background-color: #fff5f5; }';
    echo '.overdue-collection { background-color: #fffaF0; }';
    echo '.amount-principal { color: #4299e1; }';
    echo '.amount-interest { color: #ecc94b; }';
    echo '.amount-overdue { color: #f56565; }';
    echo '.amount-overdue-collection { color: #ed8936; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Report Header
    echo '<h2>Receipts Export Report</h2>';
    echo '<p>Generated on: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<p>Period: ' . date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to)) . '</p>';
    
    // Summary Section
    echo '<h3>Summary</h3>';
    echo '<table border="1">';
    echo '<tr><th>Metric</th><th>Value</th></tr>';
    echo '<tr><td>Total Receipts</td><td>' . number_format($summary['total_receipts']) . '</td></tr>';
    echo '<tr><td>Unique Loans</td><td>' . number_format($summary['unique_loans']) . '</td></tr>';
    echo '<tr><td>Unique Customers</td><td>' . number_format($summary['unique_customers']) . '</td></tr>';
    echo '<tr><td>Total Principal</td><td>₹ ' . number_format($summary['sum_principal'], 2) . '</td></tr>';
    echo '<tr><td>Total Interest</td><td>₹ ' . number_format($summary['sum_interest'], 2) . '</td></tr>';
    echo '<tr><td>Total Overdue</td><td>₹ ' . number_format($summary['sum_overdue'], 2) . '</td></tr>';
    echo '<tr><td><strong>Grand Total</strong></td><td><strong>₹ ' . number_format($summary['sum_total'], 2) . '</strong></td></tr>';
    echo '</table>';
    
    echo '<br>';
    
    // Receipts Details
    echo '<h3>Receipt Details</h3>';
    echo '<table border="1">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>S.No</th>';
    echo '<th>Date</th>';
    echo '<th>Receipt No</th>';
    echo '<th>Loan Receipt</th>';
    echo '<th>Customer Name</th>';
    echo '<th>Mobile</th>';
    echo '<th>Guardian</th>';
    echo '<th>Address</th>';
    echo '<th>Principal (₹)</th>';
    echo '<th>Interest (₹)</th>';
    echo '<th>Overdue Collection (₹)</th>';
    echo '<th>Total (₹)</th>';
    echo '<th>Payment Mode</th>';
    echo '<th>Payment Type</th>';
    echo '<th>Collected By</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $sno = 1;
    foreach ($receipts as $receipt) {
        // Determine payment type
        $is_overdue = $receipt['includes_overdue'] == 1;
        $is_overdue_collection = $is_overdue && $receipt['overdue_amount_paid'] > 0;
        
        if ($is_overdue_collection) {
            $payment_type_text = 'Overdue Collection';
            $row_class = 'overdue-collection';
        } elseif ($is_overdue) {
            $payment_type_text = 'Overdue';
            $row_class = 'overdue';
        } elseif ($receipt['principal_amount'] > 0 && $receipt['interest_amount'] > 0) {
            $payment_type_text = 'Principal + Interest';
            $row_class = '';
        } elseif ($receipt['interest_amount'] > 0) {
            $payment_type_text = 'Interest Only';
            $row_class = '';
        } elseif ($receipt['principal_amount'] > 0) {
            $payment_type_text = 'Principal Only';
            $row_class = '';
        } elseif (strpos($receipt['receipt_number'], 'ADV') === 0) {
            $payment_type_text = 'Advance';
            $row_class = '';
        } elseif (strpos($receipt['receipt_number'], 'CLS') === 0) {
            $payment_type_text = 'Closure';
            $row_class = '';
        } else {
            $payment_type_text = 'Other';
            $row_class = '';
        }
        
        // Build address
        $address_parts = [];
        if (!empty($receipt['door_no'])) $address_parts[] = $receipt['door_no'];
        if (!empty($receipt['street_name'])) $address_parts[] = $receipt['street_name'];
        if (!empty($receipt['location'])) $address_parts[] = $receipt['location'];
        if (!empty($receipt['district'])) $address_parts[] = $receipt['district'];
        if (!empty($receipt['pincode'])) $address_parts[] = $receipt['pincode'];
        $address = !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
        
        // Build guardian info
        $guardian = '';
        if (!empty($receipt['guardian_name'])) {
            $guardian = ($receipt['guardian_type'] ? $receipt['guardian_type'] . ': ' : '') . $receipt['guardian_name'];
        } else {
            $guardian = 'N/A';
        }
        
        echo '<tr class="' . $row_class . '">';
        echo '<td>' . $sno++ . '</td>';
        echo '<td>' . $receipt['formatted_date'] . '</td>';
        echo '<td>' . $receipt['receipt_number'] . '</td>';
        echo '<td>' . $receipt['loan_receipt'] . '</td>';
        echo '<td>' . htmlspecialchars($receipt['customer_name']) . '</td>';
        echo '<td>' . $receipt['mobile_number'] . '</td>';
        echo '<td>' . $guardian . '</td>';
        echo '<td>' . $address . '</td>';
        echo '<td class="' . ($receipt['principal_amount'] > 0 ? 'amount-principal' : '') . '">' . ($receipt['principal_amount'] > 0 ? number_format($receipt['principal_amount'], 2) : '-') . '</td>';
        echo '<td class="' . ($receipt['interest_amount'] > 0 ? 'amount-interest' : '') . '">' . ($receipt['interest_amount'] > 0 ? number_format($receipt['interest_amount'], 2) : '-') . '</td>';
        echo '<td class="' . ($receipt['overdue_amount_paid'] > 0 ? ($is_overdue_collection ? 'amount-overdue-collection' : 'amount-overdue') : '') . '">' . ($receipt['overdue_amount_paid'] > 0 ? number_format($receipt['overdue_amount_paid'], 2) : '-') . '</td>';
        echo '<td><strong>₹ ' . number_format($receipt['total_amount'], 2) . '</strong></td>';
        echo '<td>' . strtoupper($receipt['payment_mode']) . '</td>';
        echo '<td>' . $payment_type_text . '</td>';
        echo '<td>' . htmlspecialchars($receipt['collected_by']) . '</td>';
        echo '<td>' . ($receipt['remarks'] ? htmlspecialchars($receipt['remarks']) : '-') . '</td>';
        echo '</tr>';
    }
    
    // Totals row
    echo '<tr class="total-row">';
    echo '<td colspan="8"><strong>Grand Totals</strong></td>';
    echo '<td><strong>₹ ' . number_format($total_principal, 2) . '</strong></td>';
    echo '<td><strong>₹ ' . number_format($total_interest, 2) . '</strong></td>';
    echo '<td><strong>₹ ' . number_format($total_overdue, 2) . '</strong></td>';
    echo '<td><strong>₹ ' . number_format($total_amount, 2) . '</strong></td>';
    echo '<td colspan="4"></td>';
    echo '</tr>';
    
    echo '</tbody>';
    echo '</table>';
    
    // Footer
    echo '<br>';
    echo '<p><em>Generated by Pawn Gold Management System</em></p>';
    echo '</body>';
    echo '</html>';
    
} elseif ($format == 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="receipts_export_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, ['RECEIPTS EXPORT REPORT']);
    fputcsv($output, ['Period', date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to))]);
    fputcsv($output, ['Generated On', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Summary Section
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Receipts', $summary['total_receipts']]);
    fputcsv($output, ['Unique Loans', $summary['unique_loans']]);
    fputcsv($output, ['Unique Customers', $summary['unique_customers']]);
    fputcsv($output, ['Total Principal', '₹ ' . number_format($summary['sum_principal'], 2)]);
    fputcsv($output, ['Total Interest', '₹ ' . number_format($summary['sum_interest'], 2)]);
    fputcsv($output, ['Total Overdue', '₹ ' . number_format($summary['sum_overdue'], 2)]);
    fputcsv($output, ['Grand Total', '₹ ' . number_format($summary['sum_total'], 2)]);
    fputcsv($output, []);
    
    // Receipt Details Headers
    fputcsv($output, ['RECEIPT DETAILS']);
    fputcsv($output, [
        'S.No',
        'Date',
        'Receipt No',
        'Loan Receipt',
        'Customer Name',
        'Mobile',
        'Guardian',
        'Address',
        'Principal (₹)',
        'Interest (₹)',
        'Overdue Collection (₹)',
        'Total (₹)',
        'Payment Mode',
        'Payment Type',
        'Collected By',
        'Remarks'
    ]);
    
    // Add data rows
    $sno = 1;
    foreach ($receipts as $receipt) {
        // Determine payment type
        $is_overdue = $receipt['includes_overdue'] == 1;
        $is_overdue_collection = $is_overdue && $receipt['overdue_amount_paid'] > 0;
        
        if ($is_overdue_collection) {
            $payment_type_text = 'Overdue Collection';
        } elseif ($is_overdue) {
            $payment_type_text = 'Overdue';
        } elseif ($receipt['principal_amount'] > 0 && $receipt['interest_amount'] > 0) {
            $payment_type_text = 'Principal + Interest';
        } elseif ($receipt['interest_amount'] > 0) {
            $payment_type_text = 'Interest Only';
        } elseif ($receipt['principal_amount'] > 0) {
            $payment_type_text = 'Principal Only';
        } elseif (strpos($receipt['receipt_number'], 'ADV') === 0) {
            $payment_type_text = 'Advance';
        } elseif (strpos($receipt['receipt_number'], 'CLS') === 0) {
            $payment_type_text = 'Closure';
        } else {
            $payment_type_text = 'Other';
        }
        
        // Build address
        $address_parts = [];
        if (!empty($receipt['door_no'])) $address_parts[] = $receipt['door_no'];
        if (!empty($receipt['street_name'])) $address_parts[] = $receipt['street_name'];
        if (!empty($receipt['location'])) $address_parts[] = $receipt['location'];
        if (!empty($receipt['district'])) $address_parts[] = $receipt['district'];
        if (!empty($receipt['pincode'])) $address_parts[] = $receipt['pincode'];
        $address = !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
        
        // Build guardian info
        $guardian = '';
        if (!empty($receipt['guardian_name'])) {
            $guardian = ($receipt['guardian_type'] ? $receipt['guardian_type'] . ': ' : '') . $receipt['guardian_name'];
        } else {
            $guardian = 'N/A';
        }
        
        fputcsv($output, [
            $sno++,
            $receipt['formatted_date'],
            $receipt['receipt_number'],
            $receipt['loan_receipt'],
            $receipt['customer_name'],
            $receipt['mobile_number'],
            $guardian,
            $address,
            $receipt['principal_amount'] > 0 ? number_format($receipt['principal_amount'], 2) : '-',
            $receipt['interest_amount'] > 0 ? number_format($receipt['interest_amount'], 2) : '-',
            $receipt['overdue_amount_paid'] > 0 ? number_format($receipt['overdue_amount_paid'], 2) : '-',
            number_format($receipt['total_amount'], 2),
            strtoupper($receipt['payment_mode']),
            $payment_type_text,
            $receipt['collected_by'],
            $receipt['remarks'] ? $receipt['remarks'] : '-'
        ]);
    }
    
    // Add totals row
    fputcsv($output, [
        '',
        '',
        '',
        '',
        '',
        '',
        '',
        'GRAND TOTALS',
        number_format($total_principal, 2),
        number_format($total_interest, 2),
        number_format($total_overdue, 2),
        number_format($total_amount, 2),
        '',
        '',
        '',
        ''
    ]);
    
    fclose($output);
}

exit();
?>