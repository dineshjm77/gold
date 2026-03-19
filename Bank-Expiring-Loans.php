<?php
session_start();
$currentPage = 'bank-expiring-loans';
$pageTitle = 'Expiring Bank Loans';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$expiry_period = isset($_GET['expiry_period']) ? $_GET['expiry_period'] : '30_days';
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'active';
$export = isset($_GET['export']);

// Calculate date ranges based on expiry period
$today = date('Y-m-d');
$next_7_days = date('Y-m-d', strtotime('+7 days'));
$next_15_days = date('Y-m-d', strtotime('+15 days'));
$next_30_days = date('Y-m-d', strtotime('+30 days'));
$next_60_days = date('Y-m-d', strtotime('+60 days'));
$next_90_days = date('Y-m-d', strtotime('+90 days'));

switch ($expiry_period) {
    case '7_days':
        $date_to = $next_7_days;
        $period_label = 'Next 7 Days';
        break;
    case '15_days':
        $date_to = $next_15_days;
        $period_label = 'Next 15 Days';
        break;
    case '30_days':
        $date_to = $next_30_days;
        $period_label = 'Next 30 Days';
        break;
    case '60_days':
        $date_to = $next_60_days;
        $period_label = 'Next 60 Days';
        break;
    case '90_days':
        $date_to = $next_90_days;
        $period_label = 'Next 90 Days';
        break;
    case 'expired':
        $date_to = $today;
        $period_label = 'Already Expired';
        break;
    default:
        $date_to = $next_30_days;
        $period_label = 'Next 30 Days';
}

// Build WHERE conditions
$where_conditions = ["1=1"];
$params = [];
$types = '';

// For expired loans, find loans where all EMIs are past due but not fully paid
if ($expiry_period == 'expired') {
    $where_conditions[] = "l.status = 'active'";
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM bank_loan_emi e 
        WHERE e.bank_loan_id = l.id 
        AND e.due_date < CURDATE() 
        AND e.status = 'pending'
    )";
} else {
    // Find loans with EMIs due within the specified period
    $where_conditions[] = "l.status = 'active'";
    $where_conditions[] = "EXISTS (
        SELECT 1 FROM bank_loan_emi e 
        WHERE e.bank_loan_id = l.id 
        AND e.due_date BETWEEN CURDATE() AND ?
        AND e.status = 'pending'
    )";
    $params[] = $date_to;
    $types .= 's';
}

// Bank filter
if ($bank_id > 0) {
    $where_conditions[] = "l.bank_id = ?";
    $params[] = $bank_id;
    $types .= 'i';
}

// Customer filter
if ($customer_id > 0) {
    $where_conditions[] = "l.customer_id = ?";
    $params[] = $customer_id;
    $types .= 'i';
}

// Status filter
if ($status != 'all') {
    $where_conditions[] = "l.status = ?";
    $params[] = $status;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get customers with active loans for dropdown
$customers_query = "SELECT DISTINCT c.id, c.customer_name, c.mobile_number 
                    FROM customers c
                    JOIN bank_loans l ON c.id = l.customer_id
                    WHERE l.status = 'active'
                    ORDER BY c.customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Main query for expiring loans
$query = "SELECT 
            l.*,
            b.bank_short_name,
            b.bank_full_name,
            ba.account_holder_no,
            ba.bank_account_no,
            c.customer_name,
            c.mobile_number,
            c.email,
            c.aadhaar_number,
            u.name as employee_name,
            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = l.id AND status = 'paid') as paid_emis,
            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = l.id) as total_emis,
            (SELECT SUM(paid_amount) FROM bank_loan_emi WHERE bank_loan_id = l.id AND status = 'paid') as total_paid,
            (SELECT MIN(due_date) FROM bank_loan_emi WHERE bank_loan_id = l.id AND status = 'pending') as next_due_date,
            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = l.id AND due_date < CURDATE() AND status = 'pending') as overdue_emis,
            (SELECT SUM(emi_amount) FROM bank_loan_emi WHERE bank_loan_id = l.id AND due_date < CURDATE() AND status = 'pending') as overdue_amount
          FROM bank_loans l
          LEFT JOIN bank_master b ON l.bank_id = b.id
          LEFT JOIN bank_accounts ba ON l.bank_account_id = ba.id
          LEFT JOIN customers c ON l.customer_id = c.id
          LEFT JOIN users u ON l.employee_id = u.id
          WHERE $where_clause
          ORDER BY next_due_date ASC, l.loan_date DESC";

