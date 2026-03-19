<?php
session_start();
$currentPage = 'edit-loan';
$pageTitle = 'Edit Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id <= 0) {
    header('Location: loans.php');
    exit();
}

$message = '';
$error = '';

// Get loan details with customer information
$loan_query = "SELECT l.*, 
               c.customer_name, c.guardian_type, c.guardian_name, c.guardian_mobile,
               c.mobile_number, c.whatsapp_number, c.alternate_mobile, c.email,
               c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
               c.location, c.district, c.pincode, c.post, c.taluk,
               c.aadhaar_number, c.customer_photo,
               u.name as employee_name
               FROM loans l 
               JOIN customers c ON l.customer_id = c.id 
               JOIN users u ON l.employee_id = u.id 
               WHERE l.id = ?";

$stmt = mysqli_prepare($conn, $loan_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$loan_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($loan_result) == 0) {
    header('Location: loans.php');
    exit();
}

$loan = mysqli_fetch_assoc($loan_result);

// Check for pending edit request
$check_request = "SELECT * FROM loan_edit_requests WHERE loan_id = ? AND status = 'pending'";
$stmt = mysqli_prepare($conn, $check_request);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$request_result = mysqli_stmt_get_result($stmt);
$pending_request = mysqli_fetch_assoc($request_result);

// If there's a pending request and user is not admin, redirect
if ($pending_request && $_SESSION['user_role'] != 'admin') {
    header('Location: view-loan.php?id=' . $loan_id . '&error=pending_request');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'submit_request') {
        // Sales staff submitting edit request
        $reason = mysqli_real_escape_string($conn, trim($_POST['reason'] ?? ''));
        $new_customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
        $new_mobile = mysqli_real_escape_string($conn, trim($_POST['mobile_number'] ?? ''));
        $new_guardian = mysqli_real_escape_string($conn, trim($_POST['guardian_name'] ?? ''));
        
        // Build address
        $address_parts = [];
        if (!empty($_POST['door_no'])) $address_parts[] = trim($_POST['door_no']);
        if (!empty($_POST['house_name'])) $address_parts[] = trim($_POST['house_name']);
        if (!empty($_POST['street_name'])) $address_parts[] = trim($_POST['street_name']);
        if (!empty($_POST['location'])) $address_parts[] = trim($_POST['location']);
        if (!empty($_POST['district'])) $address_parts[] = trim($_POST['district']);
        if (!empty($_POST['pincode'])) $address_parts[] = $_POST['pincode'];
        $new_address = implode(', ', $address_parts);
        
        if (empty($reason)) {
            $error = "Please provide a reason for the edit request.";
        } else {
            // Insert edit request
            $insert_query = "INSERT INTO loan_edit_requests (
                loan_id, requested_by, new_customer_name, new_mobile, new_guardian, 
                new_address, reason, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, 'iisssss', 
                $loan_id, 
                $_SESSION['user_id'], 
                $new_customer_name,
                $new_mobile,
                $new_guardian,
                $new_address,
                $reason
            );
            
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'edit_request', 'Edit request submitted for loan: " . $loan['receipt_number'] . "', 'loans', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $loan_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: view-loan.php?id=' . $loan_id . '&success=request_submitted');
                exit();
            } else {
                $error = "Error submitting request: " . mysqli_error($conn);
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'direct_edit' && $_SESSION['user_role'] == 'admin') {
        // Admin directly editing loan
        // Update loan details in database
        $update_query = "UPDATE loans SET 
                        customer_name = ?,
                        mobile_number = ?,
                        guardian_name = ?,
                        address = ?,
                        updated_at = NOW()
                        WHERE id = ?";
        
        $address = mysqli_real_escape_string($conn, $_POST['full_address'] ?? '');
        $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name'] ?? '');
        $mobile = mysqli_real_escape_string($conn, $_POST['mobile_number'] ?? '');
        $guardian = mysqli_real_escape_string($conn, $_POST['guardian_name'] ?? '');
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, 'ssssi', $customer_name, $mobile, $guardian, $address, $loan_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'edit', 'Loan edited directly by admin: " . $loan['receipt_number'] . "', 'loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            header('Location: view-loan.php?id=' . $loan_id . '&success=updated');
            exit();
        } else {
            $error = "Error updating loan: " . mysqli_error($conn);
        }
    }
}

