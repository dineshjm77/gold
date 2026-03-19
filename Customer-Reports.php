<?php
session_start();
$currentPage = 'customer-reports';
$pageTitle = 'Customer Reports';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check role access
if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'manager' && $_SESSION['user_role'] !== 'accountant') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$loan_status = isset($_GET['loan_status']) ? $_GET['loan_status'] : '';

// Get all customers for dropdown
$customers_query = "SELECT id, customer_name, mobile_number FROM customers ORDER BY customer_name";
$customers_result = mysqli_query($conn, $customers_query);

// Get summary statistics
$summary_stats = [];

// Total customers
$total_customers_query = "SELECT COUNT(*) as total FROM customers";
$total_customers_result = mysqli_query($conn, $total_customers_query);
$summary_stats['total_customers'] = mysqli_fetch_assoc($total_customers_result)['total'];

// New customers in date range
$new_customers_query = "SELECT COUNT(*) as total FROM customers WHERE DATE(created_at) BETWEEN ? AND ?";
$new_customers_stmt = mysqli_prepare($conn, $new_customers_query);
mysqli_stmt_bind_param($new_customers_stmt, 'ss', $from_date, $to_date);
mysqli_stmt_execute($new_customers_stmt);
$new_customers_result = mysqli_stmt_get_result($new_customers_stmt);
$summary_stats['new_customers'] = mysqli_fetch_assoc($new_customers_result)['total'];

// Active loan customers
$active_loan_customers_query = "SELECT COUNT(DISTINCT customer_id) as total FROM loans WHERE status = 'open'";
$active_loan_customers_result = mysqli_query($conn, $active_loan_customers_query);
$summary_stats['active_loan_customers'] = mysqli_fetch_assoc($active_loan_customers_result)['total'];

// Total loans amount
$total_loan_amount_query = "SELECT COALESCE(SUM(loan_amount), 0) as total FROM loans WHERE status = 'open'";
$total_loan_amount_result = mysqli_query($conn, $total_loan_amount_query);
$summary_stats['total_loan_amount'] = mysqli_fetch_assoc($total_loan_amount_result)['total'];

// Total interest collected
$interest_collected_query = "SELECT COALESCE(SUM(interest_amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?";
$interest_collected_stmt = mysqli_prepare($conn, $interest_collected_query);
mysqli_stmt_bind_param($interest_collected_stmt, 'ss', $from_date, $to_date);
mysqli_stmt_execute($interest_collected_stmt);
$interest_collected_result = mysqli_stmt_get_result($interest_collected_stmt);
$summary_stats['interest_collected'] = mysqli_fetch_assoc($interest_collected_result)['total'];

// Total payments received
$total_payments_query = "SELECT COALESCE(SUM(total_amount), 0) as total FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?";
$total_payments_stmt = mysqli_prepare($conn, $total_payments_query);
mysqli_stmt_bind_param($total_payments_stmt, 'ss', $from_date, $to_date);
mysqli_stmt_execute($total_payments_stmt);
$total_payments_result = mysqli_stmt_get_result($total_payments_stmt);
$summary_stats['total_payments'] = mysqli_fetch_assoc($total_payments_result)['total'];

// Average loan amount
$avg_loan_query = "SELECT COALESCE(AVG(loan_amount), 0) as avg FROM loans WHERE DATE(created_at) BETWEEN ? AND ?";
$avg_loan_stmt = mysqli_prepare($conn, $avg_loan_query);
mysqli_stmt_bind_param($avg_loan_stmt, 'ss', $from_date, $to_date);
mysqli_stmt_execute($avg_loan_stmt);
$avg_loan_result = mysqli_stmt_get_result($avg_loan_stmt);
$summary_stats['avg_loan'] = mysqli_fetch_assoc($avg_loan_result)['avg'];

