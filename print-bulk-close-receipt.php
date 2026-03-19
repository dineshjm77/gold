<?php
session_start();
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

// Get parameters from URL
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$close_date = isset($_GET['close_date']) ? $_GET['close_date'] : date('Y-m-d');
$bulk_receipt = isset($_GET['receipt']) ? $_GET['receipt'] : 'BULK' . date('Ymd') . '001';
$loan_count = isset($_GET['count']) ? intval($_GET['count']) : 0;
$payment_mode = isset($_GET['mode']) ? $_GET['mode'] : 'cash';
$total_amount = isset($_GET['total']) ? floatval($_GET['total']) : 0;

if ($customer_id <= 0) {
    die("Invalid customer ID");
}

// Set UTF-8 for database connection
mysqli_set_charset($conn, 'utf8mb4');

// First, verify the customer exists
$customer_check = "SELECT id, customer_name FROM customers WHERE id = ?";
$stmt = mysqli_prepare($conn, $customer_check);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$customer_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($customer_result) == 0) {
    die("Customer not found with ID: " . $customer_id);
}
$customer_basic = mysqli_fetch_assoc($customer_result);

// Get customer details with all fields
$customer_query = "SELECT c.* FROM customers c WHERE c.id = ?";
$stmt = mysqli_prepare($conn, $customer_query);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$customer_result = mysqli_stmt_get_result($stmt);
$customer = mysqli_fetch_assoc($customer_result);

