<?php
session_start();
$currentPage = 'monthly-reports';
$pageTitle = 'Monthly Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get month selection
$report_type = isset($_GET['type']) ? $_GET['type'] : 'summary';
$branch_id = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$export = isset($_GET['export']) ? $_GET['export'] : '';

// Calculate month start and end dates
$start_date = date('Y-m-01', strtotime("$year-$month-01"));
$end_date = date('Y-m-t', strtotime("$year-$month-01"));

// Month name for display
$month_name = date('F', mktime(0, 0, 0, $month, 1, $year));

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get branches for filter
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Build WHERE clause for branch filter
$branch_where = "";
if ($branch_id > 0) {
    $branch_where = " AND branch_id = $branch_id";
}

// ==================== MONTHLY SUMMARY STATISTICS ====================

// Monthly Loan Statistics
$monthly_loans_query = "SELECT 
                        COUNT(*) as total_loans,
                        SUM(loan_amount) as total_amount,
                        SUM(net_weight) as total_weight,
                        COUNT(DISTINCT customer_id) as unique_customers,
                        AVG(loan_amount) as avg_loan_amount,
                        MAX(loan_amount) as max_loan,
                        MIN(loan_amount) as min_loan
                        FROM loans 
                        WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_loans_result = mysqli_query($conn, $monthly_loans_query);
$monthly_loans = mysqli_fetch_assoc($monthly_loans_result);

// Monthly Closed Loans
$monthly_closed_query = "SELECT 
                         COUNT(*) as total_closed,
                         SUM(loan_amount) as closed_amount,
                         SUM(payable_interest) as total_interest,
                         SUM(loan_amount + payable_interest - COALESCE(discount, 0) + COALESCE(round_off, 0)) as total_received,
                         AVG(DATEDIFF(close_date, receipt_date)) as avg_duration
                         FROM loans 
                         WHERE DATE(close_date) BETWEEN '$start_date' AND '$end_date' AND status = 'closed' $branch_where";
$monthly_closed_result = mysqli_query($conn, $monthly_closed_query);
$monthly_closed = mysqli_fetch_assoc($monthly_closed_result);

