<?php
session_start();
$currentPage = 'bank-stocks';
$pageTitle = 'Bank Stock Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager', 'accountant'])) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Create bank_stocks table if it doesn't exist
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'bank_stocks'");
if (mysqli_num_rows($table_check) == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `bank_stocks` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stock_code` varchar(50) NOT NULL,
        `stock_name` varchar(200) NOT NULL,
        `stock_type` enum('share','bond','mutual_fund','fixed_deposit','gold','silver','other') DEFAULT 'other',
        `bank_id` int(11) DEFAULT NULL,
        `purchase_date` date NOT NULL,
        `purchase_price` decimal(15,2) NOT NULL,
        `current_price` decimal(15,2) DEFAULT NULL,
        `quantity` decimal(15,3) NOT NULL DEFAULT 1.000,
        `total_investment` decimal(15,2) NOT NULL,
        `current_value` decimal(15,2) DEFAULT NULL,
        `profit_loss` decimal(15,2) DEFAULT NULL,
        `profit_loss_percentage` decimal(10,2) DEFAULT NULL,
        `isin_code` varchar(50) DEFAULT NULL,
        `symbol` varchar(50) DEFAULT NULL,
        `maturity_date` date DEFAULT NULL,
        `interest_rate` decimal(10,2) DEFAULT NULL,
        `dividend_yield` decimal(10,2) DEFAULT NULL,
        `broker_name` varchar(200) DEFAULT NULL,
        `brokerage` decimal(10,2) DEFAULT NULL,
        `certificate_number` varchar(100) DEFAULT NULL,
        `holder_name` varchar(200) NOT NULL,
        `nominee_name` varchar(200) DEFAULT NULL,
        `remarks` text DEFAULT NULL,
        `document_path` varchar(255) DEFAULT NULL,
        `status` enum('active','sold','matured','closed') DEFAULT 'active',
        `sold_date` date DEFAULT NULL,
        `sold_price` decimal(15,2) DEFAULT NULL,
        `sold_value` decimal(15,2) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `stock_code` (`stock_code`),
        KEY `bank_id` (`bank_id`),
        KEY `stock_type` (`stock_type`),
        KEY `status` (`status`),
        KEY `purchase_date` (`purchase_date`),
        KEY `maturity_date` (`maturity_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_table);
}

