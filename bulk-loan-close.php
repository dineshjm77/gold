<?php
session_start();
$currentPage = 'bulk-loan-close';
$pageTitle = 'Bulk Loan Close';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$customer = null;
$loans = [];
$multiple_customers = [];
$selected_search_term = '';

// Get customer ID from URL
$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

// Get all open loans with customer details for search
$loans_for_search_query = "SELECT l.id, l.receipt_number, l.receipt_date, l.loan_amount, 
                          c.id as customer_id, c.customer_name, c.mobile_number,
                          l.net_weight, l.interest_amount,
                          DATEDIFF(NOW(), l.receipt_date) as days_old,
                          (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as paid_principal,
                          (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as paid_interest
                          FROM loans l
                          JOIN customers c ON l.customer_id = c.id
                          WHERE l.status = 'open'
                          ORDER BY l.receipt_date DESC";
$loans_for_search_result = mysqli_query($conn, $loans_for_search_query);

// Prepare loan data for JavaScript search
$jsLoans = [];

if ($loans_for_search_result && mysqli_num_rows($loans_for_search_result) > 0) {
    while ($loan = mysqli_fetch_assoc($loans_for_search_result)) {
        // Calculate initials
        $name_parts = explode(' ', trim($loan['customer_name']));
        $initials = '';
        foreach ($name_parts as $part) {
            if (!empty($part)) {
                $initials .= strtoupper(substr($part, 0, 1));
            }
        }
        if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
        
        $jsLoans[] = [
            'id' => (int)$loan['id'],
            'receipt' => $loan['receipt_number'],
            'customer_id' => (int)$loan['customer_id'],
            'customer_name' => $loan['customer_name'],
            'amount' => (float)$loan['loan_amount'],
            'mobile' => $loan['mobile_number'],
            'date' => date('d-m-Y', strtotime($loan['receipt_date'])),
            'days' => (int)$loan['days_old'],
            'paid_principal' => (float)($loan['paid_principal'] ?? 0),
            'payable_principal' => (float)$loan['loan_amount'] - (float)($loan['paid_principal'] ?? 0),
            'net_weight' => (float)$loan['net_weight'],
            'interest_rate' => (float)$loan['interest_amount'],
            'initials' => $initials
        ];
    }
}

// Handle search submission
if (isset($_POST['search_loan']) && !empty($_POST['search_loan'])) {
    $selected_search_term = trim($_POST['search_loan']);
    
    // Check if it's a numeric ID (loan ID)
    if (is_numeric($selected_search_term)) {
        // Get customer ID from selected loan
        $selected_loan_id = intval($selected_search_term);
        $loan_customer_query = "SELECT customer_id FROM loans WHERE id = ?";
        $stmt = mysqli_prepare($conn, $loan_customer_query);
        mysqli_stmt_bind_param($stmt, 'i', $selected_loan_id);
        mysqli_stmt_execute($stmt);
        $loan_customer_result = mysqli_stmt_get_result($stmt);
        
        if ($loan_customer_row = mysqli_fetch_assoc($loan_customer_result)) {
            $customer_id = $loan_customer_row['customer_id'];
        } else {
            $error = "Selected loan not found";
        }
    } 
    // Otherwise treat as text search for customer
    else {
        $search_term = mysqli_real_escape_string($conn, $selected_search_term);
        
        $search_query = "SELECT id, customer_name, guardian_type, guardian_name, 
                        mobile_number, whatsapp_number,
                        door_no, street_name, location, district, pincode, post, taluk
                        FROM customers 
                        WHERE customer_name LIKE ? OR mobile_number LIKE ?";
        
        $search_param = "%$search_term%";
        
        $stmt = mysqli_prepare($conn, $search_query);
        mysqli_stmt_bind_param($stmt, 'ss', $search_param, $search_param);
        mysqli_stmt_execute($stmt);
        $search_result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($search_result) == 1) {
            // Single customer found - load their loans
            $customer = mysqli_fetch_assoc($search_result);
            $customer_id = $customer['id'];
        } elseif (mysqli_num_rows($search_result) > 1) {
            // Multiple customers found
            while ($cust = mysqli_fetch_assoc($search_result)) {
                $multiple_customers[] = $cust;
            }
        } else {
            $error = "No customer found with the search term: " . htmlspecialchars($search_term);
        }
    }
}

// If customer ID is provided, load customer and their loans
if ($customer_id > 0) {
    // Get customer details
    $customer_query = "SELECT id, customer_name, guardian_type, guardian_name, 
                      mobile_number, whatsapp_number,
                      door_no, street_name, location, district, pincode, post, taluk,
                      customer_photo
                      FROM customers WHERE id = ?";
    $stmt = mysqli_prepare($conn, $customer_query);
    mysqli_stmt_bind_param($stmt, 'i', $customer_id);
    mysqli_stmt_execute($stmt);
    $customer_result = mysqli_stmt_get_result($stmt);
    
    if ($customer_result && mysqli_num_rows($customer_result) > 0) {
        $customer = mysqli_fetch_assoc($customer_result);
        
        // Get all open loans for this customer with calculations
        $loans_query = "SELECT l.*, 
                       DATEDIFF(NOW(), l.receipt_date) as days_old,
                       (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as paid_principal,
                       (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as paid_interest
                       FROM loans l 
                       WHERE l.customer_id = ? AND l.status = 'open'
                       ORDER BY l.receipt_date DESC";
        
        $stmt = mysqli_prepare($conn, $loans_query);
        mysqli_stmt_bind_param($stmt, 'i', $customer_id);
        mysqli_stmt_execute($stmt);
        $loans_result = mysqli_stmt_get_result($stmt);
        
        $loans = [];
        $total_principal = 0;
        $total_payable_principal = 0;
        $total_one_month_interest = 0;
        $total_paying_interest = 0;
        $total_payable_months = 0;
        $total_days = 0;
        $total_final_amount = 0;
        $total_weight = 0;
        
        while ($loan = mysqli_fetch_assoc($loans_result)) {
            // Calculate loan details
            $principal = floatval($loan['loan_amount']);
            $interest_rate = floatval($loan['interest_amount']);
            $days = intval($loan['days_old']);
            $paid_principal = floatval($loan['paid_principal'] ?? 0);
            $paid_interest = floatval($loan['paid_interest'] ?? 0);
            
            // Calculate remaining principal
            $payable_principal = $principal - $paid_principal;
            
            // Calculate monthly interest (based on original principal)
            $monthly_interest = ($principal * $interest_rate) / 100;
            $daily_interest = $monthly_interest / 30;
            
            // Calculate payable interest
            $total_interest_accrued = $days * $daily_interest;
            $payable_interest = max(0, $total_interest_accrued - $paid_interest);
            
            // Calculate payable months
            $payable_months = floor($days / 30);
            
            // Calculate final amount
            $final_amount = $payable_principal + $payable_interest;
            
            // Add receipt charge if any
            if (isset($loan['receipt_charge']) && $loan['receipt_charge'] > 0) {
                $final_amount += floatval($loan['receipt_charge']);
            }
            
            $loan['calculated'] = [
                'payable_principal' => round($payable_principal, 2),
                'monthly_interest' => round($monthly_interest, 2),
                'daily_interest' => round($daily_interest, 2),
                'payable_interest' => round($payable_interest, 2),
                'payable_months' => $payable_months,
                'days' => $days,
                'final_amount' => round($final_amount, 2)
            ];
            
            $loans[] = $loan;
            
            // Calculate totals
            $total_principal += $principal;
            $total_payable_principal += $payable_principal;
            $total_one_month_interest += $monthly_interest;
            $total_paying_interest += $payable_interest;
            $total_payable_months += $payable_months;
            $total_days += $days;
            $total_final_amount += $final_amount;
            $total_weight += floatval($loan['net_weight']);
        }
    } else {
        $error = "Customer not found with ID: " . $customer_id;
    }
}

// ============================================
// FIXED BULK CLOSE SUBMISSION HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_close') {
    $selected_loan_ids = $_POST['selected_loans'] ?? [];
    $close_date = mysqli_real_escape_string($conn, $_POST['close_date']);
    $global_discount = floatval($_POST['global_discount'] ?? 0);
    $global_round_off = floatval($_POST['global_round_off'] ?? 0);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode'] ?? 'cash');
    $employee_id = intval($_SESSION['user_id']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Bulk loan closure');
    
    if (empty($selected_loan_ids)) {
        $error = "Please select at least one loan to close";
    } else {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            $total_closed_amount = 0;
            $closed_count = 0;
            
            // Generate a unique bulk receipt number
            $bulk_receipt = 'BULK' . date('Ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            foreach ($selected_loan_ids as $loan_id) {
                $loan_id = intval($loan_id);
                
                // Get loan details with current calculations
                $loan_query = "SELECT l.*, 
                              (SELECT COALESCE(SUM(principal_amount), 0) FROM payments WHERE loan_id = l.id) as paid_principal,
                              (SELECT COALESCE(SUM(interest_amount), 0) FROM payments WHERE loan_id = l.id) as paid_interest,
                              DATEDIFF(?, l.receipt_date) as days_old
                              FROM loans l WHERE l.id = ?";
                $stmt = mysqli_prepare($conn, $loan_query);
                mysqli_stmt_bind_param($stmt, 'si', $close_date, $loan_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $loan_data = mysqli_fetch_assoc($result);
                
                if (!$loan_data) {
                    throw new Exception("Loan not found with ID: " . $loan_id);
                }
                
                // Calculate final amounts
                $principal = floatval($loan_data['loan_amount']);
                $interest_rate = floatval($loan_data['interest_amount']);
                $days = intval($loan_data['days_old'] ?? 0);
                $paid_principal = floatval($loan_data['paid_principal'] ?? 0);
                $paid_interest = floatval($loan_data['paid_interest'] ?? 0);
                
                $payable_principal = $principal - $paid_principal;
                $monthly_interest = ($principal * $interest_rate) / 100;
                $daily_interest = $monthly_interest / 30;
                $total_interest_accrued = $days * $daily_interest;
                $payable_interest = max(0, $total_interest_accrued - $paid_interest);
                $receipt_charge = floatval($loan_data['receipt_charge'] ?? 0);
                
                // Calculate base amount
                $base_amount = $payable_principal + $payable_interest + $receipt_charge;
                
                // Generate unique receipt for this loan
                $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
                $receipt_result = mysqli_query($conn, $receipt_query);
                $receipt_row = mysqli_fetch_assoc($receipt_result);
                $receipt_count = ($receipt_row['count'] ?? 0) + 1;
                $payment_receipt = 'CLS' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
                
                // Insert payment record
                $insert_payment = "INSERT INTO payments (
                    loan_id, receipt_number, payment_date, principal_amount, 
                    interest_amount, total_amount, payment_mode, employee_id, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $insert_payment);
                mysqli_stmt_bind_param(
                    $stmt, 
                    'issdddiss', 
                    $loan_id, 
                    $payment_receipt, 
                    $close_date,
                    $payable_principal, 
                    $payable_interest, 
                    $base_amount,
                    $payment_mode, 
                    $employee_id, 
                    $remarks
                );
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error inserting payment: " . mysqli_stmt_error($stmt));
                }
                
                // Update loan as closed
                $update_loan = "UPDATE loans SET 
                    status = 'closed', 
                    close_date = ?,
                    discount = ?,
                    round_off = ?,
                    updated_at = NOW()
                    WHERE id = ?";
                
                $stmt = mysqli_prepare($conn, $update_loan);
                // For bulk close, we're not applying individual discounts yet
                $individual_discount = 0;
                $individual_round_off = 0;
                mysqli_stmt_bind_param($stmt, 'sddi', $close_date, $individual_discount, $individual_round_off, $loan_id);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error updating loan: " . mysqli_stmt_error($stmt));
                }
                
                $total_closed_amount += $base_amount;
                $closed_count++;
            }
            
            // Apply global discount and round off to the total
            $final_total = $total_closed_amount - $global_discount + $global_round_off;
            
            // Store bulk closure info in session for receipt
            $_SESSION['bulk_closure'] = [
                'customer_id' => $customer_id,
                'close_date' => $close_date,
                'bulk_receipt' => $bulk_receipt,
                'total_amount' => $final_total,
                'loan_count' => $closed_count,
                'payment_mode' => $payment_mode,
                'global_discount' => $global_discount,
                'global_round_off' => $global_round_off
            ];
            
            mysqli_commit($conn);
            
            // MODIFIED: Redirect directly to print bulk receipt page
            $redirect_url = "print-bulk-close-receipt.php?customer_id=" . $customer_id . 
                           "&close_date=" . urlencode($close_date) . 
                           "&receipt=" . urlencode($bulk_receipt) . 
                           "&count=" . $closed_count .
                           "&mode=" . urlencode($payment_mode) .
                           "&total=" . urlencode($final_total);
            
            header('Location: ' . $redirect_url);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error during bulk close: " . $e->getMessage();
        }
    }
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'closed':
            $message = "Loans closed successfully!";
            break;
    }
}

