<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$currentPage = 'expense-details';
$pageTitle = 'Expense Details';

// Database connection
require_once 'includes/db.php';

// Check if main connection exists
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection failed: " . mysqli_connect_error());
}

$conn->set_charset("utf8mb4");

// Check authentication
if (!file_exists('auth_check.php')) {
    die("auth_check.php file not found");
}
require_once 'auth_check.php';

// Check if user has admin or sale access
if (!isset($_SESSION['user_role']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'sale')) {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

// Fixed company ID (always 4 as per your requirement)
$FIXED_COMPANY_ID = 4;

// Function to check if table exists
function tableExists($conn, $table) {
    if (!$conn) return false;
    $sql = "SELECT 1 FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param("s", $table);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (bool)$row;
}

// Create expense_details table if it doesn't exist (in main database) - WITHOUT credit column
$create_table = "CREATE TABLE IF NOT EXISTS `expense_details` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `receipt_number` int(11) NOT NULL,
    `date` date NOT NULL,
    `expense_type` varchar(150) NOT NULL,
    `detail` text DEFAULT NULL,
    `payment_method` varchar(50) DEFAULT 'cash',
    `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
    `status` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `receipt_number` (`receipt_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($create_table)) {
    error_log("Failed to create expense_details table: " . $conn->error);
}

// Check if payment_method column exists, if not add it
$column_check = $conn->query("SHOW COLUMNS FROM expense_details LIKE 'payment_method'");
if ($column_check && $column_check->num_rows == 0) {
    $alter_query = "ALTER TABLE expense_details ADD COLUMN `payment_method` varchar(50) DEFAULT 'cash' AFTER `detail`";
    $conn->query($alter_query);
}

// Check if amount column exists (might be credit/debit in old structure)
$amount_check = $conn->query("SHOW COLUMNS FROM expense_details LIKE 'amount'");
if (!$amount_check || $amount_check->num_rows == 0) {
    // If amount doesn't exist, we need to add it (assuming we're migrating from credit/debit)
    $alter_amount = "ALTER TABLE expense_details ADD COLUMN `amount` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `payment_method`";
    $conn->query($alter_amount);
}

