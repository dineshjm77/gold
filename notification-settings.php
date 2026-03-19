<?php
session_start();
$currentPage = 'notification-settings';
$pageTitle = 'Notification Settings';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Check if notification_settings table exists, if not create it
$check_table = $conn->query("SHOW TABLES LIKE 'notification_settings'");
if ($check_table->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `notification_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `notification_type` varchar(50) NOT NULL,
        `notification_title` varchar(255) NOT NULL,
        `notification_message` text DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `display_location` enum('dashboard','topbar','both') DEFAULT 'both',
        `priority` enum('low','medium','high','critical') DEFAULT 'medium',
        `start_date` date DEFAULT NULL,
        `end_date` date DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `notification_type` (`notification_type`),
        KEY `is_active` (`is_active`),
        KEY `display_location` (`display_location`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($create_table);
}

// Check if user_notifications table exists for user-specific notifications
$check_user_table = $conn->query("SHOW TABLES LIKE 'user_notifications'");
if ($check_user_table->num_rows == 0) {
    $create_user_table = "CREATE TABLE IF NOT EXISTS `user_notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `notification_id` int(11) DEFAULT NULL,
        `title` varchar(255) NOT NULL,
        `message` text NOT NULL,
        `type` varchar(50) DEFAULT 'info',
        `is_read` tinyint(1) DEFAULT 0,
        `is_archived` tinyint(1) DEFAULT 0,
        `link` varchar(255) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `read_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `notification_id` (`notification_id`),
        KEY `is_read` (`is_read`),
        CONSTRAINT `user_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($create_user_table);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new notification
        if ($_POST['action'] === 'add_notification') {
            $notification_type = mysqli_real_escape_string($conn, $_POST['notification_type']);
            $notification_title = mysqli_real_escape_string($conn, $_POST['notification_title']);
            $notification_message = mysqli_real_escape_string($conn, $_POST['notification_message']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_location = mysqli_real_escape_string($conn, $_POST['display_location']);
            $priority = mysqli_real_escape_string($conn, $_POST['priority']);
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            
            $insert_query = "INSERT INTO notification_settings 
                (notification_type, notification_title, notification_message, is_active, display_location, priority, start_date, end_date, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("sssissssi", $notification_type, $notification_title, $notification_message, $is_active, $display_location, $priority, $start_date, $end_date, $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $success = "Notification added successfully!";
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'create', ?, 'notification_settings', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_desc = "Added notification: " . $notification_title;
                $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_desc, $stmt->insert_id);
                $log_stmt->execute();
            } else {
                $error = "Error adding notification: " . $conn->error;
            }
        }
        
        // Update notification
        elseif ($_POST['action'] === 'update_notification') {
            $notification_id = intval($_POST['notification_id']);
            $notification_type = mysqli_real_escape_string($conn, $_POST['notification_type']);
            $notification_title = mysqli_real_escape_string($conn, $_POST['notification_title']);
            $notification_message = mysqli_real_escape_string($conn, $_POST['notification_message']);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_location = mysqli_real_escape_string($conn, $_POST['display_location']);
            $priority = mysqli_real_escape_string($conn, $_POST['priority']);
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            
            $update_query = "UPDATE notification_settings SET 
                notification_type = ?,
                notification_title = ?,
                notification_message = ?,
                is_active = ?,
                display_location = ?,
                priority = ?,
                start_date = ?,
                end_date = ?,
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("sssissssi", $notification_type, $notification_title, $notification_message, $is_active, $display_location, $priority, $start_date, $end_date, $notification_id);
            
            if ($stmt->execute()) {
                $success = "Notification updated successfully!";
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'update', ?, 'notification_settings', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_desc = "Updated notification: " . $notification_title;
                $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_desc, $notification_id);
                $log_stmt->execute();
            } else {
                $error = "Error updating notification: " . $conn->error;
            }
        }
        
        // Delete notification
        elseif ($_POST['action'] === 'delete_notification') {
            $notification_id = intval($_POST['notification_id']);
            
            // Get notification title for log
            $title_query = "SELECT notification_title FROM notification_settings WHERE id = ?";
            $stmt = $conn->prepare($title_query);
            $stmt->bind_param("i", $notification_id);
            $stmt->execute();
            $title_result = $stmt->get_result();
            $notification_title = $title_result->fetch_assoc()['notification_title'];
            
            $delete_query = "DELETE FROM notification_settings WHERE id = ?";
            $stmt = $conn->prepare($delete_query);
            $stmt->bind_param("i", $notification_id);
            
            if ($stmt->execute()) {
                $success = "Notification deleted successfully!";
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'delete', ?, 'notification_settings', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_desc = "Deleted notification: " . $notification_title;
                $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_desc, $notification_id);
                $log_stmt->execute();
            } else {
                $error = "Error deleting notification: " . $conn->error;
            }
        }
        
        // Toggle notification status
        elseif ($_POST['action'] === 'toggle_status') {
            $notification_id = intval($_POST['notification_id']);
            $current_status = intval($_POST['current_status']);
            $new_status = $current_status ? 0 : 1;
            
            $update_query = "UPDATE notification_settings SET is_active = ? WHERE id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ii", $new_status, $notification_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'new_status' => $new_status]);
                exit();
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
                exit();
            }
        }
        
        // Send notification to users
        elseif ($_POST['action'] === 'send_to_users') {
            $notification_id = intval($_POST['notification_id']);
            $user_type = $_POST['user_type'] ?? 'all'; // all, admin, manager, sale, accountant
            
            // Get notification details
            $notif_query = "SELECT * FROM notification_settings WHERE id = ?";
            $stmt = $conn->prepare($notif_query);
            $stmt->bind_param("i", $notification_id);
            $stmt->execute();
            $notification = $stmt->get_result()->fetch_assoc();
            
            // Build user query based on type
            $user_query = "SELECT id FROM users WHERE status = 'active'";
            if ($user_type !== 'all') {
                $user_query .= " AND role = '$user_type'";
            }
            
            $users_result = $conn->query($user_query);
            $success_count = 0;
            
            while ($user = $users_result->fetch_assoc()) {
                $insert_user_query = "INSERT INTO user_notifications 
                    (user_id, notification_id, title, message, type, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($insert_user_query);
                $stmt->bind_param("iisss", $user['id'], $notification_id, $notification['notification_title'], $notification['notification_message'], $notification['priority']);
                if ($stmt->execute()) {
                    $success_count++;
                }
            }
            
            $success = "Notification sent to $success_count users successfully!";
        }
    }
}

