<?php
session_start();
$currentPage = 'customers';
$pageTitle = 'Customers';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle status update or delete
if (isset($_GET['action']) && isset($_GET['id'])) {
    $customer_id = intval($_GET['id']);
    
    if ($_GET['action'] === 'delete') {
        // Check if customer has any loans before deleting
        $check_loans = "SELECT COUNT(*) as loan_count FROM loans WHERE customer_id = ?";
        $stmt = mysqli_prepare($conn, $check_loans);
        mysqli_stmt_bind_param($stmt, 'i', $customer_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $loan_count = mysqli_fetch_assoc($result)['loan_count'];
        
        if ($loan_count > 0) {
            $error = "Cannot delete customer because they have $loan_count loan(s).";
        } else {
            // Get customer name for logging
            $name_query = "SELECT customer_name FROM customers WHERE id = ?";
            $name_stmt = mysqli_prepare($conn, $name_query);
            mysqli_stmt_bind_param($name_stmt, 'i', $customer_id);
            mysqli_stmt_execute($name_stmt);
            $name_result = mysqli_stmt_get_result($name_stmt);
            $customer_name = mysqli_fetch_assoc($name_result)['customer_name'] ?? 'Unknown';
            
            $delete_query = "DELETE FROM customers WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, 'i', $customer_id);
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'delete', ?, 'customers', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $log_description = "Customer deleted: " . $customer_name;
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $customer_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: customers.php?success=deleted');
                exit();
            } else {
                $error = "Error deleting customer: " . mysqli_error($conn);
            }
        }
    }
}

// Handle URL success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Customer added successfully!";
            break;
        case 'updated':
            $message = "Customer updated successfully!";
            break;
        case 'deleted':
            $message = "Customer deleted successfully!";
            break;
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$loan_status = isset($_GET['loan_status']) ? mysqli_real_escape_string($conn, $_GET['loan_status']) : '';

// Check if the new columns exist
$check_columns_query = "SHOW COLUMNS FROM customers LIKE 'is_noted_person'";
$check_columns_result = mysqli_query($conn, $check_columns_query);
$has_noted_person = mysqli_num_rows($check_columns_result) > 0;

// Build query with loan summary and limit information
$query = "SELECT c.*, 
          COUNT(l.id) as total_loans,
          SUM(CASE WHEN l.status = 'open' THEN 1 ELSE 0 END) as open_loans,
          SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END) as closed_loans,
          COALESCE(SUM(CASE WHEN l.status = 'open' THEN l.loan_amount ELSE 0 END), 0) as total_active_loan_amount,
          COALESCE(SUM(l.loan_amount), 0) as total_loan_amount,
          MAX(l.created_at) as last_loan_date";

// Add noted person columns if they exist
if ($has_noted_person) {
    $query .= ", c.is_noted_person, c.noted_person_remarks";
}

$query .= " FROM customers c
          LEFT JOIN loans l ON c.id = l.customer_id
          WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (c.customer_name LIKE '%$search%' OR c.mobile_number LIKE '%$search%' OR c.aadhaar_number LIKE '%$search%' OR c.guardian_name LIKE '%$search%')";
}

$query .= " GROUP BY c.id";

if ($loan_status === 'has_loans') {
    $query .= " HAVING total_loans > 0";
} elseif ($loan_status === 'no_loans') {
    $query .= " HAVING total_loans = 0";
} elseif ($loan_status === 'active_loans') {
    $query .= " HAVING open_loans > 0";
} elseif ($loan_status === 'noted_persons' && $has_noted_person) {
    $query .= " HAVING is_noted_person = 1";
}

$query .= " ORDER BY c.created_at DESC";

$customers_result = mysqli_query($conn, $query);
if (!$customers_result) {
    $error = "Query error: " . mysqli_error($conn);
}

// Get statistics
$total_customers = 0;
$active_loans_customers = 0;
$total_loans_amount = 0;
$new_customers_month = 0;
$noted_persons_count = 0;

$total_query = "SELECT COUNT(*) as count FROM customers";
$total_result = mysqli_query($conn, $total_query);
if ($total_result) {
    $total_customers = mysqli_fetch_assoc($total_result)['count'] ?? 0;
}

$active_query = "SELECT COUNT(DISTINCT customer_id) as count FROM loans WHERE status = 'open'";
$active_result = mysqli_query($conn, $active_query);
if ($active_result) {
    $active_loans_customers = mysqli_fetch_assoc($active_result)['count'] ?? 0;
}

