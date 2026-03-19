<?php
session_start();
$currentPage = 'interest-type';
$pageTitle = 'Interest Type Management';

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
                $interest_type = $_POST['interest_type'] ?? '';
                $daily_monthly = $_POST['daily_monthly'] ?? 'Monthly';
                $rate = $_POST['rate'] ?? 0;
                $grace_days = $_POST['grace_days'] ?? 0;
                $penal_rate = $_POST['penal_rate'] ?? 0;
                $fixed_dynamic = $_POST['fixed_dynamic'] ?? null;
                $description = $_POST['description'] ?? '';
                
                $insert_query = "INSERT INTO interest_settings 
                                (interest_type, daily_monthly, rate, grace_days, penal_rate, fixed_dynamic, description, is_active) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($insert_query);
                $stmt->bind_param("ssddiss", $interest_type, $daily_monthly, $rate, $grace_days, $penal_rate, $fixed_dynamic, $description);
                
                if ($stmt->execute()) {
                    $new_id = $conn->insert_id;
                    
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'create', 'Added new interest type', 'interest_settings', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                    $log_stmt->execute();
                    
                    // Redirect to prevent form resubmission on refresh
                    header('Location: Interest-Type.php?success=added');
                    exit();
                } else {
                    $error = "Error adding interest type: " . $stmt->error;
                }
                break;
                
            case 'edit':
                $id = $_POST['id'] ?? 0;
                $interest_type = $_POST['interest_type'] ?? '';
                $daily_monthly = $_POST['daily_monthly'] ?? 'Monthly';
                $rate = $_POST['rate'] ?? 0;
                $grace_days = $_POST['grace_days'] ?? 0;
                $penal_rate = $_POST['penal_rate'] ?? 0;
                $fixed_dynamic = $_POST['fixed_dynamic'] ?? null;
                $description = $_POST['description'] ?? '';
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                $update_query = "UPDATE interest_settings 
                                SET interest_type = ?, daily_monthly = ?, rate = ?, grace_days = ?, 
                                    penal_rate = ?, fixed_dynamic = ?, description = ?, is_active = ?
                                WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ssddissii", $interest_type, $daily_monthly, $rate, $grace_days, $penal_rate, $fixed_dynamic, $description, $is_active, $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'update', 'Updated interest type', 'interest_settings', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    // Redirect to prevent form resubmission on refresh
                    header('Location: Interest-Type.php?success=updated');
                    exit();
                } else {
                    $error = "Error updating interest type: " . $stmt->error;
                }
                break;
                
            case 'delete':
                $id = $_POST['id'] ?? 0;
                
                $delete_query = "DELETE FROM interest_settings WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Log the activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                 VALUES (?, 'delete', 'Deleted interest type', 'interest_settings', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                    $log_stmt->execute();
                    
                    // Redirect to prevent form resubmission on refresh
                    header('Location: Interest-Type.php?success=deleted');
                    exit();
                } else {
                    $error = "Error deleting interest type: " . $stmt->error;
                }
                break;
                
            case 'toggle_status':
                $id = $_POST['id'] ?? 0;
                $current_status = $_POST['current_status'] ?? 1;
                $new_status = $current_status ? 0 : 1;
                
                $update_query = "UPDATE interest_settings SET is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("ii", $new_status, $id);
                
                if ($stmt->execute()) {
                    // Redirect to prevent form resubmission on refresh
                    header('Location: Interest-Type.php?success=status_updated');
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
            $message = "Interest type added successfully!";
            break;
        case 'updated':
            $message = "Interest type updated successfully!";
            break;
        case 'deleted':
            $message = "Interest type deleted successfully!";
            break;
        case 'status_updated':
            $message = "Interest type status updated!";
            break;
    }
}

