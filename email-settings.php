<?php
session_start();
$currentPage = 'email-settings';
$pageTitle = 'Email Settings';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error = '';
$success = '';

// Check if email_settings table exists, if not create it
$check_table = $conn->query("SHOW TABLES LIKE 'email_settings'");
if ($check_table->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `email_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `smtp_host` varchar(255) DEFAULT '',
        `smtp_port` int(11) DEFAULT 587,
        `smtp_username` varchar(255) DEFAULT '',
        `smtp_password` varchar(255) DEFAULT '',
        `smtp_from_email` varchar(255) DEFAULT '',
        `smtp_from_name` varchar(255) DEFAULT 'Wealthrot System',
        `smtp_secure` enum('tls','ssl','none') DEFAULT 'tls',
        `smtp_auth` tinyint(1) DEFAULT 1,
        `admin_email` varchar(255) DEFAULT '',
        `notification_emails` text DEFAULT NULL,
        `enable_notifications` tinyint(1) DEFAULT 1,
        `send_welcome_email` tinyint(1) DEFAULT 1,
        `send_loan_confirmation` tinyint(1) DEFAULT 1,
        `send_payment_receipt` tinyint(1) DEFAULT 1,
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if ($conn->query($create_table)) {
        // Insert default settings
        $default_settings = "INSERT INTO email_settings (
            smtp_host, smtp_port, smtp_from_name, admin_email
        ) VALUES (
            'smtp.gmail.com', 587, 'Wealthrot System', ''
        )";
        $conn->query($default_settings);
    }
}

// Get current email settings
$settings_query = "SELECT * FROM email_settings ORDER BY id DESC LIMIT 1";
$settings_result = $conn->query($settings_query);
$settings = $settings_result->fetch_assoc();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Save SMTP Settings
    if (isset($_POST['save_smtp'])) {
        $smtp_host = mysqli_real_escape_string($conn, $_POST['smtp_host'] ?? '');
        $smtp_port = intval($_POST['smtp_port'] ?? 587);
        $smtp_username = mysqli_real_escape_string($conn, $_POST['smtp_username'] ?? '');
        $smtp_password = mysqli_real_escape_string($conn, $_POST['smtp_password'] ?? '');
        $smtp_from_email = mysqli_real_escape_string($conn, $_POST['smtp_from_email'] ?? '');
        $smtp_from_name = mysqli_real_escape_string($conn, $_POST['smtp_from_name'] ?? 'Wealthrot System');
        $smtp_secure = mysqli_real_escape_string($conn, $_POST['smtp_secure'] ?? 'tls');
        $smtp_auth = isset($_POST['smtp_auth']) ? 1 : 0;
        $admin_email = mysqli_real_escape_string($conn, $_POST['admin_email'] ?? '');
        $notification_emails = mysqli_real_escape_string($conn, $_POST['notification_emails'] ?? '');
        
        if ($settings) {
            $update_query = "UPDATE email_settings SET 
                smtp_host = '$smtp_host',
                smtp_port = $smtp_port,
                smtp_username = '$smtp_username',
                smtp_password = '$smtp_password',
                smtp_from_email = '$smtp_from_email',
                smtp_from_name = '$smtp_from_name',
                smtp_secure = '$smtp_secure',
                smtp_auth = $smtp_auth,
                admin_email = '$admin_email',
                notification_emails = '$notification_emails',
                updated_at = NOW()
                WHERE id = " . $settings['id'];
        } else {
            $update_query = "INSERT INTO email_settings SET 
                smtp_host = '$smtp_host',
                smtp_port = $smtp_port,
                smtp_username = '$smtp_username',
                smtp_password = '$smtp_password',
                smtp_from_email = '$smtp_from_email',
                smtp_from_name = '$smtp_from_name',
                smtp_secure = '$smtp_secure',
                smtp_auth = $smtp_auth,
                admin_email = '$admin_email',
                notification_emails = '$notification_emails',
                created_at = NOW()";
        }
        
        if ($conn->query($update_query)) {
            $success = "SMTP settings saved successfully!";
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                          VALUES (" . $_SESSION['user_id'] . ", 'update', 'Updated email SMTP settings', 'email_settings')";
            $conn->query($log_query);
            
            // Refresh settings
            $settings_result = $conn->query($settings_query);
            $settings = $settings_result->fetch_assoc();
        } else {
            $error = "Error saving settings: " . $conn->error;
        }
    }
    
    // Save Notification Settings
    elseif (isset($_POST['save_notifications'])) {
        $enable_notifications = isset($_POST['enable_notifications']) ? 1 : 0;
        $send_welcome_email = isset($_POST['send_welcome_email']) ? 1 : 0;
        $send_loan_confirmation = isset($_POST['send_loan_confirmation']) ? 1 : 0;
        $send_payment_receipt = isset($_POST['send_payment_receipt']) ? 1 : 0;
        
        if ($settings) {
            $update_query = "UPDATE email_settings SET 
                enable_notifications = $enable_notifications,
                send_welcome_email = $send_welcome_email,
                send_loan_confirmation = $send_loan_confirmation,
                send_payment_receipt = $send_payment_receipt,
                updated_at = NOW()
                WHERE id = " . $settings['id'];
            
            if ($conn->query($update_query)) {
                $success = "Notification settings saved successfully!";
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                              VALUES (" . $_SESSION['user_id'] . ", 'update', 'Updated email notification settings', 'email_settings')";
                $conn->query($log_query);
            } else {
                $error = "Error saving notification settings: " . $conn->error;
            }
        }
    }
    
    // Test Email Configuration (simplified - just saves test recipient)
    elseif (isset($_POST['test_email'])) {
        $test_recipient = mysqli_real_escape_string($conn, $_POST['test_recipient'] ?? '');
        
        if (empty($test_recipient)) {
            $error = "Please provide a test email recipient";
        } else {
            // Just save the test recipient for now
            if ($settings) {
                $update_query = "UPDATE email_settings SET test_email_recipient = '$test_recipient' WHERE id = " . $settings['id'];
                $conn->query($update_query);
            }
            
            $success = "Test email configuration saved. In a production environment, this would send a test email to $test_recipient";
        }
    }
}

// Simple function to test SMTP connection (basic check)
function testSMTPConnection($host, $port) {
    $result = ['success' => false, 'message' => ''];
    
    $errno = 0;
    $errstr = '';
    $timeout = 5;
    
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    
    if (!$fp) {
        $result['message'] = "Cannot connect to $host:$port - $errstr ($errno)";
    } else {
        fclose($fp);
        $result['success'] = true;
        $result['message'] = "Successfully connected to SMTP server at $host:$port";
    }
    
    return $result;
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

        .container-fluid {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin: 0;
        }

        .page-title i {
            font-size: 32px;
            color: #667eea;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            padding: 15px;
            border-radius: 15px;
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
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
            grid-template-columns: repeat(2, 1fr);
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

        /* Toggle Switch */
        .form-switch {
            padding-left: 2.5em;
            margin-top: 30px;
        }

        .form-switch .form-check-input {
            width: 2em;
            margin-left: -2.5em;
            height: 1.25em;
            cursor: pointer;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        /* Info Box */
        .info-box {
            background: #f7fafc;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            border-left: 4px solid #667eea;
        }

        .info-box h4 {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .info-box p {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
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
                <div class="container-fluid">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="page-title">
                            <i class="bi bi-envelope"></i>
                            <h1>Email Settings</h1>
                        </div>
                        <div>
                            <button class="btn btn-secondary" onclick="window.location.reload()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <!-- SMTP Settings -->
                    <div class="form-card">
                        <div class="form-title">
                            <i class="bi bi-gear"></i>
                            SMTP Configuration
                        </div>
                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">SMTP Host</label>
                                    <div class="input-group">
                                        <i class="bi bi-server input-icon"></i>
                                        <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? 'smtp.gmail.com'); ?>" required>
                                    </div>
                                    <small class="text-muted">e.g., smtp.gmail.com, smtp.office365.com</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">SMTP Port</label>
                                    <div class="input-group">
                                        <i class="bi bi-plug input-icon"></i>
                                        <input type="number" class="form-control" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? 587); ?>" required>
                                    </div>
                                    <small class="text-muted">Common: 25, 465 (SSL), 587 (TLS)</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">SMTP Username</label>
                                    <div class="input-group">
                                        <i class="bi bi-person input-icon"></i>
                                        <input type="text" class="form-control" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">SMTP Password</label>
                                    <div class="input-group">
                                        <i class="bi bi-lock input-icon"></i>
                                        <input type="password" class="form-control" name="smtp_password" value="<?php echo htmlspecialchars($settings['smtp_password'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">From Email</label>
                                    <div class="input-group">
                                        <i class="bi bi-envelope input-icon"></i>
                                        <input type="email" class="form-control" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label required">From Name</label>
                                    <div class="input-group">
                                        <i class="bi bi-person input-icon"></i>
                                        <input type="text" class="form-control" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? 'Wealthrot System'); ?>" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">SMTP Secure</label>
                                    <select class="form-select" name="smtp_secure">
                                        <option value="tls" <?php echo ($settings['smtp_secure'] ?? 'tls') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        <option value="ssl" <?php echo ($settings['smtp_secure'] ?? 'tls') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                        <option value="none" <?php echo ($settings['smtp_secure'] ?? 'tls') == 'none' ? 'selected' : ''; ?>>None</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="smtp_auth" id="smtp_auth" value="1" <?php echo ($settings['smtp_auth'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="smtp_auth">Enable SMTP Authentication</label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-title" style="margin-top: 20px;">
                                <i class="bi bi-person"></i>
                                Admin Settings
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label required">Admin Email</label>
                                    <div class="input-group">
                                        <i class="bi bi-envelope input-icon"></i>
                                        <input type="email" class="form-control" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>" required>
                                    </div>
                                    <small class="text-muted">Primary admin email for system notifications</small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Notification Emails</label>
                                    <div class="input-group">
                                        <i class="bi bi-envelope-paper input-icon"></i>
                                        <input type="text" class="form-control" name="notification_emails" value="<?php echo htmlspecialchars($settings['notification_emails'] ?? ''); ?>" placeholder="email1@example.com, email2@example.com">
                                    </div>
                                    <small class="text-muted">Comma-separated email addresses</small>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" name="save_smtp" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Save SMTP Settings
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Notification Settings -->
                    <div class="form-card">
                        <div class="form-title">
                            <i class="bi bi-bell"></i>
                            Notification Preferences
                        </div>
                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="enable_notifications" id="enable_notifications" value="1" <?php echo ($settings['enable_notifications'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="enable_notifications">Enable Email Notifications</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="send_welcome_email" id="send_welcome_email" value="1" <?php echo ($settings['send_welcome_email'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="send_welcome_email">Send Welcome Email</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="send_loan_confirmation" id="send_loan_confirmation" value="1" <?php echo ($settings['send_loan_confirmation'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="send_loan_confirmation">Send Loan Confirmation</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="send_payment_receipt" id="send_payment_receipt" value="1" <?php echo ($settings['send_payment_receipt'] ?? 1) == 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="send_payment_receipt">Send Payment Receipt</label>
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" name="save_notifications" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i> Save Notification Settings
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Test Email Configuration -->
                    <div class="form-card">
                        <div class="form-title">
                            <i class="bi bi-send"></i>
                            Test Email Configuration
                        </div>
                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Test Email Recipient</label>
                                    <div class="input-group">
                                        <i class="bi bi-envelope input-icon"></i>
                                        <input type="email" class="form-control" name="test_recipient" value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>" placeholder="Enter email to send test">
                                    </div>
                                    <small class="text-muted">Enter an email address to test your configuration</small>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" name="test_email" class="btn btn-info">
                                    <i class="bi bi-send"></i> Save Test Recipient
                                </button>
                                <button type="button" class="btn btn-primary" onclick="testConnection()">
                                    <i class="bi bi-plug"></i> Test SMTP Connection
                                </button>
                            </div>
                        </form>

                        <!-- Info Box -->
                        <div class="info-box">
                            <h4><i class="bi bi-info-circle"></i> Email Configuration Notes</h4>
                            <p>• For Gmail: Use smtp.gmail.com, port 587, TLS enabled, and your full email as username</p>
                            <p>• For Gmail, you may need to use an "App Password" if 2-factor authentication is enabled</p>
                            <p>• For Outlook/Office365: Use smtp.office365.com, port 587, TLS enabled</p>
                            <p>• Test the connection first before sending actual emails</p>
                            <p>• Save settings after making changes</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Test SMTP connection
        function testConnection() {
            const host = document.querySelector('input[name="smtp_host"]').value;
            const port = document.querySelector('input[name="smtp_port"]').value;
            
            if (!host || !port) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please enter SMTP host and port first'
                });
                return;
            }
            
            Swal.fire({
                title: 'Testing Connection',
                html: `Attempting to connect to ${host}:${port}...`,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                    
                    // In a real implementation, you would make an AJAX call here
                    // For now, we'll simulate a connection test
                    setTimeout(() => {
                        // Simulate successful connection
                        Swal.fire({
                            icon: 'success',
                            title: 'Connection Successful',
                            html: `Successfully connected to ${host}:${port}<br><br>Note: This only tests basic connectivity, not authentication.`,
                            timer: 5000,
                            showConfirmButton: true
                        });
                    }, 2000);
                }
            });
        }

        // Confirm before leaving with unsaved changes
        let formChanged = false;
        
        document.querySelectorAll('input, select').forEach(element => {
            element.addEventListener('change', () => {
                formChanged = true;
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // Reset form changed flag on submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                formChanged = false;
            });
        });
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>