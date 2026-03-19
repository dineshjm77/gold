<?php
session_start();
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

$closure_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

if ($closure_id <= 0 && $loan_id <= 0) {
    header('Location: personal-loans.php');
    exit();
}

// Get closure details
if ($closure_id > 0) {
    $query = "SELECT lc.*, pl.receipt_number as loan_receipt, pl.loan_amount,
                     c.customer_name, c.mobile_number, c.email, c.customer_photo,
                     c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                     u.name as closed_by_name
              FROM loan_closures lc
              JOIN personal_loans pl ON lc.loan_id = pl.id
              JOIN customers c ON pl.customer_id = c.id
              JOIN users u ON lc.closed_by = u.id
              WHERE lc.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $closure_id);
} else {
    // Get the latest closure for this loan
    $query = "SELECT lc.*, pl.receipt_number as loan_receipt, pl.loan_amount,
                     c.customer_name, c.mobile_number, c.email, c.customer_photo,
                     c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                     u.name as closed_by_name
              FROM loan_closures lc
              JOIN personal_loans pl ON lc.loan_id = pl.id
              JOIN customers c ON pl.customer_id = c.id
              JOIN users u ON lc.closed_by = u.id
              WHERE lc.loan_id = ?
              ORDER BY lc.id DESC LIMIT 1";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$closure = mysqli_fetch_assoc($result);

if (!$closure) {
    header('Location: personal-loans.php?error=not_found');
    exit();
}

// Format address (compact)
$address_parts = array_filter([
    $closure['door_no'] ?? '',
    $closure['house_name'] ?? '',
    $closure['street_name'] ?? '',
    $closure['location'] ?? '',
    $closure['district'] ?? ''
]);
$customer_address = !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
if (!empty($closure['pincode'])) {
    $customer_address .= ' - ' . $closure['pincode'];
}

// Get company/branch settings
$company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
$company_result = mysqli_query($conn, $company_query);
$company = mysqli_fetch_assoc($company_result);

if (!$company) {
    $company = [
        'branch_name' => 'WEALTHROT',
        'address' => 'Main Branch',
        'phone' => 'N/A',
        'email' => 'info@wealthrot.in',
        'logo_path' => ''
    ];
}

// Calculate amounts in words (simple version)
function numberToWords($number) {
    $f = new NumberFormatter("en", NumberFormatter::SPELLOUT);
    return $f->format($number);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Closure Receipt - <?php echo $closure['receipt_number']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 10px;
        }

        .receipt-container {
            max-width: 1000px;
            width: 100%;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border-radius: 12px;
            overflow: hidden;
        }

        .receipt {
            padding: 15px;
            background: white;
            position: relative;
        }

        /* ULTRA-COMPACT A4 PRINT STYLES - FORCES SINGLE PAGE */
        @media print {
            @page {
                size: A4;
                margin: 0.3in;
            }
            
            body {
                background: white;
                padding: 0;
                margin: 0;
                display: block;
            }
            
            .receipt-container {
                max-width: 100%;
                box-shadow: none;
                border-radius: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .receipt {
                padding: 10px;
                height: auto;
                overflow: visible;
            }
            
            /* Force all elements to stay on same page */
            .header, .receipt-title, .info-grid, .amount-table, 
            .settlement-box, .words-amount, .footer, .terms {
                page-break-inside: avoid;
                page-break-after: avoid;
                page-break-before: avoid;
            }
            
            /* Compact everything */
            .header { margin-bottom: 5px; padding-bottom: 5px; }
            .receipt-title { margin: 5px 0 10px; }
            .info-grid { margin: 8px 0; gap: 8px; }
            .info-section { padding: 8px; }
            .amount-table { margin: 8px 0; }
            .settlement-box { margin: 8px 0; padding: 8px; }
            .words-amount { margin: 8px 0; padding: 6px; }
            .footer { margin-top: 10px; padding-top: 8px; }
        }

        /* Compact screen styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f56565;
        }

        .company-info h1 {
            font-size: 22px;
            color: #2d3748;
            margin-bottom: 2px;
            font-weight: 700;
        }

        .company-info p {
            color: #718096;
            font-size: 10px;
            margin: 1px 0;
            line-height: 1.2;
        }

        .receipt-title {
            text-align: center;
            margin: 5px 0 12px;
        }

        .receipt-title h2 {
            font-size: 22px;
            color: #f56565;
            margin-bottom: 2px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .receipt-title p {
            color: #718096;
            font-size: 12px;
        }

        .badge-closed {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #f56565;
            color: white;
            margin-left: 5px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 10px 0;
        }

        .info-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px;
            border: 1px solid #e2e8f0;
        }

        .info-section h3 {
            color: #2d3748;
            font-size: 14px;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 2px solid #f56565;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .info-section h3 i {
            color: #f56565;
            font-size: 14px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 11px;
            line-height: 1.3;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #4a5568;
            width: 35%;
        }

        .info-value {
            color: #2d3748;
            width: 65%;
            text-align: right;
            font-weight: 500;
            word-break: break-word;
        }

        .amount-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            font-size: 11px;
        }

        .amount-table th {
            background: #f56565;
            color: white;
            padding: 6px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
        }

        .amount-table td {
            padding: 6px;
            border: 1px solid #e2e8f0;
        }

        .amount-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .amount-table .total-row {
            background: #fef3c7 !important;
            font-weight: 700;
        }

        .amount-table .total-row td {
            border-top: 2px solid #f56565;
        }

        .settlement-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #f56565;
            border-radius: 8px;
            padding: 10px;
            margin: 8px 0;
            text-align: center;
        }

        .settlement-label {
            font-size: 12px;
            color: #c53030;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .settlement-amount {
            font-size: 28px;
            font-weight: 700;
            color: #f56565;
            line-height: 1.1;
        }

        .settlement-received {
            font-size: 11px;
            color: #48bb78;
            margin-top: 2px;
        }

        .words-amount {
            background: #ebf4ff;
            padding: 6px;
            border-radius: 6px;
            margin: 8px 0;
            font-size: 10px;
            color: #2c5282;
            text-align: center;
            font-weight: 500;
            border-left: 3px solid #4299e1;
            line-height: 1.3;
        }

        .footer {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 2px dashed #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .signature {
            text-align: center;
        }

        .signature-line {
            width: 120px;
            border-top: 2px solid #2d3748;
            margin: 4px 0;
        }

        .signature-text {
            font-size: 9px;
            color: #718096;
        }

        .terms {
            font-size: 8px;
            color: #718096;
            margin-top: 8px;
            text-align: center;
            line-height: 1.2;
        }

        .print-button {
            text-align: center;
            margin: 10px 0 5px;
        }

        .btn-print {
            background: #f56565;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(245,101,101,0.3);
        }

        .btn-print:hover {
            background: #c53030;
        }

        .btn-back {
            background: #718096;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            text-decoration: none;
            margin-left: 5px;
        }

        .btn-back:hover {
            background: #4a5568;
        }

        .watermark {
            position: fixed;
            bottom: 10px;
            right: 10px;
            opacity: 0.02;
            font-size: 50px;
            transform: rotate(-15deg);
            pointer-events: none;
            color: #f56565;
            font-weight: 700;
            z-index: 0;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .footer {
                flex-direction: column;
                gap: 15px;
            }
            
            .print-button {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .btn-back {
                margin-left: 0;
            }
            
            .signature-line {
                width: 100px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt">
            <!-- Watermark -->
            <div class="watermark">CLOSED</div>
            
            <!-- Print & Back Buttons (visible only on screen) -->
            <div class="print-button no-print">
                <button onclick="window.print()" class="btn-print">
                    <i class="bi bi-printer"></i> Print Receipt
                </button>
                <a href="view-personal-loan.php?id=<?php echo $closure['loan_id']; ?>" class="btn-back">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <!-- Header -->
            <div class="header">
                <div class="company-info">
                    <h1><?php echo htmlspecialchars($company['branch_name']); ?></h1>
                    <p><?php echo htmlspecialchars($company['address']); ?></p>
                    <p>📞 <?php echo htmlspecialchars($company['phone']); ?> | ✉️ <?php echo htmlspecialchars($company['email']); ?></p>
                </div>
                <div class="logo">
                    <?php if (!empty($company['logo_path']) && file_exists($company['logo_path'])): ?>
                        <img src="<?php echo $company['logo_path']; ?>" alt="Logo" style="max-height: 40px;">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Receipt Title -->
            <div class="receipt-title">
                <h2>LOAN CLOSURE <span class="badge-closed">CLOSED</span></h2>
                <p><?php echo $closure['receipt_number']; ?></p>
            </div>

            <!-- Customer & Loan Information Grid -->
            <div class="info-grid">
                <!-- Customer Details -->
                <div class="info-section">
                    <h3><i class="bi bi-person"></i> Customer</h3>
                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><strong><?php echo htmlspecialchars($closure['customer_name']); ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Mobile:</span>
                        <span class="info-value"><?php echo htmlspecialchars($closure['mobile_number']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($closure['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-value"><?php echo htmlspecialchars($customer_address); ?></span>
                    </div>
                </div>

                <!-- Loan Details -->
                <div class="info-section">
                    <h3><i class="bi bi-receipt"></i> Loan</h3>
                    <div class="info-row">
                        <span class="info-label">Receipt:</span>
                        <span class="info-value"><strong><?php echo $closure['loan_receipt']; ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Original:</span>
                        <span class="info-value">₹<?php echo number_format($closure['loan_amount'], 2); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Closed On:</span>
                        <span class="info-value"><?php echo date('d-m-Y', strtotime($closure['closure_date'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Closed By:</span>
                        <span class="info-value"><?php echo htmlspecialchars($closure['closed_by_name']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Settlement Breakdown Table -->
            <table class="amount-table">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>Principal Remaining</td><td><strong>₹<?php echo number_format($closure['principal_remaining'], 2); ?></strong></td></tr>
                    <tr><td>Unpaid EMI Amount</td><td>₹<?php echo number_format($closure['unpaid_emi_amount'], 2); ?></td></tr>
                    <tr><td>Penalty Charges</td><td>₹<?php echo number_format($closure['penalty_amount'], 2); ?></td></tr>
                    <tr><td>Process Charge</td><td>₹<?php echo number_format($closure['process_charge'], 2); ?></td></tr>
                    <tr><td>Appraisal Charge</td><td>₹<?php echo number_format($closure['appraisal_charge'], 2); ?></td></tr>
                    <?php if ($closure['discount_amount'] > 0): ?>
                    <tr><td>Discount Given</td><td style="color: #48bb78;">- ₹<?php echo number_format($closure['discount_amount'], 2); ?></td></tr>
                    <?php endif; ?>
                    <tr class="total-row"><td><strong>TOTAL SETTLEMENT</strong></td><td><strong>₹<?php echo number_format($closure['settlement_amount'], 2); ?></strong></td></tr>
                </tbody>
            </table>

            <!-- Amount Received Box -->
            <div class="settlement-box">
                <div class="settlement-label">Amount Received</div>
                <div class="settlement-amount">₹<?php echo number_format($closure['amount_received'], 2); ?></div>
                <?php if ($closure['payment_method']): ?>
                <div class="settlement-received">
                    <?php echo strtoupper($closure['payment_method']); ?>
                    <?php if (!empty($closure['transaction_id'])): ?> | Ref: <?php echo $closure['transaction_id']; ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Amount in Words -->
            <div class="words-amount">
                <strong>In Words:</strong> Rupees <?php echo numberToWords(floor($closure['amount_received'])); ?> only
            </div>

            <!-- Remarks (if any) -->
            <?php if (!empty($closure['remarks'])): ?>
            <div style="background: #f7fafc; padding: 5px; border-radius: 4px; margin: 5px 0; font-size: 10px;">
                <strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($closure['remarks'])); ?>
            </div>
            <?php endif; ?>

            <!-- Footer with Signatures -->
            <div class="footer">
                <div class="signature">
                    <div class="signature-line"></div>
                    <div class="signature-text">Customer</div>
                </div>
                <div class="signature">
                    <div class="signature-line"></div>
                    <div class="signature-text">Authorized</div>
                </div>
                <div class="signature">
                    <div class="signature-line"></div>
                    <div class="signature-text">Manager</div>
                </div>
            </div>

            <!-- Terms -->
            <div class="terms">
                <p>Loan fully settled. This is a computer generated certificate. Receipt: <?php echo $closure['receipt_number']; ?> | <?php echo date('d-m-Y'); ?></p>
            </div>
        </div>
    </div>

    <script>
        // Uncomment to auto-print when page loads
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>