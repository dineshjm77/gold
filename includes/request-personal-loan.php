<?php
session_start();
$currentPage = 'request-personal-loan';
$pageTitle = 'Request Personal Loan';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'sale'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle accept/decline actions
if ($loan_id > 0) {
    if ($action == 'accept') {
        // Get loan details
        $loan_query = "SELECT l.*, c.customer_name, c.email, c.mobile_number, c.id as customer_id
                       FROM loans l 
                       JOIN customers c ON l.customer_id = c.id 
                       WHERE l.id = ?";
        $stmt = mysqli_prepare($conn, $loan_query);
        mysqli_stmt_bind_param($stmt, 'i', $loan_id);
        mysqli_stmt_execute($stmt);
        $loan_result = mysqli_stmt_get_result($stmt);
        $loan = mysqli_fetch_assoc($loan_result);
        
        if ($loan) {
            // Create personal_loan_requests table if not exists
            $create_table = "CREATE TABLE IF NOT EXISTS personal_loan_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                loan_id INT NOT NULL,
                customer_id INT NOT NULL,
                customer_name VARCHAR(150) NOT NULL,
                mobile VARCHAR(15) NOT NULL,
                email VARCHAR(100),
                request_date DATETIME NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                approved_amount DECIMAL(15,2),
                approved_by INT,
                approved_date DATETIME,
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY loan_id (loan_id),
                KEY customer_id (customer_id),
                KEY status (status)
            )";
            mysqli_query($conn, $create_table);
            
            // Insert request
            $insert_query = "INSERT INTO personal_loan_requests (loan_id, customer_id, customer_name, mobile, email, request_date, status) 
                            VALUES (?, ?, ?, ?, ?, NOW(), 'pending')";
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, 'iisss', $loan_id, $loan['customer_id'], $loan['customer_name'], $loan['mobile_number'], $loan['email']);
            
            if (mysqli_stmt_execute($stmt)) {
                // Log in activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'personal_loan_request', ?, 'loans', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $desc = "Personal loan request submitted for loan ID: " . $loan_id;
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $desc, $loan_id);
                mysqli_stmt_execute($log_stmt);
                
                $message = "Your personal loan request has been submitted successfully. An admin will contact you soon.";
            } else {
                $error = "Error submitting request. Please try again.";
            }
        } else {
            $error = "Loan not found.";
        }
    } elseif ($action == 'decline') {
        $message = "You have declined the personal loan offer.";
    }
}

