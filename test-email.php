<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/email_helper.php';

// Simple HTML page for testing
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Test</title>
    <style>
        body { font-family: Arial; padding: 30px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .success { color: #48bb78; padding: 10px; background: #f0fff4; border-left: 4px solid #48bb78; }
        .error { color: #f56565; padding: 10px; background: #fff5f5; border-left: 4px solid #f56565; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 20px 0; }
        code { background: #f7fafc; padding: 2px 5px; border-radius: 3px; }
        input[type="email"], input[type="text"] { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #667eea; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #5a67d8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email Configuration Test</h1>
        
        <div class="info">
            <h3>Current Settings:</h3>
            <p><strong>SMTP Host:</strong> <?php echo SMTP_HOST; ?></p>
            <p><strong>SMTP Port:</strong> <?php echo SMTP_PORT; ?></p>
            <p><strong>SMTP Username:</strong> <?php echo SMTP_USERNAME; ?></p>
            <p><strong>From Email:</strong> <?php echo FROM_EMAIL; ?></p>
            <p><strong>PHPMailer Loaded:</strong> <?php echo isset($phpmailer_loaded) && $phpmailer_loaded ? '✅ Yes' : '❌ No'; ?></p>
        </div>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $test_email = $_POST['test_email'] ?? SMTP_USERNAME;
            $test_message = $_POST['test_message'] ?? 'This is a test email from WEALTHROT';
            
            // Create a simple test email
            $subject = "Test Email from WEALTHROT - " . date('Y-m-d H:i:s');
            $body = "
            <h2>Email Test Successful!</h2>
            <p>Your email configuration is working correctly.</p>
            <p><strong>Message:</strong> " . htmlspecialchars($test_message) . "</p>
            <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>Server:</strong> {$_SERVER['SERVER_NAME']}</p>
            ";
            
            // Use the same function but with dummy loan data
            $dummy_loan = [
                'id' => 0,
                'receipt_number' => 'TEST-' . date('Ymd'),
                'customer_name' => 'Test Customer',
                'email' => $test_email
            ];
            
            $result = sendEmailPHPMailer($test_email, $subject, $body, $dummy_loan, $conn);
            
            if ($result['success']) {
                echo "<div class='success'>✅ " . $result['message'] . "</div>";
            } else {
                echo "<div class='error'>❌ " . $result['message'] . "</div>";
                
                // Show Gmail App Password instructions
                echo "<div class='info'>";
                echo "<h4>📋 Gmail App Password Instructions:</h4>";
                echo "<ol>";
                $steps = getGmailAppPasswordInstructions();
                foreach ($steps as $step) {
                    echo "<li>" . htmlspecialchars($step) . "</li>";
                }
                echo "</ol>";
                echo "</div>";
            }
        }
        ?>
        
        <form method="POST">
            <h3>Send Test Email</h3>
            
            <label>Test Email Address:</label>
            <input type="email" name="test_email" value="<?php echo SMTP_USERNAME; ?>" required>
            
            <label>Test Message (optional):</label>
            <input type="text" name="test_message" value="Test email from WEALTHROT">
            
            <button type="submit">Send Test Email</button>
        </form>
        
        <hr>
        
        <h3>📋 How to Get Gmail App Password:</h3>
        <ol>
            <li>Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a></li>
            <li>Enable <strong>2-Step Verification</strong> (if not already enabled)</li>
            <li>Go to <strong>App Passwords</strong> (under Security)</li>
            <li>Select <strong>Mail</strong> and <strong>Other</strong> (name it "WEALTHROT")</li>
            <li>Copy the 16-character password</li>
            <li>Update <code>includes/email_config.php</code> with this password</li>
        </ol>
    </div>
</body>
</html>