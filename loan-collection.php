<?php
session_start();
$currentPage = 'loan-collection';
$pageTitle = 'Loan Collection';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission (admin or sale)
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$loan = null;
$customer = null;
$items = null;
$payments = null;
$overdue_details = null;
$current_interest_due = 0;
$total_principal_paid = 0;
$total_interest_paid = 0;
$remaining_principal = 0;
$pending_interest = 0;
$interest_overdue = false;

// First, check if the new columns exist
$check_columns_query = "SHOW COLUMNS FROM loans LIKE 'remaining_principal'";
$check_columns_result = mysqli_query($conn, $check_columns_query);
$has_new_columns = (mysqli_num_rows($check_columns_result) > 0);

// Get all open loans for dropdown - with conditional column selection
if ($has_new_columns) {
    $open_loans_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                         COALESCE(l.remaining_principal, l.loan_amount) as remaining_principal,
                         COALESCE(l.pending_interest, 0) as pending_interest,
                         l.receipt_date, l.interest_amount,
                         l.net_weight, l.product_value, l.last_overdue_calculated, l.total_overdue_amount,
                         l.last_interest_paid_date,
                         c.id as customer_id, c.customer_name, c.mobile_number,
                         DATEDIFF(NOW(), l.receipt_date) as days_old,
                         (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count,
                         (SELECT MAX(payment_date) FROM payments WHERE loan_id = l.id) as last_payment_date
                         FROM loans l
                         JOIN customers c ON l.customer_id = c.id
                         WHERE l.status = 'open'
                         ORDER BY l.receipt_date DESC";
} else {
    // Fallback query if new columns don't exist
    $open_loans_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                         l.loan_amount as remaining_principal,
                         0 as pending_interest,
                         l.receipt_date, l.interest_amount,
                         l.net_weight, l.product_value, l.last_overdue_calculated, l.total_overdue_amount,
                         l.last_interest_paid_date,
                         c.id as customer_id, c.customer_name, c.mobile_number,
                         DATEDIFF(NOW(), l.receipt_date) as days_old,
                         (SELECT COUNT(*) FROM payments WHERE loan_id = l.id) as payment_count,
                         (SELECT MAX(payment_date) FROM payments WHERE loan_id = l.id) as last_payment_date
                         FROM loans l
                         JOIN customers c ON l.customer_id = c.id
                         WHERE l.status = 'open'
                         ORDER BY l.receipt_date DESC";
}
$open_loans_result = mysqli_query($conn, $open_loans_query);

// Get all active employees for collection assignment
$employees_query = "SELECT id, name, role FROM users WHERE status = 'active' AND (role = 'admin' OR role = 'sale' OR role = 'manager' OR role = 'accountant') ORDER BY name";
$employees_result = mysqli_query($conn, $employees_query);

// Get all bank accounts for payment
$bank_accounts_query = "SELECT ba.*, bm.bank_full_name 
                        FROM bank_accounts ba 
                        JOIN bank_master bm ON ba.bank_id = bm.id 
                        WHERE ba.status = 1 
                        ORDER BY bm.bank_full_name, ba.account_holder_no";
$bank_accounts_result = mysqli_query($conn, $bank_accounts_query);

// Function to calculate due dates and overdue amounts based on remaining principal
function calculateOverdueDetails($loan_id, $conn) {
    // Get loan details with remaining principal
    $query = "SELECT l.*, 
              (SELECT SUM(interest_amount) FROM payments WHERE loan_id = l.id) as total_interest_paid,
              (SELECT SUM(principal_amount) FROM payments WHERE loan_id = l.id) as total_principal_paid,
              (SELECT MAX(payment_date) FROM payments WHERE loan_id = l.id AND interest_amount > 0) as last_interest_payment
              FROM loans l WHERE l.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        error_log("Prepare failed: " . mysqli_error($conn));
        return null;
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan) return null;
    
    $today = new DateTime();
    $loan_start = new DateTime($loan['receipt_date']);
    
    // Calculate remaining principal from payments if column doesn't exist
    $check_column_query = "SHOW COLUMNS FROM loans LIKE 'remaining_principal'";
    $check_column_result = mysqli_query($conn, $check_column_query);
    $has_remaining_column = (mysqli_num_rows($check_column_result) > 0);
    
    if ($has_remaining_column && isset($loan['remaining_principal']) && $loan['remaining_principal'] > 0) {
        $remaining_principal = $loan['remaining_principal'];
    } else {
        // Calculate remaining principal from payments
        $total_principal_paid = $loan['total_principal_paid'] ?? 0;
        $remaining_principal = $loan['loan_amount'] - $total_principal_paid;
    }
    
    $regular_rate = floatval($loan['interest_amount']);
    
    // Calculate monthly and daily interest on remaining principal
    $monthly_interest = ($remaining_principal * $regular_rate) / 100;
    $daily_interest = $monthly_interest / 30;
    
    $overdue_details = [];
    $total_overdue = 0;
    
    // Determine last paid date or loan start
    if (!empty($loan['last_interest_payment'])) {
        $last_paid = new DateTime($loan['last_interest_payment']);
    } else {
        $last_paid = clone $loan_start;
    }
    
    // Calculate all due dates from last paid to today
    $current_due = clone $last_paid;
    $current_due->modify('+1 month'); // First due date after last payment
    
    $overdue_months = 0;
    while ($current_due <= $today) {
        $due_date = clone $current_due;
        $days_overdue = $today->diff($due_date)->days;
        
        // Calculate overdue amount for this period based on remaining principal
        $period_overdue = ($remaining_principal * $regular_rate) / 100;
        
        $overdue_details[] = [
            'due_date' => $due_date->format('Y-m-d'),
            'due_date_formatted' => $due_date->format('d-m-Y'),
            'days_overdue' => $days_overdue,
            'regular_rate' => $regular_rate,
            'overdue_amount' => round($period_overdue, 2),
            'is_current' => ($overdue_months == 0)
        ];
        
        $total_overdue += $period_overdue;
        $overdue_months++;
        
        // Move to next month
        $current_due->modify('+1 month');
    }
    
    // Calculate next due date
    $next_due = clone $last_paid;
    $next_due->modify('+1 month');
    if ($next_due <= $today) {
        // Find the next future due date
        while ($next_due <= $today) {
            $next_due->modify('+1 month');
        }
    }
    
    return [
        'remaining_principal' => $remaining_principal,
        'regular_rate' => $regular_rate,
        'monthly_interest' => round($monthly_interest, 2),
        'daily_interest' => round($daily_interest, 2),
        'total_overdue' => round($total_overdue, 2),
        'overdue_details' => $overdue_details,
        'next_due_date' => $next_due->format('Y-m-d'),
        'next_due_formatted' => $next_due->format('d-m-Y'),
        'last_paid_date' => !empty($loan['last_interest_payment']) ? date('d-m-Y', strtotime($loan['last_interest_payment'])) : 'No payments',
        'overdue_months' => $overdue_months,
        'has_overdue' => $total_overdue > 0
    ];
}

// Function to recalculate loan balances - FIXED VERSION
function recalculateLoanBalances($loan_id, $conn) {
    // Get loan details
    $query = "SELECT * FROM loans WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    
    if (!$loan) return false;
    
    // Get all payments
    $payments_query = "SELECT * FROM payments WHERE loan_id = ? ORDER BY payment_date, id";
    $stmt = mysqli_prepare($conn, $payments_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $payments_result = mysqli_stmt_get_result($stmt);
    
    $total_principal_paid = 0;
    $total_interest_paid = 0;
    $last_payment_date = null;
    
    while ($payment = mysqli_fetch_assoc($payments_result)) {
        $total_principal_paid += $payment['principal_amount'];
        $total_interest_paid += $payment['interest_amount'];
        $last_payment_date = $payment['payment_date'];
    }
    
    $remaining_principal = $loan['loan_amount'] - $total_principal_paid;
    if ($remaining_principal < 0) $remaining_principal = 0;
    
    // Calculate interest accrued up to today
    $today = new DateTime();
    $loan_start = new DateTime($loan['receipt_date']);
    
    // Determine last calculation date - use last payment date if available
    $last_calc_date = $loan_start;
    if (!empty($loan['last_interest_paid_date'])) {
        $last_calc_date = new DateTime($loan['last_interest_paid_date']);
    } elseif (!empty($last_payment_date)) {
        $last_calc_date = new DateTime($last_payment_date);
    }
    
    // Calculate days since last calculation
    $interval = $last_calc_date->diff($today);
    $days_since_calc = ($interval->y * 365) + ($interval->m * 30) + $interval->d;
    if ($days_since_calc < 0) $days_since_calc = 0;
    
    // Calculate monthly interest on remaining principal
    $monthly_interest = ($remaining_principal * $loan['interest_amount']) / 100;
    $daily_interest = $monthly_interest / 30;
    
    // New interest accrued
    $new_interest = $days_since_calc * $daily_interest;
    
    // Check if new columns exist
    $check_columns_query = "SHOW COLUMNS FROM loans LIKE 'total_interest_accrued'";
    $check_columns_result = mysqli_query($conn, $check_columns_query);
    $has_new_columns = (mysqli_num_rows($check_columns_result) > 0);
    
    if ($has_new_columns) {
        $total_interest_accrued = ($loan['total_interest_accrued'] ?? 0) + $new_interest;
        $pending_interest = $total_interest_accrued - $total_interest_paid;
        if ($pending_interest < 0) $pending_interest = 0;
        
        // Update loan
        $update_query = "UPDATE loans SET 
                         remaining_principal = ?,
                         total_interest_accrued = ?,
                         pending_interest = ?,
                         last_interest_paid_date = ?
                         WHERE id = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        $today_date = $today->format('Y-m-d');
        mysqli_stmt_bind_param($update_stmt, 'dddsi', $remaining_principal, $total_interest_accrued, $pending_interest, $today_date, $loan_id);
        mysqli_stmt_execute($update_stmt);
    } else {
        $total_interest_accrued = $new_interest;
        $pending_interest = $total_interest_accrued - $total_interest_paid;
        if ($pending_interest < 0) $pending_interest = 0;
    }
    
    return [
        'remaining_principal' => $remaining_principal,
        'total_interest_accrued' => $total_interest_accrued,
        'pending_interest' => $pending_interest,
        'total_principal_paid' => $total_principal_paid,
        'total_interest_paid' => $total_interest_paid
    ];
}

// Handle AJAX search request for live search
if (isset($_GET['ajax_search']) && isset($_GET['term'])) {
    header('Content-Type: application/json');
    $search_term = mysqli_real_escape_string($conn, $_GET['term']);
    
    // Prepare search terms for different matching strategies
    $search_term_like = '%' . $search_term . '%';
    $search_term_start = $search_term . '%';
    
    // Check if new columns exist
    $check_columns_query = "SHOW COLUMNS FROM loans LIKE 'remaining_principal'";
    $check_columns_result = mysqli_query($conn, $check_columns_query);
    $has_new_columns = (mysqli_num_rows($check_columns_result) > 0);
    
    if ($has_new_columns) {
        $ajax_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                       COALESCE(l.remaining_principal, l.loan_amount) as remaining_principal,
                       COALESCE(l.pending_interest, 0) as pending_interest,
                       l.interest_amount,
                       l.receipt_date, l.total_overdue_amount,
                       c.customer_name, c.mobile_number
                       FROM loans l
                       JOIN customers c ON l.customer_id = c.id
                       WHERE l.status = 'open' 
                       AND (
                           l.receipt_number LIKE ? OR 
                           c.customer_name LIKE ? OR 
                           c.mobile_number LIKE ? OR
                           c.customer_name LIKE ? OR
                           l.receipt_number LIKE ?
                       )
                       ORDER BY 
                           CASE 
                               WHEN l.receipt_number = ? THEN 1
                               WHEN c.customer_name LIKE ? THEN 2
                               WHEN l.receipt_number LIKE ? THEN 3
                               WHEN c.mobile_number LIKE ? THEN 4
                               ELSE 5
                           END,
                           l.receipt_date DESC
                       LIMIT 50";
    } else {
        $ajax_query = "SELECT l.id, l.receipt_number, l.loan_amount, 
                       l.loan_amount as remaining_principal,
                       0 as pending_interest,
                       l.interest_amount,
                       l.receipt_date, l.total_overdue_amount,
                       c.customer_name, c.mobile_number
                       FROM loans l
                       JOIN customers c ON l.customer_id = c.id
                       WHERE l.status = 'open' 
                       AND (
                           l.receipt_number LIKE ? OR 
                           c.customer_name LIKE ? OR 
                           c.mobile_number LIKE ? OR
                           c.customer_name LIKE ? OR
                           l.receipt_number LIKE ?
                       )
                       ORDER BY 
                           CASE 
                               WHEN l.receipt_number = ? THEN 1
                               WHEN c.customer_name LIKE ? THEN 2
                               WHEN l.receipt_number LIKE ? THEN 3
                               WHEN c.mobile_number LIKE ? THEN 4
                               ELSE 5
                           END,
                           l.receipt_date DESC
                       LIMIT 50";
    }
    
    $stmt = mysqli_prepare($conn, $ajax_query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssssssss', 
            $search_term_like, $search_term_like, $search_term_like,
            $search_term_start, $search_term_start,
            $search_term, $search_term_start, $search_term_start, $search_term_like
        );
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $loans = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // Determine match type for highlighting
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
                'loan_amount' => $row['loan_amount'],
                'remaining_principal' => $row['remaining_principal'],
                'pending_interest' => $row['pending_interest'],
                'interest_rate' => $row['interest_amount'],
                'overdue_amount' => round($row['total_overdue_amount'] ?? 0, 2),
                'match_type' => $match_type
            ];
        }
        
        echo json_encode([
            'results' => $loans,
            'count' => count($loans),
            'search_term' => $search_term
        ]);
    } else {
        echo json_encode(['error' => 'Database error', 'results' => []]);
    }
    exit();
}

