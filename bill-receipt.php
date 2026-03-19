<?php
session_start();
$currentPage = 'bill-receipt';
$pageTitle = 'Bill Receipt';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$receipt = null;
$loan = null;
$customer = null;
$payments = null;

// Get branch ID from session or default to 1
$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;

// Get company/branch details
$branch_query = "SELECT * FROM branches WHERE id = ? AND status = 'active'";
$stmt = mysqli_prepare($conn, $branch_query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$branch_result = mysqli_stmt_get_result($stmt);
$branch = mysqli_fetch_assoc($branch_result);

if (!$branch) {
    // Fallback to first active branch
    $fallback_query = "SELECT * FROM branches WHERE status = 'active' LIMIT 1";
    $fallback_result = mysqli_query($conn, $fallback_query);
    $branch = mysqli_fetch_assoc($fallback_result);
}

// Get receipt number from URL
$receipt_no = isset($_GET['receipt']) ? mysqli_real_escape_string($conn, $_GET['receipt']) : '';

if (!empty($receipt_no)) {
    // Get receipt details with new columns
    $receipt_query = "SELECT p.*, 
                      l.receipt_number as loan_receipt, 
                      l.loan_amount,
                      l.interest_amount as loan_interest_rate,
                      l.receipt_date as loan_date,
                      c.id as customer_id,
                      c.customer_name, 
                      c.mobile_number,
                      c.whatsapp_number,
                      c.guardian_name,
                      c.guardian_type,
                      c.door_no,
                      c.street_name,
                      c.location,
                      c.district,
                      c.pincode,
                      u.name as employee_name
                      FROM payments p
                      JOIN loans l ON p.loan_id = l.id
                      JOIN customers c ON l.customer_id = c.id
                      JOIN users u ON p.employee_id = u.id
                      WHERE p.receipt_number = ?";

    $stmt = mysqli_prepare($conn, $receipt_query);
    mysqli_stmt_bind_param($stmt, 's', $receipt_no);
    mysqli_stmt_execute($stmt);
    $receipt_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($receipt_result) > 0) {
        $receipt = mysqli_fetch_assoc($receipt_result);
        
        // Get loan details
        $loan_query = "SELECT * FROM loans WHERE id = ?";
        $stmt = mysqli_prepare($conn, $loan_query);
        mysqli_stmt_bind_param($stmt, 'i', $receipt['loan_id']);
        mysqli_stmt_execute($stmt);
        $loan = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        // Get customer details
        $customer_query = "SELECT * FROM customers WHERE id = ?";
        $stmt = mysqli_prepare($conn, $customer_query);
        mysqli_stmt_bind_param($stmt, 'i', $receipt['customer_id']);
        mysqli_stmt_execute($stmt);
        $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        // Get all payments for this loan with new columns
        $payments_query = "SELECT p.*, u.name as collector_name 
                          FROM payments p 
                          JOIN users u ON p.employee_id = u.id 
                          WHERE p.loan_id = ? 
                          ORDER BY p.payment_date DESC";
        $stmt = mysqli_prepare($conn, $payments_query);
        mysqli_stmt_bind_param($stmt, 'i', $receipt['loan_id']);
        mysqli_stmt_execute($stmt);
        $payments_result = mysqli_stmt_get_result($stmt);
        $payments = [];
        while ($payment = mysqli_fetch_assoc($payments_result)) {
            $payments[] = $payment;
        }
        
        // Calculate totals including overdue charges
        $total_principal_paid = 0;
        $total_interest_paid = 0;
        $total_overdue_paid = 0;
        $total_charge_paid = 0;
        
        foreach ($payments as $payment) {
            $total_principal_paid += $payment['principal_amount'];
            $total_interest_paid += $payment['interest_amount'];
            $total_overdue_paid += $payment['overdue_amount_paid'] ?? 0;
            $total_charge_paid += $payment['overdue_charge'] ?? 0;
        }
        
        $remaining_principal = $loan['loan_amount'] - $total_principal_paid;
    }
}

