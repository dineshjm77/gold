<?php
session_start();
$currentPage = 'bank-account-details';
$pageTitle = 'Bank Account Details';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle AJAX request to get bank details by ID
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_bank') {
    $bank_id = intval($_GET['bank_id']);
    $query = "SELECT * FROM bank_master WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $bank_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $bank = $result->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $bank]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Bank not found']);
    }
    exit();
}

// Fetch banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name, branch_location, ifsc_code FROM bank_master WHERE status = 1 ORDER BY bank_full_name ASC";
$banks_result = $conn->query($banks_query);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $account_holder_no = $_POST['account_holder_no'] ?? '';
                $bank_account_no = $_POST['bank_account_no'] ?? '';
                $confirm_account_no = $_POST['confirm_account_no'] ?? '';
                $bank_id = $_POST['bank_id'] ?? 0;
                $branch = $_POST['branch'] ?? '';
                $ifsc_code = $_POST['ifsc_code'] ?? '';
                $account_type = $_POST['account_type'] ?? '';
                $authorized_person = $_POST['authorized_person'] ?? '';
                $registered_mobile = $_POST['registered_mobile'] ?? '';
                $opening_balance = $_POST['opening_balance'] ?? 0;
                $as_on_date = $_POST['as_on_date'] ?? date('Y-m-d');
                $status = isset($_POST['status']) ? 1 : 0;
                
                // Validate account number match
                if ($bank_account_no !== $confirm_account_no) {
                    $error = "Bank account number and confirm account number do not match.";
                    break;
                }
                
                // Create bank_accounts table if it doesn't exist
                $create_table = "CREATE TABLE IF NOT EXISTS `bank_accounts` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `account_holder_no` varchar(100) NOT NULL,
                    `bank_account_no` varchar(50) NOT NULL,
                    `bank_id` int(11) NOT NULL,
                    `branch` varchar(150) NOT NULL,
                    `ifsc_code` varchar(20) NOT NULL,
                    `account_type` varchar(50) NOT NULL,
                    `authorized_person` varchar(150) NOT NULL,
                    `registered_mobile` varchar(15) NOT NULL,
                    `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
                    `as_on_date` date NOT NULL,
                    `status` tinyint(1) DEFAULT 1,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `bank_id` (`bank_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                
                $conn->query($create_table);
                
                $insert_query = "INSERT INTO bank_accounts (account_holder_no, bank_account_no, bank_id, branch, ifsc_code, account_type, authorized_person, registered_mobile, opening_balance, as_on_date, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("ssisssssdsi", $account_holder_no, $bank_account_no, $bank_id, $branch, $ifsc_code, $account_type, $authorized_person, $registered_mobile, $opening_balance, $as_on_date, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new bank account: " . $account_holder_no . "', 'bank_accounts', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Account-Details.php?success=added');
                    exit();
                } else {
                    $error = "Error adding bank account: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $account_holder_no = $_POST['account_holder_no'] ?? '';
                $bank_account_no = $_POST['bank_account_no'] ?? '';
                $confirm_account_no = $_POST['confirm_account_no'] ?? '';
                $bank_id = $_POST['bank_id'] ?? 0;
                $branch = $_POST['branch'] ?? '';
                $ifsc_code = $_POST['ifsc_code'] ?? '';
                $account_type = $_POST['account_type'] ?? '';
                $authorized_person = $_POST['authorized_person'] ?? '';
                $registered_mobile = $_POST['registered_mobile'] ?? '';
                $opening_balance = $_POST['opening_balance'] ?? 0;
                $as_on_date = $_POST['as_on_date'] ?? date('Y-m-d');
                $status = isset($_POST['status']) ? 1 : 0;
                
                // Validate account number match
                if ($bank_account_no !== $confirm_account_no) {
                    $error = "Bank account number and confirm account number do not match.";
                    break;
                }
                
                $update_query = "UPDATE bank_accounts 
                                SET account_holder_no = ?, bank_account_no = ?, bank_id = ?, branch = ?, 
                                    ifsc_code = ?, account_type = ?, authorized_person = ?, registered_mobile = ?, 
                                    opening_balance = ?, as_on_date = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ssisssssdsii", $account_holder_no, $bank_account_no, $bank_id, $branch, $ifsc_code, $account_type, $authorized_person, $registered_mobile, $opening_balance, $as_on_date, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated bank account', 'bank_accounts', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Account-Details.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating bank account: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM bank_accounts WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted bank account', 'bank_accounts', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Account-Details.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting bank account: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE bank_accounts SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    header('Location: Bank-Account-Details.php?success=status_updated');
                    exit();
                } else {
                    $error = "Error updating status: " . $stmt->error;
                }
                break;
        }
    }
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Bank account added successfully!";
            break;
        case 'updated':
            $message = "Bank account updated successfully!";
            break;
        case 'deleted':
            $message = "Bank account deleted successfully!";
            break;
        case 'status_updated':
            $message = "Bank account status updated successfully!";
            break;
    }
}

