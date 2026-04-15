<?php
session_start();
$currentPage = 'bank-loan';
$pageTitle = 'Bank Loan Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Use current filename everywhere to avoid case-sensitive path issues on Linux hosting
$selfPage = basename($_SERVER['PHP_SELF']);

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header('Location: index.php');
    exit();
}

// ============== AJAX HANDLER - MUST BE AT THE TOP BEFORE ANY HTML OUTPUT ==============
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_customer_items') {
    header('Content-Type: application/json');
    
    $customer_id = intval($_GET['customer_id'] ?? 0);
    
    if ($customer_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
        exit();
    }
    
    // Debug: Log the customer ID
    error_log("Fetching items for customer ID: " . $customer_id);
    
    // Get all open loans for this customer with their items
    $query = "SELECT l.id, l.receipt_number, l.receipt_date, l.loan_amount, l.status,
              li.id as item_id, li.jewel_name, li.karat, li.defect_details, 
              li.stone_details, li.net_weight, li.quantity, li.photo_path
              FROM loans l
              JOIN loan_items li ON l.id = li.loan_id
              WHERE l.customer_id = ? AND l.status = 'open'
              ORDER BY l.receipt_date DESC, li.id ASC";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("Database prepare error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        error_log("Query execution error: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
        exit();
    }
    
    $items = [];
    $item_count = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $item_count++;
        $items[] = [
            'loan_id' => $row['id'],
            'receipt_number' => $row['receipt_number'],
            'receipt_date' => date('d-m-Y', strtotime($row['receipt_date'])),
            'loan_amount' => floatval($row['loan_amount']),
            'item_id' => $row['item_id'],
            'jewel_name' => $row['jewel_name'],
            'karat' => floatval($row['karat']),
            'defect_details' => $row['defect_details'] ?? '',
            'stone_details' => $row['stone_details'] ?? '',
            'net_weight' => floatval($row['net_weight']),
            'quantity' => intval($row['quantity']),
            'photo_path' => $row['photo_path'] ?? ''
        ];
    }
    
    error_log("Found $item_count items for customer ID: " . $customer_id);
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'total_items' => count($items)
    ]);
    exit();
}


if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_loan_details') {
    header('Content-Type: application/json');

    $loan_id = intval($_GET['loan_id'] ?? 0);

    if ($loan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid loan ID']);
        exit();
    }

    $loan_sql = "SELECT bl.*, 
                        b.bank_short_name, b.bank_full_name,
                        ba.account_holder_no, ba.bank_account_no,
                        c.customer_name, c.mobile_number, c.aadhaar_number,
                        u.name as employee_name
                 FROM bank_loans bl
                 LEFT JOIN bank_master b ON bl.bank_id = b.id
                 LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                 LEFT JOIN customers c ON bl.customer_id = c.id
                 LEFT JOIN users u ON bl.employee_id = u.id
                 WHERE bl.id = ?
                 LIMIT 1";
    $loan_stmt = mysqli_prepare($conn, $loan_sql);
    if (!$loan_stmt) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    mysqli_stmt_bind_param($loan_stmt, 'i', $loan_id);
    mysqli_stmt_execute($loan_stmt);
    $loan_result = mysqli_stmt_get_result($loan_stmt);
    $loan = mysqli_fetch_assoc($loan_result);

    if (!$loan) {
        echo json_encode(['success' => false, 'message' => 'Loan details not found']);
        exit();
    }

    $items = [];
    $item_sql = "SELECT id, jewel_name, karat, defect_details, stone_details, net_weight, quantity, photo_path
                 FROM bank_loan_items
                 WHERE bank_loan_id = ?
                 ORDER BY id ASC";
    $item_stmt = mysqli_prepare($conn, $item_sql);
    if ($item_stmt) {
        mysqli_stmt_bind_param($item_stmt, 'i', $loan_id);
        mysqli_stmt_execute($item_stmt);
        $item_result = mysqli_stmt_get_result($item_stmt);
        while ($row = mysqli_fetch_assoc($item_result)) {
            $items[] = $row;
        }
    }

    $emis = [];
    $emi_sql = "SELECT installment_no, due_date, principal_amount, interest_amount, emi_amount, balance_amount, status, paid_date, paid_amount, payment_method, remarks
                FROM bank_loan_emi
                WHERE bank_loan_id = ?
                ORDER BY installment_no ASC";
    $emi_stmt = mysqli_prepare($conn, $emi_sql);
    if ($emi_stmt) {
        mysqli_stmt_bind_param($emi_stmt, 'i', $loan_id);
        mysqli_stmt_execute($emi_stmt);
        $emi_result = mysqli_stmt_get_result($emi_stmt);
        while ($row = mysqli_fetch_assoc($emi_result)) {
            $emis[] = $row;
        }
    }

    $payments = [];
    $payment_sql = "SELECT receipt_number, payment_date, payment_amount, payment_method, remarks, created_at
                    FROM bank_loan_payments
                    WHERE bank_loan_id = ?
                    ORDER BY id DESC";
    $payment_stmt = mysqli_prepare($conn, $payment_sql);
    if ($payment_stmt) {
        mysqli_stmt_bind_param($payment_stmt, 'i', $loan_id);
        mysqli_stmt_execute($payment_stmt);
        $payment_result = mysqli_stmt_get_result($payment_stmt);
        while ($row = mysqli_fetch_assoc($payment_result)) {
            $payments[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'loan' => $loan,
        'items' => $items,
        'emis' => $emis,
        'payments' => $payments
    ]);
    exit();
}

// ============== END AJAX HANDLER ==============

$message = '';
$error = '';

// Check for success messages - MODIFIED FOR SWEETALERT
$show_success_alert = false;
$success_message = '';
$success_reference = '';

if (isset($_GET['success'])) {
    $show_success_alert = true;
    switch ($_GET['success']) {
        case 'created':
            $success_message = "Bank Loan Created Successfully!";
            $success_reference = htmlspecialchars($_GET['ref'] ?? '');
            break;
        case 'payment_made':
            $success_message = "EMI Payment Processed Successfully!";
            $success_reference = htmlspecialchars($_GET['receipt'] ?? '');
            break;
    }
}

// Create necessary tables if they don't exist
$tables_created = false;

