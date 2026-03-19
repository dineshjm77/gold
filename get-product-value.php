<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

if (!isset($_GET['type']) || empty($_GET['type'])) {
    echo json_encode(['success' => false, 'error' => 'Product type is required']);
    exit();
}

$product_type = mysqli_real_escape_string($conn, $_GET['type']);

// Get product value settings
$query = "SELECT * FROM product_value_settings WHERE product_type = ? AND status = 1 LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 's', $product_type);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) > 0) {
    $settings = mysqli_fetch_assoc($result);
    
    // Calculate loan value per gram (percentage of total value)
    $loan_value_per_gram = ($settings['total_value_per_gram'] * $settings['percentage'] / 100);
    
    echo json_encode([
        'success' => true,
        'total_value_per_gram' => floatval($settings['total_value_per_gram']),
        'percentage' => floatval($settings['percentage']),
        'product_value_per_gram' => floatval($loan_value_per_gram)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No settings found for this product type'
    ]);
}
?>