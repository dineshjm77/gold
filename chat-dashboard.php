<?php
session_start();
$currentPage = 'charts';
$pageTitle = 'Analytics Dashboard';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Get current user info
$user_id = $_SESSION['user_id'];
$user_query = "SELECT name, role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$user_name = explode(' ', $user_data['name'])[0] ?? 'User';
$user_role = $user_data['role'] ?? 'User';

// Date ranges
$today = date('Y-m-d');
$first_day_month = date('Y-m-01');
$last_day_month = date('Y-m-t');
$first_day_year = date('Y-01-01');
$last_day_year = date('Y-12-31');

// Get selected date range
$date_range = $_GET['range'] ?? 'month';
$custom_start = $_GET['start_date'] ?? $first_day_month;
$custom_end = $_GET['end_date'] ?? $last_day_month;

switch($date_range) {
    case 'week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $start_date = $first_day_month;
        $end_date = $last_day_month;
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $start_date = date('Y-') . (($quarter - 1) * 3 + 1) . '-01';
        $end_date = date('Y-m-t', strtotime($start_date . ' +2 months'));
        break;
    case 'year':
        $start_date = $first_day_year;
        $end_date = $last_day_year;
        break;
    case 'custom':
        $start_date = $custom_start;
        $end_date = $custom_end;
        break;
    default:
        $start_date = $first_day_month;
        $end_date = $last_day_month;
}

// ==================== CHART DATA QUERIES ====================

