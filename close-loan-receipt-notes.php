<?php
session_start();
$currentPage = 'close-loan-receipt-notes';
$pageTitle = 'Closed Loan Receipts';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$search_results = [];
$search_performed = false;

// Handle search
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($search_term)) {
    $search_performed = true;
    
    // Build search query based on type
    $search_term_escaped = mysqli_real_escape_string($conn, $search_term);
    $search_param = "%$search_term_escaped%";
    
    $query = "SELECT l.*, 
              c.customer_name, c.mobile_number, c.guardian_name, c.guardian_type,
              u.name as employee_name,
              (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count,
              (SELECT SUM(principal_amount) FROM payments WHERE loan_id = l.id) as total_paid_principal,
              (SELECT SUM(interest_amount) FROM payments WHERE loan_id = l.id) as total_paid_interest,
              DATEDIFF(l.close_date, l.receipt_date) as duration_days
              FROM loans l
              JOIN customers c ON l.customer_id = c.id
              LEFT JOIN users u ON l.employee_id = u.id
              WHERE l.status = 'closed'";
    
    // Add search conditions based on type
    if ($search_type == 'receipt') {
        $query .= " AND l.receipt_number LIKE ?";
    } elseif ($search_type == 'customer') {
        $query .= " AND (c.customer_name LIKE ? OR c.mobile_number LIKE ?)";
    } else {
        $query .= " AND (l.receipt_number LIKE ? OR c.customer_name LIKE ? OR c.mobile_number LIKE ?)";
    }
    
    // Add date filters if provided
    if (!empty($from_date) && !empty($to_date)) {
        $query .= " AND DATE(l.close_date) BETWEEN ? AND ?";
    } elseif (!empty($from_date)) {
        $query .= " AND DATE(l.close_date) >= ?";
    } elseif (!empty($to_date)) {
        $query .= " AND DATE(l.close_date) <= ?";
    }
    
    $query .= " ORDER BY l.close_date DESC, l.id DESC";
    
    // Prepare and execute
    $stmt = mysqli_prepare($conn, $query);
    
    // Bind parameters based on search type and date filters
    if ($search_type == 'receipt') {
        if (!empty($from_date) && !empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'sss', $search_param, $from_date, $to_date);
        } elseif (!empty($from_date)) {
            mysqli_stmt_bind_param($stmt, 'ss', $search_param, $from_date);
        } elseif (!empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'ss', $search_param, $to_date);
        } else {
            mysqli_stmt_bind_param($stmt, 's', $search_param);
        }
    } elseif ($search_type == 'customer') {
        if (!empty($from_date) && !empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'sssss', $search_param, $search_param, $from_date, $to_date);
        } elseif (!empty($from_date)) {
            mysqli_stmt_bind_param($stmt, 'sss', $search_param, $search_param, $from_date);
        } elseif (!empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'sss', $search_param, $search_param, $to_date);
        } else {
            mysqli_stmt_bind_param($stmt, 'ss', $search_param, $search_param);
        }
    } else {
        if (!empty($from_date) && !empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'ssssss', $search_param, $search_param, $search_param, $from_date, $to_date);
        } elseif (!empty($from_date)) {
            mysqli_stmt_bind_param($stmt, 'ssss', $search_param, $search_param, $search_param, $from_date);
        } elseif (!empty($to_date)) {
            mysqli_stmt_bind_param($stmt, 'ssss', $search_param, $search_param, $search_param, $to_date);
        } else {
            mysqli_stmt_bind_param($stmt, 'sss', $search_param, $search_param, $search_param);
        }
    }
    
    mysqli_stmt_execute($stmt);
    $search_results = mysqli_stmt_get_result($stmt);
}

// Get recent closed loans for quick access
$recent_query = "SELECT l.*, 
                c.customer_name, c.mobile_number,
                DATEDIFF(l.close_date, l.receipt_date) as duration_days
                FROM loans l
                JOIN customers c ON l.customer_id = c.id
                WHERE l.status = 'closed'
                ORDER BY l.close_date DESC
                LIMIT 20";
$recent_result = mysqli_query($conn, $recent_query);

// Get summary statistics
$stats_query = "SELECT 
                COUNT(*) as total_closed,
                SUM(loan_amount) as total_principal,
                SUM(payable_interest) as total_interest,
                SUM(loan_amount + payable_interest - COALESCE(discount, 0) + COALESCE(round_off, 0)) as total_received,
                COUNT(DISTINCT customer_id) as unique_customers
                FROM loans
                WHERE status = 'closed'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Format address function
