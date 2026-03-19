<?php
session_start();
$currentPage = 'cache-clear';
$pageTitle = 'Clear System Cache';
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

$message = '';
$error = '';
$cache_stats = [];

// Define cache directories
$cache_directories = [
    'temp' => [
        'path' => __DIR__ . '/tmp/',
        'name' => 'Temporary Files',
        'description' => 'Session temporary files and upload temp files',
        'icon' => 'bi-folder2-open',
        'color' => '#4299e1'
    ],
    'logs' => [
        'path' => __DIR__ . '/logs/',
        'name' => 'Log Files',
        'description' => 'System and error log files',
        'icon' => 'bi-file-text',
        'color' => '#f56565'
    ],
    'cache' => [
        'path' => __DIR__ . '/cache/',
        'name' => 'Application Cache',
        'description' => 'Cached data, compiled templates, etc.',
        'icon' => 'bi-database',
        'color' => '#48bb78'
    ],
    'sessions' => [
        'path' => session_save_path() ?: __DIR__ . '/tmp/sessions/',
        'name' => 'Session Files',
        'description' => 'Active user session files',
        'icon' => 'bi-people',
        'color' => '#9f7aea'
    ],
    'thumbnails' => [
        'path' => __DIR__ . '/uploads/thumbnails/',
        'name' => 'Image Thumbnails',
        'description' => 'Generated image thumbnails',
        'icon' => 'bi-images',
        'color' => '#ed8936'
    ],
    'opcache' => [
        'path' => 'opcache',
        'name' => 'OPcache',
        'description' => 'PHP OPcache (requires restart)',
        'icon' => 'bi-cpu',
        'color' => '#fbbf24',
        'special' => true
    ]
];

// Function to get directory size
function getDirectorySize($path) {
    if (!file_exists($path)) {
        return 0;
    }
    
    $size = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $size += $file->getSize();
    }
    return $size;
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

// Function to get file count
function getFileCount($path) {
    if (!file_exists($path)) {
        return 0;
    }
    
    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $count++;
    }
    return $count;
}

// Function to clear directory
function clearDirectory($path, $deleteSelf = false) {
    if (!file_exists($path)) {
        return true;
    }
    
    $success = true;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isDir()) {
            $success = $success && rmdir($file->getRealPath());
        } else {
            $success = $success && unlink($file->getRealPath());
        }
    }
    
    if ($deleteSelf) {
        $success = $success && rmdir($path);
    }
    
    return $success;
}

// Function to clear OPcache
function clearOPcache() {
    if (function_exists('opcache_reset')) {
        return opcache_reset();
    }
    return false;
}

// Function to clear APCu cache
function clearAPCucache() {
    if (function_exists('apcu_clear_cache')) {
        return apcu_clear_cache();
    }
    return false;
}

// Get cache statistics
foreach ($cache_directories as $key => $dir) {
    if (isset($dir['special']) && $dir['special']) {
        // Handle special caches
        if ($key == 'opcache') {
            $cache_stats[$key] = [
                'name' => $dir['name'],
                'description' => $dir['description'],
                'icon' => $dir['icon'],
                'color' => $dir['color'],
                'size' => 'N/A',
                'files' => opcache_get_status() ? opcache_get_status()['opcache_statistics']['num_cached_scripts'] : 0,
                'exists' => function_exists('opcache_reset'),
                'writable' => function_exists('opcache_reset'),
                'size_formatted' => 'N/A',
                'special' => true
            ];
        }
    } else {
        // Handle regular directories
        $exists = file_exists($dir['path']);
        $writable = $exists ? is_writable($dir['path']) : false;
        $size = $exists ? getDirectorySize($dir['path']) : 0;
        $files = $exists ? getFileCount($dir['path']) : 0;
        
        $cache_stats[$key] = [
            'name' => $dir['name'],
            'description' => $dir['description'],
            'icon' => $dir['icon'],
            'color' => $dir['color'],
            'path' => $dir['path'],
            'exists' => $exists,
            'writable' => $writable,
            'size' => $size,
            'files' => $files,
            'size_formatted' => formatBytes($size),
            'special' => false
        ];
    }
}

