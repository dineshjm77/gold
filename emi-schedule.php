<?php
session_start();
$currentPage = 'emi-schedule';
$pageTitle = 'EMI Schedule';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

if ($loan_id <= 0) {
    header('Location: personal-loans.php');
    exit();
}

// Get loan details
$loan_query = "SELECT pl.*, c.customer_name, c.mobile_number, c.email,
                      c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode
               FROM personal_loans pl
               JOIN customers c ON pl.customer_id = c.id
               WHERE pl.id = ?";
$loan_stmt = mysqli_prepare($conn, $loan_query);
mysqli_stmt_bind_param($loan_stmt, 'i', $loan_id);
mysqli_stmt_execute($loan_stmt);
$loan_result = mysqli_stmt_get_result($loan_stmt);
$loan = mysqli_fetch_assoc($loan_result);

if (!$loan) {
    header('Location: personal-loans.php');
    exit();
}

// Get EMI schedules grouped by month
$emi_query = "SELECT 
                DATE_FORMAT(due_date, '%Y-%m') as month_key,
                DATE_FORMAT(due_date, '%M %Y') as month_name,
                MIN(due_date) as first_due,
                MAX(due_date) as last_due,
                COUNT(*) as total_emis,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_emis,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_emis,
                SUM(principal_amount) as total_principal,
                SUM(interest_amount) as total_interest,
                SUM(total_amount) as month_total,
                SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status != 'paid' THEN total_amount ELSE 0 END) as pending_amount
              FROM emi_schedules 
              WHERE loan_id = ? AND loan_type = 'personal'
              GROUP BY DATE_FORMAT(due_date, '%Y-%m')
              ORDER BY month_key ASC";

$emi_stmt = mysqli_prepare($conn, $emi_query);
mysqli_stmt_bind_param($emi_stmt, 'i', $loan_id);
mysqli_stmt_execute($emi_stmt);
$emi_result = mysqli_stmt_get_result($emi_stmt);

// Get all EMIs for detailed view
$details_query = "SELECT * FROM emi_schedules 
                  WHERE loan_id = ? AND loan_type = 'personal'
                  ORDER BY due_date ASC";
$details_stmt = mysqli_prepare($conn, $details_query);
mysqli_stmt_bind_param($details_stmt, 'i', $loan_id);
mysqli_stmt_execute($details_stmt);
$details_result = mysqli_stmt_get_result($details_stmt);

// Calculate totals
$total_paid = 0;
$total_pending = 0;
$total_interest = 0;
$total_principal = 0;

