<?php
session_start();
$currentPage = 'investment';
$pageTitle = 'Investment Management';

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
                $investment_no = $_POST['investment_no'] ?? '';
                $investment_date = $_POST['investment_date'] ?? '';
                $investment_amount = $_POST['investment_amount'] ?? 0;
                $interest = $_POST['interest'] ?? 0;
                $investment_type = $_POST['investment_type'] ?? '';
                $investor_name = $_POST['investor_name'] ?? '';
                $guardian_type = $_POST['guardian_type'] ?? '';
                $guardian_name = $_POST['guardian_name'] ?? '';
                $mobile_number = $_POST['mobile_number'] ?? '';
                $gods_name = $_POST['gods_name'] ?? '';
                $payment_method = $_POST['payment_method'] ?? 'cash';
                $status = 1;
                
                // Create investments table if it doesn't exist
                $insert_query = "INSERT INTO investments (investment_no, investment_date, investment_amount, interest, investment_type, investor_name, guardian_type, guardian_name, mobile_number, gods_name, payment_method, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("isddsssssssi", $investment_no, $investment_date, $investment_amount, $interest, $investment_type, $investor_name, $guardian_type, $guardian_name, $mobile_number, $gods_name, $payment_method, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new investment', 'investments', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Investment.php?success=added');
                    exit();
                } else {
                    $error = "Error adding investment: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $investment_no = $_POST['investment_no'] ?? '';
                $investment_date = $_POST['investment_date'] ?? '';
                $investment_amount = $_POST['investment_amount'] ?? 0;
                $interest = $_POST['interest'] ?? 0;
                $investment_type = $_POST['investment_type'] ?? '';
                $investor_name = $_POST['investor_name'] ?? '';
                $guardian_type = $_POST['guardian_type'] ?? '';
                $guardian_name = $_POST['guardian_name'] ?? '';
                $mobile_number = $_POST['mobile_number'] ?? '';
                $gods_name = $_POST['gods_name'] ?? '';
                $payment_method = $_POST['payment_method'] ?? 'cash';
                $status = isset($_POST['status']) ? 1 : 0;
                
                $update_query = "UPDATE investments 
                                SET investment_no = ?, investment_date = ?, investment_amount = ?, interest = ?, 
                                    investment_type = ?, investor_name = ?, guardian_type = ?, guardian_name = ?, 
                                    mobile_number = ?, gods_name = ?, payment_method = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("isddsssssssii", $investment_no, $investment_date, $investment_amount, $interest, $investment_type, $investor_name, $guardian_type, $guardian_name, $mobile_number, $gods_name, $payment_method, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated investment', 'investments', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Investment.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating investment: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM investments WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted investment', 'investments', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Investment.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting investment: " . $stmt->error;
                }
                break;
        }
    }
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Investment added successfully!";
            break;
        case 'updated':
            $message = "Investment updated successfully!";
            break;
        case 'deleted':
            $message = "Investment deleted successfully!";
            break;
    }
}

// Fetch all investments
// First, check if the table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'investments'");
if ($table_check->num_rows == 0) {
    // Create investments table
    $create_table = "CREATE TABLE IF NOT EXISTS `investments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `investment_no` int(11) NOT NULL,
        `investment_date` date NOT NULL,
        `investment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
        `interest` decimal(15,2) NOT NULL DEFAULT 0.00,
        `investment_type` varchar(150) NOT NULL,
        `investor_name` varchar(150) NOT NULL,
        `guardian_type` varchar(50) DEFAULT NULL,
        `guardian_name` varchar(150) DEFAULT NULL,
        `mobile_number` varchar(15) NOT NULL,
        `gods_name` varchar(150) DEFAULT NULL,
        `payment_method` varchar(50) DEFAULT 'cash',
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `investment_no` (`investment_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table);
}

// Get max investment number for display
$max_no_query = "SELECT COALESCE(MAX(investment_no), 0) + 1 as next_no FROM investments";
$max_no_result = $conn->query($max_no_query);
$next_no = $max_no_result->fetch_assoc()['next_no'];

$investments_query = "SELECT * FROM investments ORDER BY investment_no DESC";
$investments_result = $conn->query($investments_query);

// Fetch investment types for dropdown from investment_types table
$investment_types_query = "SELECT investment_type_name FROM investment_types WHERE status = 1 ORDER BY investment_type_name ASC";
$investment_types_result = $conn->query($investment_types_query);
$investment_types = [];
if ($investment_types_result && $investment_types_result->num_rows > 0) {
    while ($type = $investment_types_result->fetch_assoc()) {
        $investment_types[] = $type['investment_type_name'];
    }
} else {
    // Default investment types if table is empty
    $investment_types = ['கவராமிகள் கணக்கு', 'நடப்பு கணக்கு', 'பங்குமுகல் கணக்கு', 'வட்டி கணக்கு'];
}

