<?php
session_start();
$currentPage = 'personal-loans';
$pageTitle = 'Personal Loans';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

// Get all personal loans
$query = "SELECT pl.*, c.customer_name, c.mobile_number,
                 (SELECT COUNT(*) FROM emi_schedules WHERE loan_id = pl.id AND status = 'paid') as paid_emis,
                 (SELECT COUNT(*) FROM emi_schedules WHERE loan_id = pl.id AND status IN ('unpaid', 'overdue')) as pending_emis
          FROM personal_loans pl
          JOIN customers c ON pl.customer_id = c.id
          ORDER BY pl.created_at DESC";
$result = mysqli_query($conn, $query);
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
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
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

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-closed {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn {
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .action-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .progress-badge {
            font-size: 11px;
            color: #718096;
            margin-top: 3px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-group {
                flex-direction: column;
            }
            
            .btn {
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
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-cash-stack"></i>
                            Personal Loans
                        </h1>
                        <a href="create-personal-loan.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> New Personal Loan
                        </a>
                    </div>

                    <div class="loans-card">
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Receipt No.</th>
                                        <th>Date</th>
                                        <th>Customer</th>
                                        <th>Loan Amount</th>
                                        <th>Interest</th>
                                        <th>Tenure</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                        <?php while($loan = mysqli_fetch_assoc($result)): 
                                            $total_emis = $loan['paid_emis'] + $loan['pending_emis'];
                                            $progress_percent = $total_emis > 0 ? round(($loan['paid_emis'] / $total_emis) * 100) : 0;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($loan['customer_name']); ?><br>
                                                    <small style="color: #718096;"><?php echo $loan['mobile_number']; ?></small>
                                                </td>
                                                <td><strong>₹<?php echo number_format($loan['loan_amount'], 2); ?></strong></td>
                                                <td><?php echo $loan['interest_rate']; ?>%</td>
                                                <td><?php echo $loan['tenure_months']; ?> months</td>
                                                <td>
                                                    <div style="background: #e2e8f0; border-radius: 10px; height: 6px; width: 100px;">
                                                        <div style="background: #48bb78; width: <?php echo $progress_percent; ?>%; height: 6px; border-radius: 10px;"></div>
                                                    </div>
                                                    <span class="progress-badge"><?php echo $loan['paid_emis']; ?>/<?php echo $total_emis; ?> paid</span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $loan['status']; ?>">
                                                        <?php echo ucfirst($loan['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-group">
                                                        <a href="view-personal-loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-primary btn-sm" title="View Details">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="emi-schedule.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-info btn-sm" title="EMI Schedule">
                                                            <i class="bi bi-calendar-check"></i>
                                                        </a>
                                                        <?php if ($loan['status'] == 'active'): ?>
                                                            <a href="collect-emi.php?loan_id=<?php echo $loan['id']; ?>" class="btn btn-success btn-sm" title="Collect EMI">
                                                                <i class="bi bi-cash"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" style="text-align: center; padding: 40px; color: #a0aec0;">
                                                <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                                No personal loans found
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
</body>
</html>