// IMPORTANT: Get all loans closed for this customer on the specified date
// Using a more flexible query that doesn't require exact time match
$loans_query = "SELECT l.*, 
               (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as total_paid_principal,
               (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as total_paid_interest,
               (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count,
               (SELECT GROUP_CONCAT(receipt_number SEPARATOR ', ') FROM payments WHERE loan_id = l.id) as payment_receipts
               FROM loans l 
               WHERE l.customer_id = ? AND l.status = 'closed' AND DATE(l.close_date) = ?
               ORDER BY l.receipt_date ASC";

$stmt = mysqli_prepare($conn, $loans_query);
mysqli_stmt_bind_param($stmt, 'is', $customer_id, $close_date);
mysqli_stmt_execute($stmt);
$loans_result = mysqli_stmt_get_result($stmt);

$loans = [];
$total_principal = 0;
$total_payable_principal = 0;
$total_interest = 0;
$total_paid_interest = 0;
$total_final_amount = 0;
$total_weight = 0;
$total_discount = 0;
$total_round_off = 0;
$actual_loan_count = 0;

while ($loan = mysqli_fetch_assoc($loans_result)) {
    // Calculate loan details
    $principal = floatval($loan['loan_amount']);
    $interest_rate = floatval($loan['interest_amount']);
    $receipt_charge = floatval($loan['receipt_charge'] ?? 0);
    $discount = floatval($loan['discount'] ?? 0);
    $round_off = floatval($loan['round_off'] ?? 0);
    
    // Get payments info
    $paid_principal = floatval($loan['total_paid_principal'] ?? 0);
    $paid_interest = floatval($loan['total_paid_interest'] ?? 0);
    
    // Calculate duration
    $receipt_date = new DateTime($loan['receipt_date']);
    $close_date_obj = new DateTime($loan['close_date']);
    $duration_days = $receipt_date->diff($close_date_obj)->days;
    $duration_months = floor($duration_days / 30);
    $duration_remaining_days = $duration_days % 30;
    
    // Calculate interest
    $monthly_interest = ($principal * $interest_rate) / 100;
    $daily_interest = $monthly_interest / 30;
    $total_interest_accrued = $daily_interest * $duration_days;
    
    // Calculate payable amounts
    $payable_principal = $principal - $paid_principal;
    $payable_interest = max(0, $total_interest_accrued - $paid_interest);
    
    // Calculate final amount
    $final_amount = $payable_principal + $payable_interest + $receipt_charge - $discount + $round_off;
    
    $loan['calculated'] = [
        'duration_days' => $duration_days,
        'duration_months' => $duration_months,
        'duration_remaining_days' => $duration_remaining_days,
        'monthly_interest' => round($monthly_interest, 2),
        'daily_interest' => round($daily_interest, 4),
        'total_interest_accrued' => round($total_interest_accrued, 2),
        'payable_principal' => round($payable_principal, 2),
        'payable_interest' => round($payable_interest, 2),
        'final_amount' => round($final_amount, 2)
    ];
    
    $loans[] = $loan;
    
    // Calculate totals
    $total_principal += $principal;
    $total_payable_principal += $payable_principal;
    $total_interest += $total_interest_accrued;
    $total_paid_interest += $paid_interest;
    $total_final_amount += $final_amount;
    $total_weight += floatval($loan['net_weight']);
    $total_discount += $discount;
    $total_round_off += $round_off;
    $actual_loan_count++;
}

// If no loans found, try a more permissive query to debug
if (empty($loans)) {
    // Debug query to see what loans exist for this customer
    $debug_query = "SELECT id, receipt_number, status, close_date 
                    FROM loans 
                    WHERE customer_id = ? AND status = 'closed'
                    ORDER BY close_date DESC";
    $stmt = mysqli_prepare($conn, $debug_query);
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $debug_result = mysqli_stmt_get_result($stmt);
    
    $debug_loans = [];
    while ($debug = mysqli_fetch_assoc($debug_result)) {
        $debug_loans[] = $debug;
    }
    
    
    
}

// Use actual count from database if available, otherwise use URL parameter
$display_loan_count = $actual_loan_count > 0 ? $actual_loan_count : $loan_count;
$display_total_amount = $total_final_amount > 0 ? $total_final_amount : $total_amount;

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

// Format customer address
$customer_address = trim(($customer['door_no'] ?? '') . ' ' . ($customer['street_name'] ?? '') . ', ' . 
                     ($customer['location'] ?? '') . ', ' . ($customer['district'] ?? '') . ' - ' . 
                     ($customer['pincode'] ?? ''));

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Loan Closure Receipt - <?php echo htmlspecialchars($customer['customer_name'] ?? 'Customer'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, 'Helvetica', sans-serif;
            background: #fff;
            padding: 20px;
            color: #333;
            line-height: 1.4;
        }

        .receipt-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 12px;
        }

        /* Header with Logo */
        .header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #48bb78;
        }

        .logo-container {
            flex: 0 0 80px;
            margin-right: 15px;
        }

        .logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 8px;
        }

        .header-content {
            flex: 1;
            text-align: center;
        }

        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .company-details {
            font-size: 12px;
            color: #718096;
            margin-bottom: 3px;
        }

        .receipt-title {
            font-size: 22px;
            font-weight: 600;
            color: #48bb78;
            margin: 10px 0 5px;
        }

        .receipt-subtitle {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .badge {
            display: inline-block;
            background: #48bb78;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 10px;
        }

        .bulk-badge {
            background: #9f7aea;
            font-size: 12px;
            padding: 4px 12px;
        }

        .customer-info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #48bb78;
        }

        .customer-name {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .customer-detail-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
            color: #4a5568;
        }

        .customer-detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .customer-detail-item i {
            color: #48bb78;
            width: 16px;
        }

        .summary-box {
            background: #f0fff4;
            border: 1px solid #48bb78;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }

        .summary-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #48bb78;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .summary-item {
            text-align: center;
        }

        .summary-label {
            font-size: 11px;
            color: #718096;
            margin-bottom: 3px;
        }

        .summary-value {
            font-size: 18px;
            font-weight: 700;
            color: #48bb78;
        }

        .summary-value.small {
            font-size: 14px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin: 25px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .loans-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 11px;
        }

        .loans-table th {
            background: #f7fafc;
            padding: 8px 5px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .loans-table td {
            padding: 6px 5px;
            border: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .loans-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount-highlight {
            color: #48bb78;
            font-weight: 600;
        }

        .interest-highlight {
            color: #ecc94b;
            font-weight: 600;
        }

        .payment-history {
            margin: 15px 0;
            font-size: 11px;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dotted #e2e8f0;
        }

        .amount-word {
            font-size: 13px;
            margin: 15px 0;
            padding: 12px;
            background: #ebf4ff;
            border-left: 4px solid #48bb78;
            border-radius: 4px;
        }

        .signature {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }

        .signature-box {
            text-align: center;
            width: 45%;
        }

        .signature-line {
            border-top: 1px solid #2d3748;
            margin-top: 40px;
            padding-top: 8px;
            font-size: 12px;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            color: #a0aec0;
            border-top: 1px solid #e2e8f0;
            margin-top: 30px;
            padding-top: 15px;
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }
            .receipt-container {
                box-shadow: none;
                padding: 15px;
            }
            .btn-print, .btn-back, .debug-info {
                display: none;
            }
        }

        .btn-print {
            display: block;
            width: 200px;
            margin: 20px auto 10px;
            padding: 12px 20px;
            background: #48bb78;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }

        .btn-print:hover {
            background: #38a169;
        }

        .btn-back {
            display: block;
            width: 200px;
            margin: 10px auto;
            padding: 12px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #5a67d8;
        }

        .btn-print i, .btn-back i {
            margin-right: 8px;
        }

        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="receipt-container" id="receipt-content">
        <!-- Header with Logo on Left -->
        <div class="header">
            <div class="logo-container">
                <?php if ($logo_exists && !empty($logo_base64)): ?>
                    <img src="<?php echo $logo_base64; ?>" alt="Company Logo" class="logo">
                <?php else: ?>
                    <div style="width:80px;height:80px;background:#f7fafc;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#a0aec0;font-size:10px;text-align:center;border:1px dashed #48bb78;">
                        Logo
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-content">
                <div class="company-name"><?php echo htmlspecialchars($company['branch_name']); ?></div>
                <div class="company-details"><?php echo htmlspecialchars($company['address']); ?></div>
                <div class="company-details">
                    <?php 
                    $contact = [];
                    if (!empty($company['phone'])) $contact[] = 'Phone: ' . $company['phone'];
                    if (!empty($company['email'])) $contact[] = 'Email: ' . $company['email'];
                    echo htmlspecialchars(implode(' | ', $contact));
                    ?>
                </div>
                <div class="receipt-title">BULK LOAN CLOSURE RECEIPT</div>
                <div class="receipt-subtitle">
                    <span class="badge bulk-badge">Bulk Closure</span>
                    Receipt: <strong><?php echo htmlspecialchars($bulk_receipt); ?></strong> | 
                    Date: <strong><?php echo date('d-m-Y', strtotime($close_date)); ?></strong>
                </div>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="customer-info-card">
            <div class="customer-name">
                <?php echo htmlspecialchars($customer['customer_name'] ?? 'Unknown Customer'); ?>
                <?php if (!empty($customer['guardian_name'])): ?>
                    <span style="font-size: 14px; font-weight: normal; color: #718096; margin-left: 10px;">
                        (<?php echo ($customer['guardian_type'] ?? '') ? ($customer['guardian_type'] . '. ') : ''; ?><?php echo htmlspecialchars($customer['guardian_name'] ?? ''); ?>)
                    </span>
                <?php endif; ?>
            </div>
            <div class="customer-detail-row">
                <div class="customer-detail-item">
                    <i class="bi bi-geo-alt"></i> 
                    <span><?php echo htmlspecialchars($customer_address ?: 'Address not available'); ?></span>
                </div>
                <div class="customer-detail-item">
                    <i class="bi bi-telephone"></i> 
                    <span><?php echo htmlspecialchars($customer['mobile_number'] ?? 'N/A'); ?></span>
                </div>
                <?php if (!empty($customer['whatsapp_number'])): ?>
                <div class="customer-detail-item">
                    <i class="bi bi-whatsapp"></i> 
                    <span><?php echo htmlspecialchars($customer['whatsapp_number']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bulk Summary -->
        <div class="summary-box">
            <div class="summary-title">Bulk Closure Summary</div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Total Loans</div>
                    <div class="summary-value"><?php echo $display_loan_count; ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Weight</div>
                    <div class="summary-value small"><?php echo number_format($total_weight ?: 0, 3); ?> g</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Principal Amount</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_principal ?: 0, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Payable Principal</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_payable_principal ?: 0, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Interest</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_interest ?: 0, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Paid Interest</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_paid_interest ?: 0, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Discount</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_discount ?: 0, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Round Off</div>
                    <div class="summary-value small">₹ <?php echo number_format($total_round_off ?: 0, 2); ?></div>
                </div>
                <div class="summary-item" style="grid-column: span 2;">
                    <div class="summary-label">Total Final Amount</div>
                    <div class="summary-value">₹ <?php echo number_format($display_total_amount, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Loans Table (only show if loans exist) -->
        <?php if (!empty($loans)): ?>
        <div class="section-title">Loans Closed</div>
        <table class="loans-table">
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>Receipt No</th>
                    <th>Receipt Date</th>
                    <th>Close Date</th>
                    <th>Duration</th>
                    <th>Weight</th>
                    <th>Principal</th>
                    <th>P. Principal</th>
                    <th>Interest%</th>
                    <th>1M Interest</th>
                    <th>Total Interest</th>
                    <th>P. Interest</th>
                    <th>Final Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sno = 1;
                foreach ($loans as $loan): 
                ?>
                <tr>
                    <td class="text-center"><?php echo $sno++; ?></td>
                    <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                    <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                    <td><?php echo date('d-m-Y', strtotime($loan['close_date'])); ?></td>
                    <td><?php echo $loan['calculated']['duration_days']; ?>d</td>
                    <td class="text-right"><?php echo number_format($loan['net_weight'], 3); ?>g</td>
                    <td class="text-right">₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                    <td class="text-right amount-highlight">₹ <?php echo number_format($loan['calculated']['payable_principal'], 2); ?></td>
                    <td class="text-center"><?php echo $loan['interest_amount']; ?>%</td>
                    <td class="text-right">₹ <?php echo number_format($loan['calculated']['monthly_interest'], 2); ?></td>
                    <td class="text-right">₹ <?php echo number_format($loan['calculated']['total_interest_accrued'], 2); ?></td>
                    <td class="text-right interest-highlight">₹ <?php echo number_format($loan['calculated']['payable_interest'], 2); ?></td>
                    <td class="text-right amount-highlight"><strong>₹ <?php echo number_format($loan['calculated']['final_amount'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="5" class="text-right">Totals:</th>
                    <th class="text-right"><?php echo number_format($total_weight, 3); ?>g</th>
                    <th class="text-right">₹ <?php echo number_format($total_principal, 2); ?></th>
                    <th class="text-right amount-highlight">₹ <?php echo number_format($total_payable_principal, 2); ?></th>
                    <th></th>
                    <th class="text-right">₹ <?php echo number_format($total_interest / max(1, $actual_loan_count), 2); ?>*</th>
                    <th class="text-right">₹ <?php echo number_format($total_interest, 2); ?></th>
                    <th class="text-right interest-highlight">₹ <?php echo number_format($total_paid_interest, 2); ?></th>
                    <th class="text-right amount-highlight">₹ <?php echo number_format($total_final_amount, 2); ?></th>
                </tr>
            </tfoot>
        </table>
        <div style="font-size: 10px; color: #718096; margin-top: 5px; text-align: right;">
            * Average monthly interest
        </div>
        <?php endif; ?>

        <!-- Payment Details -->
        <div class="section-title">Payment Details</div>
        <table style="width: 100%; font-size: 12px; margin: 10px 0;">
            <tr>
                <td style="width: 150px;"><strong>Payment Mode:</strong></td>
                <td><?php echo ucfirst($payment_mode); ?></td>
                <td style="width: 150px;"><strong>Payment Date:</strong></td>
                <td><?php echo date('d-m-Y', strtotime($close_date)); ?></td>
            </tr>
            <tr>
                <td><strong>Bulk Receipt No:</strong></td>
                <td><?php echo $bulk_receipt; ?></td>
                <td><strong>Total Loans Closed:</strong></td>
                <td><?php echo $display_loan_count; ?></td>
            </tr>
        </table>

        <!-- Individual Loan Receipts (only show if loans exist) -->
        <?php if (!empty($loans)): ?>
        <div class="section-title">Individual Loan Receipts</div>
        <div class="payment-history">
            <?php foreach ($loans as $loan): ?>
            <div class="payment-item">
                <span style="font-weight: 600;"><?php echo $loan['receipt_number']; ?></span>
                <span>Closed: <?php echo date('d-m-Y', strtotime($loan['close_date'])); ?></span>
                <span class="amount-highlight">₹ <?php echo number_format($loan['calculated']['final_amount'], 2); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Amount in Words -->
        <div class="amount-word">
            <strong>Total Amount in Words:</strong> <?php echo numberToWords($display_total_amount); ?>
        </div>

        <!-- Settlement Note -->
        <div style="background: #f0fff4; border: 1px solid #48bb78; border-radius: 8px; padding: 15px; text-align: center; margin: 20px 0;">
            <strong style="font-size: 16px; color: #48bb78;">✓ BULK LOAN CLOSURE COMPLETED ✓</strong><br>
            <span style="font-size: 13px;">All <?php echo $display_loan_count; ?> loan(s) have been successfully closed on <?php echo date('d-m-Y', strtotime($close_date)); ?></span>
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
            This is a computer generated bulk closure receipt | Generated on: <?php echo date('d-m-Y H:i:s'); ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="text-align: center;">
        <button class="btn-print" onclick="window.print()">
            <i class="bi bi-printer"></i> Print Bulk Receipt
        </button>
        <a href="bulk-loan-close.php?customer_id=<?php echo $customer_id; ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Bulk Close
        </a>
    </div>

    <script>
        // Auto print when page loads (optional)
        // window.onload = function() { 
        //     setTimeout(function() { window.print(); }, 500);
        // }
    </script>
</body>
</html>
<?php
exit();
?>