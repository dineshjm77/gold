<?php
session_start();
$currentPage = 'employees';
$pageTitle = 'Employees';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view employees
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle status update (activate/deactivate)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $employee_id = intval($_GET['id']);
    
    if ($_GET['action'] === 'activate') {
        $update_query = "UPDATE users SET status = 'active', is_active = 1 WHERE id = ?";
        $action = 'activated';
    } elseif ($_GET['action'] === 'deactivate') {
        $update_query = "UPDATE users SET status = 'inactive', is_active = 0 WHERE id = ?";
        $action = 'deactivated';
    } elseif ($_GET['action'] === 'delete') {
        // Check if employee has any loans or activities before deleting
        $check_loans = "SELECT COUNT(*) as loan_count FROM loans WHERE employee_id = ?";
        $stmt = mysqli_prepare($conn, $check_loans);
        mysqli_stmt_bind_param($stmt, 'i', $employee_id);
        mysqli_stmt_execute($stmt);
        $loan_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['loan_count'];
        
        $check_activities = "SELECT COUNT(*) as activity_count FROM activity_log WHERE user_id = ?";
        $stmt = mysqli_prepare($conn, $check_activities);
        mysqli_stmt_bind_param($stmt, 'i', $employee_id);
        mysqli_stmt_execute($stmt);
        $activity_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['activity_count'];
        
        if ($loan_count > 0 || $activity_count > 0) {
            $error = "Cannot delete employee because they have $loan_count loan(s) and $activity_count activity record(s). You can deactivate them instead.";
        } else {
            $delete_query = "DELETE FROM users WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, 'i', $employee_id);
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'delete', 'Employee deleted', 'users', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $employee_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: employees.php?success=deleted');
                exit();
            }
        }
    }
    
    if (isset($update_query)) {
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, 'i', $employee_id);
        if (mysqli_stmt_execute($stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, '$action', 'Employee $action', 'users', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $employee_id);
            mysqli_stmt_execute($log_stmt);
            
            header('Location: employees.php?success=updated');
            exit();
        }
    }
}

// Handle URL success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'updated':
            $message = "Employee status updated successfully!";
            break;
        case 'deleted':
            $message = "Employee deleted successfully!";
            break;
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$role_filter = isset($_GET['role']) ? mysqli_real_escape_string($conn, $_GET['role']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$branch_filter = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : '';

// Build query with joins
$query = "SELECT u.*, b.branch_name 
          FROM users u 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (u.name LIKE '%$search%' OR u.username LIKE '%$search%' OR u.email LIKE '%$search%' OR u.mobile LIKE '%$search%' OR u.employee_id LIKE '%$search%')";
}
if (!empty($role_filter)) {
    $query .= " AND u.role = '$role_filter'";
}
if (!empty($status_filter)) {
    $query .= " AND u.status = '$status_filter'";
}
if (!empty($branch_filter)) {
    $query .= " AND u.branch_id = $branch_filter";
}
$query .= " ORDER BY u.created_at DESC";

$employees_result = mysqli_query($conn, $query);

