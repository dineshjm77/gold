<?php
session_start();
$currentPage = 'create-personal-loan';
$pageTitle = 'Create Personal Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';
require_once 'includes/email_personal_helper.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get mode from URL - 'direct' or 'request'
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'direct';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale') ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Get all customers for dropdown
$customers_query = "SELECT id, customer_name, mobile_number, email FROM customers ORDER BY customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get banks for payment method
$banks_query = "SELECT bm.id, bm.bank_full_name, 
                COALESCE((SELECT SUM(opening_balance) FROM bank_accounts WHERE bank_id = bm.id AND status = 1), 0) as total_balance
                FROM bank_master bm 
                WHERE bm.status = 1 
                ORDER BY bm.bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get bank accounts with current balance
$bank_accounts_query = "SELECT ba.*, bm.bank_full_name, bm.bank_short_name,
                        COALESCE((SELECT balance FROM bank_ledger WHERE bank_account_id = ba.id ORDER BY id DESC LIMIT 1), ba.opening_balance) as current_balance
                        FROM bank_accounts ba 
                        LEFT JOIN bank_master bm ON ba.bank_id = bm.id 
                        WHERE ba.status = 1 
                        ORDER BY bm.bank_full_name, ba.account_holder_no";
$bank_accounts_result = mysqli_query($conn, $bank_accounts_query);

// Get approved requests if in request mode
$approved_requests = [];
if ($mode == 'request') {
    $requests_query = "SELECT r.*, l.receipt_number, c.customer_name 
                       FROM personal_loan_requests r
                       JOIN loans l ON r.loan_id = l.id
                       JOIN customers c ON r.customer_id = c.id
                       WHERE r.status = 'approved' 
                       ORDER BY r.approved_date DESC";
    $requests_result = mysqli_query($conn, $requests_query);
    while ($row = mysqli_fetch_assoc($requests_result)) {
        $approved_requests[] = $row;
    }
}

// Get customer details if selected
$selected_customer = null;
if ($customer_id > 0) {
    $customer_query = "SELECT * FROM customers WHERE id = ?";
    $customer_stmt = mysqli_prepare($conn, $customer_query);
    mysqli_stmt_bind_param($customer_stmt, 'i', $customer_id);
    mysqli_stmt_execute($customer_stmt);
    $customer_result = mysqli_stmt_get_result($customer_stmt);
    $selected_customer = mysqli_fetch_assoc($customer_result);
}

// Get request details if selected
$selected_request = null;
if ($request_id > 0 && $mode == 'request') {
    $request_query = "SELECT r.*, l.receipt_number, l.interest_amount, l.customer_id,
                             c.customer_name, c.mobile_number, c.email
                      FROM personal_loan_requests r
                      JOIN loans l ON r.loan_id = l.id
                      JOIN customers c ON r.customer_id = c.id
                      WHERE r.id = ? AND r.status = 'approved'";
    $request_stmt = mysqli_prepare($conn, $request_query);
    mysqli_stmt_bind_param($request_stmt, 'i', $request_id);
    mysqli_stmt_execute($request_stmt);
    $request_result = mysqli_stmt_get_result($request_stmt);
    $selected_request = mysqli_fetch_assoc($request_result);
    
    if ($selected_request) {
        $customer_id = $selected_request['customer_id'];
        // Get full customer details
        $customer_query = "SELECT * FROM customers WHERE id = ?";
        $customer_stmt = mysqli_prepare($conn, $customer_query);
        mysqli_stmt_bind_param($customer_stmt, 'i', $customer_id);
        mysqli_stmt_execute($customer_stmt);
        $customer_result = mysqli_stmt_get_result($customer_stmt);
        $selected_customer = mysqli_fetch_assoc($customer_result);
    }
}

