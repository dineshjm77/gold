<?php
session_start();
$currentPage = 'expense-reports';
$pageTitle = 'Expense Reports';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin or sale access
if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'sale') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$expense_type_filter = isset($_GET['expense_type']) ? $_GET['expense_type'] : '';
$payment_method_filter = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build query for expense details
$query = "SELECT * FROM expense_details WHERE date BETWEEN ? AND ?";
$params = [$from_date, $to_date];
$types = "ss";

if (!empty($expense_type_filter)) {
    $query .= " AND expense_type = ?";
    $params[] = $expense_type_filter;
    $types .= "s";
}

if (!empty($payment_method_filter)) {
    $query .= " AND payment_method = ?";
    $params[] = $payment_method_filter;
    $types .= "s";
}

if (!empty($search)) {
    $query .= " AND (detail LIKE ? OR expense_type LIKE ? OR receipt_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

$query .= " ORDER BY date DESC, receipt_number DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $expense_result = $stmt->get_result();
} else {
    $expense_result = false;
    $error = "Database error: " . $conn->error;
}

// Calculate totals
$total_amount = 0;
$total_cash = 0;
$total_bank = 0;
$total_upi = 0;
$total_other = 0;
$expense_count = 0;

if ($expense_result && $expense_result->num_rows > 0) {
    $expense_result->data_seek(0);
    while ($row = $expense_result->fetch_assoc()) {
        $total_amount += $row['amount'];
        $expense_count++;
        
        // Sum by payment method
        $payment = $row['payment_method'] ?? 'cash';
        if ($payment == 'cash') $total_cash += $row['amount'];
        else if ($payment == 'bank') $total_bank += $row['amount'];
        else if ($payment == 'upi') $total_upi += $row['amount'];
        else $total_other += $row['amount'];
    }
    $expense_result->data_seek(0); // Reset pointer
}

// Fetch all expense types for filter dropdown
$types_query = "SELECT DISTINCT expense_type FROM expense_details ORDER BY expense_type ASC";
$types_result = $conn->query($types_query);

// Fetch all payment methods for filter dropdown
$payment_methods = ['cash', 'bank', 'upi', 'other'];

// Get summary by expense type
$summary_query = "SELECT 
                  expense_type, 
                  COUNT(*) as transaction_count,
                  SUM(amount) as total_amount
                  FROM expense_details 
                  WHERE date BETWEEN ? AND ?
                  GROUP BY expense_type
                  ORDER BY total_amount DESC";
$summary_stmt = $conn->prepare($summary_query);
if ($summary_stmt) {
    $summary_stmt->bind_param("ss", $from_date, $to_date);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
} else {
    $summary_result = false;
}

// Get summary by payment method
$payment_summary_query = "SELECT 
                          payment_method, 
                          COUNT(*) as transaction_count,
                          SUM(amount) as total_amount
                          FROM expense_details 
                          WHERE date BETWEEN ? AND ? AND amount > 0
                          GROUP BY payment_method
                          ORDER BY payment_method ASC";
$payment_summary_stmt = $conn->prepare($payment_summary_query);
if ($payment_summary_stmt) {
    $payment_summary_stmt->bind_param("ss", $from_date, $to_date);
    $payment_summary_stmt->execute();
    $payment_summary_result = $payment_summary_stmt->get_result();
} else {
    $payment_summary_result = false;
}

// Get expense statistics
$stats_query = "SELECT 
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount,
                MAX(amount) as max_amount,
                MIN(amount) as min_amount
                FROM expense_details 
                WHERE date BETWEEN ? AND ?";
$stats_stmt = $conn->prepare($stats_query);
if ($stats_stmt) {
    $stats_stmt->bind_param("ss", $from_date, $to_date);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
} else {
    $stats = [
        'total_transactions' => 0,
        'total_amount' => 0,
        'avg_amount' => 0,
        'max_amount' => 0,
        'min_amount' => 0
    ];
}

// Get daily totals for chart
$daily_query = "SELECT 
                DATE(date) as expense_date,
                COUNT(*) as transaction_count,
                SUM(amount) as daily_total
                FROM expense_details 
                WHERE date BETWEEN ? AND ?
                GROUP BY DATE(date)
                ORDER BY expense_date ASC
                LIMIT 30";
