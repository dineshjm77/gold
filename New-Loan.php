<?php
session_start();
$currentPage = 'new-loan';
$pageTitle = 'New Loan';
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

$message = '';
$error = '';

// Get product types for dropdown
$product_types_query = "SELECT id, product_type, auction_type, print_color FROM product_types WHERE status = 1 ORDER BY product_type";
$product_types_result = mysqli_query($conn, $product_types_query);
if (!$product_types_result) {
    $error = "Error loading product types: " . mysqli_error($conn);
}

// Get interest types for dropdown
$interest_types_query = "SELECT id, interest_type, rate, daily_monthly, fixed_dynamic, description 
                         FROM interest_settings WHERE is_active = 1 ORDER BY interest_type";
$interest_types_result = mysqli_query($conn, $interest_types_query);
if (!$interest_types_result) {
    $error = "Error loading interest types: " . mysqli_error($conn);
}

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale') ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);
if (!$employees_result) {
    $error = "Error loading employees: " . mysqli_error($conn);
}

// Get karat details for dropdown
$karat_query = "SELECT id, karat, max_value_per_gram, loan_value_per_gram FROM karat_details WHERE status = 1 ORDER BY karat";
$karat_result = mysqli_query($conn, $karat_query);
if (!$karat_result) {
    $error = "Error loading karat details: " . mysqli_error($conn);
}

// Get defect details for dropdown
$defect_query = "SELECT id, defect_name FROM defect_details ORDER BY defect_name";
$defect_result = mysqli_query($conn, $defect_query);
if (!$defect_result) {
    $error = "Error loading defect details: " . mysqli_error($conn);
}

// Get stone details for dropdown
$stone_query = "SELECT id, stone_name FROM stone_details ORDER BY stone_name";
$stone_result = mysqli_query($conn, $stone_query);
if (!$stone_result) {
    $error = "Error loading stone details: " . mysqli_error($conn);
}

// Get jewel names for dropdown
$jewel_names_query = "SELECT id, product_type, jewel_name FROM product_names WHERE status = 1 ORDER BY jewel_name";
$jewel_names_result = mysqli_query($conn, $jewel_names_query);
if (!$jewel_names_result) {
    $error = "Error loading jewel names: " . mysqli_error($conn);
}

// Get banks for payment method with current balance
$banks_query = "SELECT bm.id, bm.bank_full_name, 
                COALESCE((SELECT SUM(opening_balance) FROM bank_accounts WHERE bank_id = bm.id AND status = 1), 0) as total_balance
                FROM bank_master bm 
                WHERE bm.status = 1 
                ORDER BY bm.bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get bank accounts for payment method with current balance
$bank_accounts_query = "SELECT ba.*, bm.bank_full_name, bm.bank_short_name,
                        COALESCE((SELECT balance FROM bank_ledger WHERE bank_account_id = ba.id ORDER BY id DESC LIMIT 1), ba.opening_balance) as current_balance
                        FROM bank_accounts ba 
                        LEFT JOIN bank_master bm ON ba.bank_id = bm.id 
                        WHERE ba.status = 1 
                        ORDER BY bm.bank_full_name, ba.account_holder_no";
$bank_accounts_result = mysqli_query($conn, $bank_accounts_query);

// Get product value settings for all product types
$product_value_query = "SELECT * FROM product_value_settings WHERE status = 1";
$product_value_result = mysqli_query($conn, $product_value_query);
$product_values = [];
while ($row = mysqli_fetch_assoc($product_value_result)) {
    $product_values[$row['product_type']] = $row;
}

// Generate receipt number
$today = date('Y-m-d');
$receipt_query = "SELECT COUNT(*) as count FROM loans WHERE DATE(created_at) = CURDATE()";
$receipt_result = mysqli_query($conn, $receipt_query);
$receipt_count = 1;
if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
    $receipt_data = mysqli_fetch_assoc($receipt_result);
    $receipt_count = $receipt_data['count'] + 1;
}
$receipt_number = date('ymd') . str_pad($receipt_count, 3, '0', STR_PAD_LEFT);

// Check if customer ID is passed from new customer page
$preselected_customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$preselected_customer_data = null;

