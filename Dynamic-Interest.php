<?php
session_start();
$currentPage = 'dynamic-interest';
$pageTitle = 'Dynamic Interest Management';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Fetch product types for dropdown from existing tables
$product_types = ['தங்கம்', 'வெள்ளி', 'personal loan', 'gold', 'silver', 'platinum'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $product_type = $_POST['product_type'] ?? '';
                $interest_type = $_POST['interest_type'] ?? '';
                $from_amount = $_POST['from_amount'] ?? 0;
                $to_amount = $_POST['to_amount'] ?? 0;
                $months_1_3 = $_POST['months_1_3'] ?? 0;
                $months_4_6 = $_POST['months_4_6'] ?? 0;
                $months_7_9 = $_POST['months_7_9'] ?? 0;
                $months_10_12 = $_POST['months_10_12'] ?? 0;
                $above_1_year = $_POST['above_1_year'] ?? 0;
                $status = isset($_POST['status']) ? 1 : 0;
                
                $insert_query = "INSERT INTO dynamic_interest 
                                (product_type, interest_type, from_amount, to_amount, months_1_3, months_4_6, months_7_9, months_10_12, above_1_year, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("ssdddddddi", $product_type, $interest_type, $from_amount, $to_amount, $months_1_3, $months_4_6, $months_7_9, $months_10_12, $above_1_year, $status);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new dynamic interest', 'dynamic_interest', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    header('Location: Dynamic-Interest.php?success=added');
                    exit();
                } else {
                    $error = "Error adding dynamic interest: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $product_type = $_POST['product_type'] ?? '';
                $interest_type = $_POST['interest_type'] ?? '';
                $from_amount = $_POST['from_amount'] ?? 0;
                $to_amount = $_POST['to_amount'] ?? 0;
                $months_1_3 = $_POST['months_1_3'] ?? 0;
                $months_4_6 = $_POST['months_4_6'] ?? 0;
                $months_7_9 = $_POST['months_7_9'] ?? 0;
                $months_10_12 = $_POST['months_10_12'] ?? 0;
                $above_1_year = $_POST['above_1_year'] ?? 0;
                $status = isset($_POST['status']) ? 1 : 0;
                
                $update_query = "UPDATE dynamic_interest 
                                SET product_type = ?, interest_type = ?, from_amount = ?, to_amount = ?, 
                                    months_1_3 = ?, months_4_6 = ?, months_7_9 = ?, months_10_12 = ?, above_1_year = ?, status = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ssddddddddi", $product_type, $interest_type, $from_amount, $to_amount, $months_1_3, $months_4_6, $months_7_9, $months_10_12, $above_1_year, $status, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated dynamic interest', 'dynamic_interest', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Dynamic-Interest.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating dynamic interest: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM dynamic_interest WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted dynamic interest', 'dynamic_interest', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    header('Location: Dynamic-Interest.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting dynamic interest: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE dynamic_interest SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    header('Location: Dynamic-Interest.php?success=status_updated');
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
            $message = "Dynamic interest added successfully!";
            break;
        case 'updated':
            $message = "Dynamic interest updated successfully!";
            break;
        case 'deleted':
            $message = "Dynamic interest deleted successfully!";
            break;
        case 'status_updated':
            $message = "Dynamic interest status updated successfully!";
            break;
    }
}

