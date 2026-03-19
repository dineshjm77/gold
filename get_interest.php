<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin']);

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    $query = "SELECT * FROM interest_settings WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Interest type not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No ID provided']);
}
?>