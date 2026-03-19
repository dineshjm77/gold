<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set UTF-8 for database connection and output
mysqli_set_charset($conn, 'utf8mb4');
header('Content-Type: text/html; charset=utf-8');

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}   

// Get parameters
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'preview'; // preview, download, print

if ($loan_id <= 0) {
    header('Location: close-loan-receipt-notes.php');
    exit();
}

// Get loan details with customer information - only closed loans
$loan_query = "SELECT l.*, 
               c.customer_name, c.guardian_type, c.guardian_name, 
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

// Get company settings from branches
$company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
$company_result = mysqli_query($conn, $company_query);
$company = mysqli_fetch_assoc($company_result);

if (!$company) {
    $company = [
        'branch_name' => 'DHARMAPURI',
        'address' => 'DPI',
        'phone' => '4575848575',
        'email' => '',
        'website' => '',
        'logo_path' => '',
        'qr_path' => ''
    ];
}

// Calculate loan details
$principal = floatval($loan['loan_amount']);
$interest_rate = floatval($loan['interest_amount']);
$receipt_charge = floatval($loan['receipt_charge'] ?? 0);
$discount = floatval($loan['discount'] ?? 0);
$round_off = floatval($loan['round_off'] ?? 0);
$d_namuna = intval($loan['d_namuna'] ?? 0);
$others = intval($loan['others'] ?? 0);

// Calculate interest details
$loan_duration_days = intval($loan['loan_duration_days'] ?? 0);
$loan_duration_months = floor($loan_duration_days / 30);
$loan_duration_remaining_days = $loan_duration_days % 30;

$monthly_interest = ($principal * $interest_rate) / 100;
$daily_interest = $monthly_interest / 30;
$total_interest_calculated = $daily_interest * $loan_duration_days;

// Get final payment (last payment which closed the loan)
$final_payment_query = "SELECT * FROM payments 
                        WHERE loan_id = ? 
                        ORDER BY payment_date DESC, id DESC 
                        LIMIT 1";
$stmt = mysqli_prepare($conn, $final_payment_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$final_payment_result = mysqli_stmt_get_result($stmt);
$final_payment = mysqli_fetch_assoc($final_payment_result);

// Calculate final settlement
$total_principal_paid = $total_paid_principal;
$total_interest_paid = $total_paid_interest;
$total_amount_paid = $total_principal_paid + $total_interest_paid + $receipt_charge - $discount + $round_off;

// Format address
$customer_address = trim($loan['door_no'] . ' ' . $loan['street_name'] . ', ' . 
                     $loan['location'] . ', ' . $loan['district'] . ' - ' . 
                     $loan['pincode']);

// Function to convert number to words (English)
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
                    $amount_words = numToWordHelper($remainder, $words) . ' Hundred ' . $amount_words;
                } elseif ($j == 2) {
                    $amount_words = numToWordHelper($remainder, $words) . ' Thousand ' . $amount_words;
                } elseif ($j == 3) {
                    $amount_words = numToWordHelper($remainder, $words) . ' Lakh ' . $amount_words;
                } elseif ($j == 4) {
                    $amount_words = numToWordHelper($remainder, $words) . ' Crore ' . $amount_words;
                } else {
                    $amount_words = numToWordHelper($remainder, $words) . ' ' . $amount_words;
                }
            }
            $num = floor($num / 100);
            $j++;
        }
    }
    
    if ($decimal > 0) {
        $amount_words .= ' and ' . numToWordHelper($decimal, $words) . ' Paise';
    }
    
    return trim($amount_words) . ' Only';
}

