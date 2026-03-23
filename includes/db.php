<?php
$db_host = 'srv1752.hstgr.io';
$db_name = 'u983225556_pawngold';
$db_user = 'u983225556_pawngold';
$db_pass = 'Pawngold@29';

if (!$db_host || !$db_name || !$db_user) {
    die("Database configuration missing. Please set MYSQL_HOST, MYSQL_DATABASE, MYSQL_USER, and MYSQL_PASSWORD environment variables.");
}

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
