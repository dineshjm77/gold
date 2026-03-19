<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customers';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Check for success/error messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Customer created successfully!";
            break;
        case 'updated':
            $message = "Customer updated successfully!";
            break;
        case 'deleted':
            $message = "Customer deleted successfully!";
            break;
    }
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'notfound':
            $error = "Customer not found.";
            break;
        case 'delete':
            $error = "Error deleting customer.";
            break;
    }
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $delete_id = intval($_GET['id']);
    
    // Check if customer has any loans before deleting
    $check_loans = "SELECT COUNT(*) as count FROM loans WHERE customer_id = ?";
    $stmt = mysqli_prepare($conn, $check_loans);
    mysqli_stmt_bind_param($stmt, 'i', $delete_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan_count = $result->fetch_assoc()['count'];
    
    if ($loan_count > 0) {
        header('Location: customers.php?error=hasloans');
        exit();
    }
    
    // Get customer photo path before deleting
    $photo_query = "SELECT customer_photo FROM customers WHERE id = ?";
    $stmt = mysqli_prepare($conn, $photo_query);
    mysqli_stmt_bind_param($stmt, 'i', $delete_id);
    mysqli_stmt_execute($stmt);
    $photo_result = mysqli_stmt_get_result($stmt);
    $photo_data = $photo_result->fetch_assoc();
    
    // Delete customer
    $delete_query = "DELETE FROM customers WHERE id = ?";
    $stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($stmt, 'i', $delete_id);
    
    if (mysqli_stmt_execute($stmt)) {
        // Delete photo file if exists
        if ($photo_data && !empty($photo_data['customer_photo']) && file_exists($photo_data['customer_photo'])) {
            unlink($photo_data['customer_photo']);
            
            // Try to remove empty folder
            $folder = dirname($photo_data['customer_photo']);
            if (is_dir($folder) && count(scandir($folder)) == 2) {
                rmdir($folder);
            }
        }
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                      VALUES (?, 'delete', 'Customer deleted', 'customers', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $delete_id);
        mysqli_stmt_execute($log_stmt);
        
        header('Location: customers.php?success=deleted');
        exit();
    } else {
        header('Location: customers.php?error=delete');
        exit();
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$location_filter = isset($_GET['location']) ? mysqli_real_escape_string($conn, $_GET['location']) : '';
$noted_filter = isset($_GET['noted']) ? intval($_GET['noted']) : 0;

// Build query
$query = "SELECT c.*, 
          (SELECT COUNT(*) FROM loans WHERE customer_id = c.id) as total_loans,
          (SELECT SUM(loan_amount) FROM loans WHERE customer_id = c.id) as total_loan_amount,
          (SELECT COUNT(*) FROM loans WHERE customer_id = c.id AND status = 'open') as active_loans
          FROM customers c 
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.customer_name LIKE ? OR c.mobile_number LIKE ? OR c.aadhaar_number LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($location_filter)) {
    $query .= " AND c.location LIKE ?";
    $location_param = "%$location_filter%";
    $params[] = $location_param;
    $types .= "s";
}

if ($noted_filter === 1) {
    $query .= " AND c.is_noted_person = 1";
} elseif ($noted_filter === 2) {
    $query .= " AND (c.is_noted_person = 0 OR c.is_noted_person IS NULL)";
}

$query .= " ORDER BY c.created_at DESC";

// Prepare and execute query
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $customers = mysqli_stmt_get_result($stmt);
} else {
    $customers = mysqli_query($conn, $query);
}

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_customers,
                SUM(CASE WHEN is_noted_person = 1 THEN 1 ELSE 0 END) as noted_persons,
                COUNT(DISTINCT location) as total_locations
                FROM customers";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get unique locations for filter
