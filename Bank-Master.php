<?php
session_start();
$currentPage = 'bank-master';
$pageTitle = 'Bank Master';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $bank_full_name = $_POST['bank_full_name'] ?? '';
                $bank_short_name = $_POST['bank_short_name'] ?? '';
                $branch_location = $_POST['branch_location'] ?? '';
                $ifsc_code = $_POST['ifsc_code'] ?? '';
                $branch_phone = $_POST['branch_phone'] ?? '';
                $branch_manager_phone = $_POST['branch_manager_phone'] ?? '';
                $address = $_POST['address'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                
                // Create bank_master table if it doesn't exist
                $create_table = "CREATE TABLE IF NOT EXISTS `bank_master` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `bank_full_name` varchar(200) NOT NULL,
                    `bank_short_name` varchar(50) NOT NULL,
                    `branch_location` varchar(150) NOT NULL,
                    `ifsc_code` varchar(20) NOT NULL,
                    `branch_phone` varchar(15) NOT NULL,
                    `branch_manager_phone` varchar(15) NOT NULL,
                    `address` text DEFAULT NULL,
                    `status` tinyint(1) DEFAULT 1,
                    `created_at` timestamp NULL DEFAULT current_timestamp(),
                    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                
                $conn->query($create_table);
                
                $insert_query = "INSERT INTO bank_master (bank_full_name, bank_short_name, branch_location, ifsc_code, branch_phone, branch_manager_phone, address, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("sssssssi", $bank_full_name, $bank_short_name, $branch_location, $ifsc_code, $branch_phone, $branch_manager_phone, $address, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new bank: " . $bank_full_name . "', 'bank_master', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Master.php?success=added');
                    exit();
                } else {
                    $error = "Error adding bank: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $bank_full_name = $_POST['bank_full_name'] ?? '';
                $bank_short_name = $_POST['bank_short_name'] ?? '';
                $branch_location = $_POST['branch_location'] ?? '';
                $ifsc_code = $_POST['ifsc_code'] ?? '';
                $branch_phone = $_POST['branch_phone'] ?? '';
                $branch_manager_phone = $_POST['branch_manager_phone'] ?? '';
                $address = $_POST['address'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                
                $update_query = "UPDATE bank_master 
                                SET bank_full_name = ?, bank_short_name = ?, branch_location = ?, ifsc_code = ?, 
                                    branch_phone = ?, branch_manager_phone = ?, address = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("sssssssii", $bank_full_name, $bank_short_name, $branch_location, $ifsc_code, $branch_phone, $branch_manager_phone, $address, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated bank: " . $bank_full_name . "', 'bank_master', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Master.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating bank: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM bank_master WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted bank', 'bank_master', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Bank-Master.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting bank: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE bank_master SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    header('Location: Bank-Master.php?success=status_updated');
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
            $message = "Bank added successfully!";
            break;
        case 'updated':
            $message = "Bank updated successfully!";
            break;
        case 'deleted':
            $message = "Bank deleted successfully!";
            break;
        case 'status_updated':
            $message = "Bank status updated successfully!";
            break;
    }
}

// Create table if not exists and insert sample data
$table_check = $conn->query("SHOW TABLES LIKE 'bank_master'");
if ($table_check->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `bank_master` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `bank_full_name` varchar(200) NOT NULL,
        `bank_short_name` varchar(50) NOT NULL,
        `branch_location` varchar(150) NOT NULL,
        `ifsc_code` varchar(20) NOT NULL,
        `branch_phone` varchar(15) NOT NULL,
        `branch_manager_phone` varchar(15) NOT NULL,
        `address` text DEFAULT NULL,
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table);
    
    // Insert sample data from screenshot
    $sample_data = [
        ['State Bank of India', 'SBI', 'CBE', '12345678900', '0000000000', '0000000000', 'Main Branch, Coimbatore', 1],
        ['Indian Bank', 'IB', 'CHENNAI', 'KVBL0001119', '1', '1234567890', 'Anna Salai, Chennai', 1],
        ['Tamilnad mercantile Bank', 'TMB', 'Avalchinampalayam', '55555666555', '55555555555', '55555555555', 'Avalchinampalayam Branch', 1]
    ];
    
    foreach ($sample_data as $data) {
        $insert = $conn->prepare("INSERT INTO bank_master (bank_full_name, bank_short_name, branch_location, ifsc_code, branch_phone, branch_manager_phone, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("sssssssi", $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7]);
        $insert->execute();
    }
}

// Get max bank ID for display
$max_id_query = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM bank_master";
$max_id_result = $conn->query($max_id_query);
$next_id = $max_id_result->fetch_assoc()['next_id'];

// Fetch all banks
$bank_query = "SELECT * FROM bank_master ORDER BY id ASC";
$bank_result = $conn->query($bank_query);
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

        /* Bank Container */
        .bank-container {
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

        /* Bank ID Badge */
        .bank-id-badge {
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

        .bank-id-badge span {
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .bank-id-badge-large {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            border: 2px solid #e2e8f0;
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
        .bank-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1300px;
        }

        .bank-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .bank-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .bank-table tbody tr {
            transition: all 0.3s;
        }

        .bank-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .bank-id {
            font-weight: 600;
            color: #667eea;
        }

        .bank-full-name {
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

        .ifsc-code {
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

        .btn-icon.select-bank {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.select-bank:hover {
            background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
        }

        /* Mobile Card View */
        .mobile-cards {
            display: none;
        }

        .bank-mobile-card {
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

        .mobile-bank-name {
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

        .detail-value.short-name {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 50px;
            display: inline-block;
            width: fit-content;
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
            max-width: 600px;
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
            
            .bank-container {
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
            
            .bank-id-badge {
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
            
            .bank-container {
                padding: 0 10px;
            }
            
            .form-card, .table-card {
                padding: 20px;
            }
            
            .bank-mobile-card {
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
            <div class="bank-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-bank" style="margin-right: 10px;"></i>
                        Bank Master
                        <span class="badge-count"><?php echo $bank_result ? $bank_result->num_rows : 0; ?></span>
                    </h1>
                    <div class="header-actions">
                        <div class="bank-id-badge">
                            <i class="bi bi-qr-code"></i>
                            Bank Id <span><?php echo $next_id; ?></span>
                        </div>
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Bank
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
                        Add New Bank
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Bank Full Name *</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" name="bank_full_name" class="form-control" placeholder="Enter bank full name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Bank Short Name *</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <input type="text" name="bank_short_name" class="form-control" placeholder="Enter short name (e.g., SBI)" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Branch Location *</label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <input type="text" name="branch_location" class="form-control" placeholder="Enter branch location" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">IFSC Code *</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" name="ifsc_code" class="form-control" placeholder="Enter IFSC code" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Branch Phone Number *</label>
                                <div class="input-group">
                                    <i class="bi bi-telephone input-icon"></i>
                                    <input type="text" name="branch_phone" class="form-control" placeholder="Enter branch phone" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Branch Manager Phone *</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="branch_manager_phone" class="form-control" placeholder="Enter manager phone" required>
                                </div>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 3;">
                                <label class="form-label required">Address *</label>
                                <div class="input-group">
                                    <i class="bi bi-house-door input-icon"></i>
                                    <textarea name="address" class="form-control" placeholder="Enter complete address" rows="3" required></textarea>
                                </div>
                            </div>
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
                        <input type="text" class="search-input" id="searchInput" placeholder="Search by bank name, short name, location, IFSC..." onkeyup="searchBanks()">
                        <button class="search-btn" onclick="searchBanks()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="bank-table" id="bankTable">
                        <thead>
                            <tr>
                                <th>Bank ID</th>
                                <th>Bank Full Name</th>
                                <th>Bank Short Name</th>
                                <th>Branch Location</th>
                                <th>IFSC Code</th>
                                <th>Branch Phone</th>
                                <th>Manager Phone</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($bank_result && $bank_result->num_rows > 0): ?>
                                <?php while ($row = $bank_result->fetch_assoc()): ?>
                                    <tr>
                                        <td class="bank-id"><strong>#<?php echo $row['id']; ?></strong></td>
                                        <td class="bank-full-name">
                                            <i class="bi bi-bank" style="color: #667eea; margin-right: 8px;"></i>
                                            <?php echo htmlspecialchars($row['bank_full_name']); ?>
                                        </td>
                                        <td>
                                            <span class="bank-short-name">
                                                <?php echo htmlspecialchars($row['bank_short_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-geo-alt" style="color: #ecc94b; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['branch_location']); ?>
                                        </td>
                                        <td>
                                            <span class="ifsc-code">
                                                <?php echo htmlspecialchars($row['ifsc_code']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-telephone" style="color: #48bb78; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['branch_phone']); ?>
                                        </td>
                                        <td>
                                            <i class="bi bi-person" style="color: #4299e1; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['branch_manager_phone']); ?>
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
                                    <td colspan="9" class="empty-state">
                                        <i class="bi bi-bank"></i>
                                        <p>No banks found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Bank
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" id="mobileCards">
                    <?php if ($bank_result && $bank_result->num_rows > 0): ?>
                        <?php mysqli_data_seek($bank_result, 0); ?>
                        <?php while ($row = $bank_result->fetch_assoc()): ?>
                            <div class="bank-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-id">#<?php echo $row['id']; ?></span>
                                    <span class="mobile-bank-name"><?php echo htmlspecialchars($row['bank_full_name']); ?></span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Short Name</span>
                                        <span class="detail-value short-name"><?php echo htmlspecialchars($row['bank_short_name']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Location</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['branch_location']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">IFSC</span>
                                        <span class="detail-value ifsc-code"><?php echo htmlspecialchars($row['ifsc_code']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Branch Phone</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['branch_phone']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Manager Phone</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($row['branch_manager_phone']); ?></span>
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
                            <i class="bi bi-bank"></i>
                            <p>No banks found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Bank
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
<div class="modal" id="bankModal">
    <div class="modal-content">
        <form method="POST" id="bankForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="bankId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Bank
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Bank Full Name *</label>
                    <input type="text" name="bank_full_name" id="modalBankFullName" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Bank Short Name *</label>
                    <input type="text" name="bank_short_name" id="modalBankShortName" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Branch Location *</label>
                    <input type="text" name="branch_location" id="modalBranchLocation" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">IFSC Code *</label>
                    <input type="text" name="ifsc_code" id="modalIfscCode" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Branch Phone Number *</label>
                    <input type="text" name="branch_phone" id="modalBranchPhone" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Branch Manager Phone *</label>
                    <input type="text" name="branch_manager_phone" id="modalManagerPhone" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Address *</label>
                    <textarea name="address" id="modalAddress" class="form-control" rows="3" required></textarea>
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
                        Are you sure you want to delete this bank?<br>
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
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Bank';
        document.getElementById('bankId').value = '';
        document.getElementById('modalBankFullName').value = '';
        document.getElementById('modalBankShortName').value = '';
        document.getElementById('modalBranchLocation').value = '';
        document.getElementById('modalIfscCode').value = '';
        document.getElementById('modalBranchPhone').value = '';
        document.getElementById('modalManagerPhone').value = '';
        document.getElementById('modalAddress').value = '';
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('bankModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Bank';
        document.getElementById('bankId').value = data.id;
        document.getElementById('modalBankFullName').value = data.bank_full_name;
        document.getElementById('modalBankShortName').value = data.bank_short_name;
        document.getElementById('modalBranchLocation').value = data.branch_location;
        document.getElementById('modalIfscCode').value = data.ifsc_code;
        document.getElementById('modalBranchPhone').value = data.branch_phone;
        document.getElementById('modalManagerPhone').value = data.branch_manager_phone;
        document.getElementById('modalAddress').value = data.address;
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('bankModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('bankModal').classList.remove('active');
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
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this bank?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
    }
    
    function clearForm() {
        document.querySelector('input[name="bank_full_name"]').value = '';
        document.querySelector('input[name="bank_short_name"]').value = '';
        document.querySelector('input[name="branch_location"]').value = '';
        document.querySelector('input[name="ifsc_code"]').value = '';
        document.querySelector('input[name="branch_phone"]').value = '';
        document.querySelector('input[name="branch_manager_phone"]').value = '';
        document.querySelector('textarea[name="address"]').value = '';
    }
    
    // Search functionality
    function searchBanks() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        let tableRows = document.querySelectorAll('#bankTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let bankName = row.cells[1].textContent.toLowerCase();
            let shortName = row.cells[2].textContent.toLowerCase();
            let location = row.cells[3].textContent.toLowerCase();
            let ifsc = row.cells[4].textContent.toLowerCase();
            if (bankName.includes(input) || shortName.includes(input) || location.includes(input) || ifsc.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Search in mobile cards
        let mobileCards = document.querySelectorAll('.bank-mobile-card');
        mobileCards.forEach(card => {
            let text = card.textContent.toLowerCase();
            if (text.includes(input)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
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
            searchBanks();
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>