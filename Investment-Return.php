<?php
session_start();
$currentPage = 'investment-return';
$pageTitle = 'Investment Return';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'process_return':
                $investment_id = intval($_POST['investment_id'] ?? 0);
                $return_date = mysqli_real_escape_string($conn, $_POST['return_date'] ?? date('Y-m-d'));
                $return_amount = floatval($_POST['return_amount'] ?? 0);
                $interest_amount = floatval($_POST['interest_amount'] ?? 0);
                $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cash');
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
                
                // Get investment details
                $investment_query = "SELECT * FROM investments WHERE id = ?";
                $stmt = mysqli_prepare($conn, $investment_query);
                mysqli_stmt_bind_param($stmt, 'i', $investment_id);
                mysqli_stmt_execute($stmt);
                $investment_result = mysqli_stmt_get_result($stmt);
                $investment = mysqli_fetch_assoc($investment_result);
                
                if (!$investment) {
                    $error = "Investment not found!";
                    break;
                }
                
                // Calculate total payable
                $total_payable = $return_amount + $interest_amount;
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Check if investment_returns table exists, if not create it
                    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'investment_returns'");
                    if (mysqli_num_rows($table_check) == 0) {
                        $create_table = "CREATE TABLE IF NOT EXISTS `investment_returns` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `investment_return_id` varchar(50) NOT NULL,
                            `return_date` date NOT NULL,
                            `investment_no` int(11) NOT NULL,
                            `investment_date` date NOT NULL,
                            `investor_name` varchar(150) NOT NULL,
                            `investment_type` varchar(150) DEFAULT NULL,
                            `investment_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                            `payable_investment` decimal(15,2) NOT NULL DEFAULT 0.00,
                            `interest_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
                            `payable_interest` decimal(15,2) NOT NULL DEFAULT 0.00,
                            `total_days` int(11) DEFAULT 0,
                            `payment_method` varchar(50) DEFAULT 'cash',
                            `remarks` text DEFAULT NULL,
                            `status` tinyint(1) DEFAULT 1,
                            `created_at` timestamp NULL DEFAULT current_timestamp(),
                            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `investment_return_id` (`investment_return_id`),
                            KEY `investment_no` (`investment_no`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                        mysqli_query($conn, $create_table);
                    }
                    
                    // Generate return receipt number
                    $receipt_query = "SELECT COUNT(*) as count FROM investment_returns WHERE DATE(created_at) = CURDATE()";
                    $receipt_result = mysqli_query($conn, $receipt_query);
                    $receipt_count = mysqli_fetch_assoc($receipt_result)['count'] + 1;
                    $return_receipt = 'RET-' . date('Ymd') . '-' . str_pad($receipt_count, 4, '0', STR_PAD_LEFT);
                    
                    // Calculate days
                    $inv_date = new DateTime($investment['investment_date']);
                    $ret_date = new DateTime($return_date);
                    $days_diff = $inv_date->diff($ret_date)->days;
                    
                    // Insert into investment_returns table
                    $insert_query = "INSERT INTO investment_returns (
                        investment_return_id, return_date, investment_no, investment_date, 
                        investor_name, investment_type, investment_amount, payable_investment,
                        interest_amount, payable_interest, total_days, payment_method, remarks, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'ssissssdddiss', 
                        $return_receipt, $return_date,
                        $investment['investment_no'], $investment['investment_date'],
                        $investment['investor_name'], $investment['investment_type'],
                        $investment['investment_amount'], $return_amount,
                        $investment['interest'], $interest_amount,
                        $days_diff, $payment_method, $remarks
                    );
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error inserting return: " . mysqli_stmt_error($stmt));
                    }
                    
                    // Update investment status to closed/inactive
                    $update_query = "UPDATE investments SET status = 0 WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, 'i', $investment_id);
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error updating investment: " . mysqli_stmt_error($stmt));
                    }
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'investment_return', ?, 'investment_returns', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $log_description = "Investment return processed for " . $investment['investor_name'] . 
                                      " - Amount: ₹" . number_format($total_payable, 2);
                    $last_id = mysqli_insert_id($conn);
                    mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $last_id);
                    mysqli_stmt_execute($log_stmt);
                    
                    mysqli_commit($conn);
                    
                    header('Location: investment-return.php?success=return_processed&receipt=' . urlencode($return_receipt));
                    exit();
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error processing return: " . $e->getMessage();
                }
                break;
        }
    }
}

