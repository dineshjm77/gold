<?php
session_start();
$currentPage = 'company-settings';
$pageTitle = 'Company Settings';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can access settings
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create company_settings table if it doesn't exist
$create_table = "CREATE TABLE IF NOT EXISTS company_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(200) NOT NULL,
    address TEXT,
    phone VARCHAR(15),
    alternate_phone VARCHAR(15),
    email VARCHAR(100),
    website VARCHAR(100),
    gst_no VARCHAR(20),
    pan_no VARCHAR(20),
    logo_path VARCHAR(255),
    signature_path VARCHAR(255),
    stamp_path VARCHAR(255),
    qr_code_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
mysqli_query($conn, $create_table);

// Check if settings exist
$settings_query = "SELECT * FROM company_settings WHERE id = 1";
$settings_result = mysqli_query($conn, $settings_query);
$settings = mysqli_fetch_assoc($settings_result);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
        
        $company_name = mysqli_real_escape_string($conn, $_POST['company_name']);
        $address = mysqli_real_escape_string($conn, $_POST['address']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $alternate_phone = mysqli_real_escape_string($conn, $_POST['alternate_phone']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $website = mysqli_real_escape_string($conn, $_POST['website']);
        $gst_no = mysqli_real_escape_string($conn, $_POST['gst_no']);
        $pan_no = mysqli_real_escape_string($conn, $_POST['pan_no']);
        
        // Handle logo upload
        $logo_path = $settings['logo_path'] ?? '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['logo']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for logo.";
            } elseif ($_FILES['logo']['size'] > $max_size) {
                $error = "Logo size must be less than 2MB.";
            } else {
                $upload_dir = "uploads/company/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'logo_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $filepath)) {
                    // Delete old file if exists
                    if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])) {
                        unlink($settings['logo_path']);
                    }
                    $logo_path = $filepath;
                } else {
                    $error = "Failed to upload logo.";
                }
            }
        }
        
        // Handle signature upload
        $signature_path = $settings['signature_path'] ?? '';
        if (isset($_FILES['signature']) && $_FILES['signature']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 1 * 1024 * 1024; // 1MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['signature']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for signature.";
            } elseif ($_FILES['signature']['size'] > $max_size) {
                $error = "Signature size must be less than 1MB.";
            } else {
                $upload_dir = "uploads/company/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'signature_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['signature']['tmp_name'], $filepath)) {
                    // Delete old file if exists
                    if (!empty($settings['signature_path']) && file_exists($settings['signature_path'])) {
                        unlink($settings['signature_path']);
                    }
                    $signature_path = $filepath;
                } else {
                    $error = "Failed to upload signature.";
                }
            }
        }
        
        // Handle stamp upload
        $stamp_path = $settings['stamp_path'] ?? '';
        if (isset($_FILES['stamp']) && $_FILES['stamp']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 1 * 1024 * 1024; // 1MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['stamp']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['stamp']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for stamp.";
            } elseif ($_FILES['stamp']['size'] > $max_size) {
                $error = "Stamp size must be less than 1MB.";
            } else {
                $upload_dir = "uploads/company/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'stamp_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['stamp']['tmp_name'], $filepath)) {
                    // Delete old file if exists
                    if (!empty($settings['stamp_path']) && file_exists($settings['stamp_path'])) {
                        unlink($settings['stamp_path']);
                    }
                    $stamp_path = $filepath;
                } else {
                    $error = "Failed to upload stamp.";
                }
            }
        }
        
        // Handle QR code upload
        $qr_code_path = $settings['qr_code_path'] ?? '';
        if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $max_size = 1 * 1024 * 1024; // 1MB
            
            // Get file extension
            $file_ext = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png'];
            
            if (!in_array($_FILES['qr_code']['type'], $allowed_types) || !in_array($file_ext, $allowed_ext)) {
                $error = "Only JPG, JPEG and PNG images are allowed for QR code.";
            } elseif ($_FILES['qr_code']['size'] > $max_size) {
                $error = "QR code size must be less than 1MB.";
            } else {
                $upload_dir = "uploads/company/";
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $filename = 'qrcode_' . time() . '.' . $file_ext;
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $filepath)) {
                    // Delete old file if exists
                    if (!empty($settings['qr_code_path']) && file_exists($settings['qr_code_path'])) {
                        unlink($settings['qr_code_path']);
                    }
                    $qr_code_path = $filepath;
                } else {
                    $error = "Failed to upload QR code.";
                }
            }
        }
        
        if (empty($error)) {
            if ($settings) {
                // Update existing settings
                $update_query = "UPDATE company_settings SET 
                    company_name = ?, address = ?, phone = ?, alternate_phone = ?,
                    email = ?, website = ?, gst_no = ?, pan_no = ?,
                    logo_path = ?, signature_path = ?, stamp_path = ?, qr_code_path = ?
                    WHERE id = 1";
                
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, 'ssssssssssss', 
                    $company_name, $address, $phone, $alternate_phone,
                    $email, $website, $gst_no, $pan_no,
                    $logo_path, $signature_path, $stamp_path, $qr_code_path
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Company settings updated successfully!";
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                  VALUES (?, 'update', 'Updated company settings', 'company_settings')";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'i', $_SESSION['user_id']);
                    mysqli_stmt_execute($log_stmt);
                    
                } else {
                    $error = "Error updating settings: " . mysqli_error($conn);
                }
                
            } else {
                // Insert new settings
                $insert_query = "INSERT INTO company_settings (
                    company_name, address, phone, alternate_phone, email, website,
                    gst_no, pan_no, logo_path, signature_path, stamp_path, qr_code_path
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, 'ssssssssssss', 
                    $company_name, $address, $phone, $alternate_phone,
                    $email, $website, $gst_no, $pan_no,
                    $logo_path, $signature_path, $stamp_path, $qr_code_path
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Company settings saved successfully!";
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                  VALUES (?, 'create', 'Created company settings', 'company_settings')";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, 'i', $_SESSION['user_id']);
                    mysqli_stmt_execute($log_stmt);
                    
                } else {
                    $error = "Error saving settings: " . mysqli_error($conn);
                }
            }
            
            // Refresh settings
            $settings_result = mysqli_query($conn, "SELECT * FROM company_settings WHERE id = 1");
            $settings = mysqli_fetch_assoc($settings_result);
        }
    }
    
    // Handle image removal
    if (isset($_POST['action']) && $_POST['action'] === 'remove_image') {
        $image_type = $_POST['image_type'];
        $update_field = '';
        
        switch ($image_type) {
            case 'logo':
                $update_field = 'logo_path';
                break;
            case 'signature':
                $update_field = 'signature_path';
                break;
            case 'stamp':
                $update_field = 'stamp_path';
                break;
            case 'qr_code':
                $update_field = 'qr_code_path';
                break;
        }
        
        if (!empty($update_field) && $settings && !empty($settings[$update_field])) {
            // Delete file
            if (file_exists($settings[$update_field])) {
                unlink($settings[$update_field]);
            }
            
            // Update database
            $update_query = "UPDATE company_settings SET $update_field = NULL WHERE id = 1";
            if (mysqli_query($conn, $update_query)) {
                $message = ucfirst(str_replace('_', ' ', $image_type)) . " removed successfully!";
                
                // Refresh settings
                $settings_result = mysqli_query($conn, "SELECT * FROM company_settings WHERE id = 1");
                $settings = mysqli_fetch_assoc($settings_result);
            }
        }
    }
}

