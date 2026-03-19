<?php
// includes/simple_email.php

/**
 * Enhanced email sending function for Hostinger
 */
function sendLoanEmail($loan_id, $receipt_number, $customer_data, $loan_data, $conn) {
    
    // Enable error reporting for debugging
    error_log("Attempting to send email for loan #$receipt_number");
    
    // Recipients
    $to = 'dineshkarthi@gmail.com'; // Admin email
    
    // Add customer email if available
    if (!empty($customer_data['email'])) {
        $to .= ', ' . $customer_data['email'];
    }
    
    $subject = "🏦 New Loan Created - Receipt #{$receipt_number}";
    
    // Build HTML email
    $message = buildEmailHTML($receipt_number, $customer_data, $loan_data, $conn, $loan_id);
    
    // Plain text version
    $plain_message = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $message));
    
    // Headers for HTML email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Pawn Shop <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    $headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 1\r\n";
    
    // Additional headers for better deliverability
    $headers .= "Return-Path: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    
    // Send email
    $mail_sent = @mail($to, $subject, $message, $headers, "-f noreply@" . $_SERVER['HTTP_HOST']);
    
    // Log the attempt
    logEmailAttempt($conn, $loan_id, $receipt_number, $customer_data['email'] ?? '', $mail_sent);
    
    if ($mail_sent) {
        error_log("Email sent successfully for loan #$receipt_number");
    } else {
        $error = error_get_last();
        error_log("Email failed for loan #$receipt_number: " . ($error['message'] ?? 'Unknown error'));
    }
    
    return $mail_sent;
}

/**
 * Build HTML email content
 */