function numToWordHelper($num, $words) {
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

// Check if logo exists
$logo_path = '';
$logo_exists = false;
if (!empty($company['logo_path']) && file_exists($company['logo_path'])) {
    $logo_path = $company['logo_path'];
    $logo_exists = true;
} elseif (file_exists('assets/logo.png')) {
    $logo_path = 'assets/logo.png';
    $logo_exists = true;
} elseif (file_exists('../assets/logo.png')) {
    $logo_path = '../assets/logo.png';
    $logo_exists = true;
}

// Get logo as base64 for embedding
$logo_base64 = '';
if ($logo_exists) {
    $image_data = file_get_contents($logo_path);
    $image_type = pathinfo($logo_path, PATHINFO_EXTENSION);
    $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
}

// Check if customer photo exists
$customer_photo_path = '';
$customer_photo_exists = false;
if (!empty($loan['customer_photo']) && file_exists($loan['customer_photo'])) {
    $customer_photo_path = $loan['customer_photo'];
    $customer_photo_exists = true;
}

// Get customer photo as base64 for embedding
$customer_photo_base64 = '';
if ($customer_photo_exists) {
    $image_data = file_get_contents($customer_photo_path);
    $image_type = pathinfo($customer_photo_path, PATHINFO_EXTENSION);
    $customer_photo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
}

// ============================================
// GENERATE HTML CONTENT FOR ONE RECEIPT COPY (COMPACT VERSION)
// ============================================
function generateReceiptHTML($company, $loan, $items, $payments, $customer_address, 
                              $principal, $interest_rate, $receipt_charge, $discount, $round_off,
                              $d_namuna, $others, $loan_duration_days, $loan_duration_months,
                              $loan_duration_remaining_days, $total_interest_calculated, 
                              $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
                              $customer_photo_base64, $customer_photo_exists,
                              $copy_type = 'ORIGINAL') {
    
    // Limit items to prevent table overflow
    $max_items = 3; // Limited to fit two copies
    $display_items = array_slice($items, 0, $max_items);
    $has_more_items = count($items) > $max_items;
    
    // Limit payments
    $max_payments = 2; // Limited to fit two copies
    $display_payments = array_slice($payments, -$max_payments);
    $has_more_payments = count($payments) > $max_payments;
    
    $html = '<div class="receipt-wrapper">
        <div class="copy-badge">' . $copy_type . ' COPY</div>
        
        <!-- Header -->
        <div class="header">
            <div class="logo-container">';
    if ($logo_exists && !empty($logo_base64)) {
        $html .= '<img src="' . $logo_base64 . '" class="logo" alt="Logo">';
    } else {
        $html .= '<div class="no-logo">Logo</div>';
    }
    $html .= '</div>
            <div class="header-content">
                <div class="company-name">' . htmlspecialchars($company['branch_name']) . '</div>
                <div class="receipt-title">LOAN CLOSURE RECEIPT</div>
                <div class="receipt-subtitle">Receipt: ' . htmlspecialchars($loan['receipt_number']) . ' | Closed: ' . date('d-m-Y', strtotime($loan['close_date'])) . '</div>
            </div>
        </div>';
    
    // Customer Details Section
    $html .= '<div class="customer-section">
            <div class="section-title">CUSTOMER DETAILS</div>
            <div class="customer-photo-row">';
    
    // Customer Photo
    $html .= '<div class="photo-container">';
    if ($customer_photo_exists && !empty($customer_photo_base64)) {
        $html .= '<img src="' . $customer_photo_base64 . '" class="customer-photo" alt="Photo">';
    } else {
        $html .= '<div class="no-photo">No Photo</div>';
    }
    $html .= '</div>';
    
    // Customer Info
    $html .= '<div class="customer-info">
                <div class="info-row"><span class="info-label">Name:</span> <span class="tamil-text">' . htmlspecialchars($loan['customer_name']) . '</span></div>
                <div class="info-row"><span class="info-label">Guardian:</span> <span class="tamil-text">' . ($loan['guardian_type'] ? $loan['guardian_type'] . '. ' : '') . htmlspecialchars($loan['guardian_name']) . '</span></div>
                <div class="info-row"><span class="info-label">Mobile:</span> ' . htmlspecialchars($loan['mobile_number']) . '</div>
                <div class="info-row"><span class="info-label">Aadhaar:</span> ' . (isset($loan['aadhaar_number']) ? $loan['aadhaar_number'] : 'N/A') . '</div>
                <div class="info-row"><span class="info-label">Address:</span> <span class="tamil-text">' . htmlspecialchars($customer_address) . '</span></div>
            </div>
        </div>';
    
    // Loan Details Section
    $html .= '<div class="loan-section">
            <div class="section-title">LOAN DETAILS</div>
            <div class="loan-info-grid">
                <div class="info-row"><span class="info-label">Receipt Date:</span> ' . date('d-m-Y', strtotime($loan['receipt_date'])) . '</div>
                <div class="info-row"><span class="info-label">Close Date:</span> ' . date('d-m-Y', strtotime($loan['close_date'])) . '</div>
                <div class="info-row"><span class="info-label">Duration:</span> ' . $loan_duration_days . ' days</div>
                <div class="info-row"><span class="info-label">Loan Amount:</span> ₹ ' . number_format($principal, 2) . '</div>
                <div class="info-row"><span class="info-label">Interest Rate:</span> ' . $interest_rate . '%</div>
            </div>
        </div>';
    
    // Jewelry Items
    $html .= '<div class="items-section">
            <div class="section-title">JEWELRY ITEMS</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item (Tamil)</th>
                        <th>Karat</th>
                        <th>Wt(g)</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>';
    
    if (count($display_items) > 0) {
        $sno = 1;
        foreach ($display_items as $item) {
            $html .= '<tr>
                        <td>' . $sno++ . '</td>
                        <td class="tamil-text">' . htmlspecialchars($item['jewel_name']) . '</td>
                        <td>' . $item['karat'] . 'K</td>
                        <td class="text-right">' . number_format($item['net_weight'], 2) . '</td>
                        <td class="text-center">' . $item['quantity'] . '</td>
                    </tr>';
        }
        
        if ($has_more_items) {
            $html .= '<tr><td colspan="5" class="more-note">+ ' . (count($items) - $max_items) . ' more items</td></tr>';
        }
    } else {
        $html .= '<tr><td colspan="5" class="text-center">No items</td></tr>';
    }
    
    $html .= '<tr class="total-row">
                <td colspan="3" class="text-right"><strong>Total Weight:</strong></td>
                <td class="text-right"><strong>' . number_format($total_weight, 2) . ' g</strong></td>
                <td></td>
            </tr>
            </tbody>
        </table>';
    
    // Payment Summary
    $html .= '<div class="summary-section">
            <div class="section-title">PAYMENT SUMMARY</div>
            <div class="summary-box">
                <div class="summary-row"><span>Principal:</span> <span>₹ ' . number_format($principal, 2) . '</span></div>
                <div class="summary-row"><span>Interest:</span> <span>₹ ' . number_format($total_interest_calculated, 2) . '</span></div>
                <div class="summary-row"><span>Receipt Charge:</span> <span>₹ ' . number_format($receipt_charge, 2) . '</span></div>';
    
    if ($discount > 0) {
        $html .= '<div class="summary-row"><span>Discount:</span> <span>- ₹ ' . number_format($discount, 2) . '</span></div>';
    }
    
    $html .= '<div class="summary-row total"><span>Total Paid:</span> <span>₹ ' . number_format($total_amount_paid, 2) . '</span></div>
            </div>
        </div>';
    
    // Payment History
    if (!empty($display_payments)) {
        $html .= '<div class="history-section">
                <div class="section-title">PAYMENT HISTORY</div>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($display_payments as $payment) {
            $html .= '<tr>
                        <td>' . date('d-m-Y', strtotime($payment['payment_date'])) . '</td>
                        <td class="text-right">₹ ' . number_format($payment['total_amount'], 2) . '</td>
                    </tr>';
        }
        
        if ($has_more_payments) {
            $html .= '<tr><td colspan="2" class="more-note">+ ' . (count($payments) - $max_payments) . ' more</td></tr>';
        }
        
        $html .= '</tbody>
            </table>
        </div>';
    }
    
    // Amount in Words
    $html .= '<div class="amount-words">
            <strong>Amount in Words:</strong> ' . numberToWords($total_amount_paid) . '
        </div>';
    
    // Settlement Note
    $html .= '<div class="settlement-note">
            ✓ LOAN SETTLED on ' . date('d-m-Y', strtotime($loan['close_date'])) . ' ✓
        </div>';
    
    // Signature
    $html .= '<div class="signature-section">
            <div class="signature-box">Customer Signature</div>
            <div class="signature-box">Authorized Signatory</div>
        </div>';
    
    // Footer
    $html .= '<div class="footer">
            ' . date('d-m-Y H:i') . ' | ' . $copy_type . ' COPY
        </div>
    </div>';
    
    return $html;
}

// ============================================
// GENERATE HTML CONTENT FOR TWO COPIES ON ONE PAGE
// ============================================
function generateTwoCopyHTML($company, $loan, $items, $payments, $customer_address, 
                              $principal, $interest_rate, $receipt_charge, $discount, $round_off,
                              $d_namuna, $others, $loan_duration_days, $loan_duration_months,
                              $loan_duration_remaining_days, $total_interest_calculated, 
                              $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
                              $customer_photo_base64, $customer_photo_exists) {
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Loan Closure Receipt - Two Copies</title>
    <style>
        @page {
            size: A4;
            margin: 5mm;
        }
        
        body {
            font-family: "Latha", "Tamil MN", "Lohit Tamil", "Noto Sans Tamil", Arial, sans-serif;
            font-size: 8pt;
            line-height: 1.2;
            margin: 0;
            padding: 0;
            background: white;
        }
        
        .page {
            width: 100%;
            min-height: 287mm;
            display: flex;
            flex-direction: column;
        }
        
        .receipt-wrapper {
            border: 1px solid #ddd;
            padding: 4px;
            margin-bottom: 5px;
            page-break-inside: avoid;
        }
        
        .copy-badge {
            text-align: right;
            font-size: 7pt;
            font-weight: bold;
            color: #666;
            border-bottom: 1px dashed #ccc;
            margin-bottom: 3px;
            padding-bottom: 2px;
        }
        
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }
        
        .logo-container {
            width: 30px;
            height: 30px;
            margin-right: 5px;
        }
        
        .logo {
            width: 30px;
            height: 30px;
            object-fit: contain;
        }
        
        .no-logo {
            width: 30px;
            height: 30px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6pt;
            color: #999;
        }
        
        .header-content {
            flex: 1;
            text-align: center;
        }
        
        .company-name {
            font-size: 10pt;
            font-weight: bold;
        }
        
        .receipt-title {
            font-size: 9pt;
            color: #48bb78;
            font-weight: bold;
        }
        
        .receipt-subtitle {
            font-size: 6pt;
            color: #666;
        }
        
        .section-title {
            font-size: 8pt;
            font-weight: bold;
            background: #f0f0f0;
            padding: 2px 4px;
            margin: 4px 0 2px 0;
            border-left: 3px solid #48bb78;
        }
        
        .customer-section {
            margin-bottom: 4px;
        }
        
        .customer-photo-row {
            display: flex;
            gap: 5px;
        }
        
        .photo-container {
            width: 45px;
            height: 45px;
            border: 1px solid #ddd;
        }
        
        .customer-photo {
            width: 43px;
            height: 43px;
            object-fit: cover;
        }
        
        .no-photo {
            width: 43px;
            height: 43px;
            background: #f9f9f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6pt;
            color: #999;
        }
        
        .customer-info {
            flex: 1;
            font-size: 7pt;
        }
        
        .loan-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2px 5px;
            font-size: 7pt;
            margin-bottom: 4px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 1px;
        }
        
        .info-label {
            width: 55px;
            font-weight: bold;
            color: #555;
        }
        
        .items-table, .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5pt;
            margin-bottom: 4px;
        }
        
        .items-table th, .history-table th {
            background: #f5f5f5;
            padding: 2px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .items-table td, .history-table td {
            padding: 1px 2px;
            border: 1px solid #ddd;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total-row {
            font-weight: bold;
            background: #f9f9f9;
        }
        
        .summary-box {
            border: 1px solid #ddd;
            padding: 3px;
            margin-bottom: 4px;
            font-size: 7pt;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 1px 0;
            border-bottom: 1px dashed #eee;
        }
        
        .summary-row.total {
            border-top: 1px solid #48bb78;
            border-bottom: none;
            margin-top: 2px;
            padding-top: 2px;
            font-weight: bold;
        }
        
        .amount-words {
            font-size: 6.5pt;
            background: #ebf4ff;
            padding: 2px 4px;
            margin: 4px 0;
            border-left: 3px solid #48bb78;
        }
        
        .settlement-note {
            text-align: center;
            font-size: 7pt;
            font-weight: bold;
            color: #48bb78;
            margin: 4px 0;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 6px;
            font-size: 6pt;
        }
        
        .signature-box {
            width: 45%;
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 2px;
            margin-top: 5px;
        }
        
        .footer {
            text-align: center;
            font-size: 5pt;
            color: #999;
            margin-top: 3px;
            padding-top: 2px;
            border-top: 1px solid #eee;
        }
        
        .more-note {
            font-size: 5pt;
            color: #999;
            font-style: italic;
            text-align: center;
        }
        
        .tamil-text {
            font-family: "Latha", "Tamil MN", "Lohit Tamil", "Noto Sans Tamil", sans-serif;
        }
        
        .cut-line {
            text-align: center;
            margin: 2px 0;
            color: #999;
            font-size: 7pt;
        }
        
        .cut-line:before, .cut-line:after {
            content: "";
            display: inline-block;
            width: 35%;
            height: 1px;
            background: #ccc;
            vertical-align: middle;
            margin: 0 3px;
        }
        
        @media print {
            .cut-line {
                opacity: 0.3;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- First Copy -->
        ' . generateReceiptHTML($company, $loan, $items, $payments, $customer_address,
                              $principal, $interest_rate, $receipt_charge, $discount, $round_off,
                              $d_namuna, $others, $loan_duration_days, $loan_duration_months,
                              $loan_duration_remaining_days, $total_interest_calculated, 
                              $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
                              $customer_photo_base64, $customer_photo_exists, 'ORIGINAL') . '
        
        <!-- Cut Line -->
        <div class="cut-line">✂ - - - - - CUT HERE - - - - - ✂</div>
        
        <!-- Second Copy -->
        ' . generateReceiptHTML($company, $loan, $items, $payments, $customer_address,
                              $principal, $interest_rate, $receipt_charge, $discount, $round_off,
                              $d_namuna, $others, $loan_duration_days, $loan_duration_months,
                              $loan_duration_remaining_days, $total_interest_calculated, 
                              $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
                              $customer_photo_base64, $customer_photo_exists, 'DUPLICATE') . '
    </div>
</body>
</html>';
    
    return $html;
}

