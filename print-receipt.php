<?php
session_start();
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

// Get parameters
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'preview';

if ($loan_id <= 0) {
    header('Location: index.php');
    exit();
}

// Set UTF-8 for database connection
mysqli_set_charset($conn, 'utf8mb4');

// Get loan details with customer information
$loan_query = "SELECT l.*, 
               c.customer_name, c.guardian_type, c.guardian_name, 
               c.mobile_number, c.whatsapp_number, c.email,
               c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
               c.location, c.district, c.pincode, c.post, c.taluk,
               c.aadhaar_number, c.customer_photo,
               u.name as employee_name,
               DATEDIFF(NOW(), l.receipt_date) as days_old
               FROM loans l 
               JOIN customers c ON l.customer_id = c.id 
               JOIN users u ON l.employee_id = u.id 
               WHERE l.id = ?";

$stmt = mysqli_prepare($conn, $loan_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$loan_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($loan_result) == 0) {
    header('Location: index.php');
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
$total_gross = 0;
$total_net = 0;
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
    $total_gross += ($item['gross_weight'] ?? $item['net_weight']) * $item['quantity'];
    $total_net += $item['net_weight'] * $item['quantity'];
}

// Get payment history
$payments_query = "SELECT p.*, u.name as employee_name 
                   FROM payments p 
                   JOIN users u ON p.employee_id = u.id 
                   WHERE p.loan_id = ? 
                   ORDER BY p.payment_date DESC";
