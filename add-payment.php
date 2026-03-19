<?php
session_start();
$currentPage = 'add-payment';
$pageTitle = 'Add Payment';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$loan = null;
$customer = null;
$payments = null;

// Get loan ID from URL
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
$receipt_search = isset($_GET['receipt']) ? mysqli_real_escape_string($conn, $_GET['receipt']) : '';

// If receipt number is provided, search for loan
if (!empty($receipt_search) && $loan_id == 0) {
    $search_query = "SELECT id FROM loans WHERE receipt_number = ? AND status = 'open'";
    $stmt = mysqli_prepare($conn, $search_query);
    mysqli_stmt_bind_param($stmt, 's', $receipt_search);
    mysqli_stmt_execute($stmt);
    $search_result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($search_result)) {
        $loan_id = $row['id'];
    } else {
        $error = "No open loan found with Receipt Number: " . htmlspecialchars($receipt_search);
    }
}

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale' OR role = 'accountant') ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Get bank accounts for dropdown
$bank_accounts_query = "SELECT ba.id, ba.account_holder_no, ba.bank_account_no, ba.account_type, 
                        bm.bank_short_name, ba.branch
                        FROM bank_accounts ba
                        JOIN bank_master bm ON ba.bank_id = bm.id
                        WHERE ba.status = 1
                        ORDER BY bm.bank_short_name, ba.account_holder_no";
$bank_accounts_result = mysqli_query($conn, $bank_accounts_query);

