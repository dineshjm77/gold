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
    
    // Get product value settings - Since loans table doesn't have product_type, use default values
    $regular_percent = 70; // Default
    $personal_percent = 20; // Default
    
    // Default to 'தங்கம்' (Gold) as fallback
    $product_type = 'தங்கம்';
    
    // Get settings from product_value_settings
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
    
    // Get jewelry items with all details
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
            $item_photo_html = '<img src="' . $item_photo_base64 . '" style="width:50px; height:50px; object-fit:cover; border-radius:4px; border:1px solid #48bb78;">';
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
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Loan Receipt</title>
        <style>
            body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f8fafc; }
            .container { max-width: 800px; margin: 20px auto; background: white; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden; }
            .header { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 30px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 28px; }
            .header p { margin: 5px 0 0; opacity: 0.9; }
            .content { padding: 30px; }
            .section { margin-bottom: 30px; }
            .section-title { 
                font-size: 18px; 
                font-weight: 600; 
                color: #2d3748; 
                margin-bottom: 15px; 
                padding-bottom: 8px; 
                border-bottom: 2px solid #e2e8f0; 
            }
            .info-grid { 
                display: grid; 
                grid-template-columns: repeat(2, 1fr); 
                gap: 15px; 
                background: #f8fafc; 
                padding: 15px; 
                border-radius: 8px; 
            }
            .info-item { margin-bottom: 10px; }
            .info-label { font-size: 12px; color: #718096; margin-bottom: 2px; }
            .info-value { font-size: 16px; font-weight: 600; color: #2d3748; }
            .amount-box { 
                background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%); 
                border: 1px solid #48bb78; 
                border-radius: 12px; 
                padding: 20px; 
                text-align: center; 
                margin: 20px 0; 
            }
            .amount-label { font-size: 14px; color: #276749; margin-bottom: 5px; }
            .amount-value { font-size: 36px; font-weight: 700; color: #48bb78; }
            .table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            .table th { background: #667eea; color: white; padding: 12px; text-align: left; font-size: 13px; }
            .table td { padding: 12px; border: 1px solid #e2e8f0; font-size: 13px; }
            .table tr:nth-child(even) { background: #f8fafc; }
            .badge { 
                display: inline-block; 
                padding: 4px 8px; 
                border-radius: 4px; 
                font-size: 11px; 
                font-weight: 600; 
                background: #48bb7820; 
                color: #276749; 
            }
            .footer { 
                background: #f7fafc; 
                padding: 20px; 
                text-align: center; 
                font-size: 12px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .button { 
                display: inline-block; 
                padding: 12px 25px; 
                background: #48bb78; 
                color: white; 
                text-decoration: none; 
                border-radius: 6px; 
                font-weight: 600; 
                margin: 5px; 
            }
            .button-warning { background: #ecc94b; color: #744210; }
            .charges-table { width: 100%; margin: 15px 0; background: #f8fafc; border-radius: 8px; }
            .charges-table td { padding: 10px; border-bottom: 1px dashed #e2e8f0; }
            .charges-table td:last-child { text-align: right; font-weight: 600; }
            .total-row { font-size: 18px; font-weight: 700; color: #48bb78; border-top: 2px solid #48bb78; }
            .customer-photo-container { text-align: center; margin-bottom: 20px; }
            @media (max-width: 600px) {
                .info-grid { grid-template-columns: 1fr; }
                .table { font-size: 12px; }
                .table td, .table th { padding: 8px; }
            }
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
                    <span class='badge'>Loan Receipt</span>
                </div>
                
                <p>Dear <strong>" . htmlspecialchars($loan['customer_name']) . "</strong>,</p>
                
                <p>Your loan has been successfully created. Below are the complete details:</p>
                
                <!-- Customer Photo -->
                " . ($customer_photo_html ? "<div class='customer-photo-container'>{$customer_photo_html}</div>" : "") . "
                
                <!-- Loan Amount Box -->
                <div class='amount-box'>
                    <div class='amount-label'>Loan Amount</div>
                    <div class='amount-value'>₹ " . number_format($principal, 2) . "</div>
                </div>
                
                <!-- Customer Information Section -->
                <div class='section'>
                    <div class='section-title'>👤 Customer Information</div>
                    <div class='info-grid'>
                        <div class='info-item'>
                            <div class='info-label'>Name</div>
                            <div class='info-value'>" . htmlspecialchars($loan['customer_name']) . "</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Mobile</div>
                            <div class='info-value'>" . htmlspecialchars($loan['mobile_number']) . "</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Email</div>
                            <div class='info-value'>" . htmlspecialchars($loan['email']) . "</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Address</div>
                            <div class='info-value'>" . htmlspecialchars($customer_address ?: 'N/A') . "</div>
                        </div>
                    </div>
                </div>
                
                <!-- Loan Information Section -->
                <div class='section'>
                    <div class='section-title'>📄 Loan Information</div>
                    <div class='info-grid'>
                        <div class='info-item'>
                            <div class='info-label'>Receipt Number</div>
                            <div class='info-value'>{$loan['receipt_number']}</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Receipt Date</div>
                            <div class='info-value'>{$receipt_date}</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Interest Rate</div>
                            <div class='info-value'>{$interest_rate}% (daily)</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Processed By</div>
                            <div class='info-value'>" . htmlspecialchars($loan['employee_name']) . "</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Gross Weight</div>
                            <div class='info-value'>" . number_format($total_gross, 3) . " g</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Net Weight</div>
                            <div class='info-value'>" . number_format($total_net, 3) . " g</div>
                        </div>
                        <div class='info-item'>
                            <div class='info-label'>Product Value</div>
                            <div class='info-value'>₹ " . number_format($product_value, 2) . "</div>
                        </div>
                    </div>
                </div>
                
                <!-- Charges Section -->
                <div class='section'>
                    <div class='section-title'>💰 Charges Breakdown</div>
                    <table class='charges-table'>
                        <tr><td>Principal Amount</td><td>₹ " . number_format($principal, 2) . "</td></tr>
                        <tr><td>Process Charge</td><td>₹ " . number_format($process_charge, 2) . "</td></tr>
                        <tr><td>Appraisal Charge</td><td>₹ " . number_format($appraisal_charge, 2) . "</td></tr>
                        <tr class='total-row'><td>Total Amount</td><td>₹ " . number_format($principal + $process_charge + $appraisal_charge, 2) . "</td></tr>
                    </table>
                </div>
                
                <!-- Personal Loan Offer Section -->
                " . ($personal_percent > 0 ? "
                <div class='section' style='background: #fef3c7; padding: 15px; border-radius: 8px; border-left: 4px solid #ecc94b;'>
                    <div style='display: flex; align-items: center; gap: 10px; margin-bottom: 15px;'>
                        <span style='font-size: 24px;'>💰</span>
                        <h3 style='margin: 0; color: #744210;'>Special Personal Loan Offer!</h3>
                    </div>
                    <p>Based on your jewelry value, you are eligible for an additional personal loan:</p>
                    <div style='background: white; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                        <table style='width: 100%;'>
                            <tr>
                                <td>Regular Loan ({$regular_percent}%)</td>
                                <td style='text-align: right; font-weight: 600; color: #48bb78;'>₹ " . number_format($regular_loan_amount, 2) . "</td>
                            </tr>
                            <tr>
                                <td>Personal Loan ({$personal_percent}%)</td>
                                <td style='text-align: right; font-weight: 600; color: #ecc94b;'>₹ " . number_format($personal_loan_amount, 2) . "</td>
                            </tr>
                            <tr style='border-top: 2px solid #ecc94b;'>
                                <td style='font-weight: 700;'>Total Available</td>
                                <td style='text-align: right; font-weight: 700; color: #667eea;'>₹ " . number_format($total_potential, 2) . "</td>
                            </tr>
                        </table>
                    </div>
                    <div style='text-align: center; margin: 20px 0;'>
                        <a href='https://wealthrot.in/gold/accept-personal-loan.php?loan_id={$loan_id}' class='button button-warning'>
                            ✅ Accept Personal Loan Offer
                        </a>
                    </div>
                    <p style='font-size: 12px; color: #744210; margin-top: 10px;'>
                        <i class='bi bi-info-circle'></i> This offer is valid for 7 days. Terms and conditions apply.
                    </p>
                </div>
                " : "") . "
                
                <!-- Jewelry Items Section -->
                <div class='section'>
                    <div class='section-title'>💎 Jewelry Items (" . $item_count . " Items)</div>
                    <table class='table'>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Item Name</th>
                                <th>Karat</th>
                                <th>Defect</th>
                                <th>Stone</th>
                                <th>Gross</th>
                                <th>Net</th>
                                <th>Qty</th>
                                <th>Photo</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$items_html}
                        </tbody>
                        <tfoot>
                            <tr style='background: #f7fafc; font-weight: 600;'>
                                <td colspan='5' style='text-align: right;'>Total Weight:</td>
                                <td>" . number_format($total_gross, 3) . " g</td>
                                <td>" . number_format($total_net, 3) . " g</td>
                                <td colspan='2'></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <div style='text-align: center; margin-top: 30px;'>
                    <a href='https://wealthrot.in/gold/view-loan.php?id={$loan_id}' class='button'>
                        View Loan Details Online
                    </a>
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
    
    // Send the main email
    $result = sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
    
    // If personal loan is available, send a second email
    if ($personal_percent > 0 && $result['success']) {
        sendPersonalLoanOfferEmail($loan_id, $conn);
    }
    
    return $result;
}

/**
 * Send Personal Loan Offer Email - IMPROVED UI
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
    if (!$stmt) {
        error_log("Failed to prepare personal loan query: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'Database error'];
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan || empty($loan['email'])) {
        error_log("Loan not found or no email for personal loan offer: " . $loan_id);
        return ['success' => false, 'message' => 'Loan not found or no email'];
    }
    
    // Get product value settings
    $product_type = 'தங்கம்'; // Default to gold
    
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
    
    // Get company/branch settings
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
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Personal Loan Offer</title>
        <style>
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #ecc94b 0%, #fbbf24 100%); 
                color: #744210; 
                padding: 30px; 
                text-align: center;
                position: relative;
                overflow: hidden;
            }
            .header::before {
                content: '💰';
                position: absolute;
                right: -20px;
                top: -20px;
                font-size: 120px;
                opacity: 0.2;
                transform: rotate(15deg);
            }
            .header h1 { 
                margin: 0; 
                font-size: 32px; 
                font-weight: 700;
                position: relative;
                z-index: 1;
            }
            .header p { 
                margin: 10px 0 0; 
                opacity: 0.9; 
                font-size: 16px;
                position: relative;
                z-index: 1;
            }
            .content { 
                padding: 40px; 
            }
            .offer-badge {
                display: inline-block;
                background: #ecc94b;
                color: #744210;
                padding: 8px 20px;
                border-radius: 30px;
                font-size: 14px;
                font-weight: 600;
                margin-bottom: 20px;
            }
            .greeting {
                font-size: 18px;
                color: #2d3748;
                margin-bottom: 20px;
            }
            .greeting strong {
                color: #667eea;
            }
            .amount-box { 
                background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); 
                border: 2px solid #ecc94b; 
                border-radius: 16px; 
                padding: 30px; 
                text-align: center; 
                margin: 30px 0; 
            }
            .amount-label { 
                font-size: 14px; 
                color: #744210; 
                margin-bottom: 10px; 
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .amount { 
                font-size: 48px; 
                font-weight: 700; 
                color: #ecc94b; 
                line-height: 1.2;
                margin-bottom: 10px;
            }
            .amount-note { 
                font-size: 14px; 
                color: #744210; 
            }
            .info-grid { 
                display: grid; 
                grid-template-columns: repeat(3, 1fr); 
                gap: 15px; 
                margin: 30px 0; 
            }
            .info-item { 
                background: #f7fafc; 
                padding: 20px; 
                border-radius: 12px; 
                text-align: center; 
            }
            .info-label { 
                font-size: 12px; 
                color: #718096; 
                margin-bottom: 8px; 
                text-transform: uppercase;
            }
            .info-value { 
                font-size: 24px; 
                font-weight: 700; 
            }
            .info-value.regular { 
                color: #48bb78; 
            }
            .info-value.personal { 
                color: #ecc94b; 
            }
            .info-value.total { 
                color: #667eea; 
            }
            .info-percent { 
                font-size: 12px; 
                color: #a0aec0; 
                margin-top: 5px; 
            }
            .loan-details {
                background: #ebf4ff;
                border-radius: 12px;
                padding: 20px;
                margin: 30px 0;
            }
            .loan-details h3 {
                color: #2c5282;
                margin-bottom: 15px;
                font-size: 16px;
            }
            .detail-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                border-bottom: 1px solid #cbd5e0;
            }
            .detail-row:last-child {
                border-bottom: none;
            }
            .detail-label {
                color: #4a5568;
                font-weight: 500;
            }
            .detail-value {
                font-weight: 600;
                color: #2d3748;
            }
            .button-container {
                text-align: center;
                margin: 30px 0;
            }
            .button { 
                display: inline-block; 
                padding: 16px 40px; 
                margin: 10px 10px; 
                text-decoration: none; 
                border-radius: 50px; 
                font-weight: 600; 
                font-size: 16px;
                transition: all 0.3s;
                box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            }
            .button:hover {
                transform: translateY(-2px);
                box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            }
            .button-accept { 
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); 
                color: white; 
            }
            .button-decline { 
                background: linear-gradient(135deg, #f56565 0%, #c53030 100%); 
                color: white; 
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .footer p {
                margin: 5px 0;
            }
            .note {
                background: #fef3c7;
                border-left: 4px solid #ecc94b;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
                font-size: 13px;
                color: #744210;
            }
            @media (max-width: 480px) {
                .content { padding: 20px; }
                .info-grid { grid-template-columns: 1fr; }
                .amount { font-size: 36px; }
                .button { display: block; margin: 10px 0; }
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Special Personal Loan Offer! 🎉</h1>
                <p>Exclusive offer for our valued customer</p>
            </div>
            <div class='content'>
                <div class='offer-badge'>✨ EXCLUSIVE OFFER</div>
                
                <div class='greeting'>
                    Dear <strong>" . htmlspecialchars($loan['customer_name']) . "</strong>,
                </div>
                
                <p style='color: #4a5568; margin-bottom: 20px;'>
                    Congratulations! Based on your recent loan, you are pre-qualified for an additional personal loan. 
                    This is a special offer available only to our premium customers.
                </p>
                
                <div class='amount-box'>
                    <div class='amount-label'>Your Personal Loan Offer</div>
                    <div class='amount'>₹ " . number_format($personal_amount, 2) . "</div>
                    <div class='amount-note'>at the same interest rate (" . $loan['interest_amount'] . "%)</div>
                </div>
                
                <div class='info-grid'>
                    <div class='info-item'>
                        <div class='info-label'>Regular Loan</div>
                        <div class='info-value regular'>₹" . number_format($regular_amount, 0) . "</div>
                        <div class='info-percent'>({$regular_percent}% of value)</div>
                    </div>
                    <div class='info-item'>
                        <div class='info-label'>Personal Loan</div>
                        <div class='info-value personal'>₹" . number_format($personal_amount, 0) . "</div>
                        <div class='info-percent'>({$personal_percent}% of value)</div>
                    </div>
                    <div class='info-item'>
                        <div class='info-label'>Total Available</div>
                        <div class='info-value total'>₹" . number_format($total_amount, 0) . "</div>
                    </div>
                </div>
                
                <div class='loan-details'>
                    <h3>📋 Loan Details</h3>
                    <div class='detail-row'>
                        <span class='detail-label'>Loan Receipt:</span>
                        <span class='detail-value'>{$loan['receipt_number']}</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Current Loan Amount:</span>
                        <span class='detail-value'>₹" . number_format($loan['loan_amount'], 2) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Product Value:</span>
                        <span class='detail-value'>₹" . number_format($loan['product_value'], 2) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='detail-label'>Interest Rate:</span>
                        <span class='detail-value'>{$loan['interest_amount']}%</span>
                    </div>
                </div>
                
                <div class='note'>
                    <strong>⏰ Limited Time Offer:</strong> This offer is valid for 7 days from today. 
                    The personal loan will be at the same interest rate as your current loan.
                </div>
                
                <div class='button-container'>
                    <a href='https://wealthrot.in/gold/accept-personal-loan.php?loan_id={$loan_id}' class='button button-accept'>
                        ✅ Accept Offer
                    </a>
                    <a href='https://wealthrot.in/gold/decline-personal-loan.php?loan_id={$loan_id}' class='button button-decline'>
                        ❌ Decline
                    </a>
                </div>
                
                <p style='text-align: center; color: #718096; font-size: 13px; margin-top: 20px;'>
                    By accepting this offer, you agree to our terms and conditions. 
                    An admin will contact you within 24 hours to process your request.
                </p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>" . htmlspecialchars($company['address']) . "</p>
                <p>📞 " . htmlspecialchars($company['phone']) . " | 📧 " . htmlspecialchars($company['email']) . "</p>
                <p style='margin-top: 15px;'>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
                <p style='font-size: 11px;'>This is an automated message, please do not reply.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
}

/**
 * Send Personal Loan Request Confirmation to Customer
 */
function sendPersonalLoanRequestConfirmation($request_id, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
    }
    
    // Get request details - FIXED: Use correct column names
    $query = "SELECT r.*, l.receipt_number, l.loan_amount, l.product_value, l.interest_amount,
                     c.customer_name, c.email, c.mobile_number
              FROM personal_loan_requests r
              JOIN loans l ON r.loan_id = l.id
              JOIN customers c ON r.customer_id = c.id
              WHERE r.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("Failed to prepare query: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'Database error'];
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $request_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $request = mysqli_fetch_assoc($result);
    
    if (!$request || empty($request['email'])) {
        error_log("Request not found or no email for personal loan confirmation: " . $request_id);
        return ['success' => false, 'message' => 'Request not found or no email'];
    }
    
    // Determine which column name is used for the requested amount
    $requested_amount = 0;
    if (isset($request['requested_amount']) && $request['requested_amount'] > 0) {
        $requested_amount = floatval($request['requested_amount']);
    } elseif (isset($request['request_amount']) && $request['request_amount'] > 0) {
        $requested_amount = floatval($request['request_amount']);
    } elseif (isset($request['personal_loan_amount']) && $request['personal_loan_amount'] > 0) {
        $requested_amount = floatval($request['personal_loan_amount']);
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
            'email' => FROM_EMAIL
        ];
    }
    
    $subject = "✅ Personal Loan Request Received - Receipt #{$request['receipt_number']}";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Request Confirmation</title>
        <style>
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); 
                color: white; 
                padding: 30px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 40px; }
            .success-icon {
                width: 80px;
                height: 80px;
                background: #48bb78;
                color: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
                margin: 0 auto 20px;
            }
            .info-box { 
                background: #ebf4ff; 
                padding: 25px; 
                border-radius: 12px; 
                margin: 30px 0; 
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid #cbd5e0;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                color: #4a5568;
                font-weight: 500;
            }
            .info-value {
                font-weight: 600;
                color: #2d3748;
            }
            .amount-highlight {
                font-size: 24px;
                font-weight: 700;
                color: #48bb78;
            }
            .steps {
                background: #f7fafc;
                border-radius: 12px;
                padding: 20px;
                margin: 30px 0;
            }
            .steps h3 {
                color: #2d3748;
                margin-bottom: 15px;
            }
            .steps ol {
                margin-left: 20px;
                color: #4a5568;
            }
            .steps li {
                margin: 10px 0;
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .badge {
                display: inline-block;
                background: #ecc94b;
                color: #744210;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Request Received! 🎉</h1>
            </div>
            <div class='content'>
                <div class='success-icon'>
                    ✓
                </div>
                
                <p style='text-align: center; font-size: 18px; color: #2d3748;'>
                    Dear <strong>" . htmlspecialchars($request['customer_name']) . "</strong>,
                </p>
                
                <p style='text-align: center; color: #718096; margin-bottom: 30px;'>
                    Thank you for accepting our personal loan offer. Your request has been successfully submitted.
                </p>
                
                <div class='info-box'>
                    <h3 style='color: #2c5282; margin-bottom: 15px;'>📋 Request Details</h3>
                    <div class='info-row'>
                        <span class='info-label'>Request ID:</span>
                        <span class='info-value'>#{$request_id}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Loan Receipt:</span>
                        <span class='info-value'>{$request['receipt_number']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Requested Amount:</span>
                        <span class='info-value amount-highlight'>₹" . number_format($requested_amount, 2) . "</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Status:</span>
                        <span class='info-value'><span class='badge'>Pending Review</span></span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Date:</span>
                        <span class='info-value'>" . date('d-m-Y H:i', strtotime($request['request_date'])) . "</span>
                    </div>
                </div>
                
                <div class='steps'>
                    <h3>📌 What Happens Next?</h3>
                    <ol>
                        <li>An admin will review your request within <strong>24 hours</strong></li>
                        <li>You'll receive an email with the approval decision</li>
                        <li>If approved, the amount will be disbursed to your registered bank account</li>
                        <li>You can track the status in your loan dashboard</li>
                    </ol>
                </div>
                
                <p style='background: #fef3c7; padding: 15px; border-radius: 8px; font-size: 13px; color: #744210;'>
                    <strong>📞 Need help?</strong> Contact our support team at " . htmlspecialchars($company['phone']) . " or visit our branch.
                </p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>" . htmlspecialchars($company['address']) . "</p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmailPHPMailer($request['email'], $subject, $body, $request, $conn);
}

/**
 * Send Personal Loan Approval Email to Customer
 */
function sendPersonalLoanApprovalEmail($request, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
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
            'email' => FROM_EMAIL
        ];
    }
    
    $approved_amount = $request['approved_amount'] ?? $request['request_amount'];
    
    $subject = "🎉 Congratulations! Your Personal Loan is Approved!";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Loan Approved</title>
        <style>
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); 
                color: white; 
                padding: 40px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 32px; }
            .content { padding: 40px; }
            .amount-box { 
                background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%); 
                border: 2px solid #48bb78; 
                border-radius: 16px; 
                padding: 30px; 
                text-align: center; 
                margin: 30px 0; 
            }
            .amount-label { 
                font-size: 14px; 
                color: #22543d; 
                margin-bottom: 10px; 
                text-transform: uppercase;
            }
            .amount { 
                font-size: 48px; 
                font-weight: 700; 
                color: #22543d; 
                line-height: 1.2;
            }
            .info-box { 
                background: #ebf4ff; 
                padding: 25px; 
                border-radius: 12px; 
                margin: 30px 0; 
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid #cbd5e0;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .button {
                display: inline-block;
                padding: 16px 40px;
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
                color: white;
                text-decoration: none;
                border-radius: 50px;
                font-weight: 600;
                font-size: 16px;
                margin: 20px 0;
                box-shadow: 0 10px 20px rgba(72,187,120,0.3);
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Congratulations! 🎉</h1>
                <p style='margin-top: 10px;'>Your Personal Loan is Approved</p>
            </div>
            <div class='content'>
                <p style='font-size: 18px; color: #2d3748;'>
                    Dear <strong>" . htmlspecialchars($request['customer_name']) . "</strong>,
                </p>
                
                <p style='color: #4a5568; margin-bottom: 20px;'>
                    Great news! Your personal loan request has been <strong style='color:#48bb78;'>APPROVED</strong>. 
                    We're pleased to inform you that the funds will be disbursed shortly.
                </p>
                
                <div class='amount-box'>
                    <div class='amount-label'>Approved Amount</div>
                    <div class='amount'>₹" . number_format($approved_amount, 2) . "</div>
                </div>
                
                <div class='info-box'>
                    <h3 style='color: #2c5282; margin-bottom: 15px;'>📋 Loan Details</h3>
                    <div class='info-row'>
                        <span class='info-label'>Loan Receipt:</span>
                        <span class='info-value'>{$request['receipt_number']}</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Approved Date:</span>
                        <span class='info-value'>" . date('d-m-Y') . "</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Disbursement:</span>
                        <span class='info-value'>Within 24-48 hours</span>
                    </div>
                </div>
                
                <div style='text-align: center;'>
                    <a href='https://wealthrot.in/gold/view-loan.php?id={$request['loan_id']}' class='button'>
                        View Loan Details
                    </a>
                </div>
                
                <p style='text-align: center; color: #718096; margin-top: 20px;'>
                    The amount will be credited to your registered bank account. 
                    You can visit our branch for any assistance.
                </p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>Thank you for choosing WEALTHROT!</p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmailPHPMailer($request['email'], $subject, $body, $request, $conn);
}

/**
 * Send Personal Loan Rejection Email to Customer
 */
function sendPersonalLoanRejectionEmail($request, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
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
            'email' => FROM_EMAIL
        ];
    }
    
    $subject = "Update on Your Personal Loan Request";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Loan Request Update</title>
        <style>
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #f56565 0%, #c53030 100%); 
                color: white; 
                padding: 30px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 40px; }
            .info-box { 
                background: #fed7d7; 
                padding: 25px; 
                border-radius: 12px; 
                margin: 30px 0; 
            }
            .rejection-reason {
                background: white;
                padding: 20px;
                border-radius: 8px;
                margin-top: 15px;
                border-left: 4px solid #f56565;
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .contact-box {
                background: #ebf4ff;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Loan Request Update</h1>
            </div>
            <div class='content'>
                <p style='font-size: 18px; color: #2d3748;'>
                    Dear <strong>" . htmlspecialchars($request['customer_name']) . "</strong>,
                </p>
                
                <p style='color: #4a5568; margin-bottom: 20px;'>
                    Regarding your personal loan request for loan <strong>{$request['receipt_number']}</strong>.
                </p>
                
                <div class='info-box'>
                    <p style='font-weight: 600; color: #c53030; margin-bottom: 10px;'>Status: Not Approved</p>
                    
                    " . (!empty($request['rejection_reason']) ? "
                    <div class='rejection-reason'>
                        <strong>Reason:</strong><br>
                        " . nl2br(htmlspecialchars($request['rejection_reason'])) . "
                    </div>
                    " : "") . "
                </div>
                
                <div class='contact-box'>
                    <p style='margin-bottom: 10px;'><strong>Need Assistance?</strong></p>
                    <p style='margin: 5px 0;'>If you have any questions or would like to discuss this further, please don't hesitate to contact us:</p>
                    <p style='margin: 10px 0;'>📞 " . htmlspecialchars($company['phone']) . "</p>
                    <p>📧 " . htmlspecialchars($company['email']) . "</p>
                </div>
                
                <p style='color: #4a5568; margin-top: 20px;'>
                    We value your business and hope to serve you better in the future. 
                    You're welcome to reapply or visit our branch for personalized assistance.
                </p>
            </div>
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmailPHPMailer($request['email'], $subject, $body, $request, $conn);
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
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); 
                color: white; 
                padding: 30px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { 
                background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%); 
                border: 2px solid #48bb78; 
                border-radius: 12px; 
                padding: 25px; 
                text-align: center; 
                margin: 20px 0; 
            }
            .amount-label { 
                font-size: 14px; 
                color: #22543d; 
                margin-bottom: 5px; 
                text-transform: uppercase;
            }
            .amount { 
                font-size: 48px; 
                font-weight: 700; 
                color: #22543d; 
                line-height: 1.2;
            }
            .details-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
                background: #f7fafc; 
                border-radius: 12px; 
                overflow: hidden;
            }
            .details-table th { 
                background: #48bb78; 
                color: white; 
                padding: 15px; 
                text-align: left; 
            }
            .details-table td { 
                padding: 12px 15px; 
                border-bottom: 1px solid #e2e8f0; 
            }
            .details-table tr:last-child td { 
                border-bottom: none; 
            }
            .balance-info { 
                background: #ebf4ff; 
                padding: 20px; 
                border-radius: 12px; 
                margin: 20px 0; 
                border-left: 4px solid #4299e1;
            }
            .balance-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px dashed #cbd5e0;
            }
            .balance-row:last-child {
                border-bottom: none;
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .button { 
                display: inline-block; 
                padding: 12px 30px; 
                background: #48bb78; 
                color: white; 
                text-decoration: none; 
                border-radius: 50px; 
                font-weight: 600; 
                margin-top: 20px; 
            }
            .badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            .badge-paid {
                background: #c6f6d5;
                color: #22543d;
            }
            .payment-method {
                display: inline-block;
                padding: 5px 15px;
                background: #edf2f7;
                border-radius: 30px;
                font-size: 14px;
                font-weight: 600;
            }
            .thank-you {
                font-size: 18px;
                color: #48bb78;
                text-align: center;
                margin: 20px 0;
            }
            @media (max-width: 480px) {
                .amount { font-size: 36px; }
                .content { padding: 20px; }
            }
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
                <p style='font-size: 18px; color: #2d3748;'>
                    Dear <strong>" . htmlspecialchars($payment['customer_name']) . "</strong>,
                </p>
                
                <p style='color: #4a5568; margin-bottom: 20px;'>
                    We have received your payment successfully. Thank you for your timely payment.
                </p>
                
                <div class='amount-box'>
                    <div class='amount-label'>Total Amount Received</div>
                    <div class='amount'>₹ " . number_format($payment['total_amount'], 2) . "</div>
                </div>
                
                <table class='details-table'>
                    <thead>
                        <tr>
                            <th colspan='2'>Payment Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Receipt Number</strong></td>
                            <td><strong style='color: #48bb78;'>{$payment['receipt_number']}</strong></td>
                        </tr>
                        <tr>
                            <td>Payment Date</td>
                            <td>" . date('d-m-Y', strtotime($payment['payment_date'])) . "</td>
                        </tr>
                        <tr>
                            <td>Loan Receipt</td>
                            <td>{$payment['loan_receipt']}</td>
                        </tr>
                        <tr>
                            <td>Payment Method</td>
                            <td><span class='payment-method'>{$payment_icon} " . strtoupper($payment['payment_method']) . "</span></td>
                        </tr>
                    </tbody>
                </table>
                
                <table class='details-table'>
                    <thead>
                        <tr>
                            <th colspan='2'>Payment Breakdown</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$breakdown_html}
                        <tr style='background: #e6fffa; font-weight: 700;'>
                            <td><strong>GRAND TOTAL</strong></td>
                            <td style='text-align: right; color: #48bb78; font-size: 18px;'><strong>₹ " . number_format($payment['total_amount'], 2) . "</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class='balance-info'>
                    <h4 style='color: #2c5282; margin-bottom: 15px;'>📊 Updated Balance</h4>
                    <div class='balance-row'>
                        <span>Remaining Principal:</span>
                        <span style='font-weight: 600; color: #48bb78;'>₹ " . number_format($payment['remaining_principal'], 2) . "</span>
                    </div>
                    <div class='balance-row'>
                        <span>Remaining Interest:</span>
                        <span style='font-weight: 600; color: #ecc94b;'>₹ " . number_format($payment['remaining_interest'] ?? 0, 2) . "</span>
                    </div>
                    <div class='balance-row' style='border-top: 2px solid #4299e1; margin-top: 10px; padding-top: 10px;'>
                        <span style='font-weight: 700;'>Total Outstanding:</span>
                        <span style='font-weight: 700; color: #667eea;'>₹ " . number_format(($payment['remaining_principal'] + ($payment['remaining_interest'] ?? 0)), 2) . "</span>
                    </div>
                </div>
                
                <div class='thank-you'>
                    🙏 Thank you for your business!
                </div>
                
                <div style='text-align: center;'>
                    <a href='https://wealthrot.in/gold/view-personal-loan.php?receipt={$payment['loan_receipt']}' class='button'>
                        View Loan Details
                    </a>
                </div>
                
                <p style='font-size: 12px; color: #718096; text-align: center; margin-top: 20px;'>
                    This is an automated email. For any questions, please contact our support team.
                </p>
            </div>
            
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>" . htmlspecialchars($company['address']) . "</p>
                <p>📞 " . htmlspecialchars($company['phone']) . " | 📧 " . htmlspecialchars($company['email']) . "</p>
                <p style='margin-top: 10px;'>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
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
            body { 
                font-family: 'Segoe UI', Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background: white; 
                border-radius: 20px; 
                box-shadow: 0 20px 60px rgba(0,0,0,0.3); 
                overflow: hidden;
            }
            .header { 
                background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); 
                color: white; 
                padding: 30px; 
                text-align: center;
            }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { 
                background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%); 
                border: 2px solid #4299e1; 
                border-radius: 12px; 
                padding: 25px; 
                text-align: center; 
                margin: 20px 0; 
            }
            .amount-label { 
                font-size: 14px; 
                color: #2c5282; 
                margin-bottom: 5px; 
                text-transform: uppercase;
            }
            .amount { 
                font-size: 48px; 
                font-weight: 700; 
                color: #2c5282; 
                line-height: 1.2;
            }
            .details-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
                background: #f7fafc; 
                border-radius: 12px; 
                overflow: hidden;
            }
            .details-table th { 
                background: #4299e1; 
                color: white; 
                padding: 15px; 
                text-align: left; 
            }
            .details-table td { 
                padding: 12px 15px; 
                border-bottom: 1px solid #e2e8f0; 
            }
            .details-table tr:last-child td { 
                border-bottom: none; 
            }
            .balance-info { 
                background: #ebf4ff; 
                padding: 20px; 
                border-radius: 12px; 
                margin: 20px 0; 
                border-left: 4px solid #4299e1;
            }
            .balance-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px dashed #cbd5e0;
            }
            .balance-row:last-child {
                border-bottom: none;
            }
            .success-box {
                background: #c6f6d5;
                border: 2px solid #48bb78;
                border-radius: 12px;
                padding: 20px;
                text-align: center;
                margin: 20px 0;
            }
            .success-box h3 {
                color: #22543d;
                margin-bottom: 10px;
            }
            .footer { 
                text-align: center; 
                padding: 30px; 
                background: #f7fafc; 
                font-size: 13px; 
                color: #718096; 
                border-top: 1px solid #e2e8f0; 
            }
            .button { 
                display: inline-block; 
                padding: 12px 30px; 
                background: #4299e1; 
                color: white; 
                text-decoration: none; 
                border-radius: 50px; 
                font-weight: 600; 
                margin-top: 20px; 
            }
            .payment-method {
                display: inline-block;
                padding: 5px 15px;
                background: #edf2f7;
                border-radius: 30px;
                font-size: 14px;
                font-weight: 600;
            }
            .badge-completed {
                background: #c6f6d5;
                color: #22543d;
                padding: 8px 20px;
                border-radius: 30px;
                font-size: 16px;
                font-weight: 600;
                display: inline-block;
            }
            @media (max-width: 480px) {
                .amount { font-size: 36px; }
                .content { padding: 20px; }
            }
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
                <p style='font-size: 18px; color: #2d3748;'>
                    Dear <strong>" . htmlspecialchars($payment['customer_name']) . "</strong>,
                </p>
                
                <p style='color: #4a5568; margin-bottom: 20px;'>
                    " . ($loan_fully_paid ? "Congratulations! You have successfully paid off your entire loan amount." : "We have received your principal payment successfully.") . "
                </p>
                
                " . ($loan_fully_paid ? "
                <div class='success-box'>
                    <h3>🎊 LOAN FULLY PAID 🎊</h3>
                    <p style='color: #22543d;'>Your loan has been successfully closed. Thank you for choosing WEALTHROT!</p>
                </div>
                " : "") . "
                
                <div class='amount-box'>
                    <div class='amount-label'>Principal Amount Paid</div>
                    <div class='amount'>₹ " . number_format($payment['total_amount'], 2) . "</div>
                </div>
                
                <table class='details-table'>
                    <thead>
                        <tr>
                            <th colspan='2'>Payment Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Receipt Number</strong></td>
                            <td><strong style='color: #4299e1;'>{$payment['receipt_number']}</strong></td>
                        </tr>
                        <tr>
                            <td>Payment Date</td>
                            <td>" . date('d-m-Y', strtotime($payment['payment_date'])) . "</td>
                        </tr>
                        <tr>
                            <td>Loan Receipt</td>
                            <td>{$payment['loan_receipt']}</td>
                        </tr>
                        <tr>
                            <td>Payment Method</td>
                            <td><span class='payment-method'>{$payment_icon} " . strtoupper($payment['payment_method']) . "</span></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class='balance-info'>
                    <h4 style='color: #2c5282; margin-bottom: 15px;'>📊 Updated Balance</h4>
                    <div class='balance-row'>
                        <span>Original Principal:</span>
                        <span style='font-weight: 600;'>₹ " . number_format($payment['original_principal'] ?? ($payment['remaining_principal'] + $payment['principal_amount']), 2) . "</span>
                    </div>
                    <div class='balance-row'>
                        <span>Principal Paid:</span>
                        <span style='font-weight: 600; color: #4299e1;'>₹ " . number_format($payment['principal_amount'], 2) . "</span>
                    </div>
                    <div class='balance-row'>
                        <span>Remaining Principal:</span>
                        <span style='font-weight: 600; color: #48bb78;'>₹ " . number_format($payment['remaining_principal'], 2) . "</span>
                    </div>
                    " . (!$loan_fully_paid ? "
                    <div class='balance-row' style='border-top: 2px solid #4299e1; margin-top: 10px; padding-top: 10px;'>
                        <span style='font-weight: 700;'>Outstanding Balance:</span>
                        <span style='font-weight: 700; color: #667eea;'>₹ " . number_format($payment['remaining_principal'], 2) . "</span>
                    </div>
                    " : "") . "
                </div>
                
                " . (!$loan_fully_paid ? "
                <div style='text-align: center; margin-top: 20px;'>
                    <p style='color: #718096;'>Your next payment will be due as per your regular schedule.</p>
                </div>
                " : "
                <div style='text-align: center; margin-top: 20px;'>
                    <span class='badge-completed'>✓ LOAN COMPLETED</span>
                </div>
                ") . "
                
                <div style='text-align: center; margin-top: 20px;'>
                    <a href='https://wealthrot.in/gold/view-personal-loan.php?receipt={$payment['loan_receipt']}' class='button'>
                        View Loan Details
                    </a>
                </div>
                
                <p style='font-size: 12px; color: #718096; text-align: center; margin-top: 20px;'>
                    This is an automated email. For any questions, please contact our support team.
                </p>
            </div>
            
            <div class='footer'>
                <p><strong>" . htmlspecialchars($company['branch_name']) . "</strong></p>
                <p>" . htmlspecialchars($company['address']) . "</p>
                <p>📞 " . htmlspecialchars($company['phone']) . " | 📧 " . htmlspecialchars($company['email']) . "</p>
                <p style='margin-top: 10px;'>© " . date('Y') . " WEALTHROT. All rights reserved.</p>
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
?>