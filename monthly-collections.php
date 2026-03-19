<?php
session_start();
$currentPage = 'monthly-collections';
$pageTitle = 'Monthly Collections';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

// Get month from URL or use current month
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = date('Y', strtotime($selected_month . '-01'));
$month_num = date('m', strtotime($selected_month . '-01'));
$month_name = date('F Y', strtotime($selected_month . '-01'));

// Get collection statistics for the month
$stats_query = "SELECT 
                    COUNT(DISTINCT DATE(collection_date)) as collection_days,
                    COUNT(*) as total_transactions,
                    COUNT(DISTINCT loan_id) as total_loans,
                    COUNT(DISTINCT customer_id) as total_customers,
                    SUM(principal_amount) as total_principal,
                    SUM(interest_amount) as total_interest,
                    SUM(penalty_amount) as total_penalty,
                    SUM(discount_amount) as total_discount,
                    SUM(net_amount) as total_collected
                FROM emi_collections 
                WHERE MONTH(collection_date) = ? AND YEAR(collection_date) = ?";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, 'ii', $month_num, $year);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);

// Get daily collections for the month
$daily_query = "SELECT 
                    DATE(collection_date) as coll_date,
                    DAYNAME(collection_date) as day_name,
                    COUNT(*) as transaction_count,
                    COUNT(DISTINCT loan_id) as loans_count,
                    SUM(principal_amount) as principal,
                    SUM(interest_amount) as interest,
                    SUM(penalty_amount) as penalty,
                    SUM(discount_amount) as discount,
                    SUM(net_amount) as total
                FROM emi_collections 
                WHERE MONTH(collection_date) = ? AND YEAR(collection_date) = ?
                GROUP BY DATE(collection_date)
                ORDER BY coll_date DESC";

$daily_stmt = mysqli_prepare($conn, $daily_query);
mysqli_stmt_bind_param($daily_stmt, 'ii', $month_num, $year);
mysqli_stmt_execute($daily_stmt);
$daily_result = mysqli_stmt_get_result($daily_stmt);

// Get top collectors for the month
$collectors_query = "SELECT 
                        u.name as collector_name,
                        COUNT(*) as collection_count,
                        SUM(net_amount) as total_collected
                    FROM emi_collections ec
                    JOIN users u ON ec.collected_by = u.id
                    WHERE MONTH(ec.collection_date) = ? AND YEAR(ec.collection_date) = ?
                    GROUP BY ec.collected_by
                    ORDER BY total_collected DESC
                    LIMIT 5";

$collectors_stmt = mysqli_prepare($conn, $collectors_query);
mysqli_stmt_bind_param($collectors_stmt, 'ii', $month_num, $year);
mysqli_stmt_execute($collectors_stmt);
$collectors_result = mysqli_stmt_get_result($collectors_stmt);

// Get recent collections for the month
$recent_query = "SELECT 
                    ec.*, 
                    pl.receipt_number,
                    c.customer_name,
                    u.name as collector_name
                FROM emi_collections ec
                JOIN personal_loans pl ON ec.loan_id = pl.id
                JOIN customers c ON ec.customer_id = c.id
                JOIN users u ON ec.collected_by = u.id
                WHERE MONTH(ec.collection_date) = ? AND YEAR(ec.collection_date) = ?
                ORDER BY ec.collection_date DESC, ec.id DESC
                LIMIT 20";

$recent_stmt = mysqli_prepare($conn, $recent_query);
mysqli_stmt_bind_param($recent_stmt, 'ii', $month_num, $year);
mysqli_stmt_execute($recent_stmt);
$recent_result = mysqli_stmt_get_result($recent_stmt);

