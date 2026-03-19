<?php
session_start();
$currentPage = 'profit-reports';
$pageTitle = 'Profit Reports';
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
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$compare_with = isset($_GET['compare_with']) ? $_GET['compare_with'] : 'previous_period';
$export = isset($_GET['export']);

// Set date range based on selection
if ($date_range != 'custom') {
    switch ($date_range) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to = date('Y-m-d');
            break;
        case 'yesterday':
            $date_from = date('Y-m-d', strtotime('-1 day'));
            $date_to = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to = date('Y-m-d');
            break;
        case 'last_week':
            $date_from = date('Y-m-d', strtotime('monday last week'));
            $date_to = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('first day of last month'));
            $date_to = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'this_quarter':
            $quarter = ceil(date('n') / 3);
            $date_from = date('Y-m-01', strtotime('first day of January ' . date('Y') . ' + ' . (($quarter - 1) * 3) . ' months'));
            $date_to = date('Y-m-d');
            break;
        case 'last_quarter':
            $quarter = ceil(date('n') / 3) - 1;
            $year = date('Y');
            if ($quarter == 0) {
                $quarter = 4;
                $year = $year - 1;
            }
            $date_from = date('Y-m-01', strtotime('first day of January ' . $year . ' + ' . (($quarter - 1) * 3) . ' months'));
            $date_to = date('Y-m-t', strtotime('last day of ' . $date_from . ' + 2 months'));
            break;
        case 'this_year':
            $date_from = date('Y-01-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_year':
            $date_from = date('Y-01-01', strtotime('-1 year'));
            $date_to = date('Y-12-31', strtotime('-1 year'));
            break;
    }
}

// Validate dates
if (empty($date_from) || $date_from == '1970-01-01') {
    $date_from = date('Y-m-01');
}
if (empty($date_to) || $date_to == '1970-01-01') {
    $date_to = date('Y-m-d');
}

// Get previous period dates for comparison
$days_diff = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24);
$prev_date_from = date('Y-m-d', strtotime($date_from . ' - ' . ($days_diff + 1) . ' days'));
$prev_date_to = date('Y-m-d', strtotime($date_from . ' - 1 day'));

// Get branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Initialize all variables
$interest_data = ['total_interest' => 0, 'interest_transactions' => 0, 'loans_with_interest' => 0];
$charges_data = ['total_charges' => 0, 'loans_with_charges' => 0];
$expenses_by_type = [];
$total_expenses = 0;
$total_income = 0;
$net_profit = 0;
$profit_margin = 0;
$monthly_data = [];
$months = [];
$monthly_income = [];
$monthly_expenses = [];
$monthly_profit = [];
$top_sources = [];
$daily_data = [];

// ==================== MAIN PROFIT CALCULATION ====================

// 1. Interest Income from payments
$interest_query = "SELECT 
                    COALESCE(SUM(p.interest_amount), 0) as total_interest,
                    COUNT(p.id) as interest_transactions,
                    COUNT(DISTINCT p.loan_id) as loans_with_interest
                  FROM payments p
                  WHERE DATE(p.payment_date) BETWEEN ? AND ? AND p.interest_amount > 0";