$daily_stmt = $conn->prepare($daily_query);
if ($daily_stmt) {
    $daily_stmt->bind_param("ss", $from_date, $to_date);
    $daily_stmt->execute();
    $daily_result = $daily_stmt->get_result();
    
    $daily_labels = [];
    $daily_data = [];
    while ($row = $daily_result->fetch_assoc()) {
        $daily_labels[] = date('d M', strtotime($row['expense_date']));
        $daily_data[] = $row['daily_total'];
    }
} else {
    $daily_labels = [];
    $daily_data = [];
}

// Get top expense types
$top_types_query = "SELECT 
                    expense_type,
                    COUNT(*) as count,
                    SUM(amount) as total
                    FROM expense_details 
                    WHERE date BETWEEN ? AND ?
                    GROUP BY expense_type
                    ORDER BY total DESC
                    LIMIT 10";
$top_types_stmt = $conn->prepare($top_types_query);
if ($top_types_stmt) {
    $top_types_stmt->bind_param("ss", $from_date, $to_date);
    $top_types_stmt->execute();
    $top_types_result = $top_types_stmt->get_result();
} else {
    $top_types_result = false;
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* Reset and Base Styles - Matching index page */
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
            padding: 0 20px;
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

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
        }

        /* Alert Messages */
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

        .alert-info {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
            border-left-color: #4299e1;
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

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            color: #667eea;
            font-size: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .filter-btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 42px;
        }

        .filter-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .filter-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .filter-btn-secondary {
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .filter-btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
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
            font-size: 28px;
            margin-bottom: 10px;
            color: #667eea;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            font-weight: 500;
        }

        .stat-sub {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Payment Method Badges */
        .payment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .payment-cash {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .payment-bank {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .payment-upi {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        .payment-other {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
        }

        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-title i {
            color: #667eea;
        }

        .chart-container {
            height: 250px;
            position: relative;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        /* Summary Card */
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .summary-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-title i {
            color: #667eea;
        }

        /* Summary Table */
        .summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 6px;
        }

        .summary-table th {
            padding: 10px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 8px 8px 0 0;
            white-space: nowrap;
        }

        .summary-table td {
            padding: 10px;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 12px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .summary-table tbody tr:hover td {
            background: #edf2f7;
        }

        /* Detailed Table Card */
        .table-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
        }

        .detailed-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 6px;
            min-width: 1000px;
        }

        .detailed-table th {
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 8px 8px 0 0;
            white-space: nowrap;
        }

        .detailed-table td {
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 12px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .detailed-table tbody tr:hover td {
            background: #edf2f7;
        }

        .receipt-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 11px;
            display: inline-block;
        }

        .amount {
            font-weight: 600;
        }

        /* DataTables Customization */
        .dataTables_wrapper {
            padding: 0;
        }

        .dataTables_length select,
        .dataTables_filter input {
            padding: 6px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 12px;
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
            font-size: 12px;
            padding: 10px 0;
        }

        .dataTables_paginate {
            padding: 10px 0;
        }

        .dataTables_paginate .paginate_button {
            padding: 5px 8px;
            margin: 0 2px;
            border-radius: 6px;
            border: 2px solid #e2e8f0;
            background: white;
            color: #4a5568 !important;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 11px;
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
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
            font-size: 12px;
        }

        .btn-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .btn-icon.view:hover {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .btn-icon.print:hover {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        /* Export Options */
        .export-options {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .export-btn {
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .export-btn:hover {
            transform: translateY(-2px);
            background: #f8fafc;
            border-color: #667eea;
            color: #667eea;
        }

        .export-btn i {
            font-size: 14px;
        }

        /* Footer Styles */
        .footer {
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #eef2f6;
            padding: 16px 24px;
            margin-top: auto;
            width: 100%;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #64748b;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #64748b;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: #667eea;
        }

        .footer-version {
            color: #94a3b8;
            font-size: 12px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 10px;
            color: #4a5568;
        }

        /* Badge Count */
        .badge-count {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 12px;
            margin-left: 8px;
            font-weight: 600;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .page-content {
                padding: 20px;
            }
            
            .reports-container {
                padding: 0 15px;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-grid {
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
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .filter-btn {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .export-options {
                justify-content: center;
            }
            
            .footer-content {
                flex-direction: column;
                text-align: center;
            }
            
            .footer-links {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .reports-container {
                padding: 0 10px;
            }
            
            .filter-card, .chart-card, .summary-card, .table-card {
                padding: 15px;
            }
        }

        /* Thai Font Support */
        .thai-text {
            font-family: "Noto Sans Thai", "Sarabun", "Thonburi", sans-serif;
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
                        <i class="bi bi-file-earmark-bar-graph" style="margin-right: 10px;"></i>
                        Expense Reports
                        <span class="badge-count"><?php echo $expense_count; ?> Transactions</span>
                    </h1>
                    <div class="header-actions">
                        <div class="export-options">
                            <button class="export-btn" onclick="exportToPDF()">
                                <i class="bi bi-file-pdf"></i>
                                PDF
                            </button>
                            <button class="export-btn" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i>
                                Excel
                            </button>
                            <button class="export-btn" onclick="printReport()">
                                <i class="bi bi-printer"></i>
                                Print
                            </button>
                            <a href="Expense-Details.php" class="btn btn-success btn-sm" style="padding: 8px 14px; border-radius: 50px;">
                                <i class="bi bi-plus-circle"></i> Add Expense
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Display Error Message -->
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <!-- Filter Card -->
                <div class="filter-card">
                    <div class="filter-title">
                        <i class="bi bi-funnel"></i>
                        Filter Reports
                    </div>
                    
                    <form method="GET" action="">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">From Date</label>
                                <input type="date" name="from_date" class="filter-input" value="<?php echo $from_date; ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">To Date</label>
                                <input type="date" name="to_date" class="filter-input" value="<?php echo $to_date; ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Expense Type</label>
                                <select name="expense_type" class="filter-select">
                                    <option value="">All Types</option>
                                    <?php 
                                    if ($types_result && $types_result->num_rows > 0) {
                                        $types_result->data_seek(0);
                                        while ($type = $types_result->fetch_assoc()) {
                                            $selected = ($expense_type_filter == $type['expense_type']) ? 'selected' : '';
                                            echo '<option value="' . htmlspecialchars($type['expense_type']) . '" ' . $selected . '>' . htmlspecialchars($type['expense_type']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Payment Method</label>
                                <select name="payment_method" class="filter-select">
                                    <option value="">All Methods</option>
                                    <option value="cash" <?php echo $payment_method_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                    <option value="bank" <?php echo $payment_method_filter == 'bank' ? 'selected' : ''; ?>>Bank</option>
                                    <option value="upi" <?php echo $payment_method_filter == 'upi' ? 'selected' : ''; ?>>UPI</option>
                                    <option value="other" <?php echo $payment_method_filter == 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="filter-btn filter-btn-primary">
                                    <i class="bi bi-search"></i>
                                    Apply
                                </button>
                                <a href="Expense-Reports.php" class="filter-btn filter-btn-secondary">
                                    <i class="bi bi-arrow-repeat"></i>
                                    Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-calculator"></i></div>
                            <div class="stat-value">₹<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Total Expenses</div>
                            <div class="stat-sub">For selected period</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-receipt"></i></div>
                            <div class="stat-value"><?php echo number_format($stats['total_transactions'] ?? 0); ?></div>
                            <div class="stat-label">Transactions</div>
                            <div class="stat-sub">Total count</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-cash"></i></div>
                            <div class="stat-value">₹<?php echo number_format($stats['avg_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Average Expense</div>
                            <div class="stat-sub">Per transaction</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-content">
                            <div class="stat-icon"><i class="bi bi-arrow-up"></i></div>
                            <div class="stat-value">₹<?php echo number_format($stats['max_amount'] ?? 0, 2); ?></div>
                            <div class="stat-label">Highest Expense</div>
                            <div class="stat-sub">Maximum amount</div>
                        </div>
                    </div>
                </div>

                <!-- Daily Expense Chart -->
                <?php if (!empty($daily_labels) && !empty($daily_data)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <i class="bi bi-graph-up"></i>
                            Daily Expense Trend
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Charts Grid -->
                <div class="charts-grid">
                    <!-- Expense by Type Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart" style="color: #f56565;"></i>
                                Expenses by Type
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="typeChart"></canvas>
                        </div>
                    </div>

                    <!-- Payment Method Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart" style="color: #4299e1;"></i>
                                Payment Methods
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Expense Summary by Type -->
                <?php if ($summary_result && $summary_result->num_rows > 0): ?>
                <div class="summary-card">
                    <div class="summary-header">
                        <div class="summary-title">
                            <i class="bi bi-list-ul"></i>
                            Expense Summary by Type
                        </div>
                    </div>
                    
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>Expense Type</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $summary_result->data_seek(0);
                            while ($row = $summary_result->fetch_assoc()): 
                                $percentage = $total_amount > 0 ? round(($row['total_amount'] / $total_amount) * 100, 2) : 0;
                            ?>
                                <tr>
                                    <td class="thai-text"><strong><?php echo htmlspecialchars($row['expense_type']); ?></strong></td>
                                    <td><span class="receipt-badge"><?php echo $row['transaction_count']; ?></span></td>
                                    <td class="amount">₹<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span><?php echo $percentage; ?>%</span>
                                            <div style="width: 100px; height: 6px; background: #e2e8f0; border-radius: 3px;">
                                                <div style="width: <?php echo $percentage; ?>%; height: 6px; background: #f56565; border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td><strong>Total</strong></td>
                                <td><strong><?php echo $expense_count; ?></strong></td>
                                <td><strong>₹<?php echo number_format($total_amount, 2); ?></strong></td>
                                <td><strong>100%</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Payment Method Summary -->
                <?php if ($payment_summary_result && $payment_summary_result->num_rows > 0): ?>
                <div class="summary-card">
                    <div class="summary-header">
                        <div class="summary-title">
                            <i class="bi bi-credit-card"></i>
                            Payment Method Summary
                        </div>
                    </div>
                    
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>Payment Method</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $payment_summary_result->data_seek(0);
                            while ($row = $payment_summary_result->fetch_assoc()): 
                                $percentage = $total_amount > 0 ? round(($row['total_amount'] / $total_amount) * 100, 2) : 0;
                                $payment = $row['payment_method'] ?? 'other';
                                $payment_class = 'payment-' . $payment;
                                $payment_icon = $payment == 'cash' ? 'bi-cash' : ($payment == 'bank' ? 'bi-bank' : ($payment == 'upi' ? 'bi-phone' : 'bi-three-dots'));
                            ?>
                                <tr>
                                    <td>
                                        <span class="payment-badge <?php echo $payment_class; ?>">
                                            <i class="bi <?php echo $payment_icon; ?>"></i> 
                                            <?php echo ucfirst($payment); ?>
                                        </span>
                                    </td>
                                    <td><span class="receipt-badge"><?php echo $row['transaction_count']; ?></span></td>
                                    <td class="amount">₹<?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span><?php echo $percentage; ?>%</span>
                                            <div style="width: 100px; height: 6px; background: #e2e8f0; border-radius: 3px;">
                                                <div style="width: <?php echo $percentage; ?>%; height: 6px; background: <?php 
                                                    echo $payment == 'cash' ? '#48bb78' : ($payment == 'bank' ? '#4299e1' : ($payment == 'upi' ? '#f56565' : '#a0aec0')); 
                                                ?>; border-radius: 3px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Top Expense Types -->
                <?php if ($top_types_result && $top_types_result->num_rows > 0): ?>
                <div class="summary-card">
                    <div class="summary-header">
                        <div class="summary-title">
                            <i class="bi bi-trophy"></i>
                            Top Expense Categories
                        </div>
                    </div>
                    
                    <table class="summary-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Expense Type</th>
                                <th>Transactions</th>
                                <th>Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $rank = 1;
                            $top_types_result->data_seek(0);
                            while ($row = $top_types_result->fetch_assoc()): 
                            ?>
                                <tr>
                                    <td><strong>#<?php echo $rank++; ?></strong></td>
                                    <td class="thai-text"><?php echo htmlspecialchars($row['expense_type']); ?></td>
                                    <td><span class="receipt-badge"><?php echo $row['count']; ?></span></td>
                                    <td class="amount">₹<?php echo number_format($row['total'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Detailed Transactions Table -->
                <div class="table-card">
                    <div class="summary-header">
                        <div class="summary-title">
                            <i class="bi bi-table"></i>
                            Detailed Expense Transactions
                        </div>
                    </div>
                    
                    <table class="detailed-table" id="expenseTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Receipt No.</th>
                                <th>Date</th>
                                <th>Expense Type</th>
                                <th>Detail</th>
                                <th>Payment Method</th>
                                <th>Amount (₹)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expense_result && $expense_result->num_rows > 0): ?>
                                <?php 
                                $sno = 1;
                                $expense_result->data_seek(0);
                                ?>
                                <?php while ($row = $expense_result->fetch_assoc()): 
                                    $payment = $row['payment_method'] ?? 'cash';
                                    $payment_class = 'payment-' . $payment;
                                    $payment_icon = $payment == 'cash' ? 'bi-cash' : ($payment == 'bank' ? 'bi-bank' : ($payment == 'upi' ? 'bi-phone' : 'bi-three-dots'));
                                ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td>
                                            <span class="receipt-badge">
                                                <?php echo htmlspecialchars($row['receipt_number']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                        <td class="thai-text"><?php echo htmlspecialchars($row['expense_type']); ?></td>
                                        <td class="thai-text"><?php echo htmlspecialchars($row['detail']); ?></td>
                                        <td>
                                            <span class="payment-badge <?php echo $payment_class; ?>">
                                                <i class="bi <?php echo $payment_icon; ?>"></i> <?php echo ucfirst($payment); ?>
                                            </span>
                                        </td>
                                        <td class="amount">₹<?php echo number_format($row['amount'], 2); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="Expense-Details.php?edit=<?php echo $row['id']; ?>" class="btn-icon view" title="View/Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button class="btn-icon print" onclick="printReceipt(<?php echo $row['id']; ?>)" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <i class="bi bi-file-earmark-bar-graph"></i>
                                        <p>No expense records found</p>
                                        <a href="Expense-Details.php" class="btn btn-primary" style="display: inline-block; padding: 8px 16px; font-size: 13px;">
                                            <i class="bi bi-plus-circle"></i> Add New Expense
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
        <?php if ($expense_result && $expense_result->num_rows > 0): ?>
        $('#expenseTable').DataTable({
            pageLength: 25,
            order: [[2, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>'
                }
            },
            columnDefs: [
                { orderable: false, targets: [7] }
            ],
            initComplete: function() {
                $('.dataTables_filter input').addClass('filter-input');
                $('.dataTables_length select').addClass('filter-select');
            }
        });
        <?php endif; ?>
    });

    // Initialize Charts
    document.addEventListener('DOMContentLoaded', function() {
        // Daily Chart
        <?php if (!empty($daily_labels) && !empty($daily_data)): ?>
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [{
                    label: 'Daily Expenses',
                    data: <?php echo json_encode($daily_data); ?>,
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
                    tension: 0.4,
                    fill: true
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

        // Expense by Type Chart
        <?php if ($summary_result && $summary_result->num_rows > 0): 
            $summary_result->data_seek(0);
            $type_labels = [];
            $type_data = [];
            while ($row = $summary_result->fetch_assoc()):
                $type_labels[] = $row['expense_type'];
                $type_data[] = $row['total_amount'];
            endwhile;
        ?>
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($type_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($type_data); ?>,
                    backgroundColor: [
                        'rgba(245, 101, 101, 0.8)',
                        'rgba(237, 137, 54, 0.8)',
                        'rgba(236, 201, 75, 0.8)',
                        'rgba(66, 153, 225, 0.8)',
                        'rgba(159, 122, 234, 0.8)',
                        'rgba(72, 187, 120, 0.8)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 10 }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Payment Method Chart
        <?php if ($payment_summary_result && $payment_summary_result->num_rows > 0): 
            $payment_summary_result->data_seek(0);
            $payment_labels = [];
            $payment_data = [];
            while ($row = $payment_summary_result->fetch_assoc()):
                $payment_labels[] = ucfirst($row['payment_method'] ?? 'Other');
                $payment_data[] = $row['total_amount'];
            endwhile;
        ?>
        const paymentCtx = document.getElementById('paymentChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($payment_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($payment_data); ?>,
                    backgroundColor: [
                        'rgba(72, 187, 120, 0.8)',
                        'rgba(66, 153, 225, 0.8)',
                        'rgba(245, 101, 101, 0.8)',
                        'rgba(160, 174, 192, 0.8)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 10 }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    });

    // Export functions
    function exportToPDF() {
        // In production, this would call a PDF generation script
        window.open('export-expense-report.php?<?php echo http_build_query($_GET); ?>&format=pdf', '_blank');
    }
    
    function exportToExcel() {
        // In production, this would call an Excel generation script
        window.open('export-expense-report.php?<?php echo http_build_query($_GET); ?>&format=excel', '_blank');
    }
    
    function printReport() {
        window.print();
    }
    
    function printReceipt(id) {
        // In production, this would open a printable receipt
        window.open('print-expense-receipt.php?id=' + id, '_blank');
    }

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