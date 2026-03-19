<?php
session_start();
$currentPage = 'view-loan';
$pageTitle = 'View Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Handle Resend Email
if (isset($_POST['action']) && $_POST['action'] === 'resend_email') {
    if (file_exists('includes/email_helper.php')) {
        require_once 'includes/email_helper.php';
        if (function_exists('sendLoanEmail')) {
            $email_result = sendLoanEmail($loan_id, $conn);
            if ($email_result['success']) {
                $_SESSION['success_message'] = "Email resent successfully!";
                header('Location: view-loan.php?id=' . $loan_id);
                exit();
            } else {
                $_SESSION['error_message'] = "Failed to send email: " . ($email_result['message'] ?? 'Unknown error');
                header('Location: view-loan.php?id=' . $loan_id);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Email helper function not found";
            header('Location: view-loan.php?id=' . $loan_id);
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Email helper file not found";
        header('Location: view-loan.php?id=' . $loan_id);
        exit();
    }
}

// Handle Send Personal Loan Offer
if (isset($_POST['action']) && $_POST['action'] === 'send_personal_offer') {
    if (file_exists('includes/email_helper.php')) {
        require_once 'includes/email_helper.php';
        if (function_exists('sendPersonalLoanOfferEmail')) {
            $offer_result = sendPersonalLoanOfferEmail($loan_id, $conn);
            if ($offer_result['success']) {
                $_SESSION['success_message'] = "Personal loan offer sent successfully!";
                header('Location: view-loan.php?id=' . $loan_id);
                exit();
            } else {
                $_SESSION['error_message'] = "Failed to send personal loan offer: " . ($offer_result['message'] ?? 'Unknown error');
                header('Location: view-loan.php?id=' . $loan_id);
                exit();
            }
        } else {
            $_SESSION['error_message'] = "Personal loan offer function not found";
            header('Location: view-loan.php?id=' . $loan_id);
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Email helper file not found";
        header('Location: view-loan.php?id=' . $loan_id);
        exit();
    }
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Check for success messages from URL
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $message = "Loan created successfully!";
            break;
        case 'updated':
            $message = "Loan updated successfully!";
            break;
        case 'payment_added':
            $message = "Payment added successfully!";
            break;
        case 'closed':
            $message = "Loan closed successfully!";
            break;
        case 'request_submitted':
            $message = "Edit request submitted successfully! It will be reviewed by an admin.";
            break;
        case 'request_approved':
            $message = "Edit request approved successfully!";
            break;
        case 'request_rejected':
            $message = "Edit request rejected successfully!";
            break;
        case 'email_sent':
            $message = "Email sent successfully!";
            break;
        case 'offer_sent':
            $message = "Personal loan offer sent successfully!";
            break;
    }
}

// Check for error messages from URL
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'pending_request':
            $error = "Cannot edit this loan while there's a pending edit request.";
            break;
        case 'not_found':
            $error = "Loan not found.";
            break;
        case 'email_failed':
            $error = "Failed to send email. Please check email settings.";
            break;
    }
}

// ========== HANDLE APPROVE/REJECT ACTIONS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_request', 'reject_request'])) {
    
    // Only admin can approve/reject
    if ($_SESSION['user_role'] !== 'admin') {
        $error = "Unauthorized action";
    } else {
        $request_id = intval($_POST['request_id'] ?? 0);
        $action = $_POST['action'];
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            if ($action === 'approve_request') {
                // Get the pending edit data
                $query = "SELECT * FROM loan_edit_requests WHERE id = ? AND status = 'pending'";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'i', $request_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $request = mysqli_fetch_assoc($result);
                
                if ($request) {
                    // Get the loan to find customer_id
                    $loan_query = "SELECT customer_id FROM loans WHERE id = ?";
                    $loan_stmt = mysqli_prepare($conn, $loan_query);
                    mysqli_stmt_bind_param($loan_stmt, 'i', $request['loan_id']);
                    mysqli_stmt_execute($loan_stmt);
                    $loan_result = mysqli_stmt_get_result($loan_stmt);
                    $loan_data = mysqli_fetch_assoc($loan_result);
                    $customer_id = $loan_data['customer_id'];
                    
                    // Build update query for customers table
                    $update_fields = [];
                    $params = [];
                    $types = '';
                    
                    if (!empty($request['new_customer_name'])) {
                        $update_fields[] = "customer_name = ?";
                        $params[] = $request['new_customer_name'];
                        $types .= 's';
                    }
                    if (!empty($request['new_mobile'])) {
                        $update_fields[] = "mobile_number = ?";
                        $params[] = $request['new_mobile'];
                        $types .= 's';
                    }
                    if (!empty($request['new_guardian'])) {
                        $update_fields[] = "guardian_name = ?";
                        $params[] = $request['new_guardian'];
                        $types .= 's';
                    }
                    
                    if (!empty($update_fields)) {
                        $params[] = $customer_id;
                        $types .= 'i';
                        
                        $update_sql = "UPDATE customers SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $update_sql);
                        mysqli_stmt_bind_param($stmt, $types, ...$params);
                        mysqli_stmt_execute($stmt);
                    }
                    
                    // Update request status
                    $update_req = "UPDATE loan_edit_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_req);
                    mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $request_id);
                    mysqli_stmt_execute($stmt);
                    
                    // Log activity
                    $log = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                            VALUES (?, 'approve', 'Loan edit request approved for loan #" . $request['loan_id'] . "', 'loans', ?)";
                    $stmt = mysqli_prepare($conn, $log);
                    mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $request['loan_id']);
                    mysqli_stmt_execute($stmt);
                    
                    $message = "Edit request approved successfully!";
                }
            } else {
                // Reject - just update the request status
                $update_req = "UPDATE loan_edit_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_req);
                mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $request_id);
                mysqli_stmt_execute($stmt);
                
                // Get loan_id for this request
                $req_query = "SELECT loan_id FROM loan_edit_requests WHERE id = ?";
                $req_stmt = mysqli_prepare($conn, $req_query);
                mysqli_stmt_bind_param($req_stmt, 'i', $request_id);
                mysqli_stmt_execute($req_stmt);
                $req_result = mysqli_stmt_get_result($req_stmt);
                $req_data = mysqli_fetch_assoc($req_result);
                $loan_id_for_log = $req_data['loan_id'] ?? 0;
                
                // Log activity
                $log = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                        VALUES (?, 'reject', 'Loan edit request rejected for loan #" . $loan_id_for_log . "', 'loans', ?)";
                $stmt = mysqli_prepare($conn, $log);
                mysqli_stmt_bind_param($stmt, 'ii', $_SESSION['user_id'], $loan_id_for_log);
                mysqli_stmt_execute($stmt);
                
                $message = "Edit request rejected successfully!";
            }
            
            mysqli_commit($conn);
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error processing request: " . $e->getMessage();
        }
    }
}
// ========== END OF APPROVE/REJECT HANDLING ==========

