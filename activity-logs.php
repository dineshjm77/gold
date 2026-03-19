<?php
session_start();
$currentPage = 'activity-logs';
$pageTitle = 'Activity Logs';
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

// Get filter parameters
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$table_name = isset($_GET['table_name']) ? $_GET['table_name'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Validate limit
if ($limit <= 0) $limit = 100;
if ($limit > 1000) $limit = 1000; // Max 1000 records per page
if ($offset < 0) $offset = 0;

// Build WHERE clause
$where_conditions = ["1=1"];
$params = [];
$types = "";

// Date range filter
if (!empty($date_from) && !empty($date_to)) {
    $where_conditions[] = "DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= "ss";
}

// User filter
if ($user_id > 0) {
    $where_conditions[] = "al.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
}

// Action filter
if (!empty($action)) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action;
    $types .= "s";
}

// Table name filter
if (!empty($table_name)) {
    $where_conditions[] = "al.table_name = ?";
    $params[] = $table_name;
    $types .= "s";
}

// Search filter (searches in description)
if (!empty($search)) {
    $where_conditions[] = "al.description LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM activity_log al WHERE $where_clause";
$count_stmt = mysqli_prepare($conn, $count_query);
if ($count_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($count_stmt);
    $count_result = mysqli_stmt_get_result($count_stmt);
    $total_records = mysqli_fetch_assoc($count_result)['total'];
} else {
    $total_records = 0;
}
$total_pages = $limit > 0 ? ceil($total_records / $limit) : 1;
$current_page = $limit > 0 ? floor($offset / $limit) + 1 : 1;

// Get activity logs with pagination
$query = "SELECT 
            al.*,
            u.username,
            u.name as user_name,
            u.role as user_role
          FROM activity_log al
          JOIN users u ON al.user_id = u.id
          WHERE $where_clause
          ORDER BY al.created_at DESC, al.id DESC
          LIMIT ? OFFSET ?";

// Prepare parameters for main query
$main_params = $params;
$main_types = $types;

// Add pagination parameters
$main_params[] = $limit;
$main_params[] = $offset;
$main_types .= "ii";

$logs = [];
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    if (!empty($main_params)) {
        mysqli_stmt_bind_param($stmt, $main_types, ...$main_params);
    }
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $logs[] = $row;
        }
    } else {
        $error = "Query execution failed: " . mysqli_stmt_error($stmt);
    }
} else {
    $error = "Query preparation failed: " . mysqli_error($conn);
}

// Get users for filter dropdown
$users_query = "SELECT id, username, name, role FROM users WHERE status = 'active' ORDER BY name";
$users_result = mysqli_query($conn, $users_query);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}

// Get distinct actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM activity_log ORDER BY action";
$actions_result = mysqli_query($conn, $actions_query);
$actions = [];
while ($row = mysqli_fetch_assoc($actions_result)) {
    $actions[] = $row['action'];
}

// Get distinct table names for filter dropdown
$tables_query = "SELECT DISTINCT table_name FROM activity_log WHERE table_name IS NOT NULL ORDER BY table_name";
$tables_result = mysqli_query($conn, $tables_query);
$tables = [];
while ($row = mysqli_fetch_assoc($tables_result)) {
    $tables[] = $row['table_name'];
}

// Get summary statistics
$stats_query = "SELECT 
                COUNT(*) as total_logs,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT action) as unique_actions,
                COUNT(DISTINCT table_name) as unique_tables,
                MAX(created_at) as last_activity,
                MIN(created_at) as first_activity,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_logs,
                SUM(CASE WHEN YEARWEEK(created_at) = YEARWEEK(CURDATE()) THEN 1 ELSE 0 END) as week_logs,
                SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as month_logs
              FROM activity_log al
              WHERE $where_clause";

