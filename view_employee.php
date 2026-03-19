<?php
session_start();
$currentPage = 'view-employee';
$pageTitle = 'View Employee';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view employee details
checkRoleAccess(['admin']);

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: employees.php');
    exit();
}

$employee_id = intval($_GET['id']);

// Get employee details with branch information
$query = "SELECT u.*, b.branch_name, b.branch_code 
          FROM users u 
          LEFT JOIN branches b ON u.branch_id = b.id 
          WHERE u.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $employee_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 0) {
    header('Location: employees.php');
    exit();
}

$employee = mysqli_fetch_assoc($result);

// Get employee statistics
$stats_query = "SELECT 
                (SELECT COUNT(*) FROM loans WHERE employee_id = ?) as total_loans,
                (SELECT COUNT(*) FROM loans WHERE employee_id = ? AND status = 'open') as open_loans,
                (SELECT COUNT(*) FROM loans WHERE employee_id = ? AND status = 'closed') as closed_loans,
                (SELECT COALESCE(SUM(loan_amount), 0) FROM loans WHERE employee_id = ?) as total_loan_amount,
                (SELECT COUNT(*) FROM payments WHERE employee_id = ?) as total_payments,
                (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE employee_id = ?) as total_collection,
                (SELECT COUNT(*) FROM activity_log WHERE user_id = ?) as total_activities";
$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, 'iiiiiii', $employee_id, $employee_id, $employee_id, $employee_id, $employee_id, $employee_id, $employee_id);
mysqli_stmt_execute($stats_stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt));

// Get recent activities
$activities_query = "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$activities_stmt = mysqli_prepare($conn, $activities_query);
mysqli_stmt_bind_param($activities_stmt, 'i', $employee_id);
mysqli_stmt_execute($activities_stmt);
$activities_result = mysqli_stmt_get_result($activities_stmt);

// Get recent loans
$loans_query = "SELECT l.*, c.customer_name 
                FROM loans l 
                LEFT JOIN customers c ON l.customer_id = c.id 
                WHERE l.employee_id = ? 
                ORDER BY l.created_at DESC LIMIT 5";
$loans_stmt = mysqli_prepare($conn, $loans_query);
mysqli_stmt_bind_param($loans_stmt, 'i', $employee_id);
mysqli_stmt_execute($loans_stmt);
$loans_result = mysqli_stmt_get_result($loans_stmt);

