<?php
session_start();
$pageTitle = 'Accept Personal Loan Offer';
require_once 'includes/db.php';
require_once 'includes/email_personal_helper.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';
$success = false;

// Get loan ID from URL
$loan_id = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;

if ($loan_id <= 0) {
    header('Location: index.php');
    exit();
}

// Get loan details with customer information
$query = "SELECT l.*, c.customer_name, c.email, c.mobile_number, c.id as customer_id,
                 c.door_no, c.house_name, c.street_name, c.location, c.district, c.pincode
          FROM loans l
          JOIN customers c ON l.customer_id = c.id
          WHERE l.id = ?";

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 'i', $loan_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$loan = mysqli_fetch_assoc($result);

if (!$loan) {
    header('Location: index.php?error=not_found');
    exit();
}

// Get product value settings
$product_type = 'தங்கம்'; // Default to gold
$settings_query = "SELECT * FROM product_value_settings WHERE product_type = ? LIMIT 1";
$settings_stmt = mysqli_prepare($conn, $settings_query);
mysqli_stmt_bind_param($settings_stmt, 's', $product_type);
mysqli_stmt_execute($settings_stmt);
$settings_result = mysqli_stmt_get_result($settings_stmt);
$settings = mysqli_fetch_assoc($settings_result);

$personal_percent = isset($settings['personal_loan_percentage']) ? floatval($settings['personal_loan_percentage']) : 20;
$regular_percent = isset($settings['regular_loan_percentage']) ? floatval($settings['regular_loan_percentage']) : 70;

