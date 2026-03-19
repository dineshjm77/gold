<?php
session_start();
$currentPage = 'branch-management';
$pageTitle = 'Branch Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle Add Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_branch') {
    $branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name']));
    $branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code']));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $website = mysqli_real_escape_string($conn, trim($_POST['website'] ?? ''));
    $manager_name = mysqli_real_escape_string($conn, trim($_POST['manager_name'] ?? ''));
    $manager_mobile = mysqli_real_escape_string($conn, trim($_POST['manager_mobile'] ?? ''));
    $opening_time = $_POST['opening_time'] ?? '09:00:00';
    $closing_time = $_POST['closing_time'] ?? '18:00:00';
    $holiday = mysqli_real_escape_string($conn, $_POST['holiday'] ?? 'Sunday');
    $status = $_POST['status'] ?? 'active';
    
    // Check if branch code already exists
    $check_query = "SELECT id FROM branches WHERE branch_code = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 's', $branch_code);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $error = "Branch code already exists. Please use a different code.";
    } else {
        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/branches/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'logo_' . $branch_code . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                    $logo_path = $upload_path;
                }
            }
        }
        
        // Handle QR code upload
        $qr_path = null;
        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['qr_code']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/branches/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'qr_' . $branch_code . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_path)) {
                    $qr_path = $upload_path;
                }
            }
        }
        
        // Insert branch
        $insert_query = "INSERT INTO branches (
            branch_name, branch_code, address, phone, email, website,
            manager_name, manager_mobile, opening_time, closing_time, holiday,
            logo_path, qr_path, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, 'ssssssssssssss', 
            $branch_name, $branch_code, $address, $phone, $email, $website,
            $manager_name, $manager_mobile, $opening_time, $closing_time, $holiday,
            $logo_path, $qr_path, $status
        );
        
        if (mysqli_stmt_execute($stmt)) {
            $branch_id = mysqli_insert_id($conn);
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'create', ?, 'branches', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "New branch created: $branch_name ($branch_code)";
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $branch_id);
            mysqli_stmt_execute($log_stmt);
            
            $message = "Branch added successfully!";
        } else {
            $error = "Error adding branch: " . mysqli_error($conn);
        }
    }
}

// Handle Edit Branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_branch') {
    $branch_id = intval($_POST['branch_id']);
    $branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name']));
    $branch_code = mysqli_real_escape_string($conn, trim($_POST['branch_code']));
    $address = mysqli_real_escape_string($conn, trim($_POST['address'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $website = mysqli_real_escape_string($conn, trim($_POST['website'] ?? ''));
    $manager_name = mysqli_real_escape_string($conn, trim($_POST['manager_name'] ?? ''));
    $manager_mobile = mysqli_real_escape_string($conn, trim($_POST['manager_mobile'] ?? ''));
    $opening_time = $_POST['opening_time'] ?? '09:00:00';
    $closing_time = $_POST['closing_time'] ?? '18:00:00';
    $holiday = mysqli_real_escape_string($conn, $_POST['holiday'] ?? 'Sunday');
    $status = $_POST['status'] ?? 'active';
    
    // Check if branch code already exists for other branches
    $check_query = "SELECT id FROM branches WHERE branch_code = ? AND id != ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 'si', $branch_code, $branch_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $error = "Branch code already exists. Please use a different code.";
    } else {
        // Get existing paths
        $path_query = "SELECT logo_path, qr_path FROM branches WHERE id = ?";
        $path_stmt = mysqli_prepare($conn, $path_query);
        mysqli_stmt_bind_param($path_stmt, 'i', $branch_id);
        mysqli_stmt_execute($path_stmt);
        $path_result = mysqli_stmt_get_result($path_stmt);
        $paths = mysqli_fetch_assoc($path_result);
        
        $logo_path = $paths['logo_path'];
        $qr_path = $paths['qr_path'];
        
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/branches/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Delete old logo if exists
                if ($logo_path && file_exists($logo_path)) {
                    unlink($logo_path);
                }
                
                $new_filename = 'logo_' . $branch_code . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                    $logo_path = $upload_path;
                }
            }
        }
        
        // Handle QR code upload
        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['qr_code']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/branches/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Delete old QR if exists
                if ($qr_path && file_exists($qr_path)) {
                    unlink($qr_path);
                }
                
                $new_filename = 'qr_' . $branch_code . '_' . time() . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_path)) {
                    $qr_path = $upload_path;
                }
            }
        }
        
        // Update branch
        $update_query = "UPDATE branches SET 
            branch_name = ?, branch_code = ?, address = ?, phone = ?, email = ?, website = ?,
            manager_name = ?, manager_mobile = ?, opening_time = ?, closing_time = ?, holiday = ?,
            logo_path = ?, qr_path = ?, status = ?, updated_at = NOW()
            WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, 'ssssssssssssssi', 
            $branch_name, $branch_code, $address, $phone, $email, $website,
            $manager_name, $manager_mobile, $opening_time, $closing_time, $holiday,
            $logo_path, $qr_path, $status, $branch_id
        );
        
        if (mysqli_stmt_execute($stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'update', ?, 'branches', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Branch updated: $branch_name ($branch_code)";
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $branch_id);
            mysqli_stmt_execute($log_stmt);
            
            $message = "Branch updated successfully!";
        } else {
            $error = "Error updating branch: " . mysqli_error($conn);
        }
    }
}

