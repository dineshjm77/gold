<?php
session_start();
$currentPage = 'view-receipt';
$pageTitle = 'View Receipt';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$receipt_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($receipt_id <= 0) {
    header('Location: print-receipt-copy.php?error=Invalid receipt ID');
    exit();
}

// Fetch receipt details with all related information
$query = "SELECT 
            p.id as payment_id,
            p.receipt_number,
            p.payment_date,
            p.principal_amount,
            p.interest_amount,
            p.total_amount,
            p.payment_mode,
            p.remarks,
            p.created_at as payment_created_at,
            l.id as loan_id,
            l.receipt_number as loan_receipt,
            l.receipt_date as loan_date,
            l.gross_weight,
            l.net_weight,
            l.product_value,
            l.loan_amount,
            l.interest_amount as loan_interest_rate,
            l.interest_type,
            l.receipt_charge,
            l.status as loan_status,
            c.id as customer_id,
            c.customer_name,
            c.mobile_number,
            c.whatsapp_number,
            c.guardian_name,
            c.guardian_type,
            c.email,
            c.aadhaar_number,
            c.door_no,
            c.house_name,
            c.street_name,
            c.location,
            c.district,
            c.pincode,
            u.id as collected_by_id,
            u.name as collected_by,
            u.username as collected_by_username
          FROM payments p
          JOIN loans l ON p.loan_id = l.id
          JOIN customers c ON l.customer_id = c.id
          JOIN users u ON p.employee_id = u.id
          WHERE p.id = ?";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $receipt_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $receipt = mysqli_fetch_assoc($result);
    
    if (!$receipt) {
        header('Location: print-receipt-copy.php?error=Receipt not found');
        exit();
    }
} else {
    header('Location: print-receipt-copy.php?error=Database error');
    exit();
}

// Fetch loan items
$items_query = "SELECT 
                    li.id,
                    li.jewel_name,
                    li.karat,
                    li.defect_details,
                    li.stone_details,
                    li.net_weight,
                    li.quantity,
                    li.photo_path,
                    pn.jewel_name as product_name
                FROM loan_items li
                LEFT JOIN product_names pn ON li.jewel_name = pn.jewel_name
                WHERE li.loan_id = ?";

$items_stmt = mysqli_prepare($conn, $items_query);
$loan_items = [];
if ($items_stmt) {
    mysqli_stmt_bind_param($items_stmt, "i", $receipt['loan_id']);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    while ($row = mysqli_fetch_assoc($items_result)) {
        $loan_items[] = $row;
    }
}

// Fetch previous payments for this loan
$prev_payments_query = "SELECT 
                            receipt_number,
                            payment_date,
                            principal_amount,
                            interest_amount,
                            total_amount,
                            payment_mode
                        FROM payments 
                        WHERE loan_id = ? AND id != ?
                        ORDER BY payment_date DESC, id DESC
                        LIMIT 5";

$prev_payments_stmt = mysqli_prepare($conn, $prev_payments_query);
$previous_payments = [];
if ($prev_payments_stmt) {
    mysqli_stmt_bind_param($prev_payments_stmt, "ii", $receipt['loan_id'], $receipt_id);
    mysqli_stmt_execute($prev_payments_stmt);
    $prev_result = mysqli_stmt_get_result($prev_payments_stmt);
    while ($row = mysqli_fetch_assoc($prev_result)) {
        $previous_payments[] = $row;
    }
}

// Determine receipt type
$receipt_type = 'Payment';
$type_class = 'primary';
if ($receipt['principal_amount'] > 0 && $receipt['interest_amount'] > 0) {
    $receipt_type = 'Principal + Interest';
    $type_class = 'info';
} elseif ($receipt['interest_amount'] > 0) {
    $receipt_type = 'Interest Only';
    $type_class = 'warning';
} elseif ($receipt['principal_amount'] > 0) {
    $receipt_type = 'Principal Only';
    $type_class = 'success';
}

if (strpos($receipt['receipt_number'], 'ADV') === 0) {
    $receipt_type = 'Advance Payment';
    $type_class = 'purple';
} elseif (strpos($receipt['receipt_number'], 'CLS') === 0) {
    $receipt_type = 'Loan Closure';
    $type_class = 'danger';
} elseif (strpos($receipt['receipt_number'], 'BLK') === 0) {
    $receipt_type = 'Bulk Closure';
    $type_class = 'dark';
} elseif (strpos($receipt['receipt_number'], 'INT') === 0) {
    $receipt_type = 'Interest Collection';
    $type_class = 'orange';
} elseif (strpos($receipt['receipt_number'], 'PRN') === 0) {
    $receipt_type = 'Principal Payment';
    $type_class = 'blue';
}

