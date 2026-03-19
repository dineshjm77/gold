<?php
session_start();
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
if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$loan_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($loan_result) == 0) {
    die("No closed loan found with ID: " . $loan_id);
}

$loan = mysqli_fetch_assoc($loan_result);

// Get loan items (jewelry) with photos
$items_query = "SELECT * FROM loan_items WHERE loan_id = ? ORDER BY id";
$stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$items_result = mysqli_stmt_get_result($stmt);
$items = [];
while ($item = mysqli_fetch_assoc($items_result)) {
    $items[] = $item;
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
        'branch_name' => 'WEALTHROT',
        'address' => 'Main Branch',
        'phone' => '',
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

// ============================================
// PDF GENERATION
// ============================================
require_once('vendor/autoload.php'); // Make sure you have mPDF installed via composer

use Mpdf\Mpdf;

// Create new mPDF instance
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 15,
    'margin_bottom' => 15,
    'tempDir' => sys_get_temp_dir() . '/mpdf'
]);

// Start building HTML content
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Loan Closure Receipt - ' . htmlspecialchars($loan['receipt_number']) . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #fff;
            color: #333;
            line-height: 1.4;
        }

        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 20px;
        }

        /* Header with Logo */
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #48bb78;
        }

        .logo-container {
            flex: 0 0 70px;
            margin-right: 15px;
        }

        .logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
        }

        .header-content {
            flex: 1;
            text-align: center;
        }

        .company-name {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 3px;
        }

        .company-details {
            font-size: 11px;
            color: #718096;
            margin-bottom: 2px;
        }

        .receipt-title {
            font-size: 20px;
            font-weight: 600;
            color: #48bb78;
            margin: 5px 0 3px;
        }

        .receipt-subtitle {
            font-size: 12px;
            color: #4a5568;
        }

        .badge {
            display: inline-block;
            background: #48bb78;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 8px;
        }

        .two-column {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .column {
            flex: 1;
            background: #f8fafc;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .column-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row {
            display: flex;
            margin-bottom: 5px;
            font-size: 11px;
        }

        .info-label {
            font-weight: 600;
            width: 80px;
            color: #4a5568;
        }

        .info-value {
            flex: 1;
            color: #2d3748;
        }

        .info-value.highlight {
            color: #48bb78;
            font-weight: 600;
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin: 20px 0 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e2e8f0;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 11px;
        }

        .items-table th {
            background: #f7fafc;
            padding: 6px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .items-table td {
            padding: 5px 6px;
            border: 1px solid #e2e8f0;
        }

        .items-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            margin: 15px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 12px;
        }

        .summary-row.total {
            border-top: 2px solid #48bb78;
            border-bottom: none;
            margin-top: 6px;
            padding-top: 8px;
            font-weight: 700;
            font-size: 14px;
        }

        .payment-history {
            margin: 10px 0;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dotted #e2e8f0;
            font-size: 11px;
        }

        .payment-date {
            width: 70px;
        }

        .payment-receipt {
            width: 80px;
            color: #667eea;
        }

        .payment-desc {
            flex: 1;
            padding-left: 8px;
        }

        .payment-amount {
            width: 70px;
            text-align: right;
        }

        .amount-word {
            font-size: 12px;
            margin: 15px 0;
            padding: 10px;
            background: #ebf4ff;
            border-left: 4px solid #48bb78;
            border-radius: 4px;
        }

        .settlement-note {
            background: #f0fff4;
            border: 1px solid #48bb78;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
            margin: 15px 0;
            font-size: 12px;
        }

        .signature {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }

        .signature-box {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            border-top: 1px solid #2d3748;
            margin-top: 35px;
            padding-top: 6px;
            font-size: 11px;
        }

        .footer {
            text-align: center;
            font-size: 9px;
            color: #a0aec0;
            border-top: 1px solid #e2e8f0;
            margin-top: 25px;
            padding-top: 10px;
        }

        .options-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 600;
            margin-right: 4px;
        }

        .badge-dnamuna {
            background: #9f7aea;
            color: white;
        }

        .badge-others {
            background: #f687b3;
            color: white;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header with Logo -->
        <div class="header">
            <div class="logo-container">
                ' . ($logo_exists && !empty($logo_base64) ? '<img src="' . $logo_base64 . '" class="logo">' : '<div style="width:70px;height:70px;background:#f7fafc;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#a0aec0;font-size:9px;border:1px dashed #48bb78;">Logo</div>') . '
            </div>
            <div class="header-content">
                <div class="company-name">' . htmlspecialchars($company['branch_name']) . '</div>
                <div class="company-details">' . htmlspecialchars($company['address']) . '</div>
                <div class="company-details">' . 
                    htmlspecialchars(implode(' | ', array_filter([
                        !empty($company['phone']) ? 'Phone: ' . $company['phone'] : '',
                        !empty($company['email']) ? 'Email: ' . $company['email'] : ''
                    ]))) . '
                </div>
                <div class="receipt-title">LOAN CLOSURE RECEIPT</div>
                <div class="receipt-subtitle">
                    Loan Receipt: <strong>' . htmlspecialchars($loan['receipt_number']) . '</strong> | 
                    Closed on: <strong>' . date('d-m-Y', strtotime($loan['close_date'])) . '</strong>
                    ' . (($d_namuna || $others) ? '<span class="badge">' . implode(' | ', array_filter([
                        $d_namuna ? 'D-Namuna' : '',
                        $others ? 'Others' : ''
                    ])) . '</span>' : '') . '
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div class="two-column">
            <!-- Left Column - Customer Info -->
            <div class="column">
                <div class="column-title">Customer Information</div>
                <div class="info-row">
                    <span class="info-label">Name:</span>
                    <span class="info-value">' . htmlspecialchars($loan['customer_name']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Guardian:</span>
                    <span class="info-value">' . ($loan['guardian_type'] ? $loan['guardian_type'] . '. ' : '') . htmlspecialchars($loan['guardian_name']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Mobile:</span>
                    <span class="info-value">' . htmlspecialchars($loan['mobile_number']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Address:</span>
                    <span class="info-value">' . htmlspecialchars($customer_address) . '</span>
                </div>
                ' . (!empty($loan['aadhaar_number']) ? '
                <div class="info-row">
                    <span class="info-label">Aadhaar:</span>
                    <span class="info-value">XXXX-XXXX-' . substr($loan['aadhaar_number'], -4) . '</span>
                </div>' : '') . '
            </div>

            <!-- Right Column - Loan Info -->
            <div class="column">
                <div class="column-title">Loan Information</div>
                <div class="info-row">
                    <span class="info-label">Receipt Date:</span>
                    <span class="info-value">' . date('d-m-Y', strtotime($loan['receipt_date'])) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Close Date:</span>
                    <span class="info-value highlight">' . date('d-m-Y', strtotime($loan['close_date'])) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Duration:</span>
                    <span class="info-value">' . $loan_duration_days . ' days (' . $loan_duration_months . 'm ' . $loan_duration_remaining_days . 'd)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Gross Weight:</span>
                    <span class="info-value">' . $loan['gross_weight'] . ' g</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Net Weight:</span>
                    <span class="info-value">' . $loan['net_weight'] . ' g</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Loan Amount:</span>
                    <span class="info-value highlight">₹ ' . number_format($principal, 2) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Interest Rate:</span>
                    <span class="info-value">' . $interest_rate . '%</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Processed By:</span>
                    <span class="info-value">' . htmlspecialchars($loan['employee_name']) . '</span>
                </div>
            </div>
        </div>

        <!-- Options Display -->
        ' . (($d_namuna || $others) ? '
        <div style="margin-bottom: 10px;">
            ' . ($d_namuna ? '<span class="options-badge badge-dnamuna">✓ D-Namuna</span>' : '') . '
            ' . ($others ? '<span class="options-badge badge-others">✓ Others</span>' : '') . '
        </div>' : '') . '

        <!-- Jewelry Items Section -->
        <div class="section-title">Jewelry Items</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>Jewel Name</th>
                    <th>Karat</th>
                    <th>Defect</th>
                    <th>Stone</th>
                    <th class="text-right">Weight (g)</th>
                    <th class="text-center">Qty</th>
                </tr>
            </thead>
            <tbody>';