// Get customer list report
if ($report_type == 'customer_list') {
    $customer_list_query = "SELECT c.*, 
                            COUNT(l.id) as total_loans,
                            SUM(CASE WHEN l.status = 'open' THEN 1 ELSE 0 END) as open_loans,
                            COALESCE(SUM(CASE WHEN l.status = 'open' THEN l.loan_amount ELSE 0 END), 0) as active_amount,
                            MAX(l.created_at) as last_loan_date
                            FROM customers c
                            LEFT JOIN loans l ON c.id = l.customer_id
                            WHERE 1=1";
    
    if ($loan_status == 'active') {
        $customer_list_query .= " HAVING open_loans > 0";
    } elseif ($loan_status == 'inactive') {
        $customer_list_query .= " HAVING open_loans = 0";
    }
    
    $customer_list_query .= " GROUP BY c.id ORDER BY c.created_at DESC";
    $customer_list_result = mysqli_query($conn, $customer_list_query);
}

// Get new customers report
if ($report_type == 'new_customers') {
    $new_customers_detail_query = "SELECT c.*, 
                                   COUNT(l.id) as total_loans,
                                   COALESCE(SUM(l.loan_amount), 0) as total_loan_amount
                                   FROM customers c
                                   LEFT JOIN loans l ON c.id = l.customer_id
                                   WHERE DATE(c.created_at) BETWEEN ? AND ?
                                   GROUP BY c.id
                                   ORDER BY c.created_at DESC";
    $new_customers_detail_stmt = mysqli_prepare($conn, $new_customers_detail_query);
    mysqli_stmt_bind_param($new_customers_detail_stmt, 'ss', $from_date, $to_date);
    mysqli_stmt_execute($new_customers_detail_stmt);
    $new_customers_detail_result = mysqli_stmt_get_result($new_customers_detail_stmt);
}

// Get top customers by loan amount
if ($report_type == 'top_customers') {
    $top_customers_query = "SELECT c.id, c.customer_name, c.mobile_number, c.location,
                            COUNT(l.id) as total_loans,
                            COALESCE(SUM(l.loan_amount), 0) as total_loan_amount,
                            COALESCE(SUM(CASE WHEN l.status = 'open' THEN l.loan_amount ELSE 0 END), 0) as active_amount,
                            MAX(l.created_at) as last_loan_date
                            FROM customers c
                            INNER JOIN loans l ON c.id = l.customer_id
                            WHERE DATE(l.created_at) BETWEEN ? AND ?
                            GROUP BY c.id
                            ORDER BY total_loan_amount DESC
                            LIMIT 20";
    $top_customers_stmt = mysqli_prepare($conn, $top_customers_query);
    mysqli_stmt_bind_param($top_customers_stmt, 'ss', $from_date, $to_date);
    mysqli_stmt_execute($top_customers_stmt);
    $top_customers_result = mysqli_stmt_get_result($top_customers_stmt);
}

// Get customer loan history
if ($report_type == 'customer_history' && $customer_id > 0) {
    $customer_detail_query = "SELECT * FROM customers WHERE id = ?";
    $customer_detail_stmt = mysqli_prepare($conn, $customer_detail_query);
    mysqli_stmt_bind_param($customer_detail_stmt, 'i', $customer_id);
    mysqli_stmt_execute($customer_detail_stmt);
    $customer_detail_result = mysqli_stmt_get_result($customer_detail_stmt);
    $customer_detail = mysqli_fetch_assoc($customer_detail_result);
    
    $loan_history_query = "SELECT l.*, 
                           COUNT(p.id) as payment_count,
                           COALESCE(SUM(p.principal_amount), 0) as total_principal_paid,
                           COALESCE(SUM(p.interest_amount), 0) as total_interest_paid
                           FROM loans l
                           LEFT JOIN payments p ON l.id = p.loan_id
                           WHERE l.customer_id = ?
                           GROUP BY l.id
                           ORDER BY l.created_at DESC";
    $loan_history_stmt = mysqli_prepare($conn, $loan_history_query);
    mysqli_stmt_bind_param($loan_history_stmt, 'i', $customer_id);
    mysqli_stmt_execute($loan_history_stmt);
    $loan_history_result = mysqli_stmt_get_result($loan_history_stmt);
    
    // Get payment history
    $payment_history_query = "SELECT p.*, l.receipt_number 
                              FROM payments p
                              JOIN loans l ON p.loan_id = l.id
                              WHERE l.customer_id = ?
                              ORDER BY p.payment_date DESC, p.id DESC
                              LIMIT 50";
    $payment_history_stmt = mysqli_prepare($conn, $payment_history_query);
    mysqli_stmt_bind_param($payment_history_stmt, 'i', $customer_id);
    mysqli_stmt_execute($payment_history_stmt);
    $payment_history_result = mysqli_stmt_get_result($payment_history_stmt);
}

