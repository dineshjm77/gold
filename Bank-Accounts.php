<?php
session_start();
$currentPage = 'bank-accounts';
$pageTitle = 'Bank Accounts';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle form submission for adding/editing bank account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
    $is_edit = ($account_id > 0);
    
    // Get form data
    $account_holder_no = mysqli_real_escape_string($conn, trim($_POST['account_holder_no'] ?? ''));
    $bank_account_no = mysqli_real_escape_string($conn, trim($_POST['bank_account_no'] ?? ''));
    $bank_id = intval($_POST['bank_id'] ?? 0);
    $branch = mysqli_real_escape_string($conn, trim($_POST['branch'] ?? ''));
    $ifsc_code = mysqli_real_escape_string($conn, trim($_POST['ifsc_code'] ?? ''));
    $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
    $authorized_person = mysqli_real_escape_string($conn, trim($_POST['authorized_person'] ?? ''));
    $registered_mobile = mysqli_real_escape_string($conn, trim($_POST['registered_mobile'] ?? ''));
    $opening_balance = floatval($_POST['opening_balance'] ?? 0);
    $as_on_date = mysqli_real_escape_string($conn, $_POST['as_on_date'] ?? date('Y-m-d'));
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Validation
    $errors = [];
    
    if (empty($account_holder_no)) {
        $errors[] = "Account holder name/number is required";
    }
    if (empty($bank_account_no)) {
        $errors[] = "Bank account number is required";
    }
    if ($bank_id <= 0) {
        $errors[] = "Please select a bank";
    }
    if (empty($ifsc_code)) {
        $errors[] = "IFSC code is required";
    }
    if (empty($registered_mobile)) {
        $errors[] = "Registered mobile number is required";
    } elseif (!preg_match('/^[0-9]{10}$/', $registered_mobile)) {
        $errors[] = "Please enter a valid 10-digit mobile number";
    }
    
    // Check if account number already exists (for new accounts)
    if (empty($errors) && !$is_edit) {
        $check_query = "SELECT id FROM bank_accounts WHERE bank_account_no = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 's', $bank_account_no);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $errors[] = "Bank account number already exists";
        }
    }
    
    if (empty($errors)) {
        if ($is_edit) {
            $query = "UPDATE bank_accounts SET 
                account_holder_no = ?, bank_account_no = ?, bank_id = ?, 
                branch = ?, ifsc_code = ?, account_type = ?, 
                authorized_person = ?, registered_mobile = ?, 
                opening_balance = ?, as_on_date = ?, status = ?,
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssisssssdsii', 
                $account_holder_no, $bank_account_no, $bank_id,
                $branch, $ifsc_code, $account_type,
                $authorized_person, $registered_mobile,
                $opening_balance, $as_on_date, $status,
                $account_id
            );
            
            if (mysqli_stmt_execute($stmt)) {
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'update', 'Bank account updated: $account_holder_no', 'bank_accounts', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $account_id);
                mysqli_stmt_execute($log_stmt);
                
                $_SESSION['success_message'] = "Bank account updated successfully!";
                header('Location: bank-accounts.php');
                exit();
            } else {
                $error = "Error updating bank account: " . mysqli_error($conn);
            }
        } else {
            $query = "INSERT INTO bank_accounts (
                account_holder_no, bank_account_no, bank_id, branch, 
                ifsc_code, account_type, authorized_person, 
                registered_mobile, opening_balance, as_on_date, status,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssisssssdsi', 
                $account_holder_no, $bank_account_no, $bank_id,
                $branch, $ifsc_code, $account_type,
                $authorized_person, $registered_mobile,
                $opening_balance, $as_on_date, $status
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $new_id = mysqli_insert_id($conn);
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'create', 'New bank account created: $account_holder_no', 'bank_accounts', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $new_id);
                mysqli_stmt_execute($log_stmt);
                
                $_SESSION['success_message'] = "Bank account added successfully!";
                header('Location: bank-accounts.php');
                exit();
            } else {
                $error = "Error adding bank account: " . mysqli_error($conn);
            }
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $account_id = intval($_GET['id']);
    
    // Check if account has transactions
    $check_query = "SELECT COUNT(*) as count FROM bank_ledger WHERE bank_account_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 'i', $account_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));
    
    if ($check_result['count'] > 0) {
        $error = "Cannot delete bank account because it has transaction records.";
    } else {
        $delete_query = "DELETE FROM bank_accounts WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, 'i', $account_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'delete', 'Bank account deleted', 'bank_accounts', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $account_id);
            mysqli_stmt_execute($log_stmt);
            
            $_SESSION['success_message'] = "Bank account deleted successfully!";
        } else {
            $error = "Error deleting bank account: " . mysqli_error($conn);
        }
    }
    header('Location: bank-accounts.php');
    exit();
}

