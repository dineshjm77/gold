<?php
session_start();
$currentPage = 'edit-customer';
$pageTitle = 'Edit Customer';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check role access
if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'sale') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get customer ID for editing
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($edit_id <= 0) {
    header('Location: Customer-Details.php?error=invalid');
    exit();
}

// Fetch customer data
$query = "SELECT * FROM customers WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $edit_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$customer_data = mysqli_fetch_assoc($result);

if (!$customer_data) {
    header('Location: Customer-Details.php?error=notfound');
    exit();
}

// Get existing mobile numbers for suggestions (excluding current customer)
$mobile_suggestions_query = "SELECT mobile_number, customer_name FROM customers WHERE mobile_number IS NOT NULL AND mobile_number != '' AND id != ? ORDER BY created_at DESC LIMIT 10";
$mobile_suggestions_stmt = mysqli_prepare($conn, $mobile_suggestions_query);
mysqli_stmt_bind_param($mobile_suggestions_stmt, 'i', $edit_id);
mysqli_stmt_execute($mobile_suggestions_stmt);
$mobile_suggestions_result = mysqli_stmt_get_result($mobile_suggestions_stmt);
$mobile_suggestions = [];
while ($row = mysqli_fetch_assoc($mobile_suggestions_result)) {
    $mobile_suggestions[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    
    // Customer Information
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $guardian_type = mysqli_real_escape_string($conn, $_POST['guardian_type'] ?? '');
    $guardian_name = mysqli_real_escape_string($conn, $_POST['guardian_name'] ?? '');
    $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number']);
    $alternate_mobile = mysqli_real_escape_string($conn, $_POST['alternate_mobile'] ?? '');
    $whatsapp_number = mysqli_real_escape_string($conn, $_POST['whatsapp_number'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    
    // Address Information
    $door_no = mysqli_real_escape_string($conn, $_POST['door_no'] ?? '');
    $house_name = mysqli_real_escape_string($conn, $_POST['house_name'] ?? '');
    $street_name = mysqli_real_escape_string($conn, $_POST['street_name']);
    $street_name1 = mysqli_real_escape_string($conn, $_POST['street_name1'] ?? '');
    $landmark = mysqli_real_escape_string($conn, $_POST['landmark'] ?? '');
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $pincode = mysqli_real_escape_string($conn, $_POST['pincode']);
    $post = mysqli_real_escape_string($conn, $_POST['post']);
    $taluk = mysqli_real_escape_string($conn, $_POST['taluk']);
    $district = mysqli_real_escape_string($conn, $_POST['district']);
    
    // KYC Information
    $aadhaar_number = mysqli_real_escape_string($conn, $_POST['aadhaar_number'] ?? '');
    
    // Bank Details
    $account_holder_name = mysqli_real_escape_string($conn, $_POST['account_holder_name'] ?? '');
    $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
    $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name'] ?? '');
    $account_number = mysqli_real_escape_string($conn, $_POST['account_number'] ?? '');
    $confirm_account_number = $_POST['confirm_account_number'] ?? '';
    $ifsc_code = mysqli_real_escape_string($conn, $_POST['ifsc_code'] ?? '');
    $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
    $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
    
    // Additional Information
    $company_name = mysqli_real_escape_string($conn, $_POST['company_name'] ?? '');
    $referral_person = mysqli_real_escape_string($conn, $_POST['referral_person'] ?? '');
    $referral_mobile = mysqli_real_escape_string($conn, $_POST['referral_mobile'] ?? '');
    $alert_message = mysqli_real_escape_string($conn, $_POST['alert_message'] ?? '');
    $loan_limit_amount = !empty($_POST['loan_limit_amount']) ? floatval($_POST['loan_limit_amount']) : 10000000.00;
    
    // Noted Person Information
    $is_noted_person = isset($_POST['is_noted_person']) ? 1 : 0;
    $noted_person_remarks = mysqli_real_escape_string($conn, $_POST['noted_person_remarks'] ?? '');

    // Validate account numbers if provided
    if (!empty($account_number) && $account_number !== $confirm_account_number) {
        $error = "Account numbers do not match.";
    }

    // Validate mobile number (required)
    if (empty($mobile_number)) {
        $error = "Mobile number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $mobile_number)) {
        $error = "Please enter a valid 10-digit mobile number.";
    }

    // Check if mobile number already exists for another customer
    if (empty($error)) {
        $check_query = "SELECT id FROM customers WHERE mobile_number = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 'si', $mobile_number, $customer_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Mobile number already exists for another customer. Please use a different number.";
        }
    }

    // Get old photo
    $old_photo = $customer_data['customer_photo'];

    // Handle photo upload or camera capture
    $customer_photo = $old_photo; // Default to old photo

    // Check for camera capture
    if (isset($_POST['captured_photo']) && !empty($_POST['captured_photo']) && strpos($_POST['captured_photo'], 'data:image') === 0) {
        $image_data = $_POST['captured_photo'];
        
        // Remove data:image/png;base64, part
        if (preg_match('/^data:image\/(\w+);base64,/', $image_data, $type)) {
            $image_data = substr($image_data, strpos($image_data, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, etc.
            
            if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
                $error = "Invalid image format. Only JPG and PNG are allowed.";
            } else {
                $image_data = base64_decode($image_data);
                if ($image_data === false) {
                    $error = "Failed to decode image.";
                }
            }
        } else {
            $error = "Invalid image data.";
        }
        
        if (empty($error)) {
            // Create customer-specific folder
            $upload_dir = "uploads/customers/";
            $customer_folder = $upload_dir . $customer_id . '/';
            
            if (!file_exists($customer_folder)) {
                mkdir($customer_folder, 0777, true);
            }
            
            // Delete old photo if exists
            if ($old_photo && file_exists($old_photo) && strpos($old_photo, 'temp/') === false) {
                unlink($old_photo);
            }
            
            $filename = 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $type;
            $filepath = $customer_folder . $filename;
            
            if (file_put_contents($filepath, $image_data)) {
                $customer_photo = $filepath;
            } else {
                $error = "Failed to save captured photo.";
            }
        }
    }
    // Handle file upload
    elseif (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($_FILES['customer_photo']['type'], $allowed_types)) {
            $error = "Only JPG and PNG images are allowed for photo.";
        } elseif ($_FILES['customer_photo']['size'] > $max_size) {
            $error = "Photo size must be less than 2MB.";
        } else {
            // Create customer-specific folder
            $upload_dir = "uploads/customers/";
            $customer_folder = $upload_dir . $customer_id . '/';
            
            if (!file_exists($customer_folder)) {
                mkdir($customer_folder, 0777, true);
            }
            
            // Delete old photo if exists
            if ($old_photo && file_exists($old_photo) && strpos($old_photo, 'temp/') === false) {
                unlink($old_photo);
            }
            
            $ext = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
            $filename = 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $filepath = $customer_folder . $filename;
            
            if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $filepath)) {
                $customer_photo = $filepath;
            } else {
                $error = "Failed to upload photo.";
            }
        }
    }
    // If "remove_photo" is checked, delete the photo
    elseif (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
        if ($old_photo && file_exists($old_photo) && strpos($old_photo, 'temp/') === false) {
            unlink($old_photo);
        }
        $customer_photo = null;
    }

    // If no errors, proceed with update
    if (empty($error)) {
        // Update customer
        $update_query = "UPDATE customers SET 
            customer_name = ?, 
            guardian_type = ?, 
            guardian_name = ?, 
            mobile_number = ?, 
            alternate_mobile = ?, 
            whatsapp_number = ?, 
            email = ?,
            door_no = ?, 
            house_name = ?, 
            street_name = ?, 
            street_name1 = ?, 
            landmark = ?, 
            location = ?, 
            pincode = ?, 
            post = ?, 
            taluk = ?, 
            district = ?,
            aadhaar_number = ?, 
            account_holder_name = ?, 
            bank_name = ?, 
            branch_name = ?, 
            account_number = ?, 
            ifsc_code = ?, 
            account_type = ?, 
            upi_id = ?,
            company_name = ?, 
            referral_person = ?, 
            referral_mobile = ?, 
            alert_message = ?, 
            loan_limit_amount = ?, 
            is_noted_person = ?, 
            noted_person_remarks = ?, 
            customer_photo = ?, 
            updated_at = NOW()
            WHERE id = ?";
            
        $update_stmt = mysqli_prepare($conn, $update_query);
        
        // Fix: Make sure the number of parameters matches
        mysqli_stmt_bind_param($update_stmt, 
            'sssssssssssssssssssssssssssssssssi',
            $customer_name, 
            $guardian_type, 
            $guardian_name,
            $mobile_number, 
            $alternate_mobile, 
            $whatsapp_number, 
            $email,
            $door_no, 
            $house_name, 
            $street_name, 
            $street_name1,
            $landmark, 
            $location, 
            $pincode, 
            $post, 
            $taluk, 
            $district,
            $aadhaar_number,
            $account_holder_name, 
            $bank_name, 
            $branch_name,
            $account_number, 
            $ifsc_code, 
            $account_type, 
            $upi_id,
            $company_name, 
            $referral_person, 
            $referral_mobile,
            $alert_message, 
            $loan_limit_amount, 
            $is_noted_person,
            $noted_person_remarks, 
            $customer_photo, 
            $customer_id
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (?, 'update', ?, 'customers', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_description = "Customer updated: " . $customer_name . " (ID: " . $customer_id . ")";
            mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $customer_id);
            mysqli_stmt_execute($log_stmt);
            
            // Set success message in session to display after redirect
            $_SESSION['success_message'] = "Customer updated successfully!";
            
            // Redirect to customer details page
            header('Location: Customer-Details.php?success=updated');
            exit();
        } else {
            $error = "Error updating customer: " . mysqli_error($conn);
        }
    }
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Include jQuery UI for autocomplete -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <style>
        /* Reset and Base Styles */
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

        /* Customer Container */
        .customer-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
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
            display: flex;
            align-items: center;
            gap: 15px;
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
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        .btn-warning {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        /* Alert Messages */
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

        /* Customer ID Badge */
        .customer-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .customer-id-badge span {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 18px;
        }

        /* Form Card */
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
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
            position: relative;
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

        /* Auto-suggestion dropdown */
        .ui-autocomplete {
            max-height: 200px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }

        .ui-menu-item {
            padding: 10px 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .ui-menu-item:last-child {
            border-bottom: none;
        }

        .ui-menu-item .ui-menu-item-wrapper {
            padding: 5px;
        }

        .ui-state-active, .ui-widget-content .ui-state-active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            margin: 0;
        }

        .suggestion-item {
            display: flex;
            flex-direction: column;
        }

        .suggestion-number {
            font-weight: 600;
            color: #2d3748;
        }

        .suggestion-name {
            font-size: 12px;
            color: #718096;
        }

        .ui-state-active .suggestion-number,
        .ui-state-active .suggestion-name {
            color: white;
        }

        /* Photo Section */
        .photo-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Current Photo Display */
        .current-photo-section {
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 16px;
            border: 2px solid #667eea30;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .current-photo {
            width: 120px;
            height: 120px;
            border-radius: 16px;
            object-fit: cover;
            border: 3px solid #667eea;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .current-photo-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            border: 3px solid #667eea;
        }

        .current-photo-info {
            flex: 1;
        }

        .current-photo-info h4 {
            font-size: 16px;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .current-photo-info p {
            color: #718096;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .remove-photo-btn {
            background: white;
            border: 2px solid #f56565;
            color: #f56565;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .remove-photo-btn:hover {
            background: #f56565;
            color: white;
        }

        /* Photo Upload Area */
        .photo-upload-area {
            border: 2px dashed #667eea;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .photo-upload-area:hover {
            border-color: #48bb78;
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.2);
        }

        .photo-upload-area i {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 15px;
        }

        .photo-upload-area:hover i {
            color: #48bb78;
        }

        .photo-upload-area p {
            margin: 0;
            color: #4a5568;
            font-weight: 500;
        }

        .photo-upload-area small {
            color: #718096;
            font-size: 12px;
            display: block;
            margin-top: 5px;
        }

        /* Camera Section */
        .camera-section {
            border: 2px dashed #48bb78;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
        }

        .camera-preview {
            width: 100%;
            max-width: 320px;
            height: 240px;
            margin: 0 auto 15px;
            border-radius: 12px;
            overflow: hidden;
            background: #000;
            display: none;
        }

        .camera-preview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .camera-preview canvas {
            display: none;
        }

        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .camera-btn {
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .camera-btn-start {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .camera-btn-capture {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .camera-btn-stop {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .camera-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* New Photo Preview */
        .new-photo-preview {
            margin-top: 20px;
            text-align: center;
            display: none;
        }

        .new-photo-preview.show {
            display: block;
        }

        .new-photo-preview img {
            width: 120px;
            height: 120px;
            border-radius: 16px;
            border: 3px solid #48bb78;
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.3);
            object-fit: cover;
        }

        .new-photo-preview p {
            margin-top: 10px;
            color: #48bb78;
            font-weight: 600;
            font-size: 14px;
        }

        /* Checkbox Styles */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
            padding: 10px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            border: 1px solid #667eea30;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-group label {
            font-weight: 600;
            color: #4a5568;
            cursor: pointer;
        }

        /* Remarks Field */
        .remarks-field {
            margin-top: 10px;
            display: none;
        }

        .remarks-field.show {
            display: block;
        }

        .remarks-field textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
            background: #f8fafc;
        }

        .remarks-field textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        /* Bank Details Grid */
        .bank-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
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

        /* Account Match Message */
        .account-match {
            font-size: 13px;
            margin-top: 5px;
        }

        .account-match.valid {
            color: #48bb78;
        }

        .account-match.invalid {
            color: #f56565;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .photo-section {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .page-title {
                font-size: 28px;
                flex-direction: column;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .customer-id-badge {
                width: 100%;
                justify-content: center;
            }
            
            .form-grid, .form-grid-2, .bank-details-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .current-photo-section {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .customer-container {
                padding: 0 10px;
            }
            
            .form-card {
                padding: 20px;
            }
            
            .photo-upload-area {
                padding: 20px;
            }
            
            .photo-upload-area i {
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
            <div class="customer-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-pencil-square" style="margin-right: 10px;"></i>
                        Edit Customer
                    </h1>
                    <div class="header-actions">
                        <div class="customer-id-badge">
                            <i class="bi bi-qr-code"></i>
                            Customer ID <span>#<?php echo str_pad($edit_id, 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <a href="Customer-Details.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to List
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

                <!-- Customer Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="customerForm">
                    <input type="hidden" name="customer_id" value="<?php echo $edit_id; ?>">
                    
                    <!-- Photo Section with Camera -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-camera"></i>
                            Customer Photo
                        </div>

                        <!-- Current Photo Display -->
                        <div class="current-photo-section">
                            <?php if (!empty($customer_data['customer_photo']) && file_exists($customer_data['customer_photo'])): ?>
                                <img src="<?php echo htmlspecialchars($customer_data['customer_photo']); ?>" class="current-photo" alt="Customer Photo">
                            <?php else: ?>
                                <div class="current-photo-placeholder">
                                    <i class="bi bi-person"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="current-photo-info">
                                <h4>Current Photo</h4>
                                <p>Upload a new photo below to replace the current one, or check the box to remove the photo.</p>
                                
                                <label class="remove-photo-btn">
                                    <input type="checkbox" name="remove_photo" value="1" id="removePhoto" style="display: none;">
                                    <i class="bi bi-trash"></i>
                                    <span id="removePhotoText">Remove Current Photo</span>
                                </label>
                            </div>
                        </div>

                        <!-- New Photo Preview -->
                        <div class="new-photo-preview" id="newPhotoPreview">
                            <img id="newPhotoImg" src="#" alt="New Photo">
                            <p>New Photo Preview</p>
                        </div>

                        <div class="photo-section">
                            <!-- Upload Section -->
                            <div class="form-group">
                                <label class="form-label">Upload New Photo</label>
                                <div class="photo-upload-area" onclick="document.getElementById('customer_photo').click();">
                                    <i class="bi bi-cloud-upload"></i>
                                    <p>Click to upload new photo</p>
                                    <small>JPG, PNG (Max 2MB)</small>
                                    <input type="file" id="customer_photo" name="customer_photo" accept="image/*" style="display: none;" onchange="previewNewPhoto(this)">
                                </div>
                            </div>

                            <!-- Camera Capture Section -->
                            <div class="camera-section">
                                <label class="form-label">Or Take Photo with Camera</label>
                                
                                <!-- Camera Preview -->
                                <div class="camera-preview" id="cameraPreview">
                                    <video id="video" autoplay playsinline></video>
                                    <canvas id="canvas" style="display: none;"></canvas>
                                </div>
                                
                                <!-- Camera Controls -->
                                <div class="camera-controls">
                                    <button type="button" class="camera-btn camera-btn-start" id="startCameraBtn">
                                        <i class="bi bi-camera-video"></i> Start Camera
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-capture" id="capturePhotoBtn" disabled>
                                        <i class="bi bi-camera"></i> Capture
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-stop" id="stopCameraBtn" disabled>
                                        <i class="bi bi-stop-circle"></i> Stop
                                    </button>
                                </div>
                                
                                <!-- Camera Status -->
                                <div id="cameraStatus" style="font-size: 12px; color: #718096; margin-top: 10px; text-align: center;">
                                    Camera is off
                                </div>
                                
                                <input type="hidden" name="captured_photo" id="capturedPhoto">
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-person"></i>
                            Personal Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label required">Customer Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person-badge input-icon"></i>
                                    <input type="text" class="form-control" name="customer_name" 
                                           value="<?php echo htmlspecialchars($customer_data['customer_name']); ?>" 
                                           placeholder="Enter full name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Guardian Type</label>
                                <div class="input-group">
                                    <i class="bi bi-people input-icon"></i>
                                    <select class="form-select" name="guardian_type">
                                        <option value="">Select Type</option>
                                        <option value="Father" <?php echo ($customer_data['guardian_type'] == 'Father') ? 'selected' : ''; ?>>Father</option>
                                        <option value="Mother" <?php echo ($customer_data['guardian_type'] == 'Mother') ? 'selected' : ''; ?>>Mother</option>
                                        <option value="Husband" <?php echo ($customer_data['guardian_type'] == 'Husband') ? 'selected' : ''; ?>>Husband</option>
                                        <option value="Wife" <?php echo ($customer_data['guardian_type'] == 'Wife') ? 'selected' : ''; ?>>Wife</option>
                                        <option value="Son" <?php echo ($customer_data['guardian_type'] == 'Son') ? 'selected' : ''; ?>>Son</option>
                                        <option value="Daughter" <?php echo ($customer_data['guardian_type'] == 'Daughter') ? 'selected' : ''; ?>>Daughter</option>
                                        <option value="Brother" <?php echo ($customer_data['guardian_type'] == 'Brother') ? 'selected' : ''; ?>>Brother</option>
                                        <option value="Sister" <?php echo ($customer_data['guardian_type'] == 'Sister') ? 'selected' : ''; ?>>Sister</option>
                                        <option value="Other" <?php echo ($customer_data['guardian_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Guardian Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" class="form-control" name="guardian_name" 
                                           value="<?php echo htmlspecialchars($customer_data['guardian_name'] ?? ''); ?>" 
                                           placeholder="Enter guardian name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Mobile Number</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" class="form-control" name="mobile_number" id="mobile_number"
                                           value="<?php echo htmlspecialchars($customer_data['mobile_number']); ?>" 
                                           placeholder="Enter 10-digit mobile number" maxlength="10" required
                                           autocomplete="off">
                                </div>
                                <small style="color: #718096; margin-top: 5px; display: block;">
                                    <i class="bi bi-info-circle"></i> Start typing for suggestions from other customers
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alternate Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone-flip input-icon"></i>
                                    <input type="tel" class="form-control" name="alternate_mobile" 
                                           value="<?php echo htmlspecialchars($customer_data['alternate_mobile'] ?? ''); ?>" 
                                           placeholder="Enter alternate number" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">WhatsApp Number</label>
                                <div class="input-group">
                                    <i class="bi bi-whatsapp input-icon"></i>
                                    <input type="tel" class="form-control" name="whatsapp_number" 
                                           value="<?php echo htmlspecialchars($customer_data['whatsapp_number'] ?? ''); ?>" 
                                           placeholder="Enter WhatsApp number" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo htmlspecialchars($customer_data['email'] ?? ''); ?>" 
                                           placeholder="Enter email address">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-house-door"></i>
                            Address Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Door No</label>
                                <div class="input-group">
                                    <i class="bi bi-hash input-icon"></i>
                                    <input type="text" class="form-control" name="door_no" 
                                           value="<?php echo htmlspecialchars($customer_data['door_no'] ?? ''); ?>" 
                                           placeholder="Enter door number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">House Name</label>
                                <div class="input-group">
                                    <i class="bi bi-house input-icon"></i>
                                    <input type="text" class="form-control" name="house_name" 
                                           value="<?php echo htmlspecialchars($customer_data['house_name'] ?? ''); ?>" 
                                           placeholder="Enter house name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Street Name</label>
                                <div class="input-group">
                                    <i class="bi bi-signpost input-icon"></i>
                                    <input type="text" class="form-control" name="street_name" 
                                           value="<?php echo htmlspecialchars($customer_data['street_name']); ?>" 
                                           placeholder="Enter street name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Street Name 2</label>
                                <div class="input-group">
                                    <i class="bi bi-signpost-2 input-icon"></i>
                                    <input type="text" class="form-control" name="street_name1" 
                                           value="<?php echo htmlspecialchars($customer_data['street_name1'] ?? ''); ?>" 
                                           placeholder="Enter additional street info">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Landmark</label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <input type="text" class="form-control" name="landmark" 
                                           value="<?php echo htmlspecialchars($customer_data['landmark'] ?? ''); ?>" 
                                           placeholder="Enter landmark">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Location</label>
                                <div class="input-group">
                                    <i class="bi bi-pin-map input-icon"></i>
                                    <input type="text" class="form-control" name="location" 
                                           value="<?php echo htmlspecialchars($customer_data['location']); ?>" 
                                           placeholder="Enter location" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Pincode</label>
                                <div class="input-group">
                                    <i class="bi bi-mailbox input-icon"></i>
                                    <input type="text" class="form-control" name="pincode" 
                                           value="<?php echo htmlspecialchars($customer_data['pincode']); ?>" 
                                           placeholder="Enter pincode" maxlength="6" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Post</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope-paper input-icon"></i>
                                    <input type="text" class="form-control" name="post" 
                                           value="<?php echo htmlspecialchars($customer_data['post']); ?>" 
                                           placeholder="Enter post office" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Taluk</label>
                                <div class="input-group">
                                    <i class="bi bi-diagram-3 input-icon"></i>
                                    <input type="text" class="form-control" name="taluk" 
                                           value="<?php echo htmlspecialchars($customer_data['taluk']); ?>" 
                                           placeholder="Enter taluk" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">District</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" class="form-control" name="district" 
                                           value="<?php echo htmlspecialchars($customer_data['district']); ?>" 
                                           placeholder="Enter district" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KYC Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-shield-check"></i>
                            KYC Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Aadhaar Number</label>
                                <div class="input-group">
                                    <i class="bi bi-person-vcard input-icon"></i>
                                    <input type="text" class="form-control" name="aadhaar_number" 
                                           value="<?php echo htmlspecialchars($customer_data['aadhaar_number'] ?? ''); ?>" 
                                           placeholder="Enter 12-digit Aadhaar number" maxlength="12">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bank Details -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-bank"></i>
                            Bank Details (Optional)
                        </div>

                        <div class="bank-details-grid">
                            <div class="form-group">
                                <label class="form-label">Account Holder Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" class="form-control" name="account_holder_name" 
                                           value="<?php echo htmlspecialchars($customer_data['account_holder_name'] ?? ''); ?>" 
                                           placeholder="As per bank records">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bank Name</label>
                                <div class="input-group">
                                    <i class="bi bi-bank input-icon"></i>
                                    <input type="text" class="form-control" name="bank_name" 
                                           value="<?php echo htmlspecialchars($customer_data['bank_name'] ?? ''); ?>" 
                                           placeholder="Enter bank name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Branch Name</label>
                                <div class="input-group">
                                    <i class="bi bi-diagram-3 input-icon"></i>
                                    <input type="text" class="form-control" name="branch_name" 
                                           value="<?php echo htmlspecialchars($customer_data['branch_name'] ?? ''); ?>" 
                                           placeholder="Enter branch name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Account Number</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card input-icon"></i>
                                    <input type="text" class="form-control" name="account_number" id="account_number"
                                           value="<?php echo htmlspecialchars($customer_data['account_number'] ?? ''); ?>" 
                                           placeholder="Enter account number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Confirm Account Number</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card-2-back input-icon"></i>
                                    <input type="text" class="form-control" name="confirm_account_number" id="confirm_account_number"
                                           placeholder="Re-enter account number">
                                </div>
                                <div class="account-match" id="accountMatch"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">IFSC Code</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" class="form-control" name="ifsc_code" 
                                           value="<?php echo htmlspecialchars($customer_data['ifsc_code'] ?? ''); ?>" 
                                           placeholder="Enter IFSC code" maxlength="11">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Account Type</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select class="form-select" name="account_type">
                                        <option value="savings" <?php echo ($customer_data['account_type'] ?? '') == 'savings' ? 'selected' : ''; ?>>Savings Account</option>
                                        <option value="current" <?php echo ($customer_data['account_type'] ?? '') == 'current' ? 'selected' : ''; ?>>Current Account</option>
                                        <option value="salary" <?php echo ($customer_data['account_type'] ?? '') == 'salary' ? 'selected' : ''; ?>>Salary Account</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">UPI ID</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" class="form-control" name="upi_id" 
                                           value="<?php echo htmlspecialchars($customer_data['upi_id'] ?? ''); ?>" 
                                           placeholder="Enter UPI ID (optional)">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Additional Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Company Name</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" class="form-control" name="company_name" 
                                           value="<?php echo htmlspecialchars($customer_data['company_name'] ?? ''); ?>" 
                                           placeholder="Enter company name (if applicable)">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Referral Person</label>
                                <div class="input-group">
                                    <i class="bi bi-person-plus input-icon"></i>
                                    <input type="text" class="form-control" name="referral_person" 
                                           value="<?php echo htmlspecialchars($customer_data['referral_person'] ?? ''); ?>" 
                                           placeholder="Enter referral name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Referral Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" class="form-control" name="referral_mobile" 
                                           value="<?php echo htmlspecialchars($customer_data['referral_mobile'] ?? ''); ?>" 
                                           placeholder="Enter referral mobile" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alert Message</label>
                                <div class="input-group">
                                    <i class="bi bi-exclamation-triangle input-icon"></i>
                                    <input type="text" class="form-control" name="alert_message" 
                                           value="<?php echo htmlspecialchars($customer_data['alert_message'] ?? ''); ?>" 
                                           placeholder="Enter any alert message">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Loan Limit Amount (₹)</label>
                                <div class="input-group">
                                    <i class="bi bi-cash-stack input-icon"></i>
                                    <input type="number" class="form-control" name="loan_limit_amount" 
                                           value="<?php echo htmlspecialchars($customer_data['loan_limit_amount'] ?? '10000000'); ?>" 
                                           placeholder="Enter loan limit" step="1000" min="0">
                                </div>
                            </div>
                        </div>

                        <!-- Noted Person Checkbox -->
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_noted_person" name="is_noted_person" 
                                   <?php echo ($customer_data['is_noted_person'] == 1) ? 'checked' : ''; ?> 
                                   onchange="toggleNotedPersonRemarks()">
                            <label for="is_noted_person">Mark as Noted Person</label>
                        </div>

                        <!-- Noted Person Remarks -->
                        <div class="remarks-field <?php echo ($customer_data['is_noted_person'] == 1) ? 'show' : ''; ?>" id="notedPersonRemarks">
                            <label class="form-label">Remarks for Noted Person</label>
                            <textarea name="noted_person_remarks" placeholder="Enter remarks for noted person..."><?php echo htmlspecialchars($customer_data['noted_person_remarks'] ?? ''); ?></textarea>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <a href="Customer-Details.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i>
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i>
                                Update Customer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<script>
    // Mobile number autocomplete (excluding current customer)
    $(function() {
        var mobileSuggestions = [
            <?php foreach ($mobile_suggestions as $suggestion): ?>
            {
                label: '<?php echo addslashes($suggestion['mobile_number']); ?> - <?php echo addslashes($suggestion['customer_name']); ?>',
                value: '<?php echo addslashes($suggestion['mobile_number']); ?>',
                name: '<?php echo addslashes($suggestion['customer_name']); ?>'
            },
            <?php endforeach; ?>
        ];

        $("#mobile_number").autocomplete({
            source: mobileSuggestions,
            minLength: 1,
            select: function(event, ui) {
                if (confirm('Do you want to use this mobile number? This will not copy other customer details.')) {
                    $("#mobile_number").val(ui.item.value);
                }
                return false;
            }
        }).autocomplete("instance")._renderItem = function(ul, item) {
            return $("<li>")
                .append("<div class='suggestion-item'><span class='suggestion-number'>" + item.value + "</span><span class='suggestion-name'>" + item.name + "</span></div>")
                .appendTo(ul);
        };
    });

    // Camera functionality
    let videoStream = null;
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const cameraPreview = document.getElementById('cameraPreview');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const captureBtn = document.getElementById('capturePhotoBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const capturedPhotoInput = document.getElementById('capturedPhoto');
    const newPhotoPreview = document.getElementById('newPhotoPreview');
    const newPhotoImg = document.getElementById('newPhotoImg');
    const cameraStatus = document.getElementById('cameraStatus');

    // Remove Photo Checkbox
    const removePhotoCheckbox = document.getElementById('removePhoto');
    const removePhotoText = document.getElementById('removePhotoText');

    if (removePhotoCheckbox) {
        removePhotoCheckbox.addEventListener('change', function() {
            if (this.checked) {
                removePhotoText.innerHTML = '<i class="bi bi-trash"></i> Photo will be removed';
                // Clear any new photo selections
                document.getElementById('customer_photo').value = '';
                capturedPhotoInput.value = '';
                newPhotoPreview.classList.remove('show');
            } else {
                removePhotoText.innerHTML = '<i class="bi bi-trash"></i> Remove Current Photo';
            }
        });
    }

    // Start Camera
    if (startCameraBtn) {
        startCameraBtn.addEventListener('click', async function() {
            try {
                cameraStatus.textContent = 'Requesting camera access...';
                
                videoStream = await navigator.mediaDevices.getUserMedia({ 
                    video: true, 
                    audio: false 
                });
                
                video.srcObject = videoStream;
                cameraPreview.style.display = 'block';
                
                startCameraBtn.disabled = true;
                captureBtn.disabled = false;
                stopBtn.disabled = false;
                
                cameraStatus.textContent = 'Camera is active - Click Capture to take photo';
                
            } catch (err) {
                console.error('Camera error:', err);
                let errorMsg = 'Error accessing camera: ';
                
                if (err.name === 'NotAllowedError') {
                    errorMsg = 'Camera access denied. Please allow camera access in your browser settings.';
                } else if (err.name === 'NotFoundError') {
                    errorMsg = 'No camera found on this device.';
                } else {
                    errorMsg += err.message;
                }
                
                alert(errorMsg);
                cameraStatus.textContent = 'Camera error - Please try again';
            }
        });
    }

    // Capture Photo
    if (captureBtn) {
        captureBtn.addEventListener('click', function() {
            if (!videoStream || video.videoWidth === 0) {
                alert('Camera is not ready. Please wait or restart camera.');
                return;
            }
            
            try {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                
                const context = canvas.getContext('2d');
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                const imageData = canvas.toDataURL('image/jpeg', 0.9);
                capturedPhotoInput.value = imageData;
                
                // Show preview
                newPhotoImg.src = imageData;
                newPhotoPreview.classList.add('show');
                
                // Uncheck remove photo if checked
                if (removePhotoCheckbox && removePhotoCheckbox.checked) {
                    removePhotoCheckbox.checked = false;
                    removePhotoText.innerHTML = '<i class="bi bi-trash"></i> Remove Current Photo';
                }
                
                cameraStatus.textContent = 'Photo captured successfully!';
                
            } catch (err) {
                console.error('Capture error:', err);
                alert('Failed to capture photo: ' + err.message);
            }
        });
    }

    // Stop Camera
    if (stopBtn) {
        stopBtn.addEventListener('click', function() {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                video.srcObject = null;
                cameraPreview.style.display = 'none';
                
                startCameraBtn.disabled = false;
                captureBtn.disabled = true;
                stopBtn.disabled = true;
                
                cameraStatus.textContent = 'Camera is off';
                videoStream = null;
            }
        });
    }

    // Preview new uploaded photo
    function previewNewPhoto(input) {
        if (input.files && input.files[0]) {
            // Check file size
            if (input.files[0].size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                input.value = '';
                return;
            }
            
            // Check file type
            if (!input.files[0].type.match('image.*')) {
                alert('Please select an image file');
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                newPhotoImg.src = e.target.result;
                newPhotoPreview.classList.add('show');
                
                // Clear captured photo data
                capturedPhotoInput.value = '';
                
                // Uncheck remove photo if checked
                if (removePhotoCheckbox && removePhotoCheckbox.checked) {
                    removePhotoCheckbox.checked = false;
                    removePhotoText.innerHTML = '<i class="bi bi-trash"></i> Remove Current Photo';
                }
            }
            
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Toggle noted person remarks
    function toggleNotedPersonRemarks() {
        const checkbox = document.getElementById('is_noted_person');
        const remarks = document.getElementById('notedPersonRemarks');
        
        if (checkbox.checked) {
            remarks.classList.add('show');
        } else {
            remarks.classList.remove('show');
        }
    }

    // Account number match checker
    const accountNumber = document.getElementById('account_number');
    const confirmAccount = document.getElementById('confirm_account_number');
    const accountMatch = document.getElementById('accountMatch');
    
    if (confirmAccount) {
        confirmAccount.addEventListener('input', function() {
            const accountNum = accountNumber ? accountNumber.value : '';
            const confirm = this.value;
            
            if (confirm.length === 0) {
                if (accountMatch) {
                    accountMatch.textContent = '';
                    accountMatch.className = 'account-match';
                }
                return;
            }
            
            if (accountMatch) {
                if (accountNum === confirm) {
                    accountMatch.textContent = '✓ Account numbers match';
                    accountMatch.className = 'account-match valid';
                } else {
                    accountMatch.textContent = '✗ Account numbers do not match';
                    accountMatch.className = 'account-match invalid';
                }
            }
        });
    }

    // Form validation
    document.getElementById('customerForm').addEventListener('submit', function(e) {
        const mobile = document.querySelector('input[name="mobile_number"]').value;
        const accountNum = accountNumber ? accountNumber.value : '';
        const confirmAccountVal = confirmAccount ? confirmAccount.value : '';
        
        // Validate mobile number
        if (!mobile || mobile.length !== 10 || isNaN(mobile)) {
            e.preventDefault();
            alert('Please enter a valid 10-digit mobile number');
            document.querySelector('input[name="mobile_number"]').focus();
            return;
        }
        
        // Validate account numbers if provided
        if (accountNum && accountNum !== confirmAccountVal) {
            e.preventDefault();
            alert('Account numbers do not match');
            if (confirmAccount) confirmAccount.focus();
            return;
        }
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);

    // Clean up camera when page is unloaded
    window.addEventListener('beforeunload', function() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>