$amount_query = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM loans WHERE status = 'open'";
$amount_result = mysqli_query($conn, $amount_query);
if ($amount_result) {
    $total_loans_amount = mysqli_fetch_assoc($amount_result)['total'] ?? 0;
}

$new_query = "SELECT COUNT(*) as count FROM customers WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
$new_result = mysqli_query($conn, $new_query);
if ($new_result) {
    $new_customers_month = mysqli_fetch_assoc($new_result)['count'] ?? 0;
}

if ($has_noted_person) {
    $noted_query = "SELECT COUNT(*) as count FROM customers WHERE is_noted_person = 1";
    $noted_result = mysqli_query($conn, $noted_query);
    if ($noted_result) {
        $noted_persons_count = mysqli_fetch_assoc($noted_result)['count'] ?? 0;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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
            display: flex;
            align-items: center;
            gap: 15px;
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

        .btn-warning {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        .noted-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 5px rgba(245, 158, 11, 0.3);
        }

        .noted-badge i {
            font-size: 16px;
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

        .stat-icon {
            font-size: 32px;
            margin-bottom: 15px;
            color: #667eea;
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

        .stat-card.noted {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: #fbbf24;
        }

        .stat-card.noted .stat-icon {
            color: #f59e0b;
        }

        .stat-card.noted .stat-value {
            color: #92400e;
        }

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
            grid-template-columns: 2fr 1fr auto;
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

        .filter-btn-warning {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }

        .filter-btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
        }

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

        .customers-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

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

        .customer-avatar.noted {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.has-loans {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .status-badge.no-loans {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
        }

        .status-badge.active-loans {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .noted-table-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 3px 8px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-left: 5px;
        }

        .loan-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 4px;
        }

        .loan-badge.open {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .loan-badge.closed {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .loan-limit-info {
            font-size: 13px;
        }

        .loan-limit-progress {
            width: 100px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }

        .loan-limit-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #4299e1);
            border-radius: 3px;
        }

        .loan-limit-progress-bar.warning {
            background: linear-gradient(90deg, #fbbf24, #f59e0b);
        }

        .loan-limit-progress-bar.danger {
            background: linear-gradient(90deg, #f56565, #c53030);
        }

        .loan-limit-text {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .loan-limit-text i {
            font-size: 12px;
        }

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

        .btn-icon.loan:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-icon.noted:hover {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }

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

        .customer-mobile-card.noted {
            border-left: 4px solid #f59e0b;
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

        .mobile-noted-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 3px;
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

        .mobile-loan-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 15px;
        }

        .mobile-loan-limit {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
        }

        .mobile-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

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

        .badge-count {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 14px;
            margin-left: 10px;
            font-weight: 600;
        }

        .db-warning {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            border: 2px solid #f56565;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: #c53030;
            font-weight: 500;
        }

        .db-warning i {
            margin-right: 8px;
        }

        @media (max-width: 1024px) {
            .filter-form {
                grid-template-columns: 1fr;
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
                flex-direction: column;
                gap: 10px;
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
                        Customers
                        <span class="badge-count"><?php echo $total_customers; ?></span>
                        <?php if ($has_noted_person && $noted_persons_count > 0): ?>
                            <span class="noted-badge">
                                <i class="bi bi-star-fill"></i> <?php echo $noted_persons_count; ?> Noted
                            </span>
                        <?php endif; ?>
                    </h1>
                    <div class="header-actions">
                        <a href="New-Customer.php" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i>
                            Add New Customer
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

                <?php if (!$has_noted_person): ?>
                    <div class="db-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Database update required: The "Noted Person" feature is not available yet. 
                        Please run the SQL: ALTER TABLE customers ADD COLUMN is_noted_person TINYINT(1) DEFAULT 0, ADD COLUMN noted_person_remarks TEXT DEFAULT NULL;
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="stat-value"><?php echo $total_customers; ?></div>
                            <div class="stat-label">Total Customers</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon" style="color: #48bb78;"><i class="bi bi-person-check-fill"></i></div>
                            <div class="stat-value" style="color: #48bb78;"><?php echo $active_loans_customers; ?></div>
                            <div class="stat-label">Active Loan Holders</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon" style="color: #ecc94b;"><i class="bi bi-currency-rupee"></i></div>
                            <div class="stat-value" style="color: #ecc94b;">₹<?php echo number_format($total_loans_amount, 2); ?></div>
                            <div class="stat-label">Outstanding Amount</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon" style="color: #4299e1;"><i class="bi bi-calendar-plus"></i></div>
                            <div class="stat-value" style="color: #4299e1;"><?php echo $new_customers_month; ?></div>
                            <div class="stat-label">New This Month</div>
                        </div>
                    </div>
                    <?php if ($has_noted_person): ?>
                    <div class="stat-card noted">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-star-fill"></i></div>
                            <div class="stat-value"><?php echo $noted_persons_count; ?></div>
                            <div class="stat-label">Noted Persons</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Filters -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filter-form">
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" class="filter-input" name="search" placeholder="Search by name, mobile, aadhar, guardian..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Filter</label>
                                <select class="filter-select" name="loan_status">
                                    <option value="">All Customers</option>
                                    <option value="has_loans" <?php echo $loan_status === 'has_loans' ? 'selected' : ''; ?>>With Loans</option>
                                    <option value="no_loans" <?php echo $loan_status === 'no_loans' ? 'selected' : ''; ?>>Without Loans</option>
                                    <option value="active_loans" <?php echo $loan_status === 'active_loans' ? 'selected' : ''; ?>>With Active Loans</option>
                                    <?php if ($has_noted_person): ?>
                                    <option value="noted_persons" <?php echo $loan_status === 'noted_persons' ? 'selected' : ''; ?>>Noted Persons</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <button type="submit" class="filter-btn filter-btn-primary">
                                <i class="bi bi-funnel"></i>
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="customers-table" id="customersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Mobile</th>
                                <th>Guardian</th>
                                <th>Location</th>
                                <th>Loan Status</th>
                                <th>Loan Limit</th>
                                <th>Total Loans</th>
                                <th>Active Amount</th>
                                <th>Last Loan</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($customers_result && mysqli_num_rows($customers_result) > 0): ?>
                                <?php while ($customer = mysqli_fetch_assoc($customers_result)): 
                                    $initials = '';
                                    $name_parts = explode(' ', trim($customer['customer_name']));
                                    foreach ($name_parts as $part) {
                                        if (!empty($part)) {
                                            $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                    }
                                    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    
                                    if ($customer['open_loans'] > 0) {
                                        $status_class = 'active-loans';
                                        $status_text = 'Active Loans';
                                    } elseif ($customer['total_loans'] > 0) {
                                        $status_class = 'has-loans';
                                        $status_text = 'Has Loans';
                                    } else {
                                        $status_class = 'no-loans';
                                        $status_text = 'No Loans';
                                    }
                                    
                                    $loan_limit = floatval($customer['loan_limit_amount'] ?? 10000000);
                                    $active_amount = floatval($customer['total_active_loan_amount'] ?? 0);
                                    $limit_percentage = $loan_limit > 0 ? ($active_amount / $loan_limit) * 100 : 0;
                                    
                                    $progress_class = '';
                                    if ($limit_percentage > 90) {
                                        $progress_class = 'danger';
                                    } elseif ($limit_percentage > 70) {
                                        $progress_class = 'warning';
                                    }
                                    
                                    $is_noted = isset($customer['is_noted_person']) && $customer['is_noted_person'] == 1;
                                ?>
                                    <tr <?php echo $is_noted ? 'style="border-left: 3px solid #f59e0b;"' : ''; ?>>
                                        <td><strong>#<?php echo $customer['id']; ?></strong></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div class="customer-avatar <?php echo $is_noted ? 'noted' : ''; ?>"><?php echo $initials; ?></div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($customer['customer_name']); ?></strong>
                                                    <?php if ($is_noted): ?>
                                                        <span class="noted-table-badge" title="<?php echo htmlspecialchars($customer['noted_person_remarks'] ?? ''); ?>">
                                                            <i class="bi bi-star-fill"></i> Noted
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="bi bi-phone" style="color: #48bb78; margin-right: 5px;"></i>
                                            <a href="tel:<?php echo htmlspecialchars($customer['mobile_number']); ?>" style="color: #2d3748; text-decoration: none;">
                                                <?php echo htmlspecialchars($customer['mobile_number']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($customer['guardian_name'])): ?>
                                                <small style="color: #718096;"><?php echo htmlspecialchars($customer['guardian_type'] ?? ''); ?></small><br>
                                                <span><?php echo htmlspecialchars($customer['guardian_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <i class="bi bi-geo-alt" style="color: #ecc94b; margin-right: 5px;"></i>
                                            <?php 
                                            $location_parts = array_filter([
                                                $customer['location'] ?? '',
                                                $customer['district'] ?? ''
                                            ]);
                                            echo htmlspecialchars(implode(', ', $location_parts) ?: 'N/A');
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="loan-limit-info">
                                                <div class="loan-limit-text">
                                                    <i class="bi bi-pie-chart"></i>
                                                    <span>₹<?php echo number_format($loan_limit, 0); ?></span>
                                                </div>
                                                <div class="loan-limit-progress">
                                                    <div class="loan-limit-progress-bar <?php echo $progress_class; ?>" 
                                                         style="width: <?php echo min($limit_percentage, 100); ?>%"></div>
                                                </div>
                                                <small style="color: #718096;">
                                                    Used: ₹<?php echo number_format($active_amount, 0); ?> 
                                                    (<?php echo number_format($limit_percentage, 1); ?>%)
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <span class="loan-badge open" title="Open Loans"><?php echo $customer['open_loans']; ?> Open</span>
                                                <span class="loan-badge closed" title="Closed Loans"><?php echo $customer['closed_loans']; ?> Closed</span>
                                                <div style="margin-top: 4px; font-size: 12px; color: #718096;">Total: <?php echo $customer['total_loans']; ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: #48bb78;">₹<?php echo number_format($customer['total_active_loan_amount'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($customer['last_loan_date']): ?>
                                                <i class="bi bi-calendar" style="color: #718096; margin-right: 5px;"></i>
                                                <small><?php echo date('d-m-Y', strtotime($customer['last_loan_date'])); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_customer.php?id=<?php echo $customer['id']; ?>" class="btn-icon view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn-icon edit" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="New-Loan.php?customer_id=<?php echo $customer['id']; ?>" class="btn-icon loan" title="New Loan">
                                                    <i class="bi bi-plus-circle"></i>
                                                </a>
                                                <?php if ($is_noted): ?>
                                                    <span class="btn-icon noted" title="<?php echo htmlspecialchars($customer['noted_person_remarks'] ?? 'Noted Person'); ?>">
                                                        <i class="bi bi-star-fill"></i>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($customer['total_loans'] == 0): ?>
                                                    <a href="?action=delete&id=<?php echo $customer['id']; ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Are you sure you want to delete this customer? This action cannot be undone.')">
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
                                        <p>No customers found</p>
                                        <?php if (!empty($search) || !empty($loan_status)): ?>
                                            <a href="customers.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                                        <?php else: ?>
                                            <a href="New-Customer.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Customer</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards">
                    <?php if ($customers_result && mysqli_num_rows($customers_result) > 0): ?>
                        <?php mysqli_data_seek($customers_result, 0); ?>
                        <?php while ($customer = mysqli_fetch_assoc($customers_result)): 
                            $initials = '';
                            $name_parts = explode(' ', trim($customer['customer_name']));
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            
                            if ($customer['open_loans'] > 0) {
                                $status_class = 'active-loans';
                                $status_text = 'Active Loans';
                            } elseif ($customer['total_loans'] > 0) {
                                $status_class = 'has-loans';
                                $status_text = 'Has Loans';
                            } else {
                                $status_class = 'no-loans';
                                $status_text = 'No Loans';
                            }
                            
                            $loan_limit = floatval($customer['loan_limit_amount'] ?? 10000000);
                            $active_amount = floatval($customer['total_active_loan_amount'] ?? 0);
                            $limit_percentage = $loan_limit > 0 ? ($active_amount / $loan_limit) * 100 : 0;
                            
                            $progress_class = '';
                            if ($limit_percentage > 90) {
                                $progress_class = 'danger';
                            } elseif ($limit_percentage > 70) {
                                $progress_class = 'warning';
                            }
                            
                            $is_noted = isset($customer['is_noted_person']) && $customer['is_noted_person'] == 1;
                        ?>
                            <div class="customer-mobile-card <?php echo $is_noted ? 'noted' : ''; ?>">
                                <div class="mobile-card-header">
                                    <div class="mobile-title">
                                        <div class="customer-avatar <?php echo $is_noted ? 'noted' : ''; ?>" style="width: 40px; height: 40px; font-size: 14px;"><?php echo $initials; ?></div>
                                        <span class="mobile-name"><?php echo htmlspecialchars($customer['customer_name']); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <?php if ($is_noted): ?>
                                            <span class="mobile-noted-badge" title="<?php echo htmlspecialchars($customer['noted_person_remarks'] ?? ''); ?>">
                                                <i class="bi bi-star-fill"></i> Noted
                                            </span>
                                        <?php endif; ?>
                                        <span class="mobile-id">#<?php echo $customer['id']; ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Mobile</span>
                                        <span class="detail-value">
                                            <i class="bi bi-phone" style="color: #48bb78;"></i>
                                            <?php echo htmlspecialchars($customer['mobile_number']); ?>
                                        </span>
                                    </div>
                                    
                                    <?php if (!empty($customer['guardian_name'])): ?>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Guardian</span>
                                        <span class="detail-value">
                                            <?php echo htmlspecialchars($customer['guardian_type'] ?? ''); ?>: <?php echo htmlspecialchars($customer['guardian_name']); ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Location</span>
                                        <span class="detail-value">
                                            <i class="bi bi-geo-alt" style="color: #ecc94b;"></i>
                                            <?php echo htmlspecialchars($customer['location'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Status</span>
                                        <span class="status-badge <?php echo $status_class; ?>" style="display: inline-block;">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="mobile-loan-info">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                        <span class="detail-label">Loan Summary</span>
                                        <span><strong>Active: ₹<?php echo number_format($active_amount, 2); ?></strong></span>
                                    </div>
                                    <div>
                                        <span class="loan-badge open"><?php echo $customer['open_loans']; ?> Open</span>
                                        <span class="loan-badge closed"><?php echo $customer['closed_loans']; ?> Closed</span>
                                        <span style="margin-left: 8px; color: #718096;">Total: <?php echo $customer['total_loans']; ?></span>
                                    </div>
                                    
                                    <div class="mobile-loan-limit">
                                        <div class="loan-limit-text" style="margin-bottom: 5px;">
                                            <i class="bi bi-pie-chart"></i>
                                            <span>Loan Limit: ₹<?php echo number_format($loan_limit, 0); ?></span>
                                        </div>
                                        <div class="loan-limit-progress" style="width: 100%;">
                                            <div class="loan-limit-progress-bar <?php echo $progress_class; ?>" 
                                                 style="width: <?php echo min($limit_percentage, 100); ?>%"></div>
                                        </div>
                                        <small style="color: #718096; display: block; margin-top: 4px;">
                                            Used: <?php echo number_format($limit_percentage, 1); ?>% (₹<?php echo number_format($active_amount, 0); ?>)
                                        </small>
                                    </div>
                                    
                                    <?php if ($customer['last_loan_date']): ?>
                                        <div style="margin-top: 8px; font-size: 12px; color: #718096;">
                                            <i class="bi bi-calendar"></i> Last: <?php echo date('d-m-Y', strtotime($customer['last_loan_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mobile-actions">
                                    <a href="view_customer.php?id=<?php echo $customer['id']; ?>" class="btn-icon view" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn-icon edit" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="New-Loan.php?customer_id=<?php echo $customer['id']; ?>" class="btn-icon loan" title="New Loan">
                                        <i class="bi bi-plus-circle"></i>
                                    </a>
                                    <?php if ($is_noted): ?>
                                        <span class="btn-icon noted" title="<?php echo htmlspecialchars($customer['noted_person_remarks'] ?? 'Noted Person'); ?>">
                                            <i class="bi bi-star-fill"></i>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($customer['total_loans'] == 0): ?>
                                        <a href="?action=delete&id=<?php echo $customer['id']; ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Are you sure you want to delete this customer?')">
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
                            <?php if (!empty($search) || !empty($loan_status)): ?>
                                <a href="customers.php" class="btn btn-secondary" style="display: inline-block; padding: 10px 20px;">Clear Filters</a>
                            <?php else: ?>
                                <a href="New-Customer.php" class="btn btn-primary" style="display: inline-block; padding: 10px 20px;">Add New Customer</a>
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

<script>
    $(document).ready(function() {
        <?php if ($customers_result && mysqli_num_rows($customers_result) > 0): ?>
        $('#customersTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search customers...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [10] }
            ],
            initComplete: function() {
                $('.dataTables_filter input').addClass('filter-input');
                $('.dataTables_length select').addClass('filter-select');
            }
        });
        <?php endif; ?>
    });

    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>