// Handle receipt search
if (isset($_GET['receipt']) || isset($_POST['receipt_number']) || isset($_POST['select_receipt']) || isset($_GET['search'])) {
    
    // Check which method was used
    if (isset($_POST['select_receipt']) && !empty($_POST['select_receipt'])) {
        $receipt_number = mysqli_real_escape_string($conn, $_POST['select_receipt']);
    } elseif (isset($_POST['receipt_number']) && !empty($_POST['receipt_number'])) {
        $receipt_number = mysqli_real_escape_string($conn, $_POST['receipt_number']);
    } elseif (isset($_GET['receipt']) && !empty($_GET['receipt'])) {
        $receipt_number = mysqli_real_escape_string($conn, $_GET['receipt']);
    } elseif (isset($_GET['search']) && !empty($_GET['search'])) {
        $receipt_number = mysqli_real_escape_string($conn, $_GET['search']);
    } else {
        $receipt_number = '';
    }
    
    if (!empty($receipt_number)) {
        // First try exact match by receipt number
        $loan_query = "SELECT l.*, 
                       c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
                       c.mobile_number, c.whatsapp_number, c.alternate_mobile,
                       c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
                       c.location, c.district, c.pincode, c.post, c.taluk,
                       c.aadhaar_number, c.customer_photo,
                       u.name as employee_name
                       FROM loans l 
                       JOIN customers c ON l.customer_id = c.id 
                       JOIN users u ON l.employee_id = u.id 
                       WHERE l.receipt_number = ? AND l.status = 'open'";

        $stmt = mysqli_prepare($conn, $loan_query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $receipt_number);
            mysqli_stmt_execute($stmt);
            $loan_result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($loan_result) > 0) {
                $loan = mysqli_fetch_assoc($loan_result);
            } else {
                // If not found by receipt number, try by customer name or mobile
                $search_term = '%' . $receipt_number . '%';
                $loan_query = "SELECT l.*, 
                               c.id as customer_id, c.customer_name, c.guardian_type, c.guardian_name, 
                               c.mobile_number, c.whatsapp_number, c.alternate_mobile,
                               c.door_no, c.house_name, c.street_name, c.street_name1, c.landmark,
                               c.location, c.district, c.pincode, c.post, c.taluk,
                               c.aadhaar_number, c.customer_photo,
                               u.name as employee_name
                               FROM loans l 
                               JOIN customers c ON l.customer_id = c.id 
                               JOIN users u ON l.employee_id = u.id 
                               WHERE (c.customer_name LIKE ? OR c.mobile_number LIKE ?) 
                               AND l.status = 'open'
                               ORDER BY l.receipt_date DESC
                               LIMIT 1";
                
                $stmt = mysqli_prepare($conn, $loan_query);
                mysqli_stmt_bind_param($stmt, 'ss', $search_term, $search_term);
                mysqli_stmt_execute($stmt);
                $loan_result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($loan_result) > 0) {
                    $loan = mysqli_fetch_assoc($loan_result);
                } else {
                    $error = "No open loan found matching: " . htmlspecialchars($receipt_number);
                }
            }
            
            if (isset($loan) && $loan) {
                // Recalculate loan balances
                $balances = recalculateLoanBalances($loan['id'], $conn);
                
                $remaining_principal = $balances['remaining_principal'];
                $pending_interest = $balances['pending_interest'];
                $total_principal_paid = $balances['total_principal_paid'];
                $total_interest_paid = $balances['total_interest_paid'];
                
                // Calculate overdue details based on remaining principal
                $overdue_details = calculateOverdueDetails($loan['id'], $conn);
                
                // Get loan items
                $items_query = "SELECT * FROM loan_items WHERE loan_id = ?";
                $stmt = mysqli_prepare($conn, $items_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $loan['id']);
                    mysqli_stmt_execute($stmt);
                    $items_result = mysqli_stmt_get_result($stmt);
                    $items = [];
                    while ($item = mysqli_fetch_assoc($items_result)) {
                        $items[] = $item;
                    }
                }
                
                // Get all payments with new columns
                $payments_query = "SELECT p.*, u.name as employee_name 
                                  FROM payments p 
                                  JOIN users u ON p.employee_id = u.id 
                                  WHERE p.loan_id = ? 
                                  ORDER BY p.payment_date DESC";
                $stmt = mysqli_prepare($conn, $payments_query);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $loan['id']);
                    mysqli_stmt_execute($stmt);
                    $payments_result = mysqli_stmt_get_result($stmt);
                    $payments = [];
                    while ($payment = mysqli_fetch_assoc($payments_result)) {
                        $payments[] = $payment;
                    }
                }
                
                // Get customer details
                $customer = [
                    'id' => $loan['customer_id'],
                    'name' => $loan['customer_name'],
                    'guardian' => $loan['guardian_name'],
                    'guardian_type' => $loan['guardian_type'],
                    'mobile' => $loan['mobile_number'],
                    'whatsapp' => $loan['whatsapp_number'],
                    'alternate' => $loan['alternate_mobile'],
                    'address' => trim($loan['door_no'] . ' ' . $loan['street_name'] . ', ' . $loan['location'] . ', ' . $loan['district'] . ' - ' . $loan['pincode']),
                    'photo' => $loan['customer_photo']
                ];
                
                // ========== FIXED INTEREST CALCULATION ==========
                // Calculate current interest due based on remaining principal
                $loan_start = new DateTime($loan['receipt_date']);
                $today = new DateTime();
                
                // First, get the actual last payment date from payments table
                $last_payment_query = "SELECT MAX(payment_date) as last_payment_date FROM payments 
                                       WHERE loan_id = ? AND (interest_amount > 0 OR principal_amount > 0)";
                $stmt = mysqli_prepare($conn, $last_payment_query);
                mysqli_stmt_bind_param($stmt, 'i', $loan['id']);
                mysqli_stmt_execute($stmt);
                $last_payment_result = mysqli_stmt_get_result($stmt);
                $last_payment_row = mysqli_fetch_assoc($last_payment_result);
                
                $last_payment_date = null;
                if ($last_payment_row && $last_payment_row['last_payment_date']) {
                    $last_payment_date = new DateTime($last_payment_row['last_payment_date']);
                }
                
                // Get last interest paid date from loan table
                $last_interest_date = null;
                if (!empty($loan['last_interest_paid_date'])) {
                    $last_interest_date = new DateTime($loan['last_interest_paid_date']);
                }
                
                // Use the most recent date for calculation
                $calculation_start_date = $loan_start;
                if ($last_payment_date && $last_payment_date > $calculation_start_date) {
                    $calculation_start_date = $last_payment_date;
                }
                if ($last_interest_date && $last_interest_date > $calculation_start_date) {
                    $calculation_start_date = $last_interest_date;
                }
                
                // Calculate days since last payment
                $interval = $calculation_start_date->diff($today);
                $days_since_last_payment = ($interval->y * 365) + ($interval->m * 30) + $interval->d;
                
                // Calculate daily interest on remaining principal
                $monthly_interest = ($remaining_principal * $loan['interest_amount']) / 100;
                $daily_interest = $monthly_interest / 30;
                
                // Calculate current interest due
                $current_interest_due = $days_since_last_payment * $daily_interest;
                
                // Round to 2 decimal places
                $current_interest_due = round($current_interest_due, 2);
                
                // If current_interest_due is 0 but pending_interest > 0, use pending_interest
                if ($current_interest_due == 0 && $pending_interest > 0) {
                    $current_interest_due = $pending_interest;
                }
                
                $interest_overdue = ($current_interest_due > 0.01);
                // ========== END FIXED INTEREST CALCULATION ==========
            }
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}