function buildEmailHTML($receipt_number, $customer_data, $loan_data, $conn, $loan_id) {
    
    // Get loan items
    $items_html = '';
    $items_query = "SELECT * FROM loan_items WHERE loan_id = ?";
    $items_stmt = mysqli_prepare($conn, $items_query);
    mysqli_stmt_bind_param($items_stmt, 'i', $loan_id);
    mysqli_stmt_execute($items_stmt);
    $items_result = mysqli_stmt_get_result($items_stmt);
    
    if (mysqli_num_rows($items_result) > 0) {
        $items_html .= '<table style="width:100%; border-collapse:collapse; margin:10px 0;">';
        $items_html .= '<thead><tr style="background:#667eea; color:white;">';
        $items_html .= '<th style="padding:8px; text-align:left;">Item</th>';
        $items_html .= '<th style="padding:8px; text-align:left;">Karat</th>';
        $items_html .= '<th style="padding:8px; text-align:left;">Weight</th>';
        $items_html .= '<th style="padding:8px; text-align:left;">Qty</th>';
        $items_html .= '</tr></thead><tbody>';
        
        while ($item = mysqli_fetch_assoc($items_result)) {
            $items_html .= '<tr style="border-bottom:1px solid #ddd;">';
            $items_html .= '<td style="padding:8px;">' . htmlspecialchars($item['jewel_name']) . '</td>';
            $items_html .= '<td style="padding:8px;">' . $item['karat'] . 'K</td>';
            $items_html .= '<td style="padding:8px;">' . $item['net_weight'] . 'g</td>';
            $items_html .= '<td style="padding:8px;">' . $item['quantity'] . '</td>';
            $items_html .= '</tr>';
        }
        $items_html .= '</tbody></table>';
    }
    
    // Build customer address
    $address_parts = [];
    if (!empty($customer_data['door_no'])) $address_parts[] = $customer_data['door_no'];
    if (!empty($customer_data['house_name'])) $address_parts[] = $customer_data['house_name'];
    if (!empty($customer_data['street_name'])) $address_parts[] = $customer_data['street_name'];
    if (!empty($customer_data['location'])) $address_parts[] = $customer_data['location'];
    if (!empty($customer_data['district'])) $address_parts[] = $customer_data['district'];
    $address = !empty($address_parts) ? implode(', ', $address_parts) : 'Not provided';
    
    $total_amount = $loan_data['loan_amount'] + $loan_data['receipt_charge'];
    $view_link = "https://" . $_SERVER['HTTP_HOST'] . "/pawndpi/view-loan.php?id=" . $loan_id;
    
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>New Loan Created</title>
    </head>
    <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin:0; padding:0;">
        <div style="max-width:600px; margin:20px auto; background:white; border-radius:10px; overflow:hidden; box-shadow:0 0 20px rgba(0,0,0,0.1);">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; padding:30px 20px; text-align:center;">
                <h2 style="margin:0; font-size:28px;">🏦 New Loan Created</h2>
                <p style="margin:10px 0 0; opacity:0.9;">Receipt #' . $receipt_number . '</p>
            </div>
            
            <div style="padding:30px;">
                <p>Dear Team,</p>
                <p>A new loan has been created with the following details:</p>
                
                <div style="background:#f8f9fa; padding:20px; margin:20px 0; border-radius:8px; border-left:4px solid #667eea;">
                    <h3 style="margin:0 0 15px; color:#2d3748; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">📋 Loan Information</h3>
                    <table style="width:100%;">
                        <tr><td style="padding:5px 0;"><strong>Receipt Date:</strong></td><td>' . date('d/m/Y', strtotime($loan_data['receipt_date'])) . '</td></tr>
                        <tr><td style="padding:5px 0;"><strong>Loan Amount:</strong></td><td><strong style="color:#28a745;">₹ ' . number_format($loan_data['loan_amount'], 2) . '</strong></td></tr>
                        <tr><td style="padding:5px 0;"><strong>Interest Rate:</strong></td><td>' . $loan_data['interest_amount'] . '%</td></tr>
                        <tr><td style="padding:5px 0;"><strong>Receipt Charge:</strong></td><td>₹ ' . number_format($loan_data['receipt_charge'], 2) . '</td></tr>
                        <tr><td style="padding:5px 0;"><strong>Total Payable:</strong></td><td><strong style="color:#28a745;">₹ ' . number_format($total_amount, 2) . '</strong></td></tr>
                    </table>
                </div>
                
                <div style="background:#f8f9fa; padding:20px; margin:20px 0; border-radius:8px; border-left:4px solid #667eea;">
                    <h3 style="margin:0 0 15px; color:#2d3748; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">👤 Customer Information</h3>
                    <table style="width:100%;">
                        <tr><td style="padding:5px 0;"><strong>Name:</strong></td><td>' . htmlspecialchars($customer_data['customer_name']) . '</td></tr>
                        <tr><td style="padding:5px 0;"><strong>Mobile:</strong></td><td>' . htmlspecialchars($customer_data['mobile_number']) . '</td></tr>';
    
    if (!empty($customer_data['email'])) {
        $html .= '<tr><td style="padding:5px 0;"><strong>Email:</strong></td><td>' . htmlspecialchars($customer_data['email']) . '</td></tr>';
    }
    
    if (!empty($customer_data['guardian_name'])) {
        $guardian = ($customer_data['guardian_type'] ?? '') . ' ' . $customer_data['guardian_name'];
        $html .= '<tr><td style="padding:5px 0;"><strong>Guardian:</strong></td><td>' . htmlspecialchars($guardian) . '</td></tr>';
    }
    
    $html .= '<tr><td style="padding:5px 0;"><strong>Address:</strong></td><td>' . htmlspecialchars($address) . '</td></tr>';
    $html .= '</table></div>';
    
    if (!empty($items_html)) {
        $html .= '<div style="background:#f8f9fa; padding:20px; margin:20px 0; border-radius:8px; border-left:4px solid #667eea;">
            <h3 style="margin:0 0 15px; color:#2d3748; border-bottom:2px solid #e2e8f0; padding-bottom:10px;">💎 Jewelry Items</h3>
            ' . $items_html . '
        </div>';
    }
    
    $html .= '<div style="text-align:center; margin:30px 0;">
            <a href="' . $view_link . '" style="display:inline-block; padding:12px 24px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white; text-decoration:none; border-radius:5px; font-weight:500;">🔍 View Full Details</a>
        </div>
        
        <p style="margin-top:20px; font-size:14px; color:#718096; text-align:center;">
            This is an automated notification from the Pawn Shop Management System.<br>
            Generated on: ' . date('d/m/Y H:i:s') . '
        </p>
        </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

/**
 * Log email attempt in database
 */
function logEmailAttempt($conn, $loan_id, $receipt_number, $customer_email, $success) {
    $status = $success ? 'sent' : 'failed';
    
    $query = "INSERT INTO email_logs (loan_id, receipt_number, customer_email, status, sent_at, created_at) 
              VALUES (?, ?, ?, ?, " . ($success ? 'NOW()' : 'NULL') . ", NOW())";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'isss', $loan_id, $receipt_number, $customer_email, $status);
        mysqli_stmt_execute($stmt);
    }
}
?>