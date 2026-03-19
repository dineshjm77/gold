<?php
session_start();
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';

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

// ==================== STATS ====================

// Total Customers
$total_customers = $conn->query("SELECT COUNT(*) as cnt FROM customers")->fetch_assoc()['cnt'] ?? 0;

// Total Loans (Active)
$active_loans = $conn->query("SELECT COUNT(*) as cnt FROM loans WHERE status = 'open'")->fetch_assoc()['cnt'] ?? 0;

// Total Investments
$total_investments = $conn->query("SELECT COUNT(*) as cnt FROM investments")->fetch_assoc()['cnt'] ?? 0;

// Total Investors
$total_investors = $conn->query("SELECT COUNT(*) as cnt FROM investors")->fetch_assoc()['cnt'] ?? 0;

// Total Investment Amount
$total_investment_amount = $conn->query("SELECT SUM(investment_amount) as total FROM investments")->fetch_assoc()['total'] ?? 0;

// Total Loan Amount
$total_loan_amount = $conn->query("SELECT SUM(loan_amount) as total FROM loans WHERE status = 'open'")->fetch_assoc()['total'] ?? 0;

// Recent Activities
$recent_activities_query = "SELECT al.*, u.name as user_name
                            FROM activity_log al
                            JOIN users u ON al.user_id = u.id
                            ORDER BY al.created_at DESC
                            LIMIT 10";
$recent_activities = $conn->query($recent_activities_query);

// Recent Loans
$recent_loans_query = "SELECT l.*, c.customer_name
                       FROM loans l
                       LEFT JOIN customers c ON l.customer_id = c.id
                       ORDER BY l.created_at DESC
                       LIMIT 5";
$recent_loans = $conn->query($recent_loans_query);

// Recent Investments
$recent_investments_query = "SELECT * FROM investments ORDER BY created_at DESC LIMIT 5";
$recent_investments = $conn->query($recent_investments_query);

// Low Stock Alert (from categories)
$low_stock_query = "SELECT category_name, total_quantity, min_stock_level
                    FROM categories
                    WHERE total_quantity <= min_stock_level AND min_stock_level > 0
                    ORDER BY (total_quantity / min_stock_level) ASC
                    LIMIT 5";
$low_stock_result = $conn->query($low_stock_query);
$low_stock_count = $low_stock_result ? $low_stock_result->num_rows : 0;

// Today's collections (example - adjust based on your actual payment table)
$today_collections = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM payments WHERE DATE(payment_date) = '$today'")->fetch_assoc()['total'] ?? 0;

