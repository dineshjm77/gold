<?php
session_start();
$currentPage = 'view-customer';
$pageTitle = 'View Customer';
require_once 'includes/db.php';
require_once 'auth_check.php';

checkRoleAccess(['admin', 'sale']);

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: Customer-Details.php');
    exit();
}

$customer_id = intval($_GET['id']);

// Get customer details with bank fields
$query = "SELECT * FROM customers WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, 'i', $customer_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$customer = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
mysqli_free_result($result);

if (!$customer) {
    header('Location: Customer-Details.php');
    exit();
}

// Get customer loans with payment information
$loans_query = "SELECT l.*, 
                (SELECT COUNT(*) FROM loan_items WHERE loan_id = l.id) as item_count,
                (SELECT COALESCE(SUM(total_amount), 0) FROM payments WHERE loan_id = l.id) as total_paid
                FROM loans l 
                WHERE l.customer_id = ? 
                ORDER BY l.created_at DESC";
$loans_stmt = mysqli_prepare($conn, $loans_query);
mysqli_stmt_bind_param($loans_stmt, 'i', $customer_id);
mysqli_stmt_execute($loans_stmt);
$loans_result = mysqli_stmt_get_result($loans_stmt);

// Store loans in array for reuse
$loans = [];
while ($row = mysqli_fetch_assoc($loans_result)) {
    $loans[] = $row;
}
mysqli_stmt_close($loans_stmt);
mysqli_free_result($loans_result);

// Calculate total active loan amount
$active_loans_total = 0;
$active_loans_count = 0;
$closed_loans_count = 0;
$total_loans_amount = 0;

foreach ($loans as $loan) {
    $total_loans_amount += $loan['loan_amount'];
    if ($loan['status'] == 'open') {
        $active_loans_total += $loan['loan_amount'];
        $active_loans_count++;
    } elseif ($loan['status'] == 'closed') {
        $closed_loans_count++;
    }
}

// Get customer statistics
$stats_query = "SELECT 
                COUNT(*) as total_loans,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_loans,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_loans,
                COALESCE(SUM(loan_amount), 0) as total_loan_amount,
                COALESCE(SUM(CASE WHEN status = 'open' THEN loan_amount ELSE 0 END), 0) as outstanding_amount,
                MAX(created_at) as last_loan_date,
                MIN(created_at) as first_loan_date
                FROM loans WHERE customer_id = ?";
$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, 'i', $customer_id);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);
mysqli_stmt_close($stats_stmt);
mysqli_free_result($stats_result);

// Get recent payments
$payments_query = "SELECT p.*, l.receipt_number 
                   FROM payments p
                   JOIN loans l ON p.loan_id = l.id
                   WHERE l.customer_id = ?
                   ORDER BY p.created_at DESC LIMIT 5";
$payments_stmt = mysqli_prepare($conn, $payments_query);
mysqli_stmt_bind_param($payments_stmt, 'i', $customer_id);
mysqli_stmt_execute($payments_stmt);
$payments_result = mysqli_stmt_get_result($payments_stmt);

// Store payments in array
$payments = [];
while ($row = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $row;
}
mysqli_stmt_close($payments_stmt);
mysqli_free_result($payments_result);

// Calculate loan limit usage
$loan_limit = floatval($customer['loan_limit_amount'] ?? 10000000);
$total_active = floatval($active_loans_total);
$remaining_limit = $loan_limit - $total_active;
$limit_percentage = ($loan_limit > 0) ? ($total_active / $loan_limit) * 100 : 0;

// Determine progress bar color
$progress_color = '#48bb78'; // Green
if ($limit_percentage > 90) {
    $progress_color = '#f56565'; // Red
} elseif ($limit_percentage > 70) {
    $progress_color = '#ecc94b'; // Yellow
}

$is_noted = isset($customer['is_noted_person']) && $customer['is_noted_person'] == 1;

// Function to get initials from name
function getInitials($name) {
    $initials = '';
    $name_parts = explode(' ', trim($name));
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    if (strlen($initials) > 2) $initials = substr($initials, 0, 2);
    return $initials;
}

// Check if photo exists
$photo_path = !empty($customer['customer_photo']) && file_exists($customer['customer_photo']) ? $customer['customer_photo'] : null;