// Get loan details with customer information
$loan_query = "SELECT l.*, 
               c.customer_name, c.guardian_type, c.guardian_name, c.guardian_mobile,
               c.mobile_number, c.whatsapp_number, c.alternate_mobile, c.email,
               c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
               c.location, c.district, c.pincode, c.post, c.taluk,
               c.aadhaar_number, c.customer_photo,
               c.loan_limit_amount, c.is_noted_person, c.noted_person_remarks,
               c.account_holder_name, c.bank_name, c.branch_name, c.account_number,
               c.ifsc_code, c.account_type, c.upi_id,
               u.name as employee_name,
               DATEDIFF(NOW(), l.created_at) as days_old,
               DATEDIFF(NOW(), l.receipt_date) as days_since_receipt,
               TIMESTAMPDIFF(MONTH, l.receipt_date, NOW()) as months_passed
               FROM loans l 
               JOIN customers c ON l.customer_id = c.id 
               JOIN users u ON l.employee_id = u.id 
               WHERE l.id = ?";

$stmt = mysqli_prepare($conn, $loan_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$loan_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($loan_result) == 0) {
    header('Location: loans.php?error=not_found');
    exit();
}

$loan = mysqli_fetch_assoc($loan_result);

// Debug: Check what product type we have
error_log("Loan Product Type: " . ($loan['product_type'] ?? 'Not set'));

// Get product value settings for personal loan calculation - IMPROVED VERSION
$regular_percent = 70; // Default fallback
$personal_percent = 20; // Set default to 20% as per your database
$regular_loan_amount = 0;
$personal_loan_amount = 0;
$total_potential = 0;

// First try to get from product_value_settings
if (!empty($loan['product_type'])) {
    $product_value_query = "SELECT * FROM product_value_settings WHERE product_type = ? AND status = 1 LIMIT 1";
    $product_value_stmt = mysqli_prepare($conn, $product_value_query);
    mysqli_stmt_bind_param($product_value_stmt, 's', $loan['product_type']);
    mysqli_stmt_execute($product_value_stmt);
    $product_value_result = mysqli_stmt_get_result($product_value_stmt);
    $product_value_settings = mysqli_fetch_assoc($product_value_result);
    
    if ($product_value_settings) {
        $regular_percent = isset($product_value_settings['regular_loan_percentage']) ? floatval($product_value_settings['regular_loan_percentage']) : 70;
        $personal_percent = isset($product_value_settings['personal_loan_percentage']) ? floatval($product_value_settings['personal_loan_percentage']) : 20;
        
        error_log("Found product settings: Regular: $regular_percent%, Personal: $personal_percent%");
    } else {
        error_log("No product settings found for: " . $loan['product_type'] . ", using defaults");
    }
} else {
    error_log("Product type is empty for loan ID: " . $loan_id);
}

// Calculate amounts based on product value
$product_value = floatval($loan['product_value']);
$regular_loan_amount = ($product_value * $regular_percent) / 100;
$personal_loan_amount = ($product_value * $personal_percent) / 100;
$total_potential = $regular_loan_amount + $personal_loan_amount;

// Debug the calculated amounts
error_log("Product Value: $product_value, Regular: $regular_loan_amount, Personal: $personal_loan_amount, Total: $total_potential");

// IMPORTANT FIX: Explicitly get process and appraisal charges with proper defaults
$process_charge = isset($loan['process_charge']) ? floatval($loan['process_charge']) : 0;
$appraisal_charge = isset($loan['appraisal_charge']) ? floatval($loan['appraisal_charge']) : 0;

// Check for pending edit request
$edit_request_query = "SELECT * FROM loan_edit_requests WHERE loan_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1";
$stmt = mysqli_prepare($conn, $edit_request_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$edit_request_result = mysqli_stmt_get_result($stmt);
$pending_edit_request = mysqli_fetch_assoc($edit_request_result);

// Determine if actions should be disabled
$actions_disabled = ($pending_edit_request && $_SESSION['user_role'] != 'admin');
$actions_disabled_message = $actions_disabled ? "Actions are disabled while an edit request is pending approval." : "";

// Get customer's active loans (excluding current loan if closed)
$active_loans_query = "SELECT COUNT(*) as active_count, 
                       COALESCE(SUM(loan_amount), 0) as total_active_amount
                       FROM loans 
                       WHERE customer_id = ? 
                       AND status = 'open' 
                       AND id != ?";
$stmt = mysqli_prepare($conn, $active_loans_query);
mysqli_stmt_bind_param($stmt, 'ii', $loan['customer_id'], $loan_id);
mysqli_stmt_execute($stmt);
$active_loans = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$total_active_loans = floatval($active_loans['total_active_amount']);
$active_loans_count = intval($active_loans['active_count']);

// Calculate loan limit status
$loan_limit = floatval($loan['loan_limit_amount']);
$current_loan_amount = floatval($loan['loan_amount']);
$total_loans_used = $total_active_loans;

// For open loans, include current loan in used amount
if ($loan['status'] == 'open') {
    $total_loans_used += $current_loan_amount;
}

$remaining_limit = $loan_limit - $total_loans_used;
$limit_used_percentage = $loan_limit > 0 ? ($total_loans_used / $loan_limit) * 100 : 0;

// Get loan items (jewelry) with photos
$items_query = "SELECT * FROM loan_items WHERE loan_id = ? ORDER BY id";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);

// Get payment history
$payments_query = "SELECT p.*, u.name as employee_name 
                   FROM payments p 
                   JOIN users u ON p.employee_id = u.id 
                   WHERE p.loan_id = ? 
                   ORDER BY p.payment_date DESC, p.created_at DESC";
