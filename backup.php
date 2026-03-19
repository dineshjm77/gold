<?php
session_start();
$currentPage = 'backup';
$pageTitle = 'Database Backup';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin access only
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Define backup directory
$backup_dir = 'backups/';

// Create backup directory if it doesn't exist
if (!file_exists($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

// Handle backup creation
if (isset($_POST['action']) && $_POST['action'] == 'create_backup') {
    $backup_type = isset($_POST['backup_type']) ? $_POST['backup_type'] : 'full';
    $custom_tables = isset($_POST['custom_tables']) ? $_POST['custom_tables'] : [];
    $compress = isset($_POST['compress']) ? true : false;
    
    $result = createDatabaseBackup($conn, $backup_dir, $backup_type, $custom_tables, $compress);
    
    if ($result['success']) {
        $message = "Backup created successfully! File: " . $result['filename'];
        
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) VALUES (?, 'create', ?, 'backup')";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $log_desc = "Created database backup: " . $result['filename'];
        mysqli_stmt_bind_param($log_stmt, 'is', $_SESSION['user_id'], $log_desc);
        mysqli_stmt_execute($log_stmt);
    } else {
        $error = "Backup creation failed: " . $result['message'];
    }
}

// Handle backup download
if (isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = $backup_dir . $filename;
    
    if (file_exists($filepath)) {
        // Log activity
        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) VALUES (?, 'download', ?, 'backup')";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $log_desc = "Downloaded database backup: " . $filename;
        mysqli_stmt_bind_param($log_stmt, 'is', $_SESSION['user_id'], $log_desc);
        mysqli_stmt_execute($log_stmt);
        
        // Send file to browser
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit();
    } else {
        $error = "Backup file not found.";
    }
}

// Handle backup restoration
if (isset($_POST['action']) && $_POST['action'] == 'restore_backup') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == 0) {
        $uploaded_file = $_FILES['backup_file']['tmp_name'];
        $original_name = $_FILES['backup_file']['name'];
        
        // Check file extension
        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        if (!in_array($ext, ['sql', 'gz', 'zip'])) {
            $error = "Invalid file type. Please upload .sql, .gz, or .zip files.";
        } else {
            $result = restoreDatabaseBackup($conn, $uploaded_file, $ext);
            
            if ($result['success']) {
                $message = "Database restored successfully from: " . $original_name;
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) VALUES (?, 'restore', ?, 'backup')";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $log_desc = "Restored database from backup: " . $original_name;
                mysqli_stmt_bind_param($log_stmt, 'is', $_SESSION['user_id'], $log_desc);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Restoration failed: " . $result['message'];
            }
        }
    } else {
        $error = "Please select a backup file to restore.";
    }
}

// Handle backup deletion
if (isset($_GET['delete'])) {
    $filename = basename($_GET['delete']);
    $filepath = $backup_dir . $filename;
    
    if (file_exists($filepath)) {
        if (unlink($filepath)) {
            $message = "Backup file deleted successfully: " . $filename;
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) VALUES (?, 'delete', ?, 'backup')";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_desc = "Deleted database backup: " . $filename;
            mysqli_stmt_bind_param($log_stmt, 'is', $_SESSION['user_id'], $log_desc);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to delete backup file.";
        }
    } else {
        $error = "Backup file not found.";
    }
}

// Handle multiple backup deletion
if (isset($_POST['action']) && $_POST['action'] == 'delete_selected') {
    if (isset($_POST['backup_files']) && is_array($_POST['backup_files'])) {
        $deleted_count = 0;
        $failed_count = 0;
        
        foreach ($_POST['backup_files'] as $filename) {
            $filename = basename($filename);
            $filepath = $backup_dir . $filename;
            
            if (file_exists($filepath)) {
                if (unlink($filepath)) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                }
            }
        }
        
        if ($deleted_count > 0) {
            $message = "$deleted_count backup file(s) deleted successfully.";
            if ($failed_count > 0) {
                $message .= " $failed_count file(s) failed to delete.";
            }
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) VALUES (?, 'delete', ?, 'backup')";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_desc = "Deleted $deleted_count backup files";
            mysqli_stmt_bind_param($log_stmt, 'is', $_SESSION['user_id'], $log_desc);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "No files were deleted.";
        }
    } else {
        $error = "Please select files to delete.";
    }
}