// Payment mode badge class
$mode_class = 'success';
if ($receipt['payment_mode'] == 'bank') {
    $mode_class = 'primary';
} elseif ($receipt['payment_mode'] == 'upi') {
    $mode_class = 'purple';
} elseif ($receipt['payment_mode'] == 'other') {
    $mode_class = 'secondary';
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-primary {
            background: #667eea;
            color: white;
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

        .badge-dark {
            background: #2d3748;
            color: white;
        }

        .badge-orange {
            background: #ed8936;
            color: white;
        }

        .badge-blue {
            background: #3182ce;
            color: white;
        }

        .badge-secondary {
            background: #a0aec0;
            color: white;
        }

        /* Receipt Card */
        .receipt-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .receipt-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .receipt-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }

        .receipt-number {
            text-align: right;
        }

        .receipt-number h2 {
            font-size: 36px;
            font-weight: 800;
            margin: 0;
            line-height: 1.2;
        }

        .receipt-number p {
            font-size: 14px;
            opacity: 0.9;
            margin: 5px 0 0;
        }

        .receipt-body {
            padding: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }

        .info-section {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #718096;
            font-weight: 500;
        }

        .info-value {
            color: #2d3748;
            font-weight: 600;
            text-align: right;
        }

        .amount-highlight {
            font-size: 20px;
            color: #667eea;
        }

        /* Amount Box */
        .amount-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 30px;
        }

        .amount-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 42px;
            font-weight: 800;
            color: #667eea;
            line-height: 1.2;
        }

        .amount-breakdown {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .breakdown-item {
            text-align: center;
        }

        .breakdown-label {
            font-size: 12px;
            color: #718096;
        }

        .breakdown-value {
            font-size: 18px;
            font-weight: 700;
        }

        .breakdown-value.principal {
            color: #4299e1;
        }

        .breakdown-value.interest {
            color: #ecc94b;
        }

        /* Loan Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .items-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            font-size: 14px;
        }

        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
            font-size: 14px;
        }

        .items-table tbody tr:hover {
            background: #f7fafc;
        }

        .item-photo {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .item-photo:hover {
            transform: scale(1.1);
        }

        .item-detail-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background: #e2e8f0;
            color: #4a5568;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        /* Previous Payments */
        .prev-payments {
            margin-top: 30px;
        }

        .prev-payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .prev-payments-table th {
            background: #f7fafc;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .prev-payments-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Print Styles */
        @media print {
            .app-wrapper {
                display: block;
                background: white;
            }
            
            .main-content {
                background: white;
            }
            
            .page-content {
                padding: 20px;
            }
            
            .btn,
            .action-buttons,
            .sidebar,
            .topbar,
            footer {
                display: none !important;
            }
            
            .receipt-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
            
            .receipt-header {
                background: #f4f4f4 !important;
                color: black !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .badge {
                border: 1px solid #333;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .receipt-header {
                flex-direction: column;
                text-align: center;
            }
            
            .receipt-number {
                text-align: center;
            }
            
            .amount-breakdown {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                            View Receipt Details
                        </h1>
                        <div>
                            <a href="print-receipt-copy.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="bill-receipt.php?receipt=<?php echo $receipt['receipt_number']; ?>&print=1" class="btn btn-primary" target="_blank">
                            <i class="bi bi-printer"></i> Print Receipt
                        </a>
                        <a href="bill-receipt.php?receipt=<?php echo $receipt['receipt_number']; ?>" class="btn btn-info" target="_blank">
                            <i class="bi bi-eye"></i> View Receipt
                        </a>
                        <?php if ($_SESSION['user_role'] == 'admin'): ?>
                        <a href="edit-receipt.php?id=<?php echo $receipt['payment_id']; ?>" class="btn btn-success">
                            <i class="bi bi-pencil"></i> Edit Receipt
                        </a>
                        <?php endif; ?>
                        <a href="loan-details.php?id=<?php echo $receipt['loan_id']; ?>" class="btn btn-secondary">
                            <i class="bi bi-file-text"></i> View Loan
                        </a>
                    </div>

                    <!-- Main Receipt Card -->
                    <div class="receipt-card">
                        <!-- Header -->
                        <div class="receipt-header">
                            <div>
                                <h1 class="receipt-title">Payment Receipt</h1>
                                <p class="receipt-subtitle">
                                    <i class="bi bi-calendar"></i> 
                                    Generated on: <?php echo date('d-m-Y h:i A', strtotime($receipt['payment_created_at'])); ?>
                                </p>
                            </div>
                            <div class="receipt-number">
                                <h2><?php echo $receipt['receipt_number']; ?></h2>
                                <p>
                                    <span class="badge badge-<?php echo $type_class; ?>">
                                        <?php echo $receipt_type; ?>
                                    </span>
                                    <span class="badge badge-<?php echo $mode_class; ?>" style="margin-left: 5px;">
                                        <i class="bi bi-<?php echo $receipt['payment_mode'] == 'cash' ? 'cash' : ($receipt['payment_mode'] == 'bank' ? 'bank' : 'phone'); ?>"></i>
                                        <?php echo strtoupper($receipt['payment_mode']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>

                        <!-- Body -->
                        <div class="receipt-body">
                            <!-- Amount Box -->
                            <div class="amount-box">
                                <div class="amount-label">Total Amount</div>
                                <div class="amount-value">₹ <?php echo number_format($receipt['total_amount'], 2); ?></div>
                                <div class="amount-breakdown">
                                    <div class="breakdown-item">
                                        <div class="breakdown-label">Principal</div>
                                        <div class="breakdown-value principal">
                                            ₹ <?php echo number_format($receipt['principal_amount'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="breakdown-item">
                                        <div class="breakdown-label">Interest</div>
                                        <div class="breakdown-value interest">
                                            ₹ <?php echo number_format($receipt['interest_amount'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($receipt['remarks'])): ?>
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; color: #718096;">
                                    <i class="bi bi-chat"></i> <?php echo htmlspecialchars($receipt['remarks']); ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Information Grid -->
                            <div class="info-grid">
                                <!-- Payment Information -->
                                <div class="info-section">
                                    <div class="section-title">
                                        <i class="bi bi-credit-card"></i>
                                        Payment Information
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Payment Date:</span>
                                        <span class="info-value"><?php echo date('d-m-Y', strtotime($receipt['payment_date'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Payment Mode:</span>
                                        <span class="info-value">
                                            <span class="badge badge-<?php echo $mode_class; ?>">
                                                <?php echo strtoupper($receipt['payment_mode']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Collected By:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($receipt['collected_by']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Username:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($receipt['collected_by_username']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Payment Type:</span>
                                        <span class="info-value">
                                            <span class="badge badge-<?php echo $type_class; ?>">
                                                <?php echo $receipt_type; ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Customer Information -->
                                <div class="info-section">
                                    <div class="section-title">
                                        <i class="bi bi-person"></i>
                                        Customer Information
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Name:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($receipt['customer_name']); ?></span>
                                    </div>
                                    <?php if ($receipt['guardian_name']): ?>
                                    <div class="info-row">
                                        <span class="info-label"><?php echo $receipt['guardian_type'] ?: 'Guardian'; ?>:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($receipt['guardian_name']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="info-row">
                                        <span class="info-label">Mobile:</span>
                                        <span class="info-value">
                                            <?php echo $receipt['mobile_number']; ?>
                                            <?php if ($receipt['whatsapp_number']): ?>
                                            <br><small>(WhatsApp: <?php echo $receipt['whatsapp_number']; ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php if ($receipt['email']): ?>
                                    <div class="info-row">
                                        <span class="info-label">Email:</span>
                                        <span class="info-value"><?php echo $receipt['email']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($receipt['aadhaar_number']): ?>
                                    <div class="info-row">
                                        <span class="info-label">Aadhaar:</span>
                                        <span class="info-value"><?php echo $receipt['aadhaar_number']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="info-row">
                                        <span class="info-label">Address:</span>
                                        <span class="info-value">
                                            <?php 
                                            $address = [];
                                            if ($receipt['door_no']) $address[] = $receipt['door_no'];
                                            if ($receipt['house_name']) $address[] = $receipt['house_name'];
                                            if ($receipt['street_name']) $address[] = $receipt['street_name'];
                                            if ($receipt['location']) $address[] = $receipt['location'];
                                            if ($receipt['district']) $address[] = $receipt['district'];
                                            if ($receipt['pincode']) $address[] = $receipt['pincode'];
                                            echo htmlspecialchars(implode(', ', $address));
                                            ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Loan Information -->
                                <div class="info-section">
                                    <div class="section-title">
                                        <i class="bi bi-file-text"></i>
                                        Loan Information
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Loan Receipt:</span>
                                        <span class="info-value">
                                            <a href="loan-details.php?id=<?php echo $receipt['loan_id']; ?>" style="color: #667eea; text-decoration: none;">
                                                <?php echo $receipt['loan_receipt']; ?>
                                            </a>
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Loan Date:</span>
                                        <span class="info-value"><?php echo date('d-m-Y', strtotime($receipt['loan_date'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Loan Amount:</span>
                                        <span class="info-value">₹ <?php echo number_format($receipt['loan_amount'], 2); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Interest Rate:</span>
                                        <span class="info-value">
                                            <?php echo $receipt['loan_interest_rate']; ?>% 
                                            (<?php echo ucfirst($receipt['interest_type']); ?>)
                                        </span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Receipt Charge:</span>
                                        <span class="info-value">₹ <?php echo number_format($receipt['receipt_charge'] ?? 0, 2); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Loan Status:</span>
                                        <span class="info-value">
                                            <span class="badge badge-<?php echo $receipt['loan_status'] == 'open' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($receipt['loan_status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Weight Information -->
                                <div class="info-section">
                                    <div class="section-title">
                                        <i class="bi bi-gem"></i>
                                        Item Information
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Gross Weight:</span>
                                        <span class="info-value"><?php echo $receipt['gross_weight']; ?> g</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Net Weight:</span>
                                        <span class="info-value"><?php echo $receipt['net_weight']; ?> g</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Product Value:</span>
                                        <span class="info-value">₹ <?php echo number_format($receipt['product_value'], 2); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Total Items:</span>
                                        <span class="info-value"><?php echo count($loan_items); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Loan Items -->
                            <?php if (!empty($loan_items)): ?>
                            <div class="section-title" style="margin-top: 20px;">
                                <i class="bi bi-box"></i>
                                Loan Items
                            </div>
                            <div class="table-responsive">
                                <table class="items-table">
                                    <thead>
                                        <tr>
                                            <th>Photo</th>
                                            <th>Item Name</th>
                                            <th>Karat</th>
                                            <th>Net Weight</th>
                                            <th>Qty</th>
                                            <th>Details</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($loan_items as $item): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($item['photo_path']) && file_exists($item['photo_path'])): ?>
                                                <img src="<?php echo $item['photo_path']; ?>" alt="Item" class="item-photo" onclick="window.open(this.src, '_blank')">
                                                <?php else: ?>
                                                <div style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #999;">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['jewel_name']); ?></td>
                                            <td><?php echo $item['karat']; ?>K</td>
                                            <td><?php echo $item['net_weight']; ?> g</td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>
                                                <?php if (!empty($item['defect_details'])): ?>
                                                <span class="item-detail-badge" title="Defect">
                                                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($item['defect_details']); ?>
                                                </span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['stone_details'])): ?>
                                                <span class="item-detail-badge" title="Stone">
                                                    <i class="bi bi-gem"></i> <?php echo htmlspecialchars($item['stone_details']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <!-- Previous Payments -->
                            <?php if (!empty($previous_payments)): ?>
                            <div class="prev-payments">
                                <div class="section-title">
                                    <i class="bi bi-clock-history"></i>
                                    Recent Payment History for this Loan
                                </div>
                                <div class="table-responsive">
                                    <table class="prev-payments-table">
                                        <thead>
                                            <tr>
                                                <th>Receipt No</th>
                                                <th>Date</th>
                                                <th class="text-right">Principal (₹)</th>
                                                <th class="text-right">Interest (₹)</th>
                                                <th class="text-right">Total (₹)</th>
                                                <th>Mode</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($previous_payments as $prev): ?>
                                            <tr>
                                                <td>
                                                    <a href="view-receipt.php?receipt=<?php echo $prev['receipt_number']; ?>" style="color: #667eea; text-decoration: none;">
                                                        <?php echo $prev['receipt_number']; ?>
                                                    </a>
                                                </td>
                                                <td><?php echo date('d-m-Y', strtotime($prev['payment_date'])); ?></td>
                                                <td class="text-right"><?php echo $prev['principal_amount'] > 0 ? number_format($prev['principal_amount'], 2) : '-'; ?></td>
                                                <td class="text-right"><?php echo $prev['interest_amount'] > 0 ? number_format($prev['interest_amount'], 2) : '-'; ?></td>
                                                <td class="text-right"><strong>₹ <?php echo number_format($prev['total_amount'], 2); ?></strong></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $prev['payment_mode'] == 'cash' ? 'success' : ($prev['payment_mode'] == 'bank' ? 'primary' : 'purple'); ?>" style="font-size: 10px;">
                                                        <?php echo strtoupper($prev['payment_mode']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Footer Note -->
                            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px dashed #e2e8f0; text-align: center; color: #a0aec0; font-size: 12px;">
                                <i class="bi bi-shield-check"></i> This is an electronically generated receipt and does not require a physical signature.
                            </div>
                        </div>
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

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+P to print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.open('bill-receipt.php?receipt=<?php echo $receipt['receipt_number']; ?>&print=1', '_blank');
            }
            // Esc to go back
            if (e.key === 'Escape') {
                window.location.href = 'print-receipt-copy.php';
            }
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>