<?php
session_start();
$currentPage = 'closed-receipt-in-bank';
$pageTitle = 'Closed Receipts in Bank';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'sale'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle date filter submission
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'closed';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}

// Get banks for filter dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Build the main query for closed bank loans
$query = "SELECT bl.*, 
          b.bank_short_name, b.bank_full_name,
          ba.account_holder_no, ba.bank_account_no,
          c.customer_name, c.mobile_number, c.email,
          u.name as employee_name,
          (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id) as total_emis,
          (SELECT COUNT(*) FROM bank_loan_emi WHERE bank_loan_id = bl.id AND status = 'paid') as paid_emis,
          (SELECT COUNT(*) FROM bank_loan_items WHERE bank_loan_id = bl.id) as items_count,
          (SELECT SUM(payment_amount) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as total_paid,
          (SELECT COUNT(*) FROM bank_loan_payments WHERE bank_loan_id = bl.id) as payment_count
          FROM bank_loans bl
          LEFT JOIN bank_master b ON bl.bank_id = b.id
          LEFT JOIN bank_accounts ba ON bl.bank_account_id = ba.id
          LEFT JOIN customers c ON bl.customer_id = c.id
          LEFT JOIN users u ON bl.employee_id = u.id
          WHERE bl.status IN ('closed', 'defaulted')";

$params = [];
$param_types = "";

if ($bank_id > 0) {
    $query .= " AND bl.bank_id = ?";
    $params[] = $bank_id;
    $param_types .= "i";
}

if ($status_filter != 'all') {
    $query .= " AND bl.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($from_date) && !empty($to_date)) {
    $query .= " AND (bl.close_date BETWEEN ? AND ? OR (bl.close_date IS NULL AND bl.loan_date BETWEEN ? AND ?))";
    $params[] = $from_date;
    $params[] = $to_date;
    $params[] = $from_date;
    $params[] = $to_date;
    $param_types .= "ssss";
}

if (!empty($search_term)) {
    $query .= " AND (c.customer_name LIKE ? OR c.mobile_number LIKE ? OR bl.loan_reference LIKE ? OR b.bank_short_name LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ssss";
}

$query .= " ORDER BY bl.close_date DESC, bl.created_at DESC";

// Prepare and execute the query
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $query);
}

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_closed,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as fully_closed,
                    SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted,
                    SUM(loan_amount) as total_loan_amount,
                    SUM(total_payable) as total_payable,
                    SUM(total_interest) as total_interest,
                    SUM(document_charge + processing_fee) as total_charges,
                    AVG(interest_rate) as avg_interest_rate,
                    AVG(tenure_months) as avg_tenure
                  FROM bank_loans
                  WHERE status IN ('closed', 'defaulted')";

if ($bank_id > 0) {
    $summary_query .= " AND bank_id = $bank_id";
}
if (!empty($from_date) && !empty($to_date)) {
    $summary_query .= " AND (close_date BETWEEN '$from_date' AND '$to_date' OR (close_date IS NULL AND loan_date BETWEEN '$from_date' AND '$to_date'))";
}

$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

// Get monthly breakdown for chart
$monthly_query = "SELECT 
                    DATE_FORMAT(close_date, '%Y-%m') as month,
                    COUNT(*) as closed_count,
                    SUM(loan_amount) as total_amount,
                    SUM(total_interest) as total_interest,
                    AVG(interest_rate) as avg_rate
                  FROM bank_loans
                  WHERE status = 'closed' AND close_date IS NOT NULL
                  GROUP BY DATE_FORMAT(close_date, '%Y-%m')
                  ORDER BY month DESC
                  LIMIT 12";
$monthly_result = mysqli_query($conn, $monthly_query);

// Get bank-wise breakdown
$bankwise_query = "SELECT 
                    b.bank_short_name,
                    COUNT(bl.id) as loan_count,
                    SUM(bl.loan_amount) as total_amount,
                    SUM(bl.total_interest) as total_interest,
                    AVG(bl.interest_rate) as avg_rate
                  FROM bank_loans bl
                  JOIN bank_master b ON bl.bank_id = b.id
                  WHERE bl.status = 'closed'
                  GROUP BY bl.bank_id
                  ORDER BY total_amount DESC";
