<?php
session_start();
$currentPage = 'manage-branches';
$pageTitle = 'Manage Branches';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can manage branches
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle status update
if (isset($_GET['action']) && isset($_GET['id'])) {
    $branch_id = intval($_GET['id']);
    
    if ($_GET['action'] === 'activate') {
        $update_query = "UPDATE branches SET status = 'active' WHERE id = ?";
        $action = 'activated';
    } elseif ($_GET['action'] === 'deactivate') {
        $update_query = "UPDATE branches SET status = 'inactive' WHERE id = ?";
        $action = 'deactivated';
    } elseif ($_GET['action'] === 'delete') {
        // Check if branch has users before deleting
        $check_users = "SELECT COUNT(*) as user_count FROM users WHERE branch_id = ?";
        $stmt = mysqli_prepare($conn, $check_users);
        mysqli_stmt_bind_param($stmt, 'i', $branch_id);
        mysqli_stmt_execute($stmt);
        $user_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['user_count'];
        
        if ($user_count > 0) {
            $error = "Cannot delete branch because it has $user_count user(s) assigned.";
        } else {
            // Get branch details for log
            $branch_query = "SELECT branch_name FROM branches WHERE id = ?";
            $stmt = mysqli_prepare($conn, $branch_query);
            mysqli_stmt_bind_param($stmt, 'i', $branch_id);
            mysqli_stmt_execute($stmt);
            $branch_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $branch_name = $branch_data['branch_name'];
            
            $delete_query = "DELETE FROM branches WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, 'i', $branch_id);
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'delete', 'Branch deleted: $branch_name', 'branches', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $branch_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: manage_branches.php?success=deleted');
                exit();
            }
        }
    }
    
    if (isset($update_query)) {
        // Get branch details for log
        $branch_query = "SELECT branch_name FROM branches WHERE id = ?";
        $stmt = mysqli_prepare($conn, $branch_query);
        mysqli_stmt_bind_param($stmt, 'i', $branch_id);
        mysqli_stmt_execute($stmt);
        $branch_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $branch_name = $branch_data['branch_name'];
        
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, 'i', $branch_id);
        if (mysqli_stmt_execute($stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, '$action', 'Branch $action: $branch_name', 'branches', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $branch_id);
            mysqli_stmt_execute($log_stmt);
            
            header('Location: manage_branches.php?success=updated');
            exit();
        }
    }
}

// Handle URL success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'updated':
            $message = "Branch status updated successfully!";
            break;
        case 'deleted':
            $message = "Branch deleted successfully!";
            break;
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build query
$query = "SELECT b.*, 
          (SELECT COUNT(*) FROM users WHERE branch_id = b.id) as user_count 
          FROM branches b WHERE 1=1";
if (!empty($search)) {
    $query .= " AND (b.branch_name LIKE '%$search%' OR b.branch_code LIKE '%$search%' OR b.address LIKE '%$search%' OR b.phone LIKE '%$search%' OR b.email LIKE '%$search%')";
}
if (!empty($status_filter)) {
    $query .= " AND b.status = '$status_filter'";
}
$query .= " ORDER BY b.created_at DESC";

$branches_result = mysqli_query($conn, $query);

