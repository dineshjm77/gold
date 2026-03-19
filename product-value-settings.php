<?php
session_start();
$currentPage = 'product-value-settings';
$pageTitle = 'Product Value Settings';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can access settings
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create/Update product_value_settings table with new columns
$alter_table = "ALTER TABLE product_value_settings 
                ADD COLUMN IF NOT EXISTS personal_loan_percentage DECIMAL(5,2) DEFAULT 0.00 AFTER percentage,
                ADD COLUMN IF NOT EXISTS regular_loan_percentage DECIMAL(5,2) DEFAULT 70.00 AFTER personal_loan_percentage";

// Check if columns exist and add them if they don't
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM product_value_settings LIKE 'personal_loan_percentage'");
if (mysqli_num_rows($check_columns) == 0) {
    $add_column = "ALTER TABLE product_value_settings ADD COLUMN personal_loan_percentage DECIMAL(5,2) DEFAULT 0.00 AFTER percentage";
    mysqli_query($conn, $add_column);
}

$check_regular = mysqli_query($conn, "SHOW COLUMNS FROM product_value_settings LIKE 'regular_loan_percentage'");
if (mysqli_num_rows($check_regular) == 0) {
    $add_regular = "ALTER TABLE product_value_settings ADD COLUMN regular_loan_percentage DECIMAL(5,2) DEFAULT 70.00 AFTER personal_loan_percentage";
    mysqli_query($conn, $add_regular);
}

// Create table if it doesn't exist (with new columns)
$create_table = "CREATE TABLE IF NOT EXISTS product_value_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_type VARCHAR(100) NOT NULL,
    total_value_per_gram DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    personal_loan_percentage DECIMAL(5,2) DEFAULT 0.00,
    regular_loan_percentage DECIMAL(5,2) DEFAULT 70.00,
    status TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_product_type (product_type)
)";
mysqli_query($conn, $create_table);

// Get all product types for dropdown
$product_types_query = "SELECT id, product_type FROM product_types WHERE status = 1 ORDER BY product_type";
$product_types_result = mysqli_query($conn, $product_types_query);

// Get all product value settings
$settings_query = "SELECT * FROM product_value_settings ORDER BY product_type";
$settings_result = mysqli_query($conn, $settings_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'add_setting') {
        $product_type = mysqli_real_escape_string($conn, $_POST['product_type']);
        $total_value_per_gram = floatval($_POST['total_value_per_gram']);
        $percentage = floatval($_POST['percentage']);
        $personal_loan_percentage = floatval($_POST['personal_loan_percentage'] ?? 0);
        $regular_loan_percentage = floatval($_POST['regular_loan_percentage'] ?? 70);
        
        // Validate percentages
        $errors = [];
        if ($percentage < 0 || $percentage > 100) {
            $errors[] = "Main percentage must be between 0 and 100";
        }
        if ($personal_loan_percentage < 0 || $personal_loan_percentage > 100) {
            $errors[] = "Personal loan percentage must be between 0 and 100";
        }
        if ($regular_loan_percentage < 0 || $regular_loan_percentage > 100) {
            $errors[] = "Regular loan percentage must be between 0 and 100";
        }
        if (($personal_loan_percentage + $regular_loan_percentage) > 100) {
            $errors[] = "Personal loan percentage + Regular loan percentage cannot exceed 100%";
        }
        
        if (empty($errors)) {
            // Check if product type already exists
            $check_query = "SELECT id FROM product_value_settings WHERE product_type = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, 's', $product_type);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                // Update existing
                $update_query = "UPDATE product_value_settings SET 
                                total_value_per_gram = ?, 
                                percentage = ?,
                                personal_loan_percentage = ?,
                                regular_loan_percentage = ?,
                                updated_at = NOW()
                                WHERE product_type = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, 'dddds', 
                    $total_value_per_gram, 
                    $percentage, 
                    $personal_loan_percentage, 
                    $regular_loan_percentage, 
                    $product_type
                );
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $message = "Product value setting updated successfully!";
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                  VALUES (?, 'update', 'Updated product value setting: $product_type', 'product_value_settings')";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'i', $_SESSION['user_id']);
                    mysqli_stmt_execute($log_stmt);
                } else {
                    $error = "Error updating setting: " . mysqli_error($conn);
                }
            } else {
                // Insert new
                $insert_query = "INSERT INTO product_value_settings 
                                (product_type, total_value_per_gram, percentage, personal_loan_percentage, regular_loan_percentage) 
                                VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, 'sdddd', 
                    $product_type, 
                    $total_value_per_gram, 
                    $percentage, 
                    $personal_loan_percentage, 
                    $regular_loan_percentage
                );
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $message = "Product value setting added successfully!";
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                  VALUES (?, 'create', 'Added product value setting: $product_type', 'product_value_settings')";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'i', $_SESSION['user_id']);
                    mysqli_stmt_execute($log_stmt);
                } else {
                    $error = "Error adding setting: " . mysqli_error($conn);
                }
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete_setting') {
        $id = intval($_POST['id']);
        
        $delete_query = "DELETE FROM product_value_settings WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, 'i', $id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $message = "Product value setting deleted successfully!";
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                          VALUES (?, 'delete', 'Deleted product value setting ID: $id', 'product_value_settings')";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'i', $_SESSION['user_id']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Error deleting setting: " . mysqli_error($conn);
        }
    }
    
    // Refresh settings
    $settings_result = mysqli_query($conn, $settings_query);
}

