<?php
session_start();
$currentPage = 'bank-loan-close';
$pageTitle = 'Bank Loan Closure';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// ============== AJAX HANDLER - GET LOAN DETAILS ==============
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_loan_details') {
    header('Content-Type: application/json');
    
    $loan_id = intval($_GET['loan_id'] ?? 0);
    
    if ($loan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid loan ID']);
        exit();
    }
    
    // Get loan details with bank and customer information
    $query = "SELECT bl.*, 
              b.bank_short_name, b.bank_full_name,
              ba.account_holder_no, ba.bank_account_no,
              c.customer_name, c.mobile_number, c.email, c.aadhaar_number,
              u.name as employee_name,
              (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
              (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis,
              (SELECT SUM(paid_amount) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as total_paid
              FROM bank_loans bl
              LEFT JOIN bank_master b ON bl.bank_id = b.id
              LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
              LEFT JOIN customers c ON bl.customer_id = c.id
              LEFT JOIN users u ON bl.employee_id = u.id
              WHERE bl.id = ? AND bl.status = 'active'";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan) {
        echo json_encode(['success' => false, 'message' => 'Active loan not found']);
        exit();
    }
    
    // Get jewelry items for this loan
    $items_query = "SELECT * FROM bank_loan_items WHERE bank_loan_id = ? ORDER BY id ASC";
    $items_stmt = mysqli_prepare($conn, $items_query);
    mysqli_stmt_bind_param($items_stmt, 'i', $loan_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    
    $items = [];
    $total_weight = 0;
    while ($item = mysqli_fetch_assoc($items_result)) {
        $items[] = [
            'id' => $item['id'],
            'jewel_name' => $item['jewel_name'],
            'karat' => floatval($item['karat']),
            'defect_details' => $item['defect_details'] ?? '',
            'stone_details' => $item['stone_details'] ?? '',
            'net_weight' => floatval($item['net_weight']),
            'quantity' => intval($item['quantity']),
            'photo_path' => $item['photo_path'] ?? ''
        ];
        $total_weight += floatval($item['net_weight']) * intval($item['quantity']);
    }
    
    // Get EMI schedule
    $emi_query = "SELECT * FROM bank_loan_emi WHERE bank_loan_id = ? ORDER BY installment_no ASC";
    $emi_stmt = mysqli_prepare($conn, $emi_query);
    mysqli_stmt_bind_param($emi_stmt, 'i', $loan_id);
    mysqli_stmt_execute($emi_stmt);
    $emi_result = mysqli_stmt_get_result($emi_stmt);
    
    $emis = [];
    $paid_count = 0;
    $pending_count = 0;
    while ($emi = mysqli_fetch_assoc($emi_result)) {
        $emis[] = [
            'installment_no' => $emi['installment_no'],
            'due_date' => $emi['due_date'],
            'emi_amount' => floatval($emi['emi_amount']),
            'principal_amount' => floatval($emi['principal_amount']),
            'interest_amount' => floatval($emi['interest_amount']),
            'status' => $emi['status'],
            'paid_date' => $emi['paid_date'] ?? null
        ];
        if ($emi['status'] == 'paid') {
            $paid_count++;
        } else {
            $pending_count++;
        }
    }
    
    // Calculate closure amount
    $total_loan_amount = floatval($loan['loan_amount']);
    $total_paid = floatval($loan['total_paid'] ?? 0);
    $outstanding = $total_loan_amount - $total_paid;
    
    // Calculate penalty if any (e.g., 2% of outstanding for early closure)
    $penalty_percentage = 2; // 2% penalty for early closure
    $penalty_amount = $outstanding * $penalty_percentage / 100;
    
    // Calculate remaining EMIs if not fully paid
    $remaining_emis = $pending_count;
    $future_interest = 0;
    if ($pending_count > 0) {
        foreach ($emis as $emi) {
            if ($emi['status'] == 'pending') {
                $future_interest += $emi['interest_amount'];
            }
        }
    }
    
    // Total closure amount (outstanding + penalty - future interest discount if applicable)
    $closure_amount = $outstanding + $penalty_amount;
    
    echo json_encode([
        'success' => true,
        'loan' => [
            'id' => $loan['id'],
            'loan_reference' => $loan['loan_reference'],
            'loan_date' => date('d-m-Y', strtotime($loan['loan_date'])),
            'loan_amount' => $total_loan_amount,
            'interest_rate' => floatval($loan['interest_rate']),
            'tenure_months' => intval($loan['tenure_months']),
            'emi_amount' => floatval($loan['emi_amount']),
            'total_paid' => $total_paid,
            'outstanding' => $outstanding,
            'penalty_amount' => $penalty_amount,
            'future_interest' => $future_interest,
            'closure_amount' => $closure_amount,
            'bank_name' => $loan['bank_full_name'],
            'bank_short' => $loan['bank_short_name'],
            'account_no' => $loan['bank_account_no'],
            'customer_name' => $loan['customer_name'],
            'customer_mobile' => $loan['mobile_number'],
            'customer_email' => $loan['email'],
            'customer_aadhaar' => $loan['aadhaar_number'],
            'employee_name' => $loan['employee_name'],
            'paid_emis' => $paid_count,
            'pending_emis' => $pending_count,
            'total_emis' => $paid_count + $pending_count,
            'items' => $items,
            'total_weight' => $total_weight,
            'emis' => $emis
        ]
    ]);
    exit();
}

// ============== HANDLE LOAN CLOSURE ==============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_loan') {
    
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $closure_date = mysqli_real_escape_string($conn, $_POST['closure_date'] ?? date('Y-m-d'));
    $closure_amount = floatval($_POST['closure_amount'] ?? 0);
    $outstanding_principal = floatval($_POST['outstanding_principal'] ?? 0);
    $penalty_amount = floatval($_POST['penalty_amount'] ?? 0);
    $waived_interest = floatval($_POST['waived_interest'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Loan closure');
    $release_items = isset($_POST['release_items']) ? 1 : 0;
    
    // Validate
    $errors = [];
    if ($loan_id <= 0) $errors[] = "Invalid loan ID";
    if ($closure_amount < 0) $errors[] = "Closure amount cannot be negative";
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Get loan details
            $loan_query = "SELECT * FROM bank_loans WHERE id = ? AND status = 'active'";
            $loan_stmt = mysqli_prepare($conn, $loan_query);
            mysqli_stmt_bind_param($loan_stmt, 'i', $loan_id);
            mysqli_stmt_execute($loan_stmt);
            $loan_result = mysqli_stmt_get_result($loan_stmt);
            $loan = mysqli_fetch_assoc($loan_result);
            
            if (!$loan) {
                throw new Exception("Active loan not found");
            }
            
            // Generate closure receipt number
            $receipt_query = "SELECT COUNT(*) as count FROM bank_loan_payments WHERE DATE(created_at) = CURDATE()";
            $receipt_result = mysqli_query($conn, $receipt_query);
            $receipt_count = 1;
            if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
                $receipt_data = mysqli_fetch_assoc($receipt_result);
                $receipt_count = ($receipt_data['count'] ?? 0) + 1;
            }
            $receipt_no = 'CLS-' . date('Ymd') . '-' . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
            
            // Create closure payment record
            $payment_query = "INSERT INTO bank_loan_payments (
                bank_loan_id, payment_date, payment_amount, payment_method, 
                receipt_number, employee_id, remarks
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $payment_stmt = mysqli_prepare($conn, $payment_query);
            mysqli_stmt_bind_param($payment_stmt, 'isdssis', 
                $loan_id, $closure_date, $closure_amount, $payment_method, 
                $receipt_no, $_SESSION['user_id'], $remarks
            );
            
            if (!mysqli_stmt_execute($payment_stmt)) {
                throw new Exception("Error creating payment record: " . mysqli_stmt_error($payment_stmt));
            }
            
            // Update all pending EMIs as closed
            $update_emis = "UPDATE bank_loan_emi SET 
                            status = 'closed', 
                            paid_date = ?,
                            remarks = 'Loan closed early'
                            WHERE bank_loan_id = ? AND status = 'pending'";
            $update_emi_stmt = mysqli_prepare($conn, $update_emis);
            mysqli_stmt_bind_param($update_emi_stmt, 'si', $closure_date, $loan_id);
            mysqli_stmt_execute($update_emi_stmt);
            
            // Update loan as closed
            $update_loan = "UPDATE bank_loans SET 
                            status = 'closed', 
                            close_date = ?,
                            remarks = CONCAT(IFNULL(remarks, ''), ' | Closed on ', ?)
                            WHERE id = ?";
            $update_loan_stmt = mysqli_prepare($conn, $update_loan);
            mysqli_stmt_bind_param($update_loan_stmt, 'ssi', $closure_date, $closure_date, $loan_id);
            mysqli_stmt_execute($update_loan_stmt);
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'close', ?, 'bank_loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Closed bank loan: " . $loan['loan_reference'] . " with payment of ₹" . number_format($closure_amount, 2);
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            // Redirect with success message
            header('Location: bank-loan-close.php?success=closed&ref=' . urlencode($loan['loan_reference']) . '&receipt=' . urlencode($receipt_no));
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error closing loan: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Check for success messages
$show_success_alert = false;
$success_message = '';
$success_reference = '';
$success_receipt = '';

if (isset($_GET['success'])) {
    $show_success_alert = true;
    switch ($_GET['success']) {
        case 'closed':
            $success_message = "Bank Loan Closed Successfully!";
            $success_reference = htmlspecialchars($_GET['ref'] ?? '');
            $success_receipt = htmlspecialchars($_GET['receipt'] ?? '');
            break;
    }
}

// Get all active bank loans for dropdown
$loans_query = "SELECT bl.id, bl.loan_reference, bl.loan_date, bl.loan_amount, 
                bl.emi_amount, bl.interest_rate,
                c.customer_name, c.mobile_number,
                (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
                (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis
                FROM bank_loans bl
                JOIN customers c ON bl.customer_id = c.id
                WHERE bl.status = 'active'
                ORDER BY bl.loan_date DESC";
$loans_result = mysqli_query($conn, $loans_query);

// Get recently closed loans
$closed_loans_query = "SELECT bl.*, 
                       b.bank_short_name,
                       c.customer_name,
                       (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis,
                       (SELECT MAX(payment_date) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as last_payment_date
                       FROM bank_loans bl
                       LEFT JOIN bank_master b ON bl.bank_id = b.id
                       LEFT JOIN customers c ON bl.customer_id = c.id
                       WHERE bl.status = 'closed'
                       ORDER BY bl.close_date DESC
                       LIMIT 20";
$closed_loans_result = mysqli_query($conn, $closed_loans_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Animate.css for better animations -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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

        .close-container {
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
        }

        .page-title {
            font-size: 32px;
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

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-title i {
            color: #667eea;
        }

        /* Select2 Custom Styles */
        .select2-container--default .select2-selection--single {
            height: 50px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 15px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 26px;
            color: #2d3748;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 48px;
            right: 15px;
        }

        .select2-dropdown {
            border: 2px solid #667eea;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }

        .select2-results__option {
            padding: 12px 15px;
        }

        .select2-results__option--highlighted {
            background: #667eea !important;
        }

        .loan-option {
            display: flex;
            flex-direction: column;
        }

        .loan-receipt {
            font-weight: 600;
            color: #667eea;
            font-size: 16px;
        }

        .loan-customer {
            color: #2d3748;
            font-size: 14px;
        }

        .loan-details {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-title {
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

        .form-title i {
            color: #667eea;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
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

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #a0aec0;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .readonly-field {
            background: #f7fafc;
            cursor: not-allowed;
        }

        /* Loan Details Section */
        .loan-details-section {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-box {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
        }

        .info-value.amount {
            color: #48bb78;
        }

        .info-value.interest {
            color: #ecc94b;
        }

        .info-value.warning {
            color: #f56565;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 13px;
        }

        .items-table th {
            background: #f7fafc;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .items-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .item-photo-small {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
            cursor: pointer;
        }

        /* EMI Table */
        .emi-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 15px 0;
        }

        .emi-table th {
            background: #f7fafc;
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .emi-table td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-paid {
            background: #48bb78;
            color: white;
        }

        .badge-pending {
            background: #ecc94b;
            color: #744210;
        }

        .badge-closed {
            background: #a0aec0;
            color: white;
        }

        /* Calculation Box */
        .calc-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .calc-row.total {
            font-weight: 700;
            font-size: 18px;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #667eea;
        }

        .calc-label {
            color: #4a5568;
        }

        .calc-value {
            font-weight: 600;
            color: #2d3748;
        }

        .calc-value.amount {
            color: #48bb78;
        }

        .calc-value.penalty {
            color: #f56565;
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-group label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
        }

        /* Payment Options */
        .payment-options {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .payment-option label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-top: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-title {
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

        .table-responsive {
            overflow-x: auto;
        }

        .closed-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .closed-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .closed-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .closed-table tbody tr:hover {
            background: #f7fafc;
        }

        .text-right {
            text-align: right;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .payment-options {
                flex-direction: column;
                gap: 15px;
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
                <div class="close-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-file-earmark-x"></i>
                            Bank Loan Closure
                        </h1>
                        <a href="Bank-Loan.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Loans
                        </a>
                    </div>

                    <!-- Success/Error Messages (Hidden, will use SweetAlert) -->
                    <?php if ($error): ?>
                        <div class="alert alert-error" style="display: none;"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Summary Statistics -->
                    <?php
                    // Get summary stats
                    $active_count = mysqli_num_rows($loans_result);
                    $closed_count = $closed_loans_result ? mysqli_num_rows($closed_loans_result) : 0;
                    
                    $total_loan_amount_query = "SELECT SUM(loan_amount) as total FROM bank_loans WHERE status = 'active'";
                    $total_result = mysqli_query($conn, $total_loan_amount_query);
                    $total_active = mysqli_fetch_assoc($total_result)['total'] ?? 0;
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Loans</div>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                                <div class="stat-sub">Ready for closure</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Active Amount</div>
                                <div class="stat-value">₹<?php echo number_format($total_active, 2); ?></div>
                                <div class="stat-sub">Outstanding loans</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Closed Loans</div>
                                <div class="stat-value"><?php echo $closed_count; ?></div>
                                <div class="stat-sub">Successfully closed</div>
                            </div>
                        </div>
                    </div>

                    <!-- Search Card -->
                    <div class="search-card">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Find Loan to Close
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Select Active Loan</label>
                            <select class="form-select" id="loanSelect" style="width: 100%;" required>
                                <option value="">Search by loan reference or customer name...</option>
                                <?php 
                                if ($loans_result && mysqli_num_rows($loans_result) > 0) {
                                    mysqli_data_seek($loans_result, 0);
                                    while($loan = mysqli_fetch_assoc($loans_result)): 
                                        $progress = $loan['total_emis'] > 0 ? round(($loan['paid_emis'] / $loan['total_emis']) * 100) : 0;
                                ?>
                                    <option value="<?php echo $loan['id']; ?>">
                                        #<?php echo $loan['loan_reference']; ?> - <?php echo htmlspecialchars($loan['customer_name']); ?> 
                                        (₹<?php echo number_format($loan['loan_amount'], 0); ?>, 
                                         <?php echo $loan['paid_emis']; ?>/<?php echo $loan['total_emis']; ?> EMIs)
                                    </option>
                                <?php 
                                    endwhile;
                                } else {
                                    echo '<option value="">No active loans found</option>';
                                }
                                ?>
                            </select>
                            <small class="form-text">Select a loan to view closure details</small>
                        </div>
                    </div>

                    <!-- Loading Spinner -->
                    <div class="loading-spinner" id="loadingSpinner">
                        <div class="spinner"></div>
                        <p>Loading loan details...</p>
                    </div>

                    <!-- Loan Details Section (Hidden initially) -->
                    <div class="form-card" id="loanDetailsSection" style="display: none;">
                        <div class="form-title">
                            <i class="bi bi-info-circle"></i>
                            Loan Closure Details
                        </div>

                        <form method="POST" action="" id="closeForm">
                            <input type="hidden" name="action" value="close_loan">
                            <input type="hidden" name="loan_id" id="loan_id" value="">
                            <input type="hidden" name="outstanding_principal" id="outstanding_principal" value="">
                            <input type="hidden" name="penalty_amount" id="penalty_amount" value="">
                            <input type="hidden" name="waived_interest" id="waived_interest" value="0">

                            <!-- Loan Information -->
                            <div class="loan-details-section">
                                <div class="section-title">
                                    <i class="bi bi-bank"></i>
                                    Loan Information
                                </div>
                                
                                <div class="info-grid" id="loanInfo">
                                    <div class="info-box">
                                        <div class="info-label">Loan Reference</div>
                                        <div class="info-value" id="displayLoanRef">-</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Loan Date</div>
                                        <div class="info-value" id="displayLoanDate">-</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Bank</div>
                                        <div class="info-value" id="displayBank">-</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Customer</div>
                                        <div class="info-value" id="displayCustomer">-</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Mobile</div>
                                        <div class="info-value" id="displayMobile">-</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Email</div>
                                        <div class="info-value" id="displayEmail">-</div>
                                    </div>
                                </div>

                                <!-- Financial Summary -->
                                <div class="section-title" style="margin-top: 20px;">
                                    <i class="bi bi-calculator"></i>
                                    Financial Summary
                                </div>

                                <div class="info-grid">
                                    <div class="info-box">
                                        <div class="info-label">Original Loan Amount</div>
                                        <div class="info-value amount" id="displayLoanAmount">₹0.00</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Interest Rate</div>
                                        <div class="info-value interest" id="displayInterestRate">0%</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">EMI Amount</div>
                                        <div class="info-value" id="displayEMI">₹0.00</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Tenure</div>
                                        <div class="info-value" id="displayTenure">0 months</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">EMIs Paid</div>
                                        <div class="info-value" id="displayEMIsPaid">0/0</div>
                                    </div>
                                    <div class="info-box">
                                        <div class="info-label">Total Paid</div>
                                        <div class="info-value amount" id="displayTotalPaid">₹0.00</div>
                                    </div>
                                </div>

                                <!-- Outstanding Calculation -->
                                <div class="calc-box">
                                    <div class="calc-row">
                                        <span class="calc-label">Outstanding Principal:</span>
                                        <span class="calc-value amount" id="calcOutstanding">₹0.00</span>
                                    </div>
                                    <div class="calc-row">
                                        <span class="calc-label">Early Closure Penalty (2%):</span>
                                        <span class="calc-value penalty" id="calcPenalty">₹0.00</span>
                                    </div>
                                    <div class="calc-row">
                                        <span class="calc-label">Future Interest (Waived):</span>
                                        <span class="calc-value interest" id="calcFutureInterest">₹0.00</span>
                                    </div>
                                    <div class="calc-row total">
                                        <span class="calc-label">Total Closure Amount:</span>
                                        <span class="calc-value total" id="calcTotal">₹0.00</span>
                                    </div>
                                </div>

                                <!-- Jewelry Items -->
                                <div class="section-title" style="margin-top: 20px;">
                                    <i class="bi bi-gem"></i>
                                    Jewelry Items to Release
                                </div>

                                <div class="table-responsive">
                                    <table class="items-table" id="itemsTable">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Karat</th>
                                                <th>Defect</th>
                                                <th>Stone</th>
                                                <th>Weight (g)</th>
                                                <th>Qty</th>
                                                <th>Photo</th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsBody">
                                            <!-- Items will be loaded here -->
                                        </tbody>
                                        <tfoot id="itemsFooter" style="display: none;">
                                            <tr>
                                                <td colspan="4" style="text-align: right;"><strong>Total Weight:</strong></td>
                                                <td><strong id="totalWeight">0.00 g</strong></td>
                                                <td colspan="2"></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Release Items Checkbox -->
                                <div class="checkbox-group">
                                    <input type="checkbox" name="release_items" id="releaseItems" checked>
                                    <label for="releaseItems">Release jewelry items to customer upon closure</label>
                                </div>

                                <!-- EMI Schedule (Collapsible) -->
                                <div class="section-title" style="margin-top: 20px; cursor: pointer;" onclick="toggleEMISchedule()">
                                    <i class="bi bi-calendar-week"></i>
                                    EMI Schedule
                                    <i class="bi bi-chevron-down" id="emiToggleIcon"></i>
                                </div>

                                <div id="emiSchedule" style="display: none;">
                                    <div class="table-responsive">
                                        <table class="emi-table">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Due Date</th>
                                                    <th class="text-right">Principal</th>
                                                    <th class="text-right">Interest</th>
                                                    <th class="text-right">EMI</th>
                                                    <th>Status</th>
                                                    <th>Paid Date</th>
                                                </tr>
                                            </thead>
                                            <tbody id="emiBody">
                                                <!-- EMIs will be loaded here -->
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Closure Form -->
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Closure Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="closure_date" id="closureDate" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Closure Amount (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-currency-rupee input-icon"></i>
                                        <input type="number" class="form-control" name="closure_amount" id="closureAmount" step="0.01" min="0" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Method -->
                            <div class="payment-options">
                                <div class="payment-option">
                                    <input type="radio" name="payment_method" id="paymentCash" value="cash" checked>
                                    <label for="paymentCash">Cash</label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" name="payment_method" id="paymentBank" value="bank">
                                    <label for="paymentBank">Bank Transfer</label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" name="payment_method" id="paymentCheque" value="cheque">
                                    <label for="paymentCheque">Cheque</label>
                                </div>
                                <div class="payment-option">
                                    <input type="radio" name="payment_method" id="paymentUPI" value="upi">
                                    <label for="paymentUPI">UPI</label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Remarks (Optional)</label>
                                <textarea class="form-control" name="remarks" rows="2" placeholder="Add any notes about this closure"></textarea>
                            </div>

                            <div class="action-buttons">
                                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                    <i class="bi bi-x-circle"></i> Clear
                                </button>
                                <button type="submit" class="btn btn-danger" onclick="return confirmClose()">
                                    <i class="bi bi-check-circle"></i> Close Loan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Recently Closed Loans -->
                    <?php if ($closed_loans_result && mysqli_num_rows($closed_loans_result) > 0): ?>
                    <div class="table-card">
                        <div class="table-title">
                            <i class="bi bi-clock-history"></i>
                            Recently Closed Loans
                        </div>
                        <div class="table-responsive">
                            <table class="closed-table">
                                <thead>
                                    <tr>
                                        <th>Close Date</th>
                                        <th>Loan Ref</th>
                                        <th>Customer</th>
                                        <th>Bank</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th>Tenure</th>
                                        <th>EMIs</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($closed = mysqli_fetch_assoc($closed_loans_result)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($closed['close_date'] ?? $closed['created_at'])); ?></td>
                                        <td><strong><?php echo $closed['loan_reference']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($closed['customer_name']); ?></td>
                                        <td><?php echo $closed['bank_short_name'] ?? '-'; ?></td>
                                        <td class="text-right">₹<?php echo number_format($closed['loan_amount'], 2); ?></td>
                                        <td class="text-right"><?php echo $closed['tenure_months']; ?> months</td>
                                        <td class="text-right"><?php echo $closed['total_emis']; ?></td>
                                        <td><span class="badge badge-closed">Closed</span></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS files -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Initialize Select2
        $(document).ready(function() {
            $('#loanSelect').select2({
                placeholder: 'Search by loan reference or customer name...',
                allowClear: true,
                width: '100%'
            });
        });

        let currentLoanData = null;

        // Handle loan selection
        document.getElementById('loanSelect').addEventListener('change', function() {
            var loanId = this.value;
            
            if (loanId) {
                // Hide details section and show loading
                document.getElementById('loanDetailsSection').style.display = 'none';
                document.getElementById('loadingSpinner').style.display = 'block';
                
                // Fetch loan details
                var url = window.location.href.split('?')[0] + '?ajax=get_loan_details&loan_id=' + loanId;
                
                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok (Status: ' + response.status + ')');
                        }
                        return response.json();
                    })
                    .then(data => {
                        document.getElementById('loadingSpinner').style.display = 'none';
                        
                        if (data.success) {
                            displayLoanDetails(data.loan);
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.message || 'Failed to load loan details',
                                icon: 'error',
                                confirmButtonColor: '#667eea'
                            });
                        }
                    })
                    .catch(error => {
                        document.getElementById('loadingSpinner').style.display = 'none';
                        Swal.fire({
                            title: 'Error',
                            text: 'Error loading loan details: ' + error.message,
                            icon: 'error',
                            confirmButtonColor: '#667eea'
                        });
                        console.error('Error:', error);
                    });
            } else {
                document.getElementById('loanDetailsSection').style.display = 'none';
            }
        });

        // Display loan details
        function displayLoanDetails(loan) {
            currentLoanData = loan;
            
            // Set form values
            document.getElementById('loan_id').value = loan.id;
            document.getElementById('outstanding_principal').value = loan.outstanding;
            document.getElementById('penalty_amount').value = loan.penalty_amount;
            document.getElementById('closureAmount').value = loan.closure_amount.toFixed(2);
            
            // Display loan info
            document.getElementById('displayLoanRef').textContent = loan.loan_reference;
            document.getElementById('displayLoanDate').textContent = loan.loan_date;
            document.getElementById('displayBank').textContent = loan.bank_name || loan.bank_short || '-';
            document.getElementById('displayCustomer').textContent = loan.customer_name;
            document.getElementById('displayMobile').textContent = loan.customer_mobile || '-';
            document.getElementById('displayEmail').textContent = loan.customer_email || '-';
            
            // Display financial summary
            document.getElementById('displayLoanAmount').innerHTML = '₹' + formatNumber(loan.loan_amount);
            document.getElementById('displayInterestRate').textContent = loan.interest_rate + '%';
            document.getElementById('displayEMI').innerHTML = '₹' + formatNumber(loan.emi_amount);
            document.getElementById('displayTenure').textContent = loan.tenure_months + ' months';
            document.getElementById('displayEMIsPaid').textContent = loan.paid_emis + '/' + loan.total_emis;
            document.getElementById('displayTotalPaid').innerHTML = '₹' + formatNumber(loan.total_paid);
            
            // Display calculations
            document.getElementById('calcOutstanding').innerHTML = '₹' + formatNumber(loan.outstanding);
            document.getElementById('calcPenalty').innerHTML = '₹' + formatNumber(loan.penalty_amount);
            document.getElementById('calcFutureInterest').innerHTML = '₹' + formatNumber(loan.future_interest);
            document.getElementById('calcTotal').innerHTML = '₹' + formatNumber(loan.closure_amount);
            
            // Display jewelry items
            displayItems(loan.items);
            
            // Display EMI schedule
            displayEMIs(loan.emis);
            
            // Show the details section
            document.getElementById('loanDetailsSection').style.display = 'block';
        }

        // Display jewelry items
        function displayItems(items) {
            const tbody = document.getElementById('itemsBody');
            const footer = document.getElementById('itemsFooter');
            
            if (!items || items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 20px;">No jewelry items found</td></tr>';
                footer.style.display = 'none';
                return;
            }
            
            let html = '';
            let totalWeight = 0;
            
            items.forEach(item => {
                totalWeight += item.net_weight * item.quantity;
                
                let photoHtml = '';
                if (item.photo_path) {
                    photoHtml = `<img src="${item.photo_path}" class="item-photo-small" alt="Jewel" onclick="viewImage('${item.photo_path}')">`;
                } else {
                    photoHtml = `<i class="bi bi-image" style="color: #a0aec0;"></i>`;
                }
                
                html += `
                    <tr>
                        <td>${item.jewel_name}</td>
                        <td>${item.karat}K</td>
                        <td>${item.defect_details || '-'}</td>
                        <td>${item.stone_details || '-'}</td>
                        <td>${item.net_weight}</td>
                        <td>${item.quantity}</td>
                        <td>${photoHtml}</td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
            document.getElementById('totalWeight').innerHTML = totalWeight.toFixed(3) + ' g';
            footer.style.display = 'table-footer-group';
        }

        // Display EMI schedule
        function displayEMIs(emis) {
            const tbody = document.getElementById('emiBody');
            
            if (!emis || emis.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center" style="padding: 20px;">No EMI schedule found</td></tr>';
                return;
            }
            
            let html = '';
            emis.forEach(emi => {
                let statusClass = 'badge-pending';
                let statusText = 'Pending';
                
                if (emi.status === 'paid') {
                    statusClass = 'badge-paid';
                    statusText = 'Paid';
                } else if (emi.status === 'closed') {
                    statusClass = 'badge-closed';
                    statusText = 'Closed';
                }
                
                html += `
                    <tr>
                        <td>${emi.installment_no}</td>
                        <td>${formatDate(emi.due_date)}</td>
                        <td class="text-right">₹${formatNumber(emi.principal_amount)}</td>
                        <td class="text-right">₹${formatNumber(emi.interest_amount)}</td>
                        <td class="text-right">₹${formatNumber(emi.emi_amount)}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>${emi.paid_date ? formatDate(emi.paid_date) : '-'}</td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        // Toggle EMI schedule
        function toggleEMISchedule() {
            const schedule = document.getElementById('emiSchedule');
            const icon = document.getElementById('emiToggleIcon');
            
            if (schedule.style.display === 'none') {
                schedule.style.display = 'block';
                icon.classList.remove('bi-chevron-down');
                icon.classList.add('bi-chevron-up');
            } else {
                schedule.style.display = 'none';
                icon.classList.remove('bi-chevron-up');
                icon.classList.add('bi-chevron-down');
            }
        }

        // View image
        function viewImage(src) {
            Swal.fire({
                title: 'Jewelry Item',
                imageUrl: src,
                imageWidth: 400,
                imageHeight: 300,
                imageAlt: 'Jewelry item',
                confirmButtonColor: '#667eea'
            });
        }

        // Format number with commas
        function formatNumber(num) {
            return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Format date
        function formatDate(dateString) {
            var date = new Date(dateString);
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var year = date.getFullYear();
            return day + '-' + month + '-' + year;
        }

        // Reset form
        function resetForm() {
            $('#loanSelect').val(null).trigger('change');
            document.getElementById('loanDetailsSection').style.display = 'none';
        }

        // Confirm close
        function confirmClose() {
            const amount = parseFloat(document.getElementById('closureAmount').value) || 0;
            
            return Swal.fire({
                title: 'Confirm Loan Closure',
                html: `
                    <p>Are you sure you want to close this loan?</p>
                    <p><strong>Closure Amount: ₹${formatNumber(amount)}</strong></p>
                    <p style="color: #f56565; font-size: 13px;">This action cannot be undone.</p>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, close loan',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('closeForm').submit();
                }
            });
        }

        // Show SweetAlert for success messages
        <?php if ($show_success_alert): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: '<?php echo $success_message; ?>',
                html: `
                    <strong>Reference: <?php echo $success_reference; ?></strong><br>
                    <small>Receipt: <?php echo $success_receipt; ?></small>
                `,
                icon: 'success',
                confirmButtonColor: '#667eea',
                confirmButtonText: 'View Closed Loans',
                timer: 5000,
                timerProgressBar: true,
                showClass: {
                    popup: 'animate__animated animate__fadeInDown'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOutUp'
                }
            }).then((result) => {
                window.location.href = 'bank-loan-close.php';
            });
        });
        <?php endif; ?>

        // Auto-hide any error alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>