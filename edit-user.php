<?php
session_start();
$currentPage = 'edit-user';
$pageTitle = 'Edit User';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// If no ID provided, redirect to users page
if ($user_id <= 0) {
    header('Location: users.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? 'sale';
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Personal Information
    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $gender = $_POST['gender'] ?? '';
    $marital_status = $_POST['marital_status'] ?? '';
    $blood_group = $_POST['blood_group'] ?? '';
    
    // Contact Information
    $mobile = $_POST['mobile'] ?? '';
    $email = $_POST['email'] ?? '';
    $emergency_contact = $_POST['emergency_contact'] ?? '';
    $emergency_contact_name = $_POST['emergency_contact_name'] ?? '';
    $emergency_relation = $_POST['emergency_relation'] ?? '';
    
    // Address Information
    $address_line1 = $_POST['address_line1'] ?? '';
    $address_line2 = $_POST['address_line2'] ?? '';
    $city = $_POST['city'] ?? '';
    $state = $_POST['state'] ?? '';
    $pincode = $_POST['pincode'] ?? '';
    
    $permanent_address_same = isset($_POST['permanent_address_same']) ? 1 : 0;
    
    if (!$permanent_address_same) {
        $permanent_address_line1 = $_POST['permanent_address_line1'] ?? '';
        $permanent_address_line2 = $_POST['permanent_address_line2'] ?? '';
        $permanent_city = $_POST['permanent_city'] ?? '';
        $permanent_state = $_POST['permanent_state'] ?? '';
        $permanent_pincode = $_POST['permanent_pincode'] ?? '';
    } else {
        $permanent_address_line1 = $address_line1;
        $permanent_address_line2 = $address_line2;
        $permanent_city = $city;
        $permanent_state = $state;
        $permanent_pincode = $pincode;
    }
    
    // Employment Information
    $employee_id = $_POST['employee_id'] ?? '';
    $department = $_POST['department'] ?? '';
    $designation = $_POST['designation'] ?? '';
    $joining_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : null;
    $confirmation_date = !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : null;
    $employment_type = $_POST['employment_type'] ?? 'full_time';
    $reporting_manager = $_POST['reporting_manager'] ?? '';
    $work_location = $_POST['work_location'] ?? '';
    $shift_timing = $_POST['shift_timing'] ?? '';
    $weekly_off = $_POST['weekly_off'] ?? 'sunday';
    
    // Experience
    $total_experience_years = intval($_POST['total_experience_years'] ?? 0);
    $total_experience_months = intval($_POST['total_experience_months'] ?? 0);
    $previous_company = $_POST['previous_company'] ?? '';
    $previous_designation = $_POST['previous_designation'] ?? '';
    $previous_experience_years = intval($_POST['previous_experience_years'] ?? 0);
    $previous_experience_months = intval($_POST['previous_experience_months'] ?? 0);
    
    // Education
    $highest_qualification = $_POST['highest_qualification'] ?? '';
    $university = $_POST['university'] ?? '';
    $year_of_passing = !empty($_POST['year_of_passing']) ? intval($_POST['year_of_passing']) : null;
    $percentage = !empty($_POST['percentage']) ? floatval($_POST['percentage']) : null;
    $skills = $_POST['skills'] ?? '';
    $certifications = $_POST['certifications'] ?? '';
    
    // Bank Details
    $account_holder_name = $_POST['account_holder_name'] ?? '';
    $bank_name = $_POST['bank_name'] ?? '';
    $branch_name = $_POST['branch_name'] ?? '';
    $account_number = $_POST['account_number'] ?? '';
    $ifsc_code = $_POST['ifsc_code'] ?? '';
    $micr_code = $_POST['micr_code'] ?? '';
    $account_type = $_POST['account_type'] ?? 'savings';
    $upi_id = $_POST['upi_id'] ?? '';
    
    // Statutory Details
    $pan_number = $_POST['pan_number'] ?? '';
    $aadhar_number = $_POST['aadhar_number'] ?? '';
    
    // Salary Details
    $basic_salary = !empty($_POST['basic_salary']) ? floatval($_POST['basic_salary']) : null;
    $hra = !empty($_POST['hra']) ? floatval($_POST['hra']) : null;
    $conveyance = !empty($_POST['conveyance']) ? floatval($_POST['conveyance']) : null;
    $medical_allowance = !empty($_POST['medical_allowance']) ? floatval($_POST['medical_allowance']) : null;
    $special_allowance = !empty($_POST['special_allowance']) ? floatval($_POST['special_allowance']) : null;
    $bonus = !empty($_POST['bonus']) ? floatval($_POST['bonus']) : null;
    
    // PF/ESI Details
    $pf_number = $_POST['pf_number'] ?? '';
    $esi_number = $_POST['esi_number'] ?? '';
    $uan_number = $_POST['uan_number'] ?? '';
    
    // Branch Assignment
    $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
    
    // Check if username already exists for other users
    $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 'si', $username, $user_id);
    mysqli_stmt_execute($check_stmt);
    mysqli_stmt_store_result($check_stmt);
    
    if (mysqli_stmt_num_rows($check_stmt) > 0) {
        $error = "Username already exists. Please choose a different username.";
    } else {
        // Handle photo upload
        $employee_photo = null;
        if (isset($_FILES['employee_photo']) && $_FILES['employee_photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['employee_photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/employees/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Delete old photo if exists
                $old_photo_query = "SELECT employee_photo FROM users WHERE id = ?";
                $old_photo_stmt = mysqli_prepare($conn, $old_photo_query);
                mysqli_stmt_bind_param($old_photo_stmt, 'i', $user_id);
                mysqli_stmt_execute($old_photo_stmt);
                $old_photo_result = mysqli_stmt_get_result($old_photo_stmt);
                $old_photo_data = mysqli_fetch_assoc($old_photo_result);
                
                if (!empty($old_photo_data['employee_photo']) && file_exists($old_photo_data['employee_photo'])) {
                    unlink($old_photo_data['employee_photo']);
                }
                
                $new_filename = 'emp_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $upload_path)) {
                    $employee_photo = $upload_path;
                }
            }
        }
        
        // Build update query without complex parameter binding
        $update_query = "UPDATE users SET 
            name = '" . mysqli_real_escape_string($conn, $name) . "',
            username = '" . mysqli_real_escape_string($conn, $username) . "',
            role = '" . mysqli_real_escape_string($conn, $role) . "',
            status = " . intval($status) . ",
            date_of_birth = " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ",
            gender = '" . mysqli_real_escape_string($conn, $gender) . "',
            marital_status = '" . mysqli_real_escape_string($conn, $marital_status) . "',
            blood_group = '" . mysqli_real_escape_string($conn, $blood_group) . "',
            mobile = '" . mysqli_real_escape_string($conn, $mobile) . "',
            email = '" . mysqli_real_escape_string($conn, $email) . "',
            emergency_contact = '" . mysqli_real_escape_string($conn, $emergency_contact) . "',
            emergency_contact_name = '" . mysqli_real_escape_string($conn, $emergency_contact_name) . "',
            emergency_relation = '" . mysqli_real_escape_string($conn, $emergency_relation) . "',
            address_line1 = '" . mysqli_real_escape_string($conn, $address_line1) . "',
            address_line2 = '" . mysqli_real_escape_string($conn, $address_line2) . "',
            city = '" . mysqli_real_escape_string($conn, $city) . "',
            state = '" . mysqli_real_escape_string($conn, $state) . "',
            pincode = '" . mysqli_real_escape_string($conn, $pincode) . "',
            permanent_address_same = $permanent_address_same,
            permanent_address_line1 = '" . mysqli_real_escape_string($conn, $permanent_address_line1) . "',
            permanent_address_line2 = '" . mysqli_real_escape_string($conn, $permanent_address_line2) . "',
            permanent_city = '" . mysqli_real_escape_string($conn, $permanent_city) . "',
            permanent_state = '" . mysqli_real_escape_string($conn, $permanent_state) . "',
            permanent_pincode = '" . mysqli_real_escape_string($conn, $permanent_pincode) . "',
            employee_id = '" . mysqli_real_escape_string($conn, $employee_id) . "',
            department = '" . mysqli_real_escape_string($conn, $department) . "',
            designation = '" . mysqli_real_escape_string($conn, $designation) . "',
            joining_date = " . ($joining_date ? "'$joining_date'" : "NULL") . ",
            confirmation_date = " . ($confirmation_date ? "'$confirmation_date'" : "NULL") . ",
            employment_type = '" . mysqli_real_escape_string($conn, $employment_type) . "',
            reporting_manager = '" . mysqli_real_escape_string($conn, $reporting_manager) . "',
            work_location = '" . mysqli_real_escape_string($conn, $work_location) . "',
            shift_timing = '" . mysqli_real_escape_string($conn, $shift_timing) . "',
            weekly_off = '" . mysqli_real_escape_string($conn, $weekly_off) . "',
            total_experience_years = $total_experience_years,
            total_experience_months = $total_experience_months,
            previous_company = '" . mysqli_real_escape_string($conn, $previous_company) . "',
            previous_designation = '" . mysqli_real_escape_string($conn, $previous_designation) . "',
            previous_experience_years = $previous_experience_years,
            previous_experience_months = $previous_experience_months,
            highest_qualification = '" . mysqli_real_escape_string($conn, $highest_qualification) . "',
            university = '" . mysqli_real_escape_string($conn, $university) . "',
            year_of_passing = " . ($year_of_passing ? $year_of_passing : "NULL") . ",
            percentage = " . ($percentage ? $percentage : "NULL") . ",
            skills = '" . mysqli_real_escape_string($conn, $skills) . "',
            certifications = '" . mysqli_real_escape_string($conn, $certifications) . "',
            account_holder_name = '" . mysqli_real_escape_string($conn, $account_holder_name) . "',
            bank_name = '" . mysqli_real_escape_string($conn, $bank_name) . "',
            branch_name = '" . mysqli_real_escape_string($conn, $branch_name) . "',
            account_number = '" . mysqli_real_escape_string($conn, $account_number) . "',
            ifsc_code = '" . mysqli_real_escape_string($conn, $ifsc_code) . "',
            micr_code = '" . mysqli_real_escape_string($conn, $micr_code) . "',
            account_type = '" . mysqli_real_escape_string($conn, $account_type) . "',
            upi_id = '" . mysqli_real_escape_string($conn, $upi_id) . "',
            pan_number = '" . mysqli_real_escape_string($conn, $pan_number) . "',
            aadhar_number = '" . mysqli_real_escape_string($conn, $aadhar_number) . "',
            basic_salary = " . ($basic_salary ? $basic_salary : "NULL") . ",
            hra = " . ($hra ? $hra : "NULL") . ",
            conveyance = " . ($conveyance ? $conveyance : "NULL") . ",
            medical_allowance = " . ($medical_allowance ? $medical_allowance : "NULL") . ",
            special_allowance = " . ($special_allowance ? $special_allowance : "NULL") . ",
            bonus = " . ($bonus ? $bonus : "NULL") . ",
            pf_number = '" . mysqli_real_escape_string($conn, $pf_number) . "',
            esi_number = '" . mysqli_real_escape_string($conn, $esi_number) . "',
            uan_number = '" . mysqli_real_escape_string($conn, $uan_number) . "',
            branch_id = " . ($branch_id ? $branch_id : "NULL");
        
        // Add password update if provided
        if (!empty($_POST['new_password'])) {
            $hashed_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $update_query .= ", password = '" . mysqli_real_escape_string($conn, $hashed_password) . "'";
        }
        
        // Add photo update if uploaded
        if ($employee_photo) {
            $update_query .= ", employee_photo = '" . mysqli_real_escape_string($conn, $employee_photo) . "'";
        }
        
        $update_query .= " WHERE id = $user_id";
        
        if (mysqli_query($conn, $update_query)) {
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (" . $_SESSION['user_id'] . ", 'update', 'Updated user: " . mysqli_real_escape_string($conn, $username) . "', 'users', $user_id)";
            mysqli_query($conn, $log_query);
            
            $success = "User updated successfully!";
            
            // Refresh page data
            header("Location: edit-user.php?id=$user_id&success=1");
            exit();
        } else {
            $error = "Error updating user: " . mysqli_error($conn);
        }
    }
}

