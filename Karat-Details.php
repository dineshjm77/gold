<?php
session_start();
$currentPage = 'karat-details';
$pageTitle = 'Karat Details Management';

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
                $product_type = $_POST['product_type'] ?? '';
                $karat = $_POST['karat'] ?? 0;
                $max_value = $_POST['max_value'] ?? 0;
                $loan_value = $_POST['loan_value'] ?? 0;
                $status = isset($_POST['status']) ? 1 : 0;
                
                // Create karat_details table if it doesn't exist
                $insert_query = "INSERT INTO karat_details (product_type, karat, max_value_per_gram, loan_value_per_gram, status) 
                                VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("siddi", $product_type, $karat, $max_value, $loan_value, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new karat detail', 'karat_details', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Karat-Details.php?success=added');
                    exit();
                } else {
                    $error = "Error adding karat detail: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $product_type = $_POST['product_type'] ?? '';
                $karat = $_POST['karat'] ?? 0;
                $max_value = $_POST['max_value'] ?? 0;
                $loan_value = $_POST['loan_value'] ?? 0;
                $status = isset($_POST['status']) ? 1 : 0;
                
                $update_query = "UPDATE karat_details 
                                SET product_type = ?, karat = ?, max_value_per_gram = ?, loan_value_per_gram = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("siddii", $product_type, $karat, $max_value, $loan_value, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated karat detail', 'karat_details', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Karat-Details.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating karat detail: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM karat_details WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted karat detail', 'karat_details', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Karat-Details.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting karat detail: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE karat_details SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    header('Location: Karat-Details.php?success=status_updated');
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
            $message = "Karat detail added successfully!";
            break;
        case 'updated':
            $message = "Karat detail updated successfully!";
            break;
        case 'deleted':
            $message = "Karat detail deleted successfully!";
            break;
        case 'status_updated':
            $message = "Karat detail status updated successfully!";
            break;
    }
}

// Fetch all karat details
// First, check if the table exists, if not create it
$table_check = $conn->query("SHOW TABLES LIKE 'karat_details'");
if ($table_check->num_rows == 0) {
    // Create karat_details table
    $create_table = "CREATE TABLE IF NOT EXISTS `karat_details` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `product_type` varchar(100) NOT NULL,
        `karat` decimal(4,2) NOT NULL,
        `max_value_per_gram` decimal(12,2) NOT NULL,
        `loan_value_per_gram` decimal(12,2) NOT NULL,
        `status` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table);
    
    // Insert sample data from screenshot
    $sample_data = [
        ['தங்கம்', 22, 13000, 8000, 1],
        ['தங்கம்', 20, 9000, 7500, 1],
        ['தங்கம்', 18, 6500, 5000, 1],
        ['வெள்ளி', 70, 130, 70, 1],
        ['தங்கம்', 21, 6500, 6000, 1],
        ['தங்கம்', 24, 120000, 6800, 1],
        ['personal loan', 22, 10000, 2000, 0],
        ['வெள்ளி', 80, 250, 100, 1]
    ];
    
    foreach ($sample_data as $data) {
        $insert = $conn->prepare("INSERT INTO karat_details (product_type, karat, max_value_per_gram, loan_value_per_gram, status) VALUES (?, ?, ?, ?, ?)");
        $insert->bind_param("siddi", $data[0], $data[1], $data[2], $data[3], $data[4]);
        $insert->execute();
    }
}

$karat_query = "SELECT * FROM karat_details ORDER BY id ASC";
$karat_result = $conn->query($karat_query);

