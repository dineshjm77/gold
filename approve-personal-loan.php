<?php
session_start();
$currentPage = 'approve-personal-loan';
$pageTitle = 'Approve Personal Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';
require_once 'includes/email_personal_helper.php';

// Only admin can access this page
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get request ID from URL
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id <= 0) {
    header('Location: personal-loan-requests.php');
    exit();
}

// Handle approval/rejection with modification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $admin_remarks = mysqli_real_escape_string($conn, $_POST['admin_remarks'] ?? '');
    
    if ($action === 'approve') {
        $approved_amount = floatval($_POST['approved_amount'] ?? 0);
        $modified_interest = floatval($_POST['modified_interest'] ?? $_POST['original_interest']);
        $modified_tenure = intval($_POST['modified_tenure'] ?? $_POST['original_tenure']);
        $admin_notes = mysqli_real_escape_string($conn, $_POST['admin_notes'] ?? '');
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update request with admin modifications
            $update_query = "UPDATE personal_loan_requests 
                            SET status = 'approved', 
                                approved_amount = ?,
                                admin_modified_amount = ?,
                                admin_modified_interest = ?,
                                admin_modified_tenure = ?,
                                approved_by = ?,
                                approved_date = NOW(),
                                admin_remarks = ?,
                                admin_notes = ?
                            WHERE id = ? AND status = 'pending'";
            
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, 'dddiisssi', 
                $approved_amount, 
                $approved_amount,
                $modified_interest,
                $modified_tenure,
                $_SESSION['user_id'], 
                $admin_remarks,
                $admin_notes,
                $request_id
            );
            mysqli_stmt_execute($stmt);
            
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                // Get request details for email
                $req_query = "SELECT r.*, l.receipt_number, c.email, c.customer_name, c.mobile_number
                              FROM personal_loan_requests r
                              JOIN loans l ON r.loan_id = l.id
                              JOIN customers c ON r.customer_id = c.id
                              WHERE r.id = ?";
                
                $req_stmt = mysqli_prepare($conn, $req_query);
                mysqli_stmt_bind_param($req_stmt, 'i', $request_id);
                mysqli_stmt_execute($req_stmt);
                $req_result = mysqli_stmt_get_result($req_stmt);
                $request = mysqli_fetch_assoc($req_result);
                
                if ($request) {
                    // Send approval email to customer
                    if (function_exists('sendPersonalLoanApprovalEmail')) {
                        sendPersonalLoanApprovalEmail($request, $conn);
                    }
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'approve', ?, 'personal_loan_requests', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $description = "Personal loan approved for ₹" . number_format($approved_amount, 2) . " for request ID: " . $request_id;
                    mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $description, $request_id);
                    mysqli_stmt_execute($log_stmt);
                }
                
                mysqli_commit($conn);
                $message = "Personal loan request approved successfully! Customer has been notified.";
            } else {
                throw new Exception("Failed to approve request");
            }
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error approving request: " . $e->getMessage();
        }
        
    } elseif ($action === 'reject') {
        $rejection_reason = mysqli_real_escape_string($conn, $_POST['rejection_reason'] ?? '');
        
        mysqli_begin_transaction($conn);
        
        try {
            $update_query = "UPDATE personal_loan_requests 
                            SET status = 'rejected', 
                                rejection_reason = ?,
                                approved_by = ?,
                                approved_date = NOW(),
                                admin_remarks = ?
                            WHERE id = ? AND status = 'pending'";
            
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, 'siss', $rejection_reason, $_SESSION['user_id'], $admin_remarks, $request_id);
            mysqli_stmt_execute($stmt);
            
            if (mysqli_stmt_affected_rows($stmt) > 0) {
                // Get request details for email
                $req_query = "SELECT r.*, l.receipt_number, c.email, c.customer_name
                              FROM personal_loan_requests r
                              JOIN loans l ON r.loan_id = l.id
                              JOIN customers c ON r.customer_id = c.id
                              WHERE r.id = ?";
                
                $req_stmt = mysqli_prepare($conn, $req_query);
                mysqli_stmt_bind_param($req_stmt, 'i', $request_id);
                mysqli_stmt_execute($req_stmt);
                $req_result = mysqli_stmt_get_result($req_stmt);
                $request = mysqli_fetch_assoc($req_result);
                
                if ($request) {
                    // Send rejection email
                    if (function_exists('sendPersonalLoanRejectionEmail')) {
                        sendPersonalLoanRejectionEmail($request, $conn);
                    }
                }
                
                mysqli_commit($conn);
                $message = "Personal loan request rejected. Customer has been notified.";
            } else {
                throw new Exception("Failed to reject request");
            }
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error rejecting request: " . $e->getMessage();
        }
    }
}

