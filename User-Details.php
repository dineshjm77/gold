<?php
session_start();
$currentPage = 'user-details';
$pageTitle = 'User Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_user':
                // Validate required fields
                $username = mysqli_real_escape_string($conn, $_POST['username']);
                $name = mysqli_real_escape_string($conn, $_POST['name']);
                $role = mysqli_real_escape_string($conn, $_POST['role']);
                $status = mysqli_real_escape_string($conn, $_POST['status']);
                $mobile = mysqli_real_escape_string($conn, $_POST['mobile'] ?? '');
                $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                
                // Personal Information
                $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
                $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? '');
                $marital_status = mysqli_real_escape_string($conn, $_POST['marital_status'] ?? '');
                $blood_group = mysqli_real_escape_string($conn, $_POST['blood_group'] ?? '');
                
                // Emergency Contact
                $emergency_contact = mysqli_real_escape_string($conn, $_POST['emergency_contact'] ?? '');
                $emergency_contact_name = mysqli_real_escape_string($conn, $_POST['emergency_contact_name'] ?? '');
                $emergency_relation = mysqli_real_escape_string($conn, $_POST['emergency_relation'] ?? '');
                
                // Address Information
                $address_line1 = mysqli_real_escape_string($conn, $_POST['address_line1'] ?? '');
                $address_line2 = mysqli_real_escape_string($conn, $_POST['address_line2'] ?? '');
                $city = mysqli_real_escape_string($conn, $_POST['city'] ?? '');
                $state = mysqli_real_escape_string($conn, $_POST['state'] ?? '');
                $pincode = mysqli_real_escape_string($conn, $_POST['pincode'] ?? '');
                
                $permanent_address_same = isset($_POST['permanent_address_same']) ? 1 : 0;
                
                if (!$permanent_address_same) {
                    $permanent_address_line1 = mysqli_real_escape_string($conn, $_POST['permanent_address_line1'] ?? '');
                    $permanent_address_line2 = mysqli_real_escape_string($conn, $_POST['permanent_address_line2'] ?? '');
                    $permanent_city = mysqli_real_escape_string($conn, $_POST['permanent_city'] ?? '');
                    $permanent_state = mysqli_real_escape_string($conn, $_POST['permanent_state'] ?? '');
                    $permanent_pincode = mysqli_real_escape_string($conn, $_POST['permanent_pincode'] ?? '');
                } else {
                    $permanent_address_line1 = $address_line1;
                    $permanent_address_line2 = $address_line2;
                    $permanent_city = $city;
                    $permanent_state = $state;
                    $permanent_pincode = $pincode;
                }
                
                // Employment Information
                $employee_id = mysqli_real_escape_string($conn, $_POST['employee_id'] ?? '');
                $department = mysqli_real_escape_string($conn, $_POST['department'] ?? '');
                $designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
                $joining_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : null;
                $confirmation_date = !empty($_POST['confirmation_date']) ? $_POST['confirmation_date'] : null;
                $employment_type = mysqli_real_escape_string($conn, $_POST['employment_type'] ?? 'full_time');
                $reporting_manager = mysqli_real_escape_string($conn, $_POST['reporting_manager'] ?? '');
                $work_location = mysqli_real_escape_string($conn, $_POST['work_location'] ?? '');
                $shift_timing = mysqli_real_escape_string($conn, $_POST['shift_timing'] ?? '');
                $weekly_off = mysqli_real_escape_string($conn, $_POST['weekly_off'] ?? 'sunday');
                
                // Experience
                $total_experience_years = intval($_POST['total_experience_years'] ?? 0);
                $total_experience_months = intval($_POST['total_experience_months'] ?? 0);
                $previous_company = mysqli_real_escape_string($conn, $_POST['previous_company'] ?? '');
                $previous_designation = mysqli_real_escape_string($conn, $_POST['previous_designation'] ?? '');
                $previous_experience_years = intval($_POST['previous_experience_years'] ?? 0);
                $previous_experience_months = intval($_POST['previous_experience_months'] ?? 0);
                
                // Education
                $highest_qualification = mysqli_real_escape_string($conn, $_POST['highest_qualification'] ?? '');
                $university = mysqli_real_escape_string($conn, $_POST['university'] ?? '');
                $year_of_passing = !empty($_POST['year_of_passing']) ? intval($_POST['year_of_passing']) : null;
                $percentage = !empty($_POST['percentage']) ? floatval($_POST['percentage']) : null;
                $skills = mysqli_real_escape_string($conn, $_POST['skills'] ?? '');
                $certifications = mysqli_real_escape_string($conn, $_POST['certifications'] ?? '');
                
                // Bank Details
                $account_holder_name = mysqli_real_escape_string($conn, $_POST['account_holder_name'] ?? '');
                $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
                $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name'] ?? '');
                $account_number = mysqli_real_escape_string($conn, $_POST['account_number'] ?? '');
                $ifsc_code = mysqli_real_escape_string($conn, $_POST['ifsc_code'] ?? '');
                $micr_code = mysqli_real_escape_string($conn, $_POST['micr_code'] ?? '');
                $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
                $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
                
                // Statutory Details
                $pan_number = mysqli_real_escape_string($conn, $_POST['pan_number'] ?? '');
                $aadhar_number = mysqli_real_escape_string($conn, $_POST['aadhar_number'] ?? '');
                
                // Salary Details
                $basic_salary = !empty($_POST['basic_salary']) ? floatval($_POST['basic_salary']) : null;
                $hra = !empty($_POST['hra']) ? floatval($_POST['hra']) : null;
                $conveyance = !empty($_POST['conveyance']) ? floatval($_POST['conveyance']) : null;
                $medical_allowance = !empty($_POST['medical_allowance']) ? floatval($_POST['medical_allowance']) : null;
                $special_allowance = !empty($_POST['special_allowance']) ? floatval($_POST['special_allowance']) : null;
                $bonus = !empty($_POST['bonus']) ? floatval($_POST['bonus']) : null;
                
                // PF/ESI Details
                $pf_number = mysqli_real_escape_string($conn, $_POST['pf_number'] ?? '');
                $esi_number = mysqli_real_escape_string($conn, $_POST['esi_number'] ?? '');
                $uan_number = mysqli_real_escape_string($conn, $_POST['uan_number'] ?? '');
                
                // Branch Assignment
                $branch_id = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
                
                // Check if username already exists
                $check_query = "SELECT id FROM users WHERE username = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $username);
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
                            
                            $new_filename = 'emp_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                            $upload_path = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $upload_path)) {
                                $employee_photo = $upload_path;
                            }
                        }
                    }
                    
                    // Insert user
                    $insert_query = "INSERT INTO users (
                        username, password, name, role, status, mobile, email,
                        date_of_birth, gender, marital_status, blood_group,
                        emergency_contact, emergency_contact_name, emergency_relation,
                        address_line1, address_line2, city, state, pincode,
                        permanent_address_same, permanent_address_line1, permanent_address_line2,
                        permanent_city, permanent_state, permanent_pincode,
                        employee_id, department, designation, joining_date, confirmation_date,
                        employment_type, reporting_manager, work_location, shift_timing, weekly_off,
                        total_experience_years, total_experience_months, previous_company,
                        previous_designation, previous_experience_years, previous_experience_months,
                        highest_qualification, university, year_of_passing, percentage,
                        skills, certifications,
                        account_holder_name, bank_name, branch_name, account_number,
                        ifsc_code, micr_code, account_type, upi_id,
                        pan_number, aadhar_number,
                        basic_salary, hra, conveyance, medical_allowance, special_allowance, bonus,
                        pf_number, esi_number, uan_number,
                        branch_id, employee_photo
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?
                    )";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'ssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss', 
                        $username, $password, $name, $role, $status, $mobile, $email,
                        $date_of_birth, $gender, $marital_status, $blood_group,
                        $emergency_contact, $emergency_contact_name, $emergency_relation,
                        $address_line1, $address_line2, $city, $state, $pincode,
                        $permanent_address_same, $permanent_address_line1, $permanent_address_line2,
                        $permanent_city, $permanent_state, $permanent_pincode,
                        $employee_id, $department, $designation, $joining_date, $confirmation_date,
                        $employment_type, $reporting_manager, $work_location, $shift_timing, $weekly_off,
                        $total_experience_years, $total_experience_months, $previous_company,
                        $previous_designation, $previous_experience_years, $previous_experience_months,
                        $highest_qualification, $university, $year_of_passing, $percentage,
                        $skills, $certifications,
                        $account_holder_name, $bank_name, $branch_name, $account_number,
                        $ifsc_code, $micr_code, $account_type, $upi_id,
                        $pan_number, $aadhar_number,
                        $basic_salary, $hra, $conveyance, $medical_allowance, $special_allowance, $bonus,
                        $pf_number, $esi_number, $uan_number,
                        $branch_id, $employee_photo
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $user_id = mysqli_insert_id($conn);
                        
                        // Log activity
                        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                      VALUES (?, 'create', ?, 'users', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $log_description = "New user created: " . $name . " (" . $username . ")";
                        mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $user_id);
                        mysqli_stmt_execute($log_stmt);
                        
                        $message = "User created successfully!";
                    } else {
                        $error = "Error creating user: " . mysqli_error($conn);
                    }
                }
                break;
                
            case 'update_user':
                $user_id = intval($_POST['user_id']);
                $name = mysqli_real_escape_string($conn, $_POST['name']);
                $role = mysqli_real_escape_string($conn, $_POST['role']);
                $status = mysqli_real_escape_string($conn, $_POST['status']);
                $mobile = mysqli_real_escape_string($conn, $_POST['mobile'] ?? '');
                $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
                
                // Update password if provided
                $password_update = "";
                if (!empty($_POST['password'])) {
                    $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $password_update = ", password = '$new_password'";
                }
                
                $update_query = "UPDATE users SET 
                    name = '$name',
                    role = '$role',
                    status = '$status',
                    mobile = '$mobile',
                    email = '$email'
                    $password_update
                    WHERE id = $user_id";
                
                if (mysqli_query($conn, $update_query)) {
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES ({$_SESSION['user_id']}, 'update', 'User details updated', 'users', $user_id)";
                    mysqli_query($conn, $log_query);
                    
                    $message = "User updated successfully!";
                } else {
                    $error = "Error updating user: " . mysqli_error($conn);
                }
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id']);
                
                // Check if user has any loans or activities
                $check_query = "SELECT COUNT(*) as count FROM loans WHERE employee_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $user_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $check_data = mysqli_fetch_assoc($check_result);
                
                if ($check_data['count'] > 0) {
                    $error = "Cannot delete user. They have associated loan records.";
                } else {
                    // Get user details for log
                    $user_query = "SELECT name, username FROM users WHERE id = ?";
                    $user_stmt = mysqli_prepare($conn, $user_query);
                    mysqli_stmt_bind_param($user_stmt, 'i', $user_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    $user_data = mysqli_fetch_assoc($user_result);
                    
                    // Delete user
                    $delete_query = "DELETE FROM users WHERE id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $user_id);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        // Log activity
                        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                      VALUES (?, 'delete', ?, 'users', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $log_description = "User deleted: " . ($user_data['name'] ?? 'Unknown') . " (" . ($user_data['username'] ?? '') . ")";
                        mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $user_id);
                        mysqli_stmt_execute($log_stmt);
                        
                        $message = "User deleted successfully!";
                    } else {
                        $error = "Error deleting user: " . mysqli_error($conn);
                    }
                }
                break;
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$role_filter = isset($_GET['role']) ? mysqli_real_escape_string($conn, $_GET['role']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$branch_filter = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;

// Build query for users list
$query = "SELECT u.*, b.branch_name 
          FROM users u 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (u.name LIKE '%$search%' OR u.username LIKE '%$search%' OR u.email LIKE '%$search%' OR u.mobile LIKE '%$search%')";
}