// Create stock_transactions table
$trans_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'stock_transactions'");
if (mysqli_num_rows($trans_table_check) == 0) {
    $create_trans = "CREATE TABLE IF NOT EXISTS `stock_transactions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stock_id` int(11) NOT NULL,
        `transaction_date` date NOT NULL,
        `transaction_type` enum('buy','sell','dividend','bonus','split') NOT NULL,
        `quantity` decimal(15,3) NOT NULL,
        `price` decimal(15,2) NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `brokerage` decimal(10,2) DEFAULT NULL,
        `net_amount` decimal(15,2) NOT NULL,
        `remarks` text DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `stock_id` (`stock_id`),
        KEY `transaction_date` (`transaction_date`),
        KEY `transaction_type` (`transaction_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_trans);
}

// Create stock_dividends table
$div_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'stock_dividends'");
if (mysqli_num_rows($div_table_check) == 0) {
    $create_div = "CREATE TABLE IF NOT EXISTS `stock_dividends` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `stock_id` int(11) NOT NULL,
        `declaration_date` date NOT NULL,
        `ex_date` date NOT NULL,
        `record_date` date NOT NULL,
        `payment_date` date DEFAULT NULL,
        `dividend_per_share` decimal(10,2) NOT NULL,
        `total_amount` decimal(15,2) NOT NULL,
        `tax_amount` decimal(10,2) DEFAULT NULL,
        `net_amount` decimal(15,2) NOT NULL,
        `status` enum('declared','paid','pending') DEFAULT 'pending',
        `remarks` text DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `stock_id` (`stock_id`),
        KEY `ex_date` (`ex_date`),
        KEY `payment_date` (`payment_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    mysqli_query($conn, $create_div);
}

// Get filter parameters
$stock_type = isset($_GET['stock_type']) ? $_GET['stock_type'] : 'all';
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'active';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$export = isset($_GET['export']);

// Build WHERE conditions
$where_conditions = ["1=1"];
$params = [];
$types = '';

// Stock type filter
if ($stock_type != 'all') {
    $where_conditions[] = "s.stock_type = ?";
    $params[] = $stock_type;
    $types .= 's';
}

// Bank filter
if ($bank_id > 0) {
    $where_conditions[] = "s.bank_id = ?";
    $params[] = $bank_id;
    $types .= 'i';
}

// Status filter
if ($status != 'all') {
    $where_conditions[] = "s.status = ?";
    $params[] = $status;
    $types .= 's';
}

// Date range filter
if ($date_from && $date_to) {
    $where_conditions[] = "DATE(s.purchase_date) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

$where_clause = implode(' AND ', $where_conditions);

// Get banks for dropdown
$banks_query = "SELECT id, bank_full_name, bank_short_name FROM bank_master WHERE status = 1 ORDER BY bank_full_name";
$banks_result = mysqli_query($conn, $banks_query);

// Get all stocks with details
$query = "SELECT s.*, 
          b.bank_short_name, b.bank_full_name,
          u.name as created_by_name,
          (SELECT SUM(amount) FROM stock_transactions WHERE stock_id = s.id AND transaction_type = 'dividend') as total_dividends,
          (SELECT COUNT(*) FROM stock_dividends WHERE stock_id = s.id AND status = 'paid') as dividend_count
          FROM bank_stocks s
          LEFT JOIN bank_master b ON s.bank_id = b.id
          LEFT JOIN users u ON s.created_by = u.id
          WHERE $where_clause
          ORDER BY s.purchase_date DESC";

$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Get summary statistics
$summary_query = "SELECT 
                    COUNT(*) as total_stocks,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_stocks,
                    SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_stocks,
                    SUM(CASE WHEN status = 'matured' THEN 1 ELSE 0 END) as matured_stocks,
                    COALESCE(SUM(total_investment), 0) as total_investment,
                    COALESCE(SUM(current_value), 0) as total_current_value,
                    COALESCE(SUM(profit_loss), 0) as total_profit_loss,
                    AVG(profit_loss_percentage) as avg_profit_percentage,
                    COUNT(DISTINCT stock_type) as type_count
                  FROM bank_stocks s
                  WHERE $where_clause";

$summary_stmt = mysqli_prepare($conn, $summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($summary_stmt);
$summary_result = mysqli_stmt_get_result($summary_stmt);
$summary = mysqli_fetch_assoc($summary_result);

// Get portfolio summary by type
$type_summary_query = "SELECT 
                        stock_type,
                        COUNT(*) as count,
                        SUM(total_investment) as total_investment,
                        SUM(current_value) as current_value,
                        SUM(profit_loss) as profit_loss,
                        AVG(profit_loss_percentage) as avg_profit_percentage
                      FROM bank_stocks s
                      WHERE $where_clause
                      GROUP BY stock_type
                      ORDER BY total_investment DESC";

$type_summary_stmt = mysqli_prepare($conn, $type_summary_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($type_summary_stmt, $types, ...$params);
}
mysqli_stmt_execute($type_summary_stmt);
$type_summary_result = mysqli_stmt_get_result($type_summary_stmt);

// Get upcoming maturities
$maturity_query = "SELECT s.*, b.bank_short_name,
                  DATEDIFF(s.maturity_date, CURDATE()) as days_to_maturity
                  FROM bank_stocks s
                  LEFT JOIN bank_master b ON s.bank_id = b.id
                  WHERE s.status = 'active' 
                  AND s.maturity_date IS NOT NULL
                  AND s.maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                  ORDER BY s.maturity_date ASC
                  LIMIT 10";

$maturity_result = mysqli_query($conn, $maturity_query);

// Get top performing stocks
$top_performing_query = "SELECT s.*, b.bank_short_name
                        FROM bank_stocks s
                        LEFT JOIN bank_master b ON s.bank_id = b.id
                        WHERE s.status = 'active' AND s.profit_loss_percentage IS NOT NULL
                        ORDER BY s.profit_loss_percentage DESC
                        LIMIT 5";

$top_performing_result = mysqli_query($conn, $top_performing_query);

// Get worst performing stocks
$worst_performing_query = "SELECT s.*, b.bank_short_name
                          FROM bank_stocks s
                          LEFT JOIN bank_master b ON s.bank_id = b.id
                          WHERE s.status = 'active' AND s.profit_loss_percentage IS NOT NULL
                          ORDER BY s.profit_loss_percentage ASC
                          LIMIT 5";

$worst_performing_result = mysqli_query($conn, $worst_performing_query);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_stock':
                $stock_code = mysqli_real_escape_string($conn, $_POST['stock_code']);
                $stock_name = mysqli_real_escape_string($conn, $_POST['stock_name']);
                $stock_type = mysqli_real_escape_string($conn, $_POST['stock_type']);
                $bank_id = !empty($_POST['bank_id']) ? intval($_POST['bank_id']) : null;
                $purchase_date = mysqli_real_escape_string($conn, $_POST['purchase_date']);
                $purchase_price = floatval($_POST['purchase_price']);
                $current_price = !empty($_POST['current_price']) ? floatval($_POST['current_price']) : null;
                $quantity = floatval($_POST['quantity']);
                $isin_code = mysqli_real_escape_string($conn, $_POST['isin_code'] ?? '');
                $symbol = mysqli_real_escape_string($conn, $_POST['symbol'] ?? '');
                $maturity_date = !empty($_POST['maturity_date']) ? mysqli_real_escape_string($conn, $_POST['maturity_date']) : null;
                $interest_rate = !empty($_POST['interest_rate']) ? floatval($_POST['interest_rate']) : null;
                $dividend_yield = !empty($_POST['dividend_yield']) ? floatval($_POST['dividend_yield']) : null;
                $broker_name = mysqli_real_escape_string($conn, $_POST['broker_name'] ?? '');
                $brokerage = !empty($_POST['brokerage']) ? floatval($_POST['brokerage']) : null;
                $certificate_number = mysqli_real_escape_string($conn, $_POST['certificate_number'] ?? '');
                $holder_name = mysqli_real_escape_string($conn, $_POST['holder_name']);
                $nominee_name = mysqli_real_escape_string($conn, $_POST['nominee_name'] ?? '');
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
                
                // Calculate totals
                $total_investment = $purchase_price * $quantity;
                $current_value = $current_price ? $current_price * $quantity : null;
                $profit_loss = $current_value ? $current_value - $total_investment : null;
                $profit_loss_percentage = $total_investment > 0 && $current_value ? ($profit_loss / $total_investment) * 100 : null;
                
                // Check if stock code already exists
                $check_query = "SELECT id FROM bank_stocks WHERE stock_code = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $stock_code);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Stock code already exists!";
                } else {
                    // Handle document upload
                    $document_path = null;
                    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
                        $upload_dir = 'uploads/stocks/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $ext = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
                        $filename = 'stock_' . $stock_code . '_' . time() . '.' . $ext;
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($_FILES['document']['tmp_name'], $filepath)) {
                            $document_path = $filepath;
                        }
                    }
                    
                    $insert_query = "INSERT INTO bank_stocks (
                        stock_code, stock_name, stock_type, bank_id, purchase_date,
                        purchase_price, current_price, quantity, total_investment,
                        current_value, profit_loss, profit_loss_percentage,
                        isin_code, symbol, maturity_date, interest_rate,
                        dividend_yield, broker_name, brokerage, certificate_number,
                        holder_name, nominee_name, remarks, document_path, status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)";
                    
                    $stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($stmt, 'sssisdddddddsdssdssdsssi', 
                        $stock_code, $stock_name, $stock_type, $bank_id, $purchase_date,
                        $purchase_price, $current_price, $quantity, $total_investment,
                        $current_value, $profit_loss, $profit_loss_percentage,
                        $isin_code, $symbol, $maturity_date, $interest_rate,
                        $dividend_yield, $broker_name, $brokerage, $certificate_number,
                        $holder_name, $nominee_name, $remarks, $document_path, $_SESSION['user_id']
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $stock_id = mysqli_insert_id($conn);
                        
                        // Log the activity
                        $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                      VALUES (?, 'create', ?, 'bank_stocks', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $description = "Added new stock: " . $stock_name . " (" . $stock_code . ")";
                        mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $description, $stock_id);
                        mysqli_stmt_execute($log_stmt);
                        
                        $message = "Stock added successfully!";
                    } else {
                        $error = "Error adding stock: " . mysqli_stmt_error($stmt);
                    }
                }
                break;
                
            case 'edit_stock':
                $id = intval($_POST['id']);
                $stock_name = mysqli_real_escape_string($conn, $_POST['stock_name']);
                $stock_type = mysqli_real_escape_string($conn, $_POST['stock_type']);
                $bank_id = !empty($_POST['bank_id']) ? intval($_POST['bank_id']) : null;
                $current_price = !empty($_POST['current_price']) ? floatval($_POST['current_price']) : null;
                $quantity = floatval($_POST['quantity']);
                $isin_code = mysqli_real_escape_string($conn, $_POST['isin_code'] ?? '');
                $symbol = mysqli_real_escape_string($conn, $_POST['symbol'] ?? '');
                $maturity_date = !empty($_POST['maturity_date']) ? mysqli_real_escape_string($conn, $_POST['maturity_date']) : null;
                $interest_rate = !empty($_POST['interest_rate']) ? floatval($_POST['interest_rate']) : null;
                $dividend_yield = !empty($_POST['dividend_yield']) ? floatval($_POST['dividend_yield']) : null;
                $broker_name = mysqli_real_escape_string($conn, $_POST['broker_name'] ?? '');
                $brokerage = !empty($_POST['brokerage']) ? floatval($_POST['brokerage']) : null;
                $certificate_number = mysqli_real_escape_string($conn, $_POST['certificate_number'] ?? '');
                $holder_name = mysqli_real_escape_string($conn, $_POST['holder_name']);
                $nominee_name = mysqli_real_escape_string($conn, $_POST['nominee_name'] ?? '');
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
                $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'active');
                
                // Get current purchase price and quantity
                $price_query = "SELECT purchase_price, quantity FROM bank_stocks WHERE id = ?";
                $price_stmt = mysqli_prepare($conn, $price_query);
                mysqli_stmt_bind_param($price_stmt, 'i', $id);
                mysqli_stmt_execute($price_stmt);
                $price_result = mysqli_stmt_get_result($price_stmt);
                $stock_data = mysqli_fetch_assoc($price_result);
                
                $purchase_price = $stock_data['purchase_price'];
                $total_investment = $purchase_price * $quantity;
                $current_value = $current_price ? $current_price * $quantity : null;
                $profit_loss = $current_value ? $current_value - $total_investment : null;
                $profit_loss_percentage = $total_investment > 0 && $current_value ? ($profit_loss / $total_investment) * 100 : null;
                
                $update_query = "UPDATE bank_stocks SET 
                                stock_name = ?, stock_type = ?, bank_id = ?,
                                current_price = ?, quantity = ?, current_value = ?,
                                profit_loss = ?, profit_loss_percentage = ?,
                                isin_code = ?, symbol = ?, maturity_date = ?,
                                interest_rate = ?, dividend_yield = ?, broker_name = ?,
                                brokerage = ?, certificate_number = ?, holder_name = ?,
                                nominee_name = ?, remarks = ?, status = ?
                                WHERE id = ?";
                
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, 'ssiddddsssdssdsssssi', 
                    $stock_name, $stock_type, $bank_id,
                    $current_price, $quantity, $current_value,
                    $profit_loss, $profit_loss_percentage,
                    $isin_code, $symbol, $maturity_date,
                    $interest_rate, $dividend_yield, $broker_name,
                    $brokerage, $certificate_number, $holder_name,
                    $nominee_name, $remarks, $status, $id
                );
                
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Stock updated successfully!";
                } else {
                    $error = "Error updating stock: " . mysqli_stmt_error($stmt);
                }
                break;
                
            case 'sell_stock':
                $id = intval($_POST['id']);
                $sold_date = mysqli_real_escape_string($conn, $_POST['sold_date']);
                $sold_price = floatval($_POST['sold_price']);
                $quantity_sold = floatval($_POST['quantity_sold']);
                $brokerage = floatval($_POST['brokerage'] ?? 0);
                $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
                
                // Get stock details
                $stock_query = "SELECT * FROM bank_stocks WHERE id = ?";
                $stock_stmt = mysqli_prepare($conn, $stock_query);
                mysqli_stmt_bind_param($stock_stmt, 'i', $id);
                mysqli_stmt_execute($stock_stmt);
                $stock_result = mysqli_stmt_get_result($stock_stmt);
                $stock = mysqli_fetch_assoc($stock_result);
                
                if ($stock) {
                    $sold_value = $sold_price * $quantity_sold;
                    $net_amount = $sold_value - $brokerage;
                    $purchase_value = $stock['purchase_price'] * $quantity_sold;
                    $profit_loss = $net_amount - $purchase_value;
                    
                    // Begin transaction
                    mysqli_begin_transaction($conn);
                    
                    try {
                        // Record transaction
                        $trans_query = "INSERT INTO stock_transactions (
                            stock_id, transaction_date, transaction_type, quantity,
                            price, amount, brokerage, net_amount, remarks, created_by
                        ) VALUES (?, ?, 'sell', ?, ?, ?, ?, ?, ?, ?)";
                        
                        $trans_stmt = mysqli_prepare($conn, $trans_query);
                        mysqli_stmt_bind_param($trans_stmt, 'isdddddsi', 
                            $id, $sold_date, $quantity_sold, $sold_price,
                            $sold_value, $brokerage, $net_amount, $remarks, $_SESSION['user_id']
                        );
                        mysqli_stmt_execute($trans_stmt);
                        
                        // Update stock
                        $new_quantity = $stock['quantity'] - $quantity_sold;
                        
                        if ($new_quantity <= 0) {
                            // Fully sold
                            $update_query = "UPDATE bank_stocks SET 
                                            status = 'sold', sold_date = ?, sold_price = ?,
                                            sold_value = ?, quantity = 0
                                            WHERE id = ?";
                            $update_stmt = mysqli_prepare($conn, $update_query);
                            mysqli_stmt_bind_param($update_stmt, 'sddi', $sold_date, $sold_price, $sold_value, $id);
                        } else {
                            // Partially sold
                            $update_query = "UPDATE bank_stocks SET quantity = ? WHERE id = ?";
                            $update_stmt = mysqli_prepare($conn, $update_query);
                            mysqli_stmt_bind_param($update_stmt, 'di', $new_quantity, $id);
                        }
                        mysqli_stmt_execute($update_stmt);
                        
                        mysqli_commit($conn);
                        $message = "Stock sold successfully!";
                        
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $error = "Error selling stock: " . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_stock':
                $id = intval($_POST['id']);
                
                // Check if there are transactions
                $check_query = "SELECT COUNT(*) as count FROM stock_transactions WHERE stock_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 'i', $id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                $check = mysqli_fetch_assoc($check_result);
                
                if ($check['count'] > 0) {
                    $error = "Cannot delete stock with transactions!";
                } else {
                    $delete_query = "DELETE FROM bank_stocks WHERE id = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    mysqli_stmt_bind_param($delete_stmt, 'i', $id);
                    
                    if (mysqli_stmt_execute($delete_stmt)) {
                        $message = "Stock deleted successfully!";
                    } else {
                        $error = "Error deleting stock: " . mysqli_stmt_error($delete_stmt);
                    }
                }
                break;
        }
    }
}