// Monthly Collections
$monthly_collections_query = "SELECT 
                              COUNT(*) as payment_count,
                              SUM(interest_amount) as total_interest,
                              SUM(principal_amount) as total_principal,
                              SUM(overdue_charge) as total_charges,
                              COUNT(DISTINCT loan_id) as loans_serviced,
                              SUM(CASE WHEN payment_mode = 'cash' THEN total_amount ELSE 0 END) as cash_collected,
                              SUM(CASE WHEN payment_mode = 'bank' THEN total_amount ELSE 0 END) as bank_collected,
                              SUM(CASE WHEN payment_mode = 'upi' THEN total_amount ELSE 0 END) as upi_collected
                              FROM payments 
                              WHERE DATE(payment_date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_collections_result = mysqli_query($conn, $monthly_collections_query);
$monthly_collections = mysqli_fetch_assoc($monthly_collections_result);

// Monthly Customer Statistics
$monthly_customers_query = "SELECT 
                            COUNT(*) as total_new,
                            COUNT(DISTINCT referral_person) as referred_count,
                            COUNT(CASE WHEN is_noted_person = 1 THEN 1 END) as noted_persons
                            FROM customers 
                            WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_customers_result = mysqli_query($conn, $monthly_customers_query);
$monthly_customers = mysqli_fetch_assoc($monthly_customers_result);

// Monthly Expenses
$monthly_expenses_query = "SELECT 
                           COUNT(*) as expense_count,
                           SUM(amount) as total_expenses,
                           AVG(amount) as avg_expense,
                           COUNT(DISTINCT expense_type) as expense_types
                           FROM expense_details 
                           WHERE DATE(date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_expenses_result = mysqli_query($conn, $monthly_expenses_query);
$monthly_expenses = mysqli_fetch_assoc($monthly_expenses_result);

// Monthly Investments
$monthly_investments_query = "SELECT 
                              COUNT(*) as investment_count,
                              SUM(investment_amount) as total_investment,
                              AVG(investment_amount) as avg_investment
                              FROM investments 
                              WHERE DATE(investment_date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_investments_result = mysqli_query($conn, $monthly_investments_query);
$monthly_investments = mysqli_fetch_assoc($monthly_investments_result);

// Monthly Investment Returns
$monthly_returns_query = "SELECT 
                          COUNT(*) as return_count,
                          SUM(payable_investment) as principal_returned,
                          SUM(payable_interest) as interest_paid
                          FROM investment_returns 
                          WHERE DATE(return_date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_returns_result = mysqli_query($conn, $monthly_returns_query);
$monthly_returns = mysqli_fetch_assoc($monthly_returns_result);

// Monthly Bank Loans
$monthly_bank_query = "SELECT 
                       COUNT(*) as loan_count,
                       SUM(loan_amount) as total_amount,
                       AVG(interest_rate) as avg_rate
                       FROM bank_loans 
                       WHERE DATE(loan_date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_bank_result = mysqli_query($conn, $monthly_bank_query);
$monthly_bank = mysqli_fetch_assoc($monthly_bank_result);

// Monthly Bank EMI Collections
$monthly_bank_emi_query = "SELECT 
                           COUNT(*) as payment_count,
                           SUM(payment_amount) as total_collected
                           FROM bank_loan_payments 
                           WHERE DATE(payment_date) BETWEEN '$start_date' AND '$end_date' $branch_where";
$monthly_bank_emi_result = mysqli_query($conn, $monthly_bank_emi_query);
$monthly_bank_emi = mysqli_fetch_assoc($monthly_bank_emi_result);

// ==================== WEEKLY BREAKDOWN FOR THE MONTH ====================
$weekly_breakdown_query = "SELECT 
                           WEEK(l.created_at, 1) - WEEK('$start_date', 1) + 1 as week_number,
                           MIN(DATE(l.created_at)) as week_start,
                           MAX(DATE(l.created_at)) as week_end,
                           COUNT(DISTINCT l.id) as new_loans,
                           SUM(l.loan_amount) as loan_amount,
                           COUNT(DISTINCT p.id) as payments,
                           SUM(p.interest_amount) as interest_collected,
                           SUM(p.principal_amount) as principal_collected,
                           COUNT(DISTINCT c.id) as new_customers,
                           COUNT(DISTINCT e.id) as expenses,
                           SUM(e.amount) as expense_amount
                           FROM loans l
                           LEFT JOIN payments p ON DATE(p.payment_date) BETWEEN '$start_date' AND '$end_date' 
                               AND p.loan_id IN (SELECT id FROM loans WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date')
                           LEFT JOIN customers c ON DATE(c.created_at) BETWEEN '$start_date' AND '$end_date'
                           LEFT JOIN expense_details e ON DATE(e.date) BETWEEN '$start_date' AND '$end_date'
                           WHERE DATE(l.created_at) BETWEEN '$start_date' AND '$end_date'
                           GROUP BY WEEK(l.created_at, 1)
                           ORDER BY week_number ASC";

$weekly_breakdown_result = mysqli_query($conn, $weekly_breakdown_query);

// ==================== DAILY BREAKDOWN FOR THE MONTH ====================
$daily_breakdown_query = "SELECT 
                          DATE(l.created_at) as date,
                          DAYNAME(l.created_at) as day_name,
                          DAYOFMONTH(l.created_at) as day_num,
                          COUNT(DISTINCT l.id) as new_loans,
                          SUM(l.loan_amount) as loan_amount,
                          COUNT(DISTINCT p.id) as payments,
                          SUM(p.interest_amount) as interest_collected,
                          SUM(p.principal_amount) as principal_collected,
                          COUNT(DISTINCT c.id) as new_customers,
                          COUNT(DISTINCT e.id) as expenses,
                          SUM(e.amount) as expense_amount
                          FROM loans l
                          LEFT JOIN payments p ON DATE(p.payment_date) = DATE(l.created_at) 
                              AND p.loan_id IN (SELECT id FROM loans WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date')
                          LEFT JOIN customers c ON DATE(c.created_at) = DATE(l.created_at)
                          LEFT JOIN expense_details e ON DATE(e.date) = DATE(l.created_at)
                          WHERE DATE(l.created_at) BETWEEN '$start_date' AND '$end_date' 
                          GROUP BY DATE(l.created_at), DAYNAME(l.created_at), DAYOFMONTH(l.created_at)
                          
                          UNION
                          
                          SELECT 
                          DATE(p.payment_date) as date,
                          DAYNAME(p.payment_date) as day_name,
                          DAYOFMONTH(p.payment_date) as day_num,
                          0 as new_loans,
                          0 as loan_amount,
                          COUNT(DISTINCT p.id) as payments,
                          SUM(p.interest_amount) as interest_collected,
                          SUM(p.principal_amount) as principal_collected,
                          0 as new_customers,
                          0 as expenses,
                          0 as expense_amount
                          FROM payments p
                          WHERE DATE(p.payment_date) BETWEEN '$start_date' AND '$end_date'
                          AND DATE(p.payment_date) NOT IN (SELECT DISTINCT DATE(created_at) FROM loans WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date')
                          GROUP BY DATE(p.payment_date), DAYNAME(p.payment_date), DAYOFMONTH(p.payment_date)
                          
                          ORDER BY date ASC";

$daily_breakdown_result = mysqli_query($conn, $daily_breakdown_query);

// ==================== DETAILED REPORTS BASED ON TYPE ====================
switch ($report_type) {
    case 'loans':
        // Detailed monthly loans
        $details_query = "SELECT l.*, c.customer_name, c.mobile_number,
                          u.name as employee_name,
                          COUNT(li.id) as item_count,
                          DAYNAME(l.created_at) as day_name,
                          DAYOFMONTH(l.created_at) as day_num
                          FROM loans l
                          LEFT JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON l.employee_id = u.id
                          LEFT JOIN loan_items li ON l.id = li.loan_id
                          WHERE DATE(l.created_at) BETWEEN '$start_date' AND '$end_date' $branch_where
                          GROUP BY l.id
                          ORDER BY l.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'collections':
        // Detailed monthly collections
        $details_query = "SELECT p.*, l.receipt_number, c.customer_name, c.mobile_number,
                          u.name as employee_name,
                          DAYNAME(p.payment_date) as day_name,
                          DAYOFMONTH(p.payment_date) as day_num
                          FROM payments p
                          JOIN loans l ON p.loan_id = l.id
                          JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON p.employee_id = u.id
                          WHERE DATE(p.payment_date) BETWEEN '$start_date' AND '$end_date' $branch_where
                          ORDER BY p.payment_date DESC, p.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'closed':
        // Detailed monthly closed loans
        $details_query = "SELECT l.*, c.customer_name, c.mobile_number,
                          u.name as employee_name,
                          DATEDIFF(l.close_date, l.receipt_date) as duration_days,
                          DAYNAME(l.close_date) as day_name,
                          DAYOFMONTH(l.close_date) as day_num
                          FROM loans l
                          LEFT JOIN customers c ON l.customer_id = c.id
                          LEFT JOIN users u ON l.employee_id = u.id
                          WHERE DATE(l.close_date) BETWEEN '$start_date' AND '$end_date' 
                          AND l.status = 'closed' $branch_where
                          ORDER BY l.close_date DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'customers':
        // Detailed monthly new customers
        $details_query = "SELECT *, 
                          DAYNAME(created_at) as day_name,
                          DAYOFMONTH(created_at) as day_num 
                          FROM customers 
                          WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' $branch_where
                          ORDER BY created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'expenses':
        // Detailed monthly expenses
        $details_query = "SELECT e.*, et.expense_type as category_name,
                          DAYNAME(e.date) as day_name,
                          DAYOFMONTH(e.date) as day_num
                          FROM expense_details e
                          LEFT JOIN expense_types et ON e.expense_type = et.expense_type
                          WHERE DATE(e.date) BETWEEN '$start_date' AND '$end_date' $branch_where
                          ORDER BY e.date DESC, e.created_at DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'performance':
        // Employee performance report for the month
        $details_query = "SELECT 
                          u.id, u.name, u.role,
                          COUNT(DISTINCT l.id) as loans_created,
                          SUM(l.loan_amount) as loan_amount,
                          COUNT(DISTINCT p.id) as collections_done,
                          SUM(p.interest_amount + p.principal_amount) as amount_collected,
                          COUNT(DISTINCT c.id) as customers_added
                          FROM users u
                          LEFT JOIN loans l ON l.employee_id = u.id 
                              AND DATE(l.created_at) BETWEEN '$start_date' AND '$end_date'
                          LEFT JOIN payments p ON p.employee_id = u.id 
                              AND DATE(p.payment_date) BETWEEN '$start_date' AND '$end_date'
                          LEFT JOIN customers c ON c.id = l.customer_id
                          WHERE u.status = 'active'
                          GROUP BY u.id, u.name, u.role
                          HAVING loans_created > 0 OR collections_done > 0
                          ORDER BY amount_collected DESC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    case 'trends':
        // Daily trends for the month
        $details_query = "SELECT 
                          DATE(l.created_at) as date,
                          DAYNAME(l.created_at) as day_name,
                          DAYOFMONTH(l.created_at) as day_num,
                          COUNT(DISTINCT l.id) as new_loans,
                          SUM(l.loan_amount) as loan_amount,
                          COUNT(DISTINCT p.id) as payments,
                          SUM(p.interest_amount + p.principal_amount) as collection_amount,
                          COUNT(DISTINCT c.id) as new_customers
                          FROM loans l
                          LEFT JOIN payments p ON DATE(p.payment_date) = DATE(l.created_at)
                          LEFT JOIN customers c ON DATE(c.created_at) = DATE(l.created_at)
                          WHERE DATE(l.created_at) BETWEEN '$start_date' AND '$end_date'
                          GROUP BY DATE(l.created_at)
                          ORDER BY date ASC";
        $details_result = mysqli_query($conn, $details_query);
        break;
        
    default:
        // Summary - no detailed query needed
        $details_result = null;
}

// Handle export
if ($export == 'excel' || $export == 'csv') {
    $filename = 'monthly_report_' . $month_name . '_' . $year . '.' . ($export == 'excel' ? 'xls' : 'csv');
    
    header('Content-Type: ' . ($export == 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    if ($export == 'csv') {
        $output = fopen('php://output', 'w');
    } else {
        echo "<table border='1'>";
    }
    
    // Export headers based on report type
    if ($report_type == 'loans') {
        $headers = ['Date', 'Day', 'Receipt No', 'Customer', 'Mobile', 'Loan Amount', 'Items', 'Employee'];
    } elseif ($report_type == 'collections') {
        $headers = ['Date', 'Day', 'Receipt No', 'Customer', 'Mobile', 'Principal', 'Interest', 'Total', 'Method', 'Employee'];
    } elseif ($report_type == 'closed') {
        $headers = ['Date', 'Day', 'Receipt No', 'Customer', 'Mobile', 'Loan Amount', 'Interest', 'Total Received', 'Duration', 'Employee'];
    } elseif ($report_type == 'customers') {
        $headers = ['Date', 'Day', 'Name', 'Mobile', 'Email', 'Guardian', 'Address'];
    } elseif ($report_type == 'expenses') {
        $headers = ['Date', 'Day', 'Expense Type', 'Details', 'Amount', 'Payment Method'];
    } elseif ($report_type == 'performance') {
        $headers = ['Employee', 'Role', 'Loans Created', 'Loan Amount', 'Collections', 'Amount Collected', 'Customers Added'];
    } elseif ($report_type == 'trends') {
        $headers = ['Date', 'Day', 'New Loans', 'Loan Amount', 'Payments', 'Collection Amount', 'New Customers'];
    } else {
        $headers = ['Monthly Report - ' . $month_name . ' ' . $year];
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
    if ($details_result && mysqli_num_rows($details_result) > 0) {
        while ($row = mysqli_fetch_assoc($details_result)) {
            $row_data = [];
            
            if ($report_type == 'loans') {
                $row_data = [
                    date('d-m-Y', strtotime($row['created_at'])),
                    $row['day_name'] ?? '',
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    $row['item_count'] ?? 0,
                    $row['employee_name'] ?? ''
                ];
            } elseif ($report_type == 'collections') {
                $row_data = [
                    date('d-m-Y', strtotime($row['payment_date'])),
                    $row['day_name'] ?? '',
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    safeNumberFormat($row['principal_amount'] ?? 0),
                    safeNumberFormat($row['interest_amount'] ?? 0),
                    safeNumberFormat($row['total_amount'] ?? 0),
                    ucfirst($row['payment_mode'] ?? 'cash'),
                    $row['employee_name'] ?? ''
                ];
            } elseif ($report_type == 'closed') {
                $row_data = [
                    date('d-m-Y', strtotime($row['close_date'])),
                    $row['day_name'] ?? '',
                    $row['receipt_number'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    safeNumberFormat($row['payable_interest'] ?? 0),
                    safeNumberFormat(($row['loan_amount'] ?? 0) + ($row['payable_interest'] ?? 0) - ($row['discount'] ?? 0)),
                    $row['duration_days'] . ' days',
                    $row['employee_name'] ?? ''
                ];
            } elseif ($report_type == 'customers') {
                $row_data = [
                    date('d-m-Y', strtotime($row['created_at'])),
                    $row['day_name'] ?? '',
                    $row['customer_name'] ?? '',
                    $row['mobile_number'] ?? '',
                    $row['email'] ?? '',
                    $row['guardian_name'] ?? '',
                    $row['street_name'] . ', ' . $row['city']
                ];
            } elseif ($report_type == 'expenses') {
                $row_data = [
                    date('d-m-Y', strtotime($row['date'])),
                    $row['day_name'] ?? '',
                    $row['category_name'] ?? $row['expense_type'],
                    $row['detail'] ?? '',
                    safeNumberFormat($row['amount'] ?? 0),
                    ucfirst($row['payment_method'] ?? 'cash')
                ];
            } elseif ($report_type == 'performance') {
                $row_data = [
                    $row['name'] ?? '',
                    ucfirst($row['role'] ?? ''),
                    $row['loans_created'] ?? 0,
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    $row['collections_done'] ?? 0,
                    safeNumberFormat($row['amount_collected'] ?? 0),
                    $row['customers_added'] ?? 0
                ];
            } elseif ($report_type == 'trends') {
                $row_data = [
                    date('d-m-Y', strtotime($row['date'])),
                    $row['day_name'] ?? '',
                    $row['new_loans'] ?? 0,
                    safeNumberFormat($row['loan_amount'] ?? 0),
                    $row['payments'] ?? 0,
                    safeNumberFormat($row['collection_amount'] ?? 0),
                    $row['new_customers'] ?? 0
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

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
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

        /* Month Selection Card */
        .month-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .month-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .month-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
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

        .month-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            color: #2d3748;
            margin-top: 15px;
        }

        .month-info span {
            color: #667eea;
            font-size: 18px;
        }

        .month-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Stats Grid */
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
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
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

        /* Report Type Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            background: #f7fafc;
            color: #4a5568;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .tab-btn:hover {
            background: #ebf4ff;
            color: #667eea;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        /* Summary Cards */
        .summary-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .summary-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .summary-header i {
            font-size: 20px;
            color: #667eea;
        }

        .summary-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            color: #718096;
            font-size: 14px;
        }

        .summary-value {
            font-weight: 600;
            color: #2d3748;
        }

        .positive {
            color: #48bb78;
        }

        .negative {
            color: #f56565;
        }

        /* Weekly Breakdown Table */
        .breakdown-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
        }

        .breakdown-table th {
            background: #f7fafc;
            padding: 10px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .breakdown-table td {
            padding: 10px;
            text-align: center;
            border-bottom: 1px solid #e2e8f0;
        }

        .week-highlight {
            font-weight: 600;
            color: #667eea;
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

        .badge-success {
            background: #48bb78;
            color: white;
        }

        .badge-warning {
            background: #ecc94b;
            color: #744210;
        }

        .badge-danger {
            background: #f56565;
            color: white;
        }

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .badge-purple {
            background: #9f7aea;
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

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .summary-section {
                grid-template-columns: 1fr;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .month-grid {
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
            
            .report-tabs {
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
                <div class="reports-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-calendar-month"></i>
                            Monthly Reports
                        </h1>
                    </div>

                    <!-- Month Selection -->
                    <div class="month-card">
                        <div class="month-title">
                            <i class="bi bi-calendar-range"></i>
                            Select Month
                        </div>
                        
                        <form method="GET" action="" id="reportForm">
                            <div class="month-grid">
                                <div class="form-group">
                                    <label class="form-label">Year</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-year input-icon"></i>
                                        <select class="form-select" name="year">
                                            <?php for($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>>
                                                    <?php echo $y; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Month</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-month input-icon"></i>
                                        <select class="form-select" name="month">
                                            <option value="1" <?php echo $month == 1 ? 'selected' : ''; ?>>January</option>
                                            <option value="2" <?php echo $month == 2 ? 'selected' : ''; ?>>February</option>
                                            <option value="3" <?php echo $month == 3 ? 'selected' : ''; ?>>March</option>
                                            <option value="4" <?php echo $month == 4 ? 'selected' : ''; ?>>April</option>
                                            <option value="5" <?php echo $month == 5 ? 'selected' : ''; ?>>May</option>
                                            <option value="6" <?php echo $month == 6 ? 'selected' : ''; ?>>June</option>
                                            <option value="7" <?php echo $month == 7 ? 'selected' : ''; ?>>July</option>
                                            <option value="8" <?php echo $month == 8 ? 'selected' : ''; ?>>August</option>
                                            <option value="9" <?php echo $month == 9 ? 'selected' : ''; ?>>September</option>
                                            <option value="10" <?php echo $month == 10 ? 'selected' : ''; ?>>October</option>
                                            <option value="11" <?php echo $month == 11 ? 'selected' : ''; ?>>November</option>
                                            <option value="12" <?php echo $month == 12 ? 'selected' : ''; ?>>December</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <div class="input-group">
                                        <i class="bi bi-building input-icon"></i>
                                        <select class="form-select" name="branch_id">
                                            <option value="0">All Branches</option>
                                            <?php 
                                            if ($branches_result && mysqli_num_rows($branches_result) > 0) {
                                                mysqli_data_seek($branches_result, 0);
                                                while($branch = mysqli_fetch_assoc($branches_result)): 
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
                                </div>

                                <div class="month-actions">
                                    <button type="button" class="btn btn-secondary" onclick="setCurrentMonth()">
                                        <i class="bi bi-calendar-check"></i> Current Month
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> View Report
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div class="month-info">
                            <i class="bi bi-calendar2-month"></i> 
                            <?php echo $month_name . ' ' . $year; ?>: 
                            <span><?php echo date('d M Y', strtotime($start_date)); ?></span> 
                            to 
                            <span><?php echo date('d M Y', strtotime($end_date)); ?></span>
                            (<?php echo date('t', strtotime($start_date)); ?> days)
                        </div>
                    </div>

                    <!-- Report Type Tabs -->
                    <div class="report-tabs">
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=summary" 
                           class="tab-btn <?php echo $report_type == 'summary' ? 'active' : ''; ?>">
                            <i class="bi bi-grid"></i> Summary
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=loans" 
                           class="tab-btn <?php echo $report_type == 'loans' ? 'active' : ''; ?>">
                            <i class="bi bi-plus-circle"></i> New Loans
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=collections" 
                           class="tab-btn <?php echo $report_type == 'collections' ? 'active' : ''; ?>">
                            <i class="bi bi-cash-coin"></i> Collections
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=closed" 
                           class="tab-btn <?php echo $report_type == 'closed' ? 'active' : ''; ?>">
                            <i class="bi bi-check2-circle"></i> Closed Loans
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=customers" 
                           class="tab-btn <?php echo $report_type == 'customers' ? 'active' : ''; ?>">
                            <i class="bi bi-people"></i> New Customers
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=expenses" 
                           class="tab-btn <?php echo $report_type == 'expenses' ? 'active' : ''; ?>">
                            <i class="bi bi-wallet2"></i> Expenses
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=performance" 
                           class="tab-btn <?php echo $report_type == 'performance' ? 'active' : ''; ?>">
                            <i class="bi bi-person-badge"></i> Performance
                        </a>
                        <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&branch_id=<?php echo $branch_id; ?>&type=trends" 
                           class="tab-btn <?php echo $report_type == 'trends' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up"></i> Daily Trends
                        </a>
                    </div>

                    <?php if ($report_type == 'summary'): ?>
                        <!-- Summary View - All Statistics -->
                        
                        <!-- Key Metrics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-plus-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">New Loans</div>
                                    <div class="stat-value"><?php echo intval($monthly_loans['total_loans'] ?? 0); ?></div>
                                    <div class="stat-sub">Amount: ₹<?php echo safeNumberFormat($monthly_loans['total_amount'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-check2-circle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Closed Loans</div>
                                    <div class="stat-value"><?php echo intval($monthly_closed['total_closed'] ?? 0); ?></div>
                                    <div class="stat-sub">Received: ₹<?php echo safeNumberFormat($monthly_closed['total_received'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-cash-coin"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Collections</div>
                                    <div class="stat-value">₹<?php echo safeNumberFormat(($monthly_collections['total_interest'] ?? 0) + ($monthly_collections['total_principal'] ?? 0)); ?></div>
                                    <div class="stat-sub">Payments: <?php echo intval($monthly_collections['payment_count'] ?? 0); ?></div>
                                </div>
                            </div>

                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">New Customers</div>
                                    <div class="stat-value"><?php echo intval($monthly_customers['total_new'] ?? 0); ?></div>
                                    <div class="stat-sub">Referred: <?php echo intval($monthly_customers['referred_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Charts Row -->
                        <div class="charts-row">
                            <div class="chart-card">
                                <div class="chart-title">
                                    <i class="bi bi-graph-up"></i>
                                    Weekly Trends
                                </div>
                                <div class="chart-container">
                                    <canvas id="weeklyChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-card">
                                <div class="chart-title">
                                    <i class="bi bi-pie-chart"></i>
                                    Payment Methods
                                </div>
                                <div class="chart-container">
                                    <canvas id="paymentMethodChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Weekly Breakdown -->
                        <div class="breakdown-card">
                            <div class="summary-header">
                                <i class="bi bi-table"></i>
                                <h3>Weekly Breakdown</h3>
                            </div>
                            <div class="table-responsive">
                                <table class="breakdown-table">
                                    <thead>
                                        <tr>
                                            <th>Week</th>
                                            <th>Date Range</th>
                                            <th>New Loans</th>
                                            <th>Loan Amount</th>
                                            <th>Payments</th>
                                            <th>Interest</th>
                                            <th>Principal</th>
                                            <th>New Customers</th>
                                            <th>Expenses</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($weekly_breakdown_result && mysqli_num_rows($weekly_breakdown_result) > 0) {
                                            mysqli_data_seek($weekly_breakdown_result, 0);
                                            while($week_data = mysqli_fetch_assoc($weekly_breakdown_result)) {
                                        ?>
                                            <tr>
                                                <td class="week-highlight">Week <?php echo $week_data['week_number']; ?></td>
                                                <td><?php echo date('d M', strtotime($week_data['week_start'])); ?> - <?php echo date('d M', strtotime($week_data['week_end'])); ?></td>
                                                <td><?php echo intval($week_data['new_loans']); ?></td>
                                                <td class="amount">₹<?php echo safeNumberFormat($week_data['loan_amount']); ?></td>
                                                <td><?php echo intval($week_data['payments']); ?></td>
                                                <td class="positive">₹<?php echo safeNumberFormat($week_data['interest_collected']); ?></td>
                                                <td class="amount">₹<?php echo safeNumberFormat($week_data['principal_collected']); ?></td>
                                                <td><?php echo intval($week_data['new_customers']); ?></td>
                                                <td class="negative">₹<?php echo safeNumberFormat($week_data['expense_amount']); ?></td>
                                            </tr>
                                        <?php 
                                            }
                                        } else {
                                            // No weekly data
                                            echo '<tr><td colspan="9" class="text-center">No weekly data available</td></tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Detailed Summary Sections -->
                        <div class="summary-section">
                            <!-- Loan Summary -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-cash-stack"></i>
                                    <h3>Loan Summary</h3>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">New Loans:</span>
                                    <span class="summary-value"><?php echo intval($monthly_loans['total_loans'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Amount:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($monthly_loans['total_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Average Amount:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_loans['avg_loan_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Weight:</span>
                                    <span class="summary-value"><?php echo safeNumberFormat($monthly_loans['total_weight'] ?? 0, 3); ?> g</span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Unique Customers:</span>
                                    <span class="summary-value"><?php echo intval($monthly_loans['unique_customers'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Closed Loans:</span>
                                    <span class="summary-value"><?php echo intval($monthly_closed['total_closed'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Avg Duration:</span>
                                    <span class="summary-value"><?php echo safeNumberFormat($monthly_closed['avg_duration'] ?? 0, 0); ?> days</span>
                                </div>
                            </div>

                            <!-- Collection Summary -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-cash-coin"></i>
                                    <h3>Collection Summary</h3>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Total Payments:</span>
                                    <span class="summary-value"><?php echo intval($monthly_collections['payment_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Interest Collected:</span>
                                    <span class="summary-value positive">₹<?php echo safeNumberFormat($monthly_collections['total_interest'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Principal Collected:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($monthly_collections['total_principal'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Overdue Charges:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_collections['total_charges'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Loans Serviced:</span>
                                    <span class="summary-value"><?php echo intval($monthly_collections['loans_serviced'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row" style="border-top: 2px solid #e2e8f0; margin-top: 10px; padding-top: 10px;">
                                    <span class="summary-label">Cash:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_collections['cash_collected'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Bank Transfer:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_collections['bank_collected'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">UPI:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_collections['upi_collected'] ?? 0); ?></span>
                                </div>
                            </div>

                            <!-- Other Transactions -->
                            <div class="summary-card">
                                <div class="summary-header">
                                    <i class="bi bi-arrow-left-right"></i>
                                    <h3>Other Transactions</h3>
                                </div>
                                
                                <h4 style="font-size: 14px; margin: 10px 0; color: #4a5568;">Customers</h4>
                                <div class="summary-row">
                                    <span class="summary-label">New Customers:</span>
                                    <span class="summary-value"><?php echo intval($monthly_customers['total_new'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Referred:</span>
                                    <span class="summary-value"><?php echo intval($monthly_customers['referred_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Noted Persons:</span>
                                    <span class="summary-value"><?php echo intval($monthly_customers['noted_persons'] ?? 0); ?></span>
                                </div>

                                <h4 style="font-size: 14px; margin: 15px 0 10px; color: #4a5568;">Expenses</h4>
                                <div class="summary-row">
                                    <span class="summary-label">Total Expenses:</span>
                                    <span class="summary-value negative">₹<?php echo safeNumberFormat($monthly_expenses['total_expenses'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Expense Count:</span>
                                    <span class="summary-value"><?php echo intval($monthly_expenses['expense_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Categories:</span>
                                    <span class="summary-value"><?php echo intval($monthly_expenses['expense_types'] ?? 0); ?></span>
                                </div>

                                <h4 style="font-size: 14px; margin: 15px 0 10px; color: #4a5568;">Investments</h4>
                                <div class="summary-row">
                                    <span class="summary-label">New Investments:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($monthly_investments['total_investment'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Investment Count:</span>
                                    <span class="summary-value"><?php echo intval($monthly_investments['investment_count'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Returns Processed:</span>
                                    <span class="summary-value">₹<?php echo safeNumberFormat($monthly_returns['principal_returned'] ?? 0); ?></span>
                                </div>

                                <h4 style="font-size: 14px; margin: 15px 0 10px; color: #4a5568;">Bank</h4>
                                <div class="summary-row">
                                    <span class="summary-label">New Bank Loans:</span>
                                    <span class="summary-value amount">₹<?php echo safeNumberFormat($monthly_bank['total_amount'] ?? 0); ?></span>
                                </div>
                                <div class="summary-row">
                                    <span class="summary-label">Bank EMI:</span>
                                    <span class="summary-value positive">₹<?php echo safeNumberFormat($monthly_bank_emi['total_collected'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Detailed View with Table -->
                        <div class="table-card">
                            <div class="table-header">
                                <span class="table-title">
                                    <i class="bi bi-list-ul"></i>
                                    <?php 
                                    switch($report_type) {
                                        case 'loans': echo 'New Loans - ' . $month_name . ' ' . $year; break;
                                        case 'collections': echo 'Collections - ' . $month_name . ' ' . $year; break;
                                        case 'closed': echo 'Closed Loans - ' . $month_name . ' ' . $year; break;
                                        case 'customers': echo 'New Customers - ' . $month_name . ' ' . $year; break;
                                        case 'expenses': echo 'Expenses - ' . $month_name . ' ' . $year; break;
                                        case 'performance': echo 'Employee Performance - ' . $month_name . ' ' . $year; break;
                                        case 'trends': echo 'Daily Trends - ' . $month_name . ' ' . $year; break;
                                    }
                                    ?>
                                    <span class="badge badge-info" style="margin-left: 10px;">
                                        <?php echo $details_result ? mysqli_num_rows($details_result) : 0; ?> records
                                    </span>
                                </span>
                                <div class="export-buttons">
                                    <button class="btn btn-success btn-sm" onclick="exportReport('excel')">
                                        <i class="bi bi-file-excel"></i> Excel
                                    </button>
                                    <button class="btn btn-info btn-sm" onclick="exportReport('csv')">
                                        <i class="bi bi-file-spreadsheet"></i> CSV
                                    </button>
                                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print
                                    </button>
                                </div>
                            </div>
                            
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <?php if ($report_type == 'loans'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th>Interest Type</th>
                                            <th class="text-center">Items</th>
                                            <th>Employee</th>
                                        </tr>
                                        <?php elseif ($report_type == 'collections'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Principal</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">Total</th>
                                            <th>Method</th>
                                            <th>Employee</th>
                                        </tr>
                                        <?php elseif ($report_type == 'closed'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Receipt No</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Interest</th>
                                            <th class="text-right">Total Received</th>
                                            <th class="text-right">Duration</th>
                                            <th>Employee</th>
                                        </tr>
                                        <?php elseif ($report_type == 'customers'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Name</th>
                                            <th>Mobile</th>
                                            <th>Email</th>
                                            <th>Guardian</th>
                                            <th>Address</th>
                                        </tr>
                                        <?php elseif ($report_type == 'expenses'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Expense Type</th>
                                            <th>Details</th>
                                            <th class="text-right">Amount</th>
                                            <th>Payment Method</th>
                                        </tr>
                                        <?php elseif ($report_type == 'performance'): ?>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Role</th>
                                            <th class="text-right">Loans Created</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Collections</th>
                                            <th class="text-right">Amount Collected</th>
                                            <th class="text-right">Customers Added</th>
                                        </tr>
                                        <?php elseif ($report_type == 'trends'): ?>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th class="text-right">New Loans</th>
                                            <th class="text-right">Loan Amount</th>
                                            <th class="text-right">Payments</th>
                                            <th class="text-right">Collection Amount</th>
                                            <th class="text-right">New Customers</th>
                                        </tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody>
                                        <?php if ($details_result && mysqli_num_rows($details_result) > 0): ?>
                                            <?php while($row = mysqli_fetch_assoc($details_result)): ?>
                                                <?php if ($report_type == 'loans'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['created_at'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td><?php echo ucfirst($row['interest_type']); ?></td>
                                                    <td class="text-center"><?php echo intval($row['item_count']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'collections'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['payment_date'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['principal_amount']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['interest_amount']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['total_amount']); ?></td>
                                                    <td><span class="badge badge-purple"><?php echo ucfirst($row['payment_mode']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'closed'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['close_date'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td><strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['payable_interest']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat(($row['loan_amount'] ?? 0) + ($row['payable_interest'] ?? 0) - ($row['discount'] ?? 0)); ?></td>
                                                    <td class="text-right"><?php echo intval($row['duration_days']); ?> days</td>
                                                    <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'customers'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['created_at'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($row['mobile_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['guardian_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['street_name'] . ', ' . $row['city']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'expenses'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td><?php echo htmlspecialchars($row['category_name'] ?? $row['expense_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($row['detail']); ?></td>
                                                    <td class="text-right negative">₹<?php echo safeNumberFormat($row['amount']); ?></td>
                                                    <td><?php echo ucfirst($row['payment_method']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'performance'): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                                    <td><span class="badge badge-<?php echo $row['role']; ?>"><?php echo ucfirst($row['role']); ?></span></td>
                                                    <td class="text-right"><?php echo intval($row['loans_created']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td class="text-right"><?php echo intval($row['collections_done']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['amount_collected']); ?></td>
                                                    <td class="text-right"><?php echo intval($row['customers_added']); ?></td>
                                                </tr>
                                                
                                                <?php elseif ($report_type == 'trends'): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                                    <td><span class="badge badge-info"><?php echo $row['day_name'] ?? ''; ?></span></td>
                                                    <td class="text-right"><?php echo intval($row['new_loans']); ?></td>
                                                    <td class="text-right amount">₹<?php echo safeNumberFormat($row['loan_amount']); ?></td>
                                                    <td class="text-right"><?php echo intval($row['payments']); ?></td>
                                                    <td class="text-right positive">₹<?php echo safeNumberFormat($row['collection_amount']); ?></td>
                                                    <td class="text-right"><?php echo intval($row['new_customers']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="10" class="text-center" style="padding: 40px;">
                                                    No records found for <?php echo $month_name . ' ' . $year; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
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

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Set to current month
        function setCurrentMonth() {
            const now = new Date();
            const year = now.getFullYear();
            const month = now.getMonth() + 1;
            
            document.querySelector('select[name="year"]').value = year;
            document.querySelector('select[name="month"]').value = month;
            document.getElementById('reportForm').submit();
        }

        // Export report
        function exportReport(format) {
            const url = new URL(window.location.href);
            url.searchParams.set('export', format);
            window.location.href = url.toString();
        }

        <?php if ($report_type == 'summary'): ?>
        // Weekly Chart
        const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
        
        <?php
        $week_labels = [];
        $week_loan_data = [];
        $week_collection_data = [];
        
        if ($weekly_breakdown_result && mysqli_num_rows($weekly_breakdown_result) > 0) {
            mysqli_data_seek($weekly_breakdown_result, 0);
            while($week = mysqli_fetch_assoc($weekly_breakdown_result)) {
                $week_labels[] = 'Week ' . $week['week_number'];
                $week_loan_data[] = floatval($week['loan_amount'] ?? 0);
                $week_collection_data[] = (floatval($week['interest_collected'] ?? 0) + floatval($week['principal_collected'] ?? 0));
            }
        } else {
            $week_labels = ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5'];
            $week_loan_data = [0, 0, 0, 0, 0];
            $week_collection_data = [0, 0, 0, 0, 0];
        }
        ?>
        
        new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($week_labels); ?>,
                datasets: [{
                    label: 'Loan Amount (₹)',
                    data: <?php echo json_encode($week_loan_data); ?>,
                    backgroundColor: 'rgba(102, 126, 234, 0.5)',
                    borderColor: '#667eea',
                    borderWidth: 1
                }, {
                    label: 'Collection Amount (₹)',
                    data: <?php echo json_encode($week_collection_data); ?>,
                    backgroundColor: 'rgba(72, 187, 120, 0.5)',
                    borderColor: '#48bb78',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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

        // Payment Method Chart
        const methodCtx = document.getElementById('paymentMethodChart').getContext('2d');
        new Chart(methodCtx, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Bank Transfer', 'UPI'],
                datasets: [{
                    data: [
                        <?php echo $monthly_collections['cash_collected'] ?? 0; ?>,
                        <?php echo $monthly_collections['bank_collected'] ?? 0; ?>,
                        <?php echo $monthly_collections['upi_collected'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#48bb78',
                        '#4299e1',
                        '#9f7aea'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ₹${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Show success/error messages
        <?php if (!empty($message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>