if (!empty($role_filter)) {
    $query .= " AND u.role = '$role_filter'";
}

if (!empty($status_filter)) {
    $query .= " AND u.status = '$status_filter'";
}

if ($branch_filter > 0) {
    $query .= " AND u.branch_id = $branch_filter";
}

$query .= " ORDER BY u.created_at DESC";
$users_result = mysqli_query($conn, $query);

// Get branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get departments
$depts_query = "SELECT department_name FROM departments WHERE status = 1 ORDER BY department_name";
$depts_result = mysqli_query($conn, $depts_query);

// Get existing users for reporting manager dropdown
$managers_query = "SELECT id, name, role FROM users WHERE status = 'active' AND role IN ('admin', 'manager') ORDER BY name";
$managers_result = mysqli_query($conn, $managers_query);

// Safe number format function
function safeNumberFormat($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '0.00';
    }
    return number_format(floatval($value), $decimals);
}
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

        .users-container {
            max-width: 1600px;
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 13px;
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

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
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

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .user-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .user-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .user-table tbody tr:hover {
            background: #f7fafc;
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
            background: #a0aec0;
            color: white;
        }

        .badge-admin {
            background: #f56565;
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

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #f7fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: #667eea;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
            position: sticky;
            top: 0;
            background: white;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-close {
            float: right;
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
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

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-weight: 600;
            color: #2d3748;
        }

        /* Search Box */
        .search-box {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 250px;
        }

        .search-box:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .search-box {
                width: 100%;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
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
                <div class="users-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-people"></i>
                            User Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showAddUserModal()">
                                <i class="bi bi-person-plus"></i> Add New User
                            </button>
                        </div>
                    </div>

                    <!-- Summary Statistics -->
                    <?php
                    $stats_query = "SELECT 
                                    COUNT(*) as total_users,
                                    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
                                    SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as managers,
                                    SUM(CASE WHEN role = 'sale' THEN 1 ELSE 0 END) as sales,
                                    SUM(CASE WHEN role = 'accountant' THEN 1 ELSE 0 END) as accountants,
                                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
                                  FROM users";
                    $stats_result = mysqli_query($conn, $stats_query);
                    $stats = mysqli_fetch_assoc($stats_result);
                    ?>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Users</div>
                                <div class="stat-value"><?php echo $stats['total_users'] ?? 0; ?></div>
                                <div class="stat-sub">Active: <?php echo $stats['active'] ?? 0; ?> | Inactive: <?php echo $stats['inactive'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Admins</div>
                                <div class="stat-value"><?php echo $stats['admins'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Managers</div>
                                <div class="stat-value"><?php echo $stats['managers'] ?? 0; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Sales Team</div>
                                <div class="stat-value"><?php echo ($stats['sales'] ?? 0) + ($stats['accountants'] ?? 0); ?></div>
                                <div class="stat-sub">Sales: <?php echo $stats['sales'] ?? 0; ?> | Accounts: <?php echo $stats['accountants'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Users
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Search</label>
                                    <div class="input-group">
                                        <i class="bi bi-search input-icon"></i>
                                        <input type="text" class="form-control" name="search" placeholder="Name, Username, Email, Mobile..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Role</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="role">
                                            <option value="">All Roles</option>
                                            <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                            <option value="manager" <?php echo $role_filter == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="sale" <?php echo $role_filter == 'sale' ? 'selected' : ''; ?>>Sale</option>
                                            <option value="accountant" <?php echo $role_filter == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="input-group">
                                        <i class="bi bi-circle input-icon"></i>
                                        <select class="form-select" name="status">
                                            <option value="">All Status</option>
                                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Branch</label>
                                    <div class="input-group">
                                        <i class="bi bi-building input-icon"></i>
                                        <select class="form-select" name="branch_id">
                                            <option value="0">All Branches</option>
                                            <?php 
                                            if ($branches_result && mysqli_num_rows($branches_result) > 0) {
                                                mysqli_data_seek($branches_result, 0);
                                                while($branch = mysqli_fetch_assoc($branches_result)): 
                                            ?>
                                                <option value="<?php echo $branch['id']; ?>" <?php echo $branch_filter == $branch['id'] ? 'selected' : ''; ?>>
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

                            <div class="filter-actions">
                                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Users Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">
                                <i class="bi bi-list-ul"></i>
                                Users List
                                <span class="badge badge-info" style="margin-left: 10px;">
                                    <?php echo mysqli_num_rows($users_result); ?> records
                                </span>
                            </span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="user-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Username</th>
                                        <th>Contact</th>
                                        <th>Role</th>
                                        <th>Branch</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($users_result) > 0): ?>
                                        <?php while($user = mysqli_fetch_assoc($users_result)): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <?php if (!empty($user['employee_photo'])): ?>
                                                        <img src="<?php echo htmlspecialchars($user['employee_photo']); ?>" class="user-avatar" alt="Avatar" style="object-fit: cover;">
                                                    <?php else: ?>
                                                        <div class="user-avatar">
                                                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                                        <?php if (!empty($user['employee_id'])): ?>
                                                            <br><small>ID: <?php echo htmlspecialchars($user['employee_id']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td>
                                                <?php if (!empty($user['mobile'])): ?>
                                                    <i class="bi bi-phone"></i> <?php echo htmlspecialchars($user['mobile']); ?><br>
                                                <?php endif; ?>
                                                <?php if (!empty($user['email'])): ?>
                                                    <small><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $user['role']; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['branch_name'] ?? 'Not Assigned'); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? '-'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $user['status']; ?>">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($user['joining_date'])) {
                                                    echo date('d-m-Y', strtotime($user['joining_date']));
                                                } else {
                                                    echo date('d-m-Y', strtotime($user['created_at']));
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="action-buttons">
                                                    <button class="btn btn-info btn-sm" onclick="viewUser(<?php echo $user['id']; ?>)">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="btn btn-warning btn-sm" onclick="editUser(<?php echo $user['id']; ?>)">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center" style="padding: 40px;">
                                                No users found
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeAddUserModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-person-plus"></i>
                Add New User
            </h3>

            <form method="POST" action="" enctype="multipart/form-data" id="addUserForm">
                <input type="hidden" name="action" value="create_user">

                <!-- Tabs -->
                <div class="tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('basic')">Basic Info</button>
                    <button type="button" class="tab-btn" onclick="switchTab('personal')">Personal</button>
                    <button type="button" class="tab-btn" onclick="switchTab('address')">Address</button>
                    <button type="button" class="tab-btn" onclick="switchTab('employment')">Employment</button>
                    <button type="button" class="tab-btn" onclick="switchTab('education')">Education</button>
                    <button type="button" class="tab-btn" onclick="switchTab('bank')">Bank Details</button>
                    <button type="button" class="tab-btn" onclick="switchTab('salary')">Salary</button>
                </div>

                <!-- Basic Info Tab -->
                <div class="tab-pane active" id="tab-basic">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label required">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Full Name</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Role</label>
                            <select class="form-select" name="role" required>
                                <option value="">Select Role</option>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="sale">Sale</option>
                                <option value="accountant">Accountant</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" class="form-control" name="mobile">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employee Photo</label>
                            <input type="file" class="form-control" name="employee_photo" accept="image/*">
                        </div>
                    </div>
                </div>

                <!-- Personal Info Tab -->
                <div class="tab-pane" id="tab-personal">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Marital Status</label>
                            <select class="form-select" name="marital_status">
                                <option value="">Select</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Divorced">Divorced</option>
                                <option value="Widowed">Widowed</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Blood Group</label>
                            <select class="form-select" name="blood_group">
                                <option value="">Select</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Emergency Contact</label>
                            <input type="text" class="form-control" name="emergency_contact">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Emergency Contact Name</label>
                            <input type="text" class="form-control" name="emergency_contact_name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Relation</label>
                            <input type="text" class="form-control" name="emergency_relation">
                        </div>
                    </div>
                </div>

                <!-- Address Tab -->
                <div class="tab-pane" id="tab-address">
                    <h4 style="margin-bottom: 15px;">Current Address</h4>
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" class="form-control" name="address_line1">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" class="form-control" name="address_line2">
                        </div>

                        <div class="form-group">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" name="city">
                        </div>

                        <div class="form-group">
                            <label class="form-label">State</label>
                            <input type="text" class="form-control" name="state">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Pincode</label>
                            <input type="text" class="form-control" name="pincode">
                        </div>
                    </div>

                    <h4 style="margin: 20px 0 15px;">Permanent Address</h4>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="permanent_address_same" value="1" checked onchange="togglePermanentAddress()"> 
                            Same as Current Address
                        </label>
                    </div>

                    <div id="permanentAddress" style="display: none;">
                        <div class="info-grid">
                            <div class="form-group">
                                <label class="form-label">Address Line 1</label>
                                <input type="text" class="form-control" name="permanent_address_line1">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Address Line 2</label>
                                <input type="text" class="form-control" name="permanent_address_line2">
                            </div>

                            <div class="form-group">
                                <label class="form-label">City</label>
                                <input type="text" class="form-control" name="permanent_city">
                            </div>

                            <div class="form-group">
                                <label class="form-label">State</label>
                                <input type="text" class="form-control" name="permanent_state">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Pincode</label>
                                <input type="text" class="form-control" name="permanent_pincode">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Employment Tab -->
                <div class="tab-pane" id="tab-employment">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Employee ID</label>
                            <input type="text" class="form-control" name="employee_id">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department">
                                <option value="">Select Department</option>
                                <?php 
                                if ($depts_result && mysqli_num_rows($depts_result) > 0) {
                                    mysqli_data_seek($depts_result, 0);
                                    while($dept = mysqli_fetch_assoc($depts_result)): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                        <?php echo htmlspecialchars($dept['department_name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Designation</label>
                            <input type="text" class="form-control" name="designation">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Joining Date</label>
                            <input type="date" class="form-control" name="joining_date">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Confirmation Date</label>
                            <input type="date" class="form-control" name="confirmation_date">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employment Type</label>
                            <select class="form-select" name="employment_type">
                                <option value="full_time">Full Time</option>
                                <option value="part_time">Part Time</option>
                                <option value="contract">Contract</option>
                                <option value="intern">Intern</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Reporting Manager</label>
                            <select class="form-select" name="reporting_manager">
                                <option value="">Select Manager</option>
                                <?php 
                                if ($managers_result && mysqli_num_rows($managers_result) > 0) {
                                    mysqli_data_seek($managers_result, 0);
                                    while($manager = mysqli_fetch_assoc($managers_result)): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($manager['name']); ?>">
                                        <?php echo htmlspecialchars($manager['name']); ?> (<?php echo ucfirst($manager['role']); ?>)
                                    </option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Work Location</label>
                            <input type="text" class="form-control" name="work_location">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Shift Timing</label>
                            <input type="text" class="form-control" name="shift_timing" placeholder="e.g., 9:00 AM - 6:00 PM">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Weekly Off</label>
                            <select class="form-select" name="weekly_off">
                                <option value="sunday">Sunday</option>
                                <option value="monday">Monday</option>
                                <option value="tuesday">Tuesday</option>
                                <option value="wednesday">Wednesday</option>
                                <option value="thursday">Thursday</option>
                                <option value="friday">Friday</option>
                                <option value="saturday">Saturday</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Branch</label>
                            <select class="form-select" name="branch_id">
                                <option value="">Select Branch</option>
                                <?php 
                                if ($branches_result && mysqli_num_rows($branches_result) > 0) {
                                    mysqli_data_seek($branches_result, 0);
                                    while($branch = mysqli_fetch_assoc($branches_result)): 
                                ?>
                                    <option value="<?php echo $branch['id']; ?>">
                                        <?php echo htmlspecialchars($branch['branch_name']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <h4 style="margin: 20px 0 15px;">Experience</h4>
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Total Experience (Years)</label>
                            <input type="number" class="form-control" name="total_experience_years" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Total Experience (Months)</label>
                            <input type="number" class="form-control" name="total_experience_months" min="0" max="11" value="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Previous Company</label>
                            <input type="text" class="form-control" name="previous_company">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Previous Designation</label>
                            <input type="text" class="form-control" name="previous_designation">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Previous Exp (Years)</label>
                            <input type="number" class="form-control" name="previous_experience_years" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Previous Exp (Months)</label>
                            <input type="number" class="form-control" name="previous_experience_months" min="0" max="11" value="0">
                        </div>
                    </div>
                </div>

                <!-- Education Tab -->
                <div class="tab-pane" id="tab-education">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Highest Qualification</label>
                            <input type="text" class="form-control" name="highest_qualification">
                        </div>

                        <div class="form-group">
                            <label class="form-label">University</label>
                            <input type="text" class="form-control" name="university">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Year of Passing</label>
                            <input type="number" class="form-control" name="year_of_passing" min="1900" max="<?php echo date('Y'); ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Percentage/CGPA</label>
                            <input type="number" class="form-control" name="percentage" step="0.01" min="0" max="100">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Skills</label>
                            <textarea class="form-control" name="skills" rows="3" placeholder="Comma separated skills"></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Certifications</label>
                            <textarea class="form-control" name="certifications" rows="3" placeholder="Certification details"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Bank Details Tab -->
                <div class="tab-pane" id="tab-bank">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Account Holder Name</label>
                            <input type="text" class="form-control" name="account_holder_name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bank Name</label>
                            <input type="text" class="form-control" name="bank_name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Branch Name</label>
                            <input type="text" class="form-control" name="branch_name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Account Number</label>
                            <input type="text" class="form-control" name="account_number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" class="form-control" name="ifsc_code">
                        </div>

                        <div class="form-group">
                            <label class="form-label">MICR Code</label>
                            <input type="text" class="form-control" name="micr_code">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Account Type</label>
                            <select class="form-select" name="account_type">
                                <option value="savings">Savings</option>
                                <option value="current">Current</option>
                                <option value="salary">Salary</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">UPI ID</label>
                            <input type="text" class="form-control" name="upi_id">
                        </div>

                        <div class="form-group">
                            <label class="form-label">PAN Number</label>
                            <input type="text" class="form-control" name="pan_number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Aadhar Number</label>
                            <input type="text" class="form-control" name="aadhar_number">
                        </div>
                    </div>
                </div>

                <!-- Salary Tab -->
                <div class="tab-pane" id="tab-salary">
                    <div class="info-grid">
                        <div class="form-group">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" class="form-control" name="basic_salary" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">HRA</label>
                            <input type="number" class="form-control" name="hra" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Conveyance</label>
                            <input type="number" class="form-control" name="conveyance" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Medical Allowance</label>
                            <input type="number" class="form-control" name="medical_allowance" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Special Allowance</label>
                            <input type="number" class="form-control" name="special_allowance" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bonus</label>
                            <input type="number" class="form-control" name="bonus" step="0.01" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">PF Number</label>
                            <input type="text" class="form-control" name="pf_number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">ESI Number</label>
                            <input type="text" class="form-control" name="esi_number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">UAN Number</label>
                            <input type="text" class="form-control" name="uan_number">
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeEditUserModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-pencil"></i>
                Edit User
            </h3>

            <form method="POST" action="" id="editUserForm">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div class="info-grid">
                    <div class="form-group">
                        <label class="form-label required">Full Name</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Role</label>
                        <select class="form-select" name="role" id="edit_role" required>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="sale">Sale</option>
                            <option value="accountant">Accountant</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Status</label>
                        <select class="form-select" name="status" id="edit_status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" class="form-control" name="mobile" id="edit_mobile">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                    </div>

                    <div class="form-group">
                        <label class="form-label">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control" name="password">
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Update User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View User Modal -->
    <div class="modal" id="viewUserModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeViewUserModal()">&times;</span>
            <h3 class="modal-title">
                <i class="bi bi-person"></i>
                User Profile
            </h3>
            <div id="userProfileContent">
                <!-- Content will be loaded via AJAX -->
                <div class="text-center" style="padding: 40px;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
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

        // Reset filters
        function resetFilters() {
            document.querySelector('input[name="search"]').value = '';
            document.querySelector('select[name="role"]').value = '';
            document.querySelector('select[name="status"]').value = '';
            document.querySelector('select[name="branch_id"]').value = '0';
            document.getElementById('filterForm').submit();
        }

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
            const checkbox = document.querySelector('input[name="permanent_address_same"]');
            const permAddress = document.getElementById('permanentAddress');
            
            if (checkbox.checked) {
                permAddress.style.display = 'none';
            } else {
                permAddress.style.display = 'block';
            }
        }

        // Add User Modal
        function showAddUserModal() {
            document.getElementById('addUserModal').classList.add('active');
        }

        function closeAddUserModal() {
            document.getElementById('addUserModal').classList.remove('active');
            document.getElementById('addUserForm').reset();
            
            // Reset tabs to first tab
            switchTab('basic');
        }

        // Edit User Modal
        function editUser(userId) {
            // Fetch user data via AJAX
            fetch('ajax/get_user.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_user_id').value = data.user.id;
                        document.getElementById('edit_name').value = data.user.name;
                        document.getElementById('edit_role').value = data.user.role;
                        document.getElementById('edit_status').value = data.user.status;
                        document.getElementById('edit_mobile').value = data.user.mobile || '';
                        document.getElementById('edit_email').value = data.user.email || '';
                        
                        document.getElementById('editUserModal').classList.add('active');
                    } else {
                        Swal.fire('Error', 'Could not load user data', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to load user data', 'error');
                });
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').classList.remove('active');
            document.getElementById('editUserForm').reset();
        }

        // View User Modal
        function viewUser(userId) {
            document.getElementById('viewUserModal').classList.add('active');
            
            // Load user profile via AJAX
            fetch('ajax/get_user_profile.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUserProfile(data);
                    } else {
                        document.getElementById('userProfileContent').innerHTML = '<p class="text-center text-danger">Error loading profile</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('userProfileContent').innerHTML = '<p class="text-center text-danger">Error loading profile</p>';
                });
        }

        function displayUserProfile(data) {
            const user = data.user;
            let html = `
                <div class="profile-header">
                    ${user.employee_photo ? 
                        `<img src="${user.employee_photo}" class="profile-photo">` : 
                        `<div class="profile-photo" style="background: #667eea; color: white; display: flex; align-items: center; justify-content: center; font-size: 36px;">
                            ${user.name ? user.name.charAt(0).toUpperCase() : 'U'}
                        </div>`
                    }
                    <div class="profile-info">
                        <div class="profile-name">${user.name}</div>
                        <div class="profile-role">
                            <span class="badge badge-${user.role}">${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</span>
                            <span class="badge badge-${user.status}">${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</span>
                        </div>
                        <div class="profile-meta">
                            ${user.username ? `<span><i class="bi bi-person"></i> ${user.username}</span>` : ''}
                            ${user.mobile ? `<span><i class="bi bi-phone"></i> ${user.mobile}</span>` : ''}
                            ${user.email ? `<span><i class="bi bi-envelope"></i> ${user.email}</span>` : ''}
                            ${user.employee_id ? `<span><i class="bi bi-card-text"></i> ID: ${user.employee_id}</span>` : ''}
                        </div>
                    </div>
                </div>
            `;

            // Personal Information
            html += `
                <h4 style="margin: 20px 0 10px;">Personal Information</h4>
                <div class="info-grid">
                    ${user.date_of_birth ? `<div class="info-item"><div class="info-label">Date of Birth</div><div class="info-value">${user.date_of_birth}</div></div>` : ''}
                    ${user.gender ? `<div class="info-item"><div class="info-label">Gender</div><div class="info-value">${user.gender}</div></div>` : ''}
                    ${user.marital_status ? `<div class="info-item"><div class="info-label">Marital Status</div><div class="info-value">${user.marital_status}</div></div>` : ''}
                    ${user.blood_group ? `<div class="info-item"><div class="info-label">Blood Group</div><div class="info-value">${user.blood_group}</div></div>` : ''}
                </div>
            `;

            // Emergency Contact
            if (user.emergency_contact || user.emergency_contact_name) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Emergency Contact</h4>
                    <div class="info-grid">
                        ${user.emergency_contact_name ? `<div class="info-item"><div class="info-label">Name</div><div class="info-value">${user.emergency_contact_name}</div></div>` : ''}
                        ${user.emergency_relation ? `<div class="info-item"><div class="info-label">Relation</div><div class="info-value">${user.emergency_relation}</div></div>` : ''}
                        ${user.emergency_contact ? `<div class="info-item"><div class="info-label">Contact</div><div class="info-value">${user.emergency_contact}</div></div>` : ''}
                    </div>
                `;
            }

            // Address
            if (user.address_line1 || user.city) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Address</h4>
                    <div class="info-grid">
                        <div class="info-item" style="grid-column: span 2;">
                            <div class="info-label">Current Address</div>
                            <div class="info-value">
                                ${user.address_line1 ? user.address_line1 + '<br>' : ''}
                                ${user.address_line2 ? user.address_line2 + '<br>' : ''}
                                ${user.city ? user.city + ', ' : ''}${user.state ? user.state + ' - ' : ''}${user.pincode ? user.pincode : ''}
                            </div>
                        </div>
                    </div>
                `;
            }

            // Employment
            if (user.department || user.designation) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Employment Details</h4>
                    <div class="info-grid">
                        ${user.department ? `<div class="info-item"><div class="info-label">Department</div><div class="info-value">${user.department}</div></div>` : ''}
                        ${user.designation ? `<div class="info-item"><div class="info-label">Designation</div><div class="info-value">${user.designation}</div></div>` : ''}
                        ${user.joining_date ? `<div class="info-item"><div class="info-label">Joining Date</div><div class="info-value">${user.joining_date}</div></div>` : ''}
                        ${user.employment_type ? `<div class="info-item"><div class="info-label">Employment Type</div><div class="info-value">${user.employment_type.replace('_', ' ')}</div></div>` : ''}
                        ${user.reporting_manager ? `<div class="info-item"><div class="info-label">Reporting Manager</div><div class="info-value">${user.reporting_manager}</div></div>` : ''}
                        ${user.work_location ? `<div class="info-item"><div class="info-label">Work Location</div><div class="info-value">${user.work_location}</div></div>` : ''}
                        ${user.shift_timing ? `<div class="info-item"><div class="info-label">Shift</div><div class="info-value">${user.shift_timing}</div></div>` : ''}
                        ${user.weekly_off ? `<div class="info-item"><div class="info-label">Weekly Off</div><div class="info-value">${user.weekly_off}</div></div>` : ''}
                    </div>
                `;
            }

            // Experience
            if (user.total_experience_years > 0 || user.previous_company) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Experience</h4>
                    <div class="info-grid">
                        ${user.total_experience_years > 0 ? `<div class="info-item"><div class="info-label">Total Experience</div><div class="info-value">${user.total_experience_years} years ${user.total_experience_months} months</div></div>` : ''}
                        ${user.previous_company ? `<div class="info-item"><div class="info-label">Previous Company</div><div class="info-value">${user.previous_company}</div></div>` : ''}
                        ${user.previous_designation ? `<div class="info-item"><div class="info-label">Previous Designation</div><div class="info-value">${user.previous_designation}</div></div>` : ''}
                    </div>
                `;
            }

            // Education
            if (user.highest_qualification) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Education</h4>
                    <div class="info-grid">
                        ${user.highest_qualification ? `<div class="info-item"><div class="info-label">Qualification</div><div class="info-value">${user.highest_qualification}</div></div>` : ''}
                        ${user.university ? `<div class="info-item"><div class="info-label">University</div><div class="info-value">${user.university}</div></div>` : ''}
                        ${user.year_of_passing ? `<div class="info-item"><div class="info-label">Year of Passing</div><div class="info-value">${user.year_of_passing}</div></div>` : ''}
                        ${user.percentage ? `<div class="info-item"><div class="info-label">Percentage</div><div class="info-value">${user.percentage}%</div></div>` : ''}
                        ${user.skills ? `<div class="info-item" style="grid-column: span 2;"><div class="info-label">Skills</div><div class="info-value">${user.skills}</div></div>` : ''}
                    </div>
                `;
            }

            // Bank Details
            if (user.bank_name || user.account_number) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Bank Details</h4>
                    <div class="info-grid">
                        ${user.account_holder_name ? `<div class="info-item"><div class="info-label">Account Holder</div><div class="info-value">${user.account_holder_name}</div></div>` : ''}
                        ${user.bank_name ? `<div class="info-item"><div class="info-label">Bank Name</div><div class="info-value">${user.bank_name}</div></div>` : ''}
                        ${user.branch_name ? `<div class="info-item"><div class="info-label">Branch</div><div class="info-value">${user.branch_name}</div></div>` : ''}
                        ${user.account_number ? `<div class="info-item"><div class="info-label">Account Number</div><div class="info-value">${user.account_number}</div></div>` : ''}
                        ${user.ifsc_code ? `<div class="info-item"><div class="info-label">IFSC</div><div class="info-value">${user.ifsc_code}</div></div>` : ''}
                        ${user.upi_id ? `<div class="info-item"><div class="info-label">UPI ID</div><div class="info-value">${user.upi_id}</div></div>` : ''}
                    </div>
                `;
            }

            // Statutory Details
            if (user.pan_number || user.aadhar_number) {
                html += `
                    <h4 style="margin: 20px 0 10px;">Statutory Details</h4>
                    <div class="info-grid">
                        ${user.pan_number ? `<div class="info-item"><div class="info-label">PAN Number</div><div class="info-value">${user.pan_number}</div></div>` : ''}
                        ${user.aadhar_number ? `<div class="info-item"><div class="info-label">Aadhar Number</div><div class="info-value">${user.aadhar_number}</div></div>` : ''}
                        ${user.pf_number ? `<div class="info-item"><div class="info-label">PF Number</div><div class="info-value">${user.pf_number}</div></div>` : ''}
                        ${user.esi_number ? `<div class="info-item"><div class="info-label">ESI Number</div><div class="info-value">${user.esi_number}</div></div>` : ''}
                        ${user.uan_number ? `<div class="info-item"><div class="info-label">UAN Number</div><div class="info-value">${user.uan_number}</div></div>` : ''}
                    </div>
                `;
            }

            document.getElementById('userProfileContent').innerHTML = html;
        }

        function closeViewUserModal() {
            document.getElementById('viewUserModal').classList.remove('active');
        }

        // Delete user with SweetAlert confirmation
        function deleteUser(userId, userName) {
            Swal.fire({
                title: 'Delete User?',
                html: `Are you sure you want to delete <strong>${userName}</strong>?<br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_user';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'user_id';
                    idInput.value = userId;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);<?php
session_start();
$currentPage = 'user-rights';
$pageTitle = 'User Rights Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Define all available modules/pages in the system
$modules = [
    'dashboard' => [
        'name' => 'Dashboard',
        'icon' => 'bi-grid-1x2-fill',
        'description' => 'Access to main dashboard',
        'actions' => ['view']
    ],
    'branch_management' => [
        'name' => 'Branch Management',
        'icon' => 'bi-building',
        'description' => 'Manage branches and branch settings',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'loan_management' => [
        'name' => 'Loan Management',
        'icon' => 'bi-cash-stack',
        'description' => 'Manage loans and loan operations',
        'actions' => ['view', 'create', 'edit', 'close', 'bulk_close', 'print']
    ],
    'loan_collection' => [
        'name' => 'Loan Collection',
        'icon' => 'bi-check-circle',
        'description' => 'Collect loan payments and interest',
        'actions' => ['view', 'collect', 'print']
    ],
    'customer_management' => [
        'name' => 'Customer Management',
        'icon' => 'bi-people',
        'description' => 'Manage customer information',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'employee_management' => [
        'name' => 'Employee Management',
        'icon' => 'bi-person-badge',
        'description' => 'Manage employee information',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'interest_management' => [
        'name' => 'Interest Management',
        'icon' => 'bi-percent',
        'description' => 'Configure interest rates and types',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'investment_management' => [
        'name' => 'Investment Management',
        'icon' => 'bi-graph-up-arrow',
        'description' => 'Manage investments and returns',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'expense_management' => [
        'name' => 'Expense Management',
        'icon' => 'bi-cash',
        'description' => 'Manage expenses and expense types',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'bank_management' => [
        'name' => 'Bank Management',
        'icon' => 'bi-bank',
        'description' => 'Manage banks, accounts, and bank loans',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'master_data' => [
        'name' => 'Master Data',
        'icon' => 'bi-tags',
        'description' => 'Manage product types, karat, defects, etc.',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'reports' => [
        'name' => 'Reports',
        'icon' => 'bi-file-earmark-bar-graph',
        'description' => 'Access to all reports',
        'actions' => ['view', 'export', 'print']
    ],
    'auction_management' => [
        'name' => 'Auction Management',
        'icon' => 'bi-hammer',
        'description' => 'Manage auctions and auctioneers',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'notes_management' => [
        'name' => 'Notes Management',
        'icon' => 'bi-journal-text',
        'description' => 'Manage loan notes and comments',
        'actions' => ['view', 'create', 'edit', 'delete']
    ],
    'user_management' => [
        'name' => 'User Management',
        'icon' => 'bi-people',
        'description' => 'Manage users and permissions',
        'actions' => ['view', 'create', 'edit', 'delete', 'permissions']
    ],
    'company_settings' => [
        'name' => 'Company Settings',
        'icon' => 'bi-building',
        'description' => 'Manage company information',
        'actions' => ['view', 'edit']
    ],
    'activity_logs' => [
        'name' => 'Activity Logs',
        'icon' => 'bi-clock-history',
        'description' => 'View system activity logs',
        'actions' => ['view', 'export']
    ]
];

// Define role-based default permissions
$default_permissions = [
    'admin' => [
        'dashboard' => ['view' => true],
        'branch_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'loan_management' => ['view' => true, 'create' => true, 'edit' => true, 'close' => true, 'bulk_close' => true, 'print' => true],
        'loan_collection' => ['view' => true, 'collect' => true, 'print' => true],
        'customer_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'employee_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'interest_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'investment_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'expense_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'bank_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'master_data' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'reports' => ['view' => true, 'export' => true, 'print' => true],
        'auction_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'notes_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'user_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true, 'permissions' => true],
        'company_settings' => ['view' => true, 'edit' => true],
        'activity_logs' => ['view' => true, 'export' => true]
    ],
    'manager' => [
        'dashboard' => ['view' => true],
        'branch_management' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        'loan_management' => ['view' => true, 'create' => true, 'edit' => true, 'close' => true, 'bulk_close' => false, 'print' => true],
        'loan_collection' => ['view' => true, 'collect' => true, 'print' => true],
        'customer_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'employee_management' => ['view' => true, 'create' => false, 'edit' => true, 'delete' => false],
        'interest_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'investment_management' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
        'expense_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'bank_management' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
        'master_data' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'reports' => ['view' => true, 'export' => true, 'print' => true],
        'auction_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'notes_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
        'user_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false, 'permissions' => false],
        'company_settings' => ['view' => true, 'edit' => false],
        'activity_logs' => ['view' => true, 'export' => false]
    ],
    'sale' => [
        'dashboard' => ['view' => true],
        'branch_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'loan_management' => ['view' => true, 'create' => true, 'edit' => false, 'close' => false, 'bulk_close' => false, 'print' => true],
        'loan_collection' => ['view' => true, 'collect' => true, 'print' => true],
        'customer_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'employee_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'interest_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'investment_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'expense_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'bank_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'master_data' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'reports' => ['view' => true, 'export' => false, 'print' => true],
        'auction_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'notes_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'user_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false, 'permissions' => false],
        'company_settings' => ['view' => false, 'edit' => false],
        'activity_logs' => ['view' => false, 'export' => false]
    ],
    'accountant' => [
        'dashboard' => ['view' => true],
        'branch_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'loan_management' => ['view' => true, 'create' => false, 'edit' => false, 'close' => false, 'bulk_close' => false, 'print' => false],
        'loan_collection' => ['view' => true, 'collect' => false, 'print' => false],
        'customer_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'employee_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'interest_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'investment_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'expense_management' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => false],
        'bank_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'master_data' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'reports' => ['view' => true, 'export' => true, 'print' => true],
        'auction_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        'notes_management' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
        'user_management' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false, 'permissions' => false],
        'company_settings' => ['view' => false, 'edit' => false],
        'activity_logs' => ['view' => true, 'export' => true]
    ]
];

// Check if we need to create permissions table
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'user_permissions'");
if (mysqli_num_rows($table_check) == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `user_permissions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `module` varchar(100) NOT NULL,
        `action` varchar(50) NOT NULL,
        `allowed` tinyint(1) DEFAULT 0,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_module_action` (`user_id`, `module`, `action`),
        KEY `user_id` (`user_id`),
        CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $create_table);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_permissions':
                $user_id = intval($_POST['user_id']);
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Delete existing permissions for this user
                    $delete_query = "DELETE FROM user_permissions WHERE user_id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $user_id);
                    mysqli_stmt_execute($delete_stmt);
                    
                    // Insert new permissions
                    if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                        $insert_query = "INSERT INTO user_permissions (user_id, module, action, allowed) VALUES (?, ?, ?, 1)";
                        $insert_stmt = mysqli_prepare($conn, $insert_query);
                        
                        foreach ($_POST['permissions'] as $module => $actions) {
                            foreach ($actions as $action => $value) {
                                if ($value == 1) {
                                    mysqli_stmt_bind_param($insert_stmt, 'iss', $user_id, $module, $action);
                                    mysqli_stmt_execute($insert_stmt);
                                }
                            }
                        }
                    }
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'update', ?, 'user_permissions', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    
                    // Get user name for log
                    $user_query = "SELECT name FROM users WHERE id = ?";
                    $user_stmt = mysqli_prepare($conn, $user_query);
                    mysqli_stmt_bind_param($user_stmt, 'i', $user_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    $user_data = mysqli_fetch_assoc($user_result);
                    
                    $log_description = "Updated permissions for user: " . ($user_data['name'] ?? 'Unknown');
                    mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $user_id);
                    mysqli_stmt_execute($log_stmt);
                    
                    mysqli_commit($conn);
                    $message = "Permissions saved successfully!";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error saving permissions: " . $e->getMessage();
                }
                break;
                
            case 'reset_to_role':
                $user_id = intval($_POST['user_id']);
                $role = mysqli_real_escape_string($conn, $_POST['role']);
                
                // Begin transaction
                mysqli_begin_transaction($conn);
                
                try {
                    // Delete existing permissions
                    $delete_query = "DELETE FROM user_permissions WHERE user_id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $user_id);
                    mysqli_stmt_execute($delete_stmt);
                    
                    // Insert role-based permissions
                    if (isset($default_permissions[$role])) {
                        $insert_query = "INSERT INTO user_permissions (user_id, module, action, allowed) VALUES (?, ?, ?, 1)";
                        $insert_stmt = mysqli_prepare($conn, $insert_query);
                        
                        foreach ($default_permissions[$role] as $module => $actions) {
                            foreach ($actions as $action => $allowed) {
                                if ($allowed) {
                                    mysqli_stmt_bind_param($insert_stmt, 'iss', $user_id, $module, $action);
                                    mysqli_stmt_execute($insert_stmt);
                                }
                            }
                        }
                    }
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (?, 'update', ?, 'user_permissions', ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    
                    // Get user name for log
                    $user_query = "SELECT name FROM users WHERE id = ?";
                    $user_stmt = mysqli_prepare($conn, $user_query);
                    mysqli_stmt_bind_param($user_stmt, 'i', $user_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    $user_data = mysqli_fetch_assoc($user_result);
                    
                    $log_description = "Reset permissions to role default for user: " . ($user_data['name'] ?? 'Unknown');
                    mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $user_id);
                    mysqli_stmt_execute($log_stmt);
                    
                    mysqli_commit($conn);
                    $message = "Permissions reset to role default successfully!";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error = "Error resetting permissions: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get all users
$users_query = "SELECT u.*, b.branch_name 
                FROM users u 
                LEFT JOIN branches b ON u.branch_id = b.id 
                WHERE u.id != 1
                ORDER BY u.role, u.name";
$users_result = mysqli_query($conn, $users_query);

// Get selected user for permission editing
$selected_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$selected_user = null;
$user_permissions = [];

if ($selected_user_id > 0) {
    // Get user details
    $user_query = "SELECT u.*, b.branch_name 
                   FROM users u 
                   LEFT JOIN branches b ON u.branch_id = b.id 
                   WHERE u.id = ?";
    $user_stmt = mysqli_prepare($conn, $user_query);
    mysqli_stmt_bind_param($user_stmt, 'i', $selected_user_id);
    mysqli_stmt_execute($user_stmt);
    $user_result = mysqli_stmt_get_result($user_stmt);
    $selected_user = mysqli_fetch_assoc($user_result);
    
    // Get user permissions
    $perm_query = "SELECT module, action FROM user_permissions WHERE user_id = ? AND allowed = 1";
    $perm_stmt = mysqli_prepare($conn, $perm_query);
    mysqli_stmt_bind_param($perm_stmt, 'i', $selected_user_id);
    mysqli_stmt_execute($perm_stmt);
    $perm_result = mysqli_stmt_get_result($perm_stmt);
    
    while ($perm = mysqli_fetch_assoc($perm_result)) {
        $user_permissions[$perm['module']][$perm['action']] = true;
    }
}

// Function to check if permission is enabled
function hasPermission($module, $action, $user_permissions, $default_role_perms, $user_role) {
    // Check if user has specific permission set
    if (isset($user_permissions[$module][$action]) && $user_permissions[$module][$action]) {
        return true;
    }
    
    // Fall back to role default
    if (isset($default_role_perms[$user_role][$module][$action]) && $default_role_perms[$user_role][$module][$action]) {
        return true;
    }
    
    return false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

        .rights-container {
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

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* User Selection Card */
        .selection-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .selection-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .user-card {
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-card:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }

        .user-card.selected {
            border-color: #667eea;
            background: #ebf4ff;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .user-meta {
            font-size: 12px;
            color: #718096;
        }

        .user-role-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            background: #e2e8f0;
            color: #4a5568;
            margin-left: 5px;
        }

        /* Permissions Card */
        .permissions-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .permissions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .user-info-header {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-details {
            font-size: 14px;
            color: #718096;
        }

        .user-details strong {
            color: #2d3748;
            font-size: 18px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        /* Module Grid */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .module-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }

        .module-header {
            background: #f7fafc;
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .module-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }

        .module-title {
            flex: 1;
            font-weight: 600;
            color: #2d3748;
        }

        .module-description {
            font-size: 11px;
            color: #718096;
            margin-top: 2px;
        }

        .module-actions {
            padding: 15px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 10px;
        }

        .permission-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

        .permission-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .permission-label {
            color: #4a5568;
            cursor: pointer;
        }

        .permission-label:hover {
            color: #667eea;
        }

        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            padding: 15px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .role-badge.admin {
            background: #f56565;
            color: white;
        }

        .role-badge.manager {
            background: #ecc94b;
            color: #744210;
        }

        .role-badge.sale {
            background: #4299e1;
            color: white;
        }

        .role-badge.accountant {
            background: #9f7aea;
            color: white;
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
            background: #a0aec0;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-muted {
            color: #a0aec0;
        }

        .mt-4 {
            margin-top: 20px;
        }

        .mb-3 {
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .modules-grid {
                grid-template-columns: 1fr;
            }
            
            .permissions-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .quick-actions {
                flex-direction: column;
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
                <div class="rights-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-shield-lock"></i>
                            User Rights Management
                        </h1>
                    </div>

                    <!-- User Selection -->
                    <div class="selection-card">
                        <div class="selection-title">
                            <i class="bi bi-people"></i>
                            Select User to Manage Permissions
                        </div>
                        
                        <div class="user-grid">
                            <?php if (mysqli_num_rows($users_result) > 0): ?>
                                <?php while($user = mysqli_fetch_assoc($users_result)): ?>
                                    <a href="?user_id=<?php echo $user['id']; ?>" class="user-card <?php echo $selected_user_id == $user['id'] ? 'selected' : ''; ?>">
                                        <div class="user-avatar">
                                            <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                        </div>
                                        <div class="user-info">
                                            <div class="user-name">
                                                <?php echo htmlspecialchars($user['name']); ?>
                                                <span class="user-role-badge"><?php echo ucfirst($user['role']); ?></span>
                                            </div>
                                            <div class="user-meta">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </div>
                                            <div class="user-meta">
                                                <?php echo htmlspecialchars($user['branch_name'] ?? 'No Branch'); ?>
                                                <span class="badge badge-<?php echo $user['status']; ?>" style="margin-left: 5px;">
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center" style="grid-column: 1/-1; padding: 40px;">
                                    No users found
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($selected_user): ?>
                        <!-- Permissions Management -->
                        <div class="permissions-card">
                            <div class="permissions-header">
                                <div class="user-info-header">
                                    <div class="user-avatar" style="width: 64px; height: 64px; font-size: 24px;">
                                        <?php echo strtoupper(substr($selected_user['name'], 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <strong><?php echo htmlspecialchars($selected_user['name']); ?></strong>
                                        <div>
                                            <span class="role-badge <?php echo $selected_user['role']; ?>">
                                                <?php echo ucfirst($selected_user['role']); ?>
                                            </span>
                                            <span class="badge badge-<?php echo $selected_user['status']; ?>" style="margin-left: 5px;">
                                                <?php echo ucfirst($selected_user['status']); ?>
                                            </span>
                                        </div>
                                        <div style="margin-top: 5px;">
                                            <i class="bi bi-person"></i> <?php echo htmlspecialchars($selected_user['username']); ?><br>
                                            <i class="bi bi-building"></i> <?php echo htmlspecialchars($selected_user['branch_name'] ?? 'No Branch'); ?><br>
                                            <?php if (!empty($selected_user['mobile'])): ?>
                                                <i class="bi bi-phone"></i> <?php echo htmlspecialchars($selected_user['mobile']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="action-buttons">
                                    <button class="btn btn-warning" onclick="resetToRole()">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset to Role Default
                                    </button>
                                    <button class="btn btn-success" onclick="savePermissions()">
                                        <i class="bi bi-check-circle"></i> Save Permissions
                                    </button>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="quick-actions">
                                <span style="font-weight: 600; color: #2d3748;">Quick Actions:</span>
                                <button class="btn btn-sm btn-info" onclick="selectAll()">
                                    <i class="bi bi-check-all"></i> Select All
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="deselectAll()">
                                    <i class="bi bi-x-circle"></i> Deselect All
                                </button>
                                <button class="btn btn-sm btn-primary" onclick="expandAll()">
                                    <i class="bi bi-arrows-expand"></i> Expand All
                                </button>
                                <button class="btn btn-sm btn-secondary" onclick="collapseAll()">
                                    <i class="bi bi-arrows-collapse"></i> Collapse All
                                </button>
                            </div>

                            <form method="POST" action="" id="permissionsForm">
                                <input type="hidden" name="action" value="save_permissions">
                                <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">
                                
                                <div class="modules-grid" id="modulesGrid">
                                    <?php foreach ($modules as $module_key => $module): ?>
                                        <div class="module-card">
                                            <div class="module-header">
                                                <div class="module-icon">
                                                    <i class="bi <?php echo $module['icon']; ?>"></i>
                                                </div>
                                                <div class="module-title">
                                                    <?php echo $module['name']; ?>
                                                    <div class="module-description">
                                                        <?php echo $module['description']; ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <input type="checkbox" 
                                                           class="module-checkbox" 
                                                           data-module="<?php echo $module_key; ?>"
                                                           onchange="toggleModule(this, '<?php echo $module_key; ?>')"
                                                           <?php 
                                                           $all_checked = true;
                                                           foreach ($module['actions'] as $action) {
                                                               if (!hasPermission($module_key, $action, $user_permissions, $default_permissions, $selected_user['role'])) {
                                                                   $all_checked = false;
                                                                   break;
                                                               }
                                                           }
                                                           echo $all_checked ? 'checked' : '';
                                                           ?>>
                                                </div>
                                            </div>
                                            <div class="module-actions" id="module-<?php echo $module_key; ?>">
                                                <?php foreach ($module['actions'] as $action): ?>
                                                    <div class="permission-item">
                                                        <input type="checkbox" 
                                                               name="permissions[<?php echo $module_key; ?>][<?php echo $action; ?>]"
                                                               value="1"
                                                               id="perm_<?php echo $module_key; ?>_<?php echo $action; ?>"
                                                               <?php echo hasPermission($module_key, $action, $user_permissions, $default_permissions, $selected_user['role']) ? 'checked' : ''; ?>
                                                               onchange="updateModuleCheckbox('<?php echo $module_key; ?>')">
                                                        <label for="perm_<?php echo $module_key; ?>_<?php echo $action; ?>" class="permission-label">
                                                            <?php echo ucfirst($action); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </form>

                            <!-- Reset Form (hidden) -->
                            <form method="POST" action="" id="resetForm" style="display: none;">
                                <input type="hidden" name="action" value="reset_to_role">
                                <input type="hidden" name="user_id" value="<?php echo $selected_user_id; ?>">
                                <input type="hidden" name="role" value="<?php echo $selected_user['role']; ?>">
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="permissions-card text-center" style="padding: 60px;">
                            <i class="bi bi-shield-lock" style="font-size: 48px; color: #cbd5e0;"></i>
                            <h3 style="margin: 20px 0 10px; color: #4a5568;">Select a User</h3>
                            <p style="color: #718096;">Please select a user from the list above to manage their permissions</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Toggle all permissions in a module
        function toggleModule(checkbox, module) {
            const checkboxes = document.querySelectorAll(`#module-${module} input[type="checkbox"]`);
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        // Update module checkbox based on individual permissions
        function updateModuleCheckbox(module) {
            const checkboxes = document.querySelectorAll(`#module-${module} input[type="checkbox"]`);
            const moduleCheckbox = document.querySelector(`.module-checkbox[data-module="${module}"]`);
            
            let allChecked = true;
            let anyChecked = false;
            
            checkboxes.forEach(cb => {
                if (!cb.checked) {
                    allChecked = false;
                } else {
                    anyChecked = true;
                }
            });
            
            if (allChecked) {
                moduleCheckbox.checked = true;
                moduleCheckbox.indeterminate = false;
            } else if (anyChecked) {
                moduleCheckbox.indeterminate = true;
            } else {
                moduleCheckbox.checked = false;
                moduleCheckbox.indeterminate = false;
            }
        }

        // Select all permissions
        function selectAll() {
            document.querySelectorAll('.module-actions input[type="checkbox"]').forEach(cb => {
                cb.checked = true;
            });
            document.querySelectorAll('.module-checkbox').forEach(cb => {
                cb.checked = true;
                cb.indeterminate = false;
            });
        }

        // Deselect all permissions
        function deselectAll() {
            document.querySelectorAll('.module-actions input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            document.querySelectorAll('.module-checkbox').forEach(cb => {
                cb.checked = false;
                cb.indeterminate = false;
            });
        }

        // Expand all modules (show all actions)
        function expandAll() {
            // Already visible, nothing to do
        }

        // Collapse all modules (hide actions - not applicable in this design)
        function collapseAll() {
            // Not needed in this design
        }

        // Save permissions with confirmation
        function savePermissions() {
            Swal.fire({
                title: 'Save Permissions?',
                text: 'Are you sure you want to save these permission settings?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Save',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('permissionsForm').submit();
                }
            });
        }

        // Reset to role default
        function resetToRole() {
            Swal.fire({
                title: 'Reset to Role Default?',
                text: 'This will reset all permissions to the default settings for this role. Continue?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ecc94b',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('resetForm').submit();
                }
            });
        }

        // Show success/error messages
        <?php if (!empty($message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>

        // Initialize indeterminate states
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($modules as $module_key => $module): ?>
                updateModuleCheckbox('<?php echo $module_key; ?>');
            <?php endforeach; ?>
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }

        // Show success/error messages with SweetAlert
        <?php if (!empty($message)): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>

        <?php if (!empty($error)): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error); ?>'
        });
        <?php endif; ?>
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>