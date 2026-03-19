<?php
session_start();
$currentPage = 'close-personal-loan';
$pageTitle = 'Close Personal Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get loan ID from URL
$loan_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($loan_id <= 0) {
    header('Location: personal-loans.php');
    exit();
}

// Include EMI generator if exists
if (file_exists('includes/emi_generator.php')) {
    require_once 'includes/emi_generator.php';
}

// Simple fallback function if emi_generator.php doesn't have it
if (!function_exists('getEMIStats')) {
    function getEMIStats($loan_id, $loan_type, $conn) {
        return [
            'total_emis' => 0,
            'paid_emis' => 0,
            'unpaid_emis' => 0,
            'overdue_emis' => 0,
            'total_paid' => 0,
            'total_pending' => 0
        ];
    }
}

// Get loan details with customer information
$query = "SELECT pl.*, 
                 c.customer_name, c.mobile_number, c.email, c.customer_photo,
                 c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode,
                 u.name as employee_name
          FROM personal_loans pl
          LEFT JOIN customers c ON pl.customer_id = c.id
          LEFT JOIN users u ON pl.employee_id = u.id
          WHERE pl.id = ?";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
} else {
    die("Database error: " . mysqli_error($conn));
}

if (!$loan) {
    header('Location: personal-loans.php?error=not_found');
    exit();
}

// Check if loan is already closed
if ($loan['status'] == 'closed') {
    header('Location: view-personal-loan.php?id=' . $loan_id . '&error=already_closed');
    exit();
}

// Get EMI statistics
$emi_stats = [
    'total_paid' => 0,
    'total_emis' => 0,
    'paid_emis' => 0,
    'unpaid_emis' => 0,
    'overdue_emis' => 0,
    'total_pending' => 0
];

if (function_exists('getEMIStats')) {
    $emi_stats = getEMIStats($loan_id, 'personal', $conn);
}

// Check if emi_schedules table exists before querying
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'emi_schedules'");
$unpaid_emis = [];
$total_unpaid = 0;
$total_penalty = 0;

if ($table_check && mysqli_num_rows($table_check) > 0) {
    // Get all unpaid EMIs
    $unpaid_query = "SELECT * FROM emi_schedules 
                     WHERE loan_id = ? AND loan_type = 'personal' 
                     AND status IN ('unpaid', 'overdue')
                     ORDER BY installment_no ASC";
    $unpaid_stmt = mysqli_prepare($conn, $unpaid_query);
    if ($unpaid_stmt) {
        mysqli_stmt_bind_param($unpaid_stmt, 'i', $loan_id);
        mysqli_stmt_execute($unpaid_stmt);
        $unpaid_result = mysqli_stmt_get_result($unpaid_stmt);
        
        while ($row = mysqli_fetch_assoc($unpaid_result)) {
            $unpaid_emis[] = $row;
            $balance = $row['total_amount'] - ($row['paid_amount'] ?? 0);
            $total_unpaid += $balance;
            
            // Calculate penalty for overdue EMIs (example: 2% per month)
            if ($row['status'] == 'overdue') {
                $overdue_days = isset($row['overdue_days']) ? intval($row['overdue_days']) : 30;
                $penalty_rate = 0.02; // 2% penalty
                $penalty = $balance * $penalty_rate * max(1, ceil($overdue_days / 30));
                $total_penalty += $penalty;
            }
        }
        mysqli_stmt_close($unpaid_stmt);
    }
}