// Get request details
$request_query = "SELECT r.*, l.receipt_number, l.loan_amount, l.product_value, l.interest_amount,
                         l.receipt_date, l.gross_weight, l.net_weight,
                         c.customer_name, c.email, c.mobile_number, c.door_no, c.house_name,
                         c.street_name, c.location, c.district, c.pincode
                  FROM personal_loan_requests r
                  JOIN loans l ON r.loan_id = l.id
                  JOIN customers c ON r.customer_id = c.id
                  WHERE r.id = ?";

$stmt = mysqli_prepare($conn, $request_query);
mysqli_stmt_bind_param($stmt, 'i', $request_id);
mysqli_stmt_execute($stmt);
$request_result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($request_result);

if (!$request) {
    header('Location: personal-loan-requests.php?error=not_found');
    exit();
}

// Format address
$address_parts = array_filter([
    $request['door_no'] ?? '',
    $request['house_name'] ?? '',
    $request['street_name'] ?? '',
    $request['location'] ?? '',
    $request['district'] ?? ''
]);
$customer_address = !empty($address_parts) ? implode(', ', $address_parts) : 'Address not available';
if (!empty($request['pincode'])) {
    $customer_address .= ' - ' . $request['pincode'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            background: #f8fafc;
        }

        .page-content {
            padding: 30px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .request-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-pending {
            background: #feebc8;
            color: #744210;
        }

        .customer-info {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .customer-details {
            flex: 1;
        }

        .customer-name {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 25px 0;
        }

        .info-box {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
        }

        .info-box h3 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #718096;
            font-size: 13px;
        }

        .info-value {
            font-weight: 600;
            color: #2d3748;
        }

        .amount-highlight {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
        }

        .modified-badge {
            background: #ecc94b;
            color: #744210;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            margin-left: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            border-left: 5px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        .customer-notes {
            background: #ebf4ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-info {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main-content">
            <?php include 'includes/topbar.php'; ?>

            <div class="page-content">
                <div class="container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-person-check"></i>
                            Review Personal Loan Request
                        </h1>
                        <a href="personal-loan-requests.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Requests
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Request Details Card -->
                    <div class="request-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                            <h2 style="color: #2d3748;">Request #<?php echo $request_id; ?></h2>
                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                <?php echo strtoupper($request['status']); ?>
                            </span>
                        </div>

                        <!-- Customer Information -->
                        <div class="customer-info">
                            <div class="customer-details">
                                <div class="customer-name"><?php echo htmlspecialchars($request['customer_name']); ?></div>
                                <div style="display: flex; gap: 20px; margin: 5px 0; color: #4a5568;">
                                    <span><i class="bi bi-phone"></i> <?php echo $request['mobile']; ?></span>
                                    <span><i class="bi bi-envelope"></i> <?php echo $request['email']; ?></span>
                                </div>
                                <div style="color: #718096; font-size: 13px;">
                                    <i class="bi bi-geo-alt"></i> <?php echo $customer_address; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Notes (if any) -->
                        <?php if (!empty($request['customer_notes'])): ?>
                        <div class="customer-notes">
                            <strong><i class="bi bi-chat"></i> Customer Notes:</strong>
                            <p style="margin-top: 5px;"><?php echo nl2br(htmlspecialchars($request['customer_notes'])); ?></p>
                        </div>
                        <?php endif; ?>

                        <!-- Request Details Grid -->
                        <div class="info-grid">
                            <!-- Original Loan Details -->
                            <div class="info-box">
                                <h3><i class="bi bi-receipt"></i> Original Loan Details</h3>
                                <div class="info-row">
                                    <span class="info-label">Receipt Number:</span>
                                    <span class="info-value"><?php echo $request['receipt_number']; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Receipt Date:</span>
                                    <span class="info-value"><?php echo date('d-m-Y', strtotime($request['receipt_date'])); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Loan Amount:</span>
                                    <span class="info-value">₹<?php echo number_format($request['loan_amount'], 2); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Product Value:</span>
                                    <span class="info-value">₹<?php echo number_format($request['product_value'], 2); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Weight:</span>
                                    <span class="info-value"><?php echo $request['gross_weight']; ?>g (Gross) / <?php echo $request['net_weight']; ?>g (Net)</span>
                                </div>
                            </div>

                            <!-- Request Details -->
                            <div class="info-box">
                                <h3><i class="bi bi-send"></i> Request Details</h3>
                                <div class="info-row">
                                    <span class="info-label">Request Date:</span>
                                    <span class="info-value"><?php echo date('d-m-Y H:i', strtotime($request['request_date'])); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Requested Amount:</span>
                                    <span class="info-value amount-highlight">₹<?php echo number_format($request['requested_amount'], 2); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Regular Amount:</span>
                                    <span class="info-value">₹<?php echo number_format($request['regular_loan_amount'], 2); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Interest Rate:</span>
                                    <span class="info-value"><?php echo $request['interest_amount']; ?>%</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Tenure:</span>
                                    <span class="info-value"><?php echo $request['tenure_months']; ?> months</span>
                                </div>
                            </div>
                        </div>

                        <?php if ($request['status'] === 'pending'): ?>
                            <!-- Approval Form with Modification Options -->
                            <form method="POST" action="" id="approvalForm">
                                <div style="margin: 30px 0;">
                                    <h3 style="color: #2d3748; margin-bottom: 20px;">Admin Decision</h3>
                                    
                                    <div style="background: #fef3c7; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                                        <p style="color: #744210;">
                                            <i class="bi bi-info-circle"></i>
                                            You can modify the loan amount, interest rate, or tenure below. The customer will be notified of the approved terms.
                                        </p>
                                    </div>
                                    
                                    <div class="info-grid" style="grid-template-columns: repeat(3, 1fr);">
                                        <div class="form-group">
                                            <label class="form-label">Original Amount</label>
                                            <input type="text" class="form-control" value="₹<?php echo number_format($request['requested_amount'], 2); ?>" readonly>
                                            <input type="hidden" name="original_amount" value="<?php echo $request['requested_amount']; ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Original Interest</label>
                                            <input type="text" class="form-control" value="<?php echo $request['interest_amount']; ?>%" readonly>
                                            <input type="hidden" name="original_interest" value="<?php echo $request['interest_amount']; ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Original Tenure</label>
                                            <input type="text" class="form-control" value="<?php echo $request['tenure_months']; ?> months" readonly>
                                            <input type="hidden" name="original_tenure" value="<?php echo $request['tenure_months']; ?>">
                                        </div>
                                    </div>
                                    
                                    <div style="margin: 20px 0;">
                                        <label class="form-label">Action</label>
                                        <select class="form-select" name="action" id="actionSelect" onchange="toggleActionFields()" required>
                                            <option value="">Select Action</option>
                                            <option value="approve">Approve (with possible modifications)</option>
                                            <option value="reject">Reject Request</option>
                                        </select>
                                    </div>

                                    <div id="approveFields" style="display: none;">
                                        <h4 style="color: #2d3748; margin: 20px 0 15px;">Modified Terms (Optional)</h4>
                                        
                                        <div class="info-grid" style="grid-template-columns: repeat(3, 1fr);">
                                            <div class="form-group">
                                                <label class="form-label">Modified Amount (₹)</label>
                                                <input type="number" class="form-control" name="approved_amount" id="approved_amount" 
                                                       step="0.01" min="0" value="<?php echo $request['requested_amount']; ?>" 
                                                       onchange="document.getElementById('modified_amount_display').value = this.value">
                                                <small style="color: #718096;">Leave unchanged for original amount</small>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">Modified Interest (%)</label>
                                                <input type="number" class="form-control" name="modified_interest" id="modified_interest" 
                                                       step="0.01" min="0" value="<?php echo $request['interest_amount']; ?>">
                                                <small style="color: #718096;">Leave unchanged for original rate</small>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">Modified Tenure (months)</label>
                                                <input type="number" class="form-control" name="modified_tenure" id="modified_tenure" 
                                                       min="1" max="60" value="<?php echo $request['tenure_months']; ?>">
                                                <small style="color: #718096;">Leave unchanged for original tenure</small>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Admin Notes (Internal)</label>
                                            <textarea class="form-control" name="admin_notes" placeholder="Add any internal notes about modifications..."></textarea>
                                        </div>
                                    </div>

                                    <div id="rejectFields" style="display: none;">
                                        <div class="form-group">
                                            <label class="form-label">Rejection Reason <span class="required">*</span></label>
                                            <textarea class="form-control" name="rejection_reason" placeholder="Enter reason for rejection..."></textarea>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Admin Remarks (Customer will see this)</label>
                                        <textarea class="form-control" name="admin_remarks" placeholder="Add any remarks for the customer..."></textarea>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle"></i> Submit Decision
                                    </button>
                                    <a href="personal-loan-requests.php" class="btn btn-secondary">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </a>
                                </div>
                            </form>

                            <script>
                                function toggleActionFields() {
                                    const action = document.getElementById('actionSelect').value;
                                    document.getElementById('approveFields').style.display = action === 'approve' ? 'block' : 'none';
                                    document.getElementById('rejectFields').style.display = action === 'reject' ? 'block' : 'none';
                                }
                            </script>

                        <?php else: ?>
                            <!-- Show decision details for processed requests -->
                            <div style="margin-top: 20px; padding: 20px; background: #f7fafc; border-radius: 12px;">
                                <h4 style="color: #2d3748; margin-bottom: 15px;">Decision Details</h4>
                                
                                <?php if ($request['status'] === 'approved'): ?>
                                    <div class="info-row">
                                        <span class="info-label">Approved Amount:</span>
                                        <span class="info-value amount-highlight">₹<?php echo number_format($request['approved_amount'] ?? $request['requested_amount'], 2); ?></span>
                                    </div>
                                    <?php if ($request['admin_modified_amount'] && $request['admin_modified_amount'] != $request['requested_amount']): ?>
                                        <div class="info-row">
                                            <span class="info-label">Modified Amount:</span>
                                            <span class="info-value">₹<?php echo number_format($request['admin_modified_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['admin_modified_interest']): ?>
                                        <div class="info-row">
                                            <span class="info-label">Interest Rate:</span>
                                            <span class="info-value"><?php echo $request['admin_modified_interest']; ?>% (modified)</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['admin_modified_tenure']): ?>
                                        <div class="info-row">
                                            <span class="info-label">Tenure:</span>
                                            <span class="info-value"><?php echo $request['admin_modified_tenure']; ?> months (modified)</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-row">
                                        <span class="info-label">Approved By:</span>
                                        <span class="info-value">Admin (ID: <?php echo $request['approved_by']; ?>)</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Approved Date:</span>
                                        <span class="info-value"><?php echo date('d-m-Y H:i', strtotime($request['approved_date'])); ?></span>
                                    </div>
                                    
                                <?php elseif ($request['status'] === 'rejected'): ?>
                                    <div class="info-row">
                                        <span class="info-label">Rejection Reason:</span>
                                        <span class="info-value"><?php echo nl2br(htmlspecialchars($request['rejection_reason'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Rejected By:</span>
                                        <span class="info-value">Admin (ID: <?php echo $request['approved_by']; ?>)</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Rejected Date:</span>
                                        <span class="info-value"><?php echo date('d-m-Y H:i', strtotime($request['approved_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($request['admin_remarks'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Admin Remarks:</span>
                                        <span class="info-value"><?php echo nl2br(htmlspecialchars($request['admin_remarks'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($request['admin_notes'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Internal Notes:</span>
                                        <span class="info-value"><?php echo nl2br(htmlspecialchars($request['admin_notes'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($request['status'] === 'approved'): ?>
                            <div style="text-align: center; margin-top: 20px;">
                                <a href="create-personal-loan.php?request_id=<?php echo $request_id; ?>" class="btn btn-success">
                                    <i class="bi bi-plus-circle"></i> Create Loan from this Request
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>