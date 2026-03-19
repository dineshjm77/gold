<?php
session_start();
$currentPage = 'new-employee';
$pageTitle = 'Add New Employee';
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
// Generate Auto Employee ID - Sequential format EMP001, EMP002, etc.
$prefix = "EMP";

// First, let's check if there are any employees with the SIMPLE format (EMP001, EMP002)
$emp_id_query = "SELECT employee_id FROM users 
                 WHERE employee_id REGEXP '^EMP[0-9]{3}$' 
                 ORDER BY CAST(SUBSTRING(employee_id, 4) AS UNSIGNED) DESC LIMIT 1";
$emp_id_result = mysqli_query($conn, $emp_id_query);

if (mysqli_num_rows($emp_id_result) > 0) {
    $last_emp = mysqli_fetch_assoc($emp_id_result);
    // Extract the number part after 'EMP' and convert to integer
    $last_number = intval(substr($last_emp['employee_id'], 3));
    $new_number = str_pad($last_number + 1, 3, '0', STR_PAD_LEFT);
} else {
    // If no simple format employees exist, start from 001
    $new_number = '001';
}

$auto_employee_id = $prefix . $new_number;
// Get branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE status = 'active' ORDER BY branch_name";
$branches_result = mysqli_query($conn, $branches_query);

// Get departments
$depts_query = "SELECT department_name FROM departments WHERE status = 1 ORDER BY department_name";
$depts_result = mysqli_query($conn, $depts_query);

// Get managers for reporting manager dropdown
$managers_query = "SELECT id, name, role FROM users WHERE status = 'active' AND role IN ('admin', 'manager') ORDER BY name";
$managers_result = mysqli_query($conn, $managers_query);

// Get relation types
$relation_types = ['Father', 'Mother', 'Husband', 'Wife', 'Brother', 'Sister', 'Friend', 'Other'];

// Get qualification levels
$qualifications = [
    '10th' => '10th Standard',
    '12th' => '12th Standard',
    'Diploma' => 'Diploma',
    'ITI' => 'ITI',
    'B.E.' => 'Bachelor of Engineering (B.E.)',
    'B.Tech' => 'Bachelor of Technology (B.Tech)',
    'B.Sc' => 'Bachelor of Science (B.Sc)',
    'B.Com' => 'Bachelor of Commerce (B.Com)',
    'B.A.' => 'Bachelor of Arts (B.A.)',
    'BCA' => 'BCA',
    'BBA' => 'BBA',
    'M.E.' => 'Master of Engineering (M.E.)',
    'M.Tech' => 'Master of Technology (M.Tech)',
    'M.Sc' => 'Master of Science (M.Sc)',
    'M.Com' => 'Master of Commerce (M.Com)',
    'M.A.' => 'Master of Arts (M.A.)',
    'MCA' => 'MCA',
    'MBA' => 'MBA',
    'PhD' => 'Ph.D.',
    'Other' => 'Other'
];

// Check if certificate_path column exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'certificate_path'");
$has_certificate_column = mysqli_num_rows($check_column) > 0;

