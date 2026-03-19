<?php
session_start();
$currentPage = 'view-closed-loan';
$pageTitle = 'View Closed Loan';
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

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id <= 0) {
    header('Location: close-loan-receipt-notes.php');
    exit();
}

// Set UTF-8 for database connection
mysqli_set_charset($conn, 'utf8mb4');

// Get loan details with customer information - only closed loans
$loan_query = "SELECT l.*, 
               c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
               c.mobile_number, c.whatsapp_number, c.email,
               c.door_no, c.street_name, c.location, c.district, c.pincode, c.post, c.taluk,
               c.aadhaar_number, c.customer_photo,
               u.name as employee_name,
               DATEDIFF(l.close_date, l.receipt_date) as loan_duration_days
               FROM loans l 
               JOIN customers c ON l.customer_id = c.id 
               JOIN users u ON l.employee_id = u.id 
               WHERE l.id = ? AND l.status = 'closed'";

$stmt = mysqli_prepare($conn, $loan_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$loan_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($loan_result) == 0) {
    header('Location: close-loan-receipt-notes.php?error=not_found');
    exit();
}

$loan = mysqli_fetch_assoc($loan_result);

// Get loan items (jewelry) with photos
$items_query = "SELECT * FROM loan_items WHERE loan_id = ? ORDER BY id";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
$items = [];
$total_weight = 0;
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
    $total_weight += $item['net_weight'] * $item['quantity'];
}

// Get all payments made for this loan
$payments_query = "SELECT p.*, u.name as employee_name 
                   FROM payments p 
                   JOIN users u ON p.employee_id = u.id 
                   WHERE p.loan_id = ? 
                   ORDER BY p.payment_date ASC";
$stmt = mysqli_prepare($conn, $payments_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$payments_result = mysqli_stmt_get_result($stmt);
$payments = [];
$total_paid_principal = 0;
$total_paid_interest = 0;
while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment;
    $total_paid_principal += floatval($payment['principal_amount']);
    $total_paid_interest += floatval($payment['interest_amount']);
}

// Calculate loan details
$principal = floatval($loan['loan_amount']);
$interest_rate = floatval($loan['interest_amount']);
$receipt_charge = floatval($loan['receipt_charge'] ?? 0);
$discount = floatval($loan['discount'] ?? 0);
$round_off = floatval($loan['round_off'] ?? 0);
$d_namuna = intval($loan['d_namuna'] ?? 0);
$others = intval($loan['others'] ?? 0);

// Calculate duration
$receipt_date = new DateTime($loan['receipt_date']);
$close_date = new DateTime($loan['close_date']);
$duration_days = $receipt_date->diff($close_date)->days;
$duration_months = floor($duration_days / 30);
$duration_remaining_days = $duration_days % 30;

// Calculate interest details
$monthly_interest = ($principal * $interest_rate) / 100;
$daily_interest = $monthly_interest / 30;
$total_interest_accrued = $daily_interest * $duration_days;

// Calculate final amounts
$payable_principal = $principal - $total_paid_principal;
$payable_interest = $total_interest_accrued - $total_paid_interest;
$total_payable = $payable_principal + $payable_interest + $receipt_charge - $discount + $round_off;
$total_received = $total_paid_principal + $total_paid_interest + $receipt_charge - $discount + $round_off;

// Format address
$customer_address = trim($loan['door_no'] . ' ' . $loan['street_name'] . ', ' . 
                     $loan['location'] . ', ' . $loan['district'] . ' - ' . 
                     $loan['pincode']);

// Format currency function
function formatCurrency($amount) {
    return '₹ ' . number_format($amount, 2);
}

function formatDate($date) {
    return date('d-m-Y', strtotime($date));
}