// Get all notifications
$notifications_query = "SELECT n.*, u.name as creator_name 
                        FROM notification_settings n
                        LEFT JOIN users u ON n.created_by = u.id
                        ORDER BY n.created_at DESC";
$notifications_result = $conn->query($notifications_query);

// Get active notifications count
$active_count_query = "SELECT COUNT(*) as count FROM notification_settings WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())";
$active_count = $conn->query($active_count_query)->fetch_assoc()['count'];

// Get unread notifications for current user
$unread_query = "SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ? AND is_read = 0";
$stmt = $conn->prepare($unread_query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$unread_count = $stmt->get_result()->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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

        .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin: 0;
        }

        .page-title i {
            font-size: 32px;
            color: #667eea;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            padding: 15px;
            border-radius: 15px;
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
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
            transform: translateY(-2px);
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
            grid-template-columns: repeat(2, 1fr);
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

        /* Priority Badges */
        .priority-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .priority-low {
            background: #c6f6d5;
            color: #22543d;
        }

        .priority-medium {
            background: #feebc8;
            color: #744210;
        }

        .priority-high {
            background: #fed7d7;
            color: #742a2a;
        }

        .priority-critical {
            background: #fbb6ce;
            color: #97266d;
        }

        /* Location Badges */
        .location-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .location-dashboard {
            background: #bee3f8;
            color: #2c5282;
        }

        .location-topbar {
            background: #c6f6d5;
            color: #22543d;
        }

        .location-both {
            background: #e9d8fd;
            color: #553c9a;
        }

        /* Status Toggle */
        .status-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .status-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e0;
            transition: .3s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #48bb78;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .notifications-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .notifications-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .notifications-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .notifications-table tbody tr:hover {
            background: #f7fafc;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .action-btn.edit {
            background: #667eea10;
            color: #667eea;
        }

        .action-btn.edit:hover {
            background: #667eea;
            color: white;
        }

        .action-btn.delete {
            background: #f5656510;
            color: #f56565;
        }

        .action-btn.delete:hover {
            background: #f56565;
            color: white;
        }

        .action-btn.send {
            background: #48bb7810;
            color: #48bb78;
        }

        .action-btn.send:hover {
            background: #48bb78;
            color: white;
        }

        .action-btn.view {
            background: #4299e110;
            color: #4299e1;
        }

        .action-btn.view:hover {
            background: #4299e1;
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            position: relative;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: #667eea;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #a0aec0;
            cursor: pointer;
            transition: all 0.3s;
        }

        .close-btn:hover {
            color: #f56565;
            transform: rotate(90deg);
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-info {
            background: #e6f7ff;
            color: #0050b3;
            border-left: 4px solid #1890ff;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
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
                <div class="container-fluid">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="page-title">
                            <i class="bi bi-bell"></i>
                            <h1>Notification Settings</h1>
                        </div>
                        <div>
                            <button class="btn btn-primary" onclick="openAddModal()">
                                <i class="bi bi-plus-circle"></i> Add Notification
                            </button>
                            <button class="btn btn-info" onclick="refreshPage()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-megaphone"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Notifications</div>
                                <div class="stat-value"><?php echo $notifications_result->num_rows; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bell"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Now</div>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-envelope"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Unread for You</div>
                                <div class="stat-value"><?php echo $unread_count; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Last Updated</div>
                                <div class="stat-value"><?php 
                                    $last_query = "SELECT MAX(updated_at) as last FROM notification_settings";
                                    $last = $conn->query($last_query)->fetch_assoc()['last'];
                                    echo $last ? date('d-m-Y', strtotime($last)) : 'N/A';
                                ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Notifications Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Notification List
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="notifications-table" id="notificationsTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Priority</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Date Range</th>
                                        <th>Created By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($notifications_result && $notifications_result->num_rows > 0): ?>
                                        <?php while ($row = $notifications_result->fetch_assoc()): 
                                            $priority_class = '';
                                            if ($row['priority'] == 'low') $priority_class = 'priority-low';
                                            elseif ($row['priority'] == 'medium') $priority_class = 'priority-medium';
                                            elseif ($row['priority'] == 'high') $priority_class = 'priority-high';
                                            elseif ($row['priority'] == 'critical') $priority_class = 'priority-critical';
                                            
                                            $location_class = '';
                                            if ($row['display_location'] == 'dashboard') $location_class = 'location-dashboard';
                                            elseif ($row['display_location'] == 'topbar') $location_class = 'location-topbar';
                                            elseif ($row['display_location'] == 'both') $location_class = 'location-both';
                                            
                                            $is_currently_active = $row['is_active'] && 
                                                (empty($row['start_date']) || $row['start_date'] <= date('Y-m-d')) && 
                                                (empty($row['end_date']) || $row['end_date'] >= date('Y-m-d'));
                                        ?>
                                        <tr>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['notification_title']); ?></strong></td>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $row['notification_type'])); ?></td>
                                            <td><?php echo substr(htmlspecialchars($row['notification_message']), 0, 50) . '...'; ?></td>
                                            <td><span class="priority-badge <?php echo $priority_class; ?>"><?php echo ucfirst($row['priority']); ?></span></td>
                                            <td><span class="location-badge <?php echo $location_class; ?>"><?php echo ucfirst($row['display_location']); ?></span></td>
                                            <td>
                                                <label class="status-toggle">
                                                    <input type="checkbox" class="status-checkbox" data-id="<?php echo $row['id']; ?>" <?php echo $row['is_active'] ? 'checked' : ''; ?> onchange="toggleStatus(<?php echo $row['id']; ?>, this)">
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <?php if ($is_currently_active): ?>
                                                    <span class="badge badge-success" style="margin-left: 5px;">Active Now</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($row['start_date'] && $row['end_date']) {
                                                    echo date('d-m-Y', strtotime($row['start_date'])) . ' to ' . date('d-m-Y', strtotime($row['end_date']));
                                                } elseif ($row['start_date']) {
                                                    echo 'From ' . date('d-m-Y', strtotime($row['start_date']));
                                                } elseif ($row['end_date']) {
                                                    echo 'Until ' . date('d-m-Y', strtotime($row['end_date']));
                                                } else {
                                                    echo 'Always';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['creator_name'] ?? 'System'); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-btn view" onclick="viewNotification(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="action-btn edit" onclick="editNotification(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="action-btn send" onclick="sendToUsers(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['notification_title']); ?>')">
                                                        <i class="bi bi-send"></i>
                                                    </button>
                                                    <button class="action-btn delete" onclick="deleteNotification(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['notification_title'])); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-bell" style="font-size: 48px; color: #cbd5e0; display: block; margin-bottom: 10px;"></i>
                                                No notifications found. Click "Add Notification" to create one.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add/Edit Notification Modal -->
    <div id="notificationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="bi bi-bell"></i> <span id="modalTitle">Add Notification</span></h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form id="notificationForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add_notification">
                <input type="hidden" name="notification_id" id="notificationId" value="">
                
                <div class="form-group">
                    <label class="form-label required">Notification Type</label>
                    <select class="form-select" name="notification_type" id="notificationType" required>
                        <option value="info">Information</option>
                        <option value="warning">Warning</option>
                        <option value="success">Success</option>
                        <option value="error">Error</option>
                        <option value="announcement">Announcement</option>
                        <option value="reminder">Reminder</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="update">Update</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label required">Title</label>
                    <input type="text" class="form-control" name="notification_title" id="notificationTitle" placeholder="Enter notification title" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Message</label>
                    <textarea class="form-control" name="notification_message" id="notificationMessage" rows="4" placeholder="Enter notification message" required></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Display Location</label>
                        <select class="form-select" name="display_location" id="displayLocation">
                            <option value="both">Both Dashboard & Topbar</option>
                            <option value="dashboard">Dashboard Only</option>
                            <option value="topbar">Topbar Only</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority" id="priority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="start_date" id="startDate">
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="end_date" id="endDate">
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" checked>
                        <label class="form-check-label" for="isActive">
                            Active (Show notification)
                        </label>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Save Notification
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Notification Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="bi bi-eye"></i> View Notification</h2>
                <button class="close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="viewContent" style="padding: 20px;">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Send to Users Modal -->
    <div id="sendModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="bi bi-send"></i> Send Notification to Users</h2>
                <button class="close-btn" onclick="closeSendModal()">&times;</button>
            </div>
            <form method="POST" id="sendForm">
                <input type="hidden" name="action" value="send_to_users">
                <input type="hidden" name="notification_id" id="sendNotificationId" value="">
                
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill"></i>
                    <span id="sendNotificationTitle"></span>
                </div>

                <div class="form-group">
                    <label class="form-label">Send to User Type</label>
                    <select class="form-select" name="user_type" required>
                        <option value="all">All Active Users</option>
                        <option value="admin">Admins Only</option>
                        <option value="manager">Managers Only</option>
                        <option value="sale">Sales Only</option>
                        <option value="accountant">Accountants Only</option>
                    </select>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeSendModal()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Send Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Initialize DataTable
        $(document).ready(function() {
            $('#notificationsTable').DataTable({
                pageLength: 10,
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No notifications found"
                },
                columnDefs: [
                    { orderable: false, targets: [9] }
                ]
            });
        });

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Notification';
            document.getElementById('formAction').value = 'add_notification';
            document.getElementById('notificationId').value = '';
            document.getElementById('notificationForm').reset();
            document.getElementById('isActive').checked = true;
            document.getElementById('notificationModal').style.display = 'block';
        }

        function editNotification(notification) {
            document.getElementById('modalTitle').textContent = 'Edit Notification';
            document.getElementById('formAction').value = 'update_notification';
            document.getElementById('notificationId').value = notification.id;
            document.getElementById('notificationType').value = notification.notification_type;
            document.getElementById('notificationTitle').value = notification.notification_title;
            document.getElementById('notificationMessage').value = notification.notification_message;
            document.getElementById('displayLocation').value = notification.display_location;
            document.getElementById('priority').value = notification.priority;
            document.getElementById('startDate').value = notification.start_date || '';
            document.getElementById('endDate').value = notification.end_date || '';
            document.getElementById('isActive').checked = notification.is_active == 1;
            document.getElementById('notificationModal').style.display = 'block';
        }

        function viewNotification(notification) {
            const content = document.getElementById('viewContent');
            const priorityClass = {
                'low': 'priority-low',
                'medium': 'priority-medium',
                'high': 'priority-high',
                'critical': 'priority-critical'
            }[notification.priority] || 'priority-medium';
            
            const locationClass = {
                'dashboard': 'location-dashboard',
                'topbar': 'location-topbar',
                'both': 'location-both'
            }[notification.display_location] || 'location-both';
            
            content.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <span class="badge badge-info">ID: #${notification.id}</span>
                    <span class="priority-badge ${priorityClass}" style="margin-left: 10px;">${notification.priority.toUpperCase()}</span>
                    <span class="location-badge ${locationClass}" style="margin-left: 10px;">${notification.display_location.toUpperCase()}</span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 5px;">${notification.notification_title}</h3>
                    <p style="color: #4a5568; line-height: 1.6;">${notification.notification_message.replace(/\n/g, '<br>')}</p>
                </div>
                
                <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                        <div>
                            <strong style="color: #718096;">Type:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${notification.notification_type}</span>
                        </div>
                        <div>
                            <strong style="color: #718096;">Status:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${notification.is_active ? 'Active' : 'Inactive'}</span>
                        </div>
                        <div>
                            <strong style="color: #718096;">Date Range:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${notification.start_date ? notification.start_date : 'Any'} to ${notification.end_date ? notification.end_date : 'Any'}</span>
                        </div>
                        <div>
                            <strong style="color: #718096;">Created:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${new Date(notification.created_at).toLocaleString()}</span>
                        </div>
                        <div>
                            <strong style="color: #718096;">Last Updated:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${new Date(notification.updated_at).toLocaleString()}</span>
                        </div>
                        <div>
                            <strong style="color: #718096;">Created By:</strong>
                            <span style="color: #2d3748; margin-left: 5px;">${notification.creator_name || 'System'}</span>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('viewModal').style.display = 'block';
        }

        function sendToUsers(id, title) {
            document.getElementById('sendNotificationId').value = id;
            document.getElementById('sendNotificationTitle').innerHTML = `<strong>Sending:</strong> ${title}`;
            document.getElementById('sendModal').style.display = 'block';
        }

        function deleteNotification(id, title) {
            Swal.fire({
                title: 'Delete Notification?',
                html: `Are you sure you want to delete <strong>${title}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_notification">
                        <input type="hidden" name="notification_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function toggleStatus(id, checkbox) {
            const currentStatus = checkbox.checked ? 1 : 0;
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=toggle_status&notification_id=${id}&current_status=${currentStatus ? 0 : 1}`
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    checkbox.checked = !checkbox.checked;
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to update status'
                    });
                }
            })
            .catch(error => {
                checkbox.checked = !checkbox.checked;
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to update status'
                });
            });
        }

        function closeModal() {
            document.getElementById('notificationModal').style.display = 'none';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function closeSendModal() {
            document.getElementById('sendModal').style.display = 'none';
        }

        function refreshPage() {
            window.location.reload();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('notificationModal');
            const viewModal = document.getElementById('viewModal');
            const sendModal = document.getElementById('sendModal');
            
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === sendModal) {
                closeSendModal();
            }
        }

        // Validate dates
        document.getElementById('notificationForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (startDate && endDate && startDate > endDate) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Date Range',
                    text: 'End date must be after start date'
                });
            }
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>