$sno = 1;
$total_weight = 0;
foreach ($items as $item) {
    $total_weight += $item['net_weight'] * $item['quantity'];
    $html .= '
                <tr>
                    <td>' . $sno++ . '</td>
                    <td>' . htmlspecialchars($item['jewel_name']) . '</td>
                    <td>' . $item['karat'] . 'K</td>
                    <td>' . htmlspecialchars($item['defect_details'] ?: '-') . '</td>
                    <td>' . htmlspecialchars($item['stone_details'] ?: '-') . '</td>
                    <td class="text-right">' . number_format($item['net_weight'], 3) . '</td>
                    <td class="text-center">' . $item['quantity'] . '</td>
                </tr>';
}

$html .= '
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="text-right"><strong>Total Weight:</strong></td>
                    <td class="text-right"><strong>' . number_format($total_weight, 3) . ' g</strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <!-- Payment Summary -->
        <div class="section-title">Payment Summary</div>
        <div class="summary-box">
            <div class="summary-row">
                <span>Principal Amount:</span>
                <span>₹ ' . number_format($principal, 2) . '</span>
            </div>
            <div class="summary-row">
                <span>Total Interest Accrued:</span>
                <span>₹ ' . number_format($total_interest_calculated, 2) . '</span>
            </div>
            <div class="summary-row">
                <span>Receipt Charge:</span>
                <span>₹ ' . number_format($receipt_charge, 2) . '</span>
            </div>';