// Handle Delete Branch
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $branch_id = intval($_GET['id']);
    
    // Check if branch has users assigned
    $check_users = "SELECT COUNT(*) as user_count FROM users WHERE branch_id = ?";
    $user_stmt = mysqli_prepare($conn, $check_users);
    mysqli_stmt_bind_param($user_stmt, 'i', $branch_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $user_count = mysqli_fetch_assoc($user_result)['user_count'];
    
    if ($user_count > 0) {
        $error = "Cannot delete branch because it has $user_count user(s) assigned. Please reassign them first.";
    } else {
        // Get branch details for logging
        $branch_query = "SELECT branch_name, branch_code, logo_path, qr_path FROM branches WHERE id = ?";
        $branch_stmt = mysqli_prepare($conn, $branch_query);
        mysqli_stmt_bind_param($branch_stmt, 'i', $branch_id);
        mysqli_stmt_execute($branch_stmt);
        $branch_result = mysqli_stmt_get_result($branch_stmt);
        $branch = mysqli_fetch_assoc($branch_result);
        
        if ($branch) {
            // Delete logo and QR files
            if (!empty($branch['logo_path']) && file_exists($branch['logo_path'])) {
                unlink($branch['logo_path']);
            }
            if (!empty($branch['qr_path']) && file_exists($branch['qr_path'])) {
                unlink($branch['qr_path']);
            }
            
            // Delete branch
            $delete_query = "DELETE FROM branches WHERE id = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($delete_stmt, 'i', $branch_id);
            
            if (mysqli_stmt_execute($delete_stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'delete', ?, 'branches', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $log_description = "Branch deleted: " . $branch['branch_name'] . " (" . $branch['branch_code'] . ")";
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $branch_id);
                mysqli_stmt_execute($log_stmt);
                
                $message = "Branch deleted successfully!";
            } else {
                $error = "Error deleting branch: " . mysqli_error($conn);
            }
        }
    }
}

// Get all branches
$branches_query = "SELECT b.*, 
                  (SELECT COUNT(*) FROM users WHERE branch_id = b.id) as user_count
                  FROM branches b 
                  ORDER BY b.created_at DESC";
$branches_result = mysqli_query($conn, $branches_query);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_branches,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_branches,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_branches,
                (SELECT COUNT(*) FROM users WHERE branch_id IS NOT NULL) as total_users_assigned
                FROM branches";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get all users for manager selection
$users_query = "SELECT id, name, role FROM users WHERE status = 'active' ORDER BY name";
$users_result = mysqli_query($conn, $users_query);

// Format time function
function formatTime($time) {
    if (empty($time)) return 'Not set';
    return date('h:i A', strtotime($time));
}

