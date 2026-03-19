<?php
// profile.php
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

// Fetch user details from database (using your table structure)
$query = "SELECT * FROM users WHERE id = '$user_id'";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $mobile = mysqli_real_escape_string($conn, $_POST['mobile']);
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        
        // Validation
        $errors = [];
        
        if (empty($name)) $errors[] = "Name is required";
        if (empty($email)) $errors[] = "Email is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
        
        // Check if username already exists (excluding current user)
        $check_username = mysqli_query($conn, "SELECT id FROM users WHERE username = '$username' AND id != '$user_id'");
        if (mysqli_num_rows($check_username) > 0) {
            $errors[] = "Username already taken";
        }
        
        // Check if email already exists (excluding current user)
        $check_email = mysqli_query($conn, "SELECT id FROM users WHERE email = '$email' AND id != '$user_id'");
        if (mysqli_num_rows($check_email) > 0) {
            $errors[] = "Email already exists";
        }
        
        // Handle profile photo upload
        $photo_path = $user['employee_photo'];
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $size = $_FILES['profile_photo']['size'];
            
            if (in_array($ext, $allowed)) {
                if ($size <= 5 * 1024 * 1024) { // 5MB max
                    $new_filename = 'emp_' . time() . '_' . $user_id . '.' . $ext;
                    $upload_path = 'uploads/employees/' . $new_filename;
                    
                    // Create directory if not exists
                    if (!is_dir('uploads/employees')) {
                        mkdir('uploads/employees', 0777, true);
                    }
                    
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
                        // Delete old photo if exists
                        if (!empty($user['employee_photo']) && file_exists($user['employee_photo'])) {
                            unlink($user['employee_photo']);
                        }
                        $photo_path = $upload_path;
                    } else {
                        $errors[] = "Failed to upload photo";
                    }
                } else {
                    $errors[] = "File size must be less than 5MB";
                }
            } else {
                $errors[] = "Only JPG, JPEG, PNG & GIF files are allowed";
            }
        }
        
        if (empty($errors)) {
            $update_query = "UPDATE users SET 
                            name = '$name',
                            email = '$email',
                            mobile = '$mobile',
                            username = '$username',
                            employee_photo = " . ($photo_path ? "'$photo_path'" : "NULL") . "
                            WHERE id = '$user_id'";
            
            if (mysqli_query($conn, $update_query)) {
                $success_message = "Profile updated successfully!";
                // Refresh user data
                $result = mysqli_query($conn, $query);
                $user = mysqli_fetch_assoc($result);
                
                // Update session
                $_SESSION['username'] = $username;
                $_SESSION['name'] = $name;
            } else {
                $error_message = "Error updating profile: " . mysqli_error($conn);
            }
        } else {
            $error_message = implode("<br>", $errors);
        }
    }
    
    // Handle password change
    if (isset($_POST['change_password'])) {
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
}
?>
<!doctype html>
<html lang="en">

<?php include('includes/head.php'); ?>