// Check and create bank_loans table
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_loans'");
if (mysqli_num_rows($table_check) == 0) {
    $create_loans = "CREATE TABLE IF NOT EXISTS `bank_loans` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `loan_reference` varchar(50) NOT NULL,
        `bank_id` int(11) NOT NULL,
        `bank_account_id` int(11) DEFAULT NULL,
        `customer_id` int(11) NOT NULL,
        `loan_date` date NOT NULL,
        `loan_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `interest_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
        `tenure_months` int(11) NOT NULL DEFAULT 0,
        `emi_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `total_interest` decimal(15,2) NOT NULL DEFAULT 0.00,
        `document_charge` decimal(10,2) DEFAULT 0.00,
        `processing_fee` decimal(10,2) DEFAULT 0.00,
        `total_payable` decimal(15,2) NOT NULL DEFAULT 0.00,
        `product_type` varchar(100) DEFAULT NULL,
        `employee_id` int(11) NOT NULL,
        `remarks` text,
        `status` enum('active','closed','defaulted') DEFAULT 'active',
        `close_date` date DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `loan_reference` (`loan_reference`),
        KEY `bank_id` (`bank_id`),
        KEY `customer_id` (`customer_id`),
        KEY `employee_id` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $create_loans);
    $tables_created = true;
}

// Check and create bank_loan_items table (for jewelry items)
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_loan_items'");
if (mysqli_num_rows($table_check) == 0) {
    $create_items = "CREATE TABLE IF NOT EXISTS `bank_loan_items` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_loan_id` int(11) NOT NULL,
        `jewel_name` varchar(150) NOT NULL,
        `karat` decimal(4,2) NOT NULL,
        `defect_details` text DEFAULT NULL,
        `stone_details` text DEFAULT NULL,
        `net_weight` decimal(10,3) NOT NULL,
        `quantity` int(11) DEFAULT 1,
        `photo_path` varchar(255) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `bank_loan_id` (`bank_loan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $create_items);
    $tables_created = true;
}

// Check and create bank_loan_emi table
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_loan_emi'");
if (mysqli_num_rows($table_check) == 0) {
    $create_emi = "CREATE TABLE IF NOT EXISTS `bank_loan_emi` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_loan_id` int(11) NOT NULL,
        `installment_no` int(11) NOT NULL,
        `due_date` date NOT NULL,
        `principal_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `interest_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `emi_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `balance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `status` enum('pending','paid','overdue') DEFAULT 'pending',
        `paid_date` date DEFAULT NULL,
        `paid_amount` decimal(15,2) DEFAULT NULL,
        `payment_method` varchar(50) DEFAULT NULL,
        `remarks` text,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `bank_loan_id` (`bank_loan_id`),
        KEY `due_date` (`due_date`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $create_emi);
    $tables_created = true;
}

// Check and create bank_loan_payments table
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_loan_payments'");
if (mysqli_num_rows($table_check) == 0) {
    $create_payments = "CREATE TABLE IF NOT EXISTS `bank_loan_payments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_loan_id` int(11) NOT NULL,
        `emi_id` int(11) NOT NULL,
        `payment_date` date NOT NULL,
        `payment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `payment_method` varchar(50) DEFAULT 'cash',
        `receipt_number` varchar(50) NOT NULL,
        `employee_id` int(11) NOT NULL,
        `remarks` text,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `receipt_number` (`receipt_number`),
        KEY `bank_loan_id` (`bank_loan_id`),
        KEY `emi_id` (`emi_id`),
        KEY `employee_id` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $create_payments);
    $tables_created = true;
}

if ($tables_created) {
    // Refresh the page to show new tables
    header('Location: ' . $selfPage);
    exit();
}

// Safe number format function to handle null values
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get banks for dropdown
$banks_query = "SELECT bm.*, 
                COUNT(ba.id) as account_count 
                FROM bank_master bm 
                LEFT JOIN bank_accounts ba ON bm.id = ba.bank_id 
                WHERE bm.status = 1 
                GROUP BY bm.id 
                ORDER BY bm.bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get bank accounts for dropdown
$accounts_query = "SELECT ba.*, bm.bank_short_name 
                   FROM bank_accounts ba 
                   JOIN bank_master bm ON ba.bank_id = bm.id 
                   WHERE ba.status = 1 
                   ORDER BY bm.bank_short_name, ba.account_holder_no";
$accounts_result = mysqli_query($conn, $accounts_query);

// Get customers for dropdown
$customers_query = "SELECT id, customer_name, mobile_number, aadhaar_number 
                    FROM customers 
                    ORDER BY customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get product types for dropdown
$product_types_query = "SELECT id, product_type FROM product_types WHERE status = 1 ORDER BY product_type";
$product_types_result = mysqli_query($conn, $product_types_query);

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' AND role IN ('admin', 'manager', 'sale') ORDER BY name";
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

// Generate loan reference number
$ref_query = "SELECT COUNT(*) as count FROM bank_loans WHERE DATE(created_at) = CURDATE()";
$ref_result = mysqli_query($conn, $ref_query);
$ref_count = 1;
if ($ref_result && mysqli_num_rows($ref_result) > 0) {
    $ref_data = mysqli_fetch_assoc($ref_result);
    $ref_count = ($ref_data['count'] ?? 0) + 1;
}
$loan_reference = 'BNK-L' . date('Ymd') . '-' . str_pad($ref_count, 4, '0', STR_PAD_LEFT);

// Helper function to save base64 image
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_loan':
                $bank_id = intval($_POST['bank_id'] ?? 0);
                $bank_account_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
                $customer_id = intval($_POST['customer_id'] ?? 0);
                $loan_date = mysqli_real_escape_string($conn, $_POST['loan_date'] ?? date('Y-m-d'));
                $loan_amount = floatval($_POST['loan_amount'] ?? 0);
                $interest_rate = floatval($_POST['interest_rate'] ?? 0);
                $tenure_months = intval($_POST['tenure_months'] ?? 0);
                $product_type = !empty($_POST['product_type']) ? mysqli_real_escape_string($conn, $_POST['product_type']) : null;
                $document_charge = floatval($_POST['document_charge'] ?? 0);
                $processing_fee = floatval($_POST['processing_fee'] ?? 0);
                $employee_id = intval($_POST['employee_id'] ?? $_SESSION['user_id']);
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
                
                // Validate
                $errors = [];
                if ($bank_id <= 0) $errors[] = "Please select a bank";
                if ($customer_id <= 0) $errors[] = "Please select a customer";
                if ($loan_amount <= 0) $errors[] = "Loan amount must be greater than 0";
                if ($interest_rate <= 0) $errors[] = "Interest rate must be greater than 0";
                if ($tenure_months <= 0) $errors[] = "Tenure must be greater than 0";
                
                // Check if at least one jewelry item is added
                if (!isset($_POST['jewel_name']) || empty(array_filter($_POST['jewel_name']))) {
                    $errors[] = "Please add at least one jewelry item";
                }
                
                if (empty($errors)) {
                    // Begin transaction
                    mysqli_begin_transaction($conn);
                    
                    try {
                        // Calculate monthly payment (EMI)
                        $monthly_rate = $interest_rate / 100 / 12;
                        if ($monthly_rate > 0 && $tenure_months > 0) {
                            $emi = $loan_amount * $monthly_rate * pow(1 + $monthly_rate, $tenure_months) / (pow(1 + $monthly_rate, $tenure_months) - 1);
                        } else {
                            $emi = $loan_amount / $tenure_months;
                        }
                        $total_interest = ($emi * $tenure_months) - $loan_amount;
                        $total_payable = $loan_amount + $total_interest + $document_charge + $processing_fee;
                        
                        // Insert bank loan
                        $insert_query = "INSERT INTO bank_loans (
                            loan_reference, bank_id, bank_account_id, customer_id, loan_date,
                            loan_amount, interest_rate, tenure_months, emi_amount, total_interest,
                            document_charge, processing_fee, total_payable, product_type,
                            employee_id, remarks, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                        
                        $stmt = mysqli_prepare($conn, $insert_query);
                        mysqli_stmt_bind_param($stmt, 'siiisdiddddddsis', 
                            $loan_reference, $bank_id, $bank_account_id, $customer_id, $loan_date,
                            $loan_amount, $interest_rate, $tenure_months, $emi, $total_interest,
                            $document_charge, $processing_fee, $total_payable, $product_type,
                            $employee_id, $remarks
                        );
                        
                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Error creating bank loan: " . mysqli_stmt_error($stmt));
                        }
                        
                        $loan_id = mysqli_insert_id($conn);
                        
                        // Insert jewelry items
                        if (isset($_POST['jewel_name']) && is_array($_POST['jewel_name'])) {
                            for ($i = 0; $i < count($_POST['jewel_name']); $i++) {
                                if (!empty($_POST['jewel_name'][$i])) {
                                    $jewel_name = mysqli_real_escape_string($conn, $_POST['jewel_name'][$i]);
                                    $karat = floatval($_POST['karat'][$i] ?? 0);
                                    $defect = mysqli_real_escape_string($conn, $_POST['defect'][$i] ?? '');
                                    $stone = mysqli_real_escape_string($conn, $_POST['stone'][$i] ?? '');
                                    $item_net_weight = floatval($_POST['item_net_weight'][$i] ?? 0);
                                    $quantity = intval($_POST['quantity'][$i] ?? 1);
                                    
                                    // Handle jewelry photo from camera or upload
                                    $jewel_photo = null;
                                    
                                    // Check for camera capture
                                    $camera_input_name = 'jewel_photo_camera_' . $i;
                                    if (isset($_POST[$camera_input_name]) && !empty($_POST[$camera_input_name])) {
                                        $jewel_photo = saveBase64Image($_POST[$camera_input_name], "uploads/bank_loans/$loan_id/", 'jewel_' . ($i + 1));
                                    }
                                    
                                    $insert_item = "INSERT INTO bank_loan_items (
                                        bank_loan_id, jewel_name, karat, defect_details, stone_details, 
                                        net_weight, quantity, photo_path
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                    
                                    $item_stmt = mysqli_prepare($conn, $insert_item);
                                    
                                    mysqli_stmt_bind_param(
                                        $item_stmt, 
                                        'isdssdis', 
                                        $loan_id, 
                                        $jewel_name, 
                                        $karat, 
                                        $defect, 
                                        $stone, 
                                        $item_net_weight, 
                                        $quantity, 
                                        $jewel_photo
                                    );
                                    
                                    if (!mysqli_stmt_execute($item_stmt)) {
                                        throw new Exception("Error inserting jewelry item: " . mysqli_stmt_error($item_stmt));
                                    }
                                }
                            }
                        }
                        
                        // Create EMI schedule
                        $schedule_query = "INSERT INTO bank_loan_emi (
                            bank_loan_id, installment_no, due_date, principal_amount,
                            interest_amount, emi_amount, balance_amount, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                        
                        $schedule_stmt = mysqli_prepare($conn, $schedule_query);
                        $balance = $loan_amount;
                        
                        for ($i = 1; $i <= $tenure_months; $i++) {
                            $due_date = date('Y-m-d', strtotime("+$i months", strtotime($loan_date)));
                            $interest_part = $balance * $monthly_rate;
                            $principal_part = $emi - $interest_part;
                            $balance -= $principal_part;
                            if ($balance < 0) $balance = 0;
                            
                            mysqli_stmt_bind_param($schedule_stmt, 'iisdddd', 
                                $loan_id, $i, $due_date, $principal_part, $interest_part, $emi, $balance
                            );
                            mysqli_stmt_execute($schedule_stmt);
                        }
                        
                        // Log activity
                        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                      VALUES (?, 'create', ?, 'bank_loans', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $log_description = "Created bank loan: " . $loan_reference . " for ₹" . number_format($loan_amount, 2);
                        mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
                        mysqli_stmt_execute($log_stmt);
                        
                        mysqli_commit($conn);
                        
                        header('Location: ' . $selfPage . '?success=created&ref=' . urlencode($loan_reference));
                        exit();
                        
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $error = "Error creating bank loan: " . $e->getMessage();
                    }
                } else {
                    $error = implode("<br>", $errors);
                }
                break;
                
            case 'make_payment':
                $loan_id = intval($_POST['loan_id'] ?? 0);
                $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date'] ?? date('Y-m-d'));
                $payment_amount = floatval($_POST['payment_amount'] ?? 0);
                $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
                $employee_id = intval($_POST['employee_id'] ?? $_SESSION['user_id']);
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'EMI Payment');
                
                if ($loan_id <= 0 || $payment_amount <= 0) {
                    $error = "Invalid payment data";
                    break;
                }
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Get next pending EMI
                    $emi_query = "SELECT * FROM bank_loan_emi 
                                  WHERE bank_loan_id = ? AND status = 'pending' 
                                  ORDER BY installment_no ASC LIMIT 1";
                    $emi_stmt = mysqli_prepare($conn, $emi_query);
                    mysqli_stmt_bind_param($emi_stmt, 'i', $loan_id);
                    mysqli_stmt_execute($emi_stmt);
                    $emi_result = mysqli_stmt_get_result($emi_stmt);
                    $emi = mysqli_fetch_assoc($emi_result);
                    
                    if (!$emi) {
                        throw new Exception("No pending EMI found");
                    }
                    
                    if ($payment_amount < $emi['emi_amount']) {
                        throw new Exception("Payment amount is less than EMI amount");
                    }
                    
                    // Update EMI as paid
                    $update_emi = "UPDATE bank_loan_emi SET 
                                    status = 'paid', 
                                    paid_date = ?, 
                                    paid_amount = ?,
                                    payment_method = ?,
                                    remarks = ?
                                  WHERE id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_emi);
                    mysqli_stmt_bind_param($update_stmt, 'sdssi', $payment_date, $payment_amount, $payment_method, $remarks, $emi['id']);
                    mysqli_stmt_execute($update_stmt);
                    
                    // Generate receipt number
                    $receipt_query = "SELECT COUNT(*) as count FROM bank_loan_payments WHERE DATE(created_at) = CURDATE()";
                    $receipt_result = mysqli_query($conn, $receipt_query);
                    $receipt_count = 1;
                    if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
                        $receipt_data = mysqli_fetch_assoc($receipt_result);
                        $receipt_count = ($receipt_data['count'] ?? 0) + 1;
                    }
                    $receipt_no = 'EMI-' . date('Ymd') . '-' . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
                    
                    // Insert payment record
                    $payment_query = "INSERT INTO bank_loan_payments (
                        bank_loan_id, emi_id, payment_date, payment_amount,
                        payment_method, receipt_number, employee_id, remarks
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $payment_stmt = mysqli_prepare($conn, $payment_query);
                    mysqli_stmt_bind_param($payment_stmt, 'iisdssis', 
                        $loan_id, $emi['id'], $payment_date, $payment_amount,
                        $payment_method, $receipt_no, $employee_id, $remarks
                    );
                    mysqli_stmt_execute($payment_stmt);
                    
                    // Check if all EMIs are paid
                    $check_query = "SELECT COUNT(*) as pending FROM bank_loan_emi 
                                    WHERE bank_loan_id = ? AND status = 'pending'";
                    $check_stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($check_stmt, 'i', $loan_id);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    $check_data = mysqli_fetch_assoc($check_result);
                    $pending = $check_data['pending'] ?? 0;
                    
                    if ($pending == 0) {
                        // Update loan as closed
                        $close_query = "UPDATE bank_loans SET status = 'closed', close_date = ? WHERE id = ?";
                        $close_stmt = mysqli_prepare($conn, $close_query);
                        mysqli_stmt_bind_param($close_stmt, 'si', $payment_date, $loan_id);
                        mysqli_stmt_execute($close_stmt);
                    }
                    
                    mysqli_commit($conn);
                    
                    header('Location: ' . $selfPage . '?success=payment_made&receipt=' . urlencode($receipt_no));
                    exit();
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error processing payment: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get all bank loans
$loans_query = "SELECT bl.*, 
                b.bank_short_name, b.bank_full_name,
                ba.account_holder_no, ba.bank_account_no,
                c.customer_name, c.mobile_number,
                u.name as employee_name,
                (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
                (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis,
                (SELECT COUNT(*) FROM bank_loan_items WHERE bank_loan_id = bl.id) as items_count
                FROM bank_loans bl
                LEFT JOIN bank_master b ON bl.bank_id = b.id
                LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                LEFT JOIN customers c ON bl.customer_id = c.id
                LEFT JOIN users u ON bl.employee_id = u.id
                ORDER BY bl.created_at DESC";
$loans_result = mysqli_query($conn, $loans_query);

// Get EMI summary
$emi_summary_query = "SELECT 
                        COUNT(*) as total_emis,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_emis,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                        SUM(CASE WHEN status = 'pending' THEN emi_amount ELSE 0 END) as pending_amount,
                        SUM(CASE WHEN status = 'paid' THEN emi_amount ELSE 0 END) as collected_amount
                      FROM bank_loan_emi";
$emi_summary_result = mysqli_query($conn, $emi_summary_query);
$emi_summary = [
    'total_emis' => 0,
    'pending_emis' => 0,
    'paid_emis' => 0,
    'pending_amount' => 0,
    'collected_amount' => 0
];
if ($emi_summary_result && mysqli_num_rows($emi_summary_result) > 0) {
    $emi_summary = mysqli_fetch_assoc($emi_summary_result);
}
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

        .bank-loan-container {
            max-width: 1600px;
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
            grid-template-columns: repeat(4, 1fr);
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
            grid-template-columns: repeat(3, 1fr);
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

        textarea.form-control {
            padding-left: 12px;
        }

        .readonly-field {
            background: #f7fafc;
            cursor: not-allowed;
        }

        /* Customer Items Section */
        .customer-items-section {
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

        .items-list {
            max-height: 300px;
            overflow-y: auto;
            background: white;
            border-radius: 8px;
            padding: 10px;
        }

        .item-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .item-card:hover {
            background: #f7fafc;
            border-color: #667eea;
            transform: translateY(-2px);
        }

        .item-card.selected {
            background: #ebf4ff;
            border-color: #667eea;
            border-width: 2px;
        }

        .item-photo {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
        }

        .item-info {
            flex: 1;
        }

        .item-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .item-details {
            font-size: 12px;
            color: #718096;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .item-badge {
            background: #ecc94b20;
            color: #744210;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 30px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Jewelry Items Table */
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
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .jewelry-table input, .jewelry-table select {
            width: 100%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 13px;
        }

        .jewelry-table .camera-icon {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .jewelry-table .camera-icon button {
            background: #4299e1;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }

        .remove-row {
            color: #f56565;
            cursor: pointer;
            text-align: center;
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

        .summary-box {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row.total {
            font-weight: 700;
            font-size: 18px;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #667eea;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .loan-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .loan-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .loan-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .loan-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-active {
            background: #48bb78;
            color: white;
        }

        .badge-closed {
            background: #a0aec0;
            color: white;
        }

        .badge-defaulted {
            background: #f56565;
            color: white;
        }

        .badge-pending {
            background: #ecc94b;
            color: #744210;
        }

        .badge-paid {
            background: #48bb78;
            color: white;
        }

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        /* Modal */
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
            border-radius: 12px;
            padding: 25px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
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

        .emi-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
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

        /* Camera Modal */
        .camera-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .camera-modal.active {
            display: flex;
        }

        .camera-content {
            background: white;
            border-radius: 12px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
        }

        .camera-preview {
            width: 100%;
            height: 300px;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 15px;
        }

        .camera-preview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
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
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .jewelry-table {
                overflow-x: auto;
                display: block;
            }
            
            .item-card {
                flex-direction: column;
                text-align: center;
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
                <div class="bank-loan-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-bank"></i>
                            Bank Loan Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showNewLoanForm()">
                                <i class="bi bi-plus-circle"></i> New Bank Loan
                            </button>
                        </div>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Loans</div>
                                <div class="stat-value"><?php echo $loans_result ? mysqli_num_rows($loans_result) : 0; ?></div>
                                <div class="stat-sub">Active loans</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total EMIs</div>
                                <div class="stat-value"><?php echo number_format($emi_summary['total_emis'] ?? 0); ?></div>
                                <div class="stat-sub">Pending: <?php echo number_format($emi_summary['pending_emis'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Pending Amount</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($emi_summary['pending_amount'] ?? 0); ?></div>
                                <div class="stat-sub">To be collected</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Collected</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($emi_summary['collected_amount'] ?? 0); ?></div>
                                <div class="stat-sub">Total collected</div>
                            </div>
                        </div>
                    </div>

                    <!-- New Loan Form (Hidden by default) -->
                    <div class="form-card" id="newLoanForm" style="display: none;">
                        <div class="form-title">
                            <i class="bi bi-plus-circle"></i>
                            Create New Bank Loan
                        </div>

                        <form method="POST" action="" enctype="multipart/form-data" id="loanForm">
                            <input type="hidden" name="action" value="create_loan">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id" id="bank_id" required onchange="loadBankAccounts()">
                                            <option value="">Select Bank</option>
                                            <?php 
                                            if ($banks_result && mysqli_num_rows($banks_result) > 0) {
                                                mysqli_data_seek($banks_result, 0);
                                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>">
                                                    <?php echo htmlspecialchars($bank['bank_full_name'] ?? ''); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } else {
                                                echo '<option value="">No banks available</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank Account</label>
                                    <div class="input-group">
                                        <i class="bi bi-credit-card input-icon"></i>
                                        <select class="form-select" name="bank_account_id" id="bank_account_id">
                                            <option value="">Select Account (Optional)</option>
                                            <?php 
                                            if ($accounts_result && mysqli_num_rows($accounts_result) > 0) {
                                                mysqli_data_seek($accounts_result, 0);
                                                while($acc = mysqli_fetch_assoc($accounts_result)): 
                                            ?>
                                                <option value="<?php echo $acc['id']; ?>" data-bank="<?php echo $acc['bank_id'] ?? ''; ?>">
                                                    <?php echo htmlspecialchars(($acc['bank_short_name'] ?? '') . ' - ' . ($acc['account_holder_no'] ?? '')); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Customer</label>
                                    <div class="input-group">
                                        <i class="bi bi-person input-icon"></i>
                                        <select class="form-select" name="customer_id" id="customer_id" required onchange="loadCustomerItems()">
                                            <option value="">Select Customer</option>
                                            <?php 
                                            if ($customers_result && mysqli_num_rows($customers_result) > 0) {
                                                mysqli_data_seek($customers_result, 0);
                                                while($cust = mysqli_fetch_assoc($customers_result)): 
                                            ?>
                                                <option value="<?php echo $cust['id']; ?>">
                                                    <?php echo htmlspecialchars($cust['customer_name'] ?? '') . ' - ' . ($cust['mobile_number'] ?? ''); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } else {
                                                echo '<option value="">No customers available</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Loan Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="loan_date" id="loan_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Loan Amount (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-currency-rupee input-icon"></i>
                                        <input type="number" class="form-control" name="loan_amount" id="loan_amount" step="0.01" min="0" value="0" required onchange="calculateEMI()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Interest Rate (%)</label>
                                    <div class="input-group">
                                        <i class="bi bi-percent input-icon"></i>
                                        <input type="number" class="form-control" name="interest_rate" id="interest_rate" step="0.01" min="0" value="0" required onchange="calculateEMI()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Tenure (Months)</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-week input-icon"></i>
                                        <input type="number" class="form-control" name="tenure_months" id="tenure_months" min="1" max="60" value="12" required onchange="calculateEMI()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Product Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="product_type">
                                            <option value="">Select Product Type</option>
                                            <?php 
                                            if ($product_types_result && mysqli_num_rows($product_types_result) > 0) {
                                                mysqli_data_seek($product_types_result, 0);
                                                while($pt = mysqli_fetch_assoc($product_types_result)): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($pt['product_type'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($pt['product_type'] ?? ''); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Document Charge (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-file-text input-icon"></i>
                                        <input type="number" class="form-control" name="document_charge" id="document_charge" value="0" step="0.01" min="0" onchange="calculateTotal()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Processing Fee (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-gear input-icon"></i>
                                        <input type="number" class="form-control" name="processing_fee" id="processing_fee" value="0" step="0.01" min="0" onchange="calculateTotal()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Employee</label>
                                    <div class="input-group">
                                        <i class="bi bi-person-badge input-icon"></i>
                                        <select class="form-select" name="employee_id">
                                            <?php 
                                            if ($employees_result && mysqli_num_rows($employees_result) > 0) {
                                                mysqli_data_seek($employees_result, 0);
                                                while($emp = mysqli_fetch_assoc($employees_result)): 
                                            ?>
                                                <option value="<?php echo $emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($emp['name'] ?? ''); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer Items Section (Shows after customer selection) -->
                            <div class="customer-items-section" id="customerItemsSection" style="display: none;">
                                <div class="section-title">
                                    <i class="bi bi-box-seam"></i>
                                    Customer's Pawned Items (Select to add)
                                </div>
                                <div class="loading-spinner" id="itemsLoading">
                                    <div class="spinner"></div>
                                    <p style="margin-top: 10px;">Loading items...</p>
                                </div>
                                <div class="items-list" id="itemsList"></div>
                            </div>

                            <!-- Jewelry Items Table -->
                            <div class="form-group">
                                <label class="form-label required">Jewelry Items for Bank Loan</label>
                                <table class="jewelry-table" id="jewelryTable">
                                    <thead>
                                        <tr>
                                            <th>S.No.</th>
                                            <th>Jewel Name</th>
                                            <th>Karat</th>
                                            <th>Defect Details</th>
                                            <th>Stone Details</th>
                                            <th>Net Weight (g)</th>
                                            <th>Qty</th>
                                            <th>Photo</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="jewelryBody">
                                        <tr id="row1">
                                            <td>1</td>
                                            <td>
                                                <select name="jewel_name[]" class="form-select" style="padding-left: 8px;">
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
                                                <select name="karat[]" class="form-select" style="padding-left: 8px;">
                                                    <option value="">Select</option>
                                                    <?php 
                                                    if ($karat_result && mysqli_num_rows($karat_result) > 0) {
                                                        mysqli_data_seek($karat_result, 0);
                                                        while($karat = mysqli_fetch_assoc($karat_result)): 
                                                    ?>
                                                        <option value="<?php echo $karat['karat']; ?>">
                                                            <?php echo $karat['karat']; ?>K
                                                        </option>
                                                    <?php 
                                                        endwhile;
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="defect[]" class="form-select" style="padding-left: 8px;">
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
                                                <select name="stone[]" class="form-select" style="padding-left: 8px;">
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
                                                <input type="number" name="item_net_weight[]" step="0.001" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="quantity[]" value="1" min="1">
                                            </td>
                                            <td>
                                                <div class="camera-icon">
                                                    <button type="button" onclick="openJewelCamera(this, 1)" class="btn btn-sm btn-info">
                                                        <i class="bi bi-camera"></i> Take Photo
                                                    </button>
                                                    <input type="hidden" id="jewel_photo_camera_1" name="jewel_photo_camera_0">
                                                    <small id="jewel_photo_name_1" style="font-size: 10px;"></small>
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
                            </div>

                            <div class="form-group">
                                <label class="form-label">Remarks</label>
                                <textarea class="form-control" name="remarks" rows="2" placeholder="Additional notes"></textarea>
                            </div>

                            <!-- EMI Calculation Summary -->
                            <div class="summary-box" id="emiSummary" style="display: none;">
                                <h4 style="margin-bottom: 15px;">Loan Summary</h4>
                                <div class="summary-row">
                                    <span>Loan Amount:</span>
                                    <span class="amount" id="summaryLoan">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Monthly EMI:</span>
                                    <span class="amount" id="summaryEMI">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Total Interest:</span>
                                    <span class="amount" id="summaryInterest">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Document Charge:</span>
                                    <span class="amount" id="summaryDoc">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span>Processing Fee:</span>
                                    <span class="amount" id="summaryFee">₹0.00</span>
                                </div>
                                <div class="summary-row total">
                                    <span>Total Payable:</span>
                                    <span class="amount" id="summaryTotal">₹0.00</span>
                                </div>
                            </div>

                            <div class="action-buttons" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                                <button type="button" class="btn btn-secondary" onclick="hideNewLoanForm()">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-success" onclick="return confirm('Create this bank loan?')">
                                    <i class="bi bi-check-circle"></i> Create Loan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Loans List -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">Bank Loans List</span>
                            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-left:auto;">
                                <input
                                    type="text"
                                    id="loanSearchInput"
                                    class="form-control"
                                    placeholder="Search Loan Ref / Date / Bank / Customer / Loan Amount"
                                    style="min-width: 280px; max-width: 360px;"
                                    onkeyup="filterBankLoans()"
                                >
                                <span class="text-muted" id="loanSearchCount">
                                    Showing: <?php echo $loans_result ? mysqli_num_rows($loans_result) : 0; ?>/<?php echo $loans_result ? mysqli_num_rows($loans_result) : 0; ?>
                                </span>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="loan-table" id="bankLoansTable">
                                <thead>
                                    <tr>
                                        <th>Loan Ref</th>
                                        <th>Date</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th>Tenure</th>
                                        <th class="text-right">EMI</th>
                                        <th>Items</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="bankLoansTableBody">
                                    <?php 
                                    if ($loans_result && mysqli_num_rows($loans_result) > 0) {
                                        mysqli_data_seek($loans_result, 0);
                                        while($loan = mysqli_fetch_assoc($loans_result)): 
                                            $paid_emis = intval($loan['paid_emis'] ?? 0);
                                            $total_emis = intval($loan['total_emis'] ?? 0);
                                            $progress = $total_emis > 0 ? ($paid_emis / $total_emis) * 100 : 0;
                                    ?>
                                    <tr class="loan-row">
                                        <td><strong><?php echo htmlspecialchars($loan['loan_reference'] ?? ''); ?></strong></td>
                                        <td><?php echo date('d-m-Y', strtotime($loan['loan_date'] ?? date('Y-m-d'))); ?></td>
                                        <td><?php echo htmlspecialchars($loan['bank_short_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($loan['customer_name'] ?? ''); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($loan['loan_amount'] ?? 0); ?></td>
                                        <td class="text-right"><?php echo safeNumberFormat($loan['interest_rate'] ?? 0, 2); ?>%</td>
                                        <td class="text-right"><?php echo intval($loan['tenure_months'] ?? 0); ?> months</td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($loan['emi_amount'] ?? 0); ?></td>
                                        <td class="text-center">
                                            <span class="badge badge-info"><?php echo intval($loan['items_count'] ?? 0); ?> items</span>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px;">
                                                    <div style="width: <?php echo $progress; ?>%; height: 100%; background: #48bb78; border-radius: 3px;"></div>
                                                </div>
                                                <span style="font-size: 12px;"><?php echo $paid_emis; ?>/<?php echo $total_emis; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $loan['status'] ?? 'active'; ?>">
                                                <?php echo ucfirst($loan['status'] ?? 'active'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-info btn-sm" onclick="viewLoanDetails(<?php echo $loan['id']; ?>)">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <?php if (($loan['status'] ?? '') == 'active'): ?>
                                            <button class="btn btn-success btn-sm" onclick="makePayment(<?php echo $loan['id']; ?>)">
                                                <i class="bi bi-cash"></i> Pay EMI
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    } else {
                                        echo '<tr><td colspan="12" class="text-center" style="padding: 40px;">No bank loans found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Jewel Camera Modal -->
    <div class="camera-modal" id="jewelCameraModal">
        <div class="camera-content">
            <h3 style="margin-bottom: 15px;">Take Jewel Photo</h3>
            <div class="camera-preview">
                <video id="jewelModalVideo" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                <canvas id="jewelModalCanvas" style="display: none;"></canvas>
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn btn-primary" onclick="captureJewelPhoto()">
                    <i class="bi bi-camera"></i> Capture
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeJewelCamera()">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closePaymentModal()">&times;</span>
            <h3 class="modal-title">Make EMI Payment</h3>
            
            <form method="POST" action="" id="paymentForm">
                <input type="hidden" name="action" value="make_payment">
                <input type="hidden" name="loan_id" id="payment_loan_id" value="">
                
                <div id="emiDetails" class="alert alert-info">
                    <p>Please enter payment details below.</p>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Payment Date</label>
                    <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Amount (₹)</label>
                    <input type="number" class="form-control" name="payment_amount" id="payment_amount" step="0.01" min="0" value="1000" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Method</label>
                    <select class="form-select" name="payment_method" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="cheque">Cheque</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2" placeholder="Payment remarks"></textarea>
                </div>

                <div class="action-buttons" style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Process Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content" style="max-width: 800px;">
            <span class="modal-close" onclick="closeViewModal()">&times;</span>
            <h3 class="modal-title">Loan Details</h3>
            <div id="loanDetails" class="alert alert-info">
                <p>Loading loan details...</p>
            </div>
        </div>
    </div>

    <!-- Include required JS files -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        let rowCount = 1;
        let currentJewelRow = null;
        let jewelVideoStream = null;
        let selectedItems = [];

        // Show SweetAlert for success messages
        <?php if ($show_success_alert): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: '<?php echo $success_message; ?>',
                html: '<strong>Reference: <?php echo $success_reference; ?></strong>',
                icon: 'success',
                confirmButtonColor: '#667eea',
                confirmButtonText: 'View Loans',
                showCancelButton: true,
                cancelButtonColor: '#48bb78',
                cancelButtonText: 'Create Another',
                timer: 5000,
                timerProgressBar: true,
                showClass: {
                    popup: 'animate__animated animate__fadeInDown'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOutUp'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Stay on bank loan page (already here)
                    window.location.href = <?php echo json_encode($selfPage); ?>;
                } else if (result.isDismissed) {
                    // Stay on current page to create another loan
                    // Form will still be visible
                }
            });
        });
        <?php endif; ?>

        // Show/Hide New Loan Form
        function showNewLoanForm() {
            document.getElementById('newLoanForm').style.display = 'block';
            window.scrollTo({ top: document.getElementById('newLoanForm').offsetTop - 20, behavior: 'smooth' });
        }

        function hideNewLoanForm() {
            document.getElementById('newLoanForm').style.display = 'none';
            // Reset form
            document.getElementById('loanForm').reset();
            document.getElementById('customerItemsSection').style.display = 'none';
            document.getElementById('emiSummary').style.display = 'none';
            
            // Reset jewelry table to single row
            const tbody = document.getElementById('jewelryBody');
            while (tbody.children.length > 1) {
                tbody.removeChild(tbody.lastChild);
            }
            rowCount = 1;
        }

        // Filter bank accounts based on selected bank
        function loadBankAccounts() {
            var bankId = document.getElementById('bank_id').value;
            var accountSelect = document.getElementById('bank_account_id');
            var options = accountSelect.options;
            
            for (var i = 0; i < options.length; i++) {
                var option = options[i];
                if (option.value === "") continue;
                
                var optionBank = option.getAttribute('data-bank');
                if (bankId === "" || optionBank === bankId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
            accountSelect.value = '';
        }

        // Load customer's pawned items
        function loadCustomerItems() {
            var customerId = document.getElementById('customer_id').value;
            
            if (!customerId) {
                document.getElementById('customerItemsSection').style.display = 'none';
                return;
            }
            
            document.getElementById('customerItemsSection').style.display = 'block';
            document.getElementById('itemsLoading').style.display = 'block';
            document.getElementById('itemsList').innerHTML = '';
            
            // Use full URL to ensure correct path
            var url = window.location.href.split('?')[0] + '?ajax=get_customer_items&customer_id=' + customerId;
            
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok (Status: ' + response.status + ')');
                    }
                    return response.json();
                })
                .then(data => {
                    document.getElementById('itemsLoading').style.display = 'none';
                    
                    if (data.success && data.items.length > 0) {
                        let html = '';
                        data.items.forEach(item => {
                            let photoHtml = '';
                            if (item.photo_path) {
                                photoHtml = `<img src="${item.photo_path}" class="item-photo" alt="Jewel" onerror="this.src='assets/images/no-image.png'">`;
                            } else {
                                photoHtml = `<div class="item-photo" style="background: #f7fafc; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-image" style="font-size: 24px; color: #a0aec0;"></i>
                                </div>`;
                            }
                            
                            html += `
                                <div class="item-card" onclick="selectItem(this)" 
                                     data-jewel-name="${item.jewel_name}"
                                     data-karat="${item.karat}"
                                     data-defect="${item.defect_details || ''}"
                                     data-stone="${item.stone_details || ''}"
                                     data-weight="${item.net_weight}"
                                     data-quantity="${item.quantity}">
                                    <div>
                                        ${photoHtml}
                                    </div>
                                    <div class="item-info">
                                        <div class="item-title">${item.jewel_name}</div>
                                        <div class="item-details">
                                            <span>Karat: ${item.karat}K</span>
                                            <span>Weight: ${item.net_weight}g</span>
                                            <span>Qty: ${item.quantity}</span>
                                            <span>Loan: #${item.receipt_number}</span>
                                        </div>
                                        ${item.defect_details ? `<div class="item-badge">Defect: ${item.defect_details}</div>` : ''}
                                        ${item.stone_details ? `<div class="item-badge">Stone: ${item.stone_details}</div>` : ''}
                                    </div>
                                    <div>
                                        <i class="bi bi-plus-circle" style="color: #48bb78; font-size: 20px;"></i>
                                    </div>
                                </div>
                            `;
                        });
                        document.getElementById('itemsList').innerHTML = html;
                    } else {
                        document.getElementById('itemsList').innerHTML = '<p class="text-center" style="padding: 20px; color: #718096;">No pawned items found for this customer</p>';
                    }
                })
                .catch(error => {
                    document.getElementById('itemsLoading').style.display = 'none';
                    document.getElementById('itemsList').innerHTML = '<p class="text-center" style="padding: 20px; color: #f56565;">Error loading items: ' + error.message + '</p>';
                    console.error('Error:', error);
                });
        }

        // Select item from customer's list
        function selectItem(element) {
            // Get item data
            const jewelName = element.dataset.jewelName;
            const karat = element.dataset.karat;
            const defect = element.dataset.defect;
            const stone = element.dataset.stone;
            const weight = element.dataset.weight;
            const quantity = element.dataset.quantity;
            
            // Add to jewelry table
            addRowWithData(jewelName, karat, defect, stone, weight, quantity);
        }

        // Add row with pre-filled data
        function addRowWithData(jewelName, karat, defect, stone, weight, quantity) {
            rowCount++;
            const tbody = document.getElementById('jewelryBody');
            const newRow = document.createElement('tr');
            newRow.id = 'row' + rowCount;
            
            newRow.innerHTML = `
                <td>${rowCount}</td>
                <td>
                    <select name="jewel_name[]" class="form-select" style="padding-left: 8px;">
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
                    <select name="karat[]" class="form-select" style="padding-left: 8px;">
                        <option value="">Select</option>
                        <?php 
                        if ($karat_result && mysqli_num_rows($karat_result) > 0) {
                            mysqli_data_seek($karat_result, 0);
                            while($karat = mysqli_fetch_assoc($karat_result)): 
                        ?>
                            <option value="<?php echo $karat['karat']; ?>">
                                <?php echo $karat['karat']; ?>K
                            </option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </td>
                <td>
                    <select name="defect[]" class="form-select" style="padding-left: 8px;">
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
                    <select name="stone[]" class="form-select" style="padding-left: 8px;">
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
                    <input type="number" name="item_net_weight[]" step="0.001" min="0" value="${weight}">
                </td>
                <td>
                    <input type="number" name="quantity[]" value="${quantity}" min="1">
                </td>
                <td>
                    <div class="camera-icon">
                        <button type="button" onclick="openJewelCamera(this, ${rowCount})" class="btn btn-sm btn-info">
                            <i class="bi bi-camera"></i> Take Photo
                        </button>
                        <input type="hidden" id="jewel_photo_camera_${rowCount}" name="jewel_photo_camera_${rowCount - 1}">
                        <small id="jewel_photo_name_${rowCount}" style="font-size: 10px;"></small>
                    </div>
                </td>
                <td class="remove-row" onclick="removeRow(this)">
                    <i class="bi bi-trash"></i>
                </td>
            `;
            
            tbody.appendChild(newRow);
            
            // Set selected values
            const row = document.getElementById('row' + rowCount);
            row.querySelector('select[name="jewel_name[]"]').value = jewelName;
            row.querySelector('select[name="karat[]"]').value = karat;
            if (defect) row.querySelector('select[name="defect[]"]').value = defect;
            if (stone) row.querySelector('select[name="stone[]"]').value = stone;
        }

        // Calculate EMI
        function calculateEMI() {
            var amount = parseFloat(document.getElementById('loan_amount').value) || 0;
            var rate = parseFloat(document.getElementById('interest_rate').value) || 0;
            var months = parseInt(document.getElementById('tenure_months').value) || 0;
            
            if (amount > 0 && rate > 0 && months > 0) {
                var monthlyRate = rate / 100 / 12;
                var emi = amount * monthlyRate * Math.pow(1 + monthlyRate, months) / (Math.pow(1 + monthlyRate, months) - 1);
                var totalInterest = (emi * months) - amount;
                
                document.getElementById('summaryLoan').innerHTML = '₹' + formatNumber(amount);
                document.getElementById('summaryEMI').innerHTML = '₹' + formatNumber(emi);
                document.getElementById('summaryInterest').innerHTML = '₹' + formatNumber(totalInterest);
                
                calculateTotal();
                document.getElementById('emiSummary').style.display = 'block';
            } else {
                document.getElementById('emiSummary').style.display = 'none';
            }
        }

        // Calculate total payable
        function calculateTotal() {
            var amount = parseFloat(document.getElementById('loan_amount').value) || 0;
            var rate = parseFloat(document.getElementById('interest_rate').value) || 0;
            var months = parseInt(document.getElementById('tenure_months').value) || 0;
            var docCharge = parseFloat(document.getElementById('document_charge').value) || 0;
            var procFee = parseFloat(document.getElementById('processing_fee').value) || 0;
            
            if (amount > 0 && rate > 0 && months > 0) {
                var monthlyRate = rate / 100 / 12;
                var emi = amount * monthlyRate * Math.pow(1 + monthlyRate, months) / (Math.pow(1 + monthlyRate, months) - 1);
                var totalInterest = (emi * months) - amount;
                var totalPayable = amount + totalInterest + docCharge + procFee;
                
                document.getElementById('summaryDoc').innerHTML = '₹' + formatNumber(docCharge);
                document.getElementById('summaryFee').innerHTML = '₹' + formatNumber(procFee);
                document.getElementById('summaryTotal').innerHTML = '₹' + formatNumber(totalPayable);
            }
        }

        // Format number
        function formatNumber(num) {
            return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Live search for bank loan table
        function filterBankLoans() {
            const input = document.getElementById('loanSearchInput');
            const tbody = document.getElementById('bankLoansTableBody');
            const counter = document.getElementById('loanSearchCount');

            if (!input || !tbody) return;

            const filter = input.value.toLowerCase().trim();
            const rows = tbody.querySelectorAll('tr.loan-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length < 5) return;

                const refText = (cells[0].innerText || cells[0].textContent || '').toLowerCase();
                const dateText = (cells[1].innerText || cells[1].textContent || '').toLowerCase();
                const bankText = (cells[2].innerText || cells[2].textContent || '').toLowerCase();
                const customerText = (cells[3].innerText || cells[3].textContent || '').toLowerCase();
                const amountText = (cells[4].innerText || cells[4].textContent || '').toLowerCase().replace(/₹|,|\s/g, '');

                const combined = [refText, dateText, bankText, customerText, amountText].join(' ');
                const normalizedFilter = filter.replace(/₹|,|\s/g, '');

                const matches = filter === '' || combined.includes(filter) || amountText.includes(normalizedFilter);
                row.style.display = matches ? '' : 'none';

                if (matches) visibleCount++;
            });

            let noResultRow = document.getElementById('loanSearchNoResult');
            if (visibleCount === 0 && rows.length > 0) {
                if (!noResultRow) {
                    noResultRow = document.createElement('tr');
                    noResultRow.id = 'loanSearchNoResult';
                    noResultRow.innerHTML = '<td colspan="12" class="text-center" style="padding: 30px; color: #718096;">No matching bank loans found</td>';
                    tbody.appendChild(noResultRow);
                }
            } else if (noResultRow) {
                noResultRow.remove();
            }

            if (counter) {
                counter.textContent = 'Showing: ' + visibleCount + '/' + rows.length;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            filterBankLoans();
        });

        // Add new row to jewelry table
        function addRow() {
            rowCount++;
            const tbody = document.getElementById('jewelryBody');
            const newRow = document.createElement('tr');
            newRow.id = 'row' + rowCount;
            
            newRow.innerHTML = `
                <td>${rowCount}</td>
                <td>
                    <select name="jewel_name[]" class="form-select" style="padding-left: 8px;">
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
                    <select name="karat[]" class="form-select" style="padding-left: 8px;">
                        <option value="">Select</option>
                        <?php 
                        if ($karat_result && mysqli_num_rows($karat_result) > 0) {
                            mysqli_data_seek($karat_result, 0);
                            while($karat = mysqli_fetch_assoc($karat_result)): 
                        ?>
                            <option value="<?php echo $karat['karat']; ?>">
                                <?php echo $karat['karat']; ?>K
                            </option>
                        <?php 
                            endwhile;
                        }
                        ?>
                    </select>
                </td>
                <td>
                    <select name="defect[]" class="form-select" style="padding-left: 8px;">
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
                    <select name="stone[]" class="form-select" style="padding-left: 8px;">
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
                    <input type="number" name="item_net_weight[]" step="0.001" min="0">
                </td>
                <td>
                    <input type="number" name="quantity[]" value="1" min="1">
                </td>
                <td>
                    <div class="camera-icon">
                        <button type="button" onclick="openJewelCamera(this, ${rowCount})" class="btn btn-sm btn-info">
                            <i class="bi bi-camera"></i> Take Photo
                        </button>
                        <input type="hidden" id="jewel_photo_camera_${rowCount}" name="jewel_photo_camera_${rowCount - 1}">
                        <small id="jewel_photo_name_${rowCount}" style="font-size: 10px;"></small>
                    </div>
                </td>
                <td class="remove-row" onclick="removeRow(this)">
                    <i class="bi bi-trash"></i>
                </td>
            `;
            
            tbody.appendChild(newRow);
        }

        // Remove row from jewelry table
        function removeRow(element) {
            if (rowCount > 1) {
                element.closest('tr').remove();
                rowCount--;
                renumberRows();
            } else {
                alert('At least one item is required');
            }
        }

        // Renumber rows after deletion
        function renumberRows() {
            const rows = document.querySelectorAll('#jewelryBody tr');
            rows.forEach((row, index) => {
                row.cells[0].textContent = index + 1;
                row.id = 'row' + (index + 1);
                
                // Update camera button
                const cameraBtn = row.querySelector('button');
                if (cameraBtn) {
                    cameraBtn.setAttribute('onclick', `openJewelCamera(this, ${index + 1})`);
                }
                
                // Update hidden input
                const hiddenInput = row.querySelector('input[type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.name = `jewel_photo_camera_${index}`;
                    hiddenInput.id = `jewel_photo_camera_${index + 1}`;
                }
                
                // Update small tag
                const smallTag = row.querySelector('small');
                if (smallTag) {
                    smallTag.id = `jewel_photo_name_${index + 1}`;
                }
            });
        }

        // Jewel Camera Functions
        function openJewelCamera(button, rowNum) {
            currentJewelRow = button.closest('tr');
            document.getElementById('jewelCameraModal').classList.add('active');
            
            const video = document.getElementById('jewelModalVideo');
            
            navigator.mediaDevices.getUserMedia({ video: true, audio: false })
                .then(stream => {
                    jewelVideoStream = stream;
                    video.srcObject = stream;
                })
                .catch(err => {
                    console.error('Camera error:', err);
                    alert('Error accessing camera: ' + err.message);
                    closeJewelCamera();
                });
        }

        function captureJewelPhoto() {
            if (!jewelVideoStream) {
                alert('Camera is not ready');
                return;
            }
            
            const video = document.getElementById('jewelModalVideo');
            const canvas = document.getElementById('jewelModalCanvas');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            const context = canvas.getContext('2d');
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = canvas.toDataURL('image/jpeg', 0.9);
            
            // Get the row index
            const row = currentJewelRow;
            const rowIndex = row.rowIndex - 1; // Subtract header row
            
            // Set the hidden input
            const hiddenInput = row.querySelector(`input[name="jewel_photo_camera_${rowIndex}"]`);
            if (hiddenInput) {
                hiddenInput.value = imageData;
            }
            
            // Show filename indicator
            const nameSpan = row.querySelector(`#jewel_photo_name_${rowIndex + 1}`);
            if (nameSpan) {
                nameSpan.textContent = 'Photo captured';
            }
            
            closeJewelCamera();
        }

        function closeJewelCamera() {
            if (jewelVideoStream) {
                jewelVideoStream.getTracks().forEach(track => track.stop());
                jewelVideoStream = null;
            }
            document.getElementById('jewelCameraModal').classList.remove('active');
        }

        // View Loan Details
        function formatCurrency(amount) {
            const value = parseFloat(amount || 0);
            return '₹' + value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function viewLoanDetails(loanId) {
            const modal = document.getElementById('viewModal');
            const detailsBox = document.getElementById('loanDetails');
            modal.classList.add('active');
            detailsBox.className = 'alert alert-info';
            detailsBox.innerHTML = '<p>Loading loan details...</p>';

            const url = window.location.href.split('?')[0] + '?ajax=get_loan_details&loan_id=' + encodeURIComponent(loanId);

            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Unable to load loan details. Status: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data.success || !data.loan) {
                        throw new Error(data.message || 'Loan details not found');
                    }

                    const loan = data.loan;
                    const items = Array.isArray(data.items) ? data.items : [];
                    const emis = Array.isArray(data.emis) ? data.emis : [];
                    const payments = Array.isArray(data.payments) ? data.payments : [];

                    let itemsHtml = '<p style="margin:0;color:#718096;">No jewellery items found.</p>';
                    if (items.length) {
                        itemsHtml = `
                            <div style="overflow-x:auto;">
                                <table class="emi-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Jewel Name</th>
                                            <th>Karat</th>
                                            <th>Net Weight</th>
                                            <th>Qty</th>
                                            <th>Defect</th>
                                            <th>Stone</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${items.map((item, index) => `
                                            <tr>
                                                <td>${index + 1}</td>
                                                <td>${escapeHtml(item.jewel_name || '-')}</td>
                                                <td>${escapeHtml(item.karat || '-')}</td>
                                                <td>${escapeHtml(item.net_weight || '0')}</td>
                                                <td>${escapeHtml(item.quantity || '1')}</td>
                                                <td>${escapeHtml(item.defect_details || '-')}</td>
                                                <td>${escapeHtml(item.stone_details || '-')}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>`;
                    }

                    let emiHtml = '<p style="margin:0;color:#718096;">No EMI schedule found.</p>';
                    if (emis.length) {
                        emiHtml = `
                            <div style="overflow-x:auto;max-height:260px;">
                                <table class="emi-table">
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Due Date</th>
                                            <th>Principal</th>
                                            <th>Interest</th>
                                            <th>EMI</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${emis.map(emi => `
                                            <tr>
                                                <td>${escapeHtml(emi.installment_no || '')}</td>
                                                <td>${escapeHtml(emi.due_date || '-')}</td>
                                                <td>${formatCurrency(emi.principal_amount)}</td>
                                                <td>${formatCurrency(emi.interest_amount)}</td>
                                                <td>${formatCurrency(emi.emi_amount)}</td>
                                                <td><span class="status-badge ${String(emi.status || '').toLowerCase() === 'paid' ? 'status-paid' : 'status-pending'}">${escapeHtml(emi.status || '-')}</span></td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>`;
                    }

                    let paymentHtml = '<p style="margin:0;color:#718096;">No payments made yet.</p>';
                    if (payments.length) {
                        paymentHtml = `
                            <div style="overflow-x:auto;max-height:220px;">
                                <table class="emi-table">
                                    <thead>
                                        <tr>
                                            <th>Receipt</th>
                                            <th>Date</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${payments.map(payment => `
                                            <tr>
                                                <td>${escapeHtml(payment.receipt_number || '-')}</td>
                                                <td>${escapeHtml(payment.payment_date || '-')}</td>
                                                <td>${formatCurrency(payment.payment_amount)}</td>
                                                <td>${escapeHtml(payment.payment_method || '-')}</td>
                                                <td>${escapeHtml(payment.remarks || '-')}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>`;
                    }

                    detailsBox.className = '';
                    detailsBox.innerHTML = `
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px;">
                            <div class="stat-card" style="padding:14px;box-shadow:none;border:1px solid #e2e8f0;">
                                <div class="stat-content">
                                    <div class="stat-label">Loan Ref</div>
                                    <div class="stat-value" style="font-size:18px;">${escapeHtml(loan.loan_reference || '-')}</div>
                                    <div class="stat-sub">Status: ${escapeHtml(loan.status || '-')}</div>
                                </div>
                            </div>
                            <div class="stat-card" style="padding:14px;box-shadow:none;border:1px solid #e2e8f0;">
                                <div class="stat-content">
                                    <div class="stat-label">Customer</div>
                                    <div class="stat-value" style="font-size:18px;">${escapeHtml(loan.customer_name || '-')}</div>
                                    <div class="stat-sub">${escapeHtml(loan.mobile_number || '-')}</div>
                                </div>
                            </div>
                            <div class="stat-card" style="padding:14px;box-shadow:none;border:1px solid #e2e8f0;">
                                <div class="stat-content">
                                    <div class="stat-label">Loan Amount</div>
                                    <div class="stat-value" style="font-size:18px;">${formatCurrency(loan.loan_amount)}</div>
                                    <div class="stat-sub">EMI: ${formatCurrency(loan.emi_amount)}</div>
                                </div>
                            </div>
                            <div class="stat-card" style="padding:14px;box-shadow:none;border:1px solid #e2e8f0;">
                                <div class="stat-content">
                                    <div class="stat-label">Bank</div>
                                    <div class="stat-value" style="font-size:18px;">${escapeHtml(loan.bank_full_name || loan.bank_short_name || '-')}</div>
                                    <div class="stat-sub">Tenure: ${escapeHtml(loan.tenure_months || '0')} months</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-card" style="padding:16px;box-shadow:none;border:1px solid #e2e8f0;margin-bottom:16px;">
                            <div class="form-title" style="font-size:16px;margin-bottom:12px;">Loan Information</div>
                            <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:0;">
                                <div><strong>Loan Date:</strong><br>${escapeHtml(loan.loan_date || '-')}</div>
                                <div><strong>Interest Rate:</strong><br>${escapeHtml(loan.interest_rate || '0')}%</div>
                                <div><strong>Total Interest:</strong><br>${formatCurrency(loan.total_interest)}</div>
                                <div><strong>Total Payable:</strong><br>${formatCurrency(loan.total_payable)}</div>
                                <div><strong>Document Charge:</strong><br>${formatCurrency(loan.document_charge)}</div>
                                <div><strong>Processing Fee:</strong><br>${formatCurrency(loan.processing_fee)}</div>
                                <div><strong>Product Type:</strong><br>${escapeHtml(loan.product_type || '-')}</div>
                                <div><strong>Employee:</strong><br>${escapeHtml(loan.employee_name || '-')}</div>
                            </div>
                            <div style="margin-top:12px;"><strong>Remarks:</strong><br>${escapeHtml(loan.remarks || '-')}</div>
                        </div>

                        <div class="form-card" style="padding:16px;box-shadow:none;border:1px solid #e2e8f0;margin-bottom:16px;">
                            <div class="form-title" style="font-size:16px;margin-bottom:12px;">Jewellery Items</div>
                            ${itemsHtml}
                        </div>

                        <div class="form-card" style="padding:16px;box-shadow:none;border:1px solid #e2e8f0;margin-bottom:16px;">
                            <div class="form-title" style="font-size:16px;margin-bottom:12px;">EMI Schedule</div>
                            ${emiHtml}
                        </div>

                        <div class="form-card" style="padding:16px;box-shadow:none;border:1px solid #e2e8f0;margin-bottom:0;">
                            <div class="form-title" style="font-size:16px;margin-bottom:12px;">Payment History</div>
                            ${paymentHtml}
                        </div>`;
                })
                .catch(error => {
                    detailsBox.className = 'alert alert-error';
                    detailsBox.innerHTML = '<p>' + escapeHtml(error.message || 'Unable to load loan details') + '</p>';
                });
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        // Make Payment
        function makePayment(loanId) {
            document.getElementById('payment_loan_id').value = loanId;
            document.getElementById('paymentModal').classList.add('active');
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
            if (event.target.classList.contains('camera-modal')) {
                closeJewelCamera();
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