$stats_stmt = mysqli_prepare($conn, $stats_query);
if ($stats_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stats_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stats_stmt);
    $stats_result = mysqli_stmt_get_result($stats_stmt);
    $stats = mysqli_fetch_assoc($stats_result);
} else {
    $stats = [
        'total_logs' => 0,
        'unique_users' => 0,
        'unique_actions' => 0,
        'unique_tables' => 0,
        'last_activity' => null,
        'first_activity' => null,
        'today_logs' => 0,
        'week_logs' => 0,
        'month_logs' => 0
    ];
}

// Get action breakdown
$action_stats_query = "SELECT 
                        action,
                        COUNT(*) as count,
                        COUNT(DISTINCT user_id) as users,
                        MIN(created_at) as first_occurrence,
                        MAX(created_at) as last_occurrence
                      FROM activity_log al
                      WHERE $where_clause
                      GROUP BY action
                      ORDER BY count DESC
                      LIMIT 10";

$action_stats_stmt = mysqli_prepare($conn, $action_stats_query);
if ($action_stats_stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($action_stats_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($action_stats_stmt);
    $action_stats_result = mysqli_stmt_get_result($action_stats_stmt);
    $action_stats = [];
    while ($row = mysqli_fetch_assoc($action_stats_result)) {
        $action_stats[] = $row;
    }
} else {
    $action_stats = [];
}

// Get user activity summary
$user_stats_query = "SELECT 
                      u.id,
                      u.name,
                      u.username,
                      u.role,
                      COUNT(al.id) as activity_count,
                      MAX(al.created_at) as last_activity
                    FROM users u
                    LEFT JOIN activity_log al ON u.id = al.user_id
                    WHERE u.status = 'active'
                    GROUP BY u.id, u.name, u.username, u.role
                    ORDER BY activity_count DESC
                    LIMIT 10";

$user_stats_result = mysqli_query($conn, $user_stats_query);
$user_stats = [];
while ($row = mysqli_fetch_assoc($user_stats_result)) {
    $user_stats[] = $row;
}

// Get daily activity for chart
$daily_query = "SELECT 
                DATE(created_at) as activity_date,
                COUNT(*) as count
              FROM activity_log al
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              GROUP BY DATE(created_at)
              ORDER BY activity_date ASC";

$daily_result = mysqli_query($conn, $daily_query);
$daily_labels = [];
$daily_data = [];
while ($row = mysqli_fetch_assoc($daily_result)) {
    $daily_labels[] = date('d M', strtotime($row['activity_date']));
    $daily_data[] = $row['count'];
}

// Handle export
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportActivityLogs($conn, $where_clause, $params, $types);
}

function exportActivityLogs($conn, $where_clause, $params, $types) {
    // Get all logs for export
    $query = "SELECT 
                al.id,
                al.created_at as timestamp,
                u.name as user_name,
                u.username,
                u.role,
                al.action,
                al.description,
                al.table_name,
                al.record_id
              FROM activity_log al
              JOIN users u ON al.user_id = u.id
              WHERE $where_clause
              ORDER BY al.created_at DESC";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    fputcsv($output, ['ID', 'Timestamp', 'User', 'Username', 'Role', 'Action', 'Description', 'Table', 'Record ID']);
    
    // Add data
    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, [
            $row['id'],
            date('Y-m-d H:i:s', strtotime($row['timestamp'])),
            $row['user_name'],
            $row['username'],
            $row['role'],
            $row['action'],
            $row['description'],
            $row['table_name'],
            $row['record_id']
        ]);
    }
    
    fclose($output);
    exit();
}

// Helper function to get icon for action
function getActionIcon($action) {
    switch ($action) {
        case 'login':
            return 'bi-box-arrow-in-right';
        case 'logout':
            return 'bi-box-arrow-right';
        case 'create':
            return 'bi-plus-circle';
        case 'update':
        case 'edit':
            return 'bi-pencil';
        case 'delete':
            return 'bi-trash';
        case 'view':
            return 'bi-eye';
        case 'activated':
        case 'active':
            return 'bi-check-circle';
        case 'deactivated':
        case 'inactive':
            return 'bi-x-circle';
        case 'close':
            return 'bi-lock';
        case 'bulk_close':
            return 'bi-lock-fill';
        case 'interest_collection':
            return 'bi-percent';
        case 'principal_payment':
            return 'bi-cash';
        default:
            return 'bi-clock-history';
    }
}

