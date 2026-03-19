<?php
session_start();
$currentPage = 'upi-type';
$pageTitle = 'UPI Payment Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if (!in_array($_SESSION['user_role'], ['admin', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create upi_apps table if it doesn't exist
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'upi_apps'");
if (mysqli_num_rows($table_check) == 0) {
    $create_apps = "CREATE TABLE IF NOT EXISTS `upi_apps` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `app_name` varchar(100) NOT NULL,
        `app_code` varchar(50) NOT NULL,
        `icon` varchar(50) DEFAULT 'bi-phone',
        `color` varchar(20) DEFAULT '#9f7aea',
        `website` varchar(255) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `is_default` tinyint(1) DEFAULT 0,
        `sort_order` int(11) DEFAULT 0,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `app_code` (`app_code`),
        KEY `is_active` (`is_active`),
        KEY `sort_order` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_apps);
    
    // Insert default UPI apps
    $insert_apps = "INSERT INTO `upi_apps` (`app_name`, `app_code`, `icon`, `color`, `website`, `sort_order`) VALUES
        ('Google Pay', 'googlepay', 'bi-google', '#4285F4', 'https://pay.google.com', 1),
        ('PhonePe', 'phonepe', 'bi-phone', '#5F259F', 'https://www.phonepe.com', 2),
        ('Paytm', 'paytm', 'bi-cash', '#00BAF2', 'https://paytm.com', 3),
        ('Amazon Pay', 'amazonpay', 'bi-amazon', '#FF9900', 'https://www.amazonpay.in', 4),
        ('BHIM', 'bhim', 'bi-bank', '#004C8F', 'https://www.bhimupi.org.in', 5),
        ('WhatsApp Pay', 'whatsapp', 'bi-whatsapp', '#25D366', 'https://pay.whatsapp.com', 6),
        ('Other UPI', 'other', 'bi-three-dots', '#718096', null, 7)";
    
    mysqli_query($conn, $insert_apps);
}

// Create upi_ids table
$upi_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'upi_ids'");
if (mysqli_num_rows($upi_table_check) == 0) {
    $create_upi = "CREATE TABLE IF NOT EXISTS `upi_ids` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `upi_id` varchar(100) NOT NULL,
        `account_holder` varchar(150) NOT NULL,
        `app_id` int(11) DEFAULT NULL,
        `bank_id` int(11) DEFAULT NULL,
        `qr_code_path` varchar(255) DEFAULT NULL,
        `is_primary` tinyint(1) DEFAULT 0,
        `is_active` tinyint(1) DEFAULT 1,
        `daily_limit` decimal(15,2) DEFAULT NULL,
        `transaction_limit` decimal(15,2) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `upi_id` (`upi_id`),
        KEY `app_id` (`app_id`),
        KEY `bank_id` (`bank_id`),
        KEY `is_primary` (`is_primary`),
        KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_upi);
}

