<?php
$plain_password = 'admin@123';
$hashed = password_hash($plain_password, PASSWORD_DEFAULT);
echo "<h2>Hashed password for 'admin@123':</h2>";
echo "<pre>" . htmlspecialchars($hashed) . "</pre>";
echo "<p>Copy the line above — do NOT use the plain text.</p>";
?>