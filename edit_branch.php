<?php
session_start();
$currentPage = 'edit-branch';
$pageTitle = 'Edit Branch';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can edit branches
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get branch ID from URL
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: manage_branches.php');
    exit();
}

// Get branch details
$branch_query = "SELECT * FROM branches WHERE id = ?";
$stmt = mysqli_prepare($conn, $branch_query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$branch_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($branch_result) == 0) {
    header('Location: manage_branches.php');
    exit();
}

$branch = mysqli_fetch_assoc($branch_result);

// Get users for manager dropdown
$managers_query = "SELECT id, name, role FROM users WHERE is_active = 1 AND role IN ('admin', 'sale', 'manager') ORDER BY name";
$managers_result = mysqli_query($conn, $managers_query);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
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
        $logo_path = $branch['logo_path'];
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
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
                    // Delete old file
                    if (!empty($branch['logo_path']) && file_exists($branch['logo_path'])) {
                        unlink($branch['logo_path']);
                    }
                    $logo_path = $filepath;
                } else {
                    $error = "Failed to upload company logo.";
                }
            }
        }
        
        // Handle QR code upload
        $qr_path = $branch['qr_path'];
        if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 1 * 1024 * 1024; // 1MB
            
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
                    // Delete old file
                    if (!empty($branch['qr_path']) && file_exists($branch['qr_path'])) {
                        unlink($branch['qr_path']);
                    }
                    $qr_path = $filepath;
                } else {
                    $error = "Failed to upload QR code image.";
                }
            }
        }

        // Check if branch code already exists (excluding current branch)
        if (empty($error)) {
            $check_query = "SELECT id FROM branches WHERE branch_code = ? AND id != ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, 'si', $branch_code, $branch_id);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);

            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                $error = "Branch code already exists. Please use a different code.";
            } else {
                // Update branch
                $update_query = "UPDATE branches SET 
                    branch_name = ?, branch_code = ?, address = ?, phone = ?, 
                    email = ?, website = ?, manager_name = ?, manager_mobile = ?, 
                    manager_id = ?, status = ?, opening_time = ?, closing_time = ?, 
                    holiday = ?, logo_path = ?, qr_path = ?, updated_at = NOW()
                    WHERE id = ?";

                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param(
                    $update_stmt, 
                    'ssssssssissssssi',
                    $branch_name, $branch_code, $address, $phone, $email, $website,
                    $manager_name, $manager_mobile, $manager_id, $status,
                    $opening_time, $closing_time, $holiday, $logo_path, $qr_path,
                    $branch_id
                );

                if (mysqli_stmt_execute($update_stmt)) {
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'update', 'Branch updated: $branch_name', 'branches', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'ii', $_SESSION['user_id'], $branch_id);
                    mysqli_stmt_execute($log_stmt);

                    header('Location: view_branch.php?id=' . $branch_id . '&success=updated');
                    exit();
                } else {
                    $error = "Error updating branch: " . mysqli_error($conn);
                }
            }
        }
    }
    
    // Handle image removal
    if (isset($_POST['action']) && $_POST['action'] === 'remove_image') {
        $image_type = $_POST['image_type'];
        $update_field = '';
        $old_path = '';
        
        if ($image_type === 'logo') {
            $update_field = 'logo_path';
            $old_path = $branch['logo_path'];
        } elseif ($image_type === 'qr') {
            $update_field = 'qr_path';
            $old_path = $branch['qr_path'];
        }
        
        if (!empty($update_field) && !empty($old_path)) {
            // Delete file
            if (file_exists($old_path)) {
                unlink($old_path);
            }
            
            // Update database
            $update_query = "UPDATE branches SET $update_field = NULL WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, 'i', $branch_id);
            
            if (mysqli_stmt_execute($stmt)) {
                // Refresh branch data
                $branch[$update_field] = null;
                $message = ucfirst($image_type) . " image removed successfully!";
            }
        }
    }
}

