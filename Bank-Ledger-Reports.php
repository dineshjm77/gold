<?php
session_start();
$currentPage = 'bank-ledger-reports';
$pageTitle = 'Bank Ledger Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'accountant', 'manager'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create bank_ledger table if it doesn't exist
$ledger_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_ledger'");
if (mysqli_num_rows($ledger_table_check) == 0) {
    $create_ledger = "CREATE TABLE IF NOT EXISTS `bank_ledger` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `entry_date` date NOT NULL,
        `bank_id` int(11) NOT NULL,
        `bank_account_id` int(11) DEFAULT NULL,
        `transaction_type` enum('credit','debit','transfer') NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `balance` decimal(15,2) NOT NULL,
        `reference_number` varchar(100) DEFAULT NULL,
        `description` text DEFAULT NULL,
        `payment_id` int(11) DEFAULT NULL,
        `loan_id` int(11) DEFAULT NULL,
        `investment_id` int(11) DEFAULT NULL,
        `expense_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `bank_id` (`bank_id`),
        KEY `bank_account_id` (`bank_account_id`),
        KEY `entry_date` (`entry_date`),
        KEY `transaction_type` (`transaction_type`),
        KEY `reference_number` (`reference_number`),
        KEY `payment_id` (`payment_id`),
        KEY `loan_id` (`loan_id`),
        KEY `created_by` (`created_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_ledger);
}

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'ledger';
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$bank_account_id = isset($_GET['bank_account_id']) ? intval($_GET['bank_account_id']) : 0;
$transaction_type = isset($_GET['transaction_type']) ? $_GET['transaction_type'] : 'all';
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

// Build WHERE conditions
$where_conditions = ["1=1"];
$params = [];
$types = '';

// Date filter
$where_conditions[] = "DATE(bl.entry_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;
$types .= 'ss';

// Bank filter
if ($bank_id > 0) {
    $where_conditions[] = "bl.bank_id = ?";
    $params[] = $bank_id;
    $types .= 'i';
}

// Bank account filter
if ($bank_account_id > 0) {
    $where_conditions[] = "bl.bank_account_id = ?";
    $params[] = $bank_account_id;
    $types .= 'i';
}