// Create table if not exists and insert sample data
$table_check = $conn->query("SHOW TABLES LIKE 'bank_accounts'");
if ($table_check->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `bank_accounts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `account_holder_no` varchar(100) NOT NULL,
        `bank_account_no` varchar(50) NOT NULL,
        `bank_id` int(11) NOT NULL,
        `branch` varchar(150) NOT NULL,
        `ifsc_code` varchar(20) NOT NULL,
        `account_type` varchar(50) NOT NULL,
        `authorized_person` varchar(150) NOT NULL,
        `registered_mobile` varchar(15) NOT NULL,
        `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
        `as_on_date` date NOT NULL,
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table);
    
    // Get a bank ID for sample data
    $bank_sample = $conn->query("SELECT id FROM bank_master LIMIT 1");
    $bank_id = 1;
    if ($bank_sample->num_rows > 0) {
        $bank_id = $bank_sample->fetch_assoc()['id'];
    }
    
    // Insert sample data from screenshot
    $sample_data = [
        ['SRI VINAYAGA', '112255', $bank_id, 'CBE', 'SBIN0012345', 'Savings', 'SRI VINAYAGA', '7373888777', 10000.00, '2025-12-18', 1]
    ];
    
    foreach ($sample_data as $data) {
        $insert = $conn->prepare("INSERT INTO bank_accounts (account_holder_no, bank_account_no, bank_id, branch, ifsc_code, account_type, authorized_person, registered_mobile, opening_balance, as_on_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("ssisssssdsi", $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7], $data[8], $data[9], $data[10]);
        $insert->execute();
    }
}

// Get max account ID for display
$max_id_query = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM bank_accounts";
$max_id_result = $conn->query($max_id_query);
$next_id = $max_id_result->fetch_assoc()['next_id'];

// Fetch all bank accounts with bank details
$account_query = "SELECT ba.*, bm.bank_full_name, bm.bank_short_name 
                  FROM bank_accounts ba
                  LEFT JOIN bank_master bm ON ba.bank_id = bm.id
                  ORDER BY ba.id ASC";
$account_result = $conn->query($account_query);