if ($discount > 0) {
    $html .= '
            <div class="summary-row">
                <span>Discount:</span>
                <span class="text-danger">- ₹ ' . number_format($discount, 2) . '</span>
            </div>';
}

if ($round_off != 0) {
    $html .= '
            <div class="summary-row">
                <span>Round Off:</span>
                <span>₹ ' . number_format($round_off, 2) . '</span>
            </div>';
}

$html .= '
            <div class="summary-row total">
                <span>Total Amount Paid:</span>
                <span>₹ ' . number_format($total_amount_paid, 2) . '</span>
            </div>
        </div>';

// Payment History
if (!empty($payments)) {
    $html .= '
        <div class="section-title">Payment History</div>
        <div class="payment-history">';
    
    foreach ($payments as $payment) {
        $desc = '';
        if ($payment['principal_amount'] > 0 && $payment['interest_amount'] > 0) {
            $desc = 'Principal + Interest';
        } elseif ($payment['principal_amount'] > 0) {
            $desc = 'Principal Payment';
        } else {
            $desc = 'Interest Payment';
        }
        
        $html .= '
            <div class="payment-item">
                <span class="payment-date">' . date('d-m-Y', strtotime($payment['payment_date'])) . '</span>
                <span class="payment-receipt">' . $payment['receipt_number'] . '</span>
                <span class="payment-desc">' . $desc . '</span>
                <span class="payment-amount">₹ ' . number_format($payment['total_amount'], 2) . '</span>
            </div>';
    }
    
    $html .= '
        </div>';
}

// Amount in Words
$html .= '
        <div class="amount-word">
            <strong>Amount in Words:</strong> ' . numberToWords($total_amount_paid) . '
        </div>

        <!-- Settlement Note -->
        <div class="settlement-note">
            <strong style="font-size: 14px;">✓ LOAN FULLY SETTLED ✓</strong><br>
            This loan has been closed on ' . date('d-m-Y', strtotime($loan['close_date'])) . ' with total payment of ₹ ' . number_format($total_amount_paid, 2) . '
        </div>

        <!-- Signature -->
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
            This is a computer generated closure receipt | Generated on: ' . date('d-m-Y H:i:s') . '
        </div>
    </div>
</body>
</html>';

// Write HTML to PDF
$mpdf->WriteHTML($html);

// Output PDF for download
$filename = 'Closure_Receipt_' . $loan['receipt_number'] . '.pdf';
$mpdf->Output($filename, 'D'); // 'D' forces download

exit();
?>