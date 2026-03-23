<?php
/**
 * Email Helper for WEALTHROT Loan Management
 * Handles all email sending operations with PHPMailer
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_config.php';

// ============================================
// MANUAL AUTOLOADER FOR PHPMailer
// ============================================
$phpmailer_loaded = false;

// Try different possible paths for PHPMailer
$possible_paths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/PHPMailer-master/src/PHPMailer.php',
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../libs/phpmailer/src/PHPMailer.php',
    __DIR__ . '/PHPMailer/src/PHPMailer.php'
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        if (basename($path) == 'autoload.php') {
            require_once $path;
            $phpmailer_loaded = true;
            error_log("PHPMailer loaded via composer autoload: " . $path);
            break;
        } elseif (strpos($path, 'src/PHPMailer.php') !== false) {
            $base_dir = dirname($path, 2);
            if (file_exists($base_dir . '/src/Exception.php')) {
                require_once $base_dir . '/src/Exception.php';
                require_once $base_dir . '/src/PHPMailer.php';
                require_once $base_dir . '/src/SMTP.php';
                $phpmailer_loaded = true;
                error_log("PHPMailer loaded from source: " . $path);
                break;
            }
        }
    }
}

// If PHPMailer is not found, create a fallback function
if (!$phpmailer_loaded) {
    error_log("WARNING: PHPMailer not found. Email sending will be disabled.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send Loan Creation Email to Customer
 */
function sendLoanEmail($loan_id, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED) {
        error_log("Email notifications are disabled");
        return ['success' => false, 'message' => 'Email disabled'];
    }
    
    if (!$phpmailer_loaded) {
        error_log("PHPMailer not loaded. Check the path in email_helper.php");
        return ['success' => false, 'message' => 'PHPMailer not loaded'];
    }
    
    // Get loan details with customer information
    $query = "SELECT l.*, c.customer_name, c.email, c.mobile_number, c.customer_photo,
                     c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                     u.name as employee_name,
                     (SELECT COUNT(*) FROM loan_items WHERE loan_id = l.id) as item_count,
                     (SELECT SUM(quantity) FROM loan_items WHERE loan_id = l.id) as total_items
              FROM loans l
              JOIN customers c ON l.customer_id = c.id
              JOIN users u ON l.employee_id = u.id
              WHERE l.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("Failed to prepare loan query: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'Database error'];
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan) {
        error_log("Loan not found with ID: " . $loan_id);
        return ['success' => false, 'message' => 'Loan not found'];
    }
    
    if (empty($loan['email'])) {
        error_log("Customer has no email address for loan ID: " . $loan_id);
        return ['success' => false, 'message' => 'No email address'];
    }
    
    // Get product value settings
    $regular_percent = 70; // Default
    $personal_percent = 20; // Default
    $product_type = 'தங்கம்';
    
    $value_query = "SELECT * FROM product_value_settings WHERE product_type = ? LIMIT 1";
    $value_stmt = mysqli_prepare($conn, $value_query);
    mysqli_stmt_bind_param($value_stmt, 's', $product_type);
    mysqli_stmt_execute($value_stmt);
    $value_result = mysqli_stmt_get_result($value_stmt);
    $value_settings = mysqli_fetch_assoc($value_result);
    
    if ($value_settings) {
        $regular_percent = $value_settings['regular_loan_percentage'] ?? 70;
        $personal_percent = $value_settings['personal_loan_percentage'] ?? 20;
    }
    
    // Get company/branch settings
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => '',
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    // Check if logo exists
    $logo_base64 = '';
    if (!empty($company['logo_path']) && file_exists($company['logo_path'])) {
        $image_data = file_get_contents($company['logo_path']);
        $image_type = pathinfo($company['logo_path'], PATHINFO_EXTENSION);
        $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
    }
    
    // Calculate loan details
    $principal = floatval($loan['loan_amount']);
    $interest_rate = floatval($loan['interest_amount']);
    $process_charge = floatval($loan['process_charge'] ?? 0);
    $appraisal_charge = floatval($loan['appraisal_charge'] ?? 0);
    $receipt_date = date('d-m-Y', strtotime($loan['receipt_date']));
    
    // Calculate personal loan eligibility
    $product_value = floatval($loan['product_value']);
    $regular_loan_amount = ($product_value * $regular_percent) / 100;
    $personal_loan_amount = ($product_value * $personal_percent) / 100;
    $total_potential = $regular_loan_amount + $personal_loan_amount;
    
    // Format address
    $customer_address = trim(implode(', ', array_filter([
        $loan['door_no'],
        $loan['house_name'],
        $loan['street_name'],
        $loan['location'],
        $loan['district']
    ]))) . ($loan['pincode'] ? ' - ' . $loan['pincode'] : '');
    
    // Get jewelry items
    $items_query = "SELECT * FROM loan_items WHERE loan_id = ?";
    $items_stmt = mysqli_prepare($conn, $items_query);
    mysqli_stmt_bind_param($items_stmt, 'i', $loan_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    
    $items_html = '';
    $total_gross = 0;
    $total_net = 0;
    $sno = 1;
    $item_count = 0;
    
    while ($item = mysqli_fetch_assoc($items_result)) {
        $item_count++;
        $gross = ($item['gross_weight'] ?? $item['net_weight']) * $item['quantity'];
        $net = $item['net_weight'] * $item['quantity'];
        $total_gross += $gross;
        $total_net += $net;
        
        // Get item photo if exists
        $item_photo_html = '';
        if (!empty($item['photo_path']) && file_exists($item['photo_path'])) {
            $image_data = file_get_contents($item['photo_path']);
            $image_type = pathinfo($item['photo_path'], PATHINFO_EXTENSION);
            $item_photo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
            $item_photo_html = '<img src="' . $item_photo_base64 . '" style="width:50px; height:50px; object-fit:cover; border-radius:4px;">';
        } else {
            $item_photo_html = '<div style="width:50px; height:50px; background:#f0f0f0; display:flex; align-items:center; justify-content:center; border-radius:4px; color:#718096; font-size:10px;">NO PHOTO</div>';
        }
        
        $items_html .= "
         <tr>
            <td style='padding: 10px; border: 1px solid #e2e8f0; text-align: center;'>{$sno}</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars($item['jewel_name']) . "</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0;'>{$item['karat']}K</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars($item['defect_details'] ?: '-') . "</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0;'>" . htmlspecialchars($item['stone_details'] ?: '-') . "</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0; text-align: right;'>" . number_format($item['gross_weight'] ?? $item['net_weight'], 3) . "g</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0; text-align: right;'>" . number_format($item['net_weight'], 3) . "g</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0; text-align: center;'>{$item['quantity']}</td>
            <td style='padding: 10px; border: 1px solid #e2e8f0; text-align: center;'>{$item_photo_html}</td>
         </tr>
        ";
        $sno++;
    }
    
    // Get customer photo if exists
    $customer_photo_html = '';
    if (!empty($loan['customer_photo']) && file_exists($loan['customer_photo'])) {
        $image_data = file_get_contents($loan['customer_photo']);
        $image_type = pathinfo($loan['customer_photo'], PATHINFO_EXTENSION);
        $customer_photo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
        $customer_photo_html = '<img src="' . $customer_photo_base64 . '" style="width:100px; height:100px; border-radius:8px; object-fit:cover; border:2px solid #667eea; margin-bottom:15px;">';
    }
    
    // Send main loan confirmation email
    $subject = "✅ Loan Created Successfully - Receipt #{$loan['receipt_number']}";
    
    $body = getLoanEmailTemplate($loan, $company, $logo_base64, $customer_photo_html, $customer_address, $receipt_date, $principal, $interest_rate, $process_charge, $appraisal_charge, $product_value, $regular_percent, $personal_percent, $regular_loan_amount, $personal_loan_amount, $total_potential, $items_html, $total_gross, $total_net, $item_count);
    
    // Send the main email
    $result = sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
    
    // If personal loan is available, send a second email
    if ($personal_percent > 0 && $result['success']) {
        sendPersonalLoanOfferEmail($loan_id, $conn);
    }
    
    return $result;
}