$locations_query = "SELECT DISTINCT location FROM customers WHERE location IS NOT NULL AND location != '' ORDER BY location";
$locations_result = mysqli_query($conn, $locations_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
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

        .customers-container {
            width: 100%;
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

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 101, 101, 0.5);
        }

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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s;
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
            margin-bottom: 15px;
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

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
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

        .filter-input, .filter-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .filter-input:focus, .filter-select:focus {
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

        .filter-btn-reset {
            background: #a0aec0;
            color: white;
        }

        .filter-btn-reset:hover {
            background: #718096;
            transform: translateY(-2px);
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
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

        /* DataTables Customization */
        .dataTables_wrapper {
            padding: 0;
        }

        .dataTables_length select,
        .dataTables_filter input {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
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

        /* Customers Table */
        .customers-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .customers-table thead th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .customers-table tbody td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            vertical-align: middle;
        }

        .customers-table tbody tr {
            transition: all 0.3s;
        }

        .customers-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        /* Customer Avatar */
        .customer-avatar {
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

        .customer-avatar-img {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #667eea;
            box-shadow: 0 4px 10px rgba(102, 126, 234, 0.3);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-noted {
            background: #feebc8;
            color: #744210;
        }

        .badge-normal {
            background: #e2e8f0;
            color: #4a5568;
        }

        .badge-loan {
            background: #c6f6d5;
            color: #22543d;
            margin-right: 4px;
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

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        /* Mobile Cards */
        .mobile-cards {
            display: none;
        }

        .customer-mobile-card {
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
            border-radius: 50px;
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
            font-size: 12px;
            color: #718096;
            margin-bottom: 4px;
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

        /* Responsive Design */
        @media (max-width: 1200px) {
            .filter-form {
                grid-template-columns: 1fr 1fr 1fr;
            }
        }

        @media (max-width: 992px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
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
                grid-template-columns: 1fr;
            }
            
            .filter-form {
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
            
            .customers-container {
                padding: 0 10px;
            }
            
            .table-card {
                padding: 15px;
            }
            
            .customer-mobile-card {
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
            <div class="customers-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-people" style="margin-right: 10px;"></i>
                        Customer Management
                    </h1>
                    <div class="header-actions">
                        <a href="New-Customer.php" class="btn btn-success">
                            <i class="bi bi-person-plus"></i>
                            Add New Customer
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

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_customers'] ?? 0); ?></div>
                        <div class="stat-label">Total Customers</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-star-fill" style="color: #ecc94b;"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['noted_persons'] ?? 0); ?></div>
                        <div class="stat-label">Noted Persons</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-geo-alt-fill"></i>
                        </div>
                        <div class="stat-value"><?php echo number_format($stats['total_locations'] ?? 0); ?></div>
                        <div class="stat-label">Locations</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="" class="filter-form">
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" class="filter-input" name="search" placeholder="Search by name, mobile, Aadhaar..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Location</label>
                            <select class="filter-select" name="location">
                                <option value="">All Locations</option>
                                <?php 
                                if ($locations_result && mysqli_num_rows($locations_result) > 0) {
                                    mysqli_data_seek($locations_result, 0);
                                    while ($loc = mysqli_fetch_assoc($locations_result)) {
                                        $selected = ($location_filter == $loc['location']) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($loc['location']) . "' $selected>" . htmlspecialchars($loc['location']) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Customer Type</label>
                            <select class="filter-select" name="noted">
                                <option value="0">All Customers</option>
                                <option value="1" <?php echo $noted_filter == 1 ? 'selected' : ''; ?>>Noted Persons Only</option>
                                <option value="2" <?php echo $noted_filter == 2 ? 'selected' : ''; ?>>Regular Customers</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="filter-btn filter-btn-primary">
                            <i class="bi bi-funnel"></i>
                            Apply Filters
                        </button>
                        
                        <?php if (!empty($search) || !empty($location_filter) || !empty($noted_filter)): ?>
                        <a href="customers.php" class="filter-btn filter-btn-reset" style="text-decoration: none;">
                            <i class="bi bi-x-circle"></i>
                            Clear
                        </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <div class="table-header">
                        <span class="table-title">
                            <i class="bi bi-list-ul"></i>
                            Customer List
                        </span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="customers-table" id="customersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Photo</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Location</th>
                                    <th>Type</th>
                                    <th>Loans</th>
                                    <th>Total Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($customers && mysqli_num_rows($customers) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($customers)): 
                                        $initials = '';
                                        $name_parts = explode(' ', trim($row['customer_name']));
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) {
                                                $initials .= strtoupper(substr($part, 0, 1));
                                            }
                                        }
                                        if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                        
                                        $has_photo = !empty($row['customer_photo']) && file_exists($row['customer_photo']);
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td>
                                                <?php if ($has_photo): ?>
                                                    <img src="<?php echo htmlspecialchars($row['customer_photo']); ?>" class="customer-avatar-img" alt="<?php echo htmlspecialchars($row['customer_name']); ?>">
                                                <?php else: ?>
                                                    <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
                                                <?php if (!empty($row['guardian_name'])): ?>
                                                    <br><small><?php echo $row['guardian_type'] ? $row['guardian_type'] . '. ' : ''; ?><?php echo htmlspecialchars($row['guardian_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <i class="bi bi-phone" style="color: #48bb78; margin-right: 5px;"></i><?php echo htmlspecialchars($row['mobile_number']); ?>
                                                <?php if (!empty($row['email'])): ?>
                                                    <br><small><i class="bi bi-envelope" style="color: #4299e1; margin-right: 5px;"></i><?php echo htmlspecialchars($row['email']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['location'])): ?>
                                                    <i class="bi bi-geo-alt" style="color: #667eea; margin-right: 5px;"></i>
                                                    <?php echo htmlspecialchars($row['location']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['is_noted_person'] == 1): ?>
                                                    <span class="badge badge-noted">
                                                        <i class="bi bi-star-fill"></i> Noted Person
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-normal">Regular</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['total_loans'] > 0): ?>
                                                    <span class="badge badge-loan">
                                                        <i class="bi bi-cash-stack"></i> <?php echo $row['total_loans']; ?> Loans
                                                    </span>
                                                    <?php if ($row['active_loans'] > 0): ?>
                                                        <br><small><?php echo $row['active_loans']; ?> active</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No loans</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount">
                                                <?php if ($row['total_loan_amount'] > 0): ?>
                                                    ₹<?php echo number_format($row['total_loan_amount'], 2); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="view_customer.php?id=<?php echo $row['id']; ?>" class="btn-icon view" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="New-Customer.php?id=<?php echo $row['id']; ?>" class="btn-icon edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <?php if ($row['total_loans'] == 0): ?>
                                                        <a href="?action=delete&id=<?php echo $row['id']; ?>" class="btn-icon delete" title="Delete" onclick="return confirmDelete('<?php echo htmlspecialchars($row['customer_name']); ?>')">
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
                                            <i class="bi bi-people"></i>
                                            <p>No customers found</p>
                                            <?php if (!empty($search) || !empty($location_filter) || !empty($noted_filter)): ?>
                                                <a href="customers.php" class="btn btn-secondary">Clear Filters</a>
                                            <?php else: ?>
                                                <a href="New-Customer.php" class="btn btn-success">Add Your First Customer</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards">
                    <?php if ($customers && mysqli_num_rows($customers) > 0): ?>
                        <?php mysqli_data_seek($customers, 0); ?>
                        <?php while ($row = mysqli_fetch_assoc($customers)): 
                            $initials = '';
                            $name_parts = explode(' ', trim($row['customer_name']));
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            
                            $has_photo = !empty($row['customer_photo']) && file_exists($row['customer_photo']);
                        ?>
                            <div class="customer-mobile-card">
                                <div class="mobile-card-header">
                                    <div class="mobile-title">
                                        <?php if ($has_photo): ?>
                                            <img src="<?php echo htmlspecialchars($row['customer_photo']); ?>" class="mobile-avatar-img">
                                        <?php else: ?>
                                            <div class="mobile-avatar"><?php echo $initials; ?></div>
                                        <?php endif; ?>
                                        <span class="mobile-name"><?php echo htmlspecialchars($row['customer_name']); ?></span>
                                    </div>
                                    <span class="mobile-id">#<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                </div>
                                
                                <div class="mobile-badges">
                                    <?php if ($row['is_noted_person'] == 1): ?>
                                        <span class="badge badge-noted">
                                            <i class="bi bi-star-fill"></i> Noted Person
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-normal">Regular</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($row['total_loans'] > 0): ?>
                                        <span class="badge badge-loan">
                                            <i class="bi bi-cash-stack"></i> <?php echo $row['total_loans']; ?> Loans
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Mobile</span>
                                        <span class="detail-value">
                                            <i class="bi bi-phone" style="color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($row['mobile_number']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($row['email'])): ?>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value">
                                            <i class="bi bi-envelope" style="color: #4299e1;"></i>
                                            <?php echo htmlspecialchars($row['email']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Location</span>
                                        <span class="detail-value">
                                            <i class="bi bi-geo-alt" style="color: #667eea;"></i>
                                            <?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($row['guardian_name'])): ?>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Guardian</span>
                                        <span class="detail-value">
                                            <?php echo $row['guardian_type'] ? $row['guardian_type'] . '. ' : ''; ?><?php echo htmlspecialchars($row['guardian_name']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($row['total_loan_amount'] > 0): ?>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Total Loans</span>
                                        <span class="detail-value">₹<?php echo number_format($row['total_loan_amount'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mobile-actions">
                                    <a href="view_customer.php?id=<?php echo $row['id']; ?>" class="btn-icon view" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="New-Customer.php?id=<?php echo $row['id']; ?>" class="btn-icon edit" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($row['total_loans'] == 0): ?>
                                        <a href="?action=delete&id=<?php echo $row['id']; ?>" class="btn-icon delete" title="Delete" onclick="return confirmDelete('<?php echo htmlspecialchars($row['customer_name']); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <p>No customers found</p>
                            <?php if (!empty($search) || !empty($location_filter) || !empty($noted_filter)): ?>
                                <a href="customers.php" class="btn btn-secondary">Clear Filters</a>
                            <?php else: ?>
                                <a href="New-Customer.php" class="btn btn-success">Add Your First Customer</a>
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
    // Initialize DataTable
    $(document).ready(function() {
        <?php if ($customers && mysqli_num_rows($customers) > 0): ?>
        $('#customersTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search customers...",
                lengthMenu: "Show _MENU_ customers",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [1, 8] }
            ],
            initComplete: function() {
                $('.dataTables_filter input').addClass('filter-input');
                $('.dataTables_length select').addClass('filter-select');
            }
        });
        <?php endif; ?>
    });

    // Confirm delete with SweetAlert
    function confirmDelete(customerName) {
        Swal.fire({
            title: 'Delete Customer?',
            html: `Are you sure you want to delete <strong>${customerName}</strong>?<br>This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f56565',
            cancelButtonColor: '#a0aec0',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                return true;
            }
            return false;
        });
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