// Status badge function
function getStatusBadge($status) {
    return $status === 'active' 
        ? '<span class="badge badge-success">Active</span>'
        : '<span class="badge badge-danger">Inactive</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .branch-container {
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
            display: flex;
            align-items: center;
            gap: 10px;
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

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-danger {
            background: #f56565;
            color: white;
        }

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .branches-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .branches-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .branches-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .branches-table tbody tr:hover {
            background: #f7fafc;
        }

        .branch-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            object-fit: cover;
        }

        .branch-logo-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 20px;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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

        .action-btn.view {
            background: #4299e1;
            color: white;
        }

        .action-btn.view:hover {
            background: #3182ce;
        }

        .action-btn.edit {
            background: #ecc94b;
            color: #744210;
        }

        .action-btn.edit:hover {
            background: #d69e2e;
        }

        .action-btn.delete {
            background: #f56565;
            color: white;
        }

        .action-btn.delete:hover {
            background: #c53030;
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
            overflow-y: auto;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
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

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }

        .form-check input {
            width: 18px;
            height: 18px;
            accent-color: #667eea;
        }

        .current-image {
            margin-top: 5px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .current-image img {
            width: 50px;
            height: 50px;
            border-radius: 4px;
            object-fit: cover;
        }

        .text-muted {
            color: #718096;
            font-size: 12px;
            margin-top: 4px;
        }

        .time-input {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .time-input input[type="time"] {
            flex: 1;
        }

        .loading-spinner {
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .table-header {
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
                <div class="branch-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-diagram-3"></i>
                            Branch Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showAddModal()">
                                <i class="bi bi-plus-circle"></i> Add New Branch
                            </button>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Branches</div>
                                <div class="stat-value"><?php echo $stats['total_branches'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Branches</div>
                                <div class="stat-value" style="color: #48bb78;"><?php echo $stats['active_branches'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-x-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Inactive Branches</div>
                                <div class="stat-value" style="color: #f56565;"><?php echo $stats['inactive_branches'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Users Assigned</div>
                                <div class="stat-value"><?php echo $stats['total_users_assigned'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Branches Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                All Branches
                                <span class="badge badge-info" style="margin-left: 10px;">
                                    <?php echo mysqli_num_rows($branches_result); ?> records
                                </span>
                            </span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="branches-table" id="branchesTable">
                                <thead>
                                    <tr>
                                        <th>Logo</th>
                                        <th>Branch Code</th>
                                        <th>Branch Name</th>
                                        <th>Contact</th>
                                        <th>Manager</th>
                                        <th>Timings</th>
                                        <th>Holiday</th>
                                        <th>Users</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($branches_result) > 0): ?>
                                        <?php while($branch = mysqli_fetch_assoc($branches_result)): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($branch['logo_path']) && file_exists($branch['logo_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars($branch['logo_path']); ?>" class="branch-logo" alt="Logo">
                                                <?php else: ?>
                                                    <div class="branch-logo-placeholder">
                                                        <i class="bi bi-building"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($branch['branch_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                            <td>
                                                <?php if (!empty($branch['phone'])): ?>
                                                    <div><i class="bi bi-phone"></i> <?php echo htmlspecialchars($branch['phone']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($branch['email'])): ?>
                                                    <div><small><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($branch['email']); ?></small></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($branch['manager_name'])): ?>
                                                    <div><strong><?php echo htmlspecialchars($branch['manager_name']); ?></strong></div>
                                                    <?php if (!empty($branch['manager_mobile'])): ?>
                                                        <div><small><i class="bi bi-phone"></i> <?php echo htmlspecialchars($branch['manager_mobile']); ?></small></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($branch['opening_time']) && !empty($branch['closing_time'])) {
                                                    echo formatTime($branch['opening_time']) . ' - ' . formatTime($branch['closing_time']);
                                                } else {
                                                    echo 'Not set';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($branch['holiday'] ?? 'Sunday'); ?></td>
                                            <td class="text-center">
                                                <span class="badge badge-info"><?php echo intval($branch['user_count']); ?></span>
                                            </td>
                                            <td><?php echo getStatusBadge($branch['status']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-btn view" onclick="viewBranch(<?php echo $branch['id']; ?>)">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <button class="action-btn edit" onclick="editBranch(<?php echo $branch['id']; ?>)">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <?php if ($branch['user_count'] == 0): ?>
                                                        <a href="?action=delete&id=<?php echo $branch['id']; ?>" class="action-btn delete" onclick="return confirmDelete('<?php echo htmlspecialchars(addslashes($branch['branch_name'])); ?>')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-building" style="font-size: 48px; color: #cbd5e0;"></i>
                                                <p style="margin-top: 10px;">No branches found. Click "Add New Branch" to create one.</p>
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

    <!-- Add Branch Modal -->
    <div class="modal" id="addBranchModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeAddModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-plus-circle"></i>
                Add New Branch
            </h3>

            <form method="POST" action="" enctype="multipart/form-data" id="addBranchForm">
                <input type="hidden" name="action" value="add_branch">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Branch Name</label>
                        <input type="text" class="form-control" name="branch_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Branch Code</label>
                        <input type="text" class="form-control" name="branch_code" placeholder="e.g., BR001" required>
                        <small class="text-muted">Unique branch identifier</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Website</label>
                    <input type="url" class="form-control" name="website" placeholder="https://example.com">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Manager Name</label>
                        <input type="text" class="form-control" name="manager_name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager Mobile</label>
                        <input type="text" class="form-control" name="manager_mobile">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Opening Time</label>
                        <input type="time" class="form-control" name="opening_time" value="09:00">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Closing Time</label>
                        <input type="time" class="form-control" name="closing_time" value="18:00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Weekly Holiday</label>
                        <select class="form-select" name="holiday">
                            <option value="Sunday">Sunday</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Branch Logo</label>
                        <input type="file" class="form-control" name="logo" accept="image/*">
                        <small class="text-muted">Allowed: JPG, JPEG, PNG, GIF</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">QR Code</label>
                        <input type="file" class="form-control" name="qr_code" accept="image/*">
                        <small class="text-muted">QR code image for payments</small>
                    </div>
                </div>

                <div class="form-check">
                    <input type="checkbox" id="confirm_add" required>
                    <label for="confirm_add">I confirm that all information is correct</label>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Branch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Branch Modal -->
    <div class="modal" id="editBranchModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeEditModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-pencil"></i>
                Edit Branch
            </h3>

            <form method="POST" action="" enctype="multipart/form-data" id="editBranchForm">
                <input type="hidden" name="action" value="edit_branch">
                <input type="hidden" name="branch_id" id="edit_branch_id" value="">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label required">Branch Name</label>
                        <input type="text" class="form-control" name="branch_name" id="edit_branch_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Branch Code</label>
                        <input type="text" class="form-control" name="branch_code" id="edit_branch_code" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" id="edit_phone">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Website</label>
                    <input type="url" class="form-control" name="website" id="edit_website">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Manager Name</label>
                        <input type="text" class="form-control" name="manager_name" id="edit_manager_name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Manager Mobile</label>
                        <input type="text" class="form-control" name="manager_mobile" id="edit_manager_mobile">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Opening Time</label>
                        <input type="time" class="form-control" name="opening_time" id="edit_opening_time">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Closing Time</label>
                        <input type="time" class="form-control" name="closing_time" id="edit_closing_time">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Weekly Holiday</label>
                        <select class="form-select" name="holiday" id="edit_holiday">
                            <option value="Sunday">Sunday</option>
                            <option value="Monday">Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Branch Logo</label>
                        <input type="file" class="form-control" name="logo" accept="image/*">
                        <small class="text-muted">Leave blank to keep current logo</small>
                        <div id="current_logo" class="current-image" style="display: none;"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">QR Code</label>
                        <input type="file" class="form-control" name="qr_code" accept="image/*">
                        <small class="text-muted">Leave blank to keep current QR</small>
                        <div id="current_qr" class="current-image" style="display: none;"></div>
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Branch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Branch Modal -->
    <div class="modal" id="viewBranchModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeViewModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-building"></i>
                Branch Details
            </h3>
            <div id="branchDetails" class="loading-spinner">
                <div class="spinner"></div>
                <p>Loading branch details...</p>
            </div>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#branchesTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ branches",
                    emptyTable: "No branches found"
                },
                columnDefs: [
                    { orderable: false, targets: [0, 9] }
                ]
            });
        });

        // Add Branch Modal
        function showAddModal() {
            document.getElementById('addBranchModal').classList.add('active');
        }

        function closeAddModal() {
            document.getElementById('addBranchModal').classList.remove('active');
            document.getElementById('addBranchForm').reset();
        }

        // Edit Branch Modal - FIXED
        function editBranch(branchId) {
            // Show loading in edit modal
            document.getElementById('editBranchModal').classList.add('active');
            document.getElementById('branchDetails').innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>Loading branch details...</p></div>';
            
            // Fetch branch data via AJAX
            fetch('ajax/get_branch.php?id=' + branchId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Populate form fields
                        document.getElementById('edit_branch_id').value = data.branch.id;
                        document.getElementById('edit_branch_name').value = data.branch.branch_name || '';
                        document.getElementById('edit_branch_code').value = data.branch.branch_code || '';
                        document.getElementById('edit_address').value = data.branch.address || '';
                        document.getElementById('edit_phone').value = data.branch.phone || '';
                        document.getElementById('edit_email').value = data.branch.email || '';
                        document.getElementById('edit_website').value = data.branch.website || '';
                        document.getElementById('edit_manager_name').value = data.branch.manager_name || '';
                        document.getElementById('edit_manager_mobile').value = data.branch.manager_mobile || '';
                        document.getElementById('edit_opening_time').value = data.branch.opening_time || '09:00';
                        document.getElementById('edit_closing_time').value = data.branch.closing_time || '18:00';
                        document.getElementById('edit_holiday').value = data.branch.holiday || 'Sunday';
                        document.getElementById('edit_status').value = data.branch.status || 'active';
                        
                        // Show current images
                        if (data.branch.logo_path) {
                            document.getElementById('current_logo').innerHTML = '<img src="' + data.branch.logo_path + '" alt="Current Logo"> Current Logo';
                            document.getElementById('current_logo').style.display = 'flex';
                        } else {
                            document.getElementById('current_logo').style.display = 'none';
                        }
                        
                        if (data.branch.qr_path) {
                            document.getElementById('current_qr').innerHTML = '<img src="' + data.branch.qr_path + '" alt="Current QR"> Current QR';
                            document.getElementById('current_qr').style.display = 'flex';
                        } else {
                            document.getElementById('current_qr').style.display = 'none';
                        }
                    } else {
                        Swal.fire('Error', data.message || 'Could not load branch data', 'error');
                        closeEditModal();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to load branch data. Please check console for details.', 'error');
                    closeEditModal();
                });
        }

        function closeEditModal() {
            document.getElementById('editBranchModal').classList.remove('active');
            document.getElementById('editBranchForm').reset();
        }

        // View Branch Modal - FIXED
        function viewBranch(branchId) {
            document.getElementById('viewBranchModal').classList.add('active');
            document.getElementById('branchDetails').innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>Loading branch details...</p></div>';
            
            fetch('ajax/get_branch.php?id=' + branchId)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        displayBranchDetails(data.branch);
                    } else {
                        document.getElementById('branchDetails').innerHTML = '<p class="text-center text-danger">' + (data.message || 'Error loading branch details') + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('branchDetails').innerHTML = '<p class="text-center text-danger">Error loading branch details. Please try again.</p>';
                });
        }

        function closeViewModal() {
            document.getElementById('viewBranchModal').classList.remove('active');
        }

        // Display branch details
        function displayBranchDetails(branch) {
            let html = `
                <div style="text-align: center; margin-bottom: 20px;">
                    ${branch.logo_path ? 
                        `<img src="${branch.logo_path}" style="max-width: 100px; max-height: 100px; border-radius: 8px; margin-bottom: 10px;">` : 
                        '<div style="width: 100px; height: 100px; background: #f7fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;"><i class="bi bi-building" style="font-size: 40px; color: #a0aec0;"></i></div>'
                    }
                    <h2 style="margin-top: 10px; font-size: 24px;">${branch.branch_name || 'N/A'}</h2>
                    <p><strong>Code:</strong> ${branch.branch_code || 'N/A'}</p>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Status</div>
                        <div style="font-weight: 600; color: ${branch.status === 'active' ? '#48bb78' : '#f56565'};">${branch.status === 'active' ? 'Active' : 'Inactive'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Phone</div>
                        <div style="font-weight: 600;">${branch.phone || 'Not provided'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Email</div>
                        <div style="font-weight: 600;">${branch.email || 'Not provided'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Website</div>
                        <div style="font-weight: 600;">${branch.website ? `<a href="${branch.website}" target="_blank">${branch.website}</a>` : 'Not provided'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Manager</div>
                        <div style="font-weight: 600;">${branch.manager_name || 'Not assigned'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Manager Mobile</div>
                        <div style="font-weight: 600;">${branch.manager_mobile || 'Not provided'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Opening Time</div>
                        <div style="font-weight: 600;">${branch.opening_time ? new Date('1970-01-01T' + branch.opening_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'Not set'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Closing Time</div>
                        <div style="font-weight: 600;">${branch.closing_time ? new Date('1970-01-01T' + branch.closing_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'Not set'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Weekly Holiday</div>
                        <div style="font-weight: 600;">${branch.holiday || 'Sunday'}</div>
                    </div>
                    
                    <div style="padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096;">Created</div>
                        <div style="font-weight: 600;">${branch.created_at ? new Date(branch.created_at).toLocaleDateString() : 'N/A'}</div>
                    </div>
                </div>
            `;
            
            if (branch.address) {
                html += `
                    <div style="margin-top: 15px; padding: 10px; background: #f7fafc; border-radius: 8px;">
                        <div style="font-size: 12px; color: #718096; margin-bottom: 5px;">Address</div>
                        <div style="font-weight: 600;">${branch.address}</div>
                    </div>
                `;
            }
            
            if (branch.qr_path) {
                html += `
                    <div style="margin-top: 15px; text-align: center;">
                        <h4 style="margin-bottom: 10px;">QR Code</h4>
                        <img src="${branch.qr_path}" style="max-width: 150px; max-height: 150px; border: 1px solid #e2e8f0; padding: 5px; border-radius: 8px;">
                    </div>
                `;
            }
            
            document.getElementById('branchDetails').innerHTML = html;
        }

        // Confirm delete
        function confirmDelete(branchName) {
            return confirm(`Are you sure you want to delete branch "${branchName}"? This action cannot be undone.`);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
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