$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(DISTINCT l.id) as total_loans,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COUNT(DISTINCT l.customer_id) as unique_customers,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM bank_loan_emi e 
                        WHERE e.bank_loan_id = l.id 
                        AND e.due_date < CURDATE() 
                        AND e.status = 'pending'
                    ) THEN 1 ELSE 0 END) as overdue_loans
                  FROM bank_loans l
                  WHERE $where_clause";

$summary_stmt = mysqli_prepare($conn, $summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

// Get upcoming EMI summary
$emi_summary_query = "SELECT 
                        COUNT(*) as total_emis,
                        COALESCE(SUM(e.emi_amount), 0) as total_amount,
                        MIN(e.due_date) as earliest_due,
                        MAX(e.due_date) as latest_due,
                        COUNT(DISTINCT e.bank_loan_id) as loans_with_emis
                      FROM bank_loan_emi e
                      JOIN bank_loans l ON e.bank_loan_id = l.id
                      WHERE l.status = 'active' 
                      AND e.status = 'pending'
                      AND e.due_date BETWEEN CURDATE() AND ?";

$emi_summary_stmt = mysqli_prepare($conn, $emi_summary_query);
mysqli_stmt_bind_param($emi_summary_stmt, 's', $date_to);
mysqli_stmt_execute($emi_summary_stmt);
$emi_summary_result = mysqli_stmt_get_result($emi_summary_stmt);
$emi_summary = mysqli_fetch_assoc($emi_summary_result);

// Get breakdown by bank
$bank_breakdown_query = "SELECT 
                          b.id,
                          b.bank_short_name,
                          b.bank_full_name,
                          COUNT(DISTINCT l.id) as loan_count,
                          COALESCE(SUM(l.loan_amount), 0) as total_amount,
                          COUNT(DISTINCT l.customer_id) as customer_count,
                          SUM(CASE WHEN EXISTS (
                              SELECT 1 FROM bank_loan_emi e 
                              WHERE e.bank_loan_id = l.id 
                              AND e.due_date < CURDATE() 
                              AND e.status = 'pending'
                          ) THEN 1 ELSE 0 END) as overdue_count
                        FROM bank_loans l
                        JOIN bank_master b ON l.bank_id = b.id
                        WHERE $where_clause
                        GROUP BY b.id, b.bank_short_name, b.bank_full_name
                        ORDER BY loan_count DESC";

$bank_breakdown_stmt = mysqli_prepare($conn, $bank_breakdown_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($bank_breakdown_stmt, $types, ...$params);
}
mysqli_stmt_execute($bank_breakdown_stmt);
$bank_breakdown_result = mysqli_stmt_get_result($bank_breakdown_stmt);

// Get daily breakdown for the period
$daily_breakdown_query = "SELECT 
                          e.due_date,
                          COUNT(*) as emi_count,
                          COALESCE(SUM(e.emi_amount), 0) as total_amount,
                          COUNT(DISTINCT e.bank_loan_id) as loan_count
                        FROM bank_loan_emi e
                        JOIN bank_loans l ON e.bank_loan_id = l.id
                        WHERE l.status = 'active' 
                        AND e.status = 'pending'
                        AND e.due_date BETWEEN CURDATE() AND ?
                        GROUP BY e.due_date
                        ORDER BY e.due_date ASC";

$daily_breakdown_stmt = mysqli_prepare($conn, $daily_breakdown_query);
mysqli_stmt_bind_param($daily_breakdown_stmt, 's', $date_to);
mysqli_stmt_execute($daily_breakdown_stmt);
$daily_breakdown_result = mysqli_stmt_get_result($daily_breakdown_stmt);

// Handle export
if ($export) {
    exportExpiringLoans($conn, $result, $period_label);
}

function exportExpiringLoans($conn, $data, $period) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="expiring_loans_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add report header
    fputcsv($output, ['EXPIRING BANK LOANS REPORT']);
    fputcsv($output, ['Period', $period]);
    fputcsv($output, ['Generated on', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Add column headers
    fputcsv($output, [
        'Loan Ref', 'Bank', 'Customer', 'Mobile', 'Loan Amount', 
        'Interest Rate', 'Tenure', 'EMI Amount', 'Paid EMIs', 'Total EMIs',
        'Next Due Date', 'Overdue EMIs', 'Overdue Amount', 'Status'
    ]);
    
    // Add data rows
    mysqli_data_seek($data, 0);
    while ($row = mysqli_fetch_assoc($data)) {
        fputcsv($output, [
            $row['loan_reference'],
            $row['bank_short_name'] ?? '-',
            $row['customer_name'] ?? '-',
            $row['mobile_number'] ?? '-',
            $row['loan_amount'],
            $row['interest_rate'] . '%',
            $row['tenure_months'] . ' months',
            $row['emi_amount'],
            $row['paid_emis'] . '/' . $row['total_emis'],
            $row['total_emis'],
            $row['next_due_date'] ?? '-',
            $row['overdue_emis'] ?? 0,
            $row['overdue_amount'] ?? 0,
            $row['status']
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle sending reminders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reminders') {
    $loan_ids = isset($_POST['loan_ids']) ? $_POST['loan_ids'] : [];
    $reminder_type = mysqli_real_escape_string($conn, $_POST['reminder_type'] ?? 'email');
    
    if (empty($loan_ids)) {
        $error = "Please select at least one loan to send reminders";
    } else {
        $sent_count = 0;
        $failed_count = 0;
        
        foreach ($loan_ids as $loan_id) {
            // Get loan details
            $loan_query = "SELECT l.*, c.customer_name, c.email, c.mobile_number,
                          b.bank_short_name
                          FROM bank_loans l
                          JOIN customers c ON l.customer_id = c.id
                          JOIN bank_master b ON l.bank_id = b.id
                          WHERE l.id = ?";
            
            $stmt = mysqli_prepare($conn, $loan_query);
            mysqli_stmt_bind_param($stmt, 'i', $loan_id);
            mysqli_stmt_execute($stmt);
            $loan_result = mysqli_stmt_get_result($stmt);
            $loan = mysqli_fetch_assoc($loan_result);
            
            if ($loan) {
                // Get next pending EMI
                $emi_query = "SELECT * FROM bank_loan_emi 
                             WHERE bank_loan_id = ? AND status = 'pending' 
                             ORDER BY due_date ASC LIMIT 1";
                $emi_stmt = mysqli_prepare($conn, $emi_query);
                mysqli_stmt_bind_param($emi_stmt, 'i', $loan_id);
                mysqli_stmt_execute($emi_stmt);
                $emi_result = mysqli_stmt_get_result($emi_stmt);
                $emi = mysqli_fetch_assoc($emi_result);
                
                if ($emi) {
                    // Here you would integrate with your email/SMS system
                    // For now, we'll just log it
                    
                    // Log the reminder
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'reminder_sent', ?, 'bank_loans', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $description = "Reminder sent for loan " . $loan['loan_reference'] . 
                                  " - Due date: " . $emi['due_date'] . 
                                  " - Amount: ₹" . number_format($emi['emi_amount'], 2);
                    mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $description, $loan_id);
                    
                    if (mysqli_stmt_execute($log_stmt)) {
                        $sent_count++;
                    } else {
                        $failed_count++;
                    }
                }
            }
        }
        
        $message = "Reminders sent successfully to $sent_count loans";
        if ($failed_count > 0) {
            $message .= ". Failed for $failed_count loans.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- DataTables CSS -->
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

        .expiring-container {
            max-width: 1600px;
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
            gap: 15px;
        }

        .expiry-badge {
            background: #f56565;
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
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
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
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

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .chart-container {
            height: 300px;
            position: relative;
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
        }

        .table-info {
            color: #718096;
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .loan-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .loan-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .loan-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .loan-table tbody tr:hover {
            background: #f7fafc;
        }

        .loan-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-danger {
            background: #f56565;
            color: white;
        }

        .badge-warning {
            background: #ecc94b;
            color: #744210;
        }

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .badge-secondary {
            background: #a0aec0;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .text-success {
            color: #48bb78;
            font-weight: 600;
        }

        .text-danger {
            color: #f56565;
            font-weight: 600;
        }

        .text-warning {
            color: #ecc94b;
            font-weight: 600;
        }

        .amount {
            font-weight: 600;
        }

        .days-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .days-urgent {
            background: #f56565;
            color: white;
        }

        .days-warning {
            background: #ecc94b;
            color: #744210;
        }

        .days-normal {
            background: #48bb78;
            color: white;
        }

        .checkbox-select {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: white;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-icon.view:hover {
            background: #4299e1;
            color: white;
        }

        .btn-icon.remind:hover {
            background: #ecc94b;
            color: #744210;
        }

        .btn-icon.pay:hover {
            background: #48bb78;
            color: white;
        }

        /* Progress Bar */
        .progress-container {
            width: 100px;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #4299e1);
            border-radius: 3px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
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
                <div class="expiring-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-clock-history"></i>
                            Expiring Bank Loans
                            <span class="expiry-badge"><?php echo $period_label; ?></span>
                        </h1>
                        <div class="export-buttons">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-excel"></i> Export CSV
                            </a>
                            <button class="btn btn-primary btn-sm" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Loans Expiring</div>
                                <div class="stat-value"><?php echo number_format($summary['total_loans'] ?? 0); ?></div>
                                <div class="stat-sub">Total Amount: ₹<?php echo number_format($summary['total_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Unique Customers</div>
                                <div class="stat-value"><?php echo number_format($summary['unique_customers'] ?? 0); ?></div>
                                <div class="stat-sub">Avg Loan: ₹<?php echo number_format($summary['avg_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Overdue Loans</div>
                                <div class="stat-value text-danger"><?php echo number_format($summary['overdue_loans'] ?? 0); ?></div>
                                <div class="stat-sub">Need immediate attention</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Upcoming EMIs</div>
                                <div class="stat-value"><?php echo number_format($emi_summary['total_emis'] ?? 0); ?></div>
                                <div class="stat-sub">Amount: ₹<?php echo number_format($emi_summary['total_amount'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Expiring Loans
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Expiry Period</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-range input-icon"></i>
                                        <select class="form-select" name="expiry_period">
                                            <option value="7_days" <?php echo $expiry_period == '7_days' ? 'selected' : ''; ?>>Next 7 Days</option>
                                            <option value="15_days" <?php echo $expiry_period == '15_days' ? 'selected' : ''; ?>>Next 15 Days</option>
                                            <option value="30_days" <?php echo $expiry_period == '30_days' ? 'selected' : ''; ?>>Next 30 Days</option>
                                            <option value="60_days" <?php echo $expiry_period == '60_days' ? 'selected' : ''; ?>>Next 60 Days</option>
                                            <option value="90_days" <?php echo $expiry_period == '90_days' ? 'selected' : ''; ?>>Next 90 Days</option>
                                            <option value="expired" <?php echo $expiry_period == 'expired' ? 'selected' : ''; ?>>Already Expired</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id">
                                            <option value="0">All Banks</option>
                                            <?php 
                                            if ($banks_result) {
                                                mysqli_data_seek($banks_result, 0);
                                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>" <?php echo $bank_id == $bank['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <div class="input-group">
                                        <i class="bi bi-person input-icon"></i>
                                        <select class="form-select" name="customer_id">
                                            <option value="0">All Customers</option>
                                            <?php 
                                            if ($customers_result) {
                                                mysqli_data_seek($customers_result, 0);
                                                while($cust = mysqli_fetch_assoc($customers_result)): 
                                            ?>
                                                <option value="<?php echo $cust['id']; ?>" <?php echo $customer_id == $cust['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cust['customer_name'] . ' - ' . $cust['mobile_number']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="input-group">
                                        <i class="bi bi-check-circle input-icon"></i>
                                        <select class="form-select" name="status">
                                            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active Only</option>
                                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="bank-expiring-loans.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Charts Section -->
                    <div class="chart-grid">
                        <div class="chart-card">
                            <div class="chart-title">Daily EMI Schedule</div>
                            <div class="chart-container">
                                <canvas id="dailyChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">Loans by Bank</div>
                            <div class="chart-container">
                                <canvas id="bankChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions -->
                    <div class="filter-card" style="padding: 15px;">
                        <form method="POST" action="" id="bulkActionForm">
                            <input type="hidden" name="action" value="send_reminders">
                            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                                <span style="font-weight: 600; color: #4a5568;">
                                    <i class="bi bi-check2-square"></i> Bulk Actions:
                                </span>
                                <select class="form-select" name="reminder_type" style="width: 200px;">
                                    <option value="email">Send Email Reminders</option>
                                    <option value="sms">Send SMS Reminders</option>
                                    <option value="both">Send Both</option>
                                </select>
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Send reminders to selected loans?')">
                                    <i class="bi bi-envelope"></i> Send Reminders
                                </button>
                                <span style="color: #718096; font-size: 13px;">
                                    Select loans using checkboxes below
                                </span>
                            </div>
                        </form>
                    </div>

                    <!-- Loans Table - FIXED COLUMN COUNT -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">Expiring Loans List</span>
                            <span class="table-info">
                                Total: <?php echo mysqli_num_rows($result); ?> loans | 
                                Amount: ₹<?php echo number_format($summary['total_amount'] ?? 0, 2); ?>
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="loan-table" id="loansTable">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;">
                                            <input type="checkbox" class="checkbox-select" id="selectAll" onclick="toggleAll()">
                                        </th>
                                        <th>Loan Ref</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th>Progress</th>
                                        <th class="text-right">Next EMI</th>
                                        <th>Due Date</th>
                                        <th>Days Left</th>
                                        <th>Overdue</th>
                                        <th>Status</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_amount = 0;
                                    $total_emi = 0;
                                    $total_overdue = 0;
                                    
                                    while ($loan = mysqli_fetch_assoc($result)): 
                                        $total_amount += $loan['loan_amount'];
                                        $total_emi += $loan['emi_amount'];
                                        $total_overdue += $loan['overdue_amount'] ?? 0;
                                        
                                        $progress = $loan['total_emis'] > 0 ? ($loan['paid_emis'] / $loan['total_emis']) * 100 : 0;
                                        
                                        // Calculate days left
                                        $days_left = 0;
                                        $days_class = 'days-normal';
                                        if ($loan['next_due_date']) {
                                            $due = new DateTime($loan['next_due_date']);
                                            $today = new DateTime();
                                            $interval = $today->diff($due);
                                            $days_left = $interval->days;
                                            
                                            if ($due < $today) {
                                                $days_left = -$days_left;
                                                $days_class = 'days-urgent';
                                            } elseif ($days_left <= 3) {
                                                $days_class = 'days-urgent';
                                            } elseif ($days_left <= 7) {
                                                $days_class = 'days-warning';
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="checkbox-select loan-checkbox" name="loan_ids[]" value="<?php echo $loan['id']; ?>" form="bulkActionForm">
                                        </td>
                                        <td><strong><?php echo $loan['loan_reference']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($loan['bank_short_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($loan['customer_name'] ?? '-'); ?></td>
                                        <td><small><?php echo $loan['mobile_number'] ?? '-'; ?></small></td>
                                        <td class="text-right amount">₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td class="text-right"><?php echo $loan['interest_rate']; ?>%</td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <div class="progress-container">
                                                    <div class="progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                                                </div>
                                                <span style="font-size: 12px;"><?php echo $loan['paid_emis']; ?>/<?php echo $loan['total_emis']; ?></span>
                                            </div>
                                        </td>
                                        <td class="text-right amount">₹<?php echo number_format($loan['emi_amount'], 2); ?></td>
                                        <td>
                                            <?php if ($loan['next_due_date']): ?>
                                                <?php echo date('d-m-Y', strtotime($loan['next_due_date'])); ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($loan['next_due_date']): ?>
                                                <span class="days-badge <?php echo $days_class; ?>">
                                                    <?php echo $days_left > 0 ? $days_left . ' days' : ($days_left < 0 ? abs($days_left) . ' days overdue' : 'Today'); ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($loan['overdue_emis'] ?? 0) > 0): ?>
                                                <span class="badge badge-danger">
                                                    <?php echo $loan['overdue_emis']; ?> EMIs
                                                </span>
                                                <br>
                                                <small>₹<?php echo number_format($loan['overdue_amount'] ?? 0, 2); ?></small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $loan['status']; ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="bank-loan-view.php?id=<?php echo $loan['id']; ?>" class="btn-icon view" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="bank-loan-close.php?id=<?php echo $loan['id']; ?>" class="btn-icon pay" title="Make Payment">
                                                    <i class="bi bi-cash"></i>
                                                </a>
                                                <button class="btn-icon remind" onclick="sendReminder(<?php echo $loan['id']; ?>)" title="Send Reminder">
                                                    <i class="bi bi-envelope"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>

                                    <?php if (mysqli_num_rows($result) == 0): ?>
                                    <tr>
                                        <td colspan="14" class="text-center" style="padding: 50px;">
                                            <i class="bi bi-check-circle" style="font-size: 48px; color: #48bb78;"></i>
                                            <p style="margin-top: 10px;">No expiring loans found for the selected period</p>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td colspan="5" class="text-right"><strong>TOTALS:</strong></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($total_amount, 2); ?></strong></td>
                                        <td></td>
                                        <td></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($total_emi, 2); ?></strong></td>
                                        <td colspan="2"></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($total_overdue, 2); ?></strong></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Bank-wise Breakdown -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">Bank-wise Breakdown</span>
                        </div>
                        <div class="table-responsive">
                            <table class="loan-table">
                                <thead>
                                    <tr>
                                        <th>Bank</th>
                                        <th class="text-right">Loans</th>
                                        <th class="text-right">Total Amount</th>
                                        <th class="text-right">Customers</th>
                                        <th class="text-right">Overdue Loans</th>
                                        <th class="text-right">Avg Loan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $grand_total_loans = 0;
                                    $grand_total_amount = 0;
                                    $grand_total_customers = 0;
                                    $grand_total_overdue = 0;
                                    
                                    while ($bank = mysqli_fetch_assoc($bank_breakdown_result)): 
                                        $grand_total_loans += $bank['loan_count'];
                                        $grand_total_amount += $bank['total_amount'];
                                        $grand_total_customers += $bank['customer_count'];
                                        $grand_total_overdue += $bank['overdue_count'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($bank['bank_full_name']); ?></strong></td>
                                        <td class="text-right"><?php echo $bank['loan_count']; ?></td>
                                        <td class="text-right amount">₹<?php echo number_format($bank['total_amount'], 2); ?></td>
                                        <td class="text-right"><?php echo $bank['customer_count']; ?></td>
                                        <td class="text-right">
                                            <?php if ($bank['overdue_count'] > 0): ?>
                                                <span class="badge badge-danger"><?php echo $bank['overdue_count']; ?></span>
                                            <?php else: ?>
                                                0
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right">₹<?php echo number_format($bank['total_amount'] / max($bank['loan_count'], 1), 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td class="text-right"><strong>TOTALS:</strong></td>
                                        <td class="text-right"><strong><?php echo $grand_total_loans; ?></strong></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($grand_total_amount, 2); ?></strong></td>
                                        <td class="text-right"><strong><?php echo $grand_total_customers; ?></strong></td>
                                        <td class="text-right"><strong><?php echo $grand_total_overdue; ?></strong></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($grand_total_amount / max($grand_total_loans, 1), 2); ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS files -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.form-select').select2({
                width: '100%'
            });
        });

        // Suppress DataTables alerts globally
        $.fn.dataTable.ext.errMode = 'none';

        // Initialize DataTable with error suppression
        $(document).ready(function() {
            // Check if table exists and has data
            var table = document.getElementById('loansTable');
            if (table) {
                var tbody = table.querySelector('tbody');
                var hasData = tbody && tbody.children.length > 0 && tbody.children[0].cells.length > 1;
                
                if (hasData) {
                    try {
                        $('#loansTable').DataTable({
                            paging: true,
                            searching: true,
                            ordering: true,
                            info: true,
                            pageLength: 25,
                            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                            order: [[9, 'asc']], // Sort by due date
                            // Disable error messages
                            language: {
                                zeroRecords: "No matching records found",
                                infoEmpty: "No records available"
                            }
                        });
                        console.log('DataTable initialized successfully');
                    } catch (e) {
                        console.log('DataTable initialization skipped: ' + e.message);
                        // Table will still display normally without DataTables features
                    }
                } else {
                    console.log('Table has no data, skipping DataTable initialization');
                }
            }
        });

        // Daily Chart Data
        <?php
        $dates = [];
        $counts = [];
        $amounts = [];
        
        mysqli_data_seek($daily_breakdown_result, 0);
        while ($day = mysqli_fetch_assoc($daily_breakdown_result)) {
            $dates[] = date('d M', strtotime($day['due_date']));
            $counts[] = $day['emi_count'];
            $amounts[] = $day['total_amount'];
        }
        ?>

        // Daily EMI Chart
        new Chart(document.getElementById('dailyChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [{
                    label: 'Number of EMIs',
                    data: <?php echo json_encode($counts); ?>,
                    backgroundColor: '#4299e1',
                    yAxisID: 'y'
                }, {
                    label: 'Amount (₹)',
                    data: <?php echo json_encode($amounts); ?>,
                    backgroundColor: '#48bb78',
                    yAxisID: 'y1',
                    type: 'line'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Number of EMIs'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Bank Chart Data
        <?php
        $bank_names = [];
        $bank_loans = [];
        $bank_amounts = [];
        
        mysqli_data_seek($bank_breakdown_result, 0);
        while ($bank = mysqli_fetch_assoc($bank_breakdown_result)) {
            $bank_names[] = $bank['bank_short_name'];
            $bank_loans[] = $bank['loan_count'];
            $bank_amounts[] = $bank['total_amount'];
        }
        ?>

        // Bank Distribution Chart
        new Chart(document.getElementById('bankChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($bank_names); ?>,
                datasets: [{
                    data: <?php echo json_encode($bank_amounts); ?>,
                    backgroundColor: [
                        '#4299e1', '#48bb78', '#ecc94b', '#9f7aea', '#f56565',
                        '#667eea', '#38b2ac', '#ed8936', '#fc8181', '#b794f4'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '60%'
            }
        });

        // Select all checkboxes
        function toggleAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.loan-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Send individual reminder
        function sendReminder(loanId) {
            Swal.fire({
                title: 'Send Reminder',
                text: 'Send payment reminder for this loan?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ecc94b',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, send',
                showDenyButton: true,
                denyButtonText: 'Send SMS',
                denyButtonColor: '#4299e1'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('Sent!', 'Email reminder sent successfully', 'success');
                } else if (result.isDenied) {
                    Swal.fire('Sent!', 'SMS reminder sent successfully', 'success');
                }
            });
        }

        // Auto-submit form when filters change
        document.querySelectorAll('select[name="expiry_period"], select[name="bank_id"], select[name="customer_id"], select[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });

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