// Handle clear cache request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target = $_POST['target'] ?? '';
    
    if ($action === 'clear_all') {
        // Clear all caches
        $cleared = [];
        $failed = [];
        
        foreach ($cache_stats as $key => $stat) {
            if (isset($stat['special']) && $stat['special']) {
                if ($key == 'opcache') {
                    if (clearOPcache()) {
                        $cleared[] = $stat['name'];
                    } else {
                        $failed[] = $stat['name'];
                    }
                }
            } else {
                if ($stat['exists'] && $stat['writable']) {
                    if (clearDirectory($stat['path'])) {
                        $cleared[] = $stat['name'];
                    } else {
                        $failed[] = $stat['name'];
                    }
                } elseif (!$stat['exists']) {
                    // Create directory if it doesn't exist
                    if (mkdir($stat['path'], 0777, true)) {
                        $cleared[] = $stat['name'] . ' (created)';
                    }
                } else {
                    $failed[] = $stat['name'] . ' (not writable)';
                }
            }
        }
        
        if (empty($failed)) {
            $message = "All caches cleared successfully! Cleared: " . implode(', ', $cleared);
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                          VALUES (?, 'clear', ?, 'cache')";
            $log_stmt = $conn->prepare($log_query);
            $log_desc = "Cleared all system caches";
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
        } else {
            $error = "Failed to clear some caches: " . implode(', ', $failed);
            if (!empty($cleared)) {
                $message = "Successfully cleared: " . implode(', ', $cleared);
            }
        }
    } elseif ($action === 'clear_single') {
        $key = $target;
        
        if (isset($cache_stats[$key])) {
            $stat = $cache_stats[$key];
            
            if (isset($stat['special']) && $stat['special']) {
                if ($key == 'opcache' && clearOPcache()) {
                    $message = "OPcache cleared successfully!";
                    
                    // Log activity
                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                  VALUES (?, 'clear', ?, 'cache')";
                    $log_stmt = $conn->prepare($log_query);
                    $log_desc = "Cleared OPcache";
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $error = "Failed to clear OPcache. Function may not be available.";
                }
            } else {
                if ($stat['exists'] && $stat['writable']) {
                    if (clearDirectory($stat['path'])) {
                        $message = "{$stat['name']} cleared successfully!";
                        
                        // Log activity
                        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name) 
                                      VALUES (?, 'clear', ?, 'cache')";
                        $log_stmt = $conn->prepare($log_query);
                        $log_desc = "Cleared {$stat['name']}";
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                    } else {
                        $error = "Failed to clear {$stat['name']}. Permission denied.";
                    }
                } elseif (!$stat['exists']) {
                    // Create directory
                    if (mkdir($stat['path'], 0777, true)) {
                        $message = "{$stat['name']} directory created (was empty)";
                    } else {
                        $error = "Failed to create {$stat['name']} directory";
                    }
                } else {
                    $error = "{$stat['name']} is not writable. Check permissions.";
                }
            }
        } else {
            $error = "Invalid cache target specified";
        }
    } elseif ($action === 'create_dirs') {
        // Create all missing directories
        $created = [];
        $failed = [];
        
        foreach ($cache_stats as $key => $stat) {
            if (!isset($stat['special']) && !$stat['exists']) {
                if (mkdir($stat['path'], 0777, true)) {
                    $created[] = $stat['name'];
                } else {
                    $failed[] = $stat['name'];
                }
            }
        }
        
        if (!empty($created)) {
            $message = "Created directories: " . implode(', ', $created);
        }
        if (!empty($failed)) {
            $error = "Failed to create: " . implode(', ', $failed);
        }
        if (empty($created) && empty($failed)) {
            $message = "All directories already exist";
        }
    }
}

// Get total cache size
$total_size = 0;
foreach ($cache_stats as $stat) {
    if (!isset($stat['special'])) {
        $total_size += $stat['size'];
    }
}
$total_size_formatted = formatBytes($total_size);

