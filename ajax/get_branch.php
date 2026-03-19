<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get branch ID from request
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid branch ID']);
    exit();
}

// Fetch branch details
$query = "SELECT * FROM branches WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    // Format time values for input fields
    if (!empty($row['opening_time'])) {
        $row['opening_time'] = date('H:i', strtotime($row['opening_time']));
    }
    if (!empty($row['closing_time'])) {
        $row['closing_time'] = date('H:i', strtotime($row['closing_time'));
    }
    
    echo json_encode(['success' => true, 'branch' => $row]);
} else {
    echo json_encode(['success' => false, 'message' => 'Branch not found']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>