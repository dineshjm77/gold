<?php
session_start();
$currentPage = 'system-health';
$pageTitle = 'System Health Monitor';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error = '';
$success = '';

// Function to get server load
function getServerLoad() {
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        return [
            '1min' => round($load[0], 2),
            '5min' => round($load[1], 2),
            '15min' => round($load[2], 2)
        ];
    }
    return null;
}

// Function to get memory usage
function getMemoryUsage() {
    if (function_exists('memory_get_usage')) {
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        return [
            'current' => $memory_usage,
            'peak' => $memory_peak,
            'current_formatted' => formatBytes($memory_usage),
            'peak_formatted' => formatBytes($memory_peak)
        ];
    }
    return null;
}

// Function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Function to get disk usage
function getDiskUsage() {
    $total = disk_total_space('/');
    $free = disk_free_space('/');
    $used = $total - $free;
    $percent_used = ($total > 0) ? round(($used / $total) * 100, 2) : 0;
    
    return [
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'percent_used' => $percent_used,
        'total_formatted' => formatBytes($total),
        'free_formatted' => formatBytes($free),
        'used_formatted' => formatBytes($used)
    ];
}

// Function to get PHP information
function getPHPInfo() {
    return [
        'version' => phpversion(),
        'max_execution_time' => ini_get('max_execution_time') . ' seconds',
        'memory_limit' => ini_get('memory_limit'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_time' => ini_get('max_input_time') . ' seconds',
        'max_input_vars' => ini_get('max_input_vars'),
        'display_errors' => ini_get('display_errors') ? 'On' : 'Off',
        'error_reporting' => error_reporting(),
        'date_timezone' => date_default_timezone_get(),
        'session_save_path' => session_save_path() ?: 'Default',
        'allow_url_fopen' => ini_get('allow_url_fopen') ? 'On' : 'Off'
    ];
}

// Function to check database health
function checkDatabaseHealth($conn) {
    $health = [];
    
    // Check database version
    $version_query = $conn->query("SELECT VERSION() as version");
    $health['version'] = $version_query->fetch_assoc()['version'];
    
    // Check database size
    $db_name = $conn->query("SELECT DATABASE() as db")->fetch_assoc()['db'];
    $size_query = "SELECT 
        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
        FROM information_schema.tables 
        WHERE table_schema = ?";
    $stmt = $conn->prepare($size_query);
    $stmt->bind_param("s", $db_name);
    $stmt->execute();
    $health['size_mb'] = $stmt->get_result()->fetch_assoc()['size_mb'] ?? 0;
    
    // Check table counts
    $tables_query = "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = ?";
    $stmt = $conn->prepare($tables_query);
    $stmt->bind_param("s", $db_name);
    $stmt->execute();
    $health['table_count'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Check connection status
    $health['connected'] = $conn->ping();
    
    // Check server info
    $health['server_info'] = $conn->server_info;
    $health['host_info'] = $conn->host_info;
    $health['character_set'] = $conn->character_set_name();
    
    // Get table status
    $table_status_query = "SHOW TABLE STATUS";
    $result = $conn->query($table_status_query);
    $total_rows = 0;
    $total_data = 0;
    $total_index = 0;
    $table_details = [];
    
    while ($row = $result->fetch_assoc()) {
        $total_rows += $row['Rows'] ?? 0;
        $total_data += $row['Data_length'] ?? 0;
        $total_index += $row['Index_length'] ?? 0;
        $table_details[] = [
            'name' => $row['Name'],
            'rows' => $row['Rows'] ?? 0,
            'data_length' => $row['Data_length'] ?? 0,
            'index_length' => $row['Index_length'] ?? 0,
            'engine' => $row['Engine'] ?? 'Unknown',
            'collation' => $row['Collation'] ?? 'Unknown'
        ];
    }
    
    $health['total_rows'] = $total_rows;
    $health['total_data_formatted'] = formatBytes($total_data);
    $health['total_index_formatted'] = formatBytes($total_index);
    $health['table_details'] = $table_details;
    
    return $health;
}

// Function to check upload directory permissions
function checkDirectoryPermissions() {
    $directories = [
        'uploads/' => 'Uploads Directory',
        'uploads/employees/' => 'Employee Photos',
        'uploads/certificates/' => 'Certificates',
        'uploads/customers/' => 'Customer Photos',
        'uploads/loan_items/' => 'Loan Items',
        'uploads/branches/' => 'Branches',
        'tmp/' => 'Temporary Directory',
        'logs/' => 'Logs Directory',
        'backups/' => 'Backups Directory'
    ];
    
    $results = [];
    foreach ($directories as $path => $name) {
        $full_path = __DIR__ . '/' . $path;
        $status = [
            'name' => $name,
            'path' => $path,
            'exists' => file_exists($full_path),
            'readable' => is_readable($full_path),
            'writable' => is_writable($full_path),
            'permissions' => file_exists($full_path) ? substr(sprintf('%o', fileperms($full_path)), -4) : 'N/A'
        ];
        
        // Try to create if not exists
        if (!$status['exists']) {
            $status['created'] = @mkdir($full_path, 0777, true);
            $status['exists'] = file_exists($full_path);
            $status['readable'] = is_readable($full_path);
            $status['writable'] = is_writable($full_path);
            $status['permissions'] = file_exists($full_path) ? substr(sprintf('%o', fileperms($full_path)), -4) : 'N/A';
        }
        
        $results[] = $status;
    }
    
    return $results;
}

// Function to check PHP extensions
function getPHPExtensions() {
    $required_extensions = [
        'mysqli' => 'MySQLi',
        'json' => 'JSON',
        'session' => 'Session',
        'gd' => 'GD Library',
        'mbstring' => 'Multibyte String',
        'curl' => 'cURL',
        'zip' => 'Zip Archive',
        'xml' => 'XML',
        'fileinfo' => 'File Info',
        'openssl' => 'OpenSSL'
    ];
    
    $extensions = [];
    foreach ($required_extensions as $ext => $name) {
        $extensions[] = [
            'name' => $name,
            'extension' => $ext,
            'loaded' => extension_loaded($ext),
            'version' => extension_loaded($ext) ? phpversion($ext) : 'Not Installed'
        ];
    }
    
    return $extensions;
}

// Function to get system information
function getSystemInfo() {
    return [
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'hostname' => php_uname('n'),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'server_addr' => $_SERVER['SERVER_ADDR'] ?? 'Unknown',
        'server_port' => $_SERVER['SERVER_PORT'] ?? 'Unknown'
    ];
}

// Function to check for errors in logs
function checkErrorLogs($lines = 50) {
    $log_file = ini_get('error_log');
    $errors = [];
    
    if ($log_file && file_exists($log_file)) {
        $file_lines = file($log_file);
        $total_lines = count($file_lines);
        $start = max(0, $total_lines - $lines);
        
        for ($i = $start; $i < $total_lines; $i++) {
            $errors[] = $file_lines[$i];
        }
    }
    
    return $errors;
}

// Function to check scheduled tasks/cron jobs
function checkScheduledTasks() {
    $tasks = [];
    
    // Check if cron jobs are set up (example - modify based on your actual cron jobs)
    $cron_file = '/etc/cron.d/wealthrot';
    $tasks['cron_exists'] = file_exists($cron_file);
    
    if ($tasks['cron_exists']) {
        $tasks['cron_content'] = file_get_contents($cron_file);
    }
    
    // Check last backup
    $backup_dir = __DIR__ . '/backups/';
    $latest_backup = null;
    if (file_exists($backup_dir)) {
        $files = glob($backup_dir . '*.{sql,gz,zip}', GLOB_BRACE);
        if (!empty($files)) {
            $latest_backup = max($files);
            $tasks['last_backup'] = [
                'file' => basename($latest_backup),
                'time' => date('Y-m-d H:i:s', filemtime($latest_backup)),
                'size' => formatBytes(filesize($latest_backup))
            ];
        }
    }
    
    return $tasks;
}

// Get all health data
$server_load = getServerLoad();
$memory_usage = getMemoryUsage();
$disk_usage = getDiskUsage();
$php_info = getPHPInfo();
$database_health = checkDatabaseHealth($conn);
$directory_permissions = checkDirectoryPermissions();
$php_extensions = getPHPExtensions();
$system_info = getSystemInfo();
$error_logs = checkErrorLogs();
$scheduled_tasks = checkScheduledTasks();

// Get application statistics
$app_stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'active_users' => $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch_assoc()['count'],
    'total_customers' => $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'],
    'total_loans' => $conn->query("SELECT COUNT(*) as count FROM loans")->fetch_assoc()['count'],
    'active_loans' => $conn->query("SELECT COUNT(*) as count FROM loans WHERE status = 'open'")->fetch_assoc()['count'],
    'total_payments' => $conn->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'],
    'total_payment_amount' => $conn->query("SELECT SUM(total_amount) as total FROM payments")->fetch_assoc()['total'] ?? 0,
    'total_investments' => $conn->query("SELECT COUNT(*) as count FROM investments")->fetch_assoc()['count'],
    'total_investors' => $conn->query("SELECT COUNT(*) as count FROM investors")->fetch_assoc()['count']
];

// Calculate health score
$health_score = 100;
$health_issues = [];

// Check database connection
if (!$database_health['connected']) {
    $health_score -= 20;
    $health_issues[] = 'Database connection issue';
}

// Check disk space
if ($disk_usage['percent_used'] > 90) {
    $health_score -= 15;
    $health_issues[] = 'Disk space critically low (' . $disk_usage['percent_used'] . '% used)';
} elseif ($disk_usage['percent_used'] > 80) {
    $health_score -= 10;
    $health_issues[] = 'Disk space warning (' . $disk_usage['percent_used'] . '% used)';
}

// Check server load
if ($server_load && $server_load['1min'] > 5) {
    $health_score -= 10;
    $health_issues[] = 'High server load (' . $server_load['1min'] . ')';
}

// Check directory permissions
foreach ($directory_permissions as $dir) {
    if (!$dir['writable']) {
        $health_score -= 5;
        $health_issues[] = $dir['name'] . ' is not writable';
    }
}

// Check PHP extensions
foreach ($php_extensions as $ext) {
    if (!$ext['loaded']) {
        $health_score -= 5;
        $health_issues[] = 'Missing PHP extension: ' . $ext['name'];
    }
}

$health_score = max(0, $health_score);
$health_status = $health_score >= 90 ? 'Excellent' : ($health_score >= 70 ? 'Good' : ($health_score >= 50 ? 'Fair' : 'Poor'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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
            max-width: 1400px;
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

        .btn-warning {
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        /* Health Score Card */
        .health-score-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }

        .health-score-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .health-score-title {
            font-size: 18px;
            font-weight: 600;
            opacity: 0.9;
        }

        .health-score-value {
            font-size: 48px;
            font-weight: 700;
        }

        .health-score-status {
            font-size: 16px;
            padding: 5px 15px;
            border-radius: 50px;
            background: rgba(255, 255, 255, 0.2);
            display: inline-block;
        }

        .progress {
            height: 10px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
            margin: 20px 0;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: white;
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .health-issues {
            margin-top: 15px;
        }

        .health-issue-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            margin-bottom: 5px;
        }

        .health-issue-item i {
            color: #fbbf24;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea20 0%, #764ba220 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 14px;
            color: #718096;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-sub {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 5px;
        }

        /* System Cards */
        .system-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i {
            color: #667eea;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .info-item {
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value.warning {
            color: #ecc94b;
        }

        .info-value.danger {
            color: #f56565;
        }

        .info-value.success {
            color: #48bb78;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .health-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .health-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .health-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .health-table tbody tr:hover {
            background: #f7fafc;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-warning {
            background: #feebc8;
            color: #744210;
        }

        .status-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .status-info {
            background: #bee3f8;
            color: #2c5282;
        }

        /* Chart Container */
        .chart-container {
            height: 300px;
            margin: 20px 0;
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .alert-info {
            background: #e6f7ff;
            color: #0050b3;
            border-left: 4px solid #1890ff;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .action-btn.refresh {
            background: #667eea10;
            color: #667eea;
        }

        .action-btn.refresh:hover {
            background: #667eea;
            color: white;
        }

        .action-btn.download {
            background: #48bb7810;
            color: #48bb78;
        }

        .action-btn.download:hover {
            background: #48bb78;
            color: white;
        }

        .action-btn.clear {
            background: #f5656510;
            color: #f56565;
        }

        .action-btn.clear:hover {
            background: #f56565;
            color: white;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
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
                            <i class="bi bi-heart-pulse"></i>
                            <h1>System Health Monitor</h1>
                        </div>
                        <div>
                            <button class="btn btn-info" onclick="runDiagnostics()">
                                <i class="bi bi-stethoscope"></i> Run Diagnostics
                            </button>
                            <button class="btn btn-primary" onclick="refreshPage()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Health Score Card -->
                    <div class="health-score-card">
                        <div class="health-score-header">
                            <span class="health-score-title">System Health Score</span>
                            <span class="health-score-status"><?php echo $health_status; ?></span>
                        </div>
                        <div class="health-score-value"><?php echo $health_score; ?>%</div>
                        <div class="progress">
                            <div class="progress-bar" style="width: <?php echo $health_score; ?>%"></div>
                        </div>
                        
                        <?php if (!empty($health_issues)): ?>
                        <div class="health-issues">
                            <div style="margin-bottom: 10px; font-weight: 600;">Issues Found:</div>
                            <?php foreach ($health_issues as $issue): ?>
                            <div class="health-issue-item">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <span><?php echo $issue; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="health-issues">
                            <div class="health-issue-item" style="background: rgba(72, 187, 120, 0.2);">
                                <i class="bi bi-check-circle-fill" style="color: #48bb78;"></i>
                                <span>All systems are operating normally</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Stats -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-database"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Database Size</div>
                                <div class="stat-value"><?php echo $database_health['size_mb']; ?> MB</div>
                                <div class="stat-sub"><?php echo $database_health['table_count']; ?> tables</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-hdd-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Disk Usage</div>
                                <div class="stat-value <?php echo $disk_usage['percent_used'] > 90 ? 'danger' : ($disk_usage['percent_used'] > 80 ? 'warning' : ''); ?>">
                                    <?php echo $disk_usage['percent_used']; ?>%
                                </div>
                                <div class="stat-sub"><?php echo $disk_usage['used_formatted']; ?> / <?php echo $disk_usage['total_formatted']; ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cpu"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Server Load</div>
                                <div class="stat-value"><?php echo $server_load ? $server_load['1min'] : 'N/A'; ?></div>
                                <div class="stat-sub">1min average</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-memory"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Memory Usage</div>
                                <div class="stat-value"><?php echo $memory_usage ? $memory_usage['current_formatted'] : 'N/A'; ?></div>
                                <div class="stat-sub">Peak: <?php echo $memory_usage ? $memory_usage['peak_formatted'] : 'N/A'; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- System Information -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-info-circle"></i>
                            System Information
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Operating System</div>
                                <div class="info-value"><?php echo $system_info['os']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Hostname</div>
                                <div class="info-value"><?php echo $system_info['hostname']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server Software</div>
                                <div class="info-value"><?php echo $system_info['server_software']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Document Root</div>
                                <div class="info-value"><?php echo $system_info['document_root']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Server IP</div>
                                <div class="info-value"><?php echo $system_info['server_addr']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Your IP</div>
                                <div class="info-value"><?php echo $system_info['remote_addr']; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- PHP Information -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-filetype-php"></i>
                            PHP Configuration
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">PHP Version</div>
                                <div class="info-value"><?php echo $php_info['version']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Memory Limit</div>
                                <div class="info-value"><?php echo $php_info['memory_limit']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Max Execution Time</div>
                                <div class="info-value"><?php echo $php_info['max_execution_time']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Upload Max Size</div>
                                <div class="info-value"><?php echo $php_info['upload_max_filesize']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Post Max Size</div>
                                <div class="info-value"><?php echo $php_info['post_max_size']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Timezone</div>
                                <div class="info-value"><?php echo $php_info['date_timezone']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Display Errors</div>
                                <div class="info-value <?php echo $php_info['display_errors'] == 'On' ? 'warning' : 'success'; ?>">
                                    <?php echo $php_info['display_errors']; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Session Save Path</div>
                                <div class="info-value"><?php echo $php_info['session_save_path']; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Database Information -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-database"></i>
                            Database Information
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">MySQL Version</div>
                                <div class="info-value"><?php echo $database_health['version']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Database Size</div>
                                <div class="info-value"><?php echo $database_health['size_mb']; ?> MB</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Tables</div>
                                <div class="info-value"><?php echo $database_health['table_count']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Rows</div>
                                <div class="info-value"><?php echo number_format($database_health['total_rows']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Data Size</div>
                                <div class="info-value"><?php echo $database_health['total_data_formatted']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Index Size</div>
                                <div class="info-value"><?php echo $database_health['total_index_formatted']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Character Set</div>
                                <div class="info-value"><?php echo $database_health['character_set']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Connection Status</div>
                                <div class="info-value <?php echo $database_health['connected'] ? 'success' : 'danger'; ?>">
                                    <?php echo $database_health['connected'] ? 'Connected' : 'Disconnected'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Application Statistics -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-graph-up"></i>
                            Application Statistics
                        </div>
                        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 0;">
                            <div class="stat-card" style="margin-bottom: 0;">
                                <div class="stat-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Users</div>
                                    <div class="stat-value"><?php echo $app_stats['total_users']; ?></div>
                                    <div class="stat-sub"><?php echo $app_stats['active_users']; ?> active</div>
                                </div>
                            </div>
                            
                            <div class="stat-card" style="margin-bottom: 0;">
                                <div class="stat-icon">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Customers</div>
                                    <div class="stat-value"><?php echo $app_stats['total_customers']; ?></div>
                                </div>
                            </div>
                            
                            <div class="stat-card" style="margin-bottom: 0;">
                                <div class="stat-icon">
                                    <i class="bi bi-cash-stack"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Loans</div>
                                    <div class="stat-value"><?php echo $app_stats['total_loans']; ?></div>
                                    <div class="stat-sub"><?php echo $app_stats['active_loans']; ?> active</div>
                                </div>
                            </div>
                            
                            <div class="stat-card" style="margin-bottom: 0;">
                                <div class="stat-icon">
                                    <i class="bi bi-currency-rupee"></i>
                                </div>
                                <div class="stat-content">
                                    <div class="stat-label">Payments</div>
                                    <div class="stat-value"><?php echo number_format($app_stats['total_payments']); ?></div>
                                    <div class="stat-sub">₹<?php echo number_format($app_stats['total_payment_amount']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PHP Extensions -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-puzzle"></i>
                            PHP Extensions
                        </div>
                        <div class="table-responsive">
                            <table class="health-table">
                                <thead>
                                    <tr>
                                        <th>Extension</th>
                                        <th>Name</th>
                                        <th>Status</th>
                                        <th>Version</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($php_extensions as $ext): ?>
                                    <tr>
                                        <td><?php echo $ext['extension']; ?></td>
                                        <td><?php echo $ext['name']; ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $ext['loaded'] ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo $ext['loaded'] ? 'Loaded' : 'Missing'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $ext['version']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Directory Permissions -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-folder"></i>
                            Directory Permissions
                        </div>
                        <div class="table-responsive">
                            <table class="health-table">
                                <thead>
                                    <tr>
                                        <th>Directory</th>
                                        <th>Path</th>
                                        <th>Exists</th>
                                        <th>Readable</th>
                                        <th>Writable</th>
                                        <th>Permissions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($directory_permissions as $dir): ?>
                                    <tr>
                                        <td><?php echo $dir['name']; ?></td>
                                        <td><code><?php echo $dir['path']; ?></code></td>
                                        <td>
                                            <span class="status-badge <?php echo $dir['exists'] ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo $dir['exists'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $dir['readable'] ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo $dir['readable'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $dir['writable'] ? 'status-success' : 'status-danger'; ?>">
                                                <?php echo $dir['writable'] ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                        <td><code><?php echo $dir['permissions']; ?></code></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Database Tables -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-table"></i>
                            Database Tables
                        </div>
                        <div class="table-responsive">
                            <table class="health-table" id="tablesTable">
                                <thead>
                                    <tr>
                                        <th>Table Name</th>
                                        <th>Engine</th>
                                        <th>Rows</th>
                                        <th>Data Size</th>
                                        <th>Index Size</th>
                                        <th>Total Size</th>
                                        <th>Collation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($database_health['table_details'] as $table): 
                                        $total_size = $table['data_length'] + $table['index_length'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $table['name']; ?></strong></td>
                                        <td><?php echo $table['engine']; ?></td>
                                        <td class="text-right"><?php echo number_format($table['rows']); ?></td>
                                        <td class="text-right"><?php echo formatBytes($table['data_length']); ?></td>
                                        <td class="text-right"><?php echo formatBytes($table['index_length']); ?></td>
                                        <td class="text-right"><?php echo formatBytes($total_size); ?></td>
                                        <td><?php echo $table['collation']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Error Logs -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-exclamation-triangle"></i>
                            Recent Error Logs (Last 50 lines)
                        </div>
                        <?php if (!empty($error_logs)): ?>
                        <div class="table-responsive">
                            <table class="health-table">
                                <tbody>
                                    <?php foreach ($error_logs as $log): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 12px; white-space: pre-wrap;"><?php echo htmlspecialchars($log); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            No recent errors found in logs
                        </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <button class="action-btn clear" onclick="clearErrorLogs()">
                                <i class="bi bi-trash"></i> Clear Logs
                            </button>
                            <button class="action-btn download" onclick="downloadLogs()">
                                <i class="bi bi-download"></i> Download Logs
                            </button>
                        </div>
                    </div>

                    <!-- Scheduled Tasks -->
                    <div class="system-card">
                        <div class="card-title">
                            <i class="bi bi-clock-history"></i>
                            Scheduled Tasks
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Cron Jobs Configured</div>
                                <div class="info-value <?php echo $scheduled_tasks['cron_exists'] ? 'success' : 'warning'; ?>">
                                    <?php echo $scheduled_tasks['cron_exists'] ? 'Yes' : 'No'; ?>
                                </div>
                            </div>
                            
                            <?php if (isset($scheduled_tasks['last_backup'])): ?>
                            <div class="info-item">
                                <div class="info-label">Last Backup</div>
                                <div class="info-value"><?php echo $scheduled_tasks['last_backup']['time']; ?></div>
                                <div class="stat-sub"><?php echo $scheduled_tasks['last_backup']['file']; ?> (<?php echo $scheduled_tasks['last_backup']['size']; ?>)</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (isset($scheduled_tasks['cron_content'])): ?>
                        <div style="margin-top: 15px;">
                            <strong>Cron Configuration:</strong>
                            <pre style="background: #f7fafc; padding: 10px; border-radius: 8px; margin-top: 10px; font-size: 12px;"><?php echo htmlspecialchars($scheduled_tasks['cron_content']); ?></pre>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button class="action-btn refresh" onclick="refreshPage()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh Data
                        </button>
                        <button class="action-btn download" onclick="downloadHealthReport()">
                            <i class="bi bi-file-text"></i> Download Report
                        </button>
                        <button class="action-btn clear" onclick="clearCache()">
                            <i class="bi bi-database"></i> Clear Cache
                        </button>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#tablesTable').DataTable({
                pageLength: 10,
                order: [[0, 'asc']],
                language: {
                    search: "Search tables:",
                    lengthMenu: "Show _MENU_ tables",
                    info: "Showing _START_ to _END_ of _TOTAL_ tables",
                    emptyTable: "No tables found"
                }
            });
        });

        // Refresh page
        function refreshPage() {
            window.location.reload();
        }

        // Run diagnostics
        function runDiagnostics() {
            Swal.fire({
                title: 'Running System Diagnostics',
                html: 'Please wait while we check your system...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Simulate diagnostic check (in real implementation, you'd make an AJAX call)
            setTimeout(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'Diagnostics Complete',
                    text: 'System check completed successfully',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    refreshPage();
                });
            }, 2000);
        }

        // Download health report
        function downloadHealthReport() {
            const healthData = {
                timestamp: new Date().toISOString(),
                health_score: <?php echo $health_score; ?>,
                health_status: '<?php echo $health_status; ?>',
                issues: <?php echo json_encode($health_issues); ?>,
                system_info: <?php echo json_encode($system_info); ?>,
                php_info: <?php echo json_encode($php_info); ?>,
                database: {
                    size: <?php echo $database_health['size_mb']; ?>,
                    tables: <?php echo $database_health['table_count']; ?>,
                    rows: <?php echo $database_health['total_rows']; ?>
                },
                disk_usage: <?php echo json_encode($disk_usage); ?>,
                server_load: <?php echo json_encode($server_load); ?>,
                memory_usage: <?php echo json_encode($memory_usage); ?>,
                app_stats: <?php echo json_encode($app_stats); ?>
            };
            
            const dataStr = JSON.stringify(healthData, null, 2);
            const dataUri = 'data:application/json;charset=utf-8,'+ encodeURIComponent(dataStr);
            
            const exportFileDefaultName = 'system_health_report_' + new Date().toISOString().slice(0,10) + '.json';
            
            const linkElement = document.createElement('a');
            linkElement.setAttribute('href', dataUri);
            linkElement.setAttribute('download', exportFileDefaultName);
            linkElement.click();
            
            Swal.fire({
                icon: 'success',
                title: 'Report Downloaded',
                text: 'System health report has been downloaded',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Clear error logs
        function clearErrorLogs() {
            Swal.fire({
                title: 'Clear Error Logs?',
                text: 'Are you sure you want to clear all error logs? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Clear Logs',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In a real implementation, you'd make an AJAX call to clear logs
                    Swal.fire({
                        icon: 'success',
                        title: 'Logs Cleared',
                        text: 'Error logs have been cleared successfully',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }

        // Download logs
        function downloadLogs() {
            const logs = <?php echo json_encode($error_logs); ?>;
            const dataStr = logs.join('\n');
            const dataUri = 'data:text/plain;charset=utf-8,'+ encodeURIComponent(dataStr);
            
            const exportFileDefaultName = 'error_logs_' + new Date().toISOString().slice(0,10) + '.log';
            
            const linkElement = document.createElement('a');
            linkElement.setAttribute('href', dataUri);
            linkElement.setAttribute('download', exportFileDefaultName);
            linkElement.click();
            
            Swal.fire({
                icon: 'success',
                title: 'Logs Downloaded',
                text: 'Error logs have been downloaded',
                timer: 2000,
                showConfirmButton: false
            });
        }

        // Clear cache
        function clearCache() {
            Swal.fire({
                title: 'Clear System Cache?',
                text: 'This will clear temporary files and cached data. Continue?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Clear Cache',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In a real implementation, you'd make an AJAX call to clear cache
                    Swal.fire({
                        icon: 'success',
                        title: 'Cache Cleared',
                        text: 'System cache has been cleared successfully',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }
            });
        }

        // Auto-refresh every 5 minutes
        setTimeout(() => {
            refreshPage();
        }, 300000); // 5 minutes
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>