// Account types
$account_types = ['Savings', 'Current', 'Fixed Deposit', 'Recurring Deposit', 'Loan Account', 'NRI Account'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        /* Reset and Base Styles - Matching index page */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.95);
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: #f8fafc;
        }

        .page-content {
            flex: 1 0 auto;
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        }

        /* Bank Account Container */
        .account-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Page Header - Matching index page */
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
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .add-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .add-btn i {
            font-size: 20px;
        }

        /* Account ID Badge */
        .account-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .account-id-badge span {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 18px;
        }

        /* Alert Messages - Matching index page */
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
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .form-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: #667eea;
            font-size: 24px;
        }

        .form-title:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 200px;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            margin-bottom: 8px;
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
            left: 15px;
            color: #a0aec0;
            font-size: 18px;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .form-control::placeholder {
            color: #a0aec0;
            font-size: 14px;
        }

        .account-id-badge-large {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        /* Bank Info Preview */
        .bank-info-preview {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
            border: 2px solid #667eea30;
            display: none;
        }

        .bank-info-preview.active {
            display: block;
        }

        .bank-info-title {
            font-size: 14px;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bank-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .bank-info-item {
            display: flex;
            flex-direction: column;
        }

        .bank-info-label {
            font-size: 11px;
            color: #718096;
        }

        .bank-info-value {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        /* Button Styles */
        .btn {
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-secondary {
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 101, 101, 0.5);
        }

        /* Search Bar */
        .search-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .search-box {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
        }

        /* DataTables Customization */
        .dataTables_wrapper {
            padding: 0;
        }

        .dataTables_length select,
        .dataTables_filter input {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            margin: 0 5px;
        }

        .dataTables_length select:focus,
        .dataTables_filter input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .dataTables_info {
            color: #718096;
            font-size: 14px;
            padding: 10px 0;
        }

        .dataTables_paginate {
            padding: 10px 0;
        }

        .dataTables_paginate .paginate_button {
            padding: 8px 12px;
            margin: 0 3px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: white;
            color: #4a5568 !important;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dataTables_paginate .paginate_button:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            border-color: transparent;
        }

        .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            border-color: transparent;
        }

        /* Desktop Table View */
        .account-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1200px;
        }

        .account-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .account-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .account-table tbody tr {
            transition: all 0.3s;
        }

        .account-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .account-holder {
            font-weight: 600;
            color: #2d3748;
        }

        .bank-short-name {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 12px;
            display: inline-block;
        }

        .account-number {
            font-family: monospace;
            font-weight: 600;
            color: #667eea;
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .status-inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: white;
            color: #4a5568;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .btn-icon.edit:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-icon.toggle-active:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.toggle-inactive:hover {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        /* Mobile Card View */
        .mobile-cards {
            display: none;
        }

        .account-mobile-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .mobile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .mobile-id {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            padding: 4px 12px;
            border-radius: 50px;
        }

        .mobile-holder {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .mobile-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .mobile-detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }

        .detail-value.account-no {
            font-family: monospace;
            color: #667eea;
            font-weight: 600;
        }

        .mobile-status {
            margin-bottom: 16px;
        }

        .mobile-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideUp 0.4s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            font-size: 24px;
            color: white;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-radius: 0 0 24px 24px;
        }

        /* Footer Styles */
        .footer {
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #eef2f6;
            padding: 16px 24px;
            margin-top: auto;
            width: 100%;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #64748b;
            font-size: 14px;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: #667eea;
        }

        .footer-version {
            color: #94a3b8;
            font-size: 13px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        .empty-state p {
            font-size: 18px;
            margin-bottom: 10px;
            color: #4a5568;
        }

        /* Badge Count */
        .badge-count {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 14px;
            margin-left: 10px;
            font-weight: 600;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .page-content {
                padding: 20px;
            }
            
            .account-container {
                padding: 0 15px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .page-title {
                font-size: 28px;
            }
            
            .add-btn {
                width: 100%;
                justify-content: center;
            }
            
            .account-id-badge {
                width: 100%;
                justify-content: center;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .search-box {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
                justify-content: center;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .desktop-table {
                display: none;
            }
            
            .mobile-cards {
                display: block;
            }
            
            .mobile-details {
                grid-template-columns: 1fr;
            }
            
            .bank-info-grid {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                flex-direction: column;
                text-align: center;
            }
            
            .footer-links {
                justify-content: center;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .account-container {
                padding: 0 10px;
            }
            
            .form-card, .table-card {
                padding: 20px;
            }
            
            .account-mobile-card {
                padding: 15px;
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
            <div class="account-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-bank2" style="margin-right: 10px;"></i>
                        Bank Account Details
                        <span class="badge-count"><?php echo $account_result ? $account_result->num_rows : 0; ?></span>
                    </h1>
                    <div class="header-actions">
                        <div class="account-id-badge">
                            <i class="bi bi-qr-code"></i>
                            Bank Account ID <span><?php echo $next_id; ?></span>
                        </div>
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Account
                        </button>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Add Form Card -->
                <div class="form-card">
                    <div class="form-title">
                        <i class="bi bi-plus-circle"></i>
                        Add New Bank Account
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Account Holder No *</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="account_holder_no" id="accountHolder" class="form-control" placeholder="Enter account holder name/number" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Bank Account No *</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card input-icon"></i>
                                    <input type="text" name="bank_account_no" id="bankAccountNo" class="form-control" placeholder="Enter account number" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Confirm Account No *</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card-2-back input-icon"></i>
                                    <input type="text" name="confirm_account_no" id="confirmAccountNo" class="form-control" placeholder="Confirm account number" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Bank ID *</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <select name="bank_id" id="bankId" class="form-select" required onchange="loadBankDetails()">
                                        <option value="">Select Bank ID</option>
                                        <?php 
                                        if ($banks_result && $banks_result->num_rows > 0) {
                                            $banks_result->data_seek(0);
                                            while ($bank = $banks_result->fetch_assoc()) {
                                                echo '<option value="' . $bank['id'] . '">' . $bank['id'] . ' - ' . htmlspecialchars($bank['bank_full_name']) . ' (' . htmlspecialchars($bank['bank_short_name']) . ')</option>';
                                            }
                                        } else {
                                            echo '<option value="">No banks found. Please add bank first.</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Branch *</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" name="branch" id="branch" class="form-control" placeholder="Enter branch name" required readonly>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">IFSC Code *</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" name="ifsc_code" id="ifscCode" class="form-control" placeholder="Enter IFSC code" required readonly>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Account Type *</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="account_type" id="accountType" class="form-select" required>
                                        <option value="">Select Account Type</option>
                                        <?php foreach ($account_types as $type): ?>
                                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Authorized Person Name *</label>
                                <div class="input-group">
                                    <i class="bi bi-person-badge input-icon"></i>
                                    <input type="text" name="authorized_person" id="authorizedPerson" class="form-control" placeholder="Enter authorized person name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Registered Mobile Number *</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" name="registered_mobile" id="registeredMobile" class="form-control" placeholder="Enter mobile number" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Opening Balance *</label>
                                <div class="input-group">
                                    <i class="bi bi-currency-rupee input-icon"></i>
                                    <input type="number" name="opening_balance" id="openingBalance" class="form-control" step="0.01" min="0" value="0.00" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">As on date *</label>
                                <div class="input-group">
                                    <i class="bi bi-calendar input-icon"></i>
                                    <input type="date" name="as_on_date" id="asOnDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank Info Preview -->
                        <div class="bank-info-preview" id="bankInfoPreview">
                            <div class="bank-info-title">
                                <i class="bi bi-bank"></i>
                                Selected Bank Details
                            </div>
                            <div class="bank-info-grid" id="bankInfoGrid"></div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="bi bi-eraser"></i>
                                Clear
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
                                <i class="bi bi-x-circle"></i>
                                Close
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i>
                                Save
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Search Bar -->
                <div class="search-container">
                    <div class="search-box">
                        <input type="text" class="search-input" id="searchInput" placeholder="Search by account holder, bank, account number..." onkeyup="searchAccounts()">
                        <button class="search-btn" onclick="searchAccounts()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="account-table" id="accountTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Account Holder No</th>
                                <th>Bank Account No</th>
                                <th>Bank Name</th>
                                <th>Branch</th>
                                <th>Account Type</th>
                                <th>Registered Mobile</th>
                                <th>As on date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($account_result && $account_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $account_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td class="account-holder">
                                            <i class="bi bi-person" style="color: #667eea; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['account_holder_no']); ?>
                                        </td>
                                        <td>
                                            <span class="account-number">
                                                <?php echo htmlspecialchars($row['bank_account_no']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="bank-short-name">
                                                <?php echo htmlspecialchars($row['bank_short_name'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-building" style="color: #ecc94b; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['branch']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['account_type']); ?></td>
                                        <td>
                                            <i class="bi bi-phone" style="color: #48bb78; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['registered_mobile']); ?>
                                        </td>
                                        <td>
                                            <i class="bi bi-calendar" style="color: #718096; margin-right: 5px;"></i>
                                            <?php echo date('d-m-Y', strtotime($row['as_on_date'])); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                                                <i class="bi bi-<?php echo $row['status'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo $row['status'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($row['status']): ?>
                                                    <button class="btn-icon toggle-active" 
                                                            onclick="toggleStatus(<?php echo $row['id']; ?>, 1)" 
                                                            title="Deactivate">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-icon toggle-inactive" 
                                                            onclick="toggleStatus(<?php echo $row['id']; ?>, 0)" 
                                                            title="Activate">
                                                        <i class="bi bi-play-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn-icon delete" onclick="openDeleteModal(<?php echo $row['id']; ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="empty-state">
                                        <i class="bi bi-bank2"></i>
                                        <p>No bank accounts found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Account
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" id="mobileCards">
                    <?php if ($account_result && $account_result->num_rows > 0): ?>
                        <?php mysqli_data_seek($account_result, 0); ?>
                        <?php $sno = 1; ?>
                        <?php while ($row = $account_result->fetch_assoc()): ?>
                            <div class="account-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-id">#<?php echo $sno++; ?></span>
                                    <span class="mobile-holder"><?php echo htmlspecialchars($row['account_holder_no']); ?></span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Account No</span>
                                        <span class="detail-value account-no"><?php echo htmlspecialchars($row['bank_account_no']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Bank</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['bank_short_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Branch</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['branch']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Account Type</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['account_type']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Mobile</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['registered_mobile']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">As on date</span>
                                        <span class="detail-value"><?php echo date('d-m-Y', strtotime($row['as_on_date'])); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-status">
                                    <span class="status-badge <?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="bi bi-<?php echo $row['status'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                        <?php echo $row['status'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-actions">
                                    <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($row['status']): ?>
                                        <button class="btn-icon toggle-active" 
                                                onclick="toggleStatus(<?php echo $row['id']; ?>, 1)" 
                                                title="Deactivate">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-icon toggle-inactive" 
                                                onclick="toggleStatus(<?php echo $row['id']; ?>, 0)" 
                                                title="Activate">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-icon delete" onclick="openDeleteModal(<?php echo $row['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bank2"></i>
                            <p>No bank accounts found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Account
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="accountModal">
    <div class="modal-content">
        <form method="POST" id="accountForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="accountId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Bank Account
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="form-group">
                        <label class="form-label">Account Holder No *</label>
                        <input type="text" name="account_holder_no" id="modalAccountHolder" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bank Account No *</label>
                        <input type="text" name="bank_account_no" id="modalAccountNo" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm Account No *</label>
                        <input type="text" name="confirm_account_no" id="modalConfirmAccountNo" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bank ID *</label>
                        <select name="bank_id" id="modalBankId" class="form-select" required onchange="loadModalBankDetails()">
                            <option value="">Select Bank ID</option>
                            <?php 
                            if ($banks_result) {
                                $banks_result->data_seek(0);
                                while ($bank = $banks_result->fetch_assoc()) {
                                    echo '<option value="' . $bank['id'] . '">' . $bank['id'] . ' - ' . htmlspecialchars($bank['bank_full_name']) . ' (' . htmlspecialchars($bank['bank_short_name']) . ')</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Branch *</label>
                        <input type="text" name="branch" id="modalBranch" class="form-control" required readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">IFSC Code *</label>
                        <input type="text" name="ifsc_code" id="modalIfscCode" class="form-control" required readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Account Type *</label>
                        <select name="account_type" id="modalAccountType" class="form-select" required>
                            <option value="">Select Type</option>
                            <?php foreach ($account_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Authorized Person *</label>
                        <input type="text" name="authorized_person" id="modalAuthorizedPerson" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Registered Mobile *</label>
                        <input type="text" name="registered_mobile" id="modalMobile" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Opening Balance *</label>
                        <input type="number" name="opening_balance" id="modalOpeningBalance" class="form-control" step="0.01" value="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">As on date *</label>
                        <input type="date" name="as_on_date" id="modalAsOnDate" class="form-control" required>
                    </div>
                </div>
                
                <div class="bank-info-preview" id="modalBankInfoPreview" style="margin-top: 10px;">
                    <div class="bank-info-title">
                        <i class="bi bi-bank"></i>
                        Selected Bank Details
                    </div>
                    <div class="bank-info-grid" id="modalBankInfoGrid"></div>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="form-check">
                        <input type="checkbox" name="status" id="modalStatus" class="form-check-input" checked>
                        <span class="form-check-label">Active</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="bi bi-x-circle"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width: 400px;">
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">
            
            <div class="modal-header" style="background: linear-gradient(135deg, #f56565 0%, #c53030 100%);">
                <h3 class="modal-title">
                    <i class="bi bi-exclamation-triangle" style="margin-right: 8px;"></i>
                    Confirm Delete
                </h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div style="text-align: center; padding: 20px;">
                    <i class="bi bi-exclamation-circle" style="font-size: 60px; color: #f56565; margin-bottom: 15px;"></i>
                    <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
                        Are you sure you want to delete this bank account?<br>
                        <strong>This action cannot be undone.</strong>
                    </p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="bi bi-x-circle"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i>
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form method="POST" id="toggleForm" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="id" id="toggleId" value="">
    <input type="hidden" name="current_status" id="toggleStatus" value="">
</form>

<script>
    // Function to load bank details when bank ID is selected (main form)
    function loadBankDetails() {
        const bankId = document.getElementById('bankId').value;
        const branchField = document.getElementById('branch');
        const ifscField = document.getElementById('ifscCode');
        const previewDiv = document.getElementById('bankInfoPreview');
        const infoGrid = document.getElementById('bankInfoGrid');
        
        if (!bankId) {
            branchField.value = '';
            ifscField.value = '';
            branchField.readOnly = true;
            ifscField.readOnly = true;
            previewDiv.classList.remove('active');
            return;
        }
        
        // Show loading state
        infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #718096;">Loading...</div>';
        previewDiv.classList.add('active');
        
        // Fetch bank details via AJAX
        fetch('Bank-Account-Details.php?ajax=get_bank&bank_id=' + bankId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const bank = data.data;
                    branchField.value = bank.branch_location;
                    ifscField.value = bank.ifsc_code;
                    
                    // Display bank info in preview
                    infoGrid.innerHTML = `
                        <div class="bank-info-item">
                            <span class="bank-info-label">Bank Name</span>
                            <span class="bank-info-value">${bank.bank_full_name}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Short Name</span>
                            <span class="bank-info-value">${bank.bank_short_name}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Branch</span>
                            <span class="bank-info-value">${bank.branch_location}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">IFSC</span>
                            <span class="bank-info-value">${bank.ifsc_code}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Branch Phone</span>
                            <span class="bank-info-value">${bank.branch_phone}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Manager Phone</span>
                            <span class="bank-info-value">${bank.branch_manager_phone}</span>
                        </div>
                    `;
                } else {
                    branchField.value = '';
                    ifscField.value = '';
                    infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #f56565;">Bank not found</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #f56565;">Error loading bank details</div>';
            });
    }
    
    // Function to load bank details in modal
    function loadModalBankDetails() {
        const bankId = document.getElementById('modalBankId').value;
        const branchField = document.getElementById('modalBranch');
        const ifscField = document.getElementById('modalIfscCode');
        const previewDiv = document.getElementById('modalBankInfoPreview');
        const infoGrid = document.getElementById('modalBankInfoGrid');
        
        if (!bankId) {
            branchField.value = '';
            ifscField.value = '';
            branchField.readOnly = true;
            ifscField.readOnly = true;
            previewDiv.classList.remove('active');
            return;
        }
        
        // Show loading state
        infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #718096;">Loading...</div>';
        previewDiv.classList.add('active');
        
        // Fetch bank details via AJAX
        fetch('Bank-Account-Details.php?ajax=get_bank&bank_id=' + bankId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const bank = data.data;
                    branchField.value = bank.branch_location;
                    ifscField.value = bank.ifsc_code;
                    
                    // Display bank info in preview
                    infoGrid.innerHTML = `
                        <div class="bank-info-item">
                            <span class="bank-info-label">Bank Name</span>
                            <span class="bank-info-value">${bank.bank_full_name}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Short Name</span>
                            <span class="bank-info-value">${bank.bank_short_name}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Branch</span>
                            <span class="bank-info-value">${bank.branch_location}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">IFSC</span>
                            <span class="bank-info-value">${bank.ifsc_code}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Branch Phone</span>
                            <span class="bank-info-value">${bank.branch_phone}</span>
                        </div>
                        <div class="bank-info-item">
                            <span class="bank-info-label">Manager Phone</span>
                            <span class="bank-info-value">${bank.branch_manager_phone}</span>
                        </div>
                    `;
                } else {
                    branchField.value = '';
                    ifscField.value = '';
                    infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #f56565;">Bank not found</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                infoGrid.innerHTML = '<div style="grid-column: span 2; text-align: center; color: #f56565;">Error loading bank details</div>';
            });
    }
    
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Bank Account';
        document.getElementById('accountId').value = '';
        document.getElementById('modalAccountHolder').value = '';
        document.getElementById('modalAccountNo').value = '';
        document.getElementById('modalConfirmAccountNo').value = '';
        document.getElementById('modalBankId').value = '';
        document.getElementById('modalBranch').value = '';
        document.getElementById('modalIfscCode').value = '';
        document.getElementById('modalAccountType').value = '';
        document.getElementById('modalAuthorizedPerson').value = '';
        document.getElementById('modalMobile').value = '';
        document.getElementById('modalOpeningBalance').value = '0.00';
        document.getElementById('modalAsOnDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('modalBankInfoPreview').classList.remove('active');
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('accountModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Bank Account';
        document.getElementById('accountId').value = data.id;
        document.getElementById('modalAccountHolder').value = data.account_holder_no;
        document.getElementById('modalAccountNo').value = data.bank_account_no;
        document.getElementById('modalConfirmAccountNo').value = data.bank_account_no;
        document.getElementById('modalBankId').value = data.bank_id;
        document.getElementById('modalBranch').value = data.branch;
        document.getElementById('modalIfscCode').value = data.ifsc_code;
        document.getElementById('modalAccountType').value = data.account_type;
        document.getElementById('modalAuthorizedPerson').value = data.authorized_person;
        document.getElementById('modalMobile').value = data.registered_mobile;
        document.getElementById('modalOpeningBalance').value = data.opening_balance;
        document.getElementById('modalAsOnDate').value = data.as_on_date;
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        
        // Load bank details for preview
        setTimeout(() => {
            loadModalBankDetails();
        }, 100);
        
        document.getElementById('accountModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('accountModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function openDeleteModal(id) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function toggleStatus(id, currentStatus) {
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this bank account?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
    }
    
    function clearForm() {
        document.getElementById('accountHolder').value = '';
        document.getElementById('bankAccountNo').value = '';
        document.getElementById('confirmAccountNo').value = '';
        document.getElementById('bankId').value = '';
        document.getElementById('branch').value = '';
        document.getElementById('ifscCode').value = '';
        document.getElementById('accountType').value = '';
        document.getElementById('authorizedPerson').value = '';
        document.getElementById('registeredMobile').value = '';
        document.getElementById('openingBalance').value = '0.00';
        document.getElementById('asOnDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('bankInfoPreview').classList.remove('active');
    }
    
    // Search functionality
    function searchAccounts() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        let tableRows = document.querySelectorAll('#accountTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let holder = row.cells[1].textContent.toLowerCase();
            let account = row.cells[2].textContent.toLowerCase();
            let bank = row.cells[3].textContent.toLowerCase();
            let mobile = row.cells[6].textContent.toLowerCase();
            if (holder.includes(input) || account.includes(input) || bank.includes(input) || mobile.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Search in mobile cards
        let mobileCards = document.querySelectorAll('.account-mobile-card');
        mobileCards.forEach(card => {
            let text = card.textContent.toLowerCase();
            if (text.includes(input)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // Validate account number match on form submit
    document.getElementById('mainForm').addEventListener('submit', function(e) {
        const accountNo = document.getElementById('bankAccountNo').value;
        const confirmNo = document.getElementById('confirmAccountNo').value;
        
        if (accountNo !== confirmNo) {
            e.preventDefault();
            alert('Bank account number and confirm account number do not match!');
        }
    });
    
    document.getElementById('accountForm').addEventListener('submit', function(e) {
        const accountNo = document.getElementById('modalAccountNo').value;
        const confirmNo = document.getElementById('modalConfirmAccountNo').value;
        
        if (accountNo !== confirmNo) {
            e.preventDefault();
            alert('Bank account number and confirm account number do not match!');
        }
    });
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    
    // Handle Enter key in search
    document.getElementById('searchInput').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            searchAccounts();
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>