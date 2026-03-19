<?php
/**
 * Email Helper for Personal Loan Processes
 * Handles all email notifications related to personal loans
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_config.php';

/**
 * Send Personal Loan Request Confirmation to Customer
 */
function sendPersonalLoanRequestConfirmation($request_id, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
    }
    
    // Get request details
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
    
    // Get company/branch settings
    $company = getCompanySettings($conn);
    
    // Determine the requested amount
    $requested_amount = isset($request['requested_amount']) ? floatval($request['requested_amount']) : 
                       (isset($request['request_amount']) ? floatval($request['request_amount']) : 0);
    
    $subject = "✅ Personal Loan Request Received - Receipt #{$request['receipt_number']}";
    
    $body = getPersonalLoanRequestEmailBody($request, $request_id, $requested_amount, $company);
    
    return sendEmailPHPMailer($request['email'], $subject, $body, $request, $conn);
}

/**
 * Send Personal Loan Offer Email to Customer
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
    
    // Get company settings
    $company = getCompanySettings($conn);
    
    $subject = "💰 Exclusive Personal Loan Offer - Additional ₹" . number_format($personal_amount, 0) . " Available";
    
    $body = getPersonalLoanOfferEmailBody($loan, $personal_amount, $regular_amount, $total_amount, $personal_percent, $regular_percent, $company);
    
    return sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
}

/**
 * Send Personal Loan Approval Email to Customer
 */
function sendPersonalLoanApprovalEmail($request, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
    }
    
    // Get company settings
    $company = getCompanySettings($conn);
    
    $approved_amount = $request['approved_amount'] ?? $request['requested_amount'] ?? 0;
    
    $subject = "🎉 Congratulations! Your Personal Loan is Approved!";
    
    $body = getPersonalLoanApprovalEmailBody($request, $approved_amount, $company);
    
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
    
    // Get company settings
    $company = getCompanySettings($conn);
    
    $subject = "Update on Your Personal Loan Request";
    
    $body = getPersonalLoanRejectionEmailBody($request, $company);
    
    return sendEmailPHPMailer($request['email'], $subject, $body, $request, $conn);
}

/**
 * Send Personal Loan Creation Email to Customer
 */
function sendPersonalLoanCreationEmail($personal_loan_id, $conn) {
    global $phpmailer_loaded;
    
    if (!EMAIL_ENABLED || !$phpmailer_loaded) {
        return ['success' => false, 'message' => 'Email disabled or PHPMailer not loaded'];
    }
    
    // Get personal loan details
    $query = "SELECT pl.*, 
                     c.customer_name, c.email, c.mobile_number, c.customer_photo,
                     c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                     u.name as employee_name,
                     l.receipt_number as original_receipt
              FROM personal_loans pl
              LEFT JOIN customers c ON pl.customer_id = c.id
              LEFT JOIN users u ON pl.employee_id = u.id
              LEFT JOIN personal_loan_requests pr ON pl.personal_loan_request_id = pr.id
              LEFT JOIN loans l ON pr.loan_id = l.id
              WHERE pl.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("Failed to prepare personal loan query: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'Database error'];
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $personal_loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan || empty($loan['email'])) {
        error_log("Personal loan not found or no email for ID: " . $personal_loan_id);
        return ['success' => false, 'message' => 'Loan not found or no email'];
    }
    
    // Get company settings
    $company = getCompanySettings($conn);
    
    // Format address
    $customer_address = formatCustomerAddress($loan);
    
    // Calculate amounts
    $loan_amount = floatval($loan['loan_amount'] ?? 0);
    $process_charge = floatval($loan['process_charge'] ?? 0);
    $appraisal_charge = floatval($loan['appraisal_charge'] ?? 0);
    $total_payable = floatval($loan['total_payable'] ?? ($loan_amount + $process_charge + $appraisal_charge));
    $interest_rate = floatval($loan['interest_rate'] ?? 0);
    $tenure_months = intval($loan['tenure_months'] ?? 12);
    $emi_amount = floatval($loan['emi_amount'] ?? 0);
    
    // Calculate total interest
    $total_interest = ($emi_amount * $tenure_months) - $loan_amount;
    
    $subject = "✅ Personal Loan Created Successfully - Receipt #{$loan['receipt_number']}";
    
    $body = getPersonalLoanCreationEmailBody($loan, $loan_amount, $process_charge, $appraisal_charge, 
                                            $total_payable, $interest_rate, $tenure_months, $emi_amount, 
                                            $total_interest, $customer_address, $company);
    
    return sendEmailPHPMailer($loan['email'], $subject, $body, $loan, $conn);
}

