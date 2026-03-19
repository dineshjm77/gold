<?php
session_start();
$currentPage = 'view-personal-loan';
$pageTitle = 'View Personal Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id <= 0) {
    header('Location: personal-loans.php');
    exit();
}

// Get loan details with proper error handling - FIXED COLUMN NAMES
$query = "SELECT pl.*, 
                 c.customer_name, c.mobile_number, c.email, c.customer_photo,
                 c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                 u.name as employee_name, 
                 l.receipt_number as original_receipt,
                 pr.requested_amount as approved_amount
          FROM personal_loans pl
          LEFT JOIN customers c ON pl.customer_id = c.id
          LEFT JOIN users u ON pl.employee_id = u.id
          LEFT JOIN personal_loan_requests pr ON pl.personal_loan_request_id = pr.id
          LEFT JOIN loans l ON pr.loan_id = l.id
          WHERE pl.id = ?";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    error_log("Prepare failed: " . mysqli_error($conn));
    die("Database error. Please try again later.");
}

mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$loan = mysqli_fetch_assoc($result);

if (!$loan) {
    header('Location: personal-loans.php?error=not_found');
    exit();
}

// Format address with null checks
$address_parts = array_filter([
    $loan['door_no'] ?? '',
    $loan['house_name'] ?? '',
    $loan['street_name'] ?? '',
    $loan['location'] ?? '',
    $loan['district'] ?? ''
]);
$customer_address = !empty($address_parts) ? implode(', ', $address_parts) : 'Address not available';
if (!empty($loan['pincode'])) {
    $customer_address .= ' - ' . $loan['pincode'];
}

// Calculate values with defaults
$loan_amount = floatval($loan['loan_amount'] ?? 0);
$interest_rate = floatval($loan['interest_rate'] ?? 0);
$tenure_months = intval($loan['tenure_months'] ?? 12);
$process_charge = floatval($loan['process_charge'] ?? 0);
$appraisal_charge = floatval($loan['appraisal_charge'] ?? 0);
$total_payable = floatval($loan['total_payable'] ?? ($loan_amount + $process_charge + $appraisal_charge));

// Calculate monthly interest and EMI
$monthly_interest = ($loan_amount * $interest_rate) / 100 / 12;
$total_interest = $monthly_interest * $tenure_months;