// Get statistics
$total_branches = mysqli_num_rows($branches_result);
$active_branches_query = "SELECT COUNT(*) as count FROM branches WHERE status = 'active'";
$active_branches = mysqli_fetch_assoc(mysqli_query($conn, $active_branches_query))['count'];
$inactive_branches_query = "SELECT COUNT(*) as count FROM branches WHERE status = 'inactive'";
$inactive_branches = mysqli_fetch_assoc(mysqli_query($conn, $inactive_branches_query))['count'];
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

        /* Branches Container */
        .branches-container {
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

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.5);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(236, 201, 75, 0.4);
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(236, 201, 75, 0.5);
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

        /* Statistics Cards - Matching index page stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.15);
        }

        .stat-card:before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 50%;
            z-index: 0;
        }

        .stat-content {
            position: relative;
            z-index: 1;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            font-weight: 500;
        }

        .stat-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 24px;
            color: #667eea30;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
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
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
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
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 48px;
        }

        .filter-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .filter-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .filter-btn-secondary {
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .filter-btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
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

        .branches-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .branches-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .branches-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .branches-table tbody tr {
            transition: all 0.3s;
        }

        .branches-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
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

        .status-badge.active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .status-badge.inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        /* Code Badge */
        .code-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 12px;
            display: inline-block;
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

        .btn-icon.users:hover {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }

        /* User Count Badge */
        .user-count-badge {
            background: #ebf4ff;
            color: #667eea;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Mobile Cards */
        .mobile-cards {
            display: none;
        }

        .branch-mobile-card {
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
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
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
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-btn {
                width: 100%;
                justify-content: center;
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
            
            .header-actions {
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .branches-container {
                padding: 0 10px;
            }
            
            .table-card {
                padding: 15px;
            }
            
            .branch-mobile-card {
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
            <div class="branches-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-building" style="margin-right: 10px;"></i>
                        Manage Branches
                        <span class="badge-count"><?php echo $total_branches; ?></span>
                    </h1>
                    <div class="header-actions">
                        <a href="add_branch.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i>
                            Add New Branch
                        </a>
                        <a href="index.php" class="btn btn-secondary">
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

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $total_branches; ?></div>
                            <div class="stat-label">Total Branches</div>
                        </div>
                        <i class="bi bi-building stat-icon"></i>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-value" style="color: #48bb78;"><?php echo $active_branches; ?></div>
                            <div class="stat-label">Active Branches</div>
                        </div>
                        <i class="bi bi-check-circle stat-icon"></i>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-value" style="color: #f56565;"><?php echo $inactive_branches; ?></div>
                            <div class="stat-label">Inactive Branches</div>
                        </div>
                        <i class="bi bi-x-circle stat-icon"></i>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" class="filter-input" name="search" placeholder="Search by name, code, address, phone, email..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select class="filter-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <button type="submit" class="filter-btn filter-btn-primary">
                                <i class="bi bi-funnel"></i>
                                Apply Filters
                            </button>
                            <a href="manage_branches.php" class="filter-btn filter-btn-secondary">
                                <i class="bi bi-arrow-repeat"></i>
                                Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="branches-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Branch Code</th>
                                <th>Branch Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Manager</th>
                                <th>Users</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($branches_result) > 0): ?>
                                <?php mysqli_data_seek($branches_result, 0); ?>
                                <?php while ($branch = mysqli_fetch_assoc($branches_result)): ?>
                                    <tr>
                                        <td><strong>#<?php echo $branch['id']; ?></strong></td>
                                        <td>
                                            <span class="code-badge">
                                                <?php echo htmlspecialchars($branch['branch_code']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($branch['branch_name']); ?></strong>
                                            <?php if (!empty($branch['website'])): ?>
                                                <br>
                                                <small><i class="bi bi-globe" style="color: #667eea;"></i> <?php echo htmlspecialchars($branch['website']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <i class="bi bi-geo-alt" style="color: #667eea; margin-right: 5px;"></i>
                                            <?php echo htmlspecialchars(substr($branch['address'] ?? '', 0, 30)) . (strlen($branch['address'] ?? '') > 30 ? '...' : ''); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($branch['phone'])): ?>
                                                <div><i class="bi bi-telephone" style="color: #48bb78;"></i> <?php echo htmlspecialchars($branch['phone']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($branch['email'])): ?>
                                                <small><i class="bi bi-envelope" style="color: #4299e1;"></i> <?php echo htmlspecialchars($branch['email']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($branch['manager_name'])): ?>
                                                <div><i class="bi bi-person" style="color: #ecc94b;"></i> <?php echo htmlspecialchars($branch['manager_name']); ?></div>
                                                <?php if (!empty($branch['manager_mobile'])): ?>
                                                    <small><i class="bi bi-phone" style="color: #9f7aea;"></i> <?php echo htmlspecialchars($branch['manager_mobile']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="user-count-badge">
                                                <i class="bi bi-people"></i> <?php echo $branch['user_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $branch['status']; ?>">
                                                <i class="bi bi-<?php echo $branch['status'] === 'active' ? 'check-circle' : 'x-circle'; ?>" style="margin-right: 4px;"></i>
                                                <?php echo ucfirst($branch['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_branch.php?id=<?php echo $branch['id']; ?>" class="btn-icon view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_branch.php?id=<?php echo $branch['id']; ?>" class="btn-icon edit" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="manage_branch_users.php?branch_id=<?php echo $branch['id']; ?>" class="btn-icon users" title="Manage Users">
                                                    <i class="bi bi-people"></i>
                                                </a>
                                                <?php if ($branch['status'] === 'active'): ?>
                                                    <a href="?action=deactivate&id=<?php echo $branch['id']; ?>" class="btn-icon deactivate" title="Deactivate" onclick="return confirm('Are you sure you want to deactivate this branch?')">
                                                        <i class="bi bi-pause-circle"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?action=activate&id=<?php echo $branch['id']; ?>" class="btn-icon activate" title="Activate" onclick="return confirm('Are you sure you want to activate this branch?')">
                                                        <i class="bi bi-play-circle"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($branch['user_count'] == 0): ?>
                                                    <a href="?action=delete&id=<?php echo $branch['id']; ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Are you sure you want to delete this branch? This action cannot be undone.')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <i class="bi bi-building"></i>
                                        <p>No branches found</p>
                                        <?php if (!empty($search) || !empty($status_filter)): ?>
                                            <a href="manage_branches.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                                        <?php else: ?>
                                            <a href="add_branch.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Branch</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards">
                    <?php if (mysqli_num_rows($branches_result) > 0): ?>
                        <?php mysqli_data_seek($branches_result, 0); ?>
                        <?php while ($branch = mysqli_fetch_assoc($branches_result)): ?>
                            <div class="branch-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-title"><?php echo htmlspecialchars($branch['branch_name']); ?></span>
                                    <span class="status-badge <?php echo $branch['status']; ?>">
                                        <?php echo ucfirst($branch['status']); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Branch Code</span>
                                        <span class="detail-value code-badge" style="display: inline-block;"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Phone</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($branch['phone'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($branch['email'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Address</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($branch['address'] ?? 'No address'); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Manager</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($branch['manager_name'] ?? 'Not assigned'); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Users</span>
                                        <span class="detail-value"><?php echo $branch['user_count']; ?> users</span>
                                    </div>
                                </div>
                                
                                <div class="mobile-actions">
                                    <a href="view_branch.php?id=<?php echo $branch['id']; ?>" class="btn-icon view" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit_branch.php?id=<?php echo $branch['id']; ?>" class="btn-icon edit" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="manage_branch_users.php?branch_id=<?php echo $branch['id']; ?>" class="btn-icon users" title="Manage Users">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <?php if ($branch['status'] === 'active'): ?>
                                        <a href="?action=deactivate&id=<?php echo $branch['id']; ?>" class="btn-icon deactivate" title="Deactivate" onclick="return confirm('Are you sure you want to deactivate this branch?')">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="?action=activate&id=<?php echo $branch['id']; ?>" class="btn-icon activate" title="Activate" onclick="return confirm('Are you sure you want to activate this branch?')">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-building"></i>
                            <p>No branches found</p>
                            <?php if (!empty($search) || !empty($status_filter)): ?>
                                <a href="manage_branches.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                            <?php else: ?>
                                <a href="add_branch.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Branch</a>
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

<script>
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