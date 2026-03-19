<?php
session_start();
$currentPage = 'add-branch';
$pageTitle = 'Add Branch';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can add branches
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get users for manager dropdown (only active users with appropriate roles)
$managers_query = "SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('admin', 'sale', 'manager') ORDER BY name";
$managers_result = mysqli_query($conn, $managers_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name']);
        $branch_code = mysqli_real_escape_string($conn, $_POST['branch_code']);
        $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $website = mysqli_real_escape_string($conn, $_POST['website'] ?? '');
        $manager_name = mysqli_real_escape_string($conn, $_POST['manager_name'] ?? '');
        $manager_mobile = mysqli_real_escape_string($conn, $_POST['manager_mobile'] ?? '');
        $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;
        $status = $_POST['status'] ?? 'active';
        $opening_time = $_POST['opening_time'] ?? '09:00:00';
        $closing_time = $_POST['closing_time'] ?? '18:00:00';
        $holiday = $_POST['holiday'] ?? 'Sunday';
        
        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['company_logo']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for company logo.";
            } elseif ($_FILES['company_logo']['size'] > $max_size) {
                $error = "Company logo size must be less than 2MB.";
            } else {
                $upload_dir = "uploads/branches/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'logo_' . $branch_code . '_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $filepath)) {
                    $logo_path = $filepath;
                } else {
                    $error = "Failed to upload company logo.";
                }
            }
        }
        
        // Handle QR code upload
        $qr_path = null;
        if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 1 * 1024 * 1024; // 1MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['qr_image']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for QR code.";
            } elseif ($_FILES['qr_image']['size'] > $max_size) {
                $error = "QR code image size must be less than 1MB.";
            } else {
                $upload_dir = "uploads/branches/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'qr_' . $branch_code . '_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['qr_image']['tmp_name'], $filepath)) {
                    $qr_path = $filepath;
                } else {
                    $error = "Failed to upload QR code image.";
                }
            }
        }

        // Check if branch code already exists
        if (empty($error)) {
            $check_query = "SELECT id FROM branches WHERE branch_code = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, 's', $branch_code);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $error = "Branch code already exists. Please use a different code.";
            } else {
                // Insert new branch with all columns including logo, qr and website
                $insert_query = "INSERT INTO branches (
                    branch_name, branch_code, address, phone, email, website,
                    manager_name, manager_mobile, manager_id, status, 
                    opening_time, closing_time, holiday, logo_path, qr_path,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param(
                    $insert_stmt, 
                    'ssssssssissssss',
                    $branch_name, $branch_code, $address, $phone, $email, $website,
                    $manager_name, $manager_mobile, $manager_id, $status,
                    $opening_time, $closing_time, $holiday, $logo_path, $qr_path
                );

                if (mysqli_stmt_execute($insert_stmt)) {
                    $branch_id = mysqli_insert_id($conn);

                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'create', 'New branch created: " . $branch_name . "', 'branches', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $branch_id);
                    mysqli_stmt_execute($log_stmt);

                    header('Location: add-branch.php?success=added');
                    exit();
                } else {
                    $error = "Error creating branch: " . mysqli_error($conn);
                }
            }
        }
    }
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Branch created successfully!";
            break;
    }
}