$bankwise_result = mysqli_query($conn, $bankwise_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Chart.js for graphs -->
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

        .closed-receipts-container {
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

        .filter-title i {
            color: #667eea;
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
            grid-template-columns: 1fr 1fr;
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
            height: 250px;
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
        }

        .table-responsive {
            overflow-x: auto;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .receipt-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .receipt-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .receipt-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-closed {
            background: #48bb78;
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

        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .customer-name {
            font-weight: 600;
            color: #2d3748;
        }

        .customer-mobile {
            font-size: 12px;
            color: #718096;
        }

        .customer-email {
            font-size: 11px;
            color: #a0aec0;
        }

        .payment-details {
            font-size: 12px;
            color: #718096;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Modal */
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
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
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

        /* Items Grid */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .item-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            display: flex;
            gap: 10px;
        }

        .item-photo {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            object-fit: cover;
            background: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #a0aec0;
        }

        .item-details {
            flex: 1;
        }

        .item-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .item-meta {
            font-size: 11px;
            color: #718096;
        }

        /* Timeline */
        .timeline {
            margin: 20px 0;
        }

        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 10px 0;
            border-left: 2px solid #667eea;
            padding-left: 20px;
            position: relative;
            margin-left: 10px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 15px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #667eea;
        }

        .timeline-date {
            min-width: 100px;
            font-weight: 600;
            color: #4a5568;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-amount {
            font-weight: 600;
            color: #48bb78;
        }

        .summary-box {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row.total {
            font-weight: 700;
            font-size: 16px;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #667eea;
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
            
            .modal-content {
                width: 95%;
                padding: 15px;
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
                <div class="closed-receipts-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-archive"></i>
                            Closed Receipts in Bank
                        </h1>
                        <div>
                            <button class="btn btn-success" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i> Export to Excel
                            </button>
                            <button class="btn btn-primary" onclick="window.print()">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-archive"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Closed Receipts</div>
                                <div class="stat-value"><?php echo safeNumberFormat($summary['total_closed'] ?? 0, 0); ?></div>
                                <div class="stat-sub">
                                    Fully Closed: <?php echo safeNumberFormat($summary['fully_closed'] ?? 0, 0); ?> | 
                                    Defaulted: <?php echo safeNumberFormat($summary['defaulted'] ?? 0, 0); ?>
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
                                <div class="stat-sub">Total Payable: ₹<?php echo safeNumberFormat($summary['total_payable'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Interest Earned</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($summary['total_interest'] ?? 0); ?></div>
                                <div class="stat-sub">Avg Rate: <?php echo safeNumberFormat($summary['avg_interest_rate'] ?? 0, 2); ?>%</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-gear"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Charges</div>
                                <div class="stat-value">₹<?php echo safeNumberFormat($summary['total_charges'] ?? 0); ?></div>
                                <div class="stat-sub">Avg Tenure: <?php echo safeNumberFormat($summary['avg_tenure'] ?? 0, 1); ?> months</div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row -->
                    <div class="charts-row">
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-graph-up"></i>
                                Monthly Closed Loans
                            </div>
                            <div class="chart-container">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <div class="chart-title">
                                <i class="bi bi-pie-chart"></i>
                                Bank-wise Distribution
                            </div>
                            <div class="chart-container">
                                <canvas id="bankwiseChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Closed Receipts
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
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
                                            <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Fully Closed</option>
                                            <option value="defaulted" <?php echo $status_filter == 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" name="search" placeholder="Customer, Receipt, Bank..." value="<?php echo htmlspecialchars($search_term); ?>">
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

                    <!-- Closed Receipts Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Closed Receipts List
                                <span class="badge badge-closed" style="margin-left: 10px;"><?php echo $result ? mysqli_num_rows($result) : 0; ?> records</span>
                            </span>
                            <div class="export-buttons">
                                <button class="btn btn-sm btn-info" onclick="copyToClipboard()">
                                    <i class="bi bi-clipboard"></i> Copy
                                </button>
                                <button class="btn btn-sm btn-success" onclick="exportToCSV()">
                                    <i class="bi bi-file-spreadsheet"></i> CSV
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="receipt-table" id="receiptTable">
                                <thead>
                                    <tr>
                                        <th>Receipt No.</th>
                                        <th>Close Date</th>
                                        <th>Bank</th>
                                        <th>Customer</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Total Payable</th>
                                        <th class="text-right">Total Paid</th>
                                        <th>Tenure</th>
                                        <th>EMIs</th>
                                        <th>Items</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($result && mysqli_num_rows($result) > 0) {
                                        while($loan = mysqli_fetch_assoc($result)): 
                                            $total_paid = floatval($loan['total_paid'] ?? 0);
                                            $total_payable = floatval($loan['total_payable'] ?? 0);
                                            $payment_percentage = $total_payable > 0 ? ($total_paid / $total_payable) * 100 : 0;
                                            $profit_loss = $total_paid - $loan['loan_amount'];
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($loan['loan_reference'] ?? ''); ?></strong>
                                            <br>
                                            <small class="payment-details">Created: <?php echo date('d-m-Y', strtotime($loan['loan_date'] ?? date('Y-m-d'))); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($loan['close_date'])) {
                                                echo date('d-m-Y', strtotime($loan['close_date']));
                                            } else {
                                                echo '<span class="badge badge-pending">Not closed</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($loan['bank_short_name'] ?? ''); ?></strong>
                                            <br>
                                            <small class="payment-details"><?php echo htmlspecialchars($loan['account_holder_no'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div class="customer-info">
                                                <span class="customer-name"><?php echo htmlspecialchars($loan['customer_name'] ?? ''); ?></span>
                                                <span class="customer-mobile"><?php echo htmlspecialchars($loan['mobile_number'] ?? ''); ?></span>
                                                <?php if (!empty($loan['email'])): ?>
                                                <span class="customer-email"><?php echo htmlspecialchars($loan['email']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($loan['loan_amount'] ?? 0); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($loan['total_interest'] ?? 0); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($loan['total_payable'] ?? 0); ?></td>
                                        <td class="text-right">
                                            <span class="<?php echo $total_paid >= $total_payable ? 'positive' : 'amount'; ?>">
                                                ₹<?php echo safeNumberFormat($total_paid); ?>
                                            </span>
                                            <br>
                                            <small class="payment-details">
                                                <?php echo intval($loan['payment_count'] ?? 0); ?> payments
                                            </small>
                                        </td>
                                        <td class="text-right"><?php echo intval($loan['tenure_months'] ?? 0); ?> months</td>
                                        <td class="text-center">
                                            <span class="badge badge-<?php echo intval($loan['paid_emis'] ?? 0) == intval($loan['total_emis'] ?? 0) ? 'closed' : 'pending'; ?>">
                                                <?php echo intval($loan['paid_emis'] ?? 0); ?>/<?php echo intval($loan['total_emis'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-info"><?php echo intval($loan['items_count'] ?? 0); ?> items</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $loan['status'] ?? 'closed'; ?>">
                                                <?php echo ucfirst($loan['status'] ?? 'closed'); ?>
                                            </span>
                                            <?php if ($profit_loss != 0): ?>
                                            <br>
                                            <small class="<?php echo $profit_loss >= 0 ? 'positive' : 'negative'; ?>">
                                                <?php echo $profit_loss >= 0 ? '+' : ''; ?>₹<?php echo safeNumberFormat($profit_loss); ?>
                                            </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-info btn-sm" onclick="viewReceiptDetails(<?php echo $loan['id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button class="btn btn-primary btn-sm" onclick="printReceipt(<?php echo $loan['id']; ?>)">
                                                    <i class="bi bi-printer"></i>
                                                </button>
                                                <button class="btn btn-success btn-sm" onclick="downloadReceipt(<?php echo $loan['id']; ?>)">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    } else {
                                        echo '<tr><td colspan="13" class="text-center" style="padding: 40px;">No closed receipts found</td></tr>';
                                    }
                                    ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td colspan="4" class="text-right">Totals:</td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($summary['total_loan_amount'] ?? 0); ?></td>
                                        <td class="text-right">₹<?php echo safeNumberFormat($summary['total_interest'] ?? 0); ?></td>
                                        <td class="text-right amount">₹<?php echo safeNumberFormat($summary['total_payable'] ?? 0); ?></td>
                                        <td colspan="6"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Receipt Details Modal -->
    <div class="modal" id="receiptModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeReceiptModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-receipt"></i>
                Receipt Details
            </h3>
            <div id="receiptDetails">
                <!-- Content will be loaded via AJAX -->
                <div class="text-center" style="padding: 40px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
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
            document.querySelector('select[name="status"]').value = 'closed';
            document.querySelector('input[name="search"]').value = '';
            document.getElementById('filterForm').submit();
        }

        // Copy to clipboard
        function copyToClipboard() {
            const table = document.getElementById('receiptTable');
            const range = document.createRange();
            range.selectNode(table);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            
            try {
                document.execCommand('copy');
                Swal.fire({
                    icon: 'success',
                    title: 'Copied!',
                    text: 'Table data copied to clipboard',
                    timer: 2000,
                    showConfirmButton: false
                });
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to copy to clipboard'
                });
            }
            
            window.getSelection().removeAllRanges();
        }

        // Export to CSV
        function exportToCSV() {
            const table = document.getElementById('receiptTable');
            let csv = [];
            
            // Get headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.innerText);
            });
            csv.push(headers.join(','));
            
            // Get data
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => {
                    // Clean the text (remove HTML and extra spaces)
                    let text = td.innerText.replace(/\s+/g, ' ').trim();
                    row.push('"' + text + '"');
                });
                csv.push(row.join(','));
            });
            
            // Download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'closed_receipts_<?php echo date('Y-m-d'); ?>.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Export to Excel (via CSV with .xls extension)
        function exportToExcel() {
            exportToCSV(); // Simple implementation - CSV works in Excel
        }

        // View receipt details
        function viewReceiptDetails(loanId) {
            document.getElementById('receiptModal').classList.add('active');
            
            // Load receipt details via AJAX
            fetch('ajax/get_bank_loan_details.php?loan_id=' + loanId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayReceiptDetails(data);
                    } else {
                        document.getElementById('receiptDetails').innerHTML = '<p class="text-center text-danger">Error loading receipt details</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('receiptDetails').innerHTML = '<p class="text-center text-danger">Error loading receipt details</p>';
                });
        }

        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.remove('active');
        }

        // Print receipt
        function printReceipt(loanId) {
            // Open print-friendly page
            window.open('print_bank_receipt.php?loan_id=' + loanId, '_blank');
        }

        // Download receipt
        function downloadReceipt(loanId) {
            window.open('download_bank_receipt.php?loan_id=' + loanId, '_blank');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Chart
            const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            const monthlyData = <?php 
                $months = [];
                $counts = [];
                $amounts = [];
                if ($monthly_result && mysqli_num_rows($monthly_result) > 0) {
                    mysqli_data_seek($monthly_result, 0);
                    while($row = mysqli_fetch_assoc($monthly_result)) {
                        $months[] = $row['month'];
                        $counts[] = $row['closed_count'];
                        $amounts[] = floatval($row['total_amount']);
                    }
                }
                echo json_encode(['months' => $months, 'counts' => $counts, 'amounts' => $amounts]);
            ?>;
            
            new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: monthlyData.months,
                    datasets: [{
                        label: 'Number of Loans',
                        data: monthlyData.counts,
                        backgroundColor: 'rgba(102, 126, 234, 0.5)',
                        borderColor: '#667eea',
                        borderWidth: 1,
                        yAxisID: 'y'
                    }, {
                        label: 'Loan Amount (₹)',
                        data: monthlyData.amounts,
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
                                text: 'Number of Loans'
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Amount (₹)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });

            // Bankwise Chart
            const bankwiseCtx = document.getElementById('bankwiseChart').getContext('2d');
            const bankwiseData = <?php 
                $banks = [];
                $bankAmounts = [];
                if ($bankwise_result && mysqli_num_rows($bankwise_result) > 0) {
                    mysqli_data_seek($bankwise_result, 0);
                    while($row = mysqli_fetch_assoc($bankwise_result)) {
                        $banks[] = $row['bank_short_name'];
                        $bankAmounts[] = floatval($row['total_amount']);
                    }
                }
                echo json_encode(['banks' => $banks, 'amounts' => $bankAmounts]);
            ?>;
            
            new Chart(bankwiseCtx, {
                type: 'doughnut',
                data: {
                    labels: bankwiseData.banks,
                    datasets: [{
                        data: bankwiseData.amounts,
                        backgroundColor: [
                            '#667eea',
                            '#48bb78',
                            '#f56565',
                            '#ecc94b',
                            '#4299e1',
                            '#9f7aea',
                            '#ed8936',
                            '#f687b3'
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
        });

        // Display receipt details
        function displayReceiptDetails(data) {
            const loan = data.loan;
            const items = data.items || [];
            const payments = data.payments || [];
            
            let html = `
                <div class="summary-box">
                    <div class="summary-row">
                        <span>Receipt Number:</span>
                        <span class="amount">${loan.loan_reference}</span>
                    </div>
                    <div class="summary-row">
                        <span>Bank:</span>
                        <span>${loan.bank_short_name} - ${loan.bank_full_name}</span>
                    </div>
                    <div class="summary-row">
                        <span>Customer:</span>
                        <span>${loan.customer_name} (${loan.mobile_number})</span>
                    </div>
                    <div class="summary-row">
                        <span>Loan Date:</span>
                        <span>${loan.loan_date}</span>
                    </div>
                    <div class="summary-row">
                        <span>Close Date:</span>
                        <span>${loan.close_date || 'N/A'}</span>
                    </div>
                    <div class="summary-row">
                        <span>Loan Amount:</span>
                        <span class="amount">₹${parseFloat(loan.loan_amount).toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Interest Rate:</span>
                        <span>${loan.interest_rate}%</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Interest:</span>
                        <span class="positive">₹${parseFloat(loan.total_interest).toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Document Charge:</span>
                        <span>₹${parseFloat(loan.document_charge || 0).toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Processing Fee:</span>
                        <span>₹${parseFloat(loan.processing_fee || 0).toFixed(2)}</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total Payable:</span>
                        <span class="amount">₹${parseFloat(loan.total_payable).toFixed(2)}</span>
                    </div>
                </div>
            `;
            
            if (items.length > 0) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Jewelry Items</h4>
                    <div class="items-grid">
                `;
                
                items.forEach(item => {
                    html += `
                        <div class="item-card">
                            <div class="item-photo">
                                ${item.photo_path ? 
                                    `<img src="${item.photo_path}" style="width: 100%; height: 100%; object-fit: cover;">` : 
                                    '<i class="bi bi-gem"></i>'
                                }
                            </div>
                            <div class="item-details">
                                <div class="item-name">${item.jewel_name}</div>
                                <div class="item-meta">Karat: ${item.karat}K</div>
                                <div class="item-meta">Weight: ${item.net_weight}g × ${item.quantity}</div>
                                ${item.defect_details ? `<div class="item-meta">Defect: ${item.defect_details}</div>` : ''}
                                ${item.stone_details ? `<div class="item-meta">Stone: ${item.stone_details}</div>` : ''}
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
            }
            
            if (payments.length > 0) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Payment History</h4>
                    <div class="timeline">
                `;
                
                payments.forEach(payment => {
                    html += `
                        <div class="timeline-item">
                            <div class="timeline-date">${payment.payment_date}</div>
                            <div class="timeline-content">
                                <strong>${payment.receipt_number}</strong>
                                <div>${payment.remarks || 'EMI Payment'}</div>
                                <div class="payment-details">Method: ${payment.payment_method}</div>
                            </div>
                            <div class="timeline-amount">₹${parseFloat(payment.payment_amount).toFixed(2)}</div>
                        </div>
                    `;
                });
                
                html += '</div>';
            }
            
            if (loan.remarks) {
                html += `
                    <div style="margin-top: 20px;">
                        <h4>Remarks</h4>
                        <p class="payment-details">${loan.remarks}</p>
                    </div>
                `;
            }
            
            document.getElementById('receiptDetails').innerHTML = html;
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>