// Function to convert number to words
function numberToWords($number) {
    $number = floatval($number);
    $no = floor($number);
    $decimal = round($number - $no, 2) * 100;
    $decimal = (int) $decimal;
    
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($no == 0) {
        $amount_words = 'Zero';
    } else {
        $amount_words = '';
        $num = $no;
        $j = 0;
        
        while ($num > 0) {
            $remainder = $num % 100;
            if ($remainder > 0) {
                if ($j == 1) {
                    $amount_words = numToWord($remainder, $words) . ' Hundred ' . $amount_words;
                } elseif ($j == 2) {
                    $amount_words = numToWord($remainder, $words) . ' Thousand ' . $amount_words;
                } elseif ($j == 3) {
                    $amount_words = numToWord($remainder, $words) . ' Lakh ' . $amount_words;
                } elseif ($j == 4) {
                    $amount_words = numToWord($remainder, $words) . ' Crore ' . $amount_words;
                } else {
                    $amount_words = numToWord($remainder, $words) . ' ' . $amount_words;
                }
            }
            $num = floor($num / 100);
            $j++;
        }
    }
    
    if ($decimal > 0) {
        $amount_words .= ' and ' . numToWord($decimal, $words) . ' Paise';
    }
    
    return trim($amount_words) . ' Only';
}

function numToWord($num, $words) {
    if ($num < 20) {
        return $words[$num];
    } else {
        $ten = floor($num / 10) * 10;
        $unit = $num % 10;
        if ($unit == 0) {
            return $words[$ten];
        } else {
            return $words[$ten] . ' ' . $words[$unit];
        }
    }
}