// If loan ID is provided, get loan details
if ($loan_id > 0) {
    // Get loan details with customer information
    $loan_query = "SELECT l.*, 
                   c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
                   c.mobile_number, c.whatsapp_number,
                   c.door_no, c.street_name, c.location, c.district, c.pincode,
                   u.name as employee_name,
                   DATEDIFF(NOW(), l.receipt_date) as days_old
                   FROM loans l 
                   JOIN customers c ON l.customer_id = c.id 
                   JOIN users u ON l.employee_id = u.id 
                   WHERE l.id = ? AND l.status = 'open'";

    $stmt = mysqli_prepare($conn, $loan_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $loan_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($loan_result) > 0) {
        $loan = mysqli_fetch_assoc($loan_result);
        
        // Get payment history
        $payments_query = "SELECT p.*, u.name as employee_name,
                          DATE_FORMAT(p.payment_date, '%d/%m/%Y') as payment_date_formatted
                          FROM payments p 
                          JOIN users u ON p.employee_id = u.id 
                          WHERE p.loan_id = ? 
                          ORDER BY p.payment_date DESC, p.created_at DESC";
        $stmt = mysqli_prepare($conn, $payments_query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $payments_result = mysqli_stmt_get_result($stmt);
        $payments = [];
        while ($payment = mysqli_fetch_assoc($payments_result)) {
            $payments[] = $payment;
        }
        
        // Get customer details
        $customer = [
            'id' => $loan['customer_id'],
            'name' => $loan['customer_name'],
            'guardian' => $loan['guardian_name'],
            'guardian_type' => $loan['guardian_type'],
            'mobile' => $loan['mobile_number'],
            'whatsapp' => $loan['whatsapp_number'],
            'address' => trim($loan['door_no'] . ' ' . $loan['street_name'] . ', ' . $loan['location'] . ', ' . $loan['district'] . ' - ' . $loan['pincode'])
        ];
        
        // ===== FIXED: Payment Calculation Section =====
        $principal = floatval($loan['loan_amount']);
        $interest_rate = floatval($loan['interest_amount']);
        $days = $loan['days_old'];
        
        // Get total payments made
        $total_paid_query = "SELECT SUM(principal_amount) as total_principal, 
                                    SUM(interest_amount) as total_interest,
                                    COUNT(*) as payment_count
                             FROM payments WHERE loan_id = ?";
        $stmt = mysqli_prepare($conn, $total_paid_query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $paid_total = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        $paid_principal = floatval($paid_total['total_principal'] ?? 0);
        $paid_interest = floatval($paid_total['total_interest'] ?? 0);
        $payment_count = intval($paid_total['payment_count'] ?? 0);
        
        // Calculate remaining principal
        $remaining_principal = $principal - $paid_principal;
        
        // Calculate monthly interest (based on original principal)
        $monthly_interest = ($principal * $interest_rate) / 100;
        $daily_interest = $monthly_interest / 30;
        
        // Get current date for calculations
        $today = new DateTime(); // FIXED: Define $today variable
        
        // Calculate interest since last payment or loan start
        if ($payment_count > 0) {
            // Get last payment date
            $last_payment_query = "SELECT payment_date FROM payments WHERE loan_id = ? ORDER BY payment_date DESC LIMIT 1";
            $stmt = mysqli_prepare($conn, $last_payment_query);
            mysqli_stmt_bind_param($stmt, 'i', $loan_id);
            mysqli_stmt_execute($stmt);
            $last_payment = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            
            $last_date = new DateTime($last_payment['payment_date']);
            $days_since_last = $last_date->diff($today)->days;
            
            $interest_due = $daily_interest * $days_since_last;
            $interest_period = $days_since_last . ' days since last payment (' . $last_date->format('d-m-Y') . ')';
            
            // Calculate next due date
            $next_due = clone $last_date;
            $next_due->modify('+1 month');
            $next_due_formatted = $next_due->format('d/m/Y');
        } else {
            // No payments yet
            $loan_start = new DateTime($loan['receipt_date']);
            $days_since_start = $loan_start->diff($today)->days;
            
            $interest_due = $daily_interest * $days_since_start;
            $interest_period = $days_since_start . ' days since loan start';
            
            // First due date is 1 month from loan start
            $next_due = clone $loan_start;
            $next_due->modify('+1 month');
            $next_due_formatted = $next_due->format('d/m/Y');
        }
        
        $total_interest_paid = $paid_interest;
        $total_interest_accrued = ($days * $daily_interest);
        $total_interest_remaining = $total_interest_accrued - $total_interest_paid;
        
        // Round values
        $monthly_interest = round($monthly_interest, 2);
        $daily_interest = round($daily_interest, 2);
        $interest_due = round($interest_due, 2);
        $total_interest_accrued = round($total_interest_accrued, 2);
        $total_interest_paid = round($total_interest_paid, 2);
        $total_interest_remaining = round($total_interest_remaining, 2);
        // ===== END FIXED Section =====
        
    } else {
        $error = "No open loan found with ID: " . $loan_id;
        $loan = null;
    }
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_payment') {
    $loan_id = intval($_POST['loan_id']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
    $principal_amount = floatval($_POST['principal_amount'] ?? 0);
    $interest_amount = floatval($_POST['interest_amount'] ?? 0);
    $total_amount = floatval($_POST['total_amount']);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    $bank_account_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
    $reference_number = mysqli_real_escape_string($conn, $_POST['reference_number'] ?? '');
    $employee_id = intval($_POST['employee_id']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    $receipt_number = mysqli_real_escape_string($conn, $_POST['receipt_number']);
    
    // Validate
    $errors = [];
    if ($total_amount <= 0) $errors[] = "Payment amount must be greater than 0";
    if ($employee_id <= 0) $errors[] = "Please select an employee";
    
    // If payment mode is bank or upi, validate bank account
    if (($payment_mode == 'bank' || $payment_mode == 'upi' || $payment_mode == 'cheque') && !$bank_account_id) {
        $errors[] = "Please select a bank account for " . strtoupper($payment_mode) . " payment";
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate payment receipt number
            $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
            $receipt_result = mysqli_query($conn, $receipt_query);
            $receipt_count = mysqli_fetch_assoc($receipt_result)['count'] + 1;
            $payment_receipt = 'PAY' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
            
            // Insert payment
            $insert_payment = "INSERT INTO payments (
                loan_id, receipt_number, payment_date, principal_amount, 
                interest_amount, total_amount, payment_mode, bank_account_id,
                reference_number, employee_id, remarks, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = mysqli_prepare($conn, $insert_payment);
            
            if (!$stmt) {
                throw new Exception("Error preparing payment statement: " . mysqli_error($conn));
            }
            
            mysqli_stmt_bind_param($stmt, 'issdddssiss', 
                $loan_id, 
                $payment_receipt, 
                $payment_date, 
                $principal_amount, 
                $interest_amount, 
                $total_amount,
                $payment_mode, 
                $bank_account_id, 
                $reference_number, 
                $employee_id, 
                $remarks
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error executing payment statement: " . mysqli_stmt_error($stmt));
            }
            
            // ===== FIXED: Check if loan is fully paid =====
            // Get total principal paid so far
            $total_paid_query = "SELECT SUM(principal_amount) as total_principal FROM payments WHERE loan_id = ?";
            $stmt = mysqli_prepare($conn, $total_paid_query);
            mysqli_stmt_bind_param($stmt, 'i', $loan_id);
            mysqli_stmt_execute($stmt);
            $total_paid = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            
            $total_principal_paid = floatval($total_paid['total_principal'] ?? 0);
            
            // Get loan principal amount
            $loan_principal_query = "SELECT loan_amount FROM loans WHERE id = ?";
            $stmt = mysqli_prepare($conn, $loan_principal_query);
            mysqli_stmt_bind_param($stmt, 'i', $loan_id);
            mysqli_stmt_execute($stmt);
            $loan_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $loan_principal = floatval($loan_data['loan_amount']);
            
            // Check if principal is fully paid (with a small tolerance for floating point)
            if ($total_principal_paid >= $loan_principal - 0.01) {
                // Update loan status to closed
                $update_loan = "UPDATE loans SET status = 'closed', close_date = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_loan);
                mysqli_stmt_bind_param($stmt, 'si', $payment_date, $loan_id);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error closing loan: " . mysqli_stmt_error($stmt));
                }
                
                // Add closing note to remarks
                $remarks .= " | Loan fully paid and closed on " . date('d-m-Y', strtotime($payment_date));
            }
            // ===== END FIXED Section =====
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'payment', ?, 'payments', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            
            if (!$log_stmt) {
                throw new Exception("Error preparing log statement: " . mysqli_error($conn));
            }
            
            $payment_type = ($principal_amount > 0 && $interest_amount > 0) ? "Principal + Interest" : 
                           (($principal_amount > 0) ? "Principal" : "Interest");
            
            $log_description = "Payment of ₹" . number_format($total_amount, 2) . " (" . $payment_type . ") added to Loan #" . $receipt_number;
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            
            if (!mysqli_stmt_execute($log_stmt)) {
                throw new Exception("Error executing log statement: " . mysqli_stmt_error($log_stmt));
            }
            
            mysqli_commit($conn);
            
            // Redirect with success message and print receipt
            header('Location: add-payment.php?loan_id=' . $loan_id . '&success=payment_added&print=' . $payment_receipt);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error adding payment: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'payment_added':
            $message = "Payment added successfully!";
            break;
    }
}

// Get payment receipt to print
$print_receipt = isset($_GET['print']) ? $_GET['print'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        .payment-container {
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
            color: white;
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

        .search-box {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .search-box .form-group {
            flex: 1;
            margin-bottom: 0;
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

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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

        .customer-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .detail-item {
            margin-bottom: 10px;
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 2px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .loan-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-item {
            text-align: center;
        }

        .summary-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
        }

        .summary-value.principal {
            color: #667eea;
        }

        .summary-value.interest {
            color: #ecc94b;
        }

        .payment-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .payment-type-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .payment-type-tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .payment-type-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .payment-type-tab.interest-tab.active {
            background: #ecc94b;
            border-color: #ecc94b;
            color: #744210;
        }

        .payment-type-tab.principal-tab.active {
            background: #48bb78;
            border-color: #48bb78;
        }

        .payment-calculation {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .calc-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .calc-label {
            color: #4a5568;
        }

        .calc-value {
            font-weight: 600;
            color: #2d3748;
        }

        .amount-display {
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .amount-display.principal {
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            border: 2px solid #48bb78;
            color: #48bb78;
        }

        .amount-display.interest {
            background: linear-gradient(135deg, #ecc94b10 0%, #d69e2e10 100%);
            border: 2px solid #ecc94b;
            color: #ecc94b;
        }

        .amount-display.both {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea;
            color: #667eea;
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
        }

        .payment-table tr:hover {
            background: #f7fafc;
        }

        .interest-highlight {
            background: #ecc94b20;
            font-weight: 600;
            color: #744210;
        }

        .principal-highlight {
            background: #48bb7820;
            font-weight: 600;
            color: #22543d;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            .print-receipt {
                display: block;
                padding: 30px;
                font-family: 'Poppins', sans-serif;
                max-width: 210mm;
                margin: 0 auto;
            }
            
            .receipt-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #333;
                padding-bottom: 20px;
            }
            
            .receipt-title {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 10px;
            }
            
            .receipt-details {
                margin-bottom: 30px;
            }
            
            .receipt-row {
                display: flex;
                margin-bottom: 10px;
                padding: 5px 0;
                border-bottom: 1px dashed #ccc;
            }
            
            .receipt-label {
                width: 200px;
                font-weight: 600;
            }
            
            .receipt-value {
                flex: 1;
            }
            
            .receipt-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
            }
            
            .receipt-table th {
                background: #f0f0f0;
                padding: 10px;
                text-align: left;
                border: 1px solid #ddd;
            }
            
            .receipt-table td {
                padding: 8px;
                border: 1px solid #ddd;
            }
            
            .receipt-footer {
                margin-top: 50px;
                display: flex;
                justify-content: space-between;
            }
            
            .signature-line {
                width: 200px;
                border-top: 1px solid #333;
                margin-top: 30px;
                text-align: center;
                padding-top: 5px;
            }
            
            .receipt-watermark {
                position: fixed;
                bottom: 20px;
                right: 20px;
                font-size: 10px;
                color: #999;
            }
        }

        .print-receipt {
            display: none;
        }

        .next-due-box {
            background: #ebf4ff;
            border-radius: 10px;
            padding: 15px;
            margin: 10px 0;
            text-align: center;
            border: 1px solid #667eea;
        }

        .next-due-label {
            font-size: 14px;
            color: #4a5568;
        }

        .next-due-value {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
        }

        .bank-details {
            display: none;
            background: #f0fff4;
            border-left: 4px solid #48bb78;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
        }

        .bank-details.show {
            display: block;
        }

        @media (max-width: 1200px) {
            .loan-summary {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .search-box {
                flex-direction: column;
                align-items: stretch;
            }
            
            .customer-info {
                grid-template-columns: 1fr;
            }
            
            .loan-summary {
                grid-template-columns: 1fr;
            }
            
            .payment-type-tabs {
                flex-direction: column;
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
                <div class="payment-container">
                    <!-- Page Header -->
                    <div class="page-header no-print">
                        <h1 class="page-title">
                            <i class="bi bi-cash-stack"></i>
                            Add Payment
                        </h1>
                        <div>
                            <a href="loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Loans
                            </a>
                            <?php if ($loan): ?>
                            <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-info">
                                <i class="bi bi-eye"></i> View Loan
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success no-print"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error no-print"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Print Receipt Section -->
                    <?php if ($print_receipt && $loan): ?>
                        <?php
                        // Get payment details for receipt
                        $receipt_query = "SELECT p.*, u.name as collector_name, l.receipt_number as loan_receipt,
                                         l.receipt_date as loan_date, l.loan_amount,
                                         c.customer_name, c.mobile_number, c.guardian_name,
                                         ba.account_holder_no, ba.bank_account_no, bm.bank_short_name
                                         FROM payments p
                                         JOIN users u ON p.employee_id = u.id
                                         JOIN loans l ON p.loan_id = l.id
                                         JOIN customers c ON l.customer_id = c.id
                                         LEFT JOIN bank_accounts ba ON p.bank_account_id = ba.id
                                         LEFT JOIN bank_master bm ON ba.bank_id = bm.id
                                         WHERE p.receipt_number = ?";
                        $stmt = mysqli_prepare($conn, $receipt_query);
                        mysqli_stmt_bind_param($stmt, 's', $print_receipt);
                        mysqli_stmt_execute($stmt);
                        $receipt_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                        
                        if ($receipt_data):
                        ?>
                        <div class="print-receipt" id="paymentReceipt">
                            <div class="receipt-header">
                                <h2 class="receipt-title">PAYMENT RECEIPT</h2>
                                <p>Receipt No: <?php echo $receipt_data['receipt_number']; ?></p>
                                <p>Date: <?php echo date('d/m/Y', strtotime($receipt_data['payment_date'])); ?></p>
                            </div>
                            
                            <div class="receipt-details">
                                <div class="receipt-row">
                                    <span class="receipt-label">Loan Receipt No:</span>
                                    <span class="receipt-value"><?php echo $receipt_data['loan_receipt']; ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Loan Date:</span>
                                    <span class="receipt-value"><?php echo date('d/m/Y', strtotime($receipt_data['loan_date'])); ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Customer Name:</span>
                                    <span class="receipt-value"><?php echo htmlspecialchars($receipt_data['customer_name']); ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Guardian Name:</span>
                                    <span class="receipt-value"><?php echo htmlspecialchars($receipt_data['guardian_name']); ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Mobile Number:</span>
                                    <span class="receipt-value"><?php echo $receipt_data['mobile_number']; ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Principal Amount:</span>
                                    <span class="receipt-value">₹ <?php echo number_format($receipt_data['loan_amount'], 2); ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Payment Type:</span>
                                    <span class="receipt-value">
                                        <?php 
                                        if ($receipt_data['principal_amount'] > 0 && $receipt_data['interest_amount'] > 0) {
                                            echo "Principal + Interest";
                                        } elseif ($receipt_data['principal_amount'] > 0) {
                                            echo "Principal Only";
                                        } else {
                                            echo "Interest Only";
                                        }
                                        ?>
                                    </span>
                                </div>
                                <?php if ($receipt_data['principal_amount'] > 0): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Principal Paid:</span>
                                    <span class="receipt-value principal-highlight">₹ <?php echo number_format($receipt_data['principal_amount'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($receipt_data['interest_amount'] > 0): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Interest Paid:</span>
                                    <span class="receipt-value interest-highlight">₹ <?php echo number_format($receipt_data['interest_amount'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Total Amount:</span>
                                    <span class="receipt-value" style="font-weight: 700; font-size: 18px;">₹ <?php echo number_format($receipt_data['total_amount'], 2); ?></span>
                                </div>
                                <div class="receipt-row">
                                    <span class="receipt-label">Payment Mode:</span>
                                    <span class="receipt-value"><?php echo strtoupper($receipt_data['payment_mode']); ?></span>
                                </div>
                                <?php if ($receipt_data['payment_mode'] == 'bank' || $receipt_data['payment_mode'] == 'upi'): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Bank Details:</span>
                                    <span class="receipt-value">
                                        <?php echo $receipt_data['bank_short_name']; ?> - 
                                        <?php echo $receipt_data['account_holder_no']; ?> 
                                        (<?php echo $receipt_data['bank_account_no']; ?>)
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($receipt_data['reference_number'])): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Reference No:</span>
                                    <span class="receipt-value"><?php echo $receipt_data['reference_number']; ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Collected By:</span>
                                    <span class="receipt-value"><?php echo htmlspecialchars($receipt_data['collector_name']); ?></span>
                                </div>
                                <?php if (!empty($receipt_data['remarks'])): ?>
                                <div class="receipt-row">
                                    <span class="receipt-label">Remarks:</span>
                                    <span class="receipt-value"><?php echo htmlspecialchars($receipt_data['remarks']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="receipt-footer">
                                <div class="signature-line">Customer Signature</div>
                                <div class="signature-line">Authorized Signature</div>
                            </div>
                            
                            <div class="receipt-watermark">
                                This is a computer generated receipt - <?php echo date('d/m/Y H:i:s'); ?>
                            </div>
                        </div>
                        
                        <div class="no-print" style="text-align: center; margin: 20px 0;">
                            <button onclick="window.print()" class="btn btn-primary">
                                <i class="bi bi-printer"></i> Print Receipt
                            </button>
                            <a href="add-payment.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Payment
                            </a>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Search Section - Show if no loan selected -->
                    <?php if (!$loan && !$print_receipt): ?>
                    <div class="search-card no-print">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Find Loan by Receipt Number
                        </div>

                        <form method="GET" action="" class="search-box">
                            <div class="form-group">
                                <label class="form-label required">Receipt Number</label>
                                <div class="input-group">
                                    <i class="bi bi-receipt input-icon"></i>
                                    <input type="text" class="form-control" name="receipt" value="<?php echo htmlspecialchars($receipt_search); ?>" placeholder="Enter Receipt Number" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($loan && $customer && !$print_receipt): ?>
                        <!-- Customer Information -->
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-person"></i>
                                Customer Information
                            </div>

                            <div class="customer-info">
                                <div class="detail-item">
                                    <div class="detail-label">Customer Name</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($customer['name']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Guardian</div>
                                    <div class="detail-value">
                                        <?php echo $customer['guardian_type'] ? $customer['guardian_type'] . '. ' : ''; ?>
                                        <?php echo htmlspecialchars($customer['guardian']); ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Mobile Number</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($customer['mobile']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Address</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($customer['address']); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Summary -->
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-info-circle"></i>
                                Loan Details - Receipt #<?php echo $loan['receipt_number']; ?>
                            </div>

                            <div class="loan-summary">
                                <div class="summary-item">
                                    <div class="summary-label">Principal Amount</div>
                                    <div class="summary-value principal">₹ <?php echo number_format($loan['loan_amount'], 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Interest Rate</div>
                                    <div class="summary-value"><?php echo $loan['interest_amount']; ?>%</div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Receipt Date</div>
                                    <div class="summary-value"><?php echo date('d/m/Y', strtotime($loan['receipt_date'])); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Days Old</div>
                                    <div class="summary-value"><?php echo $loan['days_old']; ?> days</div>
                                </div>
                            </div>

                            <div class="loan-summary">
                                <div class="summary-item">
                                    <div class="summary-label">Paid Principal</div>
                                    <div class="summary-value">₹ <?php echo number_format($paid_principal, 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Remaining Principal</div>
                                    <div class="summary-value principal">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Paid Interest</div>
                                    <div class="summary-value">₹ <?php echo number_format($total_interest_paid, 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Interest Due</div>
                                    <div class="summary-value interest">₹ <?php echo number_format($interest_due, 2); ?></div>
                                </div>
                            </div>

                            <!-- Next Due Date -->
                            <div class="next-due-box">
                                <div class="next-due-label">Next Interest Due Date</div>
                                <div class="next-due-value"><?php echo $next_due_formatted; ?></div>
                                <div style="font-size: 13px; color: #718096; margin-top: 5px;">
                                    <?php echo $interest_period; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Form -->
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-cash"></i>
                                Add Payment
                            </div>

                            <form method="POST" action="" id="paymentForm">
                                <input type="hidden" name="action" value="add_payment">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <input type="hidden" name="receipt_number" value="<?php echo $loan['receipt_number']; ?>">

                                <!-- Payment Type Tabs -->
                                <div class="payment-type-tabs">
                                    <div class="payment-type-tab interest-tab active" onclick="setPaymentType('interest')">
                                        <i class="bi bi-percent"></i> Interest Only
                                        <div style="font-size: 12px; margin-top: 5px;">Due: ₹ <?php echo number_format($interest_due, 2); ?></div>
                                    </div>
                                    <div class="payment-type-tab principal-tab" onclick="setPaymentType('principal')">
                                        <i class="bi bi-cash"></i> Principal Only
                                        <div style="font-size: 12px; margin-top: 5px;">Remaining: ₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                    </div>
                                    <div class="payment-type-tab both-tab" onclick="setPaymentType('both')">
                                        <i class="bi bi-cash-stack"></i> Principal + Interest
                                        <div style="font-size: 12px; margin-top: 5px;">Total: ₹ <?php echo number_format($remaining_principal + $interest_due, 2); ?></div>
                                    </div>
                                </div>
                                <input type="hidden" name="payment_type" id="payment_type" value="interest">

                                <div class="form-group">
                                    <label class="form-label required">Payment Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="payment_date" id="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="row" style="display: flex; gap: 20px; margin: 20px 0;">
                                    <div style="flex: 1;" id="principal_field">
                                        <label class="form-label">Principal Amount (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-cash input-icon"></i>
                                            <input type="number" class="form-control" name="principal_amount" id="principal_amount" 
                                                   value="0" step="0.01" min="0" max="<?php echo $remaining_principal; ?>" 
                                                   onchange="calculateTotal()">
                                        </div>
                                        <small>Max: ₹ <?php echo number_format($remaining_principal, 2); ?></small>
                                    </div>
                                    <div style="flex: 1;" id="interest_field">
                                        <label class="form-label">Interest Amount (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-percent input-icon"></i>
                                            <input type="number" class="form-control" name="interest_amount" id="interest_amount" 
                                                   value="<?php echo $interest_due; ?>" step="0.01" min="0" max="<?php echo $total_interest_remaining; ?>"
                                                   onchange="calculateTotal()">
                                        </div>
                                        <small>Due: ₹ <?php echo number_format($interest_due, 2); ?></small>
                                    </div>
                                </div>

                                <!-- Amount Display -->
                                <div class="amount-display both" id="amount_display">
                                    ₹ <?php echo number_format($interest_due, 2); ?>
                                    <div style="font-size: 14px; color: #718096;">Total Amount</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Payment Mode</label>
                                    <select class="form-select" name="payment_mode" id="payment_mode" required onchange="toggleBankDetails()">
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="upi">UPI</option>
                                        <option value="cheque">Cheque</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <!-- Bank Details (shown for bank/upi/cheque) -->
                                <div class="bank-details" id="bank_details">
                                    <div class="form-group">
                                        <label class="form-label required">Select Bank Account</label>
                                        <select class="form-select" name="bank_account_id" id="bank_account_id">
                                            <option value="">-- Select Bank Account --</option>
                                            <?php 
                                            mysqli_data_seek($bank_accounts_result, 0);
                                            while($bank = mysqli_fetch_assoc($bank_accounts_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>">
                                                    <?php echo $bank['bank_short_name']; ?> - <?php echo $bank['account_holder_no']; ?> 
                                                    (<?php echo $bank['account_type']; ?>)
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Reference Number / UTR / Cheque No.</label>
                                        <input type="text" class="form-control" name="reference_number" placeholder="Enter reference number">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Employee</label>
                                    <select class="form-select" name="employee_id" required>
                                        <option value="">Select Employee</option>
                                        <?php 
                                        mysqli_data_seek($employees_result, 0);
                                        while($emp = mysqli_fetch_assoc($employees_result)): 
                                        ?>
                                            <option value="<?php echo $emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($emp['name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Remarks (Optional)</label>
                                    <textarea class="form-control" name="remarks" rows="2" placeholder="Add any notes about this payment"></textarea>
                                </div>

                                <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $interest_due; ?>">

                                <div class="action-buttons">
                                    <button type="button" class="btn btn-secondary" onclick="window.location.href='view-loan.php?id=<?php echo $loan_id; ?>'">
                                        <i class="bi bi-x-circle"></i> Cancel
                                    </button>
                                    <button type="submit" class="btn btn-success" onclick="return validatePayment()">
                                        <i class="bi bi-check-circle"></i> Add Payment
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Payment History -->
                        <?php if (!empty($payments)): ?>
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-clock-history"></i>
                                Payment History
                            </div>

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
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo $payment['receipt_number']; ?></td>
                                        <td class="principal-highlight">₹ <?php echo number_format($payment['principal_amount'], 2); ?></td>
                                        <td class="interest-highlight">₹ <?php echo number_format($payment['interest_amount'], 2); ?></td>
                                        <td><strong>₹ <?php echo number_format($payment['total_amount'], 2); ?></strong></td>
                                        <td><?php echo strtoupper($payment['payment_mode']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['employee_name']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td colspan="2" style="text-align: right;">Totals:</td>
                                        <td class="principal-highlight">₹ <?php echo number_format($paid_principal, 2); ?></td>
                                        <td class="interest-highlight">₹ <?php echo number_format($total_interest_paid, 2); ?></td>
                                        <td>₹ <?php echo number_format($paid_principal + $total_interest_paid, 2); ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        let remainingPrincipal = <?php echo $remaining_principal ?? 0; ?>;
        let interestDue = <?php echo $interest_due ?? 0; ?>;
        let totalInterestRemaining = <?php echo $total_interest_remaining ?? 0; ?>;

        // Set payment type
        function setPaymentType(type) {
            document.getElementById('payment_type').value = type;
            
            const tabs = document.querySelectorAll('.payment-type-tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            const principalField = document.getElementById('principal_field');
            const interestField = document.getElementById('interest_field');
            const amountDisplay = document.getElementById('amount_display');
            const principalInput = document.getElementById('principal_amount');
            const interestInput = document.getElementById('interest_amount');
            
            if (type === 'interest') {
                tabs[0].classList.add('active');
                principalField.style.display = 'block';
                interestField.style.display = 'block';
                principalInput.value = 0;
                principalInput.readOnly = true;
                interestInput.value = interestDue.toFixed(2);
                interestInput.readOnly = false;
                amountDisplay.className = 'amount-display interest';
            } else if (type === 'principal') {
                tabs[1].classList.add('active');
                principalField.style.display = 'block';
                interestField.style.display = 'block';
                principalInput.readOnly = false;
                interestInput.value = 0;
                interestInput.readOnly = true;
                amountDisplay.className = 'amount-display principal';
            } else {
                tabs[2].classList.add('active');
                principalField.style.display = 'block';
                interestField.style.display = 'block';
                principalInput.readOnly = false;
                interestInput.readOnly = false;
                principalInput.value = remainingPrincipal.toFixed(2);
                interestInput.value = interestDue.toFixed(2);
                amountDisplay.className = 'amount-display both';
            }
            
            calculateTotal();
        }

        // Calculate total amount
        function calculateTotal() {
            const principal = parseFloat(document.getElementById('principal_amount').value) || 0;
            const interest = parseFloat(document.getElementById('interest_amount').value) || 0;
            const total = principal + interest;
            
            document.getElementById('total_amount').value = total.toFixed(2);
            document.getElementById('amount_display').innerHTML = '₹ ' + total.toFixed(2) + '<div style="font-size: 14px; color: #718096;">Total Amount</div>';
            
            // Validate max values
            if (principal > remainingPrincipal) {
                alert('Principal amount cannot exceed remaining principal of ₹ ' + remainingPrincipal.toFixed(2));
                document.getElementById('principal_amount').value = remainingPrincipal;
                calculateTotal();
            }
            
            if (interest > totalInterestRemaining) {
                alert('Interest amount cannot exceed total interest due of ₹ ' + totalInterestRemaining.toFixed(2));
                document.getElementById('interest_amount').value = totalInterestRemaining;
                calculateTotal();
            }
        }

        // Toggle bank details based on payment mode
        function toggleBankDetails() {
            const mode = document.getElementById('payment_mode').value;
            const bankDetails = document.getElementById('bank_details');
            
            if (mode === 'bank' || mode === 'upi' || mode === 'cheque') {
                bankDetails.classList.add('show');
                document.getElementById('bank_account_id').required = true;
            } else {
                bankDetails.classList.remove('show');
                document.getElementById('bank_account_id').required = false;
            }
        }

        // Validate payment before submit
        function validatePayment() {
            const total = parseFloat(document.getElementById('total_amount').value) || 0;
            const mode = document.getElementById('payment_mode').value;
            
            if (total <= 0) {
                alert('Payment amount must be greater than 0');
                return false;
            }
            
            if ((mode === 'bank' || mode === 'upi' || mode === 'cheque') && !document.getElementById('bank_account_id').value) {
                alert('Please select a bank account for ' + mode.toUpperCase() + ' payment');
                return false;
            }
            
            return confirm('Add payment of ₹ ' + total.toFixed(2) + '?');
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);

        <?php if ($print_receipt): ?>
        // Auto-print receipt
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>