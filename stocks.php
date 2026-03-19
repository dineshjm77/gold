<?php
session_start();
$currentPage = 'stocks';
$pageTitle = 'Stock Levels';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Both admin and sale can view stock, but only admin can modify
checkRoleAccess(['admin', 'sale']);

$success = '';
$error = '';

// Handle stock update (add/subtract) - Admin only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    // Check if user is admin for write operations
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update stock.';
    } else {
        $updateId = intval($_POST['category_id']);
        $stock_change = floatval($_POST['stock_change'] ?? 0);
        $operation = $_POST['operation'] ?? 'add'; // 'add' or 'subtract'
        $notes = trim($_POST['notes'] ?? '');
        
        if ($stock_change <= 0) {
            $error = "Please enter a valid quantity.";
        } else {
            // Get current stock
            $current_query = $conn->prepare("SELECT category_name, total_quantity, purchase_price FROM category WHERE id = ?");
            $current_query->bind_param("i", $updateId);
            $current_query->execute();
            $current_result = $current_query->get_result();
            $current_data = $current_result->fetch_assoc();
            
            if (!$current_data) {
                $error = "Category not found.";
            } else {
                $new_quantity = $current_data['total_quantity'];
                $old_quantity = $current_data['total_quantity'];
                
                if ($operation === 'add') {
                    $new_quantity += $stock_change;
                    $operation_text = 'added to';
                    $operation_sign = '+';
                } else {
                    if ($current_data['total_quantity'] < $stock_change) {
                        $error = "Insufficient stock. Current stock: " . number_format($current_data['total_quantity'], 2) . " PCS";
                    } else {
                        $new_quantity -= $stock_change;
                        $operation_text = 'removed from';
                        $operation_sign = '-';
                    }
                }
                
                if (empty($error)) {
                    $stmt = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
                    $stmt->bind_param("di", $new_quantity, $updateId);
                    
                    if ($stmt->execute()) {
                        // Calculate stock value change
                        $value_change = $stock_change * $current_data['purchase_price'];
                        
                        // Log activity with details
                        $log_desc = "Stock " . $operation_text . " '" . $current_data['category_name'] . "': " . 
                                   number_format($stock_change, 2) . " PCS (" . $operation_sign . "). " . 
                                   "Previous: " . number_format($old_quantity, 2) . " PCS, " .
                                   "New: " . number_format($new_quantity, 2) . " PCS";
                        
                        $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'stock_update', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                        $log_stmt->execute();
                        
                        $success = "Stock updated successfully. New quantity: " . number_format($new_quantity, 2) . " PCS";
                    } else {
                        $error = "Failed to update stock.";
                    }
                    $stmt->close();
                }
            }
            $current_query->close();
        }
    }
}

// Handle bulk stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_stock_update' && isset($_POST['stock_updates']) && is_array($_POST['stock_updates'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to update stock.';
    } else {
        $updates = $_POST['stock_updates'];
        $success_count = 0;
        $error_count = 0;
        
        $conn->begin_transaction();
        
        foreach ($updates as $category_id => $new_stock) {
            $category_id = intval($category_id);
            $new_stock = floatval($new_stock);
            
            if ($new_stock >= 0) {
                // Get current stock for logging
                $current = $conn->prepare("SELECT category_name, total_quantity FROM category WHERE id = ?");
                $current->bind_param("i", $category_id);
                $current->execute();
                $current_data = $current->get_result()->fetch_assoc();
                $old_stock = $current_data['total_quantity'];
                
                $stmt = $conn->prepare("UPDATE category SET total_quantity = ? WHERE id = ?");
                $stmt->bind_param("di", $new_stock, $category_id);
                
                if ($stmt->execute()) {
                    $success_count++;
                    
                    // Log each update
                    $log_desc = "Bulk stock update for '" . $current_data['category_name'] . "': " .
                               number_format($old_stock, 2) . " PCS → " . number_format($new_stock, 2) . " PCS";
                    
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'stock_update', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                } else {
                    $error_count++;
                }
                $stmt->close();
            }
        }
        
        if ($error_count > 0) {
            $conn->rollback();
            $error = "Failed to update $error_count items. All changes rolled back.";
        } else {
            $conn->commit();
            $success = "Successfully updated $success_count stock levels.";
        }
    }
}

