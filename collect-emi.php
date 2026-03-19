<?php
session_start();
$currentPage = 'collect-emi';
$pageTitle = 'Collect EMI';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$emi_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

// If loan_id is provided, show all unpaid EMIs for that loan
if ($loan_id > 0 && $emi_id == 0) {
    header('Location: emi-schedule.php?loan_id=' . $loan_id);
    exit();
}

if ($emi_id <= 0) {
    header('Location: personal-loans.php');
    exit();
}

// Get EMI details
$query = "SELECT e.*, pl.receipt_number, pl.loan_amount, pl.interest_rate,
                 pl.tenure_months, pl.total_payable,
                 c.customer_name, c.mobile_number, c.email,
                 c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode
          FROM emi_schedules e
          JOIN personal_loans pl ON e.loan_id = pl.id
          JOIN customers c ON pl.customer_id = c.id
          WHERE e.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $emi_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$emi = mysqli_fetch_assoc($result);

if (!$emi) {
    header('Location: personal-loans.php');
    exit();
}

// Generate receipt number
$receipt_query = "SELECT COUNT(*) as count FROM emi_collections WHERE DATE(created_at) = CURDATE()";
$receipt_result = mysqli_query($conn, $receipt_query);
$receipt_count = 1;
if ($receipt_result && mysqli_num_rows($receipt_result) > 0) {
    $receipt_data = mysqli_fetch_assoc($receipt_result);
    $receipt_count = $receipt_data['count'] + 1;
}
$receipt_number = 'EMI' . date('ymd') . str_pad($receipt_count, 3, '0', STR_PAD_LEFT);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['collect_emi'])) {
    $paid_amount = floatval($_POST['paid_amount'] ?? $emi['total_amount']);
    $penalty = floatval($_POST['penalty'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $transaction_id = mysqli_real_escape_string($conn, $_POST['transaction_id'] ?? '');
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    
    $net_amount = $paid_amount - $discount;
    $total_due = $emi['total_amount'] + $penalty;
    
    $errors = [];
    if ($paid_amount <= 0) $errors[] = "Paid amount must be greater than 0";
    if ($paid_amount > $total_due) $errors[] = "Paid amount cannot exceed total due";
    
    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        
        try {
            // Insert collection record
            $insert_query = "INSERT INTO emi_collections (
                emi_schedule_id, loan_id, customer_id, collection_date, due_date,
                principal_amount, interest_amount, total_amount, paid_amount,
                penalty_amount, discount_amount, net_amount, payment_method,
                transaction_id, receipt_number, collected_by, remarks, status
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid')";
            
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, 'iiisdddddddsssis', 
                $emi['id'], $emi['loan_id'], $emi['customer_id'],
                $emi['due_date'],
                $emi['principal_amount'], $emi['interest_amount'],
                $emi['total_amount'], $paid_amount,
                $penalty, $discount, $net_amount,
                $payment_method, $transaction_id, $receipt_number,
                $_SESSION['user_id'], $remarks
            );
            mysqli_stmt_execute($stmt);
            
            // Update EMI schedule
            $update_query = "UPDATE emi_schedules 
                            SET status = 'paid', 
                                paid_amount = ?,
                                paid_date = NOW()
                            WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, 'di', $paid_amount, $emi['id']);
            mysqli_stmt_execute($update_stmt);
            
            mysqli_commit($conn);
            
            $_SESSION['success'] = "EMI collected successfully! Receipt #: " . $receipt_number;
            header('Location: emi-schedule.php?loan_id=' . $emi['loan_id']);
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Collect EMI</title>
    <!-- Add your CSS here -->
</head>
<body>
    <!-- Your collection form HTML -->
    <h1>Collect EMI #<?php echo $emi['installment_no']; ?></h1>
    <!-- ... rest of your form ... -->
</body>
</html>