// Format address function
function formatAddress($customer) {
    $parts = [];
    if (!empty($customer['door_no'])) $parts[] = $customer['door_no'];
    if (!empty($customer['street_name'])) $parts[] = $customer['street_name'];
    if (!empty($customer['location'])) $parts[] = $customer['location'];
    if (!empty($customer['post'])) $parts[] = $customer['post'] . ' (Po)';
    if (!empty($customer['district'])) $parts[] = $customer['district'];
    if (!empty($customer['pincode'])) $parts[] = $customer['pincode'];
    
    return implode(', ', $parts);
}

// Get customer initials
function getInitials($name) {
    $name_parts = explode(' ', trim($name));
    $initials = '';
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
    return $initials;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', 'Noto Sans Tamil', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            background: #f8fafc;
        }

        .page-content {
            padding: 30px;
        }

        .bulk-close-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-success:hover {
            background: #38a169;
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .search-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .search-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Enhanced Search Box */
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input-container {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .search-input-wrapper {
            flex: 1;
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            z-index: 1;
        }

        .search-input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-hint {
            font-size: 12px;
            color: #718096;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-hint i {
            color: #48bb78;
        }

        /* Enhanced Search Results Dropdown */
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            margin-top: 8px;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .search-result-item:hover {
            background: #f7fafc;
        }

        .search-result-item.selected {
            background: #ebf4ff;
            border-left: 4px solid #667eea;
        }

        /* Customer Avatar/Initials */
        .result-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }

        .result-details {
            flex: 1;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
            flex-wrap: wrap;
        }

        .result-receipt {
            font-weight: 700;
            color: #667eea;
            font-size: 15px;
            background: #ebf4ff;
            padding: 3px 8px;
            border-radius: 20px;
        }

        .result-customer {
            font-weight: 600;
            color: #2d3748;
            font-size: 16px;
        }

        .result-mobile {
            color: #48bb78;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .result-meta {
            display: flex;
            gap: 20px;
            margin-top: 5px;
            font-size: 12px;
            color: #718096;
        }

        .result-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .result-meta-item i {
            color: #667eea;
        }

        .result-amount {
            font-weight: 700;
            color: #48bb78;
            font-size: 16px;
            background: #f0fff4;
            padding: 5px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .no-results {
            padding: 30px;
            text-align: center;
            color: #718096;
        }

        .no-results i {
            font-size: 40px;
            color: #cbd5e0;
            margin-bottom: 10px;
        }

        /* Multiple Customers Grid */
        .multiple-customers {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .customer-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .customer-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.2);
            border-color: #667eea;
        }

        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 24px;
            flex-shrink: 0;
        }

        .customer-info {
            flex: 1;
        }

        .customer-card-name {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .customer-card-detail {
            font-size: 13px;
            color: #4a5568;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .customer-card-detail i {
            color: #667eea;
            width: 16px;
        }

        .customer-card-badge {
            display: inline-block;
            background: #ebf4ff;
            color: #667eea;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }

        .customer-info-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .customer-info-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 28px;
            flex-shrink: 0;
        }

        .customer-info-content {
            flex: 1;
        }

        .customer-name {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .customer-address {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 10px;
            line-height: 1.6;
        }

        .customer-contact {
            display: flex;
            gap: 20px;
            font-size: 16px;
        }

        .customer-contact i {
            color: #667eea;
            margin-right: 5px;
        }

        .customer-contact span {
            font-weight: 600;
            color: #2d3748;
        }

        .table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
            margin-bottom: 25px;
        }

        .loans-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 1600px;
        }

        .loans-table th {
            background: #f7fafc;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .loans-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .loans-table tr:hover {
            background: #f7fafc;
        }

        .loans-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .loans-table tfoot td {
            padding: 12px 8px;
            border-top: 2px solid #e2e8f0;
        }

        .check-column {
            text-align: center;
        }

        .check-column input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .amount-highlight {
            color: #48bb78;
            font-weight: 600;
        }

        .interest-highlight {
            color: #ecc94b;
            font-weight: 600;
        }

        .bulk-actions {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            display: flex;
            gap: 20px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .bulk-actions .form-group {
            flex: 1;
            min-width: 150px;
            margin-bottom: 0;
        }

        .summary-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-total {
            font-size: 18px;
            font-weight: 700;
            color: #48bb78;
            padding-top: 10px;
            margin-top: 10px;
            border-top: 2px solid #48bb78;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        @media (max-width: 768px) {
            .search-input-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-actions {
                flex-direction: column;
            }
            
            .customer-contact {
                flex-direction: column;
                gap: 10px;
            }
            
            .search-results {
                position: fixed;
                top: auto;
                left: 20px;
                right: 20px;
                max-height: 50vh;
            }
            
            .customer-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .multiple-customers {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <?php include 'includes/sidebar.php'; ?>

        <div class="main-content">
            <?php include 'includes/topbar.php'; ?>

            <div class="page-content">
                <div class="bulk-close-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-files"></i>
                            Bulk Loan Close
                        </h1>
                        <div>
                            <a href="loans.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Loans
                            </a>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Enhanced Search Box with Initials -->
                    <div class="search-card">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Find Customer / Loan
                        </div>

                        <div class="search-box">
                            <form method="POST" action="" id="searchForm">
                                <div class="search-input-container">
                                    <div class="search-input-wrapper">
                                        <i class="bi bi-search search-icon"></i>
                                        <input type="text" 
                                               class="search-input" 
                                               id="searchInput" 
                                               name="search_loan" 
                                               placeholder="Search by Receipt Number / Customer Name / Mobile"
                                               value="<?php echo htmlspecialchars($selected_search_term); ?>"
                                               autocomplete="off">
                                        
                                        <!-- Search Results Dropdown with Initials -->
                                        <div class="search-results" id="searchResults"></div>
                                    </div>
                                    <button type="submit" class="btn btn-primary" id="loadCustomerBtn">
                                        <i class="bi bi-arrow-right"></i> Load Customer
                                    </button>
                                </div>
                                <div class="search-hint">
                                    <i class="bi bi-info-circle"></i> 
                                    Type receipt number, customer name, or mobile number. Results show customer initials and loan details.
                                </div>
                            </form>
                        </div>

                        <!-- Multiple Customers Found -->
                        <?php if (!empty($multiple_customers)): ?>
                            <div style="margin-top: 20px;">
                                <h4 style="margin-bottom: 15px; color: #4a5568;">
                                    <i class="bi bi-people"></i> Multiple customers found. Please select one:
                                </h4>
                                <div class="multiple-customers">
                                    <?php foreach ($multiple_customers as $cust): 
                                        $initials = getInitials($cust['customer_name']);
                                    ?>
                                        <div class="customer-card" onclick="window.location.href='?customer_id=<?php echo $cust['id']; ?>'">
                                            <div class="customer-avatar"><?php echo $initials; ?></div>
                                            <div class="customer-info">
                                                <div class="customer-card-name">
                                                    <?php echo htmlspecialchars($cust['customer_name']); ?>
                                                    <span class="customer-card-badge">ID: <?php echo $cust['id']; ?></span>
                                                </div>
                                                <div class="customer-card-detail">
                                                    <i class="bi bi-telephone"></i> <?php echo $cust['mobile_number']; ?>
                                                </div>
                                                <div class="customer-card-detail">
                                                    <i class="bi bi-person"></i> 
                                                    <?php echo ($cust['guardian_type'] ? $cust['guardian_type'] . '. ' : '') . htmlspecialchars($cust['guardian_name']); ?>
                                                </div>
                                                <div class="customer-card-detail">
                                                    <i class="bi bi-geo-alt"></i> 
                                                    <?php echo htmlspecialchars(formatAddress($cust)); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($customer): ?>
                        <!-- Customer Information with Avatar/Initials -->
                        <div class="customer-info-card">
                            <div class="customer-info-avatar">
                                <?php echo getInitials($customer['customer_name']); ?>
                            </div>
                            <div class="customer-info-content">
                                <div class="customer-name">
                                    <?php echo htmlspecialchars($customer['customer_name']); ?>
                                </div>
                                <div class="customer-address">
                                    <i class="bi bi-geo-alt"></i> 
                                    <?php 
                                    echo htmlspecialchars(
                                        ($customer['guardian_type'] ? $customer['guardian_type'] . '. ' : '') . 
                                        $customer['guardian_name'] . ', ' . 
                                        formatAddress($customer)
                                    ); 
                                    ?>
                                </div>
                                <div class="customer-contact">
                                    <div>
                                        <i class="bi bi-telephone"></i> 
                                        <span><?php echo $customer['mobile_number']; ?></span>
                                    </div>
                                    <?php if (!empty($customer['whatsapp_number'])): ?>
                                    <div>
                                        <i class="bi bi-whatsapp"></i> 
                                        <span><?php echo $customer['whatsapp_number']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($loans)): ?>
                            <!-- Bulk Actions -->
                            <form method="POST" action="" id="bulkCloseForm">
                                <input type="hidden" name="action" value="bulk_close">
                                <input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>">
                                
                                <div class="bulk-actions">
                                    <div class="form-group">
                                        <label class="form-label required">Close Date</label>
                                        <input type="date" class="form-control" name="close_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Global Discount (₹)</label>
                                        <input type="number" class="form-control" name="global_discount" id="global_discount" value="0" step="0.01" min="0">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Global Round Off (₹)</label>
                                        <input type="number" class="form-control" name="global_round_off" id="global_round_off" value="0" step="0.01">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label required">Payment Mode</label>
                                        <select class="form-select" name="payment_mode" required>
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="upi">UPI</option>
                                            <option value="cheque">Cheque</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Remarks</label>
                                        <input type="text" class="form-control" name="remarks" placeholder="Bulk loan closure">
                                    </div>
                                    <button type="button" class="btn btn-success" onclick="confirmBulkClose()">
                                        <i class="bi bi-check-all"></i> Close Selected Loans
                                    </button>
                                </div>

                                <!-- Loans Table -->
                                <div class="table-container">
                                    <table class="loans-table" id="loansTable">
                                        <thead>
                                            <tr>
                                                <th class="check-column">
                                                    <input type="checkbox" id="selectAll" onclick="toggleAll()">
                                                </th>
                                                <th>#</th>
                                                <th>Receipt Number</th>
                                                <th>Receipt Date</th>
                                                <th>Product</th>
                                                <th>Weight</th>
                                                <th>Principle</th>
                                                <th>P. Principle</th>
                                                <th>Interest %</th>
                                                <th>1 Month Interest</th>
                                                <th>Payable Month</th>
                                                <th>Days</th>
                                                <th>Paying Interest</th>
                                                <th>Post</th>
                                                <th>Round Off</th>
                                                <th>Discount</th>
                                                <th>D</th>
                                                <th>Naruna</th>
                                                <th>Total Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $sno = 1;
                                            $total_weight = 0;
                                            $total_principal = 0;
                                            $total_p_principal = 0;
                                            $total_1m_interest = 0;
                                            $total_payable_months = 0;
                                            $total_days = 0;
                                            $total_paying_interest = 0;
                                            $total_final = 0;
                                            
                                            foreach ($loans as $loan): 
                                                $total_weight += floatval($loan['net_weight']);
                                                $total_principal += floatval($loan['loan_amount']);
                                                $total_p_principal += $loan['calculated']['payable_principal'];
                                                $total_1m_interest += $loan['calculated']['monthly_interest'];
                                                $total_payable_months += $loan['calculated']['payable_months'];
                                                $total_days += $loan['calculated']['days'];
                                                $total_paying_interest += $loan['calculated']['payable_interest'];
                                                $total_final += $loan['calculated']['final_amount'];
                                            ?>
                                            <tr>
                                                <td class="check-column">
                                                    <input type="checkbox" name="selected_loans[]" value="<?php echo $loan['id']; ?>" class="loan-checkbox" onchange="updateTotals()">
                                                </td>
                                                <td><?php echo $sno++; ?></td>
                                                <td><strong><?php echo $loan['receipt_number']; ?></strong></td>
                                                <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($loan['product_type'] ?? 'Jewelry'); ?></td>
                                                <td><?php echo number_format($loan['net_weight'], 3); ?> g</td>
                                                <td>₹ <?php echo number_format($loan['loan_amount'], 2); ?></td>
                                                <td class="amount-highlight">₹ <?php echo number_format($loan['calculated']['payable_principal'], 2); ?></td>
                                                <td><?php echo $loan['interest_amount']; ?>%</td>
                                                <td>₹ <?php echo number_format($loan['calculated']['monthly_interest'], 2); ?></td>
                                                <td><?php echo $loan['calculated']['payable_months']; ?></td>
                                                <td><?php echo $loan['calculated']['days']; ?></td>
                                                <td class="interest-highlight">₹ <?php echo number_format($loan['calculated']['payable_interest'], 2); ?></td>
                                                <td>-</td>
                                                <td>0.00</td>
                                                <td>0.00</td>
                                                <td>-</td>
                                                <td>0.00</td>
                                                <td class="amount-highlight">₹ <?php echo number_format($loan['calculated']['final_amount'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="5" style="text-align: right;"><strong>Totals:</strong></td>
                                                <td><strong><?php echo number_format($total_weight, 3); ?> g</strong></td>
                                                <td><strong>₹ <?php echo number_format($total_principal, 2); ?></strong></td>
                                                <td><strong class="amount-highlight">₹ <?php echo number_format($total_p_principal, 2); ?></strong></td>
                                                <td></td>
                                                <td><strong>₹ <?php echo number_format($total_1m_interest, 2); ?></strong></td>
                                                <td><strong><?php echo $total_payable_months; ?></strong></td>
                                                <td><strong><?php echo $total_days; ?></strong></td>
                                                <td><strong class="interest-highlight">₹ <?php echo number_format($total_paying_interest, 2); ?></strong></td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td><strong class="amount-highlight">₹ <?php echo number_format($total_final, 2); ?></strong></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Selected Loans Summary -->
                                <div class="summary-box" id="selectedSummary" style="display: none;">
                                    <h4 style="margin-bottom: 15px;">Selected Loans Summary</h4>
                                    <div class="summary-row">
                                        <span>Selected Loans:</span>
                                        <span id="selectedCount">0</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Total Weight:</span>
                                        <span id="selectedWeight">0.000 g</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Total Principal:</span>
                                        <span id="selectedPrincipal">₹ 0.00</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Total Payable Principal:</span>
                                        <span id="selectedPayablePrincipal">₹ 0.00</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Total Payable Interest:</span>
                                        <span id="selectedPayableInterest">₹ 0.00</span>
                                    </div>
                                    <div class="summary-row">
                                        <span>Total Days:</span>
                                        <span id="selectedDays">0</span>
                                    </div>
                                    <div class="summary-total">
                                        <span>Total Final Amount:</span>
                                        <span id="selectedFinalAmount">₹ 0.00</span>
                                    </div>
                                </div>
                            </form>

                            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
                            <script>
                                // Store loan data for calculations
                                const loanData = <?php 
                                    $data = [];
                                    foreach ($loans as $loan) {
                                        $data[$loan['id']] = [
                                            'weight' => floatval($loan['net_weight']),
                                            'principal' => floatval($loan['loan_amount']),
                                            'payable_principal' => $loan['calculated']['payable_principal'],
                                            'payable_interest' => $loan['calculated']['payable_interest'],
                                            'days' => $loan['calculated']['days'],
                                            'final_amount' => $loan['calculated']['final_amount']
                                        ];
                                    }
                                    echo json_encode($data);
                                ?>;
                                
                                // Store loans for search
                                const loansList = <?php echo json_encode($jsLoans); ?>;
                                let selectedLoanId = null;

                                // Enhanced search functionality
                                const searchInput = document.getElementById('searchInput');
                                const searchResults = document.getElementById('searchResults');

                                searchInput.addEventListener('input', function() {
                                    const searchTerm = this.value.toLowerCase().trim();
                                    
                                    if (searchTerm.length < 1) {
                                        searchResults.classList.remove('show');
                                        return;
                                    }

                                    // Filter loans
                                    const filtered = loansList.filter(loan => 
                                        loan.receipt.toLowerCase().includes(searchTerm) ||
                                        loan.customer_name.toLowerCase().includes(searchTerm) ||
                                        loan.mobile.includes(searchTerm)
                                    );

                                    // Display results with initials
                                    if (filtered.length > 0) {
                                        let html = '';
                                        filtered.forEach(loan => {
                                            html += `
                                                <div class="search-result-item" onclick="selectLoan(${loan.id})">
                                                    <div class="result-avatar">${loan.initials}</div>
                                                    <div class="result-details">
                                                        <div class="result-header">
                                                            <span class="result-receipt">${loan.receipt}</span>
                                                            <span class="result-customer">${loan.customer_name}</span>
                                                        </div>
                                                        <div class="result-meta">
                                                            <span class="result-meta-item">
                                                                <i class="bi bi-telephone"></i> ${loan.mobile}
                                                            </span>
                                                            <span class="result-meta-item">
                                                                <i class="bi bi-calendar"></i> ${loan.date}
                                                            </span>
                                                            <span class="result-meta-item">
                                                                <i class="bi bi-clock"></i> ${loan.days} days
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="result-amount">₹${loan.amount.toFixed(0)}</div>
                                                </div>
                                            `;
                                        });
                                        searchResults.innerHTML = html;
                                        searchResults.classList.add('show');
                                    } else {
                                        searchResults.innerHTML = '<div class="no-results"><i class="bi bi-search"></i><br>No loans found</div>';
                                        searchResults.classList.add('show');
                                    }
                                });

                                // Select a loan from results
                                function selectLoan(loanId) {
                                    const loan = loansList.find(l => l.id === loanId);
                                    if (loan) {
                                        searchInput.value = loan.receipt + ' - ' + loan.customer_name;
                                        selectedLoanId = loanId;
                                        searchResults.classList.remove('show');
                                    }
                                }

                                // Hide results when clicking outside
                                document.addEventListener('click', function(event) {
                                    if (!searchInput.contains(event.target) && !searchResults.contains(event.target)) {
                                        searchResults.classList.remove('show');
                                    }
                                });

                                // Handle form submission
                                document.getElementById('searchForm').addEventListener('submit', function(e) {
                                    if (selectedLoanId) {
                                        // If a loan is selected, use its ID
                                        const hiddenInput = document.createElement('input');
                                        hiddenInput.type = 'hidden';
                                        hiddenInput.name = 'search_loan';
                                        hiddenInput.value = selectedLoanId;
                                        this.appendChild(hiddenInput);
                                        
                                        // Remove the original input to avoid confusion
                                        document.getElementById('searchInput').name = '';
                                    }
                                    // Otherwise submit as text search
                                });

                                // Toggle all checkboxes
                                function toggleAll() {
                                    const selectAll = document.getElementById('selectAll');
                                    const checkboxes = document.getElementsByClassName('loan-checkbox');
                                    
                                    for (let checkbox of checkboxes) {
                                        checkbox.checked = selectAll.checked;
                                    }
                                    
                                    updateTotals();
                                }

                                // Update totals based on selected loans
                                function updateTotals() {
                                    const checkboxes = document.getElementsByClassName('loan-checkbox');
                                    const summaryBox = document.getElementById('selectedSummary');
                                    
                                    let selectedCount = 0;
                                    let totalWeight = 0;
                                    let totalPrincipal = 0;
                                    let totalPayablePrincipal = 0;
                                    let totalPayableInterest = 0;
                                    let totalDays = 0;
                                    let totalFinal = 0;
                                    
                                    for (let checkbox of checkboxes) {
                                        if (checkbox.checked) {
                                            selectedCount++;
                                            const loanId = checkbox.value;
                                            const data = loanData[loanId];
                                            
                                            if (data) {
                                                totalWeight += data.weight;
                                                totalPrincipal += data.principal;
                                                totalPayablePrincipal += data.payable_principal;
                                                totalPayableInterest += data.payable_interest;
                                                totalDays += data.days;
                                                totalFinal += data.final_amount;
                                            }
                                        }
                                    }
                                    
                                    if (selectedCount > 0) {
                                        summaryBox.style.display = 'block';
                                        
                                        document.getElementById('selectedCount').textContent = selectedCount;
                                        document.getElementById('selectedWeight').textContent = totalWeight.toFixed(3) + ' g';
                                        document.getElementById('selectedPrincipal').innerHTML = '₹ ' + totalPrincipal.toFixed(2);
                                        document.getElementById('selectedPayablePrincipal').innerHTML = '₹ ' + totalPayablePrincipal.toFixed(2);
                                        document.getElementById('selectedPayableInterest').innerHTML = '₹ ' + totalPayableInterest.toFixed(2);
                                        document.getElementById('selectedDays').textContent = totalDays;
                                        document.getElementById('selectedFinalAmount').innerHTML = '₹ ' + totalFinal.toFixed(2);
                                    } else {
                                        summaryBox.style.display = 'none';
                                    }
                                }

                                // MODIFIED: Confirm bulk close and submit form
                                function confirmBulkClose() {
                                    const checkboxes = document.getElementsByClassName('loan-checkbox');
                                    let selectedCount = 0;
                                    
                                    for (let checkbox of checkboxes) {
                                        if (checkbox.checked) selectedCount++;
                                    }
                                    
                                    if (selectedCount === 0) {
                                        Swal.fire({
                                            icon: 'warning',
                                            title: 'No Loans Selected',
                                            text: 'Please select at least one loan to close',
                                            confirmButtonColor: '#667eea'
                                        });
                                        return;
                                    }
                                    
                                    const finalAmount = document.getElementById('selectedFinalAmount').textContent;
                                    
                                    Swal.fire({
                                        title: 'Confirm Bulk Close',
                                        html: `Are you sure you want to close <strong>${selectedCount}</strong> selected loans for a total of <strong>${finalAmount}</strong>?`,
                                        icon: 'warning',
                                        showCancelButton: true,
                                        confirmButtonColor: '#48bb78',
                                        cancelButtonColor: '#f56565',
                                        confirmButtonText: 'Yes, Close Loans',
                                        cancelButtonText: 'Cancel'
                                    }).then((result) => {
                                        if (result.isConfirmed) {
                                            // Show loading
                                            Swal.fire({
                                                title: 'Processing...',
                                                text: 'Please wait while we close the loans',
                                                allowOutsideClick: false,
                                                allowEscapeKey: false,
                                                showConfirmButton: false,
                                                didOpen: () => {
                                                    Swal.showLoading();
                                                }
                                            });
                                            
                                            // Submit the form
                                            document.getElementById('bulkCloseForm').submit();
                                        }
                                    });
                                }

                                // Auto-hide alerts after 5 seconds
                                setTimeout(function() {
                                    document.querySelectorAll('.alert').forEach(function(alert) {
                                        alert.style.display = 'none';
                                    });
                                }, 5000);
                            </script>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> No open loans found for this customer.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>