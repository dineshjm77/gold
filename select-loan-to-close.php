<?php
session_start();
$currentPage = 'select-loan-to-close';
$pageTitle = 'Select Loan to Close';
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

// Get all active personal loans
$query = "SELECT pl.*, c.customer_name, c.mobile_number, c.email,
                 (SELECT COUNT(*) FROM emi_schedules WHERE loan_id = pl.id AND status IN ('unpaid', 'overdue')) as pending_emis
          FROM personal_loans pl
          JOIN customers c ON pl.customer_id = c.id
          WHERE pl.status = 'active'
          ORDER BY pl.created_at DESC";

$result = mysqli_query($conn, $query);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_active,
                SUM(loan_amount) as total_amount,
                SUM(total_payable) as total_payable
                FROM personal_loans 
                WHERE status = 'active'";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);
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
            max-width: 1200px;
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
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
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

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
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

        .loans-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 13px;
            font-weight: 600;
            color: #718096;
            background: #f7fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 15px;
            font-size: 14px;
            color: #2d3748;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover td {
            background: #f8fafc;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-warning {
            background: #feebc8;
            color: #744210;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .search-box {
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-sm {
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
                            <i class="bi bi-check-circle"></i>
                            Select Loan to Close
                        </h1>
                        <a href="personal-loans.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Loans
                        </a>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Active Loans</div>
                            <div class="stat-value"><?php echo $stats['total_active'] ?? 0; ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Outstanding</div>
                            <div class="stat-value">₹<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Payable</div>
                            <div class="stat-value">₹<?php echo number_format($stats['total_payable'] ?? 0, 2); ?></div>
                        </div>
                    </div>

                    <!-- Search Box -->
                    <div class="search-box">
                        <input type="text" id="searchInput" placeholder="Search by receipt number, customer name or mobile..." onkeyup="searchTable()">
                    </div>

                    <!-- Loans Table -->
                    <div class="loans-card">
                        <h3 style="margin-bottom: 20px;">Active Loans</h3>
                        
                        <?php if ($result && mysqli_num_rows($result) > 0): ?>
                            <div class="table-responsive">
                                <table id="loansTable">
                                    <thead>
                                        <tr>
                                            <th>Receipt #</th>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Mobile</th>
                                            <th>Loan Amount</th>
                                            <th>Total Payable</th>
                                            <th>Pending EMIs</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($loan = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                                            <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($loan['customer_name']); ?></td>
                                            <td><?php echo $loan['mobile_number']; ?></td>
                                            <td>₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                            <td><strong>₹<?php echo number_format($loan['total_payable'], 2); ?></strong></td>
                                            <td>
                                                <?php if ($loan['pending_emis'] > 0): ?>
                                                    <span class="badge badge-warning"><?php echo $loan['pending_emis']; ?> pending</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info">No EMIs</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="view-personal-loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                    <a href="close-personal-loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-danger btn-sm">
                                                        <i class="bi bi-check-circle"></i> Close
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 50px; color: #a0aec0;">
                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                <h4>No Active Loans Found</h4>
                                <p>There are no active loans available to close at the moment.</p>
                                <a href="create-personal-loan.php" class="btn btn-primary" style="margin-top: 20px;">
                                    <i class="bi bi-plus-circle"></i> Create New Loan
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
        function searchTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('loansTable');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let display = false;
                const td = tr[i].getElementsByTagName('td');
                
                for (let j = 0; j < td.length - 1; j++) { // Exclude last column (actions)
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            display = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = display ? '' : 'none';
            }
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>