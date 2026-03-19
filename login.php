<?php
session_start();
require_once 'includes/db.php';

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    
    // Debug - Remove after testing
    error_log("Login attempt - Username: $username");
    
    // Query to check user - Using correct column names
    $query = "SELECT id, username, password, name, role, status, is_active 
              FROM users 
              WHERE username = ? AND (status = 'active' OR status IS NULL) AND is_active = 1 
              LIMIT 1";
              
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($user = mysqli_fetch_assoc($result)) {
        // Debug - Remove after testing
        error_log("User found - ID: " . $user['id']);
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['last_activity'] = time();
            
            // Log login activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                          VALUES (?, 'login', 'User logged in', 'users')";
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, 'i', $user['id']);
            mysqli_stmt_execute($log_stmt);
            
            // Debug - Remove after testing
            error_log("Login successful - Redirecting to index.php");
            
            // Redirect to index page
            header('Location: index.php');
            exit();
        } else {
            $error = "Invalid password";
            error_log("Password verification failed for user: $username");
        }
    } else {
        $error = "User not found or inactive";
        error_log("User not found: $username");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WEALTHROT - Gold Loan Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-container {
            margin-bottom: 15px;
        }
        
        .logo {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            padding: 5px;
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .logo img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 50%;
            background: white;
            padding: 5px;
        }
        
        .login-header h2 {
            color: #333;
            font-weight: 800;
            margin-bottom: 5px;
            font-size: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }
        
        .input-group-custom {
            position: relative;
            margin-bottom: 20px;
        }
        
        .input-group-custom i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 18px;
        }
        
        .input-group-custom input {
            width: 100%;
            height: 50px;
            padding: 0 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .input-group-custom input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            cursor: pointer;
            font-size: 18px;
            z-index: 10;
        }
        
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }
        
        .remember-me label {
            color: #555;
            font-size: 14px;
            cursor: pointer;
        }
        
        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        
        .forgot-password:hover {
            text-decoration: underline;
        }
        
        .btn-login {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .btn-login i {
            font-size: 18px;
        }
        
        .error-message {
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 20px;
            color: #dc2626;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-message i {
            font-size: 18px;
        }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            border: 1px dashed #667eea;
        }
        
        .demo-credentials p {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .demo-credentials small {
            color: #666;
            font-size: 12px;
            display: block;
            line-height: 1.6;
        }
        
        .demo-credentials .badge {
            background: #667eea;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
            margin-top: 5px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 25px;
            color: #888;
            font-size: 13px;
        }
        
        .login-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-container">
                    <div class="logo">
                        <img src="logo.png" alt="WEALTHROT Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'bi bi-gem\' style=\'font-size: 50px; color: white;\'></i>';">
                    </div>
                </div>
                <h2>WEALTHROT</h2>
                <p>Gold Loan Management System</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="input-group-custom">
                    <i class="bi bi-person"></i>
                    <input type="text" name="username" placeholder="Username" value="admin" required autofocus>
                </div>
                
                <div class="input-group-custom">
                    <i class="bi bi-lock"></i>
                    <input type="password" name="password" id="password" placeholder="Password" value="admin123" required>
                    <i class="bi bi-eye toggle-password" id="togglePassword" onclick="togglePasswordVisibility()"></i>
                </div>
                
                <div class="remember-forgot">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    <a href="#" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
                
               
            </form>
            
            <div class="login-footer">
                &copy; <?php echo date('Y'); ?> WEALTHROT. All rights reserved.
                <br>
                <small>Version 2.0.0</small>
            </div>
        </div>
    </div>
    
    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePassword');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('bi-eye-slash');
                toggleIcon.classList.add('bi-eye');
            }
        }
        
        document.getElementById('loginForm').addEventListener('submit', function() {
            const button = document.querySelector('.btn-login');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="bi bi-arrow-repeat"></i> Logging in...';
            button.style.opacity = '0.8';
            button.disabled = true;
            
            // Re-enable after 3 seconds in case of error
            setTimeout(function() {
                button.innerHTML = originalText;
                button.style.opacity = '1';
                button.disabled = false;
            }, 3000);
        });
        
        // Auto-focus username field
        window.onload = function() {
            document.querySelector('input[name="username"]').focus();
        };
    </script>
</body>
</html>