function formatCurrency($amount) {
    return '₹ ' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d-m-Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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

        .container {
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

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .search-grid {
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

        .search-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
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

        .badge-info {
            background: #4299e1;
            color: white;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .loans-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .loans-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .loans-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .loans-table tbody tr:hover {
            background: #f7fafc;
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

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
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

        .action-btn.view {
            background: #4299e1;
            color: white;
        }

        .action-btn.view:hover {
            background: #3182ce;
        }

        .action-btn.print {
            background: #48bb78;
            color: white;
        }

        .action-btn.print:hover {
            background: #38a169;
        }

        .action-btn.pdf {
            background: #f56565;
            color: white;
        }

        .action-btn.pdf:hover {
            background: #c53030;
        }

        /* Mobile Cards */
        .mobile-cards {
            display: none;
        }

        .mobile-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #48bb78;
        }

        .mobile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .mobile-card-title {
            font-weight: 600;
            color: #2d3748;
        }

        .mobile-card-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .mobile-card-label {
            color: #718096;
        }

        .mobile-card-value {
            font-weight: 500;
            color: #2d3748;
        }

        .mobile-card-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 768px) {
            .desktop-table {
                display: none;
            }
            
            .mobile-cards {
                display: block;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .search-actions {
                flex-direction: column;
            }
            
            .search-actions .btn {
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
                <div class="container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-receipt"></i>
                            Closed Loan Receipt Notes
                        </h1>
                        <div>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-house"></i> Dashboard
                            </a>
                        </div>
                    </div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Closed Loans</div>
                                <div class="stat-value"><?php echo number_format($stats['total_closed'] ?? 0); ?></div>
                                <div class="stat-sub">Unique Customers: <?php echo number_format($stats['unique_customers'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Principal</div>
                                <div class="stat-value"><?php echo formatCurrency($stats['total_principal'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Interest</div>
                                <div class="stat-value positive"><?php echo formatCurrency($stats['total_interest'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-piggy-bank"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Received</div>
                                <div class="stat-value"><?php echo formatCurrency($stats['total_received'] ?? 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Search Section -->
                    <div class="search-card">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Search Closed Loans
                        </div>

                        <form method="GET" action="" id="searchForm">
                            <div class="search-grid">
                                <div class="form-group">
                                    <label class="form-label">Search By</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="type">
                                            <option value="all" <?php echo $search_type == 'all' ? 'selected' : ''; ?>>All Fields</option>
                                            <option value="receipt" <?php echo $search_type == 'receipt' ? 'selected' : ''; ?>>Receipt Number</option>
                                            <option value="customer" <?php echo $search_type == 'customer' ? 'selected' : ''; ?>>Customer Name/Mobile</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Search Term</label>
                                    <div class="input-group">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Enter receipt number, customer name or mobile">
                                    </div>
                                </div>

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
                            </div>

                            <div class="search-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetSearch()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Search Results or Recent Loans -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                <?php if ($search_performed): ?>
                                    Search Results for "<?php echo htmlspecialchars($search_term); ?>"
                                <?php else: ?>
                                    Recent Closed Loans
                                <?php endif; ?>
                                <span class="badge badge-info" style="margin-left: 10px;">
                                    <?php 
                                    if ($search_performed) {
                                        echo $search_results ? mysqli_num_rows($search_results) : 0;
                                    } else {
                                        echo $recent_result ? mysqli_num_rows($recent_result) : 0;
                                    }
                                    ?> records
                                </span>
                            </span>
                        </div>

                        <!-- Desktop Table View -->
                        <div class="table-responsive desktop-table">
                            <table class="loans-table" id="loansTable">
                                <thead>
                                    <tr>
                                        <th>Receipt No</th>
                                        <th>Customer</th>
                                        <th>Mobile</th>
                                        <th>Receipt Date</th>
                                        <th>Close Date</th>
                                        <th>Duration</th>
                                        <th class="text-right">Loan Amount</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Total Received</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $result_set = $search_performed ? $search_results : $recent_result;
                                    if ($result_set && mysqli_num_rows($result_set) > 0):
                                        while ($loan = mysqli_fetch_assoc($result_set)):
                                            $total_received = $loan['loan_amount'] + ($loan['payable_interest'] ?? 0) - ($loan['discount'] ?? 0) + ($loan['round_off'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($loan['receipt_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($loan['mobile_number']); ?></td>
                                        <td><?php echo formatDate($loan['receipt_date']); ?></td>
                                        <td><?php echo formatDate($loan['close_date']); ?></td>
                                        <td><?php echo $loan['duration_days'] ?? '-'; ?> days</td>
                                        <td class="text-right amount"><?php echo formatCurrency($loan['loan_amount']); ?></td>
                                        <td class="text-right positive"><?php echo formatCurrency($loan['payable_interest'] ?? 0); ?></td>
                                        <td class="text-right amount"><?php echo formatCurrency($total_received); ?></td>
                                        <td class="text-center">
                                            <div class="action-buttons">
                                                <a href="view-closed-loan.php?id=<?php echo $loan['id']; ?>" class="action-btn view" target="_blank">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <a href="print-close-receipt.php?id=<?php echo $loan['id']; ?>" class="action-btn print" target="_blank">
                                                    <i class="bi bi-printer"></i> Print
                                                </a>
                                                <a href="download-close-receipt.php?id=<?php echo $loan['id']; ?>" class="action-btn pdf" target="_blank">
                                                    <i class="bi bi-file-pdf"></i> PDF
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                    <tr>
                                        <td colspan="10" class="text-center" style="padding: 40px;">
                                            <i class="bi bi-inbox" style="font-size: 48px; color: #cbd5e0; display: block; margin-bottom: 10px;"></i>
                                            No closed loans found
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <div class="mobile-cards">
                            <?php 
                            // Reset pointer for mobile view
                            if ($search_performed) {
                                mysqli_data_seek($search_results, 0);
                                $mobile_set = $search_results;
                            } else {
                                mysqli_data_seek($recent_result, 0);
                                $mobile_set = $recent_result;
                            }
                            
                            if ($mobile_set && mysqli_num_rows($mobile_set) > 0):
                                while ($loan = mysqli_fetch_assoc($mobile_set)):
                                    $total_received = $loan['loan_amount'] + ($loan['payable_interest'] ?? 0) - ($loan['discount'] ?? 0) + ($loan['round_off'] ?? 0);
                            ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-card-title"><?php echo htmlspecialchars($loan['receipt_number']); ?></span>
                                    <span class="badge badge-closed">Closed</span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Customer:</span>
                                    <span class="mobile-card-value"><?php echo htmlspecialchars($loan['customer_name']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Mobile:</span>
                                    <span class="mobile-card-value"><?php echo htmlspecialchars($loan['mobile_number']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Close Date:</span>
                                    <span class="mobile-card-value"><?php echo formatDate($loan['close_date']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Loan Amount:</span>
                                    <span class="mobile-card-value amount"><?php echo formatCurrency($loan['loan_amount']); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Interest:</span>
                                    <span class="mobile-card-value positive"><?php echo formatCurrency($loan['payable_interest'] ?? 0); ?></span>
                                </div>
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Total Received:</span>
                                    <span class="mobile-card-value amount"><?php echo formatCurrency($total_received); ?></span>
                                </div>
                                <div class="mobile-card-actions">
                                    <a href="view-closed-loan.php?id=<?php echo $loan['id']; ?>" class="action-btn view" style="flex: 1;" target="_blank">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <a href="print-close-receipt.php?id=<?php echo $loan['id']; ?>" class="action-btn print" style="flex: 1;" target="_blank">
                                        <i class="bi bi-printer"></i> Print
                                    </a>
                                    <a href="download-close-receipt.php?id=<?php echo $loan['id']; ?>" class="action-btn pdf" style="flex: 1;" target="_blank">
                                        <i class="bi bi-file-pdf"></i> PDF
                                    </a>
                                </div>
                            </div>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <div style="text-align: center; padding: 40px; color: #718096;">
                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                No closed loans found
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Initialize DataTable
        $(document).ready(function() {
            $('#loansTable').DataTable({
                pageLength: 25,
                order: [],
                language: {
                    search: "Filter:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    emptyTable: "No closed loans found"
                },
                columnDefs: [
                    { orderable: false, targets: -1 }
                ]
            });
        });

        // Reset search form
        function resetSearch() {
            window.location.href = 'close-loan-receipt-notes.php';
        }

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