// Check for success messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'updated':
            $message = "Branch updated successfully!";
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
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

        .edit-container {
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
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 14px;
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
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

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
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        .section-title i {
            color: #667eea;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

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
        }

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

        .image-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 10px;
        }

        .format-badge {
            display: inline-block;
            background: #ebf4ff;
            color: #667eea;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        @media (max-width: 768px) {
            .form-grid, .form-grid-3, .image-upload-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-actions {
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
            <div class="edit-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-pencil" style="margin-right: 10px;"></i>
                        Edit Branch: <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </h1>
                    <div>
                        <a href="view_branch.php?id=<?php echo $branch_id; ?>" class="btn btn-secondary">
                            <i class="bi bi-eye"></i>
                            View Branch
                        </a>
                        <a href="manage_branches.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <div class="form-card">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update">
                        
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
                                           value="<?php echo htmlspecialchars($branch['branch_name']); ?>" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Branch Code</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" name="branch_code" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['branch_code']); ?>" required>
                                </div>
                                <div class="code-suggestion">
                                    <i class="bi bi-info-circle"></i>
                                    <small>Format: BR001 (BR followed by 3 digits)</small>
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
                                    <textarea name="address" class="form-control"><?php echo htmlspecialchars($branch['address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Phone Number</label>
                                <div class="input-group">
                                    <i class="bi bi-telephone input-icon"></i>
                                    <input type="tel" name="phone" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" name="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['email'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Website</label>
                                <div class="input-group">
                                    <i class="bi bi-globe input-icon"></i>
                                    <input type="url" name="website" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['website'] ?? ''); ?>">
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
                                    <?php if (!empty($branch['logo_path'])): ?>
                                        <img src="<?php echo $branch['logo_path']; ?>" class="image-preview" alt="Logo">
                                    <?php else: ?>
                                        <i class="bi bi-cloud-upload"></i>
                                        <p>Click to upload logo</p>
                                        <small>PNG, JPG, JPEG (Max 2MB)</small>
                                    <?php endif; ?>
                                    <input type="file" id="company_logo" name="company_logo" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'logo-preview')">
                                </div>
                                <div class="format-badge">PNG, JPG, JPEG</div>
                                <?php if (!empty($branch['logo_path'])): ?>
                                    <div class="image-actions">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('logo')">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- QR Payment Image Upload -->
                            <div class="image-upload-card">
                                <div class="image-upload-title">
                                    <i class="bi bi-qr-code"></i> QR Payment Code
                                </div>
                                <div class="image-upload-area" onclick="document.getElementById('qr_image').click();">
                                    <?php if (!empty($branch['qr_path'])): ?>
                                        <img src="<?php echo $branch['qr_path']; ?>" class="image-preview qr-preview" alt="QR Code">
                                    <?php else: ?>
                                        <i class="bi bi-cloud-upload"></i>
                                        <p>Click to upload QR code</p>
                                        <small>PNG, JPG, JPEG (Max 1MB)</small>
                                    <?php endif; ?>
                                    <input type="file" id="qr_image" name="qr_image" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'qr-preview')">
                                </div>
                                <div class="format-badge">PNG, JPG, JPEG</div>
                                <?php if (!empty($branch['qr_path'])): ?>
                                    <div class="image-actions">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('qr')">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </div>
                                <?php endif; ?>
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
                                        <?php 
                                        mysqli_data_seek($managers_result, 0);
                                        while($manager = mysqli_fetch_assoc($managers_result)): 
                                        ?>
                                            <option value="<?php echo $manager['id']; ?>" 
                                                <?php echo ($branch['manager_id'] == $manager['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($manager['name']); ?> (<?php echo ucfirst($manager['role']); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">OR Enter Manager Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" name="manager_name" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['manager_name'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Manager Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" name="manager_mobile" class="form-control" 
                                           value="<?php echo htmlspecialchars($branch['manager_mobile'] ?? ''); ?>">
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
                                    <input type="time" name="opening_time" class="form-control" 
                                           value="<?php echo substr($branch['opening_time'] ?? '09:00', 0, 5); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Closing Time</label>
                                <div class="input-group">
                                    <i class="bi bi-moon input-icon"></i>
                                    <input type="time" name="closing_time" class="form-control" 
                                           value="<?php echo substr($branch['closing_time'] ?? '18:00', 0, 5); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Weekly Holiday</label>
                                <div class="input-group">
                                    <i class="bi bi-calendar-week input-icon"></i>
                                    <select name="holiday" class="form-select">
                                        <option value="Sunday" <?php echo ($branch['holiday'] ?? '') == 'Sunday' ? 'selected' : ''; ?>>Sunday</option>
                                        <option value="Monday" <?php echo ($branch['holiday'] ?? '') == 'Monday' ? 'selected' : ''; ?>>Monday</option>
                                        <option value="Tuesday" <?php echo ($branch['holiday'] ?? '') == 'Tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                        <option value="Wednesday" <?php echo ($branch['holiday'] ?? '') == 'Wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                        <option value="Thursday" <?php echo ($branch['holiday'] ?? '') == 'Thursday' ? 'selected' : ''; ?>>Thursday</option>
                                        <option value="Friday" <?php echo ($branch['holiday'] ?? '') == 'Friday' ? 'selected' : ''; ?>>Friday</option>
                                        <option value="Saturday" <?php echo ($branch['holiday'] ?? '') == 'Saturday' ? 'selected' : ''; ?>>Saturday</option>
                                        <option value="None" <?php echo ($branch['holiday'] ?? '') == 'None' ? 'selected' : ''; ?>>No Holiday</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Status</label>
                                <div class="input-group">
                                    <i class="bi bi-toggle-on input-icon"></i>
                                    <select name="status" class="form-select" required>
                                        <option value="active" <?php echo $branch['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $branch['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='view_branch.php?id=<?php echo $branch_id; ?>'">
                                <i class="bi bi-x-circle"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i>
                                Update Branch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Image Removal Form -->
<form id="removeImageForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="remove_image">
    <input type="hidden" name="image_type" id="remove_image_type">
</form>

<script>
    // Preview image before upload
    function previewImage(input, previewId) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const fileType = file.type;
            const fileName = file.name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            const allowedExt = ['jpg', 'jpeg', 'png'];
            
            if (!allowedTypes.includes(fileType) || !allowedExt.includes(fileExt)) {
                alert('Only PNG, JPG and JPEG files are allowed!');
                input.value = '';
                return;
            }
            
            const maxSize = input.id === 'company_logo' ? 2 * 1024 * 1024 : 1 * 1024 * 1024;
            if (file.size > maxSize) {
                const sizeMB = maxSize / (1024 * 1024);
                alert(`File size must be less than ${sizeMB}MB!`);
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            const uploadArea = input.closest('.image-upload-area');
            
            reader.onload = function(e) {
                const icon = uploadArea.querySelector('i');
                const p = uploadArea.querySelector('p');
                const small = uploadArea.querySelector('small');
                
                if (icon) icon.style.display = 'none';
                if (p) p.style.display = 'none';
                if (small) small.style.display = 'none';
                
                let img = uploadArea.querySelector('img');
                if (!img) {
                    img = document.createElement('img');
                    uploadArea.appendChild(img);
                }
                img.src = e.target.result;
                img.className = 'image-preview' + (input.id === 'qr_image' ? ' qr-preview' : '');
            }
            
            reader.readAsDataURL(file);
        }
    }

    // Remove image
    function removeImage(imageType) {
        if (confirm('Are you sure you want to remove this image?')) {
            document.getElementById('remove_image_type').value = imageType;
            document.getElementById('removeImageForm').submit();
        }
    }

    // Branch code validation
    document.querySelector('input[name="branch_code"]').addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
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