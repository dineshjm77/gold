<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

if (!isset($_GET['customer_id']) || !isset($_GET['loan_amount'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$customer_id = intval($_GET['customer_id']);
$loan_amount = floatval($_GET['loan_amount']);

$query = "SELECT c.loan_limit_amount, 
          COALESCE(SUM(l.loan_amount), 0) as total_loans_taken
          FROM customers c
          LEFT JOIN loans l ON c.id = l.customer_id AND l.status = 'open'
          WHERE c.id = ?
          GROUP BY c.id";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $loan_limit = floatval($row['loan_limit_amount'] ?? 10000000);
    $total_taken = floatval($row['total_loans_taken'] ?? 0);
    $available = $loan_limit - $total_taken;
    
    $valid = ($loan_amount <= $available);
    
    echo json_encode([
        'success' => true,
        'valid' => $valid,
        'loan_limit' => $loan_limit,
        'total_taken' => $total_taken,
        'available' => $available,
        'remaining_after' => $available - ($valid ? $loan_amount : 0),
        'message' => $valid ? 'Within limit' : 'Exceeds available limit of ₹' . number_format($available, 2)
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
}
?>