// Generate branch code suggestion
$code_query = "SELECT MAX(CAST(SUBSTRING(branch_code, 3) AS UNSIGNED)) as last_num FROM branches WHERE branch_code LIKE 'BR%'";
$code_result = mysqli_query($conn, $code_query);
$code_row = mysqli_fetch_assoc($code_result);
$next_num = ($code_row['last_num'] ?? 0) + 1;
$suggested_code = 'BR' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* Reset and Base Styles - Matching index page */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.95);
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: #f8fafc;
        }

        .page-content {
            flex: 1 0 auto;
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        }

        /* Branch Container */
        .branch-container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header - Matching index page */
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
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-secondary {
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.5);
        }

        /* Alert Messages - Matching index page */
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            animation: slideDown 0.4s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: #dc3545;
        }

        /* Form Card - Matching index page cards */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
            font-size: 20px;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
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

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #a0aec0;
            font-size: 18px;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .form-control::placeholder {
            color: #a0aec0;
            font-size: 14px;
        }

        textarea.form-control {
            padding: 14px 20px 14px 45px;
            min-height: 100px;
            resize: vertical;
        }

        /* Image Upload Styles */
        .image-upload-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 10px;
        }

        .image-upload-card {
            background: #f7fafc;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .image-upload-card:hover {
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.1);
            border-color: #667eea;
        }

        .image-upload-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .image-upload-title i {
            color: #667eea;
        }

        .image-upload-area {
            border: 2px dashed #667eea;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 10px;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .image-upload-area:hover {
            border-color: #48bb78;
            background: #f0fff4;
        }

        .image-upload-area i {
            font-size: 36px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .image-upload-area p {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .image-upload-area small {
            color: #718096;
            font-size: 12px;
        }

        .image-preview {
            max-width: 100%;
            max-height: 100px;
            object-fit: contain;
            margin: 10px 0;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
            background: white;
        }

        .image-preview.qr-preview {
            max-height: 100px;
        }

        .format-badge {
            display: inline-block;
            background: #ebf4ff;
            color: #667eea;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }

        /* Code Suggestion Box */
        .code-suggestion {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 12px 15px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 2px solid #667eea30;
        }

        .code-suggestion i {
            color: #667eea;
            font-size: 18px;
        }

        .code-suggestion small {
            color: #4a5568;
            font-size: 13px;
        }

        .code-suggestion strong {
            color: #667eea;
            font-weight: 700;
        }

        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
            border: 2px solid #667eea30;
        }

        .info-content {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }

        .info-text {
            flex: 1;
        }

        .info-text strong {
            color: #2d3748;
            font-size: 16px;
        }

        .info-text small {
            color: #718096;
            font-size: 13px;
            display: block;
            margin-top: 4px;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .form-grid, .form-grid-3, .image-upload-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .page-title {
                font-size: 28px;
                text-align: center;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .form-grid, .form-grid-3, .image-upload-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .info-content {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .branch-container {
                padding: 0 10px;
            }
            
            .form-card {
                padding: 20px;
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
            <div class="branch-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-building" style="margin-right: 10px;"></i>
                        Add New Branch
                    </h1>
                    <div class="header-actions">
                        <a href="manage_branches.php" class="btn btn-secondary">
                            <i class="bi bi-list-ul"></i>
                            Manage Branches
                        </a>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Form Card -->
                <div class="form-card">
                    <form method="POST" id="branchForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        
                        <!-- Basic Information -->
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Basic Information
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Branch Name</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" name="branch_name" class="form-control" 
                                           placeholder="Enter branch name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Branch Code</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" name="branch_code" class="form-control" 
                                           value="<?php echo $suggested_code; ?>" required>
                                </div>
                                <div class="code-suggestion">
                                    <i class="bi bi-lightbulb"></i>
                                    <small>Suggested code: <strong><?php echo $suggested_code; ?></strong> (Format: BR001)</small>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="section-title">
                            <i class="bi bi-telephone"></i>
                            Contact Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label">Address</label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <textarea name="address" class="form-control" 
                                              placeholder="Full address..." rows="3"></textarea>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <i class="bi bi-telephone input-icon"></i>
                                    <input type="tel" name="phone" class="form-control" 
                                           placeholder="Phone number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" name="email" class="form-control" 
                                           placeholder="Email address">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Website</label>
                                <div class="input-group">
                                    <i class="bi bi-globe input-icon"></i>
                                    <input type="url" name="website" class="form-control" 
                                           placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>

                        <!-- Company Images -->
                        <div class="section-title">
                            <i class="bi bi-images"></i>
                            Company Images
                        </div>

                        <div class="image-upload-grid">
                            <!-- Company Logo Upload -->
                            <div class="image-upload-card">
                                <div class="image-upload-title">
                                    <i class="bi bi-building"></i> Company Logo
                                </div>
                                <div class="image-upload-area" onclick="document.getElementById('company_logo').click();">
                                    <i class="bi bi-cloud-upload"></i>
                                    <p>Click to upload logo</p>
                                    <small>PNG, JPG, JPEG (Max 2MB)</small>
                                    <input type="file" id="company_logo" name="company_logo" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'logo-preview')">
                                </div>
                                <div class="format-badge">PNG, JPG, JPEG</div>
                                <div id="logo-preview" class="image-preview" style="display: none;"></div>
                            </div>

                            <!-- QR Payment Image Upload -->
                            <div class="image-upload-card">
                                <div class="image-upload-title">
                                    <i class="bi bi-qr-code"></i> QR Payment Code
                                </div>
                                <div class="image-upload-area" onclick="document.getElementById('qr_image').click();">
                                    <i class="bi bi-cloud-upload"></i>
                                    <p>Click to upload QR code</p>
                                    <small>PNG, JPG, JPEG (Max 1MB)</small>
                                    <input type="file" id="qr_image" name="qr_image" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'qr-preview')">
                                </div>
                                <div class="format-badge">PNG, JPG, JPEG</div>
                                <div id="qr-preview" class="image-preview qr-preview" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Manager Information -->
                        <div class="section-title">
                            <i class="bi bi-person-badge"></i>
                            Manager Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Select Manager</label>
                                <div class="input-group">
                                    <i class="bi bi-people input-icon"></i>
                                    <select name="manager_id" class="form-select">
                                        <option value="">-- Select Manager --</option>
                                        <?php while($manager = mysqli_fetch_assoc($managers_result)): ?>
                                            <option value="<?php echo $manager['id']; ?>">
                                                <?php echo htmlspecialchars($manager['name']); ?> (<?php echo ucfirst($manager['role']); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <small style="color: #718096; margin-top: 5px; display: block;">
                                    <i class="bi bi-info-circle"></i> Select a user as branch manager
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">OR Enter Manager Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="manager_name" class="form-control" 
                                           placeholder="Manager name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Manager Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" name="manager_mobile" class="form-control" 
                                           placeholder="Manager mobile number">
                                </div>
                            </div>
                        </div>

                        <!-- Operating Hours & Status -->
                        <div class="section-title">
                            <i class="bi bi-clock"></i>
                            Operating Hours & Status
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">Opening Time</label>
                                <div class="input-group">
                                    <i class="bi bi-sun input-icon"></i>
                                    <input type="time" name="opening_time" class="form-control" value="09:00">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Closing Time</label>
                                <div class="input-group">
                                    <i class="bi bi-moon input-icon"></i>
                                    <input type="time" name="closing_time" class="form-control" value="18:00">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Weekly Holiday</label>
                                <div class="input-group">
                                    <i class="bi bi-calendar-week input-icon"></i>
                                    <select name="holiday" class="form-select">
                                        <option value="Sunday">Sunday</option>
                                        <option value="Monday">Monday</option>
                                        <option value="Tuesday">Tuesday</option>
                                        <option value="Wednesday">Wednesday</option>
                                        <option value="Thursday">Thursday</option>
                                        <option value="Friday">Friday</option>
                                        <option value="Saturday">Saturday</option>
                                        <option value="None">No Holiday</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Status</label>
                                <div class="input-group">
                                    <i class="bi bi-toggle-on input-icon"></i>
                                    <select name="status" class="form-select" required>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Branch Summary -->
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Branch Summary
                        </div>

                        <div class="info-card">
                            <div class="info-content">
                                <div class="info-icon">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div class="info-text">
                                    <strong>Branch Information:</strong>
                                    <small>Branch code must be unique. Company logo and QR code will be used in receipts and documents.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="bi bi-eraser"></i>
                                Clear
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
                                <i class="bi bi-x-circle"></i>
                                Close
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i>
                                Create Branch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script>
    // Preview image before upload
    function previewImage(input, previewId) {
        const previewDiv = document.getElementById(previewId);
        
        if (input.files && input.files[0]) {
            // Check file type
            const file = input.files[0];
            const fileType = file.type;
            const fileName = file.name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            // Allowed types
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            const allowedExt = ['jpg', 'jpeg', 'png'];
            
            if (!allowedTypes.includes(fileType) || !allowedExt.includes(fileExt)) {
                alert('Only PNG, JPG and JPEG files are allowed!');
                input.value = '';
                previewDiv.style.display = 'none';
                return;
            }
            
            // Check file size based on input id
            const maxSize = input.id === 'company_logo' ? 2 * 1024 * 1024 : 1 * 1024 * 1024;
            if (file.size > maxSize) {
                const sizeMB = maxSize / (1024 * 1024);
                alert(`File size must be less than ${sizeMB}MB!`);
                input.value = '';
                previewDiv.style.display = 'none';
                return;
            }
            
            const reader = new FileReader();
            const uploadArea = input.closest('.image-upload-area');
            
            reader.onload = function(e) {
                // Remove any existing content in upload area
                const icon = uploadArea.querySelector('i');
                const p = uploadArea.querySelector('p');
                const small = uploadArea.querySelector('small');
                
                if (icon) icon.style.display = 'none';
                if (p) p.style.display = 'none';
                if (small) small.style.display = 'none';
                
                // Show preview div and set image
                previewDiv.style.display = 'block';
                previewDiv.innerHTML = '<img src="' + e.target.result + '" style="max-width: 100%; max-height: 100px;">';
            }
            
            reader.readAsDataURL(file);
        } else {
            previewDiv.style.display = 'none';
        }
    }

    // Clear form
    function clearForm() {
        if (confirm('Are you sure you want to clear all fields?')) {
            document.getElementById('branchForm').reset();
            // Reset branch code to suggested value
            document.querySelector('input[name="branch_code"]').value = '<?php echo $suggested_code; ?>';
            
            // Hide all previews
            document.querySelectorAll('.image-preview').forEach(function(preview) {
                preview.style.display = 'none';
                preview.innerHTML = '';
            });
            
            // Show upload icons again
            document.querySelectorAll('.image-upload-area i, .image-upload-area p, .image-upload-area small').forEach(function(el) {
                el.style.display = '';
            });
        }
    }

    // Branch code validation
    document.querySelector('input[name="branch_code"]').addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    // Form validation
    document.getElementById('branchForm').addEventListener('submit', function(e) {
        const branchCode = document.querySelector('input[name="branch_code"]').value;
        
        // Branch code validation
        if (!branchCode.match(/^BR\d{3}$/)) {
            e.preventDefault();
            alert('Branch code must be in format: BR001 (BR followed by 3 digits)');
            return;
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