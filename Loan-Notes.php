<?php
session_start();
$currentPage = 'loan-notes';
$pageTitle = 'Loan Notes';

require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get current user info
$user_id = $_SESSION['user_id'];
$user_query = "SELECT name, role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
if (!$stmt) {
    die("Error preparing user query: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$user_name = explode(' ', $user_data['name'])[0] ?? 'User';
$user_role = $user_data['role'] ?? 'User';
$stmt->close();

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Initialize filter variables
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where_conditions[] = "(l.receipt_number LIKE ? OR c.customer_name LIKE ? OR c.mobile_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

if (!empty($status)) {
    $where_conditions[] = "l.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "l.receipt_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "l.receipt_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total records count
$count_query = "SELECT COUNT(DISTINCT l.id) as total 
                FROM loans l 
                LEFT JOIN customers c ON l.customer_id = c.id
                WHERE $where_clause";

$stmt = $conn->prepare($count_query);
if (!$stmt) {
    die("Error preparing count query: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$total_records = $result->fetch_assoc()['total'];
$stmt->close();

$total_pages = ceil($total_records / $records_per_page);

// Get loans with pagination
$query = "SELECT l.*, 
                 c.customer_name, 
                 c.mobile_number,
                 u.name as employee_name
          FROM loans l 
          LEFT JOIN customers c ON l.customer_id = c.id
          LEFT JOIN users u ON l.employee_id = u.id
          WHERE $where_clause
          ORDER BY l.created_at DESC
          LIMIT ? OFFSET ?";

// Create new params array for this query
$query_params = $params;
$query_types = $types;
$query_params[] = $records_per_page;
$query_params[] = $offset;
$query_types .= "ii";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Error preparing loans query: " . $conn->error);
}

if (!empty($query_params)) {
    $stmt->bind_param($query_types, ...$query_params);
}
$stmt->execute();
$loans = $stmt->get_result();
$stmt->close();

// Get summary statistics - using a separate query without the LIMIT/OFFSET params
$summary_query = "SELECT 
                    COUNT(DISTINCT l.id) as total_loans,
                    SUM(CASE WHEN l.status = 'open' THEN 1 ELSE 0 END) as active_loans,
                    SUM(CASE WHEN l.status = 'closed' THEN 1 ELSE 0 END) as closed_loans,
                    COALESCE(SUM(l.loan_amount), 0) as total_amount,
                    COALESCE(AVG(l.loan_amount), 0) as avg_amount,
                    COALESCE(SUM(l.net_weight), 0) as total_weight
                  FROM loans l
                  LEFT JOIN customers c ON l.customer_id = c.id
                  WHERE $where_clause";

$stmt = $conn->prepare($summary_query);
if (!$stmt) {
    die("Error preparing summary query: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$summary = $result->fetch_assoc();
$stmt->close();

// Set default values if summary is null
if (!$summary) {
    $summary = [
        'total_loans' => 0,
        'active_loans' => 0,
        'closed_loans' => 0,
        'total_amount' => 0,
        'avg_amount' => 0,
        'total_weight' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: #667eea;
            font-size: 28px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            cursor: pointer;
        }

        .filter-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-header h3 i {
            color: #667eea;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: transform 0.3s;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.15);
        }

        .summary-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .summary-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Loans Table */
        .table-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .records-info {
            font-size: 14px;
            color: #718096;
        }

        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .per-page-selector select {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th {
            text-align: left;
            padding: 15px 12px;
            font-size: 12px;
            font-weight: 600;
            color: #718096;
            background: #f7fafc;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        td {
            padding: 15px 12px;
            font-size: 13px;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
        }

        tbody tr {
            transition: all 0.3s;
        }

        tbody tr:hover {
            background: #f8fafc;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .customer-name {
            font-weight: 600;
            color: #2d3748;
        }

        .customer-mobile {
            font-size: 11px;
            color: #a0aec0;
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

        .amount {
            font-weight: 600;
            color: #48bb78;
        }

        .action-btns {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 11px;
            text-decoration: none;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.3s;
        }

        .action-btn.view { background: #4299e1; }
        .action-btn.edit { background: #48bb78; }
        .action-btn.print { background: #ed8936; }
        .action-btn.payment { background: #9f7aea; }

        .action-btn:hover {
            transform: translateY(-2px);
            filter: brightness(110%);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .pagination-item {
            padding: 8px 12px;
            border-radius: 8px;
            background: white;
            color: #4a5568;
            text-decoration: none;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .pagination-item:hover {
            background: #f7fafc;
            border-color: #667eea;
            color: #667eea;
        }

        .pagination-item.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }

        .pagination-item.disabled {
            opacity: 0.5;
            pointer-events: none;
            background: #f7fafc;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* No Records */
        .no-records {
            text-align: center;
            padding: 40px;
            color: #a0aec0;
        }

        .no-records i {
            font-size: 48px;
            margin-bottom: 10px;
            display: block;
        }

        @media (max-width: 768px) {
            .page-content {
                padding: 20px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
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
                    <div class="page-title">
                        <i class="bi bi-journal-text"></i>
                        Loan Notes
                    </div>
                    <div class="action-buttons">
                        <a href="New-Loan.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> New Loan
                        </a>
                        <button class="btn btn-secondary" onclick="toggleFilters()">
                            <i class="bi bi-funnel"></i> Filters
                        </button>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section" id="filterSection" style="display: <?php echo isset($_GET['show_filters']) ? 'block' : 'none'; ?>;">
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-header" onclick="toggleFilters()">
                            <h3>
                                <i class="bi bi-funnel"></i>
                                Filter Loans
                            </h3>
                            <i class="bi bi-chevron-up filter-toggle" id="filterToggle"></i>
                        </div>
                        
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>Search</label>
                                <input type="text" name="search" placeholder="Receipt #, Customer, Mobile" 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>

                            <div class="filter-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">All Status</option>
                                    <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                    <option value="auctioned" <?php echo $status === 'auctioned' ? 'selected' : ''; ?>>Auctioned</option>
                                    <option value="defaulted" <?php echo $status === 'defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <label>Date From</label>
                                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                            </div>

                            <div class="filter-group">
                                <label>Date To</label>
                                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                            </div>
                        </div>

                        <div class="filter-actions">
                            <a href="Loan-Notes.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                        </div>
                        
                        <input type="hidden" name="page" value="1">
                        <input type="hidden" name="per_page" value="<?php echo $records_per_page; ?>">
                        <input type="hidden" name="show_filters" value="1">
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="bi bi-journal-text" style="color: #667eea;"></i>
                            Total Loans
                        </div>
                        <div class="summary-value"><?php echo number_format($summary['total_loans']); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="bi bi-check-circle" style="color: #48bb78;"></i>
                            Active Loans
                        </div>
                        <div class="summary-value"><?php echo number_format($summary['active_loans']); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="bi bi-check-circle-fill" style="color: #4299e1;"></i>
                            Closed Loans
                        </div>
                        <div class="summary-value"><?php echo number_format($summary['closed_loans']); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="bi bi-cash-stack" style="color: #ed8936;"></i>
                            Total Amount
                        </div>
                        <div class="summary-value">₹<?php echo number_format($summary['total_amount'], 2); ?></div>
                        <div class="summary-sub">Avg: ₹<?php echo number_format($summary['avg_amount'], 2); ?></div>
                    </div>

                    <div class="summary-card">
                        <div class="summary-label">
                            <i class="bi bi-gem" style="color: #9f7aea;"></i>
                            Total Weight
                        </div>
                        <div class="summary-value"><?php echo number_format($summary['total_weight'], 3); ?> g</div>
                    </div>
                </div>

                <!-- Loans Table -->
                <div class="table-container">
                    <div class="table-header">
                        <div class="records-info">
                            Showing 
                            <?php 
                            $start = $offset + 1;
                            $end = min($offset + $records_per_page, $total_records);
                            echo $start . ' to ' . $end . ' of ' . $total_records; 
                            ?> 
                            records
                        </div>
                        <div class="per-page-selector">
                            <label for="perPage">Show:</label>
                            <select id="perPage" onchange="changePerPage(this.value)">
                                <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20</option>
                                <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                            <span>entries</span>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Mobile</th>
                                <th>Weight (g)</th>
                                <th>Loan Amount</th>
                                <th>Status</th>
                                <th>Employee</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($loans && $loans->num_rows > 0): ?>
                                <?php while ($loan = $loans->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($loan['receipt_number']); ?></strong>
                                        </td>
                                        <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                        <td>
                                            <div class="customer-info">
                                                <span class="customer-name"><?php echo htmlspecialchars($loan['customer_name'] ?? 'N/A'); ?></span>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['mobile_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo number_format($loan['net_weight'], 3); ?> g</td>
                                        <td class="amount">₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo $loan['status'] === 'open' ? 'success' : 
                                                    ($loan['status'] === 'closed' ? 'info' : 
                                                    ($loan['status'] === 'auctioned' ? 'warning' : 
                                                    ($loan['status'] === 'defaulted' ? 'danger' : 'secondary'))); 
                                            ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['employee_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="view-loan.php?id=<?php echo $loan['id']; ?>" class="action-btn view" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($loan['status'] === 'open'): ?>
                                                    <a href="edit-loan.php?id=<?php echo $loan['id']; ?>" class="action-btn edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="payment.php?loan_id=<?php echo $loan['id']; ?>" class="action-btn payment" title="Add Payment">
                                                        <i class="bi bi-cash"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="print-loan.php?id=<?php echo $loan['id']; ?>" class="action-btn print" title="Print" target="_blank">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="no-records">
                                        <i class="bi bi-inbox"></i>
                                        <p>No loan notes found</p>
                                        <a href="New-Loan.php" class="btn btn-primary" style="margin-top: 10px;">
                                            <i class="bi bi-plus-circle"></i> Create New Loan
                                        </a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php
                            // Build query string without page parameter
                            $query_params = $_GET;
                            unset($query_params['page']);
                            $base_url = '?' . http_build_query($query_params);
                            ?>
                            
                            <a href="<?php echo $base_url; ?>&page=1" 
                               class="pagination-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                            
                            <a href="<?php echo $base_url; ?>&page=<?php echo $page - 1; ?>" 
                               class="pagination-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1) {
                                echo '<span class="pagination-item disabled">...</span>';
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="<?php echo $base_url; ?>&page=<?php echo $i; ?>" 
                                   class="pagination-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <span class="pagination-item disabled">...</span>
                            <?php endif; ?>
                            
                            <a href="<?php echo $base_url; ?>&page=<?php echo $page + 1; ?>" 
                               class="pagination-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                            
                            <a href="<?php echo $base_url; ?>&page=<?php echo $total_pages; ?>" 
                               class="pagination-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script>
    // Show/Hide Filters
    function toggleFilters() {
        const filterSection = document.getElementById('filterSection');
        const filterToggle = document.getElementById('filterToggle');
        
        if (filterSection.style.display === 'none' || filterSection.style.display === '') {
            filterSection.style.display = 'block';
            filterToggle.className = 'bi bi-chevron-up filter-toggle';
        } else {
            filterSection.style.display = 'none';
            filterToggle.className = 'bi bi-chevron-down filter-toggle';
        }
    }

    // Change records per page
    function changePerPage(value) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', value);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
    }

    // Loading overlay
    function showLoading() {
        document.getElementById('loadingOverlay').classList.add('active');
    }

    function hideLoading() {
        document.getElementById('loadingOverlay').classList.remove('active');
    }

    // Handle form submissions
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            showLoading();
        });
    });

    // Handle link clicks
    document.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!this.target && 
                !this.href.includes('javascript:') && 
                !this.classList.contains('pagination-item') &&
                !this.classList.contains('disabled') &&
                this.href &&
                !this.href.startsWith('#')) {
                showLoading();
            }
        });
    });

    // Handle page load
    window.addEventListener('load', function() {
        hideLoading();
    });

    // Handle back/forward buttons
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            hideLoading();
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + F for filters
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            toggleFilters();
        }
    });

    // Auto-submit on filter change (optional)
    let filterTimeout;
    document.querySelectorAll('#filterForm input, #filterForm select').forEach(element => {
        element.addEventListener('change', function() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 1000);
        });
    });

    // Initialize filter section display
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('search') || urlParams.has('status') || urlParams.has('date_from') || urlParams.has('date_to')) {
            document.getElementById('filterSection').style.display = 'block';
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>