// Handle export
if ($export) {
    exportStockReport($conn, $result, $date_from, $date_to);
}

function exportStockReport($conn, $data, $from, $to) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add report header
    fputcsv($output, ['BANK STOCK REPORT']);
    fputcsv($output, ['Period', $from . ' to ' . $to]);
    fputcsv($output, ['Generated on', date('d-m-Y H:i:s')]);
    fputcsv($output, []);
    
    // Add column headers
    fputcsv($output, [
        'Stock Code', 'Stock Name', 'Type', 'Bank', 'Purchase Date',
        'Purchase Price', 'Current Price', 'Quantity', 'Total Investment',
        'Current Value', 'Profit/Loss', 'P/L %', 'ISIN', 'Symbol',
        'Maturity Date', 'Holder Name', 'Status'
    ]);
    
    // Add data rows
    mysqli_data_seek($data, 0);
    while ($row = mysqli_fetch_assoc($data)) {
        fputcsv($output, [
            $row['stock_code'],
            $row['stock_name'],
            $row['stock_type'],
            $row['bank_short_name'] ?? '-',
            $row['purchase_date'],
            $row['purchase_price'],
            $row['current_price'] ?? '-',
            $row['quantity'],
            $row['total_investment'],
            $row['current_value'] ?? '-',
            $row['profit_loss'] ?? '-',
            $row['profit_loss_percentage'] ? number_format($row['profit_loss_percentage'], 2) . '%' : '-',
            $row['isin_code'] ?? '-',
            $row['symbol'] ?? '-',
            $row['maturity_date'] ?? '-',
            $row['holder_name'],
            $row['status']
        ]);
    }
    
    fclose($output);
    exit();
}