/**
 * Get Company Settings
 */
function getCompanySettings($conn) {
    $company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    if (!$company) {
        $company = [
            'branch_name' => 'WEALTHROT',
            'address' => 'Main Branch',
            'phone' => 'N/A',
            'email' => FROM_EMAIL,
            'logo_path' => ''
        ];
    }
    
    return $company;
}

/**
 * Format Customer Address
 */
function formatCustomerAddress($loan) {
    $address_parts = array_filter([
        $loan['door_no'] ?? '',
        $loan['house_name'] ?? '',
        $loan['street_name'] ?? '',
        $loan['location'] ?? '',
        $loan['district'] ?? ''
    ]);
    $address = !empty($address_parts) ? implode(', ', $address_parts) : 'Address not available';
    if (!empty($loan['pincode'])) {
        $address .= ' - ' . $loan['pincode'];
    }
    return $address;
}

/**
 * Get Personal Loan Request Email Body
 */
function getPersonalLoanRequestEmailBody($request, $request_id, $requested_amount, $company) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Request Confirmation</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 40px; }
            .success-icon { width: 80px; height: 80px; background: #48bb78; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; margin: 0 auto 20px; }
            .info-box { background: #ebf4ff; padding: 25px; border-radius: 12px; margin: 30px 0; }
            .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #cbd5e0; }
            .info-row:last-child { border-bottom: none; }
            .info-label { color: #4a5568; font-weight: 500; }
            .info-value { font-weight: 600; color: #2d3748; }
            .amount-highlight { font-size: 24px; font-weight: 700; color: #48bb78; }
            .steps { background: #f7fafc; border-radius: 12px; padding: 20px; margin: 30px 0; }
            .steps h3 { color: #2d3748; margin-bottom: 15px; }
            .steps ol { margin-left: 20px; color: #4a5568; }
            .steps li { margin: 10px 0; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
            .badge { display: inline-block; background: #ecc94b; color: #744210; padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Request Received! 🎉</h1>
            </div>
            <div class="content">
                <div class="success-icon">✓</div>
                
                <p style="text-align: center; font-size: 18px; color: #2d3748;">
                    Dear <strong>' . htmlspecialchars($request['customer_name']) . '</strong>,
                </p>
                
                <p style="text-align: center; color: #718096; margin-bottom: 30px;">
                    Thank you for accepting our personal loan offer. Your request has been successfully submitted.
                </p>
                
                <div class="info-box">
                    <h3 style="color: #2c5282; margin-bottom: 15px;">📋 Request Details</h3>
                    <div class="info-row"><span class="info-label">Request ID:</span><span class="info-value">#' . $request_id . '</span></div>
                    <div class="info-row"><span class="info-label">Loan Receipt:</span><span class="info-value">' . $request['receipt_number'] . '</span></div>
                    <div class="info-row"><span class="info-label">Requested Amount:</span><span class="info-value amount-highlight">₹' . number_format($requested_amount, 2) . '</span></div>
                    <div class="info-row"><span class="info-label">Status:</span><span class="info-value"><span class="badge">Pending Review</span></span></div>
                    <div class="info-row"><span class="info-label">Date:</span><span class="info-value">' . date('d-m-Y H:i', strtotime($request['request_date'])) . '</span></div>
                </div>
                
                <div class="steps">
                    <h3>📌 What Happens Next?</h3>
                    <ol>
                        <li>An admin will review your request within <strong>24 hours</strong></li>
                        <li>You\'ll receive an email with the approval decision</li>
                        <li>If approved, you can create the personal loan</li>
                    </ol>
                </div>
                
                <p style="background: #fef3c7; padding: 15px; border-radius: 8px; font-size: 13px; color: #744210;">
                    <strong>📞 Need help?</strong> Contact our support team at ' . htmlspecialchars($company['phone']) . '.
                </p>
            </div>
            <div class="footer">
                <p><strong>' . htmlspecialchars($company['branch_name']) . '</strong></p>
                <p>' . htmlspecialchars($company['address']) . '</p>
                <p>© ' . date('Y') . ' WEALTHROT. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Get Personal Loan Offer Email Body
 */
function getPersonalLoanOfferEmailBody($loan, $personal_amount, $regular_amount, $total_amount, $personal_percent, $regular_percent, $company) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Personal Loan Offer</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #ecc94b 0%, #fbbf24 100%); color: #744210; padding: 30px; text-align: center; position: relative; overflow: hidden; }
            .header::before { content: "💰"; position: absolute; right: -20px; top: -20px; font-size: 120px; opacity: 0.2; transform: rotate(15deg); }
            .header h1 { margin: 0; font-size: 32px; font-weight: 700; position: relative; z-index: 1; }
            .content { padding: 40px; }
            .offer-badge { display: inline-block; background: #ecc94b; color: #744210; padding: 8px 20px; border-radius: 30px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
            .greeting { font-size: 18px; color: #2d3748; margin-bottom: 20px; }
            .amount-box { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #ecc94b; border-radius: 16px; padding: 30px; text-align: center; margin: 30px 0; }
            .amount-label { font-size: 14px; color: #744210; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }
            .amount { font-size: 48px; font-weight: 700; color: #ecc94b; line-height: 1.2; margin-bottom: 10px; }
            .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 30px 0; }
            .info-item { background: #f7fafc; padding: 20px; border-radius: 12px; text-align: center; }
            .info-label { font-size: 12px; color: #718096; margin-bottom: 8px; text-transform: uppercase; }
            .info-value { font-size: 24px; font-weight: 700; }
            .info-value.regular { color: #48bb78; }
            .info-value.personal { color: #ecc94b; }
            .info-value.total { color: #667eea; }
            .loan-details { background: #ebf4ff; border-radius: 12px; padding: 20px; margin: 30px 0; }
            .button-container { text-align: center; margin: 30px 0; }
            .button { display: inline-block; padding: 16px 40px; margin: 10px; text-decoration: none; border-radius: 50px; font-weight: 600; font-size: 16px; transition: all 0.3s; box-shadow: 0 10px 20px rgba(0,0,0,0.1); }
            .button-accept { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; }
            .button-decline { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; border-top: 1px solid #e2e8f0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Special Personal Loan Offer! 🎉</h1>
            </div>
            <div class="content">
                <div class="offer-badge">✨ EXCLUSIVE OFFER</div>
                
                <div class="greeting">Dear <strong>' . htmlspecialchars($loan['customer_name']) . '</strong>,</div>
                
                <p>Congratulations! Based on your recent loan, you are pre-qualified for an additional personal loan.</p>
                
                <div class="amount-box">
                    <div class="amount-label">Your Personal Loan Offer</div>
                    <div class="amount">₹' . number_format($personal_amount, 2) . '</div>
                </div>
                
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Regular Loan</div><div class="info-value regular">₹' . number_format($regular_amount, 0) . '</div></div>
                    <div class="info-item"><div class="info-label">Personal Loan</div><div class="info-value personal">₹' . number_format($personal_amount, 0) . '</div></div>
                    <div class="info-item"><div class="info-label">Total</div><div class="info-value total">₹' . number_format($total_amount, 0) . '</div></div>
                </div>
                
                <div class="loan-details">
                    <p><strong>Loan Receipt:</strong> ' . $loan['receipt_number'] . '</p>
                    <p><strong>Interest Rate:</strong> ' . $loan['interest_amount'] . '%</p>
                </div>
                
                <div class="button-container">
                    <a href="https://wealthrot.in/gold/accept-personal-loan.php?loan_id=' . $loan['id'] . '" class="button button-accept">✅ Accept Offer</a>
                    <a href="https://wealthrot.in/gold/decline-personal-loan.php?loan_id=' . $loan['id'] . '" class="button button-decline">❌ Decline</a>
                </div>
                
                <p style="font-size: 12px; color: #718096; text-align: center;">This offer is valid for 7 days.</p>
            </div>
            <div class="footer">
                <p><strong>' . htmlspecialchars($company['branch_name']) . '</strong></p>
                <p>© ' . date('Y') . ' WEALTHROT</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Get Personal Loan Approval Email Body
 */
function getPersonalLoanApprovalEmailBody($request, $approved_amount, $company) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Loan Approved</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 40px; text-align: center; }
            .header h1 { margin: 0; font-size: 32px; }
            .content { padding: 40px; }
            .amount-box { background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%); border: 2px solid #48bb78; border-radius: 16px; padding: 30px; text-align: center; margin: 30px 0; }
            .amount { font-size: 48px; font-weight: 700; color: #22543d; }
            .info-box { background: #ebf4ff; padding: 25px; border-radius: 12px; margin: 30px 0; }
            .button { display: inline-block; padding: 16px 40px; background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; text-decoration: none; border-radius: 50px; font-weight: 600; margin: 20px 0; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header"><h1>Congratulations! 🎉</h1></div>
            <div class="content">
                <p>Dear <strong>' . htmlspecialchars($request['customer_name']) . '</strong>,</p>
                <p>Great news! Your personal loan request has been <strong style="color:#48bb78;">APPROVED</strong>.</p>
                <div class="amount-box"><div class="amount">₹' . number_format($approved_amount, 2) . '</div></div>
                <div class="info-box">
                    <p><strong>Loan Receipt:</strong> ' . $request['receipt_number'] . '</p>
                    <p><strong>Approved Date:</strong> ' . date('d-m-Y') . '</p>
                </div>
                <div style="text-align: center;">
                    <a href="https://wealthrot.in/gold/create-personal-loan.php?request_id=' . $request['id'] . '" class="button">Create Loan Now</a>
                </div>
            </div>
            <div class="footer"><p>© ' . date('Y') . ' WEALTHROT</p></div>
        </div>
    </body>
    </html>';
}

/**
 * Get Personal Loan Rejection Email Body
 */
function getPersonalLoanRejectionEmailBody($request, $company) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Loan Request Update</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #f56565 0%, #c53030 100%); color: white; padding: 30px; text-align: center; }
            .content { padding: 40px; }
            .info-box { background: #fed7d7; padding: 25px; border-radius: 12px; margin: 30px 0; }
            .rejection-reason { background: white; padding: 20px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #f56565; }
            .footer { text-align: center; padding: 30px; background: #f7fafc; font-size: 13px; color: #718096; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header"><h1>Loan Request Update</h1></div>
            <div class="content">
                <p>Dear <strong>' . htmlspecialchars($request['customer_name']) . '</strong>,</p>
                <p>Regarding your personal loan request for loan <strong>' . $request['receipt_number'] . '</strong>.</p>
                <div class="info-box">
                    <p><strong>Status:</strong> Not Approved</p>
                    ' . (!empty($request['rejection_reason']) ? '<div class="rejection-reason"><strong>Reason:</strong><br>' . nl2br(htmlspecialchars($request['rejection_reason'])) . '</div>' : '') . '
                </div>
                <p>If you have questions, please contact our support team.</p>
            </div>
            <div class="footer"><p>© ' . date('Y') . ' WEALTHROT</p></div>
        </div>
    </body>
    </html>';
}

/**
 * Get Personal Loan Creation Email Body
 */
function getPersonalLoanCreationEmailBody($loan, $loan_amount, $process_charge, $appraisal_charge, $total_payable, 
                                         $interest_rate, $tenure_months, $emi_amount, $total_interest, $customer_address, $company) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Personal Loan Confirmation</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px; }
            .amount-box { background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%); border: 2px solid #48bb78; border-radius: 12px; padding: 25px; text-align: center; margin: 20px 0; }
            .amount-value { font-size: 36px; font-weight: 700; color: #48bb78; }
            .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; background: #f7fafc; padding: 20px; border-radius: 12px; margin: 20px 0; }
            .info-item { margin-bottom: 10px; }
            .info-label { font-size: 12px; color: #718096; margin-bottom: 2px; text-transform: uppercase; }
            .info-value { font-size: 16px; font-weight: 600; color: #2d3748; }
            .charges-table { width: 100%; margin: 15px 0; background: #f8fafc; border-radius: 8px; }
            .charges-table td { padding: 10px; border-bottom: 1px dashed #e2e8f0; }
            .charges-table td:last-child { text-align: right; font-weight: 600; }
            .total-row { font-size: 18px; font-weight: 700; color: #48bb78; border-top: 2px solid #48bb78; }
            .button { display: inline-block; padding: 12px 30px; background: #48bb78; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 10px 0; }
            .footer { background: #f7fafc; padding: 20px; text-align: center; font-size: 12px; color: #718096; border-top: 1px solid #e2e8f0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header"><h1>Personal Loan Confirmed! 🎉</h1></div>
            <div class="content">
                <p>Dear <strong>' . htmlspecialchars($loan['customer_name']) . '</strong>,</p>
                <p>Your personal loan has been successfully created.</p>
                
                <div class="amount-box"><div class="amount-value">₹' . number_format($loan_amount, 2) . '</div></div>
                
                <div class="info-grid">
                    <div class="info-item"><div class="info-label">Receipt Number</div><div class="info-value">' . $loan['receipt_number'] . '</div></div>
                    <div class="info-item"><div class="info-label">Receipt Date</div><div class="info-value">' . date('d-m-Y', strtotime($loan['receipt_date'])) . '</div></div>
                    <div class="info-item"><div class="info-label">Interest Rate</div><div class="info-value">' . $interest_rate . '%</div></div>
                    <div class="info-item"><div class="info-label">Tenure</div><div class="info-value">' . $tenure_months . ' months</div></div>
                    <div class="info-item"><div class="info-label">EMI Amount</div><div class="info-value">₹' . number_format($emi_amount, 2) . '</div></div>
                </div>
                
                <table class="charges-table">
                    <tr><td>Principal Amount</td><td>₹' . number_format($loan_amount, 2) . '</td></tr>
                    <tr><td>Process Charge</td><td>₹' . number_format($process_charge, 2) . '</td></tr>
                    <tr><td>Appraisal Charge</td><td>₹' . number_format($appraisal_charge, 2) . '</td></tr>
                    <tr><td>Total Interest</td><td>₹' . number_format($total_interest, 2) . '</td></tr>
                    <tr class="total-row"><td>Total Payable</td><td>₹' . number_format($total_payable, 2) . '</td></tr>
                </table>
                
                <div style="text-align: center;">
                    <a href="https://wealthrot.in/gold/print-personal-loan-receipt.php?id=' . $loan['id'] . '" class="button" target="_blank">🖨️ Download Receipt</a>
                </div>
            </div>
            <div class="footer">
                <p><strong>' . htmlspecialchars($company['branch_name']) . '</strong></p>
                <p>© ' . date('Y') . ' WEALTHROT</p>
            </div>
        </div>
    </body>
    </html>';
}

/**
 * Send email using PHPMailer (shared function)
 */
function sendEmailPHPMailer($to, $subject, $body, $data, $conn) {
    global $phpmailer_loaded;
    
    if (!$phpmailer_loaded) {
        return ['success' => false, 'message' => 'PHPMailer not loaded'];
    }
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
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
        logPersonalLoanEmailStatus($data['id'] ?? 0, $data['receipt_number'] ?? '', $to, 'sent', null, $conn);
        error_log("Email sent successfully to: " . $to);
        
        return ['success' => true, 'message' => 'Email sent'];
        
    } catch (Exception $e) {
        $error = "Mailer Error: " . $mail->ErrorInfo;
        error_log($error);
        
        // Log failure
        logPersonalLoanEmailStatus($data['id'] ?? 0, $data['receipt_number'] ?? '', $to, 'failed', $error, $conn);
        
        return ['success' => false, 'message' => $error];
    }
}

/**
 * Log email status for personal loans
 */
function logPersonalLoanEmailStatus($record_id, $receipt_number, $email, $status, $error, $conn) {
    // Create email_logs_personal table if not exists
    $create_table = "CREATE TABLE IF NOT EXISTS email_logs_personal (
        id INT AUTO_INCREMENT PRIMARY KEY,
        personal_loan_id INT NOT NULL,
        receipt_number VARCHAR(20) NOT NULL,
        customer_email VARCHAR(100),
        status ENUM('sent','failed','pending') DEFAULT 'pending',
        error_message TEXT,
        sent_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    mysqli_query($conn, $create_table);
    
    $query = "INSERT INTO email_logs_personal (personal_loan_id, receipt_number, customer_email, status, error_message, sent_at) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'issss', $record_id, $receipt_number, $email, $status, $error);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
?>