// Fetch all dynamic interest records
$dynamic_query = "SELECT * FROM dynamic_interest ORDER BY id ASC";
$dynamic_result = $conn->query($dynamic_query);
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

        /* Dynamic Interest Container */
        .dynamic-container {
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

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
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
        .dynamic-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1200px;
        }

        .dynamic-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .dynamic-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .dynamic-table tbody tr {
            transition: all 0.3s;
        }

        .dynamic-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .product-type {
            font-weight: 600;
        }

        .interest-rate {
            font-weight: 600;
            color: #48bb78;
        }

        .amount-range {
            font-weight: 500;
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

        .btn-icon.add-row:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.remove-row:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        /* Mobile Card View */
        .mobile-cards {
            display: none;
        }

        .dynamic-mobile-card {
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
            max-width: 800px;
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

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        /* Dynamic Rows Section */
        .dynamic-rows-section {
            margin-top: 30px;
        }

        .dynamic-rows-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .dynamic-rows-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .add-row-btn {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .add-row-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        /* Fixed: Improved dynamic row layout to prevent overflow */
        .dynamic-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 8px;
            margin-bottom: 10px;
            align-items: start;
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        /* Make form groups more compact */
        .dynamic-row .form-group {
            margin-bottom: 0;
            min-width: 0;
        }

        /* Adjust input styles for better fit */
        .dynamic-row .form-control {
            padding: 10px 8px;
            font-size: 13px;
            height: 40px;
            width: 100%;
        }

        /* Remove left padding for input icons in dynamic rows */
        .dynamic-row .input-group .input-icon {
            display: none;
        }

        /* Adjust remove button */
        .remove-row-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: #fed7d7;
            color: #f56565;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 24px;
        }

        .remove-row-btn:hover {
            background: #f56565;
            color: white;
        }

        /* Responsive adjustments for smaller screens */
        @media (max-width: 1200px) {
            .dynamic-row {
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
            }
            
            .remove-row-btn {
                margin-top: 0;
                grid-column: span 4;
                width: 100%;
            }
        }

        @media (max-width: 992px) {
            .dynamic-row {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .remove-row-btn {
                grid-column: span 3;
            }
        }

        @media (max-width: 768px) {
            .dynamic-row {
                grid-template-columns: 1fr;
            }
            
            .remove-row-btn {
                grid-column: span 1;
            }
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
        @media (max-width: 1024px) {
            .page-content {
                padding: 20px;
            }
            
            .dynamic-container {
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
            
            .form-row {
                flex-direction: column;
            }
            
            .form-group {
                width: 100%;
            }
            
            .desktop-table {
                display: none;
            }
            
            .mobile-cards {
                display: block;
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
            
            .dynamic-container {
                padding: 0 10px;
            }
            
            .form-card, .table-card {
                padding: 20px;
            }
            
            .dynamic-mobile-card {
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
            <div class="dynamic-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-arrow-left-right" style="margin-right: 10px;"></i>
                        Dynamic Interest
                        <span class="badge-count"><?php echo $dynamic_result ? $dynamic_result->num_rows : 0; ?></span>
                    </h1>
                    <div class="header-actions">
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Dynamic Interest
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
                        Add New Dynamic Interest
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <!-- Product Type and Interest Type Row -->
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label required">Product Type *</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="product_type" class="form-select" required>
                                        <option value="">Select Product Type</option>
                                        <?php foreach ($product_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Interest Type *</label>
                                <div class="input-group">
                                    <i class="bi bi-percent input-icon"></i>
                                    <input type="text" name="interest_type" class="form-control" 
                                           placeholder="Enter interest type (e.g., Gold Interest)" required>
                                </div>
                                <small style="color: #718096;">Enter the name of the interest rate (e.g., Gold Interest, Silver Interest)</small>
                            </div>
                        </div>

                        <!-- Dynamic Rows Section -->
                        <div class="dynamic-rows-section">
                            <div class="dynamic-rows-header">
                                <span class="dynamic-rows-title">Interest Rate Slabs</span>
                                <button type="button" class="add-row-btn" onclick="addDynamicRow()">
                                    <i class="bi bi-plus-circle"></i> Add Row
                                </button>
                            </div>
                            
                            <div id="dynamicRowsContainer">
                                <!-- First Row -->
                                <div class="dynamic-row" id="row-1">
                                    <div class="form-group">
                                        <label class="form-label">From Amount (₹)</label>
                                        <input type="number" name="from_amount[]" class="form-control" step="0.01" min="0" placeholder="From" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">To Amount (₹)</label>
                                        <input type="number" name="to_amount[]" class="form-control" step="0.01" min="0" placeholder="To" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">1-3 Months (%)</label>
                                        <input type="number" name="months_1_3[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">4-6 Months (%)</label>
                                        <input type="number" name="months_4_6[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">7-9 Months (%)</label>
                                        <input type="number" name="months_7_9[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">10-12 Months (%)</label>
                                        <input type="number" name="months_10_12[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Above 1 Year (%)</label>
                                        <input type="number" name="above_1_year[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="remove-row-btn" onclick="removeRow(1)" style="display: none;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group" style="margin-top: 20px;">
                                <label class="form-label">Status</label>
                                <div class="input-group">
                                    <i class="bi bi-toggle-on input-icon"></i>
                                    <select name="status" class="form-select">
                                        <option value="1" selected>Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions" style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="bi bi-eraser"></i>
                                Clear
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
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

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="dynamic-table" id="dynamicTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Product Type</th>
                                <th>Interest Type</th>
                                <th>From Amount</th>
                                <th>To Amount</th>
                                <th>1-3 Months</th>
                                <th>4-6 Months</th>
                                <th>7-9 Months</th>
                                <th>10-12 Months</th>
                                <th>Above 1 Year</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dynamic_result && $dynamic_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $dynamic_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td class="product-type tamil-text">
                                            <i class="bi bi-tag" style="color: #667eea; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['product_type']); ?>
                                        </td>
                                        <td>
                                            <i class="bi bi-percent" style="color: #48bb78; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($row['interest_type']); ?>
                                        </td>
                                        <td class="amount-range">₹<?php echo number_format($row['from_amount'], 2); ?></td>
                                        <td class="amount-range">₹<?php echo number_format($row['to_amount'], 2); ?></td>
                                        <td class="interest-rate"><?php echo number_format($row['months_1_3'], 2); ?>%</td>
                                        <td class="interest-rate"><?php echo number_format($row['months_4_6'], 2); ?>%</td>
                                        <td class="interest-rate"><?php echo number_format($row['months_7_9'], 2); ?>%</td>
                                        <td class="interest-rate"><?php echo number_format($row['months_10_12'], 2); ?>%</td>
                                        <td class="interest-rate"><?php echo number_format($row['above_1_year'], 2); ?>%</td>
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
                                    <td colspan="12" class="empty-state">
                                        <i class="bi bi-arrow-left-right"></i>
                                        <p>No dynamic interest records found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Dynamic Interest
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" id="mobileCards">
                    <?php if ($dynamic_result && $dynamic_result->num_rows > 0): ?>
                        <?php mysqli_data_seek($dynamic_result, 0); ?>
                        <?php $sno = 1; ?>
                        <?php while ($row = $dynamic_result->fetch_assoc()): ?>
                            <div class="dynamic-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-sno">#<?php echo $sno++; ?></span>
                                    <span class="mobile-product-type tamil-text">
                                        <i class="bi bi-tag" style="color: #667eea;"></i>
                                        <?php echo htmlspecialchars($row['product_type']); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Interest Type</span>
                                        <span class="detail-value">
                                            <i class="bi bi-percent" style="color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($row['interest_type']); ?>
                                        </span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Amount Range</span>
                                        <span class="detail-value highlight">₹<?php echo number_format($row['from_amount'], 2); ?> - ₹<?php echo number_format($row['to_amount'], 2); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">1-3 Months</span>
                                        <span class="detail-value highlight"><?php echo number_format($row['months_1_3'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">4-6 Months</span>
                                        <span class="detail-value highlight"><?php echo number_format($row['months_4_6'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">7-9 Months</span>
                                        <span class="detail-value highlight"><?php echo number_format($row['months_7_9'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">10-12 Months</span>
                                        <span class="detail-value highlight"><?php echo number_format($row['months_10_12'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Above 1 Year</span>
                                        <span class="detail-value highlight"><?php echo number_format($row['above_1_year'], 2); ?>%</span>
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
                            <i class="bi bi-arrow-left-right"></i>
                            <p>No dynamic interest records found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Dynamic Interest
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
<div class="modal" id="dynamicModal">
    <div class="modal-content">
        <form method="POST" id="dynamicForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="dynamicId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Dynamic Interest
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Product Type *</label>
                        <select name="product_type" id="modalProductType" class="form-select" required>
                            <option value="">Select Product Type</option>
                            <?php foreach ($product_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Interest Type *</label>
                        <input type="text" name="interest_type" id="modalInterestType" class="form-control" 
                               placeholder="Enter interest type" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">From Amount (₹) *</label>
                        <input type="number" name="from_amount" id="modalFromAmount" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">To Amount (₹) *</label>
                        <input type="number" name="to_amount" id="modalToAmount" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">1-3 Months (%) *</label>
                        <input type="number" name="months_1_3" id="modalMonths1_3" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">4-6 Months (%) *</label>
                        <input type="number" name="months_4_6" id="modalMonths4_6" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">7-9 Months (%) *</label>
                        <input type="number" name="months_7_9" id="modalMonths7_9" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">10-12 Months (%) *</label>
                        <input type="number" name="months_10_12" id="modalMonths10_12" class="form-control" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Above 1 Year (%) *</label>
                        <input type="number" name="above_1_year" id="modalAbove1Year" class="form-control" step="0.01" min="0" required>
                    </div>
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
                        Are you sure you want to delete this dynamic interest record?<br>
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
    let rowCount = 1;
    
    function addDynamicRow() {
        rowCount++;
        const container = document.getElementById('dynamicRowsContainer');
        const newRow = document.createElement('div');
        newRow.className = 'dynamic-row';
        newRow.id = `row-${rowCount}`;
        newRow.innerHTML = `
            <div class="form-group">
                <label class="form-label">From Amount (₹)</label>
                <input type="number" name="from_amount[]" class="form-control" step="0.01" min="0" placeholder="From" required>
            </div>
            <div class="form-group">
                <label class="form-label">To Amount (₹)</label>
                <input type="number" name="to_amount[]" class="form-control" step="0.01" min="0" placeholder="To" required>
            </div>
            <div class="form-group">
                <label class="form-label">1-3 Months (%)</label>
                <input type="number" name="months_1_3[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
            </div>
            <div class="form-group">
                <label class="form-label">4-6 Months (%)</label>
                <input type="number" name="months_4_6[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
            </div>
            <div class="form-group">
                <label class="form-label">7-9 Months (%)</label>
                <input type="number" name="months_7_9[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
            </div>
            <div class="form-group">
                <label class="form-label">10-12 Months (%)</label>
                <input type="number" name="months_10_12[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
            </div>
            <div class="form-group">
                <label class="form-label">Above 1 Year (%)</label>
                <input type="number" name="above_1_year[]" class="form-control" step="0.01" min="0" placeholder="Rate" required>
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="remove-row-btn" onclick="removeRow(${rowCount})">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(newRow);
    }
    
    function removeRow(rowId) {
        if (rowCount > 1) {
            const row = document.getElementById(`row-${rowId}`);
            row.remove();
            rowCount--;
        }
    }
    
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Dynamic Interest';
        document.getElementById('dynamicId').value = '';
        document.getElementById('modalProductType').value = '';
        document.getElementById('modalInterestType').value = '';
        document.getElementById('modalFromAmount').value = '';
        document.getElementById('modalToAmount').value = '';
        document.getElementById('modalMonths1_3').value = '';
        document.getElementById('modalMonths4_6').value = '';
        document.getElementById('modalMonths7_9').value = '';
        document.getElementById('modalMonths10_12').value = '';
        document.getElementById('modalAbove1Year').value = '';
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('dynamicModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Dynamic Interest';
        document.getElementById('dynamicId').value = data.id;
        document.getElementById('modalProductType').value = data.product_type;
        document.getElementById('modalInterestType').value = data.interest_type;
        document.getElementById('modalFromAmount').value = data.from_amount;
        document.getElementById('modalToAmount').value = data.to_amount;
        document.getElementById('modalMonths1_3').value = data.months_1_3;
        document.getElementById('modalMonths4_6').value = data.months_4_6;
        document.getElementById('modalMonths7_9').value = data.months_7_9;
        document.getElementById('modalMonths10_12').value = data.months_10_12;
        document.getElementById('modalAbove1Year').value = data.above_1_year;
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('dynamicModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('dynamicModal').classList.remove('active');
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
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this dynamic interest record?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
    }
    
    function clearForm() {
        // Reset all form fields
        document.querySelector('select[name="product_type"]').value = '';
        document.querySelector('input[name="interest_type"]').value = '';
        
        // Clear all dynamic rows except first
        const container = document.getElementById('dynamicRowsContainer');
        const rows = container.getElementsByClassName('dynamic-row');
        for (let i = rows.length - 1; i > 0; i--) {
            rows[i].remove();
        }
        rowCount = 1;
        
        // Clear first row fields
        const firstRow = document.getElementById('row-1');
        firstRow.querySelectorAll('input[type="number"]').forEach(input => input.value = '');
        
        // Reset status
        document.querySelector('select[name="status"]').value = '1';
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
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>