// Handle AJAX request for investment details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_investment') {
    header('Content-Type: application/json');
    
    $investment_id = intval($_GET['id'] ?? 0);
    $return_date = $_GET['return_date'] ?? date('Y-m-d');
    
    if ($investment_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid investment ID']);
        exit();
    }
    
    $investment_query = "SELECT * FROM investments WHERE id = ? AND status = 1";
    $stmt = mysqli_prepare($conn, $investment_query);
    mysqli_stmt_bind_param($stmt, 'i', $investment_id);
    mysqli_stmt_execute($stmt);
    $investment_result = mysqli_stmt_get_result($stmt);
    $investment = mysqli_fetch_assoc($investment_result);
    
    if ($investment) {
        // Calculate days
        $inv_date = new DateTime($investment['investment_date']);
        $ret_date = new DateTime($return_date);
        $days_diff = $inv_date->diff($ret_date)->days;
        
        // Calculate interest based on days (simple interest)
        // Formula: (Principal × Rate × Days) / (365 × 100)
        $annual_interest_rate = floatval($investment['interest']);
        $calculated_interest = ($investment['investment_amount'] * $annual_interest_rate * $days_diff) / (365 * 100);
        
        // Ensure interest is not negative
        if ($calculated_interest < 0) {
            $calculated_interest = 0;
        }
        
        echo json_encode([
            'success' => true,
            'id' => $investment['id'],
            'investment_no' => $investment['investment_no'],
            'investment_date' => $investment['investment_date'],
            'investment_amount' => floatval($investment['investment_amount']),
            'interest_rate' => floatval($investment['interest']),
            'investor_name' => $investment['investor_name'],
            'investment_type' => $investment['investment_type'],
            'calculated_interest' => round($calculated_interest, 2),
            'days' => $days_diff
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Investment not found or already returned']);
    }
    exit();
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'return_processed':
            $message = "Investment return processed successfully!";
            if (isset($_GET['receipt'])) {
                $message .= " Receipt No: " . htmlspecialchars($_GET['receipt']);
            }
            break;
    }
}

// Get all active investments for dropdown
$investments_query = "SELECT i.*, 
                     DATEDIFF(CURDATE(), i.investment_date) as days_passed
                     FROM investments i 
                     WHERE i.status = 1 
                     ORDER BY i.investment_date DESC";
$investments_result = mysqli_query($conn, $investments_query);

// Get recent returns
$recent_returns_query = "SELECT r.* 
                        FROM investment_returns r
                        ORDER BY r.return_date DESC 
                        LIMIT 20";
$recent_returns_result = mysqli_query($conn, $recent_returns_query);

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_returns,
                    COALESCE(SUM(payable_investment), 0) as total_principal_returned,
                    COALESCE(SUM(payable_interest), 0) as total_interest_paid,
                    COALESCE(SUM(payable_investment + payable_interest), 0) as total_amount_paid
                  FROM investment_returns";
$summary_result = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_result);

// Get active investments summary
$active_summary_query = "SELECT 
                          COUNT(*) as active_count,
                          COALESCE(SUM(investment_amount), 0) as total_active,
                          COALESCE(SUM(interest), 0) as total_interest
                        FROM investments WHERE status = 1";