// ============================================
// HANDLE DOWNLOAD/PRINT ACTIONS
// ============================================
if ($action === 'download' || $action === 'print') {
    // Check if mPDF is installed
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            
            // Create temp directory if not exists
            $tempDir = __DIR__ . '/tmp';
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            // Generate HTML content with two copies
            $html = generateTwoCopyHTML(
                $company, $loan, $items, $payments, $customer_address,
                $principal, $interest_rate, $receipt_charge, $discount, $round_off,
                $d_namuna, $others, $loan_duration_days, $loan_duration_months,
                $loan_duration_remaining_days, $total_interest_calculated,
                $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
                $customer_photo_base64, $customer_photo_exists
            );
            
            // Create mPDF instance with UTF-8 support
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8-s',
                'format' => 'A4',
                'margin_left' => 5,
                'margin_right' => 5,
                'margin_top' => 5,
                'margin_bottom' => 5,
                'tempDir' => $tempDir,
                'default_font_size' => 8,
                'default_font' => 'freeserif'
            ]);
            
            $mpdf->WriteHTML($html);
            
            // Set appropriate headers
            $filename = 'Loan_Closure_Receipt_' . $loan['receipt_number'] . '_TwoCopies.pdf';
            
            if ($action === 'download') {
                $mpdf->Output($filename, 'D');
            } else {
                $mpdf->Output($filename, 'I');
            }
            exit();
            
        } catch (Exception $e) {
            error_log("mPDF Error: " . $e->getMessage());
            $action = 'preview';
        }
    } else {
        $action = 'preview';
    }
}