// Check if location fields exist and add them if needed
$check_lat_column = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'latitude'");
if (mysqli_num_rows($check_lat_column) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN latitude DECIMAL(10,8) NULL");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN longitude DECIMAL(11,8) NULL");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN place_id VARCHAR(255) NULL");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN formatted_address TEXT NULL");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Information
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
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
    $emergency_relation = $_POST['emergency_relation_type'] ?? '';
    $emergency_relation_other = $_POST['emergency_relation_other'] ?? '';
    
    // If relation type is 'Other', use the custom value
    if ($emergency_relation === 'Other' && !empty($emergency_relation_other)) {
        $emergency_relation = $emergency_relation_other;
    }
    
    // Address Information
    $address_line1 = $_POST['address_line1'] ?? '';
    $address_line2 = $_POST['address_line2'] ?? '';
    $city = $_POST['city'] ?? '';
    $state = $_POST['state'] ?? '';
    $pincode = $_POST['pincode'] ?? '';
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    $place_id = $_POST['place_id'] ?? null;
    $formatted_address = $_POST['formatted_address'] ?? null;
    
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
    $employee_id_field = $_POST['employee_id'] ?? $auto_employee_id;
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
    $highest_qualification_other = $_POST['highest_qualification_other'] ?? '';
    if ($highest_qualification === 'Other' && !empty($highest_qualification_other)) {
        $highest_qualification = $highest_qualification_other;
    }
    
    $university = $_POST['university'] ?? '';
    $year_of_passing = !empty($_POST['year_of_passing']) ? intval($_POST['year_of_passing']) : null;
    $percentage = !empty($_POST['percentage']) ? floatval($_POST['percentage']) : null;
    $skills = $_POST['skills'] ?? '';
    $certifications = $_POST['certifications'] ?? '';
    
    // Bank Details
    $account_holder_name = $_POST['account_holder_name'] ?? '';
    $bank_name = $_POST['bank_name'] ?? '';
    $branch_name = $_POST['branch_name'] ?? '';
    $bank_address = $_POST['bank_address'] ?? '';
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
    
    // Certificate Upload Path
    $certificate_path = null;
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($username)) {
        $errors[] = "Username is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    if (empty($errors)) {
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
            
            // Handle certificate upload
            if ($has_certificate_column && isset($_FILES['employee_certificate']) && $_FILES['employee_certificate']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                $filename = $_FILES['employee_certificate']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $upload_dir = 'uploads/certificates/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'cert_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['employee_certificate']['tmp_name'], $upload_path)) {
                        $certificate_path = $upload_path;
                    }
                }
            }
            
            // Handle camera capture upload
            if (isset($_POST['camera_photo_data']) && !empty($_POST['camera_photo_data'])) {
                $image_data = $_POST['camera_photo_data'];
                $image_data = str_replace('data:image/png;base64,', '', $image_data);
                $image_data = str_replace(' ', '+', $image_data);
                $image_data = base64_decode($image_data);
                
                $upload_dir = 'uploads/employees/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'cam_' . time() . '_' . rand(1000, 9999) . '.png';
                $upload_path = $upload_dir . $new_filename;
                
                if (file_put_contents($upload_path, $image_data)) {
                    $employee_photo = $upload_path;
                }
            }
            
            // Handle camera certificate upload
            if (isset($_POST['camera_certificate_data']) && !empty($_POST['camera_certificate_data'])) {
                $image_data = $_POST['camera_certificate_data'];
                $image_data = str_replace('data:image/png;base64,', '', $image_data);
                $image_data = str_replace(' ', '+', $image_data);
                $image_data = base64_decode($image_data);
                
                $upload_dir = 'uploads/certificates/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'cert_cam_' . time() . '_' . rand(1000, 9999) . '.png';
                $upload_path = $upload_dir . $new_filename;
                
                if (file_put_contents($upload_path, $image_data)) {
                    $certificate_path = $upload_path;
                }
            }
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
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
            $latitude = $latitude ? mysqli_real_escape_string($conn, $latitude) : null;
            $longitude = $longitude ? mysqli_real_escape_string($conn, $longitude) : null;
            $place_id = $place_id ? mysqli_real_escape_string($conn, $place_id) : null;
            $formatted_address = $formatted_address ? mysqli_real_escape_string($conn, $formatted_address) : null;
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
            $bank_address = mysqli_real_escape_string($conn, $bank_address);
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
            
            // Build insert query with new fields
            if ($has_certificate_column) {
                $certificate_path = mysqli_real_escape_string($conn, $certificate_path);
                $insert_query = "INSERT INTO users (
                    name, username, password, role, status,
                    date_of_birth, gender, marital_status, blood_group,
                    mobile, email, emergency_contact, emergency_contact_name, emergency_relation,
                    address_line1, address_line2, city, state, pincode,
                    latitude, longitude, place_id, formatted_address,
                    permanent_address_same, permanent_address_line1, permanent_address_line2,
                    permanent_city, permanent_state, permanent_pincode,
                    employee_id, department, designation, joining_date, confirmation_date,
                    employment_type, reporting_manager, work_location, shift_timing, weekly_off,
                    total_experience_years, total_experience_months, previous_company,
                    previous_designation, previous_experience_years, previous_experience_months,
                    highest_qualification, university, year_of_passing, percentage,
                    skills, certifications,
                    account_holder_name, bank_name, branch_name, bank_address, account_number,
                    ifsc_code, micr_code, account_type, upi_id,
                    pan_number, aadhar_number,
                    basic_salary, hra, conveyance, medical_allowance, special_allowance, bonus,
                    pf_number, esi_number, uan_number,
                    branch_id, employee_photo, certificate_path, created_at
                ) VALUES (
                    '$name', '$username', '$hashed_password', '$role', $status,
                    " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ", '$gender', '$marital_status', '$blood_group',
                    '$mobile', '$email', '$emergency_contact', '$emergency_contact_name', '$emergency_relation',
                    '$address_line1', '$address_line2', '$city', '$state', '$pincode',
                    " . ($latitude ? "'$latitude'" : "NULL") . ", " . ($longitude ? "'$longitude'" : "NULL") . ", " . ($place_id ? "'$place_id'" : "NULL") . ", " . ($formatted_address ? "'$formatted_address'" : "NULL") . ",
                    $permanent_address_same, '$permanent_address_line1', '$permanent_address_line2',
                    '$permanent_city', '$permanent_state', '$permanent_pincode',
                    '$employee_id_field', '$department', '$designation', " . ($joining_date ? "'$joining_date'" : "NULL") . ", " . ($confirmation_date ? "'$confirmation_date'" : "NULL") . ",
                    '$employment_type', '$reporting_manager', '$work_location', '$shift_timing', '$weekly_off',
                    $total_experience_years, $total_experience_months, '$previous_company',
                    '$previous_designation', $previous_experience_years, $previous_experience_months,
                    '$highest_qualification', '$university', " . ($year_of_passing ? $year_of_passing : "NULL") . ", " . ($percentage ? $percentage : "NULL") . ",
                    '$skills', '$certifications',
                    '$account_holder_name', '$bank_name', '$branch_name', '$bank_address', '$account_number',
                    '$ifsc_code', '$micr_code', '$account_type', '$upi_id',
                    '$pan_number', '$aadhar_number',
                    " . ($basic_salary ? $basic_salary : "NULL") . ", " . ($hra ? $hra : "NULL") . ", " . ($conveyance ? $conveyance : "NULL") . ", " . ($medical_allowance ? $medical_allowance : "NULL") . ", " . ($special_allowance ? $special_allowance : "NULL") . ", " . ($bonus ? $bonus : "NULL") . ",
                    '$pf_number', '$esi_number', '$uan_number',
                    " . ($branch_id ? $branch_id : "NULL") . ", " . ($employee_photo ? "'$employee_photo'" : "NULL") . ", " . ($certificate_path ? "'$certificate_path'" : "NULL") . ", NOW()
                )";
            } else {
                $insert_query = "INSERT INTO users (
                    name, username, password, role, status,
                    date_of_birth, gender, marital_status, blood_group,
                    mobile, email, emergency_contact, emergency_contact_name, emergency_relation,
                    address_line1, address_line2, city, state, pincode,
                    latitude, longitude, place_id, formatted_address,
                    permanent_address_same, permanent_address_line1, permanent_address_line2,
                    permanent_city, permanent_state, permanent_pincode,
                    employee_id, department, designation, joining_date, confirmation_date,
                    employment_type, reporting_manager, work_location, shift_timing, weekly_off,
                    total_experience_years, total_experience_months, previous_company,
                    previous_designation, previous_experience_years, previous_experience_months,
                    highest_qualification, university, year_of_passing, percentage,
                    skills, certifications,
                    account_holder_name, bank_name, branch_name, bank_address, account_number,
                    ifsc_code, micr_code, account_type, upi_id,
                    pan_number, aadhar_number,
                    basic_salary, hra, conveyance, medical_allowance, special_allowance, bonus,
                    pf_number, esi_number, uan_number,
                    branch_id, employee_photo, created_at
                ) VALUES (
                    '$name', '$username', '$hashed_password', '$role', $status,
                    " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ", '$gender', '$marital_status', '$blood_group',
                    '$mobile', '$email', '$emergency_contact', '$emergency_contact_name', '$emergency_relation',
                    '$address_line1', '$address_line2', '$city', '$state', '$pincode',
                    " . ($latitude ? "'$latitude'" : "NULL") . ", " . ($longitude ? "'$longitude'" : "NULL") . ", " . ($place_id ? "'$place_id'" : "NULL") . ", " . ($formatted_address ? "'$formatted_address'" : "NULL") . ",
                    $permanent_address_same, '$permanent_address_line1', '$permanent_address_line2',
                    '$permanent_city', '$permanent_state', '$permanent_pincode',
                    '$employee_id_field', '$department', '$designation', " . ($joining_date ? "'$joining_date'" : "NULL") . ", " . ($confirmation_date ? "'$confirmation_date'" : "NULL") . ",
                    '$employment_type', '$reporting_manager', '$work_location', '$shift_timing', '$weekly_off',
                    $total_experience_years, $total_experience_months, '$previous_company',
                    '$previous_designation', $previous_experience_years, $previous_experience_months,
                    '$highest_qualification', '$university', " . ($year_of_passing ? $year_of_passing : "NULL") . ", " . ($percentage ? $percentage : "NULL") . ",
                    '$skills', '$certifications',
                    '$account_holder_name', '$bank_name', '$branch_name', '$bank_address', '$account_number',
                    '$ifsc_code', '$micr_code', '$account_type', '$upi_id',
                    '$pan_number', '$aadhar_number',
                    " . ($basic_salary ? $basic_salary : "NULL") . ", " . ($hra ? $hra : "NULL") . ", " . ($conveyance ? $conveyance : "NULL") . ", " . ($medical_allowance ? $medical_allowance : "NULL") . ", " . ($special_allowance ? $special_allowance : "NULL") . ", " . ($bonus ? $bonus : "NULL") . ",
                    '$pf_number', '$esi_number', '$uan_number',
                    " . ($branch_id ? $branch_id : "NULL") . ", " . ($employee_photo ? "'$employee_photo'" : "NULL") . ", NOW()
                )";
            }
            
            if (mysqli_query($conn, $insert_query)) {
                $user_id = mysqli_insert_id($conn);
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (" . $_SESSION['user_id'] . ", 'create', 'Created new employee: $name', 'users', $user_id)";
                mysqli_query($conn, $log_query);
                
                // Set success message in session
                $_SESSION['success_message'] = "Employee added successfully!";
                
                // Redirect to employees page
                header("Location: employees.php");
                exit();
            } else {
                $error = "Error adding employee: " . mysqli_error($conn);
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

        .new-employee-container {
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
            position: relative;
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

        .input-icon-right {
            position: absolute;
            right: 12px;
            color: #a0aec0;
            z-index: 1;
            cursor: pointer;
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

        .password-input {
            padding-right: 40px;
        }

        textarea.form-control {
            padding-left: 12px;
        }

        /* Style for uppercase input fields */
        input[style*="text-transform: uppercase"] {
            text-transform: uppercase;
        }

        input[style*="text-transform: uppercase"]::placeholder {
            text-transform: none;
        }

        /* IFSC Auto-fill Styles */
        .ifsc-fetching {
            font-size: 11px;
            color: #667eea;
            margin-top: 3px;
            display: none;
        }

        .ifsc-fetching.show {
            display: block;
        }

        .ifsc-success {
            font-size: 11px;
            color: #48bb78;
            margin-top: 3px;
            display: none;
        }

        .ifsc-success.show {
            display: block;
        }

        .ifsc-error {
            font-size: 11px;
            color: #f56565;
            margin-top: 3px;
            display: none;
        }

        .ifsc-error.show {
            display: block;
        }

        .spinner {
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Camera Upload Styles */
        .camera-upload-group {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .camera-preview {
            width: 100%;
            max-width: 300px;
            margin: 10px auto;
            display: none;
        }

        .camera-preview video, .camera-preview canvas {
            width: 100%;
            border-radius: 8px;
            border: 2px solid #667eea;
        }

        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .camera-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .camera-btn.start {
            background: #667eea;
            color: white;
        }

        .camera-btn.switch {
            background: #48bb78;
            color: white;
        }

        .camera-btn.capture {
            background: #f56565;
            color: white;
        }

        .camera-btn.stop {
            background: #a0aec0;
            color: white;
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
            flex-wrap: wrap;
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

        /* Map Styles */
        .map-container {
            height: 250px;
            margin: 10px 0;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #f0f4ff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .map-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .search-container input {
            flex: 1;
        }

        /* Search Results */
        #search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            position: absolute;
            z-index: 1000;
            width: calc(100% - 120px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: none;
        }

        #search-results div {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
        }

        #search-results div:hover {
            background: #f0f4ff;
        }

        #search-results div:last-child {
            border-bottom: none;
        }

        /* Selected Address Display */
        .selected-address-display {
            margin-top: 10px;
            padding: 10px;
            background: #f0f4ff;
            border-radius: 6px;
            border-left: 3px solid #667eea;
            display: none;
        }

        .selected-address-display i {
            color: #667eea;
            margin-right: 5px;
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

        /* Employee ID Display */
        .employee-id-display {
            background: #ebf4ff;
            padding: 10px;
            border-radius: 6px;
            border-left: 3px solid #667eea;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .employee-id-display strong {
            color: #667eea;
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
            
            #search-results {
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
                <div class="new-employee-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-person-plus"></i>
                            Add New Employee
                        </h1>
                        <div>
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

                    <!-- Auto-generated Employee ID Display -->
                    <div class="employee-id-display">
                        <i class="bi bi-card-text"></i> <strong>Auto-generated Employee ID:</strong> <?php echo $auto_employee_id; ?>
                    </div>

                    <!-- Add Employee Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="addEmployeeForm">
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
                                            <input type="text" class="form-control" name="name" placeholder="Enter full name" required>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Username</label>
                                        <div class="input-group">
                                            <i class="bi bi-at input-icon"></i>
                                            <input type="text" class="form-control" name="username" placeholder="Enter username" required>
                                        </div>
                                        <small class="text-muted">Username must be unique</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Password</label>
                                        <div class="input-group">
                                            <i class="bi bi-lock input-icon"></i>
                                            <input type="password" class="form-control password-input" name="password" id="password" placeholder="Enter password" required>
                                            <i class="bi bi-eye input-icon-right" id="togglePassword" style="cursor: pointer;"></i>
                                        </div>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label required">Role</label>
                                        <div class="input-group">
                                            <i class="bi bi-shield input-icon"></i>
                                            <select class="form-select" name="role" required>
                                                <option value="">Select Role</option>
                                                <option value="admin">Admin</option>
                                                <option value="manager">Manager</option>
                                                <option value="sale">Sale</option>
                                                <option value="accountant">Accountant</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Status</label>
                                        <div class="form-check form-switch" style="margin-top: 10px;">
                                            <input class="form-check-input" type="checkbox" name="status" id="status" value="1" checked>
                                            <label class="form-check-label" for="status">Active</label>
                                        </div>
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
                                            <input type="date" class="form-control" name="date_of_birth">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Gender</label>
                                        <div class="input-group">
                                            <i class="bi bi-gender-ambiguous input-icon"></i>
                                            <select class="form-select" name="gender">
                                                <option value="">Select Gender</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Marital Status</label>
                                        <div class="input-group">
                                            <i class="bi bi-heart input-icon"></i>
                                            <select class="form-select" name="marital_status">
                                                <option value="">Select Status</option>
                                                <option value="Single">Single</option>
                                                <option value="Married">Married</option>
                                                <option value="Divorced">Divorced</option>
                                                <option value="Widowed">Widowed</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Blood Group</label>
                                        <div class="input-group">
                                            <i class="bi bi-droplet input-icon"></i>
                                            <select class="form-select" name="blood_group">
                                                <option value="">Select Blood Group</option>
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
                                            <input type="text" class="form-control" name="mobile" placeholder="Enter mobile number">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <div class="input-group">
                                            <i class="bi bi-envelope input-icon"></i>
                                            <input type="email" class="form-control" name="email" placeholder="Enter email address">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact</label>
                                        <div class="input-group">
                                            <i class="bi bi-telephone-forward input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact" placeholder="Emergency contact number">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Emergency Contact Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-person input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_contact_name" placeholder="Emergency contact person name">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Relation Type</label>
                                        <div class="input-group">
                                            <i class="bi bi-people input-icon"></i>
                                            <select class="form-select" name="emergency_relation_type" id="emergency_relation_type" onchange="toggleRelationOther()">
                                                <option value="">Select Relation</option>
                                                <?php foreach ($relation_types as $relation): ?>
                                                    <option value="<?php echo $relation; ?>"><?php echo $relation; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-group" id="relation_other_group" style="display: none;">
                                        <label class="form-label">Other Relation (Specify)</label>
                                        <div class="input-group">
                                            <i class="bi bi-pencil input-icon"></i>
                                            <input type="text" class="form-control" name="emergency_relation_other" placeholder="Enter relation">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address Tab - Using OpenStreetMap (Free) with Editable Fields -->
                        <div class="tab-pane" id="tab-address">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-house"></i>
                                    Current Address with Map
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Search Address</label>
                                    <div class="search-container">
                                        <input type="text" id="address-search" class="form-control" placeholder="Type your address (e.g., Gollapatti, Dharmapuri)...">
                                        <button type="button" class="btn btn-primary" onclick="searchAddress()">
                                            <i class="bi bi-search"></i> Search
                                        </button>
                                    </div>
                                    <div id="search-results"></div>
                                   
                                </div>

                                <!-- Selected Address Display -->
                                <div id="selected-address-display" class="selected-address-display">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span id="selected-address-text"></span>
                                </div>

                                <input type="hidden" name="latitude" id="latitude">
                                <input type="hidden" name="longitude" id="longitude">
                                <input type="hidden" name="place_id" id="place_id">
                                <input type="hidden" name="formatted_address" id="formatted_address">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Address Line 1</label>
                                        <div class="input-group">
                                            <i class="bi bi-house-door input-icon"></i>
                                            <input type="text" class="form-control" name="address_line1" id="address_line1" placeholder="Door No, Street">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Address Line 2</label>
                                        <div class="input-group">
                                            <i class="bi bi-house-door input-icon"></i>
                                            <input type="text" class="form-control" name="address_line2" id="address_line2" placeholder="Area, Locality">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">City</label>
                                        <div class="input-group">
                                            <i class="bi bi-building input-icon"></i>
                                            <input type="text" class="form-control" name="city" id="city" placeholder="City">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">State</label>
                                        <div class="input-group">
                                            <i class="bi bi-map input-icon"></i>
                                            <input type="text" class="form-control" name="state" id="state" placeholder="State">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Pincode</label>
                                        <div class="input-group">
                                            <i class="bi bi-pin-map input-icon"></i>
                                            <input type="text" class="form-control" name="pincode" id="pincode" placeholder="Pincode">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-title" style="margin-top: 30px;">
                                    <i class="bi bi-house-gear"></i>
                                    Permanent Address
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="permanent_address_same" id="permanent_same" value="1" checked onchange="togglePermanentAddress()">
                                        <label class="form-check-label" for="permanent_same">
                                            Same as Current Address
                                        </label>
                                    </div>
                                </div>

                                <div id="permanent_address_fields" style="display: none;">
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Address Line 1</label>
                                            <input type="text" class="form-control" name="permanent_address_line1" placeholder="House no., Street">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Address Line 2</label>
                                            <input type="text" class="form-control" name="permanent_address_line2" placeholder="Area, Locality">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">City</label>
                                            <input type="text" class="form-control" name="permanent_city" placeholder="City">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">State</label>
                                            <input type="text" class="form-control" name="permanent_state" placeholder="State">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Pincode</label>
                                            <input type="text" class="form-control" name="permanent_pincode" placeholder="Pincode">
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
                                            <input type="text" class="form-control" name="employee_id" value="<?php echo $auto_employee_id; ?>" readonly>
                                        </div>
                                        <small class="text-muted">Auto-generated</small>
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
                                                    <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
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
                                            <input type="text" class="form-control" name="designation" placeholder="Designation">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Joining Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-plus input-icon"></i>
                                            <input type="date" class="form-control" name="joining_date">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Confirmation Date</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-check input-icon"></i>
                                            <input type="date" class="form-control" name="confirmation_date">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Employment Type</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock input-icon"></i>
                                            <select class="form-select" name="employment_type">
                                                <option value="full_time">Full Time</option>
                                                <option value="part_time">Part Time</option>
                                                <option value="contract">Contract</option>
                                                <option value="intern">Intern</option>
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
                                                    <option value="<?php echo htmlspecialchars($manager['name']); ?>">
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
                                            <input type="text" class="form-control" name="work_location" placeholder="Work location">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Shift Timing</label>
                                        <div class="input-group">
                                            <i class="bi bi-clock-history input-icon"></i>
                                            <input type="text" class="form-control" name="shift_timing" placeholder="e.g., 9:00 AM - 6:00 PM">
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Weekly Off</label>
                                        <div class="input-group">
                                            <i class="bi bi-calendar-x input-icon"></i>
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
                                </div>

                                <div class="form-title" style="margin-top: 30px;">
                                    <i class="bi bi-clock-history"></i>
                                    Experience
                                </div>
                                <div class="form-grid">
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
                                        <input type="text" class="form-control" name="previous_company" placeholder="Previous company name">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Previous Designation</label>
                                        <input type="text" class="form-control" name="previous_designation" placeholder="Previous designation">
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
                                        <select class="form-select" name="highest_qualification" id="highest_qualification" onchange="toggleQualificationOther()">
                                            <option value="">Select Qualification</option>
                                            <?php foreach ($qualifications as $key => $value): ?>
                                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group" id="qualification_other_group" style="display: none;">
                                        <label class="form-label">Other Qualification (Specify)</label>
                                        <input type="text" class="form-control" name="highest_qualification_other" placeholder="Enter qualification">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">University</label>
                                        <input type="text" class="form-control" name="university" placeholder="University name">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Year of Passing</label>
                                        <input type="number" class="form-control" name="year_of_passing" min="1900" max="<?php echo date('Y'); ?>" placeholder="Year">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Percentage/CGPA</label>
                                        <input type="number" class="form-control" name="percentage" step="0.01" min="0" max="100" placeholder="Percentage">
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
                        </div>

                        <!-- Bank Details Tab - WITH IFSC AUTO-FILL and Uppercase -->
                        <div class="tab-pane" id="tab-bank">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-bank"></i>
                                    Bank Details
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Account Holder Name</label>
                                        <input type="text" class="form-control" name="account_holder_name" placeholder="Name as in bank">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">IFSC Code</label>
                                        <div class="input-group">
                                            <i class="bi bi-upc-scan input-icon"></i>
                                            <input type="text" class="form-control" name="ifsc_code" id="ifsc_code" placeholder="Enter IFSC code" maxlength="11" oninput="fetchBankDetails()" style="text-transform: uppercase;">
                                        </div>
                                        <div class="ifsc-fetching" id="ifscFetching">
                                            <i class="bi bi-arrow-repeat spinner"></i> Fetching bank details...
                                        </div>
                                        <div class="ifsc-success" id="ifscSuccess">
                                            <i class="bi bi-check-circle"></i> Bank details fetched successfully
                                        </div>
                                        <div class="ifsc-error" id="ifscError">
                                            <i class="bi bi-exclamation-triangle"></i> Invalid IFSC code
                                        </div>
                                        <small class="text-muted">Enter 11-character IFSC to auto-fill</small>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bank Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-bank input-icon"></i>
                                            <input type="text" class="form-control" name="bank_name" id="bank_name" placeholder="Auto-filled from IFSC" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Branch Name</label>
                                        <div class="input-group">
                                            <i class="bi bi-diagram-3 input-icon"></i>
                                            <input type="text" class="form-control" name="branch_name" id="branch_name" placeholder="Auto-filled from IFSC" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bank Address</label>
                                        <div class="input-group">
                                            <i class="bi bi-geo-alt input-icon"></i>
                                            <input type="text" class="form-control" name="bank_address" id="bank_address" placeholder="Auto-filled from IFSC" readonly>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Account Number</label>
                                        <input type="text" class="form-control" name="account_number" id="account_number" placeholder="Account number">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Confirm Account Number</label>
                                        <input type="text" class="form-control" name="confirm_account_number" id="confirm_account_number" placeholder="Re-enter account number" oninput="validateAccountNumber()">
                                        <div class="ifsc-error" id="accountMatch" style="display: none;">Account numbers do not match</div>
                                        <div class="ifsc-success" id="accountMatchSuccess" style="display: none;">Account numbers match</div>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">MICR Code</label>
                                        <input type="text" class="form-control" name="micr_code" id="micr_code" placeholder="MICR code" style="text-transform: uppercase;">
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
                                        <input type="text" class="form-control" name="upi_id" placeholder="UPI ID">
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
                                        <input type="text" class="form-control" name="pan_number" id="pan_number" placeholder="PAN number" style="text-transform: uppercase;">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Aadhar Number</label>
                                        <input type="text" class="form-control" name="aadhar_number" placeholder="Aadhar number">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Basic Salary</label>
                                        <input type="number" class="form-control" name="basic_salary" step="0.01" min="0" placeholder="Basic salary">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">HRA</label>
                                        <input type="number" class="form-control" name="hra" step="0.01" min="0" placeholder="HRA">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Conveyance</label>
                                        <input type="number" class="form-control" name="conveyance" step="0.01" min="0" placeholder="Conveyance">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Medical Allowance</label>
                                        <input type="number" class="form-control" name="medical_allowance" step="0.01" min="0" placeholder="Medical allowance">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Special Allowance</label>
                                        <input type="number" class="form-control" name="special_allowance" step="0.01" min="0" placeholder="Special allowance">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Bonus</label>
                                        <input type="number" class="form-control" name="bonus" step="0.01" min="0" placeholder="Bonus">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">PF Number</label>
                                        <input type="text" class="form-control" name="pf_number" placeholder="PF number">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">ESI Number</label>
                                        <input type="text" class="form-control" name="esi_number" placeholder="ESI number">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">UAN Number</label>
                                        <input type="text" class="form-control" name="uan_number" placeholder="UAN number">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents Tab -->
                        <div class="tab-pane" id="tab-documents">
                            <div class="form-card">
                                <div class="form-title">
                                    <i class="bi bi-file-earmark-text"></i>
                                    Document Upload with Camera
                                </div>
                                
                                <!-- Employee Photo Camera -->
                                <div class="camera-upload-group">
                                    <div class="file-upload-header">
                                        <i class="bi bi-camera"></i>
                                        <h4>Take Employee Photo with Camera</h4>
                                    </div>
                                    <div class="file-upload-desc">
                                        Use camera to capture employee photo directly
                                    </div>
                                    
                                    <div id="photo-camera-preview" class="camera-preview">
                                        <video id="photo-video" autoplay playsinline></video>
                                        <canvas id="photo-canvas" style="display: none;"></canvas>
                                    </div>
                                    
                                    <div class="camera-controls">
                                        <button type="button" class="camera-btn start" id="start-photo-camera" onclick="startCamera('photo')">
                                            <i class="bi bi-camera"></i> Start Camera
                                        </button>
                                        <button type="button" class="camera-btn switch" id="switch-photo-camera" onclick="switchCamera('photo')" style="display: none;">
                                            <i class="bi bi-arrow-repeat"></i> Switch Camera
                                        </button>
                                        <button type="button" class="camera-btn capture" id="capture-photo" onclick="capturePhoto('photo')" style="display: none;">
                                            <i class="bi bi-camera-fill"></i> Capture
                                        </button>
                                        <button type="button" class="camera-btn stop" id="stop-photo-camera" onclick="stopCamera('photo')" style="display: none;">
                                            <i class="bi bi-stop"></i> Stop
                                        </button>
                                    </div>
                                    <input type="hidden" name="camera_photo_data" id="camera_photo_data">
                                    <div id="photo-captured" class="file-name"></div>
                                </div>

                                <!-- Regular Photo Upload -->
                                <div class="file-upload-group">
                                    <div class="file-upload-header">
                                        <i class="bi bi-image"></i>
                                        <h4>Upload Employee Photo</h4>
                                    </div>
                                    <div class="file-upload-desc">
                                        Upload employee photo from device
                                    </div>
                                    <div class="file-input-wrapper">
                                        <input type="file" class="file-input" name="employee_photo" id="employee_photo" accept="image/*" onchange="showFileName(this, 'photo-file-name')">
                                        <div class="file-name" id="photo-file-name"></div>
                                    </div>
                                </div>

                                <!-- Document Camera -->
                                <div class="camera-upload-group">
                                    <div class="file-upload-header">
                                        <i class="bi bi-file-earmark"></i>
                                        <h4>Take Document Photo with Camera</h4>
                                    </div>
                                    <div class="file-upload-desc">
                                        Use camera to capture document directly
                                    </div>
                                    
                                    <div id="doc-camera-preview" class="camera-preview">
                                        <video id="doc-video" autoplay playsinline></video>
                                        <canvas id="doc-canvas" style="display: none;"></canvas>
                                    </div>
                                    
                                    <div class="camera-controls">
                                        <button type="button" class="camera-btn start" id="start-doc-camera" onclick="startCamera('doc')">
                                            <i class="bi bi-camera"></i> Start Camera
                                        </button>
                                        <button type="button" class="camera-btn switch" id="switch-doc-camera" onclick="switchCamera('doc')" style="display: none;">
                                            <i class="bi bi-arrow-repeat"></i> Switch Camera
                                        </button>
                                        <button type="button" class="camera-btn capture" id="capture-doc" onclick="capturePhoto('doc')" style="display: none;">
                                            <i class="bi bi-camera-fill"></i> Capture
                                        </button>
                                        <button type="button" class="camera-btn stop" id="stop-doc-camera" onclick="stopCamera('doc')" style="display: none;">
                                            <i class="bi bi-stop"></i> Stop
                                        </button>
                                    </div>
                                    <input type="hidden" name="camera_certificate_data" id="camera_certificate_data">
                                    <div id="doc-captured" class="file-name"></div>
                                </div>

                                <!-- Regular Document Upload -->
                                <div class="file-upload-group">
                                    <div class="file-upload-header">
                                        <i class="bi bi-file-earmark"></i>
                                        <h4>Upload Document</h4>
                                    </div>
                                    <div class="file-upload-desc">
                                        Upload employee document from device (educational certificates, experience letters, identity proof, etc.)
                                    </div>
                                    <div class="file-input-wrapper">
                                        <input type="file" class="file-input" name="employee_certificate" id="employee_certificate" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx" onchange="showFileName(this, 'cert-file-name')">
                                        <div class="file-name" id="cert-file-name"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <a href="employees.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-success" onclick="return confirmAdd()">
                                <i class="bi bi-check-circle"></i> Add Employee
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

        // Toggle relation other field
        function toggleRelationOther() {
            const relationType = document.getElementById('emergency_relation_type');
            const otherGroup = document.getElementById('relation_other_group');
            
            if (relationType.value === 'Other') {
                otherGroup.style.display = 'block';
            } else {
                otherGroup.style.display = 'none';
            }
        }

        // Toggle qualification other field
        function toggleQualificationOther() {
            const qualification = document.getElementById('highest_qualification');
            const otherGroup = document.getElementById('qualification_other_group');
            
            if (qualification.value === 'Other') {
                otherGroup.style.display = 'block';
            } else {
                otherGroup.style.display = 'none';
            }
        }

        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        if (togglePassword && password) {
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.classList.toggle('bi-eye');
                this.classList.toggle('bi-eye-slash');
            });
        }

        // Convert bank fields to uppercase
        function convertToUppercase() {
            // IFSC Code
            const ifscField = document.getElementById('ifsc_code');
            if (ifscField) {
                ifscField.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
            
            // MICR Code
            const micrField = document.getElementById('micr_code');
            if (micrField) {
                micrField.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
            
            // PAN Number
            const panField = document.getElementById('pan_number');
            if (panField) {
                panField.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        }

        // Call convertToUppercase when page loads
        document.addEventListener('DOMContentLoaded', function() {
            convertToUppercase();
        });

        // IFSC Code Auto-fill Function
        function fetchBankDetails() {
            const ifsc = document.getElementById('ifsc_code').value.trim().toUpperCase();
            const fetchingDiv = document.getElementById('ifscFetching');
            const successDiv = document.getElementById('ifscSuccess');
            const errorDiv = document.getElementById('ifscError');
            
            // Hide all status divs initially
            if (fetchingDiv) fetchingDiv.classList.remove('show');
            if (successDiv) successDiv.classList.remove('show');
            if (errorDiv) errorDiv.classList.remove('show');
            
            // Clear fields if IFSC is empty
            if (ifsc.length === 0) {
                document.getElementById('bank_name').value = '';
                document.getElementById('branch_name').value = '';
                document.getElementById('bank_address').value = '';
                return;
            }
            
            // Check if IFSC is 11 characters (standard length)
            if (ifsc.length === 11) {
                // Show fetching indicator
                if (fetchingDiv) fetchingDiv.classList.add('show');
                
                // Using Razorpay IFSC API (free, no API key required)
                fetch(`https://ifsc.razorpay.com/${ifsc}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('IFSC not found');
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Hide fetching indicator
                        if (fetchingDiv) fetchingDiv.classList.remove('show');
                        
                        if (data.BANK) {
                            document.getElementById('bank_name').value = data.BANK || '';
                            document.getElementById('branch_name').value = data.BRANCH || '';
                            
                            // Construct full address
                            let address = data.ADDRESS || '';
                            if (data.CITY) address += (address ? ', ' : '') + data.CITY;
                            if (data.DISTRICT) address += (address ? ', ' : '') + data.DISTRICT;
                            if (data.STATE) address += (address ? ', ' : '') + data.STATE;
                            document.getElementById('bank_address').value = address;
                            
                            // Show success message
                            if (successDiv) {
                                successDiv.classList.add('show');
                                setTimeout(() => {
                                    successDiv.classList.remove('show');
                                }, 3000);
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching bank details:', error);
                        if (fetchingDiv) fetchingDiv.classList.remove('show');
                        
                        // Show error message
                        if (errorDiv) {
                            errorDiv.classList.add('show');
                            setTimeout(() => {
                                errorDiv.classList.remove('show');
                            }, 3000);
                        }
                        
                        // Clear fields
                        document.getElementById('bank_name').value = '';
                        document.getElementById('branch_name').value = '';
                        document.getElementById('bank_address').value = '';
                    });
            } else if (ifsc.length > 0) {
                // IFSC is not 11 characters, clear fields but don't show error until 11 chars
                document.getElementById('bank_name').value = '';
                document.getElementById('branch_name').value = '';
                document.getElementById('bank_address').value = '';
            }
        }

        // Validate account number match
        function validateAccountNumber() {
            const accountNumber = document.getElementById('account_number').value;
            const confirmNumber = document.getElementById('confirm_account_number').value;
            const matchDiv = document.getElementById('accountMatch');
            const matchSuccess = document.getElementById('accountMatchSuccess');
            
            if (confirmNumber.length === 0) {
                if (matchDiv) matchDiv.style.display = 'none';
                if (matchSuccess) matchSuccess.style.display = 'none';
                return;
            }
            
            if (accountNumber === confirmNumber) {
                if (matchDiv) matchDiv.style.display = 'none';
                if (matchSuccess) {
                    matchSuccess.style.display = 'block';
                    setTimeout(() => {
                        matchSuccess.style.display = 'none';
                    }, 3000);
                }
            } else {
                if (matchDiv) matchDiv.style.display = 'block';
                if (matchSuccess) matchSuccess.style.display = 'none';
            }
        }

        // Camera functionality
        let photoStream = null;
        let docStream = null;
        let currentFacingMode = 'environment'; // 'environment' for back camera, 'user' for front

        async function startCamera(type) {
            const video = document.getElementById(type + '-video');
            const preview = document.getElementById(type + '-camera-preview');
            const startBtn = document.getElementById('start-' + type + '-camera');
            const switchBtn = document.getElementById('switch-' + type + '-camera');
            const captureBtn = document.getElementById('capture-' + type);
            const stopBtn = document.getElementById('stop-' + type + '-camera');
            
            if (!video || !preview) {
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Camera elements not found'
                });
                return;
            }
            
            try {
                const constraints = {
                    video: { facingMode: currentFacingMode },
                    audio: false
                };
                
                const stream = await navigator.mediaDevices.getUserMedia(constraints);
                
                if (type === 'photo') {
                    if (photoStream) {
                        photoStream.getTracks().forEach(track => track.stop());
                    }
                    photoStream = stream;
                } else {
                    if (docStream) {
                        docStream.getTracks().forEach(track => track.stop());
                    }
                    docStream = stream;
                }
                
                video.srcObject = stream;
                preview.style.display = 'block';
                if (startBtn) startBtn.style.display = 'none';
                if (switchBtn) switchBtn.style.display = 'inline-flex';
                if (captureBtn) captureBtn.style.display = 'inline-flex';
                if (stopBtn) stopBtn.style.display = 'inline-flex';
                
            } catch (err) {
                console.error('Camera error:', err);
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access camera: ' + err.message
                });
            }
        }

        function switchCamera(type) {
            if (type === 'photo' && photoStream) {
                photoStream.getTracks().forEach(track => track.stop());
                photoStream = null;
            } else if (type === 'doc' && docStream) {
                docStream.getTracks().forEach(track => track.stop());
                docStream = null;
            }
            
            currentFacingMode = currentFacingMode === 'environment' ? 'user' : 'environment';
            startCamera(type);
        }

        function capturePhoto(type) {
            const video = document.getElementById(type + '-video');
            const canvas = document.getElementById(type + '-canvas');
            const capturedDiv = document.getElementById(type + '-captured');
            const hiddenInput = document.getElementById('camera_' + type + '_data');
            
            if (!video || !canvas || !hiddenInput) {
                Swal.fire({
                    icon: 'error',
                    title: 'Capture Error',
                    text: 'Camera elements not found'
                });
                return;
            }
            
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = canvas.toDataURL('image/png');
            hiddenInput.value = imageData;
            
            if (capturedDiv) {
                capturedDiv.innerHTML = '<i class="bi bi-check-circle-fill"></i> Photo captured successfully!';
                capturedDiv.classList.add('show');
            }
            
            // Stop camera after capture
            stopCamera(type);
            
            Swal.fire({
                icon: 'success',
                title: 'Captured!',
                text: 'Photo captured successfully',
                timer: 1500,
                showConfirmButton: false
            });
        }

        function stopCamera(type) {
            const video = document.getElementById(type + '-video');
            const preview = document.getElementById(type + '-camera-preview');
            const startBtn = document.getElementById('start-' + type + '-camera');
            const switchBtn = document.getElementById('switch-' + type + '-camera');
            const captureBtn = document.getElementById('capture-' + type);
            const stopBtn = document.getElementById('stop-' + type + '-camera');
            
            if (type === 'photo' && photoStream) {
                photoStream.getTracks().forEach(track => track.stop());
                photoStream = null;
            } else if (type === 'doc' && docStream) {
                docStream.getTracks().forEach(track => track.stop());
                docStream = null;
            }
            
            if (video) video.srcObject = null;
            if (preview) preview.style.display = 'none';
            if (startBtn) startBtn.style.display = 'inline-flex';
            if (switchBtn) switchBtn.style.display = 'none';
            if (captureBtn) captureBtn.style.display = 'none';
            if (stopBtn) stopBtn.style.display = 'none';
        }

        // OpenStreetMap Nominatim API (Free, no API key required)
        let searchResults = [];

        function searchAddress() {
            const query = document.getElementById('address-search').value.trim();
            if (query.length < 3) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Search',
                    text: 'Please enter at least 3 characters'
                });
                return;
            }
            
            // Show loading
            document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center;">Searching...</div>';
            document.getElementById('search-results').style.display = 'block';
            
            // Call Nominatim API
            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=5&addressdetails=1`)
                .then(response => response.json())
                .then(data => {
                    searchResults = data;
                    
                    if (data.length === 0) {
                        document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center;">No results found</div>';
                        return;
                    }
                    
                    let html = '';
                    data.forEach((item, index) => {
                        html += `<div onclick="selectAddress(${index})">
                                <i class="bi bi-geo-alt" style="margin-right: 5px;"></i> 
                                ${item.display_name}
                                </div>`;
                    });
                    document.getElementById('search-results').innerHTML = html;
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center; color: red;">Error searching address</div>';
                });
        }

        function selectAddress(index) {
            const data = searchResults[index];
            if (!data) return;
            
            // Update hidden fields
            document.getElementById('latitude').value = data.lat;
            document.getElementById('longitude').value = data.lon;
            document.getElementById('formatted_address').value = data.display_name;
            document.getElementById('place_id').value = data.place_id || '';
            
            // Update address fields
            updateAddressFieldsFromNominatim(data);
            
            // Show selected address
            const addressDisplay = document.getElementById('selected-address-display');
            const addressText = document.getElementById('selected-address-text');
            if (addressDisplay && addressText) {
                addressText.textContent = data.display_name;
                addressDisplay.style.display = 'block';
            }
            
            // Hide results
            document.getElementById('search-results').style.display = 'none';
            
            // Update map (using OpenStreetMap static map)
            updateMap(data.lat, data.lon);
        }

        function updateMap(lat, lon) {
            const mapContainer = document.getElementById('map');
            
            // Using OpenStreetMap static map
            mapContainer.innerHTML = `
                <img src="https://staticmap.openstreetmap.de/staticmap.php?center=${lat},${lon}&zoom=15&size=600x250&markers=${lat},${lon}" 
                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;"
                     alt="Map showing selected location">
            `;
        }

        function updateAddressFieldsFromNominatim(data) {
            // Parse address components
            const address = data.address || {};
            
            // Address Line 1 (House/Road)
            const addressLine1 = document.getElementById('address_line1');
            if (addressLine1) {
                let line1 = '';
                if (address.house_number) line1 += address.house_number + ' ';
                if (address.road) line1 += address.road;
                else if (address.pedestrian) line1 += address.pedestrian;
                addressLine1.value = line1.trim();
            }
            
            // Address Line 2 (Area/Suburb)
            const addressLine2 = document.getElementById('address_line2');
            if (addressLine2) {
                let line2 = '';
                if (address.suburb) line2 += address.suburb;
                else if (address.neighbourhood) line2 += address.neighbourhood;
                else if (address.village) line2 += address.village;
                else if (address.town) line2 += address.town;
                addressLine2.value = line2;
            }
            
            // City
            const cityField = document.getElementById('city');
            if (cityField) {
                cityField.value = address.city || address.town || address.village || address.municipality || '';
            }
            
            // State
            const stateField = document.getElementById('state');
            if (stateField) {
                stateField.value = address.state || address.county || '';
            }
            
            // Pincode
            const pincodeField = document.getElementById('pincode');
            if (pincodeField) {
                pincodeField.value = address.postcode || '';
            }
            
            console.log('Address fields filled:', {
                address_line1: addressLine1?.value,
                address_line2: addressLine2?.value,
                city: cityField?.value,
                state: stateField?.value,
                pincode: pincodeField?.value
            });
        }

        // Close search results when clicking outside
        document.addEventListener('click', function(event) {
            const searchResults = document.getElementById('search-results');
            const searchInput = document.getElementById('address-search');
            const searchButton = document.querySelector('button[onclick="searchAddress()"]');
            
            if (searchResults && searchInput && searchButton) {
                if (!searchInput.contains(event.target) && !searchResults.contains(event.target) && !searchButton.contains(event.target)) {
                    searchResults.style.display = 'none';
                }
            }
        });

        // Allow search on Enter key
        document.getElementById('address-search')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchAddress();
            }
        });

        // Add debounce for better performance
        let searchTimeout;
        document.getElementById('address-search')?.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (this.value.length >= 3) {
                    searchAddress();
                }
            }, 500);
        });

        // Confirm add with SweetAlert
        function confirmAdd() {
            Swal.fire({
                title: 'Add Employee?',
                text: 'Are you sure you want to add this employee?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Add',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('addEmployeeForm').submit();
                }
            });
            return false;
        }

        // Password validation
        document.getElementById('addEmployeeForm')?.addEventListener('submit', function(e) {
            const passwordInput = this.querySelector('input[name="password"]');
            if (passwordInput && passwordInput.value.length > 0 && passwordInput.value.length < 6) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Password',
                    text: 'Password must be at least 6 characters long.'
                });
            }
        });

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
                    
                    // Clear filename display
                    if (this.id === 'employee_photo') {
                        const photoFile = document.getElementById('photo-file-name');
                        if (photoFile) {
                            photoFile.innerHTML = '';
                            photoFile.classList.remove('show');
                        }
                    } else if (this.id === 'employee_certificate') {
                        const certFile = document.getElementById('cert-file-name');
                        if (certFile) {
                            certFile.innerHTML = '';
                            certFile.classList.remove('show');
                        }
                    }
                }
            });
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>