// Handle delete stock item (if you want to allow deletion of categories with zero stock)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_zero_stock' && isset($_POST['category_id']) && is_numeric($_POST['category_id'])) {
    if ($_SESSION['user_role'] !== 'admin') {
        $error = 'You do not have permission to delete categories.';
    } else {
        $deleteId = intval($_POST['category_id']);
        
        // Check if category has zero stock
        $check = $conn->prepare("SELECT category_name, total_quantity FROM category WHERE id = ? AND total_quantity <= 0");
        $check->bind_param("i", $deleteId);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            $category = $result->fetch_assoc();
            
            // Check if category is used in products or purchases
            $check_usage = $conn->prepare("SELECT id FROM purchase_item WHERE cat_id = ? LIMIT 1");
            $check_usage->bind_param("i", $deleteId);
            $check_usage->execute();
            $check_usage->store_result();
            
            if ($check_usage->num_rows > 0) {
                $error = "Cannot delete. Category has purchase history.";
            } else {
                $stmt = $conn->prepare("DELETE FROM category WHERE id = ?");
                $stmt->bind_param("i", $deleteId);
                
                if ($stmt->execute()) {
                    $log_desc = "Deleted zero stock category: " . $category['category_name'];
                    $log_query = "INSERT INTO activity_log (user_id, action, description) VALUES (?, 'delete', ?)";
                    $log_stmt = $conn->prepare($log_query);
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    
                    $success = "Category deleted successfully.";
                }
                $stmt->close();
            }
            $check_usage->close();
        }
        $check->close();
    }
}

// Filters
$filterStock = $_GET['filter_stock'] ?? '';
$filterCategory = $_GET['filter_category'] ?? '';

$where = "1=1";
$params = [];
$types = "";

if ($filterStock === 'critical') {
    $where .= " AND total_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'low') {
    $where .= " AND total_quantity <= min_stock_level AND total_quantity > (min_stock_level * 0.25) AND min_stock_level > 0";
} elseif ($filterStock === 'normal') {
    $where .= " AND total_quantity > min_stock_level AND min_stock_level > 0";
} elseif ($filterStock === 'overstock') {
    $where .= " AND total_quantity > (min_stock_level * 2) AND min_stock_level > 0";
} elseif ($filterStock === 'zero') {
    $where .= " AND total_quantity <= 0";
} elseif ($filterStock === 'no_min') {
    $where .= " AND (min_stock_level IS NULL OR min_stock_level = 0)";
}

if ($filterCategory && $filterCategory !== 'all') {
    $where .= " AND category_name LIKE ?";
    $params[] = "%$filterCategory%";
    $types .= "s";
}

$sql = "SELECT * FROM category WHERE $where ORDER BY 
        CASE 
            WHEN total_quantity <= min_stock_level AND min_stock_level > 0 THEN 1
            WHEN total_quantity <= 0 THEN 2
            ELSE 3
        END, 
        (total_quantity / NULLIF(min_stock_level, 0)) ASC";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $categories = $stmt->get_result();
} else {
    $categories = $conn->query($sql);
}

// Stats
$totalItems = $conn->query("SELECT COUNT(*) as cnt FROM category")->fetch_assoc()['cnt'];
$totalStock = $conn->query("SELECT COALESCE(SUM(total_quantity), 0) as total FROM category")->fetch_assoc()['total'];
$totalValue = $conn->query("SELECT COALESCE(SUM(purchase_price * total_quantity), 0) as total FROM category")->fetch_assoc()['total'];

// Stock level counts
$criticalCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= (min_stock_level * 0.25) AND min_stock_level > 0")->fetch_assoc()['cnt'];
$lowCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= min_stock_level AND total_quantity > (min_stock_level * 0.25) AND min_stock_level > 0")->fetch_assoc()['cnt'];
$normalCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity > min_stock_level AND min_stock_level > 0")->fetch_assoc()['cnt'];
$overstockCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity > (min_stock_level * 2) AND min_stock_level > 0")->fetch_assoc()['cnt'];
$zeroCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE total_quantity <= 0")->fetch_assoc()['cnt'];
$noMinCount = $conn->query("SELECT COUNT(*) as cnt FROM category WHERE min_stock_level IS NULL OR min_stock_level = 0")->fetch_assoc()['cnt'];