// Check if company_expenses table exists in current database
if ($conn) {
    $check_company_expenses = $conn->query("SHOW TABLES LIKE 'company_expenses'");
    if ($check_company_expenses && $check_company_expenses->num_rows == 0) {
        // Create company_expenses table if it doesn't exist
        $create_company_expenses = "CREATE TABLE IF NOT EXISTS `company_expenses` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `company_id` int(11) NOT NULL,
            `amount` decimal(18,2) NOT NULL,
            `expense_date` date NOT NULL,
            `category` varchar(60) NOT NULL DEFAULT 'general',
            `method` varchar(30) DEFAULT NULL,
            `reference_no` varchar(80) DEFAULT NULL,
            `note` varchar(255) DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `idx_ce_company` (`company_id`),
            KEY `idx_ce_date` (`expense_date`),
            KEY `idx_ce_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci";
        
        $conn->query($create_company_expenses);
    }
}

// Fetch expense types for dropdown
$expense_types_result = null;
$expense_types_query = "SELECT id, expense_type FROM expense_types WHERE status = 1 ORDER BY expense_type ASC";
$expense_types_result = $conn->query($expense_types_query);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $receipt_number = isset($_POST['receipt_number']) ? (int)$_POST['receipt_number'] : 0;
                $date = isset($_POST['date']) ? $_POST['date'] : '';
                $expense_type = isset($_POST['expense_type']) ? $_POST['expense_type'] : '';
                $detail = isset($_POST['detail']) ? $_POST['detail'] : '';
                $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
                $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
                $status = 1;
                
                // Validate required fields
                if (empty($date) || empty($expense_type) || empty($detail)) {
                    $error = "Please fill all required fields.";
                    break;
                }
                
                // Check if receipt number already exists
                $check_query = "SELECT id FROM expense_details WHERE receipt_number = ?";
                $check_stmt = $conn->prepare($check_query);
                if ($check_stmt) {
                    $check_stmt->bind_param("i", $receipt_number);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error = "Receipt number $receipt_number already exists. Please use a different number.";
                    } else {
                        // Insert into expense_details table (main database) - using amount instead of credit/debit
                        $insert_query = "INSERT INTO expense_details (receipt_number, date, expense_type, detail, payment_method, amount, status) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($insert_query);
                        if ($stmt) {
                            $stmt->bind_param("issssdi", $receipt_number, $date, $expense_type, $detail, $payment_method, $amount, $status);
                            
                            if ($stmt->execute()) {
                                $new_id = $conn->insert_id;
                                
                                // Insert into company_expenses table in current database for all expenses
                                if ($amount > 0 && $conn) {
                                    // Insert into company_expenses with fixed company_id = 4
                                    $company_expense_query = "INSERT INTO company_expenses 
                                                             (company_id, amount, expense_date, category, method, reference_no, note, created_by, created_at) 
                                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                                    
                                    $company_stmt = $conn->prepare($company_expense_query);
                                    if ($company_stmt) {
                                        // Use receipt number as reference
                                        $reference_no = "REC-{$receipt_number}";
                                        $note = "Expense: {$expense_type} - {$detail}";
                                        $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                                        
                                        $company_stmt->bind_param("idsssssi", 
                                            $FIXED_COMPANY_ID, 
                                            $amount, 
                                            $date, 
                                            $expense_type, 
                                            $payment_method, 
                                            $reference_no, 
                                            $note, 
                                            $created_by
                                        );
                                        
                                        $company_stmt->execute();
                                        $company_stmt->close();
                                    }
                                }
                                
                                // Log the activity if table exists
                                if (tableExists($conn, 'activity_log')) {
                                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                                 VALUES (?, 'create', 'Added new expense detail', 'expense_details', ?)";
                                    $log_stmt = $conn->prepare($log_query);
                                    if ($log_stmt) {
                                        $log_stmt->bind_param("ii", $_SESSION['user_id'], $new_id);
                                        $log_stmt->execute();
                                        $log_stmt->close();
                                    }
                                }
                                
                                header('Location: Expense-Details.php?success=added');
                                exit();
                            } else {
                                $error = "Error adding expense detail: " . $stmt->error;
                            }
                            $stmt->close();
                        } else {
                            $error = "Error preparing insert statement: " . $conn->error;
                        }
                    }
                    $check_stmt->close();
                } else {
                    $error = "Error preparing check statement: " . $conn->error;
                }
                break;
                
            case 'edit':
                $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                $receipt_number = isset($_POST['receipt_number']) ? (int)$_POST['receipt_number'] : 0;
                $date = isset($_POST['date']) ? $_POST['date'] : '';
                $expense_type = isset($_POST['expense_type']) ? $_POST['expense_type'] : '';
                $detail = isset($_POST['detail']) ? $_POST['detail'] : '';
                $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
                $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
                $status = isset($_POST['status']) ? 1 : 0;
                
                if ($id <= 0) {
                    $error = "Invalid expense ID.";
                    break;
                }
                
                // Get old amount for comparison
                $old_query = "SELECT amount FROM expense_details WHERE id = ?";
                $old_stmt = $conn->prepare($old_query);
                if ($old_stmt) {
                    $old_stmt->bind_param("i", $id);
                    $old_stmt->execute();
                    $old_result = $old_stmt->get_result();
                    $old_data = $old_result->fetch_assoc();
                    $old_amount = isset($old_data['amount']) ? (float)$old_data['amount'] : 0;
                    $old_stmt->close();
                } else {
                    $old_amount = 0;
                }
                
                // Check if receipt number already exists for another record
                $check_query = "SELECT id FROM expense_details WHERE receipt_number = ? AND id != ?";
                $check_stmt = $conn->prepare($check_query);
                if ($check_stmt) {
                    $check_stmt->bind_param("ii", $receipt_number, $id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error = "Receipt number $receipt_number already exists. Please use a different number.";
                    } else {
                        $update_query = "UPDATE expense_details 
                                        SET receipt_number = ?, date = ?, expense_type = ?, detail = ?, payment_method = ?, amount = ?, status = ?
                                        WHERE id = ?";
                        $stmt = $conn->prepare($update_query);
                        if ($stmt) {
                            $stmt->bind_param("issssdii", $receipt_number, $date, $expense_type, $detail, $payment_method, $amount, $status, $id);
                            
                            if ($stmt->execute()) {
                                // If amount changed, update company_expenses
                                if ($amount != $old_amount && $conn) {
                                    $reference_no = "REC-{$receipt_number}";
                                    
                                    // Check if record exists in company_expenses
                                    $check_company = $conn->prepare("SELECT id FROM company_expenses WHERE reference_no = ?");
                                    if ($check_company) {
                                        $check_company->bind_param("s", $reference_no);
                                        $check_company->execute();
                                        $company_exists = $check_company->get_result()->fetch_assoc();
                                        $check_company->close();
                                        
                                        if ($company_exists) {
                                            // Update existing record
                                            $update_company = $conn->prepare("UPDATE company_expenses 
                                                                                     SET amount = ?, expense_date = ?, category = ?, method = ?, note = ? 
                                                                                     WHERE reference_no = ?");
                                            if ($update_company) {
                                                $note = "Expense: {$expense_type} - {$detail}";
                                                $update_company->bind_param("dsssss", $amount, $date, $expense_type, $payment_method, $note, $reference_no);
                                                $update_company->execute();
                                                $update_company->close();
                                            }
                                        } else if ($amount > 0) {
                                            // Insert new record if amount > 0
                                            $insert_company = $conn->prepare("INSERT INTO company_expenses 
                                                                                     (company_id, amount, expense_date, category, method, reference_no, note, created_by, created_at) 
                                                                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                                            if ($insert_company) {
                                                $note = "Expense: {$expense_type} - {$detail}";
                                                $created_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
                                                $insert_company->bind_param("idsssssi", $FIXED_COMPANY_ID, $amount, $date, $expense_type, $payment_method, $reference_no, $note, $created_by);
                                                $insert_company->execute();
                                                $insert_company->close();
                                            }
                                        }
                                    }
                                }
                                
                                // Log the activity if table exists
                                if (tableExists($conn, 'activity_log')) {
                                    $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                                 VALUES (?, 'update', 'Updated expense detail', 'expense_details', ?)";
                                    $log_stmt = $conn->prepare($log_query);
                                    if ($log_stmt) {
                                        $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                                        $log_stmt->execute();
                                        $log_stmt->close();
                                    }
                                }
                                
                                header('Location: Expense-Details.php?success=updated');
                                exit();
                            } else {
                                $error = "Error updating expense detail: " . $stmt->error;
                            }
                            $stmt->close();
                        } else {
                            $error = "Error preparing update statement: " . $conn->error;
                        }
                    }
                    $check_stmt->close();
                } else {
                    $error = "Error preparing check statement: " . $conn->error;
                }
                break;
                
            case 'delete':
                $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                
                if ($id <= 0) {
                    $error = "Invalid expense ID.";
                    break;
                }
                
                // Get receipt number before deleting
                $receipt_query = "SELECT receipt_number FROM expense_details WHERE id = ?";
                $receipt_stmt = $conn->prepare($receipt_query);
                if ($receipt_stmt) {
                    $receipt_stmt->bind_param("i", $id);
                    $receipt_stmt->execute();
                    $receipt_result = $receipt_stmt->get_result();
                    $receipt_data = $receipt_result->fetch_assoc();
                    $receipt_number = isset($receipt_data['receipt_number']) ? $receipt_data['receipt_number'] : 0;
                    $receipt_stmt->close();
                    
                    if ($receipt_number > 0 && $conn) {
                        // Delete from company_expenses in current database
                        $reference_no = "REC-{$receipt_number}";
                        $delete_company = $conn->prepare("DELETE FROM company_expenses WHERE reference_no = ?");
                        if ($delete_company) {
                            $delete_company->bind_param("s", $reference_no);
                            $delete_company->execute();
                            $delete_company->close();
                        }
                    }
                }
                
                $delete_query = "DELETE FROM expense_details WHERE id = ?";
                $stmt = $conn->prepare($delete_query);
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    
                    if ($stmt->execute()) {
                        // Log the activity if table exists
                        if (tableExists($conn, 'activity_log')) {
                            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                                         VALUES (?, 'delete', 'Deleted expense detail', 'expense_details', ?)";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $log_stmt->bind_param("ii", $_SESSION['user_id'], $id);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                        }
                        
                        header('Location: Expense-Details.php?success=deleted');
                        exit();
                    } else {
                        $error = "Error deleting expense detail: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Error preparing delete statement: " . $conn->error;
                }
                break;
        }
    }
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Expense detail added successfully!";
            break;
        case 'updated':
            $message = "Expense detail updated successfully!";
            break;
        case 'deleted':
            $message = "Expense detail deleted successfully!";
            break;
    }
}

// Get max receipt number for display
$next_receipt = 41; // Default starting value
$max_receipt_query = "SELECT COALESCE(MAX(receipt_number), 40) + 1 as next_receipt FROM expense_details";
$max_receipt_result = $conn->query($max_receipt_query);
if ($max_receipt_result && $row = $max_receipt_result->fetch_assoc()) {
    $next_receipt = $row['next_receipt'];
}

// Fetch all expense details
$expense_result = null;
$expense_query = "SELECT * FROM expense_details ORDER BY receipt_number DESC";
$expense_result = $conn->query($expense_query);

// Check if we need to insert sample data (only if table is empty)
if ($expense_result && $expense_result->num_rows == 0) {
    // Insert sample data from screenshot - using amount instead of credit/debit
    $sample_data = [
        [41, '2026-01-14', 'สมัครสมัคร', 'สมัครสมัคร', 'cash', 50000.00, 1],
        [39, '2025-12-12', 'คะแนนการจัดจ้าง', 'จุดสมัครปี 2025', 'bank', 15000.00, 1]
    ];
    
    foreach ($sample_data as $data) {
        // Check if receipt number already exists before inserting
        $check_query = "SELECT id FROM expense_details WHERE receipt_number = ?";
        $check_stmt = $conn->prepare($check_query);
        if ($check_stmt) {
            $check_stmt->bind_param("i", $data[0]);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                $insert = $conn->prepare("INSERT INTO expense_details (receipt_number, date, expense_type, detail, payment_method, amount, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($insert) {
                    $insert->bind_param("issssdi", $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6]);
                    $insert->execute();
                    $insert->close();
                    
                    // Also insert into company_expenses for all entries
                    if ($data[5] > 0 && $conn) {
                        $reference_no = "REC-{$data[0]}";
                        $note = "Expense: {$data[2]} - {$data[3]}";
                        $company_insert = $conn->prepare("INSERT INTO company_expenses 
                                                                 (company_id, amount, expense_date, category, method, reference_no, note, created_by, created_at) 
                                                                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                        if ($company_insert) {
                            $company_insert->bind_param("idsssss", $FIXED_COMPANY_ID, $data[5], $data[1], $data[2], $data[4], $reference_no, $note);
                            $company_insert->execute();
                            $company_insert->close();
                        }
                    }
                }
            }
            $check_stmt->close();
        }
    }
    
    // Refresh the query
    $expense_result = $conn->query($expense_query);
}

// Get company name for display (just for reference)
$company_name = "Company ID: 4";
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
        /* Your existing CSS styles */
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

        .expense-container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
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
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .add-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .add-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            animation: slideDown 0.4s ease;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            border-left: 5px solid;
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
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left-color: #dc3545;
        }

        .sub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .sub-title {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
        }

        .sub-title i {
            color: #667eea;
            margin-right: 10px;
        }

        .form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
        }

        .form-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: #667eea;
            font-size: 24px;
        }

        .form-title:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 200px;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            margin-bottom: 8px;
        }

        .required::after {
            content: "*";
            color: #f56565;
            margin-left: 4px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #a0aec0;
            font-size: 18px;
            z-index: 1;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 14px 20px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .receipt-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        .payment-options {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .payment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .payment-cash {
            background: linear-gradient(135deg, #c6f6d5 0%, #9ae6b4 100%);
            color: #22543d;
        }

        .payment-bank {
            background: linear-gradient(135deg, #bee3f8 0%, #90cdf4 100%);
            color: #2c5282;
        }

        .payment-upi {
            background: linear-gradient(135deg, #fed7d7 0%, #feb2b2 100%);
            color: #742a2a;
        }

        .payment-other {
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e0 100%);
            color: #4a5568;
        }

        .company-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            background: linear-gradient(135deg, #e9d8fd 0%, #d6bcfa 100%);
            color: #553c9a;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        .btn {
            padding: 12px 30px;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
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
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        .btn-danger {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 101, 101, 0.4);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 101, 101, 0.5);
        }

        .search-container {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(102, 126, 234, 0.1);
        }

        .search-box {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .search-input {
            flex: 1;
            padding: 14px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .search-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .table-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(102, 126, 234, 0.2);
            overflow: auto;
        }

        .expense-table {
            width: 100% !important;
            border-collapse: separate;
            border-spacing: 0 8px;
            min-width: 1100px;
        }

        .expense-table th {
            padding: 16px;
            text-align: left;
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            background: #f7fafc;
            border-radius: 12px 12px 0 0;
            white-space: nowrap;
        }

        .expense-table td {
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 14px;
            color: #2d3748;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .expense-table tbody tr:hover td {
            background: #edf2f7;
            transform: scale(1.005);
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .receipt-badge-small {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 12px;
            display: inline-block;
        }

        .amount-expense {
            color: #f56565;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: white;
            color: #4a5568;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .btn-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }

        .btn-icon.edit:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-icon.delete:hover {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .mobile-cards {
            display: none;
        }

        .expense-mobile-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }

        .mobile-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .mobile-receipt {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            padding: 4px 12px;
            border-radius: 50px;
        }

        .mobile-date {
            color: #718096;
            font-size: 13px;
        }

        .mobile-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .mobile-detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
        }

        .detail-value.expense {
            color: #f56565;
            font-weight: 600;
        }

        .mobile-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
            backdrop-filter: blur(5px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 24px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideUp 0.4s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 25px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            font-size: 24px;
            color: white;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-radius: 0 0 24px 24px;
        }

        .footer {
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #eef2f6;
            padding: 16px 24px;
            margin-top: auto;
            width: 100%;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #64748b;
            font-size: 14px;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            color: #cbd5e0;
        }

        .badge-count {
            background: rgba(102, 126, 234, 0.2);
            color: #667eea;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 14px;
            margin-left: 10px;
            font-weight: 600;
        }

        .thai-text {
            font-family: "Noto Sans Thai", "Sarabun", "Thonburi", sans-serif;
        }

        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .page-content {
                padding: 20px;
            }
            .expense-container {
                padding: 0 15px;
            }
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            .page-title {
                font-size: 28px;
            }
            .add-btn {
                width: 100%;
                justify-content: center;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .search-box {
                flex-direction: column;
            }
            .search-btn {
                width: 100%;
                justify-content: center;
            }
            .form-actions {
                flex-direction: column;
            }
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
            .desktop-table {
                display: none;
            }
            .mobile-cards {
                display: block;
            }
            .mobile-details {
                grid-template-columns: 1fr;
            }
            .payment-options {
                justify-content: center;
            }
            .footer-content {
                flex-direction: column;
                text-align: center;
            }
            .modal-footer {
                flex-direction: column;
            }
            .modal-footer .btn {
                width: 100%;
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
            <div class="expense-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-cash" style="margin-right: 10px;"></i>
                        Expense Details
                    </h1>
                    <div class="header-actions">
                        <button class="add-btn" onclick="openAddModal()">
                            <i class="bi bi-plus-circle"></i>
                            Add New Expense
                        </button>
                    </div>
                </div>

                <!-- Sub Header - Expenses -->
                <div class="sub-header">
                    <h2 class="sub-title">
                        <i class="bi bi-arrow-down-circle"></i>
                        Expense List
                    </h2>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px;"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Add Form Card -->
                <div class="form-card">
                    <div class="form-title">
                        <i class="bi bi-plus-circle"></i>
                        Add New Expense Entry
                    </div>
                    
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label required">Receipt Number *</label>
                                <div class="receipt-badge">
                                    <?php echo $next_receipt; ?>
                                </div>
                                <input type="hidden" name="receipt_number" value="<?php echo $next_receipt; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Date *</label>
                                <div class="input-group">
                                    <i class="bi bi-calendar input-icon"></i>
                                    <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Company ID</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" class="form-control" value="Company 4 (Fixed)" readonly disabled>
                                    <input type="hidden" name="company_id" value="<?php echo $FIXED_COMPANY_ID; ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Expense Type *</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select name="expense_type" class="form-select" required>
                                        <option value="">Select Expense Type</option>
                                        <?php 
                                        if ($expense_types_result && $expense_types_result->num_rows > 0) {
                                            $expense_types_result->data_seek(0);
                                            while ($type = $expense_types_result->fetch_assoc()) {
                                                echo '<option value="' . htmlspecialchars($type['expense_type']) . '">' . htmlspecialchars($type['expense_type']) . '</option>';
                                            }
                                        } else {
                                            echo '<option value="general">General</option>';
                                            echo '<option value="office">Office</option>';
                                            echo '<option value="salary">Salary</option>';
                                            echo '<option value="other">Other</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Detail *</label>
                                <div class="input-group">
                                    <i class="bi bi-pencil input-icon"></i>
                                    <input type="text" name="detail" class="form-control" placeholder="Enter detail" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Payment Method *</label>
                                <div class="payment-options">
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" id="paymentCash" value="cash" checked>
                                        <label for="paymentCash"><i class="bi bi-cash"></i> Cash</label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" id="paymentBank" value="bank">
                                        <label for="paymentBank"><i class="bi bi-bank"></i> Bank</label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" id="paymentUPI" value="upi">
                                        <label for="paymentUPI"><i class="bi bi-phone"></i> UPI</label>
                                    </div>
                                    <div class="payment-option">
                                        <input type="radio" name="payment_method" id="paymentOther" value="other">
                                        <label for="paymentOther"><i class="bi bi-three-dots"></i> Other</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label required">Amount *</label>
                                <div class="input-group">
                                    <i class="bi bi-currency-rupee input-icon"></i>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0" value="0.00" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="bi bi-eraser"></i>
                                Clear
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
                                <i class="bi bi-x-circle"></i>
                                Close
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i>
                                Save Expense
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Search Bar -->
                <div class="search-container">
                    <div class="search-box">
                        <input type="text" class="search-input" id="searchInput" placeholder="Search by receipt number, expense type, detail..." onkeyup="searchExpenses()">
                        <button class="search-btn" onclick="searchExpenses()">
                            <i class="bi bi-search"></i>
                            Search
                        </button>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="table-card desktop-table">
                    <table class="expense-table" id="expenseTable">
                        <thead>
                            <tr>
                                <th>S.No.</th>
                                <th>Receipt No.</th>
                                <th>Date</th>
                                <th>Company</th>
                                <th>Expense Type</th>
                                <th>Detail</th>
                                <th>Payment</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expense_result && $expense_result->num_rows > 0): ?>
                                <?php $sno = 1; ?>
                                <?php while ($row = $expense_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $sno++; ?></strong></td>
                                        <td>
                                            <span class="receipt-badge-small">
                                                <?php echo htmlspecialchars($row['receipt_number']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                        <td>
                                            <span class="company-badge">Company 4</span>
                                        </td>
                                        <td class="thai-text"><?php echo htmlspecialchars($row['expense_type']); ?></td>
                                        <td class="thai-text"><?php echo htmlspecialchars($row['detail']); ?></td>
                                        <td>
                                            <?php 
                                            $payment = $row['payment_method'] ?? 'cash';
                                            $payment_class = 'payment-' . $payment;
                                            $payment_icon = $payment == 'cash' ? 'bi-cash' : ($payment == 'bank' ? 'bi-bank' : ($payment == 'upi' ? 'bi-phone' : 'bi-three-dots'));
                                            ?>
                                            <span class="payment-badge <?php echo $payment_class; ?>">
                                                <i class="bi <?php echo $payment_icon; ?>"></i> <?php echo ucfirst($payment); ?>
                                            </span>
                                        </td>
                                        <td class="amount-expense">₹<?php echo number_format($row['amount'], 2); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn-icon delete" onclick="openDeleteModal(<?php echo $row['id']; ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="empty-state">
                                        <i class="bi bi-cash"></i>
                                        <p>No expense details found</p>
                                        <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                            <i class="bi bi-plus-circle"></i> Add New Expense
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card View -->
                <div class="mobile-cards" id="mobileCards">
                    <?php if ($expense_result && $expense_result->num_rows > 0): ?>
                        <?php $expense_result->data_seek(0); ?>
                        <?php while ($row = $expense_result->fetch_assoc()): ?>
                            <div class="expense-mobile-card">
                                <div class="mobile-card-header">
                                    <span class="mobile-receipt">Receipt #<?php echo $row['receipt_number']; ?></span>
                                    <span class="mobile-date"><?php echo date('d-m-Y', strtotime($row['date'])); ?></span>
                                </div>
                                
                                <div class="mobile-details">
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Company</span>
                                        <span class="detail-value">Company 4</span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Expense Type</span>
                                        <span class="detail-value thai-text"><?php echo htmlspecialchars($row['expense_type']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Detail</span>
                                        <span class="detail-value thai-text"><?php echo htmlspecialchars($row['detail']); ?></span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Payment</span>
                                        <span class="detail-value">
                                            <?php 
                                            $payment = $row['payment_method'] ?? 'cash';
                                            $payment_class = 'payment-' . $payment;
                                            $payment_icon = $payment == 'cash' ? 'bi-cash' : ($payment == 'bank' ? 'bi-bank' : ($payment == 'upi' ? 'bi-phone' : 'bi-three-dots'));
                                            ?>
                                            <span class="payment-badge <?php echo $payment_class; ?>">
                                                <i class="bi <?php echo $payment_icon; ?>"></i> <?php echo ucfirst($payment); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="mobile-detail-item">
                                        <span class="detail-label">Amount</span>
                                        <span class="detail-value expense">₹<?php echo number_format($row['amount'], 2); ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-actions">
                                    <button class="btn-icon edit" onclick='openEditModal(<?php echo json_encode($row); ?>)' title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn-icon delete" onclick="openDeleteModal(<?php echo $row['id']; ?>)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-cash"></i>
                            <p>No expense details found</p>
                            <button class="add-btn" onclick="openAddModal()" style="display: inline-block; padding: 10px 20px;">
                                <i class="bi bi-plus-circle"></i> Add New Expense
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal" id="expenseModal">
    <div class="modal-content">
        <form method="POST" id="expenseForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="expenseId" value="">
            
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">
                    <i class="bi bi-plus-circle" style="margin-right: 8px;"></i>
                    Add New Expense
                </h3>
                <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Receipt Number *</label>
                    <input type="number" name="receipt_number" id="modalReceiptNumber" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="date" id="modalDate" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Company</label>
                    <input type="text" class="form-control" value="Company 4 (Fixed)" readonly disabled>
                    <input type="hidden" name="company_id" value="<?php echo $FIXED_COMPANY_ID; ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Expense Type *</label>
                    <select name="expense_type" id="modalExpenseType" class="form-select" required>
                        <option value="">Select Expense Type</option>
                        <?php 
                        if ($expense_types_result) {
                            $expense_types_result->data_seek(0);
                            while ($type = $expense_types_result->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($type['expense_type']) . '">' . htmlspecialchars($type['expense_type']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Detail *</label>
                    <input type="text" name="detail" id="modalDetail" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Payment Method *</label>
                    <div class="payment-options">
                        <div class="payment-option">
                            <input type="radio" name="payment_method" id="modalPaymentCash" value="cash" checked>
                            <label for="modalPaymentCash"><i class="bi bi-cash"></i> Cash</label>
                        </div>
                        <div class="payment-option">
                            <input type="radio" name="payment_method" id="modalPaymentBank" value="bank">
                            <label for="modalPaymentBank"><i class="bi bi-bank"></i> Bank</label>
                        </div>
                        <div class="payment-option">
                            <input type="radio" name="payment_method" id="modalPaymentUPI" value="upi">
                            <label for="modalPaymentUPI"><i class="bi bi-phone"></i> UPI</label>
                        </div>
                        <div class="payment-option">
                            <input type="radio" name="payment_method" id="modalPaymentOther" value="other">
                            <label for="modalPaymentOther"><i class="bi bi-three-dots"></i> Other</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Amount *</label>
                    <input type="number" name="amount" id="modalAmount" class="form-control" step="0.01" min="0" value="0.00" required>
                </div>
                
                <div class="form-group" id="statusField" style="display: none;">
                    <label class="form-check">
                        <input type="checkbox" name="status" id="modalStatus" class="form-check-input" checked>
                        <span class="form-check-label">Active</span>
                    </label>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                    <i class="bi bi-x-circle"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content" style="max-width: 400px;">
        <form method="POST">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">
            
            <div class="modal-header" style="background: linear-gradient(135deg, #f56565 0%, #c53030 100%);">
                <h3 class="modal-title">
                    <i class="bi bi-exclamation-triangle" style="margin-right: 8px;"></i>
                    Confirm Delete
                </h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <div style="text-align: center; padding: 20px;">
                    <i class="bi bi-exclamation-circle" style="font-size: 60px; color: #f56565; margin-bottom: 15px;"></i>
                    <p style="color: #4a5568; font-size: 16px; line-height: 1.6;">
                        Are you sure you want to delete this expense record?<br>
                        <strong>This action cannot be undone.</strong>
                    </p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="bi bi-x-circle"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i>
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('formAction').value = 'add';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle" style="margin-right: 8px;"></i> Add New Expense';
        document.getElementById('expenseId').value = '';
        document.getElementById('modalReceiptNumber').value = '<?php echo $next_receipt; ?>';
        document.getElementById('modalDate').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('modalExpenseType').value = '';
        document.getElementById('modalDetail').value = '';
        document.getElementById('modalAmount').value = '0.00';
        document.getElementById('modalPaymentCash').checked = true;
        document.getElementById('statusField').style.display = 'none';
        document.getElementById('expenseModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function openEditModal(data) {
        document.getElementById('formAction').value = 'edit';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil" style="margin-right: 8px;"></i> Edit Expense';
        document.getElementById('expenseId').value = data.id;
        document.getElementById('modalReceiptNumber').value = data.receipt_number;
        document.getElementById('modalDate').value = data.date;
        document.getElementById('modalExpenseType').value = data.expense_type;
        document.getElementById('modalDetail').value = data.detail;
        document.getElementById('modalAmount').value = data.amount;
        
        // Set payment method
        const paymentMethod = data.payment_method || 'cash';
        if (paymentMethod === 'cash') document.getElementById('modalPaymentCash').checked = true;
        else if (paymentMethod === 'bank') document.getElementById('modalPaymentBank').checked = true;
        else if (paymentMethod === 'upi') document.getElementById('modalPaymentUPI').checked = true;
        else document.getElementById('modalPaymentOther').checked = true;
        
        document.getElementById('modalStatus').checked = data.status == 1;
        document.getElementById('statusField').style.display = 'block';
        document.getElementById('expenseModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal() {
        document.getElementById('expenseModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function openDeleteModal(id) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    
    function clearForm() {
        document.querySelector('input[name="date"]').value = '<?php echo date('Y-m-d'); ?>';
        document.querySelector('select[name="expense_type"]').value = '';
        document.querySelector('input[name="detail"]').value = '';
        document.querySelector('input[name="amount"]').value = '0.00';
        document.getElementById('paymentCash').checked = true;
    }
    
    // Search functionality
    function searchExpenses() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        
        let tableRows = document.querySelectorAll('#expenseTable tbody tr');
        tableRows.forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            let cells = row.querySelectorAll('td');
            if (cells.length > 1) {
                let receipt = cells[1]?.textContent.toLowerCase() || '';
                let expenseType = cells[4]?.textContent.toLowerCase() || '';
                let detail = cells[5]?.textContent.toLowerCase() || '';
                if (receipt.includes(input) || expenseType.includes(input) || detail.includes(input)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });
        
        // Search in mobile cards
        let mobileCards = document.querySelectorAll('.expense-mobile-card');
        mobileCards.forEach(card => {
            let text = card.textContent.toLowerCase();
            if (text.includes(input)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    }
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    
    // Handle Enter key in search
    document.getElementById('searchInput').addEventListener('keyup', function(e) {
        if (e.key === 'Enter') {
            searchExpenses();
        }
    });
</script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>