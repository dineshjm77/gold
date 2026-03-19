<?php
session_start();
$currentPage = 'edit-employee';
$pageTitle = 'Edit Employee';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Get employee ID from URL
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
    header('Location: employees.php');
    exit();
}

// Fetch employee data
$employee_query = "SELECT * FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $employee_query);
mysqli_stmt_bind_param($stmt, 'i', $employee_id);
mysqli_stmt_execute($stmt);
$employee_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($employee_result) == 0) {
    header('Location: employees.php');
    exit();
}

$employee = mysqli_fetch_assoc($employee_result);

// Check if certificate_path column exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'certificate_path'");
$has_certificate_column = mysqli_num_rows($check_column) > 0;

// Get branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get departments
$depts_query = "SELECT department_name FROM departments WHERE status = 1 ORDER BY department_name";
$depts_result = mysqli_query($conn, $depts_query);

// Get managers for reporting manager dropdown (excluding current user)
$managers_query = "SELECT id, name, role FROM users WHERE status = 'active' AND role IN ('admin', 'manager') AND id != ? ORDER BY name";
$stmt = mysqli_prepare($conn, $managers_query);
mysqli_stmt_bind_param($stmt, 'i', $employee_id);
mysqli_stmt_execute($stmt);
$managers_result = mysqli_stmt_get_result($stmt);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    // Basic Information
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'sale';
    
    // Fix: Properly handle checkbox status
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
    $employee_id_field = $_POST['employee_id'] ?? '';
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
    
    // Certificate Upload Path - Start with existing value
    $certificate_path = $employee['certificate_path'];
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($errors)) {
        // Check if username already exists (excluding current user)
        $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 'si', $username, $employee_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Username already exists. Please choose a different username.";
        } else {
            // Handle photo upload
            $employee_photo = $employee['employee_photo']; // Keep existing by default
            if (isset($_FILES['employee_photo']) && $_FILES['employee_photo']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['employee_photo']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    // Create upload directory if it doesn't exist
                    $upload_dir = 'uploads/employees/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'emp_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['employee_photo']['tmp_name'], $upload_path)) {
                        // Delete old photo if exists
                        if (!empty($employee['employee_photo']) && file_exists($employee['employee_photo'])) {
                            unlink($employee['employee_photo']);
                        }
                        $employee_photo = $upload_path;
                    }
                }
            }
            
            // Handle certificate upload (only if column exists)
            if ($has_certificate_column && isset($_FILES['employee_certificate']) && $_FILES['employee_certificate']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                $filename = $_FILES['employee_certificate']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    // Create upload directory if it doesn't exist
                    $upload_dir = 'uploads/certificates/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'cert_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['employee_certificate']['tmp_name'], $upload_path)) {
                        // Delete old certificate if exists
                        if (!empty($employee['certificate_path']) && file_exists($employee['certificate_path'])) {
                            unlink($employee['certificate_path']);
                        }
                        $certificate_path = $upload_path; // This properly sets the new path
                    }
                }
            }
            // If no new file uploaded, keep the existing path (already set at the beginning)
            
            // Handle password update (only if provided)
            $password_sql = "";
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    $error = "Password must be at least 6 characters long";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $password_sql = ", password = '$hashed_password'";
                }
            }
            
            if (empty($error)) {
                // Escape all string values for SQL
                $name = mysqli_real_escape_string($conn, $name);
                $username = mysqli_real_escape_string($conn, $username);
                $role = mysqli_real_escape_string($conn, $role);
                $gender = mysqli_real_escape_string($conn, $gender);
                $marital_status = mysqli_real_escape_string($conn, $marital_status);
                $blood_group = mysqli_real_escape_string($conn, $blood_group);
                $mobile = mysqli_real_escape_string($conn, $mobile);
                $email = mysqli_real_escape_string($conn, $email);
                $emergency_contact = mysqli_real_escape_string($conn, $emergency_contact);
                $emergency_contact_name = mysqli_real_escape_string($conn, $emergency_contact_name);
                $emergency_relation = mysqli_real_escape_string($conn, $emergency_relation);
                $address_line1 = mysqli_real_escape_string($conn, $address_line1);
                $address_line2 = mysqli_real_escape_string($conn, $address_line2);
                $city = mysqli_real_escape_string($conn, $city);
                $state = mysqli_real_escape_string($conn, $state);
                $pincode = mysqli_real_escape_string($conn, $pincode);
                $permanent_address_line1 = mysqli_real_escape_string($conn, $permanent_address_line1);
                $permanent_address_line2 = mysqli_real_escape_string($conn, $permanent_address_line2);
                $permanent_city = mysqli_real_escape_string($conn, $permanent_city);
                $permanent_state = mysqli_real_escape_string($conn, $permanent_state);
                $permanent_pincode = mysqli_real_escape_string($conn, $permanent_pincode);
                $employee_id_field = mysqli_real_escape_string($conn, $employee_id_field);
                $department = mysqli_real_escape_string($conn, $department);
                $designation = mysqli_real_escape_string($conn, $designation);
                $employment_type = mysqli_real_escape_string($conn, $employment_type);
                $reporting_manager = mysqli_real_escape_string($conn, $reporting_manager);
                $work_location = mysqli_real_escape_string($conn, $work_location);
                $shift_timing = mysqli_real_escape_string($conn, $shift_timing);
                $weekly_off = mysqli_real_escape_string($conn, $weekly_off);
                $previous_company = mysqli_real_escape_string($conn, $previous_company);
                $previous_designation = mysqli_real_escape_string($conn, $previous_designation);
                $highest_qualification = mysqli_real_escape_string($conn, $highest_qualification);
                $university = mysqli_real_escape_string($conn, $university);
                $skills = mysqli_real_escape_string($conn, $skills);
                $certifications = mysqli_real_escape_string($conn, $certifications);
                $account_holder_name = mysqli_real_escape_string($conn, $account_holder_name);
                $bank_name = mysqli_real_escape_string($conn, $bank_name);
                $branch_name = mysqli_real_escape_string($conn, $branch_name);
                $account_number = mysqli_real_escape_string($conn, $account_number);
                $ifsc_code = mysqli_real_escape_string($conn, $ifsc_code);
                $micr_code = mysqli_real_escape_string($conn, $micr_code);
                $account_type = mysqli_real_escape_string($conn, $account_type);
                $upi_id = mysqli_real_escape_string($conn, $upi_id);
                $pan_number = mysqli_real_escape_string($conn, $pan_number);
                $aadhar_number = mysqli_real_escape_string($conn, $aadhar_number);
                $pf_number = mysqli_real_escape_string($conn, $pf_number);
                $esi_number = mysqli_real_escape_string($conn, $esi_number);
                $uan_number = mysqli_real_escape_string($conn, $uan_number);
                $employee_photo = mysqli_real_escape_string($conn, $employee_photo);
                
                // Build update query based on whether certificate_path column exists
                if ($has_certificate_column) {
                    $certificate_path = mysqli_real_escape_string($conn, $certificate_path);
                    $update_query = "UPDATE users SET 
                        name = '$name',
                        username = '$username',
                        role = '$role',
                        status = $status,
                        date_of_birth = " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ",
                        gender = '$gender',
                        marital_status = '$marital_status',
                        blood_group = '$blood_group',
                        mobile = '$mobile',
                        email = '$email',
                        emergency_contact = '$emergency_contact',
                        emergency_contact_name = '$emergency_contact_name',
                        emergency_relation = '$emergency_relation',
                        address_line1 = '$address_line1',
                        address_line2 = '$address_line2',
                        city = '$city',
                        state = '$state',
                        pincode = '$pincode',
                        permanent_address_same = $permanent_address_same,
                        permanent_address_line1 = '$permanent_address_line1',
                        permanent_address_line2 = '$permanent_address_line2',
                        permanent_city = '$permanent_city',
                        permanent_state = '$permanent_state',
                        permanent_pincode = '$permanent_pincode',
                        employee_id = '$employee_id_field',
                        department = '$department',
                        designation = '$designation',
                        joining_date = " . ($joining_date ? "'$joining_date'" : "NULL") . ",
                        confirmation_date = " . ($confirmation_date ? "'$confirmation_date'" : "NULL") . ",
                        employment_type = '$employment_type',
                        reporting_manager = '$reporting_manager',
                        work_location = '$work_location',
                        shift_timing = '$shift_timing',
                        weekly_off = '$weekly_off',
                        total_experience_years = $total_experience_years,
                        total_experience_months = $total_experience_months,
                        previous_company = '$previous_company',
                        previous_designation = '$previous_designation',
                        previous_experience_years = $previous_experience_years,
                        previous_experience_months = $previous_experience_months,
                        highest_qualification = '$highest_qualification',
                        university = '$university',
                        year_of_passing = " . ($year_of_passing ? $year_of_passing : "NULL") . ",
                        percentage = " . ($percentage ? $percentage : "NULL") . ",
                        skills = '$skills',
                        certifications = '$certifications',
                        account_holder_name = '$account_holder_name',
                        bank_name = '$bank_name',
                        branch_name = '$branch_name',
                        account_number = '$account_number',
                        ifsc_code = '$ifsc_code',
                        micr_code = '$micr_code',
                        account_type = '$account_type',
                        upi_id = '$upi_id',
                        pan_number = '$pan_number',
                        aadhar_number = '$aadhar_number',
                        basic_salary = " . ($basic_salary ? $basic_salary : "NULL") . ",
                        hra = " . ($hra ? $hra : "NULL") . ",
                        conveyance = " . ($conveyance ? $conveyance : "NULL") . ",
                        medical_allowance = " . ($medical_allowance ? $medical_allowance : "NULL") . ",
                        special_allowance = " . ($special_allowance ? $special_allowance : "NULL") . ",
                        bonus = " . ($bonus ? $bonus : "NULL") . ",
                        pf_number = '$pf_number',
                        esi_number = '$esi_number',
                        uan_number = '$uan_number',
                        branch_id = " . ($branch_id ? $branch_id : "NULL") . ",
                        employee_photo = " . ($employee_photo ? "'$employee_photo'" : "NULL") . ",
                        certificate_path = " . ($certificate_path ? "'$certificate_path'" : "NULL") . ",
                        updated_at = NOW()
                        $password_sql
                        WHERE id = $employee_id";
                } else {
                    $update_query = "UPDATE users SET 
                        name = '$name',
                        username = '$username',
                        role = '$role',
                        status = $status,
                        date_of_birth = " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ",
                        gender = '$gender',
                        marital_status = '$marital_status',
                        blood_group = '$blood_group',
                        mobile = '$mobile',
                        email = '$email',
                        emergency_contact = '$emergency_contact',
                        emergency_contact_name = '$emergency_contact_name',
                        emergency_relation = '$emergency_relation',
                        address_line1 = '$address_line1',
                        address_line2 = '$address_line2',
                        city = '$city',
                        state = '$state',
                        pincode = '$pincode',
                        permanent_address_same = $permanent_address_same,
                        permanent_address_line1 = '$permanent_address_line1',
                        permanent_address_line2 = '$permanent_address_line2',
                        permanent_city = '$permanent_city',
                        permanent_state = '$permanent_state',
                        permanent_pincode = '$permanent_pincode',
                        employee_id = '$employee_id_field',
                        department = '$department',
                        designation = '$designation',
                        joining_date = " . ($joining_date ? "'$joining_date'" : "NULL") . ",
                        confirmation_date = " . ($confirmation_date ? "'$confirmation_date'" : "NULL") . ",
                        employment_type = '$employment_type',
                        reporting_manager = '$reporting_manager',
                        work_location = '$work_location',
                        shift_timing = '$shift_timing',
                        weekly_off = '$weekly_off',
                        total_experience_years = $total_experience_years,
                        total_experience_months = $total_experience_months,
                        previous_company = '$previous_company',
                        previous_designation = '$previous_designation',
                        previous_experience_years = $previous_experience_years,
                        previous_experience_months = $previous_experience_months,
                        highest_qualification = '$highest_qualification',
                        university = '$university',
                        year_of_passing = " . ($year_of_passing ? $year_of_passing : "NULL") . ",
                        percentage = " . ($percentage ? $percentage : "NULL") . ",
                        skills = '$skills',
                        certifications = '$certifications',
                        account_holder_name = '$account_holder_name',
                        bank_name = '$bank_name',
                        branch_name = '$branch_name',
                        account_number = '$account_number',
                        ifsc_code = '$ifsc_code',
                        micr_code = '$micr_code',
                        account_type = '$account_type',
                        upi_id = '$upi_id',
                        pan_number = '$pan_number',
                        aadhar_number = '$aadhar_number',
                        basic_salary = " . ($basic_salary ? $basic_salary : "NULL") . ",
                        hra = " . ($hra ? $hra : "NULL") . ",
                        conveyance = " . ($conveyance ? $conveyance : "NULL") . ",
                        medical_allowance = " . ($medical_allowance ? $medical_allowance : "NULL") . ",
                        special_allowance = " . ($special_allowance ? $special_allowance : "NULL") . ",
                        bonus = " . ($bonus ? $bonus : "NULL") . ",
                        pf_number = '$pf_number',
                        esi_number = '$esi_number',
                        uan_number = '$uan_number',
                        branch_id = " . ($branch_id ? $branch_id : "NULL") . ",
                        employee_photo = " . ($employee_photo ? "'$employee_photo'" : "NULL") . ",
                        updated_at = NOW()
                        $password_sql
                        WHERE id = $employee_id";
                }
                
                if (mysqli_query($conn, $update_query)) {
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                  VALUES (" . $_SESSION['user_id'] . ", 'update', 'Updated employee: $name', 'users', $employee_id)";
                    mysqli_query($conn, $log_query);
                    
                    // Set success message in session
                    $_SESSION['success_message'] = "Employee updated successfully!";
                    
                    // Redirect to employees page
                    header("Location: employees.php");
                    exit();
                } else {
                    $error = "Error updating employee: " . mysqli_error($conn);
                }
            }
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Format date for input fields
function formatDateForInput($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    return date('Y-m-d', strtotime($date));
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

        .edit-employee-container {
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        /* File Upload Styles */
        .file-upload-group {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .file-upload-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .file-upload-header i {
            font-size: 20px;
            color: #667eea;
        }

        .file-upload-header h4 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .file-upload-desc {
            font-size: 12px;
            color: #718096;
            margin-bottom: 10px;
        }

        .file-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px dashed #667eea;
            border-radius: 6px;
            background: white;
            font-size: 13px;
            cursor: pointer;
        }

        .file-input:hover {
            background: #f0f4ff;
        }

        .file-name {
            font-size: 12px;
            color: #48bb78;
            margin-top: 5px;
            display: none;
        }

        .file-name.show {
            display: block;
        }

        .file-name i {
            margin-right: 5px;
        }

        .current-file {
            font-size: 12px;
            color: #4a5568;
            margin-top: 5px;
            padding: 5px;
            background: #ebf4ff;
            border-radius: 4px;
        }

        .current-file i {
            color: #667eea;
            margin-right: 5px;
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

        .alert-info {
            background: #e6f7ff;
            color: #0050b3;
            border-left: 4px solid #1890ff;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        /* Info Text */
        .text-muted {
            color: #718096;
            font-size: 12px;
            margin-top: 4px;
            display: block;
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
                <div class="edit-employee-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-pencil-square"></i>
                            Edit Employee: <?php echo htmlspecialchars($employee['name']); ?>
                        </h1>
                        <div>
                            <a href="view_employee.php?id=<?php echo $employee_id; ?>" class="btn btn-info">
                                <i class="bi bi-eye"></i> View
                            </a>
                            <a href="employees.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Employees
                            </a>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php 
                                echo $_SESSION['success_message']; 
                                unset($_SESSION['success_message']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <!-- Edit Employee Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="editEmployeeForm">
                        <input type="hidden" name="update_employee" value="1">
                        
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
                            <button type="button" class="tab-btn" onclick="switchTab('documents')">Documents</button>
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
                                            <input type="text" class="form-control" name="name" placeholder="Enter full name" value="<?php echo htmlspecialchars($employee['name']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Username</label>
                                        <div class="input-group">
                                            <i class="bi bi-at input-icon"></i>
                                            <input type="text" class="form-control" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($employee['username']); ?>" required>
                                        </div>
                                        <small class="text-muted">Username must be unique</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Password</label>
                                        <div class="input-group">
                                            <i class="bi bi-lock input-icon"></i>
                                            <input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password">
                                        </div>
                                        <small class="text-muted">Minimum 6 characters. Leave blank to keep current password.</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Role</label>
                                        <div class="input-group">
                                            <i class="bi bi-shield input-icon"></i>
                                            <select class="form-select" name="role" required>
                                                <option value="">Select Role</option>
                                                <option value="admin" <?php echo $employee['role'] == 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                <option value="manager" <?php echo $employee['role'] == 'manager' ? 'selected' : ''; ?>>Manager</option>
                                                <option value="sale" <?php echo $employee['role'] == 'sale' ? 'selected' : ''; ?>>Sale</option>
                                                <option value="accountant" <?php echo $employee['role'] == 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <div class="form-check form-switch" style="margin-top: 10px;">
                                            <input class="form-check-input" type="checkbox" name="status" id="status" value="1" <?php echo ($employee['status'] == 1 || $employee['status'] == 'active') ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="status">Active</label>
                                        </div>
                                        <small class="text-muted">Check to make employee active, uncheck to deactivate</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Employee Photo</label>
                                        <div class="input-group">
                                            <i class="bi bi-camera input-icon"></i>
                                            <input type="file" class="form-control" name="employee_photo" accept="image/*">
                                        </div>
                                        <small class="text-muted">Allowed: JPG, JPEG, PNG, GIF</small>
                                        <?php if (!empty($employee['employee_photo'])): ?>
                                            <div class="current-file">
                                                <i class="bi bi-image"></i> Current: <?php echo basename($employee['employee_photo']); ?>
                                            </div>
                                        <?php endif; ?>
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
                                            <input type="date" class="form-control" name="date_of_birth" value="<?php echo formatDateForInput($employee['date_of_birth']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Gender</label>
                                        <div class="input-group">
                                            <i class="bi bi-gender-ambiguous input-icon"></i>
                                            <select class="form-select" name="gender">
                                                <option value="">Select Gender</option>
                                                <option value="Male" <?php echo $employee['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="Female" <?php echo $employee['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="Other" <?php echo $employee['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Marital Status</label>
                                        <div class="input-group">
                                            <i class="bi bi-heart input-icon"></i>
                                            <select class="form-select" name="marital_status">
                                                <option value="">Select Status</option>
                                                <option value="Single" <?php echo $employee['marital_status'] == 'Single' ? 'selected' : ''; ?>>Single</option>
                                                <option value="Married" <?php echo $employee['marital_status'] == 'Married' ? 'selected' : ''; ?>>Married</option>
                                                <option value="Divorced" <?php echo $employee['marital_status'] == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                                <option value="Widowed" <?php echo $employee['marital_status'] == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Blood Group</label>
                                        <div class="input-group">
                                            <i class="bi bi-droplet input-icon"></i>
                                            <select class="form-select" name="blood_group">
                                                <option value="">Select Blood Group</option>
                                                <option value="A+" <?php echo $employee['blood_group'] == 'A+' ? 'selected' : ''; ?>>A+</option>
                                                <option value="A-" <?php echo $employee['blood_group'] == 'A-' ? 'selected' : ''; ?>>A-</option>
                                                <option value="B+" <?php echo $employee['blood_group'] == 'B+' ? 'selected' : ''; ?>>B+</option>
                                                <option value="B-" <?php echo $employee['blood_group'] == 'B-' ? 'selected' : ''; ?>>B-</option>
                                                <option value="O+" <?php echo $employee['blood_group'] == 'O+' ? 'selected' : ''; ?>>O+</option>
                                                <option value="O-" <?php echo $employee['blood_group'] == 'O-' ? 'selected' : ''; ?>>O-</option>
                                                <option value="AB+" <?php echo $employee['blood_group'] == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                                <option value="AB-" <?php echo $employee['blood_group'] == 'AB-' ? 'selected' : ''; ?>>AB-</option>
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
                                            <input type="text" class="form-control" name="mobile" placeholder="Enter mobile number" value="<?php echo htmlspecialchars($employee['mobile']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <div class="input-group">
                                            <i class="bi bi-envelope input-icon"></i>
                                            <input type="email" class="form-control" name="email" placeholder="Enter email address" value="<?php echo htmlspecialchars($employee['email']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact</label>
                                        <div class="input-group">
                                            <i class="bi bi-telephone-forward input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact" placeholder="Emergency contact number" value="<?php echo htmlspecialchars($employee['emergency_contact']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-person input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact_name" placeholder="Emergency contact person name" value="<?php echo htmlspecialchars($employee['emergency_contact_name']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Relation</label>
                                        <div class="input-group">
                                            <i class="bi bi-people input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_relation" placeholder="Relation with emergency contact" value="<?php echo htmlspecialchars($employee['emergency_relation']); ?>">
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
                                            <input type="text" class="form-control" name="address_line1" placeholder="House no., Street" value="<?php echo htmlspecialchars($employee['address_line1']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Address Line 2</label>
                                        <div class="input-group">
                                            <i class="bi bi-house-door input-icon"></i>
                                            <input type="text" class="form-control" name="address_line2" placeholder="Area, Locality" value="<?php echo htmlspecialchars($employee['address_line2']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">City</label>
                                        <div class="input-group">
                                            <i class="bi bi-building input-icon"></i>
                                            <input type="text" class="form-control" name="city" placeholder="City" value="<?php echo htmlspecialchars($employee['city']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">State</label>
                                        <div class="input-group">
                                            <i class="bi bi-map input-icon"></i>
                                            <input type="text" class="form-control" name="state" placeholder="State" value="<?php echo htmlspecialchars($employee['state']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Pincode</label>
                                        <div class="input-group">
                                            <i class="bi bi-pin-map input-icon"></i>
                                            <input type="text" class="form-control" name="pincode" placeholder="Pincode" value="<?php echo htmlspecialchars($employee['pincode']); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-title" style="margin-top: 30px;">
                                    <i class="bi bi-house-gear"></i>
                                    Permanent Address
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permanent_address_same" id="permanent_same" value="1" <?php echo $employee['permanent_address_same'] ? 'checked' : ''; ?> onchange="togglePermanentAddress()">
                                        <label class="form-check-label" for="permanent_same">
                                            Same as Current Address
                                        </label>
                                    </div>
                                </div>

                                <div id="permanent_address_fields" style="<?php echo $employee['permanent_address_same'] ? 'display: none;' : ''; ?>">
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" class="form-control" name="permanent_address_line1" placeholder="House no., Street" value="<?php echo htmlspecialchars($employee['permanent_address_line1']); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" class="form-control" name="permanent_address_line2" placeholder="Area, Locality" value="<?php echo htmlspecialchars($employee['permanent_address_line2']); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">City</label>
                                            <input type="text" class="form-control" name="permanent_city" placeholder="City" value="<?php echo htmlspecialchars($employee['permanent_city']); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">State</label>
                                            <input type="text" class="form-control" name="permanent_state" placeholder="State" value="<?php echo htmlspecialchars($employee['permanent_state']); ?>">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" class="form-control" name="permanent_pincode" placeholder="Pincode" value="<?php echo htmlspecialchars($employee['permanent_pincode']); ?>">
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
                                            <input type="text" class="form-control" name="employee_id" placeholder="Employee ID" value="<?php echo htmlspecialchars($employee['employee_id']); ?>">
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
                                                    <option value="<?php echo htmlspecialchars($dept['department_name']); ?>" <?php echo $employee['department'] == $dept['department_name'] ? 'selected' : ''; ?>>
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
                                            <input type="text" class="form-control" name="designation" placeholder="Designation" value="<?php echo htmlspecialchars($employee['designation']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Joining Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-plus input-icon"></i>
                                            <input type="date" class="form-control" name="joining_date" value="<?php echo formatDateForInput($employee['joining_date']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Confirmation Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-check input-icon"></i>
                                            <input type="date" class="form-control" name="confirmation_date" value="<?php echo formatDateForInput($employee['confirmation_date']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Employment Type</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock input-icon"></i>
                                            <select class="form-select" name="employment_type">
                                                <option value="full_time" <?php echo $employee['employment_type'] == 'full_time' ? 'selected' : ''; ?>>Full Time</option>
                                                <option value="part_time" <?php echo $employee['employment_type'] == 'part_time' ? 'selected' : ''; ?>>Part Time</option>
                                                <option value="contract" <?php echo $employee['employment_type'] == 'contract' ? 'selected' : ''; ?>>Contract</option>
                                                <option value="intern" <?php echo $employee['employment_type'] == 'intern' ? 'selected' : ''; ?>>Intern</option>
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
                                                    <option value="<?php echo htmlspecialchars($manager['name']); ?>" <?php echo $employee['reporting_manager'] == $manager['name'] ? 'selected' : ''; ?>>
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
                                            <input type="text" class="form-control" name="work_location" placeholder="Work location" value="<?php echo htmlspecialchars($employee['work_location']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Shift Timing</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock-history input-icon"></i>
                                            <input type="text" class="form-control" name="shift_timing" placeholder="e.g., 9:00 AM - 6:00 PM" value="<?php echo htmlspecialchars($employee['shift_timing']); ?>">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Weekly Off</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-x input-icon"></i>
                                            <select class="form-select" name="weekly_off">
                                                <option value="sunday" <?php echo $employee['weekly_off'] == 'sunday' ? 'selected' : ''; ?>>Sunday</option>
                                                <option value="monday" <?php echo $employee['weekly_off'] == 'monday' ? 'selected' : ''; ?>>Monday</option>
                                                <option value="tuesday" <?php echo $employee['weekly_off'] == 'tuesday' ? 'selected' : ''; ?>>Tuesday</option>
                                                <option value="wednesday" <?php echo $employee['weekly_off'] == 'wednesday' ? 'selected' : ''; ?>>Wednesday</option>
                                                <option value="thursday" <?php echo $employee['weekly_off'] == 'thursday' ? 'selected' : ''; ?>>Thursday</option>
                                                <option value="friday" <?php echo $employee['weekly_off'] == 'friday' ? 'selected' : ''; ?>>Friday</option>
                                                <option value="saturday" <?php echo $employee['weekly_off'] == 'saturday' ? 'selected' : ''; ?>>Saturday</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Branch Selection -->
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
                                                    <option value="<?php echo $branch['id']; ?>" <?php echo $employee['branch_id'] == $branch['id'] ? 'selected' : ''; ?>>
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
                                        <input type="number" class="form-control" name="total_experience_years" min="0" value="<?php echo $employee['total_experience_years'] ?? 0; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Total Experience (Months)</label>
                                        <input type="number" class="form-control" name="total_experience_months" min="0" max="11" value="<?php echo $employee['total_experience_months'] ?? 0; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Company</label>
                                        <input type="text" class="form-control" name="previous_company" placeholder="Previous company name" value="<?php echo htmlspecialchars($employee['previous_company']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Designation</label>
                                        <input type="text" class="form-control" name="previous_designation" placeholder="Previous designation" value="<?php echo htmlspecialchars($employee['previous_designation']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Exp (Years)</label>
                                        <input type="number" class="form-control" name="previous_experience_years" min="0" value="<?php echo $employee['previous_experience_years'] ?? 0; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Exp (Months)</label>
                                        <input type="number" class="form-control" name="previous_experience_months" min="0" max="11" value="<?php echo $employee['previous_experience_months'] ?? 0; ?>">
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
                                        <input type="text" class="form-control" name="highest_qualification" placeholder="e.g., B.E., M.Sc" value="<?php echo htmlspecialchars($employee['highest_qualification']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">University</label>
                                        <input type="text" class="form-control" name="university" placeholder="University name" value="<?php echo htmlspecialchars($employee['university']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Year of Passing</label>
                                        <input type="number" class="form-control" name="year_of_passing" min="1900" max="<?php echo date('Y'); ?>" placeholder="Year" value="<?php echo $employee['year_of_passing']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Percentage/CGPA</label>
                                        <input type="number" class="form-control" name="percentage" step="0.01" min="0" max="100" placeholder="Percentage" value="<?php echo $employee['percentage']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Skills</label>
                                        <textarea class="form-control" name="skills" rows="3" placeholder="Comma separated skills"><?php echo htmlspecialchars($employee['skills']); ?></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Certifications</label>
                                        <textarea class="form-control" name="certifications" rows="3" placeholder="Certification details"><?php echo htmlspecialchars($employee['certifications']); ?></textarea>
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
                                        <input type="text" class="form-control" name="account_holder_name" placeholder="Name as in bank" value="<?php echo htmlspecialchars($employee['account_holder_name']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bank Name</label>
                                        <input type="text" class="form-control" name="bank_name" placeholder="Bank name" value="<?php echo htmlspecialchars($employee['bank_name']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Branch Name</label>
                                        <input type="text" class="form-control" name="branch_name" placeholder="Branch name" value="<?php echo htmlspecialchars($employee['branch_name']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Account Number</label>
                                        <input type="text" class="form-control" name="account_number" placeholder="Account number" value="<?php echo htmlspecialchars($employee['account_number']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">IFSC Code</label>
                                        <input type="text" class="form-control" name="ifsc_code" placeholder="IFSC code" value="<?php echo htmlspecialchars($employee['ifsc_code']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">MICR Code</label>
                                        <input type="text" class="form-control" name="micr_code" placeholder="MICR code" value="<?php echo htmlspecialchars($employee['micr_code']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Account Type</label>
                                        <select class="form-select" name="account_type">
                                            <option value="savings" <?php echo $employee['account_type'] == 'savings' ? 'selected' : ''; ?>>Savings</option>
                                            <option value="current" <?php echo $employee['account_type'] == 'current' ? 'selected' : ''; ?>>Current</option>
                                            <option value="salary" <?php echo $employee['account_type'] == 'salary' ? 'selected' : ''; ?>>Salary</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">UPI ID</label>
                                        <input type="text" class="form-control" name="upi_id" placeholder="UPI ID" value="<?php echo htmlspecialchars($employee['upi_id']); ?>">
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
                                        <input type="text" class="form-control" name="pan_number" placeholder="PAN number" value="<?php echo htmlspecialchars($employee['pan_number']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Aadhar Number</label>
                                        <input type="text" class="form-control" name="aadhar_number" placeholder="Aadhar number" value="<?php echo htmlspecialchars($employee['aadhar_number']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Basic Salary</label>
                                        <input type="number" class="form-control" name="basic_salary" step="0.01" min="0" placeholder="Basic salary" value="<?php echo $employee['basic_salary']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">HRA</label>
                                        <input type="number" class="form-control" name="hra" step="0.01" min="0" placeholder="HRA" value="<?php echo $employee['hra']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Conveyance</label>
                                        <input type="number" class="form-control" name="conveyance" step="0.01" min="0" placeholder="Conveyance" value="<?php echo $employee['conveyance']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Medical Allowance</label>
                                        <input type="number" class="form-control" name="medical_allowance" step="0.01" min="0" placeholder="Medical allowance" value="<?php echo $employee['medical_allowance']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Special Allowance</label>
                                        <input type="number" class="form-control" name="special_allowance" step="0.01" min="0" placeholder="Special allowance" value="<?php echo $employee['special_allowance']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bonus</label>
                                        <input type="number" class="form-control" name="bonus" step="0.01" min="0" placeholder="Bonus" value="<?php echo $employee['bonus']; ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">PF Number</label>
                                        <input type="text" class="form-control" name="pf_number" placeholder="PF number" value="<?php echo htmlspecialchars($employee['pf_number']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">ESI Number</label>
                                        <input type="text" class="form-control" name="esi_number" placeholder="ESI number" value="<?php echo htmlspecialchars($employee['esi_number']); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">UAN Number</label>
                                        <input type="text" class="form-control" name="uan_number" placeholder="UAN number" value="<?php echo htmlspecialchars($employee['uan_number']); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents Tab -->
                        <div class="tab-pane" id="tab-documents">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-file-earmark-text"></i>
                                    Document Upload
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle-fill"></i>
                                    <div>
                                        <strong>Upload employee certificate/document</strong><br>
                                        <small>Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX (Max size: 5MB)</small>
                                    </div>
                                </div>

                                <!-- Single Document Upload -->
                                <div class="file-upload-group">
                                    <div class="file-upload-header">
                                        <i class="bi bi-file-earmark"></i>
                                        <h4>Employee Document</h4>
                                    </div>
                                    <div class="file-upload-desc">
                                        Upload any relevant employee document (educational certificates, experience letters, identity proof, etc.)
                                    </div>
                                    <div class="file-input-wrapper">
                                        <input type="file" class="file-input" name="employee_certificate" id="employee_certificate" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" onchange="showFileName(this, 'cert-file-name')">
                                        <div class="file-name" id="cert-file-name"></div>
                                    </div>
                                    <?php if ($has_certificate_column && !empty($employee['certificate_path'])): ?>
                                        <div class="current-file">
                                            <i class="bi bi-file-earmark"></i> Current: <?php echo basename($employee['certificate_path']); ?>
                                            <br>
                                            <small class="text-muted">Upload a new file to replace the existing one.</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <a href="employees.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success" onclick="return confirmUpdate()">
                                <i class="bi bi-check-circle"></i> Update Employee
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

        // Show selected file name
        function showFileName(input, elementId) {
            const fileNameElement = document.getElementById(elementId);
            if (input.files && input.files.length > 0) {
                const fileName = input.files[0].name;
                fileNameElement.innerHTML = '<i class="bi bi-check-circle-fill"></i> Selected: ' + fileName;
                fileNameElement.classList.add('show');
            } else {
                fileNameElement.innerHTML = '';
                fileNameElement.classList.remove('show');
            }
        }

        // Confirm update with SweetAlert
        function confirmUpdate() {
            Swal.fire({
                title: 'Update Employee?',
                text: 'Are you sure you want to update this employee?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Update',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('editEmployeeForm').submit();
                }
            });
            return false;
        }

        // File size validation (5MB max)
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', function() {
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (this.files && this.files[0] && this.files[0].size > maxSize) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'File size must be less than 5MB'
                    });
                    this.value = '';
                    
                    // Clear filename display for certificate upload
                    if (this.id === 'employee_certificate') {
                        document.getElementById('cert-file-name').innerHTML = '';
                        document.getElementById('cert-file-name').classList.remove('show');
                    }
                }
            });
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>