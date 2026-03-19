<?php
// test_mail_debug.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>📧 Email Debugging Tool</h2>";

// Test 1: Check if mail function exists
echo "<h3>Test 1: Mail Function Availability</h3>";
if (function_exists('mail')) {
    echo "✅ mail() function exists<br>";
} else {
    echo "❌ mail() function does not exist<br>";
}

// Test 2: Check PHP mail configuration
echo "<h3>Test 2: PHP Mail Configuration</h3>";
$mail_config = [
    'SMTP' => ini_get('SMTP'),
    'smtp_port' => ini_get('smtp_port'),
    'sendmail_from' => ini_get('sendmail_from'),
    'sendmail_path' => ini_get('sendmail_path')
];

echo "<pre>";
print_r($mail_config);
echo "</pre>";

// Test 3: Try to send a simple test email
echo "<h3>Test 3: Sending Test Email</h3>";

$to = "dineshkarthi@gmail.com";
$subject = "Test Email from Pawn Shop - " . date('Y-m-d H:i:s');
$message = "This is a test email from your Pawn Shop system.\n\n";
$message .= "If you receive this, PHP mail is working!\n";
$message .= "Time: " . date('Y-m-d H:i:s');

$headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
$headers .= "Reply-To: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

if (mail($to, $subject, $message, $headers)) {
    echo "✅ Test email sent successfully to $to<br>";
    echo "Please check your inbox (and spam folder)<br>";
} else {
    echo "❌ Failed to send test email<br>";
    echo "Error: " . error_get_last()['message'] ?? 'Unknown error';
}

// Test 4: Check if we can write to error log
echo "<h3>Test 4: Error Logging</h3>";
if (error_log("Test error log entry from Pawn Shop", 0)) {
    echo "✅ Can write to error log<br>";
} else {
    echo "❌ Cannot write to error log<br>";
}

// Test 5: Server information
echo "<h3>Test 5: Server Information</h3>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Host: " . $_SERVER['HTTP_HOST'] . "<br>";
?>