// Get all branches for reference
$branches_query = "SELECT * FROM branches ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);
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

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-warning {
            background: #ecc94b;
            color: white;
        }

        .btn-warning:hover {
            background: #d69e2e;
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

        .settings-card {
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
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .form-grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        textarea.form-control {
            padding-left: 40px;
            resize: vertical;
        }

        .image-upload-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .image-upload-card {
            background: #f7fafc;
            border-radius: 12px;
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
            border-radius: 8px;
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
            font-size: 32px;
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
            border-radius: 4px;
            padding: 5px;
            background: white;
        }

        .image-preview.qr-preview {
            max-height: 100px;
        }

        .image-preview.signature-preview {
            max-height: 60px;
        }

        .image-preview.stamp-preview {
            max-height: 80px;
        }

        .image-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 10px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .branch-list {
            margin-top: 20px;
        }

        .branch-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #f7fafc;
            transition: all 0.3s;
        }

        .branch-item:hover {
            background: white;
            border-color: #667eea;
        }

        .branch-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .branch-info p {
            font-size: 13px;
            color: #718096;
            margin-bottom: 3px;
        }

        .branch-status {
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }

        .branch-status.active {
            background: #48bb78;
            color: white;
        }

        .branch-status.inactive {
            background: #f56565;
            color: white;
        }

        .branch-actions {
            display: flex;
            gap: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
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

        @media (max-width: 1200px) {
            .image-upload-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .form-grid, .form-grid-3, .form-grid-4, .image-upload-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .branch-item {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .branch-actions {
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
                <div class="settings-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-gear"></i>
                            Company Settings
                        </h1>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Settings Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="settingsForm">
                        <input type="hidden" name="action" value="save_settings">

                        <!-- Company Information -->
                        <div class="settings-card">
                            <div class="section-title">
                                <i class="bi bi-building"></i>
                                Company Information
                            </div>

                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label class="form-label required">Company Name</label>
                                    <div class="input-group">
                                        <i class="bi bi-building input-icon"></i>
                                        <input type="text" class="form-control" name="company_name" 
                                               value="<?php echo htmlspecialchars($settings['company_name'] ?? 'PAWN BROKING SERVICES'); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label">Address</label>
                                    <div class="input-group">
                                        <i class="bi bi-geo-alt input-icon"></i>
                                        <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($settings['address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <div class="input-group">
                                        <i class="bi bi-telephone input-icon"></i>
                                        <input type="text" class="form-control" name="phone" 
                                               value="<?php echo htmlspecialchars($settings['phone'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Alternate Phone</label>
                                    <div class="input-group">
                                        <i class="bi bi-telephone-plus input-icon"></i>
                                        <input type="text" class="form-control" name="alternate_phone" 
                                               value="<?php echo htmlspecialchars($settings['alternate_phone'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <div class="input-group">
                                        <i class="bi bi-envelope input-icon"></i>
                                        <input type="email" class="form-control" name="email" 
                                               value="<?php echo htmlspecialchars($settings['email'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Website</label>
                                    <div class="input-group">
                                        <i class="bi bi-globe input-icon"></i>
                                        <input type="url" class="form-control" name="website" 
                                               value="<?php echo htmlspecialchars($settings['website'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tax Information -->
                        <div class="settings-card">
                            <div class="section-title">
                                <i class="bi bi-file-text"></i>
                                Tax Information
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">GST Number</label>
                                    <div class="input-group">
                                        <i class="bi bi-upc-scan input-icon"></i>
                                        <input type="text" class="form-control" name="gst_no" 
                                               value="<?php echo htmlspecialchars($settings['gst_no'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">PAN Number</label>
                                    <div class="input-group">
                                        <i class="bi bi-card-heading input-icon"></i>
                                        <input type="text" class="form-control" name="pan_no" 
                                               value="<?php echo htmlspecialchars($settings['pan_no'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Image Uploads - 4 Column Grid -->
                        <div class="settings-card">
                            <div class="section-title">
                                <i class="bi bi-images"></i>
                                Company Images
                            </div>

                            <div class="image-upload-grid">
                                <!-- Logo Upload -->
                                <div class="image-upload-card">
                                    <div class="image-upload-title">
                                        <i class="bi bi-building"></i> Company Logo
                                    </div>
                                    <div class="image-upload-area" onclick="document.getElementById('logo').click();">
                                        <?php if (!empty($settings['logo_path'])): ?>
                                            <img src="<?php echo $settings['logo_path']; ?>" class="image-preview" alt="Logo">
                                        <?php else: ?>
                                            <i class="bi bi-cloud-upload"></i>
                                            <p>Click to upload logo</p>
                                            <small>PNG, JPG, JPEG (Max 2MB)</small>
                                        <?php endif; ?>
                                        <input type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'logo-preview')">
                                    </div>
                                    <div class="format-badge">PNG, JPG, JPEG</div>
                                    <?php if (!empty($settings['logo_path'])): ?>
                                        <div class="image-actions">
                                            <button type="button" class="btn btn-info btn-sm" onclick="window.open('<?php echo $settings['logo_path']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('logo')">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Signature Upload -->
                                <div class="image-upload-card">
                                    <div class="image-upload-title">
                                        <i class="bi bi-pen"></i> Signature
                                    </div>
                                    <div class="image-upload-area" onclick="document.getElementById('signature').click();">
                                        <?php if (!empty($settings['signature_path'])): ?>
                                            <img src="<?php echo $settings['signature_path']; ?>" class="image-preview signature-preview" alt="Signature">
                                        <?php else: ?>
                                            <i class="bi bi-cloud-upload"></i>
                                            <p>Click to upload signature</p>
                                            <small>PNG, JPG, JPEG (Max 1MB)</small>
                                        <?php endif; ?>
                                        <input type="file" id="signature" name="signature" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'signature-preview')">
                                    </div>
                                    <div class="format-badge">PNG, JPG, JPEG</div>
                                    <?php if (!empty($settings['signature_path'])): ?>
                                        <div class="image-actions">
                                            <button type="button" class="btn btn-info btn-sm" onclick="window.open('<?php echo $settings['signature_path']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('signature')">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Stamp Upload -->
                                <div class="image-upload-card">
                                    <div class="image-upload-title">
                                        <i class="bi bi-stamp"></i> Company Stamp
                                    </div>
                                    <div class="image-upload-area" onclick="document.getElementById('stamp').click();">
                                        <?php if (!empty($settings['stamp_path'])): ?>
                                            <img src="<?php echo $settings['stamp_path']; ?>" class="image-preview stamp-preview" alt="Stamp">
                                        <?php else: ?>
                                            <i class="bi bi-cloud-upload"></i>
                                            <p>Click to upload stamp</p>
                                            <small>PNG, JPG, JPEG (Max 1MB)</small>
                                        <?php endif; ?>
                                        <input type="file" id="stamp" name="stamp" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'stamp-preview')">
                                    </div>
                                    <div class="format-badge">PNG, JPG, JPEG</div>
                                    <?php if (!empty($settings['stamp_path'])): ?>
                                        <div class="image-actions">
                                            <button type="button" class="btn btn-info btn-sm" onclick="window.open('<?php echo $settings['stamp_path']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('stamp')">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- QR Code Upload -->
                                <div class="image-upload-card">
                                    <div class="image-upload-title">
                                        <i class="bi bi-qr-code"></i> QR Code
                                    </div>
                                    <div class="image-upload-area" onclick="document.getElementById('qr_code').click();">
                                        <?php if (!empty($settings['qr_code_path'])): ?>
                                            <img src="<?php echo $settings['qr_code_path']; ?>" class="image-preview qr-preview" alt="QR Code">
                                        <?php else: ?>
                                            <i class="bi bi-cloud-upload"></i>
                                            <p>Click to upload QR code</p>
                                            <small>PNG, JPG, JPEG (Max 1MB)</small>
                                        <?php endif; ?>
                                        <input type="file" id="qr_code" name="qr_code" accept=".png,.jpg,.jpeg" style="display: none;" onchange="previewImage(this, 'qr-preview')">
                                    </div>
                                    <div class="format-badge">PNG, JPG, JPEG</div>
                                    <?php if (!empty($settings['qr_code_path'])): ?>
                                        <div class="image-actions">
                                            <button type="button" class="btn btn-info btn-sm" onclick="window.open('<?php echo $settings['qr_code_path']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeImage('qr_code')">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Branch Information (Reference Only) -->
                        <div class="settings-card">
                            <div class="section-title">
                                <i class="bi bi-diagram-3"></i>
                                Branch Information
                            </div>

                            <div class="branch-list">
                                <?php if ($branches_result && mysqli_num_rows($branches_result) > 0): ?>
                                    <?php while($branch = mysqli_fetch_assoc($branches_result)): ?>
                                        <div class="branch-item">
                                            <div class="branch-info">
                                                <h4><?php echo htmlspecialchars($branch['branch_name']); ?></h4>
                                                <p><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($branch['address'] ?? ''); ?></p>
                                                <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($branch['phone'] ?? ''); ?></p>
                                            </div>
                                            <div>
                                                <span class="branch-status <?php echo $branch['status']; ?>">
                                                    <?php echo ucfirst($branch['status']); ?>
                                                </span>
                                            </div>
                                            <div class="branch-actions">
                                                <a href="edit-branch.php?id=<?php echo $branch['id']; ?>" class="btn btn-info btn-sm">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <p style="text-align: center; color: #718096; padding: 20px;">No branches found. <a href="add-branch.php">Add a branch</a></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
                                <i class="bi bi-x-circle"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Image Removal Form -->
    <form id="removeImageForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="remove_image">
        <input type="hidden" name="image_type" id="remove_image_type">
    </form>

    <script>
        // Preview image before upload
        function previewImage(input, previewId) {
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
                    return;
                }
                
                // Check file size based on input id
                const maxSize = input.id === 'logo' ? 2 * 1024 * 1024 : 1 * 1024 * 1024;
                if (file.size > maxSize) {
                    const sizeMB = maxSize / (1024 * 1024);
                    alert(`File size must be less than ${sizeMB}MB!`);
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                const uploadArea = input.closest('.image-upload-area');
                
                reader.onload = function(e) {
                    // Remove any existing preview
                    const existingPreview = uploadArea.querySelector('img');
                    if (existingPreview) {
                        existingPreview.remove();
                    }
                    
                    // Remove icon and text
                    const icon = uploadArea.querySelector('i');
                    const p = uploadArea.querySelector('p');
                    const small = uploadArea.querySelector('small');
                    
                    if (icon) icon.remove();
                    if (p) p.remove();
                    if (small) small.remove();
                    
                    // Create new preview
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'image-preview';
                    
                    // Add specific class based on input id
                    if (input.id === 'signature') {
                        img.classList.add('signature-preview');
                    } else if (input.id === 'stamp') {
                        img.classList.add('stamp-preview');
                    } else if (input.id === 'qr_code') {
                        img.classList.add('qr-preview');
                    }
                    
                    uploadArea.appendChild(img);
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