// Fetch all interest settings
$interest_query = "SELECT * FROM interest_settings ORDER BY created_at DESC";
$interest_result = $conn->query($interest_query);
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

        /* Interest Container */
        .interest-container {
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
        .interest-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1200px;
        }

        .interest-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .interest-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .interest-table tbody tr {
            transition: all 0.3s;
        }

        .interest-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-default {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
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

        .interest-mobile-card {
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

        .mobile-type {
            font-weight: 600;
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 12px;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
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

        .empty-state .add-btn {
            display: inline-block;
            padding: 10px 20px;
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
            
            .interest-container {
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
            
            .interest-container {
                padding: 0 10px;
            }
            
            .table-card {
                padding: 15px;
            }
            
            .interest-mobile-card {
                padding: 15px;
            }
        }

        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }

        /* Tooltip */
        [title] {
            cursor: help;
        }
    </style>
</head>
<body>

<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>

        <div class="page-content">
            <div class="interest-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-percent" style="margin-right: 10px;"></i>
                        Interest Type Management
                        <span class="badge-count"><?php echo $interest_result ? $interest_result->num_rows : 0; ?></span>
                    </h1>
                    <div class="header-actions">
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Interest Type
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

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="interest-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Interest Type</th>
                                <th>Daily/Monthly</th>
                                <th>Rate (%)</th>
                                <th>Grace Days</th>
                                <th>Penal Rate (%)</th>
                                <th>Fixed/Dynamic</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($interest_result && $interest_result->num_rows > 0): ?>
                                <?php while ($row = $interest_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                                        <td>
                                            <span style="font-weight: 600; color: #667eea;">
                                                <i class="bi bi-tag" style="margin-right: 5px;"></i>
                                                <?php echo ucfirst(str_replace('_', ' ', $row['interest_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($row['daily_monthly']): ?>
                                                <span class="badge badge-default">
                                                    <?php echo $row['daily_monthly']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo number_format($row['rate'], 2); ?>%</strong></td>
                                        <td><?php echo $row['grace_days']; ?> days</td>
                                        <td><?php echo number_format($row['penal_rate'], 2); ?>%</td>
                                        <td>
                                            <?php if ($row['fixed_dynamic']): ?>
                                                <span class="badge badge-default">
                                                    <?php echo ucfirst($row['fixed_dynamic']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['description']): ?>
                                                <span title="<?php echo htmlspecialchars($row['description']); ?>">
                                                    <i class="bi bi-chat-text" style="color: #718096; margin-right: 5px;"></i>
                                                    <?php echo substr(htmlspecialchars($row['description']), 0, 20) . '...'; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $row['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                                <i class="bi bi-<?php echo $row['is_active'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-calendar" style="color: #718096; margin-right: 5px;"></i>
                                            <?php echo date('d M Y', strtotime($row['created_at'])); ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($row['is_active']): ?>
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
                                    <td colspan="11" class="empty-state">
                                        <i class="bi bi-inbox"></i>
                                        <p>No interest types found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Interest Type
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards">
                    <?php if ($interest_result && $interest_result->num_rows > 0): ?>
                        <?php mysqli_data_seek($interest_result, 0); ?>
                        <?php while ($row = $interest_result->fetch_assoc()): ?>
                            <div class="interest-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-id">#<?php echo $row['id']; ?></span>
                                    <span class="<?php echo $row['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                        <i class="bi bi-<?php echo $row['is_active'] ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                        <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-type">
                                    <i class="bi bi-tag" style="color: #667eea; margin-right: 5px;"></i>
                                    <?php echo ucfirst(str_replace('_', ' ', $row['interest_type'])); ?>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Daily/Monthly</span>
                                        <span class="detail-value"><?php echo $row['daily_monthly'] ?: '-'; ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Rate</span>
                                        <span class="detail-value"><?php echo number_format($row['rate'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Grace Days</span>
                                        <span class="detail-value"><?php echo $row['grace_days']; ?> days</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Penal Rate</span>
                                        <span class="detail-value"><?php echo number_format($row['penal_rate'], 2); ?>%</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Fixed/Dynamic</span>
                                        <span class="detail-value"><?php echo ucfirst($row['fixed_dynamic']) ?: '-'; ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Created</span>
                                        <span class="detail-value">
                                            <i class="bi bi-calendar" style="color: #718096;"></i>
                                            <?php echo date('d M Y', strtotime($row['created_at'])); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($row['description']): ?>
                                    <div style="margin-bottom: 16px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                                        <span class="detail-label" style="margin-bottom: 4px;">Description</span>
                                        <p style="font-size: 13px; color: #4a5568; margin: 0;"><?php echo htmlspecialchars($row['description']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mobile-actions">
                                    <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php if ($row['is_active']): ?>
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
                            <i class="bi bi-inbox"></i>
                            <p>No interest types found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Interest Type
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
<div class="modal" id="interestModal">
    <div class="modal-content">
        <form method="POST" id="interestForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="interestId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Interest Type
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Interest Type *</label>
                    <select name="interest_type" id="interestType" class="form-select" required>
                        <option value="">Select Interest Type</option>
                        <option value="daily">Daily Interest</option>
                        <option value="monthly">Monthly Interest</option>
                        <option value="fixed">Fixed Interest</option>
                        <option value="dynamic">Dynamic Interest</option>
                        <option value="amount_based">Amount Based Interest</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Daily/Monthly</label>
                    <select name="daily_monthly" id="dailyMonthly" class="form-select">
                        <option value="">Select</option>
                        <option value="Daily">Daily</option>
                        <option value="Monthly">Monthly</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Rate (%) *</label>
                    <input type="number" name="rate" id="rate" class="form-control" step="0.01" min="0" placeholder="Enter interest rate" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Grace Days</label>
                    <input type="number" name="grace_days" id="graceDays" class="form-control" step="1" min="0" value="0" placeholder="Enter grace days">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Penal Rate (%)</label>
                    <input type="number" name="penal_rate" id="penalRate" class="form-control" step="0.01" min="0" value="0" placeholder="Enter penal rate">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Fixed/Dynamic</label>
                    <select name="fixed_dynamic" id="fixedDynamic" class="form-select">
                        <option value="">Select</option>
                        <option value="fixed">Fixed</option>
                        <option value="dynamic">Dynamic</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" placeholder="Enter description"></textarea>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="form-check">
                        <input type="checkbox" name="is_active" id="isActive" class="form-check-input" checked>
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
                    Save Changes
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
                        Are you sure you want to delete this interest type?<br>
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
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Interest Type';
        document.getElementById('interestId').value = '';
        document.getElementById('interestType').value = '';
        document.getElementById('dailyMonthly').value = '';
        document.getElementById('rate').value = '';
        document.getElementById('graceDays').value = '0';
        document.getElementById('penalRate').value = '0';
        document.getElementById('fixedDynamic').value = '';
        document.getElementById('description').value = '';
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('interestModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Interest Type';
        document.getElementById('interestId').value = data.id;
        document.getElementById('interestType').value = data.interest_type;
        document.getElementById('dailyMonthly').value = data.daily_monthly || '';
        document.getElementById('rate').value = data.rate;
        document.getElementById('graceDays').value = data.grace_days;
        document.getElementById('penalRate').value = data.penal_rate;
        document.getElementById('fixedDynamic').value = data.fixed_dynamic || '';
        document.getElementById('description').value = data.description || '';
        document.getElementById('isActive').checked = data.is_active == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('interestModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('interestModal').classList.remove('active');
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
        if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this interest type?')) {
            document.getElementById('toggleId').value = id;
            document.getElementById('toggleStatus').value = currentStatus;
            document.getElementById('toggleForm').submit();
        }
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