// Get user data
$query = "SELECT u.*, b.branch_name 
          FROM users u 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE u.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    header('Location: users.php');
    exit();
}

// Get branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get departments
$depts_query = "SELECT department_name FROM departments WHERE status = 1 ORDER BY department_name";
$depts_result = mysqli_query($conn, $depts_query);

// Get managers for reporting manager dropdown
$managers_query = "SELECT id, name, role FROM users WHERE status = 'active' AND role IN ('admin', 'manager') AND id != ? ORDER BY name";
$managers_stmt = mysqli_prepare($conn, $managers_query);
mysqli_stmt_bind_param($managers_stmt, 'i', $user_id);
mysqli_stmt_execute($managers_stmt);
$managers_result = mysqli_stmt_get_result($managers_stmt);

// Check if success message should be shown
$show_success = isset($_GET['success']) && $_GET['success'] == 1;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .edit-user-container {
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
            display: flex;
            align-items: center;
            gap: 10px;
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

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-title {
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

        .form-title i {
            color: #667eea;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
            padding-left: 12px;
        }

        .readonly-field {
            background: #f7fafc;
            cursor: not-allowed;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .tab-btn {
            padding: 8px 16px;
            border: none;
            background: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
        }

        .tab-btn:hover {
            color: #667eea;
            background: #f7fafc;
        }

        .tab-btn.active {
            color: #667eea;
            background: #ebf4ff;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Profile Header */
        .profile-header {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border-radius: 12px;
            align-items: center;
        }

        .profile-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .profile-role {
            font-size: 14px;
            color: #718096;
        }

        .profile-meta {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .profile-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: #4a5568;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-active {
            background: #48bb78;
            color: white;
        }

        .badge-inactive {
            background: #f56565;
            color: white;
        }

        .badge-admin {
            background: #667eea;
            color: white;
        }

        .badge-manager {
            background: #ecc94b;
            color: #744210;
        }

        .badge-sale {
            background: #4299e1;
            color: white;
        }

        .badge-accountant {
            background: #9f7aea;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-btn {
                width: 100%;
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
                <div class="edit-user-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-pencil-square"></i>
                            Edit User
                        </h1>
                        <div>
                            <a href="users.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Users
                            </a>
                        </div>
                    </div>

                    <?php if ($show_success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            User updated successfully!
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Profile Header -->
                    <div class="profile-header">
                        <?php if (!empty($user['employee_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user['employee_photo']); ?>" class="profile-photo" alt="Profile">
                        <?php else: ?>
                            <div class="profile-photo" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; display: flex; align-items: center; justify-content: center; font-size: 36px;">
                                <?php 
                                $name_parts = explode(' ', $user['name']);
                                $initials = '';
                                foreach ($name_parts as $part) {
                                    if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                }
                                echo substr($initials, 0, 2);
                                ?>
                            </div>
                        <?php endif; ?>
                        <div class="profile-info">
                            <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
                            <div class="profile-role">
                                <span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
                                <span class="badge badge-<?php echo $user['status'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $user['status'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            <div class="profile-meta">
                                <span><i class="bi bi-person"></i> @<?php echo htmlspecialchars($user['username']); ?></span>
                                <?php if (!empty($user['mobile'])): ?>
                                    <span><i class="bi bi-phone"></i> <?php echo htmlspecialchars($user['mobile']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($user['email'])): ?>
                                    <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($user['branch_name'])): ?>
                                    <span><i class="bi bi-building"></i> <?php echo htmlspecialchars($user['branch_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="editUserForm">
                        <!-- Tabs -->
                        <div class="tabs">
                            <button type="button" class="tab-btn active" onclick="switchTab('basic')">Basic Info</button>
                            <button type="button" class="tab-btn" onclick="switchTab('personal')">Personal</button>
                            <button type="button" class="tab-btn" onclick="switchTab('contact')">Contact</button>
                            <button type="button" class="tab-btn" onclick="switchTab('address')">Address</button>
                            <button type="button" class="tab-btn" onclick="switchTab('employment')">Employment</button>
                            <button type="button" class="tab-btn" onclick="switchTab('education')">Education</button>
                            <button type="button" class="tab-btn" onclick="switchTab('bank')">Bank Details</button>
                            <button type="button" class="tab-btn" onclick="switchTab('salary')">Salary</button>
                        </div>

                        <!-- Basic Info Tab -->
                        <div class="tab-pane active" id="tab-basic">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-person"></i>
                                    Basic Information
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">Full Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-person input-icon"></i>
                                            <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Username</label>
                                        <div class="input-group">
                                            <i class="bi bi-at input-icon"></i>
                                            <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">New Password</label>
                                        <div class="input-group">
                                            <i class="bi bi-lock input-icon"></i>
                                            <input type="password" class="form-control" name="new_password" placeholder="Leave blank to keep current">
                                        </div>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Role</label>
                                        <div class="input-group">
                                            <i class="bi bi-shield input-icon"></i>
                                            <select class="form-select" name="role" <?php echo ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin') ? 'disabled' : ''; ?>>
                                                <option value="admin" <?php echo $user['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                <option value="manager" <?php echo $user['role'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                                <option value="sale" <?php echo $user['role'] == 'sale' ? 'selected' : ''; ?>>Sale</option>
                                                <option value="accountant" <?php echo $user['role'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                            </select>
                                        </div>
                                        <?php if ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin'): ?>
                                            <input type="hidden" name="role" value="admin">
                                            <small class="text-muted">You cannot change your own role</small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <div class="form-check form-switch" style="margin-top: 10px;">
                                            <input class="form-check-input" type="checkbox" name="status" id="status" value="1" <?php echo $user['status'] ? 'checked' : ''; ?> <?php echo ($user['id'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                            <label class="form-check-label" for="status">Active</label>
                                        </div>
                                        <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                            <small class="text-muted">You cannot deactivate your own account</small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Employee Photo</label>
                                        <div class="input-group">
                                            <i class="bi bi-camera input-icon"></i>
                                            <input type="file" class="form-control" name="employee_photo" accept="image/*">
                                        </div>
                                        <small class="text-muted">Leave blank to keep current photo</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Info Tab -->
                        <div class="tab-pane" id="tab-personal">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-person-heart"></i>
                                    Personal Information
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Date of Birth</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar input-icon"></i>
                                            <input type="date" class="form-control" name="date_of_birth" value="<?php echo $user['date_of_birth'] ?? ''; ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Gender</label>
                                        <div class="input-group">
                                            <i class="bi bi-gender-ambiguous input-icon"></i>
                                            <select class="form-select" name="gender">
                                                <option value="">Select</option>
                                                <option value="Male" <?php echo ($user['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo ($user['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other" <?php echo ($user['gender'] ?? '') == 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Marital Status</label>
                                        <div class="input-group">
                                            <i class="bi bi-heart input-icon"></i>
                                            <select class="form-select" name="marital_status">
                                                <option value="">Select</option>
                                                <option value="Single" <?php echo ($user['marital_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                                <option value="Married" <?php echo ($user['marital_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                                <option value="Divorced" <?php echo ($user['marital_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                                <option value="Widowed" <?php echo ($user['marital_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Blood Group</label>
                                        <div class="input-group">
                                            <i class="bi bi-droplet input-icon"></i>
                                            <select class="form-select" name="blood_group">
                                                <option value="">Select</option>
                                                <option value="A+" <?php echo ($user['blood_group'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                                <option value="A-" <?php echo ($user['blood_group'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                                <option value="B+" <?php echo ($user['blood_group'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                                <option value="B-" <?php echo ($user['blood_group'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                                <option value="O+" <?php echo ($user['blood_group'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                                <option value="O-" <?php echo ($user['blood_group'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                                                <option value="AB+" <?php echo ($user['blood_group'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                                <option value="AB-" <?php echo ($user['blood_group'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Tab -->
                        <div class="tab-pane" id="tab-contact">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-telephone"></i>
                                    Contact Information
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Mobile Number</label>
                                        <div class="input-group">
                                            <i class="bi bi-phone input-icon"></i>
                                            <input type="text" class="form-control" name="mobile" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <div class="input-group">
                                            <i class="bi bi-envelope input-icon"></i>
                                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact</label>
                                        <div class="input-group">
                                            <i class="bi bi-telephone-forward input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact" value="<?php echo htmlspecialchars($user['emergency_contact'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-person input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Relation</label>
                                        <div class="input-group">
                                            <i class="bi bi-people input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_relation" value="<?php echo htmlspecialchars($user['emergency_relation'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Tab -->
                        <div class="tab-pane" id="tab-address">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-house"></i>
                                    Current Address
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Address Line 1</label>
                                        <div class="input-group">
                                            <i class="bi bi-house-door input-icon"></i>
                                            <input type="text" class="form-control" name="address_line1" value="<?php echo htmlspecialchars($user['address_line1'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Address Line 2</label>
                                        <div class="input-group">
                                            <i class="bi bi-house-door input-icon"></i>
                                            <input type="text" class="form-control" name="address_line2" value="<?php echo htmlspecialchars($user['address_line2'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">City</label>
                                        <div class="input-group">
                                            <i class="bi bi-building input-icon"></i>
                                            <input type="text" class="form-control" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">State</label>
                                        <div class="input-group">
                                            <i class="bi bi-map input-icon"></i>
                                            <input type="text" class="form-control" name="state" value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Pincode</label>
                                        <div class="input-group">
                                            <i class="bi bi-pin-map input-icon"></i>
                                            <input type="text" class="form-control" name="pincode" value="<?php echo htmlspecialchars($user['pincode'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-title" style="margin-top: 30px;">
                                    <i class="bi bi-house-gear"></i>
                                    Permanent Address
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permanent_address_same" id="permanent_same" value="1" <?php echo ($user['permanent_address_same'] ?? 1) ? 'checked' : ''; ?> onchange="togglePermanentAddress()">
                                        <label class="form-check-label" for="permanent_same">
                                            Same as Current Address
                                        </label>
                                    </div>
                                </div>

                                <div id="permanent_address_fields" style="<?php echo ($user['permanent_address_same'] ?? 1) ? 'display: none;' : ''; ?>">
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" class="form-control" name="permanent_address_line1" value="<?php echo htmlspecialchars($user['permanent_address_line1'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" class="form-control" name="permanent_address_line2" value="<?php echo htmlspecialchars($user['permanent_address_line2'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">City</label>
                                            <input type="text" class="form-control" name="permanent_city" value="<?php echo htmlspecialchars($user['permanent_city'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">State</label>
                                            <input type="text" class="form-control" name="permanent_state" value="<?php echo htmlspecialchars($user['permanent_state'] ?? ''); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" class="form-control" name="permanent_pincode" value="<?php echo htmlspecialchars($user['permanent_pincode'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Employment Tab -->
                        <div class="tab-pane" id="tab-employment">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-briefcase"></i>
                                    Employment Details
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Employee ID</label>
                                        <div class="input-group">
                                            <i class="bi bi-card-text input-icon"></i>
                                            <input type="text" class="form-control" name="employee_id" value="<?php echo htmlspecialchars($user['employee_id'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Department</label>
                                        <div class="input-group">
                                            <i class="bi bi-diagram-3 input-icon"></i>
                                            <select class="form-select" name="department">
                                                <option value="">Select Department</option>
                                                <?php 
                                                if ($depts_result && mysqli_num_rows($depts_result) > 0) {
                                                    mysqli_data_seek($depts_result, 0);
                                                    while($dept = mysqli_fetch_assoc($depts_result)): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($dept['department_name']); ?>" <?php echo ($user['department'] ?? '') == $dept['department_name'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Designation</label>
                                        <div class="input-group">
                                            <i class="bi bi-person-badge input-icon"></i>
                                            <input type="text" class="form-control" name="designation" value="<?php echo htmlspecialchars($user['designation'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Joining Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-plus input-icon"></i>
                                            <input type="date" class="form-control" name="joining_date" value="<?php echo $user['joining_date'] ?? ''; ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Confirmation Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-check input-icon"></i>
                                            <input type="date" class="form-control" name="confirmation_date" value="<?php echo $user['confirmation_date'] ?? ''; ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Employment Type</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock input-icon"></i>
                                            <select class="form-select" name="employment_type">
                                                <option value="full_time" <?php echo ($user['employment_type'] ?? 'full_time') == 'full_time' ? 'selected' : ''; ?>>Full Time</option>
                                                <option value="part_time" <?php echo ($user['employment_type'] ?? '') == 'part_time' ? 'selected' : ''; ?>>Part Time</option>
                                                <option value="contract" <?php echo ($user['employment_type'] ?? '') == 'contract' ? 'selected' : ''; ?>>Contract</option>
                                                <option value="intern" <?php echo ($user['employment_type'] ?? '') == 'intern' ? 'selected' : ''; ?>>Intern</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Reporting Manager</label>
                                        <div class="input-group">
                                            <i class="bi bi-person-up input-icon"></i>
                                            <select class="form-select" name="reporting_manager">
                                                <option value="">Select Manager</option>
                                                <?php 
                                                if ($managers_result && mysqli_num_rows($managers_result) > 0) {
                                                    mysqli_data_seek($managers_result, 0);
                                                    while($manager = mysqli_fetch_assoc($managers_result)): 
                                                ?>
                                                    <option value="<?php echo htmlspecialchars($manager['name']); ?>" <?php echo ($user['reporting_manager'] ?? '') == $manager['name'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($manager['name']); ?> (<?php echo ucfirst($manager['role']); ?>)
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Work Location</label>
                                        <div class="input-group">
                                            <i class="bi bi-geo-alt input-icon"></i>
                                            <input type="text" class="form-control" name="work_location" value="<?php echo htmlspecialchars($user['work_location'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Shift Timing</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock-history input-icon"></i>
                                            <input type="text" class="form-control" name="shift_timing" placeholder="e.g., 9:00 AM - 6:00 PM" value="<?php echo htmlspecialchars($user['shift_timing'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Weekly Off</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-x input-icon"></i>
                                            <select class="form-select" name="weekly_off">
                                                <option value="sunday" <?php echo ($user['weekly_off'] ?? 'sunday') == 'sunday' ? 'selected' : ''; ?>>Sunday</option>
                                                <option value="monday" <?php echo ($user['weekly_off'] ?? '') == 'monday' ? 'selected' : ''; ?>>Monday</option>
                                                <option value="tuesday" <?php echo ($user['weekly_off'] ?? '') == 'tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                                <option value="wednesday" <?php echo ($user['weekly_off'] ?? '') == 'wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                                <option value="thursday" <?php echo ($user['weekly_off'] ?? '') == 'thursday' ? 'selected' : ''; ?>>Thursday</option>
                                                <option value="friday" <?php echo ($user['weekly_off'] ?? '') == 'friday' ? 'selected' : ''; ?>>Friday</option>
                                                <option value="saturday" <?php echo ($user['weekly_off'] ?? '') == 'saturday' ? 'selected' : ''; ?>>Saturday</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Branch</label>
                                        <div class="input-group">
                                            <i class="bi bi-building input-icon"></i>
                                            <select class="form-select" name="branch_id">
                                                <option value="">Select Branch</option>
                                                <?php 
                                                if ($branches_result && mysqli_num_rows($branches_result) > 0) {
                                                    mysqli_data_seek($branches_result, 0);
                                                    while($branch = mysqli_fetch_assoc($branches_result)): 
                                                ?>
                                                    <option value="<?php echo $branch['id']; ?>" <?php echo ($user['branch_id'] ?? '') == $branch['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($branch['branch_name']); ?>
                                                    </option>
                                                <?php 
                                                    endwhile;
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-title" style="margin-top: 30px;">
                                    <i class="bi bi-clock-history"></i>
                                    Experience
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Total Experience (Years)</label>
                                        <input type="number" class="form-control" name="total_experience_years" min="0" value="<?php echo intval($user['total_experience_years'] ?? 0); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Total Experience (Months)</label>
                                        <input type="number" class="form-control" name="total_experience_months" min="0" max="11" value="<?php echo intval($user['total_experience_months'] ?? 0); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Company</label>
                                        <input type="text" class="form-control" name="previous_company" value="<?php echo htmlspecialchars($user['previous_company'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Designation</label>
                                        <input type="text" class="form-control" name="previous_designation" value="<?php echo htmlspecialchars($user['previous_designation'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Exp (Years)</label>
                                        <input type="number" class="form-control" name="previous_experience_years" min="0" value="<?php echo intval($user['previous_experience_years'] ?? 0); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Exp (Months)</label>
                                        <input type="number" class="form-control" name="previous_experience_months" min="0" max="11" value="<?php echo intval($user['previous_experience_months'] ?? 0); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Education Tab -->
                        <div class="tab-pane" id="tab-education">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-book"></i>
                                    Education
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Highest Qualification</label>
                                        <input type="text" class="form-control" name="highest_qualification" value="<?php echo htmlspecialchars($user['highest_qualification'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">University</label>
                                        <input type="text" class="form-control" name="university" value="<?php echo htmlspecialchars($user['university'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Year of Passing</label>
                                        <input type="number" class="form-control" name="year_of_passing" min="1900" max="<?php echo date('Y'); ?>" value="<?php echo htmlspecialchars($user['year_of_passing'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Percentage/CGPA</label>
                                        <input type="number" class="form-control" name="percentage" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($user['percentage'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Skills</label>
                                        <textarea class="form-control" name="skills" rows="3" placeholder="Comma separated skills"><?php echo htmlspecialchars($user['skills'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Certifications</label>
                                        <textarea class="form-control" name="certifications" rows="3" placeholder="Certification details"><?php echo htmlspecialchars($user['certifications'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bank Details Tab -->
                        <div class="tab-pane" id="tab-bank">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-bank"></i>
                                    Bank Details
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Account Holder Name</label>
                                        <input type="text" class="form-control" name="account_holder_name" value="<?php echo htmlspecialchars($user['account_holder_name'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bank Name</label>
                                        <input type="text" class="form-control" name="bank_name" value="<?php echo htmlspecialchars($user['bank_name'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Branch Name</label>
                                        <input type="text" class="form-control" name="branch_name" value="<?php echo htmlspecialchars($user['branch_name'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Account Number</label>
                                        <input type="text" class="form-control" name="account_number" value="<?php echo htmlspecialchars($user['account_number'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">IFSC Code</label>
                                        <input type="text" class="form-control" name="ifsc_code" value="<?php echo htmlspecialchars($user['ifsc_code'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">MICR Code</label>
                                        <input type="text" class="form-control" name="micr_code" value="<?php echo htmlspecialchars($user['micr_code'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Account Type</label>
                                        <select class="form-select" name="account_type">
                                            <option value="savings" <?php echo ($user['account_type'] ?? 'savings') == 'savings' ? 'selected' : ''; ?>>Savings</option>
                                            <option value="current" <?php echo ($user['account_type'] ?? '') == 'current' ? 'selected' : ''; ?>>Current</option>
                                            <option value="salary" <?php echo ($user['account_type'] ?? '') == 'salary' ? 'selected' : ''; ?>>Salary</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">UPI ID</label>
                                        <input type="text" class="form-control" name="upi_id" value="<?php echo htmlspecialchars($user['upi_id'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Salary Tab -->
                        <div class="tab-pane" id="tab-salary">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-cash-stack"></i>
                                    Salary & Statutory Details
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">PAN Number</label>
                                        <input type="text" class="form-control" name="pan_number" value="<?php echo htmlspecialchars($user['pan_number'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Aadhar Number</label>
                                        <input type="text" class="form-control" name="aadhar_number" value="<?php echo htmlspecialchars($user['aadhar_number'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Basic Salary</label>
                                        <input type="number" class="form-control" name="basic_salary" step="0.01" min="0" value="<?php echo htmlspecialchars($user['basic_salary'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">HRA</label>
                                        <input type="number" class="form-control" name="hra" step="0.01" min="0" value="<?php echo htmlspecialchars($user['hra'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Conveyance</label>
                                        <input type="number" class="form-control" name="conveyance" step="0.01" min="0" value="<?php echo htmlspecialchars($user['conveyance'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Medical Allowance</label>
                                        <input type="number" class="form-control" name="medical_allowance" step="0.01" min="0" value="<?php echo htmlspecialchars($user['medical_allowance'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Special Allowance</label>
                                        <input type="number" class="form-control" name="special_allowance" step="0.01" min="0" value="<?php echo htmlspecialchars($user['special_allowance'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bonus</label>
                                        <input type="number" class="form-control" name="bonus" step="0.01" min="0" value="<?php echo htmlspecialchars($user['bonus'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">PF Number</label>
                                        <input type="text" class="form-control" name="pf_number" value="<?php echo htmlspecialchars($user['pf_number'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">ESI Number</label>
                                        <input type="text" class="form-control" name="esi_number" value="<?php echo htmlspecialchars($user['esi_number'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">UAN Number</label>
                                        <input type="text" class="form-control" name="uan_number" value="<?php echo htmlspecialchars($user['uan_number'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <a href="users.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success" onclick="return confirmUpdate()">
                                <i class="bi bi-check-circle"></i> Update User
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Toggle permanent address
        function togglePermanentAddress() {
            const checkbox = document.getElementById('permanent_same');
            const permAddress = document.getElementById('permanent_address_fields');
            
            if (checkbox.checked) {
                permAddress.style.display = 'none';
            } else {
                permAddress.style.display = 'block';
            }
        }

        // Confirm update with SweetAlert
        function confirmUpdate() {
            Swal.fire({
                title: 'Update User?',
                text: 'Are you sure you want to update this user?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Update',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('editUserForm').submit();
                }
            });
            return false;
        }

        // Show success message if redirected with success
        <?php if ($show_success): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'User updated successfully!',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        // Show error message if any
        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>

        // Password validation
        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            const passwordInput = this.querySelector('input[name="new_password"]');
            if (passwordInput && passwordInput.value.length > 0 && passwordInput.value.length < 6) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Password',
                    text: 'Password must be at least 6 characters long.'
                });
            }
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>