// Get list of backup files
$backup_files = [];
if ($handle = opendir($backup_dir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry != "." && $entry != ".." && !is_dir($backup_dir . $entry)) {
            $filepath = $backup_dir . $entry;
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            
            // Only show backup files
            if (in_array($ext, ['sql', 'gz', 'zip'])) {
                $backup_files[] = [
                    'name' => $entry,
                    'size' => filesize($filepath),
                    'size_formatted' => formatBytes(filesize($filepath)),
                    'date' => filemtime($filepath),
                    'date_formatted' => date('Y-m-d H:i:s', filemtime($filepath)),
                    'extension' => $ext
                ];
            }
        }
    }
    closedir($handle);
}

// Sort backup files by date (newest first)
usort($backup_files, function($a, $b) {
    return $b['date'] - $a['date'];
});

// Get database information
$db_info = getDatabaseInfo($conn);

// Get table list for custom backup
$tables_query = "SHOW TABLES";
$tables_result = mysqli_query($conn, $tables_query);
$all_tables = [];
while ($row = mysqli_fetch_array($tables_result)) {
    $all_tables[] = $row[0];
}

// Get backup statistics
$total_backups = count($backup_files);
$total_size = array_sum(array_column($backup_files, 'size'));
$newest_backup = !empty($backup_files) ? $backup_files[0]['date_formatted'] : 'No backups';
$oldest_backup = !empty($backup_files) ? end($backup_files)['date_formatted'] : 'No backups';