/**
 * Get Loan Email Template
 */
function getLoanEmailTemplate($loan, $company, $logo_base64, $customer_photo_html, $customer_address, $receipt_date, $principal, $interest_rate, $process_charge, $appraisal_charge, $product_value, $regular_percent, $personal_percent, $regular_loan_amount, $personal_loan_amount, $total_potential, $items_html, $total_gross, $total_net, $item_count) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Loan Receipt</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f8fafc; }
            .container { max-width: 800px; margin: 20px auto; background: white; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .section { margin-bottom: 30px; }
            .section-title { font-size: 18px; font-weight: 600; color: #2d3748; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
            .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; }
            .amount-box { background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%); border: 1px solid #48bb78; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
            .amount-label { font-size: 14px; color: #276749; margin-bottom: 5px; }
            .amount-value { font-size: 36px; font-weight: 700; color: #48bb78; }
            .table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            .table th { background: #667eea; color: white; padding: 12px; text-align: left; font-size: 13px; }
            .table td { padding: 12px; border: 1px solid #e2e8f0; font-size: 13px; }
            .footer { background: #f7fafc; padding: 20px; text-align: center; font-size: 12px; color: #718096; border-top: 1px solid #e2e8f0; }
            .button { display: inline-block; padding: 12px 25px; background: #48bb78; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 5px; }
            @media (max-width: 600px) { .info-grid { grid-template-columns: 1fr; } }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                " . ($logo_base64 ? "<img src='{$logo_base64}' style='height: 60px; margin-bottom: 10px;'>" : "") . "
                <h1>" . htmlspecialchars($company['branch_name']) . "</h1>
                <p>" . htmlspecialchars($company['address']) . "</p>
            </div>
            <div class='content'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <span style='display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #48bb7820; color: #276749;'>Loan Receipt</span>
                </div>
                <p>Dear <strong>" . htmlspecialchars($loan['customer_name']) . "</strong>,</p>
                <p>Your loan has been successfully created. Below are the complete details:</p>
                " . ($customer_photo_html ? "<div style='text-align: center;'>{$customer_photo_html}</div>" : "") . "
                <div class='amount-box'>
                    <div class='amount-label'>Loan Amount</div>
                    <div class='amount-value'>₹ " . number_format($principal, 2) . "</div>
                </div>
                <div class='section'>
                    <div class='section-title'>👤 Customer Information</div>
                    <div class='info-grid'>
                        <div><div style='font-size:12px; color:#718096;'>Name</div><div style='font-size:16px; font-weight:600;'>" . htmlspecialchars($loan['customer_name']) . "</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Mobile</div><div style='font-size:16px; font-weight:600;'>" . htmlspecialchars($loan['mobile_number']) . "</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Email</div><div style='font-size:16px; font-weight:600;'>" . htmlspecialchars($loan['email']) . "</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Address</div><div style='font-size:16px; font-weight:600;'>" . htmlspecialchars($customer_address ?: 'N/A') . "</div></div>
                    </div>
                </div>
                <div class='section'>
                    <div class='section-title'>📄 Loan Information</div>
                    <div class='info-grid'>
                        <div><div style='font-size:12px; color:#718096;'>Receipt Number</div><div style='font-size:16px; font-weight:600;'>{$loan['receipt_number']}</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Receipt Date</div><div style='font-size:16px; font-weight:600;'>{$receipt_date}</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Interest Rate</div><div style='font-size:16px; font-weight:600;'>{$interest_rate}%</div></div>
                        <div><div style='font-size:12px; color:#718096;'>Processed By</div><div style='font-size:16px; font-weight:600;'>" . htmlspecialchars($loan['employee_name']) . "</div></div>
                    </div>
                </div>
                " . ($item_count > 0 ? "
                <div class='section'>
                    <div class='section-title'>💎 Jewelry Items ({$item_count} Items)</div>
                    <table class='table'>
                        <thead> <tr><th>#</th><th>Item Name</th><th>Karat</th><th>Defect</th><th>Stone</th><th>Gross</th><th>Net</th><th>Qty</th><th>Photo</th></tr> </thead>
                        <tbody>{$items_html}</tbody>
                        <tfoot><tr style='background:#f7fafc; font-weight:600;'><td colspan='5' style='text-align:right;'>Total Weight:</td><td>" . number_format($total_gross, 3) . "g</td><td>" . number_format($total_net, 3) . "g</td><td colspan='2'></td></tr></tfoot>
                    </table>
                </div>
                " : "") . "
                <div style='text-align: center; margin-top: 30px;'>
                    <a href='https://wealthrot.in/gold/view-loan.php?id={$loan['id']}' class='button'>View Loan Details Online</a>
                </div>
            </div>
            <div class='footer'>
                <p>This is an automated email. Please do not reply.</p>
                <p>For assistance, contact: " . htmlspecialchars($company['phone'] ?: FROM_EMAIL) . "</p>
                <p>© " . date('Y') . " " . htmlspecialchars($company['branch_name']) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Send Personal Loan Offer Email
 */
function sendPersonalLoanOfferEmail($loan_id, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
    }
    
    // Get loan details
    $query = "SELECT l.*, c.customer_name, c.email, c.mobile_number
              FROM loans l
              JOIN customers c ON l.customer_id = c.id
              WHERE l.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan || empty($loan['email'])) {
        return ['success' => false, 'message' => 'Loan not found or no email'];
    }
    
    // Get product value settings
    $product_type = 'தங்கம்';
    $settings_query = "SELECT * FROM product_value_settings WHERE product_type = ? LIMIT 1";
    $settings_stmt = mysqli_prepare($conn, $settings_query);
    mysqli_stmt_bind_param($settings_stmt, 's', $product_type);
    mysqli_stmt_execute($settings_stmt);
    $settings_result = mysqli_stmt_get_result($settings_stmt);
    $settings = mysqli_fetch_assoc($settings_result);
    
    $personal_percent = $settings['personal_loan_percentage'] ?? 20;
    $regular_percent = $settings['regular_loan_percentage'] ?? 70;
    
    $personal_amount = ($loan['product_value'] * $personal_percent) / 100;
    $regular_amount = $loan['loan_amount'];
    $total_amount = $regular_amount + $personal_amount;
    
    // Get company settings
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => FROM_EMAIL,
            'email' => FROM_EMAIL
        ];
    }
    
    $subject = "💰 Exclusive Personal Loan Offer - Additional ₹" . number_format($personal_amount, 0) . " Available";
    
    $body = getPersonalLoanOfferTemplate($loan, $company, $personal_amount, $regular_amount, $total_amount, $regular_percent, $personal_percent);
    
    return sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
}

/**
 * Get Personal Loan Offer Template
 */
function getPersonalLoanOfferTemplate($loan, $company, $personal_amount, $regular_amount, $total_amount, $regular_percent, $personal_percent) {
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Personal Loan Offer</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #ecc94b 0%, #fbbf24 100%); color: #744210; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 32px; }
            .content { padding: 40px; }
            .amount-box { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #ecc94b; border-radius: 16px; padding: 30px; text-align: center; margin: 30px 0; }
            .amount { font-size: 48px; font-weight: 700; color: #ecc94b; }
            .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 30px 0; }
            .info-item { background: #f7fafc; padding: 20px; border-radius: 12px; text-align: center; }
            .button { display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin: 10px; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
            @media (max-width: 480px) { .content { padding: 20px; } .info-grid { grid-template-columns: 1fr; } .amount { font-size: 36px; } }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Special Personal Loan Offer! 🎉</h1>
                <p>Exclusive offer for our valued customer</p>
            </div>
            <div class='content'>
                <div style='display: inline-block; background: #ecc94b; color: #744210; padding: 8px 20px; border-radius: 30px; font-size: 14px; font-weight: 600; margin-bottom: 20px;'>✨ EXCLUSIVE OFFER</div>
                <p style='font-size: 18px;'>Dear <strong>" . htmlspecialchars($loan['customer_name']) . "</strong>,</p>
                <p>Congratulations! Based on your recent loan, you are pre-qualified for an additional personal loan.</p>
                <div class='amount-box'>
                    <div style='font-size: 14px; color: #744210; margin-bottom: 10px;'>Your Personal Loan Offer</div>
                    <div class='amount'>₹ " . number_format($personal_amount, 2) . "</div>
                    <div style='font-size: 14px; color: #744210;'>at the same interest rate (" . $loan['interest_amount'] . "%)</div>
                </div>
                <div class='info-grid'>
                    <div class='info-item'><div style='font-size:12px; color:#718096;'>Regular Loan</div><div style='font-size:24px; font-weight:700; color:#48bb78;'>₹" . number_format($regular_amount, 0) . "</div><div style='font-size:12px;'>(" . $regular_percent . "% of value)</div></div>
                    <div class='info-item'><div style='font-size:12px; color:#718096;'>Personal Loan</div><div style='font-size:24px; font-weight:700; color:#ecc94b;'>₹" . number_format($personal_amount, 0) . "</div><div style='font-size:12px;'>(" . $personal_percent . "% of value)</div></div>
                    <div class='info-item'><div style='font-size:12px; color:#718096;'>Total Available</div><div style='font-size:24px; font-weight:700; color:#667eea;'>₹" . number_format($total_amount, 0) . "</div></div>
                </div>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='https://wealthrot.in/gold/accept-personal-loan.php?loan_id={$loan['id']}' class='button'>✅ Accept Offer</a>
                </div>
                <p style='text-align: center; font-size: 13px; color: #744210; background: #fef3c7; padding: 15px; border-radius: 8px;'>⏰ This offer is valid for 7 days from today.</p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Send Monthly Interest Payment Confirmation Email
 */
function sendMonthlyInterestPaymentConfirmation($payment_details, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded || empty($payment_details['customer_email'])) {
        error_log("Monthly interest payment confirmation email not sent: Email disabled or no recipient");
        return false;
    }
    
    // Get company settings
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => FROM_EMAIL,
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    // Check if logo exists
    $logo_base64 = '';
    if (!empty($company['logo_path']) && file_exists($company['logo_path'])) {
        $image_data = file_get_contents($company['logo_path']);
        $image_type = pathinfo($company['logo_path'], PATHINFO_EXTENSION);
        $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
    }
    
    $month_display = date('F Y', strtotime($payment_details['month_key'] . '-01'));
    $subject = "📅 Monthly Interest Payment Confirmation - Receipt #{$payment_details['receipt_number']}";
    
    $payment_method_icons = [
        'cash' => '💰',
        'bank' => '🏦',
        'upi' => '📱',
        'cheque' => '📝',
        'other' => '💳'
    ];
    $payment_icon = $payment_method_icons[$payment_details['payment_method']] ?? '💰';
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Monthly Interest Payment Confirmation</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%); color: #744210; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #ecc94b; border-radius: 16px; padding: 25px; text-align: center; margin: 20px 0; }
            .amount { font-size: 48px; font-weight: 700; color: #ecc94b; }
            .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #f7fafc; border-radius: 12px; overflow: hidden; }
            .details-table th { background: #ecc94b; color: #744210; padding: 15px; text-align: left; }
            .details-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
            .month-badge { display: inline-block; background: #ecc94b20; color: #744210; padding: 5px 15px; border-radius: 30px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
            .balance-info { background: #ebf4ff; padding: 20px; border-radius: 12px; margin: 20px 0; border-left: 4px solid #4299e1; }
            .balance-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #cbd5e0; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
            .button { display: inline-block; padding: 12px 30px; background: #ecc94b; color: #744210; text-decoration: none; border-radius: 50px; font-weight: 600; margin-top: 20px; }
            @media (max-width: 480px) { .amount { font-size: 36px; } }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                " . ($logo_base64 ? "<img src='{$logo_base64}' style='height: 50px; margin-bottom: 10px;'>" : "") . "
                <h1>Monthly Interest Payment Received! ✅</h1>
                <p>Thank you for your timely payment</p>
            </div>
            <div class='content'>
                <div style='text-align: center;'><span class='month-badge'>📅 " . htmlspecialchars($month_display) . "</span></div>
                <p style='font-size: 18px;'>Dear <strong>" . htmlspecialchars($payment_details['customer_name']) . "</strong>,</p>
                <p>We have received your monthly interest payment for <strong>" . htmlspecialchars($month_display) . "</strong>. Thank you for your timely payment!</p>
                <div class='amount-box'>
                    <div style='font-size: 14px; color: #744210;'>Interest Amount Paid</div>
                    <div class='amount'>₹ " . number_format($payment_details['interest_amount'], 2) . "</div>
                </div>
                <table class='details-table'>
                    <thead><tr><th colspan='2'>Payment Details</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Receipt Number</strong></td><td><strong style='color: #ecc94b;'>{$payment_details['receipt_number']}</strong></td></tr>
                        <tr><td>Payment Date</td><td>" . date('d-m-Y', strtotime($payment_details['payment_date'])) . "</td></tr>
                        <tr><td>Loan Receipt</td><td>{$payment_details['loan_receipt']}</td></tr>
                        <tr><td>Payment For</td><td>" . htmlspecialchars($month_display) . "</td></tr>
                        <tr><td>Payment Method</td><td>{$payment_icon} " . strtoupper($payment_details['payment_method']) . "</td></tr>
                    </tbody>
                </table>
                <div class='balance-info'>
                    <h4 style='color:#2c5282;'>📊 Updated Balance</h4>
                    <div class='balance-row'><span>Remaining Principal:</span><span style='font-weight:600; color:#48bb78;'>₹ " . number_format($payment_details['remaining_principal'], 2) . "</span></div>
                    <div class='balance-row'><span>Pending Interest:</span><span style='font-weight:600; color:#ecc94b;'>₹ " . number_format($payment_details['pending_interest'], 2) . "</span></div>
                </div>
                <div style='text-align:center;'><a href='https://wealthrot.in/gold/view-personal-loan.php?receipt={$payment_details['loan_receipt']}' class='button'>View Loan Details</a></div>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $result = sendEmailPHPMailer($payment_details['customer_email'], $subject, $body, ['id' => 0, 'receipt_number' => $payment_details['receipt_number']], $conn);
    return $result['success'];
}

/**
 * Send Payment Confirmation Email for Interest/Overdue Collection
 */
function sendPaymentConfirmationEmail($payment, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded || empty($payment['customer_email'])) {
        error_log("Payment confirmation email not sent: Email disabled or no recipient");
        return false;
    }
    
    // Get company/branch settings
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => FROM_EMAIL,
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    // Check if logo exists
    $logo_base64 = '';
    if (!empty($company['logo_path']) && file_exists($company['logo_path'])) {
        $image_data = file_get_contents($company['logo_path']);
        $image_type = pathinfo($company['logo_path'], PATHINFO_EXTENSION);
        $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
    }
    
    $subject = "✅ Payment Received - Receipt #{$payment['receipt_number']}";
    
    // Build breakdown HTML
    $breakdown_html = '';
    if ($payment['interest_amount'] > 0) {
        $breakdown_html .= "<tr><td>Interest Payment</td><td style='text-align: right; color: #ecc94b; font-weight: 600;'>₹ " . number_format($payment['interest_amount'], 2) . "</td></tr>";
    }
    if ($payment['overdue_amount'] > 0) {
        $breakdown_html .= "<tr><td>Overdue Payment</td><td style='text-align: right; color: #f56565; font-weight: 600;'>₹ " . number_format($payment['overdue_amount'], 2) . "</td></tr>";
    }
    if ($payment['overdue_charge'] > 0) {
        $breakdown_html .= "<tr><td>Overdue Charge</td><td style='text-align: right; color: #4299e1; font-weight: 600;'>₹ " . number_format($payment['overdue_charge'], 2) . "</td></tr>";
    }
    
    $payment_method_icons = [
        'cash' => '💰',
        'bank' => '🏦',
        'upi' => '📱',
        'cheque' => '📝',
        'other' => '💳'
    ];
    $payment_icon = $payment_method_icons[$payment['payment_method']] ?? '💰';
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Payment Confirmation</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%); border: 2px solid #48bb78; border-radius: 12px; padding: 25px; text-align: center; margin: 20px 0; }
            .amount { font-size: 48px; font-weight: 700; color: #22543d; }
            .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #f7fafc; border-radius: 12px; overflow: hidden; }
            .details-table th { background: #48bb78; color: white; padding: 15px; text-align: left; }
            .details-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
            .balance-info { background: #ebf4ff; padding: 20px; border-radius: 12px; margin: 20px 0; border-left: 4px solid #4299e1; }
            .balance-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #cbd5e0; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
            .button { display: inline-block; padding: 12px 30px; background: #48bb78; color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin-top: 20px; }
            @media (max-width: 480px) { .amount { font-size: 36px; } }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                " . ($logo_base64 ? "<img src='{$logo_base64}' style='height: 50px; margin-bottom: 10px;'>" : "") . "
                <h1>Payment Received! ✅</h1>
                <p>Thank you for your payment</p>
            </div>
            <div class='content'>
                <p style='font-size: 18px;'>Dear <strong>" . htmlspecialchars($payment['customer_name']) . "</strong>,</p>
                <p>We have received your payment successfully. Thank you for your timely payment.</p>
                <div class='amount-box'>
                    <div style='font-size: 14px; color: #22543d;'>Total Amount Received</div>
                    <div class='amount'>₹ " . number_format($payment['total_amount'], 2) . "</div>
                </div>
                <table class='details-table'>
                    <thead><tr><th colspan='2'>Payment Details</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Receipt Number</strong></td><td><strong style='color: #48bb78;'>{$payment['receipt_number']}</strong></td></tr>
                        <tr><td>Payment Date</td><td>" . date('d-m-Y', strtotime($payment['payment_date'])) . "</td></tr>
                        <tr><td>Loan Receipt</td><td>{$payment['loan_receipt']}</td></tr>
                        <tr><td>Payment Method</td><td>{$payment_icon} " . strtoupper($payment['payment_method']) . "</td></tr>
                    </tbody>
                </table>
                <table class='details-table'>
                    <thead><tr><th colspan='2'>Payment Breakdown</th></tr></thead>
                    <tbody>{$breakdown_html}<tr style='background:#e6fffa; font-weight:700;'><td><strong>GRAND TOTAL</strong></td><td style='text-align:right; color:#48bb78; font-size:18px;'><strong>₹ " . number_format($payment['total_amount'], 2) . "</strong></td></tr></tbody>
                </table>
                <div class='balance-info'>
                    <h4 style='color:#2c5282;'>📊 Updated Balance</h4>
                    <div class='balance-row'><span>Remaining Principal:</span><span style='font-weight:600; color:#48bb78;'>₹ " . number_format($payment['remaining_principal'], 2) . "</span></div>
                    <div class='balance-row'><span>Remaining Interest:</span><span style='font-weight:600; color:#ecc94b;'>₹ " . number_format($payment['remaining_interest'] ?? 0, 2) . "</span></div>
                </div>
                <div style='text-align:center;'><a href='https://wealthrot.in/gold/view-personal-loan.php?receipt={$payment['loan_receipt']}' class='button'>View Loan Details</a></div>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $result = sendEmailPHPMailer($payment['customer_email'], $subject, $body, ['id' => 0, 'receipt_number' => $payment['receipt_number']], $conn);
    return $result['success'];
}

/**
 * Send Principal Payment Confirmation Email
 */
function sendPrincipalPaymentConfirmationEmail($payment, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded || empty($payment['customer_email'])) {
        error_log("Principal payment confirmation email not sent: Email disabled or no recipient");
        return false;
    }
    
    // Get company/branch settings
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => FROM_EMAIL,
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    // Check if logo exists
    $logo_base64 = '';
    if (!empty($company['logo_path']) && file_exists($company['logo_path'])) {
        $image_data = file_get_contents($company['logo_path']);
        $image_type = pathinfo($company['logo_path'], PATHINFO_EXTENSION);
        $logo_base64 = 'data:image/' . $image_type . ';base64,' . base64_encode($image_data);
    }
    
    $loan_fully_paid = $payment['loan_fully_paid'] ?? false;
    $subject = $loan_fully_paid ? "🎉 Loan Fully Paid - Receipt #{$payment['receipt_number']}" : "✅ Principal Payment Received - Receipt #{$payment['receipt_number']}";
    
    $payment_method_icons = [
        'cash' => '💰',
        'bank' => '🏦',
        'upi' => '📱',
        'cheque' => '📝',
        'other' => '💳'
    ];
    $payment_icon = $payment_method_icons[$payment['payment_method']] ?? '💰';
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Principal Payment Confirmation</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%); border: 2px solid #4299e1; border-radius: 12px; padding: 25px; text-align: center; margin: 20px 0; }
            .amount { font-size: 48px; font-weight: 700; color: #2c5282; }
            .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #f7fafc; border-radius: 12px; overflow: hidden; }
            .details-table th { background: #4299e1; color: white; padding: 15px; text-align: left; }
            .details-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
            .balance-info { background: #ebf4ff; padding: 20px; border-radius: 12px; margin: 20px 0; border-left: 4px solid #4299e1; }
            .balance-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #cbd5e0; }
            .success-box { background: #c6f6d5; border: 2px solid #48bb78; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
            .button { display: inline-block; padding: 12px 30px; background: #4299e1; color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin-top: 20px; }
            @media (max-width: 480px) { .amount { font-size: 36px; } }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                " . ($logo_base64 ? "<img src='{$logo_base64}' style='height: 50px; margin-bottom: 10px;'>" : "") . "
                <h1>" . ($loan_fully_paid ? "🎉 Loan Fully Paid!" : "✅ Principal Payment Received") . "</h1>
                <p>Thank you for your payment</p>
            </div>
            <div class='content'>
                <p style='font-size: 18px;'>Dear <strong>" . htmlspecialchars($payment['customer_name']) . "</strong>,</p>
                <p>" . ($loan_fully_paid ? "Congratulations! You have successfully paid off your entire loan amount." : "We have received your principal payment successfully.") . "</p>
                " . ($loan_fully_paid ? "<div class='success-box'><h3 style='color:#22543d;'>🎊 LOAN FULLY PAID 🎊</h3><p style='color:#22543d;'>Your loan has been successfully closed. Thank you for choosing WEALTHROT!</p></div>" : "") . "
                <div class='amount-box'>
                    <div style='font-size: 14px; color: #2c5282;'>Principal Amount Paid</div>
                    <div class='amount'>₹ " . number_format($payment['total_amount'], 2) . "</div>
                </div>
                <table class='details-table'>
                    <thead><tr><th colspan='2'>Payment Details</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Receipt Number</strong></td><td><strong style='color: #4299e1;'>{$payment['receipt_number']}</strong></td></tr>
                        <tr><td>Payment Date</td><td>" . date('d-m-Y', strtotime($payment['payment_date'])) . "</td></tr>
                        <tr><td>Loan Receipt</td><td>{$payment['loan_receipt']}</td></tr>
                        <tr><td>Payment Method</td><td>{$payment_icon} " . strtoupper($payment['payment_method']) . "</td></tr>
                    </tbody>
                </table>
                <div class='balance-info'>
                    <h4 style='color:#2c5282;'>📊 Updated Balance</h4>
                    <div class='balance-row'><span>Remaining Principal:</span><span style='color:#48bb78; font-weight:600;'>₹ " . number_format($payment['remaining_principal'], 2) . "</span></div>
                </div>
                <div style='text-align:center;'><a href='https://wealthrot.in/gold/view-personal-loan.php?receipt={$payment['loan_receipt']}' class='button'>View Loan Details</a></div>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $result = sendEmailPHPMailer($payment['customer_email'], $subject, $body, ['id' => 0, 'receipt_number' => $payment['receipt_number']], $conn);
    return $result['success'];
}



/**
 * Send email using PHPMailer
 */
function sendEmailPHPMailer($to, $subject, $body, $loan, $conn) {
    global $phpmailer_loaded;
    
    if (!$phpmailer_loaded) {
        error_log("PHPMailer not loaded, cannot send email");
        return ['success' => false, 'message' => 'PHPMailer not loaded'];
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = str_replace(' ', '', SMTP_PASSWORD);
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        
        // Recipients
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(FROM_EMAIL, FROM_NAME);
        
        // Add admin as BCC
        if (!empty(ADMIN_EMAIL)) {
            $mail->addBCC(ADMIN_EMAIL);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $body));
        
        $mail->send();
        
        // Log success
        logEmailStatus($loan['id'] ?? 0, $loan['receipt_number'] ?? '', $to, 'sent', null, $conn);
        error_log("Email sent successfully to: " . $to);
        
        return ['success' => true, 'message' => 'Email sent'];
        
    } catch (Exception $e) {
        $error = "Mailer Error: " . $mail->ErrorInfo;
        error_log($error);
        
        // Log failure
        logEmailStatus($loan['id'] ?? 0, $loan['receipt_number'] ?? '', $to, 'failed', $error, $conn);
        
        return ['success' => false, 'message' => $error];
    }
}

/**
 * Log email status to database
 */
function logEmailStatus($loan_id, $receipt_number, $email, $status, $error, $conn) {
    // Create email_logs table if not exists
    $create_table = "CREATE TABLE IF NOT EXISTS email_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        receipt_number VARCHAR(20) NOT NULL,
        customer_email VARCHAR(100),
        status ENUM('sent','failed','pending') DEFAULT 'pending',
        error_message TEXT,
        sent_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_table);
    
    $query = "INSERT INTO email_logs (loan_id, receipt_number, customer_email, status, error_message, sent_at) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'issss', $loan_id, $receipt_number, $email, $status, $error);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * Test email configuration
 */
function testEmailConfig() {
    global $phpmailer_loaded;
    
    $test_result = [
        'phpmailer_loaded' => $phpmailer_loaded,
        'smtp_host' => SMTP_HOST,
        'smtp_port' => SMTP_PORT,
        'smtp_username' => SMTP_USERNAME,
        'smtp_password_length' => strlen(str_replace(' ', '', SMTP_PASSWORD)),
        'from_email' => FROM_EMAIL,
        'email_enabled' => EMAIL_ENABLED
    ];
    
    return $test_result;
}


/**
 * Send Loan Closure Confirmation Email with Jewelry Return Details
 */



/**
 * Send Loan Closure Confirmation Email with Jewelry Return Details
 */
/**
 * Send Loan Closure Confirmation Email with Jewelry Return Details
 */
function sendLoanClosureConfirmationEmail($details, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded || empty($details['customer_email'])) {
        error_log("Loan closure confirmation email not sent");
        return false;
    }
    
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => '',
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    $subject = "🎉 Loan Closed Successfully - Receipt #{$details['loan_receipt']}";
    
    // Build items HTML
    $items_html = '';
    if (!empty($details['items']) && is_array($details['items'])) {
        foreach ($details['items'] as $item) {
            $items_html .= "
            <tr>
                <td style='padding: 8px; border-bottom: 1px solid #e2e8f0;'>" . htmlspecialchars($item['jewel_name'] ?? '') . "</td>
                <td style='padding: 8px; border-bottom: 1px solid #e2e8f0;'>" . ($item['karat'] ?? '') . "K</td>
                <td style='padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>" . ($item['net_weight'] ?? 0) . " g</td>
                <td style='padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: center;'>" . ($item['quantity'] ?? 1) . "</td>
            </tr>
            ";
        }
    }
    
    // Fix: Check if receiving_person exists, otherwise use collection_person or default
    $receiving_person = $details['receiving_person'] ?? $details['collection_person'] ?? 'Customer';
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Loan Closure Confirmation</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .success-box { background: #c6f6d5; border: 2px solid #48bb78; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
            .details-table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #f7fafc; border-radius: 12px; overflow: hidden; }
            .details-table th { background: #48bb78; color: white; padding: 12px; text-align: left; }
            .details-table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
            .items-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            .items-table th { background: #667eea; color: white; padding: 10px; text-align: left; font-size: 13px; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Loan Closed Successfully! 🎉</h1>
                <p>Thank you for choosing WEALTHROT</p>
            </div>
            <div class='content'>
                <div class='success-box'>
                    <h3 style='color:#22543d;'>✓ LOAN CLOSED & JEWELRY RETURNED</h3>
                    <p style='color:#22543d;'>Your loan has been successfully closed and your jewelry has been returned.</p>
                </div>
                
                <p>Dear <strong>" . htmlspecialchars($details['customer_name'] ?? '') . "</strong>,</p>
                <p>We are pleased to inform you that your loan has been successfully closed. Your jewelry has been returned to you.</p>
                
                <table class='details-table'>
                    <thead><tr><th colspan='2'>Loan Closure Details</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Loan Receipt</strong></td><td>" . htmlspecialchars($details['loan_receipt'] ?? '') . "</td></tr>
                        <tr><td><strong>Closure Date</strong></td><td>" . date('d-m-Y', strtotime($details['close_date'] ?? date('Y-m-d'))) . "</td></tr>
                        <tr><td><strong>Principal Paid</strong></td><td>₹ " . number_format($details['remaining_principal'] ?? 0, 2) . "</td></tr>
                        <tr><td><strong>Interest Paid</strong></td><td>₹ " . number_format($details['interest_paid'] ?? 0, 2) . "</td></tr>
                        <tr><td><strong>Total Amount Paid</strong></td><td><strong>₹ " . number_format($details['total_paid'] ?? 0, 2) . "</strong></td></tr>
                    </tbody>
                </table>
                
                <h4 style='margin: 20px 0 10px;'>💎 Jewelry Returned</h4>
                <table class='items-table'>
                    <thead><tr><th>Item Name</th><th>Karat</th><th>Weight</th><th>Qty</th></tr></thead>
                    <tbody>{$items_html}</tbody>
                </table>
                
                <p><strong>Received by:</strong> " . htmlspecialchars($receiving_person) . "</p>
                <p><strong>Thank you for your business!</strong> We look forward to serving you again.</p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name'] ?? 'WEALTHROT') . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $result = sendEmailPHPMailer($details['customer_email'], $subject, $body, ['id' => 0, 'receipt_number' => $details['loan_receipt']], $conn);
    return $result['success'];
}






?>