// Calculate previous and next months
$prev_month = date('Y-m', strtotime($selected_month . ' -1 month'));
$next_month = date('Y-m', strtotime($selected_month . ' +1 month'));
$current_month = date('Y-m');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .month-selector {
            display: flex;
            gap: 10px;
            align-items: center;
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
            box-shadow: 0 4px 12px rgba(102,126,234,0.4);
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.15);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
        }

        .stat-icon.teal {
            background: linear-gradient(135deg, #38b2ac 0%, #319795 100%);
        }

        .stat-icon.red {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
        }

        .stat-icon.yellow {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 13px;
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

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .dashboard-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h3 i {
            color: #667eea;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .collection-table {
            width: 100%;
            border-collapse: collapse;
        }

        .collection-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .collection-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            color: #2d3748;
        }

        .collection-table tr:hover td {
            background: #f8fafc;
        }

        .amount {
            font-weight: 600;
            color: #48bb78;
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

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .summary-box {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-total {
            font-size: 18px;
            font-weight: 700;
            color: #48bb78;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #48bb78;
            display: flex;
            justify-content: space-between;
        }

        .progress-bar {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #48bb78, #4299e1);
            border-radius: 4px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .month-selector {
                flex-direction: column;
            }
            
            .btn {
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
                <div class="container">
                    <!-- Page Header with Month Selector -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-calendar-month"></i>
                            Monthly Collections: <?php echo $month_name; ?>
                        </h1>
                        <div class="month-selector">
                            <a href="?month=<?php echo $prev_month; ?>" class="btn btn-secondary">
                                <i class="bi bi-chevron-left"></i> Previous
                            </a>
                            <input type="month" class="form-control" value="<?php echo $selected_month; ?>" 
                                   onchange="window.location.href='?month='+this.value" style="width: 150px;">
                            <a href="?month=<?php echo $next_month; ?>" class="btn btn-secondary <?php echo ($selected_month >= $current_month) ? 'disabled' : ''; ?>">
                                Next <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon purple">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Collection Days</div>
                                    <div class="stat-value"><?php echo $stats['collection_days'] ?? 0; ?></div>
                                </div>
                            </div>
                            <div class="stat-sub">Active collection days</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon blue">
                                    <i class="bi bi-arrow-left-right"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Transactions</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_transactions'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="stat-sub">Total collections</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon green">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Customers</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_customers'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="stat-sub">Unique customers</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon orange">
                                    <i class="bi bi-briefcase"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Loans</div>
                                    <div class="stat-value"><?php echo number_format($stats['total_loans'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="stat-sub">Active loans collected</div>
                        </div>
                    </div>

                    <!-- Amount Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon teal">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Principal Collected</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_principal'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['total_principal'] ? 100 : 0; ?>%"></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon yellow">
                                    <i class="bi bi-percent"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Interest Collected</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_interest'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['total_interest'] ? 100 : 0; ?>%"></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon red">
                                    <i class="bi bi-exclamation-triangle"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Penalty</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_penalty'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['total_penalty'] ? 100 : 0; ?>%"></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-header">
                                <div class="stat-icon green">
                                    <i class="bi bi-gift"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Discount Given</div>
                                    <div class="stat-value">₹<?php echo number_format($stats['total_discount'] ?? 0, 2); ?></div>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $stats['total_discount'] ? 100 : 0; ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Collection Card -->
                    <div class="dashboard-card" style="margin-bottom: 25px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                            <div>
                                <h3 style="color: white; margin-bottom: 10px;">Total Collection for <?php echo $month_name; ?></h3>
                                <div style="font-size: 48px; font-weight: 700;">₹<?php echo number_format($stats['total_collected'] ?? 0, 2); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 16px; opacity: 0.9;">Net Collected</div>
                                <div style="font-size: 24px; font-weight: 600;">₹<?php echo number_format(($stats['total_collected'] ?? 0), 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Dashboard Grid -->
                    <div class="dashboard-grid">
                        <!-- Daily Collections -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="bi bi-calendar-day"></i> Daily Collections</h3>
                            </div>
                            <div class="table-responsive">
                                <table class="collection-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Day</th>
                                            <th>Transactions</th>
                                            <th>Loans</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($daily_result && mysqli_num_rows($daily_result) > 0): ?>
                                            <?php while ($day = mysqli_fetch_assoc($daily_result)): ?>
                                            <tr>
                                                <td><?php echo date('d-m-Y', strtotime($day['coll_date'])); ?></td>
                                                <td><?php echo $day['day_name']; ?></td>
                                                <td><?php echo $day['transaction_count']; ?></td>
                                                <td><?php echo $day['loans_count']; ?></td>
                                                <td class="amount">₹<?php echo number_format($day['total'], 2); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" style="text-align: center; padding: 30px; color: #a0aec0;">
                                                    <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                    No collections found for this month
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Top Collectors -->
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3><i class="bi bi-trophy"></i> Top Collectors</h3>
                            </div>
                            <div class="table-responsive">
                                <table class="collection-table">
                                    <thead>
                                        <tr>
                                            <th>Collector</th>
                                            <th>Collections</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($collectors_result && mysqli_num_rows($collectors_result) > 0): ?>
                                            <?php while ($collector = mysqli_fetch_assoc($collectors_result)): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($collector['collector_name']); ?></td>
                                                <td><?php echo $collector['collection_count']; ?></td>
                                                <td class="amount">₹<?php echo number_format($collector['total_collected'], 2); ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" style="text-align: center; padding: 30px; color: #a0aec0;">
                                                    <i class="bi bi-person" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                    No collection data available
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Collections -->
                    <div class="dashboard-card" style="margin-top: 25px;">
                        <div class="card-header">
                            <h3><i class="bi bi-clock-history"></i> Recent Collections</h3>
                        </div>
                        <div class="table-responsive">
                            <table class="collection-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt</th>
                                        <th>Customer</th>
                                        <th>Principal</th>
                                        <th>Interest</th>
                                        <th>Penalty</th>
                                        <th>Net Amount</th>
                                        <th>Method</th>
                                        <th>Collected By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_result && mysqli_num_rows($recent_result) > 0): ?>
                                        <?php while ($row = mysqli_fetch_assoc($recent_result)): ?>
                                        <tr>
                                            <td><?php echo date('d-m-Y', strtotime($row['collection_date'])); ?></td>
                                            <td><strong><?php echo $row['receipt_number']; ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                                            <td>₹<?php echo number_format($row['principal_amount'], 2); ?></td>
                                            <td>₹<?php echo number_format($row['interest_amount'], 2); ?></td>
                                            <td>₹<?php echo number_format($row['penalty_amount'], 2); ?></td>
                                            <td class="amount">₹<?php echo number_format($row['net_amount'], 2); ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?php echo strtoupper($row['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['collector_name']); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center; padding: 40px; color: #a0aec0;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                                No recent collections found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Export Options -->
                    <div class="action-buttons" style="margin-top: 25px;">
                        <a href="export-collections.php?month=<?php echo $selected_month; ?>" class="btn btn-success">
                            <i class="bi bi-file-earmark-excel"></i> Export to Excel
                        </a>
                        <a href="print-collections.php?month=<?php echo $selected_month; ?>" class="btn btn-primary" target="_blank">
                            <i class="bi bi-printer"></i> Print Report
                        </a>
                        <a href="daily-collections.php" class="btn btn-info">
                            <i class="bi bi-calendar-day"></i> View Daily
                        </a>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script>
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