<?php
session_start();
$currentPage = 'bank-interest-difference';
$pageTitle = 'Bank Interest Difference Report';
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
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
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

// ==================== BANK LOAN INTEREST CALCULATION ====================

// 1. Bank Loan Interest Summary
$bank_interest_query = "SELECT 
                        COUNT(DISTINCT bl.id) as total_bank_loans,
                        COALESCE(SUM(bl.loan_amount), 0) as total_bank_loan_amount,
                        COALESCE(SUM(bl.total_interest), 0) as total_bank_interest,
                        COALESCE(SUM(bl.emi_amount * bl.tenure_months - bl.loan_amount), 0) as calculated_bank_interest,
                        COALESCE(AVG(bl.interest_rate), 0) as avg_bank_interest_rate,
                        COALESCE(SUM(bl.document_charge + bl.processing_fee), 0) as total_bank_charges,
                        COALESCE(SUM(bl.total_payable), 0) as total_bank_payable
                      FROM bank_loans bl
                      WHERE $where_clause";

$stmt = mysqli_prepare($conn, $bank_interest_query);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $bank_summary = mysqli_fetch_assoc($result);
} else {
    $result = mysqli_query($conn, $bank_interest_query);
    $bank_summary = mysqli_fetch_assoc($result);
}

// 2. Bank Loan EMI Payments (Interest collected)
$bank_emi_payments_query = "SELECT 
                            COUNT(DISTINCT be.id) as total_bank_emi_payments,
                            COALESCE(SUM(be.interest_amount), 0) as total_bank_interest_collected,
                            COALESCE(SUM(be.principal_amount), 0) as total_bank_principal_collected,
                            COALESCE(SUM(be.emi_amount), 0) as total_bank_emi_collected,
                            COUNT(DISTINCT CASE WHEN be.status = 'paid' THEN be.id END) as paid_bank_emis,
                            COUNT(DISTINCT CASE WHEN be.status = 'pending' THEN be.id END) as pending_bank_emis
                          FROM bank_loans bl
                          JOIN bank_loan_emi be ON bl.id = be.bank_loan_id
                          WHERE $where_clause AND be.status = 'paid'";

$stmt = mysqli_prepare($conn, $bank_emi_payments_query);
if ($stmt && !empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $bank_payments = mysqli_fetch_assoc($result);
} else {
    $result = mysqli_query($conn, $bank_emi_payments_query);
    $bank_payments = mysqli_fetch_assoc($result);
}

// ==================== PAWN LOAN INTEREST CALCULATION ====================

// 3. Pawn Loan Interest Summary
$pawn_interest_query = "SELECT 
                        COUNT(DISTINCT l.id) as total_pawn_loans,
                        COALESCE(SUM(l.loan_amount), 0) as total_pawn_loan_amount,
                        COALESCE(AVG(l.interest_amount), 0) as avg_pawn_interest_rate,
                        COALESCE(SUM(l.receipt_charge), 0) as total_pawn_charges
                      FROM loans l
                      WHERE DATE(l.receipt_date) BETWEEN ? AND ?";

