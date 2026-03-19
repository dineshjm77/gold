<?php
require_once 'includes/db.php';

echo "<h2>🔧 Admin Password Reset Tool</h2>";

// Generate new hash for 'admin123'
$new_password = 'admin123';
$new_hash = password_hash($new_password, PASSWORD_DEFAULT);

echo "New hash generated: <code>" . $new_hash . "</code><br><br>";

// Check if admin exists
$check = mysqli_query($conn, "SELECT id, username, password FROM users WHERE username = 'admin'");

if (mysqli_num_rows($check) > 0) {
    $user = mysqli_fetch_assoc($check);
    echo "✅ Admin user found (ID: {$user['id']})<br>";
    echo "Current password hash: " . substr($user['password'], 0, 30) . "...<br>";
    
    // Update password
    $update = mysqli_query($conn, "UPDATE users SET password = '$new_hash', status = 'active', is_active = 1 WHERE username = 'admin'");
    
    if ($update) {
        echo "<span style='color:green; font-weight:bold'>✅ Password updated successfully to 'admin123'!</span><br>";
    } else {
        echo "<span style='color:red'>❌ Update failed: " . mysqli_error($conn) . "</span><br>";
    }
} else {
    echo "❌ Admin user not found. Creating new admin user...<br>";
    
    // Insert new admin
    $insert = mysqli_query($conn, "INSERT INTO users (username, password, name, role, status, is_active) 
                                   VALUES ('admin', '$new_hash', 'Administrator', 'admin', 'active', 1)");
    
    if ($insert) {
        echo "<span style='color:green; font-weight:bold'>✅ New admin user created with password 'admin123'!</span><br>";
    } else {
        echo "<span style='color:red'>❌ Creation failed: " . mysqli_error($conn) . "</span><br>";
    }
}

// Verify the fix
echo "<br><h3>🔍 Verification:</h3>";
$verify = mysqli_query($conn, "SELECT id, username, password, status, is_active FROM users WHERE username = 'admin'");
$user = mysqli_fetch_assoc($verify);

echo "<pre>";
print_r($user);
echo "</pre>";

// Test password verification
if (password_verify('admin123', $user['password'])) {
    echo "<span style='color:green; font-size:18px; font-weight:bold'>✅ PASSWORD WORKS! You can now login with:</span><br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong><br>";
} else {
    echo "<span style='color:red; font-size:18px; font-weight:bold'>❌ Password still doesn't match!</span><br>";
}

echo "<br><br><a href='login.php' style='background:#1e3c72; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Login Page</a>";
?>