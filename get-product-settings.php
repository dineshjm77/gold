<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

if (!isset($_GET['type'])) {
    echo json_encode(['success' => false, 'message' => 'Product type not specified']);
    exit();
}

$product_type = mysqli_real_escape_string($conn, $_GET['type']);

// First try to get from product_value_settings
$query = "SELECT * FROM product_value_settings WHERE product_type = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 's', $product_type);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$settings = mysqli_fetch_assoc($result);

if ($settings) {
    echo json_encode([
        'success' => true,
        'product_value_per_gram' => floatval($settings['total_value_per_gram']),
        'regular_loan_percentage' => floatval($settings['regular_loan_percentage'] ?? 70),
        'personal_loan_percentage' => floatval($settings['personal_loan_percentage'] ?? 0)
    ]);
} else {
    // Try to get from karat_details as fallback
    $karat_query = "SELECT max_value_per_gram, loan_value_per_gram FROM karat_details WHERE product_type = ? AND status = 1 LIMIT 1";
    $karat_stmt = mysqli_prepare($conn, $karat_query);
    mysqli_stmt_bind_param($karat_stmt, 's', $product_type);
    mysqli_stmt_execute($karat_stmt);
    $karat_result = mysqli_stmt_get_result($karat_stmt);
    $karat = mysqli_fetch_assoc($karat_result);
    
    if ($karat) {
        // Calculate percentage from loan value vs max value
        $percentage = ($karat['loan_value_per_gram'] / $karat['max_value_per_gram']) * 100;
        
        echo json_encode([
            'success' => true,
            'product_value_per_gram' => floatval($karat['max_value_per_gram']),
            'regular_loan_percentage' => round($percentage, 2),
            'personal_loan_percentage' => 0
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Settings not found for this product type'
        ]);
    }
}
?>