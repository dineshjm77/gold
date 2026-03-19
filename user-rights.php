<?php
session_start();
$currentPage = 'user-rights';
$pageTitle = 'User Rights & Permissions';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location:user-rights.php');
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error = '';
$success = '';

// Check if user_permissions table exists, if not create it
$check_table = $conn->query("SHOW TABLES LIKE 'user_permissions'");
if ($check_table->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `user_permissions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `module` varchar(100) NOT NULL,
        `can_view` tinyint(1) DEFAULT 0,
        `can_create` tinyint(1) DEFAULT 0,
        `can_edit` tinyint(1) DEFAULT 0,
        `can_delete` tinyint(1) DEFAULT 0,
        `can_approve` tinyint(1) DEFAULT 0,
        `can_export` tinyint(1) DEFAULT 0,
        `can_print` tinyint(1) DEFAULT 0,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_module` (`user_id`,`module`),
        KEY `user_id` (`user_id`),
        CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if ($conn->query($create_table)) {
        $success = "User permissions table created successfully!";
    } else {
        $error = "Error creating table: " . $conn->error;
    }
}

// Check if role_permissions table exists (for role-based permissions)
$check_role_table = $conn->query("SHOW TABLES LIKE 'role_permissions'");
if ($check_role_table->num_rows == 0) {
    $create_role_table = "CREATE TABLE IF NOT EXISTS `role_permissions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `role` enum('admin','manager','sale','accountant') NOT NULL,
        `module` varchar(100) NOT NULL,
        `can_view` tinyint(1) DEFAULT 1,
        `can_create` tinyint(1) DEFAULT 0,
        `can_edit` tinyint(1) DEFAULT 0,
        `can_delete` tinyint(1) DEFAULT 0,
        `can_approve` tinyint(1) DEFAULT 0,
        `can_export` tinyint(1) DEFAULT 0,
        `can_print` tinyint(1) DEFAULT 1,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `role_module` (`role`,`module`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if ($conn->query($create_role_table)) {
        // Insert default role permissions
        $default_permissions = [
            // Admin has all permissions
            ['admin', 'dashboard', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'loans', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'customers', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'employees', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'investments', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'reports', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'settings', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'bank', 1, 1, 1, 1, 1, 1, 1],
            ['admin', 'auction', 1, 1, 1, 1, 1, 1, 1],
            
            // Manager permissions
            ['manager', 'dashboard', 1, 0, 0, 0, 0, 1, 1],
            ['manager', 'loans', 1, 1, 1, 0, 1, 1, 1],
            ['manager', 'customers', 1, 1, 1, 0, 0, 1, 1],
            ['manager', 'employees', 1, 0, 0, 0, 0, 0, 0],
            ['manager', 'investments', 1, 1, 1, 0, 0, 1, 1],
            ['manager', 'reports', 1, 0, 0, 0, 0, 1, 1],
            ['manager', 'settings', 1, 0, 0, 0, 0, 0, 0],
            ['manager', 'bank', 1, 0, 0, 0, 0, 1, 1],
            ['manager', 'auction', 1, 1, 1, 0, 1, 1, 1],
            
            // Sales permissions
            ['sale', 'dashboard', 1, 0, 0, 0, 0, 0, 1],
            ['sale', 'loans', 1, 1, 0, 0, 0, 0, 1],
            ['sale', 'customers', 1, 1, 1, 0, 0, 0, 1],
            ['sale', 'employees', 0, 0, 0, 0, 0, 0, 0],
            ['sale', 'investments', 1, 0, 0, 0, 0, 0, 1],
            ['sale', 'reports', 1, 0, 0, 0, 0, 0, 1],
            ['sale', 'settings', 0, 0, 0, 0, 0, 0, 0],
            ['sale', 'bank', 1, 0, 0, 0, 0, 0, 1],
            ['sale', 'auction', 1, 0, 0, 0, 0, 0, 1],
            
            // Accountant permissions
            ['accountant', 'dashboard', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'loans', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'customers', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'employees', 0, 0, 0, 0, 0, 0, 0],
            ['accountant', 'investments', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'reports', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'settings', 0, 0, 0, 0, 0, 0, 0],
            ['accountant', 'bank', 1, 0, 0, 0, 0, 1, 1],
            ['accountant', 'auction', 1, 0, 0, 0, 0, 1, 1]
        ];
        
        $insert_stmt = $conn->prepare("INSERT INTO role_permissions (role, module, can_view, can_create, can_edit, can_delete, can_approve, can_export, can_print) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($default_permissions as $perm) {
            $insert_stmt->bind_param("ssiiiiiii", $perm[0], $perm[1], $perm[2], $perm[3], $perm[4], $perm[5], $perm[6], $perm[7], $perm[8]);
            $insert_stmt->execute();
        }
        $insert_stmt->close();
    }
}

// Define all available modules
$modules = [
    'dashboard' => 'Dashboard',
    'loans' => 'Loan Management',
    'customers' => 'Customer Management',
    'employees' => 'Employee Management',
    'investments' => 'Investment Management',
    'reports' => 'Reports',
    'settings' => 'System Settings',
    'bank' => 'Bank Operations',
    'auction' => 'Auction Management',
    'branch' => 'Branch Management',
    'interest' => 'Interest Settings',
    'master' => 'Master Data',
    'expenses' => 'Expense Management',
    'notes' => 'Notes & Receipts',
    'user_management' => 'User Management'
];

// Define permission types
$permission_types = [
    'can_view' => ['label' => 'View', 'icon' => 'bi-eye', 'color' => '#4299e1'],
    'can_create' => ['label' => 'Create', 'icon' => 'bi-plus-circle', 'color' => '#48bb78'],
    'can_edit' => ['label' => 'Edit', 'icon' => 'bi-pencil', 'color' => '#ecc94b'],
    'can_delete' => ['label' => 'Delete', 'icon' => 'bi-trash', 'color' => '#f56565'],
    'can_approve' => ['label' => 'Approve', 'icon' => 'bi-check-circle', 'color' => '#9f7aea'],
    'can_export' => ['label' => 'Export', 'icon' => 'bi-download', 'color' => '#ed8936'],
    'can_print' => ['label' => 'Print', 'icon' => 'bi-printer', 'color' => '#667eea']
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update user permissions
    if (isset($_POST['update_user_permissions'])) {
        $user_id = intval($_POST['user_id']);
        
        // Delete existing permissions for this user
        $delete_query = "DELETE FROM user_permissions WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Insert new permissions
        $insert_count = 0;
        foreach ($modules as $module_key => $module_name) {
            $can_view = isset($_POST["perm_$module_key"."_view"]) ? 1 : 0;
            $can_create = isset($_POST["perm_$module_key"."_create"]) ? 1 : 0;
            $can_edit = isset($_POST["perm_$module_key"."_edit"]) ? 1 : 0;
            $can_delete = isset($_POST["perm_$module_key"."_delete"]) ? 1 : 0;
            $can_approve = isset($_POST["perm_$module_key"."_approve"]) ? 1 : 0;
            $can_export = isset($_POST["perm_$module_key"."_export"]) ? 1 : 0;
            $can_print = isset($_POST["perm_$module_key"."_print"]) ? 1 : 0;
            
            // Only insert if at least one permission is set
            if ($can_view || $can_create || $can_edit || $can_delete || $can_approve || $can_export || $can_print) {
                $insert_query = "INSERT INTO user_permissions 
                    (user_id, module, can_view, can_create, can_edit, can_delete, can_approve, can_export, can_print) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("isiiiiiii", $user_id, $module_key, $can_view, $can_create, $can_edit, $can_delete, $can_approve, $can_export, $can_print);
                if ($insert_stmt->execute()) {
                    $insert_count++;
                }
                $insert_stmt->close();
            }
        }
        
        // Get username for logging
        $user_query = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $user_query->bind_param("i", $user_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_name = $user_data['name'] ?? 'Unknown';
        
        $success = "Permissions updated successfully for $user_name ($insert_count modules updated)";
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                      VALUES (?, 'update', ?, 'user_permissions', ?)";
        $log_stmt = $conn->prepare($log_query);
        $log_desc = "Updated permissions for user: $user_name";
        $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_desc, $user_id);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    // Update role permissions
    elseif (isset($_POST['update_role_permissions'])) {
        $role = $_POST['role'];
        
        // Delete existing permissions for this role
        $delete_query = "DELETE FROM role_permissions WHERE role = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("s", $role);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Insert new permissions
        $insert_count = 0;
        foreach ($modules as $module_key => $module_name) {
            $can_view = isset($_POST["perm_$module_key"."_view"]) ? 1 : 0;
            $can_create = isset($_POST["perm_$module_key"."_create"]) ? 1 : 0;
            $can_edit = isset($_POST["perm_$module_key"."_edit"]) ? 1 : 0;
            $can_delete = isset($_POST["perm_$module_key"."_delete"]) ? 1 : 0;
            $can_approve = isset($_POST["perm_$module_key"."_approve"]) ? 1 : 0;
            $can_export = isset($_POST["perm_$module_key"."_export"]) ? 1 : 0;
            $can_print = isset($_POST["perm_$module_key"."_print"]) ? 1 : 0;
            
            // Only insert if at least one permission is set
            if ($can_view || $can_create || $can_edit || $can_delete || $can_approve || $can_export || $can_print) {
                $insert_query = "INSERT INTO role_permissions 
                    (role, module, can_view, can_create, can_edit, can_delete, can_approve, can_export, can_print) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssiiiiiii", $role, $module_key, $can_view, $can_create, $can_edit, $can_delete, $can_approve, $can_export, $can_print);
                if ($insert_stmt->execute()) {
                    $insert_count++;
                }
                $insert_stmt->close();
            }
        }
        
        $success = "Permissions updated successfully for role: " . ucfirst($role) . " ($insert_count modules updated)";
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                      VALUES (?, 'update', ?, 'role_permissions')";
        $log_stmt = $conn->prepare($log_query);
        $log_desc = "Updated permissions for role: $role";
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        $log_stmt->close();
    }
    
    // Reset user to role defaults
    elseif (isset($_POST['reset_to_role'])) {
        $user_id = intval($_POST['user_id']);
        $role = $_POST['role'];
        
        // Delete existing user permissions
        $delete_query = "DELETE FROM user_permissions WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Copy role permissions to user
        $copy_query = "INSERT INTO user_permissions (user_id, module, can_view, can_create, can_edit, can_delete, can_approve, can_export, can_print)
                       SELECT ?, module, can_view, can_create, can_edit, can_delete, can_approve, can_export, can_print
                       FROM role_permissions WHERE role = ?";
        $copy_stmt = $conn->prepare($copy_query);
        $copy_stmt->bind_param("is", $user_id, $role);
        $copy_stmt->execute();
        $copy_count = $copy_stmt->affected_rows;
        $copy_stmt->close();
        
        // Get username for logging
        $user_query = $conn->prepare("SELECT name FROM users WHERE id = ?");
        $user_query->bind_param("i", $user_id);
        $user_query->execute();
        $user_result = $user_query->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_name = $user_data['name'] ?? 'Unknown';
        
        $success = "User $user_name has been reset to default $role permissions ($copy_count modules updated)";
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                      VALUES (?, 'update', ?, 'user_permissions', ?)";
        $log_stmt = $conn->prepare($log_query);
        $log_desc = "Reset user $user_name to role $role permissions";
        $log_stmt->bind_param("isi", $_SESSION['user_id'], $log_desc, $user_id);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

// Get all users
$users_query = "SELECT id, name, username, role, status FROM users ORDER BY name";
$users_result = $conn->query($users_query);

// Get all roles
$roles = ['admin', 'manager', 'sale', 'accountant'];

// Get role permissions
$role_permissions = [];
foreach ($roles as $role) {
    $perm_query = "SELECT * FROM role_permissions WHERE role = ?";
    $perm_stmt = $conn->prepare($perm_query);
    $perm_stmt->bind_param("s", $role);
    $perm_stmt->execute();
    $perm_result = $perm_stmt->get_result();
    while ($row = $perm_result->fetch_assoc()) {
        $role_permissions[$role][$row['module']] = $row;
    }
    $perm_stmt->close();
}

// Get selected user for editing
$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$selected_user = null;
$user_permissions = [];

if ($selected_user_id > 0) {
    $user_query = "SELECT id, name, username, role, status FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bind_param("i", $selected_user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $selected_user = $user_result->fetch_assoc();
    $user_stmt->close();
    
    if ($selected_user) {
        $perm_query = "SELECT * FROM user_permissions WHERE user_id = ?";
        $perm_stmt = $conn->prepare($perm_query);
        $perm_stmt->bind_param("i", $selected_user_id);
        $perm_stmt->execute();
        $perm_result = $perm_stmt->get_result();
        while ($row = $perm_result->fetch_assoc()) {
            $user_permissions[$row['module']] = $row;
        }
        $perm_stmt->close();
    }
}

// Get selected role for editing
$selected_role = isset($_GET['role']) ? $_GET['role'] : '';
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: #667eea;
            background: #f7fafc;
        }

        .tab-btn.active {
            color: #667eea;
            background: #ebf4ff;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
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

        /* User Selector */
        .user-selector {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .user-card {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }

        .user-card.selected {
            border: 2px solid #667eea;
            background: #ebf4ff;
        }

        .user-card .name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .user-card .username {
            font-size: 12px;
            color: #718096;
            margin-bottom: 8px;
        }

        .user-card .role {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #667eea20;
            color: #667eea;
        }

        .user-card .status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        /* Permissions Table */
        .permissions-table {
            width: 100%;
            border-collapse: collapse;
        }

        .permissions-table th {
            background: #f7fafc;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            text-align: center;
        }

        .permissions-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .permissions-table tr:hover td {
            background: #f7fafc;
        }

        .module-name {
            font-weight: 600;
            color: #2d3748;
        }

        .permission-checkbox {
            text-align: center;
        }

        .permission-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .permission-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            color: #718096;
        }

        .permission-label i {
            font-size: 16px;
        }

        /* Role Badge */
        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .role-badge.admin {
            background: #667eea20;
            color: #667eea;
        }

        .role-badge.manager {
            background: #48bb7820;
            color: #48bb78;
        }

        .role-badge.sale {
            background: #f5656520;
            color: #f56565;
        }

        .role-badge.accountant {
            background: #ecc94b20;
            color: #ecc94b;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        /* Select All Bar */
        .select-all-bar {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        @media (max-width: 1024px) {
            .permissions-table {
                font-size: 12px;
            }
            
            .permission-label i {
                font-size: 14px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                width: 100%;
            }
            
            .permissions-table {
                display: block;
                overflow-x: auto;
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
                            <i class="bi bi-shield-lock"></i>
                            <h1>User Rights & Permissions</h1>
                        </div>
                        <div>
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

                    <!-- Info Alert -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>
                            <strong>Permission Management:</strong> Configure what each user or role can access. 
                            User-specific permissions override role-based defaults. Admin users have full access to all modules.
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab-btn <?php echo !$selected_role ? 'active' : ''; ?>" onclick="switchTab('users')">
                            <i class="bi bi-people"></i> User Permissions
                        </button>
                        <button class="tab-btn <?php echo $selected_role ? 'active' : ''; ?>" onclick="switchTab('roles')">
                            <i class="bi bi-shield"></i> Role Permissions
                        </button>
                    </div>

                    <!-- Users Tab -->
                    <div class="tab-pane <?php echo !$selected_role ? 'active' : ''; ?>" id="tab-users">
                        <!-- User Selector -->
                        <div class="user-selector">
                            <div class="form-title">
                                <i class="bi bi-person"></i>
                                Select User
                            </div>
                            <div class="user-grid">
                                <?php if ($users_result && $users_result->num_rows > 0): ?>
                                    <?php while ($user = $users_result->fetch_assoc()): ?>
                                        <div class="user-card <?php echo ($selected_user_id == $user['id']) ? 'selected' : ''; ?>" 
                                             onclick="selectUser(<?php echo $user['id']; ?>)">
                                            <div class="name"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <div class="username">@<?php echo htmlspecialchars($user['username']); ?></div>
                                            <div>
                                                <span class="role-badge <?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                                <span class="status <?php echo $user['status'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($selected_user): ?>
                            <!-- User Permissions Form -->
                            <div class="form-card">
                                <div class="form-title" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>
                                        <i class="bi bi-person-gear"></i>
                                        Permissions for: <?php echo htmlspecialchars($selected_user['name']); ?> (<?php echo ucfirst($selected_user['role']); ?>)
                                    </span>
                                    <div>
                                        <button class="btn btn-warning btn-sm" onclick="resetToRole(<?php echo $selected_user['id']; ?>, '<?php echo $selected_user['role']; ?>')">
                                            <i class="bi bi-arrow-repeat"></i> Reset to Role Defaults
                                        </button>
                                    </div>
                                </div>

                                <form method="POST" id="userPermissionsForm">
                                    <input type="hidden" name="update_user_permissions" value="1">
                                    <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">

                                    <!-- Select All Bar -->
                                    <div class="select-all-bar">
                                        <div>
                                            <strong>Quick Actions:</strong>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllForModule('view')">
                                                <i class="bi bi-eye"></i> All View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="selectAllForModule('create')">
                                                <i class="bi bi-plus-circle"></i> All Create
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="selectAllForModule('edit')">
                                                <i class="bi bi-pencil"></i> All Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="selectAllForModule('delete')">
                                                <i class="bi bi-trash"></i> All Delete
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="selectAllForModule('approve')">
                                                <i class="bi bi-check-circle"></i> All Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllForModule('export')">
                                                <i class="bi bi-download"></i> All Export
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="selectAllForModule('print')">
                                                <i class="bi bi-printer"></i> All Print
                                            </button>
                                        </div>
                                        <div class="ms-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAllPermissions()">
                                                <i class="bi bi-x-circle"></i> Clear All
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Permissions Table -->
                                    <div class="table-responsive">
                                        <table class="permissions-table">
                                            <thead>
                                                <tr>
                                                    <th>Module</th>
                                                    <?php foreach ($permission_types as $key => $perm): ?>
                                                        <th>
                                                            <div class="permission-label">
                                                                <i class="bi <?php echo $perm['icon']; ?>" style="color: <?php echo $perm['color']; ?>"></i>
                                                                <?php echo $perm['label']; ?>
                                                            </div>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($modules as $module_key => $module_name): ?>
                                                    <?php 
                                                        $user_perm = $user_permissions[$module_key] ?? [];
                                                        $role_perm = $role_permissions[$selected_user['role']][$module_key] ?? [];
                                                    ?>
                                                    <tr>
                                                        <td class="module-name"><?php echo $module_name; ?></td>
                                                        
                                                        <?php foreach (array_keys($permission_types) as $perm_key): ?>
                                                            <td class="permission-checkbox">
                                                                <?php 
                                                                    $checked = isset($user_perm[$perm_key]) && $user_perm[$perm_key] ? 'checked' : '';
                                                                    $role_checked = isset($role_perm[$perm_key]) && $role_perm[$perm_key] ? 'role-default' : '';
                                                                ?>
                                                                <input type="checkbox" 
                                                                       name="perm_<?php echo $module_key; ?>_<?php echo str_replace('can_', '', $perm_key); ?>" 
                                                                       id="perm_<?php echo $module_key; ?>_<?php echo $perm_key; ?>"
                                                                       value="1" 
                                                                       <?php echo $checked; ?>
                                                                       <?php echo $selected_user['role'] == 'admin' ? 'disabled' : ''; ?>
                                                                       data-role-default="<?php echo $role_checked; ?>">
                                                                <?php if ($role_checked && !$checked && $selected_user['role'] != 'admin'): ?>
                                                                    <div style="font-size: 9px; color: #718096;">(role default)</div>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($selected_user['role'] == 'admin'): ?>
                                        <div class="alert alert-warning" style="margin-top: 15px;">
                                            <i class="bi bi-exclamation-triangle-fill"></i>
                                            Admin users automatically have full permissions. Individual permissions cannot be modified.
                                        </div>
                                    <?php endif; ?>

                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-secondary" onclick="window.location.href='user-rights.php'">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                        <button type="submit" class="btn btn-success" <?php echo $selected_user['role'] == 'admin' ? 'disabled' : ''; ?>>
                                            <i class="bi bi-check-circle"></i> Save Permissions
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill"></i>
                                Please select a user from above to manage their permissions.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Roles Tab -->
                    <div class="tab-pane <?php echo $selected_role ? 'active' : ''; ?>" id="tab-roles">
                        <!-- Role Selector -->
                        <div class="user-selector">
                            <div class="form-title">
                                <i class="bi bi-shield"></i>
                                Select Role
                            </div>
                            <div class="user-grid">
                                <?php foreach ($roles as $role): ?>
                                    <div class="user-card <?php echo ($selected_role == $role) ? 'selected' : ''; ?>" 
                                         onclick="selectRole('<?php echo $role; ?>')">
                                        <div class="name"><?php echo ucfirst($role); ?></div>
                                        <div>
                                            <span class="role-badge <?php echo $role; ?>">
                                                <?php echo ucfirst($role); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($selected_role): ?>
                            <!-- Role Permissions Form -->
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-shield-gear"></i>
                                    Permissions for Role: <?php echo ucfirst($selected_role); ?>
                                </div>

                                <form method="POST" id="rolePermissionsForm">
                                    <input type="hidden" name="update_role_permissions" value="1">
                                    <input type="hidden" name="role" value="<?php echo $selected_role; ?>">

                                    <!-- Select All Bar -->
                                    <div class="select-all-bar">
                                        <div>
                                            <strong>Quick Actions:</strong>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllForRole('view')">
                                                <i class="bi bi-eye"></i> All View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="selectAllForRole('create')">
                                                <i class="bi bi-plus-circle"></i> All Create
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="selectAllForRole('edit')">
                                                <i class="bi bi-pencil"></i> All Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="selectAllForRole('delete')">
                                                <i class="bi bi-trash"></i> All Delete
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="selectAllForRole('approve')">
                                                <i class="bi bi-check-circle"></i> All Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllForRole('export')">
                                                <i class="bi bi-download"></i> All Export
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="selectAllForRole('print')">
                                                <i class="bi bi-printer"></i> All Print
                                            </button>
                                        </div>
                                        <div class="ms-auto">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAllRolePermissions()">
                                                <i class="bi bi-x-circle"></i> Clear All
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Permissions Table -->
                                    <div class="table-responsive">
                                        <table class="permissions-table">
                                            <thead>
                                                <tr>
                                                    <th>Module</th>
                                                    <?php foreach ($permission_types as $key => $perm): ?>
                                                        <th>
                                                            <div class="permission-label">
                                                                <i class="bi <?php echo $perm['icon']; ?>" style="color: <?php echo $perm['color']; ?>"></i>
                                                                <?php echo $perm['label']; ?>
                                                            </div>
                                                        </th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($modules as $module_key => $module_name): ?>
                                                    <?php 
                                                        $role_perm = $role_permissions[$selected_role][$module_key] ?? [];
                                                    ?>
                                                    <tr>
                                                        <td class="module-name"><?php echo $module_name; ?></td>
                                                        
                                                        <?php foreach (array_keys($permission_types) as $perm_key): ?>
                                                            <td class="permission-checkbox">
                                                                <input type="checkbox" 
                                                                       name="perm_<?php echo $module_key; ?>_<?php echo str_replace('can_', '', $perm_key); ?>" 
                                                                       id="role_perm_<?php echo $module_key; ?>_<?php echo $perm_key; ?>"
                                                                       value="1" 
                                                                       <?php echo (isset($role_perm[$perm_key]) && $role_perm[$perm_key]) ? 'checked' : ''; ?>
                                                                       <?php echo $selected_role == 'admin' ? 'disabled' : ''; ?>>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php if ($selected_role == 'admin'): ?>
                                        <div class="alert alert-warning" style="margin-top: 15px;">
                                            <i class="bi bi-exclamation-triangle-fill"></i>
                                            Admin role automatically has full permissions and cannot be modified.
                                        </div>
                                    <?php endif; ?>

                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-secondary" onclick="window.location.href='user-rights.php'">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                        <button type="submit" class="btn btn-success" <?php echo $selected_role == 'admin' ? 'disabled' : ''; ?>>
                                            <i class="bi bi-check-circle"></i> Save Role Permissions
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill"></i>
                                Please select a role from above to manage its default permissions.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Tab switching
        function switchTab(tabName) {
            if (tabName === 'users') {
                window.location.href = 'user-rights.php';
            } else {
                window.location.href = 'user-rights.php?role=admin';
            }
        }

        // Refresh page
        function refreshPage() {
            window.location.reload();
        }

        // Select user
        function selectUser(userId) {
            window.location.href = 'user-rights.php?user_id=' + userId;
        }

        // Select role
        function selectRole(role) {
            window.location.href = 'user-rights.php?role=' + role;
        }

        // Select all for module (user permissions)
        function selectAllForModule(permission) {
            const checkboxes = document.querySelectorAll('#userPermissionsForm input[type="checkbox"][name$="_' + permission + '"]');
            checkboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = true;
                }
            });
        }

        // Select all for role
        function selectAllForRole(permission) {
            const checkboxes = document.querySelectorAll('#rolePermissionsForm input[type="checkbox"][name$="_' + permission + '"]');
            checkboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = true;
                }
            });
        }

        // Clear all user permissions
        function clearAllPermissions() {
            const checkboxes = document.querySelectorAll('#userPermissionsForm input[type="checkbox"]');
            checkboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = false;
                }
            });
        }

        // Clear all role permissions
        function clearAllRolePermissions() {
            const checkboxes = document.querySelectorAll('#rolePermissionsForm input[type="checkbox"]');
            checkboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = false;
                }
            });
        }

        // Reset user to role defaults
        function resetToRole(userId, role) {
            Swal.fire({
                title: 'Reset Permissions?',
                html: `This will reset <strong>all permissions</strong> for this user to the default ${role} role settings.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ecc94b',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="reset_to_role" value="1">
                        <input type="hidden" name="user_id" value="${userId}">
                        <input type="hidden" name="role" value="${role}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Highlight role defaults
        document.querySelectorAll('#userPermissionsForm input[type="checkbox"]').forEach(cb => {
            if (cb.dataset.roleDefault && !cb.checked) {
                cb.parentNode.style.opacity = '0.5';
            }
        });

        // Form validation
        document.getElementById('userPermissionsForm')?.addEventListener('submit', function(e) {
            // Optional: Add validation if needed
        });

        document.getElementById('rolePermissionsForm')?.addEventListener('submit', function(e) {
            // Optional: Add validation if needed
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>