// ============================================
// HTML PREVIEW WITH TWO COPIES
// ============================================
$preview_html = generateTwoCopyHTML(
    $company, $loan, $items, $payments, $customer_address,
    $principal, $interest_rate, $receipt_charge, $discount, $round_off,
    $d_namuna, $others, $loan_duration_days, $loan_duration_months,
    $loan_duration_remaining_days, $total_interest_calculated,
    $total_amount_paid, $total_weight, $logo_base64, $logo_exists,
    $customer_photo_base64, $customer_photo_exists
);
?>
<!DOCTYPE html>
<html lang="ta">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Closure Receipt - Two Copies - <?php echo htmlspecialchars($loan['receipt_number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Latha", "Tamil MN", "Lohit Tamil", "Noto Sans Tamil", Arial, sans-serif;
            background: #f8fafc;
            padding: 15px;
        }

        .preview-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .action-bar {
            background: white;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
        }

        .btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }

        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-secondary { background: #a0aec0; color: white; }
        .btn-warning { background: #ecc94b; color: #744210; }

        .receipt-preview {
            background: white;
            border-radius: 6px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 10px;
        }

        .note-box {
            background: #ebf8ff;
            border-left: 3px solid #4299e1;
            padding: 8px 12px;
            margin-bottom: 10px;
            border-radius: 4px;
            font-size: 12px;
        }

        @media print {
            .action-bar, .btn, .note-box, .no-print {
                display: none !important;
            }
            .receipt-preview {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <div class="preview-container">
        <!-- Action Bar -->
        <div class="action-bar">
            <div>
                <strong>Receipt: <?php echo htmlspecialchars($loan['receipt_number']); ?></strong> - Two Copies on One Page
            </div>
            <div class="action-buttons">
                <a href="close-loan-receipt-notes.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                
                <?php if (file_exists(__DIR__ . '/vendor/autoload.php')): ?>
                <a href="?id=<?php echo $loan_id; ?>&action=download" class="btn btn-success">
                    <i class="bi bi-file-pdf"></i> PDF
                </a>
                <?php endif; ?>
                
                <button class="btn btn-warning" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <!-- Note -->
        <div class="note-box">
            <i class="bi bi-info-circle"></i> This will print as ONE page with TWO receipts (Original & Duplicate)
        </div>

        <!-- Receipt Preview -->
        <div class="receipt-preview">
            <?php echo $preview_html; ?>
        </div>
    </div>
</body>
</html>
<?php
exit();
?>