// Calculate settlement amount
$principal_paid = isset($emi_stats['total_paid']) ? floatval($emi_stats['total_paid']) : 0;
$principal_remaining = floatval($loan['loan_amount']) - $principal_paid;
$process_charge = floatval($loan['process_charge'] ?? 0);
$appraisal_charge = floatval($loan['appraisal_charge'] ?? 0);
$settlement_amount = $principal_remaining + $total_unpaid + $total_penalty + $process_charge + $appraisal_charge;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_loan'])) {
    $close_date = mysqli_real_escape_string($conn, $_POST['close_date'] ?? date('Y-m-d'));
    $settlement_received = floatval($_POST['settlement_received'] ?? 0);
    $discount_amount = floatval($_POST['discount_amount'] ?? 0);
    $waive_penalty = isset($_POST['waive_penalty']) ? 1 : 0;
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
    $transaction_id = mysqli_real_escape_string($conn, $_POST['transaction_id'] ?? '');
    $close_remarks = mysqli_real_escape_string($conn, $_POST['close_remarks'] ?? '');
    
    // Calculate final amount
    $final_penalty = $waive_penalty ? 0 : $total_penalty;
    $final_settlement = $principal_remaining + $total_unpaid + $final_penalty + $process_charge + $appraisal_charge - $discount_amount;
    
    // Validate
    $errors = [];
    if ($settlement_received <= 0) {
        $errors[] = "Settlement amount must be greater than 0";
    }
    if ($settlement_received > $final_settlement + 1) { // Allow small rounding difference
        $errors[] = "Received amount cannot exceed settlement amount";
    }
    
    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        
        try {
            // Generate closure receipt number
            $receipt_number = 'CLS' . date('ymd') . str_pad($loan_id, 4, '0', STR_PAD_LEFT);
            
            // Check if loan_closures table exists
            $check_table = mysqli_query($conn, "SHOW TABLES LIKE 'loan_closures'");
            if (mysqli_num_rows($check_table) == 0) {
                // Create table if not exists
                $create_table = "CREATE TABLE loan_closures (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    loan_id INT NOT NULL,
                    loan_type VARCHAR(50) DEFAULT 'personal',
                    closure_date DATE NOT NULL,
                    principal_remaining DECIMAL(15,2) NOT NULL,
                    unpaid_emi_amount DECIMAL(15,2) NOT NULL,
                    penalty_amount DECIMAL(10,2) DEFAULT 0,
                    discount_amount DECIMAL(10,2) DEFAULT 0,
                    process_charge DECIMAL(10,2) DEFAULT 0,
                    appraisal_charge DECIMAL(10,2) DEFAULT 0,
                    settlement_amount DECIMAL(15,2) NOT NULL,
                    amount_received DECIMAL(15,2) NOT NULL,
                    payment_method VARCHAR(50) DEFAULT 'cash',
                    transaction_id VARCHAR(100),
                    receipt_number VARCHAR(50) NOT NULL,
                    remarks TEXT,
                    closed_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    KEY loan_id (loan_id)
                )";
                mysqli_query($conn, $create_table);
            }
            
            // Insert closure record
            $closure_query = "INSERT INTO loan_closures (
                loan_id, loan_type, closure_date, principal_remaining,
                unpaid_emi_amount, penalty_amount, discount_amount,
                process_charge, appraisal_charge, settlement_amount,
                amount_received, payment_method, transaction_id,
                remarks, closed_by, receipt_number
            ) VALUES (?, 'personal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $closure_stmt = mysqli_prepare($conn, $closure_query);
            if ($closure_stmt) {
                // FIXED: Type string now has 15 characters to match 15 variables
                mysqli_stmt_bind_param($closure_stmt, 'isddddddddsssis', 
                    $loan_id,                    // i - integer
                    $close_date,                  // s - string
                    $principal_remaining,         // d - double
                    $total_unpaid,                 // d - double
                    $final_penalty,                // d - double
                    $discount_amount,              // d - double
                    $process_charge,               // d - double
                    $appraisal_charge,             // d - double
                    $final_settlement,             // d - double
                    $settlement_received,          // d - double
                    $payment_method,               // s - string
                    $transaction_id,               // s - string
                    $close_remarks,                // s - string
                    $_SESSION['user_id'],          // i - integer
                    $receipt_number                // s - string
                );
                mysqli_stmt_execute($closure_stmt);
                mysqli_stmt_close($closure_stmt);
            }
            
            // Update loan status
            $update_query = "UPDATE personal_loans 
                            SET status = 'closed', 
                                updated_at = NOW()
                            WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, 'i', $loan_id);
                mysqli_stmt_execute($update_stmt);
                mysqli_stmt_close($update_stmt);
            }
            
            // Update all unpaid EMIs to closed if table exists
            if ($table_check && mysqli_num_rows($table_check) > 0) {
                $update_emis = "UPDATE emi_schedules 
                               SET status = 'closed' 
                               WHERE loan_id = ? AND loan_type = 'personal' 
                               AND status IN ('unpaid', 'overdue')";
                $update_emis_stmt = mysqli_prepare($conn, $update_emis);
                if ($update_emis_stmt) {
                    mysqli_stmt_bind_param($update_emis_stmt, 'i', $loan_id);
                    mysqli_stmt_execute($update_emis_stmt);
                    mysqli_stmt_close($update_emis_stmt);
                }
            }
            
            // Log activity - check if activity_log table exists
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'close', ?, 'personal_loans', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            if ($log_stmt) {
                $log_description = "Personal loan closed: " . $loan['receipt_number'] . " with settlement ₹" . number_format($settlement_received, 2);
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $loan_id);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);
            }
            
            mysqli_commit($conn);
            
            $_SESSION['success_message'] = "Loan closed successfully! Receipt #: " . $receipt_number;
            header('Location: view-personal-loan.php?id=' . $loan_id . '&success=closed');
            exit();
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Error closing loan: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Format address
$address_parts = array_filter([
    $loan['door_no'] ?? '',
    $loan['house_name'] ?? '',
    $loan['street_name'] ?? '',
    $loan['location'] ?? '',
    $loan['district'] ?? ''
]);
$customer_address = !empty($address_parts) ? implode(', ', $address_parts) : 'Address not available';
if (!empty($loan['pincode'])) {
    $customer_address .= ' - ' . $loan['pincode'];
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

        .container {
            max-width: 100%;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
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

        .form-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102,126,234,0.1);
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

        .customer-info-card {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .customer-photo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 600;
        }

        .customer-details {
            flex: 1;
        }

        .customer-name {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .customer-contact {
            display: flex;
            gap: 20px;
            color: #4a5568;
            font-size: 14px;
            flex-wrap: wrap;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-item {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
        }

        .summary-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .summary-value.positive {
            color: #48bb78;
        }

        .summary-value.negative {
            color: #f56565;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            overflow-x: auto;
            display: block;
        }

        .details-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .details-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .details-table tr.overdue {
            background: #fff5f5;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-unpaid {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-overdue {
            background: #feebc8;
            color: #744210;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }

        .checkbox-group input {
            width: 18px;
            height: 18px;
            accent-color: #48bb78;
        }

        .settlement-box {
            background: #ebf4ff;
            border-radius: 12px;
            padding: 25px;
            margin: 20px 0;
            border: 2px solid #4299e1;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            border-left: 5px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .customer-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                <div class="container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-check-circle"></i>
                            Close Personal Loan
                            <span class="status-badge status-active">ACTIVE</span>
                        </h1>
                        <div>
                            <a href="view-personal-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Loan
                            </a>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Customer Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-person"></i>
                            Customer Information
                        </div>

                        <div class="customer-info-card">
                            <div class="customer-photo-placeholder">
                                <?php echo strtoupper(substr($loan['customer_name'] ?? 'C', 0, 1)); ?>
                            </div>
                            <div class="customer-details">
                                <div class="customer-name"><?php echo htmlspecialchars($loan['customer_name'] ?? 'N/A'); ?></div>
                                <div class="customer-contact">
                                    <span><i class="bi bi-phone"></i> <?php echo $loan['mobile_number'] ?? 'N/A'; ?></span>
                                    <span><i class="bi bi-envelope"></i> <?php echo $loan['email'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="customer-contact">
                                    <span><i class="bi bi-receipt"></i> Loan Receipt: <?php echo $loan['receipt_number'] ?? 'N/A'; ?></span>
                                    <span><i class="bi bi-calendar"></i> Loan Date: <?php echo isset($loan['receipt_date']) ? date('d-m-Y', strtotime($loan['receipt_date'])) : 'N/A'; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Loan Summary -->
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Original Loan Amount</div>
                                <div class="summary-value">₹<?php echo number_format(floatval($loan['loan_amount'] ?? 0), 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Paid</div>
                                <div class="summary-value positive">₹<?php echo number_format($principal_paid, 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Principal Remaining</div>
                                <div class="summary-value negative">₹<?php echo number_format($principal_remaining, 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Unpaid EMIs</div>
                                <div class="summary-value"><?php echo count($unpaid_emis); ?></div>
                            </div>
                        </div>

                        <!-- Settlement Calculation -->
                        <div class="settlement-box">
                            <h4 style="color: #2c5282; margin-bottom: 20px;">Settlement Calculation</h4>
                            
                            <div class="summary-grid">
                                <div class="summary-item" style="background: white;">
                                    <div class="summary-label">Principal Remaining</div>
                                    <div class="summary-value">₹<?php echo number_format($principal_remaining, 2); ?></div>
                                </div>
                                <div class="summary-item" style="background: white;">
                                    <div class="summary-label">Unpaid EMIs</div>
                                    <div class="summary-value">₹<?php echo number_format($total_unpaid, 2); ?></div>
                                </div>
                                <div class="summary-item" style="background: white;">
                                    <div class="summary-label">Penalty</div>
                                    <div class="summary-value">₹<?php echo number_format($total_penalty, 2); ?></div>
                                </div>
                                <div class="summary-item" style="background: white;">
                                    <div class="summary-label">Process Charge</div>
                                    <div class="summary-value">₹<?php echo number_format($process_charge, 2); ?></div>
                                </div>
                                <div class="summary-item" style="background: white;">
                                    <div class="summary-label">Appraisal Charge</div>
                                    <div class="summary-value">₹<?php echo number_format($appraisal_charge, 2); ?></div>
                                </div>
                                <div class="summary-item" style="background: #48bb7810;">
                                    <div class="summary-label">Total Settlement</div>
                                    <div class="summary-value positive">₹<?php echo number_format($settlement_amount, 2); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Close Loan Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="close_loan" value="1">
                            
                            <div class="section-title">
                                <i class="bi bi-file-text"></i>
                                Closure Details
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Close Date</label>
                                    <input type="date" class="form-control" name="close_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Settlement Received (₹)</label>
                                    <input type="number" class="form-control" name="settlement_received" id="settlement_received" 
                                           value="<?php echo $settlement_amount; ?>" step="0.01" min="0" required
                                           oninput="calculateFinal()">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Discount (₹)</label>
                                    <input type="number" class="form-control" name="discount_amount" id="discount_amount" 
                                           value="0" step="0.01" min="0" oninput="calculateFinal()">
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Payment Method</label>
                                    <select class="form-select" name="payment_method" required>
                                        <option value="cash">Cash</option>
                                        <option value="bank">Bank Transfer</option>
                                        <option value="upi">UPI</option>
                                        <option value="cheque">Cheque</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Transaction ID / Reference</label>
                                    <input type="text" class="form-control" name="transaction_id" placeholder="Enter transaction ID if applicable">
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" name="waive_penalty" id="waive_penalty" value="1" onchange="calculateFinal()">
                                <label for="waive_penalty">Waive all penalty charges</label>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Closure Remarks</label>
                                <textarea class="form-control" name="close_remarks" rows="3" placeholder="Add any remarks about loan closure..."></textarea>
                            </div>

                            <!-- Final Settlement Display -->
                            <div style="background: #48bb7810; border-radius: 12px; padding: 20px; margin: 20px 0; text-align: center;">
                                <div style="font-size: 14px; color: #22543d; margin-bottom: 5px;">Final Settlement Amount</div>
                                <div style="font-size: 42px; font-weight: 700; color: #48bb78;" id="final_amount">
                                    ₹<?php echo number_format($settlement_amount, 2); ?>
                                </div>
                            </div>

                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Warning:</strong> Closing a loan is irreversible. All unpaid EMIs will be marked as closed.
                                Please verify all calculations before proceeding.
                            </div>

                            <div class="action-buttons">
                                <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to close this loan? This action cannot be undone.')">
                                    <i class="bi bi-check-circle"></i> Confirm Loan Closure
                                </button>
                                <a href="view-personal-loan.php?id=<?php echo $loan_id; ?>" class="btn btn-secondary">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        function calculateFinal() {
            const baseAmount = <?php echo $settlement_amount; ?>;
            const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
            const waivePenalty = document.getElementById('waive_penalty').checked;
            
            let finalAmount = baseAmount - discount;
            
            if (waivePenalty) {
                finalAmount -= <?php echo $total_penalty; ?>;
            }
            
            if (finalAmount < 0) finalAmount = 0;
            
            document.getElementById('final_amount').innerHTML = '₹' + finalAmount.toFixed(2);
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>