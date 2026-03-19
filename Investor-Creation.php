<?php
session_start();
$currentPage = 'investor-creation';
$pageTitle = 'Investor Creation';

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
                $investor_id = $_POST['investor_id'] ?? '';
                $investor_name = $_POST['investor_name'] ?? '';
                $guardian_type = $_POST['guardian_type'] ?? '';
                $guardian_name = $_POST['guardian_name'] ?? '';
                $mobile_number = $_POST['mobile_number'] ?? '';
                $alternate_mobile = $_POST['alternate_mobile'] ?? '';
                $investor_address = $_POST['investor_address'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                
                // Create investors table if it doesn't exist
                $insert_query = "INSERT INTO investors (investor_id, investor_name, guardian_type, guardian_name, mobile_number, alternate_mobile, investor_address, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("issssssi", $investor_id, $investor_name, $guardian_type, $guardian_name, $mobile_number, $alternate_mobile, $investor_address, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new investor', 'investors', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Investor-Creation.php?success=added');
                    exit();
                } else {
                    $error = "Error adding investor: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $investor_id = $_POST['investor_id'] ?? '';
                $investor_name = $_POST['investor_name'] ?? '';
                $guardian_type = $_POST['guardian_type'] ?? '';
                $guardian_name = $_POST['guardian_name'] ?? '';
                $mobile_number = $_POST['mobile_number'] ?? '';
                $alternate_mobile = $_POST['alternate_mobile'] ?? '';
                $investor_address = $_POST['investor_address'] ?? '';
                $status = isset($_POST['status']) ? 1 : 0;
                
                $update_query = "UPDATE investors 
                                SET investor_id = ?, investor_name = ?, guardian_type = ?, guardian_name = ?, 
                                    mobile_number = ?, alternate_mobile = ?, investor_address = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("issssssii", $investor_id, $investor_name, $guardian_type, $guardian_name, $mobile_number, $alternate_mobile, $investor_address, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated investor', 'investors', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Investor-Creation.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating investor: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM investors WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted investor', 'investors', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Investor-Creation.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting investor: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE investors SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    header('Location: Investor-Creation.php?success=status_updated');
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
            $message = "Investor added successfully!";
            break;
        case 'updated':
            $message = "Investor updated successfully!";
            break;
        case 'deleted':
            $message = "Investor deleted successfully!";
            break;
        case 'status_updated':
            $message = "Investor status updated successfully!";
            break;
    }
}