// Create upi_transactions table for tracking
$trans_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'upi_transactions'");
if (mysqli_num_rows($trans_table_check) == 0) {
    $create_trans = "CREATE TABLE IF NOT EXISTS `upi_transactions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `upi_id` varchar(100) NOT NULL,
        `transaction_id` varchar(100) NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `payer_name` varchar(150) DEFAULT NULL,
        `payer_vpa` varchar(100) DEFAULT NULL,
        `reference` varchar(255) DEFAULT NULL,
        `status` enum('success','pending','failed') DEFAULT 'pending',
        `payment_id` int(11) DEFAULT NULL,
        `loan_id` int(11) DEFAULT NULL,
        `transaction_date` datetime DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `transaction_id` (`transaction_id`),
        KEY `upi_id` (`upi_id`),
        KEY `status` (`status`),
        KEY `payment_id` (`payment_id`),
        KEY `loan_id` (`loan_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_trans);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_upi':
                $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id']);
                $account_holder = mysqli_real_escape_string($conn, $_POST['account_holder']);
                $app_id = !empty($_POST['app_id']) ? intval($_POST['app_id']) : null;
                $bank_id = !empty($_POST['bank_id']) ? intval($_POST['bank_id']) : null;
                $daily_limit = !empty($_POST['daily_limit']) ? floatval($_POST['daily_limit']) : null;
                $transaction_limit = !empty($_POST['transaction_limit']) ? floatval($_POST['transaction_limit']) : null;
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                $is_primary = isset($_POST['is_primary']) ? 1 : 0;
                
                // Validate UPI ID format
                if (!preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9]+$/', $upi_id)) {
                    $error = "Invalid UPI ID format! Example: name@okhdfcbank";
                    break;
                }
                
                // Check if UPI ID already exists
                $check_query = "SELECT id FROM upi_ids WHERE upi_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $upi_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "UPI ID already exists!";
                } else {
                    // Handle QR code upload
                    $qr_code_path = null;
                    if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
                        $upload_dir = 'uploads/upi_qr/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $ext = pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION);
                        $filename = 'qr_' . preg_replace('/[^a-zA-Z0-9]/', '_', $upi_id) . '_' . time() . '.' . $ext;
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $filepath)) {
                            $qr_code_path = $filepath;
                        }
                    }
                    
                    // If setting as primary, remove primary from others
                    if ($is_primary) {
                        $reset_primary = "UPDATE upi_ids SET is_primary = 0";
                        mysqli_query($conn, $reset_primary);
                    }
                    
                    $insert_query = "INSERT INTO upi_ids (
                        upi_id, account_holder, app_id, bank_id, qr_code_path,
                        daily_limit, transaction_limit, description, is_primary, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'ssiidddsi', 
                        $upi_id, $account_holder, $app_id, $bank_id, $qr_code_path,
                        $daily_limit, $transaction_limit, $description, $is_primary
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "UPI ID added successfully!";
                    } else {
                        $error = "Error adding UPI ID: " . mysqli_stmt_error($stmt);
                    }
                }
                break;
                
            case 'edit_upi':
                $id = intval($_POST['id']);
                $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id']);
                $account_holder = mysqli_real_escape_string($conn, $_POST['account_holder']);
                $app_id = !empty($_POST['app_id']) ? intval($_POST['app_id']) : null;
                $bank_id = !empty($_POST['bank_id']) ? intval($_POST['bank_id']) : null;
                $daily_limit = !empty($_POST['daily_limit']) ? floatval($_POST['daily_limit']) : null;
                $transaction_limit = !empty($_POST['transaction_limit']) ? floatval($_POST['transaction_limit']) : null;
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                $is_primary = isset($_POST['is_primary']) ? 1 : 0;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                // Validate UPI ID format
                if (!preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9]+$/', $upi_id)) {
                    $error = "Invalid UPI ID format! Example: name@okhdfcbank";
                    break;
                }
                
                // Check if UPI ID already exists for other records
                $check_query = "SELECT id FROM upi_ids WHERE upi_id = ? AND id != ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'si', $upi_id, $id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "UPI ID already exists!";
                } else {
                    // Handle QR code upload
                    $qr_code_path = null;
                    $qr_update = '';
                    if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
                        $upload_dir = 'uploads/upi_qr/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $ext = pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION);
                        $filename = 'qr_' . preg_replace('/[^a-zA-Z0-9]/', '_', $upi_id) . '_' . time() . '.' . $ext;
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $filepath)) {
                            $qr_code_path = $filepath;
                            $qr_update = ", qr_code_path = ?";
                        }
                    }
                    
                    // If setting as primary, remove primary from others
                    if ($is_primary) {
                        $reset_primary = "UPDATE upi_ids SET is_primary = 0";
                        mysqli_query($conn, $reset_primary);
                    }
                    
                    $update_query = "UPDATE upi_ids SET 
                                    upi_id = ?, account_holder = ?, app_id = ?, bank_id = ?,
                                    daily_limit = ?, transaction_limit = ?, description = ?,
                                    is_primary = ?, is_active = ? $qr_update
                                    WHERE id = ?";
                    
                    $stmt = mysqli_prepare($conn, $update_query);
                    if ($qr_code_path) {
                        mysqli_stmt_bind_param($stmt, 'ssiiddsisi', 
                            $upi_id, $account_holder, $app_id, $bank_id,
                            $daily_limit, $transaction_limit, $description,
                            $is_primary, $is_active, $qr_code_path, $id
                        );
                    } else {
                        mysqli_stmt_bind_param($stmt, 'ssiiddsisi', 
                            $upi_id, $account_holder, $app_id, $bank_id,
                            $daily_limit, $transaction_limit, $description,
                            $is_primary, $is_active, $id
                        );
                    }
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "UPI ID updated successfully!";
                    } else {
                        $error = "Error updating UPI ID: " . mysqli_stmt_error($stmt);
                    }
                }
                break;
                
            case 'delete_upi':
                $id = intval($_POST['id']);
                
                // Check if it's primary
                $check_query = "SELECT is_primary FROM upi_ids WHERE id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $id);
                mysqli_stmt_execute($check_stmt);
                $result = mysqli_stmt_get_result($check_stmt);
                $upi = mysqli_fetch_assoc($result);
                
                if ($upi && $upi['is_primary'] == 1) {
                    $error = "Cannot delete primary UPI ID! Set another as primary first.";
                } else {
                    $delete_query = "DELETE FROM upi_ids WHERE id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $id);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        $message = "UPI ID deleted successfully!";
                    } else {
                        $error = "Error deleting UPI ID: " . mysqli_stmt_error($delete_stmt);
                    }
                }
                break;
                
            case 'set_primary':
                $id = intval($_POST['id']);
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Remove primary from all
                    $reset_query = "UPDATE upi_ids SET is_primary = 0";
                    mysqli_query($conn, $reset_query);
                    
                    // Set new primary
                    $set_query = "UPDATE upi_ids SET is_primary = 1 WHERE id = ?";
                    $set_stmt = mysqli_prepare($conn, $set_query);
                    mysqli_stmt_bind_param($set_stmt, 'i', $id);
                    mysqli_stmt_execute($set_stmt);
                    
                    mysqli_commit($conn);
                    $message = "Primary UPI ID updated successfully!";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error setting primary: " . $e->getMessage();
                }
                break;
                
            case 'add_app':
                $app_name = mysqli_real_escape_string($conn, $_POST['app_name']);
                $app_code = mysqli_real_escape_string($conn, $_POST['app_code']);
                $icon = mysqli_real_escape_string($conn, $_POST['icon'] ?? 'bi-phone');
                $color = mysqli_real_escape_string($conn, $_POST['color'] ?? '#9f7aea');
                $website = mysqli_real_escape_string($conn, $_POST['website'] ?? '');
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                $sort_order = intval($_POST['sort_order'] ?? 0);
                
                // Check if app code already exists
                $check_query = "SELECT id FROM upi_apps WHERE app_code = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $app_code);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "App code already exists!";
                } else {
                    $insert_query = "INSERT INTO upi_apps (app_name, app_code, icon, color, website, description, sort_order, is_active) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'ssssssi', $app_name, $app_code, $icon, $color, $website, $description, $sort_order);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "UPI app added successfully!";
                    } else {
                        $error = "Error adding UPI app: " . mysqli_stmt_error($stmt);
                    }
                }
                break;
                
            case 'edit_app':
                $id = intval($_POST['id']);
                $app_name = mysqli_real_escape_string($conn, $_POST['app_name']);
                $icon = mysqli_real_escape_string($conn, $_POST['icon'] ?? 'bi-phone');
                $color = mysqli_real_escape_string($conn, $_POST['color'] ?? '#9f7aea');
                $website = mysqli_real_escape_string($conn, $_POST['website'] ?? '');
                $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
                $sort_order = intval($_POST['sort_order'] ?? 0);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $update_query = "UPDATE upi_apps SET 
                                app_name = ?, icon = ?, color = ?, website = ?,
                                description = ?, sort_order = ?, is_active = ?
                                WHERE id = ?";
                
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, 'sssssisi', $app_name, $icon, $color, $website, $description, $sort_order, $is_active, $id);
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "UPI app updated successfully!";
                } else {
                    $error = "Error updating UPI app: " . mysqli_stmt_error($stmt);
                }
                break;
                
            case 'delete_app':
                $id = intval($_POST['id']);
                
                // Check if app is in use
                $check_query = "SELECT COUNT(*) as count FROM upi_ids WHERE app_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $id);
                mysqli_stmt_execute($check_stmt);
                $result = mysqli_stmt_get_result($check_stmt);
                $count = mysqli_fetch_assoc($result)['count'];
                
                if ($count > 0) {
                    $error = "Cannot delete app that is in use by UPI IDs!";
                } else {
                    $delete_query = "DELETE FROM upi_apps WHERE id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $id);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        $message = "UPI app deleted successfully!";
                    } else {
                        $error = "Error deleting UPI app: " . mysqli_stmt_error($delete_stmt);
                    }
                }
                break;
        }
    }
}

// Get all UPI IDs
$upi_query = "SELECT u.*, a.app_name, a.app_code, a.icon as app_icon, a.color as app_color,
              b.bank_short_name, b.bank_full_name
              FROM upi_ids u
              LEFT JOIN upi_apps a ON u.app_id = a.id
              LEFT JOIN bank_master b ON u.bank_id = b.id
              ORDER BY u.is_primary DESC, u.created_at DESC";
$upi_result = mysqli_query($conn, $upi_query);

// Get all UPI apps
$apps_query = "SELECT * FROM upi_apps ORDER BY sort_order ASC, app_name ASC";
$apps_result = mysqli_query($conn, $apps_query);

// Get all banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get transaction summary
$trans_summary_query = "SELECT 
                        COUNT(*) as total_transactions,
                        SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_amount,
                        COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                        COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
                        FROM upi_transactions
                        WHERE DATE(created_at) = CURDATE()";
$trans_summary_result = mysqli_query($conn, $trans_summary_query);
$trans_summary = mysqli_fetch_assoc($trans_summary_result);

// Icon options for apps
$icon_options = [
    'bi-google' => 'Google',
    'bi-phone' => 'Phone',
    'bi-cash' => 'Cash',
    'bi-amazon' => 'Amazon',
    'bi-bank' => 'Bank',
    'bi-whatsapp' => 'WhatsApp',
    'bi-paypal' => 'PayPal',
    'bi-credit-card' => 'Card',
    'bi-wallet' => 'Wallet',
    'bi-coin' => 'Coin',
    'bi-star' => 'Star',
    'bi-heart' => 'Heart',
    'bi-gem' => 'Gem',
    'bi-cup' => 'Cup',
    'bi-gift' => 'Gift',
    'bi-three-dots' => 'Other'
];

// Color options
$color_options = [
    '#4285F4' => 'Google Blue',
    '#5F259F' => 'PhonePe Purple',
    '#00BAF2' => 'Paytm Blue',
    '#FF9900' => 'Amazon Orange',
    '#004C8F' => 'BHIM Blue',
    '#25D366' => 'WhatsApp Green',
    '#9f7aea' => 'Purple',
    '#48bb78' => 'Green',
    '#4299e1' => 'Blue',
    '#f56565' => 'Red',
    '#ed8936' => 'Orange',
    '#ecc94b' => 'Yellow',
    '#667eea' => 'Indigo',
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
    <!-- QR Code Scanner/Generator -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
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

        .upi-container {
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
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

        /* UPI ID Cards */
        .upi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .upi-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .upi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .upi-card.primary {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
        }

        .primary-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .upi-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .app-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .upi-details {
            flex: 1;
        }

        .upi-id {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
            word-break: break-all;
        }

        .account-holder {
            font-size: 14px;
            color: #718096;
        }

        .upi-meta {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #718096;
        }

        .meta-item i {
            color: #667eea;
        }

        .qr-container {
            display: flex;
            justify-content: center;
            margin: 15px 0;
        }

        .qr-code {
            width: 120px;
            height: 120px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            object-fit: cover;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
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

        .btn-icon.primary-btn:hover {
            background: #48bb78;
            color: white;
        }

        .btn-icon.qr:hover {
            background: #4299e1;
            color: white;
        }

        /* Apps Table */
        .apps-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .apps-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .apps-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .apps-table tbody tr:hover {
            background: #f7fafc;
        }

        .app-icon-small {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
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

        .badge-primary {
            background: #667eea;
            color: white;
        }

        .badge-default {
            background: #ecc94b;
            color: #744210;
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

        /* QR Preview */
        .qr-preview {
            width: 150px;
            height: 150px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            object-fit: cover;
            margin: 10px auto;
            display: block;
        }

        .qr-generator {
            text-align: center;
            padding: 20px;
            background: #f7fafc;
            border-radius: 8px;
            margin: 15px 0;
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
            
            .upi-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                width: 100%;
                text-align: center;
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
                <div class="upi-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-phone"></i>
                            UPI Payment Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showAddUPIModal()">
                                <i class="bi bi-plus-circle"></i> Add UPI ID
                            </button>
                            <button class="btn btn-info" onclick="showAddAppModal()">
                                <i class="bi bi-app"></i> Add UPI App
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
                    $total_upis = mysqli_num_rows($upi_result);
                    $primary_upi = 0;
                    $active_upis = 0;
                    
                    mysqli_data_seek($upi_result, 0);
                    while ($u = mysqli_fetch_assoc($upi_result)) {
                        if ($u['is_primary']) $primary_upi++;
                        if ($u['is_active']) $active_upis++;
                    }
                    mysqli_data_seek($upi_result, 0);
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-upc-scan"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total UPI IDs</div>
                                <div class="stat-value"><?php echo $total_upis; ?></div>
                                <div class="stat-sub">Registered accounts</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active IDs</div>
                                <div class="stat-value"><?php echo $active_upis; ?></div>
                                <div class="stat-sub">Currently active</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-star-fill"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Primary ID</div>
                                <div class="stat-value"><?php echo $primary_upi ? 'Yes' : 'None'; ?></div>
                                <div class="stat-sub">Default for payments</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Today's Collection</div>
                                <div class="stat-value">₹<?php echo number_format($trans_summary['total_amount'] ?? 0, 2); ?></div>
                                <div class="stat-sub"><?php echo $trans_summary['success_count'] ?? 0; ?> transactions</div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab active" onclick="showTab('upi-ids')">
                            <i class="bi bi-upc-scan"></i> UPI IDs
                        </button>
                        <button class="tab" onclick="showTab('apps')">
                            <i class="bi bi-app"></i> UPI Apps
                        </button>
                        <button class="tab" onclick="showTab('transactions')">
                            <i class="bi bi-clock-history"></i> Transactions
                        </button>
                    </div>

                    <!-- UPI IDs Tab -->
                    <div id="upi-ids-tab" class="tab-content">
                        <?php if (mysqli_num_rows($upi_result) > 0): ?>
                            <div class="upi-grid">
                                <?php while ($upi = mysqli_fetch_assoc($upi_result)): ?>
                                    <div class="upi-card <?php echo $upi['is_primary'] ? 'primary' : ''; ?>">
                                        <?php if ($upi['is_primary']): ?>
                                            <div class="primary-badge">
                                                <i class="bi bi-star-fill"></i> Primary
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="upi-header">
                                            <div class="app-icon" style="background: <?php echo $upi['app_color'] ?? '#9f7aea'; ?>20; color: <?php echo $upi['app_color'] ?? '#9f7aea'; ?>;">
                                                <i class="bi <?php echo $upi['app_icon'] ?? 'bi-phone'; ?>"></i>
                                            </div>
                                            <div class="upi-details">
                                                <div class="upi-id"><?php echo htmlspecialchars($upi['upi_id']); ?></div>
                                                <div class="account-holder"><?php echo htmlspecialchars($upi['account_holder']); ?></div>
                                            </div>
                                        </div>
                                        
                                        <div class="upi-meta">
                                            <?php if ($upi['app_name']): ?>
                                                <span class="meta-item">
                                                    <i class="bi bi-app"></i> <?php echo htmlspecialchars($upi['app_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($upi['bank_short_name']): ?>
                                                <span class="meta-item">
                                                    <i class="bi bi-bank"></i> <?php echo htmlspecialchars($upi['bank_short_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($upi['daily_limit']): ?>
                                                <span class="meta-item">
                                                    <i class="bi bi-calendar"></i> Daily: ₹<?php echo number_format($upi['daily_limit'], 0); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($upi['qr_code_path']): ?>
                                            <div class="qr-container">
                                                <img src="<?php echo $upi['qr_code_path']; ?>" class="qr-code" alt="QR Code">
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="upi-meta">
                                            <span class="meta-item">
                                                <i class="bi bi-check-circle" style="color: <?php echo $upi['is_active'] ? '#48bb78' : '#f56565'; ?>;"></i>
                                                <?php echo $upi['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                            
                                            <?php if ($upi['transaction_limit']): ?>
                                                <span class="meta-item">
                                                    <i class="bi bi-cash-stack"></i> Per Tx: ₹<?php echo number_format($upi['transaction_limit'], 0); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="card-actions">
                                            <button class="btn-icon qr" onclick="showQRCode('<?php echo $upi['upi_id']; ?>', '<?php echo $upi['qr_code_path'] ?? ''; ?>')" title="View QR">
                                                <i class="bi bi-qr-code"></i>
                                            </button>
                                            <button class="btn-icon edit" onclick='editUPI(<?php echo json_encode($upi); ?>)' title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if (!$upi['is_primary']): ?>
                                                <button class="btn-icon primary-btn" onclick="setPrimary(<?php echo $upi['id']; ?>)" title="Set as Primary">
                                                    <i class="bi bi-star"></i>
                                                </button>
                                                <button class="btn-icon delete" onclick="deleteUPI(<?php echo $upi['id']; ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="form-card" style="text-align: center; padding: 50px;">
                                <i class="bi bi-upc-scan" style="font-size: 60px; color: #a0aec0;"></i>
                                <h3 style="margin: 20px 0; color: #4a5568;">No UPI IDs Found</h3>
                                <p style="color: #718096; margin-bottom: 20px;">Add your first UPI ID to start accepting UPI payments.</p>
                                <button class="btn btn-primary" onclick="showAddUPIModal()">
                                    <i class="bi bi-plus-circle"></i> Add UPI ID
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- UPI Apps Tab -->
                    <div id="apps-tab" class="tab-content" style="display: none;">
                        <div class="form-card">
                            <div class="table-responsive">
                                <table class="apps-table">
                                    <thead>
                                        <tr>
                                            <th>Icon</th>
                                            <th>App Name</th>
                                            <th>App Code</th>
                                            <th>Website</th>
                                            <th>Sort Order</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        mysqli_data_seek($apps_result, 0);
                                        while ($app = mysqli_fetch_assoc($apps_result)): 
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="app-icon-small" style="background: <?php echo $app['color']; ?>20; color: <?php echo $app['color']; ?>;">
                                                    <i class="bi <?php echo $app['icon']; ?>"></i>
                                                </div>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($app['app_name']); ?></strong></td>
                                            <td><code><?php echo $app['app_code']; ?></code></td>
                                            <td>
                                                <?php if ($app['website']): ?>
                                                    <a href="<?php echo $app['website']; ?>" target="_blank"><?php echo $app['website']; ?></a>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $app['sort_order']; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $app['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $app['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                                <?php if ($app['is_default']): ?>
                                                    <span class="badge badge-default">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon edit" onclick='editApp(<?php echo json_encode($app); ?>)' title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn-icon delete" onclick="deleteApp(<?php echo $app['id']; ?>)" title="Delete">
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

                    <!-- Transactions Tab -->
                    <div id="transactions-tab" class="tab-content" style="display: none;">
                        <div class="form-card">
                            <div class="table-responsive">
                                <table class="apps-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Transaction ID</th>
                                            <th>UPI ID</th>
                                            <th>Payer</th>
                                            <th class="text-right">Amount</th>
                                            <th>Status</th>
                                            <th>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $trans_query = "SELECT * FROM upi_transactions ORDER BY created_at DESC LIMIT 50";
                                        $trans_result = mysqli_query($conn, $trans_query);
                                        
                                        if (mysqli_num_rows($trans_result) > 0):
                                            while ($trans = mysqli_fetch_assoc($trans_result)):
                                        ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y H:i', strtotime($trans['created_at'])); ?></td>
                                            <td><code><?php echo $trans['transaction_id']; ?></code></td>
                                            <td><?php echo $trans['upi_id']; ?></td>
                                            <td><?php echo htmlspecialchars($trans['payer_name'] ?? '-'); ?></td>
                                            <td class="text-right">₹<?php echo number_format($trans['amount'], 2); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $trans['status'] == 'success' ? 'active' : 
                                                        ($trans['status'] == 'pending' ? 'default' : 'inactive'); 
                                                ?>">
                                                    <?php echo ucfirst($trans['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $trans['reference'] ?? '-'; ?></td>
                                        </tr>
                                        <?php 
                                            endwhile;
                                        else:
                                        ?>
                                        <tr>
                                            <td colspan="7" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-inbox" style="font-size: 48px; color: #a0aec0;"></i>
                                                <p style="margin-top: 10px;">No transactions found</p>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add/Edit UPI Modal -->
    <div class="modal" id="upiModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="upiModalTitle">Add UPI ID</h3>
                <span class="modal-close" onclick="closeUPIModal()">&times;</span>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" id="upiForm">
                <input type="hidden" name="action" id="upiAction" value="add_upi">
                <input type="hidden" name="id" id="upiId" value="">
                
                <div class="form-group">
                    <label class="form-label required">UPI ID</label>
                    <div class="input-group">
                        <i class="bi bi-upc-scan input-icon"></i>
                        <input type="text" class="form-control" name="upi_id" id="upiIdInput" required placeholder="example@okhdfcbank">
                    </div>
                    <small>Format: name@bankhandle (e.g., name@okhdfcbank)</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Account Holder Name</label>
                    <div class="input-group">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" class="form-control" name="account_holder" id="accountHolder" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">UPI App</label>
                        <div class="input-group">
                            <i class="bi bi-app input-icon"></i>
                            <select class="form-select" name="app_id" id="appId">
                                <option value="">Select App</option>
                                <?php 
                                mysqli_data_seek($apps_result, 0);
                                while ($app = mysqli_fetch_assoc($apps_result)): 
                                ?>
                                    <option value="<?php echo $app['id']; ?>">
                                        <?php echo htmlspecialchars($app['app_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bank</label>
                        <div class="input-group">
                            <i class="bi bi-bank input-icon"></i>
                            <select class="form-select" name="bank_id" id="bankId">
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
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Daily Limit (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-calendar input-icon"></i>
                            <input type="number" class="form-control" name="daily_limit" id="dailyLimit" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Per Transaction Limit (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-cash-stack input-icon"></i>
                            <input type="number" class="form-control" name="transaction_limit" id="transactionLimit" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">QR Code</label>
                    <input type="file" class="form-control" name="qr_code" accept="image/*" id="qrUpload">
                    <small>Upload QR code image (PNG, JPG) - Leave empty to keep existing</small>
                    <div id="qrPreview" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description (Optional)</label>
                    <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                </div>
                
                <div class="checkbox-item">
                    <input type="checkbox" name="is_primary" id="isPrimary">
                    <label for="isPrimary">Set as primary UPI ID</label>
                </div>
                
                <div class="form-group" id="upiStatusField" style="display: none;">
                    <label class="checkbox-item">
                        <input type="checkbox" name="is_active" id="isActive" checked>
                        <label for="isActive">Active</label>
                    </label>
                </div>
                
                <div class="qr-generator" id="qrGenerator" style="display: none;">
                    <p>Generated QR Code:</p>
                    <div id="generatedQR"></div>
                    <button type="button" class="btn btn-sm btn-info" onclick="downloadQR()">
                        <i class="bi bi-download"></i> Download QR
                    </button>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUPIModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit App Modal -->
    <div class="modal" id="appModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="appModalTitle">Add UPI App</h3>
                <span class="modal-close" onclick="closeAppModal()">&times;</span>
            </div>
            
            <form method="POST" action="" id="appForm">
                <input type="hidden" name="action" id="appAction" value="add_app">
                <input type="hidden" name="id" id="appId" value="">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">App Name</label>
                        <div class="input-group">
                            <i class="bi bi-app input-icon"></i>
                            <input type="text" class="form-control" name="app_name" id="appName" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">App Code</label>
                        <div class="input-group">
                            <i class="bi bi-code input-icon"></i>
                            <input type="text" class="form-control" name="app_code" id="appCode" required maxlength="50">
                        </div>
                        <small>Unique identifier (lowercase, no spaces)</small>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Icon</label>
                        <div class="input-group">
                            <i class="bi bi-image input-icon"></i>
                            <select class="form-select" name="icon" id="appIcon">
                                <?php foreach ($icon_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Color</label>
                        <div class="input-group">
                            <i class="bi bi-palette input-icon"></i>
                            <select class="form-select" name="color" id="appColor">
                                <?php foreach ($color_options as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" style="color: <?php echo $value; ?>;">
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Website</label>
                    <div class="input-group">
                        <i class="bi bi-globe input-icon"></i>
                        <input type="url" class="form-control" name="website" id="appWebsite">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" id="appDescription" rows="2"></textarea>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <div class="input-group">
                            <i class="bi bi-sort-numeric-down input-icon"></i>
                            <input type="number" class="form-control" name="sort_order" id="appSortOrder" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group" id="appStatusField" style="display: none;">
                        <label class="checkbox-item">
                            <input type="checkbox" name="is_active" id="appIsActive" checked>
                            <label for="appIsActive">Active</label>
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAppModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div class="modal" id="qrModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3 class="modal-title">QR Code</h3>
                <span class="modal-close" onclick="closeQRModal()">&times;</span>
            </div>
            <div style="text-align: center; padding: 20px;">
                <img id="qrModalImage" src="" class="qr-preview" alt="QR Code" style="width: 250px; height: 250px;">
                <p id="qrModalText" style="margin-top: 15px; font-weight: 600;"></p>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button class="btn btn-primary" onclick="downloadQRImage()">
                    <i class="bi bi-download"></i> Download
                </button>
                <button class="btn btn-secondary" onclick="printQR()">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
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
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName + '-tab').style.display = 'block';
            event.target.classList.add('active');
        }

        // UPI ID functions
        function showAddUPIModal() {
            document.getElementById('upiModalTitle').textContent = 'Add UPI ID';
            document.getElementById('upiAction').value = 'add_upi';
            document.getElementById('upiId').value = '';
            document.getElementById('upiIdInput').value = '';
            document.getElementById('upiIdInput').readOnly = false;
            document.getElementById('accountHolder').value = '';
            document.getElementById('appId').value = '';
            document.getElementById('bankId').value = '';
            document.getElementById('dailyLimit').value = '';
            document.getElementById('transactionLimit').value = '';
            document.getElementById('description').value = '';
            document.getElementById('isPrimary').checked = false;
            document.getElementById('qrPreview').innerHTML = '';
            document.getElementById('qrGenerator').style.display = 'none';
            document.getElementById('upiStatusField').style.display = 'none';
            
            // Refresh Select2
            $('#appId').trigger('change');
            $('#bankId').trigger('change');
            
            document.getElementById('upiModal').classList.add('active');
        }

        function editUPI(data) {
            document.getElementById('upiModalTitle').textContent = 'Edit UPI ID';
            document.getElementById('upiAction').value = 'edit_upi';
            document.getElementById('upiId').value = data.id;
            document.getElementById('upiIdInput').value = data.upi_id;
            document.getElementById('upiIdInput').readOnly = true;
            document.getElementById('accountHolder').value = data.account_holder;
            document.getElementById('appId').value = data.app_id || '';
            document.getElementById('bankId').value = data.bank_id || '';
            document.getElementById('dailyLimit').value = data.daily_limit || '';
            document.getElementById('transactionLimit').value = data.transaction_limit || '';
            document.getElementById('description').value = data.description || '';
            document.getElementById('isPrimary').checked = data.is_primary == 1;
            document.getElementById('isActive').checked = data.is_active == 1;
            
            if (data.qr_code_path) {
                document.getElementById('qrPreview').innerHTML = `<img src="${data.qr_code_path}" class="qr-preview" alt="QR Code">`;
            }
            
            document.getElementById('qrGenerator').style.display = 'none';
            document.getElementById('upiStatusField').style.display = 'block';
            
            // Refresh Select2
            $('#appId').trigger('change');
            $('#bankId').trigger('change');
            
            document.getElementById('upiModal').classList.add('active');
        }

        function closeUPIModal() {
            document.getElementById('upiModal').classList.remove('active');
        }

        function setPrimary(id) {
            if (confirm('Set this as the primary UPI ID?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="set_primary">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function deleteUPI(id) {
            Swal.fire({
                title: 'Delete UPI ID?',
                text: "This action cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_upi">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // UPI App functions
        function showAddAppModal() {
            document.getElementById('appModalTitle').textContent = 'Add UPI App';
            document.getElementById('appAction').value = 'add_app';
            document.getElementById('appId').value = '';
            document.getElementById('appName').value = '';
            document.getElementById('appCode').value = '';
            document.getElementById('appCode').readOnly = false;
            document.getElementById('appIcon').value = 'bi-phone';
            document.getElementById('appColor').value = '#9f7aea';
            document.getElementById('appWebsite').value = '';
            document.getElementById('appDescription').value = '';
            document.getElementById('appSortOrder').value = '0';
            document.getElementById('appStatusField').style.display = 'none';
            
            document.getElementById('appModal').classList.add('active');
        }

        function editApp(data) {
            document.getElementById('appModalTitle').textContent = 'Edit UPI App';
            document.getElementById('appAction').value = 'edit_app';
            document.getElementById('appId').value = data.id;
            document.getElementById('appName').value = data.app_name;
            document.getElementById('appCode').value = data.app_code;
            document.getElementById('appCode').readOnly = true;
            document.getElementById('appIcon').value = data.icon;
            document.getElementById('appColor').value = data.color;
            document.getElementById('appWebsite').value = data.website || '';
            document.getElementById('appDescription').value = data.description || '';
            document.getElementById('appSortOrder').value = data.sort_order;
            document.getElementById('appIsActive').checked = data.is_active == 1;
            document.getElementById('appStatusField').style.display = 'block';
            
            document.getElementById('appModal').classList.add('active');
        }

        function closeAppModal() {
            document.getElementById('appModal').classList.remove('active');
        }

        function deleteApp(id) {
            Swal.fire({
                title: 'Delete UPI App?',
                text: "This action cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_app">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // QR Code functions
        function showQRCode(upiId, qrPath) {
            if (qrPath) {
                document.getElementById('qrModalImage').src = qrPath;
            } else {
                // Generate QR code on the fly
                generateQRCode(upiId);
            }
            document.getElementById('qrModalText').textContent = upiId;
            document.getElementById('qrModal').classList.add('active');
        }

        function generateQRCode(upiId) {
            // Simple QR code generation (you might want to use a library)
            const qrContainer = document.getElementById('qrModalImage');
            qrContainer.src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent('upi://pay?pa=' + upiId);
        }

        function downloadQRImage() {
            const img = document.getElementById('qrModalImage');
            const link = document.createElement('a');
            link.download = 'upi-qr-' + Date.now() + '.png';
            link.href = img.src;
            link.click();
        }

        function printQR() {
            const qrUrl = document.getElementById('qrModalImage').src;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head><title>Print QR Code</title></head>
                <body style="text-align: center; padding: 50px;">
                    <img src="${qrUrl}" style="width: 300px; height: 300px;">
                    <p style="margin-top: 20px;">${document.getElementById('qrModalText').textContent}</p>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.remove('active');
        }

        // Generate QR code on the fly when entering UPI ID
        document.getElementById('upiIdInput')?.addEventListener('input', function() {
            const upiId = this.value;
            if (upiId && upiId.includes('@')) {
                document.getElementById('qrGenerator').style.display = 'block';
                const qrContainer = document.getElementById('generatedQR');
                qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=upi://pay?pa=${encodeURIComponent(upiId)}" style="margin: 10px auto; display: block;">`;
            } else {
                document.getElementById('qrGenerator').style.display = 'none';
            }
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