// 1. Loan Performance Over Time
$loan_performance_query = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as loan_count,
    SUM(loan_amount) as total_amount
    FROM loans 
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC";
$stmt = $conn->prepare($loan_performance_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$loan_performance = $stmt->get_result();

$loan_dates = [];
$loan_counts = [];
$loan_amounts = [];

while($row = $loan_performance->fetch_assoc()) {
    $loan_dates[] = date('d M', strtotime($row['date']));
    $loan_counts[] = $row['loan_count'];
    $loan_amounts[] = $row['total_amount'];
}

// 2. Payment Collections
$payment_query = "SELECT 
    DATE(payment_date) as date,
    COUNT(*) as payment_count,
    SUM(total_amount) as total_collected,
    SUM(principal_amount) as total_principal,
    SUM(interest_amount) as total_interest
    FROM payments 
    WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY DATE(payment_date)
    ORDER BY date ASC";
$stmt = $conn->prepare($payment_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$payments = $stmt->get_result();

$payment_dates = [];
$collection_amounts = [];
$principal_amounts = [];
$interest_amounts = [];

while($row = $payments->fetch_assoc()) {
    $payment_dates[] = date('d M', strtotime($row['date']));
    $collection_amounts[] = $row['total_collected'];
    $principal_amounts[] = $row['total_principal'];
    $interest_amounts[] = $row['total_interest'];
}

// 3. Loan Status Distribution
$loan_status_query = "SELECT 
    status,
    COUNT(*) as count,
    SUM(loan_amount) as total_amount
    FROM loans 
    GROUP BY status";
$loan_status_result = $conn->query($loan_status_query);

$loan_status_labels = [];
$loan_status_counts = [];
$loan_status_amounts = [];

while($row = $loan_status_result->fetch_assoc()) {
    $loan_status_labels[] = ucfirst($row['status']);
    $loan_status_counts[] = $row['count'];
    $loan_status_amounts[] = $row['total_amount'] ?? 0;
}

// 4. Product Type Distribution
$product_type_query = "SELECT 
    pt.product_type,
    COUNT(l.id) as loan_count,
    SUM(l.loan_amount) as total_amount,
    SUM(l.net_weight) as total_weight
    FROM loans l
    JOIN loan_items li ON l.id = li.loan_id
    JOIN karat_details k ON li.karat = k.karat
    JOIN product_types pt ON k.product_type = pt.product_type
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY pt.product_type";
$stmt = $conn->prepare($product_type_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$product_types = $stmt->get_result();

$product_labels = [];
$product_loan_counts = [];
$product_amounts = [];
$product_weights = [];

while($row = $product_types->fetch_assoc()) {
    $product_labels[] = $row['product_type'];
    $product_loan_counts[] = $row['loan_count'];
    $product_amounts[] = $row['total_amount'];
    $product_weights[] = $row['total_weight'];
}

// 5. Monthly Performance (for year view)
$monthly_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as loan_count,
    SUM(loan_amount) as total_loan_amount,
    (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = month) as total_collections
    FROM loans 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_result = $conn->query($monthly_query);

$months = [];
$monthly_loans = [];
$monthly_collections = [];

while($row = $monthly_result->fetch_assoc()) {
    $months[] = date('M Y', strtotime($row['month'] . '-01'));
    $monthly_loans[] = $row['total_loan_amount'];
    $monthly_collections[] = $row['total_collections'];
}

// 6. Top Customers by Loan Amount
$top_customers_query = "SELECT 
    c.customer_name,
    COUNT(l.id) as loan_count,
    SUM(l.loan_amount) as total_amount
    FROM customers c
    JOIN loans l ON c.id = l.customer_id
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY total_amount DESC
    LIMIT 10";
$stmt = $conn->prepare($top_customers_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$top_customers = $stmt->get_result();

$customer_names = [];
$customer_loan_counts = [];
$customer_amounts = [];

while($row = $top_customers->fetch_assoc()) {
    $customer_names[] = $row['customer_name'];
    $customer_loan_counts[] = $row['loan_count'];
    $customer_amounts[] = $row['total_amount'];
}

// 7. Karat Distribution
$karat_query = "SELECT 
    k.karat,
    COUNT(li.id) as item_count,
    SUM(li.net_weight) as total_weight,
    SUM(l.loan_amount) as total_amount
    FROM loan_items li
    JOIN loans l ON li.loan_id = l.id
    JOIN karat_details k ON li.karat = k.karat
    WHERE l.created_at BETWEEN ? AND ?
    GROUP BY k.karat
    ORDER BY k.karat ASC";
$stmt = $conn->prepare($karat_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$karat_result = $stmt->get_result();

$karat_labels = [];
$karat_weights = [];
$karat_amounts = [];

while($row = $karat_result->fetch_assoc()) {
    $karat_labels[] = $row['karat'] . 'K';
    $karat_weights[] = $row['total_weight'];
    $karat_amounts[] = $row['total_amount'];
}

// 8. Investment Performance
$investment_query = "SELECT 
    DATE(created_at) as date,
    COUNT(*) as investment_count,
    SUM(investment_amount) as total_investment
    FROM investments 
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY date ASC";
$stmt = $conn->prepare($investment_query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$investments = $stmt->get_result();

$investment_dates = [];
$investment_counts = [];
$investment_amounts = [];

while($row = $investments->fetch_assoc()) {
    $investment_dates[] = date('d M', strtotime($row['date']));
    $investment_counts[] = $row['investment_count'];
    $investment_amounts[] = $row['total_investment'];
}

// 9. Summary Statistics
$summary_query = "SELECT
    (SELECT COALESCE(SUM(loan_amount), 0) FROM loans WHERE DATE(created_at) BETWEEN ? AND ?) as total_loans_issued,
    (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?) as total_collections,
    (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?) as total_interest_earned,
    (SELECT COALESCE(AVG(loan_amount), 0) FROM loans WHERE DATE(created_at) BETWEEN ? AND ?) as avg_loan_amount,
    (SELECT COUNT(*) FROM loans WHERE DATE(created_at) BETWEEN ? AND ?) as loan_count,
    (SELECT COUNT(*) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?) as payment_count";
$stmt = $conn->prepare($summary_query);
$stmt->bind_param("ssssssssssss", $start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

// 10. Daily Average for the period
$days_in_period = max(1, (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1);
$avg_daily_loans = $summary['total_loans_issued'] / $days_in_period;
$avg_daily_collections = $summary['total_collections'] / $days_in_period;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Chart.js Date Adapter -->
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
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

        /* Dashboard Container */
        .dashboard-container {
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
            gap: 20px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header h1 i {
            color: #667eea;
            font-size: 32px;
        }

        /* Date Range Selector */
        .date-range-selector {
            background: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .range-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .range-btn {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            color: #4a5568;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .range-btn:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .range-btn.active {
            background: #667eea;
            border-color: #667eea;
            color: white;
        }

        .custom-range {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .custom-range input {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
        }

        .apply-btn {
            padding: 8px 20px;
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .apply-btn:hover {
            background: #38a169;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.15);
        }

        .summary-card.purple {
            border-left-color: #667eea;
        }

        .summary-card.blue {
            border-left-color: #4299e1;
        }

        .summary-card.green {
            border-left-color: #48bb78;
        }

        .summary-card.orange {
            border-left-color: #ed8936;
        }

        .summary-card.teal {
            border-left-color: #38b2ac;
        }

        .summary-card .label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .summary-card .value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .summary-card .sub {
            font-size: 12px;
            color: #a0aec0;
        }

        /* Chart Grid */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .chart-card.full-width {
            grid-column: span 2;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .chart-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-header h3 i {
            color: #667eea;
        }

        .chart-header .badge {
            padding: 4px 10px;
            background: #e2e8f0;
            border-radius: 20px;
            font-size: 12px;
            color: #4a5568;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-item {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .stat-item .stat-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .stat-item .stat-number {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th {
            text-align: left;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #718096;
            background: #f7fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .data-table td {
            padding: 12px;
            font-size: 13px;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table tbody tr:hover td {
            background: #f8fafc;
        }

        .amount {
            font-weight: 600;
            color: #48bb78;
        }

        /* Loading State */
        .chart-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            display: none;
        }

        .chart-loading.show {
            display: flex;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-card.full-width {
                grid-column: span 1;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-content {
                padding: 20px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-range-selector {
                width: 100%;
                flex-direction: column;
                align-items: flex-start;
            }
            
            .custom-range {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .custom-range input {
                flex: 1;
            }
            
            .stats-row {
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
            <div class="dashboard-container">
                <!-- Page Header with Date Range -->
                <div class="page-header">
                    <h1>
                        <i class="bi bi-bar-chart-steps"></i>
                        Analytics Dashboard
                    </h1>
                    
                    <form method="GET" action="" class="date-range-selector">
                        <div class="range-buttons">
                            <button type="submit" name="range" value="week" class="range-btn <?php echo $date_range == 'week' ? 'active' : ''; ?>">Week</button>
                            <button type="submit" name="range" value="month" class="range-btn <?php echo $date_range == 'month' ? 'active' : ''; ?>">Month</button>
                            <button type="submit" name="range" value="quarter" class="range-btn <?php echo $date_range == 'quarter' ? 'active' : ''; ?>">Quarter</button>
                            <button type="submit" name="range" value="year" class="range-btn <?php echo $date_range == 'year' ? 'active' : ''; ?>">Year</button>
                            <button type="submit" name="range" value="custom" class="range-btn <?php echo $date_range == 'custom' ? 'active' : ''; ?>">Custom</button>
                        </div>
                        
                        <div class="custom-range">
                            <input type="date" name="start_date" value="<?php echo $custom_start; ?>" class="form-control">
                            <span>to</span>
                            <input type="date" name="end_date" value="<?php echo $custom_end; ?>" class="form-control">
                            <button type="submit" class="apply-btn">Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-card purple">
                        <div class="label"><i class="bi bi-cash-stack"></i> Total Loans Issued</div>
                        <div class="value">₹<?php echo number_format($summary['total_loans_issued'], 2); ?></div>
                        <div class="sub"><?php echo $summary['loan_count']; ?> loans</div>
                    </div>
                    
                    <div class="summary-card green">
                        <div class="label"><i class="bi bi-wallet2"></i> Total Collections</div>
                        <div class="value">₹<?php echo number_format($summary['total_collections'], 2); ?></div>
                        <div class="sub"><?php echo $summary['payment_count']; ?> payments</div>
                    </div>
                    
                    <div class="summary-card blue">
                        <div class="label"><i class="bi bi-graph-up-arrow"></i> Interest Earned</div>
                        <div class="value">₹<?php echo number_format($summary['total_interest_earned'], 2); ?></div>
                        <div class="sub"><?php echo number_format($summary['total_interest_earned'] / max($summary['total_collections'], 1) * 100, 1); ?>% of collections</div>
                    </div>
                    
                    <div class="summary-card orange">
                        <div class="label"><i class="bi bi-calculator"></i> Average Loan</div>
                        <div class="value">₹<?php echo number_format($summary['avg_loan_amount'], 2); ?></div>
                        <div class="sub">Per loan</div>
                    </div>
                    
                    <div class="summary-card teal">
                        <div class="label"><i class="bi bi-calendar-check"></i> Daily Average</div>
                        <div class="value">₹<?php echo number_format($avg_daily_loans, 2); ?></div>
                        <div class="sub">Loans / ₹<?php echo number_format($avg_daily_collections, 2); ?> collections</div>
                    </div>
                </div>

                <!-- Quick Stats Row -->
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="stat-label">Days in Period</div>
                        <div class="stat-number"><?php echo $days_in_period; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Loans/Day</div>
                        <div class="stat-number"><?php echo number_format($avg_daily_loans, 1); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Collections/Day</div>
                        <div class="stat-number">₹<?php echo number_format($avg_daily_collections, 0); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">Interest Rate</div>
                        <div class="stat-number"><?php echo number_format($summary['total_interest_earned'] / max($summary['total_loans_issued'], 1) * 100, 1); ?>%</div>
                    </div>
                </div>

                <!-- Chart Grid -->
                <div class="chart-grid">
                    <!-- Loan Performance Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-bar-chart-line"></i> Loan Performance</h3>
                            <span class="badge">Daily Trend</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="loanChart"></canvas>
                        </div>
                    </div>

                    <!-- Collection Chart -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-pie-chart"></i> Collections Breakdown</h3>
                            <span class="badge">Principal vs Interest</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="collectionChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="chart-grid">
                    <!-- Loan Status Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-diagram-3"></i> Loan Status</h3>
                            <span class="badge">Distribution</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <!-- Product Type Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-grid-3x3-gap-fill"></i> Product Type</h3>
                            <span class="badge">By Loan Amount</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="productChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Monthly Performance Chart -->
                <div class="chart-card full-width" style="margin-bottom: 25px;">
                    <div class="chart-header">
                        <h3><i class="bi bi-calendar-range"></i> Monthly Performance (Last 12 Months)</h3>
                        <span class="badge">Loans vs Collections</span>
                    </div>
                    <div class="chart-container" style="height: 350px;">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>

                <div class="chart-grid">
                    <!-- Karat Distribution -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-gem"></i> Karat Distribution</h3>
                            <span class="badge">By Weight</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="karatChart"></canvas>
                        </div>
                    </div>

                    <!-- Top Customers -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="bi bi-trophy"></i> Top Customers</h3>
                            <span class="badge">By Loan Amount</span>
                        </div>
                        <div class="chart-container">
                            <canvas id="customerChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Investment Chart -->
                <?php if (!empty($investment_dates)): ?>
                <div class="chart-card full-width" style="margin-top: 25px;">
                    <div class="chart-header">
                        <h3><i class="bi bi-pie-chart-fill"></i> Investment Trends</h3>
                        <span class="badge">Daily Investment Amount</span>
                    </div>
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="investmentChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Detailed Data Table -->
                <div class="chart-card full-width" style="margin-top: 25px;">
                    <div class="chart-header">
                        <h3><i class="bi bi-table"></i> Detailed Transaction Data</h3>
                        <span class="badge">Period: <?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Loans</th>
                                    <th>Loan Amount</th>
                                    <th>Collections</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Investments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Combine data for display
                                $all_dates = array_unique(array_merge($loan_dates, $payment_dates, $investment_dates));
                                sort($all_dates);
                                
                                foreach($all_dates as $index => $date):
                                    $loan_amount = $loan_amounts[$index] ?? 0;
                                    $collection = $collection_amounts[$index] ?? 0;
                                    $principal = $principal_amounts[$index] ?? 0;
                                    $interest = $interest_amounts[$index] ?? 0;
                                    $investment = $investment_amounts[$index] ?? 0;
                                ?>
                                <tr>
                                    <td><?php echo $date; ?></td>
                                    <td><?php echo $loan_counts[$index] ?? 0; ?></td>
                                    <td class="amount">₹<?php echo number_format($loan_amount, 2); ?></td>
                                    <td class="amount">₹<?php echo number_format($collection, 2); ?></td>
                                    <td class="amount">₹<?php echo number_format($principal, 2); ?></td>
                                    <td class="amount">₹<?php echo number_format($interest, 2); ?></td>
                                    <td class="amount">₹<?php echo number_format($investment, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Chart color palette
    const colors = {
        primary: '#667eea',
        secondary: '#764ba2',
        success: '#48bb78',
        danger: '#f56565',
        warning: '#ed8936',
        info: '#4299e1',
        purple: '#9f7aea',
        pink: '#ed64a6',
        teal: '#38b2ac',
        orange: '#ed8936',
        gray: '#a0aec0'
    };

    // 1. Loan Performance Chart
    const loanCtx = document.getElementById('loanChart')?.getContext('2d');
    if (loanCtx) {
        new Chart(loanCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($loan_dates); ?>,
                datasets: [{
                    label: 'Loan Amount (₹)',
                    data: <?php echo json_encode($loan_amounts); ?>,
                    borderColor: colors.primary,
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Number of Loans',
                    data: <?php echo json_encode($loan_counts); ?>,
                    borderColor: colors.success,
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.dataset.label.includes('Amount')) {
                                    label += '₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                } else {
                                    label += context.raw;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Loan Amount (₹)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Loans'
                        },
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    }

    // 2. Collection Breakdown Chart
    const collectionCtx = document.getElementById('collectionChart')?.getContext('2d');
    if (collectionCtx) {
        new Chart(collectionCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($payment_dates); ?>,
                datasets: [{
                    label: 'Principal',
                    data: <?php echo json_encode($principal_amounts); ?>,
                    backgroundColor: colors.primary,
                    stack: 'Stack 0'
                }, {
                    label: 'Interest',
                    data: <?php echo json_encode($interest_amounts); ?>,
                    backgroundColor: colors.success,
                    stack: 'Stack 0'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                return label + ': ₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }

    // 3. Loan Status Chart
    const statusCtx = document.getElementById('statusChart')?.getContext('2d');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($loan_status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($loan_status_counts); ?>,
                    backgroundColor: [
                        colors.success,
                        colors.warning,
                        colors.danger,
                        colors.info,
                        colors.gray
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value} loans (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // 4. Product Type Chart
    const productCtx = document.getElementById('productChart')?.getContext('2d');
    if (productCtx) {
        new Chart(productCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($product_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($product_amounts); ?>,
                    backgroundColor: [
                        colors.primary,
                        colors.success,
                        colors.warning,
                        colors.info,
                        colors.purple
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw;
                                let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                let percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ₹${value.toLocaleString('en-IN')} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // 5. Monthly Performance Chart
    const monthlyCtx = document.getElementById('monthlyChart')?.getContext('2d');
    if (monthlyCtx) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [{
                    label: 'Loans Issued',
                    data: <?php echo json_encode($monthly_loans); ?>,
                    borderColor: colors.primary,
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Collections',
                    data: <?php echo json_encode($monthly_collections); ?>,
                    borderColor: colors.success,
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }

    // 6. Karat Distribution Chart
    const karatCtx = document.getElementById('karatChart')?.getContext('2d');
    if (karatCtx) {
        new Chart(karatCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($karat_labels); ?>,
                datasets: [{
                    label: 'Weight (g)',
                    data: <?php echo json_encode($karat_weights); ?>,
                    backgroundColor: colors.purple,
                    yAxisID: 'y'
                }, {
                    label: 'Loan Amount (₹)',
                    data: <?php echo json_encode($karat_amounts); ?>,
                    backgroundColor: colors.orange,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (context.dataset.label.includes('Weight')) {
                                    return label + ': ' + context.raw.toFixed(3) + ' g';
                                } else {
                                    return label + ': ₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2});
                                }
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Weight (g)' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Loan Amount (₹)' },
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    // 7. Top Customers Chart
    const customerCtx = document.getElementById('customerChart')?.getContext('2d');
    if (customerCtx) {
        new Chart(customerCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($customer_names); ?>,
                datasets: [{
                    label: 'Loan Amount',
                    data: <?php echo json_encode($customer_amounts); ?>,
                    backgroundColor: colors.teal,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }

    // 8. Investment Chart
    <?php if (!empty($investment_dates)): ?>
    const investmentCtx = document.getElementById('investmentChart')?.getContext('2d');
    if (investmentCtx) {
        new Chart(investmentCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($investment_dates); ?>,
                datasets: [{
                    label: 'Investment Amount',
                    data: <?php echo json_encode($investment_amounts); ?>,
                    borderColor: colors.pink,
                    backgroundColor: 'rgba(237, 100, 166, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '₹' + context.raw.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString('en-IN');
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

</body>
</html>