// Get loan details for display
$loan_details = null;
if ($loan_id > 0) {
    $query = "SELECT l.*, c.customer_name, c.mobile_number, c.email,
                     pvs.personal_loan_percentage, pvs.regular_loan_percentage
              FROM loans l
              JOIN customers c ON l.customer_id = c.id
              LEFT JOIN product_value_settings pvs ON pvs.product_type = l.product_type
              WHERE l.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $loan_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $loan_details = mysqli_fetch_assoc($result);
    
    if ($loan_details) {
        $personal_percent = $loan_details['personal_loan_percentage'] ?? 0;
        $personal_amount = ($loan_details['product_value'] * $personal_percent) / 100;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .request-container {
            max-width: 600px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .offer-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }

        .offer-icon {
            font-size: 64px;
            color: #ecc94b;
            margin-bottom: 20px;
        }

        .offer-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .offer-subtitle {
            color: #718096;
            margin-bottom: 25px;
        }

        .amount-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #ecc94b;
            border-radius: 12px;
            padding: 25px;
            margin: 20px 0;
        }

        .amount-label {
            font-size: 14px;
            color: #744210;
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 48px;
            font-weight: 700;
            color: #ecc94b;
        }

        .amount-note {
            font-size: 13px;
            color: #744210;
            margin-top: 5px;
        }

        .loan-details {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 25px 0;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
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

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .btn {
            flex: 1;
            padding: 15px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-accept {
            background: #48bb78;
            color: white;
        }

        .btn-accept:hover {
            background: #38a169;
            transform: translateY(-2px);
        }

        .btn-decline {
            background: #f56565;
            color: white;
        }

        .btn-decline:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .info-box {
            background: #ebf4ff;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            font-size: 13px;
            color: #2c5282;
        }

        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .amount-value {
                font-size: 36px;
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
                <div class="request-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-cash"></i>
                            Personal Loan Request
                        </h1>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-house"></i> Home
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($loan_details && !$action): ?>
                        <!-- Offer Card -->
                        <div class="offer-card">
                            <div class="offer-icon">
                                <i class="bi bi-gift"></i>
                            </div>
                            
                            <h2 class="offer-title">Special Personal Loan Offer!</h2>
                            <p class="offer-subtitle">You're pre-qualified for an additional loan</p>
                            
                            <div class="amount-box">
                                <div class="amount-label">Your Personal Loan Amount</div>
                                <div class="amount-value">₹ <?php echo number_format($personal_amount, 2); ?></div>
                                <div class="amount-note">at same interest rate (<?php echo $loan_details['interest_amount']; ?>%)</div>
                            </div>
                            
                            <div class="loan-details">
                                <h4 style="margin-bottom: 15px;">Loan Details</h4>
                                <div class="detail-row">
                                    <span class="detail-label">Receipt Number:</span>
                                    <span class="detail-value"><?php echo $loan_details['receipt_number']; ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Original Loan:</span>
                                    <span class="detail-value">₹ <?php echo number_format($loan_details['loan_amount'], 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Product Value:</span>
                                    <span class="detail-value">₹ <?php echo number_format($loan_details['product_value'], 2); ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Personal Loan %:</span>
                                    <span class="detail-value"><?php echo $personal_percent; ?>%</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Total Potential:</span>
                                    <span class="detail-value">₹ <?php echo number_format($loan_details['loan_amount'] + $personal_amount, 2); ?></span>
                                </div>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="?loan_id=<?php echo $loan_id; ?>&action=accept" class="btn btn-accept">
                                    <i class="bi bi-check-circle"></i> Accept Offer
                                </a>
                                <a href="?loan_id=<?php echo $loan_id; ?>&action=decline" class="btn btn-decline">
                                    <i class="bi bi-x-circle"></i> Decline
                                </a>
                            </div>
                            
                            <div class="info-box">
                                <i class="bi bi-info-circle me-2"></i>
                                By accepting this offer, you agree to the terms and conditions. 
                                An admin will contact you within 24 hours to process your request.
                            </div>
                        </div>
                    <?php elseif ($action == 'accept' && $message): ?>
                        <!-- Success Message -->
                        <div class="offer-card">
                            <div class="offer-icon" style="color: #48bb78;">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            
                            <h2 class="offer-title" style="color: #48bb78;">Request Submitted!</h2>
                            <p class="offer-subtitle">Your personal loan request has been received</p>
                            
                            <div class="alert alert-success" style="text-align: left;">
                                <strong>Next Steps:</strong>
                                <ol style="margin-top: 10px; margin-left: 20px;">
                                    <li>Admin will review your request</li>
                                    <li>You'll receive a confirmation call within 24 hours</li>
                                    <li>Additional funds will be disbursed after approval</li>
                                </ol>
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <a href="index.php" class="btn btn-accept" style="width: 100%;">
                                    <i class="bi bi-house"></i> Go to Dashboard
                                </a>
                            </div>
                        </div>
                    <?php elseif ($action == 'decline'): ?>
                        <!-- Decline Message -->
                        <div class="offer-card">
                            <div class="offer-icon" style="color: #a0aec0;">
                                <i class="bi bi-x-circle"></i>
                            </div>
                            
                            <h2 class="offer-title">Offer Declined</h2>
                            <p class="offer-subtitle">You have declined the personal loan offer</p>
                            
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                You can still apply for a personal loan later from your loan details page.
                            </div>
                            
                            <div style="margin-top: 20px;">
                                <a href="index.php" class="btn btn-secondary" style="width: 100%;">
                                    <i class="bi bi-house"></i> Return Home
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Invalid Request -->
                        <div class="offer-card">
                            <div class="offer-icon" style="color: #f56565;">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            
                            <h2 class="offer-title">Invalid Request</h2>
                            <p class="offer-subtitle">The loan you're looking for doesn't exist or has expired</p>
                            
                            <div style="margin-top: 20px;">
                                <a href="index.php" class="btn btn-secondary" style="width: 100%;">
                                    <i class="bi bi-house"></i> Go Home
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>