// Transaction type filter
if ($transaction_type != 'all') {
    $where_conditions[] = "bl.transaction_type = ?";
    $params[] = $transaction_type;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get bank accounts for dropdown
$accounts_query = "SELECT ba.*, bm.bank_short_name, bm.bank_full_name 
                   FROM bank_accounts ba 
                   JOIN bank_master bm ON ba.bank_id = bm.id 
                   WHERE ba.status = 1 
                   ORDER BY bm.bank_short_name, ba.account_holder_no";
$accounts_result = mysqli_query($conn, $accounts_query);

// ==================== LEDGER REPORT ====================
if ($report_type == 'ledger') {
    // Get ledger entries with pagination
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $offset = ($page - 1) * $limit;
    
    $ledger_query = "SELECT bl.*, 
                     b.bank_short_name, b.bank_full_name,
                     ba.account_holder_no, ba.bank_account_no,
                     u.name as created_by_name,
                     l.receipt_number as loan_receipt,
                     p.receipt_number as payment_receipt,
                     i.investment_no,
                     e.receipt_number as expense_receipt
                     FROM bank_ledger bl
                     LEFT JOIN bank_master b ON bl.bank_id = b.id
                     LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                     LEFT JOIN users u ON bl.created_by = u.id
                     LEFT JOIN loans l ON bl.loan_id = l.id
                     LEFT JOIN payments p ON bl.payment_id = p.id
                     LEFT JOIN investments i ON bl.investment_id = i.id
                     LEFT JOIN expense_details e ON bl.expense_id = e.id
                     WHERE $where_clause
                     ORDER BY bl.entry_date DESC, bl.id DESC
                     LIMIT ? OFFSET ?";
    
    // Add pagination parameters
    $ledger_params = array_merge($params, [$limit, $offset]);
    $ledger_types = $types . 'ii';
    
    $ledger_stmt = mysqli_prepare($conn, $ledger_query);
    if (!empty($ledger_params)) {
        mysqli_stmt_bind_param($ledger_stmt, $ledger_types, ...$ledger_params);
    }
    mysqli_stmt_execute($ledger_stmt);
    $ledger_result = mysqli_stmt_get_result($ledger_stmt);
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM bank_ledger bl WHERE $where_clause";
    $count_stmt = mysqli_prepare($conn, $count_query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
    $total_pages = ceil($total_records / $limit);
    
    // Calculate summary
    $summary_query = "SELECT 
                      COUNT(*) as total_entries,
                      SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credits,
                      SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debits,
                      COUNT(CASE WHEN transaction_type = 'credit' THEN 1 END) as credit_count,
                      COUNT(CASE WHEN transaction_type = 'debit' THEN 1 END) as debit_count,
                      COUNT(CASE WHEN transaction_type = 'transfer' THEN 1 END) as transfer_count
                      FROM bank_ledger bl
                      WHERE $where_clause";
    
    $summary_stmt = mysqli_prepare($conn, $summary_query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($summary_stmt);
    $summary_result = mysqli_stmt_get_result($summary_stmt);
    $summary = mysqli_fetch_assoc($summary_result);
    
    $net_flow = ($summary['total_credits'] ?? 0) - ($summary['total_debits'] ?? 0);
}

// ==================== BANK SUMMARY REPORT ====================
if ($report_type == 'bank_summary') {
    // Get summary by bank
    $bank_summary_query = "SELECT 
                           b.id,
                           b.bank_full_name,
                           b.bank_short_name,
                           COUNT(DISTINCT bl.id) as transaction_count,
                           SUM(CASE WHEN bl.transaction_type = 'credit' THEN bl.amount ELSE 0 END) as total_credits,
                           SUM(CASE WHEN bl.transaction_type = 'debit' THEN bl.amount ELSE 0 END) as total_debits,
                           (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                            FROM bank_ledger 
                            WHERE bank_id = b.id AND entry_date < ?) as opening_balance,
                           (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                            FROM bank_ledger 
                            WHERE bank_id = b.id AND entry_date <= ?) as closing_balance
                           FROM bank_master b
                           LEFT JOIN bank_ledger bl ON b.id = bl.bank_id AND DATE(bl.entry_date) BETWEEN ? AND ?
                           WHERE b.status = 1
                           GROUP BY b.id
                           ORDER BY b.bank_full_name";
    
    $bank_summary_stmt = mysqli_prepare($conn, $bank_summary_query);
    mysqli_stmt_bind_param($bank_summary_stmt, 'ssss', $date_from, $date_to, $date_from, $date_to);
    mysqli_stmt_execute($bank_summary_stmt);
    $bank_summary_result = mysqli_stmt_get_result($bank_summary_stmt);
    
    // Calculate grand totals
    $grand_credits = 0;
    $grand_debits = 0;
    $grand_opening = 0;
    $grand_closing = 0;
}

// ==================== ACCOUNT SUMMARY REPORT ====================
if ($report_type == 'account_summary') {
    // Get summary by bank account
    $account_summary_query = "SELECT 
                              ba.id,
                              ba.account_holder_no,
                              ba.bank_account_no,
                              ba.account_type,
                              b.bank_full_name,
                              b.bank_short_name,
                              COUNT(DISTINCT bl.id) as transaction_count,
                              SUM(CASE WHEN bl.transaction_type = 'credit' THEN bl.amount ELSE 0 END) as total_credits,
                              SUM(CASE WHEN bl.transaction_type = 'debit' THEN bl.amount ELSE 0 END) as total_debits,
                              (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                               FROM bank_ledger 
                               WHERE bank_account_id = ba.id AND entry_date < ?) as opening_balance,
                              (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                               FROM bank_ledger 
                               WHERE bank_account_id = ba.id AND entry_date <= ?) as closing_balance,
                              ba.opening_balance as initial_balance,
                              ba.as_on_date
                              FROM bank_accounts ba
                              JOIN bank_master b ON ba.bank_id = b.id
                              LEFT JOIN bank_ledger bl ON ba.id = bl.bank_account_id AND DATE(bl.entry_date) BETWEEN ? AND ?
                              WHERE ba.status = 1
                              GROUP BY ba.id
                              ORDER BY b.bank_full_name, ba.account_holder_no";
    
    $account_summary_stmt = mysqli_prepare($conn, $account_summary_query);
    mysqli_stmt_bind_param($account_summary_stmt, 'ssss', $date_from, $date_to, $date_from, $date_to);
    mysqli_stmt_execute($account_summary_stmt);
    $account_summary_result = mysqli_stmt_get_result($account_summary_stmt);
}

// ==================== DAILY SUMMARY REPORT ====================
if ($report_type == 'daily_summary') {
    // Get daily breakdown
    $daily_query = "SELECT 
                    DATE(entry_date) as trans_date,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debits,
                    COUNT(CASE WHEN transaction_type = 'credit' THEN 1 END) as credit_count,
                    COUNT(CASE WHEN transaction_type = 'debit' THEN 1 END) as debit_count
                    FROM bank_ledger bl
                    WHERE $where_clause
                    GROUP BY DATE(entry_date)
                    ORDER BY trans_date DESC";
    
    $daily_stmt = mysqli_prepare($conn, $daily_query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($daily_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($daily_stmt);
    $daily_result = mysqli_stmt_get_result($daily_stmt);
}

// ==================== MONTHLY TREND REPORT ====================
if ($report_type == 'monthly_trend') {
    // Get monthly trend for the last 12 months
    $trend_query = "SELECT 
                    DATE_FORMAT(entry_date, '%Y-%m') as month,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credits,
                    SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debits,
                    SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END) as net_flow
                    FROM bank_ledger
                    WHERE entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
                    ORDER BY month DESC";
    
    $trend_result = mysqli_query($conn, $trend_query);
    
    $months = [];
    $credits = [];
    $debits = [];
    $net = [];
    
    while ($row = mysqli_fetch_assoc($trend_result)) {
        $months[] = date('M Y', strtotime($row['month'] . '-01'));
        $credits[] = $row['total_credits'];
        $debits[] = $row['total_debits'];
        $net[] = $row['net_flow'];
    }
}

// ==================== TRANSACTION TYPE BREAKDOWN ====================
$type_breakdown_query = "SELECT 
                         transaction_type,
                         COUNT(*) as count,
                         SUM(amount) as total_amount,
                         AVG(amount) as avg_amount,
                         MIN(amount) as min_amount,
                         MAX(amount) as max_amount
                         FROM bank_ledger bl
                         WHERE $where_clause
                         GROUP BY transaction_type";
$type_breakdown_stmt = mysqli_prepare($conn, $type_breakdown_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($type_breakdown_stmt, $types, ...$params);
}
mysqli_stmt_execute($type_breakdown_stmt);
$type_breakdown_result = mysqli_stmt_get_result($type_breakdown_stmt);

// Handle export
if ($export) {
    exportBankLedgerReport($conn, $report_type, $date_from, $date_to, $bank_id, $bank_account_id);
}

function exportBankLedgerReport($conn, $type, $from, $to, $bank_id, $account_id) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bank_ledger_' . $type . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add report header
    fputcsv($output, ['BANK LEDGER REPORT - ' . strtoupper(str_replace('_', ' ', $type))]);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, ['Generated on', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    switch ($type) {
        case 'ledger':
            fputcsv($output, ['Date', 'Bank', 'Account', 'Type', 'Amount', 'Balance', 'Reference', 'Description', 'Source']);
            
            $query = "SELECT bl.*, b.bank_short_name, ba.account_holder_no,
                     l.receipt_number, p.receipt_number, i.investment_no, e.receipt_number
                     FROM bank_ledger bl
                     LEFT JOIN bank_master b ON bl.bank_id = b.id
                     LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
                     LEFT JOIN loans l ON bl.loan_id = l.id
                     LEFT JOIN payments p ON bl.payment_id = p.id
                     LEFT JOIN investments i ON bl.investment_id = i.id
                     LEFT JOIN expense_details e ON bl.expense_id = e.id
                     WHERE DATE(bl.entry_date) BETWEEN ? AND ?";
            
            $params = [$from, $to];
            $types = 'ss';
            
            if ($bank_id > 0) {
                $query .= " AND bl.bank_id = ?";
                $params[] = $bank_id;
                $types .= 'i';
            }
            
            if ($account_id > 0) {
                $query .= " AND bl.bank_account_id = ?";
                $params[] = $account_id;
                $types .= 'i';
            }
            
            $query .= " ORDER BY bl.entry_date DESC, bl.id DESC";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            while ($row = mysqli_fetch_assoc($result)) {
                $source = '';
                if ($row['loan_id']) $source = 'Loan: ' . $row['receipt_number'];
                elseif ($row['payment_id']) $source = 'Payment: ' . $row['receipt_number'];
                elseif ($row['investment_id']) $source = 'Investment: ' . $row['investment_no'];
                elseif ($row['expense_id']) $source = 'Expense: ' . $row['receipt_number'];
                
                fputcsv($output, [
                    $row['entry_date'],
                    $row['bank_short_name'] ?? '-',
                    $row['account_holder_no'] ?? '-',
                    ucfirst($row['transaction_type']),
                    $row['amount'],
                    $row['balance'],
                    $row['reference_number'] ?? '-',
                    $row['description'] ?? '-',
                    $source ?: '-'
                ]);
            }
            break;
            
        case 'bank_summary':
            fputcsv($output, ['Bank', 'Transactions', 'Credits', 'Debits', 'Opening Balance', 'Closing Balance', 'Net Flow']);
            
            $query = "SELECT 
                      b.bank_full_name,
                      COUNT(DISTINCT bl.id) as transaction_count,
                      COALESCE(SUM(CASE WHEN bl.transaction_type = 'credit' THEN bl.amount ELSE 0 END), 0) as total_credits,
                      COALESCE(SUM(CASE WHEN bl.transaction_type = 'debit' THEN bl.amount ELSE 0 END), 0) as total_debits,
                      (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                       FROM bank_ledger WHERE bank_id = b.id AND entry_date < ?) as opening_balance,
                      (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE -amount END), 0) 
                       FROM bank_ledger WHERE bank_id = b.id AND entry_date <= ?) as closing_balance
                      FROM bank_master b
                      LEFT JOIN bank_ledger bl ON b.id = bl.bank_id AND DATE(bl.entry_date) BETWEEN ? AND ?
                      WHERE b.status = 1
                      GROUP BY b.id
                      ORDER BY b.bank_full_name";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ssss', $from, $to, $from, $to);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            while ($row = mysqli_fetch_assoc($result)) {
                $net_flow = $row['total_credits'] - $row['total_debits'];
                fputcsv($output, [
                    $row['bank_full_name'],
                    $row['transaction_count'],
                    $row['total_credits'],
                    $row['total_debits'],
                    $row['opening_balance'],
                    $row['closing_balance'],
                    $net_flow
                ]);
            }
            break;
            
        case 'daily_summary':
            fputcsv($output, ['Date', 'Transactions', 'Credits', 'Debits', 'Net Flow']);
            
            $query = "SELECT 
                      DATE(entry_date) as trans_date,
                      COUNT(*) as transaction_count,
                      SUM(CASE WHEN transaction_type = 'credit' THEN amount ELSE 0 END) as total_credits,
                      SUM(CASE WHEN transaction_type = 'debit' THEN amount ELSE 0 END) as total_debits
                      FROM bank_ledger
                      WHERE DATE(entry_date) BETWEEN ? AND ?
                      GROUP BY DATE(entry_date)
                      ORDER BY trans_date DESC";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            while ($row = mysqli_fetch_assoc($result)) {
                $net_flow = $row['total_credits'] - $row['total_debits'];
                fputcsv($output, [
                    $row['trans_date'],
                    $row['transaction_count'],
                    $row['total_credits'],
                    $row['total_debits'],
                    $net_flow
                ]);
            }
            break;
    }
    
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
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- Chart.js -->
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

        .ledger-container {
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
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

        /* Report Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: white;
            color: #4a5568;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            background: #f7fafc;
            transform: translateY(-2px);
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
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

        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .ledger-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .ledger-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .ledger-table tbody tr:hover {
            background: #f7fafc;
        }

        .ledger-table tfoot {
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

        .badge-credit {
            background: #48bb78;
            color: white;
        }

        .badge-debit {
            background: #f56565;
            color: white;
        }

        .badge-transfer {
            background: #4299e1;
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

        .amount {
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-link {
            display: block;
            padding: 8px 12px;
            border-radius: 6px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
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
            
            .report-tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                width: 100%;
                justify-content: center;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .export-buttons {
                flex-direction: column;
                width: 100%;
            }
            
            .export-buttons .btn {
                width: 100%;
                justify-content: center;
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
                <div class="ledger-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-journal-bookmark-fill"></i>
                            Bank Ledger Reports
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

                    <!-- Report Type Tabs -->
                    <div class="report-tabs">
                        <a href="?report_type=ledger&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'ledger'])); ?>" class="tab-btn <?php echo $report_type == 'ledger' ? 'active' : ''; ?>">
                            <i class="bi bi-list-ul"></i> Ledger Entries
                        </a>
                        <a href="?report_type=bank_summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'bank_summary'])); ?>" class="tab-btn <?php echo $report_type == 'bank_summary' ? 'active' : ''; ?>">
                            <i class="bi bi-bank"></i> Bank Summary
                        </a>
                        <a href="?report_type=account_summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'account_summary'])); ?>" class="tab-btn <?php echo $report_type == 'account_summary' ? 'active' : ''; ?>">
                            <i class="bi bi-credit-card"></i> Account Summary
                        </a>
                        <a href="?report_type=daily_summary&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'daily_summary'])); ?>" class="tab-btn <?php echo $report_type == 'daily_summary' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-day"></i> Daily Summary
                        </a>
                        <a href="?report_type=monthly_trend&<?php echo http_build_query(array_merge($_GET, ['report_type' => 'monthly_trend'])); ?>" class="tab-btn <?php echo $report_type == 'monthly_trend' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up"></i> Monthly Trend
                        </a>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Reports
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                            
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
                                    <label class="form-label">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id" id="bank_id">
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
                                    <label class="form-label">Bank Account</label>
                                    <div class="input-group">
                                        <i class="bi bi-credit-card input-icon"></i>
                                        <select class="form-select" name="bank_account_id" id="bank_account_id">
                                            <option value="0">All Accounts</option>
                                            <?php 
                                            if ($accounts_result) {
                                                mysqli_data_seek($accounts_result, 0);
                                                while($acc = mysqli_fetch_assoc($accounts_result)): 
                                            ?>
                                                <option value="<?php echo $acc['id']; ?>" data-bank="<?php echo $acc['bank_id']; ?>" <?php echo $bank_account_id == $acc['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($acc['bank_short_name'] . ' - ' . $acc['account_holder_no']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Transaction Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-arrow-left-right input-icon"></i>
                                        <select class="form-select" name="transaction_type">
                                            <option value="all" <?php echo $transaction_type == 'all' ? 'selected' : ''; ?>>All Transactions</option>
                                            <option value="credit" <?php echo $transaction_type == 'credit' ? 'selected' : ''; ?>>Credits Only</option>
                                            <option value="debit" <?php echo $transaction_type == 'debit' ? 'selected' : ''; ?>>Debits Only</option>
                                            <option value="transfer" <?php echo $transaction_type == 'transfer' ? 'selected' : ''; ?>>Transfers Only</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="bank-ledger-reports.php?report_type=<?php echo $report_type; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <?php if ($report_type == 'ledger'): ?>
                        <!-- Summary Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-journal"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Total Entries</div>
                                    <div class="stat-value"><?php echo number_format($summary['total_entries'] ?? 0); ?></div>
                                    <div class="stat-sub">Credits: <?php echo $summary['credit_count'] ?? 0; ?> | Debits: <?php echo $summary['debit_count'] ?? 0; ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Total Credits</div>
                                    <div class="stat-value text-success">₹<?php echo number_format($summary['total_credits'] ?? 0, 2); ?></div>
                                    <div class="stat-sub">Inflow</div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-arrow-up-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Total Debits</div>
                                    <div class="stat-value text-danger">₹<?php echo number_format($summary['total_debits'] ?? 0, 2); ?></div>
                                    <div class="stat-sub">Outflow</div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-graph-up"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Net Flow</div>
                                    <div class="stat-value <?php echo $net_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        ₹<?php echo number_format(abs($net_flow), 2); ?>
                                    </div>
                                    <div class="stat-sub"><?php echo $net_flow >= 0 ? 'Net Inflow' : 'Net Outflow'; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Ledger Table -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Bank Ledger Entries</span>
                                <span class="table-info">
                                    Showing <?php echo mysqli_num_rows($ledger_result); ?> of <?php echo $total_records; ?> entries
                                </span>
                            </div>
                            <div class="table-responsive">
                                <table class="ledger-table" id="ledgerTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Bank</th>
                                            <th>Account</th>
                                            <th>Type</th>
                                            <th class="text-right">Amount (₹)</th>
                                            <th class="text-right">Balance (₹)</th>
                                            <th>Reference</th>
                                            <th>Description</th>
                                            <th>Source</th>
                                            <th>Created By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $running_balance = 0;
                                        while ($entry = mysqli_fetch_assoc($ledger_result)): 
                                            $badge_class = 'badge-' . $entry['transaction_type'];
                                            $source = '';
                                            if ($entry['loan_id']) $source = 'Loan: ' . $entry['loan_receipt'];
                                            elseif ($entry['payment_id']) $source = 'Payment: ' . $entry['payment_receipt'];
                                            elseif ($entry['investment_id']) $source = 'Investment: ' . $entry['investment_no'];
                                            elseif ($entry['expense_id']) $source = 'Expense: ' . $entry['expense_receipt'];
                                        ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($entry['entry_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($entry['bank_short_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($entry['account_holder_no'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($entry['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td class="text-right amount <?php echo $entry['transaction_type'] == 'credit' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $entry['transaction_type'] == 'credit' ? '+' : '-'; ?>
                                                ₹<?php echo number_format($entry['amount'], 2); ?>
                                            </td>
                                            <td class="text-right amount">₹<?php echo number_format($entry['balance'], 2); ?></td>
                                            <td><?php echo $entry['reference_number'] ?? '-'; ?></td>
                                            <td><?php echo htmlspecialchars($entry['description'] ?? '-'); ?></td>
                                            <td><?php echo $source ?: '-'; ?></td>
                                            <td><?php echo htmlspecialchars($entry['created_by_name'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endwhile; ?>

                                        <?php if (mysqli_num_rows($ledger_result) == 0): ?>
                                        <tr>
                                            <td colspan="10" class="text-center" style="padding: 50px;">
                                                <i class="bi bi-inbox" style="font-size: 48px; color: #a0aec0;"></i>
                                                <p style="margin-top: 10px;">No ledger entries found</p>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-link">
                                    <i class="bi bi-chevron-double-left"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                                    <i class="bi bi-chevron-double-right"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($report_type == 'bank_summary'): ?>
                        <!-- Bank Summary -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Bank-wise Summary</span>
                                <span class="table-info">Period: <?php echo date('d-m-Y', strtotime($date_from)); ?> to <?php echo date('d-m-Y', strtotime($date_to)); ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="ledger-table">
                                    <thead>
                                        <tr>
                                            <th>Bank</th>
                                            <th class="text-right">Transactions</th>
                                            <th class="text-right">Credits (₹)</th>
                                            <th class="text-right">Debits (₹)</th>
                                            <th class="text-right">Opening Balance</th>
                                            <th class="text-right">Closing Balance</th>
                                            <th class="text-right">Net Flow</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $grand_credits = 0;
                                        $grand_debits = 0;
                                        $grand_opening = 0;
                                        $grand_closing = 0;
                                        
                                        while ($bank = mysqli_fetch_assoc($bank_summary_result)): 
                                            $net_flow = $bank['total_credits'] - $bank['total_debits'];
                                            $grand_credits += $bank['total_credits'];
                                            $grand_debits += $bank['total_debits'];
                                            $grand_opening += $bank['opening_balance'];
                                            $grand_closing += $bank['closing_balance'];
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($bank['bank_full_name']); ?></strong></td>
                                            <td class="text-right"><?php echo $bank['transaction_count']; ?></td>
                                            <td class="text-right text-success">₹<?php echo number_format($bank['total_credits'], 2); ?></td>
                                            <td class="text-right text-danger">₹<?php echo number_format($bank['total_debits'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($bank['opening_balance'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($bank['closing_balance'], 2); ?></td>
                                            <td class="text-right <?php echo $net_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                ₹<?php echo number_format(abs($net_flow), 2); ?>
                                                <?php echo $net_flow >= 0 ? '(Inflow)' : '(Outflow)'; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background: #f7fafc; font-weight: 600;">
                                            <td class="text-right"><strong>TOTALS:</strong></td>
                                            <td class="text-right"><strong><?php echo $bank['transaction_count'] ?? 0; ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($grand_credits, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($grand_debits, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($grand_opening, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($grand_closing, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format(abs($grand_credits - $grand_debits), 2); ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'account_summary'): ?>
                        <!-- Account Summary -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Account-wise Summary</span>
                                <span class="table-info">Period: <?php echo date('d-m-Y', strtotime($date_from)); ?> to <?php echo date('d-m-Y', strtotime($date_to)); ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="ledger-table">
                                    <thead>
                                        <tr>
                                            <th>Bank</th>
                                            <th>Account</th>
                                            <th>Type</th>
                                            <th class="text-right">Transactions</th>
                                            <th class="text-right">Credits (₹)</th>
                                            <th class="text-right">Debits (₹)</th>
                                            <th class="text-right">Opening Balance</th>
                                            <th class="text-right">Closing Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($acc = mysqli_fetch_assoc($account_summary_result)): 
                                            $net_flow = $acc['total_credits'] - $acc['total_debits'];
                                            $calculated_opening = $acc['opening_balance'] + $acc['initial_balance'];
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($acc['bank_short_name']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($acc['account_holder_no']); ?></strong><br>
                                                <small><?php echo $acc['bank_account_no']; ?></small>
                                            </td>
                                            <td><?php echo ucfirst($acc['account_type']); ?></td>
                                            <td class="text-right"><?php echo $acc['transaction_count']; ?></td>
                                            <td class="text-right text-success">₹<?php echo number_format($acc['total_credits'], 2); ?></td>
                                            <td class="text-right text-danger">₹<?php echo number_format($acc['total_debits'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($acc['opening_balance'] + $acc['initial_balance'], 2); ?></td>
                                            <td class="text-right">₹<?php echo number_format($acc['closing_balance'] + $acc['initial_balance'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'daily_summary'): ?>
                        <!-- Daily Summary -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Daily Transaction Summary</span>
                                <span class="table-info">Period: <?php echo date('d-m-Y', strtotime($date_from)); ?> to <?php echo date('d-m-Y', strtotime($date_to)); ?></span>
                            </div>
                            <div class="table-responsive">
                                <table class="ledger-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th class="text-right">Transactions</th>
                                            <th class="text-right">Credits (₹)</th>
                                            <th class="text-right">Debits (₹)</th>
                                            <th class="text-right">Net Flow (₹)</th>
                                            <th class="text-right">Credit Count</th>
                                            <th class="text-right">Debit Count</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_credits = 0;
                                        $total_debits = 0;
                                        while ($day = mysqli_fetch_assoc($daily_result)): 
                                            $net_flow = $day['total_credits'] - $day['total_debits'];
                                            $total_credits += $day['total_credits'];
                                            $total_debits += $day['total_debits'];
                                        ?>
                                        <tr>
                                            <td><strong><?php echo date('d-m-Y', strtotime($day['trans_date'])); ?></strong></td>
                                            <td class="text-right"><?php echo $day['transaction_count']; ?></td>
                                            <td class="text-right text-success">₹<?php echo number_format($day['total_credits'], 2); ?></td>
                                            <td class="text-right text-danger">₹<?php echo number_format($day['total_debits'], 2); ?></td>
                                            <td class="text-right <?php echo $net_flow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                ₹<?php echo number_format(abs($net_flow), 2); ?>
                                            </td>
                                            <td class="text-right"><?php echo $day['credit_count']; ?></td>
                                            <td class="text-right"><?php echo $day['debit_count']; ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="background: #f7fafc; font-weight: 600;">
                                            <td class="text-right"><strong>TOTALS:</strong></td>
                                            <td class="text-right"><strong>-</strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($total_credits, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format($total_debits, 2); ?></strong></td>
                                            <td class="text-right"><strong>₹<?php echo number_format(abs($total_credits - $total_debits), 2); ?></strong></td>
                                            <td class="text-right" colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                    <?php elseif ($report_type == 'monthly_trend'): ?>
                        <!-- Monthly Trend Chart -->
                        <div class="chart-grid">
                            <div class="chart-card">
                                <div class="chart-title">Monthly Transaction Trend</div>
                                <div class="chart-container">
                                    <canvas id="trendChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-card">
                                <div class="chart-title">Transaction Type Breakdown</div>
                                <div class="chart-container">
                                    <canvas id="typeChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Monthly Trend Table -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">Monthly Trend (Last 12 Months)</span>
                            </div>
                            <div class="table-responsive">
                                <table class="ledger-table">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-right">Transactions</th>
                                            <th class="text-right">Credits (₹)</th>
                                            <th class="text-right">Debits (₹)</th>
                                            <th class="text-right">Net Flow (₹)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        mysqli_data_seek($trend_result, 0);
                                        while ($month = mysqli_fetch_assoc($trend_result)): 
                                        ?>
                                        <tr>
                                            <td><strong><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></strong></td>
                                            <td class="text-right"><?php echo $month['transaction_count']; ?></td>
                                            <td class="text-right text-success">₹<?php echo number_format($month['total_credits'], 2); ?></td>
                                            <td class="text-right text-danger">₹<?php echo number_format($month['total_debits'], 2); ?></td>
                                            <td class="text-right <?php echo $month['net_flow'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                ₹<?php echo number_format(abs($month['net_flow']), 2); ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <script>
                            // Monthly Trend Chart
                            new Chart(document.getElementById('trendChart'), {
                                type: 'line',
                                data: {
                                    labels: <?php echo json_encode(array_reverse($months)); ?>,
                                    datasets: [{
                                        label: 'Credits',
                                        data: <?php echo json_encode(array_reverse($credits)); ?>,
                                        borderColor: '#48bb78',
                                        backgroundColor: '#48bb7820',
                                        tension: 0.4,
                                        fill: true
                                    }, {
                                        label: 'Debits',
                                        data: <?php echo json_encode(array_reverse($debits)); ?>,
                                        borderColor: '#f56565',
                                        backgroundColor: '#f5656520',
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

                            // Transaction Type Breakdown Chart
                            <?php 
                            $type_data = [];
                            mysqli_data_seek($type_breakdown_result, 0);
                            while ($type = mysqli_fetch_assoc($type_breakdown_result)) {
                                $type_data[$type['transaction_type']] = $type['total_amount'];
                            }
                            ?>
                            new Chart(document.getElementById('typeChart'), {
                                type: 'doughnut',
                                data: {
                                    labels: ['Credits', 'Debits', 'Transfers'],
                                    datasets: [{
                                        data: [
                                            <?php echo $type_data['credit'] ?? 0; ?>,
                                            <?php echo $type_data['debit'] ?? 0; ?>,
                                            <?php echo $type_data['transfer'] ?? 0; ?>
                                        ],
                                        backgroundColor: ['#48bb78', '#f56565', '#4299e1']
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
                        </script>
                    <?php endif; ?>
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
    
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.form-select').select2({
                width: '100%'
            });
        });

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

        // Filter bank accounts based on selected bank
        document.getElementById('bank_id')?.addEventListener('change', function() {
            const bankId = this.value;
            const accountSelect = document.getElementById('bank_account_id');
            const options = accountSelect.options;
            
            for (let i = 0; i < options.length; i++) {
                const option = options[i];
                if (option.value === "0") continue;
                
                const optionBank = option.getAttribute('data-bank');
                if (bankId === "0" || optionBank === bankId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
            accountSelect.value = '0';
        });

        // Initialize DataTable for ledger entries
        <?php if ($report_type == 'ledger'): ?>
        $(document).ready(function() {
            $('#ledgerTable').DataTable({
                paging: false,
                searching: true,
                ordering: true,
                info: false,
                dom: 'Bfrtip',
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ]
            });
        });
        <?php endif; ?>

        // Auto-submit form when filters change
        document.querySelectorAll('select[name="bank_id"], select[name="bank_account_id"], select[name="transaction_type"]').forEach(select => {
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