$stmt = mysqli_prepare($conn, $payments_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$payments_result = mysqli_stmt_get_result($stmt);

// Get email logs
$email_logs_query = "SELECT * FROM email_logs WHERE loan_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $email_logs_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$email_logs_result = mysqli_stmt_get_result($stmt);

// Calculate interest details
$interest_rate = floatval($loan['interest_amount']);
$principal = floatval($loan['loan_amount']);
$days = $loan['days_since_receipt'];
$months_passed = $loan['months_passed'];

// Get interest calculation method (from loan if stored, default to daily)
$interest_calculation = $loan['interest_calculation'] ?? 'daily';

// Calculate interest based on method
if ($interest_calculation == 'monthly') {
    // Monthly interest calculation
    $monthly_interest = ($principal * $interest_rate) / 100;
    $total_interest = $monthly_interest * max($months_passed, 1);
} else {
    // Daily interest calculation (default)
    $monthly_interest = ($principal * $interest_rate) / 100;
    $total_interest = $monthly_interest * ($days / 30);
}

// Calculate total payable including charges
$total_payable = $principal + $total_interest + $process_charge + $appraisal_charge;

// Get total payments made
$payments_total = mysqli_query($conn, "SELECT SUM(principal_amount) as total_principal, 
                                              SUM(interest_amount) as total_interest,
                                              COUNT(*) as payment_count
                                       FROM payments WHERE loan_id = $loan_id")->fetch_assoc();

$paid_principal = floatval($payments_total['total_principal'] ?? 0);
$paid_interest = floatval($payments_total['total_interest'] ?? 0);
$payment_count = intval($payments_total['payment_count'] ?? 0);

$remaining_principal = $principal - $paid_principal;
$remaining_interest = $total_interest - $paid_interest;
$remaining_total = $remaining_principal + $remaining_interest + $process_charge + $appraisal_charge;

// Handle loan closure
if (isset($_POST['action']) && $_POST['action'] === 'close_loan') {
    // Check if actions are disabled
    if ($actions_disabled) {
        $error = "Cannot close loan while an edit request is pending.";
    } else {
        $close_date = mysqli_real_escape_string($conn, $_POST['close_date']);
        $discount = floatval($_POST['discount'] ?? 0);
        $round_off = floatval($_POST['round_off'] ?? 0);
        $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Update loan status
            $update_query = "UPDATE loans SET 
                             status = 'closed', 
                             close_date = ?,
                             discount = ?,
                             round_off = ?,
                             updated_at = NOW() 
                             WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, 'sddi', $close_date, $discount, $round_off, $loan_id);
            mysqli_stmt_execute($stmt);
            
            // Insert final payment if remaining amount > 0
            if ($remaining_total > 0) {
                $payment_receipt = 'CLS' . date('ymd') . str_pad($loan_id, 4, '0', STR_PAD_LEFT);
                $insert_payment = "INSERT INTO payments (
                    loan_id, receipt_number, payment_date, principal_amount, 
                    interest_amount, total_amount, payment_mode, employee_id, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Loan Closure Payment')";
                
                $stmt = mysqli_prepare($conn, $insert_payment);
                mysqli_stmt_bind_param($stmt, 'issdddsi', 
                    $loan_id, $payment_receipt, $close_date, 
                    $remaining_principal, $remaining_interest, $remaining_total,
                    $payment_method, $_SESSION['user_id']
                );
                mysqli_stmt_execute($stmt);
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'close', ?, 'loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Loan closed: " . $loan['receipt_number'];
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            header('Location: view-loan.php?id=' . $loan_id . '&success=closed');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error closing loan: " . $e->getMessage();
        }
    }
}

// Format address
$customer_address = trim(implode(', ', array_filter([
    $loan['door_no'],
    $loan['house_name'],
    $loan['street_name'],
    $loan['street_name1'],
    $loan['landmark'],
    $loan['location'],
    $loan['district']
]))) . ($loan['pincode'] ? ' - ' . $loan['pincode'] : '');

// Function to check if file exists and get correct path
function getImagePath($path) {
    if (empty($path)) {
        return null;
    }
    
    // Check if file exists
    if (file_exists($path)) {
        return $path;
    }
    
    // Try with different base paths
    $base_paths = [
        '',
        $_SERVER['DOCUMENT_ROOT'] . '/',
        '../',
        '../../'
    ];
    
    foreach ($base_paths as $base) {
        $full_path = $base . $path;
        if (file_exists($full_path)) {
            return $full_path;
        }
    }
    
    return null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        .loan-container {
            max-width: 1400px;
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
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .status-closed {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
        }

        .status-auctioned {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .status-defaulted {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            color: white;
        }

        .noted-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72,187,120,0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .btn-warning:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(236,201,75,0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245,101,101,0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(160,174,192,0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .btn-info:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(66,153,225,0.4);
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

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
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

        .section-title .badge {
            margin-left: auto;
            background: #ebf4ff;
            color: #667eea;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Personal Loan Offer Card */
        .personal-loan-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #ecc94b;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            position: relative;
            overflow: hidden;
        }

        .personal-loan-card::before {
            content: "💰";
            position: absolute;
            right: -20px;
            top: -20px;
            font-size: 100px;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .personal-loan-title {
            font-size: 20px;
            font-weight: 700;
            color: #744210;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .personal-loan-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .personal-loan-item {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .personal-loan-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .personal-loan-value {
            font-size: 20px;
            font-weight: 700;
        }

        .personal-loan-value.regular {
            color: #48bb78;
        }

        .personal-loan-value.personal {
            color: #ecc94b;
        }

        .personal-loan-value.total {
            color: #667eea;
        }

        .personal-loan-percent {
            font-size: 11px;
            color: #a0aec0;
        }

        .offer-badge {
            display: inline-block;
            background: #ecc94b;
            color: #744210;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        /* Edit Request Card */
        .edit-request-card {
            border-left: 4px solid #f59e0b;
            background: #fffbeb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .edit-request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .edit-request-title {
            color: #92400e;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edit-request-actions {
            display: flex;
            gap: 10px;
        }

        .edit-request-details {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }

        .edit-request-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .edit-field {
            margin-bottom: 10px;
        }

        .edit-field-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 3px;
        }

        .edit-field-value {
            font-weight: 600;
            color: #2d3748;
        }

        /* Loan Limit Card */
        .loan-limit-card {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
        }

        .loan-limit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .loan-limit-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loan-limit-amount {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
        }

        .loan-limit-progress {
            height: 10px;
            background: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin: 15px 0;
        }

        .loan-limit-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #4299e1);
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .loan-limit-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .limit-stat-item {
            text-align: center;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .limit-stat-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .limit-stat-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .limit-stat-value.warning {
            color: #f56565;
        }

        .limit-stat-value.success {
            color: #48bb78;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .info-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .info-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .info-item {
            margin-bottom: 15px;
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
            font-size: 14px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value-large {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
        }

        .customer-photo {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            margin-top: 15px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .customer-photo:hover {
            transform: scale(1.05);
        }

        .jewelry-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .jewelry-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .jewelry-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            vertical-align: middle;
        }

        .jewelry-table tr:hover {
            background: #f7fafc;
        }

        .jewel-photo-container {
            position: relative;
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .jewel-photo {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid #667eea;
            transition: transform 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .jewel-photo:hover {
            transform: scale(2);
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            position: relative;
        }

        .no-photo {
            width: 70px;
            height: 70px;
            background: #f8fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a0aec0;
            font-size: 24px;
            border: 2px dashed #cbd5e0;
        }

        .summary-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 25px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-size: 16px;
            color: #4a5568;
        }

        .summary-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .summary-total {
            font-size: 20px;
            font-weight: 700;
            color: #48bb78;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #667eea30;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }

        .payment-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .payment-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .payment-table tr:hover {
            background: #f7fafc;
        }

        .email-log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .email-log-table th {
            background: #f7fafc;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
        }

        .email-log-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .email-sent {
            color: #48bb78;
            font-weight: 600;
        }

        .email-failed {
            color: #f56565;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .modal-close {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
        }

        .form-group {
            margin-bottom: 15px;
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

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .print-section {
            display: none;
        }

        .disabled-notice {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 15px;
            color: #92400e;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }

        .image-modal.active {
            display: flex;
        }

        .image-modal-content {
            max-width: 90%;
            max-height: 90%;
        }

        .image-modal-content img {
            width: 100%;
            height: auto;
            max-height: 90vh;
            object-fit: contain;
            border: 3px solid white;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }

        .image-modal-close {
            position: absolute;
            top: 30px;
            right: 40px;
            color: white;
            font-size: 50px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .image-modal-close:hover {
            color: #f56565;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            .print-section {
                display: block;
            }
            body {
                background: white;
            }
            .main-content {
                background: white;
            }
            .info-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        @media (max-width: 1200px) {
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .loan-limit-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .page-title {
                justify-content: center;
            }
            
            .info-grid, .info-grid-2, .info-grid-3, .loan-limit-stats {
                grid-template-columns: 1fr;
            }
            
            .edit-request-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .edit-request-actions {
                justify-content: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .jewelry-table, .payment-table, .email-log-table {
                overflow-x: auto;
                display: block;
            }
            
            .customer-photo {
                margin: 15px auto 0;
            }
            
            .jewel-photo:hover {
                transform: scale(1.5);
            }
            
            .personal-loan-grid {
                grid-template-columns: 1fr;
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
                <div class="loan-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-receipt"></i>
                            Loan Details: <?php echo $loan['receipt_number']; ?>
                            <span class="status-badge status-<?php echo $loan['status']; ?>">
                                <?php echo strtoupper($loan['status']); ?>
                            </span>
                            <?php if ($loan['is_noted_person']): ?>
                                <span class="noted-badge" title="<?php echo htmlspecialchars($loan['noted_person_remarks']); ?>">
                                    <i class="bi bi-star-fill"></i> Noted Person
                                </span>
                            <?php endif; ?>
                        </h1>
                        <div class="no-print">
                            <a href="loan-receipt-notes.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <button onclick="window.print()" class="btn btn-info">
                                <i class="bi bi-printer"></i> Print
                            </button>
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

                    <!-- Actions Disabled Notice (for non-admins when request pending) -->
                    <?php if ($actions_disabled): ?>
                        <div class="disabled-notice">
                            <i class="bi bi-info-circle-fill"></i>
                            <span><?php echo $actions_disabled_message; ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Edit Request Pending Approval Section -->
                    <?php if ($pending_edit_request): ?>
                        <?php if ($_SESSION['user_role'] == 'admin'): ?>
                        <!-- Admin View - Show Approve/Reject Buttons -->
                        <div class="edit-request-card">
                            <div class="edit-request-header">
                                <div class="edit-request-title">
                                    <i class="bi bi-pencil-square"></i>
                                    Edit Request Pending Approval
                                </div>
                                <div class="edit-request-actions">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="request_id" value="<?php echo $pending_edit_request['id']; ?>">
                                        <input type="hidden" name="action" value="approve_request">
                                        <button type="submit" class="btn btn-success" onclick="return confirmApprove()">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="request_id" value="<?php echo $pending_edit_request['id']; ?>">
                                        <input type="hidden" name="action" value="reject_request">
                                        <button type="submit" class="btn btn-danger" onclick="return confirmReject()">
                                            <i class="bi bi-x-circle"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <p><strong>Reason:</strong> <?php echo htmlspecialchars($pending_edit_request['reason']); ?></p>
                            <p class="text-muted"><small>📎 Submitted on: <?php echo date('d-m-Y H:i', strtotime($pending_edit_request['created_at'])); ?></small></p>
                            
                            <!-- Show Proposed Changes -->
                            <div class="edit-request-details">
                                <h6 style="color: #4a5568; margin-bottom: 15px;">Proposed Changes:</h6>
                                <div class="edit-request-grid">
                                    <?php if ($pending_edit_request['new_customer_name']): ?>
                                    <div class="edit-field">
                                        <div class="edit-field-label">Customer Name</div>
                                        <div class="edit-field-value"><?php echo htmlspecialchars($pending_edit_request['new_customer_name']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($pending_edit_request['new_mobile']): ?>
                                    <div class="edit-field">
                                        <div class="edit-field-label">Mobile Number</div>
                                        <div class="edit-field-value"><?php echo $pending_edit_request['new_mobile']; ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($pending_edit_request['new_guardian']): ?>
                                    <div class="edit-field">
                                        <div class="edit-field-label">Guardian Name</div>
                                        <div class="edit-field-value"><?php echo htmlspecialchars($pending_edit_request['new_guardian']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($pending_edit_request['new_address']): ?>
                                    <div class="edit-field" style="grid-column: span 2;">
                                        <div class="edit-field-label">Address</div>
                                        <div class="edit-field-value"><?php echo htmlspecialchars($pending_edit_request['new_address']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <!-- Non-Admin View - Show Pending Message -->
                        <div class="edit-request-card">
                            <div class="edit-request-header">
                                <div class="edit-request-title">
                                    <i class="bi bi-clock-history"></i>
                                    Edit Request Pending
                                </div>
                            </div>
                            <p>An edit request is pending admin approval. You cannot modify this loan until the request is processed.</p>
                            <p class="text-muted"><small>Submitted on: <?php echo date('d-m-Y H:i', strtotime($pending_edit_request['created_at'])); ?></small></p>
                            <p class="text-muted"><small>Reason: <?php echo htmlspecialchars($pending_edit_request['reason']); ?></small></p>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Customer Information -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-person"></i>
                            Customer Information
                            <?php if ($loan['mobile_number']): ?>
                                <a href="tel:<?php echo $loan['mobile_number']; ?>" class="btn btn-sm btn-success" style="margin-left: auto;">
                                    <i class="bi bi-telephone"></i> Call
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="info-grid-2">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-person-badge"></i> Customer Name
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['customer_name']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-phone"></i> Mobile Number
                                </div>
                                <div class="info-value">
                                    <?php if ($loan['mobile_number']): ?>
                                        <a href="tel:<?php echo $loan['mobile_number']; ?>"><?php echo $loan['mobile_number']; ?></a>
                                        <?php if ($loan['whatsapp_number']): ?>
                                            <a href="https://wa.me/<?php echo $loan['whatsapp_number']; ?>" target="_blank" class="btn btn-sm btn-success" style="margin-left: 10px;">
                                                <i class="bi bi-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($loan['alternate_mobile'])): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-phone-flip"></i> Alternate Mobile
                                </div>
                                <div class="info-value">
                                    <a href="tel:<?php echo $loan['alternate_mobile']; ?>"><?php echo $loan['alternate_mobile']; ?></a>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['email'])): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-envelope"></i> Email
                                </div>
                                <div class="info-value">
                                    <a href="mailto:<?php echo $loan['email']; ?>"><?php echo $loan['email']; ?></a>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-person"></i> Guardian
                                </div>
                                <div class="info-value">
                                    <?php 
                                    if ($loan['guardian_name']) {
                                        echo ($loan['guardian_type'] ? $loan['guardian_type'] . '. ' : '') . $loan['guardian_name'];
                                        if ($loan['guardian_mobile']) {
                                            echo ' <a href="tel:' . $loan['guardian_mobile'] . '"><i class="bi bi-telephone"></i></a>';
                                        }
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-house"></i> Address
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars($customer_address ?: 'N/A'); ?></div>
                                <?php if ($loan['post'] || $loan['taluk']): ?>
                                    <small>Post: <?php echo $loan['post']; ?>, Taluk: <?php echo $loan['taluk']; ?></small>
                                <?php endif; ?>
                            </div>

                            <?php if ($loan['aadhaar_number']): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-card-text"></i> Aadhaar Number
                                </div>
                                <div class="info-value">XXXX-XXXX-<?php echo substr($loan['aadhaar_number'], -4); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php 
                        $customer_photo_path = getImagePath($loan['customer_photo']);
                        if ($customer_photo_path): 
                        ?>
                            <img src="<?php echo $customer_photo_path; ?>" class="customer-photo" alt="Customer Photo" onclick="openImageModal('<?php echo $customer_photo_path; ?>')">
                        <?php endif; ?>
                    </div>

                    <!-- Bank Details (if available) -->
                    <?php if (!empty($loan['account_holder_name']) || !empty($loan['bank_name'])): ?>
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-bank"></i>
                            Bank Details
                        </div>

                        <div class="info-grid-3">
                            <?php if (!empty($loan['account_holder_name'])): ?>
                            <div class="info-item">
                                <div class="info-label">Account Holder</div>
                                <div class="info-value"><?php echo $loan['account_holder_name']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['bank_name'])): ?>
                            <div class="info-item">
                                <div class="info-label">Bank Name</div>
                                <div class="info-value"><?php echo $loan['bank_name']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['branch_name'])): ?>
                            <div class="info-item">
                                <div class="info-label">Branch</div>
                                <div class="info-value"><?php echo $loan['branch_name']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['account_number'])): ?>
                            <div class="info-item">
                                <div class="info-label">Account Number</div>
                                <div class="info-value">XXXX<?php echo substr($loan['account_number'], -4); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['ifsc_code'])): ?>
                            <div class="info-item">
                                <div class="info-label">IFSC Code</div>
                                <div class="info-value"><?php echo $loan['ifsc_code']; ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($loan['upi_id'])): ?>
                            <div class="info-item">
                                <div class="info-label">UPI ID</div>
                                <div class="info-value"><?php echo $loan['upi_id']; ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Loan Limit Status Card -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-pie-chart-fill"></i>
                            Loan Limit Status
                            <?php if ($loan['status'] == 'open'): ?>
                                <span class="badge">Active Loan</span>
                            <?php else: ?>
                                <span class="badge">Historical Loan</span>
                            <?php endif; ?>
                        </div>

                        <div class="loan-limit-card">
                            <div class="loan-limit-header">
                                <div class="loan-limit-title">
                                    <i class="bi bi-credit-card"></i>
                                    Total Loan Limit
                                </div>
                                <div class="loan-limit-amount">
                                    ₹ <?php echo number_format($loan_limit, 2); ?>
                                </div>
                            </div>

                            <div class="loan-limit-progress">
                                <div class="loan-limit-progress-bar" style="width: <?php echo min($limit_used_percentage, 100); ?>%"></div>
                            </div>

                            <div class="loan-limit-stats">
                                <div class="limit-stat-item">
                                    <div class="limit-stat-label">Total Used</div>
                                    <div class="limit-stat-value <?php echo ($total_loans_used / $loan_limit) > 0.8 ? 'warning' : ''; ?>">
                                        ₹ <?php echo number_format($total_loans_used, 2); ?>
                                    </div>
                                    <small>(<?php echo number_format($limit_used_percentage, 1); ?>%)</small>
                                </div>

                                <div class="limit-stat-item">
                                    <div class="limit-stat-label">Remaining Limit</div>
                                    <div class="limit-stat-value <?php echo $remaining_limit < 0 ? 'warning' : 'success'; ?>">
                                        ₹ <?php echo number_format(max($remaining_limit, 0), 2); ?>
                                    </div>
                                    <?php if ($remaining_limit < 0): ?>
                                        <small style="color: #f56565;">Over limit by ₹ <?php echo number_format(abs($remaining_limit), 2); ?></small>
                                    <?php endif; ?>
                                </div>

                                <div class="limit-stat-item">
                                    <div class="limit-stat-label">Active Loans</div>
                                    <div class="limit-stat-value">
                                        <?php echo $active_loans_count + ($loan['status'] == 'open' ? 1 : 0); ?>
                                    </div>
                                    <small>Current: <?php echo $loan['status'] == 'open' ? 'Yes' : 'No'; ?></small>
                                </div>
                            </div>

                            <?php if ($loan['status'] == 'open'): ?>
                                <div style="margin-top: 15px; padding: 10px; background: #ebf8ff; border-radius: 8px; font-size: 13px; color: #2c5282;">
                                    <i class="bi bi-info-circle"></i>
                                    This loan is currently active and is included in the "Total Used" calculation.
                                    <?php if ($remaining_limit < 0): ?>
                                        <strong style="color: #f56565; display: block; margin-top: 5px;">
                                            ⚠️ Customer has exceeded their loan limit. Please review before issuing new loans.
                                        </strong>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 15px; padding: 10px; background: #f7fafc; border-radius: 8px; font-size: 13px; color: #718096;">
                                    <i class="bi bi-clock-history"></i>
                                    This loan is closed. The amount is not included in the "Total Used" calculation.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Loan Information -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Loan Information
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-receipt"></i> Receipt Number
                                </div>
                                <div class="info-value"><?php echo $loan['receipt_number']; ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-calendar"></i> Receipt Date
                                </div>
                                <div class="info-value"><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-calendar"></i> Created Date
                                </div>
                                <div class="info-value"><?php echo date('d-m-Y H:i', strtotime($loan['created_at'])); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-person"></i> Processed By
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['employee_name']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-coin"></i> Gross Weight
                                </div>
                                <div class="info-value"><?php echo $loan['gross_weight']; ?> g</div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-coin"></i> Net Weight
                                </div>
                                <div class="info-value"><?php echo $loan['net_weight']; ?> g</div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-cash-stack"></i> Product Value
                                </div>
                                <div class="info-value">₹ <?php echo number_format($loan['product_value'], 2); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-cash-coin"></i> Loan Amount
                                </div>
                                <div class="info-value-large">₹ <?php echo number_format($loan['loan_amount'], 2); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-percent"></i> Interest Rate
                                </div>
                                <div class="info-value"><?php echo $loan['interest_amount']; ?>% (<?php echo $interest_calculation; ?>)</div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-clock"></i> Days Old
                                </div>
                                <div class="info-value"><?php echo $days; ?> days (<?php echo number_format($months_passed, 1); ?> months)</div>
                            </div>

                            <!-- Process Charge Display -->
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-receipt-cutoff"></i> Process Charge
                                </div>
                                <div class="info-value">₹ <?php echo number_format($process_charge, 2); ?></div>
                            </div>

                            <!-- Appraisal Charge Display -->
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-journal-check"></i> Appraisal Charge
                                </div>
                                <div class="info-value">₹ <?php echo number_format($appraisal_charge, 2); ?></div>
                            </div>

                            <?php if ($loan['status'] == 'closed'): ?>
                                <div class="info-item">
                                    <div class="info-label">
                                        <i class="bi bi-calendar-check"></i> Close Date
                                    </div>
                                    <div class="info-value"><?php echo date('d-m-Y', strtotime($loan['close_date'])); ?></div>
                                </div>

                                <?php if ($loan['discount'] > 0): ?>
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="bi bi-tag"></i> Discount
                                        </div>
                                        <div class="info-value">₹ <?php echo number_format($loan['discount'], 2); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($loan['round_off'] > 0): ?>
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="bi bi-arrow-up-down"></i> Round Off
                                        </div>
                                        <div class="info-value">₹ <?php echo number_format($loan['round_off'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Personal Loan Offer Section -->
                    <?php if ($personal_percent > 0): ?>
                    <!-- Personal Loan Offer Section -->
                    <div class="personal-loan-card">
                        <div class="offer-badge">✨ SPECIAL OFFER</div>
                        <div class="personal-loan-title">
                            <i class="bi bi-gift-fill"></i>
                            Personal Loan Offer Available!
                        </div>
                        
                        <p style="color: #744210; margin-bottom: 15px;">
                            Based on your jewelry value, you are eligible for an additional personal loan on top of your current loan.
                        </p>
                        
                        <div class="personal-loan-grid">
                            <div class="personal-loan-item">
                                <div class="personal-loan-label">Regular Loan (70%)</div>
                                <div class="personal-loan-value regular">
                                    ₹ <?php echo number_format($regular_loan_amount, 2); ?>
                                </div>
                                <div class="personal-loan-percent">(70% of ₹<?php echo number_format($product_value); ?>)</div>
                            </div>
                            <div class="personal-loan-item">
                                <div class="personal-loan-label">Personal Loan (20%)</div>
                                <div class="personal-loan-value personal">
                                    ₹ <?php echo number_format($personal_loan_amount, 2); ?>
                                </div>
                                <div class="personal-loan-percent">(20% of ₹<?php echo number_format($product_value); ?>)</div>
                            </div>
                            <div class="personal-loan-item">
                                <div class="personal-loan-label">Total Available</div>
                                <div class="personal-loan-value total">
                                    ₹ <?php echo number_format($total_potential, 2); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 15px; justify-content: center; margin-top: 20px; flex-wrap: wrap;">
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="send_personal_offer">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Send personal loan offer to <?php echo htmlspecialchars($loan['email']); ?>?')" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                    <i class="bi bi-envelope-paper-fill"></i>
                                    Send Personal Loan Offer
                                </button>
                            </form>
                            <a href="create-personal-loan.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-success" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                <i class="bi bi-plus-circle-fill"></i>
                                Create Personal Loan
                            </a>
                        </div>
                        
                        <p style="font-size: 12px; color: #744210; margin-top: 15px; text-align: center;">
                            <i class="bi bi-info-circle"></i>
                            This offer is valid for 7 days. Terms and conditions apply.
                        </p>
                    </div>
                    <?php else: ?>
                    <!-- Debug info - remove in production -->
                    <div class="alert alert-warning">
                        <i class="bi bi-info-circle"></i>
                        Personal loan offer not available. 
                        Product Type: <?php echo htmlspecialchars($loan['product_type'] ?? 'Not set'); ?>, 
                        Personal Percent: <?php echo $personal_percent; ?>%
                        <?php if (empty($loan['product_type'])): ?>
                        <br><strong>Product type is empty for this loan!</strong>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Jewelry Items -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-gem"></i>
                            Jewelry Items
                            <span class="badge"><?php echo mysqli_num_rows($items_result); ?> Items</span>
                        </div>

                        <table class="jewelry-table">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Jewel Name</th>
                                    <th>Karat</th>
                                    <th>Defect Details</th>
                                    <th>Stone Details</th>
                                    <th>Gross (g)</th>
                                    <th>Net (g)</th>
                                    <th>Qty</th>
                                    <th>Photo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sno = 1;
                                $total_gross = 0;
                                $total_net = 0;
                                mysqli_data_seek($items_result, 0);
                                while($item = mysqli_fetch_assoc($items_result)): 
                                    $total_gross += ($item['gross_weight'] ?? $item['net_weight']) * $item['quantity'];
                                    $total_net += $item['net_weight'] * $item['quantity'];
                                    
                                    // Get photo path
                                    $photo_path = getImagePath($item['photo_path'] ?? '');
                                ?>
                                <tr>
                                    <td><?php echo $sno++; ?></td>
                                    <td><?php echo htmlspecialchars($item['jewel_name']); ?></td>
                                    <td><?php echo $item['karat']; ?>K</td>
                                    <td><?php echo htmlspecialchars($item['defect_details'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($item['stone_details'] ?: '-'); ?></td>
                                    <td><?php echo number_format($item['gross_weight'] ?? $item['net_weight'], 3); ?> g</td>
                                    <td><?php echo number_format($item['net_weight'], 3); ?> g</td>
                                    <td><?php echo $item['quantity']; ?></td>
                                    <td>
                                        <div class="jewel-photo-container">
                                            <?php if ($photo_path): ?>
                                                <img src="<?php echo $photo_path; ?>" class="jewel-photo" onclick="openImageModal('<?php echo $photo_path; ?>')" alt="Jewel Photo">
                                            <?php else: ?>
                                                <div class="no-photo">
                                                    <i class="bi bi-camera"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background: #f7fafc; font-weight: 600;">
                                    <td colspan="5" style="text-align: right;">Total:</td>
                                    <td><?php echo number_format($total_gross, 3); ?> g</td>
                                    <td><?php echo number_format($total_net, 3); ?> g</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Payment Summary -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-cash-stack"></i>
                            Payment Summary
                        </div>

                        <div class="summary-box">
                            <div class="summary-row">
                                <span class="summary-label">Principal Amount:</span>
                                <span class="summary-value">₹ <?php echo number_format($principal, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Interest Rate:</span>
                                <span class="summary-value"><?php echo $interest_rate; ?>% (<?php echo $interest_calculation; ?>)</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Monthly Interest:</span>
                                <span class="summary-value">₹ <?php echo number_format($monthly_interest, 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Period:</span>
                                <span class="summary-value"><?php echo $days; ?> days (<?php echo number_format($days / 30, 2); ?> months)</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Total Interest:</span>
                                <span class="summary-value">₹ <?php echo number_format($total_interest, 2); ?></span>
                            </div>
                            <!-- Process Charge in Summary -->
                            <div class="summary-row">
                                <span class="summary-label">Process Charge:</span>
                                <span class="summary-value">₹ <?php echo number_format($process_charge, 2); ?></span>
                            </div>
                            <!-- Appraisal Charge in Summary -->
                            <div class="summary-row">
                                <span class="summary-label">Appraisal Charge:</span>
                                <span class="summary-value">₹ <?php echo number_format($appraisal_charge, 2); ?></span>
                            </div>
                            
                            <?php if ($payment_count > 0): ?>
                                <div class="summary-row" style="color: #48bb78;">
                                    <span class="summary-label">Paid Principal:</span>
                                    <span class="summary-value">₹ <?php echo number_format($paid_principal, 2); ?></span>
                                </div>
                                <div class="summary-row" style="color: #48bb78;">
                                    <span class="summary-label">Paid Interest:</span>
                                    <span class="summary-value">₹ <?php echo number_format($paid_interest, 2); ?></span>
                                </div>
                                <div class="summary-row" style="color: #f56565;">
                                    <span class="summary-label">Remaining Principal:</span>
                                    <span class="summary-value">₹ <?php echo number_format($remaining_principal, 2); ?></span>
                                </div>
                                <div class="summary-row" style="color: #f56565;">
                                    <span class="summary-label">Remaining Interest:</span>
                                    <span class="summary-value">₹ <?php echo number_format($remaining_interest, 2); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Total Payable including charges -->
                            <div class="summary-total">
                                <span>Total Payable:</span>
                                <span>₹ <?php echo number_format($total_payable, 2); ?></span>
                            </div>
                            
                            <?php if ($payment_count > 0): ?>
                            <div class="summary-total" style="border-top: 2px solid #f56565; color: #f56565;">
                                <span>Remaining Total:</span>
                                <span>₹ <?php echo number_format($remaining_total, 2); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-clock-history"></i>
                            Payment History
                            <?php if ($payment_count > 0): ?>
                                <span class="badge"><?php echo $payment_count; ?> Payments (₹ <?php echo number_format($paid_principal + $paid_interest, 2); ?>)</span>
                            <?php endif; ?>
                        </div>

                        <?php if (mysqli_num_rows($payments_result) > 0): ?>
                            <table class="payment-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt No.</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Total</th>
                                        <th>Mode</th>
                                        <th>Employee</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($payments_result, 0);
                                    while($payment = mysqli_fetch_assoc($payments_result)): 
                                    ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo $payment['receipt_number']; ?></td>
                                        <td>₹ <?php echo number_format($payment['principal_amount'], 2); ?></td>
                                        <td>₹ <?php echo number_format($payment['interest_amount'], 2); ?></td>
                                        <td>₹ <?php echo number_format($payment['total_amount'], 2); ?></td>
                                        <td><?php echo strtoupper($payment['payment_mode']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['employee_name']); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td colspan="2" style="text-align: right;">Total:</td>
                                        <td>₹ <?php echo number_format($paid_principal, 2); ?></td>
                                        <td>₹ <?php echo number_format($paid_interest, 2); ?></td>
                                        <td>₹ <?php echo number_format($paid_principal + $paid_interest, 2); ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; color: #718096; padding: 30px;">No payments made yet</p>
                        <?php endif; ?>
                    </div>

                    <!-- Email Logs -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-envelope"></i>
                            Email Notifications
                            <?php if (mysqli_num_rows($email_logs_result) > 0): ?>
                                <span class="badge"><?php echo mysqli_num_rows($email_logs_result); ?> Emails</span>
                            <?php endif; ?>
                        </div>

                        <?php if (mysqli_num_rows($email_logs_result) > 0): ?>
                            <table class="email-log-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt</th>
                                        <th>To</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($log = mysqli_fetch_assoc($email_logs_result)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y H:i', strtotime($log['created_at'])); ?></td>
                                        <td><?php echo $log['receipt_number']; ?></td>
                                        <td><?php echo $log['customer_email']; ?></td>
                                        <td>
                                            <span class="<?php echo $log['status'] == 'sent' ? 'email-sent' : 'email-failed'; ?>">
                                                <?php echo strtoupper($log['status']); ?>
                                            </span>
                                            <?php if ($log['status'] == 'sent' && $log['sent_at']): ?>
                                                <br><small><?php echo date('H:i', strtotime($log['sent_at'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; color: #718096; padding: 30px;">No email notifications sent</p>
                        <?php endif; ?>

                        <!-- Working Resend Email and Personal Offer Buttons -->
                        <?php if ($loan['email']): ?>
                            <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="resend_email">
                                    <button type="submit" class="btn btn-info" onclick="return confirm('Resend loan details email to <?php echo htmlspecialchars($loan['email']); ?>?')" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                        <i class="bi bi-envelope"></i> Resend Email
                                    </button>
                                </form>
                                
                                <?php if ($personal_percent > 0): ?>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="send_personal_offer">
                                    <button type="submit" class="btn btn-warning" onclick="return confirm('Send personal loan offer to <?php echo htmlspecialchars($loan['email']); ?>?')" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                        <i class="bi bi-envelope-paper-fill"></i> Send Personal Offer
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons no-print">
                        <?php if ($loan['status'] == 'open'): ?>
                            <button type="button" class="btn btn-success" onclick="showCloseModal()" <?php echo $actions_disabled ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-circle"></i> Close Loan
                            </button>
                        <?php endif; ?>
                        
                        <a href="print-receipt.php?id=<?php echo $loan_id; ?>" class="btn btn-info" target="_blank">
                            <i class="bi bi-receipt"></i> Print Receipt
                        </a>
                        
                        <a href="loan-receipt-notes.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Image Modal for Enlarged View -->
    <div class="image-modal" id="imageModal">
        <span class="image-modal-close" onclick="closeImageModal()">&times;</span>
        <div class="image-modal-content">
            <img id="expandedImage" src="" alt="Expanded View">
        </div>
    </div>

    <!-- Close Loan Modal -->
    <div class="modal" id="closeModal">
        <div class="modal-content">
            <span class="modal-close" onclick="hideCloseModal()">&times;</span>
            <h3 class="modal-title">Close Loan #<?php echo $loan['receipt_number']; ?></h3>
            
            <form method="POST" action="" id="closeLoanForm">
                <input type="hidden" name="action" value="close_loan">
                
                <div class="form-group">
                    <label class="form-label required">Close Date</label>
                    <input type="date" class="form-control" name="close_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="summary-box" style="margin: 20px 0;">
                    <div class="summary-row">
                        <span>Remaining Principal:</span>
                        <span>₹ <?php echo number_format($remaining_principal, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Remaining Interest:</span>
                        <span>₹ <?php echo number_format($remaining_interest, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Process Charge:</span>
                        <span>₹ <?php echo number_format($process_charge, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Appraisal Charge:</span>
                        <span>₹ <?php echo number_format($appraisal_charge, 2); ?></span>
                    </div>
                    <div class="summary-total">
                        <span>Total Payable:</span>
                        <span>₹ <?php echo number_format($remaining_total, 2); ?></span>
                    </div>
                </div>

                <!-- Loan Limit Info in Modal -->
                <?php if ($loan['status'] == 'open'): ?>
                    <div style="margin: 15px 0; padding: 10px; background: #ebf8ff; border-radius: 8px; font-size: 13px;">
                        <i class="bi bi-info-circle"></i>
                        Upon closing this loan, <strong>₹ <?php echo number_format($remaining_principal, 2); ?></strong> will be added back to the customer's available limit.
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Discount (₹)</label>
                    <input type="number" class="form-control" name="discount" id="discount" value="0" step="0.01" min="0" oninput="calculateFinalAmount()">
                </div>

                <div class="form-group">
                    <label class="form-label">Round Off (₹)</label>
                    <input type="number" class="form-control" name="round_off" id="round_off" value="0" step="0.01" oninput="calculateFinalAmount()">
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Method</label>
                    <select class="form-select" name="payment_method" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="summary-box" style="margin: 20px 0; background: #48bb7810;">
                    <div class="summary-row">
                        <span style="font-weight: 600;">Final Amount:</span>
                        <span style="font-weight: 700; color: #48bb78; font-size: 20px;" id="final_amount">
                            ₹ <?php echo number_format($remaining_total, 2); ?>
                        </span>
                    </div>
                </div>

                <div class="action-buttons" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="hideCloseModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" onclick="return confirmClose()">Confirm Close</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Show close modal
        function showCloseModal() {
            document.getElementById('closeModal').classList.add('active');
        }

        // Hide close modal
        function hideCloseModal() {
            document.getElementById('closeModal').classList.remove('active');
        }

        // Calculate final amount with discount and round off
        function calculateFinalAmount() {
            const remainingTotal = <?php echo $remaining_total; ?>;
            const discount = parseFloat(document.getElementById('discount').value) || 0;
            const roundOff = parseFloat(document.getElementById('round_off').value) || 0;
            
            let finalAmount = remainingTotal - discount + roundOff;
            if (finalAmount < 0) finalAmount = 0;
            
            document.getElementById('final_amount').textContent = '₹ ' + finalAmount.toFixed(2);
        }

        // Confirm close with SweetAlert
        function confirmClose() {
            const finalAmount = document.getElementById('final_amount').textContent;
            Swal.fire({
                title: 'Close Loan?',
                html: `Are you sure you want to close this loan?<br><br>Final Amount: <strong>${finalAmount}</strong>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#718096',
                confirmButtonText: 'Yes, close loan',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('closeLoanForm').submit();
                }
            });
            return false;
        }

        // Confirm approve
        function confirmApprove() {
            return confirm('Are you sure you want to approve this edit request?');
        }

        // Confirm reject
        function confirmReject() {
            return confirm('Are you sure you want to reject this edit request?');
        }

        // Image Modal Functions
        function openImageModal(src) {
            document.getElementById('expandedImage').src = src;
            document.getElementById('imageModal').classList.add('active');
        }

        function closeImageModal() {
            document.getElementById('imageModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('closeModal');
            if (event.target == modal) {
                hideCloseModal();
            }
            
            const imageModal = document.getElementById('imageModal');
            if (event.target == imageModal) {
                closeImageModal();
            }
        }

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