// Helper function to get color for action
function getActionColor($action) {
    switch ($action) {
        case 'login':
        case 'logout':
            return 'info';
        case 'create':
            return 'success';
        case 'update':
        case 'edit':
            return 'warning';
        case 'delete':
            return 'danger';
        case 'activated':
        case 'active':
            return 'success';
        case 'deactivated':
        case 'inactive':
            return 'danger';
        case 'close':
        case 'bulk_close':
            return 'secondary';
        case 'interest_collection':
        case 'principal_payment':
            return 'primary';
        default:
            return 'secondary';
    }
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

        .logs-container {
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

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
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

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 8px 10px;
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

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
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

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-title i {
            color: #667eea;
        }

        .chart-container {
            height: 250px;
            position: relative;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-title i {
            color: #667eea;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .summary-table th {
            text-align: left;
            padding: 8px;
            background: #f7fafc;
            color: #4a5568;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        .summary-table td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-table tbody tr:hover {
            background: #f7fafc;
        }

        /* Activity Log Table */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .table-info {
            color: #718096;
            font-size: 13px;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 1000px;
        }

        .activity-table th {
            background: #f7fafc;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .activity-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .activity-table tbody tr:hover {
            background: #f7fafc;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: #2d3748;
        }

        .user-role {
            font-size: 10px;
            color: #718096;
            text-transform: uppercase;
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .action-badge i {
            font-size: 12px;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #feebc8;
            color: #744210;
        }

        .badge-danger {
            background: #fed7d7;
            color: #742a2a;
        }

        .badge-info {
            background: #bee3f8;
            color: #2c5282;
        }

        .badge-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .badge-primary {
            background: #c3dafe;
            color: #434190;
        }

        .table-badge {
            display: inline-block;
            padding: 2px 6px;
            background: #edf2f7;
            border-radius: 4px;
            font-size: 11px;
            color: #4a5568;
            font-family: monospace;
        }

        .timestamp {
            font-size: 12px;
            color: #718096;
        }

        .timestamp i {
            margin-right: 3px;
            font-size: 11px;
        }

        .description {
            max-width: 300px;
            white-space: normal;
            word-wrap: break-word;
            line-height: 1.4;
        }

        .record-id {
            display: inline-block;
            padding: 2px 6px;
            background: #667eea20;
            border-radius: 4px;
            font-size: 11px;
            color: #667eea;
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .page-link {
            display: block;
            padding: 6px 10px;
            border-radius: 6px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: #f7fafc;
            border-color: #667eea;
        }

        .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .page-link.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
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
            margin-bottom: 15px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .export-buttons {
                flex-direction: column;
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
                <div class="logs-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-clock-history"></i>
                            Activity Logs
                            <span class="badge-count"><?php echo number_format($total_records); ?> Total</span>
                        </h1>
                        <div class="header-actions">
                            <div class="export-buttons">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success btn-sm">
                                    <i class="bi bi-file-earmark-excel"></i> Export CSV
                                </a>
                                <button class="btn btn-primary btn-sm" onclick="window.print()">
                                    <i class="bi bi-printer"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Display Error Message -->
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i> Filter Activity Logs
                        </div>
                        
                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Date From</label>
                                    <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Date To</label>
                                    <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">User</label>
                                    <select class="form-select" name="user_id">
                                        <option value="0">All Users</option>
                                        <?php foreach ($users as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" <?php echo $user_id == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($user['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Action</label>
                                    <select class="form-select" name="action">
                                        <option value="">All Actions</option>
                                        <?php foreach ($actions as $act): ?>
                                        <option value="<?php echo $act; ?>" <?php echo $action == $act ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($act); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Table</label>
                                    <select class="form-select" name="table_name">
                                        <option value="">All Tables</option>
                                        <?php foreach ($tables as $table): ?>
                                        <option value="<?php echo $table; ?>" <?php echo $table_name == $table ? 'selected' : ''; ?>>
                                            <?php echo $table; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Search</label>
                                    <input type="text" class="form-control" name="search" placeholder="Search description..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Per Page</label>
                                    <select class="form-select" name="limit">
                                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
                                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200</option>
                                        <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500</option>
                                        <option value="1000" <?php echo $limit == 1000 ? 'selected' : ''; ?>>1000</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="activity-logs.php" class="btn btn-secondary btn-sm">
                                    <i class="bi bi-arrow-counterclockwise"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                            <div class="stat-value"><?php echo number_format($stats['today_logs']); ?></div>
                            <div class="stat-label">Today's Logs</div>
                            <div class="stat-sub"><?php echo number_format($stats['week_logs']); ?> this week</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-people"></i></div>
                            <div class="stat-value"><?php echo $stats['unique_users']; ?></div>
                            <div class="stat-label">Active Users</div>
                            <div class="stat-sub">In selected period</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-list-check"></i></div>
                            <div class="stat-value"><?php echo $stats['unique_actions']; ?></div>
                            <div class="stat-label">Action Types</div>
                            <div class="stat-sub">Different actions</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon"><i class="bi bi-table"></i></div>
                            <div class="stat-value"><?php echo $stats['unique_tables']; ?></div>
                            <div class="stat-label">Tables Affected</div>
                            <div class="stat-sub">Database tables</div>
                        </div>
                    </div>

                    <!-- Charts -->
                    <?php if (!empty($daily_labels) && !empty($daily_data)): ?>
                    <div class="charts-grid">
                        <div class="chart-card">
                            <div class="chart-header">
                                <div class="chart-title">
                                    <i class="bi bi-bar-chart"></i>
                                    Daily Activity (Last 30 Days)
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="dailyChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <div class="chart-title">
                                    <i class="bi bi-pie-chart"></i>
                                    Actions Breakdown
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="actionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Action Summary -->
                    <?php if (!empty($action_stats)): ?>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="summary-header">
                                <div class="summary-title">
                                    <i class="bi bi-tags"></i>
                                    Top Actions
                                </div>
                            </div>
                            <table class="summary-table">
                                <thead>
                                    <tr>
                                        <th>Action</th>
                                        <th>Count</th>
                                        <th>Users</th>
                                        <th>Last Occurrence</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($action_stats as $stat): ?>
                                    <tr>
                                        <td>
                                            <span class="action-badge badge-<?php echo getActionColor($stat['action']); ?>">
                                                <i class="bi <?php echo getActionIcon($stat['action']); ?>"></i>
                                                <?php echo ucfirst($stat['action']); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo number_format($stat['count']); ?></strong></td>
                                        <td><?php echo $stat['users']; ?></td>
                                        <td class="timestamp"><?php echo date('d-m-Y H:i', strtotime($stat['last_occurrence'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="summary-card">
                            <div class="summary-header">
                                <div class="summary-title">
                                    <i class="bi bi-person-badge"></i>
                                    Most Active Users
                                </div>
                            </div>
                            <table class="summary-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Activities</th>
                                        <th>Last Active</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($user_stats as $user): ?>
                                    <tr>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                                    <span class="user-role">@<?php echo $user['username']; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="badge badge-info"><?php echo ucfirst($user['role']); ?></span></td>
                                        <td><strong><?php echo number_format($user['activity_count']); ?></strong></td>
                                        <td class="timestamp">
                                            <?php echo $user['last_activity'] ? date('d-m-Y H:i', strtotime($user['last_activity'])) : 'Never'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Activity Logs Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">Activity Log Details</span>
                            <span class="table-info">
                                Showing <?php echo count($logs); ?> of <?php echo number_format($total_records); ?> records
                            </span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="activity-table">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>Table</th>
                                        <th>Record ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($logs)): ?>
                                        <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td class="timestamp">
                                                <i class="bi bi-clock"></i>
                                                <?php echo date('d-m-Y H:i:s', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td>
                                                <div class="user-info">
                                                    <div class="user-avatar">
                                                        <?php echo strtoupper(substr($log['user_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="user-details">
                                                        <span class="user-name"><?php echo htmlspecialchars($log['user_name']); ?></span>
                                                        <span class="user-role"><?php echo ucfirst($log['user_role']); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="action-badge badge-<?php echo getActionColor($log['action']); ?>">
                                                    <i class="bi <?php echo getActionIcon($log['action']); ?>"></i>
                                                    <?php echo ucfirst($log['action']); ?>
                                                </span>
                                            </td>
                                            <td class="description"><?php echo htmlspecialchars($log['description']); ?></td>
                                            <td>
                                                <?php if ($log['table_name']): ?>
                                                <span class="table-badge"><?php echo $log['table_name']; ?></span>
                                                <?php else: ?>
                                                <span class="table-badge">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['record_id']): ?>
                                                <span class="record-id">#<?php echo $log['record_id']; ?></span>
                                                <?php else: ?>
                                                -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="empty-state">
                                                <i class="bi bi-clock-history"></i>
                                                <p>No activity logs found</p>
                                                <?php if (!empty($search) || !empty($action) || !empty($table_name) || $user_id > 0): ?>
                                                <a href="activity-logs.php" class="btn btn-secondary btn-sm">Clear Filters</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($current_page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => 0])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-left"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => max(0, ($current_page - 2) * $limit)])); ?>" class="page-link">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                                $page_offset = ($i - 1) * $limit;
                                $params = array_merge($_GET, ['offset' => $page_offset]);
                            ?>
                            <a href="?<?php echo http_build_query($params); ?>" 
                               class="page-link <?php echo $i == $current_page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>

                            <?php if ($current_page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => $current_page * $limit])); ?>" class="page-link">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['offset' => ($total_pages - 1) * $limit])); ?>" class="page-link">
                                <i class="bi bi-chevron-double-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Info Box -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>First Activity:</strong> <?php echo $stats['first_activity'] ? date('d-m-Y H:i:s', strtotime($stats['first_activity'])) : 'N/A'; ?> |
                        <strong>Last Activity:</strong> <?php echo $stats['last_activity'] ? date('d-m-Y H:i:s', strtotime($stats['last_activity'])) : 'N/A'; ?>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Daily Activity Chart
        <?php if (!empty($daily_labels) && !empty($daily_data)): ?>
        new Chart(document.getElementById('dailyChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($daily_labels); ?>,
                datasets: [{
                    label: 'Activities',
                    data: <?php echo json_encode($daily_data); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Action Breakdown Chart
        <?php if (!empty($action_stats)): 
            $action_labels = [];
            $action_counts = [];
            foreach ($action_stats as $stat):
                $action_labels[] = ucfirst($stat['action']);
                $action_counts[] = $stat['count'];
            endforeach;
        ?>
        new Chart(document.getElementById('actionChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($action_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($action_counts); ?>,
                    backgroundColor: [
                        '#667eea', '#48bb78', '#f56565', '#ecc94b', '#9f7aea',
                        '#4299e1', '#ed8936', '#fbbf24', '#f687b3', '#a0aec0'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: { size: 10 }
                        }
                    }
                },
                cutout: '60%'
            }
        });
        <?php endif; ?>

        // Auto-submit form when limit changes
        document.querySelector('select[name="limit"]')?.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.display = 'none';
            });
        }, 5000);
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>