// Get loan status distribution for chart
$loan_status_distribution_query = "SELECT 
                                   SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_loans,
                                   SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_loans,
                                   SUM(CASE WHEN status = 'auctioned' THEN 1 ELSE 0 END) as auctioned_loans
                                   FROM loans";
$loan_status_distribution_result = mysqli_query($conn, $loan_status_distribution_query);
$loan_status_distribution = mysqli_fetch_assoc($loan_status_distribution_result);

// Get monthly new customers for chart
$monthly_customers_query = "SELECT 
                            DATE_FORMAT(created_at, '%Y-%m') as month,
                            COUNT(*) as count
                            FROM customers
                            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                            ORDER BY month ASC";
$monthly_customers_result = mysqli_query($conn, $monthly_customers_query);
$months = [];
$monthly_counts = [];
while ($row = mysqli_fetch_assoc($monthly_customers_result)) {
    $months[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthly_counts[] = $row['count'];
}

// Get top locations
$top_locations_query = "SELECT location, COUNT(*) as count 
                        FROM customers 
                        WHERE location != '' 
                        GROUP BY location 
                        ORDER BY count DESC 
                        LIMIT 10";
$top_locations_result = mysqli_query($conn, $top_locations_query);

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $export_type = $_GET['export_type'] ?? 'customer_list';
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=customer_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type == 'customer_list') {
        // Customer list export
        fputcsv($output, ['ID', 'Customer Name', 'Mobile', 'Location', 'Total Loans', 'Active Loans', 'Active Amount', 'Last Loan Date']);
        
        $export_query = "SELECT c.id, c.customer_name, c.mobile_number, c.location,
                        COUNT(l.id) as total_loans,
                        SUM(CASE WHEN l.status = 'open' THEN 1 ELSE 0 END) as open_loans,
                        COALESCE(SUM(CASE WHEN l.status = 'open' THEN l.loan_amount ELSE 0 END), 0) as active_amount,
                        MAX(l.created_at) as last_loan_date
                        FROM customers c
                        LEFT JOIN loans l ON c.id = l.customer_id
                        GROUP BY c.id
                        ORDER BY c.id DESC";
        
        $export_result = mysqli_query($conn, $export_query);
        
        while ($row = mysqli_fetch_assoc($export_result)) {
            fputcsv($output, [
                $row['id'],
                $row['customer_name'],
                $row['mobile_number'],
                $row['location'] ?? 'N/A',
                $row['total_loans'] ?? 0,
                $row['open_loans'] ?? 0,
                number_format($row['active_amount'] ?? 0, 2),
                $row['last_loan_date'] ? date('d-m-Y', strtotime($row['last_loan_date'])) : 'Never'
            ]);
        }
    } elseif ($export_type == 'loan_history' && $customer_id > 0) {
        // Loan history export
        fputcsv($output, ['Receipt No', 'Date', 'Loan Amount', 'Status', 'Principal Paid', 'Interest Paid', 'Balance']);
        
        $export_history_query = "SELECT l.receipt_number, l.created_at, l.loan_amount, l.status,
                                COALESCE(SUM(p.principal_amount), 0) as total_principal_paid,
                                COALESCE(SUM(p.interest_amount), 0) as total_interest_paid
                                FROM loans l
                                LEFT JOIN payments p ON l.id = p.loan_id
                                WHERE l.customer_id = ?
                                GROUP BY l.id
                                ORDER BY l.created_at DESC";
        
        $export_history_stmt = mysqli_prepare($conn, $export_history_query);
        mysqli_stmt_bind_param($export_history_stmt, 'i', $customer_id);
        mysqli_stmt_execute($export_history_stmt);
        $export_history_result = mysqli_stmt_get_result($export_history_stmt);
        
        while ($row = mysqli_fetch_assoc($export_history_result)) {
            $balance = $row['loan_amount'] - $row['total_principal_paid'];
            fputcsv($output, [
                $row['receipt_number'],
                date('d-m-Y', strtotime($row['created_at'])),
                number_format($row['loan_amount'], 2),
                ucfirst($row['status']),
                number_format($row['total_principal_paid'], 2),
                number_format($row['total_interest_paid'], 2),
                number_format($balance, 2)
            ]);
        }
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

        /* Reports Container */
        .reports-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
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

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.5);
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.15);
        }

        .stat-icon {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            font-weight: 500;
        }

        .stat-change {
            font-size: 12px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-change.positive {
            color: #48bb78;
        }

        .stat-change.negative {
            color: #f56565;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-title i {
            color: #667eea;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Report Type Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .report-tab {
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
        }

        .report-tab:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .report-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
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
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-title i {
            color: #667eea;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .export-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #e2e8f0;
            background: white;
            color: #4a5568;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .export-btn:hover {
            border-color: #48bb78;
            color: #48bb78;
        }

        /* Reports Table */
        .reports-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .reports-table thead th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .reports-table tbody td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .reports-table tbody tr:hover td {
            background: #edf2f7;
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .status-badge.inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        .status-badge.open {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .status-badge.closed {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
        }

        /* Amount Highlight */
        .amount-highlight {
            font-weight: 600;
            color: #48bb78;
        }

        /* Customer Info */
        .customer-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }

        .customer-details {
            flex: 1;
        }

        .customer-name {
            font-weight: 600;
            color: #2d3748;
        }

        .customer-mobile {
            font-size: 12px;
            color: #718096;
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
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .page-title {
                font-size: 28px;
                flex-direction: column;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .export-buttons {
                justify-content: flex-end;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .reports-container {
                padding: 0 10px;
            }
            
            .filter-card, .table-card {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .report-tabs {
                justify-content: center;
            }
            
            .export-buttons {
                flex-direction: column;
            }
            
            .export-btn {
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
            <div class="reports-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-bar-chart" style="margin-right: 10px;"></i>
                        Customer Reports
                    </h1>
                    <div class="header-actions">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-card">
                    <form method="GET" action="" class="filter-form">
                        <div class="filter-group">
                            <label class="filter-label">From Date</label>
                            <input type="date" class="filter-input" name="from_date" value="<?php echo $from_date; ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">To Date</label>
                            <input type="date" class="filter-input" name="to_date" value="<?php echo $to_date; ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Report Type</label>
                            <select class="filter-select" name="report_type" onchange="this.form.submit()">
                                <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary Dashboard</option>
                                <option value="customer_list" <?php echo $report_type == 'customer_list' ? 'selected' : ''; ?>>Customer List</option>
                                <option value="new_customers" <?php echo $report_type == 'new_customers' ? 'selected' : ''; ?>>New Customers</option>
                                <option value="top_customers" <?php echo $report_type == 'top_customers' ? 'selected' : ''; ?>>Top Customers</option>
                                <option value="customer_history" <?php echo $report_type == 'customer_history' ? 'selected' : ''; ?>>Customer History</option>
                            </select>
                        </div>
                        <?php if ($report_type == 'customer_list'): ?>
                        <div class="filter-group">
                            <label class="filter-label">Loan Status</label>
                            <select class="filter-select" name="loan_status">
                                <option value="">All Customers</option>
                                <option value="active" <?php echo $loan_status == 'active' ? 'selected' : ''; ?>>With Active Loans</option>
                                <option value="inactive" <?php echo $loan_status == 'inactive' ? 'selected' : ''; ?>>No Active Loans</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($report_type == 'customer_history'): ?>
                        <div class="filter-group">
                            <label class="filter-label">Select Customer</label>
                            <select class="filter-select" name="customer_id" required>
                                <option value="">Choose Customer</option>
                                <?php while ($customer = mysqli_fetch_assoc($customers_result)): ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo $customer_id == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['customer_name'] . ' - ' . $customer['mobile_number']); ?>
                                </option>
                                <?php endwhile; ?>
                                <?php mysqli_data_seek($customers_result, 0); ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="filter-btn btn-primary">
                            <i class="bi bi-funnel"></i>
                            Apply Filters
                        </button>
                    </form>
                </div>

                <?php if ($report_type == 'summary'): ?>
                <!-- Summary Dashboard -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="stat-value"><?php echo number_format($summary_stats['total_customers']); ?></div>
                        <div class="stat-label">Total Customers</div>
                        <div class="stat-change positive">
                            <i class="bi bi-arrow-up-circle"></i>
                            +<?php echo $summary_stats['new_customers']; ?> new this period
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #48bb78;"><i class="bi bi-person-check-fill"></i></div>
                        <div class="stat-value"><?php echo number_format($summary_stats['active_loan_customers']); ?></div>
                        <div class="stat-label">Active Loan Holders</div>
                        <div class="stat-change">
                            <i class="bi bi-person"></i>
                            <?php echo round(($summary_stats['active_loan_customers'] / max($summary_stats['total_customers'], 1)) * 100, 1); ?>% of total
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #ecc94b;"><i class="bi bi-currency-rupee"></i></div>
                        <div class="stat-value">₹<?php echo number_format($summary_stats['total_loan_amount'], 0); ?></div>
                        <div class="stat-label">Outstanding Amount</div>
                        <div class="stat-change">
                            Avg: ₹<?php echo number_format($summary_stats['avg_loan'], 0); ?> per loan
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="color: #4299e1;"><i class="bi bi-cash-stack"></i></div>
                        <div class="stat-value">₹<?php echo number_format($summary_stats['total_payments'], 0); ?></div>
                        <div class="stat-label">Payments Received</div>
                        <div class="stat-change positive">
                            <i class="bi bi-arrow-up-circle"></i>
                            Interest: ₹<?php echo number_format($summary_stats['interest_collected'], 0); ?>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-title">
                            <i class="bi bi-pie-chart"></i>
                            Loan Status Distribution
                        </div>
                        <div class="chart-container">
                            <canvas id="loanStatusChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-title">
                            <i class="bi bi-graph-up"></i>
                            New Customers (Last 12 Months)
                        </div>
                        <div class="chart-container">
                            <canvas id="monthlyCustomersChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top Locations -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="bi bi-geo-alt"></i>
                            Top Customer Locations
                        </h3>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Location</th>
                                    <th>Number of Customers</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_locations = 0;
                                $locations_data = [];
                                while ($location = mysqli_fetch_assoc($top_locations_result)) {
                                    $locations_data[] = $location;
                                    $total_locations += $location['count'];
                                }
                                foreach ($locations_data as $location): 
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <i class="bi bi-geo-alt" style="color: #667eea;"></i>
                                            <strong><?php echo htmlspecialchars($location['location']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo $location['count']; ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <span><?php echo round(($location['count'] / $total_locations) * 100, 1); ?>%</span>
                                            <div style="width: 100px; height: 6px; background: #e2e8f0; border-radius: 3px;">
                                                <div style="width: <?php echo ($location['count'] / $total_locations) * 100; ?>%; height: 100%; background: linear-gradient(90deg, #667eea, #764ba2); border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($locations_data)): ?>
                                <tr>
                                    <td colspan="3" class="empty-state">
                                        <i class="bi bi-geo-alt"></i>
                                        <p>No location data available</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php elseif ($report_type == 'customer_list'): ?>
                <!-- Customer List Report -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="bi bi-list-ul"></i>
                            Customer List Report
                        </h3>
                        <div class="export-buttons">
                            <a href="?export=csv&export_type=customer_list&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>&loan_status=<?php echo $loan_status; ?>" class="export-btn">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                                Export CSV
                            </a>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="reports-table" id="customerListTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Location</th>
                                    <th>Total Loans</th>
                                    <th>Active Loans</th>
                                    <th>Active Amount</th>
                                    <th>Last Loan</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($customer_list_result) && mysqli_num_rows($customer_list_result) > 0): ?>
                                    <?php while ($customer = mysqli_fetch_assoc($customer_list_result)): 
                                        $initials = '';
                                        $name_parts = explode(' ', trim($customer['customer_name']));
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) {
                                                $initials .= strtoupper(substr($part, 0, 1));
                                            }
                                        }
                                        if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $customer['id']; ?></strong></td>
                                        <td>
                                            <div class="customer-info">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-details">
                                                    <div class="customer-name"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                                    <div class="customer-mobile"><?php echo htmlspecialchars($customer['mobile_number']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><i class="bi bi-telephone" style="color: #48bb78;"></i> <?php echo htmlspecialchars($customer['mobile_number']); ?></div>
                                            <?php if (!empty($customer['email'])): ?>
                                            <div><small><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($customer['email']); ?></small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($customer['location'] ?? 'N/A'); ?><br>
                                            <small><?php echo htmlspecialchars($customer['district'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo $customer['total_loans'] ?? 0; ?></td>
                                        <td>
                                            <span class="status-badge <?php echo ($customer['open_loans'] ?? 0) > 0 ? 'active' : 'inactive'; ?>">
                                                <?php echo $customer['open_loans'] ?? 0; ?>
                                            </span>
                                        </td>
                                        <td class="amount-highlight">₹<?php echo number_format($customer['active_amount'] ?? 0, 2); ?></td>
                                        <td>
                                            <?php if ($customer['last_loan_date']): ?>
                                                <small><?php echo date('d-m-Y', strtotime($customer['last_loan_date'])); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Never</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="?report_type=customer_history&customer_id=<?php echo $customer['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-icon" style="padding: 6px 12px; border-radius: 8px; background: #667eea; color: white; text-decoration: none; font-size: 12px;">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">
                                            <i class="bi bi-people"></i>
                                            <p>No customers found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php elseif ($report_type == 'new_customers'): ?>
                <!-- New Customers Report -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="bi bi-person-plus"></i>
                            New Customers (<?php echo date('d-m-Y', strtotime($from_date)); ?> to <?php echo date('d-m-Y', strtotime($to_date)); ?>)
                        </h3>
                        <div class="export-buttons">
                            <a href="?export=csv&export_type=new_customers&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="export-btn">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                                Export CSV
                            </a>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="reports-table" id="newCustomersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer Name</th>
                                    <th>Mobile</th>
                                    <th>Guardian</th>
                                    <th>Location</th>
                                    <th>Joined Date</th>
                                    <th>Total Loans</th>
                                    <th>Loan Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($new_customers_detail_result) && mysqli_num_rows($new_customers_detail_result) > 0): ?>
                                    <?php while ($customer = mysqli_fetch_assoc($new_customers_detail_result)): ?>
                                    <tr>
                                        <td><strong>#<?php echo $customer['id']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['mobile_number']); ?></td>
                                        <td>
                                            <?php if (!empty($customer['guardian_name'])): ?>
                                                <small><?php echo htmlspecialchars($customer['guardian_type'] ?? ''); ?></small><br>
                                                <?php echo htmlspecialchars($customer['guardian_name']); ?>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($customer['location'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('d-m-Y', strtotime($customer['created_at'])); ?></small>
                                        </td>
                                        <td><?php echo $customer['total_loans'] ?? 0; ?></td>
                                        <td class="amount-highlight">₹<?php echo number_format($customer['total_loan_amount'] ?? 0, 2); ?></td>
                                        <td>
                                            <a href="?report_type=customer_history&customer_id=<?php echo $customer['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-icon" style="padding: 6px 12px; border-radius: 8px; background: #667eea; color: white; text-decoration: none; font-size: 12px;">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">
                                            <i class="bi bi-person-plus"></i>
                                            <p>No new customers in this period</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php elseif ($report_type == 'top_customers'): ?>
                <!-- Top Customers Report -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="bi bi-trophy"></i>
                            Top Customers by Loan Amount
                        </h3>
                        <div class="export-buttons">
                            <a href="?export=csv&export_type=top_customers&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="export-btn">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                                Export CSV
                            </a>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="reports-table" id="topCustomersTable">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Location</th>
                                    <th>Total Loans</th>
                                    <th>Total Loan Amount</th>
                                    <th>Active Amount</th>
                                    <th>Last Loan</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($top_customers_result) && mysqli_num_rows($top_customers_result) > 0): ?>
                                    <?php 
                                    $rank = 1;
                                    while ($customer = mysqli_fetch_assoc($top_customers_result)): 
                                        $initials = '';
                                        $name_parts = explode(' ', trim($customer['customer_name']));
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) {
                                                $initials .= strtoupper(substr($part, 0, 1));
                                            }
                                        }
                                        if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($rank <= 3): ?>
                                                <span style="font-size: 18px;"><?php echo $rank == 1 ? '🥇' : ($rank == 2 ? '🥈' : '🥉'); ?></span>
                                            <?php else: ?>
                                                <strong>#<?php echo $rank; ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="customer-info">
                                                <div class="customer-avatar"><?php echo $initials; ?></div>
                                                <div class="customer-details">
                                                    <div class="customer-name"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <i class="bi bi-phone" style="color: #48bb78;"></i> <?php echo htmlspecialchars($customer['mobile_number']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['location'] ?? 'N/A'); ?></td>
                                        <td><?php echo $customer['total_loans']; ?></td>
                                        <td class="amount-highlight">₹<?php echo number_format($customer['total_loan_amount'], 2); ?></td>
                                        <td class="amount-highlight">₹<?php echo number_format($customer['active_amount'], 2); ?></td>
                                        <td>
                                            <small><?php echo date('d-m-Y', strtotime($customer['last_loan_date'])); ?></small>
                                        </td>
                                        <td>
                                            <a href="?report_type=customer_history&customer_id=<?php echo $customer['id']; ?>&from_date=<?php echo $from_date; ?>&to_date=<?php echo $to_date; ?>" class="btn-icon" style="padding: 6px 12px; border-radius: 8px; background: #667eea; color: white; text-decoration: none; font-size: 12px;">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php 
                                    $rank++;
                                    endwhile; 
                                    ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">
                                            <i class="bi bi-trophy"></i>
                                            <p>No loan data available in this period</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php elseif ($report_type == 'customer_history' && $customer_id > 0 && isset($customer_detail)): ?>
                <!-- Customer History Report -->
                <div class="table-card">
                    <div class="table-header">
                        <h3 class="table-title">
                            <i class="bi bi-clock-history"></i>
                            Customer History: <?php echo htmlspecialchars($customer_detail['customer_name']); ?>
                        </h3>
                        <div class="export-buttons">
                            <a href="?export=csv&export_type=loan_history&customer_id=<?php echo $customer_id; ?>" class="export-btn">
                                <i class="bi bi-file-earmark-spreadsheet"></i>
                                Export CSV
                            </a>
                        </div>
                    </div>

                    <!-- Customer Summary -->
                    <div style="background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%); border-radius: 16px; padding: 20px; margin-bottom: 20px;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <div>
                                <small style="color: #718096;">Mobile Number</small>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($customer_detail['mobile_number']); ?></div>
                            </div>
                            <div>
                                <small style="color: #718096;">Guardian</small>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($customer_detail['guardian_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div>
                                <small style="color: #718096;">Location</small>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($customer_detail['location']); ?></div>
                            </div>
                            <div>
                                <small style="color: #718096;">Loan Limit</small>
                                <div style="font-weight: 600; color: #48bb78;">₹<?php echo number_format($customer_detail['loan_limit_amount'] ?? 10000000, 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Loan History -->
                    <h4 style="margin: 20px 0 10px; color: #2d3748;">Loan History</h4>
                    <div style="overflow-x: auto; margin-bottom: 30px;">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Receipt No</th>
                                    <th>Date</th>
                                    <th>Loan Amount</th>
                                    <th>Interest</th>
                                    <th>Status</th>
                                    <th>Principal Paid</th>
                                    <th>Interest Paid</th>
                                    <th>Balance</th>
                                    <th>Payments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($loan_history_result) && mysqli_num_rows($loan_history_result) > 0): ?>
                                    <?php while ($loan = mysqli_fetch_assoc($loan_history_result)): 
                                        $balance = $loan['loan_amount'] - $loan['total_principal_paid'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                                        <td><?php echo date('d-m-Y', strtotime($loan['created_at'])); ?></td>
                                        <td class="amount-highlight">₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td><?php echo $loan['interest_amount']; ?>%</td>
                                        <td>
                                            <span class="status-badge <?php echo $loan['status']; ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td>₹<?php echo number_format($loan['total_principal_paid'], 2); ?></td>
                                        <td>₹<?php echo number_format($loan['total_interest_paid'], 2); ?></td>
                                        <td>
                                            <?php if ($loan['status'] == 'open'): ?>
                                                <span class="amount-highlight">₹<?php echo number_format($balance, 2); ?></span>
                                            <?php else: ?>
                                                <span style="color: #718096;">₹0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $loan['payment_count']; ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="empty-state">
                                            <i class="bi bi-clock-history"></i>
                                            <p>No loan history found for this customer</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Payment History -->
                    <h4 style="margin: 30px 0 10px; color: #2d3748;">Recent Payments</h4>
                    <div style="overflow-x: auto;">
                        <table class="reports-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt No</th>
                                    <th>Loan Receipt</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Total</th>
                                    <th>Payment Mode</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($payment_history_result) && mysqli_num_rows($payment_history_result) > 0): ?>
                                    <?php while ($payment = mysqli_fetch_assoc($payment_history_result)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo $payment['receipt_number']; ?></td>
                                        <td><?php echo $payment['receipt_number']; ?></td>
                                        <td>₹<?php echo number_format($payment['principal_amount'], 2); ?></td>
                                        <td>₹<?php echo number_format($payment['interest_amount'], 2); ?></td>
                                        <td class="amount-highlight">₹<?php echo number_format($payment['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="status-badge"><?php echo ucfirst($payment['payment_mode']); ?></span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">
                                            <i class="bi bi-cash"></i>
                                            <p>No payment history found</p>
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

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- jQuery and DataTables -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize DataTables for tables with ID
        <?php if ($report_type == 'customer_list' && isset($customer_list_result) && mysqli_num_rows($customer_list_result) > 0): ?>
        $('#customerListTable').DataTable({
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
            }
        });
        <?php endif; ?>

        <?php if ($report_type == 'new_customers' && isset($new_customers_detail_result) && mysqli_num_rows($new_customers_detail_result) > 0): ?>
        $('#newCustomersTable').DataTable({
            pageLength: 25,
            order: [[5, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search customers...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ customers"
            }
        });
        <?php endif; ?>

        <?php if ($report_type == 'top_customers' && isset($top_customers_result) && mysqli_num_rows($top_customers_result) > 0): ?>
        $('#topCustomersTable').DataTable({
            pageLength: 25,
            order: [[5, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search customers...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOP_ customers"
            }
        });
        <?php endif; ?>
    });

    <?php if ($report_type == 'summary'): ?>
    // Loan Status Distribution Chart
    const loanStatusCtx = document.getElementById('loanStatusChart').getContext('2d');
    new Chart(loanStatusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Open Loans', 'Closed Loans', 'Auctioned Loans'],
            datasets: [{
                data: [
                    <?php echo $loan_status_distribution['open_loans'] ?? 0; ?>,
                    <?php echo $loan_status_distribution['closed_loans'] ?? 0; ?>,
                    <?php echo $loan_status_distribution['auctioned_loans'] ?? 0; ?>
                ],
                backgroundColor: [
                    '#4299e1',
                    '#48bb78',
                    '#f56565'
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
                }
            }
        }
    });

    // Monthly New Customers Chart
    const monthlyCtx = document.getElementById('monthlyCustomersChart').getContext('2d');
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>,
            datasets: [{
                label: 'New Customers',
                data: <?php echo json_encode($monthly_counts); ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#667eea',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 4
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
                    grid: {
                        display: true,
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
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