// Get branches for filter dropdown
$branches_query = "SELECT id, branch_name FROM branches ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get statistics
$total_employees = mysqli_query($conn, "SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'] ?? 0;
$active_employees = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE status = 'active' AND is_active = 1")->fetch_assoc()['count'] ?? 0;
$inactive_employees = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE status = 'inactive' OR is_active = 0")->fetch_assoc()['count'] ?? 0;
$admin_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetch_assoc()['count'] ?? 0;
$manager_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'manager'")->fetch_assoc()['count'] ?? 0;
$sale_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'sale'")->fetch_assoc()['count'] ?? 0;
$accountant_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'accountant'")->fetch_assoc()['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        /* Employees Container */
        .employees-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
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

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.5);
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

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        /* Statistics Cards - Enhanced */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.15);
        }

        .stat-card.active {
            border: 2px solid #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #e6ecff 100%);
        }

        .stat-icon {
            font-size: 28px;
            margin-bottom: 10px;
            color: #667eea;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            font-weight: 500;
        }

        /* Filters Section - Enhanced */
        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-input {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .filter-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .filter-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: #f8fafc;
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .filter-btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 44px;
        }

        .filter-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .filter-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .filter-btn-reset {
            background: #e2e8f0;
            color: #4a5568;
        }

        .filter-btn-reset:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid #e2e8f0;
            background: white;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .quick-action-btn:hover {
            border-color: #667eea;
            color: #667eea;
            transform: translateY(-2px);
        }

        .quick-action-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
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
            border-radius: 10px;
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

        /* Employees Table */
        .employees-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .employees-table thead th {
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 10px 10px 0 0;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .employees-table tbody td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            vertical-align: middle;
        }

        .employees-table tbody tr {
            transition: all 0.3s;
        }

        .employees-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.002);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        /* Employee Avatar */
        .employee-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        .employee-avatar-img {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #667eea;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .status-badge.inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        /* Role Badges */
        .role-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-badge.admin {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .role-badge.manager {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #9b2c2c;
        }

        .role-badge.sale {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .role-badge.accountant {
            background: linear-gradient(135deg, #feebc8 0%, #fbd38d 100%);
            color: #744210;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
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
            text-decoration: none;
            font-size: 16px;
        }

        .btn-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .btn-icon.view:hover {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .btn-icon.edit:hover {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .btn-icon.activate:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.deactivate:hover {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        /* Employee ID Badge */
        .employee-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        /* Mobile Cards */
        .mobile-cards {
            display: none;
        }

        .employee-mobile-card {
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

        .mobile-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .mobile-avatar {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }

        .mobile-avatar-img {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            object-fit: cover;
        }

        .mobile-name {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .mobile-id {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }

        .mobile-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }

        .mobile-detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 11px;
            color: #718096;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }

        .mobile-badges {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .mobile-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
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
            border-radius: 30px;
            font-size: 14px;
            margin-left: 10px;
            font-weight: 600;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: flex-end;
        }

        .export-btn {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: white;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            border: 2px solid #e2e8f0;
        }

        .export-btn:hover {
            border-color: #48bb78;
            color: #48bb78;
            transform: translateY(-2px);
        }

        .export-btn.excel:hover {
            border-color: #48bb78;
            color: #48bb78;
        }

        .export-btn.pdf:hover {
            border-color: #f56565;
            color: #f56565;
        }

        .export-btn.print:hover {
            border-color: #4299e1;
            color: #4299e1;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .filter-form {
                grid-template-columns: 1fr 1fr 1fr 1fr;
                gap: 10px;
            }
            
            .filter-btn {
                grid-column: span 4;
            }
        }

        @media (max-width: 992px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
            
            .filter-btn {
                grid-column: span 2;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
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
            
            .header-actions {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-btn {
                grid-column: span 1;
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
            
            .export-buttons {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .employees-container {
                padding: 0 10px;
            }
            
            .table-card {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .employee-mobile-card {
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
            <div class="employees-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-people-fill" style="margin-right: 10px;"></i>
                        Employee Management
                        <span class="badge-count"><?php echo $total_employees; ?> Total</span>
                    </h1>
                    <div class="header-actions">
                        <a href="new-employee.php" class="btn btn-primary">
                            <i class="bi bi-person-plus-fill"></i>
                            Add New Employee
                        </a>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to Dashboard
                        </a>
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

                <!-- Statistics Cards - Enhanced -->
                <div class="stats-grid">
                    <div class="stat-card" onclick="filterByStatus('all')">
                        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="stat-value"><?php echo $total_employees; ?></div>
                        <div class="stat-label">Total Employees</div>
                    </div>
                    <div class="stat-card" onclick="filterByStatus('active')">
                        <div class="stat-icon" style="color: #48bb78;"><i class="bi bi-person-check-fill"></i></div>
                        <div class="stat-value" style="color: #48bb78;"><?php echo $active_employees; ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                    <div class="stat-card" onclick="filterByStatus('inactive')">
                        <div class="stat-icon" style="color: #f56565;"><i class="bi bi-person-x-fill"></i></div>
                        <div class="stat-value" style="color: #f56565;"><?php echo $inactive_employees; ?></div>
                        <div class="stat-label">Inactive</div>
                    </div>
                    <div class="stat-card" onclick="filterByRole('admin')">
                        <div class="stat-icon" style="color: #4299e1;"><i class="bi bi-shield-lock-fill"></i></div>
                        <div class="stat-value" style="color: #4299e1;"><?php echo $admin_count; ?></div>
                        <div class="stat-label">Admins</div>
                    </div>
                    <div class="stat-card" onclick="filterByRole('manager')">
                        <div class="stat-icon" style="color: #f56565;"><i class="bi bi-person-badge-fill"></i></div>
                        <div class="stat-value" style="color: #f56565;"><?php echo $manager_count; ?></div>
                        <div class="stat-label">Managers</div>
                    </div>
                    <div class="stat-card" onclick="filterByRole('sale')">
                        <div class="stat-icon" style="color: #48bb78;"><i class="bi bi-cart-fill"></i></div>
                        <div class="stat-value" style="color: #48bb78;"><?php echo $sale_count; ?></div>
                        <div class="stat-label">Sales Staff</div>
                    </div>
                    <div class="stat-card" onclick="filterByRole('accountant')">
                        <div class="stat-icon" style="color: #ecc94b;"><i class="bi bi-calculator-fill"></i></div>
                        <div class="stat-value" style="color: #ecc94b;"><?php echo $accountant_count; ?></div>
                        <div class="stat-label">Accountants</div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="window.location.href='employees.php'">
                        <i class="bi bi-eye"></i> All Employees
                    </button>
                    <button class="quick-action-btn" onclick="window.location.href='employees.php?status=active'">
                        <i class="bi bi-person-check"></i> Active Only
                    </button>
                    <button class="quick-action-btn" onclick="window.location.href='employees.php?status=inactive'">
                        <i class="bi bi-person-x"></i> Inactive Only
                    </button>
                    <button class="quick-action-btn" onclick="window.location.href='employees.php?role=admin'">
                        <i class="bi bi-shield-lock"></i> Admins
                    </button>
                    <button class="quick-action-btn" onclick="window.location.href='employees.php?role=manager'">
                        <i class="bi bi-person-badge"></i> Managers
                    </button>
                    <button class="quick-action-btn" onclick="window.location.href='employees.php?role=sale'">
                        <i class="bi bi-cart"></i> Sales
                    </button>
                </div>

                <!-- Export Buttons -->
                <div class="export-buttons">
                    <button class="export-btn excel" onclick="exportToExcel()">
                        <i class="bi bi-file-earmark-excel"></i> Export to Excel
                    </button>
                    <button class="export-btn pdf" onclick="exportToPDF()">
                        <i class="bi bi-file-earmark-pdf"></i> Export to PDF
                    </button>
                    <button class="export-btn print" onclick="window.print()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                </div>

                <!-- Filters - Enhanced -->
                <div class="filters-section">
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" class="filter-input" name="search" placeholder="Search by name, ID, email, mobile..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Role</label>
                                <select class="filter-select" name="role" id="roleSelect">
                                    <option value="">All Roles</option>
                                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    <option value="manager" <?php echo $role_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                    <option value="sale" <?php echo $role_filter === 'sale' ? 'selected' : ''; ?>>Sales Staff</option>
                                    <option value="accountant" <?php echo $role_filter === 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select class="filter-select" name="status" id="statusSelect">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Branch</label>
                                <select class="filter-select" name="branch_id" id="branchSelect">
                                    <option value="">All Branches</option>
                                    <?php 
                                    mysqli_data_seek($branches_result, 0);
                                    while($branch = mysqli_fetch_assoc($branches_result)): 
                                    ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $branch_filter == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="filter-btn filter-btn-primary">
                                    <i class="bi bi-funnel-fill"></i>
                                    Apply Filters
                                </button>
                                <a href="employees.php" class="filter-btn filter-btn-reset">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="employees-table" id="employeesTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Username</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($employees_result) > 0): ?>
                                <?php while ($emp = mysqli_fetch_assoc($employees_result)): 
                                    $initials = '';
                                    $name_parts = explode(' ', trim($emp['name']));
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                    }
                                    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    
                                    // Check if employee has photo
                                    $has_photo = !empty($emp['employee_photo']) && file_exists($emp['employee_photo']);
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo $emp['id']; ?></strong></td>
                                        <td>
                                            <?php if ($has_photo): ?>
                                                <img src="<?php echo htmlspecialchars($emp['employee_photo']); ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>" class="employee-avatar-img">
                                            <?php else: ?>
                                                <div class="employee-avatar"><?php echo $initials; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="employee-id-badge">
                                                <?php echo htmlspecialchars($emp['employee_id'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($emp['name']); ?></strong>
                                        </td>
                                        <td>
                                            <i class="bi bi-person" style="color: #667eea; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($emp['username']); ?>
                                        </td>
                                        <td>
                                            <div><i class="bi bi-envelope" style="color: #ecc94b; margin-right: 5px;"></i><?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?></div>
                                            <small><i class="bi bi-phone" style="color: #48bb78; margin-right: 5px;"></i><?php echo htmlspecialchars($emp['mobile'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <span class="role-badge <?php echo $emp['role']; ?>">
                                                <?php echo ucfirst($emp['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-building" style="color: #4299e1; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars($emp['branch_name'] ?? 'Not Assigned'); ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo ($emp['status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>">
                                                <i class="bi bi-<?php echo ($emp['status'] ?? 'active') === 'active' ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo ucfirst($emp['status'] ?? 'active'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="bi bi-calendar" style="color: #718096; margin-right: 5px;"></i>
                                            <?php echo $emp['created_at'] ? date('d-m-Y', strtotime($emp['created_at'])) : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="btn-icon view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="btn-icon edit" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if (($emp['status'] ?? 'active') === 'active' && ($emp['is_active'] ?? 1) == 1): ?>
                                                    <a href="#" class="btn-icon deactivate" title="Deactivate" onclick="confirmAction('deactivate', <?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="#" class="btn-icon activate" title="Activate" onclick="confirmAction('activate', <?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                                        <i class="bi bi-play-circle"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($emp['id'] != $_SESSION['user_id']): ?>
                                                    <a href="#" class="btn-icon delete" title="Delete" onclick="confirmDelete(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="empty-state">
                                        <i class="bi bi-people"></i>
                                        <p>No employees found</p>
                                        <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter) || !empty($branch_filter)): ?>
                                            <a href="employees.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                                        <?php else: ?>
                                            <a href="new-employee.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Employee</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards">
                    <?php if (mysqli_num_rows($employees_result) > 0): ?>
                        <?php mysqli_data_seek($employees_result, 0); ?>
                        <?php while ($emp = mysqli_fetch_assoc($employees_result)): 
                            $initials = '';
                            $name_parts = explode(' ', trim($emp['name']));
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            
                            // Check if employee has photo
                            $has_photo = !empty($emp['employee_photo']) && file_exists($emp['employee_photo']);
                        ?>
                            <div class="employee-mobile-card">
                                <div class="mobile-card-header">
                                    <div class="mobile-title">
                                        <?php if ($has_photo): ?>
                                            <img src="<?php echo htmlspecialchars($emp['employee_photo']); ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>" class="mobile-avatar-img">
                                        <?php else: ?>
                                            <div class="mobile-avatar"><?php echo $initials; ?></div>
                                        <?php endif; ?>
                                        <span class="mobile-name"><?php echo htmlspecialchars($emp['name']); ?></span>
                                    </div>
                                    <span class="mobile-id"><?php echo htmlspecialchars($emp['employee_id'] ?? '#' . $emp['id']); ?></span>
                                </div>
                                
                                <div class="mobile-badges">
                                    <span class="role-badge <?php echo $emp['role']; ?>">
                                        <?php echo ucfirst($emp['role']); ?>
                                    </span>
                                    <span class="status-badge <?php echo ($emp['status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($emp['status'] ?? 'active'); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Username</span>
                                        <span class="detail-value">
                                            <i class="bi bi-person" style="color: #667eea;"></i>
                                            <?php echo htmlspecialchars($emp['username']); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value">
                                            <i class="bi bi-envelope" style="color: #ecc94b;"></i>
                                            <?php echo htmlspecialchars($emp['email'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Mobile</span>
                                        <span class="detail-value">
                                            <i class="bi bi-phone" style="color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($emp['mobile'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Branch</span>
                                        <span class="detail-value">
                                            <i class="bi bi-building" style="color: #4299e1;"></i>
                                            <?php echo htmlspecialchars($emp['branch_name'] ?? 'Not Assigned'); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Joined</span>
                                        <span class="detail-value">
                                            <i class="bi bi-calendar" style="color: #718096;"></i>
                                            <?php echo $emp['created_at'] ? date('d-m-Y', strtotime($emp['created_at'])) : 'N/A'; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mobile-actions">
                                    <a href="view_employee.php?id=<?php echo $emp['id']; ?>" class="btn-icon view" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit_employee.php?id=<?php echo $emp['id']; ?>" class="btn-icon edit" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (($emp['status'] ?? 'active') === 'active' && ($emp['is_active'] ?? 1) == 1): ?>
                                        <a href="#" class="btn-icon deactivate" title="Deactivate" onclick="confirmAction('deactivate', <?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="#" class="btn-icon activate" title="Activate" onclick="confirmAction('activate', <?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($emp['id'] != $_SESSION['user_id']): ?>
                                        <a href="#" class="btn-icon delete" title="Delete" onclick="confirmDelete(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['name']); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>No employees found</p>
                            <?php if (!empty($search) || !empty($role_filter) || !empty($status_filter) || !empty($branch_filter)): ?>
                                <a href="employees.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                            <?php else: ?>
                                <a href="new-employee.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Employee</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- jQuery and DataTables -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        <?php if (mysqli_num_rows($employees_result) > 0): ?>
        $('#employeesTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search employees...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ employees",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [1, 10] }
            ],
            initComplete: function() {
                // Style the search input
                $('.dataTables_filter input').addClass('filter-input');
                $('.dataTables_length select').addClass('filter-select');
            }
        });
        <?php endif; ?>
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);

    // Filter functions
    function filterByRole(role) {
        document.getElementById('roleSelect').value = role;
        document.getElementById('filterForm').submit();
    }

    function filterByStatus(status) {
        document.getElementById('statusSelect').value = status;
        document.getElementById('filterForm').submit();
    }

    // Confirmation dialogs with SweetAlert
    function confirmAction(action, id, name) {
        const actionText = action === 'activate' ? 'activate' : 'deactivate';
        Swal.fire({
            title: `Are you sure?`,
            text: `Do you want to ${actionText} ${name}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: action === 'activate' ? '#48bb78' : '#a0aec0',
            cancelButtonColor: '#718096',
            confirmButtonText: `Yes, ${actionText}`,
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?action=${action}&id=${id}`;
            }
        });
        return false;
    }

    function confirmDelete(id, name) {
        Swal.fire({
            title: 'Delete Employee?',
            html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f56565',
            cancelButtonColor: '#718096',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `?action=delete&id=${id}`;
            }
        });
        return false;
    }

    // Export functions (placeholder - you'll need to implement actual export)
    function exportToExcel() {
        Swal.fire({
            title: 'Export to Excel',
            text: 'This feature will be available soon!',
            icon: 'info',
            timer: 2000,
            showConfirmButton: false
        });
    }

    function exportToPDF() {
        Swal.fire({
            title: 'Export to PDF',
            text: 'This feature will be available soon!',
            icon: 'info',
            timer: 2000,
            showConfirmButton: false
        });
    }

    // Live search with debounce
    let searchTimeout;
    document.querySelector('input[name="search"]').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 500);
    });

    // Auto-submit on select change
    document.getElementById('roleSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    document.getElementById('statusSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    document.getElementById('branchSelect').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>