// Generate receipt number
$today = date('Y-m-d');
$receipt_query = "SELECT COUNT(*) as count FROM personal_loans WHERE DATE(created_at) = CURDATE()";
$receipt_result = mysqli_query($conn, $receipt_query);
$receipt_count = 1;
if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
    $receipt_data = mysqli_fetch_assoc($receipt_result);
    $receipt_count = $receipt_data['count'] + 1;
}
$receipt_number = 'PL' . date('ymd') . str_pad($receipt_count, 3, '0', STR_PAD_LEFT);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $receipt_date = mysqli_real_escape_string($conn, $_POST['receipt_date'] ?? date('Y-m-d'));
    $loan_amount = floatval($_POST['loan_amount'] ?? 0);
    $interest_rate = floatval($_POST['interest_rate'] ?? 0);
    $interest_type = mysqli_real_escape_string($conn, $_POST['interest_type'] ?? 'monthly');
    $tenure_months = intval($_POST['tenure_months'] ?? 12);
    $emi_amount = floatval($_POST['emi_amount'] ?? 0);
    $process_charge = floatval($_POST['process_charge'] ?? 0);
    $appraisal_charge = floatval($_POST['appraisal_charge'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $creation_mode = mysqli_real_escape_string($conn, $_POST['creation_mode'] ?? 'direct');
    
    // Payment method fields
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
    $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
    $transaction_ref = mysqli_real_escape_string($conn, $_POST['transaction_ref'] ?? '');
    $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
    $cheque_number = mysqli_real_escape_string($conn, $_POST['cheque_number'] ?? '');
    
    // For request mode, get request_id
    $selected_request_id = 0;
    if ($creation_mode == 'request') {
        $selected_request_id = intval($_POST['request_id'] ?? 0);
    }
    
    // Validate required fields
    $errors = [];
    if ($customer_id <= 0) $errors[] = "Please select a customer";
    if ($loan_amount <= 0) $errors[] = "Loan amount must be greater than 0";
    if ($interest_rate <= 0) $errors[] = "Interest rate must be greater than 0";
    if ($employee_id <= 0) $errors[] = "Please select an employee";
    
    // Validate payment method specific fields
    if ($payment_method === 'bank') {
        if ($bank_id <= 0) $errors[] = "Please select a bank";
        if ($bank_account_id <= 0) $errors[] = "Please select a bank account";
        
        // Check bank balance if account selected
        if ($bank_account_id > 0) {
            $balance_query = "SELECT COALESCE((
                                SELECT balance FROM bank_ledger 
                                WHERE bank_account_id = ? 
                                ORDER BY id DESC LIMIT 1
                              ), opening_balance, 0) as current_balance 
                              FROM bank_accounts WHERE id = ?";
            
            $balance_stmt = mysqli_prepare($conn, $balance_query);
            mysqli_stmt_bind_param($balance_stmt, 'ii', $bank_account_id, $bank_account_id);
            mysqli_stmt_execute($balance_stmt);
            $balance_result = mysqli_stmt_get_result($balance_stmt);
            $balance_row = mysqli_fetch_assoc($balance_result);
            $current_balance = $balance_row['current_balance'] ?? 0;
            
            $total_amount = $loan_amount + $process_charge + $appraisal_charge;
            
            if ($total_amount > $current_balance) {
                $errors[] = "Insufficient bank balance! Available: ₹" . number_format($current_balance, 2) . 
                            ", Required: ₹" . number_format($total_amount, 2);
            }
        }
    } elseif ($payment_method === 'upi') {
        if (empty($upi_id)) $errors[] = "Please enter UPI ID/Transaction ID";
    } elseif ($payment_method === 'cheque') {
        if (empty($cheque_number)) $errors[] = "Please enter cheque number";
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Check if personal_loans table exists
            $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'personal_loans'");
            if (mysqli_num_rows($check_table) == 0) {
                $create_table = "CREATE TABLE personal_loans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    personal_loan_request_id INT NULL,
                    original_loan_id INT NULL,
                    customer_id INT NOT NULL,
                    receipt_number VARCHAR(20) NOT NULL,
                    receipt_date DATE NOT NULL,
                    loan_amount DECIMAL(15,2) NOT NULL,
                    interest_rate DECIMAL(5,2) NOT NULL,
                    interest_type VARCHAR(50) DEFAULT 'monthly',
                    tenure_months INT DEFAULT 12,
                    emi_amount DECIMAL(15,2) DEFAULT 0,
                    process_charge DECIMAL(10,2) DEFAULT 0,
                    appraisal_charge DECIMAL(10,2) DEFAULT 0,
                    total_payable DECIMAL(15,2) NOT NULL,
                    payment_method VARCHAR(50) DEFAULT 'cash',
                    bank_id INT NULL,
                    bank_account_id INT NULL,
                    transaction_ref VARCHAR(100),
                    cheque_number VARCHAR(50),
                    upi_id VARCHAR(100),
                    employee_id INT NOT NULL,
                    status ENUM('active','closed','defaulted') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY customer_id (customer_id)
                )";
                mysqli_query($conn, $create_table);
            }
            
            // Insert personal loan with payment details
            $insert_query = "INSERT INTO personal_loans (
                personal_loan_request_id, customer_id, receipt_number, 
                receipt_date, loan_amount, interest_rate, interest_type, tenure_months, 
                emi_amount, process_charge, appraisal_charge, total_payable,
                payment_method, bank_id, bank_account_id, transaction_ref, cheque_number, upi_id,
                employee_id, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
            
            $total_payable = $loan_amount + $process_charge + $appraisal_charge;
            
            $stmt = mysqli_prepare($conn, $insert_query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($conn));
            }
            
            // Handle NULL for request_id in direct mode
            $request_id_for_db = ($creation_mode == 'request' && $selected_request_id > 0) ? $selected_request_id : null;
            
            mysqli_stmt_bind_param($stmt, 'iisssddsidddisiisssi', 
                $request_id_for_db,
                $customer_id,
                $receipt_number,
                $receipt_date,
                $loan_amount,
                $interest_rate,
                $interest_type,
                $tenure_months,
                $emi_amount,
                $process_charge,
                $appraisal_charge,
                $total_payable,
                $payment_method,
                ($bank_id > 0 ? $bank_id : null),
                ($bank_account_id > 0 ? $bank_account_id : null),
                $transaction_ref,
                $cheque_number,
                $upi_id,
                $employee_id
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error inserting personal loan: " . mysqli_stmt_error($stmt));
            }
            
            $personal_loan_id = mysqli_insert_id($conn);
            
            // If payment method is bank, update bank ledger
            if ($payment_method === 'bank' && $bank_account_id > 0) {
                // Get current balance
                $balance_query = "SELECT COALESCE((
                                    SELECT balance FROM bank_ledger 
                                    WHERE bank_account_id = ? 
                                    ORDER BY id DESC LIMIT 1
                                  ), opening_balance, 0) as current_balance 
                                  FROM bank_accounts WHERE id = ?";
                
                $balance_stmt = mysqli_prepare($conn, $balance_query);
                mysqli_stmt_bind_param($balance_stmt, 'ii', $bank_account_id, $bank_account_id);
                mysqli_stmt_execute($balance_stmt);
                $balance_result = mysqli_stmt_get_result($balance_stmt);
                $balance_row = mysqli_fetch_assoc($balance_result);
                $current_balance = $balance_row['current_balance'] ?? 0;
                
                $new_balance = $current_balance - $total_payable;
                
                // Insert bank ledger entry
                $ledger_query = "INSERT INTO bank_ledger (
                    entry_date, bank_id, bank_account_id, transaction_type, 
                    amount, balance, reference_number, description, 
                    loan_id, created_by, created_at
                ) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?, NOW())";
                
                $ledger_stmt = mysqli_prepare($conn, $ledger_query);
                $description = "Personal loan disbursement - Receipt #: " . $receipt_number;
                
                mysqli_stmt_bind_param($ledger_stmt, 'siidsssii', 
                    $receipt_date,
                    $bank_id,
                    $bank_account_id,
                    $total_payable,
                    $new_balance,
                    $transaction_ref,
                    $description,
                    $personal_loan_id,
                    $_SESSION['user_id']
                );
                
                if (!mysqli_stmt_execute($ledger_stmt)) {
                    throw new Exception("Error updating bank ledger: " . mysqli_stmt_error($ledger_stmt));
                }
            }
            
            // If this was from a request, update the request status
            if ($creation_mode == 'request' && $selected_request_id > 0) {
                $update_request = "UPDATE personal_loan_requests SET status = 'completed' WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_request);
                mysqli_stmt_bind_param($update_stmt, 'i', $selected_request_id);
                mysqli_stmt_execute($update_stmt);
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'create', ?, 'personal_loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Personal loan created: " . $receipt_number . " for ₹" . number_format($loan_amount, 2) . " via " . $payment_method;
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $personal_loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            // Send email if customer has email
            if (!empty($selected_customer['email']) && function_exists('sendPersonalLoanCreationEmail')) {
                sendPersonalLoanCreationEmail($personal_loan_id, $conn);
            }
            
            // Redirect to view page
            header('Location: view-personal-loan.php?id=' . $personal_loan_id . '&success=created');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error creating personal loan: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
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

        .container {
            max-width: 1200px;
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        /* Mode Tabs */
        .mode-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            padding: 10px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .mode-tab {
            flex: 1;
            padding: 15px;
            text-align: center;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #718096;
            border: 2px solid transparent;
        }

        .mode-tab:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        .mode-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .mode-tab i {
            margin-right: 8px;
        }

        .mode-tab small {
            display: block;
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.8;
        }

        /* Cards */
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
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

        /* Payment Method Tabs */
        .payment-tabs {
            display: flex;
            gap: 10px;
            margin: 20px 0;
        }

        .payment-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .payment-tab:hover {
            border-color: #667eea;
        }

        .payment-tab.active {
            border-color: transparent;
        }

        .payment-tab.cash.active {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .payment-tab.bank.active {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .payment-tab.upi.active {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }

        .payment-tab.cheque.active {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        /* Bank Details */
        .bank-details {
            background: #f0f4ff;
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            display: none;
        }

        .bank-details.show {
            display: block;
        }

        /* Bank Balance Display */
        .bank-balance {
            background: linear-gradient(135deg, #48bb7810 0%, #4299e110 100%);
            border: 1px solid #48bb78;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 5px;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .balance-amount {
            font-weight: 700;
            color: #48bb78;
            font-size: 16px;
        }

        .balance-label {
            color: #4a5568;
            font-weight: 600;
        }

        .balance-warning {
            background: linear-gradient(135deg, #f5656510 0%, #c5303010 100%);
            border-color: #f56565;
        }

        .balance-warning .balance-amount {
            color: #f56565;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
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

        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
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

        .readonly-field {
            background: #f1f5f9;
            cursor: not-allowed;
        }

        /* Customer Card */
        .customer-card {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .customer-photo {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid #667eea;
        }

        .customer-photo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 600;
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

        .customer-contact {
            display: flex;
            gap: 20px;
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .customer-address {
            color: #718096;
            font-size: 13px;
        }

        /* Request Card */
        .request-card {
            background: #ebf4ff;
            border: 2px solid #4299e1;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .request-title {
            color: #2c5282;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #cbd5e0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #4a5568;
            font-weight: 500;
        }

        .info-value {
            font-weight: 600;
            color: #2d3748;
        }

        .amount-highlight {
            color: #48bb78;
            font-size: 18px;
            font-weight: 700;
        }

        /* Summary Box */
        .summary-box {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-total {
            font-size: 18px;
            font-weight: 700;
            color: #48bb78;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #48bb78;
            display: flex;
            justify-content: space-between;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left-color: #48bb78;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left-color: #f56565;
        }

        .alert-info {
            background: #bee3f8;
            color: #2c5282;
            border-left-color: #4299e1;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        @media (max-width: 768px) {
            .form-grid, .form-grid-3 {
                grid-template-columns: 1fr;
            }
            
            .customer-card {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-contact {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .mode-tabs {
                flex-direction: column;
            }
            
            .payment-tabs {
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
                            <i class="bi bi-plus-circle"></i>
                            Create Personal Loan
                        </h1>
                        <div>
                            <a href="personal-loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> View All Loans
                            </a>
                        </div>
                    </div>

                    <!-- Mode Selection Tabs -->
                    <div class="mode-tabs">
                        <a href="?mode=direct" class="mode-tab <?php echo $mode == 'direct' ? 'active' : ''; ?>">
                            <i class="bi bi-person-plus"></i> Direct Loan (No Request)
                            <small>Create loan directly for any customer</small>
                        </a>
                        <a href="?mode=request" class="mode-tab <?php echo $mode == 'request' ? 'active' : ''; ?>">
                            <i class="bi bi-check-circle"></i> From Approved Request
                            <small>Create loan from an approved request</small>
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i> <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Main Form -->
                    <form method="POST" action="" id="loanForm">
                        <input type="hidden" name="creation_mode" value="<?php echo $mode; ?>">
                        
                        <!-- Customer Selection Card -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-person"></i>
                                Select Customer
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Customer</label>
                                    <select class="form-select" name="customer_id" id="customerSelect" required onchange="window.location.href='?mode=<?php echo $mode; ?>&customer_id='+this.value">
                                        <option value="">-- Select Customer --</option>
                                        <?php
                                        mysqli_data_seek($customers_result, 0);
                                        while ($customer = mysqli_fetch_assoc($customers_result)) {
                                            $selected = ($customer['id'] == $customer_id) ? 'selected' : '';
                                            echo '<option value="' . $customer['id'] . '" ' . $selected . '>' . 
                                                 htmlspecialchars($customer['customer_name']) . ' (' . $customer['mobile_number'] . ')</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <?php if ($mode == 'request'): ?>
                                <div class="form-group">
                                    <label class="form-label">Approved Request (Optional)</label>
                                    <select class="form-select" name="request_id" id="requestSelect" onchange="window.location.href='?mode=request&request_id='+this.value">
                                        <option value="">-- Direct Loan (No Request) --</option>
                                        <?php foreach ($approved_requests as $req): 
                                            if ($customer_id == 0 || $req['customer_id'] == $customer_id) {
                                                $selected = ($req['id'] == $request_id) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo $req['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo $req['receipt_number']; ?> - ₹<?php echo number_format($req['requested_amount'], 2); ?> - <?php echo $req['customer_name']; ?>
                                        </option>
                                        <?php 
                                            }
                                        endforeach; ?>
                                    </select>
                                    <small class="form-text text-muted">
                                        <i class="bi bi-info-circle"></i> Select a request to auto-fill details, or leave empty for direct loan
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Customer Details Card -->
                            <?php if ($selected_customer): ?>
                            <div class="customer-card">
                                <?php if (!empty($selected_customer['customer_photo']) && file_exists($selected_customer['customer_photo'])): ?>
                                    <img src="<?php echo $selected_customer['customer_photo']; ?>" class="customer-photo">
                                <?php else: ?>
                                    <div class="customer-photo-placeholder">
                                        <?php echo strtoupper(substr($selected_customer['customer_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="customer-details">
                                    <div class="customer-name"><?php echo htmlspecialchars($selected_customer['customer_name']); ?></div>
                                    <div class="customer-contact">
                                        <span><i class="bi bi-phone"></i> <?php echo $selected_customer['mobile_number'] ?? 'N/A'; ?></span>
                                        <span><i class="bi bi-envelope"></i> <?php echo $selected_customer['email'] ?? 'N/A'; ?></span>
                                    </div>
                                    <div class="customer-address">
                                        <i class="bi bi-geo-alt"></i> 
                                        <?php 
                                        $address = array_filter([
                                            $selected_customer['door_no'],
                                            $selected_customer['house_name'],
                                            $selected_customer['street_name'],
                                            $selected_customer['location'],
                                            $selected_customer['district']
                                        ]);
                                        echo !empty($address) ? implode(', ', $address) : 'Address not available';
                                        if (!empty($selected_customer['pincode'])) {
                                            echo ' - ' . $selected_customer['pincode'];
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Selected Request Details -->
                            <?php if ($selected_request): ?>
                            <div class="request-card">
                                <div class="request-title">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Approved Request Details
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Request ID:</span>
                                    <span class="info-value">#<?php echo $selected_request['id']; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Original Receipt:</span>
                                    <span class="info-value"><?php echo $selected_request['receipt_number']; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Requested Amount:</span>
                                    <span class="info-value amount-highlight">₹<?php echo number_format($selected_request['requested_amount'], 2); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Approved Date:</span>
                                    <span class="info-value"><?php echo date('d-m-Y', strtotime($selected_request['approved_date'])); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Loan Information Card -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-info-circle"></i>
                                Loan Information
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label required">Receipt Date</label>
                                    <input type="date" class="form-control" name="receipt_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Receipt Number</label>
                                    <input type="text" class="form-control readonly-field" value="<?php echo $receipt_number; ?>" readonly>
                                    <input type="hidden" name="receipt_number" value="<?php echo $receipt_number; ?>">
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
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label required">Loan Amount (₹)</label>
                                    <input type="number" class="form-control" name="loan_amount" id="loan_amount" 
                                           value="<?php echo $selected_request['requested_amount'] ?? 0; ?>" 
                                           step="0.01" min="0" required oninput="calculateEMI(); validateBankBalance()">
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Interest Rate (%)</label>
                                    <input type="number" class="form-control" name="interest_rate" id="interest_rate" 
                                           value="<?php echo $selected_request['interest_amount'] ?? 2; ?>" 
                                           step="0.01" min="0" required oninput="calculateEMI()">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Interest Type</label>
                                    <select class="form-select" name="interest_type" id="interest_type" onchange="calculateEMI()">
                                        <option value="monthly" selected>Monthly</option>
                                        <option value="daily">Daily</option>
                                        <option value="quarterly">Quarterly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label">Tenure (Months)</label>
                                    <input type="number" class="form-control" name="tenure_months" id="tenure_months" 
                                           value="12" min="1" max="60" oninput="calculateEMI()">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">EMI Amount (₹)</label>
                                    <input type="number" class="form-control readonly-field" name="emi_amount" id="emi_amount" 
                                           value="0" step="0.01" readonly>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Process Charge (₹)</label>
                                    <input type="number" class="form-control" name="process_charge" id="process_charge" 
                                           value="200" step="0.01" min="0" oninput="calculateTotal(); validateBankBalance()">
                                </div>
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label">Appraisal Charge (₹)</label>
                                    <input type="number" class="form-control" name="appraisal_charge" id="appraisal_charge" 
                                           value="100" step="0.01" min="0" oninput="calculateTotal(); validateBankBalance()">
                                </div>
                            </div>

                            <!-- Payment Method Tabs -->
                            <div class="section-title" style="margin-top: 20px;">
                                <i class="bi bi-credit-card"></i>
                                Payment Method
                            </div>

                            <div class="payment-tabs">
                                <div class="payment-tab cash active" onclick="setPaymentMethod('cash')">
                                    <i class="bi bi-cash"></i> Cash
                                </div>
                                <div class="payment-tab bank" onclick="setPaymentMethod('bank')">
                                    <i class="bi bi-bank"></i> Bank Transfer
                                </div>
                                <div class="payment-tab upi" onclick="setPaymentMethod('upi')">
                                    <i class="bi bi-phone"></i> UPI
                                </div>
                                <div class="payment-tab cheque" onclick="setPaymentMethod('cheque')">
                                    <i class="bi bi-file-text"></i> Cheque
                                </div>
                            </div>
                            <input type="hidden" name="payment_method" id="payment_method" value="cash">

                            <!-- Bank Details Section -->
                            <div class="bank-details" id="bankDetails">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Select Bank</label>
                                        <select class="form-select" name="bank_id" id="bank_id" onchange="loadBankAccounts()">
                                            <option value="">Select Bank</option>
                                            <?php 
                                            if ($banks_result && mysqli_num_rows($banks_result) > 0) {
                                                mysqli_data_seek($banks_result, 0);
                                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $bank['total_balance']; ?>">
                                                    <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                                </option>
                                            <?php 
                                                endwhile; 
                                            }
                                            ?>
                                        </select>
                                        <div id="bankBalanceDisplay" class="bank-balance" style="display: none;">
                                            <span class="balance-label">Total Bank Balance:</span>
                                            <span class="balance-amount" id="bankBalanceAmount">₹ 0.00</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Select Account</label>
                                        <select class="form-select" name="bank_account_id" id="bank_account_id" onchange="showAccountBalance()">
                                            <option value="">Select Account</option>
                                            <?php 
                                            if ($bank_accounts_result && mysqli_num_rows($bank_accounts_result) > 0) {
                                                mysqli_data_seek($bank_accounts_result, 0);
                                                while($account = mysqli_fetch_assoc($bank_accounts_result)): 
                                            ?>
                                                <option value="<?php echo $account['id']; ?>" 
                                                        data-bank-id="<?php echo $account['bank_id']; ?>"
                                                        data-balance="<?php echo $account['current_balance']; ?>"
                                                        data-account="<?php echo htmlspecialchars($account['account_holder_no']); ?>">
                                                    <?php echo htmlspecialchars($account['account_holder_no'] . ' - ' . $account['bank_account_no']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                        <div id="accountBalanceDisplay" class="bank-balance" style="display: none;">
                                            <span class="balance-label">Available Balance:</span>
                                            <span class="balance-amount" id="accountBalanceAmount">₹ 0.00</span>
                                        </div>
                                        <div id="balanceWarning" class="bank-balance balance-warning" style="display: none;">
                                            <span class="balance-label">⚠️ Insufficient Balance!</span>
                                            <span class="balance-amount">Cannot process loan</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Transaction Reference</label>
                                        <input type="text" class="form-control" name="transaction_ref" placeholder="Enter transaction reference/ID">
                                    </div>
                                </div>
                            </div>

                            <!-- UPI Details (hidden by default) -->
                            <div id="upiDetails" style="display: none;" class="bank-details">
                                <div class="form-group">
                                    <label class="form-label">UPI ID / Transaction ID</label>
                                    <input type="text" class="form-control" name="upi_id" placeholder="Enter UPI transaction ID">
                                </div>
                            </div>

                            <!-- Cheque Details (hidden by default) -->
                            <div id="chequeDetails" style="display: none;" class="bank-details">
                                <div class="form-group">
                                    <label class="form-label">Cheque Number</label>
                                    <input type="text" class="form-control" name="cheque_number" placeholder="Enter cheque number">
                                </div>
                            </div>

                            <!-- Summary -->
                            <div class="summary-box">
                                <h4 style="margin-bottom: 15px;">Loan Summary</h4>
                                <div class="summary-row">
                                    <span>Principal Amount:</span>
                                    <span id="summary_principal">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Process Charge:</span>
                                    <span id="summary_process">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Appraisal Charge:</span>
                                    <span id="summary_appraisal">₹0.00</span>
                                </div>
                                <div class="summary-total">
                                    <span>Total Payable:</span>
                                    <span id="summary_total">₹0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle"></i> Create Personal Loan
                            </button>
                            <a href="personal-loans.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        let currentBankBalance = 0;

        function calculateEMI() {
            const principal = parseFloat(document.getElementById('loan_amount').value) || 0;
            const rate = parseFloat(document.getElementById('interest_rate').value) || 0;
            const tenure = parseInt(document.getElementById('tenure_months').value) || 12;
            const interestType = document.getElementById('interest_type').value;
            
            let monthlyRate = rate / 12 / 100;
            
            if (interestType === 'daily') {
                monthlyRate = (rate * 30) / 12 / 100;
            } else if (interestType === 'quarterly') {
                monthlyRate = rate / 4 / 100;
            } else if (interestType === 'yearly') {
                monthlyRate = rate / 12 / 100;
            }
            
            if (principal > 0 && monthlyRate > 0 && tenure > 0) {
                const emi = principal * monthlyRate * Math.pow(1 + monthlyRate, tenure) / (Math.pow(1 + monthlyRate, tenure) - 1);
                document.getElementById('emi_amount').value = emi.toFixed(2);
            } else if (principal > 0 && tenure > 0) {
                document.getElementById('emi_amount').value = (principal / tenure).toFixed(2);
            } else {
                document.getElementById('emi_amount').value = '0.00';
            }
            
            calculateTotal();
        }

        function calculateTotal() {
            const principal = parseFloat(document.getElementById('loan_amount').value) || 0;
            const processCharge = parseFloat(document.getElementById('process_charge').value) || 0;
            const appraisalCharge = parseFloat(document.getElementById('appraisal_charge').value) || 0;
            
            const total = principal + processCharge + appraisalCharge;
            
            document.getElementById('summary_principal').innerHTML = '₹' + principal.toFixed(2);
            document.getElementById('summary_process').innerHTML = '₹' + processCharge.toFixed(2);
            document.getElementById('summary_appraisal').innerHTML = '₹' + appraisalCharge.toFixed(2);
            document.getElementById('summary_total').innerHTML = '₹' + total.toFixed(2);
        }

        function setPaymentMethod(method) {
            document.getElementById('payment_method').value = method;
            
            // Update active state
            document.querySelectorAll('.payment-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('.payment-tab.' + method).classList.add('active');
            
            // Hide all payment details sections
            document.getElementById('bankDetails').classList.remove('show');
            document.getElementById('upiDetails').style.display = 'none';
            document.getElementById('chequeDetails').style.display = 'none';
            
            // Show relevant payment details
            if (method === 'bank') {
                document.getElementById('bankDetails').classList.add('show');
            } else if (method === 'upi') {
                document.getElementById('upiDetails').style.display = 'block';
            } else if (method === 'cheque') {
                document.getElementById('chequeDetails').style.display = 'block';
            }
        }

        function loadBankAccounts() {
            const bankId = document.getElementById('bank_id').value;
            const bankOption = document.querySelector(`#bank_id option[value="${bankId}"]`);
            
            if (bankOption) {
                const balance = bankOption.getAttribute('data-balance') || 0;
                document.getElementById('bankBalanceAmount').textContent = '₹ ' + parseFloat(balance).toLocaleString(undefined, {minimumFractionDigits: 2});
                document.getElementById('bankBalanceDisplay').style.display = 'flex';
            } else {
                document.getElementById('bankBalanceDisplay').style.display = 'none';
            }
            
            // Filter accounts by bank
            const accountSelect = document.getElementById('bank_account_id');
            Array.from(accountSelect.options).forEach(option => {
                if (option.value === '') return;
                const optionBankId = option.getAttribute('data-bank-id');
                if (bankId === '' || optionBankId === bankId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
            
            // Hide account balance if no account selected
            document.getElementById('accountBalanceDisplay').style.display = 'none';
            document.getElementById('balanceWarning').style.display = 'none';
            currentBankBalance = 0;
        }

        function showAccountBalance() {
            const accountSelect = document.getElementById('bank_account_id');
            const selectedOption = accountSelect.options[accountSelect.selectedIndex];
            
            if (selectedOption && selectedOption.value !== '') {
                const balance = parseFloat(selectedOption.getAttribute('data-balance')) || 0;
                currentBankBalance = balance;
                
                document.getElementById('accountBalanceAmount').textContent = '₹ ' + balance.toLocaleString(undefined, {minimumFractionDigits: 2});
                document.getElementById('accountBalanceDisplay').style.display = 'flex';
                
                // Validate against loan amount
                validateBankBalance();
            } else {
                document.getElementById('accountBalanceDisplay').style.display = 'none';
                document.getElementById('balanceWarning').style.display = 'none';
                currentBankBalance = 0;
            }
        }

        function validateBankBalance() {
            const paymentMethod = document.getElementById('payment_method').value;
            const principal = parseFloat(document.getElementById('loan_amount').value) || 0;
            const processCharge = parseFloat(document.getElementById('process_charge').value) || 0;
            const appraisalCharge = parseFloat(document.getElementById('appraisal_charge').value) || 0;
            const totalAmount = principal + processCharge + appraisalCharge;
            
            const balanceWarning = document.getElementById('balanceWarning');
            const submitBtn = document.getElementById('submitBtn');
            
            if (paymentMethod === 'bank' && currentBankBalance > 0) {
                if (totalAmount > currentBankBalance) {
                    balanceWarning.style.display = 'flex';
                    submitBtn.disabled = true;
                    submitBtn.title = 'Insufficient bank balance';
                } else {
                    balanceWarning.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.title = '';
                }
            } else {
                balanceWarning.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.title = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            calculateEMI();
            calculateTotal();
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>