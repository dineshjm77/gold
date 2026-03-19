<?php
session_start();
$currentPage = 'reloan';
$pageTitle = 'Reloan';
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
$original_loan = null;
$customer = null;
$items = [];
$search_term = '';
$search_results = [];

// Handle search request
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = mysqli_real_escape_string($conn, $_GET['search']);
    $search_like = '%' . $search_term . '%';
    
    $search_query = "SELECT l.id, l.receipt_number, l.loan_amount, l.receipt_date, l.status,
                            c.customer_name, c.mobile_number,
                            (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as total_principal_paid,
                            (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as total_interest_paid,
                            (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count
                     FROM loans l
                     JOIN customers c ON l.customer_id = c.id
                     WHERE l.receipt_number LIKE ? 
                        OR c.customer_name LIKE ? 
                        OR c.mobile_number LIKE ?
                     ORDER BY l.receipt_date DESC
                     LIMIT 20";
    
    $stmt = mysqli_prepare($conn, $search_query);
    mysqli_stmt_bind_param($stmt, 'sss', $search_like, $search_like, $search_like);
    mysqli_stmt_execute($stmt);
    $search_results = mysqli_stmt_get_result($stmt);
}

// Check if receipt number or loan ID is provided
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
$receipt_number = isset($_GET['receipt']) ? mysqli_real_escape_string($conn, $_GET['receipt']) : '';
$selected_id = isset($_GET['select']) ? intval($_GET['select']) : 0;

// If a specific loan is selected from search results
if ($selected_id > 0) {
    $loan_id = $selected_id;
}

// If receipt number is provided, find the loan ID
if (!empty($receipt_number) && $loan_id == 0) {
    $receipt_query = "SELECT id FROM loans WHERE receipt_number = ?";
    $stmt = mysqli_prepare($conn, $receipt_query);
    mysqli_stmt_bind_param($stmt, 's', $receipt_number);
    mysqli_stmt_execute($stmt);
    $receipt_result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($receipt_result)) {
        $loan_id = $row['id'];
    } else {
        $error = "No loan found with receipt number: " . htmlspecialchars($receipt_number);
    }
}

// Get product types for dropdown
$product_types_query = "SELECT id, product_type, auction_type, print_color FROM product_types WHERE status = 1 ORDER BY product_type";
$product_types_result = mysqli_query($conn, $product_types_query);

// Get interest types for dropdown
$interest_types_query = "SELECT id, interest_type, rate, daily_monthly, fixed_dynamic, description 
                         FROM interest_settings WHERE is_active = 1 ORDER BY interest_type";
$interest_types_result = mysqli_query($conn, $interest_types_query);

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale') ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Get karat details for dropdown
$karat_query = "SELECT id, karat, max_value_per_gram, loan_value_per_gram FROM karat_details WHERE status = 1 ORDER BY karat";
$karat_result = mysqli_query($conn, $karat_query);

// Get defect details for dropdown
$defect_query = "SELECT id, defect_name FROM defect_details ORDER BY defect_name";
$defect_result = mysqli_query($conn, $defect_query);

// Get stone details for dropdown
$stone_query = "SELECT id, stone_name FROM stone_details ORDER BY stone_name";
$stone_result = mysqli_query($conn, $stone_query);

// Get jewel names for dropdown
$jewel_names_query = "SELECT id, product_type, jewel_name FROM product_names WHERE status = 1 ORDER BY jewel_name";
$jewel_names_result = mysqli_query($conn, $jewel_names_query);

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

// If loan ID is provided, load the original loan details
if ($loan_id > 0) {
    $loan_query = "SELECT l.*, 
                   c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
                   c.mobile_number, c.whatsapp_number, c.email,
                   c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
                   c.location, c.district, c.pincode, c.post, c.taluk,
                   c.aadhaar_number, c.customer_photo, c.loan_limit_amount,
                   (SELECT COALESCE(SUM(loan_amount), 0) FROM loans WHERE customer_id = c.id AND status = 'open' AND id != l.id) as total_active_loans_excluding_current,
                   (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as total_principal_paid,
                   (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as total_interest_paid,
                   (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count
                   FROM loans l 
                   JOIN customers c ON l.customer_id = c.id 
                   WHERE l.id = ?";
    
    $stmt = mysqli_prepare($conn, $loan_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $loan_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($loan_result) > 0) {
        $original_loan = mysqli_fetch_assoc($loan_result);
        
        $total_paid_principal = $original_loan['total_principal_paid'] ?? 0;
        $total_paid_interest = $original_loan['total_interest_paid'] ?? 0;
        $remaining_principal = $original_loan['loan_amount'] - $total_paid_principal;
        $total_paid = $total_paid_principal + $total_paid_interest;
        
        $customer = [
            'id' => $original_loan['customer_id'],
            'name' => $original_loan['customer_name'],
            'guardian' => $original_loan['guardian_name'],
            'guardian_type' => $original_loan['guardian_type'],
            'mobile' => $original_loan['mobile_number'],
            'whatsapp' => $original_loan['whatsapp_number'],
            'email' => $original_loan['email'],
            'address' => trim($original_loan['door_no'] . ' ' . $original_loan['street_name'] . ', ' . $original_loan['location'] . ', ' . $original_loan['district'] . ' - ' . $original_loan['pincode']),
            'photo' => $original_loan['customer_photo'],
            'loan_limit' => $original_loan['loan_limit_amount'],
            'active_loans' => ($original_loan['total_active_loans_excluding_current'] ?? 0) + ($original_loan['status'] == 'open' ? $original_loan['loan_amount'] : 0)
        ];
        
        $items_query = "SELECT * FROM loan_items WHERE loan_id = ?";
        $stmt = mysqli_prepare($conn, $items_query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $items_result = mysqli_stmt_get_result($stmt);
        
        while ($item = mysqli_fetch_assoc($items_result)) {
            $items[] = $item;
        }
        
        $value_query = "SELECT * FROM product_value_settings WHERE product_type = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $value_query);
        mysqli_stmt_bind_param($stmt, 's', $original_loan['product_type']);
        mysqli_stmt_execute($stmt);
        $value_result = mysqli_stmt_get_result($stmt);
        $value_settings = mysqli_fetch_assoc($value_result);
        
        if ($value_settings) {
            $current_value_per_gram = $value_settings['total_value_per_gram'];
            $current_loan_percentage = $value_settings['percentage'];
        } else {
            $current_value_per_gram = 10000;
            $current_loan_percentage = 75;
        }
        
        $total_net_weight = 0;
        foreach ($items as &$item) {
            $total_net_weight += $item['net_weight'] * $item['quantity'];
            $item['current_value_per_gram'] = $current_value_per_gram;
            $item['new_product_value'] = $item['net_weight'] * $current_value_per_gram;
            $item['new_loan_amount'] = ($item['net_weight'] * $current_value_per_gram * $current_loan_percentage) / 100;
        }
        
        $new_product_value = $total_net_weight * $current_value_per_gram;
        $calculated_loan_amount = ($new_product_value * $current_loan_percentage) / 100;
        $new_max_value = $total_net_weight * $current_value_per_gram;
        $value_increase = $new_product_value - $original_loan['product_value'];
        $percentage_increase = $original_loan['product_value'] > 0 ? round(($value_increase / $original_loan['product_value']) * 100, 1) : 0;
        
        $active_loans_total = $customer['active_loans'];
        if ($original_loan['status'] == 'open') {
            $remaining_limit = $customer['loan_limit'] - $active_loans_total;
        } else {
            $remaining_limit = $customer['loan_limit'] - $active_loans_total;
        }
        
    } else {
        $error = "Loan not found with ID: " . $loan_id;
    }
}

// Generate new receipt number
$today = date('Y-m-d');
$receipt_query = "SELECT COUNT(*) as count FROM loans WHERE DATE(created_at) = CURDATE()";
$receipt_result = mysqli_query($conn, $receipt_query);
$receipt_count = 1;
if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
    $receipt_data = mysqli_fetch_assoc($receipt_result);
    $receipt_count = $receipt_data['count'] + 1;
}
$new_receipt_number = 'R' . date('ymd') . str_pad($receipt_count, 3, '0', STR_PAD_LEFT);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_reloan') {
    
    $original_loan_id = intval($_POST['original_loan_id'] ?? 0);
    $receipt_date_display = $_POST['receipt_date'] ?? date('d/m/Y');
    
    $date_parts = explode('/', $receipt_date_display);
    if (count($date_parts) == 3) {
        $receipt_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
    } else {
        $receipt_date = date('Y-m-d');
    }
    
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $product_type = mysqli_real_escape_string($conn, $_POST['product_type'] ?? '');
    $loan_amount = floatval($_POST['loan_amount'] ?? 0);
    $extra_amount = floatval($_POST['extra_amount'] ?? 0);
    $interest_type = mysqli_real_escape_string($conn, $_POST['interest_type'] ?? '');
    $interest_rate = floatval($_POST['interest_rate'] ?? 0);
    $process_charge = floatval($_POST['process_charge'] ?? 0);
    $appraisal_charge = floatval($_POST['appraisal_charge'] ?? 0);
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $product_value = floatval($_POST['product_value'] ?? 0);
    $max_value = floatval($_POST['max_value'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $interest_calculation = mysqli_real_escape_string($conn, $_POST['interest_calculation'] ?? 'daily');
    
    $bank_id = isset($_POST['bank_id']) ? intval($_POST['bank_id']) : 0;
    $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
    $transaction_ref = mysqli_real_escape_string($conn, $_POST['transaction_ref'] ?? '');
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date'] ?? date('Y-m-d'));
    
    $gross_weight = floatval($_POST['gross_weight'] ?? 0);
    $net_weight = floatval($_POST['net_weight'] ?? 0);
    
    $errors = [];
    if (empty($customer_id)) $errors[] = "Please select a customer";
    if (empty($product_type)) $errors[] = "Please select product type";
    if (empty($interest_type)) $errors[] = "Please select interest type";
    if (empty($employee_id)) $errors[] = "Please select employee";
    if ($gross_weight <= 0) $errors[] = "Gross weight must be greater than 0";
    if ($net_weight <= 0) $errors[] = "Net weight must be greater than 0";
    if ($loan_amount <= 0) $errors[] = "Loan amount must be greater than 0";
    
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
                $current_used = $limit_data['total_loans_taken'];
                $total_after_reloan = $current_used + $loan_amount;
                
                if ($total_after_reloan > $limit_data['loan_limit_amount']) {
                    $errors[] = "New loan amount ₹" . number_format($loan_amount, 2) . " would make total ₹" . number_format($total_after_reloan, 2) . 
                                " which exceeds customer limit of ₹" . number_format($limit_data['loan_limit_amount'], 2);
                }
            }
        }
    }
    
    if ($payment_method === 'bank' && $bank_account_id > 0 && $loan_amount > 0) {
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
        mysqli_begin_transaction($conn);
        
        try {
            if ($original_loan['status'] == 'open') {
                $update_original = "UPDATE loans SET reloan = 1 WHERE id = ?";
            } else {
                $update_original = "UPDATE loans SET reloan = 1, status = 'reloan' WHERE id = ?";
            }
            
            $update_stmt = mysqli_prepare($conn, $update_original);
            mysqli_stmt_bind_param($update_stmt, 'i', $original_loan_id);
            mysqli_stmt_execute($update_stmt);
            
            $check_columns = mysqli_query($conn, "SHOW COLUMNS FROM loans LIKE 'process_charge'");
            $has_process_charge = mysqli_num_rows($check_columns) > 0;
            
            $check_appraisal = mysqli_query($conn, "SHOW COLUMNS FROM loans LIKE 'appraisal_charge'");
            $has_appraisal_charge = mysqli_num_rows($check_appraisal) > 0;
            
            if ($has_process_charge && $has_appraisal_charge) {
                $insert_loan = "INSERT INTO loans (
                    receipt_number, receipt_date, customer_id, gross_weight, net_weight, 
                    product_value, loan_amount, interest_type, interest_amount, 
                    process_charge, appraisal_charge, employee_id, status, reloan,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, NOW(), NOW())";
                
                $stmt = mysqli_prepare($conn, $insert_loan);
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssiidddsdddi',
                    $new_receipt_number,
                    $receipt_date,
                    $customer_id,
                    $gross_weight,
                    $net_weight,
                    $product_value,
                    $loan_amount,
                    $interest_type,
                    $interest_rate,
                    $process_charge,
                    $appraisal_charge,
                    $employee_id
                );
            } else {
                $insert_loan = "INSERT INTO loans (
                    receipt_number, receipt_date, customer_id, gross_weight, net_weight, 
                    product_value, loan_amount, interest_type, interest_amount, 
                    employee_id, status, reloan, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, NOW(), NOW())";
                
                $stmt = mysqli_prepare($conn, $insert_loan);
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssiiddddsdi',
                    $new_receipt_number,
                    $receipt_date,
                    $customer_id,
                    $gross_weight,
                    $net_weight,
                    $product_value,
                    $loan_amount,
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

            $new_loan_id = mysqli_insert_id($conn);
            
            if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
                $check_item_column = mysqli_query($conn, "SHOW COLUMNS FROM loan_items LIKE 'gross_weight'");
                $has_gross_weight = mysqli_num_rows($check_item_column) > 0;
                
                for ($i = 0; $i < count($_POST['item_id']); $i++) {
                    $original_item_id = intval($_POST['item_id'][$i]);
                    $jewel_name = mysqli_real_escape_string($conn, $_POST['jewel_name'][$i] ?? '');
                    $karat = floatval($_POST['karat'][$i] ?? 0);
                    $defect = mysqli_real_escape_string($conn, $_POST['defect'][$i] ?? '');
                    $stone = mysqli_real_escape_string($conn, $_POST['stone'][$i] ?? '');
                    $item_gross_weight = floatval($_POST['item_gross_weight'][$i] ?? 0);
                    $item_net_weight = floatval($_POST['item_net_weight'][$i] ?? 0);
                    $quantity = intval($_POST['quantity'][$i] ?? 1);
                    
                    $jewel_photo = null;
                    
                    $camera_field = 'jewel_photo_camera_' . $i;
                    if (isset($_POST[$camera_field]) && !empty($_POST[$camera_field])) {
                        $jewel_photo = saveBase64Image($_POST[$camera_field], "uploads/loan_items/$new_loan_id/", 'jewel_' . ($i + 1));
                    }
                    
                    $file_field = 'jewel_photo_' . $i;
                    if (isset($_FILES[$file_field]) && $_FILES[$file_field]['error'] == 0) {
                        $upload_dir = "uploads/loan_items/$new_loan_id/";
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
                    
                    if (!$jewel_photo && $original_item_id > 0) {
                        $orig_photo_query = "SELECT photo_path FROM loan_items WHERE id = ?";
                        $orig_stmt = mysqli_prepare($conn, $orig_photo_query);
                        mysqli_stmt_bind_param($orig_stmt, 'i', $original_item_id);
                        mysqli_stmt_execute($orig_stmt);
                        $orig_result = mysqli_stmt_get_result($orig_stmt);
                        $orig_item = mysqli_fetch_assoc($orig_result);
                        
                        if ($orig_item && !empty($orig_item['photo_path']) && file_exists($orig_item['photo_path'])) {
                            $upload_dir = "uploads/loan_items/$new_loan_id/";
                            if (!file_exists($upload_dir)) {
                                mkdir($upload_dir, 0777, true);
                            }
                            
                            $ext = pathinfo($orig_item['photo_path'], PATHINFO_EXTENSION);
                            $filename = 'jewel_' . ($i + 1) . '_' . time() . '.' . $ext;
                            $new_filepath = $upload_dir . $filename;
                            
                            if (copy($orig_item['photo_path'], $new_filepath)) {
                                $jewel_photo = $new_filepath;
                            }
                        }
                    }
                    
                    if ($has_gross_weight) {
                        $insert_item = "INSERT INTO loan_items (
                            loan_id, jewel_name, karat, defect_details, stone_details, 
                            gross_weight, net_weight, quantity, photo_path
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $item_stmt = mysqli_prepare($conn, $insert_item);
                        mysqli_stmt_bind_param(
                            $item_stmt, 
                            'isdssddds', 
                            $new_loan_id, 
                            $jewel_name, 
                            $karat, 
                            $defect, 
                            $stone, 
                            $item_gross_weight, 
                            $item_net_weight, 
                            $quantity,
                            $jewel_photo
                        );
                    } else {
                        $insert_item = "INSERT INTO loan_items (
                            loan_id, jewel_name, karat, defect_details, stone_details, 
                            net_weight, quantity, photo_path
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $item_stmt = mysqli_prepare($conn, $insert_item);
                        mysqli_stmt_bind_param(
                            $item_stmt, 
                            'isdssdds', 
                            $new_loan_id, 
                            $jewel_name, 
                            $karat, 
                            $defect, 
                            $stone, 
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
            
            if ($payment_method === 'bank' && $bank_account_id > 0 && $loan_amount > 0) {
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
                    throw new Exception("Insufficient bank balance! Available: ₹" . number_format($current_balance, 2));
                }
                
                $new_balance = $current_balance - $loan_amount;
                
                $insert_ledger = "INSERT INTO bank_ledger (
                    entry_date, bank_id, bank_account_id, transaction_type, 
                    amount, balance, reference_number, description, 
                    loan_id, created_by, created_at
                ) VALUES (?, ?, ?, 'debit', ?, ?, ?, ?, ?, ?, NOW())";
                
                $ledger_stmt = mysqli_prepare($conn, $insert_ledger);
                $description = "Reloan disbursement - Receipt #: " . $new_receipt_number . " (Original: " . $original_loan['receipt_number'] . ")";
                
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
                    $new_loan_id,
                    $_SESSION['user_id']
                );
                
                if (!mysqli_stmt_execute($ledger_stmt)) {
                    throw new Exception("Error updating bank ledger: " . mysqli_stmt_error($ledger_stmt));
                }
            }
            
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'create', ?, 'loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            
            if (!$log_stmt) {
                throw new Exception("Error preparing log statement: " . mysqli_error($conn));
            }
            
            $log_description = "Reloan created: " . $new_receipt_number . " from original loan: " . $original_loan['receipt_number'] . " (Original status: " . $original_loan['status'] . ")";
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $new_loan_id);
            
            if (!mysqli_stmt_execute($log_stmt)) {
                throw new Exception("Error executing log statement: " . mysqli_stmt_error($log_stmt));
            }
            
            mysqli_commit($conn);
            
            $email_sent = false;
            
            if (isset($_POST['send_email']) && $_POST['send_email'] == '1') {
                if (file_exists('includes/email_helper.php')) {
                    require_once 'includes/email_helper.php';
                    if (function_exists('sendLoanEmail')) {
                        $email_sent = sendLoanEmail($new_loan_id, $conn);
                    }
                }
            }
            
            $redirect_url = 'view-loan.php?id=' . $new_loan_id . '&success=reloan_created';
            if ($email_sent) {
                $redirect_url .= '&email=sent';
            }
            header('Location: ' . $redirect_url);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error creating reloan: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

function saveBase64Image($base64_data, $upload_dir, $filename_prefix) {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $type)) {
        $image_data = substr($base64_data, strpos($base64_data, ',') + 1);
        $type = strtolower($type[1]);
        
        if (in_array($type, ['jpg', 'jpeg', 'png'])) {
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

$recent_reloans_query = "SELECT l.*, c.customer_name, c.mobile_number,
                         DATEDIFF(NOW(), l.receipt_date) as days_old,
                         l.created_at
                         FROM loans l 
                         JOIN customers c ON l.customer_id = c.id 
                         WHERE l.reloan = 1
                         ORDER BY l.created_at DESC LIMIT 10";
$recent_reloans_result = mysqli_query($conn, $recent_reloans_query);

// Helper function to safely escape output
function safeEscape($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

        .reloan-container {
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

        .reloan-badge {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 15px;
        }

        .status-badge-large {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .status-open {
            background: #48bb78;
            color: white;
        }

        .status-closed {
            background: #a0aec0;
            color: white;
        }

        .status-reloan {
            background: #9f7aea;
            color: white;
        }

        .search-section {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
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

        .search-results-table {
            margin-top: 25px;
            width: 100%;
            overflow-x: auto;
        }

        .search-results-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .search-results-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .search-results-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .search-results-table tr:hover {
            background: #f7fafc;
            cursor: pointer;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-open {
            background: #48bb7820;
            color: #276749;
        }

        .status-closed {
            background: #a0aec020;
            color: #4a5568;
        }

        .status-reloan {
            background: #9f7aea20;
            color: #553c9a;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .original-loan-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .original-loan-title {
            font-size: 16px;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .info-item {
            background: white;
            padding: 12px;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .remaining-amount-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .remaining-amount-label {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .remaining-amount-value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }

        .remaining-amount-note {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }

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

        .loan-limit-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 15px;
            margin: 15px 0;
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

        .loan-amount-section {
            background: linear-gradient(135deg, #48bb7810 0%, #4299e110 100%);
            border: 2px solid #48bb78;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .loan-amount-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loan-amount-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .calculated-amount {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
            flex: 1;
        }

        .calculated-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
        }

        .calculated-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .extra-amount-input {
            flex: 1;
        }

        .extra-amount-input input {
            width: 100%;
            padding: 12px;
            border: 2px solid #48bb78;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
        }

        .final-amount-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-top: 15px;
        }

        .final-amount-box .final-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .final-amount-box .final-value {
            font-size: 36px;
            font-weight: 700;
        }

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

        .original-value {
            font-size: 11px;
            color: #718096;
            margin-top: 2px;
        }

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

        .camera-btn-switch {
            background: #9f7aea;
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

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

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

        .reloan-badge-small {
            background: #9f7aea;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 8px;
        }

        @media (max-width: 1200px) {
            .form-grid, .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .form-grid, .form-grid-2, .form-grid-3, .info-grid {
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

            .search-box {
                flex-direction: column;
                align-items: stretch;
            }

            .loan-amount-row {
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
                <div class="reloan-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-arrow-repeat"></i>
                            Reloan Process
                            <span class="reloan-badge">New Loan on Existing Items</span>
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
                            <?php echo safeEscape($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo safeEscape($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$original_loan): ?>
                        <!-- Search Section -->
                        <div class="search-section">
                            <div class="search-title">
                                <i class="bi bi-search"></i>
                                Search for Loan to Reloan
                            </div>
                            
                            <form method="GET" action="" class="search-box">
                                <div class="form-group">
                                    <label class="form-label required">Search by Receipt Number, Customer Name, or Mobile Number</label>
                                    <div class="input-group">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" name="search" 
                                               value="<?php echo safeEscape($search_term ?? ''); ?>" 
                                               placeholder="e.g., 260312001 or Raj or 9876543210" required>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </form>

                            <?php if ($search_results && mysqli_num_rows($search_results) > 0): ?>
                                <div class="search-results-table">
                                    <h4 style="margin: 20px 0 15px; color: #2d3748;">Search Results</h4>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Receipt No</th>
                                                <th>Customer</th>
                                                <th>Mobile</th>
                                                <th>Original Amount</th>
                                                <th>Date</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($loan = mysqli_fetch_assoc($search_results)): 
                                                $total_paid = ($loan['total_principal_paid'] ?? 0) + ($loan['total_interest_paid'] ?? 0);
                                                $remaining = $loan['loan_amount'] - ($loan['total_principal_paid'] ?? 0);
                                                $status_class = '';
                                                $status_text = ucfirst($loan['status']);
                                                
                                                if ($loan['status'] == 'open') $status_class = 'status-open';
                                                elseif ($loan['status'] == 'closed') $status_class = 'status-closed';
                                                else $status_class = 'status-reloan';
                                            ?>
                                            <tr onclick="window.location.href='?select=<?php echo (int)$loan['id']; ?>'">
                                                <td><strong><?php echo safeEscape($loan['receipt_number']); ?></strong></td>
                                                <td><?php echo safeEscape($loan['customer_name']); ?></td>
                                                <td><?php echo safeEscape($loan['mobile_number']); ?></td>
                                                <td>₹ <?php echo number_format((float)$loan['loan_amount'], 2); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($loan['receipt_date'])); ?></td>
                                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                                <td>
                                                    <a href="?select=<?php echo (int)$loan['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="bi bi-arrow-repeat"></i> Select
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php elseif ($search_term && mysqli_num_rows($search_results) == 0): ?>
                                <div class="alert alert-warning mt-3">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    No loans found matching "<?php echo safeEscape($search_term); ?>"
                                </div>
                            <?php endif; ?>

                            <!-- Recent Loans (Open and Closed) -->
                            <div style="margin-top: 30px;">
                                <h4 style="margin-bottom: 15px; color: #2d3748;">Recent Loans</h4>
                                <div class="search-results-table">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Receipt No</th>
                                                <th>Customer</th>
                                                <th>Mobile</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $recent_loans_query = "SELECT l.*, c.customer_name, c.mobile_number
                                                                   FROM loans l 
                                                                   JOIN customers c ON l.customer_id = c.id 
                                                                   WHERE l.status IN ('open', 'closed')
                                                                   ORDER BY l.created_at DESC LIMIT 10";
                                            $recent_result = mysqli_query($conn, $recent_loans_query);
                                            while($recent = mysqli_fetch_assoc($recent_result)):
                                                $status_class = $recent['status'] == 'open' ? 'status-open' : 'status-closed';
                                            ?>
                                            <tr>
                                                <td><strong><?php echo safeEscape($recent['receipt_number']); ?></strong></td>
                                                <td><?php echo safeEscape($recent['customer_name']); ?></td>
                                                <td><?php echo safeEscape($recent['mobile_number']); ?></td>
                                                <td>₹ <?php echo number_format((float)$recent['loan_amount'], 2); ?></td>
                                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo ucfirst($recent['status']); ?></span></td>
                                                <td>
                                                    <a href="?select=<?php echo (int)$recent['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="bi bi-arrow-repeat"></i> Reloan
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <!-- Original Loan Information -->
                        <div class="original-loan-card">
                            <div class="original-loan-title">
                                <i class="bi bi-file-text"></i>
                                Original Loan Details (Receipt: <?php echo safeEscape($original_loan['receipt_number']); ?>)
                                <span class="status-badge-large <?php echo $original_loan['status'] == 'open' ? 'status-open' : ($original_loan['status'] == 'closed' ? 'status-closed' : 'status-reloan'); ?>">
                                    <?php echo ucfirst($original_loan['status']); ?>
                                </span>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Original Date</div>
                                    <div class="info-value"><?php echo date('d/m/Y', strtotime($original_loan['receipt_date'])); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Original Amount</div>
                                    <div class="info-value">₹ <?php echo number_format((float)$original_loan['loan_amount'], 2); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Interest Rate</div>
                                    <div class="info-value"><?php echo safeEscape($original_loan['interest_amount']); ?>%</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Net Weight</div>
                                    <div class="info-value"><?php echo safeEscape($original_loan['net_weight']); ?> g</div>
                                </div>
                            </div>

                            <?php if (($original_loan['payment_count'] ?? 0) > 0): ?>
                            <div class="remaining-amount-box">
                                <div class="remaining-amount-label">Amount Already Paid</div>
                                <div class="remaining-amount-value" style="color: #48bb78;">₹ <?php echo number_format($total_paid, 2); ?></div>
                                <div class="remaining-amount-note">
                                    Principal Paid: ₹ <?php echo number_format($total_paid_principal, 2); ?> | 
                                    Interest Paid: ₹ <?php echo number_format($total_paid_interest, 2); ?>
                                </div>
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #e2e8f0;">
                                    <div class="remaining-amount-label">Remaining Principal</div>
                                    <div class="remaining-amount-value" style="color: #f56565;">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-graph-up-arrow"></i>
                                <strong>Current Market Value:</strong> 
                                Product value has changed from ₹<?php echo number_format((float)$original_loan['product_value'], 2); ?> 
                                to <strong class="text-success">₹<?php echo number_format($new_product_value, 2); ?></strong> 
                                (<?php echo $percentage_increase; ?>% change)
                            </div>
                            
                            <?php if ($original_loan['status'] == 'open'): ?>
                            <div class="alert alert-warning mt-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Note:</strong> This loan is currently <strong>ACTIVE</strong>. Creating a reloan will add a NEW loan on top of the existing one. The original loan will remain active until fully paid.
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Customer Information -->
                        <div class="form-card">
                            <div class="section-title">
                                <i class="bi bi-person"></i>
                                Customer Information
                            </div>

                            <div class="customer-info-card">
                                <?php if (!empty($customer['photo'])): ?>
                                    <img src="<?php echo safeEscape($customer['photo']); ?>" class="customer-photo-small" alt="Customer Photo">
                                <?php else: ?>
                                    <div class="customer-photo-small" style="background: #f7fafc; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-person" style="font-size: 32px; color: #a0aec0;"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="customer-details">
                                    <div class="customer-name">
                                        <?php echo safeEscape($customer['name']); ?>
                                        <?php if (!empty($original_loan['d_namuna'])): ?>
                                            <span class="badge bg-warning">D-NAMUNA</span>
                                        <?php endif; ?>
                                        <?php if (!empty($original_loan['others'])): ?>
                                            <span class="badge bg-info">OTHERS</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="customer-contact">
                                        <span><i class="bi bi-phone"></i> <?php echo safeEscape($customer['mobile']); ?></span>
                                        <?php if (!empty($customer['email'])): ?>
                                            <span><i class="bi bi-envelope"></i> <?php echo safeEscape($customer['email']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="customer-address">
                                        <i class="bi bi-geo-alt"></i> <?php echo safeEscape($customer['address']); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Loan Limit Card -->
                            <div class="loan-limit-card">
                                <div class="loan-limit-title">
                                    <i class="bi bi-pie-chart-fill"></i> Loan Limit Status
                                </div>
                                <div class="loan-limit-amount">₹ <?php echo number_format($remaining_limit, 2); ?></div>
                                <div class="loan-limit-progress">
                                    <?php 
                                    $percentage_used = $customer['loan_limit'] > 0 ? ($customer['active_loans'] / $customer['loan_limit']) * 100 : 0;
                                    ?>
                                    <div class="loan-limit-progress-bar" style="width: <?php echo $percentage_used; ?>%"></div>
                                </div>
                                <div class="loan-limit-details">
                                    <span>Total Limit: ₹ <?php echo number_format((float)$customer['loan_limit'], 2); ?></span>
                                    <span>Currently Used: ₹ <?php echo number_format((float)$customer['active_loans'], 2); ?></span>
                                    <span class="<?php echo ($remaining_limit >= $calculated_loan_amount) ? 'loan-limit-success' : 'loan-limit-warning'; ?>">
                                        Available: ₹ <?php echo number_format($remaining_limit, 2); ?>
                                    </span>
                                </div>
                                <div class="loan-limit-details mt-2">
                                    <span>New Loan Amount:</span>
                                    <span class="loan-limit-warning">₹ <?php echo number_format($calculated_loan_amount, 2); ?></span>
                                </div>
                                <div class="loan-limit-details">
                                    <span>Total After New Loan:</span>
                                    <span class="<?php echo (($customer['active_loans'] + $calculated_loan_amount) <= $customer['loan_limit']) ? 'loan-limit-success' : 'loan-limit-warning'; ?>">
                                        ₹ <?php echo number_format($customer['active_loans'] + $calculated_loan_amount, 2); ?>
                                    </span>
                                </div>
                                <?php if ($remaining_limit < $calculated_loan_amount): ?>
                                    <div class="loan-limit-warning mt-2">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        Calculated loan amount (₹<?php echo number_format($calculated_loan_amount, 2); ?>) exceeds available limit!
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Main Reloan Form -->
                        <form method="POST" action="" enctype="multipart/form-data" id="reloanForm">
                            <input type="hidden" name="action" value="create_reloan">
                            <input type="hidden" name="original_loan_id" value="<?php echo (int)$loan_id; ?>">
                            <input type="hidden" name="customer_id" value="<?php echo (int)$customer['id']; ?>">
                            
                            <!-- Basic Information -->
                            <div class="form-card">
                                <div class="section-title">
                                    <i class="bi bi-info-circle"></i>
                                    New Loan Information
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">New Receipt Number</label>
                                        <div class="input-group">
                                            <i class="bi bi-receipt input-icon"></i>
                                            <input type="text" class="form-control readonly-field" value="<?php echo safeEscape($new_receipt_number); ?>" readonly>
                                        </div>
                                        <small class="form-text">Auto-generated</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Receipt Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar input-icon"></i>
                                            <input type="text" class="form-control datepicker" name="receipt_date" value="<?php echo date('d/m/Y'); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Product Type</label>
                                        <div class="input-group">
                                            <i class="bi bi-tag input-icon"></i>
                                            <input type="text" class="form-control readonly-field" value="<?php echo safeEscape($original_loan['product_type']); ?>" readonly>
                                            <input type="hidden" name="product_type" value="<?php echo safeEscape($original_loan['product_type']); ?>">
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
                                                    <option value="<?php echo safeEscape($it['interest_type']); ?>" data-rate="<?php echo safeEscape($it['rate']); ?>"
                                                        <?php echo ($it['interest_type'] == $original_loan['interest_type']) ? 'selected' : ''; ?>>
                                                        <?php echo safeEscape($it['interest_type'] . ' (' . $it['rate'] . '%)'); ?>
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
                                            <input type="number" class="form-control" name="interest_rate" id="interest_rate" step="0.01" min="0" value="<?php echo safeEscape($original_loan['interest_amount']); ?>" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Current Value/Gram (₹)</label>
                                        <div class="input-group">
                                            <i class="bi bi-calculator input-icon"></i>
                                            <input type="number" class="form-control readonly-field" value="<?php echo number_format((float)$current_value_per_gram, 2); ?>" readonly>
                                        </div>
                                        <small class="form-text">Based on current market rate</small>
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
                                                    <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                                        <?php echo safeEscape($emp['name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Interest Calculation Options -->
                                <div class="interest-calculation-options">
                                    <div class="interest-option">
                                        <input type="radio" name="interest_calculation" id="interest_daily" value="daily" checked>
                                        <label for="interest_daily">Daily Interest</label>
                                    </div>
                                    <div class="interest-option">
                                        <input type="radio" name="interest_calculation" id="interest_monthly" value="monthly">
                                        <label for="interest_monthly">Monthly Interest</label>
                                    </div>
                                    <div class="interest-option">
                                        <input type="radio" name="interest_calculation" id="interest_without" value="without">
                                        <label for="interest_without">Without Interest</label>
                                    </div>
                                </div>

                                <!-- New Loan Amount Section - Editable -->
                                <div class="loan-amount-section">
                                    <div class="loan-amount-title">
                                        <i class="bi bi-cash-coin"></i> New Loan Amount
                                    </div>
                                    
                                    <div class="loan-amount-row">
                                        <div class="calculated-amount">
                                            <div class="calculated-label">Calculated Amount (Based on <?php echo (int)$current_loan_percentage; ?>% of value)</div>
                                            <div class="calculated-value" id="calculatedAmount">₹ <?php echo number_format($calculated_loan_amount, 2); ?></div>
                                        </div>
                                        <div class="extra-amount-input">
                                            <label class="form-label">Add Extra Amount (₹)</label>
                                            <input type="number" name="extra_amount" id="extra_amount" class="form-control" 
                                                   value="0" step="0.01" min="0" onchange="updateFinalAmount()"
                                                   placeholder="Enter additional amount">
                                            <small class="form-text">Add extra amount on top of calculated value</small>
                                        </div>
                                    </div>
                                    
                                    <div class="final-amount-box">
                                        <div class="final-label">Final Loan Amount (Calculated + Extra)</div>
                                        <div class="final-value" id="finalAmount">₹ <?php echo number_format($calculated_loan_amount, 2); ?></div>
                                        <input type="hidden" name="loan_amount" id="loan_amount" value="<?php echo $calculated_loan_amount; ?>">
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
                                                    <option value="<?php echo (int)$bank['id']; ?>" data-balance="<?php echo (float)$bank['total_balance']; ?>">
                                                        <?php echo safeEscape($bank['bank_full_name']); ?>
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
                                                    <option value="<?php echo (int)$account['id']; ?>" 
                                                            data-bank-id="<?php echo (int)$account['bank_id']; ?>"
                                                            data-balance="<?php echo (float)$account['current_balance']; ?>"
                                                            data-account="<?php echo safeEscape($account['account_holder_no']); ?>">
                                                        <?php echo safeEscape($account['account_holder_no'] . ' - ' . $account['bank_account_no']); ?>
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
                                    </div>
                                </div>
                            </div>

                            <!-- Jewelry Items Table -->
                            <div class="form-card">
                                <div class="section-title">
                                    <i class="bi bi-grid-3x3-gap-fill"></i>
                                    Jewelry Items (Updated Values)
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
                                        </tr>
                                    </thead>
                                    <tbody id="jewelryBody">
                                        <?php foreach ($items as $index => $item): ?>
                                        <tr id="row<?php echo $index + 1; ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <input type="hidden" name="item_id[]" value="<?php echo (int)$item['id']; ?>">
                                                <select name="jewel_name[]" class="form-select">
                                                    <option value="">Select</option>
                                                    <?php 
                                                    if ($jewel_names_result && mysqli_num_rows($jewel_names_result) > 0) {
                                                        mysqli_data_seek($jewel_names_result, 0);
                                                        while($jewel = mysqli_fetch_assoc($jewel_names_result)): 
                                                    ?>
                                                        <option value="<?php echo safeEscape($jewel['jewel_name']); ?>"
                                                            <?php echo ($jewel['jewel_name'] == $item['jewel_name']) ? 'selected' : ''; ?>>
                                                            <?php echo safeEscape($jewel['jewel_name']); ?>
                                                        </option>
                                                    <?php 
                                                        endwhile;
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="karat[]" class="form-select" onchange="updateItemValues(this)">
                                                    <option value="">Select</option>
                                                    <?php 
                                                    if ($karat_result && mysqli_num_rows($karat_result) > 0) {
                                                        mysqli_data_seek($karat_result, 0);
                                                        while($karat = mysqli_fetch_assoc($karat_result)): 
                                                    ?>
                                                        <option value="<?php echo safeEscape($karat['karat']); ?>" 
                                                                data-max="<?php echo (float)$karat['max_value_per_gram']; ?>"
                                                                <?php echo ($karat['karat'] == $item['karat']) ? 'selected' : ''; ?>>
                                                            <?php echo safeEscape($karat['karat']); ?>K
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
                                                        <option value="<?php echo safeEscape($defect['defect_name']); ?>"
                                                            <?php echo ($defect['defect_name'] == $item['defect_details']) ? 'selected' : ''; ?>>
                                                            <?php echo safeEscape($defect['defect_name']); ?>
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
                                                        <option value="<?php echo safeEscape($stone['stone_name']); ?>"
                                                            <?php echo ($stone['stone_name'] == $item['stone_details']) ? 'selected' : ''; ?>>
                                                            <?php echo safeEscape($stone['stone_name']); ?>
                                                        </option>
                                                    <?php 
                                                        endwhile;
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="item_gross_weight[]" step="0.001" min="0" 
                                                       class="gross-weight" value="<?php echo (float)($item['gross_weight'] ?? 0); ?>"
                                                       onchange="calculateNetWeight(this); updateItemValues(this)">
                                            </td>
                                            <td>
                                                <input type="number" name="item_margin_weight[]" step="0.001" min="0" 
                                                       class="margin-weight" value="0"
                                                       onchange="calculateNetWeight(this); updateItemValues(this)">
                                            </td>
                                            <td>
                                                <input type="number" name="item_net_weight[]" step="0.001" min="0" 
                                                       class="net-weight" value="<?php echo (float)$item['net_weight']; ?>" readonly>
                                            </td>
                                            <td>
                                                <input type="number" name="quantity[]" value="<?php echo (int)$item['quantity']; ?>" 
                                                       min="1" onchange="updateTotalWeight()">
                                            </td>
                                            <td>
                                                <div class="jewelry-photo-section">
                                                    <div class="photo-btn-group">
                                                        <button type="button" onclick="openJewelCamera(this, <?php echo $index + 1; ?>)" 
                                                                class="camera-btn camera-btn-capture" title="Take Photo">
                                                            <i class="bi bi-camera"></i>
                                                        </button>
                                                        <label class="camera-btn camera-btn-upload" title="Upload Photo">
                                                            <i class="bi bi-cloud-upload"></i>
                                                            <input type="file" name="jewel_photo_<?php echo $index; ?>" accept="image/*" style="display: none;" 
                                                                   onchange="previewJewelPhoto(this, <?php echo $index + 1; ?>)">
                                                        </label>
                                                    </div>
                                                    <input type="hidden" id="jewel_photo_camera_<?php echo $index + 1; ?>" name="jewel_photo_camera_<?php echo $index; ?>">
                                                    <div class="photo-preview-container" id="photo_preview_<?php echo $index + 1; ?>" style="display: none;">
                                                        <img src="" class="jewel-photo-preview" alt="Preview">
                                                    </div>
                                                    <small class="photo-filename" id="jewel_photo_name_<?php echo $index + 1; ?>">
                                                        <?php if (!empty($item['photo_path'])): ?>
                                                            <i class="bi bi-check-circle-fill" style="color:#48bb78;"></i> Original photo available
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <!-- Weight Summary -->
                                <div class="summary-box">
                                    <h4 style="margin-bottom: 15px; color: #2d3748;">Weight & Value Summary</h4>
                                    <div class="summary-row">
                                        <span class="summary-label">Total Gross Weight:</span>
                                        <span class="summary-value" id="total_gross_weight"><?php echo number_format((float)$original_loan['gross_weight'], 3); ?> g</span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Total Margin Weight:</span>
                                        <span class="summary-value" id="total_margin_weight">0.000 g</span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Total Net Weight:</span>
                                        <span class="summary-value" id="total_net_weight"><?php echo number_format((float)$original_loan['net_weight'], 3); ?> g</span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Original Loan Amount:</span>
                                        <span class="summary-value" style="color: #718096;">₹ <?php echo number_format((float)$original_loan['loan_amount'], 2); ?></span>
                                    </div>
                                    <div class="summary-row" style="border-top: 2px solid #667eea30; margin-top: 10px; padding-top: 10px;">
                                        <span class="summary-label">New Product Value:</span>
                                        <span class="summary-value" style="color: #667eea;">₹ <?php echo number_format($new_product_value, 2); ?></span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Loan Percentage:</span>
                                        <span class="summary-value"><?php echo (int)$current_loan_percentage; ?>%</span>
                                    </div>
                                </div>

                                <!-- Hidden fields for totals -->
                                <input type="hidden" name="gross_weight" id="gross_weight" value="<?php echo (float)$original_loan['gross_weight']; ?>">
                                <input type="hidden" name="net_weight" id="net_weight" value="<?php echo (float)$original_loan['net_weight']; ?>">
                                <input type="hidden" name="product_value" id="product_value" value="<?php echo (float)$new_product_value; ?>">
                                <input type="hidden" name="max_value" id="max_value" value="<?php echo (float)$new_max_value; ?>">
                                
                                <div class="form-grid" style="margin-top: 20px;">
                                    <div class="form-group">
                                        <label class="form-label">Process Charge (₹)</label>
                                        <input type="number" class="form-control" name="process_charge" id="process_charge" value="0.00" step="0.01" min="0">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Appraisal Charge (₹)</label>
                                        <input type="number" class="form-control" name="appraisal_charge" id="appraisal_charge" value="0.00" step="0.01" min="0">
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
                                        An email will be sent to the customer with new loan details
                                    </small>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="action-buttons">
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="bi bi-check-circle"></i> Create Reloan & Send Email
                                </button>
                                <button type="button" class="btn btn-danger" onclick="window.location.href='loans.php'">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <!-- Recent Reloans -->
                    <?php if ($recent_reloans_result && mysqli_num_rows($recent_reloans_result) > 0): ?>
                    <div class="recent-loans">
                        <h3>Recent Reloans</h3>
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
                                    <?php while($reloan = mysqli_fetch_assoc($recent_reloans_result)): ?>
                                    <tr onclick="window.location.href='view-loan.php?id=<?php echo (int)$reloan['id']; ?>'">
                                        <td>
                                            <?php echo safeEscape($reloan['receipt_number']); ?>
                                            <span class="reloan-badge-small">Reloan</span>
                                        </td>
                                        <td><?php echo safeEscape($reloan['customer_name']); ?></td>
                                        <td>₹ <?php echo number_format((float)$reloan['loan_amount'], 2); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($reloan['created_at'])); ?></td>
                                        <td><?php echo (int)$reloan['days_old']; ?> days</td>
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

        let currentBankBalance = 0;
        let rowCount = <?php echo isset($items) ? count($items) : 0; ?>;
        let currentJewelRow = null;
        let currentRowNum = null;
        let cameraStream = null;
        let currentFacingMode = 'environment';
        let calculatedLoanAmount = <?php echo (float)($calculated_loan_amount ?? 0); ?>;

        // ========== LOAN AMOUNT FUNCTIONS ==========
        function updateFinalAmount() {
            const extraAmount = parseFloat(document.getElementById('extra_amount').value) || 0;
            const finalAmount = calculatedLoanAmount + extraAmount;
            
            document.getElementById('finalAmount').textContent = '₹ ' + finalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('loan_amount').value = finalAmount.toFixed(2);
            
            validateBankBalance();
        }

        // ========== VALUE CALCULATION FUNCTIONS ==========
        function updateItemValues(element) {
            updateTotalWeight();
        }

        function calculateNetWeight(element) {
            const row = element.closest('tr');
            const gross = parseFloat(row.querySelector('.gross-weight').value) || 0;
            const margin = parseFloat(row.querySelector('.margin-weight').value) || 0;
            const net = Math.max(0, gross - margin);
            row.querySelector('.net-weight').value = net.toFixed(3);
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
            
            const valuePerGram = <?php echo (float)($current_value_per_gram ?? 10000); ?>;
            const loanPercentage = <?php echo (float)($current_loan_percentage ?? 75); ?>;
            
            const newProductValue = totalNet * valuePerGram;
            const newCalculatedAmount = (newProductValue * loanPercentage) / 100;
            
            calculatedLoanAmount = newCalculatedAmount;
            
            document.getElementById('product_value').value = newProductValue.toFixed(2);
            document.getElementById('max_value').value = newProductValue.toFixed(2);
            document.getElementById('calculatedAmount').textContent = '₹ ' + newCalculatedAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            updateFinalAmount();
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
            
            const row = currentJewelRow;
            
            const hiddenInput = row.querySelector('input[type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = imageData;
            }
            
            const previewContainer = row.querySelector('.photo-preview-container');
            const previewImg = previewContainer.querySelector('img');
            previewImg.src = imageData;
            previewContainer.style.display = 'block';
            
            const fileInput = row.querySelector('input[type="file"]');
            if (fileInput) fileInput.value = '';
            
            const nameSpan = row.querySelector('.photo-filename');
            if (nameSpan) {
                nameSpan.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#48bb78;"></i> New photo captured';
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
                    const previewContainer = row.querySelector('.photo-preview-container');
                    const previewImg = previewContainer.querySelector('img');
                    previewImg.src = e.target.result;
                    previewContainer.style.display = 'block';
                    
                    const hiddenInput = row.querySelector('input[type="hidden"]');
                    if (hiddenInput) hiddenInput.value = '';
                    
                    const nameSpan = row.querySelector('.photo-filename');
                    if (nameSpan) {
                        nameSpan.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#48bb78;"></i> ' + input.files[0].name.substring(0, 15) + '...';
                    }
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Form submission validation
        document.getElementById('reloanForm')?.addEventListener('submit', function(e) {
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
            
            return Swal.fire({
                title: 'Create Reloan?',
                html: `You are creating a new loan of <strong>₹${loanAmount.toLocaleString()}</strong><br>
                       Original loan amount was ₹${<?php echo (float)($original_loan['loan_amount'] ?? 0); ?>}<br>
                       This will create a NEW loan while keeping the original loan active.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#f56565',
                confirmButtonText: 'Yes, create reloan',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (!result.isConfirmed) {
                    e.preventDefault();
                }
            });
        });

        // Auto-hide alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(alert => alert.style.display = 'none');
        }, 5000);

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadInterestRate();
            updateFinalAmount();
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>