$pawn_stmt = mysqli_prepare($conn, $pawn_interest_query);
if ($pawn_stmt) {
    mysqli_stmt_bind_param($pawn_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($pawn_stmt);
    $pawn_result = mysqli_stmt_get_result($pawn_stmt);
    $pawn_summary = mysqli_fetch_assoc($pawn_result);
} else {
    $pawn_summary = [
        'total_pawn_loans' => 0,
        'total_pawn_loan_amount' => 0,
        'avg_pawn_interest_rate' => 0,
        'total_pawn_charges' => 0
    ];
}

// 4. Pawn Loan Interest Collected
$pawn_interest_collected_query = "SELECT 
                                  COUNT(DISTINCT p.id) as total_pawn_interest_payments,
                                  COALESCE(SUM(p.interest_amount), 0) as total_pawn_interest_collected,
                                  COALESCE(SUM(p.principal_amount), 0) as total_pawn_principal_collected,
                                  COALESCE(SUM(p.total_amount), 0) as total_pawn_collected
                                FROM payments p
                                WHERE DATE(p.payment_date) BETWEEN ? AND ?";

$pawn_payments_stmt = mysqli_prepare($conn, $pawn_interest_collected_query);
if ($pawn_payments_stmt) {
    mysqli_stmt_bind_param($pawn_payments_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($pawn_payments_stmt);
    $pawn_payments_result = mysqli_stmt_get_result($pawn_payments_stmt);
    $pawn_payments = mysqli_fetch_assoc($pawn_payments_result);
} else {
    $pawn_payments = [
        'total_pawn_interest_payments' => 0,
        'total_pawn_interest_collected' => 0,
        'total_pawn_principal_collected' => 0,
        'total_pawn_collected' => 0
    ];
}

// ==================== INTEREST DIFFERENCE CALCULATION ====================

// Calculate differences
$bank_total_interest = ($bank_summary['total_bank_interest'] ?? 0) + ($bank_summary['total_bank_charges'] ?? 0);
$pawn_total_interest = ($pawn_payments['total_pawn_interest_collected'] ?? 0) + ($pawn_summary['total_pawn_charges'] ?? 0);

$interest_difference = $bank_total_interest - $pawn_total_interest;
$interest_difference_percentage = $bank_total_interest > 0 ? round(($interest_difference / $bank_total_interest) * 100, 2) : 0;

// Average rate difference
$rate_difference = ($bank_summary['avg_bank_interest_rate'] ?? 0) - ($pawn_summary['avg_pawn_interest_rate'] ?? 0);

// Loan volume comparison
$loan_volume_comparison = [
    'bank_count' => $bank_summary['total_bank_loans'] ?? 0,
    'pawn_count' => $pawn_summary['total_pawn_loans'] ?? 0,
    'bank_amount' => $bank_summary['total_bank_loan_amount'] ?? 0,
    'pawn_amount' => $pawn_summary['total_pawn_loan_amount'] ?? 0
];

// ==================== DETAILED BREAKDOWN ====================

// 5. Bank Loan Details with Interest
$bank_loan_details_query = "SELECT 
                            bl.id,
                            bl.loan_reference,
                            bl.loan_date,
                            bl.loan_amount,
                            bl.interest_rate as bank_interest_rate,
                            bl.tenure_months,
                            bl.emi_amount,
                            bl.total_interest as total_bank_interest,
                            bl.document_charge,
                            bl.processing_fee,
                            bl.total_payable,
                            bm.bank_short_name,
                            bm.bank_full_name,
                            c.customer_name,
                            c.mobile_number,
                            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
                            (SELECT COALESCE(SUM(interest_amount), 0) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as collected_interest,
                            (SELECT COALESCE(SUM(principal_amount), 0) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as collected_principal
                          FROM bank_loans bl
                          JOIN bank_master bm ON bl.bank_id = bm.id
                          JOIN customers c ON bl.customer_id = c.id
                          WHERE $where_clause
                          ORDER BY bl.loan_date DESC";

$bank_details_result = null;
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $bank_loan_details_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $bank_details_result = mysqli_stmt_get_result($stmt);
    }
} else {
    $bank_details_result = mysqli_query($conn, $bank_loan_details_query);
}

$bank_details = [];
if ($bank_details_result) {
    while ($row = mysqli_fetch_assoc($bank_details_result)) {
        $bank_details[] = $row;
    }
}

// 6. Pawn Loan Details with Interest
$pawn_loan_details_query = "SELECT 
                            l.id,
                            l.receipt_number,
                            l.receipt_date,
                            l.loan_amount as pawn_loan_amount,
                            l.interest_amount as pawn_interest_rate,
                            l.receipt_charge,
                            l.status,
                            c.customer_name,
                            c.mobile_number,
                            (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as collected_interest,
                            (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as collected_principal,
                            (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count
                          FROM loans l
                          JOIN customers c ON l.customer_id = c.id
                          WHERE DATE(l.receipt_date) BETWEEN ? AND ?
                          ORDER BY l.receipt_date DESC
                          LIMIT 20";

$pawn_details_stmt = mysqli_prepare($conn, $pawn_loan_details_query);
$pawn_details = [];
if ($pawn_details_stmt) {
    mysqli_stmt_bind_param($pawn_details_stmt, 'ss', $date_from, $date_to);
    mysqli_stmt_execute($pawn_details_stmt);
    $pawn_details_result = mysqli_stmt_get_result($pawn_details_stmt);
    
    while ($row = mysqli_fetch_assoc($pawn_details_result)) {
        $pawn_details[] = $row;
    }
}

// 7. Monthly Interest Comparison - FIXED: Corrected parameter count
$monthly_comparison_query = "SELECT 
                              DATE_FORMAT(month_date, '%Y-%m') as month,
                              COALESCE(bank_data.bank_interest, 0) as bank_interest,
                              COALESCE(pawn_data.pawn_interest, 0) as pawn_interest,
                              COALESCE(bank_data.bank_interest, 0) - COALESCE(pawn_data.pawn_interest, 0) as difference
                            FROM (
                              SELECT DATE_SUB(LAST_DAY(?), INTERVAL seq MONTH) as month_date
                              FROM (
                                SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 
                                UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                              ) numbers
                            ) months
                            LEFT JOIN (
                              SELECT 
                                DATE_FORMAT(bl.loan_date, '%Y-%m') as month,
                                COALESCE(SUM(bl.total_interest), 0) as bank_interest
                              FROM bank_loans bl
                              WHERE bl.loan_date BETWEEN DATE_SUB(?, INTERVAL 5 MONTH) AND ?
                              GROUP BY DATE_FORMAT(bl.loan_date, '%Y-%m')
                            ) bank_data ON DATE_FORMAT(months.month_date, '%Y-%m') = bank_data.month
                            LEFT JOIN (
                              SELECT 
                                DATE_FORMAT(p.payment_date, '%Y-%m') as month,
                                COALESCE(SUM(p.interest_amount), 0) as pawn_interest
                              FROM payments p
                              WHERE p.payment_date BETWEEN DATE_SUB(?, INTERVAL 5 MONTH) AND ?
                              GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
                            ) pawn_data ON DATE_FORMAT(months.month_date, '%Y-%m') = pawn_data.month
                            ORDER BY months.month_date DESC
                            LIMIT 6";

$monthly_stmt = mysqli_prepare($conn, $monthly_comparison_query);
if ($monthly_stmt) {
    // Bind 5 parameters: end_date (for last_day), end_date (for bank date_sub), end_date (for bank), 
    // end_date (for pawn date_sub), end_date (for pawn)
    mysqli_stmt_bind_param($monthly_stmt, 'sssss', $date_to, $date_to, $date_to, $date_to, $date_to);
    mysqli_stmt_execute($monthly_stmt);
    $monthly_result = mysqli_stmt_get_result($monthly_stmt);
    
    $monthly_data = [];
    $month_labels = [];
    $bank_monthly = [];
    $pawn_monthly = [];
    $diff_monthly = [];
    
    while ($row = mysqli_fetch_assoc($monthly_result)) {
        if ($row['month']) {
            $monthly_data[] = $row;
            $month_labels[] = date('M Y', strtotime($row['month'] . '-01'));
            $bank_monthly[] = floatval($row['bank_interest']);
            $pawn_monthly[] = floatval($row['pawn_interest']);
            $diff_monthly[] = floatval($row['difference']);
        }
    }
}

// 8. Bank-wise Interest Difference - FIXED: Simplified query to avoid parameter issues
$bank_wise_diff_query = "SELECT 
                          bm.id,
                          bm.bank_full_name,
                          bm.bank_short_name,
                          COUNT(DISTINCT bl.id) as bank_loan_count,
                          COALESCE(SUM(bl.total_interest), 0) as bank_total_interest,
                          COALESCE(AVG(bl.interest_rate), 0) as avg_bank_rate,
                          0 as related_pawn_interest
                        FROM bank_master bm
                        LEFT JOIN bank_loans bl ON bm.id = bl.bank_id
                        WHERE bl.id IS NOT NULL
                        GROUP BY bm.id, bm.bank_full_name, bm.bank_short_name
                        HAVING bank_loan_count > 0
                        ORDER BY bank_total_interest DESC";

$bank_wise_result = mysqli_query($conn, $bank_wise_diff_query);
$bank_wise_diff = [];
if ($bank_wise_result) {
    while ($row = mysqli_fetch_assoc($bank_wise_result)) {
        // For each bank, get related pawn interest from customers who have loans with this bank
        $related_pawn_query = "SELECT COALESCE(SUM(p.interest_amount), 0) as total
                              FROM payments p
                              JOIN loans l ON p.loan_id = l.id
                              WHERE l.customer_id IN (
                                SELECT DISTINCT customer_id 
                                FROM bank_loans 
                                WHERE bank_id = " . intval($row['id']) . "
                              )
                              AND p.payment_date BETWEEN '" . mysqli_real_escape_string($conn, $date_from) . "' 
                              AND '" . mysqli_real_escape_string($conn, $date_to) . "'";
        
        $related_result = mysqli_query($conn, $related_pawn_query);
        if ($related_result) {
            $related_data = mysqli_fetch_assoc($related_result);
            $row['related_pawn_interest'] = floatval($related_data['total'] ?? 0);
        } else {
            $row['related_pawn_interest'] = 0;
        }
        
        $row['interest_difference'] = $row['bank_total_interest'] - $row['related_pawn_interest'];
        $row['difference_percentage'] = $row['bank_total_interest'] > 0 
            ? round(($row['interest_difference'] / $row['bank_total_interest']) * 100, 2) 
            : 0;
        $bank_wise_diff[] = $row;
    }
}

// Handle export
if ($export) {
    exportInterestDifferenceReport($date_from, $date_to, $bank_summary, $pawn_summary, 
                                   $bank_payments, $pawn_payments, $bank_details, $pawn_details);
}

function exportInterestDifferenceReport($from, $to, $bank_summary, $pawn_summary, $bank_payments, $pawn_payments, $bank_details, $pawn_details) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="interest_difference_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['BANK INTEREST VS PAWN INTEREST DIFFERENCE REPORT']);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, ['Generated On', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Bank Summary
    fputcsv($output, ['BANK LOAN SUMMARY']);
    fputcsv($output, ['Total Bank Loans', $bank_summary['total_bank_loans'] ?? 0]);
    fputcsv($output, ['Total Bank Loan Amount', '₹ ' . number_format($bank_summary['total_bank_loan_amount'] ?? 0, 2)]);
    fputcsv($output, ['Total Bank Interest', '₹ ' . number_format($bank_summary['total_bank_interest'] ?? 0, 2)]);
    fputcsv($output, ['Average Bank Interest Rate', ($bank_summary['avg_bank_interest_rate'] ?? 0) . '%']);
    fputcsv($output, ['Total Bank Charges', '₹ ' . number_format($bank_summary['total_bank_charges'] ?? 0, 2)]);
    fputcsv($output, ['Total Bank Payable', '₹ ' . number_format($bank_summary['total_bank_payable'] ?? 0, 2)]);
    fputcsv($output, ['Bank Interest Collected', '₹ ' . number_format($bank_payments['total_bank_interest_collected'] ?? 0, 2)]);
    fputcsv($output, []);
    
    // Pawn Summary
    fputcsv($output, ['PAWN LOAN SUMMARY']);
    fputcsv($output, ['Total Pawn Loans', $pawn_summary['total_pawn_loans'] ?? 0]);
    fputcsv($output, ['Total Pawn Loan Amount', '₹ ' . number_format($pawn_summary['total_pawn_loan_amount'] ?? 0, 2)]);
    fputcsv($output, ['Average Pawn Interest Rate', ($pawn_summary['avg_pawn_interest_rate'] ?? 0) . '%']);
    fputcsv($output, ['Total Pawn Charges', '₹ ' . number_format($pawn_summary['total_pawn_charges'] ?? 0, 2)]);
    fputcsv($output, ['Pawn Interest Collected', '₹ ' . number_format($pawn_payments['total_pawn_interest_collected'] ?? 0, 2)]);
    fputcsv($output, []);
    
    // Difference Summary
    $bank_total = ($bank_summary['total_bank_interest'] ?? 0) + ($bank_summary['total_bank_charges'] ?? 0);
    $pawn_total = ($pawn_payments['total_pawn_interest_collected'] ?? 0) + ($pawn_summary['total_pawn_charges'] ?? 0);
    $difference = $bank_total - $pawn_total;
    $percentage = $bank_total > 0 ? round(($difference / $bank_total) * 100, 2) : 0;
    
    fputcsv($output, ['INTEREST DIFFERENCE SUMMARY']);
    fputcsv($output, ['Bank Total (Interest + Charges)', '₹ ' . number_format($bank_total, 2)]);
    fputcsv($output, ['Pawn Total (Interest + Charges)', '₹ ' . number_format($pawn_total, 2)]);
    fputcsv($output, ['Interest Difference', '₹ ' . number_format($difference, 2)]);
    fputcsv($output, ['Difference Percentage', $percentage . '%']);
    fputcsv($output, []);
    
    // Bank Details
    fputcsv($output, ['BANK LOAN DETAILS']);
    fputcsv($output, ['Loan Ref', 'Bank', 'Customer', 'Loan Date', 'Loan Amount', 'Interest Rate', 'Total Interest', 'Collected Interest']);
    
    foreach ($bank_details as $loan) {
        fputcsv($output, [
            $loan['loan_reference'] ?? '',
            $loan['bank_short_name'] ?? '',
            $loan['customer_name'] ?? '',
            $loan['loan_date'] ?? '',
            '₹ ' . number_format($loan['loan_amount'] ?? 0, 2),
            ($loan['bank_interest_rate'] ?? 0) . '%',
            '₹ ' . number_format($loan['total_bank_interest'] ?? 0, 2),
            '₹ ' . number_format($loan['collected_interest'] ?? 0, 2)
        ]);
    }
    
    fputcsv($output, []);
    
    // Pawn Details
    fputcsv($output, ['PAWN LOAN DETAILS']);
    fputcsv($output, ['Receipt No', 'Customer', 'Receipt Date', 'Loan Amount', 'Interest Rate', 'Charge', 'Collected Interest']);
    
    foreach ($pawn_details as $loan) {
        fputcsv($output, [
            $loan['receipt_number'] ?? '',
            $loan['customer_name'] ?? '',
            $loan['receipt_date'] ?? '',
            '₹ ' . number_format($loan['pawn_loan_amount'] ?? 0, 2),
            ($loan['pawn_interest_rate'] ?? 0) . '%',
            '₹ ' . number_format($loan['receipt_charge'] ?? 0, 2),
            '₹ ' . number_format($loan['collected_interest'] ?? 0, 2)
        ]);
    }
    
    fclose($output);
    exit();
}

// Helper function for formatting
function formatMoney($amount) {
    return '₹ ' . number_format($amount, 2);
}

function getDifferenceClass($difference) {
    if ($difference > 0) return 'text-success';
    if ($difference < 0) return 'text-danger';
    return '';
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

        .summary-card.bank {
            border-left: 4px solid #4299e1;
        }

        .summary-card.pawn {
            border-left: 4px solid #48bb78;
        }

        .summary-card.difference {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .summary-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 10px;
        }

        .summary-label.white {
            color: rgba(255,255,255,0.9);
        }

        .summary-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .summary-value.white {
            color: white;
        }

        .summary-sub {
            font-size: 13px;
            color: #a0aec0;
        }

        .summary-sub.white {
            color: rgba(255,255,255,0.8);
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

        /* Comparison Cards */
        .comparison-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .comparison-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .comparison-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .comparison-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .comparison-icon.bank {
            background: #4299e1;
            color: white;
        }

        .comparison-icon.pawn {
            background: #48bb78;
            color: white;
        }

        .comparison-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .comparison-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .comparison-item:last-child {
            border-bottom: none;
        }

        .comparison-label {
            color: #718096;
        }

        .comparison-value {
            font-weight: 600;
            color: #2d3748;
        }

        .difference-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }

        .difference-badge.positive {
            background: #c6f6d5;
            color: #22543d;
        }

        .difference-badge.negative {
            background: #fed7d7;
            color: #742a2a;
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

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-success {
            color: #48bb78;
            font-weight: 600;
        }

        .text-danger {
            color: #f56565;
            font-weight: 600;
        }

        .text-primary {
            color: #4299e1;
            font-weight: 600;
        }

        .amount {
            font-weight: 600;
        }

        /* Progress Bar */
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

        /* Info Box */
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

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .comparison-grid {
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
                <div class="report-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-arrow-left-right"></i>
                            Bank Interest Difference Report
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
                            Filter Report
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
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Generate Report
                                </button>
                                <a href="bank-interest-difference.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Cards -->
                    <div class="summary-grid">
                        <div class="summary-card bank">
                            <div class="summary-label">Bank Loan Interest</div>
                            <div class="summary-value"><?php echo formatMoney($bank_total_interest); ?></div>
                            <div class="summary-sub">
                                Loans: <?php echo number_format($bank_summary['total_bank_loans'] ?? 0); ?> | 
                                Rate: <?php echo number_format($bank_summary['avg_bank_interest_rate'] ?? 0, 2); ?>%
                            </div>
                        </div>

                        <div class="summary-card pawn">
                            <div class="summary-label">Pawn Loan Interest</div>
                            <div class="summary-value"><?php echo formatMoney($pawn_total_interest); ?></div>
                            <div class="summary-sub">
                                Loans: <?php echo number_format($pawn_summary['total_pawn_loans'] ?? 0); ?> | 
                                Rate: <?php echo number_format($pawn_summary['avg_pawn_interest_rate'] ?? 0, 2); ?>%
                            </div>
                        </div>

                        <div class="summary-card difference">
                            <div class="summary-label white">Interest Difference</div>
                            <div class="summary-value white <?php echo getDifferenceClass($interest_difference); ?>">
                                <?php echo formatMoney($interest_difference); ?>
                            </div>
                            <div class="summary-sub white">
                                Bank <?php echo $interest_difference > 0 ? 'Higher' : 'Lower'; ?> by <?php echo abs($interest_difference_percentage); ?>%
                            </div>
                        </div>

                        <div class="summary-card">
                            <div class="summary-label">Rate Difference</div>
                            <div class="summary-value <?php echo getDifferenceClass($rate_difference); ?>">
                                <?php echo number_format($rate_difference, 2); ?>%
                            </div>
                            <div class="summary-sub">
                                Bank Rate: <?php echo number_format($bank_summary['avg_bank_interest_rate'] ?? 0, 2); ?>% | 
                                Pawn Rate: <?php echo number_format($pawn_summary['avg_pawn_interest_rate'] ?? 0, 2); ?>%
                            </div>
                        </div>
                    </div>

                    <!-- Comparison Section -->
                    <div class="comparison-grid">
                        <div class="comparison-card">
                            <div class="comparison-header">
                                <div class="comparison-icon bank">
                                    <i class="bi bi-bank"></i>
                                </div>
                                <div class="comparison-title">Bank Loan Details</div>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Total Loans</span>
                                <span class="comparison-value"><?php echo number_format($bank_summary['total_bank_loans'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Total Loan Amount</span>
                                <span class="comparison-value"><?php echo formatMoney($bank_summary['total_bank_loan_amount'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Total Interest (Projected)</span>
                                <span class="comparison-value"><?php echo formatMoney($bank_summary['total_bank_interest'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Interest Collected</span>
                                <span class="comparison-value text-success"><?php echo formatMoney($bank_payments['total_bank_interest_collected'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Charges (Doc + Processing)</span>
                                <span class="comparison-value"><?php echo formatMoney($bank_summary['total_bank_charges'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">EMI Progress</span>
                                <span class="comparison-value">
                                    <?php echo $bank_payments['paid_bank_emis'] ?? 0; ?>/<?php echo ($bank_payments['paid_bank_emis'] ?? 0) + ($bank_payments['pending_bank_emis'] ?? 0); ?>
                                </span>
                            </div>
                        </div>

                        <div class="comparison-card">
                            <div class="comparison-header">
                                <div class="comparison-icon pawn">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div class="comparison-title">Pawn Loan Details</div>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Total Loans</span>
                                <span class="comparison-value"><?php echo number_format($pawn_summary['total_pawn_loans'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Total Loan Amount</span>
                                <span class="comparison-value"><?php echo formatMoney($pawn_summary['total_pawn_loan_amount'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Interest Collected</span>
                                <span class="comparison-value text-success"><?php echo formatMoney($pawn_payments['total_pawn_interest_collected'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Receipt Charges</span>
                                <span class="comparison-value"><?php echo formatMoney($pawn_summary['total_pawn_charges'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Principal Collected</span>
                                <span class="comparison-value"><?php echo formatMoney($pawn_payments['total_pawn_principal_collected'] ?? 0); ?></span>
                            </div>
                            <div class="comparison-item">
                                <span class="comparison-label">Payment Transactions</span>
                                <span class="comparison-value"><?php echo number_format($pawn_payments['total_pawn_interest_payments'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <?php if (!empty($monthly_data)): ?>
                    <div class="chart-grid">
                        <div class="chart-card">
                            <div class="chart-title">Monthly Interest Comparison</div>
                            <div class="chart-container">
                                <canvas id="monthlyComparisonChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">Interest Difference Trend</div>
                            <div class="chart-container">
                                <canvas id="differenceChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Bank-wise Difference -->
                    <?php if (!empty($bank_wise_diff)): ?>
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-pie-chart"></i>
                                Bank-wise Interest Difference
                            </h3>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Bank</th>
                                        <th class="text-right">Loans</th>
                                        <th class="text-right">Avg Rate</th>
                                        <th class="text-right">Bank Interest</th>
                                        <th class="text-right">Related Pawn Interest</th>
                                        <th class="text-right">Difference</th>
                                        <th class="text-right">% Diff</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bank_wise_diff as $bank): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($bank['bank_full_name']); ?></strong></td>
                                        <td class="text-right"><?php echo $bank['bank_loan_count']; ?></td>
                                        <td class="text-right"><?php echo number_format($bank['avg_bank_rate'], 2); ?>%</td>
                                        <td class="text-right amount"><?php echo formatMoney($bank['bank_total_interest']); ?></td>
                                        <td class="text-right amount"><?php echo formatMoney($bank['related_pawn_interest']); ?></td>
                                        <td class="text-right <?php echo getDifferenceClass($bank['interest_difference']); ?>">
                                            <?php echo formatMoney($bank['interest_difference']); ?>
                                        </td>
                                        <td class="text-right">
                                            <span class="badge badge-<?php echo $bank['difference_percentage'] > 0 ? 'success' : 'danger'; ?>">
                                                <?php echo $bank['difference_percentage']; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Bank Loan Details -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-bank"></i>
                                Bank Loan Interest Details
                            </h3>
                            <span><?php echo count($bank_details); ?> loans</span>
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
                                        <th class="text-right">Interest Rate</th>
                                        <th class="text-right">Total Interest</th>
                                        <th class="text-right">Collected</th>
                                        <th class="text-right">Pending</th>
                                        <th>Progress</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bank_details)): ?>
                                        <?php foreach ($bank_details as $loan): 
                                            $pending_interest = $loan['total_bank_interest'] - $loan['collected_interest'];
                                            $progress = $loan['total_bank_interest'] > 0 
                                                ? round(($loan['collected_interest'] / $loan['total_bank_interest']) * 100, 2) 
                                                : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($loan['loan_reference']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($loan['bank_short_name']); ?></td>
                                            <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($loan['loan_date'])); ?></td>
                                            <td class="text-right amount"><?php echo formatMoney($loan['loan_amount']); ?></td>
                                            <td class="text-right"><?php echo $loan['bank_interest_rate']; ?>%</td>
                                            <td class="text-right amount"><?php echo formatMoney($loan['total_bank_interest']); ?></td>
                                            <td class="text-right text-success"><?php echo formatMoney($loan['collected_interest']); ?></td>
                                            <td class="text-right text-warning"><?php echo formatMoney($pending_interest); ?></td>
                                            <td>
                                                <div class="progress" style="width: 80px;">
                                                    <div class="progress-bar bg-<?php echo $progress > 70 ? 'success' : ($progress > 30 ? 'warning' : 'danger'); ?>" 
                                                         style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <small><?php echo $loan['paid_emis']; ?>/<?php echo $loan['tenure_months']; ?> EMIs</small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px; color: #a0aec0;"></i>
                                                No bank loans found for the selected period
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pawn Loan Details -->
                    <div class="table-card">
                        <div class="table-header">
                            <h3 class="table-title">
                                <i class="bi bi-cash-stack"></i>
                                Pawn Loan Interest Details
                            </h3>
                            <span><?php echo count($pawn_details); ?> loans</span>
                        </div>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Receipt No</th>
                                        <th>Customer</th>
                                        <th>Receipt Date</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest Rate</th>
                                        <th class="text-right">Receipt Charge</th>
                                        <th class="text-right">Interest Collected</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($pawn_details)): ?>
                                        <?php foreach ($pawn_details as $loan): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($loan['receipt_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                            <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                            <td class="text-right amount"><?php echo formatMoney($loan['pawn_loan_amount']); ?></td>
                                            <td class="text-right"><?php echo $loan['pawn_interest_rate']; ?>%</td>
                                            <td class="text-right"><?php echo formatMoney($loan['receipt_charge']); ?></td>
                                            <td class="text-right text-success"><?php echo formatMoney($loan['collected_interest']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $loan['status'] == 'open' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px; color: #a0aec0;"></i>
                                                No pawn loans found for the selected period
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Info Box -->
                    <div class="info-box">
                        <div class="info-title">Analysis Summary</div>
                        <div class="info-value">
                            Bank loans generate <?php echo formatMoney(abs($interest_difference)); ?> 
                            <?php echo $interest_difference > 0 ? 'more' : 'less'; ?> interest compared to pawn loans 
                            (<?php echo abs($interest_difference_percentage); ?>% difference)
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

        // Monthly Comparison Chart
        <?php if (!empty($month_labels) && !empty($bank_monthly) && !empty($pawn_monthly)): ?>
        new Chart(document.getElementById('monthlyComparisonChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_reverse($month_labels)); ?>,
                datasets: [
                    {
                        label: 'Bank Interest',
                        data: <?php echo json_encode(array_reverse($bank_monthly)); ?>,
                        borderColor: '#4299e1',
                        backgroundColor: 'rgba(66, 153, 225, 0.1)',
                        tension: 0.4,
                        fill: false
                    },
                    {
                        label: 'Pawn Interest',
                        data: <?php echo json_encode(array_reverse($pawn_monthly)); ?>,
                        borderColor: '#48bb78',
                        backgroundColor: 'rgba(72, 187, 120, 0.1)',
                        tension: 0.4,
                        fill: false
                    }
                ]
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

        // Difference Chart
        new Chart(document.getElementById('differenceChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_reverse($month_labels)); ?>,
                datasets: [{
                    label: 'Interest Difference',
                    data: <?php echo json_encode(array_reverse($diff_monthly)); ?>,
                    backgroundColor: function(context) {
                        const value = context.raw;
                        return value >= 0 ? '#48bb78' : '#f56565';
                    },
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

        // Auto-submit form when filters change
        document.querySelector('select[name="bank_id"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
        
        document.querySelector('select[name="customer_id"]')?.addEventListener('change', function() {
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