// Handle interest payment submission (with balance tracking)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_interest') {
    $loan_id = intval($_POST['loan_id']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
    $interest_amount = floatval($_POST['interest_amount']);
    $overdue_amount = floatval($_POST['overdue_amount'] ?? 0);
    $overdue_charge = floatval($_POST['overdue_charge'] ?? 0);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
    $collection_employee_id = intval($_POST['collection_employee_id']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Interest Collection');
    $receipt_number = mysqli_real_escape_string($conn, $_POST['receipt_number']);
    
    // Get current loan balances
    $loan_query = "SELECT loan_amount FROM loans WHERE id = ?";
    $stmt = mysqli_prepare($conn, $loan_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $loan_result = mysqli_stmt_get_result($stmt);
    $loan_data = mysqli_fetch_assoc($loan_result);
    
    // Calculate remaining principal from payments
    $payments_query = "SELECT SUM(principal_amount) as total_principal_paid, 
                              SUM(interest_amount) as total_interest_paid
                       FROM payments WHERE loan_id = ?";
    $stmt = mysqli_prepare($conn, $payments_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $payments_result = mysqli_stmt_get_result($stmt);
    $payments_total = mysqli_fetch_assoc($payments_result);
    
    $remaining_principal_before = $loan_data['loan_amount'] - ($payments_total['total_principal_paid'] ?? 0);
    $pending_interest_before = $payments_total['total_interest_paid'] ?? 0;
    
    // Determine if payment includes overdue amounts
    $includes_overdue = ($overdue_amount > 0 || $overdue_charge > 0) ? 1 : 0;
    
    // Validate
    $errors = [];
    if ($interest_amount <= 0 && $overdue_amount <= 0 && $overdue_charge <= 0) {
        $errors[] = "Amount must be greater than 0";
    }
    if ($collection_employee_id <= 0) $errors[] = "Please select the collecting employee";
    if ($payment_mode === 'bank' && $bank_account_id <= 0) {
        $errors[] = "Please select a bank account for bank transfer";
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate payment receipt number based on type
            $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
            $receipt_result = mysqli_query($conn, $receipt_query);
            $receipt_row = mysqli_fetch_assoc($receipt_result);
            $receipt_count = $receipt_row['count'] + 1;
            
            // Determine receipt prefix
            if ($overdue_charge > 0) {
                $prefix = 'CHG'; // Overdue Charge
            } elseif ($overdue_amount > 0 && $interest_amount > 0) {
                $prefix = 'INT'; // Interest + Overdue
            } elseif ($overdue_amount > 0) {
                $prefix = 'OVD'; // Overdue Only
            } else {
                $prefix = 'INT'; // Interest Only
            }
            
            $payment_receipt = $prefix . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
            
            $total_amount = $interest_amount + $overdue_amount + $overdue_charge;
            
            // Calculate remaining balances after payment
            $remaining_interest_after = $pending_interest_before - $interest_amount;
            if ($remaining_interest_after < 0) $remaining_interest_after = 0;
            
            // Insert interest payment
            $insert_payment = "INSERT INTO payments (
                loan_id, receipt_number, payment_date, principal_amount, 
                interest_amount, total_amount, payment_mode, employee_id, remarks,
                includes_overdue, overdue_amount_paid, overdue_charge
            ) VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $insert_payment);
            mysqli_stmt_bind_param($stmt, 'issdddissdd', 
                $loan_id, $payment_receipt, $payment_date, 
                $interest_amount, $total_amount,
                $payment_mode, $collection_employee_id, $remarks,
                $includes_overdue, $overdue_amount, $overdue_charge
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error inserting payment: " . mysqli_stmt_error($stmt));
            }
            
            $payment_id = mysqli_insert_id($conn);
            
            // Update loan last interest paid date
            $update_loan = "UPDATE loans SET last_interest_paid_date = ? WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_loan);
            mysqli_stmt_bind_param($update_stmt, 'si', $payment_date, $loan_id);
            mysqli_stmt_execute($update_stmt);
            
            // If payment mode is bank, update bank ledger
            if ($payment_mode === 'bank' && $bank_account_id > 0) {
                // Get bank details
                $bank_query = "SELECT bank_id FROM bank_accounts WHERE id = ?";
                $bank_stmt = mysqli_prepare($conn, $bank_query);
                mysqli_stmt_bind_param($bank_stmt, 'i', $bank_account_id);
                mysqli_stmt_execute($bank_stmt);
                $bank_result = mysqli_stmt_get_result($bank_stmt);
                $bank_data = mysqli_fetch_assoc($bank_result);
                $bank_id = $bank_data['bank_id'];
                
                // Get current balance
                $balance_query = "SELECT balance FROM bank_ledger 
                                 WHERE bank_account_id = ? 
                                 ORDER BY id DESC LIMIT 1";
                $balance_stmt = mysqli_prepare($conn, $balance_query);
                mysqli_stmt_bind_param($balance_stmt, 'i', $bank_account_id);
                mysqli_stmt_execute($balance_stmt);
                $balance_result = mysqli_stmt_get_result($balance_stmt);
                
                if (mysqli_num_rows($balance_result) > 0) {
                    $balance_row = mysqli_fetch_assoc($balance_result);
                    $last_balance = $balance_row['balance'];
                } else {
                    // Get opening balance
                    $opening_query = "SELECT opening_balance FROM bank_accounts WHERE id = ?";
                    $opening_stmt = mysqli_prepare($conn, $opening_query);
                    mysqli_stmt_bind_param($opening_stmt, 'i', $bank_account_id);
                    mysqli_stmt_execute($opening_stmt);
                    $opening_result = mysqli_stmt_get_result($opening_stmt);
                    $opening_data = mysqli_fetch_assoc($opening_result);
                    $last_balance = $opening_data['opening_balance'];
                }
                
                $new_balance = $last_balance + $total_amount;
                
                // Insert into bank ledger
                $ledger_query = "INSERT INTO bank_ledger (
                    entry_date, bank_id, bank_account_id, transaction_type, 
                    amount, balance, reference_number, description, payment_id, loan_id, created_by
                ) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?, ?)";
                
                $description = "Interest collection - Receipt #: " . $payment_receipt;
                $ledger_stmt = mysqli_prepare($conn, $ledger_query);
                mysqli_stmt_bind_param($ledger_stmt, 'siidsssiii', 
                    $payment_date, $bank_id, $bank_account_id,
                    $total_amount, $new_balance, $payment_receipt, $description,
                    $payment_id, $loan_id, $_SESSION['user_id']
                );
                
                if (!mysqli_stmt_execute($ledger_stmt)) {
                    throw new Exception("Error updating bank ledger: " . mysqli_stmt_error($ledger_stmt));
                }
            }
            
            // Get employee name for log
            $emp_name_query = "SELECT name FROM users WHERE id = ?";
            $emp_stmt = mysqli_prepare($conn, $emp_name_query);
            mysqli_stmt_bind_param($emp_stmt, 'i', $collection_employee_id);
            mysqli_stmt_execute($emp_stmt);
            $emp_result = mysqli_stmt_get_result($emp_stmt);
            $emp_data = mysqli_fetch_assoc($emp_result);
            $emp_name = $emp_data['name'];
            
            // Log activity with detailed description
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'interest_collection', ?, 'payments', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            
            $log_description = "Payment of ₹" . number_format($total_amount, 2);
            $details = [];
            if ($interest_amount > 0) $details[] = "Interest: ₹" . number_format($interest_amount, 2);
            if ($overdue_amount > 0) $details[] = "Overdue: ₹" . number_format($overdue_amount, 2);
            if ($overdue_charge > 0) $details[] = "Charge: ₹" . number_format($overdue_charge, 2);
            
            $log_description .= " (" . implode(" + ", $details) . ") collected by " . $emp_name . " for Loan #" . $receipt_number;
            
            if ($payment_mode === 'bank') {
                $log_description .= " via Bank Transfer";
            }
            
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            // Redirect to bill receipt page
            header('Location: bill-receipt.php?receipt=' . urlencode($payment_receipt) . '&success=payment_received');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error collecting payment: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle principal payment submission (with balance tracking) - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_principal') {
    $loan_id = intval($_POST['loan_id']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);
    $principal_amount = floatval($_POST['principal_amount']);
    $payment_mode = mysqli_real_escape_string($conn, $_POST['payment_mode']);
    $bank_account_id = isset($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : 0;
    $collection_employee_id = intval($_POST['collection_employee_id']);
    $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? 'Principal Payment');
    $receipt_number = mysqli_real_escape_string($conn, $_POST['receipt_number']);
    $current_interest_due = floatval($_POST['current_interest_due']);
    
    // Get current loan balances
    $loan_query = "SELECT loan_amount FROM loans WHERE id = ?";
    $stmt = mysqli_prepare($conn, $loan_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $loan_result = mysqli_stmt_get_result($stmt);
    $loan_data = mysqli_fetch_assoc($loan_result);
    
    // Calculate remaining principal from payments
    $payments_query = "SELECT SUM(principal_amount) as total_principal_paid 
                       FROM payments WHERE loan_id = ?";
    $stmt = mysqli_prepare($conn, $payments_query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $payments_result = mysqli_stmt_get_result($stmt);
    $payments_total = mysqli_fetch_assoc($payments_result);
    
    $remaining_principal_before = $loan_data['loan_amount'] - ($payments_total['total_principal_paid'] ?? 0);
    
    // Validate
    $errors = [];
    
    // Check if interest is overdue - CANNOT pay principal if interest is due
    if ($current_interest_due > 0.01) {
        $errors[] = "Cannot pay principal while interest of ₹" . number_format($current_interest_due, 2) . " is due. Please pay interest first.";
    }
    
    if ($principal_amount <= 0) $errors[] = "Principal amount must be greater than 0";
    if ($collection_employee_id <= 0) $errors[] = "Please select the collecting employee";
    if ($payment_mode === 'bank' && $bank_account_id <= 0) {
        $errors[] = "Please select a bank account for bank transfer";
    }
    
    // Check if principal amount exceeds remaining principal
    if ($principal_amount > $remaining_principal_before) {
        $errors[] = "Principal payment of ₹" . number_format($principal_amount, 2) . " exceeds remaining principal of ₹" . number_format($remaining_principal_before, 2);
    }
    
    if (empty($errors)) {
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Generate payment receipt number
            $receipt_query = "SELECT COUNT(*) as count FROM payments WHERE DATE(created_at) = CURDATE()";
            $receipt_result = mysqli_query($conn, $receipt_query);
            $receipt_row = mysqli_fetch_assoc($receipt_result);
            $receipt_count = $receipt_row['count'] + 1;
            $payment_receipt = 'PRN' . date('ymd') . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
            
            // Calculate remaining balances after payment
            $remaining_principal_after = $remaining_principal_before - $principal_amount;
            
            // Insert principal payment
            $insert_payment = "INSERT INTO payments (
                loan_id, receipt_number, payment_date, principal_amount, 
                interest_amount, total_amount, payment_mode, employee_id, remarks,
                includes_overdue, overdue_amount_paid, overdue_charge
            ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 0, 0, 0)";
            
            $stmt = mysqli_prepare($conn, $insert_payment);
            mysqli_stmt_bind_param($stmt, 'issddiss', 
                $loan_id, $payment_receipt, $payment_date, 
                $principal_amount, $principal_amount,
                $payment_mode, $collection_employee_id, $remarks
            );
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error inserting payment: " . mysqli_stmt_error($stmt));
            }
            
            $payment_id = mysqli_insert_id($conn);
            
            // Update loan remaining principal if column exists
            $check_loan_column = "SHOW COLUMNS FROM loans LIKE 'remaining_principal'";
            $check_loan_result = mysqli_query($conn, $check_loan_column);
            if (mysqli_num_rows($check_loan_result) > 0) {
                $update_loan = "UPDATE loans SET remaining_principal = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_loan);
                mysqli_stmt_bind_param($update_stmt, 'di', $remaining_principal_after, $loan_id);
                mysqli_stmt_execute($update_stmt);
            }
            
            // If payment mode is bank, update bank ledger
            if ($payment_mode === 'bank' && $bank_account_id > 0) {
                // Get bank details
                $bank_query = "SELECT bank_id FROM bank_accounts WHERE id = ?";
                $bank_stmt = mysqli_prepare($conn, $bank_query);
                mysqli_stmt_bind_param($bank_stmt, 'i', $bank_account_id);
                mysqli_stmt_execute($bank_stmt);
                $bank_result = mysqli_stmt_get_result($bank_stmt);
                $bank_data = mysqli_fetch_assoc($bank_result);
                $bank_id = $bank_data['bank_id'];
                
                // Get current balance
                $balance_query = "SELECT balance FROM bank_ledger 
                                 WHERE bank_account_id = ? 
                                 ORDER BY id DESC LIMIT 1";
                $balance_stmt = mysqli_prepare($conn, $balance_query);
                mysqli_stmt_bind_param($balance_stmt, 'i', $bank_account_id);
                mysqli_stmt_execute($balance_stmt);
                $balance_result = mysqli_stmt_get_result($balance_stmt);
                
                if (mysqli_num_rows($balance_result) > 0) {
                    $balance_row = mysqli_fetch_assoc($balance_result);
                    $last_balance = $balance_row['balance'];
                } else {
                    // Get opening balance
                    $opening_query = "SELECT opening_balance FROM bank_accounts WHERE id = ?";
                    $opening_stmt = mysqli_prepare($conn, $opening_query);
                    mysqli_stmt_bind_param($opening_stmt, 'i', $bank_account_id);
                    mysqli_stmt_execute($opening_stmt);
                    $opening_result = mysqli_stmt_get_result($opening_stmt);
                    $opening_data = mysqli_fetch_assoc($opening_result);
                    $last_balance = $opening_data['opening_balance'];
                }
                
                $new_balance = $last_balance + $principal_amount;
                
                // Insert into bank ledger
                $ledger_query = "INSERT INTO bank_ledger (
                    entry_date, bank_id, bank_account_id, transaction_type, 
                    amount, balance, reference_number, description, payment_id, loan_id, created_by
                ) VALUES (?, ?, ?, 'credit', ?, ?, ?, ?, ?, ?, ?)";
                
                $description = "Principal payment - Receipt #: " . $payment_receipt;
                $ledger_stmt = mysqli_prepare($conn, $ledger_query);
                mysqli_stmt_bind_param($ledger_stmt, 'siidsssiii', 
                    $payment_date, $bank_id, $bank_account_id,
                    $principal_amount, $new_balance, $payment_receipt, $description,
                    $payment_id, $loan_id, $_SESSION['user_id']
                );
                
                if (!mysqli_stmt_execute($ledger_stmt)) {
                    throw new Exception("Error updating bank ledger: " . mysqli_stmt_error($ledger_stmt));
                }
            }
            
            // Check if loan is fully paid
            if ($remaining_principal_after <= 0) {
                // Close the loan
                $close_query = "UPDATE loans SET status = 'closed', close_date = ? WHERE id = ?";
                $close_stmt = mysqli_prepare($conn, $close_query);
                mysqli_stmt_bind_param($close_stmt, 'si', $payment_date, $loan_id);
                mysqli_stmt_execute($close_stmt);
            }
            
            // Get employee name for log
            $emp_name_query = "SELECT name FROM users WHERE id = ?";
            $emp_stmt = mysqli_prepare($conn, $emp_name_query);
            mysqli_stmt_bind_param($emp_stmt, 'i', $collection_employee_id);
            mysqli_stmt_execute($emp_stmt);
            $emp_result = mysqli_stmt_get_result($emp_stmt);
            $emp_data = mysqli_fetch_assoc($emp_result);
            $emp_name = $emp_data['name'];
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'principal_payment', ?, 'payments', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Principal of ₹" . number_format($principal_amount, 2) . " paid by " . $emp_name . " for Loan #" . $receipt_number;
            
            if ($payment_mode === 'bank') {
                $log_description .= " via Bank Transfer";
            }
            
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
            mysqli_stmt_execute($log_stmt);
            
            mysqli_commit($conn);
            
            // Redirect to bill receipt page
            header('Location: bill-receipt.php?receipt=' . urlencode($payment_receipt) . '&success=payment_received');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error processing principal payment: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Check for success messages from bill receipt page
if (isset($_GET['success']) && $_GET['success'] == 'payment_received') {
    $message = "Payment processed successfully!";
}

// Get company settings for display
$company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
$company_result = mysqli_query($conn, $company_query);
$company = mysqli_fetch_assoc($company_result);

if (!$company) {
    $company = [
        'branch_name' => 'DHARMAPURI',
        'address' => 'DPI',
        'phone' => '4575848575',
        'email' => '',
        'logo_path' => ''
    ];
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

        .collection-container {
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

        .interest-badge {
            background: #ecc94b;
            color: #744210;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
        }

        .principal-badge {
            background: #4299e1;
            color: white;
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
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

        /* Live Search Styles */
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

        .search-result-item.selected {
            background: #c3dafe;
            border-left: 4px solid #667eea;
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

        .result-due {
            color: #ecc94b;
            font-weight: 600;
        }

        .result-overdue {
            color: #f56565;
            font-weight: 600;
        }

        .result-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 5px;
        }

        .badge-overdue {
            background: #f56565;
            color: white;
        }

        .badge-due {
            background: #ecc94b;
            color: #744210;
        }

        .loading-indicator {
            text-align: center;
            padding: 20px;
            color: #718096;
        }

        .loading-indicator i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-results {
            padding: 30px;
            text-align: center;
            color: #718096;
        }

        .no-results i {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 10px;
        }

        .search-stats {
            margin-top: 10px;
            font-size: 13px;
            color: #718096;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .receipt-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 18px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
        }

        .customer-info {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 30px;
            align-items: start;
        }

        .customer-photo {
            width: 150px;
            height: 150px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .customer-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .detail-item {
            margin-bottom: 10px;
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 2px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .loan-summary {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .summary-item {
            text-align: center;
        }

        .summary-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
        }

        .summary-value.principal {
            color: #667eea;
        }

        .summary-value.remaining {
            color: #48bb78;
        }

        .summary-value.interest-rate {
            color: #ecc94b;
        }

        .overdue-badge {
            background: #f56565;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .current-badge {
            background: #48bb78;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .warning-badge {
            background: #ecc94b;
            color: #744210;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .interest-breakdown {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .breakdown-box {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }

        .breakdown-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .breakdown-value {
            font-size: 18px;
            font-weight: 700;
        }

        .breakdown-value.monthly {
            color: #4299e1;
        }

        .breakdown-value.daily {
            color: #9f7aea;
        }

        .interest-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .interest-box {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }

        .interest-box.accrued {
            border-top: 4px solid #4299e1;
        }

        .interest-box.paid {
            border-top: 4px solid #48bb78;
        }

        .interest-box.due {
            border-top: 4px solid #ecc94b;
        }

        .interest-box.current {
            border-top: 4px solid #f56565;
        }

        .interest-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .interest-value {
            font-size: 20px;
            font-weight: 700;
        }

        .interest-value.accrued {
            color: #4299e1;
        }

        .interest-value.paid {
            color: #48bb78;
        }

        .interest-value.due {
            color: #ecc94b;
        }

        .interest-value.current {
            color: #f56565;
        }

        .payment-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .payment-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #e2e8f0;
        }

        .payment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .payment-card.interest {
            border-color: #ecc94b;
        }

        .payment-card.principal {
            border-color: #4299e1;
        }

        .payment-card.interest:hover {
            background: #fef3c7;
        }

        .payment-card.principal:hover {
            background: #ebf8ff;
        }

        .payment-card-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .payment-card-icon.interest {
            color: #ecc94b;
        }

        .payment-card-icon.principal {
            color: #4299e1;
        }

        .payment-card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .payment-card-amount {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .payment-card-amount.interest {
            color: #ecc94b;
        }

        .payment-card-amount.principal {
            color: #4299e1;
        }

        .payment-card-note {
            font-size: 12px;
            color: #718096;
        }

        .payment-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
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

        .payment-history {
            margin-top: 20px;
        }

        .payment-table {
            width: 100%;
            border-collapse: collapse;
        }

        .payment-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .payment-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .interest-highlight {
            background: #ecc94b20;
            font-weight: 600;
            color: #744210;
        }

        .principal-highlight {
            background: #4299e120;
            font-weight: 600;
            color: #2c5282;
        }

        .overdue-highlight {
            background: #f5656520;
            font-weight: 600;
            color: #c53030;
        }

        .charge-highlight {
            background: #4299e120;
            font-weight: 600;
            color: #2b6cb0;
        }

        .both-highlight {
            background: #9f7aea20;
            font-weight: 600;
            color: #553c9a;
        }

        .total-highlight {
            background: #48bb7820;
            font-weight: 700;
            color: #276749;
        }

        .collection-employee {
            background: #ebf4ff;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
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
            max-width: 800px;
            width: 95%;
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

        .amount-display {
            font-size: 32px;
            font-weight: 700;
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .amount-display.interest {
            color: #ecc94b;
            border: 2px solid #ecc94b;
        }

        .amount-display.principal {
            color: #4299e1;
            border: 2px solid #4299e1;
        }

        .amount-display small {
            font-size: 14px;
            color: #718096;
            display: block;
        }

        .due-date-box {
            background: #ebf4ff;
            border-radius: 10px;
            padding: 15px;
            margin: 10px 0;
            text-align: center;
            border: 1px solid #667eea;
        }

        .due-date-label {
            font-size: 14px;
            color: #4a5568;
        }

        .due-date-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .interest-warning {
            background: #fef3c7;
            border-left: 4px solid #ecc94b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            color: #744210;
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

        /* Overdue specific styles */
        .overdue-section {
            margin: 20px 0;
            padding: 15px;
            background: #fff5f5;
            border-radius: 8px;
            border-left: 4px solid #f56565;
        }

        .overdue-title {
            color: #c53030;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .overdue-amount {
            font-size: 24px;
            font-weight: 700;
            color: #c53030;
            margin-bottom: 5px;
        }

        .overdue-note {
            font-size: 13px;
            color: #742a2a;
        }

        .overdue-details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }

        .overdue-details-table th {
            background: #fed7d7;
            color: #c53030;
            padding: 8px;
            text-align: left;
        }

        .overdue-details-table td {
            padding: 8px;
            border-bottom: 1px solid #fed7d7;
        }

        .overdue-details-table tr:last-child td {
            border-bottom: none;
        }

        .display-card {
            border-radius: 12px;
            padding: 15px;
            text-align: center;
        }

        .display-card.interest-card {
            background: #fef3c7;
            border: 2px solid #ecc94b;
        }

        .display-card.overdue-card {
            background: #fff5f5;
            border: 2px solid #f56565;
        }

        .display-card.charge-card {
            background: #e6f7ff;
            border: 2px solid #4299e1;
        }

        .display-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .display-value {
            font-size: 24px;
            font-weight: 700;
        }

        .display-value.interest {
            color: #ecc94b;
        }

        .display-value.overdue {
            color: #f56565;
        }

        .display-value.charge {
            color: #4299e1;
        }

        .grand-total-box {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
            border: 2px solid #667eea;
        }

        .grand-total-label {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .grand-total-value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }

        .grand-total-note {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .summary-row.total {
            border-top: 2px solid #667eea;
            border-bottom: none;
            margin-top: 10px;
            padding-top: 10px;
            font-weight: 700;
        }

        .summary-interest {
            color: #ecc94b;
            font-weight: 600;
        }

        .summary-overdue {
            color: #f56565;
            font-weight: 600;
        }

        .summary-charge {
            color: #4299e1;
            font-weight: 600;
        }

        .summary-grand {
            color: #667eea;
            font-size: 18px;
        }

        .three-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        /* Bank Account Selection Styles */
        .bank-selection {
            margin-top: 15px;
            padding: 15px;
            background: #ebf8ff;
            border-radius: 8px;
            border-left: 4px solid #4299e1;
            display: none;
        }

        .bank-selection.show {
            display: block;
        }

        .bank-selection label {
            color: #2c5282;
            font-weight: 600;
        }

        .bank-option {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .bank-option:last-child {
            border-bottom: none;
        }

        .bank-details {
            font-size: 12px;
            color: #718096;
            margin-top: 2px;
        }

        .bank-balance {
            color: #48bb78;
            font-weight: 600;
        }

        /* Balance Summary Styles */
        .balance-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
            padding: 20px;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border-radius: 12px;
        }

        .balance-box {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .balance-box.principal {
            border-top: 4px solid #4299e1;
        }

        .balance-box.interest {
            border-top: 4px solid #ecc94b;
        }

        .balance-box.total {
            border-top: 4px solid #48bb78;
        }

        .balance-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .balance-value {
            font-size: 24px;
            font-weight: 700;
        }

        .balance-value.principal {
            color: #4299e1;
        }

        .balance-value.interest {
            color: #ecc94b;
        }

        .balance-value.total {
            color: #48bb78;
        }

        .balance-note {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        .progress-container {
            margin: 15px 0;
            background: #edf2f7;
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #4299e1, #48bb78);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        @media (max-width: 1200px) {
            .loan-summary {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .interest-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .interest-breakdown {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .customer-info {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .customer-photo {
                margin: 0 auto;
            }
            
            .payment-actions {
                grid-template-columns: 1fr;
            }
            
            .balance-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .customer-details {
                grid-template-columns: 1fr;
            }
            
            .loan-summary {
                grid-template-columns: 1fr;
            }
            
            .interest-summary {
                grid-template-columns: 1fr;
            }
            
            .interest-breakdown {
                grid-template-columns: 1fr;
            }
            
            .items-table, .payment-table {
                overflow-x: auto;
                display: block;
            }
            
            .quick-stats {
                flex-direction: column;
                gap: 10px;
            }
            
            .three-column-grid, .two-column-grid {
                grid-template-columns: 1fr;
            }
            
            .search-result-item {
                padding: 12px;
            }
            
            .result-details {
                flex-direction: column;
                gap: 5px;
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
                <div class="collection-container">
                    <!-- Page Header -->
                    <div class="page-header no-print">
                        <h1 class="page-title">
                            <i class="bi bi-cash-coin"></i>
                            Loan Collection
                            <span class="interest-badge">Interest</span>
                            <span class="principal-badge">Principal</span>
                        </h1>
                        <a href="New-Loan.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Loans
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success no-print"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error no-print"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Live Search Section -->
                    <div class="search-card no-print">
                        <div class="search-title">
                            <i class="bi bi-search"></i>
                            Search Open Loans
                        </div>

                        <!-- Live Search Input -->
                        <div class="live-search-container">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="live-search-input" id="liveSearch" 
                                   placeholder="Type receipt number, customer name or mobile number..." 
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                                   autocomplete="off">
                            <i class="bi bi-x-circle clear-search" id="clearSearch" onclick="clearSearch()"></i>
                            
                            <!-- Search Results Dropdown -->
                            <div class="search-results" id="searchResults"></div>
                        </div>

                        <!-- Search Stats -->
                        <div class="search-stats">
                            <span id="searchStats">Type at least 2 characters to search</span>
                            <span id="resultCount"></span>
                        </div>

                        <!-- Quick Stats -->
                        <?php
                        if ($open_loans_result) {
                            mysqli_data_seek($open_loans_result, 0);
                            $total_principal_query = "SELECT SUM(loan_amount) as total FROM loans WHERE status = 'open'";
                            $total_result = mysqli_query($conn, $total_principal_query);
                            $total_data = mysqli_fetch_assoc($total_result);
                            
                            $total_overdue_query = "SELECT SUM(total_overdue_amount) as total_overdue FROM loans WHERE status = 'open'";
                            $total_overdue_result = mysqli_query($conn, $total_overdue_query);
                            $total_overdue_data = mysqli_fetch_assoc($total_overdue_result);
                        ?>
                        <div class="quick-stats">
                            <div><strong>Total Open Loans:</strong> <?php echo mysqli_num_rows($open_loans_result); ?></div>
                            <div><strong>Total Outstanding:</strong> ₹ <?php echo number_format($total_data['total'] ?? 0, 0); ?></div>
                            <div><strong>Total Overdue:</strong> <span style="color: #f56565;">₹ <?php echo number_format($total_overdue_data['total_overdue'] ?? 0, 2); ?></span></div>
                        </div>
                        <?php } ?>
                    </div>

                    <?php if ($loan && $customer && $overdue_details): ?>
                        <!-- Receipt Number Display -->
                        <div style="text-align: center;" class="no-print">
                            <span class="receipt-badge">
                                <i class="bi bi-receipt"></i> Receipt #<?php echo $loan['receipt_number']; ?>
                            </span>
                            <?php if ($overdue_details['has_overdue']): ?>
                                <span class="overdue-badge" style="background: #f56565; color: white; padding: 8px 20px; border-radius: 50px; font-size: 18px; font-weight: 600; display: inline-block; margin-left: 10px;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> Overdue: ₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Balance Summary -->
                        <div class="balance-summary no-print">
                            <div class="balance-box principal">
                                <div class="balance-label">Principal Balance</div>
                                <div class="balance-value principal">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                <div class="balance-note">Original: ₹ <?php echo number_format($loan['loan_amount'], 2); ?></div>
                            </div>
                            <div class="balance-box interest">
                                <div class="balance-label">Pending Interest</div>
                                <div class="balance-value interest">₹ <?php echo number_format($pending_interest, 2); ?></div>
                                <div class="balance-note">Total Paid: ₹ <?php echo number_format($total_interest_paid, 2); ?></div>
                            </div>
                            <div class="balance-box total">
                                <div class="balance-label">Total Outstanding</div>
                                <div class="balance-value total">₹ <?php echo number_format($remaining_principal + $pending_interest, 2); ?></div>
                                <div class="balance-note">Principal + Interest</div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <?php 
                        $paid_percentage = ($total_principal_paid / $loan['loan_amount']) * 100;
                        ?>
                        <div class="progress-container no-print">
                            <div class="progress-bar" style="width: <?php echo $paid_percentage; ?>%;"></div>
                        </div>

                        <!-- Due Date Information -->
                        <div class="due-date-box no-print">
                            <div class="two-column-grid">
                                <div>
                                    <div class="due-date-label">Last Payment Date</div>
                                    <div class="due-date-value" style="font-size: 18px;"><?php echo $overdue_details['last_paid_date']; ?></div>
                                </div>
                                <div>
                                    <div class="due-date-label">Next Due Date</div>
                                    <div class="due-date-value"><?php echo $overdue_details['next_due_formatted']; ?></div>
                                </div>
                            </div>
                            <?php if ($overdue_details['overdue_months'] > 0): ?>
                                <div style="margin-top: 15px; padding: 10px; background: #f56565; color: white; border-radius: 8px;">
                                    <strong>⚠️ Overdue by <?php echo $overdue_details['overdue_months']; ?> month(s)</strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Overdue Details Table -->
                        <?php if (!empty($overdue_details['overdue_details'])): ?>
                        <div class="overdue-section no-print">
                            <div class="overdue-title">
                                <i class="bi bi-exclamation-triangle-fill"></i> Overdue Payment Schedule
                            </div>
                            
                            <table class="overdue-details-table">
                                <thead>
                                    <tr>
                                        <th>Due Date</th>
                                        <th>Days Overdue</th>
                                        <th>Rate</th>
                                        <th>Overdue Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($overdue_details['overdue_details'] as $overdue): ?>
                                    <tr>
                                        <td><?php echo $overdue['due_date_formatted']; ?></td>
                                        <td><?php echo $overdue['days_overdue']; ?> days</td>
                                        <td><?php echo $overdue['regular_rate']; ?>%</td>
                                        <td style="color: #f56565; font-weight: 600;">₹ <?php echo number_format($overdue['overdue_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #fed7d7; font-weight: 600;">
                                        <td colspan="3" style="text-align: right;">Total Overdue:</td>
                                        <td style="color: #c53030;">₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Interest Warning if Due -->
                        <?php if ($interest_overdue): ?>
                        <div class="interest-warning no-print">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <strong>Current Month Interest of ₹ <?php echo number_format($current_interest_due, 2); ?> is due!</strong>
                            <p>Please collect interest before accepting any principal payment.</p>
                        </div>
                        <?php endif; ?>

                        <!-- Customer Information -->
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-person"></i>
                                Customer Information
                            </div>

                            <div class="customer-info">
                                <?php if (!empty($customer['photo'])): ?>
                                    <img src="<?php echo $customer['photo']; ?>" class="customer-photo" alt="Customer Photo">
                                <?php else: ?>
                                    <div class="customer-photo" style="background: #f7fafc; display: flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-person" style="font-size: 48px; color: #a0aec0;"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="customer-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Customer Name</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($customer['name']); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Guardian</div>
                                        <div class="detail-value">
                                            <?php echo $customer['guardian_type'] ? $customer['guardian_type'] . '. ' : ''; ?>
                                            <?php echo htmlspecialchars($customer['guardian']); ?>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Mobile Number</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($customer['mobile']); ?></div>
                                    </div>
                                    <?php if (!empty($customer['whatsapp'])): ?>
                                        <div class="detail-item">
                                            <div class="detail-label">WhatsApp</div>
                                            <div class="detail-value"><?php echo htmlspecialchars($customer['whatsapp']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <div class="detail-label">Address</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($customer['address']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Summary with Remaining Principal -->
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-info-circle"></i>
                                Loan Details
                            </div>

                            <div class="loan-summary">
                                <div class="summary-item">
                                    <div class="summary-label">Original Principal</div>
                                    <div class="summary-value principal">₹ <?php echo number_format($loan['loan_amount'], 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Paid Principal</div>
                                    <div class="summary-value">₹ <?php echo number_format($total_principal_paid, 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Remaining Principal</div>
                                    <div class="summary-value remaining">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Interest Rate</div>
                                    <div class="summary-value interest-rate"><?php echo $loan['interest_amount']; ?>%</div>
                                </div>
                                <div class="summary-item">
                                    <div class="summary-label">Loan Date</div>
                                    <div class="summary-value"><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></div>
                                </div>
                            </div>

                            <!-- Interest Breakdown -->
                            <div class="interest-breakdown">
                                <div class="breakdown-box">
                                    <div class="breakdown-label">Monthly Interest</div>
                                    <div class="breakdown-value monthly">₹ <?php echo number_format($overdue_details['monthly_interest'], 2); ?></div>
                                </div>
                                <div class="breakdown-box">
                                    <div class="breakdown-label">Daily Interest</div>
                                    <div class="breakdown-value daily">₹ <?php echo number_format($overdue_details['daily_interest'], 2); ?></div>
                                </div>
                                <div class="breakdown-box">
                                    <div class="breakdown-label">Regular Rate</div>
                                    <div class="breakdown-value interest-rate"><?php echo $overdue_details['regular_rate']; ?>%</div>
                                </div>
                            </div>

                            <!-- Interest Summary -->
                            <div class="interest-summary">
                                <div class="interest-box accrued">
                                    <div class="interest-label">Total Interest Accrued</div>
                                    <div class="interest-value accrued">₹ <?php echo number_format($loan['total_interest_accrued'] ?? 0, 2); ?></div>
                                </div>
                                <div class="interest-box paid">
                                    <div class="interest-label">Total Interest Paid</div>
                                    <div class="interest-value paid">₹ <?php echo number_format($total_interest_paid, 2); ?></div>
                                </div>
                                <div class="interest-box due">
                                    <div class="interest-label">Pending Interest</div>
                                    <div class="interest-value due">₹ <?php echo number_format($pending_interest, 2); ?></div>
                                </div>
                                <div class="interest-box current">
                                    <div class="interest-label">Next Due Date</div>
                                    <div class="interest-value current" style="font-size: 16px;"><?php echo $overdue_details['next_due_formatted']; ?></div>
                                </div>
                            </div>

                            <!-- Collection Actions -->
                            <div class="payment-actions">
                                <!-- Interest Collection Card -->
                                <div class="payment-card interest" onclick="showInterestModal()">
                                    <div class="payment-card-icon interest">
                                        <i class="bi bi-percent"></i>
                                    </div>
                                    <div class="payment-card-title">Collect Interest</div>
                                    <div class="payment-card-amount interest">₹ <?php echo number_format($current_interest_due, 2); ?></div>
                                    <div class="payment-card-note">
                                        <small>Pending: ₹ <?php echo number_format($pending_interest, 2); ?></small>
                                    </div>
                                    <?php if ($overdue_details['has_overdue']): ?>
                                    <div class="payment-card-note">
                                        <span class="warning-badge">+ Overdue: ₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Principal Payment Card -->
                                <div class="payment-card principal <?php echo ($interest_overdue) ? 'disabled' : ''; ?>" 
                                     onclick="<?php echo (!$interest_overdue) ? 'showPrincipalModal()' : ''; ?>">
                                    <div class="payment-card-icon principal">
                                        <i class="bi bi-cash-stack"></i>
                                    </div>
                                    <div class="payment-card-title">Pay Principal</div>
                                    <div class="payment-card-amount principal">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                                    <div class="payment-card-note">
                                        <?php if ($interest_overdue): ?>
                                            <span class="warning-badge">Pay interest first</span>
                                        <?php else: ?>
                                            <small>Remaining: ₹ <?php echo number_format($remaining_principal, 2); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Jewelry Items -->
                        <?php if (!empty($items)): ?>
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-gem"></i>
                                Jewelry Items
                            </div>

                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>Jewel Name</th>
                                        <th>Karat</th>
                                        <th>Defect</th>
                                        <th>Stone</th>
                                        <th>Weight (g)</th>
                                        <th>Qty</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['jewel_name']); ?></td>
                                        <td><?php echo $item['karat']; ?>K</td>
                                        <td><?php echo htmlspecialchars($item['defect_details'] ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($item['stone_details'] ?: '-'); ?></td>
                                        <td><?php echo $item['net_weight']; ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Payment History with Balance Tracking -->
                        <?php if (!empty($payments)): ?>
                        <div class="info-card no-print">
                            <div class="section-title">
                                <i class="bi bi-clock-history"></i>
                                Payment History
                            </div>

                            <table class="payment-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Type</th>
                                        <th class="text-right">Principal (₹)</th>
                                        <th class="text-right">Interest (₹)</th>
                                        <th class="text-right">Total (₹)</th>
                                        <th>Mode</th>
                                        <th>Collected By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><strong><?php echo $payment['receipt_number']; ?></strong></td>
                                        <td>
                                            <?php if ($payment['principal_amount'] > 0 && $payment['interest_amount'] > 0): ?>
                                                <span class="badge both-highlight">Both</span>
                                            <?php elseif ($payment['principal_amount'] > 0): ?>
                                                <span class="badge principal-highlight">Principal</span>
                                            <?php elseif ($payment['interest_amount'] > 0): ?>
                                                <span class="badge interest-highlight">Interest</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right principal-highlight">
                                            <?php echo $payment['principal_amount'] > 0 ? '₹ ' . number_format($payment['principal_amount'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-right interest-highlight">
                                            <?php echo $payment['interest_amount'] > 0 ? '₹ ' . number_format($payment['interest_amount'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-right total-highlight"><strong>₹ <?php echo number_format($payment['total_amount'], 2); ?></strong></td>
                                        <td><?php echo strtoupper($payment['payment_mode']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['employee_name']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Interest Collection Modal with Balance Tracking -->
    <div class="modal no-print" id="interestModal">
        <div class="modal-content">
            <span class="modal-close" onclick="hideInterestModal()">&times;</span>
            <h3 class="modal-title">Collect Interest</h3>
            
            <?php if ($loan && $overdue_details): ?>
            <form method="POST" action="" id="interestForm">
                <input type="hidden" name="action" value="collect_interest">
                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                <input type="hidden" name="receipt_number" value="<?php echo $loan['receipt_number']; ?>">
                
                <!-- Current Balances -->
                <div class="balance-summary" style="margin-bottom: 20px;">
                    <div class="balance-box principal">
                        <div class="balance-label">Current Principal</div>
                        <div class="balance-value principal">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                    </div>
                    <div class="balance-box interest">
                        <div class="balance-label">Pending Interest</div>
                        <div class="balance-value interest">₹ <?php echo number_format($pending_interest, 2); ?></div>
                    </div>
                </div>

                <!-- Three Column Display for Amounts -->
                <div class="three-column-grid">
                    <!-- Interest Amount Display -->
                    <div class="display-card interest-card">
                        <div class="display-label">Current Interest Due</div>
                        <div class="display-value interest" id="displayInterestAmount">₹ <?php echo number_format($current_interest_due, 2); ?></div>
                        <small style="color: #744210;">Based on ₹<?php echo number_format($remaining_principal, 0); ?> principal</small>
                    </div>

                    <!-- Overdue Amount Display -->
                    <div class="display-card overdue-card">
                        <div class="display-label">Total Overdue</div>
                        <div class="display-value overdue" id="displayOverdueAmount">₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?></div>
                        <small style="color: #742a2a;">Past Due Amounts</small>
                    </div>

                    <!-- Overdue Charge Display -->
                    <div class="display-card charge-card">
                        <div class="display-label">Overdue Charge</div>
                        <div class="display-value charge" id="displayChargeAmount">₹ 0.00</div>
                        <small style="color: #2b6cb0;">Additional Penalty</small>
                    </div>
                </div>

                <!-- Grand Total Display -->
                <div class="grand-total-box">
                    <div class="grand-total-label">GRAND TOTAL</div>
                    <div class="grand-total-value" id="grandTotalAmount">₹ <?php echo number_format($current_interest_due + $overdue_details['total_overdue'], 2); ?></div>
                    <div class="grand-total-note">Interest + Overdue + Charges</div>
                </div>

                <!-- Three Column Input Fields -->
                <div class="three-column-grid">
                    <!-- Interest Input -->
                    <div class="form-group">
                        <label class="form-label required">Interest (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-percent input-icon" style="color: #ecc94b;"></i>
                            <input type="number" class="form-control" name="interest_amount" id="interest_amount" 
                                   value="<?php echo $current_interest_due; ?>" step="0.01" min="0" 
                                   max="<?php echo $current_interest_due; ?>" required onchange="updateTotals()">
                        </div>
                        <small style="color: #718096;">Max: ₹ <?php echo number_format($current_interest_due, 2); ?></small>
                    </div>

                    <!-- Overdue Input -->
                    <?php if ($overdue_details['has_overdue']): ?>
                    <div class="form-group">
                        <label class="form-label">Overdue (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-exclamation-triangle input-icon" style="color: #f56565;"></i>
                            <input type="number" class="form-control" name="overdue_amount" id="overdue_amount" 
                                   value="<?php echo $overdue_details['total_overdue']; ?>" step="0.01" min="0" 
                                   max="<?php echo $overdue_details['total_overdue']; ?>" onchange="updateTotals()">
                        </div>
                        <small style="color: #f56565;">Max: ₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?></small>
                    </div>
                    <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">Overdue (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-exclamation-triangle input-icon" style="color: #f56565;"></i>
                            <input type="number" class="form-control" name="overdue_amount" id="overdue_amount" 
                                   value="0" step="0.01" min="0" readonly>
                        </div>
                        <small style="color: #718096;">No overdue</small>
                    </div>
                    <?php endif; ?>

                    <!-- Overdue Charge Input -->
                    <div class="form-group">
                        <label class="form-label">Overdue Charge (₹)</label>
                        <div class="input-group">
                            <i class="bi bi-exclamation-circle input-icon" style="color: #4299e1;"></i>
                            <input type="number" class="form-control" name="overdue_charge" id="overdue_charge" 
                                   value="0" step="0.01" min="0" onchange="updateTotals()">
                        </div>
                        <small style="color: #4299e1;">Additional penalty fee</small>
                    </div>
                </div>

                <!-- Payment Date and Employee - Two Column -->
                <div class="two-column-grid">
                    <div class="form-group">
                        <label class="form-label required">Payment Date</label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Collecting Employee</label>
                        <div class="input-group">
                            <i class="bi bi-person-badge input-icon"></i>
                            <select class="form-select" name="collection_employee_id" required>
                                <option value="">Select Employee</option>
                                <?php 
                                if ($employees_result) {
                                    mysqli_data_seek($employees_result, 0);
                                    while($emp = mysqli_fetch_assoc($employees_result)): 
                                ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['name']); ?> (<?php echo ucfirst($emp['role']); ?>)
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Payment Mode -->
                <div class="form-group">
                    <label class="form-label required">Payment Mode</label>
                    <select class="form-select" name="payment_mode" id="payment_mode" required onchange="toggleBankSelection()">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <!-- Bank Account Selection (shown only for bank mode) -->
                <div class="bank-selection" id="bank_selection">
                    <label class="form-label required">Select Bank Account</label>
                    <select class="form-select" name="bank_account_id" id="bank_account_id">
                        <option value="">Select Bank Account</option>
                        <?php 
                        if ($bank_accounts_result) {
                            mysqli_data_seek($bank_accounts_result, 0);
                            while($bank = mysqli_fetch_assoc($bank_accounts_result)): 
                                // Get current balance
                                $balance_query = "SELECT balance FROM bank_ledger 
                                                WHERE bank_account_id = {$bank['id']} 
                                                ORDER BY id DESC LIMIT 1";
                                $balance_result = mysqli_query($conn, $balance_query);
                                if (mysqli_num_rows($balance_result) > 0) {
                                    $current_balance = mysqli_fetch_assoc($balance_result)['balance'];
                                } else {
                                    $current_balance = $bank['opening_balance'];
                                }
                        ?>
                            <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $current_balance; ?>">
                                <?php echo $bank['bank_full_name']; ?> - <?php echo $bank['account_holder_no']; ?> (A/C: <?php echo substr($bank['bank_account_no'], -4); ?>)
                            </option>
                        <?php 
                            endwhile;
                        } 
                        ?>
                    </select>
                    <small class="form-text text-muted">The amount will be credited to the selected bank account</small>
                </div>

                <!-- Remarks -->
                <div class="form-group">
                    <label class="form-label">Remarks (Optional)</label>
                    <textarea class="form-control" name="remarks" rows="2" placeholder="Add any notes about this collection">Interest Collection</textarea>
                </div>

                <!-- Detailed Summary -->
                <div style="background: #f7fafc; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid #e2e8f0;">
                    <h4 style="margin: 0 0 15px 0; color: #2d3748; font-size: 16px;">Payment Summary</h4>
                    
                    <div class="two-column-grid">
                        <div class="summary-row">
                            <span style="color: #ecc94b; font-weight: 600;">Current Interest:</span>
                            <span class="summary-interest" id="summaryInterest">₹ <?php echo number_format($current_interest_due, 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span style="color: #f56565; font-weight: 600;">Overdue Amount:</span>
                            <span class="summary-overdue" id="summaryOverdue">₹ <?php echo number_format($overdue_details['total_overdue'], 2); ?></span>
                        </div>
                        <div class="summary-row">
                            <span style="color: #4299e1; font-weight: 600;">Overdue Charge:</span>
                            <span class="summary-charge" id="summaryCharge">₹ 0.00</span>
                        </div>
                        <div class="summary-row total">
                            <span style="font-weight: 700;">Grand Total:</span>
                            <span class="summary-grand" id="summaryTotal">₹ <?php echo number_format($current_interest_due + $overdue_details['total_overdue'], 2); ?></span>
                        </div>
                    </div>
                    
                    <div style="border-top: 2px dashed #e2e8f0; padding-top: 15px; margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between; font-size: 14px; color: #4a5568;">
                            <span>Remaining Principal:</span>
                            <span class="font-weight-600">₹ <?php echo number_format($remaining_principal, 2); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 14px; color: #4a5568;">
                            <span>Pending Interest After Payment:</span>
                            <span class="font-weight-600" id="remainingInterestAfter">₹ <?php echo number_format($pending_interest - $current_interest_due, 2); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="hideInterestModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning" onclick="return confirmCollect()">
                        <i class="bi bi-check-circle"></i> Collect Payment
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Principal Payment Modal with Balance Tracking -->
    <div class="modal no-print" id="principalModal">
        <div class="modal-content">
            <span class="modal-close" onclick="hidePrincipalModal()">&times;</span>
            <h3 class="modal-title">Pay Principal Amount</h3>
            
            <?php if ($loan && !$interest_overdue): ?>
            <form method="POST" action="" id="principalForm">
                <input type="hidden" name="action" value="pay_principal">
                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                <input type="hidden" name="receipt_number" value="<?php echo $loan['receipt_number']; ?>">
                <input type="hidden" name="current_interest_due" value="<?php echo $current_interest_due; ?>">
                
                <!-- Current Balances -->
                <div class="balance-summary" style="margin-bottom: 20px;">
                    <div class="balance-box principal">
                        <div class="balance-label">Current Principal</div>
                        <div class="balance-value principal" id="currentPrincipalDisplay">₹ <?php echo number_format($remaining_principal, 2); ?></div>
                    </div>
                    <div class="balance-box interest">
                        <div class="balance-label">Pending Interest</div>
                        <div class="balance-value interest">₹ <?php echo number_format($pending_interest, 2); ?></div>
                    </div>
                </div>

                <div class="amount-display principal" id="principalDisplayAmount">
                    ₹ <?php echo number_format($remaining_principal, 2); ?>
                    <small>Remaining Principal</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Date</label>
                    <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Principal Amount (₹)</label>
                    <div class="input-group">
                        <i class="bi bi-cash-stack input-icon"></i>
                        <input type="number" class="form-control" name="principal_amount" id="principal_amount" 
                               value="<?php echo $remaining_principal; ?>" step="0.01" min="0.01" 
                               max="<?php echo $remaining_principal; ?>" required onchange="updatePrincipalDisplay()">
                    </div>
                    <small style="color: #718096;">Max: ₹ <?php echo number_format($remaining_principal, 2); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Collecting Employee</label>
                    <div class="input-group">
                        <i class="bi bi-person-badge input-icon"></i>
                        <select class="form-select" name="collection_employee_id" required>
                            <option value="">Select Employee</option>
                            <?php 
                            if ($employees_result) {
                                mysqli_data_seek($employees_result, 0);
                                while($emp = mysqli_fetch_assoc($employees_result)): 
                            ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo ($_SESSION['user_id'] == $emp['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['name']); ?> (<?php echo ucfirst($emp['role']); ?>)
                                </option>
                            <?php 
                                endwhile;
                            } 
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label required">Payment Mode</label>
                    <select class="form-select" name="payment_mode" id="principal_payment_mode" required onchange="togglePrincipalBankSelection()">
                        <option value="cash">Cash</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="upi">UPI</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <!-- Bank Account Selection for Principal (shown only for bank mode) -->
                <div class="bank-selection" id="principal_bank_selection">
                    <label class="form-label required">Select Bank Account</label>
                    <select class="form-select" name="bank_account_id" id="principal_bank_account_id">
                        <option value="">Select Bank Account</option>
                        <?php 
                        if ($bank_accounts_result) {
                            mysqli_data_seek($bank_accounts_result, 0);
                            while($bank = mysqli_fetch_assoc($bank_accounts_result)): 
                                // Get current balance
                                $balance_query = "SELECT balance FROM bank_ledger 
                                                WHERE bank_account_id = {$bank['id']} 
                                                ORDER BY id DESC LIMIT 1";
                                $balance_result = mysqli_query($conn, $balance_query);
                                if (mysqli_num_rows($balance_result) > 0) {
                                    $current_balance = mysqli_fetch_assoc($balance_result)['balance'];
                                } else {
                                    $current_balance = $bank['opening_balance'];
                                }
                        ?>
                            <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $current_balance; ?>">
                                <?php echo $bank['bank_full_name']; ?> - <?php echo $bank['account_holder_no']; ?> (A/C: <?php echo substr($bank['bank_account_no'], -4); ?>)
                            </option>
                        <?php 
                            endwhile;
                        } 
                        ?>
                    </select>
                    <small class="form-text text-muted">The amount will be credited to the selected bank account</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks (Optional)</label>
                    <textarea class="form-control" name="remarks" rows="2" placeholder="Add any notes about this principal payment">Principal Payment</textarea>
                </div>

                <div style="background: #f7fafc; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid #e2e8f0;">
                    <div class="summary-row">
                        <span>Original Principal:</span>
                        <span>₹ <?php echo number_format($loan['loan_amount'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Already Paid:</span>
                        <span>₹ <?php echo number_format($total_principal_paid, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Remaining After Payment:</span>
                        <span id="remainingAfterPayment">₹ <?php echo number_format($remaining_principal - $remaining_principal, 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span style="font-weight: 700;">Amount to Pay:</span>
                        <span style="font-weight: 700; color: #4299e1; font-size: 18px;" id="principalFinalAmount">₹ <?php echo number_format($remaining_principal, 2); ?></span>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="hidePrincipalModal()">Cancel</button>
                    <button type="submit" class="btn btn-info" onclick="return confirm('Pay principal of ₹' + document.getElementById('principal_amount').value + '?')">
                        <i class="bi bi-check-circle"></i> Pay Principal
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Include flatpickr and custom scripts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Store original values
        let originalInterest = <?php echo $current_interest_due ?? 0; ?>;
        let originalOverdue = <?php echo isset($overdue_details) ? $overdue_details['total_overdue'] : 0; ?>;
        let remainingPrincipal = <?php echo $remaining_principal ?? 0; ?>;
        let pendingInterest = <?php echo $pending_interest ?? 0; ?>;

        // Live Search Functionality
        let searchTimeout;
        const searchInput = document.getElementById('liveSearch');
        const searchResults = document.getElementById('searchResults');
        const searchStats = document.getElementById('searchStats');
        const resultCount = document.getElementById('resultCount');
        const clearBtn = document.getElementById('clearSearch');

        // Show clear button if there's text
        if (searchInput.value.length > 0) {
            clearBtn.style.display = 'block';
        }

        // Search input event
        searchInput.addEventListener('input', function() {
            const term = this.value.trim();
            
            // Show/hide clear button
            if (term.length > 0) {
                clearBtn.style.display = 'block';
            } else {
                clearBtn.style.display = 'none';
                searchResults.classList.remove('show');
                searchStats.textContent = 'Type at least 2 characters to search';
                resultCount.textContent = '';
                return;
            }

            // Clear previous timeout
            clearTimeout(searchTimeout);

            // Don't search if less than 2 characters
            if (term.length < 2) {
                searchResults.classList.remove('show');
                searchStats.textContent = 'Type at least 2 characters to search';
                resultCount.textContent = '';
                return;
            }

            // Show loading
            searchResults.innerHTML = '<div class="loading-indicator"><i class="bi bi-arrow-repeat"></i> Searching...</div>';
            searchResults.classList.add('show');
            searchStats.textContent = `Searching for "${term}"...`;

            // Set timeout to avoid too many requests
            searchTimeout = setTimeout(() => {
                fetch(`loan-collection.php?ajax_search=1&term=${encodeURIComponent(term)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            searchResults.innerHTML = `<div class="no-results"><i class="bi bi-exclamation-circle"></i><br>${data.error}</div>`;
                            searchStats.textContent = 'Error searching';
                            resultCount.textContent = '';
                            return;
                        }

                        const results = data.results || [];
                        
                        if (results.length === 0) {
                            searchResults.innerHTML = '<div class="no-results"><i class="bi bi-search"></i><br>No loans found matching your search</div>';
                            searchStats.textContent = `No results for "${term}"`;
                            resultCount.textContent = '';
                        } else {
                            // Build results HTML
                            let html = '';
                            results.forEach(loan => {
                                const matchClass = loan.match_type === 'exact' ? 'exact' : '';
                                const matchText = loan.match_type === 'exact' ? 'Exact Match' : 
                                                loan.match_type === 'receipt_start' ? 'Receipt Match' :
                                                loan.match_type === 'name_start' ? 'Name Match' : 'Partial Match';
                                
                                html += `
                                    <div class="search-result-item" onclick="selectLoan('${loan.receipt_number}')">
                                        <div class="match-badge ${matchClass}">${matchText}</div>
                                        <div class="result-receipt">${loan.receipt_number}</div>
                                        <div class="result-customer">${loan.customer_name}</div>
                                        <div class="result-details">
                                            <span class="result-mobile">📞 ${loan.mobile}</span>
                                            <span class="result-balance">💰 Principal: ₹${loan.remaining_principal.toLocaleString()}</span>
                                            <span class="result-due">📈 Pending Interest: ₹${loan.pending_interest.toLocaleString()}</span>
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
            }, 300); // 300ms delay
        });

        // Clear search
        function clearSearch() {
            searchInput.value = '';
            clearBtn.style.display = 'none';
            searchResults.classList.remove('show');
            searchStats.textContent = 'Type at least 2 characters to search';
            resultCount.textContent = '';
        }

        // Select loan from search
        function selectLoan(receiptNumber) {
            window.location.href = `loan-collection.php?search=${encodeURIComponent(receiptNumber)}`;
        }

        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            if (!searchInput.contains(event.target) && !searchResults.contains(event.target)) {
                searchResults.classList.remove('show');
            }
        });

        // Toggle bank selection for interest modal
        function toggleBankSelection() {
            const paymentMode = document.getElementById('payment_mode').value;
            const bankSelection = document.getElementById('bank_selection');
            const bankSelect = document.getElementById('bank_account_id');
            
            if (paymentMode === 'bank') {
                bankSelection.classList.add('show');
                bankSelect.setAttribute('required', 'required');
            } else {
                bankSelection.classList.remove('show');
                bankSelect.removeAttribute('required');
            }
        }

        // Toggle bank selection for principal modal
        function togglePrincipalBankSelection() {
            const paymentMode = document.getElementById('principal_payment_mode').value;
            const bankSelection = document.getElementById('principal_bank_selection');
            const bankSelect = document.getElementById('principal_bank_account_id');
            
            if (paymentMode === 'bank') {
                bankSelection.classList.add('show');
                bankSelect.setAttribute('required', 'required');
            } else {
                bankSelection.classList.remove('show');
                bankSelect.removeAttribute('required');
            }
        }

        // Interest Modal Functions
        function showInterestModal() {
            document.getElementById('interestModal').classList.add('active');
            // Reset bank selection
            document.getElementById('payment_mode').value = 'cash';
            document.getElementById('bank_selection').classList.remove('show');
        }

        function hideInterestModal() {
            document.getElementById('interestModal').classList.remove('active');
        }

        // Update totals when values change
        function updateTotals() {
            const interestAmount = parseFloat(document.getElementById('interest_amount').value) || 0;
            const overdueAmount = parseFloat(document.getElementById('overdue_amount')?.value || 0);
            const chargeAmount = parseFloat(document.getElementById('overdue_charge')?.value || 0);
            const totalAmount = interestAmount + overdueAmount + chargeAmount;
            
            // Update display boxes
            document.getElementById('displayInterestAmount').innerHTML = '₹ ' + interestAmount.toFixed(2);
            
            if (document.getElementById('displayOverdueAmount')) {
                document.getElementById('displayOverdueAmount').innerHTML = '₹ ' + overdueAmount.toFixed(2);
            }
            
            document.getElementById('displayChargeAmount').innerHTML = '₹ ' + chargeAmount.toFixed(2);
            document.getElementById('grandTotalAmount').innerHTML = '₹ ' + totalAmount.toFixed(2);
            
            // Update summary
            document.getElementById('summaryInterest').innerHTML = '₹ ' + interestAmount.toFixed(2);
            
            if (document.getElementById('summaryOverdue')) {
                document.getElementById('summaryOverdue').innerHTML = '₹ ' + overdueAmount.toFixed(2);
            }
            
            document.getElementById('summaryCharge').innerHTML = '₹ ' + chargeAmount.toFixed(2);
            document.getElementById('summaryTotal').innerHTML = '₹ ' + totalAmount.toFixed(2);
            
            // Update remaining interest
            const remainingInterest = pendingInterest - interestAmount;
            document.getElementById('remainingInterestAfter').innerHTML = '₹ ' + (remainingInterest > 0 ? remainingInterest.toFixed(2) : '0.00');
        }

        // Confirm collection function with detailed breakdown
        function confirmCollect() {
            const interestAmount = parseFloat(document.getElementById('interest_amount').value) || 0;
            const overdueAmount = parseFloat(document.getElementById('overdue_amount')?.value || 0);
            const chargeAmount = parseFloat(document.getElementById('overdue_charge')?.value || 0);
            const totalAmount = interestAmount + overdueAmount + chargeAmount;
            
            let message = 'Collect payment of ₹' + totalAmount.toFixed(2) + '?\n\n';
            message += '═══════════════════════════\n';
            message += 'BREAKDOWN:\n';
            message += '═══════════════════════════\n';
            message += '• Current Interest: ₹' + interestAmount.toFixed(2) + '\n';
            if (overdueAmount > 0) {
                message += '• Overdue Amount: ₹' + overdueAmount.toFixed(2) + '\n';
            }
            if (chargeAmount > 0) {
                message += '• Overdue Charge: ₹' + chargeAmount.toFixed(2) + '\n';
            }
            message += '───────────────────────────\n';
            message += '• TOTAL: ₹' + totalAmount.toFixed(2) + '\n';
            message += '═══════════════════════════\n';
            message += 'Remaining Interest after payment: ₹' + (pendingInterest - interestAmount).toFixed(2);
            
            return confirm(message);
        }

        // Principal Modal Functions
        function showPrincipalModal() {
            document.getElementById('principalModal').classList.add('active');
            // Reset bank selection
            document.getElementById('principal_payment_mode').value = 'cash';
            document.getElementById('principal_bank_selection').classList.remove('show');
        }

        function hidePrincipalModal() {
            document.getElementById('principalModal').classList.remove('active');
        }

        function updatePrincipalDisplay() {
            const amount = parseFloat(document.getElementById('principal_amount').value) || 0;
            const maxAmount = <?php echo $remaining_principal ?? 0; ?>;
            const remaining = maxAmount - amount;
            
            document.getElementById('principalDisplayAmount').innerHTML = '₹ ' + amount.toFixed(2) + '<small>Payment Amount</small>';
            document.getElementById('principalFinalAmount').innerHTML = '₹ ' + amount.toFixed(2);
            document.getElementById('remainingAfterPayment').innerHTML = '₹ ' + remaining.toFixed(2);
            document.getElementById('currentPrincipalDisplay').innerHTML = '₹ ' + remaining.toFixed(2);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const interestModal = document.getElementById('interestModal');
            const principalModal = document.getElementById('principalModal');
            
            if (event.target == interestModal) {
                hideInterestModal();
            }
            if (event.target == principalModal) {
                hidePrincipalModal();
            }
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