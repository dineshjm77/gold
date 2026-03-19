<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

// If customer_id is provided, return single customer
if (isset($_GET['customer_id']) && !empty($_GET['customer_id'])) {
    $customer_id = intval($_GET['customer_id']);
    
    $query = "SELECT c.*, 
              COALESCE(SUM(l.loan_amount), 0) as total_loans_taken,
              COUNT(CASE WHEN l.status = 'open' THEN 1 END) as active_loans_count
              FROM customers c
              LEFT JOIN loans l ON c.id = l.customer_id AND l.status = 'open'
              WHERE c.id = ?
              GROUP BY c.id";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $row['loan_limit'] = floatval($row['loan_limit_amount'] ?? 10000000);
        $row['total_loans_taken'] = floatval($row['total_loans_taken'] ?? 0);
        $row['active_loans_count'] = intval($row['active_loans_count'] ?? 0);
        $row['remaining_limit'] = $row['loan_limit'] - $row['total_loans_taken'];
        
        echo json_encode([
            'success' => true,
            'customer' => $row
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
    }
    exit();
}

// Search customers
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . mysqli_real_escape_string($conn, $_GET['search']) . '%';
    
    $query = "SELECT c.*, 
              COALESCE(SUM(l.loan_amount), 0) as total_loans_taken,
              COUNT(CASE WHEN l.status = 'open' THEN 1 END) as active_loans_count,
              (c.loan_limit_amount - COALESCE(SUM(l.loan_amount), 0)) as remaining_limit
              FROM customers c
              LEFT JOIN loans l ON c.id = l.customer_id AND l.status = 'open'
              WHERE c.customer_name LIKE ? OR c.mobile_number LIKE ? OR c.id LIKE ?
              GROUP BY c.id
              ORDER BY c.customer_name
              LIMIT 10";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'sss', $search, $search, $search);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $customers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['loan_limit'] = floatval($row['loan_limit_amount'] ?? 10000000);
        $row['total_loans_taken'] = floatval($row['total_loans_taken'] ?? 0);
        $row['active_loans_count'] = intval($row['active_loans_count'] ?? 0);
        $row['remaining_limit'] = floatval($row['remaining_limit'] ?? ($row['loan_limit'] - $row['total_loans_taken']));
        $customers[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'customers' => $customers
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'No search term provided']);
?>