// Calculate amounts
$product_value = floatval($loan['product_value']);
$personal_amount = ($product_value * $personal_percent) / 100;
$regular_amount = floatval($loan['loan_amount']);
$total_amount = $regular_amount + $personal_amount;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? $loan['customer_name']));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? $loan['email']));
    $mobile = mysqli_real_escape_string($conn, trim($_POST['mobile'] ?? $loan['mobile_number']));
    $accept_terms = isset($_POST['accept_terms']) ? 1 : 0;
    $customer_notes = mysqli_real_escape_string($conn, trim($_POST['customer_notes'] ?? ''));
    
    $errors = [];
    
    if (empty($name)) $errors[] = "Name is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (empty($mobile)) $errors[] = "Mobile number is required";
    if (!preg_match('/^[0-9]{10}$/', $mobile)) $errors[] = "Mobile number must be 10 digits";
    if (!$accept_terms) $errors[] = "You must accept the terms and conditions";
    
    if (empty($errors)) {
        // Check if table exists
        $table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'personal_loan_requests'");
        
        if (mysqli_num_rows($table_exists) == 0) {
            // Create table with all fields
            $create_table = "CREATE TABLE personal_loan_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                loan_id INT NOT NULL,
                customer_id INT NOT NULL,
                customer_name VARCHAR(150) NOT NULL,
                mobile VARCHAR(15) NOT NULL,
                email VARCHAR(100),
                requested_amount DECIMAL(15,2) NOT NULL,
                admin_modified_amount DECIMAL(15,2) DEFAULT NULL,
                regular_loan_amount DECIMAL(15,2) NOT NULL,
                personal_loan_amount DECIMAL(15,2) NOT NULL,
                product_value DECIMAL(15,2) NOT NULL,
                interest_amount DECIMAL(5,2) DEFAULT NULL,
                admin_modified_interest DECIMAL(5,2) DEFAULT NULL,
                tenure_months INT DEFAULT 12,
                admin_modified_tenure INT DEFAULT NULL,
                customer_notes TEXT,
                request_date DATETIME NOT NULL,
                status ENUM('pending','approved','rejected','cancelled','completed') DEFAULT 'pending',
                approved_amount DECIMAL(15,2) DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                approved_date DATETIME DEFAULT NULL,
                rejection_reason TEXT DEFAULT NULL,
                admin_remarks TEXT DEFAULT NULL,
                admin_notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY loan_id (loan_id),
                KEY customer_id (customer_id),
                KEY status (status)
            )";
            mysqli_query($conn, $create_table);
        }
        
        // Check if already requested
        $check_query = "SELECT id, status FROM personal_loan_requests 
                        WHERE loan_id = ? AND customer_id = ? AND status IN ('pending', 'approved')";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 'ii', $loan_id, $loan['customer_id']);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $existing = mysqli_fetch_assoc($check_result);
            if ($existing['status'] == 'pending') {
                $error = "You already have a pending request for this loan. Please wait for admin approval.";
            } else {
                $error = "You already have an approved request for this loan. Please contact the branch to proceed.";
            }
        } else {
            // Insert request
            $insert_query = "INSERT INTO personal_loan_requests 
                            (loan_id, customer_id, customer_name, mobile, email, 
                             requested_amount, regular_loan_amount, personal_loan_amount, 
                             product_value, interest_amount, tenure_months, customer_notes,
                             request_date, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            if (!$insert_stmt) {
                $error = "Database error: " . mysqli_error($conn);
            } else {
                $interest_amount = $loan['interest_amount'] ?? 2.00;
                $tenure_months = 12; // Default tenure
                
                mysqli_stmt_bind_param($insert_stmt, 'iisssdddddss', 
                    $loan_id, 
                    $loan['customer_id'], 
                    $name, 
                    $mobile, 
                    $email, 
                    $personal_amount,
                    $regular_amount,
                    $personal_amount,
                    $product_value,
                    $interest_amount,
                    $tenure_months,
                    $customer_notes
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $request_id = mysqli_insert_id($conn);
                    
                    // Send confirmation email to customer
                    if (function_exists('sendPersonalLoanRequestConfirmation')) {
                        sendPersonalLoanRequestConfirmation($request_id, $conn);
                    }
                    
                    // Notify admin about new request (you can add email notification here)
                    
                    $success = true;
                    $message = "Your personal loan request has been submitted successfully! An admin will review your request and contact you within 24-48 hours.";
                } else {
                    $error = "Error submitting request: " . mysqli_error($conn);
                }
            }
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Personal Loan</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            width: 100%;
        }

        .offer-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
            overflow: hidden;
        }

        .offer-card::before {
            content: "💰";
            position: absolute;
            right: -20px;
            top: -20px;
            font-size: 120px;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .success-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: #48bb78;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
        }

        .pending-icon {
            width: 80px;
            height: 80px;
            background: #ecc94b;
            color: #744210;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
        }

        .offer-badge {
            background: linear-gradient(135deg, #ecc94b 0%, #fbbf24 100%);
            color: #744210;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 28px;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #718096;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .amount-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 2px solid #ecc94b;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }

        .amount-label {
            font-size: 14px;
            color: #744210;
            margin-bottom: 5px;
        }

        .amount-value {
            font-size: 42px;
            font-weight: 700;
            color: #ecc94b;
            line-height: 1.2;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 25px 0;
            padding: 20px;
            background: #f7fafc;
            border-radius: 12px;
        }

        .info-item {
            text-align: center;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 20px;
            font-weight: 700;
        }

        .info-value.regular {
            color: #48bb78;
        }

        .info-value.personal {
            color: #ecc94b;
        }

        .info-value.total {
            color: #667eea;
        }

        .loan-details {
            background: #ebf4ff;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .loan-details h3 {
            color: #2c5282;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #cbd5e0;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .form-control[readonly] {
            background: #f7fafc;
            cursor: not-allowed;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .terms-check {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0;
        }

        .terms-check input {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            accent-color: #48bb78;
        }

        .terms-check label {
            font-size: 14px;
            color: #4a5568;
            flex: 1;
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(72,187,120,0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #f56565;
        }

        .alert-info {
            background: #bee3f8;
            color: #2c5282;
            border-left: 4px solid #4299e1;
        }

        .process-steps {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            position: relative;
        }

        .process-steps::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 50px;
            right: 50px;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }

        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
        }

        .step-icon {
            width: 30px;
            height: 30px;
            background: white;
            border: 2px solid #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 5px;
            color: #667eea;
            font-size: 14px;
        }

        .step.active .step-icon {
            background: #667eea;
            color: white;
        }

        .step-label {
            font-size: 11px;
            color: #718096;
        }

        @media (max-width: 480px) {
            .offer-card {
                padding: 25px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .amount-value {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <!-- Success Message -->
            <div class="success-card">
                <div class="pending-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <h1>Request Submitted!</h1>
                <p style="color: #718096; margin: 15px 0;"><?php echo $message; ?></p>
                
                <div class="loan-details">
                    <h3>Request Details</h3>
                    <div class="detail-row">
                        <span>Loan Receipt:</span>
                        <span><strong><?php echo $loan['receipt_number']; ?></strong></span>
                    </div>
                    <div class="detail-row">
                        <span>Requested Amount:</span>
                        <span><strong style="color: #ecc94b;">₹<?php echo number_format($personal_amount, 2); ?></strong></span>
                    </div>
                    <div class="detail-row">
                        <span>Status:</span>
                        <span><span style="background: #ecc94b; color: #744210; padding: 4px 12px; border-radius: 20px; font-size: 12px;">Pending Admin Review</span></span>
                    </div>
                </div>
                
                <!-- Process Steps -->
                <div class="process-steps">
                    <div class="step active">
                        <div class="step-icon">1</div>
                        <div class="step-label">Request Sent</div>
                    </div>
                    <div class="step">
                        <div class="step-icon">2</div>
                        <div class="step-label">Admin Review</div>
                    </div>
                    <div class="step">
                        <div class="step-icon">3</div>
                        <div class="step-label">Approval</div>
                    </div>
                    <div class="step">
                        <div class="step-icon">4</div>
                        <div class="step-label">Loan Created</div>
                    </div>
                </div>
                
                <div class="alert alert-info" style="text-align: left;">
                    <i class="bi bi-info-circle"></i>
                    <strong>What happens next?</strong>
                    <ol style="margin-top: 10px; margin-left: 20px;">
                        <li>Admin will review your request within 24-48 hours</li>
                        <li>You may receive a call for verification</li>
                        <li>Once approved, you'll get an email confirmation</li>
                        <li>Visit the branch to complete the loan process</li>
                    </ol>
                </div>
                
                <p style="font-size: 14px; color: #718096; margin: 20px 0;">
                    You will receive an email confirmation once admin reviews your request.
                </p>
                
                <a href="index.php" class="btn btn-primary" style="text-decoration: none; display: block;">
                    <i class="bi bi-house"></i> Return to Home
                </a>
            </div>
        <?php else: ?>
            <!-- Application Form -->
            <div class="offer-card">
                <div class="offer-badge">✨ PERSONAL LOAN APPLICATION</div>
                <h1>Apply for Personal Loan</h1>
                <p class="subtitle">Complete the form below to submit your request for admin approval</p>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <div class="amount-box">
                    <div class="amount-label">Eligible Personal Loan Amount</div>
                    <div class="amount-value">₹<?php echo number_format($personal_amount, 2); ?></div>
                    <div class="amount-note">at <?php echo $loan['interest_amount']; ?>% interest rate</div>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Regular Loan</div>
                        <div class="info-value regular">₹<?php echo number_format($regular_amount, 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Personal Loan</div>
                        <div class="info-value personal">₹<?php echo number_format($personal_amount, 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Total Available</div>
                        <div class="info-value total">₹<?php echo number_format($total_amount, 2); ?></div>
                    </div>
                </div>
                
                <div class="loan-details">
                    <h3>Loan Details</h3>
                    <div class="detail-row">
                        <span>Loan Receipt:</span>
                        <span><strong><?php echo $loan['receipt_number']; ?></strong></span>
                    </div>
                    <div class="detail-row">
                        <span>Product Value:</span>
                        <span><strong>₹<?php echo number_format($loan['product_value'], 2); ?></strong></span>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Your Name</label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($loan['customer_name']); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($loan['email']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="tel" class="form-control" name="mobile" value="<?php echo htmlspecialchars($loan['mobile_number']); ?>" required pattern="[0-9]{10}" title="Please enter 10 digit mobile number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Notes (Optional)</label>
                        <textarea class="form-control" name="customer_notes" placeholder="Any specific requirements or information for the admin..."></textarea>
                    </div>
                    
                    <div class="terms-check">
                        <input type="checkbox" name="accept_terms" id="accept_terms" required>
                        <label for="accept_terms">
                            I confirm that the information provided is correct and I agree to the 
                            <a href="#" target="_blank">terms and conditions</a>. I understand that this request 
                            requires admin approval and the final loan amount may be modified based on verification.
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Submit Request for Approval
                    </button>
                </form>
                
                <a href="index.php" class="btn btn-secondary" style="margin-top: 15px;">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>