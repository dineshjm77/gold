<?php
session_start();
$currentPage = 'upcoming-collections';
$pageTitle = 'Upcoming Collections';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get filter parameters
$filter_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$filter_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$filter_branch = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Get branches for filter
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Calculate date range for the selected month
$start_date = "$filter_year-$filter_month-01";
$end_date = date('Y-m-t', strtotime($start_date));

// Get all open loans with upcoming payment dates
$query = "SELECT 
    l.id,
    l.receipt_number,
    l.receipt_date,
    l.loan_amount,
    l.interest_amount as interest_rate,
    l.receipt_charge,
    l.employee_id,
    l.status,
    c.id as customer_id,
    c.customer_name,
    c.mobile_number,
    c.email,
    u.name as employee_name,
    b.branch_name,
    b.id as branch_id,
    -- Calculate payment due date (next month from receipt date)
    DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) as payment_due_date,
    -- Calculate days until due
    DATEDIFF(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH), CURDATE()) as days_until_due,
    -- Calculate monthly interest amount
    (l.loan_amount * l.interest_amount / 100) as monthly_interest,
    -- Get total paid interest
    COALESCE((SELECT SUM(interest_amount) FROM payments WHERE loan_id = l.id), 0) as total_interest_paid,
    -- Get last payment date
    (SELECT MAX(payment_date) FROM payments WHERE loan_id = l.id) as last_payment_date,
    -- Count how many payments made
    (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count
FROM loans l
JOIN customers c ON l.customer_id = c.id
LEFT JOIN users u ON l.employee_id = u.id
LEFT JOIN branches b ON u.branch_id = b.id
WHERE l.status = 'open'
AND DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$param_types = "ss";

if ($filter_branch > 0) {
    $query .= " AND b.id = ?";
    $params[] = $filter_branch;
    $param_types .= "i";
}

if ($filter_status == 'overdue') {
    $query .= " AND DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) < CURDATE()";
} elseif ($filter_status == 'upcoming') {
    $query .= " AND DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) >= CURDATE()";
} elseif ($filter_status == 'paid') {
    $query .= " AND (SELECT COUNT(*) FROM payments WHERE loan_id = l.id AND MONTH(payment_date) = MONTH(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH)) AND YEAR(payment_date) = YEAR(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH))) > 0";
}

$query .= " ORDER BY payment_due_date ASC";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $param_types, ...$params);
mysqli_stmt_execute($stmt);
$collections_result = mysqli_stmt_get_result($stmt);

// Get summary statistics
$stats_query = "SELECT 
    COUNT(*) as total_upcoming,
    SUM(CASE WHEN DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
    SUM(CASE WHEN DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) >= CURDATE() THEN 1 ELSE 0 END) as upcoming_count,
    SUM(l.loan_amount * l.interest_amount / 100) as total_expected_interest,
    AVG(l.loan_amount * l.interest_amount / 100) as avg_interest,
    SUM(l.loan_amount) as total_loan_amount
FROM loans l
WHERE l.status = 'open'
AND DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) BETWEEN ? AND ?";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, 'ss', $start_date, $end_date);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);

// Get monthly breakdown for the year
$monthly_query = "SELECT 
    MONTH(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH)) as month_num,
    DATE_FORMAT(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH), '%M') as month_name,
    COUNT(*) as total_loans,
    SUM(l.loan_amount * l.interest_amount / 100) as total_interest,
    SUM(l.loan_amount) as total_principal,
    SUM(CASE WHEN DATE_ADD(l.receipt_date, INTERVAL 1 MONTH) < CURDATE() THEN 1 ELSE 0 END) as overdue
FROM loans l
WHERE l.status = 'open'
AND YEAR(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH)) = ?
GROUP BY MONTH(DATE_ADD(l.receipt_date, INTERVAL 1 MONTH))
ORDER BY month_num ASC";