// Get pending edit requests (for admin only)
$pending_requests = null;
$pending_count = 0;
if ($user_role === 'admin') {
    $pending_requests_query = "SELECT er.*, l.receipt_number, c.customer_name, u.name as requester_name 
                              FROM loan_edit_requests er
                              JOIN loans l ON er.loan_id = l.id
                              JOIN customers c ON l.customer_id = c.id
                              JOIN users u ON er.requested_by = u.id
                              WHERE er.status = 'pending'
                              ORDER BY er.created_at DESC
                              LIMIT 5";
    $pending_requests = $conn->query($pending_requests_query);
    $pending_count = $pending_requests ? $pending_requests->num_rows : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner:before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .welcome-banner:after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .welcome-banner h2 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .date-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 50px;
            display: inline-block;
            font-size: 14px;
            margin-top: 10px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
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

        .stat-header {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
            z-index: 1;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
        }

        .stat-icon.purple {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .stat-icon.blue {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            box-shadow: 0 10px 20px rgba(66, 153, 225, 0.3);
        }

        .stat-icon.green {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            box-shadow: 0 10px 20px rgba(72, 187, 120, 0.3);
        }

        .stat-icon.orange {
            background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
            box-shadow: 0 10px 20px rgba(237, 137, 54, 0.3);
        }

        .stat-icon.red {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            box-shadow: 0 10px 20px rgba(245, 101, 101, 0.3);
        }

        .stat-icon.teal {
            background: linear-gradient(135deg, #38b2ac 0%, #319795 100%);
            box-shadow: 0 10px 20px rgba(56, 178, 172, 0.3);
        }

        .stat-icon.pink {
            background: linear-gradient(135deg, #ed64a6 0%, #d53f8c 100%);
            box-shadow: 0 10px 20px rgba(237, 100, 166, 0.3);
        }

        .stat-icon.indigo {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            box-shadow: 0 10px 20px rgba(159, 122, 234, 0.3);
        }

        .stat-content {
            flex: 1;
        }

        .stat-title {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
        }

        .stat-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trend-up {
            color: #48bb78;
        }

        .trend-down {
            color: #f56565;
        }

        /* Quick Actions */
        .quick-actions-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .quick-action-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #4a5568;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .quick-action-card:hover {
            transform: translateY(-5px);
            border-color: #667eea;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }

        .quick-action-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
        }

        .quick-action-title {
            font-size: 14px;
            font-weight: 600;
        }

        /* Dashboard Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        .dashboard-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
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
            font-size: 20px;
        }

        .card-header a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .card-header a:hover {
            color: #764ba2;
            gap: 8px;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .dashboard-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dashboard-table th {
            text-align: left;
            padding: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #718096;
            background: #f7fafc;
            border-radius: 10px 10px 0 0;
        }

        .dashboard-table td {
            padding: 12px;
            font-size: 14px;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
        }

        .dashboard-table tbody tr:hover td {
            background: #f8fafc;
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

        .badge-purple {
            background: #e9d8fd;
            color: #553c9a;
        }

        .amount {
            font-weight: 600;
            color: #48bb78;
        }

        /* Activity List */
        .activity-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
            font-size: 16px;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .activity-meta {
            font-size: 12px;
            color: #a0aec0;
            display: flex;
            gap: 15px;
        }

        .activity-meta i {
            margin-right: 3px;
        }

        /* Chart Placeholder */
        .chart-placeholder {
            height: 200px;
            background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a0aec0;
            font-size: 14px;
            border: 2px dashed #e2e8f0;
        }

        /* Action Buttons for Requests */
        .action-btn {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            margin: 0 2px;
        }

        .action-btn.approve {
            background: #c6f6d5;
            color: #22543d;
        }

        .action-btn.reject {
            background: #fed7d7;
            color: #742a2a;
        }

        .action-btn:hover {
            opacity: 0.8;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-content {
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .welcome-banner h2 {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .stat-header {
                flex-direction: column;
                text-align: center;
            }
            
            .stat-footer {
                flex-direction: column;
                gap: 10px;
                text-align: center;
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
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <h2>Welcome back, <?php echo htmlspecialchars($user_name); ?>! 👋</h2>
                    <p>Here's what's happening with your business today.</p>
                    <div class="date-badge">
                        <i class="bi bi-calendar3" style="margin-right: 8px;"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <!-- Total Customers -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon purple">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Total Customers</div>
                                <div class="stat-value"><?php echo number_format($total_customers); ?></div>
                                <div class="stat-sub">Registered customers</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>Active customers</span>
                            <span class="stat-trend trend-up">
                                <i class="bi bi-arrow-up"></i>
                                <?php echo number_format($total_customers); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Active Loans -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Active Loans</div>
                                <div class="stat-value"><?php echo number_format($active_loans); ?></div>
                                <div class="stat-sub">Open loan accounts</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>Total loan amount</span>
                            <span class="amount">₹<?php echo number_format($total_loan_amount, 2); ?></span>
                        </div>
                    </div>

                    <!-- Total Investments -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon green">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Total Investments</div>
                                <div class="stat-value"><?php echo number_format($total_investments); ?></div>
                                <div class="stat-sub">Investment accounts</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>Investment amount</span>
                            <span class="amount">₹<?php echo number_format($total_investment_amount, 2); ?></span>
                        </div>
                    </div>

                    <!-- Total Investors -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon orange">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Total Investors</div>
                                <div class="stat-value"><?php echo number_format($total_investors); ?></div>
                                <div class="stat-sub">Registered investors</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>Active investors</span>
                            <span class="stat-trend trend-up">
                                <i class="bi bi-arrow-up"></i>
                                <?php echo number_format($total_investors); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Today's Collections -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon teal">
                                <i class="bi bi-wallet2"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Today's Collections</div>
                                <div class="stat-value">₹<?php echo number_format($today_collections, 2); ?></div>
                                <div class="stat-sub">Total collected today</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>This month</span>
                            <span class="stat-trend trend-up">
                                <i class="bi bi-arrow-up"></i>
                                ₹<?php echo number_format($today_collections * 30, 2); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Low Stock Alert -->
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-icon red">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-title">Low Stock Alert</div>
                                <div class="stat-value"><?php echo $low_stock_count; ?></div>
                                <div class="stat-sub">Items below minimum</div>
                            </div>
                        </div>
                        <div class="stat-footer">
                            <span>Categories</span>
                            <span class="stat-trend trend-down">
                                <i class="bi bi-exclamation-circle"></i>
                                Need attention
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions-title">
                    <i class="bi bi-lightning-charge-fill" style="color: #667eea;"></i>
                    Quick Actions
                </div>
                <div class="quick-actions">
                    <a href="New-Loan.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-plus-circle"></i></div>
                        <div class="quick-action-title">New Loan</div>
                    </a>
                    <a href="New-Customer.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-person-plus"></i></div>
                        <div class="quick-action-title">Add Customer</div>
                    </a>
                    <a href="Investment.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-pie-chart"></i></div>
                        <div class="quick-action-title">New Investment</div>
                    </a>
                    <a href="Investment-Return.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-arrow-return-left"></i></div>
                        <div class="quick-action-title">Investment Return</div>
                    </a>
                    <a href="categories.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-tags"></i></div>
                        <div class="quick-action-title">Manage Categories</div>
                    </a>
                    <a href="reports.php" class="quick-action-card">
                        <div class="quick-action-icon"><i class="bi bi-file-earmark-bar-graph"></i></div>
                        <div class="quick-action-title">View Reports</div>
                    </a>
                </div>

                <!-- Pending Edit Requests Card (only for admin) -->
                <?php if ($user_role === 'admin' && $pending_count > 0): ?>
                <div class="dashboard-card" style="margin-bottom: 25px; border-left: 4px solid #9f7aea;">
                    <div class="card-header">
                        <h3><i class="bi bi-send" style="color: #9f7aea;"></i> Pending Edit Requests (<?php echo $pending_count; ?>)</h3>
                        <a href="loan-edit-requests.php">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="table-responsive">
                        <table class="dashboard-table">
                            <thead>
                                <tr>
                                    <th>Loan #</th>
                                    <th>Customer</th>
                                    <th>Requested By</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($request = $pending_requests->fetch_assoc()): ?>
                                <tr>
                                    <td><a href="view-loan.php?id=<?php echo $request['loan_id']; ?>" style="color: #667eea; text-decoration: none; font-weight: 600;"><?php echo $request['receipt_number']; ?></a></td>
                                    <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                                    <td><?php echo htmlspecialchars($request['request_reason']); ?></td>
                                    <td><?php echo date('d-m-Y', strtotime($request['created_at'])); ?></td>
                                    <td>
                                        <a href="approve-edit-request.php?id=<?php echo $request['id']; ?>" class="action-btn approve">Approve</a>
                                        <a href="reject-edit-request.php?id=<?php echo $request['id']; ?>" class="action-btn reject">Reject</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Dashboard Grid -->
                <div class="dashboard-grid">
                    <!-- Recent Loans -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3><i class="bi bi-cash-stack"></i> Recent Loans</h3>
                            <a href="loans.php">View All <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="table-responsive">
                            <table class="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Receipt #</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_loans && $recent_loans->num_rows > 0): ?>
                                        <?php while ($loan = $recent_loans->fetch_assoc()): ?>
                                            <tr>
                                                <td><a href="view-loan.php?id=<?php echo $loan['id']; ?>" style="color: #667eea; text-decoration: none;"><?php echo htmlspecialchars($loan['receipt_number']); ?></a></td>
                                                <td><?php echo htmlspecialchars($loan['customer_name'] ?? 'Unknown'); ?></td>
                                                <td class="amount">₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $loan['status'] === 'open' ? 'success' : 'warning'; ?>">
                                                        <?php echo ucfirst($loan['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: #a0aec0; padding: 20px;">
                                                <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                No loans found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Investments -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3><i class="bi bi-pie-chart"></i> Recent Investments</h3>
                            <a href="Investment.php">View All <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <div class="table-responsive">
                            <table class="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Inv No.</th>
                                        <th>Investor</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_investments && $recent_investments->num_rows > 0): ?>
                                        <?php while ($inv = $recent_investments->fetch_assoc()): ?>
                                            <tr>
                                                <td>#<?php echo htmlspecialchars($inv['investment_no']); ?></td>
                                                <td><?php echo htmlspecialchars($inv['investor_name']); ?></td>
                                                <td class="amount">₹<?php echo number_format($inv['investment_amount'], 2); ?></td>
                                                <td><?php echo htmlspecialchars($inv['investment_type']); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="text-align: center; color: #a0aec0; padding: 20px;">
                                                <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                                                No investments found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="dashboard-card" style="margin-top: 25px;">
                    <div class="card-header">
                        <h3><i class="bi bi-clock-history"></i> Recent Activities</h3>
                    </div>
                    <div class="activity-list">
                        <?php if ($recent_activities && $recent_activities->num_rows > 0): ?>
                            <?php while ($activity = $recent_activities->fetch_assoc()): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="bi bi-<?php 
                                            echo $activity['action'] === 'create' ? 'plus-circle' : 
                                                ($activity['action'] === 'update' ? 'pencil' : 
                                                ($activity['action'] === 'delete' ? 'trash' : 
                                                ($activity['action'] === 'login' ? 'box-arrow-in-right' : 'info-circle'))); 
                                        ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <strong><?php echo htmlspecialchars($activity['user_name']); ?></strong>
                                            <?php echo htmlspecialchars($activity['action']); ?> 
                                            <?php echo htmlspecialchars($activity['description']); ?>
                                        </div>
                                        <div class="activity-meta">
                                            <span><i class="bi bi-clock"></i> <?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?></span>
                                            <?php if ($activity['table_name']): ?>
                                                <span><i class="bi bi-table"></i> <?php echo $activity['table_name']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; color: #a0aec0; padding: 40px;">
                                <i class="bi bi-clock-history" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                <p>No recent activities</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Performance Chart Placeholder -->
                <div class="dashboard-card" style="margin-top: 25px;">
                    <div class="card-header">
                        <h3><i class="bi bi-graph-up"></i> Performance Overview</h3>
                    </div>
                    <div class="chart-placeholder">
                        <i class="bi bi-bar-chart-line" style="font-size: 48px; margin-bottom: 10px; display: block;"></i>
                        Chart visualization will appear here
                    </div>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
</body>
</html>