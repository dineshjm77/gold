<?php
session_start();
$currentPage = 'bank-loan-reports';
$pageTitle = 'Bank Loan Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'month';
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Build WHERE clause based on filters
$where_conditions = [];
$params = [];
$param_types = "";

if ($bank_id > 0) {
    $where_conditions[] = "bl.bank_id = ?";
    $params[] = $bank_id;
    $param_types .= "i";
}

if (!empty($from_date) && !empty($to_date)) {
    $where_conditions[] = "bl.loan_date BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
    $param_types .= "ss";
}

if ($status != 'all') {
    $where_conditions[] = "bl.status = ?";
    $params[] = $status;
    $param_types .= "s";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_loans,
                    SUM(CASE WHEN bl.status = 'active' THEN 1 ELSE 0 END) as active_loans,
                    SUM(CASE WHEN bl.status = 'closed' THEN 1 ELSE 0 END) as closed_loans,
                    SUM(CASE WHEN bl.status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_loans,
                    SUM(bl.loan_amount) as total_loan_amount,
                    SUM(bl.total_interest) as total_interest,
                    SUM(bl.document_charge + bl.processing_fee) as total_charges,
                    SUM(bl.total_payable) as total_payable,
                    AVG(bl.interest_rate) as avg_interest_rate,
                    AVG(bl.tenure_months) as avg_tenure,
                    MAX(bl.loan_amount) as max_loan,
                    MIN(bl.loan_amount) as min_loan
                  FROM bank_loans bl
                  $where_clause";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $summary_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $summary_result = mysqli_stmt_get_result($stmt);
} else {
    $summary_result = mysqli_query($conn, $summary_query);
}
$summary = mysqli_fetch_assoc($summary_result);

// Get collection summary (EMI payments)
$collection_query = "SELECT 
                        COUNT(DISTINCT bp.id) as total_payments,
                        SUM(bp.payment_amount) as total_collected,
                        AVG(bp.payment_amount) as avg_payment,
                        COUNT(DISTINCT bp.bank_loan_id) as loans_with_payments,
                        SUM(CASE WHEN bp.payment_method = 'cash' THEN bp.payment_amount ELSE 0 END) as cash_collected,
                        SUM(CASE WHEN bp.payment_method = 'bank' THEN bp.payment_amount ELSE 0 END) as bank_collected,
                        SUM(CASE WHEN bp.payment_method = 'upi' THEN bp.payment_amount ELSE 0 END) as upi_collected,
                        SUM(CASE WHEN bp.payment_method = 'cheque' THEN bp.payment_amount ELSE 0 END) as cheque_collected
                     FROM bank_loan_payments bp
                     JOIN bank_loans bl ON bp.bank_loan_id = bl.id
                     WHERE bp.payment_date BETWEEN ? AND ?";

$collection_params = [$from_date, $to_date];
$collection_types = "ss";

if ($bank_id > 0) {
    $collection_query .= " AND bl.bank_id = ?";
    $collection_params[] = $bank_id;
    $collection_types .= "i";
}

$collection_stmt = mysqli_prepare($conn, $collection_query);
mysqli_stmt_bind_param($collection_stmt, $collection_types, ...$collection_params);
mysqli_stmt_execute($collection_stmt);
$collection_result = mysqli_stmt_get_result($collection_stmt);
$collection = mysqli_fetch_assoc($collection_result);

// Get reports based on type
switch ($report_type) {
    case 'summary':
        // Summary report by month/bank
        if ($group_by == 'month') {
            $report_query = "SELECT 
                                DATE_FORMAT(bl.loan_date, '%Y-%m') as period,
                                COUNT(*) as loan_count,
                                SUM(bl.loan_amount) as total_amount,
                                SUM(bl.total_interest) as total_interest,
                                SUM(bl.document_charge + bl.processing_fee) as total_charges,
                                SUM(bl.total_payable) as total_payable,
                                AVG(bl.interest_rate) as avg_rate,
                                SUM(CASE WHEN bl.status = 'active' THEN 1 ELSE 0 END) as active_count,
                                SUM(CASE WHEN bl.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                                SUM(CASE WHEN bl.status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_count
                              FROM bank_loans bl
                              $where_clause
                              GROUP BY DATE_FORMAT(bl.loan_date, '%Y-%m')
                              ORDER BY period DESC";
        } else if ($group_by == 'bank') {
            $report_query = "SELECT 
                                b.id as bank_id,
                                b.bank_short_name,
                                b.bank_full_name,
                                COUNT(bl.id) as loan_count,
                                SUM(bl.loan_amount) as total_amount,
                                SUM(bl.total_interest) as total_interest,
                                SUM(bl.document_charge + bl.processing_fee) as total_charges,
                                SUM(bl.total_payable) as total_payable,
                                AVG(bl.interest_rate) as avg_rate,
                                SUM(CASE WHEN bl.status = 'active' THEN 1 ELSE 0 END) as active_count,
                                SUM(CASE WHEN bl.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                                SUM(CASE WHEN bl.status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_count
                              FROM bank_loans bl
                              JOIN bank_master b ON bl.bank_id = b.id
                              $where_clause
                              GROUP BY bl.bank_id
                              ORDER BY total_amount DESC";
        } else {
            $report_query = "SELECT 
                                bl.*,
                                b.bank_short_name,
                                b.bank_full_name,
                                c.customer_name,
                                c.mobile_number,
                                (SELECT COUNT(*) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as payment_count,
                                (SELECT SUM(payment_amount) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as total_paid
                              FROM bank_loans bl
                              LEFT JOIN bank_master b ON bl.bank_id = b.id
                              LEFT JOIN customers c ON bl.customer_id = c.id
                              $where_clause
                              ORDER BY bl.loan_date DESC";
        }
        break;

    case 'collection':
        // Collection report
        $report_query = "SELECT 
                            bp.*,
                            bl.loan_reference,
                            bl.loan_amount,
                            bl.interest_rate,
                            b.bank_short_name,
                            c.customer_name,
                            c.mobile_number,
                            u.name as employee_name
                          FROM bank_loan_payments bp
                          JOIN bank_loans bl ON bp.bank_loan_id = bl.id
                          LEFT JOIN bank_master b ON bl.bank_id = b.id
                          LEFT JOIN customers c ON bl.customer_id = c.id
                          LEFT JOIN users u ON bp.employee_id = u.id
                          WHERE bp.payment_date BETWEEN ? AND ?";

        $report_params = [$from_date, $to_date];
        $report_types = "ss";

        if ($bank_id > 0) {
            $report_query .= " AND bl.bank_id = ?";
            $report_params[] = $bank_id;
            $report_types .= "i";
        }

        $report_query .= " ORDER BY bp.payment_date DESC, bp.created_at DESC";
        
        $report_stmt = mysqli_prepare($conn, $report_query);
        mysqli_stmt_bind_param($report_stmt, $report_types, ...$report_params);
        mysqli_stmt_execute($report_stmt);
        $report_result = mysqli_stmt_get_result($report_stmt);
        break;

    case 'emi':
        // EMI schedule report
        $report_query = "SELECT 
                            be.*,
                            bl.loan_reference,
                            bl.loan_amount,
                            bl.interest_rate,
                            b.bank_short_name,
                            c.customer_name,
                            c.mobile_number,
                            DATEDIFF(CURDATE(), be.due_date) as days_overdue
                          FROM bank_loan_emi be
                          JOIN bank_loans bl ON be.bank_loan_id = bl.id
                          LEFT JOIN bank_master b ON bl.bank_id = b.id
                          LEFT JOIN customers c ON bl.customer_id = c.id";

        $emi_where = $where_conditions;
        
        if ($group_by == 'overdue') {
            $emi_where[] = "be.due_date < CURDATE() AND be.status = 'pending'";
        } else if ($group_by == 'upcoming') {
            $emi_where[] = "be.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND be.status = 'pending'";
        }

        $emi_where_clause = !empty($emi_where) ? " WHERE " . implode(" AND ", $emi_where) : "";
        $report_query .= $emi_where_clause . " ORDER BY be.due_date ASC";

        if (!empty($params)) {
            $report_stmt = mysqli_prepare($conn, $report_query);
            mysqli_stmt_bind_param($report_stmt, $param_types, ...$params);
            mysqli_stmt_execute($report_stmt);
            $report_result = mysqli_stmt_get_result($report_stmt);
        } else {
            $report_result = mysqli_query($conn, $report_query);
        }
        break;

    case 'interest':
        // Interest analysis report
        $report_query = "SELECT 
                            bl.id,
                            bl.loan_reference,
                            bl.loan_date,
                            bl.loan_amount,
                            bl.interest_rate,
                            bl.total_interest as expected_interest,
                            bl.tenure_months,
                            b.bank_short_name,
                            c.customer_name,
                            (SELECT SUM(payment_amount) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as total_paid,
                            (SELECT SUM(interest_amount) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as collected_interest,
                            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
                            (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis
                          FROM bank_loans bl
                          LEFT JOIN bank_master b ON bl.bank_id = b.id
                          LEFT JOIN customers c ON bl.customer_id = c.id
                          $where_clause
                          ORDER BY bl.loan_date DESC";

        if (!empty($params)) {
            $report_stmt = mysqli_prepare($conn, $report_query);
            mysqli_stmt_bind_param($report_stmt, $param_types, ...$params);
            mysqli_stmt_execute($report_stmt);
            $report_result = mysqli_stmt_get_result($report_stmt);
        } else {
            $report_result = mysqli_query($conn, $report_query);
        }
        break;

    default:
        // Default to summary by month
        $report_query = "SELECT 
                            DATE_FORMAT(bl.loan_date, '%Y-%m') as period,
                            COUNT(*) as loan_count,
                            SUM(bl.loan_amount) as total_amount,
                            SUM(bl.total_interest) as total_interest,
                            SUM(bl.total_payable) as total_payable,
                            AVG(bl.interest_rate) as avg_rate
                          FROM bank_loans bl
                          $where_clause
                          GROUP BY DATE_FORMAT(bl.loan_date, '%Y-%m')
                          ORDER BY period DESC";

        if (!empty($params)) {
            $report_stmt = mysqli_prepare($conn, $report_query);
            mysqli_stmt_bind_param($report_stmt, $param_types, ...$params);
            mysqli_stmt_execute($report_stmt);
            $report_result = mysqli_stmt_get_result($report_stmt);
        } else {
            $report_result = mysqli_query($conn, $report_query);
        }
}

// Handle export
if ($export == 'excel' || $export == 'csv') {
    $filename = 'bank_loan_report_' . date('Ymd_His') . '.' . ($export == 'excel' ? 'xls' : 'csv');
    
    header('Content-Type: ' . ($export == 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($export == 'csv') {
        $output = fopen('php://output', 'w');
    } else {
        echo "<table border='1'>";
    }
    
    // Output headers based on report type
    switch ($report_type) {
        case 'summary':
            if ($group_by == 'month') {
                $headers = ['Period', 'Loan Count', 'Total Amount', 'Total Interest', 'Total Charges', 'Total Payable', 'Avg Rate %', 'Active', 'Closed', 'Defaulted'];
            } else if ($group_by == 'bank') {
                $headers = ['Bank', 'Loan Count', 'Total Amount', 'Total Interest', 'Total Charges', 'Total Payable', 'Avg Rate %', 'Active', 'Closed', 'Defaulted'];
            } else {
                $headers = ['Date', 'Receipt No', 'Bank', 'Customer', 'Mobile', 'Loan Amount', 'Interest Rate', 'Tenure', 'Status', 'Payments', 'Total Paid'];
            }
            break;
        case 'collection':
            $headers = ['Date', 'Receipt No', 'Loan Ref', 'Bank', 'Customer', 'Amount', 'Method', 'Employee', 'Remarks'];
            break;
        case 'emi':
            if ($group_by == 'overdue' || $group_by == 'upcoming') {
                $headers = ['Due Date', 'Loan Ref', 'Bank', 'Customer', 'Installment', 'Principal', 'Interest', 'EMI Amount', 'Balance', 'Status', 'Days'];
            } else {
                $headers = ['Due Date', 'Loan Ref', 'Bank', 'Customer', 'Installment', 'Principal', 'Interest', 'EMI Amount', 'Balance', 'Status'];
            }
            break;
        case 'interest':
            $headers = ['Loan Ref', 'Date', 'Bank', 'Customer', 'Loan Amount', 'Rate %', 'Expected Interest', 'Collected Interest', 'Difference', 'Progress'];
            break;
        default:
            $headers = ['Period', 'Loans', 'Amount', 'Interest', 'Payable', 'Avg Rate'];
    }
    
    if ($export == 'csv') {
        fputcsv($output, $headers);
    } else {
        echo "<tr>";
        foreach ($headers as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
    }
    
    // Output data
    if (isset($report_result) && mysqli_num_rows($report_result) > 0) {
        mysqli_data_seek($report_result, 0);
        while ($row = mysqli_fetch_assoc($report_result)) {
            $row_data = [];
            
            switch ($report_type) {
                case 'summary':
                    if ($group_by == 'month') {
                        $row_data = [
                            $row['period'] ?? '',
                            $row['loan_count'] ?? 0,
                            safeNumberFormat($row['total_amount'] ?? 0),
                            safeNumberFormat($row['total_interest'] ?? 0),
                            safeNumberFormat($row['total_charges'] ?? 0),
                            safeNumberFormat($row['total_payable'] ?? 0),
                            safeNumberFormat($row['avg_rate'] ?? 0, 2) . '%',
                            $row['active_count'] ?? 0,
                            $row['closed_count'] ?? 0,
                            $row['defaulted_count'] ?? 0
                        ];
                    } else if ($group_by == 'bank') {
                        $row_data = [
                            $row['bank_short_name'] ?? '',
                            $row['loan_count'] ?? 0,
                            safeNumberFormat($row['total_amount'] ?? 0),
                            safeNumberFormat($row['total_interest'] ?? 0),
                            safeNumberFormat($row['total_charges'] ?? 0),
                            safeNumberFormat($row['total_payable'] ?? 0),
                            safeNumberFormat($row['avg_rate'] ?? 0, 2) . '%',
                            $row['active_count'] ?? 0,
                            $row['closed_count'] ?? 0,
                            $row['defaulted_count'] ?? 0
                        ];
                    } else {
                        $row_data = [
                            date('d-m-Y', strtotime($row['loan_date'])),
                            $row['loan_reference'] ?? '',
                            $row['bank_short_name'] ?? '',
                            $row['customer_name'] ?? '',
                            $row['mobile_number'] ?? '',
                            safeNumberFormat($row['loan_amount'] ?? 0),
                            safeNumberFormat($row['interest_rate'] ?? 0, 2) . '%',
                            $row['tenure_months'] . ' months',
                            ucfirst($row['status'] ?? ''),
                            $row['payment_count'] ?? 0,
                            safeNumberFormat($row['total_paid'] ?? 0)
                        ];
                    }
                    break;
                    
                case 'collection':
                    $row_data = [
                        date('d-m-Y', strtotime($row['payment_date'])),
                        $row['receipt_number'] ?? '',
                        $row['loan_reference'] ?? '',
                        $row['bank_short_name'] ?? '',
                        $row['customer_name'] ?? '',
                        safeNumberFormat($row['payment_amount'] ?? 0),
                        ucfirst($row['payment_method'] ?? ''),
                        $row['employee_name'] ?? '',
                        $row['remarks'] ?? ''
                    ];
                    break;
                    
                case 'emi':
                    $row_data = [
                        date('d-m-Y', strtotime($row['due_date'])),
                        $row['loan_reference'] ?? '',
                        $row['bank_short_name'] ?? '',
                        $row['customer_name'] ?? '',
                        $row['installment_no'] ?? 0,
                        safeNumberFormat($row['principal_amount'] ?? 0),
                        safeNumberFormat($row['interest_amount'] ?? 0),
                        safeNumberFormat($row['emi_amount'] ?? 0),
                        safeNumberFormat($row['balance_amount'] ?? 0),
                        ucfirst($row['status'] ?? '')
                    ];
                    if ($group_by == 'overdue' || $group_by == 'upcoming') {
                        $row_data[] = $row['days_overdue'] ?? 0;
                    }
                    break;
                    
                case 'interest':
                    $expected = floatval($row['expected_interest'] ?? 0);
                    $collected = floatval($row['collected_interest'] ?? 0);
                    $difference = $expected - $collected;
                    $progress = $row['total_emis'] > 0 ? round(($row['paid_emis'] / $row['total_emis']) * 100, 2) : 0;
                    
                    $row_data = [
                        $row['loan_reference'] ?? '',
                        date('d-m-Y', strtotime($row['loan_date'])),
                        $row['bank_short_name'] ?? '',
                        $row['customer_name'] ?? '',
                        safeNumberFormat($row['loan_amount'] ?? 0),
                        safeNumberFormat($row['interest_rate'] ?? 0, 2) . '%',
                        safeNumberFormat($expected),
                        safeNumberFormat($collected),
                        safeNumberFormat($difference),
                        $progress . '%'
                    ];
                    break;
                    
                default:
                    $row_data = [
                        $row['period'] ?? '',
                        $row['loan_count'] ?? 0,
                        safeNumberFormat($row['total_amount'] ?? 0),
                        safeNumberFormat($row['total_interest'] ?? 0),
                        safeNumberFormat($row['total_payable'] ?? 0),
                        safeNumberFormat($row['avg_rate'] ?? 0, 2) . '%'
                    ];
            }
            
            if ($export == 'csv') {
                fputcsv($output, $row_data);
            } else {
                echo "<tr>";
                foreach ($row_data as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
        }
    }
    
    if ($export == 'excel') {
        echo "</table>";
    } else {
        fclose($output);
    }
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .reports-container {
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
            gap: 10px;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* Report Type Selector */
        .report-type-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .report-type-btn {
            padding: 12px 24px;
            border-radius: 8px;
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .report-type-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .report-type-btn.active {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 13px;
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
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
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

        /* Charts Row */
        .charts-row {
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
            display: flex;
            align-items: center;
            gap: 10px;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            vertical-align: middle;
        }

        .report-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-active {
            background: #48bb78;
            color: white;
        }

        .badge-closed {
            background: #a0aec0;
            color: white;
        }

        .badge-defaulted {
            background: #f56565;
            color: white;
        }

        .badge-paid {
            background: #4299e1;
            color: white;
        }

        .badge-pending {
            background: #ecc94b;
            color: #744210;
        }

        .badge-overdue {
            background: #f56565;
            color: white;
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

        .positive {
            color: #48bb78;
            font-weight: 600;
        }

        .negative {
            color: #f56565;
            font-weight: 600;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #48bb78;
            border-radius: 3px;
        }

        .search-box {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-row {
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
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .export-buttons {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .search-box {
                width: 100%;
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
                <div class="reports-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-file-earmark-bar-graph"></i>
                            Bank Loan Reports
                        </h1>
                    </div>

                    <!-- Report Type Selector -->
                    <div class="report-type-selector">
                        <a href="?report_type=summary&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&bank_id=<?php echo $bank_id; ?>&status=<?php echo $status; ?>" 
                           class="report-type-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-grid"></i> Summary Report
                        </a>
                        <a href="?report_type=collection&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&bank_id=<?php echo $bank_id; ?>&status=<?php echo $status; ?>" 
                           class="report-type-btn <?php echo $report_type == 'collection' ? 'active' : ''; ?>">
                            <i class="bi bi-cash-stack"></i> Collection Report
                        </a>
                        <a href="?report_type=emi&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&bank_id=<?php echo $bank_id; ?>&status=<?php echo $status; ?>&group_by=all" 
                           class="report-type-btn <?php echo $report_type == 'emi' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar-check"></i> EMI Schedule
                        </a>
                        <a href="?report_type=interest&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&bank_id=<?php echo $bank_id; ?>&status=<?php echo $status; ?>" 
                           class="report-type-btn <?php echo $report_type == 'interest' ? 'active' : ''; ?>">
                            <i class="bi bi-percent"></i> Interest Analysis
                        </a>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Reports
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                            
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">From Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="from_date" value="<?php echo $from_date; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">To Date</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="to_date" value="<?php echo $to_date; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id">
                                            <option value="0">All Banks</option>
                                            <?php 
                                            if ($banks_result && mysqli_num_rows($banks_result) > 0) {
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
                                    <label class="form-label">Status</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="status">
                                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="closed" <?php echo $status == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                            <option value="defaulted" <?php echo $status == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                        </select>
                                    </div>
                                </div>

                                <?php if ($report_type == 'summary'): ?>
                                <div class="form-group">
                                    <label class="form-label">Group By</label>
                                    <div class="input-group">
                                        <i class="bi bi-layer-forward input-icon"></i>
                                        <select class="form-select" name="group_by">
                                            <option value="month" <?php echo $group_by == 'month' ? 'selected' : ''; ?>>By Month</option>
                                            <option value="bank" <?php echo $group_by == 'bank' ? 'selected' : ''; ?>>By Bank</option>
                                            <option value="detail" <?php echo $group_by == 'detail' ? 'selected' : ''; ?>>Detailed</option>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($report_type == 'emi'): ?>
                                <div class="form-group">
                                    <label class="form-label">View</label>
                                    <div class="input-group">
                                        <i class="bi bi-eye input-icon"></i>
                                        <select class="form-select" name="group_by">
                                            <option value="all" <?php echo $group_by == 'all' ? 'selected' : ''; ?>>All EMIs</option>
                                            <option value="overdue" <?php echo $group_by == 'overdue' ? 'selected' : ''; ?>>Overdue Only</option>
                                            <option value="upcoming" <?php echo $group_by == 'upcoming' ? 'selected' : ''; ?>>Upcoming (30 days)</option>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="filter-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                                    <i class="bi bi-file-excel"></i> Export Excel
                                </button>
                                <button type="button" class="btn btn-info" onclick="exportReport('csv')">
                                    <i class="bi bi-file-spreadsheet"></i> Export CSV
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Loans</div>
                                <div class="stat-value"><?php echo safeNumberFormat($summary['total_loans'] ?? 0, 0); ?></div>
                                <div class="stat-sub">
                                    Active: <?php echo safeNumberFormat($summary['active_loans'] ?? 0, 0); ?> | 
                                    Closed: <?php echo safeNumberFormat($summary['closed_loans'] ?? 0, 0); ?>
                                </div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Loan Amount</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($summary['total_loan_amount'] ?? 0); ?></div>
                                <div class="stat-sub">Max: ₹<?php echo safeNumberFormat($summary['max_loan'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Interest & Charges</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat(($summary['total_interest'] ?? 0) + ($summary['total_charges'] ?? 0)); ?></div>
                                <div class="stat-sub">Avg Rate: <?php echo safeNumberFormat($summary['avg_interest_rate'] ?? 0, 2); ?>%</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-piggy-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Collection</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($collection['total_collected'] ?? 0); ?></div>
                                <div class="stat-sub">Payments: <?php echo safeNumberFormat($collection['total_payments'] ?? 0, 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row (Only for summary report) -->
                    <?php if ($report_type == 'summary' && isset($report_result) && mysqli_num_rows($report_result) > 0 && ($group_by == 'month' || $group_by == 'bank')): ?>
                    <?php 
                        // Prepare chart data
                        $chart_labels = [];
                        $chart_amounts = [];
                        $chart_counts = [];
                        $chart_interests = [];
                        
                        mysqli_data_seek($report_result, 0);
                        while($row = mysqli_fetch_assoc($report_result)) {
                            if ($group_by == 'month') {
                                $chart_labels[] = $row['period'];
                                $chart_amounts[] = floatval($row['total_amount']);
                                $chart_counts[] = intval($row['loan_count']);
                                $chart_interests[] = floatval($row['total_interest']);
                            } else if ($group_by == 'bank') {
                                $chart_labels[] = $row['bank_short_name'];
                                $chart_amounts[] = floatval($row['total_amount']);
                                $chart_counts[] = intval($row['loan_count']);
                                $chart_interests[] = floatval($row['total_interest']);
                            }
                        }
                    ?>
                    <div class="charts-row">
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-graph-up"></i>
                                Loan Amount by <?php echo ucfirst($group_by); ?>
                            </div>
                            <div class="chart-container">
                                <canvas id="amountChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart"></i>
                                Loan Distribution
                            </div>
                            <div class="chart-container">
                                <canvas id="distributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Report Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-table"></i>
                                <?php 
                                switch($report_type) {
                                    case 'summary':
                                        echo 'Summary Report';
                                        break;
                                    case 'collection':
                                        echo 'Collection Report';
                                        break;
                                    case 'emi':
                                        echo 'EMI Schedule';
                                        break;
                                    case 'interest':
                                        echo 'Interest Analysis';
                                        break;
                                    default:
                                        echo 'Report Data';
                                }
                                ?>
                                <span class="badge badge-info" style="margin-left: 10px;">
                                    <?php echo isset($report_result) ? mysqli_num_rows($report_result) : 0; ?> records
                                </span>
                            </span>
                            <div>
                                <input type="text" id="tableSearch" class="search-box" placeholder="Search in table...">
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="report-table" id="reportTable">
                                <thead>
                                    <?php if ($report_type == 'summary'): ?>
                                        <?php if ($group_by == 'month'): ?>
                                        <tr>
                                            <th>Period</th>
                                            <th class="text-right">Loan Count</th>
                                            <th class="text-right">Total Amount</th>
                                            <th class="text-right">Total Interest</th>
                                            <th class="text-right">Total Charges</th>
                                            <th class="text-right">Total Payable</th>
                                            <th class="text-right">Avg Rate</th>
                                            <th class="text-right">Active</th>
                                            <th class="text-right">Closed</th>
                                            <th class="text-right">Defaulted</th>
                                        </tr>
                                        <?php elseif ($group_by == 'bank'): ?>
                                        <tr>
                                            <th>Bank</th>
                                            <th class="text-right">Loan Count</th>
                                            <th class="text-right">Total Amount</th>
                                            <th class="text-right">Total Interest</th>
                                            <th class="text-right">Total Charges</th>
                                            <th class="text-right">Total Payable</th>
                                            <th class="text-right">Avg Rate</th>
                                            <th class="text-right">Active</th>
                                            <th class="text-right">Closed</th>
                                            <th class="text-right">Defaulted</th>
                                        </tr>
                                        <?php else: ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt No</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Interest Rate</th>
                                            <th>Tenure</th>
                                            <th>Status</th>
                                            <th class="text-right">Payments</th>
                                            <th class="text-right">Total Paid</th>
                                        </tr>
                                        <?php endif; ?>
                                    <?php elseif ($report_type == 'collection'): ?>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Loan Ref</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th class="text-right">Amount</th>
                                        <th>Method</th>
                                        <th>Employee</th>
                                        <th>Remarks</th>
                                    </tr>
                                    <?php elseif ($report_type == 'emi'): ?>
                                        <?php if ($group_by == 'overdue' || $group_by == 'upcoming'): ?>
                                        <tr>
                                            <th>Due Date</th>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th class="text-right">Installment</th>
                                            <th class="text-right">Principal</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">EMI Amount</th>
                                            <th class="text-right">Balance</th>
                                            <th>Status</th>
                                            <th class="text-right">Days</th>
                                        </tr>
                                        <?php else: ?>
                                        <tr>
                                            <th>Due Date</th>
                                            <th>Loan Ref</th>
                                            <th>Bank</th>
                                            <th>Customer</th>
                                            <th class="text-right">Installment</th>
                                            <th class="text-right">Principal</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">EMI Amount</th>
                                            <th class="text-right">Balance</th>
                                            <th>Status</th>
                                        </tr>
                                        <?php endif; ?>
                                    <?php elseif ($report_type == 'interest'): ?>
                                    <tr>
                                        <th>Loan Ref</th>
                                        <th>Date</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Rate</th>
                                        <th class="text-right">Expected Interest</th>
                                        <th class="text-right">Collected Interest</th>
                                        <th class="text-right">Difference</th>
                                        <th class="text-right">Progress</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php 
                                    if (isset($report_result) && mysqli_num_rows($report_result) > 0):
                                        mysqli_data_seek($report_result, 0);
                                        while($row = mysqli_fetch_assoc($report_result)):
                                    ?>
                                        <?php if ($report_type == 'summary'): ?>
                                            <?php if ($group_by == 'month'): ?>
                                            <tr>
                                                <td><strong><?php echo $row['period']; ?></strong></td>
                                                <td class="text-right"><?php echo intval($row['loan_count']); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_amount']); ?></td>
                                                <td class="text-right">₹<?php echo safeNumberFormat($row['total_interest']); ?></td>
                                                <td class="text-right">₹<?php echo safeNumberFormat($row['total_charges'] ?? 0); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_payable']); ?></td>
                                                <td class="text-right"><?php echo safeNumberFormat($row['avg_rate'], 2); ?>%</td>
                                                <td class="text-right"><?php echo intval($row['active_count'] ?? 0); ?></td>
                                                <td class="text-right"><?php echo intval($row['closed_count'] ?? 0); ?></td>
                                                <td class="text-right"><?php echo intval($row['defaulted_count'] ?? 0); ?></td>
                                            </tr>
                                            <?php elseif ($group_by == 'bank'): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($row['bank_short_name']); ?></strong></td>
                                                <td class="text-right"><?php echo intval($row['loan_count']); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_amount']); ?></td>
                                                <td class="text-right">₹<?php echo safeNumberFormat($row['total_interest']); ?></td>
                                                <td class="text-right">₹<?php echo safeNumberFormat($row['total_charges'] ?? 0); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_payable']); ?></td>
                                                <td class="text-right"><?php echo safeNumberFormat($row['avg_rate'], 2); ?>%</td>
                                                <td class="text-right"><?php echo intval($row['active_count'] ?? 0); ?></td>
                                                <td class="text-right"><?php echo intval($row['closed_count'] ?? 0); ?></td>
                                                <td class="text-right"><?php echo intval($row['defaulted_count'] ?? 0); ?></td>
                                            </tr>
                                            <?php else: ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($row['loan_date'])); ?></td>
                                                <td><strong><?php echo htmlspecialchars($row['loan_reference']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($row['bank_short_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                <td class="text-right"><?php echo safeNumberFormat($row['interest_rate'], 2); ?>%</td>
                                                <td><?php echo intval($row['tenure_months']); ?> months</td>
                                                <td>
                                                    <span class="badge badge-<?php echo $row['status']; ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right"><?php echo intval($row['payment_count']); ?></td>
                                                <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_paid'] ?? 0); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            
                                        <?php elseif ($report_type == 'collection'): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($row['payment_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['loan_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($row['bank_short_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td class="text-right amount">₹<?php echo safeNumberFormat($row['payment_amount']); ?></td>
                                            <td>
                                                <span class="badge badge-info"><?php echo ucfirst($row['payment_method']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                                        </tr>
                                        
                                        <?php elseif ($report_type == 'emi'): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($row['due_date'])); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['loan_reference']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['bank_short_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td class="text-right">#<?php echo intval($row['installment_no']); ?></td>
                                            <td class="text-right">₹<?php echo safeNumberFormat($row['principal_amount']); ?></td>
                                            <td class="text-right">₹<?php echo safeNumberFormat($row['interest_amount']); ?></td>
                                            <td class="text-right amount">₹<?php echo safeNumberFormat($row['emi_amount']); ?></td>
                                            <td class="text-right">₹<?php echo safeNumberFormat($row['balance_amount']); ?></td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo $row['status'] == 'paid' ? 'paid' : 
                                                        ($row['status'] == 'overdue' ? 'overdue' : 'pending'); 
                                                ?>">
                                                    <?php echo ucfirst($row['status']); ?>
                                                </span>
                                            </td>
                                            <?php if ($group_by == 'overdue' || $group_by == 'upcoming'): ?>
                                            <td class="text-right <?php echo ($row['days_overdue'] ?? 0) > 30 ? 'negative' : ''; ?>">
                                                <?php echo intval($row['days_overdue'] ?? 0); ?> days
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        
                                        <?php elseif ($report_type == 'interest'): 
                                            $expected = floatval($row['expected_interest'] ?? 0);
                                            $collected = floatval($row['collected_interest'] ?? 0);
                                            $difference = $expected - $collected;
                                            $progress = $row['total_emis'] > 0 ? round(($row['paid_emis'] / $row['total_emis']) * 100, 2) : 0;
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['loan_reference']); ?></strong></td>
                                            <td><?php echo date('d-m-Y', strtotime($row['loan_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['bank_short_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                            <td class="text-right"><?php echo safeNumberFormat($row['interest_rate'], 2); ?>%</td>
                                            <td class="text-right">₹<?php echo safeNumberFormat($expected); ?></td>
                                            <td class="text-right <?php echo $collected > 0 ? 'positive' : ''; ?>">
                                                ₹<?php echo safeNumberFormat($collected); ?>
                                            </td>
                                            <td class="text-right <?php echo $difference > 0 ? 'negative' : 'positive'; ?>">
                                                ₹<?php echo safeNumberFormat($difference); ?>
                                            </td>
                                            <td class="text-right">
                                                <div style="display: flex; align-items: center; gap: 5px; justify-content: flex-end;">
                                                    <div class="progress-bar" style="width: 80px;">
                                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%;"></div>
                                                    </div>
                                                    <span><?php echo $progress; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="10" class="text-center" style="padding: 40px;">
                                                No data found for the selected filters
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                                <?php if ($report_type == 'summary' && $group_by != 'detail' && isset($report_result) && mysqli_num_rows($report_result) > 0): ?>
                                <?php 
                                    // Calculate totals for footer
                                    mysqli_data_seek($report_result, 0);
                                    $total_loans = 0;
                                    $total_amount = 0;
                                    $total_interest = 0;
                                    $total_charges = 0;
                                    $total_payable = 0;
                                    
                                    while($row = mysqli_fetch_assoc($report_result)) {
                                        $total_loans += intval($row['loan_count'] ?? 0);
                                        $total_amount += floatval($row['total_amount'] ?? 0);
                                        $total_interest += floatval($row['total_interest'] ?? 0);
                                        $total_charges += floatval($row['total_charges'] ?? 0);
                                        $total_payable += floatval($row['total_payable'] ?? 0);
                                    }
                                ?>
                                <tfoot style="background: #f7fafc; font-weight: 600;">
                                    <tr>
                                        <td>Total</td>
                                        <td class="text-right"><?php echo $total_loans; ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_amount); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_interest); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_charges); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($total_payable); ?></td>
                                        <td colspan="4"></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Reset filters
        function resetFilters() {
            document.querySelector('input[name="from_date"]').value = '<?php echo date('Y-m-01'); ?>';
            document.querySelector('input[name="to_date"]').value = '<?php echo date('Y-m-d'); ?>';
            document.querySelector('select[name="bank_id"]').value = '0';
            document.querySelector('select[name="status"]').value = 'all';
            <?php if ($report_type == 'summary'): ?>
            if (document.querySelector('select[name="group_by"]')) {
                document.querySelector('select[name="group_by"]').value = 'month';
            }
            <?php endif; ?>
            <?php if ($report_type == 'emi'): ?>
            if (document.querySelector('select[name="group_by"]')) {
                document.querySelector('select[name="group_by"]').value = 'all';
            }
            <?php endif; ?>
            document.getElementById('filterForm').submit();
        }

        // Export report with SweetAlert
        function exportReport(format) {
            Swal.fire({
                title: 'Export Report',
                text: 'Do you want to export the current report as ' + format.toUpperCase() + '?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Export',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Exporting...',
                        text: 'Please wait while we generate your report',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        willOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Redirect to export URL
                    const form = document.getElementById('filterForm');
                    const url = new URL(window.location.href);
                    url.searchParams.set('export', format);
                    
                    // Preserve all current parameters
                    const params = new URLSearchParams(new FormData(form));
                    params.forEach((value, key) => {
                        url.searchParams.set(key, value);
                    });
                    
                    window.location.href = url.toString();
                }
            });
        }

        // Simple table search functionality (replaces DataTables)
        document.getElementById('tableSearch').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const table = document.getElementById('reportTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                let found = false;
                const cells = rows[i].getElementsByTagName('td');
                
                for (let j = 0; j < cells.length; j++) {
                    const cellValue = cells[j].textContent || cells[j].innerText;
                    if (cellValue.toLowerCase().indexOf(searchValue) > -1) {
                        found = true;
                        break;
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        });

        // Initialize Charts for summary report
        <?php if ($report_type == 'summary' && isset($chart_labels) && !empty($chart_labels)): ?>
        
        // Amount Chart
        new Chart(document.getElementById('amountChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Loan Amount (₹)',
                    data: <?php echo json_encode($chart_amounts); ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.5)',
                    borderColor: '#667eea',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Interest (₹)',
                    data: <?php echo json_encode($chart_interests); ?>,
                    type: 'line',
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Amount (₹)'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Interest (₹)'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Distribution Chart
        new Chart(document.getElementById('distributionChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($chart_counts); ?>,
                    backgroundColor: [
                        '#667eea', '#48bb78', '#f56565', '#ecc94b', 
                        '#4299e1', '#9f7aea', '#ed8936', '#f687b3'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
        <?php endif; ?>

        // Show success message if export was successful
        <?php if (isset($_GET['export']) && $_GET['export'] != ''): ?>
        Swal.fire({
            icon: 'success',
            title: 'Export Successful',
            text: 'Your report has been exported successfully',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        // Show error message if any
        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>