// Get customer initials
function getInitials($name) {
    $name_parts = explode(' ', trim($name));
    $initials = '';
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
    return $initials;
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

        .view-container {
            max-width: 1200px;
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-closed {
            background: #48bb78;
            color: white;
        }

        .badge-dnamuna {
            background: #9f7aea;
            color: white;
        }

        .badge-others {
            background: #f687b3;
            color: white;
        }

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .card-title {
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

        .card-title i {
            color: #667eea;
        }

        /* Customer Header */
        .customer-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border-radius: 12px;
        }

        .customer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 32px;
            flex-shrink: 0;
        }

        .customer-info {
            flex: 1;
        }

        .customer-name {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .customer-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            color: #718096;
            font-size: 14px;
        }

        .customer-meta i {
            color: #667eea;
            margin-right: 5px;
        }

        /* Two Column Layout */
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .info-item {
            padding: 12px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value.highlight {
            color: #48bb78;
        }

        .info-value.interest {
            color: #ecc94b;
        }

        .info-value.warning {
            color: #f56565;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .items-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .jewel-photo {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }

        /* Payment History */
        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-date {
            font-weight: 600;
            color: #667eea;
            min-width: 100px;
        }

        .payment-receipt {
            color: #2d3748;
            font-weight: 500;
            min-width: 120px;
        }

        .payment-desc {
            flex: 1;
            color: #718096;
            padding: 0 15px;
        }

        .payment-amount {
            font-weight: 600;
            min-width: 100px;
            text-align: right;
        }

        .payment-amount.principal {
            color: #667eea;
        }

        .payment-amount.interest {
            color: #ecc94b;
        }

        /* Summary Box */
        .summary-box {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row.total {
            border-top: 2px solid #48bb78;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            font-weight: 700;
            font-size: 18px;
        }

        .summary-label {
            color: #4a5568;
        }

        .summary-value {
            font-weight: 600;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .mt-4 {
            margin-top: 20px;
        }

        .mb-3 {
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .two-column {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .customer-header {
                flex-direction: column;
                text-align: center;
            }
            
            .customer-meta {
                justify-content: center;
            }
            
            .payment-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .payment-date, .payment-receipt, .payment-desc, .payment-amount {
                width: 100%;
                text-align: left;
            }
            
            .items-table {
                overflow-x: auto;
                display: block;
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
                <div class="view-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-file-text"></i>
                            Closed Loan Details
                        </h1>
                        <div>
                            <a href="close-loan-receipt-notes.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                            <a href="print-close-receipt.php?id=<?php echo $loan_id; ?>" class="btn btn-success" target="_blank">
                                <i class="bi bi-printer"></i> Print Receipt
                            </a>
                        </div>
                    </div>

                    <!-- Customer Header with Avatar -->
                    <div class="customer-header">
                        <?php if (!empty($loan['customer_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($loan['customer_photo']); ?>" class="customer-avatar" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="customer-avatar">
                                <?php echo getInitials($loan['customer_name']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="customer-info">
                            <div class="customer-name">
                                <?php echo htmlspecialchars($loan['customer_name']); ?>
                                <span class="status-badge badge-closed" style="margin-left: 10px;">Closed</span>
                                <?php if ($d_namuna): ?>
                                    <span class="status-badge badge-dnamuna" style="margin-left: 5px;">D-Namuna</span>
                                <?php endif; ?>
                                <?php if ($others): ?>
                                    <span class="status-badge badge-others" style="margin-left: 5px;">Others</span>
                                <?php endif; ?>
                            </div>
                            <div class="customer-meta">
                                <span><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($loan['mobile_number']); ?></span>
                                <?php if (!empty($loan['whatsapp_number'])): ?>
                                    <span><i class="bi bi-whatsapp"></i> <?php echo htmlspecialchars($loan['whatsapp_number']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($loan['email'])): ?>
                                    <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($loan['email']); ?></span>
                                <?php endif; ?>
                                <span><i class="bi bi-person"></i> <?php echo ($loan['guardian_type'] ? $loan['guardian_type'] . '. ' : '') . htmlspecialchars($loan['guardian_name']); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Two Column Layout -->
                    <div class="two-column">
                        <!-- Left Column - Loan Information -->
                        <div class="info-card">
                            <div class="card-title">
                                <i class="bi bi-receipt"></i>
                                Loan Information
                            </div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Receipt Number</div>
                                    <div class="info-value highlight"><?php echo htmlspecialchars($loan['receipt_number']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Receipt Date</div>
                                    <div class="info-value"><?php echo formatDate($loan['receipt_date']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Close Date</div>
                                    <div class="info-value highlight"><?php echo formatDate($loan['close_date']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Duration</div>
                                    <div class="info-value"><?php echo $duration_days; ?> days (<?php echo $duration_months; ?>m <?php echo $duration_remaining_days; ?>d)</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Product Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($loan['product_type'] ?? 'Jewelry'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Interest Type</div>
                                    <div class="info-value"><?php echo ucfirst($loan['interest_type']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Processed By</div>
                                    <div class="info-value"><?php echo htmlspecialchars($loan['employee_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Aadhaar</div>
                                    <div class="info-value"><?php echo $loan['aadhaar_number'] ? 'XXXX-XXXX-' . substr($loan['aadhaar_number'], -4) : 'Not Provided'; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column - Weight & Value -->
                        <div class="info-card">
                            <div class="card-title">
                                <i class="bi bi-gem"></i>
                                Weight & Value
                            </div>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Gross Weight</div>
                                    <div class="info-value"><?php echo number_format($loan['gross_weight'], 3); ?> g</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Net Weight</div>
                                    <div class="info-value"><?php echo number_format($loan['net_weight'], 3); ?> g</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Total Items Weight</div>
                                    <div class="info-value"><?php echo number_format($total_weight, 3); ?> g</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Product Value</div>
                                    <div class="info-value"><?php echo formatCurrency($loan['product_value']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Loan Amount</div>
                                    <div class="info-value highlight"><?php echo formatCurrency($principal); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Interest Rate</div>
                                    <div class="info-value interest"><?php echo $interest_rate; ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <div class="info-card">
                        <div class="card-title">
                            <i class="bi bi-geo-alt"></i>
                            Address Information
                        </div>
                        <div class="info-grid">
                            <div class="info-item" style="grid-column: span 2;">
                                <div class="info-label">Current Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($customer_address); ?></div>
                            </div>
                            <?php if (!empty($loan['post']) || !empty($loan['taluk'])): ?>
                            <div class="info-item">
                                <div class="info-label">Post Office</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['post'] ?? ''); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Taluk</div>
                                <div class="info-value"><?php echo htmlspecialchars($loan['taluk'] ?? ''); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Jewelry Items -->
                    <div class="info-card">
                        <div class="card-title">
                            <i class="bi bi-box"></i>
                            Jewelry Items
                        </div>
                        
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Photo</th>
                                    <th>Jewel Name</th>
                                    <th>Karat</th>
                                    <th>Defect</th>
                                    <th>Stone</th>
                                    <th class="text-right">Weight (g)</th>
                                    <th class="text-center">Qty</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sno = 1;
                                foreach ($items as $item): 
                                ?>
                                <tr>
                                    <td><?php echo $sno++; ?></td>
                                    <td>
                                        <?php if (!empty($item['photo_path']) && file_exists($item['photo_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($item['photo_path']); ?>" class="jewel-photo" alt="Jewel">
                                        <?php else: ?>
                                            <div style="width:50px;height:50px;background:#f7fafc;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#a0aec0;">
                                                <i class="bi bi-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['jewel_name']); ?></td>
                                    <td><?php echo $item['karat']; ?>K</td>
                                    <td><?php echo htmlspecialchars($item['defect_details'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($item['stone_details'] ?: '-'); ?></td>
                                    <td class="text-right"><?php echo number_format($item['net_weight'], 3); ?></td>
                                    <td class="text-center"><?php echo $item['quantity']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="6" class="text-right"><strong>Total Weight:</strong></td>
                                    <td class="text-right"><strong><?php echo number_format($total_weight, 3); ?> g</strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Payment History -->
                    <?php if (!empty($payments)): ?>
                    <div class="info-card">
                        <div class="card-title">
                            <i class="bi bi-clock-history"></i>
                            Payment History
                        </div>
                        
                        <div class="payment-history">
                            <?php foreach ($payments as $payment): ?>
                            <div class="payment-item">
                                <span class="payment-date"><?php echo formatDate($payment['payment_date']); ?></span>
                                <span class="payment-receipt"><?php echo $payment['receipt_number']; ?></span>
                                <span class="payment-desc">
                                    <?php 
                                    if ($payment['principal_amount'] > 0 && $payment['interest_amount'] > 0) {
                                        echo 'Principal + Interest';
                                    } elseif ($payment['principal_amount'] > 0) {
                                        echo 'Principal Payment';
                                    } else {
                                        echo 'Interest Payment';
                                    }
                                    ?>
                                </span>
                                <span class="payment-amount principal"><?php echo $payment['principal_amount'] > 0 ? formatCurrency($payment['principal_amount']) : ''; ?></span>
                                <span class="payment-amount interest"><?php echo $payment['interest_amount'] > 0 ? formatCurrency($payment['interest_amount']) : ''; ?></span>
                                <span class="payment-amount" style="color: #48bb78;"><?php echo formatCurrency($payment['total_amount']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Financial Summary -->
                    <div class="summary-box">
                        <div class="card-title" style="border-bottom: none; margin-bottom: 10px;">
                            <i class="bi bi-calculator"></i>
                            Financial Summary
                        </div>
                        
                        <div class="summary-row">
                            <span class="summary-label">Principal Amount:</span>
                            <span class="summary-value"><?php echo formatCurrency($principal); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Total Interest Accrued:</span>
                            <span class="summary-value interest"><?php echo formatCurrency($total_interest_accrued); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Interest Paid:</span>
                            <span class="summary-value"><?php echo formatCurrency($total_paid_interest); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Principal Paid:</span>
                            <span class="summary-value"><?php echo formatCurrency($total_paid_principal); ?></span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Receipt Charge:</span>
                            <span class="summary-value"><?php echo formatCurrency($receipt_charge); ?></span>
                        </div>
                        <?php if ($discount > 0): ?>
                        <div class="summary-row">
                            <span class="summary-label">Discount:</span>
                            <span class="summary-value warning">- <?php echo formatCurrency($discount); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($round_off != 0): ?>
                        <div class="summary-row">
                            <span class="summary-label">Round Off:</span>
                            <span class="summary-value"><?php echo formatCurrency($round_off); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-row total">
                            <span class="summary-label">Total Amount Received:</span>
                            <span class="summary-value highlight"><?php echo formatCurrency($total_received); ?></span>
                        </div>
                    </div>

                    <!-- Remarks if any -->
                    <?php if (!empty($loan['remarks'])): ?>
                    <div class="info-card" style="margin-top: 20px;">
                        <div class="card-title">
                            <i class="bi bi-chat-text"></i>
                            Remarks
                        </div>
                        <p style="color: #4a5568;"><?php echo nl2br(htmlspecialchars($loan['remarks'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>