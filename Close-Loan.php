<?php
session_start();
$currentPage = 'close-loan';
$pageTitle = 'Close Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$loan = null;
$customer = null;
$loan_id = 0;

// Get loan ID from URL
if (isset($_GET['id'])) {
    $loan_id = intval($_GET['id']);
} elseif (isset($_GET['loan_id'])) {
    $loan_id = intval($_GET['loan_id']);
}

// Handle receipt search
$receipt_search = isset($_GET['receipt']) ? mysqli_real_escape_string($conn, $_GET['receipt']) : '';

// If receipt number is provided, search for loan
if (!empty($receipt_search) && $loan_id == 0) {
    $search_query = "SELECT id FROM loans WHERE receipt_number = ? AND status = 'open'";
    $stmt = mysqli_prepare($conn, $search_query);
    mysqli_stmt_bind_param($stmt, 's', $receipt_search);
    mysqli_stmt_execute($stmt);
    $search_result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($search_result)) {
        $loan_id = $row['id'];
    } else {
        $error = "No open loan found with Receipt Number: " . htmlspecialchars($receipt_search);
    }
}

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// If loan ID is provided, get loan details
if ($loan_id > 0) {
    // Get loan details with customer information
    $loan_query = "SELECT l.*, 
                   c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
                   c.mobile_number, c.whatsapp_number, c.email,
                   c.door_no, c.street_name, c.location, c.district, c.pincode, c.post, c.taluk,
                   c.aadhaar_number, c.customer_photo,
                   u.name as employee_name,
                   DATEDIFF(NOW(), l.receipt_date) as total_days
                   FROM loans l 
                   JOIN customers c ON l.customer_id = c.id 
                   JOIN users u ON l.employee_id = u.id 
                   WHERE l.id = ? AND l.status = 'open'";

    $stmt = mysqli_prepare($conn, $loan_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $loan_result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($loan_result) > 0) {
        $loan = mysqli_fetch_assoc($loan_result);
        
        // Get loan items (jewelry)
        $items_query = "SELECT * FROM loan_items WHERE loan_id = ?";
        $stmt = mysqli_prepare($conn, $items_query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $items_result = mysqli_stmt_get_result($stmt);
        $items = [];
        $total_net_weight = 0;
        while ($item = mysqli_fetch_assoc($items_result)) {
            $items[] = $item;
            $total_net_weight += $item['net_weight'] * $item['quantity'];
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
        
        // Get customer details
        $customer = [
            'id' => $loan['customer_id'],
            'name' => $loan['customer_name'],
            'guardian' => $loan['guardian_name'],
            'guardian_type' => $loan['guardian_type'],
            'mobile' => $loan['mobile_number'],
            'whatsapp' => $loan['whatsapp_number'],
            'email' => $loan['email'],
            'address' => trim($loan['door_no'] . ' ' . $loan['street_name'] . ', ' . $loan['location'] . ', ' . $loan['district'] . ' - ' . $loan['pincode']),
            'aadhaar' => $loan['aadhaar_number'],
            'photo' => $loan['customer_photo']
        ];
        
        // ===== AUTO CALCULATIONS =====
        $principal = floatval($loan['loan_amount']);
        $interest_rate = floatval($loan['interest_amount']);
        $total_days = $loan['total_days'];
        
        // Calculate months and days
        $total_months = floor($total_days / 30);
        $balance_days = $total_days % 30;
        $balance_months = $total_months;
        
        // Get total payments made
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
        $payment_count = intval($paid_total['payment_count'] ?? 0);
        
        // Calculate advance payments (if any)
        $advance_principal = $paid_principal;
        $advance_interest = $paid_interest;
        
        // Calculate remaining principal
        $remaining_principal = $principal - $paid_principal;
        
        // Calculate monthly interest (based on original principal)
        $monthly_interest = ($principal * $interest_rate) / 100;
        $daily_interest = $monthly_interest / 30;
        
        // Calculate total interest accrued
        $total_interest_accrued = $total_days * $daily_interest;
        
        // Calculate payable interest (total accrued minus paid)
        $payable_interest = $total_interest_accrued - $paid_interest;
        
        // Calculate 1 month interest
        $one_month_interest = $monthly_interest;
        
        // Total interest due
        $total_interest_due = $payable_interest;
        
        // Round all values
        $principal = round($principal, 2);
        $remaining_principal = round($remaining_principal, 2);
        $monthly_interest = round($monthly_interest, 2);
        $daily_interest = round($daily_interest, 2);
        $total_interest_accrued = round($total_interest_accrued, 2);
        $paid_interest = round($paid_interest, 2);
        $payable_interest = round($payable_interest, 2);
        $total_interest_due = round($total_interest_due, 2);
        $one_month_interest = round($one_month_interest, 2);
        $advance_principal = round($advance_principal, 2);
        $advance_interest = round($advance_interest, 2);
        
        // Calculate total payable including receipt charge
        $receipt_charge = floatval($loan['receipt_charge'] ?? 0);
        $total_payable = $remaining_principal + $payable_interest + $receipt_charge;
        
    } else {
        $error = "No open loan found with ID: " . $loan_id;
        $loan = null;
    }
}

// Handle advance payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'pay_advance') {
        $loan_id = intval($_POST['loan_id']);
        $advance_principal = floatval($_POST['advance_principal'] ?? 0);
        $advance_interest = floatval($_POST['advance_interest'] ?? 0);
        $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode'] ?? 'cash');
        $employee_id = intval($_SESSION['user_id']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Advance payment');
        
        if ($advance_principal > 0 || $advance_interest > 0) {
            // Begin transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Generate payment receipt number
                $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
                $receipt_result = mysqli_query($conn, $receipt_query);
                $receipt_count = mysqli_fetch_assoc($receipt_result)['count'] + 1;
                $payment_receipt = 'ADV' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
                
                // Insert advance payment
                $insert_payment = "INSERT INTO payments (
                    loan_id, receipt_number, payment_date, principal_amount, 
                    interest_amount, total_amount, payment_mode, employee_id, remarks
                ) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $insert_payment);
                $total_amount = $advance_principal + $advance_interest;
                
                mysqli_stmt_bind_param($stmt, 'isdddiss', 
                    $loan_id, $payment_receipt, 
                    $advance_principal, $advance_interest, $total_amount,
                    $payment_mode, $employee_id, $remarks
                );
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error adding advance payment: " . mysqli_stmt_error($stmt));
                }
                
                mysqli_commit($conn);
                $message = "Advance payment of ₹" . number_format($total_amount, 2) . " added successfully!";
                
                // Refresh the page to show updated calculations
                header('Location: close-loan.php?id=' . $loan_id . '&success=advance_added');
                exit();
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Error adding advance payment: " . $e->getMessage();
            }
        }
        
    } elseif ($_POST['action'] === 'close_loan') {
        $loan_id = intval($_POST['loan_id']);
        $close_date = mysqli_real_escape_string($conn, $_POST['close_date']);
        $final_principal = floatval($_POST['final_principal']);
        $final_interest = floatval($_POST['final_interest']);
        $receipt_charge = floatval($_POST['receipt_charge'] ?? 0);
        $discount = floatval($_POST['discount'] ?? 0);
        $round_off = floatval($_POST['round_off'] ?? 0);
        $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
        $employee_id = intval($_SESSION['user_id']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Loan closure');
        $d_namuna = isset($_POST['d_namuna']) ? 1 : 0;
        $others = isset($_POST['others']) ? 1 : 0;
        
        $total_amount = $final_principal + $final_interest + $receipt_charge - $discount + $round_off;
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate closure receipt number
            $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
            $receipt_result = mysqli_query($conn, $receipt_query);
            $receipt_count = mysqli_fetch_assoc($receipt_result)['count'] + 1;
            $payment_receipt = 'CLS' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
            
            // Insert final payment if any amount is paid
            if ($total_amount > 0) {
                $insert_payment = "INSERT INTO payments (
                    loan_id, receipt_number, payment_date, principal_amount, 
                    interest_amount, total_amount, payment_mode, employee_id, remarks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $insert_payment);
                mysqli_stmt_bind_param($stmt, 'issdddiss', 
                    $loan_id, $payment_receipt, $close_date,
                    $final_principal, $final_interest, $total_amount,
                    $payment_mode, $employee_id, $remarks
                );
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error adding final payment: " . mysqli_stmt_error($stmt));
                }
            }
            
            // Update loan as closed
            $update_loan = "UPDATE loans SET 
                status = 'closed', 
                close_date = ?,
                discount = ?,
                round_off = ?,
                d_namuna = ?,
                others = ?,
                updated_at = NOW()
                WHERE id = ?";
            
            $stmt = mysqli_prepare($conn, $update_loan);
            mysqli_stmt_bind_param($stmt, 'sddiii', 
                $close_date, $discount, $round_off, $d_namuna, $others, $loan_id
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error closing loan: " . mysqli_stmt_error($stmt));
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'close', ?, 'loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Loan closed with final payment of ₹" . number_format($total_amount, 2);
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            // Redirect to print closure receipt
            header('Location: print-close-receipt.php?id=' . $loan_id);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error closing loan: " . $e->getMessage();
        }
    }
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'advance_added':
            $message = "Advance payment added successfully!";
            break;
    }
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
            font-family: 'Poppins', 'Pyidaungsu', sans-serif;
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

        .close-loan-container {
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

        .btn-warning {
            background: #ecc94b;
            color: white;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        .search-box {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }

        .search-box .form-group {
            flex: 1;
            margin-bottom: 0;
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

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #a0aec0;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px 10px 40px;
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

        .readonly-field {
            background: #f7fafc;
            cursor: not-allowed;
        }

        .info-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Main Content Layout */
        .main-content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .left-panel {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .right-panel {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Loan Info Cards */
        .loan-info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .loan-info-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .info-label {
            font-size: 14px;
            color: #4a5568;
            font-weight: 500;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value.highlight {
            color: #667eea;
            font-size: 16px;
        }

        .info-value.interest {
            color: #ecc94b;
        }

        .info-value.total {
            color: #48bb78;
            font-size: 16px;
        }

        .info-value.warning {
            color: #f56565;
        }

        /* Three Column Layout for Top Section */
        .three-column-layout {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .column {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .column-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Customer Info Box */
        .customer-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .customer-name {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .customer-detail {
            display: flex;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .customer-detail-label {
            width: 100px;
            color: #4a5568;
        }

        .customer-detail-value {
            font-weight: 600;
            color: #2d3748;
        }

        /* Options Row */
        .options-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .option-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #667eea;
        }

        .payment-methods {
            display: flex;
            gap: 20px;
            margin-left: auto;
        }

        .payment-method {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: #4a5568;
        }

        .payment-method i {
            color: #48bb78;
        }

        /* Calculations Row */
        .calculations-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .calc-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }

        .calc-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .calc-value {
            font-size: 18px;
            font-weight: 700;
        }

        .calc-value.principal {
            color: #667eea;
        }

        .calc-value.interest {
            color: #ecc94b;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .items-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .items-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .items-table tfoot td {
            padding: 12px;
            border-top: 2px solid #e2e8f0;
        }

        /* Total Quantity Box */
        .total-quantity-box {
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            border: 1px solid #48bb78;
            border-radius: 8px;
            padding: 15px;
            display: inline-block;
            margin-top: 10px;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
        }

        .action-btn {
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }

        .action-btn.pay-advance {
            background: #667eea;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .action-btn.pay-advance:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .action-btn.close-loan {
            background: #48bb78;
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .action-btn.close-loan:hover {
            background: #38a169;
            transform: translateY(-2px);
        }

        .action-btn.receipt {
            background: #ecc94b;
            color: white;
            box-shadow: 0 4px 15px rgba(236, 201, 75, 0.4);
        }

        .action-btn.receipt:hover {
            background: #d69e2e;
            transform: translateY(-2px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
        }

        .modal-close {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
        }

        @media (max-width: 1200px) {
            .three-column-layout {
                grid-template-columns: 1fr;
            }
            
            .main-content-grid {
                grid-template-columns: 1fr;
            }
            
            .calculations-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .search-box {
                flex-direction: column;
                align-items: stretch;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            
            .options-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .payment-methods {
                margin-left: 0;
            }
            
            .calculations-row {
                grid-template-columns: 1fr;
            }
            
            .items-table {
                overflow-x: auto;
                display: block;
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
                <div class="close-loan-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-file-earmark-x"></i>
                            Close Loan
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

                    <!-- Search Section - Show if no loan selected -->
                    <?php if (!$loan && $loan_id == 0): ?>
                    <div class="search-card">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Find Loan to Close
                        </div>

                        <form method="GET" action="" class="search-box">
                            <div class="form-group">
                                <label class="form-label required">Receipt Number</label>
                                <div class="input-group">
                                    <i class="bi bi-receipt input-icon"></i>
                                    <input type="text" class="form-control" name="receipt" value="<?php echo htmlspecialchars($receipt_search); ?>" placeholder="Enter Receipt Number" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($loan && $customer): ?>
                        <!-- Three Column Layout - Top Section -->
                        <div class="three-column-layout">
                            <!-- Column 1: Loan Basic Info -->
                            <div class="column">
                                <div class="column-title">Loan Information</div>
                                
                                <div class="info-row">
                                    <span class="info-label">Loan ID</span>
                                    <span class="info-value highlight"><?php echo $loan['id']; ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Receipt Number</span>
                                    <span class="info-value"><?php echo $loan['receipt_number']; ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Receipt Date</span>
                                    <span class="info-value"><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Product Type</span>
                                    <span class="info-value"><?php echo $loan['product_type'] ?? 'Jewelry'; ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Interest Type</span>
                                    <span class="info-value"><?php echo $loan['interest_type']; ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Employee Name</span>
                                    <span class="info-value"><?php echo $loan['employee_name']; ?></span>
                                </div>
                            </div>

                            <!-- Column 2: Weight & Calculations -->
                            <div class="column">
                                <div class="column-title">Weight & Calculations</div>
                                
                                <div class="info-row">
                                    <span class="info-label">Gross Weight</span>
                                    <span class="info-value"><?php echo $loan['gross_weight']; ?> g</span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Net Weight</span>
                                    <span class="info-value"><?php echo $loan['net_weight']; ?> g</span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Principal Amount</span>
                                    <span class="info-value">₹ <?php echo number_format($principal, 2); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Payable Principal</span>
                                    <span class="info-value highlight">₹ <?php echo number_format($remaining_principal, 2); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">1 Month Interest</span>
                                    <span class="info-value">₹ <?php echo number_format($one_month_interest, 2); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Payable Interest</span>
                                    <span class="info-value interest">₹ <?php echo number_format($payable_interest, 2); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Receipt Charge</span>
                                    <span class="info-value">₹ <?php echo number_format($loan['receipt_charge'], 2); ?></span>
                                </div>
                            </div>

                            <!-- Column 3: Customer Info & Options -->
                            <div class="column">
                                <div class="column-title">Customer Information</div>
                                
                                <div class="info-row">
                                    <span class="info-label">Customer Name</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['name']); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Mobile Number</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['mobile']); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Guardian</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['guardian']); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Address</span>
                                    <span class="info-value"><?php echo htmlspecialchars(substr($customer['address'], 0, 30)) . '...'; ?></span>
                                </div>
                                
                                <div class="options-row">
                                    <div class="option-item">
                                        <input type="checkbox" id="d_namuna" name="d_namuna">
                                        <label for="d_namuna">D-Namuna</label>
                                    </div>
                                    <div class="option-item">
                                        <input type="checkbox" id="others" name="others">
                                        <label for="others">Others</label>
                                    </div>
                                </div>
                                
                                <div class="payment-methods">
                                    <div class="payment-method">
                                        <i class="bi bi-cash"></i> Cash
                                    </div>
                                    <div class="payment-method">
                                        <i class="bi bi-bank"></i> Other Payments
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Advance & Interest Row - Calculations -->
                        <div class="calculations-row">
                            <div class="calc-box">
                                <div class="calc-label">Advance Principal</div>
                                <div class="calc-value principal">₹ <?php echo number_format($advance_principal, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Advance Interest</div>
                                <div class="calc-value interest">₹ <?php echo number_format($advance_interest, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Total Interest</div>
                                <div class="calc-value interest">₹ <?php echo number_format($total_interest_due, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Balance Months</div>
                                <div class="calc-value"><?php echo $balance_months; ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Total Days</div>
                                <div class="calc-value"><?php echo $total_days; ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Discount</div>
                                <div class="calc-value warning">₹ 0.00</div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Round Off</div>
                                <div class="calc-value">₹ 0.00</div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Total Payable</div>
                                <div class="calc-value total">₹ <?php echo number_format($total_payable, 2); ?></div>
                            </div>
                        </div>

                        <!-- Product Info -->
                        <div class="loan-info-card">
                            <div class="loan-info-title">Product Details</div>
                            <div class="info-row">
                                <span class="info-label">Product</span>
                                <span class="info-value"><?php echo $loan['product_type'] ?? 'Jewelry'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Principal / Interest</span>
                                <span class="info-value">₹ <?php echo number_format($principal, 2); ?> / <?php echo $interest_rate; ?>%</span>
                            </div>
                        </div>

                        <!-- Jewelry Items Table -->
                        <div class="loan-info-card">
                            <div class="loan-info-title">Jewelry Items</div>
                            
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>S.No.</th>
                                        <th>Jewel Name</th>
                                        <th>Defect Details</th>
                                        <th>Stone Details</th>
                                        <th>Karat</th>
                                        <th>Net Weight</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $sno = 1;
                                    $total_qty = 0;
                                    foreach ($items as $item): 
                                        $total_qty += $item['quantity'];
                                    ?>
                                    <tr>
                                        <td><?php echo $sno++; ?></td>
                                        <td><?php echo htmlspecialchars($item['jewel_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['defect_details'] ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($item['stone_details'] ?: '-'); ?></td>
                                        <td><?php echo $item['karat']; ?>K</td>
                                        <td><?php echo $item['net_weight']; ?> g</td>
                                        <td><?php echo $item['quantity']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="6" style="text-align: right;">Total Quantity:</td>
                                        <td><?php echo $total_qty; ?></td>
                                    </tr>
                                    <tr>
                                        <td colspan="6" style="text-align: right;">Total Net Weight:</td>
                                        <td><?php echo number_format($total_net_weight, 3); ?> g</td>
                                    </tr>
                                </tfoot>
                            </table>
                            
                            <div class="total-quantity-box">
                                <strong>Total Weight:</strong> <?php echo number_format($total_net_weight, 3); ?> g
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button class="action-btn pay-advance" onclick="showAdvanceModal()">
                                <i class="bi bi-cash-stack"></i> Pay Advance
                            </button>
                            <button class="action-btn close-loan" onclick="showCloseModal()">
                                <i class="bi bi-check-circle"></i> Close Loan
                            </button>
                            <button class="action-btn receipt" onclick="window.location.href='print-receipt.php?id=<?php echo $loan_id; ?>'">
                                <i class="bi bi-receipt"></i> Receipt
                            </button>
                        </div>

                        <!-- Hidden form for options -->
                        <form id="optionsForm">
                            <input type="hidden" id="option_d_namuna" name="d_namuna" value="0">
                            <input type="hidden" id="option_others" name="others" value="0">
                        </form>

                        <!-- Advance Payment Modal -->
                        <div class="modal" id="advanceModal">
                            <div class="modal-content">
                                <span class="modal-close" onclick="hideAdvanceModal()">&times;</span>
                                <h3 class="modal-title">Pay Advance</h3>
                                
                                <form method="POST" action="" id="advanceForm">
                                    <input type="hidden" name="action" value="pay_advance">
                                    <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label">Advance Principal (₹)</label>
                                        <input type="number" class="form-control" name="advance_principal" id="advance_principal" value="0" step="0.01" min="0" max="<?php echo $remaining_principal; ?>" onchange="calculateAdvanceTotal()">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Advance Interest (₹)</label>
                                        <input type="number" class="form-control" name="advance_interest" id="advance_interest" value="0" step="0.01" min="0" max="<?php echo $payable_interest; ?>" onchange="calculateAdvanceTotal()">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Payment Mode</label>
                                        <select class="form-select" name="payment_mode" required>
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank Transfer</option>
                                            <option value="upi">UPI</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Remarks</label>
                                        <textarea class="form-control" name="remarks" rows="2" placeholder="Enter remarks"></textarea>
                                    </div>
                                    
                                    <div class="summary-box" style="background: #f7fafc; padding: 15px; border-radius: 8px; margin: 15px 0;">
                                        <div class="info-row">
                                            <span>Total Advance:</span>
                                            <span style="font-weight: 700; color: #667eea;" id="advance_total">₹ 0.00</span>
                                        </div>
                                    </div>
                                    
                                    <div class="action-buttons" style="margin-top: 20px;">
                                        <button type="button" class="btn btn-secondary" onclick="hideAdvanceModal()">Cancel</button>
                                        <button type="submit" class="btn btn-success">Pay Advance</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Close Loan Modal -->
                        <div class="modal" id="closeModal">
                            <div class="modal-content">
                                <span class="modal-close" onclick="hideCloseModal()">&times;</span>
                                <h3 class="modal-title">Close Loan</h3>
                                
                                <form method="POST" action="" id="closeForm">
                                    <input type="hidden" name="action" value="close_loan">
                                    <input type="hidden" name="loan_id" value="<?php echo $loan_id; ?>">
                                    <input type="hidden" name="final_principal" value="<?php echo $remaining_principal; ?>">
                                    <input type="hidden" name="final_interest" value="<?php echo $payable_interest; ?>">
                                    <input type="hidden" name="receipt_charge" value="<?php echo $loan['receipt_charge']; ?>">
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Close Date</label>
                                        <input type="date" class="form-control" name="close_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="row" style="display: flex; gap: 15px;">
                                        <div style="flex: 1;">
                                            <label class="form-label">Discount (₹)</label>
                                            <input type="number" class="form-control" name="discount" id="discount" value="0" step="0.01" min="0">
                                        </div>
                                        <div style="flex: 1;">
                                            <label class="form-label">Round Off (₹)</label>
                                            <input type="number" class="form-control" name="round_off" id="round_off" value="0" step="0.01">
                                        </div>
                                    </div>
                                    
                                    <div class="options-row">
                                        <div class="option-item">
                                            <input type="checkbox" name="d_namuna" id="modal_d_namuna">
                                            <label for="modal_d_namuna">D-Namuna</label>
                                        </div>
                                        <div class="option-item">
                                            <input type="checkbox" name="others" id="modal_others">
                                            <label for="modal_others">Others</label>
                                        </div>
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
                                        <textarea class="form-control" name="remarks" rows="2" placeholder="Enter remarks"></textarea>
                                    </div>
                                    
                                    <div class="summary-box" style="background: #f7fafc; padding: 15px; border-radius: 8px; margin: 15px 0;">
                                        <div class="info-row">
                                            <span>Principal Due:</span>
                                            <span>₹ <?php echo number_format($remaining_principal, 2); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span>Interest Due:</span>
                                            <span>₹ <?php echo number_format($payable_interest, 2); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span>Receipt Charge:</span>
                                            <span>₹ <?php echo number_format($loan['receipt_charge'], 2); ?></span>
                                        </div>
                                        <div class="info-row" style="border-top: 2px solid #48bb78; margin-top: 10px; padding-top: 10px;">
                                            <span style="font-weight: 700;">Total Payable:</span>
                                            <span style="font-weight: 700; color: #48bb78;">₹ <?php echo number_format($total_payable, 2); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="action-buttons" style="margin-top: 20px;">
                                        <button type="button" class="btn btn-secondary" onclick="hideCloseModal()">Cancel</button>
                                        <button type="submit" class="btn btn-success" onclick="return confirmClose()">Close Loan</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Update options
        document.getElementById('d_namuna')?.addEventListener('change', function() {
            document.getElementById('option_d_namuna').value = this.checked ? 1 : 0;
        });
        
        document.getElementById('others')?.addEventListener('change', function() {
            document.getElementById('option_others').value = this.checked ? 1 : 0;
        });

        // Calculate advance total
        function calculateAdvanceTotal() {
            const principal = parseFloat(document.getElementById('advance_principal').value) || 0;
            const interest = parseFloat(document.getElementById('advance_interest').value) || 0;
            const total = principal + interest;
            document.getElementById('advance_total').innerHTML = '₹ ' + total.toFixed(2);
        }

        // Show/hide modals
        function showAdvanceModal() {
            document.getElementById('advanceModal').classList.add('active');
        }

        function hideAdvanceModal() {
            document.getElementById('advanceModal').classList.remove('active');
        }

        function showCloseModal() {
            document.getElementById('closeModal').classList.add('active');
        }

        function hideCloseModal() {
            document.getElementById('closeModal').classList.remove('active');
        }

        // Confirm close with SweetAlert
        function confirmClose() {
            Swal.fire({
                title: 'Close Loan?',
                text: 'Are you sure you want to close this loan? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#f56565',
                confirmButtonText: 'Yes, Close Loan',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('closeForm').submit();
                }
            });
            return false;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const advanceModal = document.getElementById('advanceModal');
            const closeModal = document.getElementById('closeModal');
            
            if (event.target == advanceModal) {
                hideAdvanceModal();
            }
            if (event.target == closeModal) {
                hideCloseModal();
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);

        // Show success message if redirected with success
        <?php if (isset($_GET['success']) && $_GET['success'] == 'advance_added'): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Advance payment added successfully!',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        // Show error message if any
        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>