// Fetch gods names for dropdown from gods_name table
$gods_query = "SELECT * FROM gods_name WHERE status = 1 ORDER BY gods_name ASC";
$gods_result = $conn->query($gods_query);

// Fetch investors for dropdown from investors table - FIXED HERE
$investors_query = "SELECT investor_name FROM investors WHERE status = 1 ORDER BY investor_name ASC";
$investors_result = $conn->query($investors_query);
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

        /* Investment Container */
        .investment-container {
            width: 100%;
            max-width: 1400px;
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
            grid-template-columns: repeat(4, 1fr);
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

        .investment-no-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-item label {
            font-size: 15px;
            font-weight: 500;
            color: #4a5568;
            cursor: pointer;
        }

        .payment-options {
            display: flex;
            gap: 20px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .payment-option label {
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            cursor: pointer;
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

        /* God Income Section */
        .god-income-section {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }

        .god-income-section.active {
            display: block;
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

        .investment-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1200px;
        }

        .investment-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
        }

        .investment-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .investment-table tbody tr {
            transition: all 0.3s;
        }

        .investment-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .investment-no-badge-small {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        .amount.highlight {
            color: #48bb78;
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

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
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

        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 992px) {
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
            
            .checkbox-group {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .payment-options {
                flex-direction: column;
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
            
            .investment-container {
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
            <div class="investment-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-pie-chart" style="margin-right: 10px;"></i>
                        Investment
                        <span class="badge-count"><?php echo $investments_result ? $investments_result->num_rows : 0; ?></span>
                    </h1>
                    <button class="add-btn" onclick="openAddModal()">
                        <i class="bi bi-plus-circle"></i>
                        Add New Investment
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
                        <i class="bi bi-plus-circle"></i>
                        Add New Investment
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-hash"></i>
                                    Investment No*
                                </label>
                                <div class="investment-no-badge">
                                    <?php echo $next_no; ?>
                                </div>
                                <input type="hidden" name="investment_no" value="<?php echo $next_no; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-calendar"></i>
                                    Investment Date*
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-calendar input-icon"></i>
                                    <input type="date" name="investment_date" id="investmentDate" class="form-control" 
                                           value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-currency-rupee"></i>
                                    Investment Amount*
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-currency-rupee input-icon"></i>
                                    <input type="number" name="investment_amount" id="investmentAmount" class="form-control" 
                                           placeholder="Enter amount" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-percent"></i>
                                    Interest
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-percent input-icon"></i>
                                    <input type="number" name="interest" id="interest" class="form-control" 
                                           placeholder="Enter interest" step="0.01" min="0" value="0.00">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-tag"></i>
                                    Investment Type*
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="investment_type" id="investmentType" class="form-select" required>
                                        <option value="">Select Investment Type</option>
                                        <?php foreach ($investment_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person"></i>
                                    Investor Name*
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <select name="investor_name" id="investorName" class="form-select" required>
                                        <option value="">Select Investor Name</option>
                                        <?php 
                                        if ($investors_result && $investors_result->num_rows > 0) {
                                            mysqli_data_seek($investors_result, 0);
                                            while ($investor = $investors_result->fetch_assoc()) { 
                                        ?>
                                            <option value="<?php echo htmlspecialchars($investor['investor_name']); ?>">
                                                <?php echo htmlspecialchars($investor['investor_name']); ?>
                                            </option>
                                        <?php 
                                            }
                                        } 
                                        ?>
                                        <option value="other">Other (Type manually)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-people"></i>
                                    Guardian Type
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="guardian_type" id="guardianType" class="form-select">
                                        <option value="">Select Guardian Type</option>
                                        <option value="Father">Father</option>
                                        <option value="Mother">Mother</option>
                                        <option value="Husband">Husband</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person"></i>
                                    Guardian Name
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="guardian_name" id="guardianName" class="form-control" 
                                           placeholder="Enter guardian name">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-phone"></i>
                                    Mobile Number*
                                </label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" name="mobile_number" id="mobileNumber" class="form-control" 
                                           placeholder="Enter mobile number" required maxlength="10">
                                </div>
                            </div>
                        </div>
                        
                        <!-- God Income Checkbox -->
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="god_income" id="godIncome" onchange="toggleGodIncome()">
                                <label for="godIncome">God Income</label>
                            </div>
                        </div>
                        
                        <!-- God Income Section - Shows only when checkbox is checked -->
                        <div class="god-income-section" id="godIncomeSection">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">
                                        <i class="bi bi-building"></i>
                                        God's Name*
                                    </label>
                                    <div class="input-group">
                                        <i class="bi bi-building input-icon"></i>
                                        <select name="gods_name" id="godsName" class="form-select">
                                            <option value="">Select God's Name</option>
                                            <?php 
                                            if ($gods_result && $gods_result->num_rows > 0) {
                                                mysqli_data_seek($gods_result, 0);
                                                while ($god = $gods_result->fetch_assoc()) { 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($god['gods_name']); ?>">
                                                    <?php echo htmlspecialchars($god['gods_name']); ?>
                                                </option>
                                            <?php 
                                                }
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="payment-options">
                            <div class="payment-option">
                                <input type="radio" name="payment_method" id="paymentCash" value="cash" checked>
                                <label for="paymentCash">Cash</label>
                            </div>
                            <div class="payment-option">
                                <input type="radio" name="payment_method" id="paymentBank" value="bank">
                                <label for="paymentBank">Bank Transfer</label>
                            </div>
                            <div class="payment-option">
                                <input type="radio" name="payment_method" id="paymentUPI" value="upi">
                                <label for="paymentUPI">UPI</label>
                            </div>
                            <div class="payment-option">
                                <input type="radio" name="payment_method" id="paymentOther" value="other">
                                <label for="paymentOther">Other</label>
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
                        <input type="text" class="search-input" id="searchInput" placeholder="Search investments by investor name, mobile, investment type..." onkeyup="searchInvestments()">
                        <button class="search-btn" onclick="searchInvestments()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Table Card -->
                <div class="table-card">
                    <table class="investment-table" id="investmentTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Investment No</th>
                                <th>Investment Date</th>
                                <th>Investment Amount</th>
                                <th>Interest</th>
                                <th>Investment Type</th>
                                <th>Investor Name</th>
                                <th>Mobile Number</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($investments_result && $investments_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $investments_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td>
                                            <span class="investment-no-badge-small">
                                                <?php echo htmlspecialchars($row['investment_no']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d-m-Y', strtotime($row['investment_date'])); ?></td>
                                        <td class="amount highlight">₹<?php echo number_format($row['investment_amount'], 2); ?></td>
                                        <td class="amount">₹<?php echo number_format($row['interest'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($row['investment_type']); ?></td>
                                        <td><?php echo htmlspecialchars($row['investor_name']); ?></td>
                                        <td>
                                            <i class="bi bi-phone" style="margin-right: 4px; color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($row['mobile_number']); ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
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
                                    <td colspan="9" class="empty-state">
                                        <i class="bi bi-pie-chart"></i>
                                        <p>No investments found</p>
                                        <small>Click "Add New Investment" to create one.</small>
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
<div class="modal" id="investmentModal">
    <div class="modal-content">
        <form method="POST" id="investmentForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="investmentId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Investment
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Investment No*</label>
                    <input type="number" name="investment_no" id="modalInvestmentNo" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Investment Date*</label>
                    <input type="date" name="investment_date" id="modalInvestmentDate" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Investment Amount*</label>
                    <input type="number" name="investment_amount" id="modalInvestmentAmount" class="form-control" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Interest</label>
                    <input type="number" name="interest" id="modalInterest" class="form-control" step="0.01" value="0.00">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Investment Type*</label>
                    <select name="investment_type" id="modalInvestmentType" class="form-select" required>
                        <option value="">Select Type</option>
                        <?php foreach ($investment_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Investor Name*</label>
                    <select name="investor_name" id="modalInvestorName" class="form-select" required>
                        <option value="">Select Investor Name</option>
                        <?php 
                        // Re-fetch investors for modal - FIXED HERE
                        $investors_query2 = "SELECT investor_name FROM investors WHERE status = 1 ORDER BY investor_name ASC";
                        $investors_result2 = $conn->query($investors_query2);
                        if ($investors_result2 && $investors_result2->num_rows > 0) {
                            while ($investor = $investors_result2->fetch_assoc()) { 
                        ?>
                            <option value="<?php echo htmlspecialchars($investor['investor_name']); ?>">
                                <?php echo htmlspecialchars($investor['investor_name']); ?>
                            </option>
                        <?php 
                            }
                        } 
                        ?>
                        <option value="other">Other (Type manually)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Guardian Type</label>
                    <select name="guardian_type" id="modalGuardianType" class="form-select">
                        <option value="">Select</option>
                        <option value="Father">Father</option>
                        <option value="Mother">Mother</option>
                        <option value="Husband">Husband</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Guardian Name</label>
                    <input type="text" name="guardian_name" id="modalGuardianName" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Mobile Number*</label>
                    <input type="text" name="mobile_number" id="modalMobileNumber" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">God's Name</label>
                    <select name="gods_name" id="modalGodsName" class="form-select">
                        <option value="">Select God's Name</option>
                        <?php 
                        $gods_query2 = "SELECT * FROM gods_name WHERE status = 1 ORDER BY gods_name ASC";
                        $gods_result2 = $conn->query($gods_query2);
                        if ($gods_result2 && $gods_result2->num_rows > 0) {
                            while ($god = $gods_result2->fetch_assoc()) { 
                        ?>
                            <option value="<?php echo htmlspecialchars($god['gods_name']); ?>">
                                <?php echo htmlspecialchars($god['gods_name']); ?>
                            </option>
                        <?php 
                            }
                        } 
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" id="modalPaymentMethod" class="form-select">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group" id="modalStatusField" style="display: none;">
                    <label class="form-check">
                        <input type="checkbox" name="status" id="modalStatus" class="form-check-input" checked>
                        <span class="form-check-label">Active</span>
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
                        Are you sure you want to delete this investment?<br>
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

<script>
    // Toggle God Income section based on checkbox
    function toggleGodIncome() {
        var checkbox = document.getElementById('godIncome');
        var section = document.getElementById('godIncomeSection');
        
        if (checkbox.checked) {
            section.style.display = 'block';
            section.classList.add('active');
            document.getElementById('godsName').setAttribute('required', 'required');
        } else {
            section.style.display = 'none';
            section.classList.remove('active');
            document.getElementById('godsName').removeAttribute('required');
        }
    }
    
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Investment';
        document.getElementById('investmentId').value = '';
        document.getElementById('modalInvestmentNo').value = '<?php echo $next_no; ?>';
        document.getElementById('modalInvestmentDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('modalInvestmentAmount').value = '';
        document.getElementById('modalInterest').value = '0.00';
        document.getElementById('modalInvestmentType').value = '';
        document.getElementById('modalInvestorName').value = '';
        document.getElementById('modalGuardianType').value = '';
        document.getElementById('modalGuardianName').value = '';
        document.getElementById('modalMobileNumber').value = '';
        document.getElementById('modalGodsName').value = '';
        document.getElementById('modalPaymentMethod').value = 'cash';
        document.getElementById('modalStatusField').style.display = 'none';
        document.getElementById('investmentModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Investment';
        document.getElementById('investmentId').value = data.id;
        document.getElementById('modalInvestmentNo').value = data.investment_no;
        document.getElementById('modalInvestmentDate').value = data.investment_date;
        document.getElementById('modalInvestmentAmount').value = data.investment_amount;
        document.getElementById('modalInterest').value = data.interest;
        document.getElementById('modalInvestmentType').value = data.investment_type;
        document.getElementById('modalInvestorName').value = data.investor_name;
        document.getElementById('modalGuardianType').value = data.guardian_type || '';
        document.getElementById('modalGuardianName').value = data.guardian_name || '';
        document.getElementById('modalMobileNumber').value = data.mobile_number;
        document.getElementById('modalGodsName').value = data.gods_name || '';
        document.getElementById('modalPaymentMethod').value = data.payment_method || 'cash';
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('modalStatusField').style.display = 'block';
        document.getElementById('investmentModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('investmentModal').classList.remove('active');
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
        document.getElementById('investmentDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('investmentAmount').value = '';
        document.getElementById('interest').value = '0.00';
        document.getElementById('investmentType').value = '';
        document.getElementById('investorName').value = '';
        document.getElementById('guardianType').value = '';
        document.getElementById('guardianName').value = '';
        document.getElementById('mobileNumber').value = '';
        document.getElementById('godsName').value = '';
        document.getElementById('godIncome').checked = false;
        document.getElementById('godIncomeSection').style.display = 'none';
        document.getElementById('godIncomeSection').classList.remove('active');
        document.getElementById('paymentCash').checked = true;
    }
    
    // Search functionality
    function searchInvestments() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        let tableRows = document.querySelectorAll('#investmentTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let investorName = row.cells[6].textContent.toLowerCase();
            let mobile = row.cells[7].textContent.toLowerCase();
            let investmentType = row.cells[5].textContent.toLowerCase();
            if (investorName.includes(input) || mobile.includes(input) || investmentType.includes(input)) {
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
            searchInvestments();
        }
    });
    
    // Initialize God Income section
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('godIncomeSection').style.display = 'none';
        document.getElementById('godIncomeSection').classList.remove('active');
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>