if ($preselected_customer_id > 0) {
    $customer_query = "SELECT * FROM customers WHERE id = ?";
    $customer_stmt = mysqli_prepare($conn, $customer_query);
    if ($customer_stmt) {
        mysqli_stmt_bind_param($customer_stmt, 'i', $preselected_customer_id);
        mysqli_stmt_execute($customer_stmt);
        $customer_result = mysqli_stmt_get_result($customer_stmt);
        $preselected_customer_data = mysqli_fetch_assoc($customer_result);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $receipt_date_display = $_POST['receipt_date'] ?? date('d/m/Y');
    
    // Convert from dd/mm/yyyy to yyyy-mm-dd for database
    $date_parts = explode('/', $receipt_date_display);
    if (count($date_parts) == 3) {
        $receipt_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
    } else {
        $receipt_date = date('Y-m-d');
    }
    
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $product_type = mysqli_real_escape_string($conn, $_POST['product_type'] ?? '');
    $loan_amount = floatval($_POST['loan_amount'] ?? 0);
    $interest_type = mysqli_real_escape_string($conn, $_POST['interest_type'] ?? '');
    $interest_rate = floatval($_POST['interest_rate'] ?? 0);
    $process_charge = floatval($_POST['process_charge'] ?? 0);
    $appraisal_charge = floatval($_POST['appraisal_charge'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $product_value = floatval($_POST['product_value'] ?? 0);
    $max_value = floatval($_POST['max_value'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $interest_calculation = mysqli_real_escape_string($conn, $_POST['interest_calculation'] ?? 'daily');
    
    // Bank payment details
    $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
    $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
    $transaction_ref = mysqli_real_escape_string($conn, $_POST['transaction_ref'] ?? '');
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date'] ?? date('Y-m-d'));
    
    // Get total weights from hidden fields
    $gross_weight = floatval($_POST['gross_weight'] ?? 0);
    $net_weight = floatval($_POST['net_weight'] ?? 0);
    
    // Validate required fields
    $errors = [];
    if (empty($customer_id)) $errors[] = "Please select a customer";
    if (empty($product_type)) $errors[] = "Please select product type";
    if (empty($interest_type)) $errors[] = "Please select interest type";
    if (empty($employee_id)) $errors[] = "Please select employee";
    if ($gross_weight <= 0) $errors[] = "Gross weight must be greater than 0";
    if ($net_weight <= 0) $errors[] = "Net weight must be greater than 0";
    if ($loan_amount <= 0) $errors[] = "Loan amount must be greater than 0";
    
    // Check loan limit for customer
    if ($customer_id > 0) {
        $limit_check_query = "SELECT c.loan_limit_amount, 
                              COALESCE(SUM(l.loan_amount), 0) as total_loans_taken
                              FROM customers c
                              LEFT JOIN loans l ON c.id = l.customer_id AND l.status = 'open'
                              WHERE c.id = ?
                              GROUP BY c.id";
        
        $limit_stmt = mysqli_prepare($conn, $limit_check_query);
        if ($limit_stmt) {
            mysqli_stmt_bind_param($limit_stmt, 'i', $customer_id);
            mysqli_stmt_execute($limit_stmt);
            $limit_result = mysqli_stmt_get_result($limit_stmt);
            $limit_data = mysqli_fetch_assoc($limit_result);
            
            if ($limit_data) {
                $remaining_limit = $limit_data['loan_limit_amount'] - $limit_data['total_loans_taken'];
                if ($loan_amount > $remaining_limit) {
                    $errors[] = "Customer loan amount ₹" . number_format($loan_amount, 2) . " exceeds remaining limit of ₹" . number_format($remaining_limit, 2);
                }
            }
        }
    }
    
    // If payment method is bank, check if bank account has sufficient balance
    if ($payment_method === 'bank' && $bank_account_id > 0 && $loan_amount > 0) {
        // Get current bank account balance
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
        
        if ($loan_amount > $current_balance) {
            $errors[] = "Insufficient bank balance! Available: ₹" . number_format($current_balance, 2) . 
                        ", Required: ₹" . number_format($loan_amount, 2);
        }
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert loan with process_charge and appraisal_charge
            // Insert loan with process_charge and appraisal_charge
$insert_loan = "INSERT INTO loans (
    receipt_number, receipt_date, customer_id, gross_weight, net_weight, 
    product_value, loan_amount, remaining_principal, 
    process_charge, appraisal_charge,
    interest_type, interest_amount, 
    employee_id, status, created_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())";

$remaining_principal = $loan_amount; // Initially, remaining principal equals loan amount

$stmt = mysqli_prepare($conn, $insert_loan);
if ($stmt) {
    // Count the parameters: 13 placeholders (?) and 13 variables
    mysqli_stmt_bind_param(
        $stmt,
        'ssiddddddddsi', // 13 characters: s,s,i,d,d,d,d,d,d,d,d,s,i
        $receipt_number,
        $receipt_date,
        $customer_id,
        $gross_weight,
        $net_weight,
        $product_value,
        $loan_amount,
        $remaining_principal,
        $process_charge,
        $appraisal_charge,
        $interest_type,
        $interest_rate,
        $employee_id
    );
}

            if (!$stmt) {
                throw new Exception("Error preparing loan statement: " . mysqli_error($conn));
            }

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error executing loan statement: " . mysqli_stmt_error($stmt));
            }

            $loan_id = mysqli_insert_id($conn);
            
            // Insert loan items
            if (isset($_POST['jewel_name']) && is_array($_POST['jewel_name'])) {
                for ($i = 0; $i < count($_POST['jewel_name']); $i++) {
                    if (!empty($_POST['jewel_name'][$i])) {
                        $jewel_name = mysqli_real_escape_string($conn, $_POST['jewel_name'][$i]);
                        $karat = floatval($_POST['karat'][$i] ?? 0);
                        $defect = mysqli_real_escape_string($conn, $_POST['defect'][$i] ?? '');
                        $stone = mysqli_real_escape_string($conn, $_POST['stone'][$i] ?? '');
                        $item_gross_weight = floatval($_POST['item_gross_weight'][$i] ?? 0);
                        $item_net_weight = floatval($_POST['item_net_weight'][$i] ?? 0);
                        $quantity = intval($_POST['quantity'][$i] ?? 1);
                        
                        // Handle jewel photo
                        $jewel_photo = null;
                        
                        // Check for camera capture
                        $camera_field = 'jewel_photo_camera_' . $i;
                        if (isset($_POST[$camera_field]) && !empty($_POST[$camera_field])) {
                            $jewel_photo = saveBase64Image($_POST[$camera_field], "uploads/loan_items/$loan_id/", 'jewel_' . ($i + 1));
                        }
                        
                        // Check for file upload
                        $file_field = 'jewel_photo_' . $i;
                        if (isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] == 0) {
                            $upload_dir = "uploads/loan_items/$loan_id/";
                            if (!file_exists($upload_dir)) {
                                mkdir($upload_dir, 0777, true);
                            }
                            
                            $ext = pathinfo($_FILES[$file_field]['name'], PATHINFO_EXTENSION);
                            $filename = 'jewel_' . ($i + 1) . '_' . time() . '.' . $ext;
                            $filepath = $upload_dir . $filename;
                            
                            if (move_uploaded_file($_FILES[$file_field]['tmp_name'], $filepath)) {
                                $jewel_photo = $filepath;
                            }
                        }
                        
                        $insert_item = "INSERT INTO loan_items (
                            loan_id, jewel_name, karat, defect_details, stone_details, 
                            gross_weight, net_weight, quantity, photo_path
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $item_stmt = mysqli_prepare($conn, $insert_item);
                        if ($item_stmt) {
                            mysqli_stmt_bind_param(
                                $item_stmt, 
                                'isdssddds', 
                                $loan_id, 
                                $jewel_name, 
                                $karat, 
                                $defect, 
                                $stone, 
                                $item_gross_weight, 
                                $item_net_weight, 
                                $quantity,
                                $jewel_photo
                            );
                        }
                        
                        if (!$item_stmt) {
                            throw new Exception("Error preparing item statement: " . mysqli_error($conn));
                        }
                        
                        if (!mysqli_stmt_execute($item_stmt)) {
                            throw new Exception("Error executing item statement: " . mysqli_stmt_error($item_stmt));
                        }
                    }
                }
            }
            
            // If payment method is bank, insert into bank ledger
            if ($payment_method === 'bank' && $bank_account_id > 0 && $loan_amount > 0) {
                // Get current balance again (in case it changed)
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
                
                // Double-check balance before inserting
                if ($loan_amount > $current_balance) {
                    throw new Exception("Insufficient bank balance! Available: ₹" . number_format($current_balance, 2));
                }
                
                // Calculate new balance after debit
                $new_balance = $current_balance - $loan_amount;
                
                // Insert debit transaction
                $insert_ledger = "INSERT INTO bank_ledger (
                    entry_date, bank_id, bank_account_id, transaction_type, 
                    amount, balance, reference_number, description, 
                    loan_id, created_by, created_at
                ) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?, NOW())";
                
                $ledger_stmt = mysqli_prepare($conn, $insert_ledger);
                $description = "Loan disbursement - Receipt #: " . $receipt_number;
                
                mysqli_stmt_bind_param(
                    $ledger_stmt,
                    'siidsssii',
                    $payment_date,
                    $bank_id,
                    $bank_account_id,
                    $loan_amount,
                    $new_balance,
                    $transaction_ref,
                    $description,
                    $loan_id,
                    $_SESSION['user_id']
                );
                
                if (!mysqli_stmt_execute($ledger_stmt)) {
                    throw new Exception("Error updating bank ledger: " . mysqli_stmt_error($ledger_stmt));
                }
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'create', ?, 'loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            
            if (!$log_stmt) {
                throw new Exception("Error preparing log statement: " . mysqli_error($conn));
            }
            
            $log_description = "New loan created: " . $receipt_number;
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            
            if (!mysqli_stmt_execute($log_stmt)) {
                throw new Exception("Error executing log statement: " . mysqli_stmt_error($log_stmt));
            }
            
            // Commit transaction
            mysqli_commit($conn);
            
            // Send email notification
            $email_sent = false;
            $email_message = '';
            
            if (isset($_POST['send_email']) && $_POST['send_email'] == '1') {
                if (file_exists('includes/email_helper.php')) {
                    require_once 'includes/email_helper.php';
                    if (function_exists('sendLoanEmail')) {
                        $email_result = sendLoanEmail($loan_id, $conn);
                        $email_sent = $email_result['success'];
                    }
                }
            }
            
            // Redirect with success message
            $redirect_url = 'view-loan.php?id=' . $loan_id . '&success=created';
            if ($email_sent) {
                $redirect_url .= '&email=sent';
            }
            header('Location: ' . $redirect_url);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error creating loan: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Helper function to save base64 image
function saveBase64Image($base64_data, $upload_dir, $filename_prefix) {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $type)) {
        $image_data = substr($base64_data, strpos($base64_data, ',') + 1);
        $type = strtolower($type[1]);
        
        if (in_array($type, ['jpg', 'jpeg', 'png', 'gif'])) {
            $image_data = base64_decode($image_data);
            if ($image_data !== false) {
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = $filename_prefix . '_' . time() . '.' . $type;
                $filepath = $upload_dir . $filename;
                
                if (file_put_contents($filepath, $image_data)) {
                    return $filepath;
                }
            }
        }
    }
    return null;
}

// Get recent loans for display
$recent_loans_query = "SELECT l.*, c.customer_name, c.mobile_number, c.customer_photo,
                       DATEDIFF(NOW(), l.receipt_date) as days_old,
                       l.created_at
                       FROM loans l 
                       JOIN customers c ON l.customer_id = c.id 
                       WHERE l.status = 'open' 
                       ORDER BY l.created_at DESC LIMIT 10";
$recent_loans_result = mysqli_query($conn, $recent_loans_query);
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
        /* All your existing CSS styles remain exactly the same */
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        .form-card {
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-2 {
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

        .form-group.full-width {
            grid-column: 1 / -1;
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
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
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

        .form-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
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

        /* Customer Search Styles */
        .customer-search-container {
            position: relative;
        }

        .customer-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .customer-search-results.show {
            display: block;
        }

        .customer-result-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
        }

        .customer-result-item:hover {
            background: #f7fafc;
        }

        .customer-result-item.selected {
            background: #ebf4ff;
            border-left: 3px solid #667eea;
        }

        .customer-result-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 3px;
        }

        .customer-result-details {
            font-size: 12px;
            color: #718096;
        }

        .customer-result-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        .badge-noted {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-active-loans {
            background: #ebf4ff;
            color: #2c5282;
        }

        /* Customer Info Card */
        .customer-info-card {
            background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 15px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .customer-photo-small {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid #667eea;
            box-shadow: 0 4px 10px rgba(102,126,234,0.3);
        }

        .customer-details {
            flex: 1;
        }

        .customer-name {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .customer-contact {
            display: flex;
            gap: 20px;
            margin-bottom: 5px;
            color: #4a5568;
            font-size: 14px;
        }

        .customer-address {
            color: #718096;
            font-size: 13px;
        }

        /* Loan Limit Card */
        .loan-limit-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 15px;
            margin: 15px 0;
            display: none;
        }

        .loan-limit-card.show {
            display: block;
        }

        .loan-limit-title {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loan-limit-amount {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .loan-limit-progress {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .loan-limit-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #4299e1);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .loan-limit-details {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: #718096;
            margin-top: 5px;
        }

        .loan-limit-warning {
            color: #f56565;
            font-weight: 600;
        }

        .loan-limit-success {
            color: #48bb78;
            font-weight: 600;
        }

        /* Payment Tabs */
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

        .payment-tab.other.active {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
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

        /* Interest Tabs */
        .interest-calculation-options {
            display: flex;
            gap: 20px;
            margin: 15px 0;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .interest-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .interest-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }

        .interest-option label {
            font-weight: 600;
            color: #4a5568;
        }

        /* Personal Loan Info */
        .personal-loan-info {
            background: #fef3c7;
            border-left: 4px solid #ecc94b;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            display: none;
        }

        .personal-loan-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .personal-loan-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }

        .personal-loan-label {
            font-size: 11px;
            color: #744210;
            margin-bottom: 3px;
        }

        .personal-loan-value {
            font-size: 16px;
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

        /* Jewelry Table */
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
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .jewelry-table input, .jewelry-table select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
        }

        /* Jewelry Photo Styles */
        .jewelry-photo-section {
            display: flex;
            flex-direction: column;
            gap: 5px;
            align-items: center;
        }

        .photo-btn-group {
            display: flex;
            gap: 5px;
            width: 100%;
        }

        .camera-btn {
            flex: 1;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .camera-btn-capture {
            background: #4299e1;
            color: white;
        }

        .camera-btn-upload {
            background: #48bb78;
            color: white;
        }

        .camera-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .photo-preview-container {
            text-align: center;
            margin-top: 5px;
        }

        .jewel-photo-preview {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            object-fit: cover;
            border: 2px solid #667eea;
        }

        .photo-filename {
            font-size: 10px;
            color: #718096;
            margin-top: 3px;
            display: block;
            word-break: break-all;
        }

        /* Camera Modal */
        .camera-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .camera-modal-content {
            background: white;
            border-radius: 16px;
            padding: 25px;
            max-width: 600px;
            width: 95%;
        }

        .camera-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .camera-modal-header h3 {
            font-size: 20px;
            color: #2d3748;
            margin: 0;
        }

        .camera-modal-header button {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #718096;
        }

        .camera-preview-container {
            width: 100%;
            height: 350px;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .camera-preview-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .camera-modal-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .remove-row {
            color: #f56565;
            cursor: pointer;
            text-align: center;
            font-size: 18px;
        }

        .remove-row:hover {
            color: #c53030;
        }

        .add-row-btn {
            background: #f7fafc;
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            margin: 20px 0;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s;
        }

        .add-row-btn:hover {
            background: #ebf4ff;
            border-color: #48bb78;
        }

        /* Summary Box */
        .summary-box {
            background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 600;
            color: #4a5568;
        }

        .summary-value {
            font-weight: 700;
            color: #2d3748;
        }

        .summary-total-row {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            padding-top: 10px;
            margin-top: 10px;
            border-top: 2px solid #667eea30;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Recent Loans */
        .recent-loans {
            margin-top: 40px;
        }

        .recent-loans h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #2d3748;
        }

        .recent-loans-table {
            width: 100%;
            overflow-x: auto;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .recent-loans-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .recent-loans-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            white-space: nowrap;
        }

        .recent-loans-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .recent-loans-table tr:hover {
            background: #f7fafc;
            cursor: pointer;
        }

        .customer-photo-thumb {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }

        .date-hint {
            font-size: 11px;
            color: #718096;
            margin-top: 2px;
        }

        .noted-person-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 15px;
        }

        .new-customer-btn {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .new-customer-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(159, 122, 234, 0.4);
        }

        /* Submit Button States */
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .form-grid, .form-grid-2, .form-grid-3 {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .jewelry-table {
                overflow-x: auto;
                display: block;
            }
            
            .customer-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-contact {
                flex-direction: column;
                gap: 5px;
            }
            
            .payment-tabs {
                flex-direction: column;
            }
            
            .interest-calculation-options {
                flex-direction: column;
            }
            
            .photo-btn-group {
                flex-direction: column;
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
                            <i class="bi bi-plus-circle"></i>
                            New Loan
                        </h1>
                        <div>
                            <a href="loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Loans
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

                    <!-- Main Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="loanForm">
                        
                        <!-- Customer Information -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-person"></i>
                                Customer Information
                                <span class="noted-person-badge" id="notedPersonBadge" style="display: none;">
                                    <i class="bi bi-star-fill"></i> Noted Person
                                </span>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label required">Search Customer</label>
                                    <div class="input-group customer-search-container">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" id="customerSearch" placeholder="Enter customer name, mobile or ID" autocomplete="off" 
                                               value="<?php echo $preselected_customer_data ? htmlspecialchars($preselected_customer_data['customer_name']) : ''; ?>">
                                        <input type="hidden" name="customer_id" id="customer_id" value="<?php echo $preselected_customer_id; ?>" required>
                                        <div class="customer-search-results" id="customerSearchResults"></div>
                                    </div>
                                    <small class="form-text">Type at least 2 characters to search</small>
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Receipt Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="text" class="form-control datepicker" name="receipt_date" id="receipt_date" value="<?php echo date('d/m/Y'); ?>" required placeholder="dd/mm/yyyy">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Receipt Number</label>
                                    <div class="input-group">
                                        <i class="bi bi-receipt input-icon"></i>
                                        <input type="text" class="form-control readonly-field" name="receipt_number" value="<?php echo $receipt_number; ?>" readonly>
                                    </div>
                                </div>

                                <div class="form-group" style="text-align: right;">
                                    <button type="button" class="new-customer-btn" onclick="window.open('New-Customer.php', '_blank')">
                                        <i class="bi bi-person-plus"></i> Add New Customer
                                    </button>
                                </div>
                            </div>

                            <!-- Customer Info Card (Hidden initially) -->
                            <div id="customerInfoCard" class="customer-info-card" style="display: none;">
                                <img id="customerPhotoDisplay" src="" alt="Customer Photo" class="customer-photo-small" style="display: none;">
                                <div class="customer-details">
                                    <div class="customer-name" id="displayCustomerName"></div>
                                    <div class="customer-contact">
                                        <span><i class="bi bi-phone"></i> <span id="displayCustomerMobile"></span></span>
                                        <span><i class="bi bi-envelope"></i> <span id="displayCustomerEmail"></span></span>
                                    </div>
                                    <div class="customer-address" id="displayCustomerAddress"></div>
                                </div>
                            </div>

                            <!-- Loan Limit Card (Hidden initially) -->
                            <div class="loan-limit-card" id="loanLimitCard">
                                <div class="loan-limit-title">
                                    <i class="bi bi-pie-chart-fill"></i> Loan Limit Status
                                </div>
                                <div class="loan-limit-amount" id="loanLimitAmount">₹ 0.00</div>
                                <div class="loan-limit-progress">
                                    <div class="loan-limit-progress-bar" id="loanLimitProgress" style="width: 0%"></div>
                                </div>
                                <div class="loan-limit-details">
                                    <span>Total Limit: <span id="totalLimit">₹ 0.00</span></span>
                                    <span>Used: <span id="usedLimit">₹ 0.00</span></span>
                                    <span class="loan-limit-success">Available: <span id="availableLimit">₹ 0.00</span></span>
                                </div>
                                <div class="loan-limit-details">
                                    <span>Active Loans: <span id="activeLoansCount">0</span></span>
                                    <span class="loan-limit-warning" id="limitWarning" style="display: none;">
                                        <i class="bi bi-exclamation-triangle"></i> Exceeds limit
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Product Information -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-gem"></i>
                                Product Information
                            </div>

                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label class="form-label required">Product Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="product_type" id="product_type" required onchange="loadProductSettings()">
                                            <option value="">Select Product Type</option>
                                            <?php 
                                            if ($product_types_result && mysqli_num_rows($product_types_result) > 0) {
                                                mysqli_data_seek($product_types_result, 0);
                                                while($pt = mysqli_fetch_assoc($product_types_result)): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($pt['product_type']); ?>">
                                                    <?php echo htmlspecialchars($pt['product_type']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Interest Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-percent input-icon"></i>
                                        <select class="form-select" name="interest_type" id="interest_type" required onchange="loadInterestRate()">
                                            <option value="">Select Interest Type</option>
                                            <?php 
                                            if ($interest_types_result && mysqli_num_rows($interest_types_result) > 0) {
                                                mysqli_data_seek($interest_types_result, 0);
                                                while($it = mysqli_fetch_assoc($interest_types_result)): 
                                            ?>
                                                <option value="<?php echo $it['interest_type']; ?>" data-rate="<?php echo $it['rate']; ?>">
                                                    <?php echo htmlspecialchars($it['interest_type'] . ' (' . $it['rate'] . '%)'); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Interest Rate (%)</label>
                                    <div class="input-group">
                                        <i class="bi bi-percent input-icon"></i>
                                        <input type="number" class="form-control" name="interest_rate" id="interest_rate" step="0.01" min="0" value="0" readonly>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Product Value/Gram (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-calculator input-icon"></i>
                                        <input type="number" class="form-control readonly-field" name="product_value_per_gram" id="product_value_per_gram" step="0.01" min="0" readonly value="0.00">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Loan Value/Gram (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-cash-coin input-icon"></i>
                                        <input type="number" class="form-control readonly-field" name="loan_value_per_gram" id="loan_value_per_gram" step="0.01" min="0" readonly value="0.00">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Employee</label>
                                    <div class="input-group">
                                        <i class="bi bi-person-badge input-icon"></i>
                                        <select class="form-select" name="employee_id" required>
                                            <option value="">Select Employee</option>
                                            <?php 
                                            if ($employees_result && mysqli_num_rows($employees_result) > 0) {
                                                mysqli_data_seek($employees_result, 0);
                                                while($emp = mysqli_fetch_assoc($employees_result)): 
                                            ?>
                                                <option value="<?php echo $emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($emp['name']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Personal Loan Eligibility Section -->
                            <div class="personal-loan-info" id="personalLoanInfo">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <i class="bi bi-gift" style="color: #ecc94b; font-size: 24px;"></i>
                                    <h4 style="margin: 0; color: #744210;">Personal Loan Offer Available!</h4>
                                </div>
                                <div class="personal-loan-grid">
                                    <div class="personal-loan-item">
                                        <div class="personal-loan-label">Regular Loan</div>
                                        <div class="personal-loan-value regular" id="displayRegularAmount">₹ 0.00</div>
                                        <small id="displayRegularPercent">(0%)</small>
                                    </div>
                                    <div class="personal-loan-item">
                                        <div class="personal-loan-label">Personal Loan</div>
                                        <div class="personal-loan-value personal" id="displayPersonalAmount">₹ 0.00</div>
                                        <small id="displayPersonalPercent">(0%)</small>
                                    </div>
                                    <div class="personal-loan-item">
                                        <div class="personal-loan-label">Total Available</div>
                                        <div class="personal-loan-value total" id="displayTotalAmount">₹ 0.00</div>
                                    </div>
                                </div>
                                <p style="font-size: 12px; color: #744210; margin-top: 10px;">
                                    <i class="bi bi-info-circle"></i> 
                                    You can get additional personal loan amount on top of your regular loan.
                                </p>
                            </div>

                            <!-- Interest Calculation Options -->
                            <div class="interest-calculation-options">
                                <div class="interest-option">
                                    <input type="radio" name="interest_calculation" id="interest_daily" value="daily" checked onchange="setInterestCalculation('daily')">
                                    <label for="interest_daily">Daily Interest</label>
                                </div>
                                <div class="interest-option">
                                    <input type="radio" name="interest_calculation" id="interest_monthly" value="monthly" onchange="setInterestCalculation('monthly')">
                                    <label for="interest_monthly">Monthly Interest</label>
                                </div>
                                <div class="interest-option">
                                    <input type="radio" name="interest_calculation" id="interest_without" value="without" onchange="setInterestCalculation('without')">
                                    <label for="interest_without">Without Interest</label>
                                </div>
                            </div>

                            <!-- Payment Method Tabs -->
                            <div class="payment-tabs">
                                <div class="payment-tab cash active" onclick="setPaymentMethod('cash')">
                                    <i class="bi bi-cash"></i> Cash
                                </div>
                                <div class="payment-tab bank" onclick="setPaymentMethod('bank')">
                                    <i class="bi bi-bank"></i> Bank
                                </div>
                                <div class="payment-tab upi" onclick="setPaymentMethod('upi')">
                                    <i class="bi bi-phone"></i> UPI
                                </div>
                                <div class="payment-tab other" onclick="setPaymentMethod('other')">
                                    <i class="bi bi-three-dots"></i> Other
                                </div>
                            </div>
                            <input type="hidden" name="payment_method" id="payment_method" value="cash">

                            <!-- Bank Details Section -->
                            <div class="bank-details" id="bankDetails">
                                <div class="form-grid-2">
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
                                    <div class="form-group">
                                        <label class="form-label">Payment Date</label>
                                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="alert alert-info" style="margin-top: 10px;">
                                    <i class="bi bi-info-circle-fill"></i>
                                    The loan amount will be debited from the selected bank account. 
                                    <strong>Loan cannot be created if balance is insufficient.</strong>
                                </div>
                            </div>
                        </div>

                        <!-- Jewelry Items Table -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                                Jewelry Items
                            </div>

                            <table class="jewelry-table" id="jewelryTable">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Jewel Name</th>
                                        <th>Karat</th>
                                        <th>Defect</th>
                                        <th>Stone</th>
                                        <th>Gross (g)</th>
                                        <th>Margin (g)</th>
                                        <th>Net (g)</th>
                                        <th>Qty</th>
                                        <th>Photo</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="jewelryBody">
                                    <tr id="row1">
                                        <td>1</td>
                                        <td>
                                            <select name="jewel_name[]" class="form-select">
                                                <option value="">Select</option>
                                                <?php 
                                                if ($jewel_names_result && mysqli_num_rows($jewel_names_result) > 0) {
                                                    mysqli_data_seek($jewel_names_result, 0);
                                                    while($jewel = mysqli_fetch_assoc($jewel_names_result)): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($jewel['jewel_name']); ?>">
                                                        <?php echo htmlspecialchars($jewel['jewel_name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="karat[]" class="form-select" onchange="updateLoanValue()">
                                                <option value="">Select</option>
                                                <?php 
                                                if ($karat_result && mysqli_num_rows($karat_result) > 0) {
                                                    mysqli_data_seek($karat_result, 0);
                                                    while($karat = mysqli_fetch_assoc($karat_result)): 
                                                ?>
                                                    <option value="<?php echo $karat['karat']; ?>" data-max="<?php echo $karat['max_value_per_gram']; ?>" data-loan="<?php echo $karat['loan_value_per_gram']; ?>">
                                                        <?php echo $karat['karat']; ?>K
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="defect[]" class="form-select">
                                                <option value="">Select</option>
                                                <?php 
                                                if ($defect_result && mysqli_num_rows($defect_result) > 0) {
                                                    mysqli_data_seek($defect_result, 0);
                                                    while($defect = mysqli_fetch_assoc($defect_result)): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($defect['defect_name']); ?>">
                                                        <?php echo htmlspecialchars($defect['defect_name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="stone[]" class="form-select">
                                                <option value="">Select</option>
                                                <?php 
                                                if ($stone_result && mysqli_num_rows($stone_result) > 0) {
                                                    mysqli_data_seek($stone_result, 0);
                                                    while($stone = mysqli_fetch_assoc($stone_result)): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($stone['stone_name']); ?>">
                                                        <?php echo htmlspecialchars($stone['stone_name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="item_gross_weight[]" step="0.001" min="0" class="gross-weight" value="5" onchange="calculateNetWeight(this)">
                                        </td>
                                        <td>
                                            <input type="number" name="item_margin_weight[]" step="0.001" min="0" class="margin-weight" value="1" onchange="calculateNetWeight(this)">
                                        </td>
                                        <td>
                                            <input type="number" name="item_net_weight[]" step="0.001" min="0" class="net-weight" value="4" readonly>
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" value="1" min="1" onchange="updateTotalWeight()">
                                        </td>
                                        <td>
                                            <div class="jewelry-photo-section">
                                                <div class="photo-btn-group">
                                                    <!-- Camera Button -->
                                                    <button type="button" onclick="openJewelCamera(this, 1)" class="camera-btn camera-btn-capture" title="Take Photo">
                                                        <i class="bi bi-camera"></i>
                                                    </button>
                                                    
                                                    <!-- Upload Button -->
                                                    <label class="camera-btn camera-btn-upload" title="Upload Photo">
                                                        <i class="bi bi-cloud-upload"></i>
                                                        <input type="file" name="jewel_photo_0" accept="image/*" style="display: none;" onchange="previewJewelPhoto(this, 1)">
                                                    </label>
                                                </div>
                                                
                                                <!-- Hidden input for camera capture -->
                                                <input type="hidden" id="jewel_photo_camera_1" name="jewel_photo_camera_0">
                                                
                                                <!-- Photo Preview -->
                                                <div class="photo-preview-container" id="photo_preview_1" style="display: none;">
                                                    <img src="" class="jewel-photo-preview" alt="Preview">
                                                </div>
                                                
                                                <!-- File name display -->
                                                <small class="photo-filename" id="jewel_photo_name_1"></small>
                                            </div>
                                        </td>
                                        <td class="remove-row" onclick="removeRow(this)">
                                            <i class="bi bi-trash"></i>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="add-row-btn" onclick="addRow()">
                                <i class="bi bi-plus-circle"></i> Add Another Item
                            </div>
                            
                            <!-- Basic Information -->
                            <div class="form-card">
                                <div class="section-title">
                                    <i class="bi bi-info-circle"></i>
                                    Basic Information
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Product Value (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-cash input-icon"></i>
                                            <input type="number" class="form-control" name="product_value" id="product_value" step="0.01" min="0" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Collateral Value (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-cash-stack input-icon"></i>
                                            <input type="number" class="form-control" name="max_value" id="max_value" step="0.01" min="0" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Loan Amount (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-cash-coin input-icon"></i>
                                            <input type="number" class="form-control" name="loan_amount" id="loan_amount" step="0.01" min="0" required oninput="validateBankBalance()">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Process Charge (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-receipt-cutoff input-icon"></i>
                                            <input type="number" class="form-control" name="process_charge" id="process_charge" value="200.00" step="0.01" min="0">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Appraisal Charge (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-journal-check input-icon"></i>
                                            <input type="number" class="form-control" name="appraisal_charge" id="appraisal_charge" value="100.00" step="0.01" min="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Weight Summary -->
                            <div class="summary-box">
                                <h4 style="margin-bottom: 15px; color: #2d3748;">Weight Summary</h4>
                                <div class="summary-row">
                                    <span class="summary-label">Total Gross Weight:</span>
                                    <span class="summary-value" id="total_gross_weight">5.000 g</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Margin Weight:</span>
                                    <span class="summary-value" id="total_margin_weight">1.000 g</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Net Weight:</span>
                                    <span class="summary-value" id="total_net_weight">4.000 g</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Items:</span>
                                    <span class="summary-value" id="total_items">1</span>
                                </div>
                                <div class="summary-total-row">
                                    <span class="summary-label">Loan Amount:</span>
                                    <span class="summary-value" id="final_loan_amount">₹ 0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Email Notification Option -->
                        <div class="form-card">
                            <div class="form-check" style="margin: 10px 0;">
                                <input type="checkbox" class="form-check-input" name="send_email" id="send_email" value="1" checked>
                                <label class="form-check-label" for="send_email">
                                    <i class="bi bi-envelope-fill text-primary"></i>
                                    Send email notification to customer
                                </label>
                                <small class="form-text d-block">
                                    An email will be sent to the customer with loan details and receipt
                                </small>
                            </div>
                        </div>

                        <!-- Hidden fields for totals -->
                        <input type="hidden" name="gross_weight" id="gross_weight" value="5.000">
                        <input type="hidden" name="net_weight" id="net_weight" value="4.000">

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="submit" class="btn btn-success" id="submitBtn">
                                <i class="bi bi-check-circle"></i> Save Loan & Send Email
                            </button>
                            <button type="button" class="btn btn-warning" onclick="clearForm()">
                                <i class="bi bi-eraser"></i> Clear
                            </button>
                            <button type="button" class="btn btn-danger" onclick="window.location.href='loans.php'">
                                <i class="bi bi-x-circle"></i> Close
                            </button>
                        </div>
                    </form>

                    <!-- Recent Loans -->
                    <?php if ($recent_loans_result && mysqli_num_rows($recent_loans_result) > 0): ?>
                    <div class="recent-loans">
                        <h3>Recent Open Loans</h3>
                        <div class="recent-loans-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Receipt No</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($loan = mysqli_fetch_assoc($recent_loans_result)): ?>
                                    <tr onclick="window.location.href='view-loan.php?id=<?php echo $loan['id']; ?>'">
                                        <td><?php echo $loan['receipt_number']; ?></td>
                                        <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                        <td>₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($loan['created_at'])); ?></td>
                                        <td><?php echo $loan['days_old']; ?> days</td>
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

    <!-- Camera Modal -->
    <div id="cameraModal" class="camera-modal">
        <div class="camera-modal-content">
            <div class="camera-modal-header">
                <h3>Take Jewelry Photo</h3>
                <button onclick="closeCamera()">&times;</button>
            </div>
            <div class="camera-preview-container">
                <video id="cameraVideo" autoplay playsinline></video>
                <canvas id="cameraCanvas" style="display: none;"></canvas>
            </div>
            <div class="camera-modal-controls">
                <button type="button" class="btn btn-info" onclick="switchCamera()">
                    <i class="bi bi-arrow-repeat"></i> Switch Camera
                </button>
                <button type="button" class="btn btn-success" onclick="capturePhoto()">
                    <i class="bi bi-camera"></i> Capture
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeCamera()">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Initialize date picker
        flatpickr(".datepicker", {
            dateFormat: "d/m/Y",
            defaultDate: "today",
            maxDate: "today",
            allowInput: true
        });

        let rowCount = 1;
        let selectedCustomerId = <?php echo $preselected_customer_id ?: 0; ?>;
        let searchTimeout = null;
        let currentBankBalance = 0;
        
        // Camera variables
        let currentJewelRow = null;
        let currentRowNum = null;
        let cameraStream = null;
        let currentFacingMode = 'environment';

        // Product settings data
        const productValues = <?php echo json_encode($product_values); ?>;

        // ========== PRODUCT SETTINGS FUNCTIONS ==========
        function loadProductSettings() {
            const productType = document.getElementById('product_type').value;
            if (!productType) return;
            
            // Get product value settings
            fetch('get-product-settings.php?type=' + encodeURIComponent(productType))
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update value per gram
                        document.getElementById('product_value_per_gram').value = data.product_value_per_gram.toFixed(2);
                        
                        // Update loan value per gram (using regular percentage)
                        const loanValuePerGram = (data.product_value_per_gram * data.regular_loan_percentage) / 100;
                        document.getElementById('loan_value_per_gram').value = loanValuePerGram.toFixed(2);
                        
                        // Update total weight calculation
                        updateTotalWeight();
                        
                        // Show personal loan info if available
                        if (data.personal_loan_percentage > 0) {
                            document.getElementById('personalLoanInfo').style.display = 'block';
                            
                            // Store percentages for later use
                            document.getElementById('displayRegularPercent').innerHTML = '(' + data.regular_loan_percentage + '%)';
                            document.getElementById('displayPersonalPercent').innerHTML = '(' + data.personal_loan_percentage + '%)';
                        } else {
                            document.getElementById('personalLoanInfo').style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Error loading product settings:', error));
        }

        // ========== JEWELRY ITEM FUNCTIONS ==========
        function calculateNetWeight(element) {
            const row = element.closest('tr');
            const gross = parseFloat(row.querySelector('.gross-weight').value) || 0;
            const margin = parseFloat(row.querySelector('.margin-weight').value) || 0;
            const net = Math.max(0, gross - margin);
            row.querySelector('.net-weight').value = net.toFixed(3);
            updateTotalWeight();
        }

        function updateLoanValue() {
            // This will be called when karat changes
            updateTotalWeight();
        }

        function updateTotalWeight() {
            let totalGross = 0;
            let totalMargin = 0;
            let totalNet = 0;
            
            document.querySelectorAll('#jewelryBody tr').forEach(row => {
                const gross = parseFloat(row.querySelector('.gross-weight').value) || 0;
                const margin = parseFloat(row.querySelector('.margin-weight').value) || 0;
                const net = parseFloat(row.querySelector('.net-weight').value) || 0;
                const qty = parseInt(row.querySelector('input[name="quantity[]"]').value) || 1;
                
                totalGross += gross * qty;
                totalMargin += margin * qty;
                totalNet += net * qty;
            });
            
            document.getElementById('total_gross_weight').textContent = totalGross.toFixed(3) + ' g';
            document.getElementById('total_margin_weight').textContent = totalMargin.toFixed(3) + ' g';
            document.getElementById('total_net_weight').textContent = totalNet.toFixed(3) + ' g';
            
            document.getElementById('gross_weight').value = totalGross.toFixed(3);
            document.getElementById('net_weight').value = totalNet.toFixed(3);
            
            // Calculate product value based on product type settings
            const productType = document.getElementById('product_type').value;
            const valuePerGram = parseFloat(document.getElementById('product_value_per_gram').value) || 0;
            const loanValuePerGram = parseFloat(document.getElementById('loan_value_per_gram').value) || 0;
            
            if (productType && valuePerGram > 0) {
                const totalValue = totalNet * valuePerGram;
                const loanValue = totalNet * loanValuePerGram;
                
                document.getElementById('product_value').value = totalValue.toFixed(2);
                document.getElementById('max_value').value = totalValue.toFixed(2);
                document.getElementById('loan_amount').value = loanValue.toFixed(2);
                document.getElementById('final_loan_amount').textContent = '₹ ' + loanValue.toFixed(2);
                
                // Update personal loan calculations
                if (productValues[productType]) {
                    const regularPercent = productValues[productType]['regular_loan_percentage'] || 70;
                    const personalPercent = productValues[productType]['personal_loan_percentage'] || 0;
                    
                    const regularAmount = (totalValue * regularPercent) / 100;
                    const personalAmount = (totalValue * personalPercent) / 100;
                    
                    document.getElementById('displayRegularAmount').innerHTML = '₹ ' + regularAmount.toFixed(2);
                    document.getElementById('displayPersonalAmount').innerHTML = '₹ ' + personalAmount.toFixed(2);
                    document.getElementById('displayTotalAmount').innerHTML = '₹ ' + (regularAmount + personalAmount).toFixed(2);
                }
            }
            
            // Validate bank balance after setting loan amount
            validateBankBalance();
        }

        // ========== CUSTOMER SEARCH FUNCTIONS ==========
        document.getElementById('customerSearch').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value.trim();
            
            if (searchTerm.length < 2) {
                document.getElementById('customerSearchResults').classList.remove('show');
                return;
            }
            
            const resultsDiv = document.getElementById('customerSearchResults');
            resultsDiv.innerHTML = '<div class="customer-result-item">Searching...</div>';
            resultsDiv.classList.add('show');
            
            searchTimeout = setTimeout(() => {
                fetch('get-customers.php?search=' + encodeURIComponent(searchTerm))
                    .then(response => response.json())
                    .then(data => {
                        resultsDiv.innerHTML = '';
                        
                        if (data.success && data.customers && data.customers.length > 0) {
                            data.customers.forEach(customer => {
                                const item = document.createElement('div');
                                item.className = 'customer-result-item';
                                item.setAttribute('onclick', 'selectCustomer(' + customer.id + ')');
                                
                                let badges = '';
                                if (customer.is_noted_person) {
                                    badges += '<span class="customer-result-badge badge-noted"><i class="bi bi-star-fill"></i> Noted</span>';
                                }
                                if (customer.active_loans_count > 0) {
                                    badges += '<span class="customer-result-badge badge-active-loans">' + customer.active_loans_count + ' Active</span>';
                                }
                                
                                item.innerHTML = `
                                    <div class="customer-result-name">
                                        ${customer.customer_name} (ID: ${customer.id}) ${badges}
                                    </div>
                                    <div class="customer-result-details">
                                        📞 ${customer.mobile_number} | Limit: ₹${(customer.loan_limit_amount || 0).toLocaleString()}
                                    </div>
                                `;
                                
                                resultsDiv.appendChild(item);
                            });
                        } else {
                            resultsDiv.innerHTML = '<div class="customer-result-item">No customers found</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        resultsDiv.innerHTML = '<div class="customer-result-item">Error searching customers</div>';
                    });
            }, 500);
        });

        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            const container = document.querySelector('.customer-search-container');
            const results = document.getElementById('customerSearchResults');
            if (!container.contains(event.target)) {
                results.classList.remove('show');
            }
        });

        // Select customer function
        function selectCustomer(customerId) {
            document.getElementById('customerSearchResults').classList.remove('show');
            document.getElementById('customerSearch').value = 'Loading...';
            
            fetch('get-customers.php?customer_id=' + customerId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.customer) {
                        const customer = data.customer;
                        selectedCustomerId = customer.id;
                        document.getElementById('customer_id').value = customer.id;
                        
                        // Show customer info card
                        document.getElementById('customerInfoCard').style.display = 'flex';
                        document.getElementById('displayCustomerName').textContent = customer.customer_name;
                        document.getElementById('displayCustomerMobile').textContent = customer.mobile_number || '';
                        document.getElementById('displayCustomerEmail').textContent = customer.email || '';
                        
                        // Build address
                        let address = [];
                        if (customer.door_no) address.push(customer.door_no);
                        if (customer.house_name) address.push(customer.house_name);
                        if (customer.street_name) address.push(customer.street_name);
                        if (customer.location) address.push(customer.location);
                        if (customer.district) address.push(customer.district);
                        document.getElementById('displayCustomerAddress').textContent = address.join(', ') || 'Address not available';
                        
                        // Show photo if available
                        if (customer.customer_photo) {
                            document.getElementById('customerPhotoDisplay').src = customer.customer_photo;
                            document.getElementById('customerPhotoDisplay').style.display = 'block';
                        }
                        
                        // Update search input
                        document.getElementById('customerSearch').value = customer.customer_name;
                        
                        // Show loan limit card
                        updateLoanLimitDisplay(customer);
                    }
                })
                .catch(error => {
                    console.error('Error loading customer:', error);
                    document.getElementById('customerSearch').value = '';
                });
        }

        // Update loan limit display
        function updateLoanLimitDisplay(customer) {
            const limitCard = document.getElementById('loanLimitCard');
            const totalLimit = document.getElementById('totalLimit');
            const usedLimit = document.getElementById('usedLimit');
            const availableLimit = document.getElementById('availableLimit');
            const loanLimitAmount = document.getElementById('loanLimitAmount');
            const progressBar = document.getElementById('loanLimitProgress');
            const activeCount = document.getElementById('activeLoansCount');
            
            const loan_limit = customer.loan_limit_amount || 10000000;
            const total_taken = customer.total_loans_taken || 0;
            const remaining = loan_limit - total_taken;
            
            totalLimit.textContent = '₹ ' + loan_limit.toLocaleString(undefined, {minimumFractionDigits: 2});
            usedLimit.textContent = '₹ ' + total_taken.toLocaleString(undefined, {minimumFractionDigits: 2});
            availableLimit.textContent = '₹ ' + remaining.toLocaleString(undefined, {minimumFractionDigits: 2});
            loanLimitAmount.textContent = '₹ ' + remaining.toLocaleString(undefined, {minimumFractionDigits: 2});
            activeCount.textContent = customer.active_loans_count || 0;
            
            const percentage = loan_limit > 0 ? (total_taken / loan_limit) * 100 : 0;
            progressBar.style.width = percentage + '%';
            
            limitCard.classList.add('show');
        }

        // ========== INTEREST RATE FUNCTIONS ==========
        function loadInterestRate() {
            const select = document.getElementById('interest_type');
            const selectedOption = select.options[select.selectedIndex];
            
            if (selectedOption && selectedOption.value) {
                const rate = selectedOption.getAttribute('data-rate');
                if (rate) {
                    document.getElementById('interest_rate').value = parseFloat(rate).toFixed(2);
                } else {
                    document.getElementById('interest_rate').value = '0.00';
                }
            } else {
                document.getElementById('interest_rate').value = '0.00';
            }
        }

        // ========== BANK BALANCE VALIDATION ==========
        function validateBankBalance() {
            const paymentMethod = document.getElementById('payment_method').value;
            const loanAmount = parseFloat(document.getElementById('loan_amount').value) || 0;
            const balanceWarning = document.getElementById('balanceWarning');
            const submitBtn = document.getElementById('submitBtn');
            
            if (paymentMethod === 'bank' && currentBankBalance > 0) {
                if (loanAmount > currentBankBalance) {
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

        // ========== INTEREST CALCULATION FUNCTIONS ==========
        function setInterestCalculation(type) {
            const radio = document.querySelector(`input[name="interest_calculation"][value="${type}"]`);
            if (radio) {
                radio.checked = true;
            }
        }

        // ========== PAYMENT METHOD FUNCTIONS ==========
        function setPaymentMethod(method) {
            document.getElementById('payment_method').value = method;
            
            document.querySelectorAll('.payment-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('.payment-tab.' + method).classList.add('active');
            
            const bankDetails = document.getElementById('bankDetails');
            if (method === 'bank') {
                bankDetails.classList.add('show');
                validateBankBalance();
            } else {
                bankDetails.classList.remove('show');
                document.getElementById('submitBtn').disabled = false;
            }
        }

        // ========== BANK ACCOUNT FUNCTIONS ==========
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
                document.getElementById('submitBtn').disabled = false;
            }
        }

        // ========== JEWELRY PHOTO FUNCTIONS ==========
        function openJewelCamera(button, rowNum) {
            currentJewelRow = button.closest('tr');
            currentRowNum = rowNum;
            
            document.getElementById('cameraModal').style.display = 'flex';
            
            startCamera();
        }

        async function startCamera() {
            try {
                const constraints = {
                    video: { facingMode: currentFacingMode },
                    audio: false
                };
                
                cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
                const video = document.getElementById('cameraVideo');
                video.srcObject = cameraStream;
            } catch (err) {
                console.error('Camera error:', err);
                Swal.fire('Error', 'Unable to access camera: ' + err.message, 'error');
                closeCamera();
            }
        }

        function switchCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
                startCamera();
            }
        }

        function capturePhoto() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('cameraCanvas');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            const context = canvas.getContext('2d');
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = canvas.toDataURL('image/jpeg', 0.8);
            
            // Get the row
            const row = currentJewelRow;
            
            // Set the hidden input
            const hiddenInput = row.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = imageData;
            }
            
            // Show preview
            const previewContainer = row.querySelector('.photo-preview-container');
            const previewImg = previewContainer.querySelector('img');
            previewImg.src = imageData;
            previewContainer.style.display = 'block';
            
            // Clear any file input
            const fileInput = row.querySelector('input[type="file"]');
            if (fileInput) fileInput.value = '';
            
            // Show success message
            const nameSpan = row.querySelector('.photo-filename');
            if (nameSpan) {
                nameSpan.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#48bb78;"></i> Photo captured';
            }
            
            closeCamera();
        }

        function closeCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            document.getElementById('cameraModal').style.display = 'none';
        }

        function previewJewelPhoto(input, rowNum) {
            if (input.files && input.files[0]) {
                if (input.files[0].size > 2 * 1024 * 1024) {
                    Swal.fire('Error', 'File size must be less than 2MB', 'error');
                    input.value = '';
                    return;
                }
                
                if (!input.files[0].type.match('image.*')) {
                    Swal.fire('Error', 'Please select an image file', 'error');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                const row = input.closest('tr');
                
                reader.onload = function(e) {
                    // Show preview
                    const previewContainer = row.querySelector('.photo-preview-container');
                    const previewImg = previewContainer.querySelector('img');
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'block';
                    
                    // Clear camera capture
                    const hiddenInput = row.querySelector('input[type="hidden"]');
                    if (hiddenInput) hiddenInput.value = '';
                    
                    // Show file name
                    const nameSpan = row.querySelector('.photo-filename');
                    if (nameSpan) {
                        nameSpan.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#48bb78;"></i> ' + input.files[0].name.substring(0, 15) + '...';
                    }
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Add new row
        function addRow() {
            rowCount++;
            const tbody = document.getElementById('jewelryBody');
            const firstRow = document.getElementById('row1');
            const newRow = firstRow.cloneNode(true);
            
            // Update IDs and names
            newRow.id = 'row' + rowCount;
            newRow.querySelector('td').textContent = rowCount;
            
            // Update camera button
            const cameraBtn = newRow.querySelector('button[onclick^="openJewelCamera"]');
            if (cameraBtn) {
                cameraBtn.setAttribute('onclick', 'openJewelCamera(this, ' + rowCount + ')');
            }
            
            // Update file input
            const fileInput = newRow.querySelector('input[type="file"]');
            if (fileInput) {
                fileInput.name = 'jewel_photo_' + (rowCount - 1);
                fileInput.setAttribute('onchange', 'previewJewelPhoto(this, ' + rowCount + ')');
                fileInput.value = '';
            }
            
            // Update hidden input
            const hiddenInput = newRow.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                hiddenInput.id = 'jewel_photo_camera_' + rowCount;
                hiddenInput.name = 'jewel_photo_camera_' + (rowCount - 1);
                hiddenInput.value = '';
            }
            
            // Update preview container
            const previewContainer = newRow.querySelector('.photo-preview-container');
            if (previewContainer) {
                previewContainer.style.display = 'none';
            }
            
            // Update name span
            const nameSpan = newRow.querySelector('.photo-filename');
            if (nameSpan) {
                nameSpan.innerHTML = '';
            }
            
            // Clear input values
            newRow.querySelectorAll('input[type="number"]').forEach(input => {
                if (input.name.includes('quantity')) {
                    input.value = '1';
                } else {
                    input.value = '';
                }
            });
            
            newRow.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
            
            tbody.appendChild(newRow);
            document.getElementById('total_items').textContent = rowCount;
        }

        // Remove row
        function removeRow(element) {
            if (rowCount > 1) {
                element.closest('tr').remove();
                rowCount--;
                renumberRows();
                updateTotalWeight();
            } else {
                Swal.fire('Warning', 'At least one item is required', 'warning');
            }
        }

        // Renumber rows after deletion
        function renumberRows() {
            const rows = document.querySelectorAll('#jewelryBody tr');
            rows.forEach((row, index) => {
                row.cells[0].textContent = index + 1;
                row.id = 'row' + (index + 1);
                
                // Update camera button
                const cameraBtn = row.querySelector('button[onclick^="openJewelCamera"]');
                if (cameraBtn) {
                    cameraBtn.setAttribute('onclick', 'openJewelCamera(this, ' + (index + 1) + ')');
                }
                
                // Update file input
                const fileInput = row.querySelector('input[type="file"]');
                if (fileInput) {
                    fileInput.name = 'jewel_photo_' + index;
                    fileInput.setAttribute('onchange', 'previewJewelPhoto(this, ' + (index + 1) + ')');
                }
                
                // Update hidden input
                const hiddenInput = row.querySelector('input[type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.id = 'jewel_photo_camera_' + (index + 1);
                    hiddenInput.name = 'jewel_photo_camera_' + index;
                }
            });
            document.getElementById('total_items').textContent = rowCount;
        }

        // ========== FORM FUNCTIONS ==========
        document.getElementById('product_type').addEventListener('change', loadProductSettings);

        document.getElementById('loan_amount').addEventListener('input', function() {
            document.getElementById('final_loan_amount').textContent = '₹ ' + (parseFloat(this.value) || 0).toFixed(2);
            
            // Validate loan limit for customer
            const loanAmount = parseFloat(this.value) || 0;
            const availableText = document.getElementById('availableLimit').textContent;
            const available = parseFloat(availableText.replace(/[₹,]/g, '')) || 0;
            
            if (loanAmount > available) {
                document.getElementById('limitWarning').style.display = 'inline';
                document.getElementById('submitBtn').disabled = true;
            } else {
                document.getElementById('limitWarning').style.display = 'none';
                // Don't enable yet - bank balance might still be an issue
                validateBankBalance();
            }
        });

        function clearForm() {
            Swal.fire({
                title: 'Clear Form?',
                text: 'Are you sure you want to clear all fields?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#718096',
                confirmButtonText: 'Yes, clear',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    location.reload();
                }
            });
        }

        // Form submission validation
        document.getElementById('loanForm').addEventListener('submit', function(e) {
            const paymentMethod = document.getElementById('payment_method').value;
            const loanAmount = parseFloat(document.getElementById('loan_amount').value) || 0;
            
            if (paymentMethod === 'bank') {
                if (currentBankBalance <= 0) {
                    e.preventDefault();
                    Swal.fire('Error', 'Please select a bank account', 'error');
                    return;
                }
                
                if (loanAmount > currentBankBalance) {
                    e.preventDefault();
                    Swal.fire('Error', 'Insufficient bank balance! Available: ₹' + currentBankBalance.toLocaleString(), 'error');
                    return;
                }
            }
        });

        // Load preselected customer if any
        <?php if ($preselected_customer_id > 0 && $preselected_customer_data): ?>
        document.addEventListener('DOMContentLoaded', function() {
            selectCustomer(<?php echo $preselected_customer_id; ?>);
        });
        <?php endif; ?>

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadInterestRate();
            loadProductSettings();
            updateTotalWeight();
        });

        // Auto-hide alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => alert.style.display = 'none');
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>