// Top 5 categories by value
$topValueQuery = "SELECT category_name, (purchase_price * total_quantity) as stock_value 
                  FROM category 
                  WHERE total_quantity > 0 
                  ORDER BY stock_value DESC 
                  LIMIT 5";
$topValue = $conn->query($topValueQuery);

// Stock status helper
function getStockStatus($current, $min) {
    if ($current <= 0) {
        return ['class' => 'cancelled', 'text' => 'Out of Stock', 'icon' => 'bi-x-circle'];
    } elseif ($min <= 0) {
        return ['class' => 'pending', 'text' => 'No Minimum', 'icon' => 'bi-exclamation-circle'];
    } elseif ($current <= ($min * 0.25)) {
        return ['class' => 'cancelled', 'text' => 'Critical', 'icon' => 'bi-exclamation-triangle'];
    } elseif ($current <= $min) {
        return ['class' => 'pending', 'text' => 'Low Stock', 'icon' => 'bi-exclamation-diamond'];
    } elseif ($current > ($min * 2)) {
        return ['class' => 'info', 'text' => 'Overstock', 'icon' => 'bi-box'];
    } else {
        return ['class' => 'completed', 'text' => 'Normal', 'icon' => 'bi-check-circle'];
    }
}

// Check if user is admin for action buttons
$is_admin = ($_SESSION['user_role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        .stock-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .stock-indicator.critical { background: #dc2626; }
        .stock-indicator.low { background: #f59e0b; }
        .stock-indicator.normal { background: #10b981; }
        .stock-indicator.overstock { background: #6366f1; }
        
        .stock-bar-container {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 150px;
        }
        
        .stock-bar {
            flex: 1;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .stock-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .stock-bar-fill.critical { background: #dc2626; }
        .stock-bar-fill.low { background: #f59e0b; }
        .stock-bar-fill.normal { background: #10b981; }
        .stock-bar-fill.overstock { background: #6366f1; }
        
        .value-highlight {
            font-weight: 600;
            color: #1e293b;
        }
        
        .value-muted {
            color: #64748b;
            font-size: 12px;
        }
        
        .stock-value-badge {
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .stock-value-badge i {
            color: #2463eb;
            margin-right: 4px;
        }
        
        .quick-stock-form {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .quick-stock-input {
            width: 80px;
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .quick-stock-input:focus {
            outline: none;
            border-color: #2463eb;
        }
        
        .quick-stock-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-stock-btn.add {
            background: #10b981;
            color: white;
        }
        
        .quick-stock-btn.add:hover {
            background: #059669;
        }
        
        .quick-stock-btn.subtract {
            background: #ef4444;
            color: white;
        }
        
        .quick-stock-btn.subtract:hover {
            background: #dc2626;
        }
        
        .permission-badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f1f5f9;
            color: #64748b;
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .filter-badge.critical { background: #fee2e2; color: #dc2626; }
        .filter-badge.low { background: #fff3e0; color: #f59e0b; }
        .filter-badge.normal { background: #e0f2e7; color: #10b981; }
        .filter-badge.overstock { background: #e0e7ff; color: #6366f1; }
        .filter-badge.zero { background: #f1f5f9; color: #64748b; }
        
        .bulk-update-mode {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
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
                    <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Stock Levels</h4>
                    <p style="font-size: 14px; color: var(--text-muted); margin: 0;">Monitor and manage inventory stock levels</p>
                </div>
                <?php if ($is_admin): ?>
                    <div class="d-flex gap-2">
                        <button class="btn-outline-custom" id="toggleBulkUpdate" data-testid="button-bulk-update">
                            <i class="bi bi-pencil-square"></i> Bulk Update
                        </button>
                        <a href="categories.php" class="btn-outline-custom">
                            <i class="bi bi-tags"></i> Manage Categories
                        </a>
                    </div>
                <?php else: ?>
                    <span class="permission-badge"><i class="bi bi-eye"></i> View Only Mode</span>
                <?php endif; ?>
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
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-items">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon blue">
                                <i class="bi bi-boxes"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Items</div>
                                <div class="stat-value" data-testid="stat-value-items"><?php echo $totalItems; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-stock">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon green">
                                <i class="bi bi-cubes"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Total Stock</div>
                                <div class="stat-value" data-testid="stat-value-stock"><?php echo number_format($totalStock, 2); ?> PCS</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-total-value">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon purple">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Stock Value</div>
                                <div class="stat-value" data-testid="stat-value-value">₹<?php echo number_format($totalValue, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="stat-card" data-testid="stat-alerts">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon orange">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="stat-info">
                                <div class="stat-label">Stock Alerts</div>
                                <div class="stat-value" data-testid="stat-value-alerts"><?php echo $criticalCount + $lowCount; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Status Summary -->
            <div class="row g-3 mb-4">
                <div class="col-md-8">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <div class="row g-2 text-center">
                                <div class="col-4 col-md">
                                    <div class="filter-badge critical mb-1">
                                        <i class="bi bi-exclamation-triangle me-1"></i> <?php echo $criticalCount; ?>
                                    </div>
                                    <div class="value-muted">Critical</div>
                                </div>
                                <div class="col-4 col-md">
                                    <div class="filter-badge low mb-1">
                                        <i class="bi bi-exclamation-diamond me-1"></i> <?php echo $lowCount; ?>
                                    </div>
                                    <div class="value-muted">Low</div>
                                </div>
                                <div class="col-4 col-md">
                                    <div class="filter-badge normal mb-1">
                                        <i class="bi bi-check-circle me-1"></i> <?php echo $normalCount; ?>
                                    </div>
                                    <div class="value-muted">Normal</div>
                                </div>
                                <div class="col-4 col-md">
                                    <div class="filter-badge overstock mb-1">
                                        <i class="bi bi-box me-1"></i> <?php echo $overstockCount; ?>
                                    </div>
                                    <div class="value-muted">Overstock</div>
                                </div>
                                <div class="col-4 col-md">
                                    <div class="filter-badge zero mb-1">
                                        <i class="bi bi-x-circle me-1"></i> <?php echo $zeroCount; ?>
                                    </div>
                                    <div class="value-muted">Zero</div>
                                </div>
                                <div class="col-4 col-md">
                                    <div class="filter-badge mb-1">
                                        <i class="bi bi-dash-circle me-1"></i> <?php echo $noMinCount; ?>
                                    </div>
                                    <div class="value-muted">No Min</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dashboard-card">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-2">Top 5 by Value</h6>
                            <?php if ($topValue && $topValue->num_rows > 0): ?>
                                <?php while ($item = $topValue->fetch_assoc()): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="value-muted"><?php echo htmlspecialchars($item['category_name']); ?></span>
                                        <span class="fw-semibold">₹<?php echo number_format($item['stock_value'], 2); ?></span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="value-muted text-center">No data available</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulk Update Mode (Hidden by default) -->
            <div id="bulkUpdateMode" class="bulk-update-mode" style="display: none;">
                <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" id="bulkUpdateForm">
                    <input type="hidden" name="action" value="bulk_stock_update">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-semibold mb-1"><i class="bi bi-pencil-square me-2"></i>Bulk Stock Update</h5>
                            <p class="value-muted mb-0">Enter new stock quantities for multiple items at once</p>
                        </div>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="toggleBulkUpdate()">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Update all stock levels?')">
                                <i class="bi bi-check-circle me-1"></i> Apply Changes
                            </button>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2" style="font-size: 12px;">
                        <i class="bi bi-info-circle me-1"></i> Enter new stock quantities in kg. Leave empty to keep current value.
                    </div>
                </form>
            </div>

            <!-- Filter Bar -->
            <div class="dashboard-card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 flex-wrap filter-bar-inner">
                        <div class="d-flex gap-1 flex-wrap filter-tabs">
                            <a href="stocks.php" class="btn btn-sm <?php echo !$filterStock ? 'btn-primary' : 'btn-outline-secondary'; ?>" data-testid="filter-all">
                                All <span class="badge bg-white text-dark ms-1"><?php echo $totalItems; ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=critical" class="btn btn-sm <?php echo $filterStock === 'critical' ? 'btn-danger' : 'btn-outline-secondary'; ?>" data-testid="filter-critical">
                                Critical <span class="badge bg-white text-dark ms-1"><?php echo $criticalCount; ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=low" class="btn btn-sm <?php echo $filterStock === 'low' ? 'btn-warning' : 'btn-outline-secondary'; ?>" data-testid="filter-low">
                                Low <span class="badge bg-white text-dark ms-1"><?php echo $lowCount; ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=normal" class="btn btn-sm <?php echo $filterStock === 'normal' ? 'btn-success' : 'btn-outline-secondary'; ?>" data-testid="filter-normal">
                                Normal <span class="badge bg-white text-dark ms-1"><?php echo $normalCount; ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=overstock" class="btn btn-sm <?php echo $filterStock === 'overstock' ? 'btn-info' : 'btn-outline-secondary'; ?>" data-testid="filter-overstock">
                                Overstock <span class="badge bg-white text-dark ms-1"><?php echo $overstockCount; ?></span>
                            </a>
                            <a href="stocks.php?filter_stock=zero" class="btn btn-sm <?php echo $filterStock === 'zero' ? 'btn-secondary' : 'btn-outline-secondary'; ?>" data-testid="filter-zero">
                                Zero Stock <span class="badge bg-white text-dark ms-1"><?php echo $zeroCount; ?></span>
                            </a>
                        </div>
                        <div class="ms-auto">
                            <a href="stocks.php" class="btn btn-sm btn-outline-secondary" data-testid="clear-filters">
                                <i class="bi bi-x-circle"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Table -->
            <div class="dashboard-card" data-testid="stock-table">
                <div class="desktop-table" style="overflow-x: auto;">
                    <table class="table-custom" id="stockTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Category</th>
                                <th>Purchase Price</th>
                                <th>Current Stock</th>
                                <th>Min Stock Level</th>
                                <th>Stock Status</th>
                                <th>Stock Value</th>
                                <th>Last Updated</th>
                                <?php if ($is_admin): ?>
                                    <th style="text-align: center;">Quick Update</th>
                                    <th style="text-align: center;">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($categories && $categories->num_rows > 0): ?>
                                <?php while ($item = $categories->fetch_assoc()): 
                                    $stock_status = getStockStatus($item['total_quantity'], $item['min_stock_level']);
                                    $stock_percentage = $item['min_stock_level'] > 0 ? min(($item['total_quantity'] / $item['min_stock_level']) * 100, 200) : 100;
                                    $bar_class = $stock_status['class'] === 'cancelled' ? 'critical' : 
                                                ($stock_status['class'] === 'pending' ? 'low' : 
                                                ($stock_status['class'] === 'info' ? 'overstock' : 'normal'));
                                ?>
                                    <tr data-testid="row-stock-<?php echo $item['id']; ?>">
                                        <td><span class="order-id">#<?php echo $item['id']; ?></span></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($item['category_name']); ?></td>
                                        <td class="value-highlight">₹<?php echo number_format($item['purchase_price'], 2); ?></td>
                                        <td>
                                            <div class="stock-bar-container">
                                                <span class="fw-semibold"><?php echo number_format($item['total_quantity'], 2); ?> PCS</span>
                                                <div class="stock-bar" title="<?php echo $stock_percentage; ?>% of minimum">
                                                    <div class="stock-bar-fill <?php echo $bar_class; ?>" style="width: <?php echo min($stock_percentage, 100); ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($item['min_stock_level'] > 0): ?>
                                                <span class="value-muted"><?php echo number_format($item['min_stock_level'], 2); ?> PCS</span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $stock_status['class']; ?>">
                                                <i class="bi <?php echo $stock_status['icon']; ?> me-1"></i>
                                                <?php echo $stock_status['text']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="stock-value-badge">
                                                <i class="bi bi-currency-rupee"></i>
                                                <?php echo number_format($item['purchase_price'] * $item['total_quantity'], 2); ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--text-muted); white-space: nowrap;"><?php echo date('M d, Y', strtotime($item['updated_at'])); ?></td>
                                        
                                        <?php if ($is_admin): ?>
                                            <td>
                                                <div class="quick-stock-form">
                                                    <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="update_stock">
                                                        <input type="hidden" name="category_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="operation" value="add">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn add" title="Add Stock">
                                                            <i class="bi bi-plus"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: flex; gap: 5px;" onsubmit="return validateStockForm(this)">
                                                        <input type="hidden" name="action" value="update_stock">
                                                        <input type="hidden" name="category_id" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="operation" value="subtract">
                                                        <input type="number" name="stock_change" class="quick-stock-input" placeholder="Qty" step="0.001" min="0.001" required>
                                                        <button type="submit" class="quick-stock-btn subtract" title="Remove Stock">
                                                            <i class="bi bi-dash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center justify-content-center gap-1">
                                                    <!-- Bulk Update Input -->
                                                    <div style="display: none;" class="bulk-input-<?php echo $item['id']; ?>">
                                                        <input type="number" form="bulkUpdateForm" name="stock_updates[<?php echo $item['id']; ?>]" class="form-control form-control-sm" style="width: 100px;" step="0.001" min="0" placeholder="New qty">
                                                    </div>
                                                    
                                                    <?php if ($item['total_quantity'] <= 0): ?>
                                                        <!-- Delete Zero Stock -->
                                                        <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="display: inline;" 
                                                              onsubmit="return confirm('Delete this category? It has zero stock.')">
                                                            <input type="hidden" name="action" value="clear_zero_stock">
                                                            <input type="hidden" name="category_id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size: 12px; padding: 3px 8px;" title="Delete (Zero Stock)">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" style="padding: 12px;">
                    <?php
                        // Reset result pointer for mobile view
                        if ($categories && $categories->num_rows > 0) {
                            $categories->data_seek(0);
                        }
                    ?>
                    <?php if ($categories && $categories->num_rows > 0): ?>
                        <?php while ($mItem = $categories->fetch_assoc()): 
                            $stock_status = getStockStatus($mItem['total_quantity'], $mItem['min_stock_level']);
                            $stock_percentage = $mItem['min_stock_level'] > 0 ? min(($mItem['total_quantity'] / $mItem['min_stock_level']) * 100, 200) : 100;
                        ?>
                            <div class="mobile-card" data-testid="mobile-card-stock-<?php echo $mItem['id']; ?>">
                                <div class="mobile-card-header">
                                    <div>
                                        <span class="order-id">#<?php echo $mItem['id']; ?></span>
                                        <span class="customer-name ms-2 fw-semibold"><?php echo htmlspecialchars($mItem['category_name']); ?></span>
                                    </div>
                                    <span class="status-badge <?php echo $stock_status['class']; ?>">
                                        <i class="bi <?php echo $stock_status['icon']; ?> me-1"></i>
                                        <?php echo $stock_status['text']; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Purchase Price</span>
                                    <span class="mobile-card-value">₹<?php echo number_format($mItem['purchase_price'], 2); ?> / PCS</span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Current Stock</span>
                                    <span class="mobile-card-value">
                                        <span class="fw-semibold"><?php echo number_format($mItem['total_quantity'], 2); ?> PCS</span>
                                        <div class="stock-bar mt-1" style="width: 100%;">
                                            <div class="stock-bar-fill <?php echo $stock_status['class'] === 'cancelled' ? 'critical' : ($stock_status['class'] === 'pending' ? 'low' : 'normal'); ?>" 
                                                 style="width: <?php echo min($stock_percentage, 100); ?>%;"></div>
                                        </div>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Min Stock Level</span>
                                    <span class="mobile-card-value">
                                        <?php if ($mItem['min_stock_level'] > 0): ?>
                                            <?php echo number_format($mItem['min_stock_level'], 2); ?> PCS
                                        <?php else: ?>
                                            <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Stock Value</span>
                                    <span class="mobile-card-value fw-semibold" style="color: var(--primary);">
                                        ₹<?php echo number_format($mItem['purchase_price'] * $mItem['total_quantity'], 2); ?>
                                    </span>
                                </div>
                                
                                <div class="mobile-card-row">
                                    <span class="mobile-card-label">Last Updated</span>
                                    <span class="mobile-card-value"><?php echo date('M d, Y', strtotime($mItem['updated_at'])); ?></span>
                                </div>
                                
                                <?php if ($is_admin): ?>
                                    <div class="mobile-card-actions flex-column">
                                        <div class="d-flex gap-2 mb-2 w-100">
                                            <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                                <input type="hidden" name="action" value="update_stock">
                                                <input type="hidden" name="category_id" value="<?php echo $mItem['id']; ?>">
                                                <input type="hidden" name="operation" value="add">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="stock_change" class="form-control" placeholder="Add qty" step="0.001" min="0.001" required>
                                                    <button type="submit" class="btn btn-success" type="button">
                                                        <i class="bi bi-plus"></i> Add
                                                    </button>
                                                </div>
                                            </form>
                                            <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" style="flex: 1;" onsubmit="return validateStockForm(this)">
                                                <input type="hidden" name="action" value="update_stock">
                                                <input type="hidden" name="category_id" value="<?php echo $mItem['id']; ?>">
                                                <input type="hidden" name="operation" value="subtract">
                                                <div class="input-group input-group-sm">
                                                    <input type="number" name="stock_change" class="form-control" placeholder="Remove qty" step="0.001" min="0.001" required>
                                                    <button type="submit" class="btn btn-danger" type="button">
                                                        <i class="bi bi-dash"></i> Remove
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <?php if ($mItem['total_quantity'] <= 0): ?>
                                            <form method="POST" action="stocks.php<?php echo $filterStock ? '?filter_stock='.$filterStock : ''; ?>" class="w-100" 
                                                  onsubmit="return confirm('Delete this category? It has zero stock.')">
                                                <input type="hidden" name="action" value="clear_zero_stock">
                                                <input type="hidden" name="category_id" value="<?php echo $mItem['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                                    <i class="bi bi-trash me-1"></i>Delete Zero Stock Item
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 16px; color: var(--text-muted);">
                            <i class="bi bi-boxes d-block mb-2" style="font-size: 36px;"></i>
                            <div style="font-size: 15px; font-weight: 500; margin-bottom: 4px;">No stock items found</div>
                            <div style="font-size: 13px;">
                                <?php if ($filterStock): ?>
                                    Try changing your filters or <a href="stocks.php">view all stock</a>
                                <?php else: ?>
                                    <a href="categories.php">Add categories</a> to start tracking stock
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

<?php include 'includes/scripts.php'; ?>
<script>
$(document).ready(function() {
    $('#stockTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search stock:",
            lengthMenu: "Show _MENU_ items",
            info: "Showing _START_ to _END_ of _TOTAL_ items",
            emptyTable: "No stock items available"
        },
        columnDefs: [
            <?php if ($is_admin): ?>
            { orderable: false, targets: [-1, -2] }
            <?php else: ?>
            { orderable: false, targets: [] }
            <?php endif; ?>
        ]
    });
});

// Toggle Bulk Update Mode
function toggleBulkUpdate() {
    const bulkMode = document.getElementById('bulkUpdateMode');
    const toggleBtn = document.getElementById('toggleBulkUpdate');
    
    if (bulkMode.style.display === 'none') {
        bulkMode.style.display = 'block';
        toggleBtn.innerHTML = '<i class="bi bi-x-circle"></i> Cancel Bulk Update';
        toggleBtn.classList.remove('btn-outline-custom');
        toggleBtn.classList.add('btn-outline-danger');
        
        // Show bulk input fields
        document.querySelectorAll('[class^="bulk-input-"]').forEach(el => {
            el.style.display = 'inline-block';
        });
    } else {
        bulkMode.style.display = 'none';
        toggleBtn.innerHTML = '<i class="bi bi-pencil-square"></i> Bulk Update';
        toggleBtn.classList.remove('btn-outline-danger');
        toggleBtn.classList.add('btn-outline-custom');
        
        // Hide bulk input fields
        document.querySelectorAll('[class^="bulk-input-"]').forEach(el => {
            el.style.display = 'none';
        });
    }
}

// Validate stock form
function validateStockForm(form) {
    const input = form.querySelector('input[name="stock_change"]');
    const value = parseFloat(input.value);
    
    if (isNaN(value) || value <= 0) {
        alert('Please enter a valid positive quantity.');
        return false;
    }
    
    // Check if subtracting and show warning
    const operation = form.querySelector('input[name="operation"]')?.value;
    if (operation === 'subtract') {
        const row = form.closest('tr');
        if (row) {
            const stockCell = row.querySelector('td:nth-child(4) .fw-semibold');
            if (stockCell) {
                const currentStock = parseFloat(stockCell.textContent);
                if (value > currentStock) {
                    alert('Cannot remove more than current stock quantity.');
                    return false;
                }
            }
        }
    }
    
    return true;
}

// Quick filter buttons
document.querySelectorAll('[data-filter]').forEach(btn => {
    btn.addEventListener('click', function() {
        const filter = this.dataset.filter;
        window.location.href = 'stocks.php?filter_stock=' + filter;
    });
});
</script>
</body>
</html>