// Get emergency contact
$emergency_contact = $employee['emergency_contact'] ?? '';
$emergency_name = $employee['emergency_contact_name'] ?? '';
$emergency_relation = $employee['emergency_relation'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #48bb78;
            --success-dark: #38a169;
            --danger: #f56565;
            --danger-dark: #c53030;
            --warning: #ecc94b;
            --warning-dark: #d69e2e;
            --info: #4299e1;
            --info-dark: #3182ce;
            --dark: #2d3748;
            --light: #f7fafc;
            --gray: #a0aec0;
            --gray-dark: #718096;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
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

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #d69e2e 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(236, 201, 75, 0.4);
        }

        .btn-outline-secondary {
            background: white;
            border: 2px solid var(--gray);
            color: var(--gray-dark);
        }

        .btn-outline-secondary:hover {
            background: var(--gray);
            color: white;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Profile Header */
        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 300px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            border-radius: 50%;
            transform: translate(100px, -100px);
        }

        .profile-avatar-large {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 3.5rem;
            margin: 0 auto 15px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .profile-avatar-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            margin: 0 auto 15px;
        }

        .profile-name {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .profile-role {
            font-size: 1.1rem;
            color: var(--gray-dark);
            margin-bottom: 15px;
        }

        .profile-badges {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .role-badge-large {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .role-badge-large.admin {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .role-badge-large.manager {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }

        .role-badge-large.sale {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .role-badge-large.accountant {
            background: rgba(236, 201, 75, 0.1);
            color: var(--warning-dark);
        }

        .status-badge-large {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .status-badge-large.active {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .status-badge-large.inactive {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }

        .branch-badge-large {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            background: rgba(66, 153, 225, 0.1);
            color: var(--info);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        /* Info Row */
        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #e2e8f0;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            width: 140px;
            font-weight: 600;
            color: var(--gray-dark);
            font-size: 0.95rem;
        }

        .info-value {
            flex: 1;
            color: var(--dark);
            font-weight: 500;
        }

        .info-value a {
            color: var(--primary);
            text-decoration: none;
        }

        .info-value a:hover {
            text-decoration: underline;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            text-align: center;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: -10px;
            right: -10px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 50%;
        }

        .stat-icon {
            font-size: 2.2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray-dark);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Info Cards */
        .info-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
            height: 100%;
            transition: all 0.3s;
        }

        .info-card:hover {
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.1);
        }

        .info-card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card-title i {
            font-size: 1.4rem;
            color: var(--primary);
        }

        /* Document Card */
        .document-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .document-card:hover {
            background: #edf2f7;
            transform: translateX(5px);
        }

        .document-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .document-info {
            flex: 1;
        }

        .document-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
            font-size: 1rem;
        }

        .document-meta {
            font-size: 0.85rem;
            color: var(--gray-dark);
        }

        .document-actions {
            display: flex;
            gap: 8px;
        }

        .btn-doc {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .btn-view-doc {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .btn-view-doc:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        .btn-download-doc {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .btn-download-doc:hover {
            background: var(--success);
            color: white;
            transform: translateY(-3px);
        }

        /* Activity Items */
        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.3s;
        }

        .activity-item:hover {
            background: #f8fafc;
            padding-left: 10px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.4rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
            font-size: 1rem;
        }

        .activity-meta {
            font-size: 0.85rem;
            color: var(--gray-dark);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        /* Badges */
        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-open {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .badge-closed {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .badge-auctioned {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }

        .badge-defaulted {
            background: rgba(236, 201, 75, 0.1);
            color: var(--warning-dark);
        }

        /* Role Badges */
        .role-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-badge.admin {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        .role-badge.manager {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }

        .role-badge.sale {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .role-badge.accountant {
            background: rgba(236, 201, 75, 0.1);
            color: var(--warning-dark);
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge.active {
            background: rgba(72, 187, 120, 0.1);
            color: var(--success);
        }

        .status-badge.inactive {
            background: rgba(245, 101, 101, 0.1);
            color: var(--danger);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn-action {
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--warning) 0%, #d69e2e 100%);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(236, 201, 75, 0.4);
        }

        .btn-back {
            background: linear-gradient(135deg, var(--gray) 0%, #718096 100%);
            color: white;
        }

        .btn-back:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(160, 174, 192, 0.4);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger) 0%, #c53030 100%);
            color: white;
        }

        .btn-delete:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(245, 101, 101, 0.4);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-dark);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #cbd5e0;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 15px;
            color: #4a5568;
        }

        /* Grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -12px;
        }

        .col-md-6 {
            width: 50%;
            padding: 0 12px;
        }

        .col-12 {
            width: 100%;
            padding: 0 12px;
        }

        .mt-3 {
            margin-top: 20px;
        }

        .mt-4 {
            margin-top: 25px;
        }

        .me-2 {
            margin-right: 8px;
        }

        .ms-2 {
            margin-left: 8px;
        }

        .ms-3 {
            margin-left: 15px;
        }

        .mb-3 {
            margin-bottom: 15px;
        }

        .py-4 {
            padding-top: 25px;
            padding-bottom: 25px;
        }

        .text-center {
            text-align: center;
        }

        .text-muted {
            color: var(--gray-dark);
        }

        .text-primary {
            color: var(--primary);
        }

        .text-success {
            color: var(--success);
        }

        .text-warning {
            color: var(--warning);
        }

        .text-danger {
            color: var(--danger);
        }

        .text-info {
            color: var(--info);
        }

        .fw-bold {
            font-weight: 700;
        }

        .d-block {
            display: block;
        }

        .d-flex {
            display: flex;
        }

        .align-items-center {
            align-items: center;
        }

        .justify-content-between {
            justify-content: space-between;
        }

        .gap-4 {
            gap: 20px;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-content {
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .profile-header {
                padding: 20px;
            }

            .profile-name {
                font-size: 1.8rem;
            }

            .profile-avatar-large,
            .profile-avatar-img {
                width: 120px;
                height: 120px;
                font-size: 2.8rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .info-row {
                flex-direction: column;
            }

            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }

            .col-md-6 {
                width: 100%;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .profile-badges {
                flex-direction: column;
                align-items: center;
            }

            .profile-badges span {
                width: 100%;
                text-align: center;
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
                <!-- Page Header -->
                <div class="page-header">
                    <h4 class="page-title">
                        <i class="bi bi-person-badge"></i>
                        Employee Details
                    </h4>
                    <div>
                        <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-warning me-2">
                            <i class="bi bi-pencil-square"></i> Edit Employee
                        </a>
                        <a href="employees.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <?php 
                            $initials = '';
                            $name_parts = explode(' ', trim($employee['name']));
                            foreach ($name_parts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                            }
                            if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
                            
                            // Check if employee has photo
                            $has_photo = !empty($employee['employee_photo']) && file_exists($employee['employee_photo']);
                            ?>
                            
                            <?php if ($has_photo): ?>
                                <img src="<?php echo htmlspecialchars($employee['employee_photo']); ?>" alt="<?php echo htmlspecialchars($employee['name']); ?>" class="profile-avatar-img">
                            <?php else: ?>
                                <div class="profile-avatar-large"><?php echo $initials; ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-9">
                            <div class="profile-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                            <div class="profile-role"><?php echo htmlspecialchars($employee['username']); ?> • ID: #<?php echo $employee['id']; ?></div>
                            <div class="profile-badges">
                                <span class="role-badge-large <?php echo $employee['role']; ?>">
                                    <i class="bi bi-shield-check"></i> <?php echo ucfirst($employee['role']); ?>
                                </span>
                                <span class="status-badge-large <?php echo ($employee['status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>">
                                    <i class="bi bi-circle-fill"></i> <?php echo ucfirst($employee['status'] ?? 'active'); ?>
                                </span>
                                <?php if (!empty($employee['branch_name'])): ?>
                                    <span class="branch-badge-large">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($employee['branch_name']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Employee ID:</div>
                                        <div class="info-value"><strong>#<?php echo $employee['id']; ?></strong></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Mobile:</div>
                                        <div class="info-value">
                                            <?php if (!empty($employee['mobile'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($employee['mobile']); ?>">
                                                    <i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($employee['mobile']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Email:</div>
                                        <div class="info-value">
                                            <?php if (!empty($employee['email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>">
                                                    <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($employee['email']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Not provided</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Joined Date:</div>
                                        <div class="info-value">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?php echo $employee['created_at'] ? date('d M Y', strtotime($employee['created_at'])) : 'N/A'; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Last Updated:</div>
                                        <div class="info-value">
                                            <i class="bi bi-clock-history me-1"></i>
                                            <?php echo $employee['updated_at'] ? date('d M Y h:i A', strtotime($employee['updated_at'])) : 'N/A'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-file-text"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_loans'] ?? 0; ?></div>
                        <div class="stat-label">Total Loans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-warning">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['open_loans'] ?? 0; ?></div>
                        <div class="stat-label">Open Loans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['closed_loans'] ?? 0; ?></div>
                        <div class="stat-label">Closed Loans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-info">
                            <i class="bi bi-currency-rupee"></i>
                        </div>
                        <div class="stat-value">₹<?php echo number_format($stats['total_loan_amount'] ?? 0); ?></div>
                        <div class="stat-label">Loan Amount</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-success">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_payments'] ?? 0; ?></div>
                        <div class="stat-label">Payments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon text-primary">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-value">₹<?php echo number_format($stats['total_collection'] ?? 0); ?></div>
                        <div class="stat-label">Collection</div>
                    </div>
                </div>

                <div class="row">
                    <!-- Personal Information -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-person-circle"></i>
                                <span>Personal Information</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Full Name:</div>
                                <div class="info-value fw-bold"><?php echo htmlspecialchars($employee['name']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Username:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['username']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Role:</div>
                                <div class="info-value">
                                    <span class="role-badge <?php echo $employee['role']; ?>">
                                        <?php echo ucfirst($employee['role']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($employee['status'] ?? 'active') === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($employee['status'] ?? 'active'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Date of Birth:</div>
                                <div class="info-value">
                                    <?php echo !empty($employee['date_of_birth']) ? date('d M Y', strtotime($employee['date_of_birth'])) : 'Not provided'; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Gender:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['gender'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Marital Status:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['marital_status'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Blood Group:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['blood_group'] ?? 'Not provided'); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-telephone"></i>
                                <span>Contact Information</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Mobile Number:</div>
                                <div class="info-value">
                                    <?php if (!empty($employee['mobile'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($employee['mobile']); ?>">
                                            <i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($employee['mobile']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Email Address:</div>
                                <div class="info-value">
                                    <?php if (!empty($employee['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($employee['email']); ?>">
                                            <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($employee['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($emergency_contact) || !empty($emergency_name)): ?>
                            <div class="info-card-title mt-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                <span>Emergency Contact</span>
                            </div>
                            <?php if (!empty($emergency_name)): ?>
                            <div class="info-row">
                                <div class="info-label">Contact Person:</div>
                                <div class="info-value"><?php echo htmlspecialchars($emergency_name); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($emergency_relation)): ?>
                            <div class="info-row">
                                <div class="info-label">Relation:</div>
                                <div class="info-value"><?php echo htmlspecialchars($emergency_relation); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($emergency_contact)): ?>
                            <div class="info-row">
                                <div class="info-label">Emergency Number:</div>
                                <div class="info-value">
                                    <a href="tel:<?php echo htmlspecialchars($emergency_contact); ?>">
                                        <i class="bi bi-telephone-forward me-1"></i><?php echo htmlspecialchars($emergency_contact); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Address Information -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-house-door"></i>
                                <span>Address Information</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Current Address:</div>
                                <div class="info-value">
                                    <?php 
                                    $current_address = [];
                                    if (!empty($employee['address_line1'])) $current_address[] = $employee['address_line1'];
                                    if (!empty($employee['address_line2'])) $current_address[] = $employee['address_line2'];
                                    if (!empty($employee['city'])) $current_address[] = $employee['city'];
                                    if (!empty($employee['state'])) $current_address[] = $employee['state'];
                                    if (!empty($employee['pincode'])) $current_address[] = $employee['pincode'];
                                    
                                    if (!empty($current_address)) {
                                        echo nl2br(htmlspecialchars(implode("\n", $current_address)));
                                    } else {
                                        echo '<span class="text-muted">Not provided</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($employee['formatted_address'])): ?>
                            <div class="info-row">
                                <div class="info-label">Full Address:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['formatted_address']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['latitude']) && !empty($employee['longitude'])): ?>
                            <div class="info-row">
                                <div class="info-label">Coordinates:</div>
                                <div class="info-value">
                                    <a href="https://www.google.com/maps?q=<?php echo $employee['latitude']; ?>,<?php echo $employee['longitude']; ?>" target="_blank">
                                        <i class="bi bi-geo-alt me-1"></i><?php echo $employee['latitude']; ?>, <?php echo $employee['longitude']; ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($employee['permanent_address_same'] != 1): ?>
                            <div class="info-card-title mt-3">
                                <i class="bi bi-house-gear"></i>
                                <span>Permanent Address</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Permanent Address:</div>
                                <div class="info-value">
                                    <?php 
                                    $permanent_address = [];
                                    if (!empty($employee['permanent_address_line1'])) $permanent_address[] = $employee['permanent_address_line1'];
                                    if (!empty($employee['permanent_address_line2'])) $permanent_address[] = $employee['permanent_address_line2'];
                                    if (!empty($employee['permanent_city'])) $permanent_address[] = $employee['permanent_city'];
                                    if (!empty($employee['permanent_state'])) $permanent_address[] = $employee['permanent_state'];
                                    if (!empty($employee['permanent_pincode'])) $permanent_address[] = $employee['permanent_pincode'];
                                    
                                    if (!empty($permanent_address)) {
                                        echo nl2br(htmlspecialchars(implode("\n", $permanent_address)));
                                    } else {
                                        echo '<span class="text-muted">Same as current address</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Employment Information -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-briefcase"></i>
                                <span>Employment Information</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Employee ID:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['employee_id'] ?? 'Not assigned'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Department:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['department'] ?? 'Not assigned'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Designation:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['designation'] ?? 'Not assigned'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Branch:</div>
                                <div class="info-value">
                                    <?php if (!empty($employee['branch_name'])): ?>
                                        <strong><?php echo htmlspecialchars($employee['branch_name']); ?></strong>
                                        <?php if (!empty($employee['branch_code'])): ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($employee['branch_code']); ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not Assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Joining Date:</div>
                                <div class="info-value">
                                    <?php echo !empty($employee['joining_date']) ? date('d M Y', strtotime($employee['joining_date'])) : 'Not provided'; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Confirmation Date:</div>
                                <div class="info-value">
                                    <?php echo !empty($employee['confirmation_date']) ? date('d M Y', strtotime($employee['confirmation_date'])) : 'Not provided'; ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Employment Type:</div>
                                <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? 'full_time')); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Reporting Manager:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['reporting_manager'] ?? 'Not assigned'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Work Location:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['work_location'] ?? 'Not specified'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Shift Timing:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['shift_timing'] ?? 'Not specified'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Weekly Off:</div>
                                <div class="info-value"><?php echo ucfirst($employee['weekly_off'] ?? 'Sunday'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Education & Experience -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-mortarboard"></i>
                                <span>Education & Experience</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Highest Qualification:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['highest_qualification'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">University:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['university'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Year of Passing:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['year_of_passing'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Percentage/CGPA:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['percentage'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Total Experience:</div>
                                <div class="info-value">
                                    <?php 
                                    $exp_years = $employee['total_experience_years'] ?? 0;
                                    $exp_months = $employee['total_experience_months'] ?? 0;
                                    if ($exp_years > 0 || $exp_months > 0) {
                                        echo $exp_years . ' years ' . $exp_months . ' months';
                                    } else {
                                        echo 'Fresher';
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php if (!empty($employee['previous_company'])): ?>
                            <div class="info-row">
                                <div class="info-label">Previous Company:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['previous_company']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Previous Designation:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['previous_designation']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Previous Experience:</div>
                                <div class="info-value">
                                    <?php 
                                    $prev_years = $employee['previous_experience_years'] ?? 0;
                                    $prev_months = $employee['previous_experience_months'] ?? 0;
                                    echo $prev_years . ' years ' . $prev_months . ' months';
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['skills'])): ?>
                            <div class="info-row">
                                <div class="info-label">Skills:</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['skills'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['certifications'])): ?>
                            <div class="info-row">
                                <div class="info-label">Certifications:</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($employee['certifications'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bank & Statutory Details -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-bank"></i>
                                <span>Bank Details</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Account Holder:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['account_holder_name'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Bank Name:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['bank_name'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Branch:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['branch_name'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Account Number:</div>
                                <div class="info-value">
                                    <?php 
                                    if (!empty($employee['account_number'])) {
                                        $acc_num = $employee['account_number'];
                                        echo 'XXXX' . substr($acc_num, -4);
                                    } else {
                                        echo 'Not provided';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">IFSC Code:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['ifsc_code'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">MICR Code:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['micr_code'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Account Type:</div>
                                <div class="info-value"><?php echo ucfirst($employee['account_type'] ?? 'savings'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">UPI ID:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['upi_id'] ?? 'Not provided'); ?></div>
                            </div>
                            
                            <div class="info-card-title mt-3">
                                <i class="bi bi-file-earmark-text"></i>
                                <span>Statutory Details</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">PAN Number:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['pan_number'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Aadhar Number:</div>
                                <div class="info-value">
                                    <?php 
                                    if (!empty($employee['aadhar_number'])) {
                                        $aadhar = $employee['aadhar_number'];
                                        echo 'XXXX-XXXX-' . substr($aadhar, -4);
                                    } else {
                                        echo 'Not provided';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">PF Number:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['pf_number'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">ESI Number:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['esi_number'] ?? 'Not provided'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">UAN Number:</div>
                                <div class="info-value"><?php echo htmlspecialchars($employee['uan_number'] ?? 'Not provided'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Salary Details -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-cash-stack"></i>
                                <span>Salary Details</span>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Basic Salary:</div>
                                <div class="info-value">₹<?php echo number_format($employee['basic_salary'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">HRA:</div>
                                <div class="info-value">₹<?php echo number_format($employee['hra'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Conveyance:</div>
                                <div class="info-value">₹<?php echo number_format($employee['conveyance'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Medical Allowance:</div>
                                <div class="info-value">₹<?php echo number_format($employee['medical_allowance'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Special Allowance:</div>
                                <div class="info-value">₹<?php echo number_format($employee['special_allowance'] ?? 0, 2); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Bonus:</div>
                                <div class="info-value">₹<?php echo number_format($employee['bonus'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-file-earmark-text"></i>
                                <span>Employee Documents</span>
                            </div>
                            
                            <?php if ($has_photo): ?>
                            <div class="document-card">
                                <div class="document-icon">
                                    <i class="bi bi-camera-fill"></i>
                                </div>
                                <div class="document-info">
                                    <div class="document-name">Employee Photo</div>
                                    <div class="document-meta">
                                        <?php 
                                        $photo_path = $employee['employee_photo'];
                                        $photo_size = file_exists($photo_path) ? filesize($photo_path) : 0;
                                        $photo_size_formatted = $photo_size > 0 ? round($photo_size / 1024, 2) . ' KB' : 'Unknown';
                                        ?>
                                        <i class="bi bi-file-earmark"></i> <?php echo basename($photo_path); ?> 
                                        <i class="bi bi-hdd-stack ms-2"></i> <?php echo $photo_size_formatted; ?>
                                    </div>
                                </div>
                                <div class="document-actions">
                                    <a href="<?php echo htmlspecialchars($employee['employee_photo']); ?>" target="_blank" class="btn-doc btn-view-doc" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($employee['employee_photo']); ?>" download class="btn-doc btn-download-doc" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($employee['certificate_path']) && file_exists($employee['certificate_path'])): ?>
                            <div class="document-card">
                                <div class="document-icon">
                                    <i class="bi bi-file-pdf"></i>
                                </div>
                                <div class="document-info">
                                    <div class="document-name">Certificate / Document</div>
                                    <div class="document-meta">
                                        <?php 
                                        $cert_path = $employee['certificate_path'];
                                        $cert_size = file_exists($cert_path) ? filesize($cert_path) : 0;
                                        $cert_size_formatted = $cert_size > 0 ? round($cert_size / 1024, 2) . ' KB' : 'Unknown';
                                        $ext = strtoupper(pathinfo($cert_path, PATHINFO_EXTENSION));
                                        ?>
                                        <i class="bi bi-file-earmark"></i> <?php echo basename($cert_path); ?> 
                                        <i class="bi bi-hdd-stack ms-2"></i> <?php echo $cert_size_formatted; ?>
                                    </div>
                                </div>
                                <div class="document-actions">
                                    <a href="<?php echo htmlspecialchars($cert_path); ?>" target="_blank" class="btn-doc btn-view-doc" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($cert_path); ?>" download class="btn-doc btn-download-doc" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!$has_photo && empty($employee['certificate_path'])): ?>
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-x"></i>
                                <p>No documents uploaded for this employee.</p>
                                <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="bi bi-upload me-2"></i>Upload Documents
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Loans -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-file-text"></i>
                                <span>Recent Loans</span>
                                <a href="loans.php?employee=<?php echo $employee['id']; ?>" class="btn btn-sm btn-primary ms-auto">
                                    <i class="bi bi-eye"></i> View All
                                </a>
                            </div>
                            
                            <?php if (mysqli_num_rows($loans_result) > 0): ?>
                                <?php while ($loan = mysqli_fetch_assoc($loans_result)): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="bi bi-file-text"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title d-flex align-items-center">
                                                <a href="view_loan.php?id=<?php echo $loan['id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($loan['receipt_number']); ?>
                                                </a>
                                                <span class="badge-status badge-<?php echo $loan['status']; ?> ms-2">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                            </div>
                                            <div class="activity-meta">
                                                <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($loan['customer_name'] ?? 'N/A'); ?></span>
                                                <span><i class="bi bi-currency-rupee"></i> <?php echo number_format($loan['loan_amount'], 2); ?></span>
                                                <span><i class="bi bi-calendar"></i> <?php echo date('d-m-Y', strtotime($loan['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-file-text"></i>
                                    <p>No loans found for this employee.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activities -->
                    <div class="col-md-6">
                        <div class="info-card">
                            <div class="info-card-title">
                                <i class="bi bi-activity"></i>
                                <span>Recent Activities</span>
                            </div>
                            
                            <?php if (mysqli_num_rows($activities_result) > 0): ?>
                                <?php while ($activity = mysqli_fetch_assoc($activities_result)): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="bi bi-<?php echo strpos($activity['action'], 'login') !== false ? 'box-arrow-in-right' : 'info-circle'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-title">
                                                <?php echo ucfirst(htmlspecialchars($activity['action'])); ?>
                                            </div>
                                            <div class="activity-meta">
                                                <span><?php echo htmlspecialchars($activity['description']); ?></span>
                                                <span><i class="bi bi-clock"></i> <?php echo date('d-m-Y h:i A', strtotime($activity['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-activity"></i>
                                    <p>No activities found for this employee.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="action-buttons">
                            <a href="edit_employee.php?id=<?php echo $employee['id']; ?>" class="btn-action btn-edit">
                                <i class="bi bi-pencil-square"></i> Edit Employee
                            </a>
                            <?php if ($employee['id'] != $_SESSION['user_id']): ?>
                                <a href="#" class="btn-action btn-delete" onclick="confirmDelete(<?php echo $employee['id']; ?>, '<?php echo addslashes($employee['name']); ?>')">
                                    <i class="bi bi-trash"></i> Delete Employee
                                </a>
                            <?php endif; ?>
                            <a href="employees.php" class="btn-action btn-back">
                                <i class="bi bi-arrow-left"></i> Back to List
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- jQuery and DataTables -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                if (alert) alert.style.display = 'none';
            });
        }, 5000);

        // Confirm delete with SweetAlert
        function confirmDelete(id, name) {
            Swal.fire({
                title: 'Delete Employee?',
                html: `Are you sure you want to delete <strong>${name}</strong>?<br><br>This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#718096',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `employees.php?action=delete&id=${id}`;
                }
            });
            return false;
        }

        // Copy to clipboard function
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                Swal.fire({
                    icon: 'success',
                    title: 'Copied!',
                    text: 'Copied to clipboard',
                    timer: 1500,
                    showConfirmButton: false
                });
            }, function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Could not copy to clipboard',
                    timer: 1500,
                    showConfirmButton: false
                });
            });
        }

        // Add copy buttons to info values
        document.querySelectorAll('.info-value').forEach(function(element) {
            if (element.innerText.trim() && !element.querySelector('a')) {
                const text = element.innerText.trim();
                if (text !== 'Not provided' && text !== 'Not assigned' && text !== 'N/A') {
                    element.style.cursor = 'pointer';
                    element.addEventListener('click', function() {
                        copyToClipboard(this.innerText);
                    });
                    element.title = 'Click to copy';
                }
            }
        });

        // Print function
        function printPage() {
            window.print();
        }

        // Export as PDF function (placeholder)
        function exportAsPDF() {
            Swal.fire({
                title: 'Export as PDF',
                text: 'This feature will be available soon!',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>