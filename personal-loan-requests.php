<?php
session_start();
$currentPage = 'personal-loan-requests';
$pageTitle = 'Personal Loan Requests';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can access this page
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle status filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';
$valid_statuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'pending';
}

// Build query
$query = "SELECT r.*, l.receipt_number, c.customer_name, c.email, c.mobile_number
          FROM personal_loan_requests r
          JOIN loans l ON r.loan_id = l.id
          JOIN customers c ON r.customer_id = c.id";

if ($status_filter !== 'all') {
    $query .= " WHERE r.status = '$status_filter'";
}

$query .= " ORDER BY r.created_at DESC";

$requests_result = mysqli_query($conn, $query);

// Get counts for tabs
$count_pending = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM personal_loan_requests WHERE status = 'pending'"))['cnt'];
$count_approved = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM personal_loan_requests WHERE status = 'approved'"))['cnt'];
$count_rejected = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM personal_loan_requests WHERE status = 'rejected'"))['cnt'];
$count_total = $count_pending + $count_approved + $count_rejected;
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
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            padding: 10px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #718096;
        }

        .tab:hover {
            background: #f7fafc;
        }

        .tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .tab .count {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 5px;
        }

        .tab.active .count {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .requests-card {
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

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-pending {
            background: #feebc8;
            color: #744210;
        }

        .status-approved {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-rejected {
            background: #fed7d7;
            color: #742a2a;
        }

        .amount {
            font-weight: 600;
            color: #48bb78;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
        }

        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }

        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
            }
            
            table {
                font-size: 13px;
            }
            
            td, th {
                padding: 10px;
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
                            <i class="bi bi-send"></i>
                            Personal Loan Requests
                        </h1>
                        <div>
                            <span style="color: #718096;">Total: <?php echo $count_total; ?></span>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <a href="?status=pending" class="tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                            Pending <span class="count"><?php echo $count_pending; ?></span>
                        </a>
                        <a href="?status=approved" class="tab <?php echo $status_filter === 'approved' ? 'active' : ''; ?>">
                            Approved <span class="count"><?php echo $count_approved; ?></span>
                        </a>
                        <a href="?status=rejected" class="tab <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>">
                            Rejected <span class="count"><?php echo $count_rejected; ?></span>
                        </a>
                        <a href="?status=all" class="tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                            All <span class="count"><?php echo $count_total; ?></span>
                        </a>
                    </div>

                    <!-- Requests Table -->
                    <div class="requests-card">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Loan Receipt</th>
                                        <th>Requested Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($requests_result && mysqli_num_rows($requests_result) > 0): ?>
                                        <?php while($row = mysqli_fetch_assoc($requests_result)): ?>
                                            <tr>
                                                <td>#<?php echo $row['id']; ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($row['request_date'])); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($row['customer_name']); ?></strong><br>
                                                    <small style="color: #718096;"><?php echo $row['mobile']; ?></small>
                                                </td>
                                                <td><?php echo $row['receipt_number']; ?></td>
                                                <td class="amount">₹<?php echo number_format($row['request_amount'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $row['status']; ?>">
                                                        <?php echo ucfirst($row['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="approve-personal-loan.php?id=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="bi bi-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 40px; color: #a0aec0;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                                No requests found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
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
</body>
</html>