// Format customer address
$customer_address = trim(($customer['door_no'] ?? '') . ' ' . ($customer['street_name'] ?? '') . ', ' . 
                     ($customer['location'] ?? '') . ', ' . ($customer['district'] ?? '') . ' - ' . 
                     ($customer['pincode'] ?? ''));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo $receipt_no; ?></title>
    <style>
        /* A4 Size Styles */
        @page {
            size: A4;
            margin: 0;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', Helvetica, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .receipt-a4 {
            width: 210mm;
            min-height: 297mm;
            background: white;
            margin: 0 auto;
            padding: 15mm 15mm 10mm 15mm;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            position: relative;
        }
        
        /* Header with Logo */
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        
        .logo-container {
            flex: 0 0 100px;
            margin-right: 20px;
        }
        
        .logo {
            max-width: 100px;
            max-height: 80px;
            object-fit: contain;
        }
        
        .company-info {
            flex: 1;
            text-align: center;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        
        .company-details {
            font-size: 12px;
            color: #666;
            margin-bottom: 3px;
            line-height: 1.4;
        }
        
        .receipt-title {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0 5px;
            text-align: center;
        }
        
        .receipt-no {
            font-size: 16px;
            font-weight: bold;
            margin: 5px 0;
            text-align: center;
        }
        
        .receipt-date {
            font-size: 14px;
            color: #666;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .badge {
            display: inline-block;
            padding: 6px 20px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: bold;
            margin: 10px auto;
            text-align: center;
            width: fit-content;
        }
        
        .badge-principal {
            background: #4299e1;
            color: white;
        }
        
        .badge-interest {
            background: #ecc94b;
            color: #744210;
        }
        
        .badge-overdue {
            background: #f56565;
            color: white;
        }
        
        .badge-charge {
            background: #9f7aea;
            color: white;
        }
        
        .badge-mixed {
            background: #667eea;
            color: white;
        }
        
        /* Two Column Layout */
        .info-grid {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }
        
        .info-section {
            flex: 1;
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 12px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e2e8f0;
            color: #333;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .label {
            font-weight: bold;
            width: 100px;
            color: #666;
        }
        
        .value {
            flex: 1;
            color: #333;
        }
        
        /* Amount Box */
        .amount-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
            border: 2px solid #667eea;
        }
        
        .amount-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .amount-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .amount-breakdown {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .breakdown-item {
            text-align: center;
        }
        
        .breakdown-label {
            font-size: 12px;
            color: #666;
        }
        
        .breakdown-value {
            font-size: 16px;
            font-weight: bold;
        }
        
        .principal-value {
            color: #4299e1;
        }
        
        .interest-value {
            color: #ecc94b;
        }
        
        .overdue-value {
            color: #f56565;
        }
        
        .charge-value {
            color: #9f7aea;
        }
        
        /* Amount in Words */
        .words-box {
            font-size: 13px;
            margin: 20px 0;
            padding: 12px;
            background: #ebf4ff;
            border-left: 4px solid #667eea;
            border-radius: 4px;
        }
        
        /* Payment History Table */
        .history-section {
            margin: 25px 0;
        }
        
        .history-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        .history-table th {
            background: #f7fafc;
            padding: 10px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #e2e8f0;
        }
        
        .history-table td {
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
        }
        
        .history-table tfoot {
            background: #f7fafc;
            font-weight: bold;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* Signature Section */
        .signature {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 12px;
        }
        
        .signature-box {
            text-align: center;
            width: 200px;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 40px;
            padding-top: 5px;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 30px;
            position: absolute;
            bottom: 10mm;
            left: 15mm;
            right: 15mm;
        }
        
        /* Print Button */
        .print-controls {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
            font-weight: bold;
        }
        
        .btn:hover {
            background: #5a67d8;
        }
        
        .btn-secondary {
            background: #a0aec0;
        }
        
        .btn-secondary:hover {
            background: #718096;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .receipt-a4 {
                box-shadow: none;
                padding: 15mm;
            }
            
            .print-controls {
                display: none;
            }
            
            .badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .amount-box {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .words-box {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .history-table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button class="btn" onclick="window.print()">🖨️ Print Receipt</button>
        <button class="btn btn-secondary" onclick="window.close()">✖️ Close</button>
    </div>

    <?php if ($receipt && $customer && $loan): ?>
    <div class="receipt-a4">
        <!-- Header with Logo -->
        <div class="header">
            <div class="logo-container">
                <?php if (!empty($branch['logo_path']) && file_exists($branch['logo_path'])): ?>
                    <img src="<?php echo $branch['logo_path']; ?>" class="logo" alt="Company Logo">
                <?php else: ?>
                    <div style="width:100px;height:80px;background:#f7fafc;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#a0aec0;font-size:12px;border:1px dashed #667eea;">
                        Logo
                    </div>
                <?php endif; ?>
            </div>
            <div class="company-info">
                <div class="company-name"><?php echo htmlspecialchars($branch['branch_name'] ?? 'PAWN BROKER'); ?></div>
                <div class="company-details"><?php echo htmlspecialchars($branch['address'] ?? ''); ?></div>
                <div class="company-details">
                    Phone: <?php echo $branch['phone'] ?? ''; ?> 
                    <?php if (!empty($branch['email'])): ?> | Email: <?php echo $branch['email']; ?><?php endif; ?>
                </div>
                <?php if (!empty($branch['website'])): ?>
                    <div class="company-details"><?php echo $branch['website']; ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Receipt Title -->
        <div class="receipt-title">PAYMENT RECEIPT</div>
        <div class="receipt-no">Receipt No: <?php echo $receipt['receipt_number']; ?></div>
        <div class="receipt-date">Date: <?php echo date('d-m-Y', strtotime($receipt['payment_date'])); ?></div>
        
        <!-- Payment Type Badge -->
        <div style="text-align: center;">
            <?php 
            $badge_class = 'badge-interest';
            $badge_text = 'INTEREST PAYMENT';
            
            if (($receipt['overdue_charge'] ?? 0) > 0) {
                $badge_class = 'badge-charge';
                $badge_text = 'OVERDUE CHARGE';
            } elseif (($receipt['overdue_amount_paid'] ?? 0) > 0 && $receipt['interest_amount'] > 0) {
                $badge_class = 'badge-mixed';
                $badge_text = 'INTEREST + OVERDUE';
            } elseif (($receipt['overdue_amount_paid'] ?? 0) > 0) {
                $badge_class = 'badge-overdue';
                $badge_text = 'OVERDUE PAYMENT';
            } elseif ($receipt['principal_amount'] > 0 && $receipt['interest_amount'] == 0) {
                $badge_class = 'badge-principal';
                $badge_text = 'PRINCIPAL PAYMENT';
            }
            ?>
            <div class="badge <?php echo $badge_class; ?>">
                <?php echo $badge_text; ?>
            </div>
        </div>

        <!-- Information Grid -->
        <div class="info-grid">
            <!-- Customer Information -->
            <div class="info-section">
                <div class="section-title">👤 CUSTOMER INFORMATION</div>
                <div class="info-row">
                    <span class="label">Name:</span>
                    <span class="value"><?php echo htmlspecialchars($customer['customer_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Guardian:</span>
                    <span class="value">
                        <?php echo $customer['guardian_type'] ? $customer['guardian_type'] . '. ' : ''; ?>
                        <?php echo htmlspecialchars($customer['guardian_name']); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="label">Mobile:</span>
                    <span class="value">
                        <?php echo $customer['mobile_number']; ?>
                        <?php if (!empty($customer['whatsapp_number'])): ?>
                            <br><small>WhatsApp: <?php echo $customer['whatsapp_number']; ?></small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="label">Address:</span>
                    <span class="value"><?php echo htmlspecialchars($customer_address); ?></span>
                </div>
            </div>

            <!-- Loan Information -->
            <div class="info-section">
                <div class="section-title">💰 LOAN INFORMATION</div>
                <div class="info-row">
                    <span class="label">Loan Receipt:</span>
                    <span class="value"><?php echo $receipt['loan_receipt']; ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Loan Date:</span>
                    <span class="value"><?php echo date('d-m-Y', strtotime($receipt['loan_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Original Principal:</span>
                    <span class="value">₹ <?php echo number_format($loan['loan_amount'], 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Remaining Principal:</span>
                    <span class="value" style="color:#4299e1; font-weight:bold;">₹ <?php echo number_format($remaining_principal, 2); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Interest Rate:</span>
                    <span class="value"><?php echo $loan['interest_amount']; ?>%</span>
                </div>
                <div class="info-row">
                    <span class="label">Collected By:</span>
                    <span class="value"><?php echo htmlspecialchars($receipt['employee_name']); ?></span>
                </div>
            </div>
        </div>

        <!-- Amount Box with detailed breakdown -->
        <div class="amount-box">
            <div class="amount-label">TOTAL AMOUNT PAID</div>
            <div class="amount-value">₹ <?php echo number_format($receipt['total_amount'], 2); ?></div>
            <div class="amount-breakdown">
                <?php if ($receipt['principal_amount'] > 0): ?>
                <div class="breakdown-item">
                    <div class="breakdown-label">Principal</div>
                    <div class="breakdown-value principal-value">₹ <?php echo number_format($receipt['principal_amount'], 2); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($receipt['interest_amount'] > 0): ?>
                <div class="breakdown-item">
                    <div class="breakdown-label">Interest</div>
                    <div class="breakdown-value interest-value">₹ <?php echo number_format($receipt['interest_amount'], 2); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (($receipt['overdue_amount_paid'] ?? 0) > 0): ?>
                <div class="breakdown-item">
                    <div class="breakdown-label">Overdue</div>
                    <div class="breakdown-value overdue-value">₹ <?php echo number_format($receipt['overdue_amount_paid'], 2); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (($receipt['overdue_charge'] ?? 0) > 0): ?>
                <div class="breakdown-item">
                    <div class="breakdown-label">Charge</div>
                    <div class="breakdown-value charge-value">₹ <?php echo number_format($receipt['overdue_charge'], 2); ?></div>
                </div>
                <?php endif; ?>
                
                <div class="breakdown-item">
                    <div class="breakdown-label">Mode</div>
                    <div class="breakdown-value"><?php echo strtoupper($receipt['payment_mode']); ?></div>
                </div>
            </div>
        </div>

        <!-- Amount in Words -->
        <div class="words-box">
            <strong>Amount in Words:</strong> <?php echo numberToWords($receipt['total_amount']); ?>
        </div>

        <!-- Payment History with overdue details -->
        <?php if (!empty($payments)): ?>
        <div class="history-section">
            <div class="history-title">📋 PAYMENT HISTORY</div>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt No</th>
                        <th>Type</th>
                        <th class="text-right">Principal (₹)</th>
                        <th class="text-right">Interest (₹)</th>
                        <th class="text-right">Overdue (₹)</th>
                        <th class="text-right">Charge (₹)</th>
                        <th class="text-right">Total (₹)</th>
                        <th>Mode</th>
                        <th>Collected By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_principal = 0;
                    $total_interest = 0;
                    $total_overdue = 0;
                    $total_charge = 0;
                    $total_all = 0;
                    
                    foreach ($payments as $payment): 
                        // Determine payment type
                        $type = 'Interest';
                        if (($payment['overdue_charge'] ?? 0) > 0) {
                            $type = 'Charge';
                        } elseif (($payment['overdue_amount_paid'] ?? 0) > 0 && $payment['interest_amount'] > 0) {
                            $type = 'Int+Overdue';
                        } elseif (($payment['overdue_amount_paid'] ?? 0) > 0) {
                            $type = 'Overdue';
                        } elseif ($payment['principal_amount'] > 0 && $payment['interest_amount'] == 0) {
                            $type = 'Principal';
                        }
                        
                        $total_principal += $payment['principal_amount'];
                        $total_interest += $payment['interest_amount'];
                        $total_overdue += $payment['overdue_amount_paid'] ?? 0;
                        $total_charge += $payment['overdue_charge'] ?? 0;
                        $total_all += $payment['total_amount'];
                    ?>
                    <tr>
                        <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                        <td><?php echo $payment['receipt_number']; ?></td>
                        <td><?php echo $type; ?></td>
                        <td class="text-right"><?php echo $payment['principal_amount'] > 0 ? number_format($payment['principal_amount'], 2) : '-'; ?></td>
                        <td class="text-right"><?php echo $payment['interest_amount'] > 0 ? number_format($payment['interest_amount'], 2) : '-'; ?></td>
                        <td class="text-right"><?php echo ($payment['overdue_amount_paid'] ?? 0) > 0 ? number_format($payment['overdue_amount_paid'], 2) : '-'; ?></td>
                        <td class="text-right"><?php echo ($payment['overdue_charge'] ?? 0) > 0 ? number_format($payment['overdue_charge'], 2) : '-'; ?></td>
                        <td class="text-right"><?php echo number_format($payment['total_amount'], 2); ?></td>
                        <td><?php echo strtoupper($payment['payment_mode']); ?></td>
                        <td><?php echo htmlspecialchars($payment['collector_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right"><strong>TOTALS:</strong></td>
                        <td class="text-right"><strong>₹ <?php echo number_format($total_principal, 2); ?></strong></td>
                        <td class="text-right"><strong>₹ <?php echo number_format($total_interest, 2); ?></strong></td>
                        <td class="text-right"><strong>₹ <?php echo number_format($total_overdue, 2); ?></strong></td>
                        <td class="text-right"><strong>₹ <?php echo number_format($total_charge, 2); ?></strong></td>
                        <td class="text-right"><strong>₹ <?php echo number_format($total_all, 2); ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <!-- Signature Section -->
        <div class="signature">
            <div class="signature-box">
                <div class="signature-line">Customer Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized Signatory</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            This is a computer generated receipt | Generated on: <?php echo date('d-m-Y H:i:s'); ?> | <?php echo $branch['branch_name'] ?? ''; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="receipt-a4" style="display: flex; align-items: center; justify-content: center; flex-direction: column;">
        <h3 style="color: #f56565; margin-bottom: 20px;">❌ No receipt found</h3>
        <p style="margin-bottom: 20px;">Invalid receipt number: <?php echo htmlspecialchars($receipt_no); ?></p>
        <button class="btn" onclick="window.close()">Close</button>
    </div>
    <?php endif; ?>

    <script>
        // Auto print if specified in URL
        <?php if (isset($_GET['print'])): ?>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
        <?php endif; ?>
    </script>
</body>
</html>