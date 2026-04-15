<?php
session_start();
$currentPage = 'print-bank-receipt';
$pageTitle = 'Print Bank Receipt';
require_once 'includes/db.php';
require_once 'auth_check.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt($value) {
    return number_format((float)($value ?? 0), 2);
}

$receipt = trim($_GET['receipt'] ?? '');
$paymentId = (int)($_GET['payment_id'] ?? 0);
$ledgerId = (int)($_GET['ledger_id'] ?? 0);
$loanId = (int)($_GET['loan_id'] ?? 0);
$download = (int)($_GET['download'] ?? 0);
$error = '';
$payment = null;
$company = [
    'company_name' => 'WEALTHROT',
    'company_address' => '',
    'company_phone' => '',
    'company_email' => ''
];

$companyTableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'company_settings'");
if ($companyTableCheck && mysqli_num_rows($companyTableCheck) > 0) {
    if ($companyResult = mysqli_query($conn, "SELECT * FROM company_settings ORDER BY id ASC LIMIT 1")) {
        if ($companyRow = mysqli_fetch_assoc($companyResult)) {
            $company['company_name'] = $companyRow['company_name'] ?? $company['company_name'];
            $company['company_address'] = $companyRow['company_address'] ?? ($companyRow['address'] ?? '');
            $company['company_phone'] = $companyRow['company_phone'] ?? ($companyRow['phone'] ?? '');
            $company['company_email'] = $companyRow['company_email'] ?? ($companyRow['email'] ?? '');
        }
    }
}


if ($loanId > 0 && $paymentId <= 0 && $receipt === '') {
    $loanStmt = mysqli_prepare($conn, "
        SELECT id, receipt_number
        FROM bank_loan_payments
        WHERE bank_loan_id = ?
        ORDER BY
            CASE WHEN receipt_number LIKE 'CLS-%' THEN 0 ELSE 1 END,
            payment_date DESC,
            id DESC
        LIMIT 1
    ");
    if ($loanStmt) {
        mysqli_stmt_bind_param($loanStmt, 'i', $loanId);
        mysqli_stmt_execute($loanStmt);
        $loanResult = mysqli_stmt_get_result($loanStmt);
        if ($loanRow = mysqli_fetch_assoc($loanResult)) {
            $paymentId = (int)($loanRow['id'] ?? 0);
            if (empty($receipt) && !empty($loanRow['receipt_number'])) {
                $receipt = trim((string)$loanRow['receipt_number']);
            }
        }
        mysqli_stmt_close($loanStmt);
    }
}

if ($ledgerId > 0 && $paymentId <= 0 && $receipt === '') {
    $ledgerStmt = mysqli_prepare($conn, "SELECT payment_id, reference_number FROM bank_ledger WHERE id = ? LIMIT 1");
    if ($ledgerStmt) {
        mysqli_stmt_bind_param($ledgerStmt, 'i', $ledgerId);
        mysqli_stmt_execute($ledgerStmt);
        $ledgerResult = mysqli_stmt_get_result($ledgerStmt);
        if ($ledgerRow = mysqli_fetch_assoc($ledgerResult)) {
            $paymentId = (int)($ledgerRow['payment_id'] ?? 0);
            if ($paymentId <= 0 && !empty($ledgerRow['reference_number'])) {
                $receipt = trim((string)$ledgerRow['reference_number']);
            }
        }
        mysqli_stmt_close($ledgerStmt);
    }
}

if ($receipt === '' && $paymentId <= 0) {
    $error = 'Receipt number, payment id, ledger id, or loan id is required.';
}

if ($error === '') {
    $sql = "
        SELECT
            p.*,
            l.loan_reference,
            l.loan_date,
            l.loan_amount,
            l.interest_rate,
            l.tenure_months,
            l.emi_amount AS loan_emi_amount,
            l.close_date,
            b.bank_full_name,
            b.bank_short_name,
            ba.account_holder_no,
            ba.bank_account_no,
            ba.ifsc_code,
            c.customer_name,
            c.mobile_number,
            c.email,
            u.name AS collected_by,
            emi.installment_no,
            emi.due_date,
            emi.emi_amount,
            emi.principal_amount,
            emi.interest_amount,
            ledger.transaction_type AS ledger_transaction_type,
            ledger.balance AS ledger_balance,
            ledger.reference_number AS ledger_reference,
            ledger.description AS ledger_description,
            (
                SELECT COUNT(*)
                FROM bank_loan_items li
                WHERE li.bank_loan_id = l.id
            ) AS items_count,
            (
                SELECT COALESCE(SUM(li.net_weight * COALESCE(li.quantity,1)), 0)
                FROM bank_loan_items li
                WHERE li.bank_loan_id = l.id
            ) AS total_weight
        FROM bank_loan_payments p
        LEFT JOIN bank_loans l ON p.bank_loan_id = l.id
        LEFT JOIN bank_master b ON l.bank_id = b.id
        LEFT JOIN bank_accounts ba ON l.bank_account_id = ba.id
        LEFT JOIN customers c ON l.customer_id = c.id
        LEFT JOIN users u ON p.employee_id = u.id
        LEFT JOIN bank_loan_emi emi ON p.emi_id = emi.id
        LEFT JOIN bank_ledger ledger ON ledger.payment_id = p.id
        WHERE 1 = 1
    ";

    $params = [];
    $types = '';

    if ($receipt !== '') {
        $sql .= " AND p.receipt_number = ? ";
        $params[] = $receipt;
        $types .= 's';
    } elseif ($paymentId > 0) {
        $sql .= " AND p.id = ? ";
        $params[] = $paymentId;
        $types .= 'i';
    }

    $sql .= " ORDER BY p.id DESC LIMIT 1 ";

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            $payment = mysqli_fetch_assoc($result);
            if (!$payment) {
                $error = 'Receipt not found.';
            }
        } else {
            $error = 'Unable to load receipt details.';
        }
        mysqli_stmt_close($stmt);
    } else {
        $error = 'Unable to prepare receipt query.';
    }
}