$interest_stmt = mysqli_prepare($conn, $interest_query);
if ($interest_stmt) {
    mysqli_stmt_bind_param($interest_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($interest_stmt);
    $interest_result = mysqli_stmt_get_result($interest_stmt);
    $interest_data = mysqli_fetch_assoc($interest_result);
}

// 2. Receipt Charges Income from loans
$charges_query = "SELECT 
                    COALESCE(SUM(l.receipt_charge), 0) as total_charges,
                    COUNT(l.id) as loans_with_charges
                  FROM loans l
                  WHERE DATE(l.receipt_date) BETWEEN ? AND ?";

$charges_stmt = mysqli_prepare($conn, $charges_query);
if ($charges_stmt) {
    mysqli_stmt_bind_param($charges_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($charges_stmt);
    $charges_result = mysqli_stmt_get_result($charges_stmt);
    $charges_data = mysqli_fetch_assoc($charges_result);
}

// 3. Expenses from expense_details table
$expenses_query = "SELECT 
                    expense_type,
                    COALESCE(SUM(amount), 0) as expense_amount,
                    COUNT(*) as expense_count
                  FROM expense_details
                  WHERE date BETWEEN ? AND ? AND amount > 0
                  GROUP BY expense_type
                  ORDER BY expense_amount DESC";

$expenses_stmt = mysqli_prepare($conn, $expenses_query);
if ($expenses_stmt) {
    mysqli_stmt_bind_param($expenses_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($expenses_stmt);
    $expenses_result = mysqli_stmt_get_result($expenses_stmt);
    
    $expenses_by_type = [];
    $total_expenses = 0;
    while ($row = mysqli_fetch_assoc($expenses_result)) {
        $expenses_by_type[] = $row;
        $total_expenses += $row['expense_amount'];
    }
}

// ==================== CALCULATE PROFIT ====================

$total_income = ($interest_data['total_interest'] ?? 0) + 
                ($charges_data['total_charges'] ?? 0);

$net_profit = $total_income - $total_expenses;
$profit_margin = $total_income > 0 ? round(($net_profit / $total_income) * 100, 2) : 0;

// ==================== COMPARISON DATA ====================

// Previous period interest
$prev_interest = 0;
$prev_interest_query = "SELECT COALESCE(SUM(p.interest_amount), 0) as total_interest
                        FROM payments p
                        WHERE DATE(p.payment_date) BETWEEN ? AND ? AND p.interest_amount > 0";

$prev_interest_stmt = mysqli_prepare($conn, $prev_interest_query);
if ($prev_interest_stmt) {
    mysqli_stmt_bind_param($prev_interest_stmt, 'ss', $prev_date_from, $prev_date_to);
    mysqli_stmt_execute($prev_interest_stmt);
    $prev_interest_result = mysqli_stmt_get_result($prev_interest_stmt);
    $prev_interest = mysqli_fetch_assoc($prev_interest_result)['total_interest'] ?? 0;
}

// Previous period charges
$prev_charges = 0;
$prev_charges_query = "SELECT COALESCE(SUM(l.receipt_charge), 0) as total_charges
                       FROM loans l
                       WHERE DATE(l.receipt_date) BETWEEN ? AND ?";

$prev_charges_stmt = mysqli_prepare($conn, $prev_charges_query);
if ($prev_charges_stmt) {
    mysqli_stmt_bind_param($prev_charges_stmt, 'ss', $prev_date_from, $prev_date_to);
    mysqli_stmt_execute($prev_charges_stmt);
    $prev_charges_result = mysqli_stmt_get_result($prev_charges_stmt);
    $prev_charges = mysqli_fetch_assoc($prev_charges_result)['total_charges'] ?? 0;
}

// Previous period expenses
$prev_expenses = 0;
$prev_expenses_query = "SELECT COALESCE(SUM(amount), 0) as total_expenses
                        FROM expense_details
                        WHERE date BETWEEN ? AND ? AND amount > 0";

$prev_expenses_stmt = mysqli_prepare($conn, $prev_expenses_query);
if ($prev_expenses_stmt) {
    mysqli_stmt_bind_param($prev_expenses_stmt, 'ss', $prev_date_from, $prev_date_to);
    mysqli_stmt_execute($prev_expenses_stmt);
    $prev_expenses_result = mysqli_stmt_get_result($prev_expenses_stmt);
    $prev_expenses = mysqli_fetch_assoc($prev_expenses_result)['total_expenses'] ?? 0;
}

$prev_total_income = $prev_interest + $prev_charges;
$prev_net_profit = $prev_total_income - $prev_expenses;

// Calculate growth percentages
$income_growth = $prev_total_income > 0 ? round((($total_income - $prev_total_income) / $prev_total_income) * 100, 2) : ($total_income > 0 ? 100 : 0);
$expense_growth = $prev_expenses > 0 ? round((($total_expenses - $prev_expenses) / $prev_expenses) * 100, 2) : ($total_expenses > 0 ? 100 : 0);
$profit_growth = $prev_net_profit > 0 ? round((($net_profit - $prev_net_profit) / $prev_net_profit) * 100, 2) : ($net_profit > 0 ? 100 : 0);

// ==================== MONTHLY TREND ====================

$monthly_query = "SELECT 
                    DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                    COALESCE(SUM(p.interest_amount), 0) as interest,
                    COALESCE(SUM(l.receipt_charge), 0) as charges
                  FROM (SELECT DISTINCT DATE_FORMAT(payment_date, '%Y-%m') as month FROM payments 
                        UNION 
                        SELECT DISTINCT DATE_FORMAT(receipt_date, '%Y-%m') FROM loans) months
                  LEFT JOIN payments p ON DATE_FORMAT(p.payment_date, '%Y-%m') = months.month
                  LEFT JOIN loans l ON DATE_FORMAT(l.receipt_date, '%Y-%m') = months.month
                  WHERE months.month BETWEEN DATE_FORMAT(DATE_SUB(?, INTERVAL 11 MONTH), '%Y-%m') AND DATE_FORMAT(?, '%Y-%m')
                  GROUP BY months.month
                  ORDER BY months.month DESC";

$monthly_stmt = mysqli_prepare($conn, $monthly_query);
if ($monthly_stmt) {
    mysqli_stmt_bind_param($monthly_stmt, 'ss', $date_to, $date_to);
    mysqli_stmt_execute($monthly_stmt);
    $monthly_result = mysqli_stmt_get_result($monthly_stmt);
    
    while ($row = mysqli_fetch_assoc($monthly_result)) {
        $monthly_data[] = $row;
        $months[] = date('M Y', strtotime($row['month'] . '-01'));
        $income = $row['interest'] + $row['charges'];
        $monthly_income[] = $income;
        $monthly_expenses[] = 0; // Expenses not available by month in this query
        $monthly_profit[] = $income;
    }
}

// ==================== TOP PROFIT SOURCES ====================

$top_sources_query = "SELECT 
                        'Interest' as source,
                        COALESCE(SUM(p.interest_amount), 0) as amount,
                        COUNT(DISTINCT p.loan_id) as count
                      FROM payments p
                      WHERE DATE(p.payment_date) BETWEEN ? AND ? AND p.interest_amount > 0
                      UNION ALL
                      SELECT 
                        'Receipt Charges' as source,
                        COALESCE(SUM(l.receipt_charge), 0) as amount,
                        COUNT(l.id) as count
                      FROM loans l
                      WHERE DATE(l.receipt_date) BETWEEN ? AND ? AND l.receipt_charge > 0
                      ORDER BY amount DESC";

$top_sources_stmt = mysqli_prepare($conn, $top_sources_query);
if ($top_sources_stmt) {
    mysqli_stmt_bind_param($top_sources_stmt, 'ssss', $date_from, $date_to, $date_from, $date_to);
    mysqli_stmt_execute($top_sources_stmt);
    $top_sources_result = mysqli_stmt_get_result($top_sources_stmt);
    
    while ($row = mysqli_fetch_assoc($top_sources_result)) {
        $top_sources[] = $row;
    }
}

// ==================== DAILY PROFIT BREAKDOWN ====================

$daily_query = "SELECT 
                  dates.date,
                  COALESCE(SUM(p.interest_amount), 0) as interest,
                  COALESCE(SUM(l.receipt_charge), 0) as charges,
                  COALESCE(SUM(e.amount), 0) as expenses
                FROM (
                  SELECT DISTINCT DATE(payment_date) as date FROM payments WHERE payment_date BETWEEN ? AND ?
                  UNION
                  SELECT DISTINCT DATE(receipt_date) FROM loans WHERE receipt_date BETWEEN ? AND ?
                  UNION
                  SELECT DISTINCT DATE(date) FROM expense_details WHERE date BETWEEN ? AND ?
                ) dates
                LEFT JOIN payments p ON DATE(p.payment_date) = dates.date
                LEFT JOIN loans l ON DATE(l.receipt_date) = dates.date
                LEFT JOIN expense_details e ON DATE(e.date) = dates.date
                GROUP BY dates.date
                ORDER BY dates.date DESC
                LIMIT 30";

$daily_stmt = mysqli_prepare($conn, $daily_query);
if ($daily_stmt) {
    mysqli_stmt_bind_param($daily_stmt, 'ssssss', $date_from, $date_to, $date_from, $date_to, $date_from, $date_to);
    mysqli_stmt_execute($daily_stmt);
    $daily_result = mysqli_stmt_get_result($daily_stmt);
    
    while ($row = mysqli_fetch_assoc($daily_result)) {
        $row['total_income'] = $row['interest'] + $row['charges'];
        $row['profit'] = $row['total_income'] - $row['expenses'];
        $daily_data[] = $row;
    }
}

// Handle export
if ($export) {
    exportProfitReport($date_from, $date_to, $interest_data, $charges_data, $expenses_by_type, $total_income, $total_expenses, $net_profit);
}

function exportProfitReport($from, $to, $interest, $charges, $expenses, $total_income, $total_expenses, $net_profit) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="profit_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['PROFIT & LOSS REPORT']);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, []);
    
    // Income Section
    fputcsv($output, ['INCOME']);
    fputcsv($output, ['Description', 'Amount (₹)']);
    fputcsv($output, ['Interest Income', $interest['total_interest'] ?? 0]);
    fputcsv($output, ['Receipt Charges', $charges['total_charges'] ?? 0]);
    fputcsv($output, ['Total Income', $total_income]);
    fputcsv($output, []);
    
    // Expenses Section
    fputcsv($output, ['EXPENSES']);
    fputcsv($output, ['Description', 'Amount (₹)']);
    foreach ($expenses as $expense) {
        fputcsv($output, [$expense['expense_type'], $expense['expense_amount']]);
    }
    fputcsv($output, ['Total Expenses', $total_expenses]);
    fputcsv($output, []);
    
    // Profit Section
    fputcsv($output, ['NET PROFIT', $net_profit]);
    
    fclose($output);
    exit();
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

        .profit-container {
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

        /* Profit Summary Cards */
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

        .summary-card.profit {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .summary-card.loss {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .summary-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .summary-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .summary-sub {
            font-size: 13px;
            opacity: 0.8;
        }

        .growth-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .growth-badge.positive {
            background: rgba(72, 187, 120, 0.2);
            color: #48bb78;
        }

        .growth-badge.negative {
            background: rgba(245, 101, 101, 0.2);
            color: #f56565;
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

        /* P&L Table */
        .pl-table {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .pl-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
        }

        .pl-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .pl-section {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
        }

        .pl-section-title {
            font-size: 16px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .pl-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .pl-row.total {
            font-weight: 700;
            font-size: 16px;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #667eea;
        }

        .pl-label {
            color: #4a5568;
        }

        .pl-amount {
            font-weight: 600;
            color: #2d3748;
        }

        .pl-amount.income {
            color: #48bb78;
        }

        .pl-amount.expense {
            color: #f56565;
        }

        /* Top Sources */
        .sources-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .source-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .source-icon {
            width: 50px;
            height: 50px;
            border-radius: 25px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
            margin: 0 auto 15px;
        }

        .source-name {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .source-amount {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
            margin-bottom: 5px;
        }

        .source-count {
            font-size: 13px;
            color: #a0aec0;
        }

        /* Daily Table */
        .table-responsive {
            overflow-x: auto;
        }

        .profit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .profit-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .profit-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .profit-table tbody tr:hover {
            background: #f7fafc;
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

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .pl-grid {
                grid-template-columns: 1fr;
            }
            
            .sources-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
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
                <div class="profit-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-graph-up-arrow"></i>
                            Profit Reports
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
                            Filter Profit Report
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Date Range</label>
                                    <select class="form-select" name="date_range" onchange="toggleCustomDates()">
                                        <option value="today" <?php echo $date_range == 'today' ? 'selected' : ''; ?>>Today</option>
                                        <option value="yesterday" <?php echo $date_range == 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                        <option value="this_week" <?php echo $date_range == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                        <option value="last_week" <?php echo $date_range == 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                                        <option value="this_month" <?php echo $date_range == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                        <option value="last_month" <?php echo $date_range == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                        <option value="this_quarter" <?php echo $date_range == 'this_quarter' ? 'selected' : ''; ?>>This Quarter</option>
                                        <option value="last_quarter" <?php echo $date_range == 'last_quarter' ? 'selected' : ''; ?>>Last Quarter</option>
                                        <option value="this_year" <?php echo $date_range == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                                        <option value="last_year" <?php echo $date_range == 'last_year' ? 'selected' : ''; ?>>Last Year</option>
                                        <option value="custom" <?php echo $date_range == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                                    </select>
                                </div>

                                <div class="form-group" id="dateFromGroup" style="<?php echo $date_range == 'custom' ? 'display:block' : 'display:none'; ?>">
                                    <label class="form-label">Date From</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                    </div>
                                </div>

                                <div class="form-group" id="dateToGroup" style="<?php echo $date_range == 'custom' ? 'display:block' : 'display:none'; ?>">
                                    <label class="form-label">Date To</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <select class="form-select" name="branch_id">
                                        <option value="0">All Branches</option>
                                        <?php 
                                        if ($branches_result) {
                                            while ($branch = mysqli_fetch_assoc($branches_result)): 
                                        ?>
                                        <option value="<?php echo $branch['id']; ?>" <?php echo $branch_id == $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                                        </option>
                                        <?php 
                                            endwhile;
                                        } 
                                        ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Compare With</label>
                                    <select class="form-select" name="compare_with">
                                        <option value="previous_period" <?php echo $compare_with == 'previous_period' ? 'selected' : ''; ?>>Previous Period</option>
                                        <option value="previous_year" <?php echo $compare_with == 'previous_year' ? 'selected' : ''; ?>>Previous Year</option>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Generate Report
                                </button>
                                <a href="profit-reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Profit Summary Cards -->
                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-label">Total Income</div>
                            <div class="summary-value">₹ <?php echo number_format($total_income, 2); ?></div>
                            <div class="summary-sub">Interest + Charges</div>
                            <div class="growth-badge <?php echo $income_growth >= 0 ? 'positive' : 'negative'; ?>">
                                <i class="bi bi-arrow-<?php echo $income_growth >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($income_growth); ?>%
                            </div>
                        </div>

                        <div class="summary-card">
                            <div class="summary-label">Total Expenses</div>
                            <div class="summary-value">₹ <?php echo number_format($total_expenses, 2); ?></div>
                            <div class="summary-sub">Operating & Other Costs</div>
                            <div class="growth-badge <?php echo $expense_growth <= 0 ? 'positive' : 'negative'; ?>">
                                <i class="bi bi-arrow-<?php echo $expense_growth <= 0 ? 'down' : 'up'; ?>"></i>
                                <?php echo abs($expense_growth); ?>%
                            </div>
                        </div>

                        <div class="summary-card <?php echo $net_profit >= 0 ? 'profit' : 'loss'; ?>">
                            <div class="summary-label">Net Profit</div>
                            <div class="summary-value">₹ <?php echo number_format($net_profit, 2); ?></div>
                            <div class="summary-sub">Profit Margin: <?php echo $profit_margin; ?>%</div>
                            <div class="growth-badge">
                                <i class="bi bi-arrow-<?php echo $profit_growth >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($profit_growth); ?>%
                            </div>
                        </div>

                        <div class="summary-card">
                            <div class="summary-label">Transactions</div>
                            <div class="summary-value"><?php echo ($interest_data['interest_transactions'] ?? 0) + count($expenses_by_type); ?></div>
                            <div class="summary-sub">
                                Interest: <?php echo $interest_data['loans_with_interest'] ?? 0; ?> loans | 
                                Expenses: <?php echo count($expenses_by_type); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="chart-grid">
                        <div class="chart-card">
                            <div class="chart-title">Monthly Income Trend</div>
                            <div class="chart-container">
                                <canvas id="profitTrendChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">Income vs Expenses</div>
                            <div class="chart-container">
                                <canvas id="incomeExpenseChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Top Profit Sources -->
                    <?php if (!empty($top_sources)): ?>
                    <div class="sources-grid">
                        <?php foreach ($top_sources as $source): ?>
                        <div class="source-card">
                            <div class="source-icon">
                                <i class="bi bi-<?php 
                                    echo $source['source'] == 'Interest' ? 'percent' : 'receipt'; 
                                ?>"></i>
                            </div>
                            <div class="source-name"><?php echo $source['source']; ?></div>
                            <div class="source-amount">₹ <?php echo number_format($source['amount'], 2); ?></div>
                            <div class="source-count"><?php echo number_format($source['count']); ?> transactions</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Profit & Loss Statement -->
                    <div class="pl-table">
                        <div class="pl-title">Profit & Loss Statement</div>
                        <div class="pl-grid">
                            <div class="pl-section">
                                <div class="pl-section-title">Income</div>
                                <div class="pl-row">
                                    <span class="pl-label">Interest Income</span>
                                    <span class="pl-amount income">₹ <?php echo number_format($interest_data['total_interest'] ?? 0, 2); ?></span>
                                </div>
                                <div class="pl-row">
                                    <span class="pl-label">Receipt Charges</span>
                                    <span class="pl-amount income">₹ <?php echo number_format($charges_data['total_charges'] ?? 0, 2); ?></span>
                                </div>
                                <div class="pl-row total">
                                    <span class="pl-label">Total Income</span>
                                    <span class="pl-amount income">₹ <?php echo number_format($total_income, 2); ?></span>
                                </div>
                            </div>

                            <div class="pl-section">
                                <div class="pl-section-title">Expenses</div>
                                <?php if (!empty($expenses_by_type)): ?>
                                    <?php foreach ($expenses_by_type as $expense): ?>
                                    <div class="pl-row">
                                        <span class="pl-label"><?php echo htmlspecialchars($expense['expense_type']); ?></span>
                                        <span class="pl-amount expense">₹ <?php echo number_format($expense['expense_amount'], 2); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <div class="pl-row">
                                    <span class="pl-label">No expenses recorded</span>
                                    <span class="pl-amount expense">₹ 0.00</span>
                                </div>
                                <?php endif; ?>
                                <div class="pl-row total">
                                    <span class="pl-label">Total Expenses</span>
                                    <span class="pl-amount expense">₹ <?php echo number_format($total_expenses, 2); ?></span>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 30px; padding: 20px; background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%); border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; color: #4a5568; margin-bottom: 10px;">NET PROFIT / LOSS</div>
                            <div style="font-size: 36px; font-weight: 700; <?php echo $net_profit >= 0 ? 'color: #48bb78;' : 'color: #f56565;'; ?>">
                                ₹ <?php echo number_format($net_profit, 2); ?>
                            </div>
                            <div style="font-size: 14px; color: #718096; margin-top: 10px;">
                                Profit Margin: <?php echo $profit_margin; ?>% | Period: <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Profit Breakdown -->
                    <?php if (!empty($daily_data)): ?>
                    <div class="pl-table">
                        <div class="pl-title">Daily Profit Breakdown (Last 30 Days)</div>
                        <div class="table-responsive">
                            <table class="profit-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th class="text-right">Interest (₹)</th>
                                        <th class="text-right">Charges (₹)</th>
                                        <th class="text-right">Total Income (₹)</th>
                                        <th class="text-right">Expenses (₹)</th>
                                        <th class="text-right">Profit (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_data as $day): ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            // Check if date is valid before formatting
                                            if (!empty($day['date']) && $day['date'] != '0000-00-00') {
                                                echo date('d M Y', strtotime($day['date']));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-right">₹ <?php echo number_format($day['interest'], 2); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($day['charges'], 2); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($day['total_income'], 2); ?></td>
                                        <td class="text-right">₹ <?php echo number_format($day['expenses'], 2); ?></td>
                                        <td class="text-right <?php echo $day['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            ₹ <?php echo number_format($day['profit'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
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

        // Toggle custom date inputs
        function toggleCustomDates() {
            const dateRange = document.querySelector('select[name="date_range"]').value;
            const dateFromGroup = document.getElementById('dateFromGroup');
            const dateToGroup = document.getElementById('dateToGroup');
            
            if (dateRange === 'custom') {
                dateFromGroup.style.display = 'block';
                dateToGroup.style.display = 'block';
            } else {
                dateFromGroup.style.display = 'none';
                dateToGroup.style.display = 'none';
            }
        }

        // Profit Trend Chart
        <?php if (!empty($months) && !empty($monthly_income)): ?>
        new Chart(document.getElementById('profitTrendChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_reverse($months)); ?>,
                datasets: [{
                    label: 'Income',
                    data: <?php echo json_encode(array_reverse($monthly_income)); ?>,
                    borderColor: '#48bb78',
                    backgroundColor: '#48bb7820',
                    tension: 0.4,
                    fill: true
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
        <?php else: ?>
        // If no monthly data, show empty chart or message
        document.getElementById('profitTrendChart').parentNode.innerHTML = '<div style="text-align: center; padding: 50px; color: #718096;">No monthly data available</div>';
        <?php endif; ?>

        // Income vs Expense Pie Chart
        new Chart(document.getElementById('incomeExpenseChart'), {
            type: 'doughnut',
            data: {
                labels: ['Interest', 'Charges', 'Expenses'],
                datasets: [{
                    data: [
                        <?php echo $interest_data['total_interest'] ?? 0; ?>,
                        <?php echo $charges_data['total_charges'] ?? 0; ?>,
                        <?php echo $total_expenses; ?>
                    ],
                    backgroundColor: ['#48bb78', '#4299e1', '#f56565']
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

        // Auto-submit form when branch changes
        document.querySelector('select[name="branch_id"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
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