$monthly_stmt = mysqli_prepare($conn, $monthly_query);
mysqli_stmt_bind_param($monthly_stmt, 'i', $filter_year);
mysqli_stmt_execute($monthly_stmt);
$monthly_result = mysqli_stmt_get_result($monthly_stmt);

// Function to get status badge
function getStatusBadge($days_until_due, $has_payment) {
    if ($has_payment) {
        return '<span class="badge badge-success">Paid</span>';
    } elseif ($days_until_due < 0) {
        return '<span class="badge badge-danger">Overdue</span>';
    } elseif ($days_until_due <= 3) {
        return '<span class="badge badge-warning">Due Soon</span>';
    } else {
        return '<span class="badge badge-info">Upcoming</span>';
    }
}

// Format currency
function formatCurrency($amount) {
    return '₹ ' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .collections-container {
            max-width: 1400px;
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

        /* Table Card - FIXED: No DataTables */
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

        .search-box {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .collections-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .collections-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .collections-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .collections-table tbody tr:hover {
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

        .positive {
            color: #48bb78;
            font-weight: 600;
        }

        .negative {
            color: #f56565;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .action-btn.collect {
            background: #48bb78;
            color: white;
        }

        .action-btn.view {
            background: #4299e1;
            color: white;
        }

        .action-btn.history {
            background: #9f7aea;
            color: white;
        }

        /* Pagination - FIXED: Custom pagination */
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .page-item {
            list-style: none;
        }

        .page-link {
            padding: 8px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s;
            cursor: pointer;
            background: white;
        }

        .page-link:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Payment History Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .modal-close {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-date {
            font-weight: 600;
            color: #667eea;
        }

        .payment-amount {
            font-weight: 600;
            color: #48bb78;
        }

        /* Entries per page selector */
        .entries-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .entries-selector select {
            padding: 6px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }

        .table-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            font-size: 13px;
            color: #718096;
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
                <div class="collections-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-calendar-check"></i>
                            Upcoming Collections
                        </h1>
                        <div>
                            <a href="loan-collection.php" class="btn btn-success">
                                <i class="bi bi-cash-coin"></i> Collect Payment
                            </a>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Collections
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Month</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-month input-icon"></i>
                                        <select class="form-select" name="month">
                                            <?php for($m = 1; $m <= 12; $m++): 
                                                $month_name = date('F', mktime(0, 0, 0, $m, 1));
                                            ?>
                                                <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo $filter_month == str_pad($m, 2, '0', STR_PAD_LEFT) ? 'selected' : ''; ?>>
                                                    <?php echo $month_name; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Year</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar-year input-icon"></i>
                                        <select class="form-select" name="year">
                                            <?php for($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                                <option value="<?php echo $y; ?>" <?php echo $filter_year == $y ? 'selected' : ''; ?>>
                                                    <?php echo $y; ?>
                                                </option>
                                            <?php endfor; ?>
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
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $filter_branch == $branch['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($branch['branch_name']); ?>
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
                                            <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All</option>
                                            <option value="upcoming" <?php echo $filter_status == 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                            <option value="overdue" <?php echo $filter_status == 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                            <option value="paid" <?php echo $filter_status == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Due This Month</div>
                                <div class="stat-value"><?php echo intval($stats['total_upcoming'] ?? 0); ?></div>
                                <div class="stat-sub">Loans due in <?php echo date('F Y', strtotime($start_date)); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #ecc94b;">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Overdue</div>
                                <div class="stat-value" style="color: #ecc94b;"><?php echo intval($stats['overdue_count'] ?? 0); ?></div>
                                <div class="stat-sub">Past due date</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #48bb78;">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Expected Interest</div>
                                <div class="stat-value" style="color: #48bb78;"><?php echo formatCurrency($stats['total_expected_interest'] ?? 0); ?></div>
                                <div class="stat-sub">Avg: <?php echo formatCurrency($stats['avg_interest'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon" style="color: #9f7aea;">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Principal</div>
                                <div class="stat-value" style="color: #9f7aea;"><?php echo formatCurrency($stats['total_loan_amount'] ?? 0); ?></div>
                                <div class="stat-sub">Outstanding amount</div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <?php if (mysqli_num_rows($monthly_result) > 0): ?>
                    <div class="charts-row">
                        <!-- Monthly Collections Chart -->
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-bar-chart"></i>
                                Monthly Collections - <?php echo $filter_year; ?>
                            </div>
                            <div class="chart-container">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>

                        <!-- Status Distribution Chart -->
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart"></i>
                                Payment Status Distribution
                            </div>
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Collections Table - FIXED: No DataTables -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Collections for <?php echo date('F Y', strtotime($start_date)); ?>
                                <span class="badge badge-info" style="margin-left: 10px;" id="recordCount">
                                    <?php echo mysqli_num_rows($collections_result); ?> records
                                </span>
                            </span>
                            <div>
                                <input type="text" id="tableSearch" class="search-box" placeholder="Search in table...">
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="collections-table" id="collectionsTable">
                                <thead>
                                    <tr>
                                        <th>Due Date</th>
                                        <th>Receipt No</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th>Branch</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest Rate</th>
                                        <th class="text-right">Monthly Interest</th>
                                        <th>Status</th>
                                        <th>Days</th>
                                        <th>Last Payment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <?php if (mysqli_num_rows($collections_result) > 0): ?>
                                        <?php while($row = mysqli_fetch_assoc($collections_result)): 
                                            $has_payment = ($row['payment_count'] > 0 && date('Y-m', strtotime($row['last_payment_date'] ?? '')) == $filter_year . '-' . $filter_month);
                                            $status_badge = getStatusBadge($row['days_until_due'], $has_payment);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo date('d-m-Y', strtotime($row['payment_due_date'])); ?></strong>
                                            </td>
                                            <td>
                                                <a href="view-loan.php?id=<?php echo $row['id']; ?>" style="color: #667eea; text-decoration: none;">
                                                    <strong><?php echo htmlspecialchars($row['receipt_number']); ?></strong>
                                                </a>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong>
                                                </div>
                                                <small style="color: #718096;">ID: <?php echo $row['customer_id']; ?></small>
                                            </td>
                                            <td>
                                                <i class="bi bi-phone" style="color: #48bb78;"></i>
                                                <a href="tel:<?php echo htmlspecialchars($row['mobile_number']); ?>" style="color: #2d3748; text-decoration: none;">
                                                    <?php echo htmlspecialchars($row['mobile_number']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['branch_name'] ?? 'Main'); ?></td>
                                            <td class="text-right amount"><?php echo formatCurrency($row['loan_amount']); ?></td>
                                            <td class="text-right"><?php echo $row['interest_rate']; ?>%</td>
                                            <td class="text-right positive"><?php echo formatCurrency($row['monthly_interest']); ?></td>
                                            <td><?php echo $status_badge; ?></td>
                                            <td class="text-center <?php echo $row['days_until_due'] < 0 ? 'negative' : ''; ?>">
                                                <?php echo $row['days_until_due'] > 0 ? '+' . $row['days_until_due'] : $row['days_until_due']; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['last_payment_date']): ?>
                                                    <small><?php echo date('d-m-Y', strtotime($row['last_payment_date'])); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">No payments</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="loan-collection.php?loan_id=<?php echo $row['id']; ?>" class="action-btn collect" title="Collect Payment">
                                                        <i class="bi bi-cash"></i> Collect
                                                    </a>
                                                    <a href="view-loan.php?id=<?php echo $row['id']; ?>" class="action-btn view" title="View Details">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <button class="action-btn history" onclick="viewPaymentHistory(<?php echo $row['id']; ?>)" title="Payment History">
                                                        <i class="bi bi-clock-history"></i> History
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12" class="text-center" style="padding: 40px;">
                                                <i class="bi bi-calendar-x" style="font-size: 48px; color: #cbd5e0;"></i>
                                                <p style="margin-top: 10px;">No upcoming collections found for <?php echo date('F Y', strtotime($start_date)); ?></p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination and Entries Info - FIXED: Custom pagination -->
                        <div class="table-info">
                            <div class="entries-selector">
                                <label>Show</label>
                                <select id="entriesPerPage" onchange="changeEntriesPerPage()">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="-1">All</option>
                                </select>
                                <label>entries</label>
                            </div>
                            <div id="tableInfo"></div>
                        </div>
                        <div class="pagination" id="pagination"></div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Payment History Modal -->
    <div class="modal" id="paymentHistoryModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closePaymentHistoryModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-clock-history"></i>
                Payment History
            </h3>
            <div id="paymentHistoryContent">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Chart.js initialization
        <?php if (mysqli_num_rows($monthly_result) > 0): ?>
        // Monthly Collections Chart
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyData = <?php 
            $months = [];
            $interests = [];
            $overdue = [];
            mysqli_data_seek($monthly_result, 0);
            while($row = mysqli_fetch_assoc($monthly_result)) {
                $months[] = $row['month_name'];
                $interests[] = floatval($row['total_interest']);
                $overdue[] = intval($row['overdue']);
            }
            echo json_encode(['months' => $months, 'interests' => $interests, 'overdue' => $overdue]);
        ?>;

        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyData.months,
                datasets: [{
                    label: 'Expected Interest (₹)',
                    data: monthlyData.interests,
                    backgroundColor: 'rgba(72, 187, 120, 0.5)',
                    borderColor: '#48bb78',
                    borderWidth: 1,
                    yAxisID: 'y'
                }, {
                    label: 'Overdue Count',
                    data: monthlyData.overdue,
                    type: 'line',
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
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
                        },
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Loans'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusData = <?php 
            $total = intval($stats['total_upcoming'] ?? 0);
            $overdue = intval($stats['overdue_count'] ?? 0);
            $upcoming = intval($stats['upcoming_count'] ?? 0);
            $paid = $total - $overdue - $upcoming;
            echo json_encode([
                'labels' => ['Overdue', 'Upcoming', 'Paid'],
                'data' => [$overdue, $upcoming, $paid]
            ]);
        ?>;

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.labels,
                datasets: [{
                    data: statusData.data,
                    backgroundColor: [
                        '#f56565',
                        '#4299e1',
                        '#48bb78'
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
        <?php endif; ?>

        // ========== CUSTOM PAGINATION AND SEARCH ==========
        let currentPage = 1;
        let entriesPerPage = 25;
        let tableRows = [];
        let filteredRows = [];

        // Get all table rows
        function getTableRows() {
            const tbody = document.getElementById('tableBody');
            const rows = [];
            if (tbody) {
                for (let i = 0; i < tbody.children.length; i++) {
                    rows.push(tbody.children[i]);
                }
            }
            return rows;
        }

        // Initialize table rows
        function initializeTable() {
            tableRows = getTableRows();
            filteredRows = [...tableRows];
            updateTable();
        }

        // Search functionality
        document.getElementById('tableSearch')?.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            if (searchTerm === '') {
                filteredRows = [...tableRows];
            } else {
                filteredRows = tableRows.filter(row => {
                    const cells = row.getElementsByTagName('td');
                    for (let i = 0; i < cells.length; i++) {
                        const cellText = cells[i].textContent || cells[i].innerText;
                        if (cellText.toLowerCase().includes(searchTerm)) {
                            return true;
                        }
                    }
                    return false;
                });
            }
            
            currentPage = 1;
            updateTable();
            updateRecordCount();
        });

        // Change entries per page
        function changeEntriesPerPage() {
            const select = document.getElementById('entriesPerPage');
            entriesPerPage = parseInt(select.value);
            if (isNaN(entriesPerPage)) entriesPerPage = filteredRows.length;
            currentPage = 1;
            updateTable();
        }

        // Update table display with pagination
        function updateTable() {
            const tbody = document.getElementById('tableBody');
            if (!tbody) return;

            // Hide all rows
            tableRows.forEach(row => row.style.display = 'none');
            
            // Show filtered and paginated rows
            const start = (currentPage - 1) * entriesPerPage;
            const end = entriesPerPage === -1 ? filteredRows.length : start + entriesPerPage;
            
            for (let i = start; i < end && i < filteredRows.length; i++) {
                filteredRows[i].style.display = '';
            }
            
            updatePagination();
            updateInfo();
        }

        // Update pagination controls
        function updatePagination() {
            const totalPages = entriesPerPage === -1 ? 1 : Math.ceil(filteredRows.length / entriesPerPage);
            const pagination = document.getElementById('pagination');
            if (!pagination) return;
            
            let html = '';
            
            // Previous button
            html += `<button class="page-link ${currentPage === 1 ? 'disabled' : ''}" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>Previous</button>`;
            
            // Page numbers
            const maxVisible = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            
            if (endPage - startPage + 1 < maxVisible) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }
            
            if (startPage > 1) {
                html += `<button class="page-link" onclick="changePage(1)">1</button>`;
                if (startPage > 2) html += `<span class="page-link disabled">...</span>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button class="page-link ${currentPage === i ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="page-link disabled">...</span>`;
                html += `<button class="page-link" onclick="changePage(${totalPages})">${totalPages}</button>`;
            }
            
            // Next button
            html += `<button class="page-link ${currentPage === totalPages ? 'disabled' : ''}" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Next</button>`;
            
            pagination.innerHTML = html;
        }

        // Change page
        function changePage(page) {
            const totalPages = entriesPerPage === -1 ? 1 : Math.ceil(filteredRows.length / entriesPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            updateTable();
        }

        // Update table info
        function updateInfo() {
            const info = document.getElementById('tableInfo');
            if (!info) return;
            
            const start = filteredRows.length === 0 ? 0 : (currentPage - 1) * entriesPerPage + 1;
            const end = Math.min(currentPage * entriesPerPage, filteredRows.length);
            
            info.textContent = `Showing ${start} to ${end} of ${filteredRows.length} entries`;
        }

        // Update record count badge
        function updateRecordCount() {
            const countBadge = document.getElementById('recordCount');
            if (countBadge) {
                countBadge.textContent = filteredRows.length + ' records';
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initializeTable();
        });

        // Reset filters
        function resetFilters() {
            window.location.href = 'upcoming-collections.php';
        }

        // View Payment History
        function viewPaymentHistory(loanId) {
            document.getElementById('paymentHistoryModal').classList.add('active');
            document.getElementById('paymentHistoryContent').innerHTML = '<div class="text-center"><i class="bi bi-arrow-clockwise spin"></i> Loading...</div>';
            
            fetch(`get-payment-history.php?loan_id=${loanId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let html = '<div style="max-height: 400px; overflow-y: auto;">';
                        
                        if (data.payments.length > 0) {
                            data.payments.forEach(payment => {
                                html += `
                                    <div class="payment-item">
                                        <div>
                                            <div class="payment-date">${payment.payment_date}</div>
                                            <small>${payment.receipt_number}</small>
                                        </div>
                                        <div>
                                            <div class="payment-amount">₹${payment.amount}</div>
                                            <small>${payment.type}</small>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html += '<p class="text-center">No payment history found</p>';
                        }
                        
                        html += '</div>';
                        document.getElementById('paymentHistoryContent').innerHTML = html;
                    } else {
                        document.getElementById('paymentHistoryContent').innerHTML = '<p class="text-center text-danger">Error loading payment history</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('paymentHistoryContent').innerHTML = '<p class="text-center text-danger">Error loading payment history</p>';
                });
        }

        function closePaymentHistoryModal() {
            document.getElementById('paymentHistoryModal').classList.remove('active');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>