// Stock type options
$stock_types = [
    'share' => 'Shares/Stocks',
    'bond' => 'Bonds',
    'mutual_fund' => 'Mutual Funds',
    'fixed_deposit' => 'Fixed Deposits',
    'gold' => 'Gold',
    'silver' => 'Silver',
    'other' => 'Other'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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

        .stocks-container {
            max-width: 1600px;
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
            background: #ecc94b;
            color: #744210;
        }

        .btn-warning:hover {
            background: #d69e2e;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

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

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 5px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 12px;
            color: #a0aec0;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Portfolio Summary */
        .portfolio-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .portfolio-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .portfolio-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .portfolio-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #e2e8f0;
        }

        .portfolio-label {
            color: #718096;
        }

        .portfolio-value {
            font-weight: 600;
            color: #2d3748;
        }

        .profit {
            color: #48bb78;
        }

        .loss {
            color: #f56565;
        }

        /* Table Card */
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
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
        }

        .table-info {
            color: #718096;
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .stocks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .stocks-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }

        .stocks-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .stocks-table tbody tr:hover {
            background: #f7fafc;
        }

        .stocks-table tfoot {
            background: #f7fafc;
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-share {
            background: #4299e1;
            color: white;
        }

        .badge-bond {
            background: #48bb78;
            color: white;
        }

        .badge-mutual_fund {
            background: #9f7aea;
            color: white;
        }

        .badge-fixed_deposit {
            background: #ecc94b;
            color: #744210;
        }

        .badge-gold {
            background: #ed8936;
            color: white;
        }

        .badge-silver {
            background: #a0aec0;
            color: white;
        }

        .badge-active {
            background: #48bb78;
            color: white;
        }

        .badge-sold {
            background: #f56565;
            color: white;
        }

        .badge-matured {
            background: #4299e1;
            color: white;
        }

        .text-right {
            text-align: right;
        }

        .profit {
            color: #48bb78;
            font-weight: 600;
        }

        .loss {
            color: #f56565;
            font-weight: 600;
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
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-icon.edit:hover {
            background: #4299e1;
            color: white;
        }

        .btn-icon.sell:hover {
            background: #48bb78;
            color: white;
        }

        .btn-icon.delete:hover {
            background: #f56565;
            color: white;
        }

        .btn-icon.view:hover {
            background: #9f7aea;
            color: white;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin: 0;
        }

        .modal-close {
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }

        .modal-close:hover {
            color: #f56565;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .portfolio-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer .btn {
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
                <div class="stocks-container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-graph-up"></i>
                            Bank Stock Management
                        </h1>
                        <div>
                            <button class="btn btn-primary" onclick="showAddStockModal()">
                                <i class="bi bi-plus-circle"></i> Add Stock
                            </button>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 1])); ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-excel"></i> Export CSV
                            </a>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <!-- Summary Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-pie-chart"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Stocks</div>
                                <div class="stat-value"><?php echo number_format($summary['total_stocks'] ?? 0); ?></div>
                                <div class="stat-sub">Active: <?php echo number_format($summary['active_stocks'] ?? 0); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Investment</div>
                                <div class="stat-value">₹<?php echo number_format($summary['total_investment'] ?? 0, 2); ?></div>
                                <div class="stat-sub">Current: ₹<?php echo number_format($summary['total_current_value'] ?? 0, 2); ?></div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Profit/Loss</div>
                                <div class="stat-value <?php echo ($summary['total_profit_loss'] ?? 0) >= 0 ? 'profit' : 'loss'; ?>">
                                    ₹<?php echo number_format(abs($summary['total_profit_loss'] ?? 0), 2); ?>
                                </div>
                                <div class="stat-sub">Avg Return: <?php echo number_format($summary['avg_profit_percentage'] ?? 0, 2); ?>%</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Asset Types</div>
                                <div class="stat-value"><?php echo number_format($summary['type_count'] ?? 0); ?></div>
                                <div class="stat-sub">Diversification</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Section -->
                    <div class="filter-card">
                        <div class="filter-title">
                            <i class="bi bi-funnel"></i>
                            Filter Stocks
                        </div>

                        <form method="GET" action="" id="filterForm">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Stock Type</label>
                                    <div class="input-group">
                                        <i class="bi bi-tag input-icon"></i>
                                        <select class="form-select" name="stock_type">
                                            <option value="all" <?php echo $stock_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                                            <?php foreach ($stock_types as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" <?php echo $stock_type == $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Bank</label>
                                    <div class="input-group">
                                        <i class="bi bi-bank input-icon"></i>
                                        <select class="form-select" name="bank_id">
                                            <option value="0">All Banks</option>
                                            <?php 
                                            if ($banks_result) {
                                                mysqli_data_seek($banks_result, 0);
                                                while($bank = mysqli_fetch_assoc($banks_result)): 
                                            ?>
                                                <option value="<?php echo $bank['id']; ?>" <?php echo $bank_id == $bank['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                                </option>
                                            <?php 
                                                endwhile;
                                            } 
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <div class="input-group">
                                        <i class="bi bi-check-circle input-icon"></i>
                                        <select class="form-select" name="status">
                                            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="sold" <?php echo $status == 'sold' ? 'selected' : ''; ?>>Sold</option>
                                            <option value="matured" <?php echo $status == 'matured' ? 'selected' : ''; ?>>Matured</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Date From</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_from" value="<?php echo $date_from; ?>">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Date To</label>
                                    <div class="input-group">
                                        <i class="bi bi-calendar input-icon"></i>
                                        <input type="date" class="form-control" name="date_to" value="<?php echo $date_to; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="bank-stocks.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Portfolio Summary by Type -->
                    <div class="portfolio-grid">
                        <div class="portfolio-card">
                            <div class="portfolio-title">Portfolio by Type</div>
                            <?php 
                            mysqli_data_seek($type_summary_result, 0);
                            while ($type = mysqli_fetch_assoc($type_summary_result)): 
                                $profit_class = ($type['profit_loss'] ?? 0) >= 0 ? 'profit' : 'loss';
                            ?>
                            <div class="portfolio-item">
                                <span class="portfolio-label">
                                    <span class="badge badge-<?php echo $type['stock_type']; ?>">
                                        <?php echo $stock_types[$type['stock_type']] ?? $type['stock_type']; ?>
                                    </span>
                                    (<?php echo $type['count']; ?>)
                                </span>
                                <span class="portfolio-value <?php echo $profit_class; ?>">
                                    ₹<?php echo number_format($type['current_value'] ?? 0, 2); ?>
                                </span>
                            </div>
                            <?php endwhile; ?>
                        </div>

                        <div class="portfolio-card">
                            <div class="portfolio-title">Upcoming Maturities</div>
                            <?php while ($maturity = mysqli_fetch_assoc($maturity_result)): 
                                $days = $maturity['days_to_maturity'];
                                $badge_class = $days <= 7 ? 'badge-danger' : ($days <= 30 ? 'badge-warning' : 'badge-info');
                            ?>
                            <div class="portfolio-item">
                                <span class="portfolio-label">
                                    <?php echo htmlspecialchars($maturity['stock_name']); ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $days; ?> days</span>
                                </span>
                                <span class="portfolio-value">
                                    ₹<?php echo number_format($maturity['current_value'] ?? $maturity['total_investment'], 2); ?>
                                </span>
                            </div>
                            <?php endwhile; ?>
                        </div>

                        <div class="portfolio-card">
                            <div class="portfolio-title">Top Performers</div>
                            <?php while ($top = mysqli_fetch_assoc($top_performing_result)): ?>
                            <div class="portfolio-item">
                                <span class="portfolio-label">
                                    <?php echo htmlspecialchars($top['stock_name']); ?>
                                    <small>(<?php echo $top['bank_short_name'] ?? '-'; ?>)</small>
                                </span>
                                <span class="portfolio-value profit">
                                    +<?php echo number_format($top['profit_loss_percentage'], 2); ?>%
                                </span>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <!-- Stocks Table -->
                    <div class="table-card">
                        <div class="table-header">
                            <span class="table-title">Stock Holdings</span>
                            <span class="table-info">
                                Total: <?php echo mysqli_num_rows($result); ?> stocks | 
                                Value: ₹<?php echo number_format($summary['total_current_value'] ?? 0, 2); ?>
                            </span>
                        </div>
                        <div class="table-responsive">
                            <table class="stocks-table" id="stocksTable">
                                <thead>
                                    <tr>
                                        <th>Stock Code</th>
                                        <th>Stock Name</th>
                                        <th>Type</th>
                                        <th>Bank</th>
                                        <th>Purchase Date</th>
                                        <th class="text-right">Quantity</th>
                                        <th class="text-right">Purchase Price</th>
                                        <th class="text-right">Current Price</th>
                                        <th class="text-right">Investment</th>
                                        <th class="text-right">Current Value</th>
                                        <th class="text-right">P/L</th>
                                        <th class="text-right">P/L %</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_investment = 0;
                                    $total_current = 0;
                                    $total_pl = 0;
                                    
                                    while ($stock = mysqli_fetch_assoc($result)): 
                                        $total_investment += $stock['total_investment'];
                                        $total_current += $stock['current_value'] ?? 0;
                                        $total_pl += $stock['profit_loss'] ?? 0;
                                        
                                        $pl_class = ($stock['profit_loss'] ?? 0) >= 0 ? 'profit' : 'loss';
                                        $pl_percentage_class = ($stock['profit_loss_percentage'] ?? 0) >= 0 ? 'profit' : 'loss';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $stock['stock_code']; ?></strong></td>
                                        <td><?php echo htmlspecialchars($stock['stock_name']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $stock['stock_type']; ?>">
                                                <?php echo $stock_types[$stock['stock_type']] ?? $stock['stock_type']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($stock['bank_short_name'] ?? '-'); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($stock['purchase_date'])); ?></td>
                                        <td class="text-right"><?php echo number_format($stock['quantity'], 3); ?></td>
                                        <td class="text-right">₹<?php echo number_format($stock['purchase_price'], 2); ?></td>
                                        <td class="text-right">
                                            <?php echo $stock['current_price'] ? '₹' . number_format($stock['current_price'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-right">₹<?php echo number_format($stock['total_investment'], 2); ?></td>
                                        <td class="text-right">
                                            <?php echo $stock['current_value'] ? '₹' . number_format($stock['current_value'], 2) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $pl_class; ?>">
                                            <?php echo $stock['profit_loss'] ? '₹' . number_format(abs($stock['profit_loss']), 2) : '-'; ?>
                                        </td>
                                        <td class="text-right <?php echo $pl_percentage_class; ?>">
                                            <?php echo $stock['profit_loss_percentage'] ? number_format($stock['profit_loss_percentage'], 2) . '%' : '-'; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $stock['status']; ?>">
                                                <?php echo ucfirst($stock['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon view" onclick="viewStock(<?php echo $stock['id']; ?>)" title="View Details">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <?php if ($stock['status'] == 'active'): ?>
                                                <button class="btn-icon edit" onclick='editStock(<?php echo json_encode($stock); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn-icon sell" onclick="sellStock(<?php echo $stock['id']; ?>)" title="Sell">
                                                    <i class="bi bi-cash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn-icon delete" onclick="deleteStock(<?php echo $stock['id']; ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                                <tfoot>
                                    <tr style="background: #f7fafc; font-weight: 600;">
                                        <td colspan="8" class="text-right"><strong>TOTALS:</strong></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($total_investment, 2); ?></strong></td>
                                        <td class="text-right"><strong>₹<?php echo number_format($total_current, 2); ?></strong></td>
                                        <td class="text-right <?php echo $total_pl >= 0 ? 'profit' : 'loss'; ?>">
                                            <strong>₹<?php echo number_format(abs($total_pl), 2); ?></strong>
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add Stock Modal -->
    <div class="modal" id="stockModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="stockModalTitle">Add New Stock</h3>
                <span class="modal-close" onclick="closeStockModal()">&times;</span>
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data" id="stockForm">
                <input type="hidden" name="action" id="stockAction" value="add_stock">
                <input type="hidden" name="id" id="stockId" value="">
                
                <div class="form-group">
                    <label class="form-label required">Stock Code</label>
                    <input type="text" class="form-control" name="stock_code" id="stockCode" required maxlength="50">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Stock Name</label>
                    <input type="text" class="form-control" name="stock_name" id="stockName" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Stock Type</label>
                        <select class="form-select" name="stock_type" id="stockType" required>
                            <?php foreach ($stock_types as $value => $label): ?>
                                <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Bank</label>
                        <select class="form-select" name="bank_id" id="stockBank">
                            <option value="">Select Bank (Optional)</option>
                            <?php 
                            if ($banks_result) {
                                mysqli_data_seek($banks_result, 0);
                                while($bank = mysqli_fetch_assoc($banks_result)): 
                            ?>
                                <option value="<?php echo $bank['id']; ?>">
                                    <?php echo htmlspecialchars($bank['bank_full_name']); ?>
                                </option>
                            <?php 
                                endwhile;
                            } 
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Purchase Date</label>
                        <input type="date" class="form-control" name="purchase_date" id="purchaseDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Purchase Price (per unit)</label>
                        <input type="number" class="form-control" name="purchase_price" id="purchasePrice" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Quantity</label>
                        <input type="number" class="form-control" name="quantity" id="quantity" step="0.001" min="0" required value="1">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Current Price (per unit)</label>
                        <input type="number" class="form-control" name="current_price" id="currentPrice" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">ISIN Code</label>
                        <input type="text" class="form-control" name="isin_code" id="isinCode">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Symbol</label>
                        <input type="text" class="form-control" name="symbol" id="symbol">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Maturity Date</label>
                        <input type="date" class="form-control" name="maturity_date" id="maturityDate">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Interest Rate (%)</label>
                        <input type="number" class="form-control" name="interest_rate" id="interestRate" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Dividend Yield (%)</label>
                        <input type="number" class="form-control" name="dividend_yield" id="dividendYield" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Broker Name</label>
                        <input type="text" class="form-control" name="broker_name" id="brokerName">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Brokerage (₹)</label>
                        <input type="number" class="form-control" name="brokerage" id="brokerage" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Certificate Number</label>
                        <input type="text" class="form-control" name="certificate_number" id="certificateNumber">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Holder Name</label>
                    <input type="text" class="form-control" name="holder_name" id="holderName" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nominee Name</label>
                    <input type="text" class="form-control" name="nominee_name" id="nomineeName">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Document</label>
                    <input type="file" class="form-control" name="document" accept=".pdf,.jpg,.jpeg,.png">
                    <small>Upload certificate or statement (PDF, JPG, PNG)</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" id="remarks" rows="2"></textarea>
                </div>
                
                <div class="form-group" id="stockStatusField" style="display: none;">
                    <label class="checkbox-item">
                        <input type="checkbox" name="is_active" id="isActive" checked>
                        <label for="isActive">Active</label>
                    </label>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeStockModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sell Stock Modal -->
    <div class="modal" id="sellModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Sell Stock</h3>
                <span class="modal-close" onclick="closeSellModal()">&times;</span>
            </div>
            
            <form method="POST" action="" id="sellForm">
                <input type="hidden" name="action" value="sell_stock">
                <input type="hidden" name="id" id="sellStockId" value="">
                
                <div class="form-group">
                    <label class="form-label required">Stock</label>
                    <input type="text" class="form-control" id="sellStockName" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Available Quantity</label>
                    <input type="text" class="form-control" id="sellAvailableQty" readonly>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Sell Date</label>
                        <input type="date" class="form-control" name="sold_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Quantity to Sell</label>
                        <input type="number" class="form-control" name="quantity_sold" id="sellQuantity" step="0.001" min="0" required onchange="calculateSellAmount()">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Sell Price (per unit)</label>
                        <input type="number" class="form-control" name="sold_price" id="sellPrice" step="0.01" min="0" required onchange="calculateSellAmount()">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Brokerage (₹)</label>
                        <input type="number" class="form-control" name="brokerage" id="sellBrokerage" step="0.01" min="0" value="0" onchange="calculateSellAmount()">
                    </div>
                </div>
                
                <div class="summary-box" style="background: #f7fafc; padding: 15px; border-radius: 8px; margin: 15px 0;">
                    <div class="calc-item">
                        <span>Sell Value:</span>
                        <span class="amount" id="sellValue">₹0.00</span>
                    </div>
                    <div class="calc-item">
                        <span>Brokerage:</span>
                        <span class="amount" id="sellBrokerageDisplay">₹0.00</span>
                    </div>
                    <div class="calc-item">
                        <span>Net Amount:</span>
                        <span class="amount" id="sellNetAmount">₹0.00</span>
                    </div>
                    <div class="calc-item" style="border-top: 2px solid #48bb78; margin-top: 10px; padding-top: 10px;">
                        <span style="font-weight: 700;">Profit/Loss:</span>
                        <span style="font-weight: 700; color: #48bb78;" id="sellProfitLoss">₹0.00</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeSellModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Sell Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Include required JS files -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.form-select').select2({
                width: '100%'
            });
        });

        // Initialize DataTable
        $(document).ready(function() {
            $('#stocksTable').DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
                order: [[4, 'desc']] // Sort by purchase date
            });
        });

        // Stock Modal Functions
        function showAddStockModal() {
            document.getElementById('stockModalTitle').textContent = 'Add New Stock';
            document.getElementById('stockAction').value = 'add_stock';
            document.getElementById('stockId').value = '';
            document.getElementById('stockCode').value = '';
            document.getElementById('stockCode').readOnly = false;
            document.getElementById('stockName').value = '';
            document.getElementById('stockType').value = 'share';
            document.getElementById('stockBank').value = '';
            document.getElementById('purchaseDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('purchasePrice').value = '';
            document.getElementById('quantity').value = '1';
            document.getElementById('currentPrice').value = '';
            document.getElementById('isinCode').value = '';
            document.getElementById('symbol').value = '';
            document.getElementById('maturityDate').value = '';
            document.getElementById('interestRate').value = '';
            document.getElementById('dividendYield').value = '';
            document.getElementById('brokerName').value = '';
            document.getElementById('brokerage').value = '';
            document.getElementById('certificateNumber').value = '';
            document.getElementById('holderName').value = '';
            document.getElementById('nomineeName').value = '';
            document.getElementById('remarks').value = '';
            document.getElementById('stockStatusField').style.display = 'none';
            
            document.getElementById('stockModal').classList.add('active');
        }

        function editStock(data) {
            document.getElementById('stockModalTitle').textContent = 'Edit Stock';
            document.getElementById('stockAction').value = 'edit_stock';
            document.getElementById('stockId').value = data.id;
            document.getElementById('stockCode').value = data.stock_code;
            document.getElementById('stockCode').readOnly = true;
            document.getElementById('stockName').value = data.stock_name;
            document.getElementById('stockType').value = data.stock_type;
            document.getElementById('stockBank').value = data.bank_id || '';
            document.getElementById('purchaseDate').value = data.purchase_date;
            document.getElementById('purchasePrice').value = data.purchase_price;
            document.getElementById('quantity').value = data.quantity;
            document.getElementById('currentPrice').value = data.current_price || '';
            document.getElementById('isinCode').value = data.isin_code || '';
            document.getElementById('symbol').value = data.symbol || '';
            document.getElementById('maturityDate').value = data.maturity_date || '';
            document.getElementById('interestRate').value = data.interest_rate || '';
            document.getElementById('dividendYield').value = data.dividend_yield || '';
            document.getElementById('brokerName').value = data.broker_name || '';
            document.getElementById('brokerage').value = data.brokerage || '';
            document.getElementById('certificateNumber').value = data.certificate_number || '';
            document.getElementById('holderName').value = data.holder_name;
            document.getElementById('nomineeName').value = data.nominee_name || '';
            document.getElementById('remarks').value = data.remarks || '';
            document.getElementById('stockStatusField').style.display = 'block';
            
            document.getElementById('stockModal').classList.add('active');
        }

        function closeStockModal() {
            document.getElementById('stockModal').classList.remove('active');
        }

        // Sell Stock Functions
        function sellStock(id) {
            // Get stock details via AJAX
            fetch('get-stock-details.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('sellStockId').value = data.id;
                        document.getElementById('sellStockName').value = data.stock_name;
                        document.getElementById('sellAvailableQty').value = data.quantity;
                        document.getElementById('sellQuantity').max = data.quantity;
                        document.getElementById('sellQuantity').value = data.quantity;
                        document.getElementById('sellPrice').value = data.current_price || data.purchase_price;
                        document.getElementById('sellModal').classList.add('active');
                    }
                });
        }

        function calculateSellAmount() {
            const qty = parseFloat(document.getElementById('sellQuantity').value) || 0;
            const price = parseFloat(document.getElementById('sellPrice').value) || 0;
            const brokerage = parseFloat(document.getElementById('sellBrokerage').value) || 0;
            
            const sellValue = qty * price;
            const netAmount = sellValue - brokerage;
            
            document.getElementById('sellValue').innerHTML = '₹' + sellValue.toFixed(2);
            document.getElementById('sellBrokerageDisplay').innerHTML = '₹' + brokerage.toFixed(2);
            document.getElementById('sellNetAmount').innerHTML = '₹' + netAmount.toFixed(2);
            
            // Calculate profit/loss (simplified - you might want to fetch purchase price)
            const purchasePrice = parseFloat(document.getElementById('sellAvailableQty').getAttribute('data-purchase-price')) || 0;
            const purchaseValue = qty * purchasePrice;
            const profitLoss = netAmount - purchaseValue;
            
            document.getElementById('sellProfitLoss').innerHTML = '₹' + profitLoss.toFixed(2);
            document.getElementById('sellProfitLoss').className = profitLoss >= 0 ? 'profit' : 'loss';
        }

        function closeSellModal() {
            document.getElementById('sellModal').classList.remove('active');
        }

        // View Stock Details
        function viewStock(id) {
            window.location.href = 'stock-details.php?id=' + id;
        }

        // Delete Stock
        function deleteStock(id) {
            Swal.fire({
                title: 'Delete Stock?',
                text: "This action cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_stock">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Auto-submit form when filters change
        document.querySelectorAll('select[name="stock_type"], select[name="bank_id"], select[name="status"]').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
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