// Get PHP info for cache functions
$opcache_enabled = function_exists('opcache_reset');
$apcu_enabled = function_exists('apcu_clear_cache');
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

        .btn-warning {
            background: #fbbf24;
            color: #92400e;
        }

        .btn-warning:hover {
            background: #f59e0b;
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

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
        }

        /* Stats Cards */
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

        /* Cache Cards */
        .cache-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .cache-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid;
            transition: all 0.3s;
        }

        .cache-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .cache-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .cache-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
        }

        .cache-title {
            flex: 1;
        }

        .cache-title h3 {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 3px;
        }

        .cache-title p {
            font-size: 12px;
            color: #718096;
        }

        .cache-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .value {
            font-size: 16px;
            font-weight: 700;
            color: #2d3748;
        }

        .stat-item .label {
            font-size: 11px;
            color: #718096;
        }

        .cache-status {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-exists {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-missing {
            background: #fed7d7;
            color: #742a2a;
        }

        .status-writable {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-not-writable {
            background: #fed7d7;
            color: #742a2a;
        }

        .cache-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .action-btn.clear {
            background: #f5656510;
            color: #f56565;
        }

        .action-btn.clear:hover {
            background: #f56565;
            color: white;
        }

        .action-btn.create {
            background: #48bb7810;
            color: #48bb78;
        }

        .action-btn.create:hover {
            background: #48bb78;
            color: white;
        }

        .action-btn.view {
            background: #667eea10;
            color: #667eea;
        }

        .action-btn.view:hover {
            background: #667eea;
            color: white;
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
            flex-wrap: wrap;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .cache-grid {
                grid-template-columns: 1fr;
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
                            <i class="bi bi-database"></i>
                            <h1>Clear System Cache</h1>
                        </div>
                        <div>
                            <button class="btn btn-danger" onclick="clearAllCache()">
                                <i class="bi bi-trash"></i> Clear All Cache
                            </button>
                            <button class="btn btn-info" onclick="createAllDirectories()">
                                <i class="bi bi-folder-plus"></i> Create Missing Dirs
                            </button>
                            <button class="btn btn-secondary" onclick="refreshPage()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-hdd-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Cache Size</div>
                                <div class="stat-value"><?php echo $total_size_formatted; ?></div>
                                <div class="stat-sub">Across all caches</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-folder"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Cache Directories</div>
                                <div class="stat-value"><?php echo count($cache_stats); ?></div>
                                <div class="stat-sub">Total directories</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">OPcache Status</div>
                                <div class="stat-value"><?php echo $opcache_enabled ? 'Available' : 'N/A'; ?></div>
                                <div class="stat-sub">PHP OPcache</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-clock-history"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Last Cleared</div>
                                <div class="stat-value"><?php 
                                    $last_query = "SELECT created_at FROM activity_log WHERE action = 'clear' AND table_name = 'cache' ORDER BY created_at DESC LIMIT 1";
                                    $last_result = $conn->query($last_query);
                                    if ($last_result && $last_result->num_rows > 0) {
                                        echo date('d-m-Y', strtotime($last_result->fetch_assoc()['created_at']));
                                    } else {
                                        echo 'Never';
                                    }
                                ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Warning Alert -->
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div>
                            <strong>Warning:</strong> Clearing cache may temporarily slow down your application while care rebuilt. 
                            Active sessions may be affected if you clear session files.
                        </div>
                    </div>

                    <!-- Cache Cards -->
                    <div class="cache-grid">
                        <?php foreach ($cache_stats as $key => $cache): ?>
                            <div class="cache-card" style="border-left-color: <?php echo $cache['color']; ?>">
                                <div class="cache-header">
                                    <div class="cache-icon" style="background: <?php echo $cache['color']; ?>">
                                        <i class="bi <?php echo $cache['icon']; ?>"></i>
                                    </div>
                                    <div class="cache-title">
                                        <h3><?php echo $cache['name']; ?></h3>
                                        <p><?php echo $cache['description']; ?></p>
                                    </div>
                                </div>

                                <div class="cache-stats">
                                    <?php if (isset($cache['special']) && $cache['special']): ?>
                                        <div class="stat-item">
                                            <div class="value"><?php echo $cache['files']; ?></div>
                                            <div class="label">Cached Scripts</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="value"><?php echo $cache['exists'] ? 'Yes' : 'No'; ?></div>
                                            <div class="label">Available</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="stat-item">
                                            <div class="value"><?php echo $cache['size_formatted']; ?></div>
                                            <div class="label">Size</div>
                                        </div>
                                        <div class="stat-item">
                                            <div class="value"><?php echo number_format($cache['files']); ?></div>
                                            <div class="label">Files</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="cache-status">
                                    <?php if (!isset($cache['special'])): ?>
                                        <span class="status-badge <?php echo $cache['exists'] ? 'status-exists' : 'status-missing'; ?>">
                                            <i class="bi bi-<?php echo $cache['exists'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                            <?php echo $cache['exists'] ? 'Exists' : 'Missing'; ?>
                                        </span>
                                        <span class="status-badge <?php echo $cache['writable'] ? 'status-writable' : 'status-not-writable'; ?>">
                                            <i class="bi bi-<?php echo $cache['writable'] ? 'pencil' : 'lock'; ?>"></i>
                                            <?php echo $cache['writable'] ? 'Writable' : 'Not Writable'; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge <?php echo $cache['exists'] ? 'status-exists' : 'status-missing'; ?>">
                                            <i class="bi bi-<?php echo $cache['exists'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                                            <?php echo $cache['exists'] ? 'Available' : 'Not Available'; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="cache-actions">
                                    <?php if (isset($cache['special']) && $cache['special']): ?>
                                        <?php if ($key == 'opcache' && $opcache_enabled): ?>
                                            <button class="action-btn clear" onclick="clearSingleCache('<?php echo $key; ?>', '<?php echo $cache['name']; ?>')">
                                                <i class="bi bi-trash"></i> Clear
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($cache['exists'] && $cache['writable']): ?>
                                            <button class="action-btn clear" onclick="clearSingleCache('<?php echo $key; ?>', '<?php echo $cache['name']; ?>')">
                                                <i class="bi bi-trash"></i> Clear
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!$cache['exists']): ?>
                                            <button class="action-btn create" onclick="createDirectory('<?php echo $key; ?>', '<?php echo $cache['name']; ?>')">
                                                <i class="bi bi-folder-plus"></i> Create
                                            </button>
                                        <?php endif; ?>
                                        <button class="action-btn view" onclick="viewDirectory('<?php echo $key; ?>')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (!isset($cache['special']) && $cache['exists']): ?>
                                    <div style="margin-top: 10px; font-size: 11px; color: #a0aec0;">
                                        <i class="bi bi-folder"></i> <?php echo $cache['path']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Cache Info -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>
                            <strong>Cache Information:</strong><br>
                            - Temporary files: Session data and upload temporary files<br>
                            - Log files: System and error logs (clearing these will remove all log history)<br>
                            - Application cache: Cached data, compiled templates<br>
                            - Session files: Active user sessions (users will be logged out)<br>
                            - Thumbnails: Generated image thumbnails<br>
                            - OPcache: PHP bytecode cache (requires web server restart after clearing)
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <form method="POST" id="clearAllForm" style="display: inline;">
                            <input type="hidden" name="action" value="clear_all">
                            <button type="button" class="btn btn-danger" onclick="confirmClearAll()">
                                <i class="bi bi-trash"></i> Clear All Cache
                            </button>
                        </form>
                        
                        <form method="POST" id="createAllForm" style="display: inline;">
                            <input type="hidden" name="action" value="create_dirs">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-folder-plus"></i> Create All Directories
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Refresh page
        function refreshPage() {
            window.location.reload();
        }

        // Clear single cache
        function clearSingleCache(key, name) {
            Swal.fire({
                title: 'Clear ' + name + '?',
                text: 'Are you sure you want to clear this cache?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Clear',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="clear_single">
                        <input type="hidden" name="target" value="${key}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Create directory
        function createDirectory(key, name) {
            Swal.fire({
                title: 'Create ' + name + '?',
                text: 'This directory does not exist. Create it now?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Create',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="create_dirs">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // View directory contents
        function viewDirectory(key) {
            // In a real implementation, you might show directory contents
            Swal.fire({
                title: 'Directory Contents',
                text: 'This feature would show the files in this directory',
                icon: 'info',
                confirmButtonColor: '#667eea'
            });
        }

        // Confirm clear all
        function confirmClearAll() {
            Swal.fire({
                title: 'Clear All Cache?',
                html: 'Are you sure you want to clear <strong>all</strong> caches?<br><br>' +
                      '<span style="color: #f56565;">Warning: This will:</span><br>' +
                      '• Clear all temporary files<br>' +
                      '• Delete all log files<br>' +
                      '• Clear application cache<br>' +
                      '• Log out all users (if session cache is cleared)<br>' +
                      '• Reset OPcache (if enabled)',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Clear All',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('clearAllForm').submit();
                }
            });
        }

        // Create all directories
        function createAllDirectories() {
            Swal.fire({
                title: 'Create All Directories?',
                text: 'This will create all missing cache directories. Continue?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#48bb78',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Create All',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('createAllForm').submit();
                }
            });
        }

        // Auto-refresh stats every minute
        setInterval(() => {
            // In a real implementation, you might refresh stats via AJAX
            // For now, we'll just show a notification
            console.log('Stats would refresh here');
        }, 60000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>