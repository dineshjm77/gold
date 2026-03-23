<?php
session_start();
$currentPage = 'close-loan';
$pageTitle = 'Close Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';
require_once 'includes/email_helper.php';

// Check if user has permission (admin or sale, accountant)
if (!in_array($_SESSION['user_role'], ['admin', 'sale', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$loan = null;
$customer = null;
$loan_id = 0;

// Get receiving persons (employees who can receive jewelry)
$receiving_persons_query = "SELECT id, name, role FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale' OR role = 'manager' OR role = 'accountant') ORDER BY name";
$receiving_persons_result = mysqli_query($conn, $receiving_persons_query);

// Get employees for dropdown
$employees_query = "SELECT id, name FROM users WHERE status = 'active' ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Check if the remaining_principal column exists
$check_columns_query = "SHOW COLUMNS FROM loans LIKE 'remaining_principal'";
$check_columns_result = mysqli_query($conn, $check_columns_query);
$has_remaining_column = (mysqli_num_rows($check_columns_result) > 0);

// Check and add collection_person_photo column if needed
$check_photo_column = "SHOW COLUMNS FROM jewelry_returns LIKE 'collection_person_photo'";
$photo_col_result = mysqli_query($conn, $check_photo_column);
if (mysqli_num_rows($photo_col_result) == 0) {
    $add_photo_column = "ALTER TABLE jewelry_returns ADD COLUMN collection_person_photo VARCHAR(255) DEFAULT NULL AFTER receiving_person_id_proof";
    mysqli_query($conn, $add_photo_column);
}

// Handle AJAX search request for live search
if (isset($_GET['ajax_search']) && isset($_GET['term'])) {
    header('Content-Type: application/json');
    $search_term = mysqli_real_escape_string($conn, $_GET['term']);
    $search_term_like = '%' . $search_term . '%';
    
    // Check open loans count first
    $count_query = "SELECT COUNT(*) as count FROM loans WHERE status = 'open'";
    $count_result = mysqli_query($conn, $count_query);
    $open_loans_count = mysqli_fetch_assoc($count_result)['count'];
    
    // Use the same query structure as loan-collection.php
    if ($has_remaining_column) {
        $ajax_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                       COALESCE(l.remaining_principal, l.loan_amount) as remaining_principal,
                       COALESCE(l.pending_interest, 0) as pending_interest,
                       l.interest_amount,
                       l.receipt_date, 
                       COALESCE(l.total_overdue_amount, 0) as total_overdue_amount,
                       c.customer_name, c.mobile_number, c.email
                       FROM loans l
                       JOIN customers c ON l.customer_id = c.id
                       WHERE l.status = 'open' 
                       AND (l.receipt_number LIKE ? OR c.customer_name LIKE ? OR c.mobile_number LIKE ?)
                       ORDER BY l.receipt_date DESC
                       LIMIT 50";
    } else {
        $ajax_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                       l.loan_amount as remaining_principal,
                       0 as pending_interest,
                       l.interest_amount,
                       l.receipt_date, 
                       0 as total_overdue_amount,
                       c.customer_name, c.mobile_number, c.email
                       FROM loans l
                       JOIN customers c ON l.customer_id = c.id
                       WHERE l.status = 'open' 
                       AND (l.receipt_number LIKE ? OR c.customer_name LIKE ? OR c.mobile_number LIKE ?)
                       ORDER BY l.receipt_date DESC
                       LIMIT 50";
    }
    
    $stmt = mysqli_prepare($conn, $ajax_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sss', $search_term_like, $search_term_like, $search_term_like);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $loans = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // Determine match type
            $match_type = 'partial';
            if (stripos($row['receipt_number'], $search_term) === 0) {
                $match_type = 'receipt_start';
            } elseif (stripos($row['customer_name'], $search_term) === 0) {
                $match_type = 'name_start';
            } elseif ($row['receipt_number'] == $search_term) {
                $match_type = 'exact';
            }
            
            $loans[] = [
                'id' => $row['id'],
                'receipt_number' => $row['receipt_number'],
                'customer_name' => $row['customer_name'],
                'mobile' => $row['mobile_number'],
                'loan_amount' => round(floatval($row['loan_amount']), 2),
                'remaining_principal' => round(floatval($row['remaining_principal']), 2),
                'pending_interest' => round(floatval($row['pending_interest']), 2),
                'interest_rate' => $row['interest_amount'],
                'overdue_amount' => round(floatval($row['total_overdue_amount']), 2),
                'match_type' => $match_type
            ];
        }
        
        echo json_encode([
            'results' => $loans, 
            'count' => count($loans), 
            'search_term' => $search_term,
            'open_loans_count' => $open_loans_count
        ]);
    } else {
        echo json_encode([
            'error' => 'Database error: ' . mysqli_error($conn), 
            'results' => [],
            'open_loans_count' => $open_loans_count
        ]);
    }
    exit();
}

// Get loan ID from URL
if (isset($_GET['id'])) {
    $loan_id = intval($_GET['id']);
} elseif (isset($_GET['loan_id'])) {
    $loan_id = intval($_GET['loan_id']);
}

// Handle receipt search
$search_term = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

// If receipt number is provided, search for loan
if (!empty($search_term) && $loan_id == 0) {
    $search_query = "SELECT id FROM loans WHERE receipt_number = ? AND status = 'open'";
    $stmt = mysqli_prepare($conn, $search_query);
    mysqli_stmt_bind_param($stmt, 's', $search_term);
    mysqli_stmt_execute($stmt);
    $search_result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($search_result)) {
        $loan_id = $row['id'];
    } else {
        // Try searching by customer name or mobile
        $search_like = '%' . $search_term . '%';
        $search_query = "SELECT l.id FROM loans l 
                         JOIN customers c ON l.customer_id = c.id 
                         WHERE l.status = 'open' 
                         AND (c.customer_name LIKE ? OR c.mobile_number LIKE ?)
                         LIMIT 1";
        $stmt = mysqli_prepare($conn, $search_query);
        mysqli_stmt_bind_param($stmt, 'ss', $search_like, $search_like);
        mysqli_stmt_execute($stmt);
        $search_result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($search_result)) {
            $loan_id = $row['id'];
        } else {
            $error = "No open loan found matching: " . htmlspecialchars($search_term);
        }
    }
}

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
        $total_days = max(1, $loan['total_days']);
        
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
        
        // Calculate remaining principal
        $remaining_principal = $principal - $paid_principal;
        
        // Calculate interest based on interest type
        $interest_type = $loan['interest_type'] ?? 'monthly';
        if ($interest_type == 'daily') {
            $daily_interest = ($principal * $interest_rate) / 100 / 30;
            $total_interest_accrued = $total_days * $daily_interest;
        } else {
            $monthly_interest = ($principal * $interest_rate) / 100;
            $daily_interest = $monthly_interest / 30;
            $total_interest_accrued = $total_days * $daily_interest;
        }
        
        // Calculate payable interest
        $payable_interest = $total_interest_accrued - $paid_interest;
        if ($payable_interest < 0) $payable_interest = 0;
        
        // Calculate 1 month interest
        if ($interest_type == 'daily') {
            $one_month_interest = ($principal * $interest_rate) / 100;
        } else {
            $one_month_interest = ($principal * $interest_rate) / 100;
        }
        
        // Total interest due
        $total_interest_due = $payable_interest;
        
        // Round all values
        $principal = round($principal, 2);
        $remaining_principal = round($remaining_principal, 2);
        $one_month_interest = round($one_month_interest, 2);
        $daily_interest = round($daily_interest, 2);
        $total_interest_accrued = round($total_interest_accrued, 2);
        $paid_interest = round($paid_interest, 2);
        $payable_interest = round($payable_interest, 2);
        $total_interest_due = round($total_interest_due, 2);
        $paid_principal = round($paid_principal, 2);
        
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
            mysqli_begin_transaction($conn);
            
            try {
                $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
                $receipt_result = mysqli_query($conn, $receipt_query);
                $receipt_count = mysqli_fetch_assoc($receipt_result)['count'] + 1;
                $payment_receipt = 'ADV' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
                
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
        
        // Jewelry collection person details
        $collection_type = mysqli_real_escape_string($conn, $_POST['collection_type'] ?? 'customer');
        $receiving_person_name = '';
        $receiving_person_relation = '';
        $receiving_person_mobile = '';
        $receiving_person_id_proof = '';
        $other_person_photo = '';
        $receiving_person_signature = isset($_POST['signature_verified']) ? 1 : 0;
        $id_proof_verified = isset($_POST['id_proof_verified']) ? 1 : 0;
        
        if ($collection_type === 'customer') {
            $receiving_person_name = $customer['name'];
            $receiving_person_relation = 'Self';
            $receiving_person_mobile = $customer['mobile'];
            $receiving_person_id_proof = $customer['aadhaar'] ?? '';
        } else {
            $receiving_person_name = mysqli_real_escape_string($conn, $_POST['other_person_name'] ?? '');
            $receiving_person_relation = mysqli_real_escape_string($conn, $_POST['other_person_relation'] ?? '');
            $receiving_person_mobile = mysqli_real_escape_string($conn, $_POST['other_person_mobile'] ?? '');
            $receiving_person_id_proof = mysqli_real_escape_string($conn, $_POST['other_person_id_proof'] ?? '');
            $other_person_photo = $_POST['other_person_photo'] ?? '';
            
            if (empty($receiving_person_name)) {
                $error = "Please enter the name of the person collecting the jewelry";
            }
        }
        
        $total_amount = $final_principal + $final_interest + $receipt_charge - $discount + $round_off;
        
        if (empty($error)) {
            mysqli_begin_transaction($conn);
            
            try {
                // Save other person photo if provided
                $saved_photo_path = '';
                if (!empty($other_person_photo) && strpos($other_person_photo, 'data:image') === 0) {
                    $upload_dir = __DIR__ . '/uploads/collection_persons/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $photo_data = explode(',', $other_person_photo);
                    if (count($photo_data) > 1) {
                        $photo_binary = base64_decode($photo_data[1]);
                        $filename = 'collection_' . $loan_id . '_' . time() . '.jpg';
                        $filepath = $upload_dir . $filename;
                        file_put_contents($filepath, $photo_binary);
                        $saved_photo_path = 'uploads/collection_persons/' . $filename;
                    }
                }
                
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
                
                // Insert jewelry return record
                $insert_return = "INSERT INTO jewelry_returns (
                    loan_id, loan_receipt_number, customer_id, customer_name,
                    return_date, collection_type, receiving_person_name, receiving_person_relation,
                    receiving_person_mobile, receiving_person_id_proof, collection_person_photo,
                    signature_verified, id_proof_verified, remarks, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $insert_return);
                mysqli_stmt_bind_param($stmt, 'isississsssissi', 
                    $loan_id, $loan['receipt_number'], $customer['id'], $customer['name'],
                    $close_date, $collection_type, $receiving_person_name, $receiving_person_relation,
                    $receiving_person_mobile, $receiving_person_id_proof, $saved_photo_path,
                    $receiving_person_signature, $id_proof_verified, $remarks, $employee_id
                );
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error recording jewelry return: " . mysqli_stmt_error($stmt));
                }
                
                $return_id = mysqli_insert_id($conn);
                
                // Insert jewelry items return details
                foreach ($items as $item) {
                    $insert_item_return = "INSERT INTO jewelry_return_items (
                        return_id, loan_id, item_id, jewel_name, karat, 
                        net_weight, quantity, defect_details, stone_details
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = mysqli_prepare($conn, $insert_item_return);
                    mysqli_stmt_bind_param($stmt, 'iiissdiss', 
                        $return_id, $loan_id, $item['id'], $item['jewel_name'], $item['karat'],
                        $item['net_weight'], $item['quantity'], $item['defect_details'], $item['stone_details']
                    );
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error recording jewelry item return: " . mysqli_stmt_error($stmt));
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
                $log_description = "Loan closed with final payment of ₹" . number_format($total_amount, 2) . ". Jewelry collected by: " . 
                                   ($collection_type == 'customer' ? "Customer: " . $customer['name'] : 
                                    "Other Person: " . $receiving_person_name . " (Relation: " . $receiving_person_relation . ")");
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
                mysqli_stmt_execute($log_stmt);
                
                mysqli_commit($conn);
                
                // Send email notification if customer has email
                if (!empty($customer['email'])) {
                    $email_details = [
                        'customer_name' => $customer['name'],
                        'customer_email' => $customer['email'],
                        'loan_receipt' => $loan['receipt_number'],
                        'close_date' => $close_date,
                        'remaining_principal' => $final_principal,
                        'interest_paid' => $final_interest,
                        'total_paid' => $total_amount,
                        'collection_type' => $collection_type,
                        'collection_person' => $receiving_person_name,
                        'receiving_person' => $receiving_person_name,
                        'items_count' => count($items),
                        'items' => $items
                    ];
                    sendLoanClosureConfirmationEmail($email_details, $conn);
                }
                
                // Redirect to print closure receipt
                header('Location: print-close-receipt.php?id=' . $loan_id);
                exit();
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Error closing loan: " . $e->getMessage();
            }
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

// Get total open loans for stats
$open_loans_count_query = "SELECT COUNT(*) as count FROM loans WHERE status = 'open'";
$open_count_result = mysqli_query($conn, $open_loans_count_query);
$open_count = mysqli_fetch_assoc($open_count_result)['count'];

$total_principal_query = "SELECT SUM(loan_amount) as total FROM loans WHERE status = 'open'";
$total_result = mysqli_query($conn, $total_principal_query);
$total_data = mysqli_fetch_assoc($total_result);
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
            font-family: 'Poppins', sans-serif;
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

        .search-title i {
            color: #667eea;
        }

        .live-search-container {
            position: relative;
            margin-bottom: 20px;
        }

        .live-search-input {
            width: 100%;
            padding: 15px 20px 15px 50px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .live-search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            font-size: 20px;
        }

        .clear-search {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            cursor: pointer;
            font-size: 20px;
            display: none;
        }

        .clear-search:hover {
            color: #f56565;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #667eea;
            border-radius: 12px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            margin-top: 5px;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .search-result-item:hover {
            background: #ebf4ff;
        }

        .match-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            background: #667eea20;
            color: #667eea;
            font-weight: 600;
        }

        .match-badge.exact {
            background: #48bb7820;
            color: #276749;
        }

        .result-receipt {
            font-weight: 700;
            color: #667eea;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .result-customer {
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
            margin-bottom: 3px;
        }

        .result-details {
            display: flex;
            gap: 20px;
            font-size: 13px;
            color: #718096;
            flex-wrap: wrap;
            margin-top: 5px;
        }

        .result-mobile {
            color: #48bb78;
        }

        .result-balance {
            color: #4299e1;
            font-weight: 600;
        }

        .result-overdue {
            color: #f56565;
            font-weight: 600;
        }

        .search-stats {
            margin-top: 10px;
            font-size: 13px;
            color: #718096;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .quick-stats {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
            flex-wrap: wrap;
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

        .section-title i {
            color: #667eea;
        }

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

        .calc-value.total {
            color: #48bb78;
        }

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

        .total-quantity-box {
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            border: 1px solid #48bb78;
            border-radius: 8px;
            padding: 15px;
            display: inline-block;
            margin-top: 10px;
        }

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

        .action-btn.close-loan {
            background: #48bb78;
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

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
            max-width: 700px;
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

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
        }

        .form-label.required:after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
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
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

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
            gap: 8px;
        }

        .option-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .summary-box {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .collection-person-section {
            background: #ebf8ff;
            border-left: 4px solid #4299e1;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .collection-person-title {
            font-weight: 600;
            color: #2c5282;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .collection-type-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }

        .collection-type-btn {
            flex: 1;
            padding: 12px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s;
        }

        .collection-type-btn.active {
            background: #4299e1;
            border-color: #4299e1;
            color: white;
        }

        .collection-type-btn:hover:not(.active) {
            border-color: #4299e1;
        }

        .other-person-fields {
            display: none;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #cbd5e0;
        }

        .other-person-fields.show {
            display: block;
        }

        .loading-indicator {
            text-align: center;
            padding: 20px;
            color: #667eea;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #a0aec0;
        }

        .no-results i {
            font-size: 48px;
            margin-bottom: 10px;
            display: block;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .spin {
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        .camera-preview {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
        }

        video, canvas, img {
            width: 100%;
            border-radius: 8px;
        }

        @media (max-width: 1200px) {
            .three-column-layout {
                grid-template-columns: 1fr;
            }
            
            .calculations-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .calculations-row {
                grid-template-columns: 1fr;
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

                    <!-- Live Search Section - Show if no loan selected -->
                    <?php if (!$loan && $loan_id == 0): ?>
                    <div class="search-card">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Find Loan to Close
                        </div>

                        <div class="live-search-container">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="live-search-input" id="liveSearch" 
                                   placeholder="Type receipt number, customer name or mobile number..." 
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                   autocomplete="off">
                            <i class="bi bi-x-circle clear-search" id="clearSearch" onclick="clearSearch()"></i>
                            
                            <div class="search-results" id="searchResults"></div>
                        </div>

                        <div class="search-stats">
                            <span id="searchStats">Type at least 2 characters to search</span>
                            <span id="resultCount"></span>
                        </div>

                        <div class="quick-stats">
                            <div><strong>Total Open Loans:</strong> <?php echo $open_count; ?></div>
                            <div><strong>Total Outstanding:</strong> ₹ <?php echo number_format($total_data['total'] ?? 0, 0); ?></div>
                        </div>
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
                                    <span class="info-label">Interest Type</span>
                                    <span class="info-value interest"><?php echo ucfirst($interest_type); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Interest Rate</span>
                                    <span class="info-value interest"><?php echo $interest_rate; ?>%</span>
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
                                    <span class="info-value">₹ <?php echo number_format($loan['receipt_charge'] ?? 0, 2); ?></span>
                                </div>
                            </div>

                            <!-- Column 3: Customer Info -->
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
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></span>
                                </div>
                                
                                <div class="info-row">
                                    <span class="info-label">Address</span>
                                    <span class="info-value"><?php echo htmlspecialchars(substr($customer['address'], 0, 40)); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Advance & Interest Row - Calculations -->
                        <div class="calculations-row">
                            <div class="calc-box">
                                <div class="calc-label">Paid Principal</div>
                                <div class="calc-value principal">₹ <?php echo number_format($paid_principal, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Paid Interest</div>
                                <div class="calc-value interest">₹ <?php echo number_format($paid_interest, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Total Interest Due</div>
                                <div class="calc-value interest">₹ <?php echo number_format($total_interest_due, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Total Days</div>
                                <div class="calc-value"><?php echo $total_days; ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Daily Interest</div>
                                <div class="calc-value interest">₹ <?php echo number_format($daily_interest, 2); ?></div>
                            </div>
                            <div class="calc-box">
                                <div class="calc-label">Discount</div>
                                <div class="calc-value">₹ 0.00</div>
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

                        <!-- Jewelry Items Table -->
                        <div class="info-card">
                            <div class="section-title">
                                <i class="bi bi-gem"></i>
                                Jewelry Items to Return
                            </div>
                            
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
                                        <td colspan="6" style="text-align: right;"><strong>Total Quantity:</strong></td>
                                        <td><strong><?php echo $total_qty; ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                            
                            <div class="total-quantity-box">
                                <i class="bi bi-calculator"></i> Total Items: <?php echo count($items); ?> | Total Quantity: <?php echo $total_qty; ?> pcs
                            </div>
                        </div>

                        <!-- Payment History -->
                        <?php if (!empty($payments)): ?>
                        <div class="info-card">
                            <div class="section-title">
                                <i class="bi bi-clock-history"></i>
                                Payment History
                            </div>
                            
                            <table class="items-table">
                                <thead>
                                     <tr>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Principal (₹)</th>
                                        <th>Interest (₹)</th>
                                        <th>Total (₹)</th>
                                        <th>Mode</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><strong><?php echo $payment['receipt_number']; ?></strong></td>
                                        <td>₹ <?php echo number_format($payment['principal_amount'], 2); ?></td>
                                        <td>₹ <?php echo number_format($payment['interest_amount'], 2); ?></td>
                                        <td><strong>₹ <?php echo number_format($payment['total_amount'], 2); ?></strong></td>
                                        <td><?php echo strtoupper($payment['payment_mode']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['remarks']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="action-btn pay-advance" onclick="showAdvanceModal()">
                                <i class="bi bi-cash"></i> Add Advance Payment
                            </button>
                            <button type="button" class="action-btn close-loan" onclick="showCloseModal()">
                                <i class="bi bi-check-circle"></i> Close Loan
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Advance Payment Modal -->
    <div class="modal" id="advanceModal">
        <div class="modal-content">
            <span class="modal-close" onclick="hideAdvanceModal()">&times;</span>
            <h3 class="modal-title">Add Advance Payment</h3>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="pay_advance">
                <input type="hidden" name="loan_id" value="<?php echo $loan['id'] ?? ''; ?>">
                
                <div class="form-group">
                    <label class="form-label required">Advance Principal Amount (₹)</label>
                    <input type="number" class="form-control" name="advance_principal" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Advance Interest Amount (₹)</label>
                    <input type="number" class="form-control" name="advance_interest" step="0.01" min="0" value="0">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Payment Mode</label>
                    <select class="form-select" name="payment_mode" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2">Advance payment</textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="hideAdvanceModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Close Loan Modal -->
    <div class="modal" id="closeModal">
        <div class="modal-content">
            <span class="modal-close" onclick="hideCloseModal()">&times;</span>
            <h3 class="modal-title">Close Loan</h3>
            
            <form method="POST" action="" id="closeLoanForm">
                <input type="hidden" name="action" value="close_loan">
                <input type="hidden" name="loan_id" value="<?php echo $loan['id'] ?? ''; ?>">
                
                <div class="form-group">
                    <label class="form-label required">Closing Date</label>
                    <input type="date" class="form-control" name="close_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Final Principal (₹)</label>
                    <input type="number" class="form-control" name="final_principal" id="final_principal" step="0.01" min="0" value="<?php echo $remaining_principal ?? 0; ?>" required onchange="updateTotalPayable()">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Final Interest (₹)</label>
                    <input type="number" class="form-control" name="final_interest" id="final_interest" step="0.01" min="0" value="<?php echo $payable_interest ?? 0; ?>" required onchange="updateTotalPayable()">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Receipt Charge (₹)</label>
                    <input type="number" class="form-control" name="receipt_charge" id="receipt_charge" step="0.01" min="0" value="<?php echo $loan['receipt_charge'] ?? 0; ?>" onchange="updateTotalPayable()">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Discount (₹)</label>
                    <input type="number" class="form-control" name="discount" id="discount" step="0.01" min="0" value="0" onchange="updateTotalPayable()">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Round Off (₹)</label>
                    <input type="number" class="form-control" name="round_off" id="round_off" step="0.01" value="0" onchange="updateTotalPayable()">
                </div>
                
                <div class="summary-box">
                    <div class="info-row">
                        <span class="info-label">Total Payable:</span>
                        <span class="info-value total" id="total_payable_display">₹ <?php echo number_format($total_payable ?? 0, 2); ?></span>
                    </div>
                </div>
                
                <div class="options-row">
                    <div class="option-item">
                        <input type="checkbox" name="d_namuna" id="d_namuna">
                        <label for="d_namuna">D. Namuna</label>
                    </div>
                    <div class="option-item">
                        <input type="checkbox" name="others" id="others">
                        <label for="others">Others</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Payment Mode</label>
                    <select class="form-select" name="payment_mode" required>
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <!-- Jewelry Collection Person Section -->
                <div class="collection-person-section">
                    <div class="collection-person-title">
                        <i class="bi bi-person-check"></i> Jewelry Collection Person Details
                    </div>
                    
                    <div class="collection-type-buttons">
                        <div class="collection-type-btn active" onclick="setCollectionType('customer')">
                            <i class="bi bi-person"></i> Customer Self
                        </div>
                        <div class="collection-type-btn" onclick="setCollectionType('other')">
                            <i class="bi bi-person-badge"></i> Other Person
                        </div>
                    </div>
                    
                    <input type="hidden" name="collection_type" id="collection_type" value="customer">
                    
                    <div id="otherPersonFields" class="other-person-fields">
                        <div class="form-group">
                            <label class="form-label required">Person Name</label>
                            <input type="text" class="form-control" name="other_person_name" id="other_person_name">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Relation with Customer</label>
                            <input type="text" class="form-control" name="other_person_relation" id="other_person_relation" placeholder="e.g., Brother, Father, Friend">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" class="form-control" name="other_person_mobile" id="other_person_mobile">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">ID Proof Number</label>
                            <input type="text" class="form-control" name="other_person_id_proof" id="other_person_id_proof" placeholder="Aadhaar, Voter ID, etc.">
                        </div>
                        
                        <div class="form-group" id="cameraButtonDiv">
                            <label class="form-label">Person Photo</label>
                            <button type="button" class="btn btn-primary" onclick="openCameraModal()" style="width: 100%;">
                                <i class="bi bi-camera"></i> Take Photo
                            </button>
                            <div id="otherPersonPhotoThumbnail" style="margin-top: 10px; text-align: center;"></div>
                            <input type="hidden" name="other_person_photo" id="other_person_photo" value="">
                        </div>
                    </div>
                    
                    <div class="options-row">
                        <div class="option-item">
                            <input type="checkbox" name="signature_verified" id="signature_verified">
                            <label for="signature_verified">Signature Verified</label>
                        </div>
                        <div class="option-item">
                            <input type="checkbox" name="id_proof_verified" id="id_proof_verified">
                            <label for="id_proof_verified">ID Proof Verified</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2">Loan closure</textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="hideCloseModal()">Cancel</button>
                    <button type="submit" class="btn btn-success" onclick="return confirmClose()">Close Loan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Camera Modal -->
    <div class="modal" id="cameraModal">
        <div class="modal-content" style="max-width: 500px;">
            <span class="modal-close" onclick="closeCameraModal()">&times;</span>
            <h3 class="modal-title">Take Photo of Person Collecting Jewelry</h3>
            
            <div style="text-align: center; margin: 20px 0;">
                <video id="video" width="100%" height="auto" autoplay style="border-radius: 12px; border: 2px solid #667eea;"></video>
                <canvas id="canvas" style="display: none;"></canvas>
                <img id="photoPreview" style="display: none; width: 100%; border-radius: 12px; margin-top: 10px;">
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn btn-primary" id="captureBtn" onclick="capturePhoto()">
                    <i class="bi bi-camera"></i> Capture Photo
                </button>
                <button type="button" class="btn btn-secondary" id="retakeBtn" onclick="retakePhoto()" style="display: none;">
                    <i class="bi bi-arrow-repeat"></i> Retake
                </button>
                <button type="button" class="btn btn-success" id="savePhotoBtn" onclick="savePhoto()" style="display: none;">
                    <i class="bi bi-check-lg"></i> Save Photo
                </button>
            </div>
            
            <div class="form-group" style="margin-top: 20px;">
                <label class="form-label">Photo Status</label>
                <div id="photoStatus"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        let remainingPrincipal = <?php echo $remaining_principal ?? 0; ?>;
        let payableInterest = <?php echo $payable_interest ?? 0; ?>;
        let receiptCharge = <?php echo $loan['receipt_charge'] ?? 0; ?>;

        // Camera functionality
        let stream = null;
        let capturedPhotoData = null;

        function openCameraModal() {
            const modal = document.getElementById('cameraModal');
            modal.classList.add('active');
            
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function(mediaStream) {
                        stream = mediaStream;
                        const video = document.getElementById('video');
                        video.srcObject = stream;
                        video.play();
                        
                        document.getElementById('captureBtn').style.display = 'inline-block';
                        document.getElementById('retakeBtn').style.display = 'none';
                        document.getElementById('savePhotoBtn').style.display = 'none';
                        document.getElementById('photoPreview').style.display = 'none';
                        document.getElementById('video').style.display = 'block';
                    })
                    .catch(function(err) {
                        console.error("Camera error: " + err);
                        alert("Unable to access camera. Please make sure you have granted camera permissions.");
                    });
            } else {
                alert("Camera not supported on this device.");
            }
        }

        function capturePhoto() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            capturedPhotoData = canvas.toDataURL('image/jpeg', 0.8);
            
            const photoPreview = document.getElementById('photoPreview');
            photoPreview.src = capturedPhotoData;
            photoPreview.style.display = 'block';
            
            document.getElementById('video').style.display = 'none';
            document.getElementById('captureBtn').style.display = 'none';
            document.getElementById('retakeBtn').style.display = 'inline-block';
            document.getElementById('savePhotoBtn').style.display = 'inline-block';
            
            document.getElementById('photoStatus').innerHTML = '<span style="color: #48bb78;"><i class="bi bi-check-circle"></i> Photo captured successfully!</span>';
        }

        function retakePhoto() {
            document.getElementById('video').style.display = 'block';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('captureBtn').style.display = 'inline-block';
            document.getElementById('retakeBtn').style.display = 'none';
            document.getElementById('savePhotoBtn').style.display = 'none';
            document.getElementById('photoStatus').innerHTML = '';
            capturedPhotoData = null;
        }

        function savePhoto() {
            if (capturedPhotoData) {
                document.getElementById('other_person_photo').value = capturedPhotoData;
                document.getElementById('photoStatus').innerHTML = '<span style="color: #48bb78;"><i class="bi bi-check-circle-fill"></i> Photo saved!</span>';
                
                const thumbnailDiv = document.getElementById('otherPersonPhotoThumbnail');
                if (thumbnailDiv) {
                    thumbnailDiv.innerHTML = '<img src="' + capturedPhotoData + '" style="width: 80px; height: 80px; border-radius: 8px; object-fit: cover; border: 2px solid #48bb78;">';
                }
                
                setTimeout(() => {
                    closeCameraModal();
                }, 1500);
            }
        }

        function closeCameraModal() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            document.getElementById('cameraModal').classList.remove('active');
            document.getElementById('video').style.display = 'block';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('captureBtn').style.display = 'inline-block';
            document.getElementById('retakeBtn').style.display = 'none';
            document.getElementById('savePhotoBtn').style.display = 'none';
            document.getElementById('photoStatus').innerHTML = '';
        }

        // Live Search Functionality
        let searchTimeout;
        const searchInput = document.getElementById('liveSearch');
        const searchResults = document.getElementById('searchResults');
        const searchStats = document.getElementById('searchStats');
        const resultCount = document.getElementById('resultCount');
        const clearBtn = document.getElementById('clearSearch');

        if (searchInput && searchInput.value.length > 0) {
            clearBtn.style.display = 'block';
        }

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const term = this.value.trim();
                
                if (term.length > 0) {
                    clearBtn.style.display = 'block';
                } else {
                    clearBtn.style.display = 'none';
                    searchResults.classList.remove('show');
                    searchStats.textContent = 'Type at least 2 characters to search';
                    resultCount.textContent = '';
                    return;
                }

                clearTimeout(searchTimeout);

                if (term.length < 2) {
                    searchResults.classList.remove('show');
                    searchStats.textContent = 'Type at least 2 characters to search';
                    resultCount.textContent = '';
                    return;
                }

                searchResults.innerHTML = '<div class="loading-indicator"><i class="bi bi-arrow-repeat spin"></i> Searching...</div>';
                searchResults.classList.add('show');
                searchStats.textContent = `Searching for "${term}"...`;

                searchTimeout = setTimeout(() => {
                    fetch(`close-loan.php?ajax_search=1&term=${encodeURIComponent(term)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                searchResults.innerHTML = `<div class="no-results"><i class="bi bi-exclamation-circle"></i><br>${data.error}</div>`;
                                searchStats.textContent = 'Error searching';
                                resultCount.textContent = '';
                                return;
                            }
                            
                            if (data.open_loans_count === 0) {
                                searchResults.innerHTML = '<div class="no-results"><i class="bi bi-info-circle"></i><br>No open loans found in the system!<br>Please create a loan first.</div>';
                                searchStats.textContent = 'No open loans available';
                                resultCount.textContent = '';
                                return;
                            }

                            const results = data.results || [];
                            
                            if (results.length === 0) {
                                searchResults.innerHTML = `<div class="no-results"><i class="bi bi-search"></i><br>No open loans found matching "${term}"<br>Try a different search term</div>`;
                                searchStats.textContent = `No results for "${term}"`;
                                resultCount.textContent = '';
                            } else {
                                let html = '';
                                results.forEach(loan => {
                                    const matchClass = loan.match_type === 'exact' ? 'exact' : '';
                                    const matchText = loan.match_type === 'exact' ? 'Exact Match' : 
                                                    loan.match_type === 'receipt_start' ? 'Receipt Match' :
                                                    loan.match_type === 'name_start' ? 'Name Match' : 'Partial Match';
                                    
                                    html += `
                                        <div class="search-result-item" onclick="selectLoan(${loan.id})">
                                            <div class="match-badge ${matchClass}">${matchText}</div>
                                            <div class="result-receipt">${escapeHtml(loan.receipt_number)}</div>
                                            <div class="result-customer">${escapeHtml(loan.customer_name)}</div>
                                            <div class="result-details">
                                                <span class="result-mobile">📞 ${escapeHtml(loan.mobile)}</span>
                                                <span class="result-balance">💰 Principal: ₹${Number(loan.remaining_principal).toLocaleString()}</span>
                                                <span class="result-due">📈 Interest: ₹${Number(loan.pending_interest).toLocaleString()}</span>
                                                ${loan.overdue_amount > 0 ? `<span class="result-overdue">⚠️ Overdue: ₹${loan.overdue_amount.toLocaleString()}</span>` : ''}
                                            </div>
                                        </div>
                                    `;
                                });
                                searchResults.innerHTML = html;
                                searchStats.textContent = `Found ${results.length} result${results.length > 1 ? 's' : ''} for "${term}"`;
                                resultCount.textContent = `${results.length} results`;
                            }
                        })
                        .catch(error => {
                            console.error('Search error:', error);
                            searchResults.innerHTML = '<div class="no-results"><i class="bi bi-exclamation-triangle"></i><br>Error searching. Please try again.</div>';
                            searchStats.textContent = 'Error occurred';
                            resultCount.textContent = '';
                        });
                }, 300);
            });
        }

        function clearSearch() {
            if (searchInput) {
                searchInput.value = '';
                clearBtn.style.display = 'none';
                searchResults.classList.remove('show');
                searchStats.textContent = 'Type at least 2 characters to search';
                resultCount.textContent = '';
            }
        }

        function selectLoan(loanId) {
            window.location.href = `close-loan.php?id=${loanId}`;
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            const searchContainer = document.querySelector('.live-search-container');
            if (searchContainer && !searchContainer.contains(event.target)) {
                if (searchResults) searchResults.classList.remove('show');
            }
        });

        // Advance Modal Functions
        function showAdvanceModal() {
            document.getElementById('advanceModal').classList.add('active');
        }

        function hideAdvanceModal() {
            document.getElementById('advanceModal').classList.remove('active');
        }

        // Close Modal Functions
        function showCloseModal() {
            document.getElementById('closeModal').classList.add('active');
            updateTotalPayable();
        }

        function hideCloseModal() {
            document.getElementById('closeModal').classList.remove('active');
        }

        function updateTotalPayable() {
            const finalPrincipal = parseFloat(document.getElementById('final_principal')?.value) || 0;
            const finalInterest = parseFloat(document.getElementById('final_interest')?.value) || 0;
            const receiptCharge = parseFloat(document.getElementById('receipt_charge')?.value) || 0;
            const discount = parseFloat(document.getElementById('discount')?.value) || 0;
            const roundOff = parseFloat(document.getElementById('round_off')?.value) || 0;
            
            const total = finalPrincipal + finalInterest + receiptCharge - discount + roundOff;
            const displayElement = document.getElementById('total_payable_display');
            if (displayElement) {
                displayElement.innerHTML = '₹ ' + total.toFixed(2);
            }
        }

        function confirmClose() {
            const totalPayable = document.getElementById('total_payable_display')?.innerText || '₹ 0';
            const collectionType = document.getElementById('collection_type').value;
            
            if (collectionType === 'other') {
                const otherPersonName = document.getElementById('other_person_name')?.value;
                if (!otherPersonName) {
                    alert('Please enter the name of the person collecting the jewelry');
                    return false;
                }
            }
            
            return confirm(`Are you sure you want to close this loan?\n\nTotal Payable: ${totalPayable}\n\nThis action cannot be undone!`);
        }

        // Collection Type Functions
        function setCollectionType(type) {
            document.getElementById('collection_type').value = type;
            
            const customerBtn = document.querySelectorAll('.collection-type-btn')[0];
            const otherBtn = document.querySelectorAll('.collection-type-btn')[1];
            const otherFields = document.getElementById('otherPersonFields');
            const cameraButtonDiv = document.getElementById('cameraButtonDiv');
            
            if (type === 'customer') {
                customerBtn.classList.add('active');
                otherBtn.classList.remove('active');
                otherFields.classList.remove('show');
                if (cameraButtonDiv) cameraButtonDiv.style.display = 'none';
            } else {
                customerBtn.classList.remove('active');
                otherBtn.classList.add('active');
                otherFields.classList.add('show');
                if (cameraButtonDiv) cameraButtonDiv.style.display = 'block';
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const advanceModal = document.getElementById('advanceModal');
            const closeModal = document.getElementById('closeModal');
            const cameraModal = document.getElementById('cameraModal');
            
            if (event.target == advanceModal) hideAdvanceModal();
            if (event.target == closeModal) hideCloseModal();
            if (event.target == cameraModal) closeCameraModal();
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>