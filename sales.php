<?php
session_start();
$currentPage = 'sales';
$pageTitle = 'Sales';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view sales
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Handle payment collection for pending invoices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment' && isset($_POST['invoice_id']) && is_numeric($_POST['invoice_id'])) {
    
    $invoice_id = intval($_POST['invoice_id']);
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    
    if ($payment_amount <= 0) {
        $error = "Please enter a valid payment amount.";
    } else {
        // Get invoice details
        $invoice_query = $conn->prepare("SELECT * FROM invoice WHERE id = ?");
        $invoice_query->bind_param("i", $invoice_id);
        $invoice_query->execute();
        $invoice = $invoice_query->get_result()->fetch_assoc();
        
        if (!$invoice) {
            $error = "Invoice not found.";
        } elseif ($invoice['pending_amount'] <= 0) {
            $error = "No pending amount for this invoice.";
        } elseif ($payment_amount > $invoice['pending_amount']) {
            $error = "Payment amount exceeds pending amount. Pending: ₹" . number_format($invoice['pending_amount'], 2);
        } else {
            $conn->begin_transaction();
            
            try {
                // Calculate new pending amount
                $new_pending = $invoice['pending_amount'] - $payment_amount;
                
                // Update invoice
                $update = $conn->prepare("UPDATE invoice SET pending_amount = ? WHERE id = ?");
                $update->bind_param("di", $new_pending, $invoice_id);
                
                if (!$update->execute()) {
                    throw new Exception("Failed to update invoice.");
                }
                
                // Log activity
                $log_desc = "Payment collected of ₹$payment_amount for invoice #" . $invoice['inv_num'] . 
                           ". Remaining pending: ₹$new_pending";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'payment', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $conn->commit();
                $success = "Payment of ₹" . number_format($payment_amount, 2) . " collected successfully.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

// Handle invoice cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_invoice' && isset($_POST['invoice_id']) && is_numeric($_POST['invoice_id'])) {
    
    if ($_SESSION['user_role'] !== 'admin') {
        $error = "Only admins can cancel invoices.";
    } else {
        $invoice_id = intval($_POST['invoice_id']);
        
        // Get invoice details
        $invoice_query = $conn->prepare("SELECT * FROM invoice WHERE id = ?");
        $invoice_query->bind_param("i", $invoice_id);
        $invoice_query->execute();
        $invoice = $invoice_query->get_result()->fetch_assoc();
        
        if (!$invoice) {
            $error = "Invoice not found.";
        } else {
            $conn->begin_transaction();
            
            try {
                // Get invoice items to restore stock
                $items_query = $conn->prepare("SELECT * FROM invoice_item WHERE invoice_id = ?");
                $items_query->bind_param("i", $invoice_id);
                $items_query->execute();
                $items = $items_query->get_result();
                
                while ($item = $items->fetch_assoc()) {
                    if ($item['cat_id']) {
                        // Restore stock to category
                        $update_stock = $conn->prepare("UPDATE category SET total_quantity = total_quantity + ? WHERE id = ?");
                        $update_stock->bind_param("di", $item['quantity'], $item['cat_id']);
                        $update_stock->execute();
                    }
                }
                
                // Delete invoice items
                $delete_items = $conn->prepare("DELETE FROM invoice_item WHERE invoice_id = ?");
                $delete_items->bind_param("i", $invoice_id);
                $delete_items->execute();
                
                // Delete invoice
                $delete_invoice = $conn->prepare("DELETE FROM invoice WHERE id = ?");
                $delete_invoice->bind_param("i", $invoice_id);
                $delete_invoice->execute();
                
                // Log activity
                $log_desc = "Cancelled invoice #" . $invoice['inv_num'] . " (₹" . number_format($invoice['total'], 2) . ")";
                $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'cancel', ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                $log_stmt->execute();
                
                $conn->commit();
                $success = "Invoice cancelled successfully. Stock has been restored.";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to cancel invoice: " . $e->getMessage();
            }
        }
    }
}

// Filters
$filterDate = $_GET['filter_date'] ?? date('Y-m-d');
$filterCustomer = $_GET['filter_customer'] ?? '';
$filterPayment = $_GET['filter_payment'] ?? '';
$filterStatus = $_GET['filter_status'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterDate && $filterDate !== 'all') {
    $where .= " AND DATE(i.created_at) = ?";  // Added i. prefix
    $params[] = $filterDate;
    $types .= "s";
}

if ($filterCustomer && $filterCustomer !== 'all') {
    $where .= " AND i.customer_id = ?";  // Added i. prefix
    $params[] = $filterCustomer;
    $types .= "i";
}

if ($filterPayment && $filterPayment !== 'all') {
    $where .= " AND i.payment_method = ?";  // Added i. prefix
    $params[] = $filterPayment;
    $types .= "s";
}

if ($filterStatus && $filterStatus !== 'all') {
    if ($filterStatus === 'paid') {
        $where .= " AND i.pending_amount = 0";  // Added i. prefix
    } elseif ($filterStatus === 'pending') {
        $where .= " AND i.pending_amount > 0";  // Added i. prefix
    } elseif ($filterStatus === 'overdue') {
        $where .= " AND i.pending_amount > 0 AND DATE(i.created_at) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";  // Added i. prefix
    }
}

$sql = "SELECT i.*, c.customer_name, c.phone, c.gst_number 
        FROM invoice i 
        LEFT JOIN customers c ON i.customer_id = c.id 
        WHERE $where 
        ORDER BY i.created_at DESC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $invoices = $stmt->get_result();
} else {
    $invoices = $conn->query($sql);
}

// Get customers for filter dropdown
$customers = $conn->query("SELECT id, customer_name FROM customers ORDER BY customer_name ASC");

// Stats
$today_sales = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM invoice WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['total'];
$month_sales = $conn->query("SELECT COALESCE(SUM(total), 0) as total FROM invoice WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetch_assoc()['total'];
$total_invoices = $conn->query("SELECT COUNT(*) as cnt FROM invoice")->fetch_assoc()['cnt'];
$pending_amount = $conn->query("SELECT COALESCE(SUM(pending_amount), 0) as total FROM invoice WHERE pending_amount > 0")->fetch_assoc()['total'];

// Today's count
$today_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['cnt'];

// Payment method stats
$cash_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'cash'")->fetch_assoc()['cnt'];
$card_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'card'")->fetch_assoc()['cnt'];
$upi_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'upi'")->fetch_assoc()['cnt'];
$bank_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'bank'")->fetch_assoc()['cnt'];
$credit_count = $conn->query("SELECT COUNT(*) as cnt FROM invoice WHERE payment_method = 'credit'")->fetch_assoc()['cnt'];

// Status badge helper
function getPaymentStatus($pending) {
    if ($pending == 0) {
        return ['class' => 'completed', 'text' => 'Paid', 'icon' => 'bi-check-circle'];
    } else {
        return ['class' => 'pending', 'text' => 'Pending', 'icon' => 'bi-clock-history'];
    }
}

// Payment method badge helper
function getPaymentMethodBadge($method) {
    switch($method) {
        case 'cash':
            return ['class' => 'success', 'icon' => 'bi-cash-stack', 'text' => 'Cash'];
        case 'card':
            return ['class' => 'primary', 'icon' => 'bi-credit-card', 'text' => 'Card'];
        case 'upi':
            return ['class' => 'info', 'icon' => 'bi-phone', 'text' => 'UPI'];
        case 'bank':
            return ['class' => 'warning', 'icon' => 'bi-bank', 'text' => 'Bank'];
        case 'credit':
            return ['class' => 'danger', 'icon' => 'bi-clock-history', 'text' => 'Credit'];
        default:
            return ['class' => 'secondary', 'icon' => 'bi-question-circle', 'text' => ucfirst($method)];
    }
}

// Check if user is admin for certain actions
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .invoice-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }
        
        .invoice-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-color: #cbd5e1;
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f6;
        }
        
        .invoice-number {
            font-weight: 700;
            color: #2463eb;
            font-size: 16px;
        }
        
        .invoice-date {
            font-size: 12px;
            color: #64748b;
        }
        
        .customer-info {
            margin-bottom: 12px;
        }
        
        .customer-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
        }
        
        .customer-detail {
            font-size: 11px;
            color: #64748b;
        }
        
        .amount-large {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .amount-label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .pending-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .paid-badge {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .method-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .method-badge.cash { background: #e8f2ff; color: #2463eb; }
        .method-badge.card { background: #f0fdf4; color: #16a34a; }
        .method-badge.upi { background: #fef3c7; color: #d97706; }
        .method-badge.bank { background: #f3e8ff; color: #9333ea; }
        .method-badge.credit { background: #fee2e2; color: #dc2626; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #eef2f6;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #eef2f6;
            margin-bottom: 20px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        .payment-form {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
            margin-top: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
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
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Sales</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">View and manage all invoices</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="invoice-create.php" class="btn-primary-custom">
                        <i class="bi bi-plus-circle"></i> New Invoice
                    </a>
                    <a href="reports.php" class="btn-outline-custom">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert" data-testid="alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($today_sales, 2); ?></div>
                            <div class="stat-label">Today's Sales</div>
                        </div>
                        <div class="stat-icon blue" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-day"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 12px;"><?php echo $today_count; ?> invoices today</div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($month_sales, 2); ?></div>
                            <div class="stat-label">Monthly Sales</div>
                        </div>
                        <div class="stat-icon green" style="width: 48px; height: 48px;">
                            <i class="bi bi-calendar-month"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-muted" style="font-size: 12px;"><?php echo date('F Y'); ?></div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?php echo $total_invoices; ?></div>
                            <div class="stat-label">Total Invoices</div>
                        </div>
                        <div class="stat-icon purple" style="width: 48px; height: 48px;">
                            <i class="bi bi-receipt"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-danger">₹<?php echo number_format($pending_amount, 2); ?></div>
                            <div class="stat-label">Pending Amount</div>
                        </div>
                        <div class="stat-icon orange" style="width: 48px; height: 48px;">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Methods Summary -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="card-body py-3">
                            <div class="d-flex gap-3 flex-wrap align-items-center">
                                <span class="fw-semibold">Payment Methods:</span>
                                <span class="method-badge cash"><i class="bi bi-cash-stack me-1"></i>Cash: <?php echo $cash_count; ?></span>
                                <span class="method-badge card"><i class="bi bi-credit-card me-1"></i>Card: <?php echo $card_count; ?></span>
                                <span class="method-badge upi"><i class="bi bi-phone me-1"></i>UPI: <?php echo $upi_count; ?></span>
                                <span class="method-badge bank"><i class="bi bi-bank me-1"></i>Bank: <?php echo $bank_count; ?></span>
                                <span class="method-badge credit"><i class="bi bi-clock-history me-1"></i>Credit: <?php echo $credit_count; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="sales.php" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="filter_date" class="form-control" value="<?php echo $filterDate; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Customer</label>
                        <select name="filter_customer" class="form-select">
                            <option value="all">All Customers</option>
                            <?php 
                            if ($customers && $customers->num_rows > 0) {
                                while ($customer = $customers->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo $filterCustomer == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['customer_name']); ?>
                                </option>
                            <?php 
                                endwhile; 
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Payment Method</label>
                        <select name="filter_payment" class="form-select">
                            <option value="all">All</option>
                            <option value="cash" <?php echo $filterPayment == 'cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="card" <?php echo $filterPayment == 'card' ? 'selected' : ''; ?>>Card</option>
                            <option value="upi" <?php echo $filterPayment == 'upi' ? 'selected' : ''; ?>>UPI</option>
                            <option value="bank" <?php echo $filterPayment == 'bank' ? 'selected' : ''; ?>>Bank</option>
                            <option value="credit" <?php echo $filterPayment == 'credit' ? 'selected' : ''; ?>>Credit</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="filter_status" class="form-select">
                            <option value="all">All</option>
                            <option value="paid" <?php echo $filterStatus == 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="pending" <?php echo $filterStatus == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $filterStatus == 'overdue' ? 'selected' : ''; ?>>Overdue (30+ days)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="d-flex gap-2 w-100">
                            <button type="submit" class="btn-primary-custom flex-fill">
                                <i class="bi bi-funnel"></i> Apply
                            </button>
                            <a href="sales.php" class="btn-outline-custom flex-fill">
                                <i class="bi bi-x-circle"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Invoices List -->
            <div class="dashboard-card ">
                <div class="card-header-custom p-4">
                    <h5><i class="bi bi-receipt me-2 "></i>Invoices</h5>
                    <p>Showing <?php echo $invoices ? $invoices->num_rows : 0; ?> invoices</p>
                </div>
                
                <!-- Desktop Table View -->
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="salesTable">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Subtotal</th>
                                <th>Tax</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Pending</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($invoices && $invoices->num_rows > 0): ?>
                                <?php while ($invoice = $invoices->fetch_assoc()): 
                                    $status = getPaymentStatus($invoice['pending_amount']);
                                    $method = getPaymentMethodBadge($invoice['payment_method']);
                                    
                                    // Get item count for this invoice
                                    $item_count_query = $conn->prepare("SELECT COUNT(*) as cnt FROM invoice_item WHERE invoice_id = ?");
                                    $item_count_query->bind_param("i", $invoice['id']);
                                    $item_count_query->execute();
                                    $item_count = $item_count_query->get_result()->fetch_assoc()['cnt'];
                                ?>
                                    <tr data-testid="row-invoice-<?php echo $invoice['id']; ?>">
                                        <td>
                                            <span class="order-id"><?php echo htmlspecialchars($invoice['inv_num']); ?></span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <?php echo date('d M Y', strtotime($invoice['created_at'])); ?>
                                            <div class="text-muted" style="font-size: 10px;"><?php echo date('h:i A', strtotime($invoice['created_at'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></div>
                                            <?php if ($invoice['phone']): ?>
                                                <div class="text-muted" style="font-size: 11px;"><?php echo $invoice['phone']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo $item_count; ?></td>
                                        <td>₹<?php echo number_format($invoice['subtotal'], 2); ?></td>
                                        <td>₹<?php echo number_format($invoice['cgst_amount'] + $invoice['sgst_amount'], 2); ?></td>
                                        <td class="fw-semibold">₹<?php echo number_format($invoice['total'], 2); ?></td>
                                        <td>
                                            <span class="method-badge <?php echo $method['class']; ?>">
                                                <i class="bi <?php echo $method['icon']; ?>"></i>
                                                <?php echo $method['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $status['class']; ?>">
                                                <i class="bi <?php echo $status['icon']; ?>"></i>
                                                <?php echo $status['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($invoice['pending_amount'] > 0): ?>
                                                <span class="pending-badge">
                                                    <i class="bi bi-exclamation-circle"></i>
                                                    ₹<?php echo number_format($invoice['pending_amount'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="paid-badge">
                                                    <i class="bi bi-check-circle"></i>
                                                    Paid
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <!-- View Invoice -->
                                                <a href="invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info" style="font-size: 12px; padding: 3px 8px;" title="View Invoice">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                
                                                <!-- Print Invoice -->
                                                <a href="invoice-print.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-size: 12px; padding: 3px 8px;" title="Print Invoice">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                
                                                <?php if ($invoice['pending_amount'] > 0): ?>
                                                    <!-- Collect Payment -->
                                                    <button class="btn btn-sm btn-outline-success" style="font-size: 12px; padding: 3px 8px;" 
                                                            onclick="showPaymentModal(<?php echo $invoice['id']; ?>, '<?php echo $invoice['inv_num']; ?>', <?php echo $invoice['pending_amount']; ?>)"
                                                            title="Collect Payment">
                                                        <i class="bi bi-cash-stack"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($is_admin): ?>
                                                    <!-- Cancel Invoice (Admin only) -->
                                                    <form method="POST" action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status']); ?>" 
                                                          style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to cancel this invoice? Stock will be restored.')">
                                                        <input type="hidden" name="action" value="cancel_invoice">
                                                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" title="Cancel Invoice">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php if ($invoices && $invoices->num_rows > 0): ?>
                        <?php 
                        // Reset pointer for mobile view
                        $invoices->data_seek(0);
                        while ($invoice = $invoices->fetch_assoc()): 
                            $status = getPaymentStatus($invoice['pending_amount']);
                            $method = getPaymentMethodBadge($invoice['payment_method']);
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-invoice-<?php echo $invoice['id']; ?>">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id"><?php echo htmlspecialchars($invoice['inv_num']); ?></span>
                                        <span class="customer-name ms-2"><?php echo htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer'); ?></span>
                                    </div>
                                    <span class="status-badge <?php echo $status['class']; ?>">
                                        <i class="bi <?php echo $status['icon']; ?>"></i>
                                        <?php echo $status['text']; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Date & Time</span>
                                    <span class="mobile-card-value"><?php echo date('d M Y, h:i A', strtotime($invoice['created_at'])); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Payment Method</span>
                                    <span class="mobile-card-value">
                                        <span class="method-badge <?php echo $method['class']; ?>">
                                            <i class="bi <?php echo $method['icon']; ?>"></i>
                                            <?php echo $method['text']; ?>
                                        </span>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Subtotal</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($invoice['subtotal'], 2); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Tax (GST)</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($invoice['cgst_amount'] + $invoice['sgst_amount'], 2); ?></span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label fw-bold">Total</span>
                                    <span class="mobile-card-value fw-bold" style="color: var(--primary);">₹<?php echo number_format($invoice['total'], 2); ?></span>
                                </div>
                                
                                <?php if ($invoice['pending_amount'] > 0): ?>
                                    <div class="mobile-card-row">
                                        <span class="mobile-card-label text-danger">Pending</span>
                                        <span class="mobile-card-value text-danger fw-semibold">₹<?php echo number_format($invoice['pending_amount'], 2); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mobile-card-actions">
                                    <a href="invoice-view.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-info flex-fill">
                                        <i class="bi bi-eye me-1"></i>View
                                    </a>
                                    
                                    <a href="invoice-print.php?id=<?php echo $invoice['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </a>
                                    
                                    <?php if ($invoice['pending_amount'] > 0): ?>
                                        <button class="btn btn-sm btn-outline-success flex-fill" 
                                                onclick="showPaymentModal(<?php echo $invoice['id']; ?>, '<?php echo $invoice['inv_num']; ?>', <?php echo $invoice['pending_amount']; ?>)">
                                            <i class="bi bi-cash-stack me-1"></i>Pay
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_admin): ?>
                                        <form method="POST" action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status']); ?>" 
                                              style="flex: 1;" 
                                              onsubmit="return confirm('Cancel this invoice? Stock will be restored.')">
                                            <input type="hidden" name="action" value="cancel_invoice">
                                            <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                <i class="bi bi-x-circle me-1"></i>Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-receipt d-block mb-2" style="font-size: 48px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No invoices found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterDate || $filterCustomer || $filterPayment || $filterStatus): ?>
                                    Try changing your filters or <a href="sales.php">view all invoices</a>
                                <?php else: ?>
                                    <a href="invoice-create.php">Create your first invoice</a> to get started
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Payment Collection Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="sales.php<?php echo buildQueryString(['filter_date', 'filter_customer', 'filter_payment', 'filter_status']); ?>" id="paymentForm">
                <input type="hidden" name="action" value="collect_payment">
                <input type="hidden" name="invoice_id" id="paymentInvoiceId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Collect Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Invoice Number</label>
                        <input type="text" class="form-control" id="paymentInvoiceNum" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Pending Amount</label>
                        <input type="text" class="form-control" id="paymentPending" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₹</span>
                            <input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" required id="paymentAmount" oninput="validatePaymentAmount()">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="upi">UPI</option>
                            <option value="bank">Bank Transfer</option>
                        </select>
                    </div>
                    
                    <div id="paymentError" class="alert alert-danger py-2" style="display: none; font-size: 12px;"></div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="submitPayment">Collect Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Helper function to build query string with current filters
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $key) {
        unset($params[$key]);
    }
    return count($params) ? '?' . http_build_query($params) : '';
}
?>

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#salesTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']],
        language: {
            search: "Search invoices:",
            lengthMenu: "Show _MENU_ invoices",
            info: "Showing _START_ to _END_ of _TOTAL_ invoices",
            emptyTable: "No invoices available"
        },
        columnDefs: [
            { orderable: false, targets: [-1] }
        ]
    });
});

// Show payment collection modal
function showPaymentModal(invoiceId, invoiceNum, pendingAmount) {
    document.getElementById('paymentInvoiceId').value = invoiceId;
    document.getElementById('paymentInvoiceNum').value = invoiceNum;
    document.getElementById('paymentPending').value = '₹' + parseFloat(pendingAmount).toFixed(2);
    document.getElementById('paymentAmount').value = pendingAmount.toFixed(2);
    document.getElementById('paymentAmount').max = pendingAmount;
    
    $('#paymentModal').modal('show');
}

// Validate payment amount
function validatePaymentAmount() {
    const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
    const pending = parseFloat(document.getElementById('paymentPending').value.replace('₹', '')) || 0;
    const errorDiv = document.getElementById('paymentError');
    const submitBtn = document.getElementById('submitPayment');
    
    if (amount > pending) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Payment amount cannot exceed pending amount of ₹' + pending.toFixed(2);
        submitBtn.disabled = true;
    } else if (amount <= 0) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Please enter a valid amount.';
        submitBtn.disabled = true;
    } else {
        errorDiv.style.display = 'none';
        submitBtn.disabled = false;
    }
}

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});
</script>
</body>
</html>