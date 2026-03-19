<?php
session_start();
$currentPage = 'reject-edit-request';
$pageTitle = 'Reject Edit Request';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can reject requests
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Check if request ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$request_id = intval($_GET['id']);

// Get request details
$query = "SELECT er.*, l.receipt_number, l.id as loan_id, u.name as requester_name 
          FROM loan_edit_requests er
          JOIN loans l ON er.loan_id = l.id
          JOIN users u ON er.requested_by = u.id
          WHERE er.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $request_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 0) {
    $_SESSION['error_message'] = "Request not found.";
    header('Location: index.php');
    exit();
}

$request = mysqli_fetch_assoc($result);

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rejection_reason = mysqli_real_escape_string($conn, trim($_POST['rejection_reason'] ?? ''));
    
    if (empty($rejection_reason)) {
        $error = "Please provide a reason for rejection.";
    } else {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update request status to rejected
            $update_query = "UPDATE loan_edit_requests SET 
                             status = 'rejected', 
                             rejected_by = ?, 
                             rejected_at = NOW(),
                             rejection_reason = ? 
                             WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, 'isi', $_SESSION['user_id'], $rejection_reason, $request_id);
            mysqli_stmt_execute($stmt);
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'reject', 'Rejected edit request for loan: " . $request['receipt_number'] . "', 'loan_edit_requests', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $request_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            $_SESSION['success_message'] = "Edit request rejected successfully.";
            header('Location: index.php');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error rejecting request: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .container { max-width: 600px; margin: 50px auto; padding: 20px; }
        .card { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        h2 { color: #2d3748; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .info-item { background: #f7fafc; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .info-label { font-size: 13px; color: #718096; margin-bottom: 5px; }
        .info-value { font-size: 16px; font-weight: 600; color: #2d3748; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: #4a5568; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: #f56565; box-shadow: 0 0 0 3px rgba(245,101,101,0.1); }
        .buttons { display: flex; gap: 15px; margin-top: 25px; }
        .btn { padding: 12px 25px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-danger { background: #f56565; color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-secondary { background: #a0aec0; color: white; }
        .btn-secondary:hover { background: #718096; }
        .alert-error { background: #fed7d7; color: #742a2a; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .required::after { content: "*"; color: #f56565; margin-left: 4px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        
        <div class="page-content">
            <div class="container">
                <div class="card">
                    <h2>
                        <i class="bi bi-x-circle" style="color: #f56565;"></i>
                        Reject Edit Request
                    </h2>
                    
                    <?php if ($error): ?>
                        <div class="alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-label">Loan Receipt Number</div>
                        <div class="info-value"><?php echo $request['receipt_number']; ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Requested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['requester_name']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Request Date</div>
                        <div class="info-value"><?php echo date('d-m-Y H:i', strtotime($request['created_at'])); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Reason for Edit</div>
                        <div class="info-value"><?php echo htmlspecialchars($request['request_reason']); ?></div>
                    </div>
                    
                    <?php if (!empty($request['request_details'])): ?>
                    <div class="info-item">
                        <div class="info-label">Additional Details</div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($request['request_details'])); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label class="form-label required">Rejection Reason</label>
                            <textarea class="form-control" name="rejection_reason" rows="4" 
                                      placeholder="Please explain why this edit request is being rejected..." required></textarea>
                        </div>
                        
                        <div style="background: #fff5f5; padding: 15px; border-radius: 8px; margin: 20px 0;">
                            <i class="bi bi-exclamation-triangle" style="color: #f56565;"></i>
                            Rejecting this request will notify the sale user that their request has been declined.
                            The sale user will not be able to edit this loan.
                        </div>
                        
                        <div class="buttons">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-x-lg"></i> Yes, Reject Request
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
</body>
</html>