mysqli_data_seek($details_result, 0);
while ($row = mysqli_fetch_assoc($details_result)) {
    if ($row['status'] == 'paid') {
        $total_paid += $row['total_amount'];
    } else {
        $total_pending += $row['total_amount'];
    }
    $total_interest += $row['interest_amount'];
    $total_principal += $row['principal_amount'];
}
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .customer-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .customer-details h3 {
            font-size: 20px;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 25px 0;
        }

        .stat-card {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e2e8f0;
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

        .stat-value.success {
            color: #48bb78;
        }

        .stat-value.warning {
            color: #f56565;
        }

        .month-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .month-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .month-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }

        .month-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .month-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-completed {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-partial {
            background: #feebc8;
            color: #744210;
        }

        .badge-pending {
            background: #fed7d7;
            color: #742a2a;
        }

        .month-details {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 15px 0;
        }

        .detail-item {
            text-align: center;
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
        }

        .progress-bar {
            background: #e2e8f0;
            border-radius: 10px;
            height: 8px;
            width: 100%;
            margin: 10px 0;
        }

        .progress-fill {
            background: linear-gradient(90deg, #48bb78, #4299e1);
            height: 8px;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .emi-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .emi-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .emi-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }

        .emi-table tr:hover td {
            background: #f8fafc;
        }

        .emi-table .paid-row {
            background: #f0fff4;
        }

        .emi-table .overdue-row {
            background: #fff5f5;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-paid {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-unpaid {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-overdue {
            background: #feebc8;
            color: #744210;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .month-details {
                grid-template-columns: 1fr;
            }
            
            .customer-info {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
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
                            <i class="bi bi-calendar-check"></i>
                            EMI Schedule - <?php echo $loan['receipt_number']; ?>
                        </h1>
                        <div>
                            <a href="personal-loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <a href="collect-emi.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-success">
                                <i class="bi bi-cash"></i> Collect EMI
                            </a>
                        </div>
                    </div>

                    <!-- Customer Information -->
                    <div class="info-card">
                        <div class="customer-info">
                            <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;">
                                <?php echo strtoupper(substr($loan['customer_name'], 0, 1)); ?>
                            </div>
                            <div class="customer-details">
                                <h3><?php echo htmlspecialchars($loan['customer_name']); ?></h3>
                                <p style="color: #718096;">
                                    <i class="bi bi-phone"></i> <?php echo $loan['mobile_number']; ?> |
                                    <i class="bi bi-envelope"></i> <?php echo $loan['email']; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Principal</div>
                            <div class="stat-value">₹<?php echo number_format($total_principal, 2); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Total Interest</div>
                            <div class="stat-value">₹<?php echo number_format($total_interest, 2); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Paid Amount</div>
                            <div class="stat-value success">₹<?php echo number_format($total_paid, 2); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Pending Amount</div>
                            <div class="stat-value warning">₹<?php echo number_format($total_pending, 2); ?></div>
                        </div>
                    </div>

                    <!-- Monthly Schedule Cards -->
                    <div class="info-card">
                        <h3 style="margin-bottom: 20px;">Monthly Breakdown</h3>
                        
                        <?php if (mysqli_num_rows($emi_result) > 0): ?>
                            <?php while ($month = mysqli_fetch_assoc($emi_result)): 
                                $month_total = $month['month_total'];
                                $paid = $month['paid_amount'];
                                $pending = $month['pending_amount'];
                                $progress = $month_total > 0 ? round(($paid / $month_total) * 100) : 0;
                                
                                $status_class = 'badge-pending';
                                $status_text = 'Pending';
                                if ($paid >= $month_total) {
                                    $status_class = 'badge-completed';
                                    $status_text = 'Completed';
                                } elseif ($paid > 0) {
                                    $status_class = 'badge-partial';
                                    $status_text = 'Partial';
                                }
                            ?>
                            <div class="month-card">
                                <div class="month-header">
                                    <span class="month-title"><?php echo $month['month_name']; ?></span>
                                    <span class="month-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                </div>
                                
                                <div class="month-details">
                                    <div class="detail-item">
                                        <div class="detail-label">EMIs</div>
                                        <div class="detail-value"><?php echo $month['paid_emis']; ?>/<?php echo $month['total_emis']; ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Principal</div>
                                        <div class="detail-value">₹<?php echo number_format($month['total_principal'], 2); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Interest</div>
                                        <div class="detail-value">₹<?php echo number_format($month['total_interest'], 2); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Total</div>
                                        <div class="detail-value">₹<?php echo number_format($month_total, 2); ?></div>
                                    </div>
                                </div>
                                
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                
                                <div style="display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px;">
                                    <span style="color: #48bb78;">Paid: ₹<?php echo number_format($paid, 2); ?></span>
                                    <span style="color: #f56565;">Pending: ₹<?php echo number_format($pending, 2); ?></span>
                                </div>
                                
                                <!-- Detailed EMIs for this month (collapsible) -->
                                <details style="margin-top: 15px;">
                                    <summary style="color: #667eea; cursor: pointer; font-size: 13px;">View Details</summary>
                                    <table class="emi-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Due Date</th>
                                                <th>Principal</th>
                                                <th>Interest</th>
                                                <th>Total</th>
                                                <th>Paid</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $detail_query = "SELECT * FROM emi_schedules 
                                                            WHERE loan_id = ? AND loan_type = 'personal'
                                                            AND DATE_FORMAT(due_date, '%Y-%m') = ?
                                                            ORDER BY due_date ASC";
                                            $detail_stmt = mysqli_prepare($conn, $detail_query);
                                            mysqli_stmt_bind_param($detail_stmt, 'is', $loan_id, $month['month_key']);
                                            mysqli_stmt_execute($detail_stmt);
                                            $detail_result = mysqli_stmt_get_result($detail_stmt);
                                            
                                            while ($emi = mysqli_fetch_assoc($detail_result)):
                                                $row_class = $emi['status'] == 'paid' ? 'paid-row' : ($emi['status'] == 'overdue' ? 'overdue-row' : '');
                                            ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td>#<?php echo $emi['installment_no']; ?></td>
                                                <td><?php echo date('d-m-Y', strtotime($emi['due_date'])); ?></td>
                                                <td>₹<?php echo number_format($emi['principal_amount'], 2); ?></td>
                                                <td>₹<?php echo number_format($emi['interest_amount'], 2); ?></td>
                                                <td>₹<?php echo number_format($emi['total_amount'], 2); ?></td>
                                                <td>₹<?php echo number_format($emi['paid_amount'] ?? 0, 2); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $emi['status']; ?>">
                                                        <?php echo ucfirst($emi['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($emi['status'] != 'paid'): ?>
                                                        <a href="collect-emi.php?id=<?php echo $emi['id']; ?>" class="btn btn-success btn-sm" style="padding: 4px 8px; font-size: 11px;">
                                                            <i class="bi bi-cash"></i> Pay
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </details>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p style="text-align: center; color: #718096; padding: 40px;">No EMI schedules found</p>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="personal-loans.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Loans
                        </a>
                        <a href="collect-emi.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-success">
                            <i class="bi bi-cash"></i> Collect EMI
                        </a>
                        <?php if ($loan['status'] == 'active'): ?>
                        <a href="close-personal-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-warning">
                            <i class="bi bi-check-circle"></i> Close Loan
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</body>
</html>