// Function to create database backup
function createDatabaseBackup($conn, $backup_dir, $type = 'full', $custom_tables = [], $compress = false) {
    try {
        // Get all tables
        $tables = [];
        $result = mysqli_query($conn, "SHOW TABLES");
        while ($row = mysqli_fetch_array($result)) {
            $tables[] = $row[0];
        }
        
        // Filter tables for custom backup
        if ($type == 'custom' && !empty($custom_tables)) {
            $tables = array_intersect($tables, $custom_tables);
        }
        
        // Start building SQL
        $sql = "-- Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: " . $GLOBALS['DB_NAME'] . "\n";
        $sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "START TRANSACTION;\n\n";
        
        // Backup each table
        foreach ($tables as $table) {
            // Drop table if exists
            $sql .= "-- Structure for table `$table`\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // Get create table syntax
            $create_result = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
            $create_row = mysqli_fetch_array($create_result);
            $sql .= $create_row[1] . ";\n\n";
            
            // Get table data
            $data_result = mysqli_query($conn, "SELECT * FROM `$table`");
            $num_rows = mysqli_num_rows($data_result);
            
            if ($num_rows > 0) {
                $sql .= "-- Data for table `$table`\n";
                
                // Get column names
                $fields = [];
                while ($field = mysqli_fetch_field($data_result)) {
                    $fields[] = $field->name;
                }
                $field_names = '`' . implode('`, `', $fields) . '`';
                
                // Process rows in batches to avoid memory issues
                $batch_size = 100;
                $row_count = 0;
                
                while ($row = mysqli_fetch_assoc($data_result)) {
                    if ($row_count % $batch_size == 0) {
                        if ($row_count > 0) {
                            $sql .= ";\n";
                        }
                        $sql .= "INSERT INTO `$table` ($field_names) VALUES ";
                    } else {
                        $sql .= ",";
                    }
                    
                    // Escape values
                    $values = [];
                    foreach ($fields as $field) {
                        if (isset($row[$field]) && $row[$field] !== null) {
                            $values[] = "'" . mysqli_real_escape_string($conn, $row[$field]) . "'";
                        } else {
                            $values[] = "NULL";
                        }
                    }
                    $sql .= "\n(" . implode(', ', $values) . ")";
                    
                    $row_count++;
                }
                
                if ($row_count > 0) {
                    $sql .= ";\n\n";
                }
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $sql .= "COMMIT;\n";
        
        // Generate filename
        $db_name = $GLOBALS['DB_NAME'] ?? 'database';
        $date = date('Y-m-d_H-i-s');
        $filename = $db_name . '_' . $date . '_' . $type . '.sql';
        
        if ($compress) {
            $filename .= '.gz';
            $filepath = $backup_dir . $filename;
            
            // Compress SQL
            $gz = gzopen($filepath, 'w9');
            gzwrite($gz, $sql);
            gzclose($gz);
        } else {
            $filepath = $backup_dir . $filename;
            file_put_contents($filepath, $sql);
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Function to restore database backup
function restoreDatabaseBackup($conn, $filepath, $ext) {
    try {
        // Read file content
        if ($ext == 'gz') {
            $gz = gzopen($filepath, 'r');
            $sql = '';
            while (!gzeof($gz)) {
                $sql .= gzgets($gz, 4096);
            }
            gzclose($gz);
        } else {
            $sql = file_get_contents($filepath);
        }
        
        // Remove comments
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $sql = preg_replace('/^\/\*!.*\*\/;/m', '', $sql);
        
        // Split SQL into individual queries
        $queries = explode(';', $sql);
        
        // Disable foreign key checks
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
        
        // Execute each query
        $success_count = 0;
        $error_count = 0;
        
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (mysqli_query($conn, $query)) {
                    $success_count++;
                } else {
                    $error_count++;
                    // Log error but continue
                    error_log("Restore query failed: " . mysqli_error($conn));
                }
            }
        }
        
        // Re-enable foreign key checks
        mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
        
        return [
            'success' => true,
            'message' => "Restored $success_count queries, $error_count errors"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Function to get database information
function getDatabaseInfo($conn) {
    $info = [];
    
    // Database name
    $result = mysqli_query($conn, "SELECT DATABASE() as dbname");
    $row = mysqli_fetch_assoc($result);
    $info['name'] = $row['dbname'];
    
    // Database size
    $result = mysqli_query($conn, "SELECT SUM(data_length + index_length) as size 
                                   FROM information_schema.tables 
                                   WHERE table_schema = DATABASE()");
    $row = mysqli_fetch_assoc($result);
    $info['size'] = $row['size'] ?? 0;
    $info['size_formatted'] = formatBytes($info['size']);
    
    // Table count
    $result = mysqli_query($conn, "SELECT COUNT(*) as count 
                                   FROM information_schema.tables 
                                   WHERE table_schema = DATABASE()");
    $row = mysqli_fetch_assoc($result);
    $info['table_count'] = $row['count'] ?? 0;
    
    // MySQL version
    $result = mysqli_query($conn, "SELECT VERSION() as version");
    $row = mysqli_fetch_assoc($result);
    $info['mysql_version'] = $row['version'];
    
    // Character set
    $result = mysqli_query($conn, "SELECT @@character_set_database as charset");
    $row = mysqli_fetch_assoc($result);
    $info['charset'] = $row['charset'];
    
    // Collation
    $result = mysqli_query($conn, "SELECT @@collation_database as collation");
    $row = mysqli_fetch_assoc($result);
    $info['collation'] = $row['collation'];
    
    return $info;
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Get table sizes
$table_sizes = [];
$tables_result = mysqli_query($conn, "SELECT 
    table_name, 
    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb,
    table_rows as row_count
    FROM information_schema.tables 
    WHERE table_schema = DATABASE()
    ORDER BY size_mb DESC");

while ($row = mysqli_fetch_assoc($tables_result)) {
    $table_sizes[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .app-wrapper {
            display: flex;
            min-height: 100vh;
            background: rgba(255, 255, 255, 0.95);
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: #f8fafc;
        }

        .page-content {
            flex: 1 0 auto;
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        }

        .backup-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-count {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 10px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideDown 0.4s ease;
            border-left: 4px solid;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left-color: #17a2b8;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left-color: #ffc107;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
        }

        .stat-card:before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 50%;
        }

        .stat-icon {
            font-size: 24px;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
        }

        .stat-sub {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* Action Cards Grid */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .action-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .action-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .action-subtitle {
            font-size: 13px;
            color: #718096;
            margin-top: 2px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px;
            border-bottom: 1px solid #f0f0f0;
        }

        .checkbox-item:last-child {
            border-bottom: none;
        }

        .checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .checkbox-label {
            font-size: 13px;
            color: #2d3748;
            cursor: pointer;
            flex: 1;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .backup-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .backup-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .backup-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .backup-table tbody tr:hover {
            background: #f7fafc;
        }

        .file-icon {
            font-size: 20px;
            color: #667eea;
        }

        .file-name {
            font-weight: 600;
            color: #2d3748;
            font-family: monospace;
        }

        .file-size {
            font-size: 12px;
            color: #718096;
        }

        .file-date {
            font-size: 12px;
            color: #4a5568;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #feebc8;
            color: #744210;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            background: white;
            color: #4a5568;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-decoration: none;
            font-size: 14px;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .btn-icon.download:hover {
            background: #48bb78;
            color: white;
        }

        .btn-icon.restore:hover {
            background: #ecc94b;
            color: #744210;
        }

        .btn-icon.delete:hover {
            background: #f56565;
            color: white;
        }

        .btn-icon.info:hover {
            background: #4299e1;
            color: white;
        }

        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Database Info Card */
        .info-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border: 1px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        /* Progress Bar */
        .progress {
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #cbd5e0;
        }

        .empty-state p {
            font-size: 14px;
            color: #4a5568;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .action-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .table-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .btn-group {
                flex-direction: column;
                width: 100%;
            }
            
            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .backup-container {
                padding: 0 10px;
            }
            
            .action-card, .table-card, .info-card {
                padding: 15px;
            }
            
            .backup-table {
                font-size: 12px;
            }
            
            .btn-icon {
                width: 28px;
                height: 28px;
                font-size: 12px;
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
                <div class="backup-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-database"></i>
                            Database Backup
                            <span class="badge-count"><?php echo $total_backups; ?> Backups</span>
                        </h1>
                    </div>

                    <!-- Display Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Database Info -->
                    <div class="info-card">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <i class="bi bi-info-circle" style="color: #667eea; font-size: 20px;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #2d3748;">Database Information</h3>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Database Name</span>
                                <span class="info-value"><?php echo $db_info['name']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Database Size</span>
                                <span class="info-value"><?php echo $db_info['size_formatted']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Total Tables</span>
                                <span class="info-value"><?php echo $db_info['table_count']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">MySQL Version</span>
                                <span class="info-value"><?php echo $db_info['mysql_version']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Character Set</span>
                                <span class="info-value"><?php echo $db_info['charset']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Collation</span>
                                <span class="info-value"><?php echo $db_info['collation']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-files"></i></div>
                            <div class="stat-value"><?php echo $total_backups; ?></div>
                            <div class="stat-label">Total Backups</div>
                            <div class="stat-sub"><?php echo formatBytes($total_size); ?> total size</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-calendar-plus"></i></div>
                            <div class="stat-value"><?php echo date('d M Y', strtotime($newest_backup)); ?></div>
                            <div class="stat-label">Newest Backup</div>
                            <div class="stat-sub"><?php echo $newest_backup; ?></div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-calendar-minus"></i></div>
                            <div class="stat-value"><?php echo date('d M Y', strtotime($oldest_backup)); ?></div>
                            <div class="stat-label">Oldest Backup</div>
                            <div class="stat-sub"><?php echo $oldest_backup; ?></div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-table"></i></div>
                            <div class="stat-value"><?php echo $db_info['table_count']; ?></div>
                            <div class="stat-label">Database Tables</div>
                            <div class="stat-sub"><?php echo $db_info['size_formatted']; ?></div>
                        </div>
                    </div>

                    <!-- Action Cards -->
                    <div class="action-grid">
                        <!-- Create Backup Card -->
                        <div class="action-card">
                            <div class="action-header">
                                <div class="action-icon">
                                    <i class="bi bi-plus-circle"></i>
                                </div>
                                <div>
                                    <div class="action-title">Create Backup</div>
                                    <div class="action-subtitle">Create a new database backup</div>
                                </div>
                            </div>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_backup">
                                
                                <div class="form-group">
                                    <label class="form-label">Backup Type</label>
                                    <select name="backup_type" id="backup_type" class="form-select" onchange="toggleCustomTables()">
                                        <option value="full">Full Database Backup</option>
                                        <option value="structure">Structure Only</option>
                                        <option value="data">Data Only</option>
                                        <option value="custom">Custom Tables</option>
                                    </select>
                                </div>
                                
                                <div id="custom_tables_section" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Select Tables</label>
                                        <div class="checkbox-group">
                                            <?php foreach ($all_tables as $table): ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" name="custom_tables[]" value="<?php echo $table; ?>" id="table_<?php echo $table; ?>">
                                                <label for="table_<?php echo $table; ?>" class="checkbox-label"><?php echo $table; ?></label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="checkbox-item" style="border-bottom: none;">
                                        <input type="checkbox" name="compress" id="compress" value="1">
                                        <label for="compress" class="checkbox-label">Compress backup (GZip)</label>
                                    </div>
                                </div>
                                
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-database-add"></i> Create Backup
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Restore Backup Card -->
                        <div class="action-card">
                            <div class="action-header">
                                <div class="action-icon">
                                    <i class="bi bi-arrow-repeat"></i>
                                </div>
                                <div>
                                    <div class="action-title">Restore Backup</div>
                                    <div class="action-subtitle">Restore database from backup file</div>
                                </div>
                            </div>
                            
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="restore_backup">
                                
                                <div class="form-group">
                                    <label class="form-label">Select Backup File</label>
                                    <input type="file" name="backup_file" class="form-control" accept=".sql,.gz,.zip" required>
                                    <small style="color: #718096; font-size: 11px;">Supported formats: .sql, .gz, .zip</small>
                                </div>
                                
                                <div class="alert alert-warning" style="padding: 10px; margin-bottom: 15px;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>Warning:</strong> Restoring will overwrite existing data!
                                </div>
                                
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to restore this backup? All current data will be overwritten!')">
                                        <i class="bi bi-arrow-repeat"></i> Restore Database
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Table Sizes -->
                    <div class="table-card">
                        <div class="table-header">
                            <div class="table-title">
                                <i class="bi bi-table"></i>
                                Table Sizes
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="backup-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Table Name</th>
                                        <th>Rows</th>
                                        <th>Size (MB)</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_mb = array_sum(array_column($table_sizes, 'size_mb'));
                                    $rank = 1;
                                    foreach ($table_sizes as $table): 
                                        $percentage = $total_mb > 0 ? round(($table['size_mb'] / $total_mb) * 100, 2) : 0;
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $rank++; ?></strong></td>
                                        <td><span class="file-name"><?php echo $table['table_name']; ?></span></td>
                                        <td><?php echo number_format($table['row_count']); ?></td>
                                        <td><?php echo $table['size_mb']; ?> MB</td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <span><?php echo $percentage; ?>%</span>
                                                <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px;">
                                                    <div style="width: <?php echo $percentage; ?>%; height: 6px; background: #667eea; border-radius: 3px;"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Backup Files List -->
                    <div class="table-card">
                        <div class="table-header">
                            <div class="table-title">
                                <i class="bi bi-files"></i>
                                Backup Files
                                <span class="badge-count"><?php echo count($backup_files); ?> Files</span>
                            </div>
                            <div class="table-actions">
                                <form method="POST" action="" id="deleteForm" onsubmit="return confirm('Are you sure you want to delete selected files?');">
                                    <input type="hidden" name="action" value="delete_selected">
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="selectAll()">
                                            <i class="bi bi-check-all"></i> Select All
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="deselectAll()">
                                            <i class="bi bi-x"></i> Deselect All
                                        </button>
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="bi bi-trash"></i> Delete Selected
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <?php if (!empty($backup_files)): ?>
                        <div class="table-responsive">
                            <table class="backup-table">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes()">
                                        </th>
                                        <th>File Name</th>
                                        <th>Size</th>
                                        <th>Date Created</th>
                                        <th>Type</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backup_files as $file): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="backup_files[]" value="<?php echo $file['name']; ?>" class="file-checkbox" form="deleteForm">
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <i class="bi bi-file-earmark-zip file-icon"></i>
                                                <span class="file-name"><?php echo $file['name']; ?></span>
                                            </div>
                                        </td>
                                        <td><span class="file-size"><?php echo $file['size_formatted']; ?></span></td>
                                        <td><span class="file-date"><?php echo $file['date_formatted']; ?></span></td>
                                        <td>
                                            <span class="badge badge-<?php echo $file['extension'] == 'sql' ? 'success' : ($file['extension'] == 'gz' ? 'info' : 'warning'); ?>">
                                                <?php echo strtoupper($file['extension']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?download=<?php echo urlencode($file['name']); ?>" class="btn-icon download" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <a href="backup-info.php?file=<?php echo urlencode($file['name']); ?>" class="btn-icon info" title="Info">
                                                    <i class="bi bi-info-circle"></i>
                                                </a>
                                                <a href="?delete=<?php echo urlencode($file['name']); ?>" class="btn-icon delete" title="Delete" onclick="return confirm('Are you sure you want to delete this backup?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-files"></i>
                            <p>No backup files found</p>
                            <small style="color: #a0aec0;">Create your first backup using the form above</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Storage Usage -->
                    <div class="info-card">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <i class="bi bi-hdd-stack" style="color: #667eea; font-size: 20px;"></i>
                            <h3 style="font-size: 16px; font-weight: 600; color: #2d3748;">Storage Usage</h3>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Total Backup Size</span>
                                <span class="info-value"><?php echo formatBytes($total_size); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Database Size</span>
                                <span class="info-value"><?php echo $db_info['size_formatted']; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Free Space</span>
                                <span class="info-value"><?php 
                                    $free_space = disk_free_space($backup_dir);
                                    echo $free_space ? formatBytes($free_space) : 'N/A';
                                ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Total Space</span>
                                <span class="info-value"><?php 
                                    $total_space = disk_total_space($backup_dir);
                                    echo $total_space ? formatBytes($total_space) : 'N/A';
                                ?></span>
                            </div>
                        </div>
                        <?php if ($total_space > 0): ?>
                        <div class="progress" style="margin-top: 15px;">
                            <?php 
                            $usage_percent = ($total_size / $total_space) * 100;
                            $usage_percent = min($usage_percent, 100);
                            ?>
                            <div class="progress-bar" style="width: <?php echo $usage_percent; ?>%;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                            <span style="font-size: 12px; color: #718096;">Used: <?php echo formatBytes($total_size); ?></span>
                            <span style="font-size: 12px; color: #718096;">Free: <?php echo formatBytes($free_space); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Backup Tips -->
                    <div class="alert alert-info">
                        <i class="bi bi-lightbulb"></i>
                        <strong>Tips:</strong>
                        <ul style="margin-top: 5px; margin-left: 20px;">
                            <li>Create regular backups to prevent data loss</li>
                            <li>Store backups in a safe location separate from your server</li>
                            <li>Test your backups periodically by restoring them to a test environment</li>
                            <li>Compressed backups save storage space</li>
                            <li>The backup directory is: <code><?php echo realpath($backup_dir); ?></code></li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script>
        // Toggle custom tables section based on backup type
        function toggleCustomTables() {
            var backupType = document.getElementById('backup_type').value;
            var customSection = document.getElementById('custom_tables_section');
            
            if (backupType === 'custom') {
                customSection.style.display = 'block';
            } else {
                customSection.style.display = 'none';
            }
        }

        // Select all checkboxes
        function selectAll() {
            var checkboxes = document.getElementsByClassName('file-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = true;
            }
            document.getElementById('selectAllCheckbox').checked = true;
        }

        // Deselect all checkboxes
        function deselectAll() {
            var checkboxes = document.getElementsByClassName('file-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = false;
            }
            document.getElementById('selectAllCheckbox').checked = false;
        }

        // Toggle all checkboxes based on select all checkbox
        function toggleAllCheckboxes() {
            var selectAll = document.getElementById('selectAllCheckbox').checked;
            var checkboxes = document.getElementsByClassName('file-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAll;
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                if (!alert.classList.contains('alert-info')) {
                    alert.style.display = 'none';
                }
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>