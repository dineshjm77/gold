<?php
// config/email_config.php

// Email Configuration for Gmail
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'dineshkarthi11503@gmail.com'); // Your Gmail
define('SMTP_PASSWORD', 'tsbc vfes ordr vuhg'); // Replace with your Gmail App Password
define('SMTP_FROM_EMAIL', 'dineshkarthi11503@gmail.com');
define('SMTP_FROM_NAME', 'Pawn Shop Management');
define('SMTP_SECURE', 'tls');

// Admin email for notifications
define('ADMIN_EMAIL', 'dineshkarthi11503@gmail.com');

// Enable/disable email sending
define('ENABLE_EMAIL_NOTIFICATIONS', true);
?>