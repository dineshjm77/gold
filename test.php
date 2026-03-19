<?php
// mail_test.php
$to = 'ariharasudhan1062003@gmail.com';
$subject = 'PHP mail() test ' . date('Y-m-d H:i:s');
$message = "<html><body><h2>Test</h2><p>This is a test email from PHP mail()</p></body></html>";
$from = 'noreply@yourcompany.com';
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-type: text/html; charset=UTF-8\r\n";
$headers .= "From: Your Company <{$from}>\r\n";
$headers .= "Reply-To: support@ecommerstore.in\r\n";

$sent = mail($to, $subject, $message, $headers, '-f'.$from);
if ($sent) {
    echo "mail() returned true — accepted by PHP. Check inbox and mail logs.\n";
} else {
    echo "mail() returned false — not accepted by PHP. Check php.ini and MTA.\n";
}
?>