// Function to calculate product values
function calculateProductValue($total_value, $percentage) {
    return ($total_value * $percentage) / 100;
}

function calculatePersonalLoanAmount($total_value, $personal_percentage) {
    return ($total_value * $personal_percentage) / 100;
}

function calculateRegularLoanAmount($total_value, $regular_percentage) {
    return ($total_value * $regular_percentage) / 100;
}

function calculateRemainingAmount($total_value, $regular_percentage, $personal_percentage) {
    $total_percentage = $regular_percentage + $personal_percentage;
    $remaining_percentage = 100 - $total_percentage;
    return ($total_value * $remaining_percentage) / 100;
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

        .settings-container {
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        .form-card {
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            align-items: end;
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

        .form-text {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }

        .settings-table {
            width: 100%;
            border-collapse: collapse;
        }

        .settings-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .settings-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .settings-table tr:hover {
            background: #f7fafc;
        }

        .badge {
            background: #667eea20;
            color: #667eea;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #48bb7820;
            color: #48bb78;
        }

        .badge-warning {
            background: #ecc94b20;
            color: #ecc94b;
        }

        .badge-info {
            background: #4299e120;
            color: #4299e1;
        }

        .badge-secondary {
            background: #a0aec020;
            color: #718096;
        }

        .value-display {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            border: 1px solid #e2e8f0;
        }

        .value-box {
            text-align: center;
            padding: 15px 10px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .value-box-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .value-box-amount {
            font-size: 20px;
            font-weight: 700;
        }

        .value-box-amount.primary {
            color: #667eea;
        }

        .value-box-amount.success {
            color: #48bb78;
        }

        .value-box-amount.warning {
            color: #ecc94b;
        }

        .value-box-amount.secondary {
            color: #718096;
        }

        .value-box-amount.total {
            color: #667eea;
        }

        .percentage-bar {
            display: flex;
            height: 40px;
            border-radius: 8px;
            overflow: hidden;
            margin: 20px 0 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .percentage-segment {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: white;
            transition: width 0.3s ease;
            position: relative;
        }

        .segment-regular {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }

        .segment-personal {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
        }

        .segment-remaining {
            background: linear-gradient(135deg, #a0aec0 0%, #718096 100%);
        }

        .segment-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #2d3748;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
            margin-bottom: 5px;
        }

        .percentage-segment:hover .segment-tooltip {
            opacity: 1;
        }

        .legend {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin: 15px 0;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-color.regular {
            background: #48bb78;
        }

        .legend-color.personal {
            background: #ecc94b;
        }

        .legend-color.remaining {
            background: #a0aec0;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-icon {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .btn-icon.edit {
            background: #667eea20;
            color: #667eea;
        }

        .btn-icon.edit:hover {
            background: #667eea;
            color: white;
        }

        .btn-icon.delete {
            background: #f5656520;
            color: #f56565;
        }

        .btn-icon.delete:hover {
            background: #f56565;
            color: white;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .info-card {
            background: #f7fafc;
            border-radius: 8px;
            padding: 15px;
            border-left: 4px solid #667eea;
        }

        .info-card.personal {
            border-left-color: #ecc94b;
        }

        .info-card.remaining {
            border-left-color: #a0aec0;
        }

        .info-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-example {
            font-size: 13px;
            color: #4a5568;
            background: white;
            padding: 12px;
            border-radius: 6px;
        }

        .example-highlight {
            color: #667eea;
            font-weight: 600;
        }

        .example-highlight.personal {
            color: #ecc94b;
        }

        .example-highlight.remaining {
            color: #718096;
        }

        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .value-display {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .settings-table {
                overflow-x: auto;
                display: block;
            }
            
            .value-display {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .legend {
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
                <div class="settings-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-calculator"></i>
                            Product Value Settings
                        </h1>
                        <a href="New-Loan.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to New Loan
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Add/Edit Form -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-plus-circle"></i>
                            Add/Update Product Value
                        </div>

                        <form method="POST" action="" id="valueForm">
                            <input type="hidden" name="action" value="add_setting">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Product Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="product_type" id="product_type" required>
                                            <option value="">Select Product Type</option>
                                            <?php 
                                            if ($product_types_result && mysqli_num_rows($product_types_result) > 0) {
                                                mysqli_data_seek($product_types_result, 0);
                                                while($pt = mysqli_fetch_assoc($product_types_result)): 
                                            ?>
                                                <option value="<?php echo htmlspecialchars($pt['product_type']); ?>">
                                                    <?php echo htmlspecialchars($pt['product_type']); ?>
                                                </option>
                                            <?php endwhile; 
                                            } ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">Total Value per Gram (₹)</label>
                                    <div class="input-group">
                                        <i class="bi bi-cash-stack input-icon"></i>
                                        <input type="number" class="form-control" name="total_value_per_gram" 
                                               id="total_value" step="0.01" min="0" required 
                                               onchange="calculateAllValues()">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Regular Loan Percentage (%)</label>
                                    <div class="input-group">
                                        <i class="bi bi-percent input-icon" style="color: #48bb78;"></i>
                                        <input type="number" class="form-control" name="regular_loan_percentage" 
                                               id="regular_percentage" step="0.01" min="0" max="100" 
                                               value="70" onchange="calculateAllValues()">
                                    </div>
                                    <small class="form-text">Standard loan amount percentage</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Personal Loan Percentage (%)</label>
                                    <div class="input-group">
                                        <i class="bi bi-percent input-icon" style="color: #ecc94b;"></i>
                                        <input type="number" class="form-control" name="personal_loan_percentage" 
                                               id="personal_percentage" step="0.01" min="0" max="100" 
                                               value="0" onchange="calculateAllValues()">
                                    </div>
                                    <small class="form-text">Additional personal loan amount</small>
                                </div>
                            </div>

                            <!-- Live Calculation Display with Remaining Amount -->
                            <div class="value-display">
                                <div class="value-box">
                                    <div class="value-box-label">
                                        <i class="bi bi-cash"></i> Total Value
                                    </div>
                                    <div class="value-box-amount primary" id="displayTotalValue">₹ 0.00</div>
                                </div>
                                <div class="value-box">
                                    <div class="value-box-label">
                                        <i class="bi bi-shield-check" style="color: #48bb78;"></i> Regular Loan
                                    </div>
                                    <div class="value-box-amount success" id="displayRegularValue">₹ 0.00</div>
                                    <small id="displayRegularPercent">(0%)</small>
                                </div>
                                <div class="value-box">
                                    <div class="value-box-label">
                                        <i class="bi bi-person" style="color: #ecc94b;"></i> Personal Loan
                                    </div>
                                    <div class="value-box-amount warning" id="displayPersonalValue">₹ 0.00</div>
                                    <small id="displayPersonalPercent">(0%)</small>
                                </div>
                                <div class="value-box">
                                    <div class="value-box-label">
                                        <i class="bi bi-box" style="color: #718096;"></i> Remaining Amount
                                    </div>
                                    <div class="value-box-amount secondary" id="displayRemainingValue">₹ 0.00</div>
                                    <small id="displayRemainingPercent">(0%)</small>
                                </div>
                                <div class="value-box">
                                    <div class="value-box-label">
                                        <i class="bi bi-cash-coin"></i> Total Loan
                                    </div>
                                    <div class="value-box-amount total" id="displayTotalLoan">₹ 0.00</div>
                                    <small>(Regular + Personal)</small>
                                </div>
                            </div>

                            <!-- Percentage Bar with All Segments -->
                            <div class="percentage-bar" id="percentageBar">
                                <div class="percentage-segment segment-regular" id="regularSegment" style="width: 70%">
                                    70%
                                    <span class="segment-tooltip">Regular Loan: 70%</span>
                                </div>
                                <div class="percentage-segment segment-personal" id="personalSegment" style="width: 0%">
                                    0%
                                    <span class="segment-tooltip">Personal Loan: 0%</span>
                                </div>
                                <div class="percentage-segment segment-remaining" id="remainingSegment" style="width: 30%">
                                    30%
                                    <span class="segment-tooltip">Remaining Buffer: 30%</span>
                                </div>
                            </div>

                            <!-- Legend -->
                            <div class="legend">
                                <div class="legend-item">
                                    <span class="legend-color regular"></span>
                                    <span>Regular Loan</span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-color personal"></span>
                                    <span>Personal Loan</span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-color remaining"></span>
                                    <span>Remaining Buffer</span>
                                </div>
                            </div>

                            <div style="margin-top: 20px;">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save"></i> Save Setting
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                    <i class="bi bi-eraser"></i> Clear
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Settings List with All Values -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-list-ul"></i>
                            Product Value Settings
                        </div>

                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>Product Type</th>
                                    <th>Total Value (₹/g)</th>
                                    <th>Regular Loan</th>
                                    <th>Personal Loan</th>
                                    <th>Remaining</th>
                                    <th>Total Loan</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($settings_result && mysqli_num_rows($settings_result) > 0): ?>
                                    <?php while($setting = mysqli_fetch_assoc($settings_result)): 
                                        $regular_value = calculateProductValue($setting['total_value_per_gram'], $setting['regular_loan_percentage'] ?? 70);
                                        $personal_value = calculateProductValue($setting['total_value_per_gram'], $setting['personal_loan_percentage'] ?? 0);
                                        $remaining_value = calculateRemainingAmount(
                                            $setting['total_value_per_gram'], 
                                            $setting['regular_loan_percentage'] ?? 70, 
                                            $setting['personal_loan_percentage'] ?? 0
                                        );
                                        $total_loan_value = $regular_value + $personal_value;
                                        $regular_percent = $setting['regular_loan_percentage'] ?? 70;
                                        $personal_percent = $setting['personal_loan_percentage'] ?? 0;
                                        $remaining_percent = 100 - ($regular_percent + $personal_percent);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($setting['product_type']); ?></strong>
                                        </td>
                                        <td>₹ <?php echo number_format($setting['total_value_per_gram'], 2); ?></td>
                                        <td>
                                            <span class="badge badge-success"><?php echo $regular_percent; ?>%</span>
                                            <div style="font-size: 12px; margin-top: 4px; color: #48bb78;">
                                                ₹ <?php echo number_format($regular_value, 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning"><?php echo $personal_percent; ?>%</span>
                                            <div style="font-size: 12px; margin-top: 4px; color: #ecc94b;">
                                                ₹ <?php echo number_format($personal_value, 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-secondary"><?php echo $remaining_percent; ?>%</span>
                                            <div style="font-size: 12px; margin-top: 4px; color: #718096;">
                                                ₹ <?php echo number_format($remaining_value, 2); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: #667eea;">₹ <?php echo number_format($total_loan_value, 2); ?></strong>
                                            <div style="font-size: 11px; color: #718096;">
                                                (<?php echo $regular_percent + $personal_percent; ?>%)
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge" style="background: <?php echo $setting['status'] ? '#48bb7820' : '#f5656520'; ?>; color: <?php echo $setting['status'] ? '#48bb78' : '#f56565'; ?>;">
                                                <?php echo $setting['status'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="button" class="btn-icon edit" 
                                                        onclick="editSetting(
                                                            '<?php echo $setting['product_type']; ?>', 
                                                            <?php echo $setting['total_value_per_gram']; ?>, 
                                                            <?php echo $setting['personal_loan_percentage'] ?? 0; ?>,
                                                            <?php echo $setting['regular_loan_percentage'] ?? 70; ?>
                                                        )">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this setting?');">
                                                    <input type="hidden" name="action" value="delete_setting">
                                                    <input type="hidden" name="id" value="<?php echo $setting['id']; ?>">
                                                    <button type="submit" class="btn-icon delete">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 40px; color: #718096;">
                                            <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 10px;"></i>
                                            No product value settings found. Add your first setting above.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Information Cards with Remaining Amount -->
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-title">
                                <i class="bi bi-shield-check" style="color: #48bb78;"></i>
                                Regular Loan (Standard)
                            </div>
                            <div class="info-example">
                                <strong>Example:</strong><br>
                                Total Value: ₹10<br>
                                Regular Loan (70%): <span class="example-highlight">₹7.00</span><br>
                                This is the standard loan amount
                            </div>
                        </div>
                        
                        <div class="info-card personal">
                            <div class="info-title">
                                <i class="bi bi-person-badge" style="color: #ecc94b;"></i>
                                Personal Loan (Additional)
                            </div>
                            <div class="info-example">
                                <strong>Example:</strong><br>
                                Total Value: ₹10<br>
                                Regular Loan (70%): ₹7.00<br>
                                Personal Loan (20%): <span class="example-highlight personal">₹2.00</span><br>
                                <strong>Total Loan: ₹9.00</strong>
                            </div>
                        </div>

                        <div class="info-card remaining">
                            <div class="info-title">
                                <i class="bi bi-box" style="color: #718096;"></i>
                                Remaining Amount (Buffer)
                            </div>
                            <div class="info-example">
                                <strong>Example:</strong><br>
                                Total Value: ₹10<br>
                                Regular (70%): ₹7.00<br>
                                Personal (20%): ₹2.00<br>
                                <span class="example-highlight remaining">Remaining (10%): ₹1.00</span><br>
                                <strong>Buffer amount for safety</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Formula Card -->
                    <div class="form-card" style="background: #ebf4ff;">
                        <div class="section-title">
                            <i class="bi bi-calculator"></i>
                            Calculation Formulas
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px;">
                            <div>
                                <strong>Regular Loan</strong>
                                <p style="font-size: 13px; color: #4a5568; margin-top: 5px;">
                                    Total × (Regular% ÷ 100)
                                </p>
                            </div>
                            <div>
                                <strong>Personal Loan</strong>
                                <p style="font-size: 13px; color: #4a5568; margin-top: 5px;">
                                    Total × (Personal% ÷ 100)
                                </p>
                            </div>
                            <div>
                                <strong>Remaining Amount</strong>
                                <p style="font-size: 13px; color: #4a5568; margin-top: 5px;">
                                    Total × (100 - Regular% - Personal%) ÷ 100
                                </p>
                            </div>
                            <div>
                                <strong>Total Loan</strong>
                                <p style="font-size: 13px; color: #4a5568; margin-top: 5px;">
                                    Regular + Personal Loan
                                </p>
                            </div>
                        </div>
                        <div style="margin-top: 15px; padding: 10px; background: white; border-radius: 8px; text-align: center;">
                            <strong>Note:</strong> Regular + Personal percentages should not exceed 100%. 
                            The remaining percentage serves as a buffer for safety.
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script>
        // Calculate all values in real-time
        function calculateAllValues() {
            const totalValue = parseFloat(document.getElementById('total_value').value) || 0;
            let regularPercent = parseFloat(document.getElementById('regular_percentage').value) || 0;
            let personalPercent = parseFloat(document.getElementById('personal_percentage').value) || 0;
            
            // Ensure percentages don't exceed 100
            if (regularPercent + personalPercent > 100) {
                const maxPersonal = 100 - regularPercent;
                if (maxPersonal >= 0) {
                    document.getElementById('personal_percentage').value = maxPersonal;
                    personalPercent = maxPersonal;
                } else {
                    document.getElementById('regular_percentage').value = 0;
                    document.getElementById('personal_percentage').value = 0;
                    regularPercent = 0;
                    personalPercent = 0;
                }
            }
            
            const remainingPercent = 100 - (regularPercent + personalPercent);
            
            const regularValue = (totalValue * regularPercent) / 100;
            const personalValue = (totalValue * personalPercent) / 100;
            const remainingValue = (totalValue * remainingPercent) / 100;
            const totalLoan = regularValue + personalValue;
            
            // Update displays
            document.getElementById('displayTotalValue').innerHTML = '₹ ' + totalValue.toFixed(2);
            
            document.getElementById('displayRegularValue').innerHTML = '₹ ' + regularValue.toFixed(2);
            document.getElementById('displayRegularPercent').innerHTML = '(' + regularPercent + '%)';
            
            document.getElementById('displayPersonalValue').innerHTML = '₹ ' + personalValue.toFixed(2);
            document.getElementById('displayPersonalPercent').innerHTML = '(' + personalPercent + '%)';
            
            document.getElementById('displayRemainingValue').innerHTML = '₹ ' + remainingValue.toFixed(2);
            document.getElementById('displayRemainingPercent').innerHTML = '(' + remainingPercent + '%)';
            
            document.getElementById('displayTotalLoan').innerHTML = '₹ ' + totalLoan.toFixed(2);
            
            // Update percentage bar
            document.getElementById('regularSegment').style.width = regularPercent + '%';
            document.getElementById('regularSegment').innerHTML = regularPercent + '%';
            
            document.getElementById('personalSegment').style.width = personalPercent + '%';
            document.getElementById('personalSegment').innerHTML = personalPercent + '%';
            
            document.getElementById('remainingSegment').style.width = remainingPercent + '%';
            document.getElementById('remainingSegment').innerHTML = remainingPercent + '%';
        }

        // Edit setting
        function editSetting(productType, totalValue, personalPercent, regularPercent) {
            document.getElementById('product_type').value = productType;
            document.getElementById('total_value').value = totalValue;
            document.getElementById('regular_percentage').value = regularPercent;
            document.getElementById('personal_percentage').value = personalPercent;
            
            calculateAllValues();
            
            // Scroll to form
            document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
        }

        // Clear form
        function clearForm() {
            document.getElementById('product_type').value = '';
            document.getElementById('total_value').value = '';
            document.getElementById('regular_percentage').value = '70';
            document.getElementById('personal_percentage').value = '0';
            calculateAllValues();
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateAllValues();
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>