// Format Aadhar number
function formatAadhar($aadhar) {
    if (empty($aadhar)) return 'Not provided';
    $aadhar = preg_replace('/[^0-9]/', '', $aadhar);
    if (strlen($aadhar) == 12) {
        return substr($aadhar, 0, 4) . ' ' . substr($aadhar, 4, 4) . ' ' . substr($aadhar, 8, 4);
    }
    return $aadhar;
}

// Mask account number
function maskAccountNumber($account) {
    if (empty($account)) return 'Not provided';
    $len = strlen($account);
    if ($len <= 4) return $account;
    return str_repeat('X', $len - 4) . substr($account, -4);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        :root {
            --primary: #0d6efd;
            --success: #198754;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #0dcaf0;
            --dark: #1e293b;
            --noted: #f59e0b;
        }

        body {
            background: #f8f9fa;
        }

        .page-header {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 25px;
        }

        .page-title {
            color: var(--danger);
            font-weight: 700;
            font-size: 1.8rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin: 0 25px 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            position: relative;
        }

        .profile-header.noted {
            border-left: 5px solid var(--noted);
        }

        .noted-ribbon {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, var(--noted) 0%, #fbbf24 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3);
            z-index: 10;
        }

        .noted-ribbon i {
            font-size: 16px;
        }

        /* Profile Photo Styles */
        .profile-photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 15px;
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            background: #f8f9fa;
        }

        .profile-photo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, #6610f2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 3.5rem;
            margin: 0 auto 15px;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .profile-photo-placeholder.noted {
            background: linear-gradient(135deg, var(--noted) 0%, #fbbf24 100%);
        }

        .profile-photo-edit {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--primary);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            text-decoration: none;
        }

        .profile-photo-edit:hover {
            background: var(--primary);
            color: white;
            transform: scale(1.1);
        }

        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
        }

        .profile-id {
            font-size: 1rem;
            color: #6c757d;
            margin-bottom: 15px;
            text-align: center;
        }

        .profile-badges {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .badge-custom {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .badge-customer {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }

        .badge-loans {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }

        .badge-active {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .badge-email {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
        }

        .badge-noted {
            background: rgba(245, 158, 11, 0.1);
            color: var(--noted);
        }

        /* Loan Limit Card */
        .loan-limit-card {
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border: 2px solid #667eea30;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .loan-limit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .loan-limit-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loan-limit-amount {
            font-size: 24px;
            font-weight: 700;
            color: #48bb78;
        }

        .loan-limit-progress {
            height: 10px;
            background: #e2e8f0;
            border-radius: 5px;
            overflow: hidden;
            margin: 15px 0;
        }

        .loan-limit-progress-bar {
            height: 100%;
            border-radius: 5px;
            transition: width 0.3s ease;
        }

        .loan-limit-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .limit-stat-item {
            text-align: center;
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .limit-stat-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .limit-stat-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .limit-stat-value.warning {
            color: var(--danger);
        }

        .limit-stat-value.success {
            color: var(--success);
        }

        .info-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            height: 100%;
        }

        .info-card.noted {
            border-left: 4px solid var(--noted);
        }

        .info-card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-card-title i {
            font-size: 1.4rem;
            color: var(--primary);
        }

        .info-card-title .noted-icon {
            color: var(--noted);
        }

        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #e9ecef;
        }

        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .info-label {
            width: 140px;
            font-weight: 600;
            color: #6c757d;
        }

        .info-value {
            flex: 1;
            color: var(--dark);
        }

        .email-highlight {
            background: rgba(13, 202, 240, 0.1);
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
        }

        .noted-remark {
            background: rgba(245, 158, 11, 0.1);
            border-left: 4px solid var(--noted);
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-style: italic;
            color: #92400e;
        }

        .noted-remark i {
            color: var(--noted);
            margin-right: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin: 0 25px 25px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            text-align: center;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-card.noted {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-color: var(--noted);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .loan-table {
            width: 100%;
            border-collapse: collapse;
        }

        .loan-table th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }

        .loan-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .loan-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-open {
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary);
        }

        .status-closed {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success);
        }

        .status-auctioned {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        .status-defaulted {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning);
        }

        .payment-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }

        .payment-content {
            flex: 1;
        }

        .payment-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 3px;
        }

        .payment-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .btn-action {
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-new-loan {
            background: linear-gradient(135deg, var(--success) 0%, #146c43 100%);
            color: white;
        }

        .btn-new-loan:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25, 135, 84, 0.3);
            color: white;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--warning) 0%, #e0a800 100%);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
            color: white;
        }

        .btn-back {
            background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
            color: white;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
            color: white;
        }

        .btn-noted {
            background: linear-gradient(135deg, var(--noted) 0%, #fbbf24 100%);
            color: white;
        }

        .btn-noted:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
            color: white;
        }

        .address-block {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
            line-height: 1.8;
        }

        .bank-details {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
        }

        .bank-row {
            display: flex;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #dee2e6;
        }

        .bank-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .bank-label {
            width: 120px;
            font-weight: 600;
            color: #495057;
        }

        .bank-value {
            flex: 1;
            color: var(--dark);
        }

        .upi-badge {
            background: rgba(13, 202, 240, 0.1);
            color: var(--info);
            padding: 3px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            display: inline-block;
        }

        .ifsc-code {
            font-family: monospace;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        @media (max-width: 768px) {
            .profile-header {
                padding: 20px;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }

            .loan-limit-stats {
                grid-template-columns: 1fr;
            }

            .bank-row {
                flex-direction: column;
            }

            .bank-label {
                width: 100%;
                margin-bottom: 5px;
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
                    <div class="page-title">
                        <i class="bi bi-person-circle"></i>
                        Customer Profile
                        <?php if ($is_noted): ?>
                            <span class="badge-custom badge-noted">
                                <i class="bi bi-star-fill"></i> Noted Person
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="New-Loan.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-success me-2">
                            <i class="bi bi-plus-circle me-2"></i>New Loan
                        </a>
                        <a href="new-customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-warning me-2">
                            <i class="bi bi-pencil me-2"></i>Edit
                        </a>
                        <a href="customers.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-2"></i>Back to List
                        </a>
                    </div>
                </div>

                <!-- Profile Header -->
                <div class="profile-header <?php echo $is_noted ? 'noted' : ''; ?>">
                    <?php if ($is_noted && !empty($customer['noted_person_remarks'])): ?>
                        <div class="noted-ribbon" title="<?php echo htmlspecialchars($customer['noted_person_remarks']); ?>">
                            <i class="bi bi-star-fill"></i> Noted Person
                            <i class="bi bi-info-circle"></i>
                        </div>
                    <?php elseif ($is_noted): ?>
                        <div class="noted-ribbon">
                            <i class="bi bi-star-fill"></i> Noted Person
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-3 text-center">
                            <div class="profile-photo-container">
                                <?php if ($photo_path): ?>
                                    <!-- Show actual photo if exists -->
                                    <img src="<?php echo htmlspecialchars($photo_path); ?>" 
                                         alt="Customer Photo" 
                                         class="profile-photo"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="profile-photo-placeholder <?php echo $is_noted ? 'noted' : ''; ?>" 
                                         style="display: none;">
                                        <?php echo getInitials($customer['customer_name']); ?>
                                    </div>
                                <?php else: ?>
                                    <!-- Show placeholder with initials -->
                                    <div class="profile-photo-placeholder <?php echo $is_noted ? 'noted' : ''; ?>">
                                        <?php echo getInitials($customer['customer_name']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Edit photo button -->
                                <a href="new-customer.php?id=<?php echo $customer['id']; ?>" class="profile-photo-edit" title="Change Photo">
                                    <i class="bi bi-camera-fill"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-9">
                            <div class="profile-name">
                                <?php echo htmlspecialchars($customer['customer_name']); ?>
                                <?php if ($is_noted): ?>
                                    <i class="bi bi-star-fill" style="color: var(--noted);"></i>
                                <?php endif; ?>
                            </div>
                            <div class="profile-id">Customer ID: #<?php echo str_pad($customer['id'], 4, '0', STR_PAD_LEFT); ?></div>
                            
                            <div class="profile-badges">
                                <span class="badge-custom badge-customer">
                                    <i class="bi bi-person-badge me-2"></i><?php echo htmlspecialchars($customer['guardian_type'] ?? 'Customer'); ?>
                                </span>
                                <span class="badge-custom badge-loans">
                                    <i class="bi bi-file-text me-2"></i><?php echo $stats['total_loans'] ?? 0; ?> Total Loans
                                </span>
                                <?php if (($stats['open_loans'] ?? 0) > 0): ?>
                                <span class="badge-custom badge-active">
                                    <i class="bi bi-clock-history me-2"></i><?php echo $stats['open_loans']; ?> Active Loans
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($customer['email'])): ?>
                                <span class="badge-custom badge-email">
                                    <i class="bi bi-envelope me-2"></i>Email Verified
                                </span>
                                <?php endif; ?>
                                <?php if ($is_noted): ?>
                                <span class="badge-custom badge-noted">
                                    <i class="bi bi-star-fill me-2"></i>Noted Person
                                </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_noted && !empty($customer['noted_person_remarks'])): ?>
                                <div class="noted-remark">
                                    <i class="bi bi-chat-quote-fill"></i>
                                    <?php echo htmlspecialchars($customer['noted_person_remarks']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Mobile Number:</div>
                                        <div class="info-value">
                                            <a href="tel:<?php echo htmlspecialchars($customer['mobile_number']); ?>">
                                                <i class="bi bi-phone me-2 text-success"></i>
                                                <?php echo htmlspecialchars($customer['mobile_number']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <?php if (!empty($customer['email'])): ?>
                                    <div class="info-row">
                                        <div class="info-label">Email Address:</div>
                                        <div class="info-value">
                                            <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>" class="email-highlight">
                                                <i class="bi bi-envelope-fill me-2 text-info"></i>
                                                <?php echo htmlspecialchars($customer['email']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer['whatsapp_number'])): ?>
                                    <div class="info-row">
                                        <div class="info-label">WhatsApp:</div>
                                        <div class="info-value">
                                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $customer['whatsapp_number']); ?>" target="_blank">
                                                <i class="bi bi-whatsapp me-2 text-success"></i>
                                                <?php echo htmlspecialchars($customer['whatsapp_number']); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($customer['alternate_mobile'])): ?>
                                    <div class="info-row">
                                        <div class="info-label">Alternate:</div>
                                        <div class="info-value">
                                            <i class="bi bi-telephone me-2"></i>
                                            <?php echo htmlspecialchars($customer['alternate_mobile']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Guardian:</div>
                                        <div class="info-value">
                                            <i class="bi bi-person me-2"></i>
                                            <?php echo htmlspecialchars($customer['guardian_name'] ?? 'N/A'); ?>
                                            <?php if (!empty($customer['guardian_type'])): ?>
                                            <span class="text-muted">(<?php echo htmlspecialchars($customer['guardian_type']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Aadhar:</div>
                                        <div class="info-value">
                                            <i class="bi bi-credit-card me-2"></i>
                                            <?php echo formatAadhar($customer['aadhaar_number'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Customer Since:</div>
                                        <div class="info-value">
                                            <i class="bi bi-calendar me-2"></i>
                                            <?php echo date('d F Y', strtotime($customer['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Details Section -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="info-card <?php echo $is_noted ? 'noted' : ''; ?>">
                            <div class="info-card-title">
                                <i class="bi bi-bank <?php echo $is_noted ? 'noted-icon' : ''; ?>"></i>
                                <span>Bank Details</span>
                                <?php if (!empty($customer['account_number'])): ?>
                                    <span class="badge bg-success ms-2">Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2">Not Provided</span>
                                <?php endif; ?>
                            </div>

                            <div class="bank-details">
                                <?php if (!empty($customer['account_holder_name']) || !empty($customer['bank_name'])): ?>
                                    <div class="bank-row">
                                        <div class="bank-label">Account Holder:</div>
                                        <div class="bank-value">
                                            <strong><?php echo htmlspecialchars($customer['account_holder_name'] ?? $customer['customer_name']); ?></strong>
                                        </div>
                                    </div>
                                    
                                    <div class="bank-row">
                                        <div class="bank-label">Bank Name:</div>
                                        <div class="bank-value">
                                            <i class="bi bi-building me-2"></i>
                                            <?php echo htmlspecialchars($customer['bank_name'] ?? 'Not provided'); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($customer['branch_name'])): ?>
                                    <div class="bank-row">
                                        <div class="bank-label">Branch:</div>
                                        <div class="bank-value">
                                            <i class="bi bi-geo-alt me-2"></i>
                                            <?php echo htmlspecialchars($customer['branch_name']); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($customer['account_number'])): ?>
                                    <div class="bank-row">
                                        <div class="bank-label">Account Number:</div>
                                        <div class="bank-value">
                                            <i class="bi bi-credit-card me-2"></i>
                                            <?php echo maskAccountNumber($customer['account_number']); ?>
                                            <small class="text-muted ms-2">(<?php echo htmlspecialchars($customer['account_type'] ?? 'savings'); ?>)</small>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($customer['ifsc_code'])): ?>
                                    <div class="bank-row">
                                        <div class="bank-label">IFSC Code:</div>
                                        <div class="bank-value">
                                            <span class="ifsc-code"><?php echo htmlspecialchars($customer['ifsc_code']); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($customer['upi_id'])): ?>
                                    <div class="bank-row">
                                        <div class="bank-label">UPI ID:</div>
                                        <div class="bank-value">
                                            <span class="upi-badge">
                                                <i class="bi bi-phone me-1"></i>
                                                <?php echo htmlspecialchars($customer['upi_id']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-bank fs-1 d-block mb-3"></i>
                                        <p>No bank details provided for this customer.</p>
                                        <a href="new-customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil me-2"></i>Add Bank Details
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon text-primary">
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
                        <div class="stat-value">₹<?php echo number_format($stats['total_loan_amount'] ?? 0, 2); ?></div>
                        <div class="stat-label">Total Loan Amount</div>
                    </div>
                    <div class="stat-card <?php echo $remaining_limit < 0 ? 'noted' : ''; ?>">
                        <div class="stat-icon <?php echo $remaining_limit < 0 ? 'text-danger' : 'text-success'; ?>">
                            <i class="bi bi-pie-chart"></i>
                        </div>
                        <div class="stat-value <?php echo $remaining_limit < 0 ? 'text-danger' : 'text-success'; ?>">
                            ₹<?php echo number_format(max($remaining_limit, 0), 2); ?>
                        </div>
                        <div class="stat-label">Available Limit</div>
                    </div>
                </div>

                <!-- Loan Limit Details -->
                <div class="info-card">
                    <div class="info-card-title">
                        <i class="bi bi-credit-card"></i>
                        <span>Loan Limit Status</span>
                    </div>
                    
                    <div class="loan-limit-card">
                        <div class="loan-limit-header">
                            <div class="loan-limit-title">
                                <i class="bi bi-pie-chart-fill"></i>
                                Total Loan Limit
                            </div>
                            <div class="loan-limit-amount">
                                ₹<?php echo number_format($loan_limit, 2); ?>
                            </div>
                        </div>

                        <div class="loan-limit-progress">
                            <div class="loan-limit-progress-bar" style="width: <?php echo min($limit_percentage, 100); ?>%; background-color: <?php echo $progress_color; ?>;"></div>
                        </div>

                        <div class="loan-limit-stats">
                            <div class="limit-stat-item">
                                <div class="limit-stat-label">Used Amount</div>
                                <div class="limit-stat-value <?php echo ($total_active / $loan_limit) > 0.8 ? 'warning' : ''; ?>">
                                    ₹<?php echo number_format($total_active, 2); ?>
                                </div>
                                <small>(<?php echo number_format($limit_percentage, 1); ?>%)</small>
                            </div>

                            <div class="limit-stat-item">
                                <div class="limit-stat-label">Remaining Limit</div>
                                <div class="limit-stat-value <?php echo $remaining_limit < 0 ? 'warning' : 'success'; ?>">
                                    ₹<?php echo number_format(max($remaining_limit, 0), 2); ?>
                                </div>
                            </div>

                            <div class="limit-stat-item">
                                <div class="limit-stat-label">Active Loans</div>
                                <div class="limit-stat-value">
                                    <?php echo $active_loans_count; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($remaining_limit < 0): ?>
                            <div style="margin-top: 15px; padding: 10px; background: #fff5f5; border-radius: 8px; font-size: 13px; color: #c53030;">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Warning:</strong> Customer has exceeded their loan limit by ₹<?php echo number_format(abs($remaining_limit), 2); ?>.
                                Please review before issuing new loans.
                            </div>
                        <?php elseif ($remaining_limit < 1000): ?>
                            <div style="margin-top: 15px; padding: 10px; background: #fef3c7; border-radius: 8px; font-size: 13px; color: #92400e;">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Notice:</strong> Customer is approaching their loan limit. Only ₹<?php echo number_format($remaining_limit, 2); ?> remaining.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row">
                    <!-- Address Information -->
                    <div class="col-md-6">
                        <div class="info-card <?php echo $is_noted ? 'noted' : ''; ?>">
                            <div class="info-card-title">
                                <i class="bi bi-house-door-fill <?php echo $is_noted ? 'noted-icon' : ''; ?>"></i>
                                <span>Address Information</span>
                                <?php if ($is_noted): ?>
                                    <i class="bi bi-star-fill noted-icon ms-auto"></i>
                                <?php endif; ?>
                            </div>
                            
                            <div class="address-block">
                                <?php 
                                $address_parts = array_filter([
                                    $customer['door_no'] ?? '',
                                    $customer['house_name'] ?? '',
                                    $customer['street_name'] ?? '',
                                    $customer['street_name1'] ?? '',
                                    $customer['landmark'] ?? '',
                                    $customer['location'] ?? '',
                                    $customer['post'] ?? '',
                                    $customer['taluk'] ?? '',
                                    $customer['district'] ?? '',
                                    $customer['pincode'] ?? ''
                                ]);
                                
                                if (!empty($address_parts)) {
                                    echo '<i class="bi bi-geo-alt me-2 text-primary"></i>';
                                    echo nl2br(htmlspecialchars(implode(', ', $address_parts)));
                                } else {
                                    echo '<span class="text-muted"><i class="bi bi-geo-alt me-2"></i>No address provided</span>';
                                }
                                ?>
                            </div>

                            <?php if (!empty($customer['company_name'])): ?>
                            <div class="mt-3">
                                <i class="bi bi-building me-2 text-info"></i>
                                <strong>Company:</strong> <?php echo htmlspecialchars($customer['company_name']); ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($customer['referral_person']) || !empty($customer['referral_mobile'])): ?>
                            <div class="mt-3">
                                <i class="bi bi-person-badge me-2 text-warning"></i>
                                <strong>Referred by:</strong> 
                                <?php echo htmlspecialchars($customer['referral_person'] ?? ''); ?>
                                <?php if (!empty($customer['referral_mobile'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($customer['referral_mobile']); ?>" class="ms-2">
                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($customer['referral_mobile']); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="col-md-6">
                        <div class="info-card <?php echo $is_noted ? 'noted' : ''; ?>">
                            <div class="info-card-title">
                                <i class="bi bi-info-circle-fill <?php echo $is_noted ? 'noted-icon' : ''; ?>"></i>
                                <span>Additional Information</span>
                                <?php if ($is_noted): ?>
                                    <i class="bi bi-star-fill noted-icon ms-auto"></i>
                                <?php endif; ?>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Loan Limit:</div>
                                <div class="info-value">
                                    <strong class="text-success">₹<?php echo number_format($loan_limit, 2); ?></strong>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Currently Used:</div>
                                <div class="info-value">
                                    <strong class="<?php echo $total_active > 0 ? 'text-warning' : 'text-muted'; ?>">
                                        ₹<?php echo number_format($total_active, 2); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="info-row">
                                <div class="info-label">Limit Status:</div>
                                <div class="info-value">
                                    <?php if ($remaining_limit >= 0): ?>
                                        <span class="text-success">
                                            <i class="bi bi-check-circle-fill"></i> Within Limit
                                        </span>
                                    <?php else: ?>
                                        <span class="text-danger">
                                            <i class="bi bi-exclamation-triangle-fill"></i> Exceeded by ₹<?php echo number_format(abs($remaining_limit), 2); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($customer['email'])): ?>
                            <div class="info-row">
                                <div class="info-label">Email Status:</div>
                                <div class="info-value">
                                    <span class="badge bg-info text-white">
                                        <i class="bi bi-check-circle-fill me-1"></i> Active
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($customer['alert_message'])): ?>
                            <div class="info-row">
                                <div class="info-label">Alert:</div>
                                <div class="info-value">
                                    <span class="text-danger">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                        <?php echo htmlspecialchars($customer['alert_message']); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-row">
                                <div class="info-label">First Loan:</div>
                                <div class="info-value">
                                    <?php if ($stats['first_loan_date']): ?>
                                        <i class="bi bi-calendar-check me-2"></i>
                                        <?php echo date('d-m-Y', strtotime($stats['first_loan_date'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="bi bi-dash-circle me-2"></i>No loans yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">Last Loan:</div>
                                <div class="info-value">
                                    <?php if ($stats['last_loan_date']): ?>
                                        <i class="bi bi-calendar me-2"></i>
                                        <?php echo date('d-m-Y', strtotime($stats['last_loan_date'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="bi bi-dash-circle me-2"></i>No loans yet</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loans History -->
                <div class="info-card mt-3">
                    <div class="info-card-title">
                        <i class="bi bi-file-text"></i>
                        <span>Loan History</span>
                        <?php if (count($loans) > 0): ?>
                        <a href="customer-loans.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-primary ms-auto">View All</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($loans) > 0): ?>
                    <div class="table-responsive">
                        <table class="loan-table">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Loan Amount</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($loans as $loan): 
                                    $balance = $loan['loan_amount'] - $loan['total_paid'];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($loan['receipt_number']); ?></strong></td>
                                    <td><?php echo date('d-m-Y', strtotime($loan['receipt_date'])); ?></td>
                                    <td class="text-center"><?php echo $loan['item_count']; ?></td>
                                    <td>₹<?php echo number_format($loan['loan_amount'], 2); ?></td>
                                    <td>₹<?php echo number_format($loan['total_paid'], 2); ?></td>
                                    <td>
                                        <?php if ($balance > 0): ?>
                                        <strong class="text-danger">₹<?php echo number_format($balance, 2); ?></strong>
                                        <?php else: ?>
                                        <span class="text-success">₹0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $loan['status']; ?>">
                                            <?php echo ucfirst($loan['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="view_loan.php?id=<?php echo $loan['id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-file-text fs-1 d-block mb-3"></i>
                        <p>No loans found for this customer.</p>
                        <a href="New-Loan.php?customer_id=<?php echo $customer['id']; ?>" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Create First Loan
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Payments -->
                <?php if (count($payments) > 0): ?>
                <div class="info-card mt-3">
                    <div class="info-card-title">
                        <i class="bi bi-cash-stack"></i>
                        <span>Recent Payments</span>
                        <a href="customer-payments.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-primary ms-auto">View All</a>
                    </div>
                    
                    <div class="row">
                        <?php foreach ($payments as $payment): ?>
                        <div class="col-md-6">
                            <div class="payment-item">
                                <div class="payment-icon">
                                    <i class="bi bi-cash"></i>
                                </div>
                                <div class="payment-content">
                                    <div class="payment-title">
                                        <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                    </div>
                                    <div class="payment-meta">
                                        <span><i class="bi bi-calendar me-1"></i> <?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></span>
                                        <span class="ms-3"><i class="bi bi-currency-rupee me-1"></i> <?php echo number_format($payment['total_amount'], 2); ?></span>
                                    </div>
                                    <div class="payment-meta">
                                        <span><i class="bi bi-credit-card me-1"></i> <?php echo ucfirst($payment['payment_mode']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <a href="New-Loan.php?customer_id=<?php echo $customer['id']; ?>" class="btn-action btn-new-loan">
                        <i class="bi bi-plus-circle"></i> New Loan
                    </a>
                    <a href="new-customer.php?id=<?php echo $customer['id']; ?>" class="btn-action btn-edit">
                        <i class="bi bi-pencil"></i> Edit Customer
                    </a>
                    <?php if ($is_noted): ?>
                    <button class="btn-action btn-noted" onclick="alert('Noted Person Remarks:\n<?php echo htmlspecialchars($customer['noted_person_remarks'] ?? 'No remarks provided'); ?>')">
                        <i class="bi bi-star-fill"></i> View Remarks
                    </button>
                    <?php endif; ?>
                    <a href="customers.php" class="btn-action btn-back">
                        <i class="bi bi-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>