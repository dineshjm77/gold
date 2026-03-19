<?php
session_start();
$currentPage = 'payment-type';
$pageTitle = 'Payment Types';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if (!in_array($_SESSION['user_role'], ['admin', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create payment_types table if it doesn't exist
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'payment_types'");
if (mysqli_num_rows($table_check) == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `payment_types` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `payment_code` varchar(20) NOT NULL,
        `payment_name` varchar(100) NOT NULL,
        `display_name` varchar(100) NOT NULL,
        `icon` varchar(50) DEFAULT 'bi-cash',
        `color` varchar(20) DEFAULT '#48bb78',
        `is_active` tinyint(1) DEFAULT 1,
        `is_default` tinyint(1) DEFAULT 0,
        `requires_reference` tinyint(1) DEFAULT 0,
        `requires_bank` tinyint(1) DEFAULT 0,
        `requires_account` tinyint(1) DEFAULT 0,
        `requires_cheque_details` tinyint(1) DEFAULT 0,
        `requires_upi_details` tinyint(1) DEFAULT 0,
        `sort_order` int(11) DEFAULT 0,
        `description` text DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `payment_code` (`payment_code`),
        KEY `is_active` (`is_active`),
        KEY `sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_table);
    
    // Insert default payment types
    $insert_defaults = "INSERT INTO `payment_types` (`payment_code`, `payment_name`, `display_name`, `icon`, `color`, `is_default`, `requires_reference`, `sort_order`) VALUES
        ('cash', 'Cash', 'Cash Payment', 'bi-cash', '#48bb78', 1, 0, 1),
        ('bank', 'Bank Transfer', 'Bank Transfer', 'bi-bank', '#4299e1', 0, 1, 2),
        ('upi', 'UPI', 'UPI Payment', 'bi-phone', '#9f7aea', 0, 1, 3),
        ('cheque', 'Cheque', 'Cheque Payment', 'bi-file-text', '#f56565', 0, 1, 4),
        ('card', 'Card', 'Card Payment', 'bi-credit-card', '#ed8936', 0, 1, 5),
        ('other', 'Other', 'Other Payment', 'bi-three-dots', '#718096', 0, 1, 6)";
    
    mysqli_query($conn, $insert_defaults);
}

// Create payment_method_settings table
$settings_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'payment_method_settings'");
if (mysqli_num_rows($settings_table_check) == 0) {
    $create_settings = "CREATE TABLE IF NOT EXISTS `payment_method_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `payment_type_id` int(11) NOT NULL,
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text DEFAULT NULL,
        `setting_type` enum('text','number','boolean','json') DEFAULT 'text',
        `description` text DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `payment_type_id` (`payment_type_id`),
        UNIQUE KEY `payment_setting` (`payment_type_id`, `setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_settings);
}

// Create bank_payment_details table for bank-specific settings
$bank_details_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_payment_details'");
if (mysqli_num_rows($bank_details_check) == 0) {
    $create_bank_details = "CREATE TABLE IF NOT EXISTS `bank_payment_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `payment_type_id` int(11) NOT NULL,
        `bank_id` int(11) DEFAULT NULL,
        `bank_account_id` int(11) DEFAULT NULL,
        `upi_id` varchar(100) DEFAULT NULL,
        `qr_code_path` varchar(255) DEFAULT NULL,
        `account_holder` varchar(150) DEFAULT NULL,
        `is_default` tinyint(1) DEFAULT 0,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `payment_type_id` (`payment_type_id`),
        KEY `bank_id` (`bank_id`),
        KEY `bank_account_id` (`bank_account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_bank_details);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_payment_type':
                $payment_code = mysqli_real_escape_string($conn, $_POST['payment_code']);
                $payment_name = mysqli_real_escape_string($conn, $_POST['payment_name']);
                $display_name = mysqli_real_escape_string($conn, $_POST['display_name']);
                $icon = mysqli_real_escape_string($conn, $_POST['icon'] ?? 'bi-cash');
                $color = mysqli_real_escape_string($conn, $_POST['color'] ?? '#48bb78');
                $requires_reference = isset($_POST['requires_reference']) ? 1 : 0;
                $requires_bank = isset($_POST['requires_bank']) ? 1 : 0;
                $requires_account = isset($_POST['requires_account']) ? 1 : 0;
                $requires_cheque_details = isset($_POST['requires_cheque_details']) ? 1 : 0;
                $requires_upi_details = isset($_POST['requires_upi_details']) ? 1 : 0;
                $sort_order = intval($_POST['sort_order'] ?? 0);
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                
                // Check if payment code already exists
                $check_query = "SELECT id FROM payment_types WHERE payment_code = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $payment_code);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Payment code already exists!";
                } else {
                    $insert_query = "INSERT INTO payment_types (
                        payment_code, payment_name, display_name, icon, color,
                        requires_reference, requires_bank, requires_account,
                        requires_cheque_details, requires_upi_details, sort_order,
                        description, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'sssssiiiiiiis', 
                        $payment_code, $payment_name, $display_name, $icon, $color,
                        $requires_reference, $requires_bank, $requires_account,
                        $requires_cheque_details, $requires_upi_details, $sort_order,
                        $description
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "Payment type added successfully!";
                    } else {
                        $error = "Error adding payment type: " . mysqli_stmt_error($stmt);
                    }
                }
                break;
                
            case 'edit_payment_type':
                $id = intval($_POST['id']);
                $payment_name = mysqli_real_escape_string($conn, $_POST['payment_name']);
                $display_name = mysqli_real_escape_string($conn, $_POST['display_name']);
                $icon = mysqli_real_escape_string($conn, $_POST['icon'] ?? 'bi-cash');
                $color = mysqli_real_escape_string($conn, $_POST['color'] ?? '#48bb78');
                $requires_reference = isset($_POST['requires_reference']) ? 1 : 0;
                $requires_bank = isset($_POST['requires_bank']) ? 1 : 0;
                $requires_account = isset($_POST['requires_account']) ? 1 : 0;
                $requires_cheque_details = isset($_POST['requires_cheque_details']) ? 1 : 0;
                $requires_upi_details = isset($_POST['requires_upi_details']) ? 1 : 0;
                $sort_order = intval($_POST['sort_order'] ?? 0);
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $update_query = "UPDATE payment_types SET 
                                payment_name = ?, display_name = ?, icon = ?, color = ?,
                                requires_reference = ?, requires_bank = ?, requires_account = ?,
                                requires_cheque_details = ?, requires_upi_details = ?,
                                sort_order = ?, description = ?, is_active = ?
                                WHERE id = ?";
                
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, 'ssssiiiiiiisi', 
                    $payment_name, $display_name, $icon, $color,
                    $requires_reference, $requires_bank, $requires_account,
                    $requires_cheque_details, $requires_upi_details,
                    $sort_order, $description, $is_active, $id
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Payment type updated successfully!";
                } else {
                    $error = "Error updating payment type: " . mysqli_stmt_error($stmt);
                }
                break;
                
            case 'delete_payment_type':
                $id = intval($_POST['id']);
                
                // Check if it's a default payment type
                $check_query = "SELECT is_default FROM payment_types WHERE id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $payment = mysqli_fetch_assoc($check_result);
                
                if ($payment && $payment['is_default'] == 1) {
                    $error = "Cannot delete default payment type!";
                } else {
                    $delete_query = "DELETE FROM payment_types WHERE id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $id);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        $message = "Payment type deleted successfully!";
                    } else {
                        $error = "Error deleting payment type: " . mysqli_stmt_error($delete_stmt);
                    }
                }
                break;
                
            case 'set_default':
                $id = intval($_POST['id']);
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Remove default from all
                    $reset_query = "UPDATE payment_types SET is_default = 0";
                    mysqli_query($conn, $reset_query);
                    
                    // Set new default
                    $set_query = "UPDATE payment_types SET is_default = 1 WHERE id = ?";
                    $set_stmt = mysqli_prepare($conn, $set_query);
                    mysqli_stmt_bind_param($set_stmt, 'i', $id);
                    mysqli_stmt_execute($set_stmt);
                    
                    mysqli_commit($conn);
                    $message = "Default payment type updated successfully!";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error setting default: " . $e->getMessage();
                }
                break;
                
            case 'save_bank_details':
                $payment_type_id = intval($_POST['payment_type_id']);
                $bank_id = !empty($_POST['bank_id']) ? intval($_POST['bank_id']) : null;
                $bank_account_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
                $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
                $account_holder = mysqli_real_escape_string($conn, $_POST['account_holder'] ?? '');
                $is_default = isset($_POST['is_default']) ? 1 : 0;
                
                // Handle QR code upload
                $qr_code_path = null;
                if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
                    $upload_dir = 'uploads/qr_codes/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $ext = pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION);
                    $filename = 'qr_' . $payment_type_id . '_' . time() . '.' . $ext;
                    $filepath = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $filepath)) {
                        $qr_code_path = $filepath;
                    }
                }
                
                // Check if details already exist
                $check_query = "SELECT id FROM bank_payment_details WHERE payment_type_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $payment_type_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    // Update existing
                    $row = mysqli_fetch_assoc($check_result);
                    $update_query = "UPDATE bank_payment_details SET 
                                    bank_id = ?, bank_account_id = ?, upi_id = ?,
                                    account_holder = ?, is_default = ?";
                    
                    if ($qr_code_path) {
                        $update_query .= ", qr_code_path = ?";
                    }
                    
                    $update_query .= " WHERE payment_type_id = ?";
                    
                    $update_stmt = mysqli_prepare($conn, $update_query);
                    if ($qr_code_path) {
                        mysqli_stmt_bind_param($update_stmt, 'iissisi', 
                            $bank_id, $bank_account_id, $upi_id, $account_holder, $is_default, $qr_code_path, $payment_type_id);
                    } else {
                        mysqli_stmt_bind_param($update_stmt, 'iissii', 
                            $bank_id, $bank_account_id, $upi_id, $account_holder, $is_default, $payment_type_id);
                    }
                } else {
                    // Insert new
                    $insert_query = "INSERT INTO bank_payment_details (
                        payment_type_id, bank_id, bank_account_id, upi_id, 
                        account_holder, qr_code_path, is_default
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($insert_stmt, 'iiisssi', 
                        $payment_type_id, $bank_id, $bank_account_id, $upi_id, 
                        $account_holder, $qr_code_path, $is_default
                    );
                }
                
                if (mysqli_stmt_execute($stmt ?? $insert_stmt)) {
                    $message = "Bank details saved successfully!";
                } else {
                    $error = "Error saving bank details: " . mysqli_stmt_error($stmt ?? $insert_stmt);
                }
                break;
        }
    }
}

// Get all payment types
$payment_types_query = "SELECT * FROM payment_types ORDER BY sort_order ASC, payment_name ASC";
$payment_types_result = mysqli_query($conn, $payment_types_query);

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get bank accounts for dropdown
$accounts_query = "SELECT ba.*, bm.bank_short_name 
                   FROM bank_accounts ba 
                   JOIN bank_master bm ON ba.bank_id = bm.id 
                   WHERE ba.status = 1 
                   ORDER BY bm.bank_short_name, ba.account_holder_no";
$accounts_result = mysqli_query($conn, $accounts_query);

// Get payment method settings
$settings_query = "SELECT ps.*, pt.payment_name 
                   FROM payment_method_settings ps
                   JOIN payment_types pt ON ps.payment_type_id = pt.id
                   ORDER BY pt.sort_order, ps.setting_key";
$settings_result = mysqli_query($conn, $settings_query);

// Get bank payment details
$bank_details_query = "SELECT bpd.*, pt.payment_name, pt.payment_code,
                       bm.bank_full_name, bm.bank_short_name,
                       ba.account_holder_no, ba.bank_account_no
                       FROM bank_payment_details bpd
                       JOIN payment_types pt ON bpd.payment_type_id = pt.id
                       LEFT JOIN bank_master bm ON bpd.bank_id = bm.id
                       LEFT JOIN bank_accounts ba ON bpd.bank_account_id = ba.id
                       ORDER BY pt.sort_order";
$bank_details_result = mysqli_query($conn, $bank_details_query);

// Icon options
$icon_options = [
    'bi-cash' => 'Cash',
    'bi-bank' => 'Bank',
    'bi-phone' => 'Phone/UPI',
    'bi-file-text' => 'File/Cheque',
    'bi-credit-card' => 'Credit Card',
    'bi-paypal' => 'PayPal',
    'bi-google' => 'Google Pay',
    'bi-apple' => 'Apple Pay',
    'bi-wallet' => 'Wallet',
    'bi-cash-stack' => 'Cash Stack',
    'bi-coin' => 'Coin',
    'bi-calculator' => 'Calculator',
    'bi-receipt' => 'Receipt',
    'bi-cart' => 'Cart',
    'bi-bag' => 'Bag',
    'bi-gift' => 'Gift',
    'bi-star' => 'Star',
    'bi-heart' => 'Heart',
    'bi-emoji-smile' => 'Smile',
    'bi-three-dots' => 'Other'
];

// Color options
$color_options = [
    '#48bb78' => 'Green',
    '#4299e1' => 'Blue',
    '#9f7aea' => 'Purple',
    '#f56565' => 'Red',
    '#ed8936' => 'Orange',
    '#ecc94b' => 'Yellow',
    '#667eea' => 'Indigo',
    '#38b2ac' => 'Teal',
    '#805ad5' => 'Violet',
    '#dd6b20' => 'Orange Dark',
    '#d53f8c' => 'Pink',
    '#718096' => 'Gray'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Color Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@simonwep/pickr/dist/themes/classic.min.css"/>
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: white;
            color: #4a5568;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .tab:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }

        .tab.active {
            background: #667eea;
            color: white;
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

        /* Icon Preview */
        .icon-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
        }

        .icon-preview i {
            font-size: 24px;
        }

        .color-preview {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: 2px solid #e2e8f0;
        }

        /* Checkbox Grid */
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 15px 0;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-item label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
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

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .payment-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .payment-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .payment-table tbody tr:hover {
            background: #f7fafc;
        }

        .payment-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f7fafc;
            font-size: 18px;
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

        .badge-inactive {
            background: #a0aec0;
            color: white;
        }

        .badge-default {
            background: #667eea;
            color: white;
        }

        .badge-required {
            background: #ecc94b;
            color: #744210;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: white;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-icon.edit:hover {
            background: #667eea;
            color: white;
        }

        .btn-icon.delete:hover {
            background: #f56565;
            color: white;
        }

        .btn-icon.settings:hover {
            background: #4299e1;
            color: white;
        }

        .btn-icon.default:hover {
            background: #48bb78;
            color: white;
        }

        /* Settings Card */
        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #e2e8f0;
        }

        .settings-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .qr-preview {
            width: 100px;
            height: 100px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            object-fit: cover;
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
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .modal-close {
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
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
            
            .checkbox-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer .btn {
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
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-credit-card"></i>
                            Payment Types
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showAddModal()">
                                <i class="bi bi-plus-circle"></i> Add Payment Type
                            </button>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Summary Statistics -->
                    <?php
                    $total_types = mysqli_num_rows($payment_types_result);
                    $active_types = 0;
                    $default_type = '';
                    
                    mysqli_data_seek($payment_types_result, 0);
                    while ($pt = mysqli_fetch_assoc($payment_types_result)) {
                        if ($pt['is_active']) $active_types++;
                        if ($pt['is_default']) $default_type = $pt['display_name'];
                    }
                    mysqli_data_seek($payment_types_result, 0);
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-credit-card"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Payment Types</div>
                                <div class="stat-value"><?php echo $total_types; ?></div>
                                <div class="stat-sub">Registered methods</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Types</div>
                                <div class="stat-value"><?php echo $active_types; ?></div>
                                <div class="stat-sub">Currently enabled</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-star-fill"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Default Type</div>
                                <div class="stat-value"><?php echo $default_type ?: 'None'; ?></div>
                                <div class="stat-sub">Primary payment method</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-gear"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Bank Details</div>
                                <div class="stat-value"><?php echo mysqli_num_rows($bank_details_result); ?></div>
                                <div class="stat-sub">Configured banks</div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab active" onclick="showTab('payment-types')">
                            <i class="bi bi-credit-card"></i> Payment Types
                        </button>
                        <button class="tab" onclick="showTab('bank-details')">
                            <i class="bi bi-bank"></i> Bank Details
                        </button>
                        <button class="tab" onclick="showTab('settings')">
                            <i class="bi bi-gear"></i> Settings
                        </button>
                    </div>

                    <!-- Payment Types Tab -->
                    <div id="payment-types-tab" class="tab-content">
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Payment Methods</span>
                                <span class="text-muted">Manage all payment types</span>
                            </div>
                            <div class="table-responsive">
                                <table class="payment-table">
                                    <thead>
                                        <tr>
                                            <th>Icon</th>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>Display Name</th>
                                            <th>Requirements</th>
                                            <th>Status</th>
                                            <th>Default</th>
                                            <th>Order</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        mysqli_data_seek($payment_types_result, 0);
                                        while ($pt = mysqli_fetch_assoc($payment_types_result)): 
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="payment-icon" style="color: <?php echo $pt['color']; ?>;">
                                                    <i class="bi <?php echo $pt['icon']; ?>"></i>
                                                </div>
                                            </td>
                                            <td><strong><?php echo $pt['payment_code']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($pt['payment_name']); ?></td>
                                            <td><?php echo htmlspecialchars($pt['display_name']); ?></td>
                                            <td>
                                                <?php if ($pt['requires_reference']): ?>
                                                    <span class="badge badge-required">Ref</span>
                                                <?php endif; ?>
                                                <?php if ($pt['requires_bank']): ?>
                                                    <span class="badge badge-required">Bank</span>
                                                <?php endif; ?>
                                                <?php if ($pt['requires_account']): ?>
                                                    <span class="badge badge-required">Account</span>
                                                <?php endif; ?>
                                                <?php if ($pt['requires_cheque_details']): ?>
                                                    <span class="badge badge-required">Cheque</span>
                                                <?php endif; ?>
                                                <?php if ($pt['requires_upi_details']): ?>
                                                    <span class="badge badge-required">UPI</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $pt['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $pt['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($pt['is_default']): ?>
                                                    <span class="badge badge-default">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $pt['sort_order']; ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon edit" onclick='editPaymentType(<?php echo json_encode($pt); ?>)' title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if (!$pt['is_default']): ?>
                                                        <button class="btn-icon default" onclick="setDefault(<?php echo $pt['id']; ?>)" title="Set as Default">
                                                            <i class="bi bi-star"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($pt['payment_code'], ['bank', 'upi', 'cheque'])): ?>
                                                        <button class="btn-icon settings" onclick="showBankDetails(<?php echo $pt['id']; ?>)" title="Bank Details">
                                                            <i class="bi bi-bank"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (!$pt['is_default']): ?>
                                                        <button class="btn-icon delete" onclick="deletePaymentType(<?php echo $pt['id']; ?>)" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details Tab -->
                    <div id="bank-details-tab" class="tab-content" style="display: none;">
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Bank Payment Details</span>
                                <button class="btn btn-primary btn-sm" onclick="showAddBankDetails()">
                                    <i class="bi bi-plus-circle"></i> Add Bank Details
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="payment-table">
                                    <thead>
                                        <tr>
                                            <th>Payment Type</th>
                                            <th>Bank</th>
                                            <th>Account</th>
                                            <th>Account Holder</th>
                                            <th>UPI ID</th>
                                            <th>QR Code</th>
                                            <th>Default</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        mysqli_data_seek($bank_details_result, 0);
                                        while ($bd = mysqli_fetch_assoc($bank_details_result)): 
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($bd['payment_name']); ?></td>
                                            <td><?php echo htmlspecialchars($bd['bank_full_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($bd['bank_account_no'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($bd['account_holder'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($bd['upi_id'] ?? '-'); ?></td>
                                            <td>
                                                <?php if ($bd['qr_code_path']): ?>
                                                    <img src="<?php echo $bd['qr_code_path']; ?>" class="qr-preview" alt="QR Code">
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($bd['is_default']): ?>
                                                    <span class="badge badge-default">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $bd['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $bd['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon edit" onclick='editBankDetails(<?php echo json_encode($bd); ?>)' title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-icon delete" onclick="deleteBankDetails(<?php echo $bd['id']; ?>)" title="Delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Settings Tab -->
                    <div id="settings-tab" class="tab-content" style="display: none;">
                        <div class="form-card">
                            <div class="form-title">
                                <i class="bi bi-gear"></i>
                                Payment Settings
                            </div>
                            
                            <?php 
                            mysqli_data_seek($payment_types_result, 0);
                            while ($pt = mysqli_fetch_assoc($payment_types_result)): 
                            ?>
                            <div class="settings-card">
                                <div class="settings-title">
                                    <i class="bi <?php echo $pt['icon']; ?>" style="color: <?php echo $pt['color']; ?>;"></i>
                                    <?php echo htmlspecialchars($pt['display_name']); ?> Settings
                                </div>
                                
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Display Name</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($pt['display_name']); ?>" readonly>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Sort Order</label>
                                        <input type="number" class="form-control" value="<?php echo $pt['sort_order']; ?>" readonly>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <input type="text" class="form-control" value="<?php echo $pt['is_active'] ? 'Active' : 'Inactive'; ?>" readonly>
                                    </div>
                                </div>
                                
                                <div class="checkbox-grid">
                                    <div class="checkbox-item">
                                        <input type="checkbox" <?php echo $pt['requires_reference'] ? 'checked' : ''; ?> disabled>
                                        <label>Requires Reference Number</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" <?php echo $pt['requires_bank'] ? 'checked' : ''; ?> disabled>
                                        <label>Requires Bank Selection</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" <?php echo $pt['requires_account'] ? 'checked' : ''; ?> disabled>
                                        <label>Requires Account Details</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" <?php echo $pt['requires_cheque_details'] ? 'checked' : ''; ?> disabled>
                                        <label>Requires Cheque Details</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" <?php echo $pt['requires_upi_details'] ? 'checked' : ''; ?> disabled>
                                        <label>Requires UPI Details</label>
                                    </div>
                                </div>
                                
                                <?php if (!empty($pt['description'])): ?>
                                <div style="margin-top: 10px; padding: 10px; background: #f7fafc; border-radius: 6px;">
                                    <small style="color: #718096;"><?php echo htmlspecialchars($pt['description']); ?></small>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add/Edit Payment Type Modal -->
    <div class="modal" id="paymentTypeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Payment Type</h3>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            
            <form method="POST" action="" id="paymentTypeForm">
                <input type="hidden" name="action" id="formAction" value="add_payment_type">
                <input type="hidden" name="id" id="paymentTypeId" value="">
                
                <div class="form-group">
                    <label class="form-label required">Payment Code</label>
                    <div class="input-group">
                        <i class="bi bi-code input-icon"></i>
                        <input type="text" class="form-control" name="payment_code" id="paymentCode" required maxlength="20" placeholder="e.g., cash, bank, upi">
                    </div>
                    <small>Unique identifier (lowercase, no spaces)</small>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Payment Name</label>
                        <div class="input-group">
                            <i class="bi bi-tag input-icon"></i>
                            <input type="text" class="form-control" name="payment_name" id="paymentName" required placeholder="e.g., Cash">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Display Name</label>
                        <div class="input-group">
                            <i class="bi bi-card-text input-icon"></i>
                            <input type="text" class="form-control" name="display_name" id="displayName" required placeholder="e.g., Cash Payment">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <div class="input-group">
                            <i class="bi bi-image input-icon"></i>
                            <select class="form-select" name="icon" id="icon" onchange="updateIconPreview()">
                                <?php foreach ($icon_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" data-icon="<?php echo $value; ?>">
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="icon-preview">
                            <i class="bi" id="iconPreview"></i>
                            <span id="iconName"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <div class="input-group">
                            <i class="bi bi-palette input-icon"></i>
                            <select class="form-select" name="color" id="color" onchange="updateColorPreview()">
                                <?php foreach ($color_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" style="color: <?php echo $value; ?>;">
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="icon-preview">
                            <div class="color-preview" id="colorPreview"></div>
                            <span id="colorName"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <div class="input-group">
                            <i class="bi bi-sort-numeric-down input-icon"></i>
                            <input type="number" class="form-control" name="sort_order" id="sortOrder" value="0" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="form-title" style="margin-top: 20px;">Required Fields</div>
                
                <div class="checkbox-grid">
                    <div class="checkbox-item">
                        <input type="checkbox" name="requires_reference" id="requiresReference">
                        <label for="requiresReference">Requires Reference Number</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="requires_bank" id="requiresBank">
                        <label for="requiresBank">Requires Bank Selection</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="requires_account" id="requiresAccount">
                        <label for="requiresAccount">Requires Account Details</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="requires_cheque_details" id="requiresCheque">
                        <label for="requiresCheque">Requires Cheque Details</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" name="requires_upi_details" id="requiresUPI">
                        <label for="requiresUPI">Requires UPI Details</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description (Optional)</label>
                    <textarea class="form-control" name="description" id="description" rows="3" placeholder="Enter description..."></textarea>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="checkbox-item">
                        <input type="checkbox" name="is_active" id="isActive" checked>
                        <label for="isActive">Active</label>
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bank Details Modal -->
    <div class="modal" id="bankDetailsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Bank Payment Details</h3>
                <span class="modal-close" onclick="closeBankDetailsModal()">&times;</span>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" id="bankDetailsForm">
                <input type="hidden" name="action" value="save_bank_details">
                <input type="hidden" name="payment_type_id" id="bankPaymentTypeId" value="">
                <input type="hidden" name="details_id" id="bankDetailsId" value="">
                
                <div class="form-group">
                    <label class="form-label required">Payment Type</label>
                    <select class="form-select" name="payment_type_id" id="bankPaymentType" required onchange="loadPaymentTypeDetails()">
                        <option value="">Select Payment Type</option>
                        <?php 
                        mysqli_data_seek($payment_types_result, 0);
                        while ($pt = mysqli_fetch_assoc($payment_types_result)): 
                            if (in_array($pt['payment_code'], ['bank', 'upi', 'cheque'])): 
                        ?>
                            <option value="<?php echo $pt['id']; ?>" data-code="<?php echo $pt['payment_code']; ?>">
                                <?php echo htmlspecialchars($pt['display_name']); ?>
                            </option>
                        <?php 
                            endif;
                        endwhile; 
                        ?>
                    </select>
                </div>
                
                <div id="bankSpecificFields">
                    <!-- Bank specific fields will be shown/hidden based on payment type -->
                </div>
                
                <div class="form-group">
                    <label class="form-label">Account Holder Name</label>
                    <input type="text" class="form-control" name="account_holder" id="accountHolder">
                </div>
                
                <div class="form-group">
                    <label class="form-label">UPI ID</label>
                    <input type="text" class="form-control" name="upi_id" id="upiId" placeholder="example@bank">
                </div>
                
                <div class="form-group">
                    <label class="form-label">QR Code</label>
                    <input type="file" class="form-control" name="qr_code" accept="image/*">
                    <small>Upload QR code image (PNG, JPG)</small>
                    <div id="qrPreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="checkbox-item">
                    <input type="checkbox" name="is_default" id="isDefaultBank">
                    <label for="isDefaultBank">Set as default for this payment type</label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeBankDetailsModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Details</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Include required JS files -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.form-select').select2({
                width: '100%'
            });
        });

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').style.display = 'block';
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Icon preview
        function updateIconPreview() {
            const select = document.getElementById('icon');
            const icon = select.value;
            const preview = document.getElementById('iconPreview');
            preview.className = 'bi ' + icon;
            document.getElementById('iconName').textContent = select.options[select.selectedIndex].text;
        }

        // Color preview
        function updateColorPreview() {
            const select = document.getElementById('color');
            const color = select.value;
            const preview = document.getElementById('colorPreview');
            preview.style.backgroundColor = color;
            document.getElementById('colorName').textContent = select.options[select.selectedIndex].text;
        }

        // Show add modal
        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Payment Type';
            document.getElementById('formAction').value = 'add_payment_type';
            document.getElementById('paymentTypeId').value = '';
            document.getElementById('paymentCode').value = '';
            document.getElementById('paymentCode').readOnly = false;
            document.getElementById('paymentName').value = '';
            document.getElementById('displayName').value = '';
            document.getElementById('icon').value = 'bi-cash';
            document.getElementById('color').value = '#48bb78';
            document.getElementById('sortOrder').value = '0';
            document.getElementById('requiresReference').checked = false;
            document.getElementById('requiresBank').checked = false;
            document.getElementById('requiresAccount').checked = false;
            document.getElementById('requiresCheque').checked = false;
            document.getElementById('requiresUPI').checked = false;
            document.getElementById('description').value = '';
            document.getElementById('statusField').style.display = 'none';
            
            updateIconPreview();
            updateColorPreview();
            
            document.getElementById('paymentTypeModal').classList.add('active');
        }

        // Edit payment type
        function editPaymentType(data) {
            document.getElementById('modalTitle').textContent = 'Edit Payment Type';
            document.getElementById('formAction').value = 'edit_payment_type';
            document.getElementById('paymentTypeId').value = data.id;
            document.getElementById('paymentCode').value = data.payment_code;
            document.getElementById('paymentCode').readOnly = true;
            document.getElementById('paymentName').value = data.payment_name;
            document.getElementById('displayName').value = data.display_name;
            document.getElementById('icon').value = data.icon;
            document.getElementById('color').value = data.color;
            document.getElementById('sortOrder').value = data.sort_order;
            document.getElementById('requiresReference').checked = data.requires_reference == 1;
            document.getElementById('requiresBank').checked = data.requires_bank == 1;
            document.getElementById('requiresAccount').checked = data.requires_account == 1;
            document.getElementById('requiresCheque').checked = data.requires_cheque_details == 1;
            document.getElementById('requiresUPI').checked = data.requires_upi_details == 1;
            document.getElementById('description').value = data.description || '';
            document.getElementById('isActive').checked = data.is_active == 1;
            document.getElementById('statusField').style.display = 'block';
            
            updateIconPreview();
            updateColorPreview();
            
            document.getElementById('paymentTypeModal').classList.add('active');
        }

        // Set as default
        function setDefault(id) {
            if (confirm('Set this as the default payment type?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="set_default">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Delete payment type
        function deletePaymentType(id) {
            if (confirm('Are you sure you want to delete this payment type? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_payment_type">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Show bank details modal
        function showBankDetails(paymentTypeId) {
            document.getElementById('bankPaymentTypeId').value = paymentTypeId;
            document.getElementById('bankPaymentType').value = paymentTypeId;
            loadPaymentTypeDetails();
            document.getElementById('bankDetailsModal').classList.add('active');
        }

        // Show add bank details
        function showAddBankDetails() {
            document.getElementById('bankPaymentTypeId').value = '';
            document.getElementById('bankPaymentType').value = '';
            document.getElementById('bankDetailsId').value = '';
            document.getElementById('accountHolder').value = '';
            document.getElementById('upiId').value = '';
            document.getElementById('qrPreview').innerHTML = '';
            document.getElementById('isDefaultBank').checked = false;
            
            // Clear bank specific fields
            document.getElementById('bankSpecificFields').innerHTML = '';
            
            document.getElementById('bankDetailsModal').classList.add('active');
        }

        // Edit bank details
        function editBankDetails(data) {
            document.getElementById('bankPaymentTypeId').value = data.payment_type_id;
            document.getElementById('bankPaymentType').value = data.payment_type_id;
            document.getElementById('bankDetailsId').value = data.id;
            document.getElementById('accountHolder').value = data.account_holder || '';
            document.getElementById('upiId').value = data.upi_id || '';
            document.getElementById('isDefaultBank').checked = data.is_default == 1;
            
            if (data.qr_code_path) {
                document.getElementById('qrPreview').innerHTML = `<img src="${data.qr_code_path}" class="qr-preview" alt="QR Code">`;
            }
            
            loadPaymentTypeDetails(data);
            document.getElementById('bankDetailsModal').classList.add('active');
        }

        // Load payment type specific fields
        function loadPaymentTypeDetails(existingData) {
            const paymentTypeId = document.getElementById('bankPaymentType').value;
            const select = document.getElementById('bankPaymentType');
            const option = select.options[select.selectedIndex];
            const code = option ? option.getAttribute('data-code') : '';
            
            let html = '';
            
            if (code === 'bank' || code === 'cheque') {
                html += `
                    <div class="form-group">
                        <label class="form-label">Bank</label>
                        <select class="form-select" name="bank_id" id="bankSelect">
                            <option value="">Select Bank</option>
                            <?php 
                            mysqli_data_seek($banks_result, 0);
                            while ($bank = mysqli_fetch_assoc($banks_result)): 
                            ?>
                            <option value="<?php echo $bank['id']; ?>">
                                <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bank Account</label>
                        <select class="form-select" name="bank_account_id" id="accountSelect">
                            <option value="">Select Account</option>
                            <?php 
                            mysqli_data_seek($accounts_result, 0);
                            while ($acc = mysqli_fetch_assoc($accounts_result)): 
                            ?>
                            <option value="<?php echo $acc['id']; ?>" data-bank="<?php echo $acc['bank_id']; ?>">
                                <?php echo htmlspecialchars($acc['bank_short_name'] . ' - ' . $acc['account_holder_no']); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                `;
            }
            
            if (code === 'upi') {
                html += `
                    <div class="form-group">
                        <label class="form-label">UPI ID</label>
                        <input type="text" class="form-control" name="upi_id" value="${existingData?.upi_id || ''}" placeholder="example@bank">
                    </div>
                `;
            }
            
            document.getElementById('bankSpecificFields').innerHTML = html;
            
            // Set existing values if editing
            if (existingData) {
                if (existingData.bank_id) {
                    document.getElementById('bankSelect').value = existingData.bank_id;
                }
                if (existingData.bank_account_id) {
                    document.getElementById('accountSelect').value = existingData.bank_account_id;
                }
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('paymentTypeModal').classList.remove('active');
        }

        function closeBankDetailsModal() {
            document.getElementById('bankDetailsModal').classList.remove('active');
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);

        // Initialize preview on load
        document.addEventListener('DOMContentLoaded', function() {
            updateIconPreview();
            updateColorPreview();
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>