$stmt = mysqli_prepare($conn, $payments_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$payments_result = mysqli_stmt_get_result($stmt);
$payments = [];
while ($payment = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment;
}

// Get company settings
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
$process_charge = floatval($loan['process_charge'] ?? 0);
$appraisal_charge = floatval($loan['appraisal_charge'] ?? 0);
$discount = floatval($loan['discount'] ?? 0);
$round_off = floatval($loan['round_off'] ?? 0);
$d_namuna = intval($loan['d_namuna'] ?? 0);
$others = intval($loan['others'] ?? 0);
$days = max(0, intval($loan['days_old']));

$monthly_interest = ($principal * $interest_rate) / 100;
$daily_interest = $monthly_interest / 30;
$total_interest = $daily_interest * $days;

// Get total payments
$total_paid_query = "SELECT SUM(principal_amount) as total_principal, 
                            SUM(interest_amount) as total_interest,
                            COUNT(*) as payment_count
                     FROM payments WHERE loan_id = ?";
$stmt = mysqli_prepare($conn, $total_paid_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$paid_total = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$paid_principal = floatval($paid_total['total_principal'] ?? 0);
$paid_interest = floatval($paid_total['total_interest'] ?? 0);

$remaining_principal = $principal - $paid_principal;
$remaining_interest = $total_interest - $paid_interest;
$total_payable = $remaining_principal + $remaining_interest + $process_charge + $appraisal_charge - $discount + $round_off;

// Format address
$customer_address = trim(implode(', ', array_filter([
    $loan['door_no'],
    $loan['house_name'],
    $loan['street_name'],
    $loan['street_name1'],
    $loan['landmark'],
    $loan['location'],
    $loan['district']
]))) . ($loan['pincode'] ? ' - ' . $loan['pincode'] : '');

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

// Format date
function formatReceiptDate($date) {
    if ($date == '0000-00-00' || empty($date)) {
        return 'N/A';
    }
    return date('d-m-Y', strtotime($date));
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

// Get logo as base64
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

// Get customer photo as base64
$customer_photo_base64 = '';
if ($customer_photo_exists) {
    $image_data = file_get_contents($customer_photo_path);
    $image_type = pathinfo($customer_photo_path, PATHINFO_EXTENSION);
    $customer_photo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
}

// Process jewelry photos
$jewel_photos = [];
foreach ($items as $index => $item) {
    if (!empty($item['photo_path']) && file_exists($item['photo_path'])) {
        $image_data = file_get_contents($item['photo_path']);
        $image_type = pathinfo($item['photo_path'], PATHINFO_EXTENSION);
        $jewel_photos[$index] = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
    } else {
        $jewel_photos[$index] = null;
    }
}

$receipt_date = formatReceiptDate($loan['receipt_date']);

// Build jewelry items HTML for small receipt
$jewelry_html_small = '';
if (count($items) > 0) {
    $sno = 1;
    foreach ($items as $index => $item) {
        $photo_html = '';
        if (isset($jewel_photos[$index]) && $jewel_photos[$index]) {
            $photo_html = '<img src="' . $jewel_photos[$index] . '" style="width:25px;height:25px;object-fit:cover;border:1px solid #48bb78;">';
        } else {
            $photo_html = '<div style="width:25px;height:25px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:7px;">NO</div>';
        }
        
        $jewelry_html_small .= '<tr>
            <td style="border:1px solid #ddd;padding:3px;text-align:center;">' . $sno++ . '</td>
            <td style="border:1px solid #ddd;padding:3px;font-family:Latha,\'Tamil MN\',sans-serif;">' . htmlspecialchars($item['jewel_name']) . '</td>
            <td style="border:1px solid #ddd;padding:3px;text-align:right;">' . number_format($item['gross_weight'] ?? $item['net_weight'], 2) . '</td>
            <td style="border:1px solid #ddd;padding:3px;text-align:right;">' . number_format($item['net_weight'], 2) . '</td>
            <td style="border:1px solid #ddd;padding:3px;text-align:center;">' . $photo_html . '</td>
        </tr>';
    }
} else {
    $jewelry_html_small = '<tr><td colspan="5" style="border:1px solid #ddd;padding:5px;text-align:center;">No items</td></tr>';
}

// Build jewelry items HTML for full receipt
$jewelry_html_full = '';
if (count($items) > 0) {
    $sno = 1;
    foreach ($items as $index => $item) {
        $photo_html = '';
        if (isset($jewel_photos[$index]) && $jewel_photos[$index]) {
            $photo_html = '<img src="' . $jewel_photos[$index] . '" style="width:35px;height:35px;object-fit:cover;border:1px solid #48bb78;">';
        } else {
            $photo_html = '<div style="width:35px;height:35px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">NO</div>';
        }
        
        $jewelry_html_full .= '<tr>
            <td style="border:1px solid #ddd;padding:5px;text-align:center;">' . $sno++ . '</td>
            <td style="border:1px solid #ddd;padding:5px;font-family:Latha,\'Tamil MN\',sans-serif;">' . htmlspecialchars($item['jewel_name']) . '</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:center;">' . $item['karat'] . 'K</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:right;">' . number_format($item['gross_weight'] ?? $item['net_weight'], 2) . '</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:right;">' . number_format($item['net_weight'], 2) . '</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:center;">' . $item['quantity'] . '</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:center;">' . $photo_html . '</td>
        </tr>';
    }
} else {
    $jewelry_html_full = '<tr><td colspan="7" style="border:1px solid #ddd;padding:10px;text-align:center;">No items</td></tr>';
}

// Build payment history HTML
$payment_html = '';
if (count($payments) > 0) {
    $display_payments = array_slice($payments, 0, 3);
    foreach ($display_payments as $payment) {
        $payment_html .= '<tr>
            <td style="border:1px solid #ddd;padding:5px;">' . date('d-m-Y', strtotime($payment['payment_date'])) . '</td>
            <td style="border:1px solid #ddd;padding:5px;">' . $payment['receipt_number'] . '</td>
            <td style="border:1px solid #ddd;padding:5px;text-align:right;">₹ ' . number_format($payment['total_amount'], 2) . '</td>
        </tr>';
    }
    if (count($payments) > 3) {
        $payment_html .= '<tr><td colspan="3" style="border:1px solid #ddd;padding:5px;text-align:center;font-style:italic;">+ ' . (count($payments) - 3) . ' more payments</td></tr>';
    }
}

// Create the complete HTML
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Loan Receipt - ' . htmlspecialchars($loan['receipt_number']) . '</title>
    <style>
        @page { size: A4; margin: 5mm; }
        body { font-family: Arial, "Latha", "Tamil MN", sans-serif; font-size: 10pt; margin: 0; padding: 0; background: white; }
        .page { width: 100%; }
        .page-break { page-break-after: always; }
        .receipt-box { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; }
        .copy-badge { text-align: right; font-size: 12pt; font-weight: bold; color: #48bb78; border-bottom: 2px solid #48bb78; margin-bottom: 10px; padding-bottom: 5px; }
        .header { display: flex; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .logo { width: 80px; height: 80px; margin-right: 15px; }
        .logo img { width: 80px; height: 80px; object-fit: contain; }
        .header-content { flex: 1; text-align: center; }
        .company-name { font-size: 18pt; font-weight: bold; color: #2d3748; }
        .receipt-title { font-size: 16pt; color: #48bb78; font-weight: bold; margin: 5px 0; }
        .section-title { font-size: 12pt; font-weight: bold; background: #f0f0f0; padding: 5px 10px; margin: 10px 0 5px; border-left: 4px solid #48bb78; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background: #f5f5f5; padding: 5px; border: 1px solid #ddd; text-align: left; }
        td { padding: 5px; border: 1px solid #ddd; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { font-weight: bold; background: #f0f9ff; }
        .amount-words { font-size: 10pt; background: #ebf4ff; padding: 8px 12px; margin: 10px 0; border-left: 4px solid #48bb78; }
        .signature { display: flex; justify-content: space-between; margin: 20px 0 10px; }
        .signature-box { width: 45%; text-align: center; border-top: 1px solid #000; padding-top: 8px; }
        .footer { text-align: center; font-size: 8pt; color: #999; margin-top: 10px; padding-top: 5px; border-top: 1px solid #eee; }
        .cut-line { text-align: center; margin: 15px 0; color: #999; font-size: 10pt; font-weight: bold; }
        .cut-line:before, .cut-line:after { content: ""; display: inline-block; width: 30%; height: 2px; background: #ccc; vertical-align: middle; margin: 0 10px; }
        .small-receipt { font-size: 9pt; margin-bottom: 20px; padding: 12px; }
        .small-receipt table { font-size: 8pt; }
        .small-receipt .header { margin-bottom: 10px; }
        .small-receipt .customer-info { font-size: 8pt; }
        .badge { display: inline-block; background: #48bb78; color: white; padding: 2px 8px; border-radius: 12px; font-size: 9pt; margin: 0 3px; }
        .tamil-text { font-family: "Latha", "Tamil MN", "Lohit Tamil", "Noto Sans Tamil", sans-serif; }
        .page2-container { min-height: 280mm; display: flex; flex-direction: column; justify-content: space-between; }
        .receipt-wrapper { margin-bottom: 25px; }
    </style>
</head>
<body>
    <div class="page">
        <!-- PAGE 1: FULL A4 RECEIPT -->
        <div class="receipt-box">
            <div class="copy-badge">ORIGINAL COPY</div>
            
            <div class="header">
                <div class="logo">' . ($logo_exists ? '<img src="' . $logo_base64 . '">' : '<div style="width:80px;height:80px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">LOGO</div>') . '</div>
                <div class="header-content">
                    <div class="company-name">' . htmlspecialchars($company['branch_name']) . '</div>
                    <div class="receipt-title">LOAN RECEIPT</div>
                    <div>' . htmlspecialchars($loan['receipt_number']) . ' | ' . $receipt_date . '</div>
                    ' . ($d_namuna || $others ? '<div>' . ($d_namuna ? '<span class="badge">D-NAMUNA</span>' : '') . ($others ? '<span class="badge">OTHERS</span>' : '') . '</div>' : '') . '
                </div>
            </div>
            
            <div class="section-title">CUSTOMER DETAILS</div>
            <table>
                <tr><td style="width:100px;"><strong>Name:</strong></td><td>' . htmlspecialchars($loan['customer_name']) . '</td></tr>
                <tr><td><strong>Guardian:</strong></td><td>' . ($loan['guardian_type'] ? $loan['guardian_type'] . '. ' : '') . htmlspecialchars($loan['guardian_name']) . '</td></tr>
                <tr><td><strong>Mobile:</strong></td><td>' . htmlspecialchars($loan['mobile_number']) . '</td></tr>
                <tr><td><strong>Address:</strong></td><td>' . htmlspecialchars($customer_address) . '</td></tr>
            </table>
            
            <div class="section-title">LOAN DETAILS</div>
            <table>
                <tr>
                    <td><strong>Gross Weight:</strong></td><td>' . number_format($loan['gross_weight'], 2) . ' g</td>
                    <td><strong>Net Weight:</strong></td><td>' . number_format($loan['net_weight'], 2) . ' g</td>
                </tr>
                <tr>
                    <td><strong>Loan Amount:</strong></td><td>₹ ' . number_format($loan['loan_amount'], 2) . '</td>
                    <td><strong>Interest Rate:</strong></td><td>' . $loan['interest_amount'] . '%</td>
                </tr>
                <tr>
                    <td><strong>Process Charge:</strong></td><td>₹ ' . number_format($process_charge, 2) . '</td>
                    <td><strong>Appraisal Charge:</strong></td><td>₹ ' . number_format($appraisal_charge, 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Days:</strong></td><td>' . $days . ' days</td>
                    <td></td><td></td>
                </tr>
            </table>
            
            <div class="section-title">JEWELRY ITEMS</div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Karat</th>
                        <th>Gross (g)</th>
                        <th>Net (g)</th>
                        <th>Qty</th>
                        <th>Photo</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $jewelry_html_full . '
                    <tr class="total-row">
                        <td colspan="3" class="text-right"><strong>TOTAL:</strong></td>
                        <td class="text-right"><strong>' . number_format($total_gross, 2) . ' g</strong></td>
                        <td class="text-right"><strong>' . number_format($total_net, 2) . ' g</strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
            
            <div class="section-title">PAYMENT SUMMARY</div>
            <table>
                <tr>
                    <td><strong>Principal:</strong></td><td>₹ ' . number_format($principal, 2) . '</td>
                    <td><strong>Total Interest:</strong></td><td>₹ ' . number_format($total_interest, 2) . '</td>
                </tr>
                <tr>
                    <td><strong>Process Charge:</strong></td><td>₹ ' . number_format($process_charge, 2) . '</td>
                    <td><strong>Appraisal Charge:</strong></td><td>₹ ' . number_format($appraisal_charge, 2) . '</td>
                </tr>';
                
if ($discount > 0) {
    $html .= '<tr><td><strong>Discount:</strong></td><td>- ₹ ' . number_format($discount, 2) . '</td><td></td><td></td></tr>';
}

if ($round_off != 0) {
    $html .= '<tr><td><strong>Round Off:</strong></td><td>₹ ' . number_format($round_off, 2) . '</td><td></td><td></td></tr>';
}

$html .= '<tr class="total-row">
                    <td colspan="3" class="text-right"><strong>Total Payable:</strong></td>
                    <td><strong>₹ ' . number_format($total_payable, 2) . '</strong></td>
                </tr>
            </table>
            
            <div class="amount-words"><strong>Amount in Words:</strong> ' . numberToWords($total_payable) . '</div>';
            
if (!empty($payment_html)) {
    $html .= '<div class="section-title">PAYMENT HISTORY</div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receipt No</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $payment_html . '
                </tbody>
            </table>';
}

$html .= '<div class="signature">
                <div class="signature-box">Customer Signature</div>
                <div class="signature-box">Authorized Signatory</div>
            </div>
            
            <div class="footer">Generated: ' . date('d-m-Y H:i') . ' | ORIGINAL COPY</div>
        </div>
        
        <!-- PAGE BREAK -->
        <div class="page-break"></div>
        
        <!-- PAGE 2: TWO SMALL RECEIPTS WITH INCREASED HEIGHT -->
        <div class="page2-container">
            <!-- FIRST COPY (ORIGINAL) - INCREASED HEIGHT -->
            <div class="receipt-box small-receipt" style="margin-bottom: 25px; padding: 15px;">
                <div class="copy-badge" style="font-size:11pt; margin-bottom: 8px;">ORIGINAL COPY</div>
                
                <div style="display:flex;align-items:center;margin-bottom:10px;">
                    <div style="width:40px;height:40px;margin-right:8px;">' . ($logo_exists ? '<img src="' . $logo_base64 . '" style="width:40px;height:40px;object-fit:contain;">' : '<div style="width:40px;height:40px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">L</div>') . '</div>
                    <div style="flex:1;text-align:center;">
                        <div style="font-weight:bold; font-size:12pt;">' . htmlspecialchars($company['branch_name']) . '</div>
                        <div style="color:#48bb78;font-weight:bold; font-size:11pt;">LOAN RECEIPT</div>
                        <div style="font-size:9pt;">' . htmlspecialchars($loan['receipt_number']) . ' | ' . $receipt_date . '</div>
                    </div>
                </div>
                
                <div style="display:flex;margin:10px 0;gap:10px;">
                    <div style="width:45px;height:45px;border:1px solid #ddd;">' . ($customer_photo_exists ? '<img src="' . $customer_photo_base64 . '" style="width:43px;height:43px;object-fit:cover;">' : '<div style="width:43px;height:43px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">NO</div>') . '</div>
                    <div style="flex:1;font-size:9pt;">
                        <div><strong>' . htmlspecialchars($loan['customer_name']) . '</strong></div>
                        <div>📞 ' . htmlspecialchars($loan['mobile_number']) . ' | 👤 ' . htmlspecialchars(substr($loan['guardian_name'] ?? '', 0, 20)) . '</div>
                    </div>
                </div>
                
                <div style="display:flex;justify-content:space-between;background:#f0f0f0;padding:5px;margin:10px 0;font-size:9pt;">
                    <div><strong>Amount:</strong> ₹ ' . number_format($loan['loan_amount'], 2) . '</div>
                    <div><strong>Interest:</strong> ' . $loan['interest_amount'] . '%</div>
                    <div><strong>Days:</strong> ' . $days . '</div>
                </div>
                
                <table style="font-size:8pt; margin:10px 0;">
                    <thead>
                        <tr>
                            <th style="padding:4px;">#</th>
                            <th style="padding:4px;">Item</th>
                            <th style="padding:4px;">Gross</th>
                            <th style="padding:4px;">Net</th>
                            <th style="padding:4px;">Photo</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $jewelry_html_small . '
                        <tr style="font-weight:bold;background:#f0f0f0;">
                            <td colspan="2" class="text-right" style="padding:4px;">TOTAL:</td>
                            <td class="text-right" style="padding:4px;">' . number_format($total_gross, 2) . '</td>
                            <td class="text-right" style="padding:4px;">' . number_format($total_net, 2) . '</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="border:1px solid #ddd;padding:8px;margin:10px 0;font-size:8pt;">
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Principal:</span> <span>₹ ' . number_format($principal, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Interest:</span> <span>₹ ' . number_format($total_interest, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Process:</span> <span>₹ ' . number_format($process_charge, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Appraisal:</span> <span>₹ ' . number_format($appraisal_charge, 2) . '</span></div>';
                    
if ($discount > 0) {
    $html .= '<div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Discount:</span> <span>-₹ ' . number_format($discount, 2) . '</span></div>';
}

$html .= '<div style="display:flex;justify-content:space-between;margin-top:5px;border-top:2px solid #48bb78;padding-top:5px;font-weight:bold; font-size:9pt;">
                        <span>Total Payable:</span>
                        <span>₹ ' . number_format($total_payable, 2) . '</span>
                    </div>
                </div>
                
                <div style="display:flex;justify-content:space-between;margin-top:15px;font-size:8pt;">
                    <div style="width:45%;text-align:center;border-top:1px solid #000;padding-top:5px;">Customer</div>
                    <div style="width:45%;text-align:center;border-top:1px solid #000;padding-top:5px;">Authorized</div>
                </div>
                
                <div style="text-align:center;font-size:7pt;color:#999;margin-top:8px;">' . date('d-m-Y') . ' | ORIGINAL</div>
            </div>
            
            <!-- CUT LINE - MORE PROMINENT -->
            <div class="cut-line">✂ - - - - - CUT HERE - - - - - ✂</div>
            
            <!-- SECOND COPY (DUPLICATE) - INCREASED HEIGHT -->
            <div class="receipt-box small-receipt" style="margin-top: 15px; padding: 15px;">
                <div class="copy-badge" style="font-size:11pt; margin-bottom: 8px;">DUPLICATE COPY</div>
                
                <div style="display:flex;align-items:center;margin-bottom:10px;">
                    <div style="width:40px;height:40px;margin-right:8px;">' . ($logo_exists ? '<img src="' . $logo_base64 . '" style="width:40px;height:40px;object-fit:contain;">' : '<div style="width:40px;height:40px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">L</div>') . '</div>
                    <div style="flex:1;text-align:center;">
                        <div style="font-weight:bold; font-size:12pt;">' . htmlspecialchars($company['branch_name']) . '</div>
                        <div style="color:#48bb78;font-weight:bold; font-size:11pt;">LOAN RECEIPT</div>
                        <div style="font-size:9pt;">' . htmlspecialchars($loan['receipt_number']) . ' | ' . $receipt_date . '</div>
                    </div>
                </div>
                
                <div style="display:flex;margin:10px 0;gap:10px;">
                    <div style="width:45px;height:45px;border:1px solid #ddd;">' . ($customer_photo_exists ? '<img src="' . $customer_photo_base64 . '" style="width:43px;height:43px;object-fit:cover;">' : '<div style="width:43px;height:43px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;">NO</div>') . '</div>
                    <div style="flex:1;font-size:9pt;">
                        <div><strong>' . htmlspecialchars($loan['customer_name']) . '</strong></div>
                        <div>📞 ' . htmlspecialchars($loan['mobile_number']) . ' | 👤 ' . htmlspecialchars(substr($loan['guardian_name'] ?? '', 0, 20)) . '</div>
                    </div>
                </div>
                
                <div style="display:flex;justify-content:space-between;background:#f0f0f0;padding:5px;margin:10px 0;font-size:9pt;">
                    <div><strong>Amount:</strong> ₹ ' . number_format($loan['loan_amount'], 2) . '</div>
                    <div><strong>Interest:</strong> ' . $loan['interest_amount'] . '%</div>
                    <div><strong>Days:</strong> ' . $days . '</div>
                </div>
                
                <table style="font-size:8pt; margin:10px 0;">
                    <thead>
                        <tr>
                            <th style="padding:4px;">#</th>
                            <th style="padding:4px;">Item</th>
                            <th style="padding:4px;">Gross</th>
                            <th style="padding:4px;">Net</th>
                            <th style="padding:4px;">Photo</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $jewelry_html_small . '
                        <tr style="font-weight:bold;background:#f0f0f0;">
                            <td colspan="2" class="text-right" style="padding:4px;">TOTAL:</td>
                            <td class="text-right" style="padding:4px;">' . number_format($total_gross, 2) . '</td>
                            <td class="text-right" style="padding:4px;">' . number_format($total_net, 2) . '</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="border:1px solid #ddd;padding:8px;margin:10px 0;font-size:8pt;">
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Principal:</span> <span>₹ ' . number_format($principal, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Interest:</span> <span>₹ ' . number_format($total_interest, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Process:</span> <span>₹ ' . number_format($process_charge, 2) . '</span></div>
                    <div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Appraisal:</span> <span>₹ ' . number_format($appraisal_charge, 2) . '</span></div>';
                    
if ($discount > 0) {
    $html .= '<div style="display:flex;justify-content:space-between; margin-bottom:3px;"><span>Discount:</span> <span>-₹ ' . number_format($discount, 2) . '</span></div>';
}

$html .= '<div style="display:flex;justify-content:space-between;margin-top:5px;border-top:2px solid #48bb78;padding-top:5px;font-weight:bold; font-size:9pt;">
                        <span>Total Payable:</span>
                        <span>₹ ' . number_format($total_payable, 2) . '</span>
                    </div>
                </div>
                
                <div style="display:flex;justify-content:space-between;margin-top:15px;font-size:8pt;">
                    <div style="width:45%;text-align:center;border-top:1px solid #000;padding-top:5px;">Customer</div>
                    <div style="width:45%;text-align:center;border-top:1px solid #000;padding-top:5px;">Authorized</div>
                </div>
                
                <div style="text-align:center;font-size:7pt;color:#999;margin-top:8px;">' . date('d-m-Y') . ' | DUPLICATE</div>
            </div>
        </div>
    </div>
</body>
</html>';

// Handle download/print actions
if ($action === 'download' || $action === 'print') {
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            
            $tempDir = __DIR__ . '/tmp';
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8-s',
                'format' => 'A4',
                'margin_left' => 5,
                'margin_right' => 5,
                'margin_top' => 5,
                'margin_bottom' => 5,
                'tempDir' => $tempDir,
                'default_font_size' => 9,
                'default_font' => 'freeserif'
            ]);
            
            $mpdf->WriteHTML($html);
            
            $filename = 'Loan_Receipt_' . $loan['receipt_number'] . '.pdf';
            
            if ($action === 'download') {
                $mpdf->Output($filename, 'D');
            } else {
                $mpdf->Output($filename, 'I');
            }
            exit();
            
        } catch (Exception $e) {
            $action = 'preview';
        }
    }
}

// Show preview
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Receipt - <?php echo htmlspecialchars($loan['receipt_number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f8fafc; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .action-bar { background: white; border-radius: 6px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .btn { padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-secondary { background: #a0aec0; color: white; }
        .btn-warning { background: #ecc94b; color: #744210; }
        .receipt-preview { background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow-x: auto; }
        .note-box { background: #ebf8ff; border-left: 3px solid #4299e1; padding: 10px 15px; margin-bottom: 15px; border-radius: 4px; }
        .badge { display: inline-block; background: #48bb78; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px; }
        @media print { .action-bar, .btn, .note-box { display: none !important; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="action-bar">
            <div>
                <strong>Receipt: <?php echo htmlspecialchars($loan['receipt_number']); ?></strong>
                <span class="badge">2 Pages</span>
            </div>
            <div class="action-buttons">
                <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <a href="?id=<?php echo $loan_id; ?>&action=download" class="btn btn-success">
                    <i class="bi bi-file-pdf"></i> Download PDF
                </a>
                <a href="?id=<?php echo $loan_id; ?>&action=print" class="btn btn-primary" target="_blank">
                    <i class="bi bi-printer"></i> Print PDF
                </a>
                <button class="btn btn-warning" onclick="window.print()">
                    <i class="bi bi-printer"></i> Browser Print
                </button>
            </div>
        </div>
        
        <div class="note-box">
            <strong>2 Pages:</strong> Page 1 - Full A4 ORIGINAL | Page 2 - Two copies (ORIGINAL & DUPLICATE) with increased height and cut line
        </div>
        
        <div class="receipt-preview">
            <?php echo $html; ?>
        </div>
        
        <div class="action-bar" style="margin-top:15px;">
            <div>Both pages will print automatically</div>
            <div class="action-buttons">
                <a href="view-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <button class="btn btn-warning" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print Receipts
                </button>
            </div>
        </div>
    </div>
</body>
</html>
<?php exit(); ?>