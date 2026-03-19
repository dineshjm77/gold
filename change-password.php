<?php
session_start();
include('includes/db.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Fetch user details
$query = "SELECT * FROM users WHERE id = '$user_id'";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $errors = [];
    
    // Verify current password
    if (!password_verify($current_password, $user['password'])) {
        $errors[] = "Current password is incorrect";
    }
    
    if (strlen($new_password) < 6) {
        $errors[] = "New password must be at least 6 characters";
    }
    
    if ($new_password !== $confirm_password) {
        $errors[] = "New passwords do not match";
    }
    
    if (empty($errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_query = "UPDATE users SET password = '$hashed_password' WHERE id = '$user_id'";
        
        if (mysqli_query($conn, $update_query)) {
            $success_message = "Password changed successfully!";
        } else {
            $error_message = "Error changing password: " . mysqli_error($conn);
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .password-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 15px 15px 0 0;
            color: white;
            margin-bottom: 30px;
        }
        
        .password-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .password-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
            display: block;
        }
        
        .input-group {
            position: relative;
        }
        
        .form-control {
            height: 50px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 10px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
            outline: none;
        }
        
        .input-group-text {
            background: white;
            border: 2px solid #e9ecef;
            border-left: none;
            border-radius: 0 10px 10px 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .input-group-text:hover {
            background: #f8f9fa;
            color: #667eea;
        }
        
        .password-strength {
            margin-top: 10px;
        }
        
        .strength-bar {
            height: 5px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .strength-progress {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }
        
        .strength-text {
            font-size: 13px;
            color: #6c757d;
        }
        
        .requirements-list {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .requirements-list h6 {
            color: #495057;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .requirements-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .requirements-list li {
            padding: 5px 0;
            color: #6c757d;
            font-size: 14px;
        }
        
        .requirements-list li i {
            margin-right: 10px;
            font-size: 12px;
        }
        
        .requirements-list li.valid {
            color: #28a745;
        }
        
        .requirements-list li.valid i {
            color: #28a745;
        }
        
        .btn-change {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .btn-change:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
        }
        
        .btn-change:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .info-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-card p {
            margin: 0;
            color: #495057;
            font-size: 14px;
        }
        
        .info-card i {
            color: #667eea;
            margin-right: 8px;
        }
        
        @media (max-width: 768px) {
            .password-body {
                padding: 20px;
            }
            
            .password-header {
                padding: 20px;
            }
            
            .password-header h3 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body data-sidebar="dark">

<!-- Begin page -->
<div id="layout-wrapper">

<?php include('includes/topbar.php'); ?>    

    <!-- ========== Left Sidebar Start ========== -->
    <div class="vertical-menu">
        <div data-simplebar class="h-100">
            <?php include('includes/sidebar.php'); ?>
        </div>
    </div>
    <!-- Left Sidebar End -->

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">

                <!-- Start page title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0">Change Password</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                                    <li class="breadcrumb-item"><a href="profile.php">Profile</a></li>
                                    <li class="breadcrumb-item active">Change Password</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End page title -->

                <!-- Alerts -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> 
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> 
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="password-card">
                            <div class="password-header">
                                <h3 class="text-white mb-2"><i class="fas fa-lock me-2"></i> Change Password</h3>
                                <p class="text-white-50 mb-0">Secure your account with a strong password</p>
                            </div>
                            
                            <div class="password-body">
                                <!-- User Info -->
                                <div class="info-card">
                                    <p>
                                        <i class="fas fa-user"></i> 
                                        <strong><?php echo htmlspecialchars($user['name']); ?></strong> 
                                        (<?php echo htmlspecialchars($user['username']); ?>)
                                    </p>
                                </div>

                                <form method="POST" id="passwordForm">
                                    <!-- Current Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-lock me-2"></i>Current Password *
                                        </label>
                                        <div class="input-group">
                                            <input type="password" name="current_password" id="current_password" 
                                                   class="form-control" placeholder="Enter current password" required>
                                            <span class="input-group-text" onclick="togglePassword('current_password')">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- New Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-key me-2"></i>New Password *
                                        </label>
                                        <div class="input-group">
                                            <input type="password" name="new_password" id="new_password" 
                                                   class="form-control" placeholder="Enter new password" 
                                                   onkeyup="checkPasswordStrength()" required>
                                            <span class="input-group-text" onclick="togglePassword('new_password')">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                        
                                        <!-- Password Strength Meter -->
                                        <div class="password-strength">
                                            <div class="strength-bar">
                                                <div class="strength-progress" id="strengthProgress"></div>
                                            </div>
                                            <span class="strength-text" id="strengthText">Enter password</span>
                                        </div>
                                    </div>

                                    <!-- Confirm Password -->
                                    <div class="form-group">
                                        <label class="form-label">
                                            <i class="fas fa-check-circle me-2"></i>Confirm New Password *
                                        </label>
                                        <div class="input-group">
                                            <input type="password" name="confirm_password" id="confirm_password" 
                                                   class="form-control" placeholder="Re-enter new password" 
                                                   onkeyup="checkPasswordMatch()" required>
                                            <span class="input-group-text" onclick="togglePassword('confirm_password')">
                                                <i class="fas fa-eye"></i>
                                            </span>
                                        </div>
                                        <small class="text-muted" id="passwordMatchMsg"></small>
                                    </div>

                                    <!-- Password Requirements -->
                                    <div class="requirements-list">
                                        <h6><i class="fas fa-shield-alt me-2"></i>Password Requirements:</h6>
                                        <ul>
                                            <li id="req-length">
                                                <i class="fas fa-times-circle text-danger"></i> 
                                                At least 6 characters
                                            </li>
                                            <li id="req-match">
                                                <i class="fas fa-times-circle text-danger"></i> 
                                                Passwords match
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Submit Button -->
                                    <button type="submit" name="change_password" class="btn-change" id="submitBtn">
                                        <i class="fas fa-sync-alt me-2"></i> Change Password
                                    </button>

                                    <!-- Back to Profile Link -->
                                    <div class="text-center mt-3">
                                        <a href="profile.php" class="text-muted">
                                            <i class="fas fa-arrow-left me-1"></i> Back to Profile
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- container-fluid -->
        </div><!-- End Page-content -->

        <?php include('includes/footer.php'); ?>
    </div><!-- end main-content-->

</div><!-- END layout-wrapper -->

<?php include('includes/scripts.php'); ?>

<script>
// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
    field.setAttribute('type', type);
    
    // Toggle eye icon
    const button = field.nextElementSibling;
    const icon = button.querySelector('i');
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

// Check password strength
function checkPasswordStrength() {
    const password = document.getElementById('new_password').value;
    const strengthProgress = document.getElementById('strengthProgress');
    const strengthText = document.getElementById('strengthText');
    const reqLength = document.getElementById('req-length');
    
    let strength = 0;
    let color = '';
    let text = '';
    
    // Check length
    if (password.length >= 6) {
        strength += 1;
        reqLength.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least 6 characters';
        reqLength.classList.add('valid');
    } else {
        reqLength.innerHTML = '<i class="fas fa-times-circle text-danger"></i> At least 6 characters';
        reqLength.classList.remove('valid');
    }
    
    // Calculate strength percentage
    const percentage = (password.length / 12) * 100;
    
    if (password.length === 0) {
        strengthProgress.style.width = '0%';
        strengthProgress.style.background = '#e9ecef';
        strengthText.textContent = 'Enter password';
    } else if (password.length < 6) {
        strengthProgress.style.width = '25%';
        strengthProgress.style.background = '#dc3545';
        strengthText.textContent = 'Too short';
    } else if (password.length < 8) {
        strengthProgress.style.width = '50%';
        strengthProgress.style.background = '#ffc107';
        strengthText.textContent = 'Fair';
    } else if (password.length < 10) {
        strengthProgress.style.width = '75%';
        strengthProgress.style.background = '#17a2b8';
        strengthText.textContent = 'Good';
    } else {
        strengthProgress.style.width = '100%';
        strengthProgress.style.background = '#28a745';
        strengthText.textContent = 'Strong';
    }
    
    checkPasswordMatch();
}

// Check if passwords match
function checkPasswordMatch() {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    const reqMatch = document.getElementById('req-match');
    const submitBtn = document.getElementById('submitBtn');
    
    if (confirmPass.length > 0) {
        if (newPass === confirmPass) {
            reqMatch.innerHTML = '<i class="fas fa-check-circle text-success"></i> Passwords match';
            reqMatch.classList.add('valid');
            submitBtn.disabled = false;
        } else {
            reqMatch.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Passwords do not match';
            reqMatch.classList.remove('valid');
            submitBtn.disabled = true;
        }
    } else {
        reqMatch.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Passwords match';
        reqMatch.classList.remove('valid');
        submitBtn.disabled = true;
    }
    
    // Check length requirement
    const reqLength = document.getElementById('req-length');
    if (newPass.length >= 6) {
        reqLength.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least 6 characters';
        reqLength.classList.add('valid');
    } else {
        reqLength.innerHTML = '<i class="fas fa-times-circle text-danger"></i> At least 6 characters';
        reqLength.classList.remove('valid');
    }
}

// Form validation before submit
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    
    if (newPass !== confirmPass) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Mismatch',
            text: 'New password and confirm password do not match!'
        });
        return false;
    }
    
    if (newPass.length < 6) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must be at least 6 characters long!'
        });
        return false;
    }
});

// Show success message with SweetAlert
<?php if ($success_message): ?>
Swal.fire({
    icon: 'success',
    title: 'Success!',
    text: '<?php echo $success_message; ?>',
    timer: 3000,
    showConfirmButton: false
}).then(() => {
    window.location.href = 'profile.php';
});
<?php endif; ?>

// Show error message with SweetAlert
<?php if ($error_message): ?>
Swal.fire({
    icon: 'error',
    title: 'Error!',
    html: '<?php echo str_replace("'", "\'", $error_message); ?>',
    confirmButtonText: 'OK'
});
<?php endif; ?>
</script>

</body>
</html>