// Fetch product types for dropdown (you may have a product_types table)
$product_types = ['தங்கம்', 'வெள்ளி', 'personal loan', 'gold', 'silver'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        /* Karat Container */
        .karat-container {
            width: 100%;
            max-width: 1200px;
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

        /* Search Bar */
        .search-container {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .search-box {
            display: flex;
            gap: 15px;
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

        /* Desktop Table View */
        .karat-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1000px;
        }

        .karat-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .karat-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .karat-table tbody tr {
            transition: all 0.3s;
        }

        .karat-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .karat-table .product-type {
            font-weight: 600;
        }

        .karat-table .amount {
            font-weight: 600;
            color: #48bb78;
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

        .karat-mobile-card {
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

        .mobile-sno {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            padding: 4px 12px;
            border-radius: 50px;
        }

        .mobile-product-type {
            font-size: 18px;
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

        .detail-value.highlight {
            color: #48bb78;
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
            max-width: 500px;
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

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 14px 16px;
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

        .input-group {
            display: flex;
            align-items: center;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
        }

        .input-group:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .input-group-text {
            padding: 14px 16px;
            background: #edf2f7;
            color: #4a5568;
            font-weight: 600;
            border-right: 2px solid #e2e8f0;
        }

        .input-group .form-control {
            border: none;
            border-radius: 0;
            background: transparent;
        }

        .input-group .form-control:focus {
            box-shadow: none;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .form-check-label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
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

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 101, 101, 0.5);
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
            max-width: 1200px;
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
        @media (max-width: 1024px) {
            .search-box {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
                justify-content: center;
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
            
            .karat-container {
                padding: 0 10px;
            }
            
            .table-card {
                padding: 15px;
            }
            
            .karat-mobile-card {
                padding: 15px;
            }
        }

        /* Tamil Font Support */
        .tamil-text {
            font-family: "Noto Sans Tamil", "Latha", "Mukta", sans-serif;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">
            <div class="karat-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-gem" style="margin-right: 10px;"></i>
                        Karat Details
                        <span class="badge-count"><?php echo $karat_result ? $karat_result->num_rows : 0; ?></span>
                    </h1>
                    <div class="header-actions">
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Karat
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

                <!-- Search Bar -->
                <div class="search-container">
                    <div class="search-box">
                        <input type="text" class="search-input" id="searchInput" placeholder="Search by product type or karat..." onkeyup="searchKarat()">
                        <button class="search-btn" onclick="searchKarat()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="karat-table" id="karatTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Product Type</th>
                                <th>Karat</th>
                                <th>Maximum Value / Per Gram</th>
                                <th>Loan value / Per gram</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($karat_result && $karat_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $karat_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td class="product-type tamil-text">
                                            <i class="bi bi-tag" style="color: #667eea; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['product_type']); ?>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: #edf2f7; color: #2d3748; padding: 4px 8px; border-radius: 20px;">
                                                <i class="bi bi-star-fill" style="color: #ecc94b; margin-right: 4px;"></i>
                                                <?php echo $row['karat']; ?>K
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($row['max_value_per_gram']); ?></td>
                                        <td class="amount">₹<?php echo number_format($row['loan_value_per_gram']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                                                <i class="bi bi-<?php echo $row['status'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo $row['status'] ? 'Active' : 'InActive'; ?>
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
                                    <td colspan="7" class="empty-state">
                                        <i class="bi bi-gem"></i>
                                        <p>No karat details found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Karat
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" id="mobileCards">
                    <?php if ($karat_result && $karat_result->num_rows > 0): ?>
                        <?php mysqli_data_seek($karat_result, 0); ?>
                        <?php $sno = 1; ?>
                        <?php while ($row = $karat_result->fetch_assoc()): ?>
                            <div class="karat-mobile-card" data-product="<?php echo strtolower($row['product_type']); ?>" data-karat="<?php echo $row['karat']; ?>">
                                <div class="mobile-card-header">
                                    <span class="mobile-sno">#<?php echo $sno++; ?></span>
                                    <span class="mobile-product-type tamil-text">
                                        <i class="bi bi-tag" style="color: #667eea;"></i>
                                        <?php echo htmlspecialchars($row['product_type']); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Karat</span>
                                        <span class="detail-value">
                                            <i class="bi bi-star-fill" style="color: #ecc94b;"></i>
                                            <?php echo $row['karat']; ?>K
                                        </span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Max Value/Gram</span>
                                        <span class="detail-value highlight">₹<?php echo number_format($row['max_value_per_gram']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Loan Value/Gram</span>
                                        <span class="detail-value highlight">₹<?php echo number_format($row['loan_value_per_gram']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-status">
                                    <span class="status-badge <?php echo $row['status'] ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="bi bi-<?php echo $row['status'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                        <?php echo $row['status'] ? 'Active' : 'InActive'; ?>
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
                            <i class="bi bi-gem"></i>
                            <p>No karat details found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Karat
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
<div class="modal" id="karatModal">
    <div class="modal-content">
        <form method="POST" id="karatForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="karatId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Karat Detail
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Product Type *</label>
                    <select name="product_type" id="productType" class="form-select" required>
                        <option value="">Select Product Type</option>
                        <?php foreach ($product_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                        <?php endforeach; ?>
                        <option value="other">Other (specify in karat field)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Karat *</label>
                    <input type="number" name="karat" id="karat" class="form-control" step="0.01" min="0" placeholder="Enter karat value" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Maximum Value / Per Gram *</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="max_value" id="maxValue" class="form-control" step="0.01" min="0" placeholder="Enter maximum value" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Loan value / Per gram *</label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="loan_value" id="loanValue" class="form-control" step="0.01" min="0" placeholder="Enter loan value" required>
                    </div>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="form-check">
                        <input type="checkbox" name="status" id="status" class="form-check-input" checked>
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
                        Are you sure you want to delete this karat detail?<br>
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
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Karat Detail';
        document.getElementById('karatId').value = '';
        document.getElementById('productType').value = '';
        document.getElementById('karat').value = '';
        document.getElementById('maxValue').value = '';
        document.getElementById('loanValue').value = '';
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('karatModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Karat Detail';
        document.getElementById('karatId').value = data.id;
        document.getElementById('productType').value = data.product_type;
        document.getElementById('karat').value = data.karat;
        document.getElementById('maxValue').value = data.max_value_per_gram;
        document.getElementById('loanValue').value = data.loan_value_per_gram;
        document.getElementById('status').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('karatModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('karatModal').classList.remove('active');
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
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this karat detail?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
    }
    
    // Search functionality
    function searchKarat() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        // Search in desktop table
        let tableRows = document.querySelectorAll('#karatTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let productType = row.cells[1].textContent.toLowerCase();
            let karat = row.cells[2].textContent.toLowerCase();
            if (productType.includes(input) || karat.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Search in mobile cards
        let mobileCards = document.querySelectorAll('.karat-mobile-card');
        mobileCards.forEach(card => {
            let product = card.getAttribute('data-product') || '';
            let karat = card.getAttribute('data-karat') || '';
            if (product.includes(input) || karat.includes(input)) {
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
            searchKarat();
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>