// Format current address
$current_address = trim(implode(', ', array_filter([
    $loan['door_no'],
    $loan['house_name'],
    $loan['street_name'],
    $loan['street_name1'],
    $loan['landmark'],
    $loan['location'],
    $loan['district']
]))) . ($loan['pincode'] ? ' - ' . $loan['pincode'] : '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .edit-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-open {
            background: #48bb78;
            color: white;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72,187,120,0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(236,201,75,0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(160,174,192,0.4);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            animation: slideDown 0.4s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: #dc3545;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeeba 100%);
            color: #856404;
            border-left-color: #ffc107;
        }

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        .form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
        }

        .current-info {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e2e8f0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .info-item {
            margin-bottom: 10px;
        }

        .info-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label i {
            color: #667eea;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
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

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
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

        .readonly-field {
            background: #f1f5f9;
            cursor: not-allowed;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .role-badge.admin {
            background: #667eea20;
            color: #667eea;
        }

        .role-badge.sale {
            background: #48bb7820;
            color: #48bb78;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                <div class="edit-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-pencil-square"></i>
                            Edit Loan: <?php echo $loan['receipt_number']; ?>
                            <span class="status-badge status-<?php echo $loan['status']; ?>">
                                <?php echo strtoupper($loan['status']); ?>
                            </span>
                        </h1>
                        <div>
                            <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Loan
                            </a>
                        </div>
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

                    <!-- Role Information -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        You are editing as <strong><?php echo ucfirst($_SESSION['user_role']); ?></strong>
                        <?php if ($_SESSION['user_role'] == 'admin'): ?>
                            <span class="role-badge admin">Admin</span> - You can edit directly
                        <?php else: ?>
                            <span class="role-badge sale">Sales Staff</span> - Changes require admin approval
                        <?php endif; ?>
                    </div>

                    <!-- Current Information -->
                    <div class="current-info">
                        <h5 style="margin-bottom: 15px; color: #2d3748;">
                            <i class="bi bi-info-circle"></i> Current Information
                        </h5>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Customer Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['customer_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Mobile Number</div>
                                <div class="info-value"><?php echo $loan['mobile_number'] ?: 'N/A'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Guardian Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['guardian_name'] ?: 'N/A'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Current Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($current_address ?: 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Form -->
                    <form method="POST" action="" class="form-card" id="editForm">
                        <input type="hidden" name="action" value="<?php echo $_SESSION['user_role'] == 'admin' ? 'direct_edit' : 'submit_request'; ?>">
                        
                        <div class="section-title">
                            <i class="bi bi-pencil"></i>
                            <?php echo $_SESSION['user_role'] == 'admin' ? 'Edit Loan Details' : 'Submit Edit Request'; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   value="<?php echo htmlspecialchars($loan['customer_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" class="form-control" name="mobile_number" 
                                   value="<?php echo $loan['mobile_number']; ?>" maxlength="10">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Guardian Name</label>
                            <input type="text" class="form-control" name="guardian_name" 
                                   value="<?php echo htmlspecialchars($loan['guardian_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Door No</label>
                            <input type="text" class="form-control" name="door_no" 
                                   value="<?php echo $loan['door_no']; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">House Name</label>
                            <input type="text" class="form-control" name="house_name" 
                                   value="<?php echo $loan['house_name']; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Street Name</label>
                            <input type="text" class="form-control" name="street_name" 
                                   value="<?php echo $loan['street_name']; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   value="<?php echo $loan['location']; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">District</label>
                            <input type="text" class="form-control" name="district" 
                                   value="<?php echo $loan['district']; ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="pincode" 
                                   value="<?php echo $loan['pincode']; ?>" maxlength="6">
                        </div>

                        <!-- Hidden field for full address -->
                        <input type="hidden" name="full_address" id="full_address">

                        <?php if ($_SESSION['user_role'] != 'admin'): ?>
                        <div class="form-group">
                            <label class="form-label required">Reason for Edit Request</label>
                            <textarea class="form-control" name="reason" placeholder="Please explain why you need to edit this loan..." required></textarea>
                            <small style="color: #718096; margin-top: 5px; display: block;">
                                This reason will be reviewed by an admin.
                            </small>
                        </div>

                        <div class="alert alert-warning" style="margin-top: 20px;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Note:</strong> Your changes will not be applied immediately. They require admin approval.
                            Once approved, the loan details will be updated automatically.
                        </div>
                        <?php endif; ?>

                        <div class="action-buttons">
                            <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-<?php echo $_SESSION['user_role'] == 'admin' ? 'success' : 'warning'; ?>">
                                <i class="bi bi-<?php echo $_SESSION['user_role'] == 'admin' ? 'check-circle' : 'send'; ?>"></i>
                                <?php echo $_SESSION['user_role'] == 'admin' ? 'Update Loan' : 'Submit Request'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Build full address before submit
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const doorNo = document.querySelector('input[name="door_no"]').value;
            const houseName = document.querySelector('input[name="house_name"]').value;
            const streetName = document.querySelector('input[name="street_name"]').value;
            const location = document.querySelector('input[name="location"]').value;
            const district = document.querySelector('input[name="district"]').value;
            const pincode = document.querySelector('input[name="pincode"]').value;
            
            const addressParts = [];
            if (doorNo) addressParts.push(doorNo);
            if (houseName) addressParts.push(houseName);
            if (streetName) addressParts.push(streetName);
            if (location) addressParts.push(location);
            if (district) addressParts.push(district);
            if (pincode) addressParts.push(pincode);
            
            document.getElementById('full_address').value = addressParts.join(', ');
            
            <?php if ($_SESSION['user_role'] != 'admin'): ?>
            // Confirm for sales staff
            e.preventDefault();
            Swal.fire({
                title: 'Submit Edit Request?',
                text: 'Your changes will require admin approval. Do you want to continue?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ecc94b',
                cancelButtonColor: '#718096',
                confirmButtonText: 'Yes, submit request',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.submit();
                }
            });
            <?php endif; ?>
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>