// Fetch all investors
// First, check if the table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'investors'");
if ($table_check->num_rows == 0) {
    // Create investors table
    $create_table = "CREATE TABLE IF NOT EXISTS `investors` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `investor_id` int(11) NOT NULL,
        `investor_name` varchar(150) NOT NULL,
        `guardian_type` varchar(50) NOT NULL,
        `guardian_name` varchar(150) NOT NULL,
        `mobile_number` varchar(15) NOT NULL,
        `alternate_mobile` varchar(15) DEFAULT NULL,
        `investor_address` text DEFAULT NULL,
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `investor_id` (`investor_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table);
    
    // Insert sample data from screenshot
    $sample_data = [
        [1, 'அத்விக்', 'Father', 'பிரதீஷ்', '7373888777', '', 'Chennai', 1]
    ];
    
    foreach ($sample_data as $data) {
        $insert = $conn->prepare("INSERT INTO investors (investor_id, investor_name, guardian_type, guardian_name, mobile_number, alternate_mobile, investor_address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("issssssi", $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6], $data[7]);
        $insert->execute();
    }
}

// Get max investor ID for display
$max_id_query = "SELECT COALESCE(MAX(investor_id), 0) + 1 as next_id FROM investors";
$max_id_result = $conn->query($max_id_query);
$next_id = $max_id_result->fetch_assoc()['next_id'];

$investors_query = "SELECT * FROM investors ORDER BY id ASC";
$investors_result = $conn->query($investors_query);

// Guardian types
$guardian_types = ['Father', 'Mother', 'Husband', 'Other'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Reset and Base Styles */
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

        /* Full Width Container */
        .investor-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Page Header */
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

        /* Alert Messages */
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

        .form-label i {
            color: #667eea;
            margin-right: 5px;
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
        }

        .form-control {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .form-control::placeholder {
            color: #a0aec0;
            font-size: 14px;
        }

        .form-select {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            background: #f8fafc;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a0aec0' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            background-size: 16px;
        }

        .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        textarea.form-control {
            padding: 14px 20px 14px 45px;
            min-height: 100px;
            resize: vertical;
        }

        .investor-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

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
            border-radius: 15px;
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
            padding: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
            width: 100%;
        }

        .investor-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1200px;
        }

        .investor-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
        }

        .investor-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .investor-table tbody tr {
            transition: all 0.3s;
        }

        .investor-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .investor-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
        }

        .investor-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
        }

        .tamil-text {
            font-family: "Noto Sans Tamil", "Latha", "Mukta", sans-serif;
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

        .btn-icon.toggle:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 20px;
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

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .page-title {
                font-size: 28px;
                text-align: center;
            }
            
            .add-btn {
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
            
            .investor-container {
                padding: 0 10px;
            }
            
            .form-card, .table-card {
                padding: 20px;
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
            <div class="investor-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-person-badge" style="margin-right: 10px;"></i>
                        Investor Creation
                        <span class="badge-count"><?php echo $investors_result ? $investors_result->num_rows : 0; ?></span>
                    </h1>
                    <button class="add-btn" onclick="openAddModal()">
                        <i class="bi bi-plus-circle"></i>
                        Add New Investor
                    </button>
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
                        <i class="bi bi-person-plus-fill"></i>
                        Add New Investor
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-hash"></i>
                                    Investor Id *
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-person-badge input-icon"></i>
                                    <input type="number" name="investor_id" id="mainInvestorId" class="form-control" 
                                           placeholder="Enter Investor ID" value="<?php echo $next_id; ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person"></i>
                                    Investor Name *
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="investor_name" id="mainInvestorName" class="form-control" 
                                           placeholder="Enter investor name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-people"></i>
                                    Guardian Type *
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="guardian_type" id="mainGuardianType" class="form-select" required>
                                        <option value="">Select Guardian Type</option>
                                        <?php foreach ($guardian_types as $type): ?>
                                            <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person"></i>
                                    Guardian Name *
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="guardian_name" id="mainGuardianName" class="form-control" 
                                           placeholder="Enter guardian name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-phone"></i>
                                    Mobile Number *
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" name="mobile_number" id="mainMobileNumber" class="form-control" 
                                           placeholder="Enter mobile number" required maxlength="10">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-phone"></i>
                                    Alternate Mobile Number
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" name="alternate_mobile" id="mainAlternateMobile" class="form-control" 
                                           placeholder="Enter alternate mobile number" maxlength="10">
                                </div>
                            </div>
                            
                            <div class="form-group" style="grid-column: span 3;">
                                <label class="form-label">
                                    <i class="bi bi-geo-alt"></i>
                                    Investor Address
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <textarea name="investor_address" id="mainInvestorAddress" class="form-control" 
                                              placeholder="Enter investor address" rows="3"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="bi bi-x-circle"></i>
                                Close
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i>
                                Save
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Search Bar -->
                <div class="search-container">
                    <div class="search-box">
                        <input type="text" class="search-input" id="searchInput" placeholder="Search investors by name, guardian, mobile..." onkeyup="searchInvestors()">
                        <button class="search-btn" onclick="searchInvestors()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Table Card -->
                <div class="table-card">
                    <table class="investor-table" id="investorTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Investor ID</th>
                                <th>Investor Name</th>
                                <th>Guardian Type</th>
                                <th>Guardian Name</th>
                                <th>Mobile Number</th>
                                <th>Alternate Mobile</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($investors_result && $investors_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $investors_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td>
                                            <span class="investor-id-badge">
                                                <i class="bi bi-person-badge" style="margin-right: 4px;"></i>
                                                <?php echo htmlspecialchars($row['investor_id']); ?>
                                            </span>
                                        </td>
                                        <td class="investor-name tamil-text">
                                            <i class="bi bi-person" style="margin-right: 6px; color: #667eea;"></i>
                                            <?php echo htmlspecialchars($row['investor_name']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['guardian_type']); ?></td>
                                        <td class="tamil-text"><?php echo htmlspecialchars($row['guardian_name']); ?></td>
                                        <td>
                                            <i class="bi bi-phone" style="margin-right: 4px; color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($row['mobile_number']); ?>
                                        </td>
                                        <td>
                                            <?php if ($row['alternate_mobile']): ?>
                                                <i class="bi bi-phone" style="margin-right: 4px; color: #ecc94b;"></i>
                                                <?php echo htmlspecialchars($row['alternate_mobile']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['investor_address']): ?>
                                                <i class="bi bi-geo-alt" style="margin-right: 4px; color: #f56565;"></i>
                                                <?php echo substr(htmlspecialchars($row['investor_address']), 0, 20) . '...'; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                                                <i class="bi bi-<?php echo $row['status'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo $row['status'] ? 'Active' : 'In Active'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn-icon toggle" 
                                                        onclick="toggleStatus(<?php echo $row['id']; ?>, <?php echo $row['status']; ?>)" 
                                                        title="<?php echo $row['status'] ? 'Deactivate' : 'Activate'; ?>">
                                                    <i class="bi bi-<?php echo $row['status'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                                                </button>
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
                                        <i class="bi bi-person"></i>
                                        <p>No investors found</p>
                                        <small>Click "Add New Investor" to create one.</small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="investorModal">
    <div class="modal-content">
        <form method="POST" id="investorForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="investorId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-person-plus-fill" style="margin-right: 8px;"></i>
                    Add New Investor
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-hash"></i>
                        Investor Id *
                    </label>
                    <div class="input-group">
                        <i class="bi bi-person-badge input-icon"></i>
                        <input type="number" name="investor_id" id="modalInvestorId" class="form-control" 
                               placeholder="Enter Investor ID" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-person"></i>
                        Investor Name *
                    </label>
                    <div class="input-group">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" name="investor_name" id="modalInvestorName" class="form-control" 
                               placeholder="Enter investor name" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-people"></i>
                        Guardian Type *
                    </label>
                    <div class="input-group">
                        <i class="bi bi-tag input-icon"></i>
                        <select name="guardian_type" id="modalGuardianType" class="form-select" required>
                            <option value="">Select Guardian Type</option>
                            <?php foreach ($guardian_types as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-person"></i>
                        Guardian Name *
                    </label>
                    <div class="input-group">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" name="guardian_name" id="modalGuardianName" class="form-control" 
                               placeholder="Enter guardian name" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-phone"></i>
                        Mobile Number *
                    </label>
                    <div class="input-group">
                        <i class="bi bi-phone input-icon"></i>
                        <input type="text" name="mobile_number" id="modalMobileNumber" class="form-control" 
                               placeholder="Enter mobile number" required maxlength="10">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-phone"></i>
                        Alternate Mobile Number
                    </label>
                    <div class="input-group">
                        <i class="bi bi-phone input-icon"></i>
                        <input type="text" name="alternate_mobile" id="modalAlternateMobile" class="form-control" 
                               placeholder="Enter alternate mobile number" maxlength="10">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-geo-alt"></i>
                        Investor Address
                    </label>
                    <div class="input-group">
                        <i class="bi bi-geo-alt input-icon"></i>
                        <textarea name="investor_address" id="modalInvestorAddress" class="form-control" 
                                  placeholder="Enter investor address" rows="3"></textarea>
                    </div>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="form-check" style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="status" id="modalStatus" class="form-check-input" style="width: 18px; height: 18px;" checked>
                        <span class="form-check-label" style="font-size: 15px;">Active</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="bi bi-x-circle"></i>
                    Close
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
                        Are you sure you want to delete this investor?<br>
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
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-person-plus-fill" style="margin-right: 8px;"></i> Add New Investor';
        document.getElementById('investorId').value = '';
        document.getElementById('modalInvestorId').value = '<?php echo $next_id; ?>';
        document.getElementById('modalInvestorName').value = '';
        document.getElementById('modalGuardianType').value = '';
        document.getElementById('modalGuardianName').value = '';
        document.getElementById('modalMobileNumber').value = '';
        document.getElementById('modalAlternateMobile').value = '';
        document.getElementById('modalInvestorAddress').value = '';
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('investorModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Investor';
        document.getElementById('investorId').value = data.id;
        document.getElementById('modalInvestorId').value = data.investor_id;
        document.getElementById('modalInvestorName').value = data.investor_name;
        document.getElementById('modalGuardianType').value = data.guardian_type;
        document.getElementById('modalGuardianName').value = data.guardian_name;
        document.getElementById('modalMobileNumber').value = data.mobile_number;
        document.getElementById('modalAlternateMobile').value = data.alternate_mobile;
        document.getElementById('modalInvestorAddress').value = data.investor_address;
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('investorModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('investorModal').classList.remove('active');
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
    
    function clearForm() {
        document.getElementById('mainInvestorId').value = '<?php echo $next_id; ?>';
        document.getElementById('mainInvestorName').value = '';
        document.getElementById('mainGuardianType').value = '';
        document.getElementById('mainGuardianName').value = '';
        document.getElementById('mainMobileNumber').value = '';
        document.getElementById('mainAlternateMobile').value = '';
        document.getElementById('mainInvestorAddress').value = '';
    }
    
    function toggleStatus(id, currentStatus) {
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this investor?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
    }
    
    // Search functionality
    function searchInvestors() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        let tableRows = document.querySelectorAll('#investorTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let investorName = row.cells[2].textContent.toLowerCase();
            let guardianName = row.cells[4].textContent.toLowerCase();
            let mobile = row.cells[5].textContent.toLowerCase();
            if (investorName.includes(input) || guardianName.includes(input) || mobile.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
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
            searchInvestors();
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>