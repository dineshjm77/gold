<?php
session_start();
$currentPage = 'view-branch';
$pageTitle = 'View Branch';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view branch details
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Get branch ID from URL
$branch_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($branch_id <= 0) {
    header('Location: manage_branches.php');
    exit();
}

// Get branch details
$branch_query = "SELECT b.*, 
                (SELECT COUNT(*) FROM users WHERE branch_id = b.id) as user_count
                FROM branches b WHERE b.id = ?";
$stmt = mysqli_prepare($conn, $branch_query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$branch_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($branch_result) == 0) {
    header('Location: manage_branches.php');
    exit();
}

$branch = mysqli_fetch_assoc($branch_result);

// Get users assigned to this branch
$users_query = "SELECT id, name, username, role, status, mobile, email 
                FROM users WHERE branch_id = ? ORDER BY name";
$stmt = mysqli_prepare($conn, $users_query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$users_result = mysqli_stmt_get_result($stmt);

// Get recent activity for this branch
$activity_query = "SELECT al.*, u.name as user_name 
                   FROM activity_log al
                   JOIN users u ON al.user_id = u.id
                   WHERE al.table_name = 'branches' AND al.record_id = ?
                   ORDER BY al.created_at DESC LIMIT 10";
$stmt = mysqli_prepare($conn, $activity_query);
mysqli_stmt_bind_param($stmt, 'i', $branch_id);
mysqli_stmt_execute($stmt);
$activity_result = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
            font-family: 'Poppins', sans-serif;
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

        .view-container {
            max-width: 1200px;
            margin: 0 auto;
        }

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
        }

        .btn {
            padding: 12px 24px;
            border-radius: 50px;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-secondary {
            background: white;
            border: 2px solid #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e0;
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #ecc94b 0%, #d69e2e 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .view-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .info-item {
            margin-bottom: 15px;
        }

        .info-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-label i {
            color: #667eea;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .info-value-large {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.active {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .status-badge.inactive {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        .code-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 18px;
            display: inline-block;
        }

        .image-preview {
            max-width: 200px;
            max-height: 100px;
            object-fit: contain;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 5px;
            background: white;
            margin-top: 10px;
        }

        .image-preview.qr-preview {
            max-width: 150px;
            max-height: 150px;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .users-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .users-table tr:hover {
            background: #f7fafc;
        }

        .activity-timeline {
            margin-top: 20px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #667eea;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .activity-meta {
            font-size: 12px;
            color: #718096;
            display: flex;
            gap: 15px;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
            <div class="view-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-building" style="margin-right: 10px;"></i>
                        Branch Details: <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </h1>
                    <div>
                        <a href="edit_branch.php?id=<?php echo $branch_id; ?>" class="btn btn-warning">
                            <i class="bi bi-pencil"></i>
                            Edit Branch
                        </a>
                        <a href="manage_branches.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
                </div>

                <!-- Branch Information -->
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-info-circle"></i>
                        Branch Information
                    </div>

                    <div style="text-align: center; margin-bottom: 30px;">
                        <span class="code-badge"><?php echo htmlspecialchars($branch['branch_code']); ?></span>
                        <span class="status-badge <?php echo $branch['status']; ?>" style="margin-left: 15px;">
                            <i class="bi bi-<?php echo $branch['status'] === 'active' ? 'check-circle' : 'x-circle'; ?>"></i>
                            <?php echo ucfirst($branch['status']); ?>
                        </span>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-building"></i> Branch Name
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['branch_name']); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-hash"></i> Branch Code
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['branch_code']); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-calendar"></i> Created Date
                            </div>
                            <div class="info-value"><?php echo date('d-m-Y H:i', strtotime($branch['created_at'])); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-people"></i> Total Users
                            </div>
                            <div class="info-value"><?php echo $branch['user_count']; ?> users</div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-clock"></i> Opening Time
                            </div>
                            <div class="info-value"><?php echo date('h:i A', strtotime($branch['opening_time'])); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-clock"></i> Closing Time
                            </div>
                            <div class="info-value"><?php echo date('h:i A', strtotime($branch['closing_time'])); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-calendar-week"></i> Weekly Holiday
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['holiday']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-telephone"></i>
                        Contact Information
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-geo-alt"></i> Address
                            </div>
                            <div class="info-value"><?php echo nl2br(htmlspecialchars($branch['address'] ?? 'Not provided')); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-telephone"></i> Phone
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['phone'] ?? 'Not provided'); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-envelope"></i> Email
                            </div>
                            <div class="info-value">
                                <?php if (!empty($branch['email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($branch['email']); ?>"><?php echo htmlspecialchars($branch['email']); ?></a>
                                <?php else: ?>
                                    Not provided
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-globe"></i> Website
                            </div>
                            <div class="info-value">
                                <?php if (!empty($branch['website'])): ?>
                                    <a href="<?php echo htmlspecialchars($branch['website']); ?>" target="_blank"><?php echo htmlspecialchars($branch['website']); ?></a>
                                <?php else: ?>
                                    Not provided
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manager Information -->
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-person-badge"></i>
                        Manager Information
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-person"></i> Manager Name
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['manager_name'] ?? 'Not assigned'); ?></div>
                        </div>

                        <div class="info-item">
                            <div class="info-label">
                                <i class="bi bi-phone"></i> Manager Mobile
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($branch['manager_mobile'] ?? 'Not provided'); ?></div>
                        </div>

                        <?php if (!empty($branch['manager_id'])): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-person-vcard"></i> Manager ID
                                </div>
                                <div class="info-value">#<?php echo $branch['manager_id']; ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Company Images -->
                <?php if (!empty($branch['logo_path']) || !empty($branch['qr_path'])): ?>
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-images"></i>
                        Company Images
                    </div>

                    <div style="display: flex; gap: 40px; flex-wrap: wrap;">
                        <?php if (!empty($branch['logo_path'])): ?>
                            <div>
                                <div class="info-label" style="margin-bottom: 10px;">
                                    <i class="bi bi-building"></i> Company Logo
                                </div>
                                <img src="<?php echo htmlspecialchars($branch['logo_path']); ?>" class="image-preview" alt="Company Logo">
                                <br>
                                <a href="<?php echo htmlspecialchars($branch['logo_path']); ?>" target="_blank" class="btn btn-secondary btn-sm" style="margin-top: 10px;">View Full Size</a>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($branch['qr_path'])): ?>
                            <div>
                                <div class="info-label" style="margin-bottom: 10px;">
                                    <i class="bi bi-qr-code"></i> QR Payment Code
                                </div>
                                <img src="<?php echo htmlspecialchars($branch['qr_path']); ?>" class="image-preview qr-preview" alt="QR Code">
                                <br>
                                <a href="<?php echo htmlspecialchars($branch['qr_path']); ?>" target="_blank" class="btn btn-secondary btn-sm" style="margin-top: 10px;">View Full Size</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Users in this Branch -->
                <?php if (mysqli_num_rows($users_result) > 0): ?>
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-people"></i>
                        Users in this Branch (<?php echo mysqli_num_rows($users_result); ?>)
                    </div>

                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Mobile</th>
                                <th>Email</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = mysqli_fetch_assoc($users_result)): ?>
                                <tr>
                                    <td>#<?php echo $user['id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo ucfirst($user['role']); ?></td>
                                    <td><?php echo htmlspecialchars($user['mobile'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $user['status']; ?>" style="font-size: 11px;">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Recent Activity -->
                <?php if (mysqli_num_rows($activity_result) > 0): ?>
                <div class="view-card">
                    <div class="section-title">
                        <i class="bi bi-clock-history"></i>
                        Recent Activity
                    </div>

                    <div class="activity-timeline">
                        <?php while ($activity = mysqli_fetch_assoc($activity_result)): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="bi bi-<?php 
                                        echo $activity['action'] == 'create' ? 'plus-circle' : 
                                            ($activity['action'] == 'update' ? 'pencil' : 
                                            ($activity['action'] == 'delete' ? 'trash' : 'arrow-repeat')); 
                                    ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php echo htmlspecialchars($activity['description']); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($activity['user_name']); ?></span>
                                        <span><i class="bi bi-clock"></i> <?php echo date('d-m-Y H:i', strtotime($activity['created_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<?php include 'includes/scripts.php'; ?>
</body>
</html>