<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can approve/reject
if ($_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$loan_id = intval($_POST['loan_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$loan_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    if ($action === 'approve') {
        // Get the pending edit data
        $query = "SELECT * FROM loan_edit_requests WHERE loan_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $request = mysqli_fetch_assoc($result);
        
        if ($request) {
            // Update the loan with the requested changes
            $update = "UPDATE loans SET 
                       customer_name = ?, 
                       mobile_number = ?,
                       guardian_name = ?,
                       address = ?,
                       updated_at = NOW()
                       WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $update);
            mysqli_stmt_bind_param($stmt, 'ssssi', 
                $request['new_customer_name'],
                $request['new_mobile'],
                $request['new_guardian'],
                $request['new_address'],
                $loan_id
            );
            mysqli_stmt_execute($stmt);
            
            // Update request status
            $update_req = "UPDATE loan_edit_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_req);
            mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $request['id']);
            mysqli_stmt_execute($stmt);
            
            // Log activity
            $log = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                    VALUES (?, 'approve', 'Loan edit request approved', 'loans', ?)";
            $stmt = mysqli_prepare($conn, $log);
            mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $loan_id);
            mysqli_stmt_execute($stmt);
        }
    } else {
        // Reject - just update the request status
        $update_req = "UPDATE loan_edit_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE loan_id = ? AND status = 'pending'";
        $stmt = mysqli_prepare($conn, $update_req);
        mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $loan_id);
        mysqli_stmt_execute($stmt);
        
        // Log activity
        $log = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                VALUES (?, 'reject', 'Loan edit request rejected', 'loans', ?)";
        $stmt = mysqli_prepare($conn, $log);
        mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $loan_id);
        mysqli_stmt_execute($stmt);
    }
    
    mysqli_commit($conn);
    echo json_encode(['success' => true, 'message' => 'Request ' . $action . 'd successfully']);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>