// Get success message from URL
$success_message = '';
if (isset($_GET['success']) && $_GET['success'] == 'created') {
    $success_message = "Personal loan created successfully!";
} elseif (isset($_GET['success']) && $_GET['success'] == 'closed') {
    $success_message = "Personal loan closed successfully!";
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

        .loan-container {
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
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
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

        .status-defaulted {
            background: #fed7d7;
            color: #742a2a;
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
            text-decoration: none;
            transition: all 0.3s;
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
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72,187,120,0.4);
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid;
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left-color: #48bb78;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
        }

        .customer-info {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .customer-photo {
            width: 100px;
            height: 100px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid #667eea;
            box-shadow: 0 4px 15px rgba(102,126,234,0.3);
        }

        .customer-photo-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 40px;
            font-weight: 600;
            border: 3px solid white;
        }

        .customer-details {
            flex: 1;
        }

        .customer-name {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .customer-contact {
            display: flex;
            gap: 20px;
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .customer-contact i {
            color: #667eea;
            width: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .info-item {
            margin-bottom: 15px;
        }

        .info-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label i {
            color: #667eea;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value-large {
            font-size: 28px;
            font-weight: 700;
            color: #48bb78;
        }

        .summary-box {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-row:last-child {
            border-bottom: none;
        }

        .summary-label {
            font-weight: 500;
            color: #4a5568;
        }

        .summary-value {
            font-weight: 600;
            color: #2d3748;
        }

        .summary-total {
            font-size: 20px;
            font-weight: 700;
            color: #48bb78;
            padding-top: 15px;
            margin-top: 10px;
            border-top: 2px solid #48bb78;
            display: flex;
            justify-content: space-between;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-info {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-contact {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .main-content {
                background: white;
            }
            .info-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
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
                <div class="loan-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-cash-stack"></i>
                            Personal Loan: <?php echo htmlspecialchars($loan['receipt_number'] ?? 'N/A'); ?>
                            <span class="status-badge status-<?php echo $loan['status'] ?? 'active'; ?>">
                                <?php echo strtoupper($loan['status'] ?? 'ACTIVE'); ?>
                            </span>
                        </h1>
                        <div class="no-print">
                            <a href="personal-loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <button onclick="window.print()" class="btn btn-primary">
                                <i class="bi bi-printer"></i> Print
                            </button>
                        </div>
                        <?php if ($loan['status'] == 'closed'): ?>
    <a href="print-close-loan-receipt.php?loan_id=<?php echo $loan_id; ?>" class="btn btn-primary" target="_blank">
        <i class="bi bi-printer"></i> Print Closure Receipt
    </a>
<?php endif; ?>
                    </div>

                    <!-- Success Message -->
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Customer Information -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-person"></i>
                            Customer Information
                        </div>

                        <div class="customer-info">
                            <?php if (!empty($loan['customer_photo']) && file_exists($loan['customer_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($loan['customer_photo']); ?>" class="customer-photo" alt="Customer Photo">
                            <?php else: ?>
                                <div class="customer-photo-placeholder">
                                    <?php echo strtoupper(substr($loan['customer_name'] ?? 'C', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="customer-details">
                                <div class="customer-name"><?php echo htmlspecialchars($loan['customer_name'] ?? 'N/A'); ?></div>
                                <div class="customer-contact">
                                    <span><i class="bi bi-phone"></i> <?php echo htmlspecialchars($loan['mobile_number'] ?? 'N/A'); ?></span>
                                    <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($loan['email'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="customer-contact">
                                    <span><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($customer_address); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loan Information -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Loan Information
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-receipt"></i> Receipt Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['receipt_number'] ?? 'N/A'); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar"></i> Receipt Date</div>
                                <div class="info-value"><?php echo !empty($loan['receipt_date']) ? date('d-m-Y', strtotime($loan['receipt_date'])) : 'N/A'; ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-journal"></i> Original Loan</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['original_receipt'] ?? 'N/A'); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-cash-coin"></i> Loan Amount</div>
                                <div class="info-value-large">₹<?php echo number_format($loan_amount, 2); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-percent"></i> Interest Rate</div>
                                <div class="info-value"><?php echo $interest_rate; ?>% (<?php echo htmlspecialchars($loan['interest_type'] ?? 'monthly'); ?>)</div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-clock"></i> Tenure</div>
                                <div class="info-value"><?php echo $tenure_months; ?> months</div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calculator"></i> EMI Amount</div>
                                <div class="info-value">₹<?php echo number_format($loan['emi_amount'] ?? 0, 2); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-person-badge"></i> Processed By</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['employee_name'] ?? 'N/A'); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar-plus"></i> Created Date</div>
                                <div class="info-value"><?php echo !empty($loan['created_at']) ? date('d-m-Y', strtotime($loan['created_at'])) : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Charges Summary -->
                    <div class="info-card">
                        <div class="section-title">
                            <i class="bi bi-cash-stack"></i>
                            Charges & Summary
                        </div>

                        <div class="summary-box">
                            <div class="summary-row">
                                <span class="summary-label">Principal Amount:</span>
                                <span class="summary-value">₹<?php echo number_format($loan_amount, 2); ?></span>
                            </div>
                            
                            <div class="summary-row">
                                <span class="summary-label">Process Charge:</span>
                                <span class="summary-value">₹<?php echo number_format($process_charge, 2); ?></span>
                            </div>
                            
                            <div class="summary-row">
                                <span class="summary-label">Appraisal Charge:</span>
                                <span class="summary-value">₹<?php echo number_format($appraisal_charge, 2); ?></span>
                            </div>
                            
                            <?php if ($tenure_months > 0): ?>
                            <div class="summary-row">
                                <span class="summary-label">Monthly Interest:</span>
                                <span class="summary-value">₹<?php echo number_format($monthly_interest, 2); ?></span>
                            </div>
                            
                            <div class="summary-row">
                                <span class="summary-label">Total Interest (<?php echo $tenure_months; ?> months):</span>
                                <span class="summary-value">₹<?php echo number_format($total_interest, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="summary-total">
                                <span>Total Payable:</span>
                                <span>₹<?php echo number_format($total_payable, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons no-print">
                        <?php if (($loan['status'] ?? 'active') == 'active'): ?>
                            <a href="edit-personal-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-primary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                            <a href="close-personal-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-danger">
    <i class="bi bi-check-circle"></i> Close Loan
</a>
                        <?php endif; ?>
                        
                        <a href="personal-loans.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to List
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