<head>
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 15px 15px 0 0;
            color: white;
        }
        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
        }
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .profile-image-upload {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: white;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        .profile-image-upload:hover {
            transform: scale(1.1);
            background: #f0f0f0;
        }
        .profile-image-upload i {
            color: #667eea;
            font-size: 20px;
        }
        #profilePhotoInput {
            display: none;
        }
        .info-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .info-card h5 {
            color: #495057;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 5px;
        }
        .info-value {
            color: #212529;
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 15px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .role-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .role-admin {
            background: rgba(102, 126, 234, 0.15);
            color: #667eea;
        }
        .role-sale {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-active {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }
        .status-inactive {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        .nav-pills .nav-link {
            color: #495057;
            font-weight: 500;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 5px;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .nav-pills .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        .tab-content {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .btn-outline-gradient {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-outline-gradient:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .branch-badge {
            background: #e9ecef;
            color: #495057;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
        }
        @media (max-width: 768px) {
            .profile-header {
                padding: 20px;
            }
            .profile-image {
                width: 120px;
                height: 120px;
            }
        }
    </style>
</head>

<body data-sidebar="dark">

<!-- Loader -->


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
                            <h4 class="mb-sm-0">My Profile</h4>
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="javascript: void(0);">User</a></li>
                                    <li class="breadcrumb-item active">Profile</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End page title -->

                <!-- Alerts -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Profile Header -->
                <div class="profile-header mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3 class="text-white mb-2">Welcome back, <?php echo htmlspecialchars($user['name']); ?>!</h3>
                            <p class="text-white-50 mb-0">
                                <i class="fas fa-calendar-alt me-2"></i> Member since <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                            </p>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <span class="role-badge <?php echo $user['role'] === 'admin' ? 'role-admin' : 'role-sale'; ?>">
                                <i class="fas <?php echo $user['role'] === 'admin' ? 'fa-crown' : 'fa-user'; ?> me-2"></i>
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                            <span class="status-badge <?php echo ($user['status'] ?? 'active') === 'active' ? 'status-active' : 'status-inactive'; ?> ms-2">
                                <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                <?php echo ucfirst($user['status'] ?? 'active'); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Sidebar Navigation -->
                    <div class="col-lg-3">
                        <div class="card">
                            <div class="card-body p-3">
                                <div class="profile-image-container">
                                    <img src="<?php echo !empty($user['employee_photo']) ? htmlspecialchars($user['employee_photo']) : 'assets/images/users/avatar-default.png'; ?>" 
                                         alt="Profile" class="profile-image" id="profilePreview">
                                    <div class="profile-image-upload" onclick="document.getElementById('profilePhotoInput').click()">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                </div>
                                
                                <h5 class="text-center mb-1"><?php echo htmlspecialchars($user['name']); ?></h5>
                                <p class="text-center text-muted mb-2">@<?php echo htmlspecialchars($user['username']); ?></p>
                                
                                <?php if (!empty($user['branch_id'])): ?>
                                    <div class="text-center mb-3">
                                        <span class="branch-badge">
                                            <i class="fas fa-store me-1"></i> Branch #<?php echo $user['branch_id']; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <hr>
                                
                                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                                    <button class="nav-link active" id="v-pills-profile-tab" data-bs-toggle="pill" data-bs-target="#v-pills-profile" type="button" role="tab">
                                        <i class="fas fa-user"></i> Profile Information
                                    </button>
                                    <button class="nav-link" id="v-pills-edit-tab" data-bs-toggle="pill" data-bs-target="#v-pills-edit" type="button" role="tab">
                                        <i class="fas fa-edit"></i> Edit Profile
                                    </button>
                                    <button class="nav-link" id="v-pills-password-tab" data-bs-toggle="pill" data-bs-target="#v-pills-password" type="button" role="tab">
                                        <i class="fas fa-lock"></i> Change Password
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Content -->
                    <div class="col-lg-9">
                        <div class="tab-content" id="v-pills-tabContent">
                            <!-- Profile Information Tab -->
                            <div class="tab-pane fade show active" id="v-pills-profile" role="tabpanel">
                                <h4 class="mb-4">Profile Information</h4>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="info-card">
                                            <h5>Personal Details</h5>
                                            
                                            <div class="info-label">Full Name</div>
                                            <div class="info-value"><?php echo htmlspecialchars($user['name']); ?></div>
                                            
                                            <div class="info-label">Username</div>
                                            <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                                            
                                            <div class="info-label">Email Address</div>
                                            <div class="info-value"><?php echo htmlspecialchars($user['email'] ?? 'Not provided'); ?></div>
                                            
                                            <div class="info-label">Mobile Number</div>
                                            <div class="info-value"><?php echo htmlspecialchars($user['mobile'] ?? 'Not provided'); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="info-card">
                                            <h5>Account Details</h5>
                                            
                                            <div class="info-label">Role</div>
                                            <div class="info-value">
                                                <span class="role-badge <?php echo $user['role'] === 'admin' ? 'role-admin' : 'role-sale'; ?>">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="info-label">Status</div>
                                            <div class="info-value">
                                                <span class="status-badge <?php echo ($user['status'] ?? 'active') === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo ucfirst($user['status'] ?? 'active'); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="info-label">Branch ID</div>
                                            <div class="info-value"><?php echo $user['branch_id'] ?? 'Not assigned'; ?></div>
                                            
                                            <div class="info-label">Account Created</div>
                                            <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user['created_at'])); ?></div>
                                            
                                            <div class="info-label">Last Updated</div>
                                            <div class="info-value"><?php echo date('F j, Y \a\t g:i A', strtotime($user['updated_at'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Profile Tab -->
                            <div class="tab-pane fade" id="v-pills-edit" role="tabpanel">
                                <h4 class="mb-4">Edit Profile</h4>
                                
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Full Name *</label>
                                            <input type="text" name="name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Username *</label>
                                            <input type="text" name="username" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Email Address *</label>
                                            <input type="email" name="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Mobile Number</label>
                                            <input type="text" name="mobile" class="form-control" 
                                                   value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                                        </div>
                                        
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Profile Photo</label>
                                            <input type="file" name="profile_photo" id="profilePhotoInput" class="form-control" accept="image/*">
                                            <small class="text-muted">Allowed: JPG, JPEG, PNG, GIF (Max: 5MB)</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" name="update_profile" class="btn btn-gradient">
                                            <i class="fas fa-save me-2"></i> Save Changes
                                        </button>
                                        <button type="reset" class="btn btn-outline-gradient ms-2">
                                            <i class="fas fa-undo me-2"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Change Password Tab -->
                            <div class="tab-pane fade" id="v-pills-password" role="tabpanel">
                                <h4 class="mb-4">Change Password</h4>
                                
                                <form method="POST" onsubmit="return validatePassword()">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Current Password *</label>
                                            <div class="input-group">
                                                <input type="password" name="current_password" id="current_password" class="form-control" required>
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">New Password *</label>
                                            <div class="input-group">
                                                <input type="password" name="new_password" id="new_password" class="form-control" required>
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted">Minimum 6 characters</small>
                                        </div>
                                        
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Confirm New Password *</label>
                                            <div class="input-group">
                                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button type="submit" name="change_password" class="btn btn-gradient">
                                            <i class="fas fa-key me-2"></i> Change Password
                                        </button>
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
// Preview profile image before upload
document.getElementById('profilePhotoInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profilePreview').src = e.target.result;
        }
        reader.readAsDataURL(file);
    }
});

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

// Validate password before submission
function validatePassword() {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    
    if (newPass !== confirmPass) {
        Swal.fire({
            icon: 'error',
            title: 'Password Mismatch',
            text: 'New password and confirm password do not match!'
        });
        return false;
    }
    
    if (newPass.length < 6) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid Password',
            text: 'Password must be at least 6 characters long!'
        });
        return false;
    }
    
    return true;
}

// Show success message with SweetAlert
<?php if ($success_message): ?>
Swal.fire({
    icon: 'success',
    title: 'Success!',
    text: '<?php echo $success_message; ?>',
    timer: 3000,
    showConfirmButton: false
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