$active_summary_result = mysqli_query($conn, $active_summary_query);
$active_summary = mysqli_fetch_assoc($active_summary_result);
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

        .return-container {
            max-width: 1200px;
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: #667eea;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
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

        /* Investment Details Section */
        .investment-details {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 25px;
            margin: 25px 0;
        }

        .details-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .detail-box {
            background: white;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
        }

        .detail-value.amount {
            color: #48bb78;
        }

        .detail-value.interest {
            color: #ecc94b;
        }

        .detail-value.days {
            color: #4299e1;
        }

        /* Calculation Box */
        .calc-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .calc-row.total {
            font-weight: 700;
            font-size: 18px;
            border-bottom: none;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #667eea;
        }

        .calc-label {
            color: #4a5568;
        }

        .calc-value {
            font-weight: 600;
            color: #2d3748;
        }

        .calc-value.amount {
            color: #48bb78;
        }

        .calc-value.interest {
            color: #ecc94b;
        }

        .calc-value.total {
            color: #667eea;
        }

        /* Amount Input Row */
        .amount-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }

        /* Payment Options */
        .payment-section {
            margin: 25px 0;
        }

        .payment-title {
            font-size: 16px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 15px;
        }

        .payment-options {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .payment-option label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        /* Recent Returns Table */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .returns-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .returns-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .returns-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .returns-table tbody tr:hover {
            background: #f7fafc;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-cash {
            background: #48bb78;
            color: white;
        }

        .badge-bank {
            background: #4299e1;
            color: white;
        }

        .badge-cheque {
            background: #9f7aea;
            color: white;
        }

        .badge-other {
            background: #a0aec0;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .amount-principal {
            color: #48bb78;
            font-weight: 600;
        }

        .amount-interest {
            color: #ecc94b;
            font-weight: 600;
        }

        .amount-total {
            color: #667eea;
            font-weight: 700;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Debug Info */
        .debug-info {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 10px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            display: none;
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .details-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
            
            .amount-row {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .payment-options {
                flex-direction: column;
                gap: 15px;
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
                <div class="return-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-arrow-return-left"></i>
                            Investment Return
                        </h1>
                        <a href="Investment.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Investments
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Debug Info (Remove in production) -->
                    <div class="debug-info" id="debugInfo"></div>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Active Investments</div>
                                <div class="stat-value"><?php echo number_format($active_summary['active_count'] ?? 0); ?></div>
                                <div class="stat-sub">Total: ₹<?php echo number_format($active_summary['total_active'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Returns</div>
                                <div class="stat-value"><?php echo number_format($summary['total_returns'] ?? 0); ?></div>
                                <div class="stat-sub">Processed returns</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Principal Returned</div>
                                <div class="stat-value">₹<?php echo number_format($summary['total_principal_returned'] ?? 0, 2); ?></div>
                                <div class="stat-sub">Total amount paid</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-percent"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Interest Paid</div>
                                <div class="stat-value">₹<?php echo number_format($summary['total_interest_paid'] ?? 0, 2); ?></div>
                                <div class="stat-sub">Total interest</div>
                            </div>
                        </div>
                    </div>

                    <!-- Return Form Card -->
                    <div class="form-card">
                        <h2 class="form-title">
                            <i class="bi bi-calculator"></i>
                            Process Investment Return
                        </h2>

                        <form method="POST" action="" id="returnForm">
                            <input type="hidden" name="action" value="process_return">
                            
                            <div class="form-group">
                                <label class="form-label required">Select Investment</label>
                                <select class="form-select" id="investmentSelect" name="investment_id" required>
                                    <option value="">-- Select an investment --</option>
                                    <?php 
                                    if ($investments_result && mysqli_num_rows($investments_result) > 0) {
                                        mysqli_data_seek($investments_result, 0);
                                        while($inv = mysqli_fetch_assoc($investments_result)): 
                                    ?>
                                        <option value="<?php echo $inv['id']; ?>">
                                            #<?php echo $inv['investment_no']; ?> - <?php echo htmlspecialchars($inv['investor_name']); ?> (₹<?php echo number_format($inv['investment_amount'], 0); ?>)
                                        </option>
                                    <?php 
                                        endwhile;
                                    } else {
                                        echo '<option value="">No active investments found</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Return Date</label>
                                <input type="date" class="form-control" name="return_date" id="returnDate" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <!-- Loading Spinner -->
                            <div class="loading-spinner" id="loadingSpinner">
                                <div class="spinner"></div>
                                <p>Loading investment details...</p>
                            </div>

                            <!-- Investment Details Section (Hidden initially) -->
                            <div id="investmentDetailsSection" style="display: none;">
                                <div class="investment-details">
                                    <h3 class="details-title">
                                        <i class="bi bi-info-circle"></i>
                                        Investment Details
                                    </h3>
                                    
                                    <div class="details-grid">
                                        <div class="detail-box">
                                            <div class="detail-label">Investment No</div>
                                            <div class="detail-value" id="displayInvNo">-</div>
                                        </div>
                                        <div class="detail-box">
                                            <div class="detail-label">Investor Name</div>
                                            <div class="detail-value" id="displayInvName">-</div>
                                        </div>
                                        <div class="detail-box">
                                            <div class="detail-label">Investment Date</div>
                                            <div class="detail-value" id="displayInvDate">-</div>
                                        </div>
                                        <div class="detail-box">
                                            <div class="detail-label">Investment Amount</div>
                                            <div class="detail-value amount" id="displayInvAmount">₹0.00</div>
                                        </div>
                                        <div class="detail-box">
                                            <div class="detail-label">Interest Rate (%)</div>
                                            <div class="detail-value interest" id="displayInvRate">0%</div>
                                        </div>
                                        <div class="detail-box">
                                            <div class="detail-label">Days Held</div>
                                            <div class="detail-value days" id="displayDays">0</div>
                                        </div>
                                    </div>

                                    <!-- Calculation Box -->
                                    <div class="calc-box">
                                        <div class="calc-row">
                                            <span class="calc-label">Principal Amount:</span>
                                            <span class="calc-value amount" id="calcPrincipal">₹0.00</span>
                                        </div>
                                        <div class="calc-row">
                                            <span class="calc-label">Calculated Interest:</span>
                                            <span class="calc-value interest" id="calcInterest">₹0.00</span>
                                        </div>
                                        <div class="calc-row total">
                                            <span class="calc-label">Total Payable:</span>
                                            <span class="calc-value total" id="calcTotal">₹0.00</span>
                                        </div>
                                    </div>

                                    <!-- Amount Input Row -->
                                    <div class="amount-row">
                                        <div class="form-group">
                                            <label class="form-label required">Return Amount (Principal)</label>
                                            <input type="number" class="form-control" name="return_amount" id="returnAmount" step="0.01" min="0" value="0" required oninput="calculateTotal()">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label required">Interest Amount</label>
                                            <input type="number" class="form-control" name="interest_amount" id="interestAmount" step="0.01" min="0" value="0" required oninput="calculateTotal()">
                                        </div>
                                    </div>

                                    <!-- Payment Section -->
                                    <div class="payment-section">
                                        <div class="payment-title">Payment Method</div>
                                        <div class="payment-options">
                                            <div class="payment-option">
                                                <input type="radio" name="payment_method" id="paymentCash" value="cash" checked>
                                                <label for="paymentCash">Cash</label>
                                            </div>
                                            <div class="payment-option">
                                                <input type="radio" name="payment_method" id="paymentBank" value="bank">
                                                <label for="paymentBank">Bank Transfer</label>
                                            </div>
                                            <div class="payment-option">
                                                <input type="radio" name="payment_method" id="paymentCheque" value="cheque">
                                                <label for="paymentCheque">Cheque</label>
                                            </div>
                                            <div class="payment-option">
                                                <input type="radio" name="payment_method" id="paymentOther" value="other">
                                                <label for="paymentOther">Other</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Remarks (Optional)</label>
                                        <textarea class="form-control" name="remarks" rows="3" placeholder="Add any notes about this return..."></textarea>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                                        <i class="bi bi-x-circle"></i> Clear
                                    </button>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Process this investment return? This will mark the investment as completed.')">
                                        <i class="bi bi-check-circle"></i> Process Return
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Recent Returns Table -->
                    <?php if ($recent_returns_result && mysqli_num_rows($recent_returns_result) > 0): ?>
                    <div class="table-card">
                        <h3 class="table-title">
                            <i class="bi bi-clock-history"></i>
                            Recent Returns
                        </h3>
                        <div class="table-responsive">
                            <table class="returns-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt No</th>
                                        <th>Investor</th>
                                        <th>Inv No</th>
                                        <th class="text-right">Principal</th>
                                        <th class="text-right">Interest</th>
                                        <th class="text-right">Total</th>
                                        <th>Payment Mode</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($return = mysqli_fetch_assoc($recent_returns_result)): ?>
                                    <tr>
                                        <td><?php echo date('d-m-Y', strtotime($return['return_date'])); ?></td>
                                        <td><strong><?php echo $return['investment_return_id']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($return['investor_name']); ?></td>
                                        <td>#<?php echo $return['investment_no']; ?></td>
                                        <td class="text-right amount-principal">₹<?php echo number_format($return['payable_investment'], 2); ?></td>
                                        <td class="text-right amount-interest">₹<?php echo number_format($return['payable_interest'], 2); ?></td>
                                        <td class="text-right amount-total">₹<?php echo number_format($return['payable_investment'] + $return['payable_interest'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $return['payment_method']; ?>">
                                                <?php echo strtoupper($return['payment_method']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include flatpickr -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Get the base URL for AJAX requests
        const currentUrl = window.location.pathname;
        const baseUrl = currentUrl.substring(0, currentUrl.lastIndexOf('/') + 1);
        
        // Initialize date picker
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Handle investment selection
        document.getElementById('investmentSelect').addEventListener('change', function() {
            var investmentId = this.value;
            var returnDate = document.getElementById('returnDate').value;
            
            if (investmentId) {
                // Hide details section and show loading
                document.getElementById('investmentDetailsSection').style.display = 'none';
                document.getElementById('loadingSpinner').style.display = 'block';
                
                // Build the AJAX URL
                var ajaxUrl = window.location.href.split('?')[0] + '?ajax=get_investment&id=' + investmentId + '&return_date=' + returnDate;
                
                // Show debug info
                document.getElementById('debugInfo').style.display = 'block';
                document.getElementById('debugInfo').innerHTML = 'Fetching: ' + ajaxUrl;
                
                // Fetch investment details
                fetch(ajaxUrl)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('HTTP error! status: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        document.getElementById('loadingSpinner').style.display = 'none';
                        
                        if (data.success) {
                            // Display investment details
                            document.getElementById('displayInvNo').textContent = data.investment_no;
                            document.getElementById('displayInvName').textContent = data.investor_name;
                            document.getElementById('displayInvDate').textContent = formatDate(data.investment_date);
                            document.getElementById('displayInvAmount').innerHTML = '₹' + formatNumber(data.investment_amount);
                            document.getElementById('displayInvRate').textContent = data.interest_rate + '%';
                            document.getElementById('displayDays').textContent = data.days;
                            
                            // Set form values
                            document.getElementById('returnAmount').value = data.investment_amount.toFixed(2);
                            document.getElementById('interestAmount').value = data.calculated_interest.toFixed(2);
                            
                            // Calculate and show details
                            calculateTotal();
                            
                            // Show details section
                            document.getElementById('investmentDetailsSection').style.display = 'block';
                            
                            // Hide debug info on success
                            document.getElementById('debugInfo').style.display = 'none';
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        document.getElementById('loadingSpinner').style.display = 'none';
                        document.getElementById('debugInfo').innerHTML += '<br>Error: ' + error.message;
                        alert('Error fetching investment details: ' + error.message);
                        console.error(error);
                    });
            } else {
                document.getElementById('investmentDetailsSection').style.display = 'none';
            }
        });

        // Calculate total amount
        function calculateTotal() {
            var principal = parseFloat(document.getElementById('returnAmount').value) || 0;
            var interest = parseFloat(document.getElementById('interestAmount').value) || 0;
            var total = principal + interest;
            
            document.getElementById('calcPrincipal').innerHTML = '₹' + formatNumber(principal);
            document.getElementById('calcInterest').innerHTML = '₹' + formatNumber(interest);
            document.getElementById('calcTotal').innerHTML = '₹' + formatNumber(total);
        }

        // Format number with commas
        function formatNumber(num) {
            return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Format date
        function formatDate(dateString) {
            var date = new Date(dateString);
            var day = String(date.getDate()).padStart(2, '0');
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var year = date.getFullYear();
            return day + '-' + month + '-' + year;
        }

        // Reset form
        function resetForm() {
            document.getElementById('investmentSelect').value = '';
            document.getElementById('returnDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('investmentDetailsSection').style.display = 'none';
            document.getElementById('returnAmount').value = '';
            document.getElementById('interestAmount').value = '';
            document.querySelector('textarea[name="remarks"]').value = '';
            document.getElementById('paymentCash').checked = true;
        }

        // Handle return date change
        document.getElementById('returnDate').addEventListener('change', function() {
            var investmentId = document.getElementById('investmentSelect').value;
            if (investmentId) {
                // Trigger investment selection change to recalculate
                var event = new Event('change');
                document.getElementById('investmentSelect').dispatchEvent(event);
            }
        });

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