$receiptType = 'Bank Loan Receipt';
if ($payment) {
    $receiptNo = strtoupper((string)($payment['receipt_number'] ?? ''));
    if (strpos($receiptNo, 'EMI-') === 0) {
        $receiptType = 'Bank Loan EMI Receipt';
    } elseif (strpos($receiptNo, 'CLS-') === 0) {
        $receiptType = 'Bank Loan Closure Receipt';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            background: #eef2ff;
            color: #0f172a;
        }
        .page-wrap {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-primary { background: #4f46e5; color: #fff; }
        .receipt-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10);
            overflow: hidden;
        }
        .receipt-top {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 24px;
        }
        .company-name {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .receipt-type {
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .04em;
        }
        .company-meta {
            margin-top: 8px;
            line-height: 1.7;
            font-size: 13px;
            opacity: 0.95;
        }
        .receipt-body {
            padding: 24px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 18px;
            margin-bottom: 22px;
        }
        .info-box {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            background: #f8fafc;
        }
        .info-title {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 8px;
        }
        .info-value {
            font-size: 15px;
            color: #0f172a;
            font-weight: 600;
            line-height: 1.6;
        }
        .amount-panel {
            background: #eef2ff;
            border: 1px dashed #818cf8;
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            flex-wrap: wrap;
        }
        .amount-panel .label {
            font-size: 14px;
            color: #334155;
            font-weight: 600;
        }
        .amount-panel .value {
            font-size: 30px;
            font-weight: 800;
            color: #312e81;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .details-table th, .details-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
            text-align: left;
            vertical-align: top;
        }
        .details-table th {
            width: 32%;
            color: #475569;
            background: #f8fafc;
            font-weight: 700;
        }
        .footer-note {
            margin-top: 14px;
            font-size: 13px;
            color: #475569;
            line-height: 1.8;
        }
        .signature-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap: 20px;
            margin-top: 30px;
        }
        .signature-box {
            padding-top: 34px;
            border-top: 1px dashed #94a3b8;
            text-align: center;
            font-size: 13px;
            color: #475569;
            font-weight: 600;
        }
        .status-pill {
            display: inline-flex;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #dcfce7;
            color: #166534;
        }
        .error-card {
            background: #fff;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10);
            text-align: center;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page-wrap { max-width: 100%; margin: 0; padding: 0; }
            .receipt-card, .error-card { box-shadow: none; border-radius: 0; }
        }
        @media (max-width: 768px) {
            .grid-2, .signature-row { grid-template-columns: 1fr; }
            .company-name { font-size: 24px; }
            .amount-panel .value { font-size: 24px; }
            .details-table th, .details-table td { font-size: 13px; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="toolbar">
        <a href="Closed-Receipt-In-Bank.php" class="btn btn-secondary">← Back</a>
        <?php if (!$error): ?>
            <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Print Receipt</button>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="error-card">
            <div style="font-size: 40px; margin-bottom: 8px;">⚠️</div>
            <div style="font-size: 22px; font-weight: 800; margin-bottom: 8px;">Receipt unavailable</div>
            <div style="font-size: 14px; color: #64748b;"><?php echo h($error); ?></div>
        </div>
    <?php else: ?>
        <div class="receipt-card">
            <div class="receipt-top">
                <div class="company-name"><?php echo h($company['company_name'] ?: 'WEALTHROT'); ?></div>
                <div class="receipt-type"><?php echo h($receiptType); ?></div>
                <div class="company-meta">
                    <?php if (!empty($company['company_address'])): ?>
                        <div><?php echo nl2br(h($company['company_address'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <?php if (!empty($company['company_phone'])): ?>
                            Phone: <?php echo h($company['company_phone']); ?>
                        <?php endif; ?>
                        <?php if (!empty($company['company_email'])): ?>
                            <?php echo !empty($company['company_phone']) ? ' | ' : ''; ?>Email: <?php echo h($company['company_email']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="receipt-body">
                <div class="grid-2">
                    <div class="info-box">
                        <div class="info-title">Receipt Details</div>
                        <div class="info-value">
                            Receipt No: <strong><?php echo h($payment['receipt_number']); ?></strong><br>
                            Date: <strong><?php echo h(date('d-m-Y', strtotime($payment['payment_date']))); ?></strong><br>
                            Payment Mode: <strong><?php echo h(ucfirst((string)($payment['payment_method'] ?? 'cash'))); ?></strong><br>
                            Status: <span class="status-pill">Received</span>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-title">Loan / Bank</div>
                        <div class="info-value">
                            Loan Ref: <strong><?php echo h($payment['loan_reference'] ?: '-'); ?></strong><br>
                            Bank: <strong><?php echo h($payment['bank_full_name'] ?: ($payment['bank_short_name'] ?: '-')); ?></strong><br>
                            Account: <strong><?php echo h($payment['account_holder_no'] ?: ($payment['bank_account_no'] ?: '-')); ?></strong><br>
                            IFSC: <strong><?php echo h($payment['ifsc_code'] ?: '-'); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="amount-panel">
                    <div class="label">
                        Amount Received
                        <?php if (!empty($payment['installment_no'])): ?>
                            <div style="font-size: 12px; color: #475569; margin-top: 4px;">Installment #<?php echo (int)$payment['installment_no']; ?></div>
                        <?php elseif (strpos(strtoupper((string)$payment['receipt_number']), 'CLS-') === 0): ?>
                            <div style="font-size: 12px; color: #475569; margin-top: 4px;">Loan closure payment</div>
                        <?php endif; ?>
                    </div>
                    <div class="value">₹<?php echo money_fmt($payment['payment_amount']); ?></div>
                </div>

                <table class="details-table">
                    <tr>
                        <th>Customer Name</th>
                        <td><?php echo h($payment['customer_name'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Mobile Number</th>
                        <td><?php echo h($payment['mobile_number'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><?php echo h($payment['email'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Loan Date</th>
                        <td><?php echo !empty($payment['loan_date']) ? h(date('d-m-Y', strtotime($payment['loan_date']))) : '-'; ?></td>
                    </tr>
                    <tr>
                        <th>Original Loan Amount</th>
                        <td>₹<?php echo money_fmt($payment['loan_amount']); ?></td>
                    </tr>
                    <tr>
                        <th>Interest Rate</th>
                        <td><?php echo money_fmt($payment['interest_rate']); ?>%</td>
                    </tr>
                    <tr>
                        <th>Tenure / EMI</th>
                        <td>
                            <?php echo (int)($payment['tenure_months'] ?? 0); ?> months
                            <?php if ((float)($payment['loan_emi_amount'] ?? 0) > 0): ?>
                                | EMI ₹<?php echo money_fmt($payment['loan_emi_amount']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>EMI Detail</th>
                        <td>
                            <?php if (!empty($payment['installment_no'])): ?>
                                EMI #<?php echo (int)$payment['installment_no']; ?>
                                <?php if (!empty($payment['due_date'])): ?>
                                    | Due <?php echo h(date('d-m-Y', strtotime($payment['due_date']))); ?>
                                <?php endif; ?>
                                <?php if ((float)($payment['principal_amount'] ?? 0) > 0 || (float)($payment['interest_amount'] ?? 0) > 0): ?>
                                    <br>Principal ₹<?php echo money_fmt($payment['principal_amount']); ?> | Interest ₹<?php echo money_fmt($payment['interest_amount']); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Not linked to a single EMI row
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Jewelry Items</th>
                        <td><?php echo (int)($payment['items_count'] ?? 0); ?> item(s), Total Weight <?php echo money_fmt($payment['total_weight']); ?> g</td>
                    </tr>
                    <tr>
                        <th>Collected By</th>
                        <td><?php echo h($payment['collected_by'] ?: '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Ledger Entry</th>
                        <td>
                            Type: <?php echo h(ucfirst((string)($payment['ledger_transaction_type'] ?? '-'))); ?>
                            <?php if ($payment['ledger_balance'] !== null && $payment['ledger_balance'] !== ''): ?>
                                | Balance ₹<?php echo money_fmt($payment['ledger_balance']); ?>
                            <?php endif; ?>
                            <?php if (!empty($payment['ledger_reference'])): ?>
                                <br>Reference: <?php echo h($payment['ledger_reference']); ?>
                            <?php endif; ?>
                            <?php if (!empty($payment['ledger_description'])): ?>
                                <br><?php echo h($payment['ledger_description']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Remarks</th>
                        <td><?php echo nl2br(h($payment['remarks'] ?: '-')); ?></td>
                    </tr>
                </table>

                <div class="footer-note">
                    This is a computer-generated receipt for bank loan payment entry saved in <strong>bank_loan_payments</strong>.
                    Please keep this receipt for future reference.
                </div>

                <div class="signature-row">
                    <div class="signature-box">Customer Signature</div>
                    <div class="signature-box">Authorized Signature</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