// Handle status toggle
if (isset($_GET['action']) && in_array($_GET['action'], ['activate', 'deactivate']) && isset($_GET['id'])) {
    $account_id = intval($_GET['id']);
    $new_status = ($_GET['action'] === 'activate') ? 1 : 0;
    
    $update_query = "UPDATE bank_accounts SET status = ? WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, 'ii', $new_status, $account_id);
    
    if (mysqli_stmt_execute($update_stmt)) {
        $action_text = ($new_status == 1) ? 'activated' : 'deactivated';
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                      VALUES (?, '$action_text', 'Bank account $action_text', 'bank_accounts', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $account_id);
        mysqli_stmt_execute($log_stmt);
        
        $_SESSION['success_message'] = "Bank account $action_text successfully!";
    }
    header('Location: bank-accounts.php');
    exit();
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$bank_filter = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$type_filter = isset($_GET['account_type']) ? mysqli_real_escape_string($conn, $_GET['account_type']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build query for bank accounts with bank details
$query = "SELECT a.*, b.bank_full_name, b.bank_short_name 
          FROM bank_accounts a 
          LEFT JOIN bank_master b ON a.bank_id = b.id 
          WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (a.account_holder_no LIKE '%$search%' OR a.bank_account_no LIKE '%$search%' OR a.ifsc_code LIKE '%$search%')";
}
if ($bank_filter > 0) {
    $query .= " AND a.bank_id = $bank_filter";
}
if (!empty($type_filter)) {
    $query .= " AND a.account_type = '$type_filter'";
}
if (!empty($status_filter)) {
    $query .= " AND a.status = " . ($status_filter === 'active' ? '1' : '0');
}
$query .= " ORDER BY a.created_at DESC";

$accounts_result = mysqli_query($conn, $query);

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get account types
$account_types = ['savings', 'current', 'salary', 'fixed_deposit', 'recurring_deposit', 'nri', 'other'];

// Get statistics
$total_accounts = mysqli_query($conn, "SELECT COUNT(*) as count FROM bank_accounts")->fetch_assoc()['count'] ?? 0;
$active_accounts = mysqli_query($conn, "SELECT COUNT(*) as count FROM bank_accounts WHERE status = 1")->fetch_assoc()['count'] ?? 0;
$total_balance = mysqli_query($conn, "SELECT SUM(opening_balance) as total FROM bank_accounts WHERE status = 1")->fetch_assoc()['total'] ?? 0;
$savings_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM bank_accounts WHERE account_type = 'savings'")->fetch_assoc()['count'] ?? 0;
$current_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM bank_accounts WHERE account_type = 'current'")->fetch_assoc()['count'] ?? 0;

// Get account for editing if ID is provided
$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$edit_data = [];

if ($edit_id > 0) {
    $edit_query = "SELECT * FROM bank_accounts WHERE id = ?";
    $edit_stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($edit_stmt, 'i', $edit_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_data = mysqli_fetch_assoc($edit_result);
}

// Check for success messages from session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #48bb78;
            --success-dark: #38a169;
            --danger: #f56565;
            --danger-dark: #c53030;
            --warning: #ecc94b;
            --warning-dark: #d69e2e;
            --info: #4299e1;
            --info-dark: #3182ce;
            --dark: #2d3748;
            --light: #f7fafc;
            --gray: #a0aec0;
            --gray-dark: #718096;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.95);
        }

        .main-content {
            flex: 1;
            background: #f8fafc;
        }

        .page-content {
            padding: 30px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
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
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            box-shadow: 0 4px 10px rgba(102,126,234,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(102,126,234,0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(72,187,120,0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white;
        }

        .btn-secondary {
            background: white;
            border: 2px solid var(--gray);
            color: var(--gray-dark);
        }

        .btn-secondary:hover {
            background: var(--gray);
            color: white;
        }

        /* Alert */
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
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
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

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102,126,234,0.15);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            color: var(--gray-dark);
            font-size: 0.9rem;
        }

        /* Form Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            overflow-y: auto;
            padding: 20px;
        }

        .modal-content {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray);
        }

        .modal-close:hover {
            color: var(--danger);
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

        .form-group.full-width {
            grid-column: 1 / -1;
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
            color: var(--danger);
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
            padding: 12px 12px 12px 40px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
            background: white;
        }

        /* Filters Section */
        .filters-section {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .filter-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .filter-input, .filter-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            background: #f8fafc;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }

        .filter-btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            cursor: pointer;
            height: 44px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(102,126,234,0.3);
        }

        .filter-btn-reset {
            background: #e2e8f0;
            color: #4a5568;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.2);
            overflow: auto;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table thead th {
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 10px;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .table tbody td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 10px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s;
        }

        .table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.002);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        /* Status Badge */
        .status-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: rgba(72,187,120,0.1);
            color: var(--success);
        }

        .status-badge.inactive {
            background: rgba(245,101,101,0.1);
            color: var(--danger);
        }

        /* Type Badge */
        .type-badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            background: rgba(102,126,234,0.1);
            color: var(--primary);
            text-transform: uppercase;
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
            background: linear-gradient(135deg, var(--info) 0%, var(--info-dark) 100%);
            color: white;
        }

        .btn-icon.edit:hover {
            background: linear-gradient(135deg, var(--warning) 0%, var(--warning-dark) 100%);
            color: white;
        }

        .btn-icon.activate:hover {
            background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%);
            color: white;
        }

        .btn-icon.deactivate:hover {
            background: linear-gradient(135deg, var(--gray) 0%, var(--gray-dark) 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-dark);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 15px;
            color: #cbd5e0;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 15px;
        }

        /* Mobile Responsive */
        @media (max-width: 992px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .filter-btn {
                grid-column: span 2;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .filter-form {
                grid-template-columns: 1fr;
            }
            .filter-btn {
                grid-column: span 1;
            }
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
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
                <div class="container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-bank2"></i>
                            Bank Accounts Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="openAddModal()">
                                <i class="bi bi-plus-lg"></i> Add New Account
                            </button>
                            <a href="bank-master.php" class="btn btn-secondary ms-2">
                                <i class="bi bi-bank"></i> Bank Master
                            </a>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bank2"></i>
                            </div>
                            <div class="stat-value"><?php echo $total_accounts; ?></div>
                            <div class="stat-label">Total Accounts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="color: var(--success);">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-value"><?php echo $active_accounts; ?></div>
                            <div class="stat-label">Active Accounts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="color: var(--info);">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                            <div class="stat-value">₹<?php echo number_format($total_balance); ?></div>
                            <div class="stat-label">Total Balance</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="color: var(--warning);">
                                <i class="bi bi-piggy-bank"></i>
                            </div>
                            <div class="stat-value"><?php echo $savings_count; ?></div>
                            <div class="stat-label">Savings Accounts</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon" style="color: var(--primary);">
                                <i class="bi bi-briefcase"></i>
                            </div>
                            <div class="stat-value"><?php echo $current_count; ?></div>
                            <div class="stat-label">Current Accounts</div>
                        </div>
                    </div>

                    <!-- Filters Section -->
                    <div class="filters-section">
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-form">
                                <div class="filter-group">
                                    <label class="filter-label">Search</label>
                                    <input type="text" class="filter-input" name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Search by holder, account no, IFSC...">
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Bank</label>
                                    <select class="filter-select" name="bank_id">
                                        <option value="">All Banks</option>
                                        <?php 
                                        mysqli_data_seek($banks_result, 0);
                                        while($bank = mysqli_fetch_assoc($banks_result)): 
                                        ?>
                                            <option value="<?php echo $bank['id']; ?>" 
                                                <?php echo $bank_filter == $bank['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Account Type</label>
                                    <select class="filter-select" name="account_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($account_types as $type): ?>
                                            <option value="<?php echo $type; ?>" 
                                                <?php echo $type_filter === $type ? 'selected' : ''; ?>>
                                                <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label class="filter-label">Status</label>
                                    <select class="filter-select" name="status">
                                        <option value="">All Status</option>
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" class="filter-btn">
                                        <i class="bi bi-funnel"></i> Apply
                                    </button>
                                    <a href="bank-accounts.php" class="filter-btn filter-btn-reset">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Bank Accounts Table -->
                    <div class="table-card">
                        <table class="table" id="accountsTable">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Account Holder</th>
                                    <th>Bank Details</th>
                                    <th>Account No</th>
                                    <th>IFSC Code</th>
                                    <th>Type</th>
                                    <th>Balance (₹)</th>
                                    <th>As on Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($accounts_result) > 0): ?>
                                    <?php $sno = 1; ?>
                                    <?php while ($account = mysqli_fetch_assoc($accounts_result)): ?>
                                        <tr>
                                            <td><?php echo $sno++; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($account['account_holder_no']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($account['authorized_person']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($account['bank_full_name'] ?? $account['bank_short_name'] ?? 'N/A'); ?></strong><br>
                                                <small><?php echo htmlspecialchars($account['branch']); ?></small>
                                            </td>
                                            <td>
                                                <span class="type-badge">
                                                    <?php echo 'XXXX' . substr($account['bank_account_no'], -4); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($account['ifsc_code']); ?></td>
                                            <td>
                                                <span class="type-badge">
                                                    <?php echo ucfirst(str_replace('_', ' ', $account['account_type'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong>₹<?php echo number_format($account['opening_balance'], 2); ?></strong><br>
                                                <small class="text-muted">Mobile: <?php echo $account['registered_mobile']; ?></small>
                                            </td>
                                            <td><?php echo date('d-m-Y', strtotime($account['as_on_date'])); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $account['status'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $account['status'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="bank-ledger.php?account_id=<?php echo $account['id']; ?>" 
                                                       class="btn-icon view" title="View Ledger">
                                                        <i class="bi bi-journal-bookmark-fill"></i>
                                                    </a>
                                                    <a href="?edit=<?php echo $account['id']; ?>" 
                                                       class="btn-icon edit" title="Edit" onclick="openEditModal(event, <?php echo $account['id']; ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <?php if ($account['status']): ?>
                                                        <a href="?action=deactivate&id=<?php echo $account['id']; ?>" 
                                                           class="btn-icon deactivate" title="Deactivate" 
                                                           onclick="return confirmAction('deactivate', <?php echo $account['id']; ?>, '<?php echo addslashes($account['account_holder_no']); ?>')">
                                                            <i class="bi bi-pause-circle"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="?action=activate&id=<?php echo $account['id']; ?>" 
                                                           class="btn-icon activate" title="Activate" 
                                                           onclick="return confirmAction('activate', <?php echo $account['id']; ?>, '<?php echo addslashes($account['account_holder_no']); ?>')">
                                                            <i class="bi bi-play-circle"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="?action=delete&id=<?php echo $account['id']; ?>" 
                                                       class="btn-icon delete" title="Delete" 
                                                       onclick="return confirmDelete(<?php echo $account['id']; ?>, '<?php echo addslashes($account['account_holder_no']); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" class="empty-state">
                                            <i class="bi bi-bank2"></i>
                                            <p>No bank accounts found</p>
                                            <button class="btn btn-primary" onclick="openAddModal()">
                                                <i class="bi bi-plus-lg"></i> Add Your First Account
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add/Edit Bank Account Modal -->
    <div id="accountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add New Bank Account</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="accountForm">
                <input type="hidden" name="account_id" id="account_id" value="<?php echo $edit_id; ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Account Holder Name/No</label>
                        <div class="input-group">
                            <i class="bi bi-person input-icon"></i>
                            <input type="text" class="form-control" name="account_holder_no" 
                                   id="account_holder_no" required
                                   value="<?php echo htmlspecialchars($edit_data['account_holder_no'] ?? ''); ?>"
                                   placeholder="Enter account holder name">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Bank</label>
                        <div class="input-group">
                            <i class="bi bi-bank input-icon"></i>
                            <select class="form-select" name="bank_id" id="bank_id" required>
                                <option value="">Select Bank</option>
                                <?php 
                                mysqli_data_seek($banks_result, 0);
                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                ?>
                                    <option value="<?php echo $bank['id']; ?>" 
                                        <?php echo (($edit_data['bank_id'] ?? 0) == $bank['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Account Number</label>
                        <div class="input-group">
                            <i class="bi bi-credit-card input-icon"></i>
                            <input type="text" class="form-control" name="bank_account_no" 
                                   id="bank_account_no" required
                                   value="<?php echo htmlspecialchars($edit_data['bank_account_no'] ?? ''); ?>"
                                   placeholder="Enter account number">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">IFSC Code</label>
                        <div class="input-group">
                            <i class="bi bi-upc-scan input-icon"></i>
                            <input type="text" class="form-control" name="ifsc_code" 
                                   id="ifsc_code" required style="text-transform: uppercase;"
                                   value="<?php echo htmlspecialchars($edit_data['ifsc_code'] ?? ''); ?>"
                                   placeholder="Enter IFSC code" maxlength="11">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <div class="input-group">
                            <i class="bi bi-diagram-3 input-icon"></i>
                            <input type="text" class="form-control" name="branch" 
                                   id="branch" value="<?php echo htmlspecialchars($edit_data['branch'] ?? ''); ?>"
                                   placeholder="Enter branch name">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Account Type</label>
                        <div class="input-group">
                            <i class="bi bi-tag input-icon"></i>
                            <select class="form-select" name="account_type" id="account_type">
                                <?php foreach ($account_types as $type): ?>
                                    <option value="<?php echo $type; ?>" 
                                        <?php echo (($edit_data['account_type'] ?? 'savings') == $type) ? 'selected' : ''; ?>>
                                        <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Authorized Person</label>
                        <div class="input-group">
                            <i class="bi bi-person-badge input-icon"></i>
                            <input type="text" class="form-control" name="authorized_person" 
                                   id="authorized_person" value="<?php echo htmlspecialchars($edit_data['authorized_person'] ?? ''); ?>"
                                   placeholder="Enter authorized person name">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Registered Mobile</label>
                        <div class="input-group">
                            <i class="bi bi-phone input-icon"></i>
                            <input type="tel" class="form-control" name="registered_mobile" 
                                   id="registered_mobile" required maxlength="10"
                                   value="<?php echo htmlspecialchars($edit_data['registered_mobile'] ?? ''); ?>"
                                   placeholder="Enter 10-digit mobile number">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Opening Balance (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-currency-rupee input-icon"></i>
                            <input type="number" class="form-control" name="opening_balance" 
                                   id="opening_balance" step="0.01" min="0"
                                   value="<?php echo htmlspecialchars($edit_data['opening_balance'] ?? '0'); ?>"
                                   placeholder="Enter opening balance">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">As on Date</label>
                        <div class="input-group">
                            <i class="bi bi-calendar input-icon"></i>
                            <input type="date" class="form-control" name="as_on_date" 
                                   id="as_on_date" value="<?php echo htmlspecialchars($edit_data['as_on_date'] ?? date('Y-m-d')); ?>">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="status" 
                                   id="status" value="1" <?php echo (!isset($edit_data['status']) || $edit_data['status'] == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="status">Active</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- jQuery and DataTables -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Initialize DataTable
        $(document).ready(function() {
            <?php if (mysqli_num_rows($accounts_result) > 0): ?>
            $('#accountsTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search accounts...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ accounts",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [9] }
                ]
            });
            <?php endif; ?>

            // Auto-submit filters on change
            $('#bank_id, #account_type, #status').change(function() {
                $('#filterForm').submit();
            });

            // Auto-convert IFSC to uppercase
            $('#ifsc_code').on('input', function() {
                this.value = this.value.toUpperCase();
            });

            // IFSC Auto-fill (optional - you can implement if needed)
            $('#ifsc_code').on('blur', function() {
                const ifsc = this.value.trim().toUpperCase();
                if (ifsc.length === 11) {
                    // You can implement IFSC lookup here if needed
                    console.log('IFSC entered:', ifsc);
                }
            });
        });

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Bank Account';
            document.getElementById('account_id').value = '0';
            document.getElementById('accountForm').reset();
            document.getElementById('as_on_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('status').checked = true;
            document.getElementById('accountModal').style.display = 'block';
        }

        function openEditModal(event, id) {
            event.preventDefault();
            // The edit is handled by PHP redirect, but we can also use AJAX
            window.location.href = 'bank-accounts.php?edit=' + id;
        }

        function closeModal() {
            document.getElementById('accountModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('accountModal');
            if (event.target == modal) {
                closeModal();
            }
        }

        // Confirmation functions
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
                title: 'Delete Bank Account?',
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

        // Auto-hide alerts
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);

        // Show modal if edit parameter exists
        <?php if ($edit_id > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('modalTitle').textContent = 'Edit Bank Account';
            document.getElementById('accountModal').style.display = 'block';
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>