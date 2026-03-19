<?php
session_start();
$currentPage = 'bank-outstanding-loan-reports';
$pageTitle = 'Bank Outstanding Loan Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'accountant', 'manager'])) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'active';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$export = isset($_GET['export']);

// Validate dates
if (empty($date_from) || $date_from == '1970-01-01') {
    $date_from = date('Y-m-01');
}
if (empty($date_to) || $date_to == '1970-01-01') {
    $date_to = date('Y-m-d');
}

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get customers for dropdown
$customers_query = "SELECT id, customer_name, mobile_number FROM customers ORDER BY customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Build WHERE clause for filtering
$where_conditions = ["1=1"];
$params = [];
$types = "";

if ($bank_id > 0) {
    $where_conditions[] = "bl.bank_id = ?";
    $params[] = $bank_id;
    $types .= "i";
}

if ($status != 'all') {
    $where_conditions[] = "bl.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($customer_id > 0) {
    $where_conditions[] = "bl.customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

if ($date_from && $date_to) {
    $where_conditions[] = "DATE(bl.loan_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

$where_clause = implode(" AND ", $where_conditions);

// ==================== SUMMARY STATISTICS ====================

// 1. Total Outstanding Loans
$total_loans_query = "SELECT 
                        COUNT(*) as total_loans,
                        COALESCE(SUM(bl.loan_amount), 0) as total_loan_amount,
                        COALESCE(SUM(bl.total_interest), 0) as total_interest,
                        COALESCE(SUM(bl.total_payable), 0) as total_payable,
                        COALESCE(SUM(bl.document_charge + bl.processing_fee), 0) as total_charges
                      FROM bank_loans bl
                      WHERE $where_clause";

$stmt = mysqli_prepare($conn, $total_loans_query);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $summary = mysqli_fetch_assoc($result);
} else {
    $result = mysqli_query($conn, $total_loans_query);
    $summary = mysqli_fetch_assoc($result);
}

// 2. EMI Summary - FIXED: Added table aliases to resolve ambiguity
$emi_summary_query = "SELECT 
                        COUNT(DISTINCT be.id) as total_emis,
                        SUM(CASE WHEN be.status = 'pending' THEN 1 ELSE 0 END) as pending_emis,
                        SUM(CASE WHEN be.status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                        SUM(CASE WHEN be.status = 'pending' THEN be.emi_amount ELSE 0 END) as pending_amount,
                        SUM(CASE WHEN be.status = 'paid' THEN be.emi_amount ELSE 0 END) as paid_amount,
                        SUM(CASE WHEN be.due_date < CURDATE() AND be.status = 'pending' THEN 1 ELSE 0 END) as overdue_emis,
                        SUM(CASE WHEN be.due_date < CURDATE() AND be.status = 'pending' THEN be.emi_amount ELSE 0 END) as overdue_amount
                      FROM bank_loans bl
                      JOIN bank_loan_emi be ON bl.id = be.bank_loan_id
                      WHERE $where_clause";

$stmt = mysqli_prepare($conn, $emi_summary_query);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $emi_summary = mysqli_fetch_assoc($result);
} else {
    $result = mysqli_query($conn, $emi_summary_query);
    $emi_summary = mysqli_fetch_assoc($result);
}

// 3. Bank-wise Outstanding - FIXED: Added proper aliases and removed ambiguous column
$bank_wise_query = "SELECT 
                      bm.id,
                      bm.bank_full_name,
                      bm.bank_short_name,
                      COUNT(DISTINCT bl.id) as loan_count,
                      COALESCE(SUM(bl.loan_amount), 0) as total_loan_amount,
                      COALESCE(SUM(bl.total_payable), 0) as total_payable,
                      COALESCE(SUM(CASE WHEN bl.status = 'active' THEN bl.loan_amount ELSE 0 END), 0) as outstanding_amount,
                      COUNT(DISTINCT CASE WHEN bl.status = 'active' THEN bl.id END) as active_loans,
                      COUNT(DISTINCT CASE WHEN be.due_date < CURDATE() AND be.status = 'pending' THEN be.id END) as overdue_count
                    FROM bank_master bm
                    LEFT JOIN bank_loans bl ON bm.id = bl.bank_id
                    LEFT JOIN bank_loan_emi be ON bl.id = be.bank_loan_id AND be.status = 'pending'
                    GROUP BY bm.id, bm.bank_full_name, bm.bank_short_name
                    HAVING loan_count > 0
                    ORDER BY outstanding_amount DESC";

$bank_wise_result = mysqli_query($conn, $bank_wise_query);
$bank_wise = [];
if ($bank_wise_result) {
    while ($row = mysqli_fetch_assoc($bank_wise_result)) {
        $bank_wise[] = $row;
    }
}

// 4. Outstanding Loans List - FIXED: Added proper table aliases for all columns
$outstanding_query = "SELECT 
                        bl.id,
                        bl.loan_reference,
                        bl.loan_date,
                        bl.loan_amount,
                        bl.interest_rate,
                        bl.tenure_months,
                        bl.emi_amount as loan_emi_amount,
                        bl.total_interest,
                        bl.document_charge,
                        bl.processing_fee,
                        bl.total_payable,
                        bl.status,
                        bl.close_date,
                        bm.bank_full_name,
                        bm.bank_short_name,
                        ba.account_holder_no,
                        ba.bank_account_no,
                        c.customer_name,
                        c.mobile_number,
                        u.name as employee_name,
                        (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
                        (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis,
                        (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'pending' AND due_date < CURDATE()) as overdue_emis,
                        (SELECT COALESCE(SUM(emi_amount), 0) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'pending') as pending_amount,
                        (SELECT COALESCE(SUM(emi_amount), 0) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_amount,
                        (SELECT MIN(due_date) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'pending') as next_due_date,
                        (SELECT MIN(due_date) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'pending') as first_due_date
                      FROM bank_loans bl
                      JOIN bank_master bm ON bl.bank_id = bm.id
                      LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                      JOIN customers c ON bl.customer_id = c.id
                      JOIN users u ON bl.employee_id = u.id
                      WHERE $where_clause
                      ORDER BY bl.status, bl.loan_date DESC";

$stmt = mysqli_prepare($conn, $outstanding_query);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $outstanding_result = mysqli_stmt_get_result($stmt);
} else {
    $outstanding_result = mysqli_query($conn, $outstanding_query);
}

$outstanding_loans = [];
$active_loans = [];
$closed_loans = [];
$overdue_loans = [];

if ($outstanding_result) {
    while ($row = mysqli_fetch_assoc($outstanding_result)) {
        $outstanding_loans[] = $row;
        
        // Calculate progress percentage
        $row['progress_percentage'] = ($row['total_emis'] > 0) ? round(($row['paid_emis'] / $row['total_emis']) * 100, 2) : 0;
        $row['balance_amount'] = $row['total_payable'] - $row['paid_amount'];
        
        // Categorize loans
        if ($row['status'] == 'active') {
            if ($row['overdue_emis'] > 0) {
                $overdue_loans[] = $row;
            } else {
                $active_loans[] = $row;
            }
        } else {
            $closed_loans[] = $row;
        }
    }
}

// 5. Upcoming EMI Schedule - FIXED: Added proper table aliases
$upcoming_emi_query = "SELECT 
                        be.id,
                        be.installment_no,
                        be.due_date,
                        be.emi_amount,
                        be.principal_amount,
                        be.interest_amount,
                        be.balance_amount,
                        bl.id as bank_loan_id,
                        bl.loan_reference,
                        bl.loan_amount,
                        bm.bank_short_name,
                        c.customer_name,
                        c.mobile_number
                      FROM bank_loan_emi be
                      JOIN bank_loans bl ON be.bank_loan_id = bl.id
                      JOIN bank_master bm ON bl.bank_id = bm.id
                      JOIN customers c ON bl.customer_id = c.id
                      WHERE be.status = 'pending' 
                      AND be.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                      ORDER BY be.due_date ASC
                      LIMIT 20";

$upcoming_emi_result = mysqli_query($conn, $upcoming_emi_query);
$upcoming_emis = [];
if ($upcoming_emi_result) {
    while ($row = mysqli_fetch_assoc($upcoming_emi_result)) {
        $upcoming_emis[] = $row;
    }
}

// 6. Overdue EMI List - FIXED: Added proper table aliases
$overdue_emi_query = "SELECT 
                        be.id,
                        be.installment_no,
                        be.due_date,
                        be.emi_amount,
                        be.principal_amount,
                        be.interest_amount,
                        DATEDIFF(CURDATE(), be.due_date) as days_overdue,
                        bl.id as bank_loan_id,
                        bl.loan_reference,
                        bl.loan_amount,
                        bm.bank_short_name,
                        c.customer_name,
                        c.mobile_number
                      FROM bank_loan_emi be
                      JOIN bank_loans bl ON be.bank_loan_id = bl.id
                      JOIN bank_master bm ON bl.bank_id = bm.id
                      JOIN customers c ON bl.customer_id = c.id
                      WHERE be.status = 'pending' 
                      AND be.due_date < CURDATE()
                      ORDER BY be.due_date ASC";

$overdue_emi_result = mysqli_query($conn, $overdue_emi_query);
$overdue_emis_list = [];
if ($overdue_emi_result) {
    while ($row = mysqli_fetch_assoc($overdue_emi_result)) {
        $overdue_emis_list[] = $row;
    }
}

// 7. Monthly EMI Collection Forecast - FIXED: Added proper table aliases
$forecast_query = "SELECT 
                    DATE_FORMAT(be.due_date, '%Y-%m') as month,
                    COUNT(*) as emi_count,
                    COALESCE(SUM(be.emi_amount), 0) as expected_amount
                  FROM bank_loan_emi be
                  JOIN bank_loans bl ON be.bank_loan_id = bl.id
                  WHERE be.status = 'pending' 
                  AND be.due_date >= CURDATE()
                  AND be.due_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
                  GROUP BY DATE_FORMAT(be.due_date, '%Y-%m')
                  ORDER BY month ASC";

$forecast_result = mysqli_query($conn, $forecast_query);
$forecast_data = [];
$forecast_months = [];
$forecast_amounts = [];
if ($forecast_result) {
    while ($row = mysqli_fetch_assoc($forecast_result)) {
        $forecast_data[] = $row;
        $forecast_months[] = date('M Y', strtotime($row['month'] . '-01'));
        $forecast_amounts[] = $row['expected_amount'];
    }
}

// Handle export
if ($export) {
    exportOutstandingReport($date_from, $date_to, $summary, $emi_summary, $outstanding_loans);
}

function exportOutstandingReport($from, $to, $summary, $emi_summary, $loans) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bank_outstanding_loans_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['BANK OUTSTANDING LOAN REPORT']);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, ['Generated On', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Summary Section
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Loans', $summary['total_loans'] ?? 0]);
    fputcsv($output, ['Total Loan Amount', '₹ ' . number_format($summary['total_loan_amount'] ?? 0, 2)]);
    fputcsv($output, ['Total Interest', '₹ ' . number_format($summary['total_interest'] ?? 0, 2)]);
    fputcsv($output, ['Total Charges', '₹ ' . number_format($summary['total_charges'] ?? 0, 2)]);
    fputcsv($output, ['Total Payable', '₹ ' . number_format($summary['total_payable'] ?? 0, 2)]);
    fputcsv($output, []);
    
    fputcsv($output, ['EMI SUMMARY']);
    fputcsv($output, ['Total EMIs', $emi_summary['total_emis'] ?? 0]);
    fputcsv($output, ['Paid EMIs', $emi_summary['paid_emis'] ?? 0]);
    fputcsv($output, ['Pending EMIs', $emi_summary['pending_emis'] ?? 0]);
    fputcsv($output, ['Overdue EMIs', $emi_summary['overdue_emis'] ?? 0]);
    fputcsv($output, ['Pending Amount', '₹ ' . number_format($emi_summary['pending_amount'] ?? 0, 2)]);
    fputcsv($output, ['Overdue Amount', '₹ ' . number_format($emi_summary['overdue_amount'] ?? 0, 2)]);
    fputcsv($output, ['Paid Amount', '₹ ' . number_format($emi_summary['paid_amount'] ?? 0, 2)]);
    fputcsv($output, []);
    
    // Details Section
    fputcsv($output, ['OUTSTANDING LOANS DETAILS']);
    fputcsv($output, [
        'Loan Ref', 'Bank', 'Customer', 'Mobile', 'Loan Date',
        'Loan Amount', 'Interest Rate', 'Tenure', 'EMI Amount',
        'Paid EMIs', 'Total EMIs', 'Progress %', 'Pending Amount',
        'Next Due', 'Status'
    ]);
    
    foreach ($loans as $loan) {
        fputcsv($output, [
            $loan['loan_reference'] ?? '',
            $loan['bank_short_name'] ?? '',
            $loan['customer_name'] ?? '',
            $loan['mobile_number'] ?? '',
            $loan['loan_date'] ?? '',
            '₹ ' . number_format($loan['loan_amount'] ?? 0, 2),
            ($loan['interest_rate'] ?? 0) . '%',
            ($loan['tenure_months'] ?? 0) . ' months',
            '₹ ' . number_format($loan['loan_emi_amount'] ?? 0, 2),
            $loan['paid_emis'] ?? 0,
            $loan['total_emis'] ?? 0,
            ($loan['progress_percentage'] ?? 0) . '%',
            '₹ ' . number_format($loan['pending_amount'] ?? 0, 2),
            $loan['next_due_date'] ?? 'N/A',
            ucfirst($loan['status'] ?? '')
        ]);
    }
    
    fclose($output);
    exit();
}

// Helper function for status badges
function getStatusBadge($status) {
    switch ($status) {
        case 'active':
            return '<span class="badge badge-success">Active</span>';
        case 'closed':
            return '<span class="badge badge-secondary">Closed</span>';
        case 'defaulted':
            return '<span class="badge badge-danger">Defaulted</span>';
        default:
            return '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
    }
}

// Helper function for progress bar
function getProgressBar($percentage) {
    $color = 'bg-success';
    if ($percentage < 30) $color = 'bg-danger';
    else if ($percentage < 70) $color = 'bg-warning';
    
    return '<div class="progress" style="height: 6px;">
                <div class="progress-bar ' . $color . '" role="progressbar" 
                     style="width: ' . $percentage . '%" 
                     aria-valuenow="' . $percentage . '" 
                     aria-valuemin="0" 
                     aria-valuemax="100">
                </div>
            </div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .report-container {
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
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

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .summary-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .summary-card.success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .summary-card.warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .summary-card.danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .summary-card.info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .summary-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-sub {
            font-size: 13px;
            opacity: 0.8;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .stats-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .stats-list {
            list-style: none;
        }

        .stats-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .stats-item:last-child {
            border-bottom: none;
        }

        .stats-label {
            color: #4a5568;
        }

        .stats-value {
            font-weight: 600;
            color: #2d3748;
        }

        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
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

        /* Tables */
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

        .table-title i {
            color: #667eea;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .report-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .report-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .report-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #feebc8;
            color: #744210;
        }

        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount {
            font-weight: 600;
            color: #2d3748;
        }

        .amount-success {
            color: #48bb78;
            font-weight: 600;
        }

        .amount-danger {
            color: #f56565;
            font-weight: 600;
        }

        .amount-warning {
            color: #ecc94b;
            font-weight: 600;
        }

        .progress {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin: 5px 0;
        }

        .progress-bar {
            height: 100%;
            border-radius: 3px;
        }

        .progress-bar.bg-success {
            background: #48bb78;
        }

        .progress-bar.bg-warning {
            background: #ecc94b;
        }

        .progress-bar.bg-danger {
            background: #f56565;
        }

        .info-box {
            background: #f7fafc;
            border-left: 4px solid #667eea;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .info-title {
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
        }

        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: white;
            color: #4a5568;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .tab-btn:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .tab-buttons {
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
                <div class="report-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-bank"></i>
                            Bank Outstanding Loan Reports
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

                    <!-- Display Error Message -->
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <strong>Error:</strong> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Reports
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Bank</label>
                                    <select class="form-select" name="bank_id">
                                        <option value="0">All Banks</option>
                                        <?php 
                                        if ($banks_result) {
                                            mysqli_data_seek($banks_result, 0);
                                            while ($bank = mysqli_fetch_assoc($banks_result)): 
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

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <option value="all">All Status</option>
                                        <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        <option value="defaulted" <?php echo $status == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Customer</label>
                                    <select class="form-select" name="customer_id">
                                        <option value="0">All Customers</option>
                                        <?php 
                                        if ($customers_result) {
                                            mysqli_data_seek($customers_result, 0);
                                            while ($cust = mysqli_fetch_assoc($customers_result)): 
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

                                <div class="form-group">
                                    <label class="form-label">Date From</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Date To</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Generate Report
                                </button>
                                <a href="bank-outstanding-loan-reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Cards -->
                    <div class="summary-grid">
                        <div class="summary-card primary">
                            <div class="summary-label">Total Loans</div>
                            <div class="summary-value"><?php echo number_format($summary['total_loans'] ?? 0); ?></div>
                            <div class="summary-sub">
                                Active: <?php echo count($active_loans); ?> | 
                                Closed: <?php echo count($closed_loans); ?>
                            </div>
                        </div>

                        <div class="summary-card success">
                            <div class="summary-label">Total Loan Amount</div>
                            <div class="summary-value">₹ <?php echo number_format($summary['total_loan_amount'] ?? 0, 2); ?></div>
                            <div class="summary-sub">Principal amount</div>
                        </div>

                        <div class="summary-card warning">
                            <div class="summary-label">Total Payable</div>
                            <div class="summary-value">₹ <?php echo number_format($summary['total_payable'] ?? 0, 2); ?></div>
                            <div class="summary-sub">Including interest & charges</div>
                        </div>

                        <div class="summary-card info">
                            <div class="summary-label">Pending EMIs</div>
                            <div class="summary-value">₹ <?php echo number_format($emi_summary['pending_amount'] ?? 0, 2); ?></div>
                            <div class="summary-sub">
                                <?php echo $emi_summary['pending_emis'] ?? 0; ?> EMIs pending
                            </div>
                        </div>
                    </div>

                    <!-- EMI Summary Cards -->
                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label">Total EMIs</div>
                            <div class="summary-value"><?php echo number_format($emi_summary['total_emis'] ?? 0); ?></div>
                            <div class="summary-sub">
                                Paid: <?php echo $emi_summary['paid_emis'] ?? 0; ?> | 
                                Pending: <?php echo $emi_summary['pending_emis'] ?? 0; ?>
                            </div>
                        </div>

                        <div class="summary-card success">
                            <div class="summary-label">Paid Amount</div>
                            <div class="summary-value">₹ <?php echo number_format($emi_summary['paid_amount'] ?? 0, 2); ?></div>
                            <div class="summary-sub">Total collected</div>
                        </div>

                        <div class="summary-card danger">
                            <div class="summary-label">Overdue EMIs</div>
                            <div class="summary-value"><?php echo number_format($emi_summary['overdue_emis'] ?? 0); ?></div>
                            <div class="summary-sub">
                                Amount: ₹ <?php echo number_format($emi_summary['overdue_amount'] ?? 0, 2); ?>
                            </div>
                        </div>

                        <div class="summary-card warning">
                            <div class="summary-label">Collection Ratio</div>
                            <?php 
                            $collection_ratio = ($emi_summary['total_emis'] > 0) 
                                ? round(($emi_summary['paid_emis'] / $emi_summary['total_emis']) * 100, 2) 
                                : 0;
                            ?>
                            <div class="summary-value"><?php echo $collection_ratio; ?>%</div>
                            <div class="summary-sub">EMIs paid</div>
                        </div>
                    </div>

                    <!-- Tab Buttons -->
                    <div class="tab-buttons">
                        <button class="tab-btn active" onclick="showTab('active')">Active Loans (<?php echo count($active_loans); ?>)</button>
                        <button class="tab-btn" onclick="showTab('overdue')">Overdue Loans (<?php echo count($overdue_loans); ?>)</button>
                        <button class="tab-btn" onclick="showTab('closed')">Closed Loans (<?php echo count($closed_loans); ?>)</button>
                        <button class="tab-btn" onclick="showTab('upcoming')">Upcoming EMIs (<?php echo count($upcoming_emis); ?>)</button>
                        <button class="tab-btn" onclick="showTab('overdue_emis')">Overdue EMIs (<?php echo count($overdue_emis_list); ?>)</button>
                    </div>

                    <!-- Active Loans Tab -->
                    <div id="active-tab" class="tab-pane active">
                        <div class="table-card">
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class="bi bi-check-circle"></i>
                                    Active Loans
                                </h3>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Loan Date</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">EMI</th>
                                            <th>Progress</th>
                                            <th class="text-right">Pending</th>
                                            <th>Next Due</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($active_loans)): ?>
                                            <?php foreach ($active_loans as $loan): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($loan['loan_reference']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($loan['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['loan_date'])); ?></td>
                                                <td class="text-right amount">₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                                <td class="text-right"><?php echo $loan['interest_rate']; ?>%</td>
                                                <td class="text-right amount">₹ <?php echo number_format($loan['loan_emi_amount'], 2); ?></td>
                                                <td style="min-width: 100px;">
                                                    <?php echo getProgressBar($loan['progress_percentage']); ?>
                                                    <small><?php echo $loan['paid_emis']; ?>/<?php echo $loan['total_emis']; ?></small>
                                                </td>
                                                <td class="text-right amount-warning">₹ <?php echo number_format($loan['pending_amount'], 2); ?></td>
                                                <td>
                                                    <?php if ($loan['next_due_date']): ?>
                                                        <?php echo date('d-m-Y', strtotime($loan['next_due_date'])); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo getStatusBadge($loan['status']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="11" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px; color: #a0aec0;"></i>
                                                    No active loans found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Overdue Loans Tab -->
                    <div id="overdue-tab" class="tab-pane">
                        <div class="table-card">
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    Overdue Loans
                                </h3>
                                <?php if (!empty($overdue_loans)): ?>
                                <span class="badge badge-danger"><?php echo count($overdue_loans); ?> Overdue</span>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Loan Date</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Overdue EMIs</th>
                                            <th class="text-right">Overdue Amount</th>
                                            <th>First Due</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($overdue_loans)): ?>
                                            <?php foreach ($overdue_loans as $loan): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($loan['loan_reference']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($loan['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['loan_date'])); ?></td>
                                                <td class="text-right amount">₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                                <td class="text-right amount-danger"><?php echo $loan['overdue_emis']; ?></td>
                                                <td class="text-right amount-danger">₹ <?php echo number_format($loan['pending_amount'], 2); ?></td>
                                                <td>
                                                    <?php if ($loan['first_due_date']): ?>
                                                        <?php echo date('d-m-Y', strtotime($loan['first_due_date'])); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="bank-loan-details.php?id=<?php echo $loan['id']; ?>" class="btn btn-info btn-sm">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-check-circle" style="font-size: 48px; display: block; margin-bottom: 10px; color: #48bb78;"></i>
                                                    No overdue loans found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Closed Loans Tab -->
                    <div id="closed-tab" class="tab-pane">
                        <div class="table-card">
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Closed Loans
                                </h3>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Loan Date</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Total Paid</th>
                                            <th>Close Date</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($closed_loans)): ?>
                                            <?php foreach ($closed_loans as $loan): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($loan['loan_reference']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($loan['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['loan_date'])); ?></td>
                                                <td class="text-right amount">₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                                <td class="text-right amount-success">₹ <?php echo number_format($loan['paid_amount'], 2); ?></td>
                                                <td>
                                                    <?php if ($loan['close_date']): ?>
                                                        <?php echo date('d-m-Y', strtotime($loan['close_date'])); ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo getStatusBadge($loan['status']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px; color: #a0aec0;"></i>
                                                    No closed loans found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming EMIs Tab -->
                    <div id="upcoming-tab" class="tab-pane">
                        <div class="table-card">
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class="bi bi-calendar-check"></i>
                                    Upcoming EMIs (Next 30 Days)
                                </h3>
                                <?php if (!empty($upcoming_emis)): ?>
                                <span class="badge badge-info"><?php echo count($upcoming_emis); ?> EMIs</span>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Due Date</th>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Installment</th>
                                            <th class="text-right">EMI Amount</th>
                                            <th class="text-right">Principal</th>
                                            <th class="text-right">Interest</th>
                                            <th>Days Left</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($upcoming_emis)): ?>
                                            <?php foreach ($upcoming_emis as $emi): 
                                                $days_left = (strtotime($emi['due_date']) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
                                            ?>
                                            <tr>
                                                <td><strong><?php echo date('d-m-Y', strtotime($emi['due_date'])); ?></strong></td>
                                                <td><?php echo htmlspecialchars($emi['loan_reference']); ?></td>
                                                <td><?php echo htmlspecialchars($emi['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($emi['customer_name']); ?></td>
                                                <td><?php echo $emi['installment_no']; ?></td>
                                                <td class="text-right amount">₹ <?php echo number_format($emi['emi_amount'], 2); ?></td>
                                                <td class="text-right">₹ <?php echo number_format($emi['principal_amount'], 2); ?></td>
                                                <td class="text-right">₹ <?php echo number_format($emi['interest_amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $days_left <= 7 ? 'warning' : 'info'; ?>">
                                                        <?php echo round($days_left); ?> days
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-calendar" style="font-size: 48px; display: block; margin-bottom: 10px; color: #a0aec0;"></i>
                                                    No upcoming EMIs in the next 30 days
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Overdue EMIs Tab -->
                    <div id="overdue_emis-tab" class="tab-pane">
                        <div class="table-card">
                            <div class="table-header">
                                <h3 class="table-title">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    Overdue EMIs
                                </h3>
                                <?php if (!empty($overdue_emis_list)): ?>
                                <span class="badge badge-danger"><?php echo count($overdue_emis_list); ?> Overdue</span>
                                <?php endif; ?>
                            </div>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Due Date</th>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Installment</th>
                                            <th class="text-right">EMI Amount</th>
                                            <th class="text-right">Days Overdue</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($overdue_emis_list)): ?>
                                            <?php foreach ($overdue_emis_list as $emi): ?>
                                            <tr>
                                                <td><strong class="text-danger"><?php echo date('d-m-Y', strtotime($emi['due_date'])); ?></strong></td>
                                                <td><?php echo htmlspecialchars($emi['loan_reference']); ?></td>
                                                <td><?php echo htmlspecialchars($emi['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($emi['customer_name']); ?></td>
                                                <td><?php echo $emi['installment_no']; ?></td>
                                                <td class="text-right amount-danger">₹ <?php echo number_format($emi['emi_amount'], 2); ?></td>
                                                <td class="text-center">
                                                    <span class="badge badge-danger"><?php echo $emi['days_overdue']; ?> days</span>
                                                </td>
                                                <td>
                                                    <a href="bank-loan-details.php?id=<?php echo $emi['bank_loan_id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="bi bi-cash"></i> Collect
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-check-circle" style="font-size: 48px; display: block; margin-bottom: 10px; color: #48bb78;"></i>
                                                    No overdue EMIs found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Bank-wise Summary -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-pie-chart"></i>
                                Bank-wise Outstanding Summary
                            </h3>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Bank</th>
                                        <th class="text-right">Total Loans</th>
                                        <th class="text-right">Active Loans</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Outstanding</th>
                                        <th class="text-right">Overdue</th>
                                        <th class="text-right">Collection %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bank_wise)): ?>
                                        <?php foreach ($bank_wise as $bank): 
                                            $collection_pct = ($bank['total_loan_amount'] > 0) 
                                                ? round((($bank['total_loan_amount'] - $bank['outstanding_amount']) / $bank['total_loan_amount']) * 100, 2) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($bank['bank_full_name']); ?></strong></td>
                                            <td class="text-right"><?php echo $bank['loan_count']; ?></td>
                                            <td class="text-right"><?php echo $bank['active_loans']; ?></td>
                                            <td class="text-right amount">₹ <?php echo number_format($bank['total_loan_amount'], 2); ?></td>
                                            <td class="text-right amount-warning">₹ <?php echo number_format($bank['outstanding_amount'], 2); ?></td>
                                            <td class="text-right amount-danger"><?php echo $bank['overdue_count']; ?></td>
                                            <td class="text-right">
                                                <span class="badge badge-<?php echo $collection_pct > 70 ? 'success' : ($collection_pct > 40 ? 'warning' : 'danger'); ?>">
                                                    <?php echo $collection_pct; ?>%
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center" style="padding: 40px;">
                                                No bank-wise data available
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- EMI Collection Forecast Chart -->
                    <?php if (!empty($forecast_data)): ?>
                    <div class="chart-card">
                        <div class="chart-title">EMI Collection Forecast (Next 6 Months)</div>
                        <div class="chart-container">
                            <canvas id="forecastChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Info Box -->
                    <div class="info-box">
                        <div class="info-title">Report Summary</div>
                        <div class="info-value">
                            Total Outstanding: ₹ <?php echo number_format($emi_summary['pending_amount'] ?? 0, 2); ?> | 
                            Overdue: ₹ <?php echo number_format($emi_summary['overdue_amount'] ?? 0, 2); ?> | 
                            Collection Rate: <?php echo $collection_ratio; ?>%
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Auto-submit form when filters change
        document.querySelector('select[name="bank_id"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.querySelector('select[name="status"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Forecast Chart
        <?php if (!empty($forecast_months) && !empty($forecast_amounts)): ?>
        new Chart(document.getElementById('forecastChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($forecast_months); ?>,
                datasets: [{
                    label: 'Expected Collection',
                    data: <?php echo json_encode($forecast_amounts); ?>,
                    backgroundColor: '#4299e1',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

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