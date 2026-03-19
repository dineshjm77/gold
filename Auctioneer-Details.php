<?php
session_start();
$currentPage = 'auctioneer-details';
$pageTitle = 'Auctioneer Management';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$error = '';
$success = '';

// Check if auctioneers table exists, if not create it
$check_table = $conn->query("SHOW TABLES LIKE 'auctioneers'");
if ($check_table->num_rows == 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS `auctioneers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `auctioneer_code` varchar(50) NOT NULL,
        `auctioneer_name` varchar(150) NOT NULL,
        `firm_name` varchar(200) DEFAULT NULL,
        `license_number` varchar(100) DEFAULT NULL,
        `license_valid_upto` date DEFAULT NULL,
        `gst_number` varchar(50) DEFAULT NULL,
        `pan_number` varchar(20) DEFAULT NULL,
        `mobile_number` varchar(15) NOT NULL,
        `alternate_mobile` varchar(15) DEFAULT NULL,
        `email` varchar(100) DEFAULT NULL,
        `address_line1` varchar(255) DEFAULT NULL,
        `address_line2` varchar(255) DEFAULT NULL,
        `city` varchar(100) DEFAULT NULL,
        `state` varchar(100) DEFAULT NULL,
        `pincode` varchar(10) DEFAULT NULL,
        `country` varchar(50) DEFAULT 'India',
        `bank_name` varchar(200) DEFAULT NULL,
        `branch_name` varchar(200) DEFAULT NULL,
        `account_number` varchar(50) DEFAULT NULL,
        `ifsc_code` varchar(20) DEFAULT NULL,
        `account_type` enum('savings','current') DEFAULT 'savings',
        `upi_id` varchar(100) DEFAULT NULL,
        `commission_rate` decimal(5,2) DEFAULT 0.00,
        `commission_type` enum('percentage','fixed') DEFAULT 'percentage',
        `security_deposit` decimal(15,2) DEFAULT 0.00,
        `contract_start_date` date DEFAULT NULL,
        `contract_end_date` date DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `remarks` text DEFAULT NULL,
        `photo_path` varchar(255) DEFAULT NULL,
        `document_path` varchar(255) DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `auctioneer_code` (`auctioneer_code`),
        UNIQUE KEY `license_number` (`license_number`),
        KEY `mobile_number` (`mobile_number`),
        KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if ($conn->query($create_table)) {
        $success = "Auctioneers table created successfully!";
    } else {
        $error = "Error creating table: " . $conn->error;
    }
}

// Check if auctioneer_documents table exists
$check_docs = $conn->query("SHOW TABLES LIKE 'auctioneer_documents'");
if ($check_docs->num_rows == 0) {
    $create_docs = "CREATE TABLE IF NOT EXISTS `auctioneer_documents` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `auctioneer_id` int(11) NOT NULL,
        `document_type` varchar(50) NOT NULL,
        `document_name` varchar(255) NOT NULL,
        `file_path` varchar(255) NOT NULL,
        `file_size` int(11) DEFAULT NULL,
        `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `auctioneer_id` (`auctioneer_id`),
        CONSTRAINT `auctioneer_documents_ibfk_1` FOREIGN KEY (`auctioneer_id`) REFERENCES `auctioneers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($create_docs);
}

// Check if auction_history table exists
$check_history = $conn->query("SHOW TABLES LIKE 'auction_history'");
if ($check_history->num_rows == 0) {
    $create_history = "CREATE TABLE IF NOT EXISTS `auction_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `auctioneer_id` int(11) NOT NULL,
        `auction_date` date NOT NULL,
        `loan_id` int(11) DEFAULT NULL,
        `lot_number` varchar(50) DEFAULT NULL,
        `item_description` text DEFAULT NULL,
        `reserve_price` decimal(15,2) DEFAULT 0.00,
        `final_bid_amount` decimal(15,2) DEFAULT 0.00,
        `commission_amount` decimal(15,2) DEFAULT 0.00,
        `net_amount` decimal(15,2) DEFAULT 0.00,
        `buyer_name` varchar(200) DEFAULT NULL,
        `buyer_mobile` varchar(15) DEFAULT NULL,
        `payment_status` enum('pending','partial','completed') DEFAULT 'pending',
        `remarks` text DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `auctioneer_id` (`auctioneer_id`),
        KEY `loan_id` (`loan_id`),
        CONSTRAINT `auction_history_ibfk_1` FOREIGN KEY (`auctioneer_id`) REFERENCES `auctioneers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($create_history);
}

// Generate unique auctioneer code
function generateAuctioneerCode($conn) {
    $prefix = 'AUC';
    $year = date('Y');
    $month = date('m');
    
    $query = "SELECT COUNT(*) as count FROM auctioneers WHERE auctioneer_code LIKE '$prefix$year$month%'";
    $result = $conn->query($query);
    $count = $result->fetch_assoc()['count'] + 1;
    
    return $prefix . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new auctioneer
    if (isset($_POST['add_auctioneer'])) {
        $auctioneer_code = generateAuctioneerCode($conn);
        $auctioneer_name = mysqli_real_escape_string($conn, $_POST['auctioneer_name'] ?? '');
        $firm_name = mysqli_real_escape_string($conn, $_POST['firm_name'] ?? '');
        $license_number = mysqli_real_escape_string($conn, $_POST['license_number'] ?? '');
        $license_valid_upto = !empty($_POST['license_valid_upto']) ? $_POST['license_valid_upto'] : null;
        $gst_number = mysqli_real_escape_string($conn, $_POST['gst_number'] ?? '');
        $pan_number = mysqli_real_escape_string($conn, $_POST['pan_number'] ?? '');
        $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number'] ?? '');
        $alternate_mobile = mysqli_real_escape_string($conn, $_POST['alternate_mobile'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $address_line1 = mysqli_real_escape_string($conn, $_POST['address_line1'] ?? '');
        $address_line2 = mysqli_real_escape_string($conn, $_POST['address_line2'] ?? '');
        $city = mysqli_real_escape_string($conn, $_POST['city'] ?? '');
        $state = mysqli_real_escape_string($conn, $_POST['state'] ?? '');
        $pincode = mysqli_real_escape_string($conn, $_POST['pincode'] ?? '');
        $country = mysqli_real_escape_string($conn, $_POST['country'] ?? 'India');
        $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
        $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name'] ?? '');
        $account_number = mysqli_real_escape_string($conn, $_POST['account_number'] ?? '');
        $ifsc_code = mysqli_real_escape_string($conn, $_POST['ifsc_code'] ?? '');
        $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
        $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
        $commission_rate = floatval($_POST['commission_rate'] ?? 0);
        $commission_type = mysqli_real_escape_string($conn, $_POST['commission_type'] ?? 'percentage');
        $security_deposit = floatval($_POST['security_deposit'] ?? 0);
        $contract_start_date = !empty($_POST['contract_start_date']) ? $_POST['contract_start_date'] : null;
        $contract_end_date = !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($auctioneer_name)) {
            $errors[] = "Auctioneer name is required";
        }
        if (empty($mobile_number)) {
            $errors[] = "Mobile number is required";
        }
        
        // Check if license number already exists
        if (!empty($license_number)) {
            $check_license = "SELECT id FROM auctioneers WHERE license_number = '$license_number'";
            $license_result = $conn->query($check_license);
            if ($license_result->num_rows > 0) {
                $errors[] = "License number already exists";
            }
        }
        
        if (empty($errors)) {
            // Handle photo upload
            $photo_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['photo']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $upload_dir = 'uploads/auctioneers/photos/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'auc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo_path = $upload_path;
                    }
                }
            }
            
            // Handle document upload
            $document_path = null;
            if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
                $filename = $_FILES['document']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                if (in_array($ext, $allowed)) {
                    $upload_dir = 'uploads/auctioneers/documents/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_filename = 'doc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_path)) {
                        $document_path = $upload_path;
                    }
                }
            }
            
            $insert_query = "INSERT INTO auctioneers SET 
                auctioneer_code = '$auctioneer_code',
                auctioneer_name = '$auctioneer_name',
                firm_name = " . ($firm_name ? "'$firm_name'" : "NULL") . ",
                license_number = " . ($license_number ? "'$license_number'" : "NULL") . ",
                license_valid_upto = " . ($license_valid_upto ? "'$license_valid_upto'" : "NULL") . ",
                gst_number = " . ($gst_number ? "'$gst_number'" : "NULL") . ",
                pan_number = " . ($pan_number ? "'$pan_number'" : "NULL") . ",
                mobile_number = '$mobile_number',
                alternate_mobile = " . ($alternate_mobile ? "'$alternate_mobile'" : "NULL") . ",
                email = " . ($email ? "'$email'" : "NULL") . ",
                address_line1 = " . ($address_line1 ? "'$address_line1'" : "NULL") . ",
                address_line2 = " . ($address_line2 ? "'$address_line2'" : "NULL") . ",
                city = " . ($city ? "'$city'" : "NULL") . ",
                state = " . ($state ? "'$state'" : "NULL") . ",
                pincode = " . ($pincode ? "'$pincode'" : "NULL") . ",
                country = '$country',
                bank_name = " . ($bank_name ? "'$bank_name'" : "NULL") . ",
                branch_name = " . ($branch_name ? "'$branch_name'" : "NULL") . ",
                account_number = " . ($account_number ? "'$account_number'" : "NULL") . ",
                ifsc_code = " . ($ifsc_code ? "'$ifsc_code'" : "NULL") . ",
                account_type = '$account_type',
                upi_id = " . ($upi_id ? "'$upi_id'" : "NULL") . ",
                commission_rate = $commission_rate,
                commission_type = '$commission_type',
                security_deposit = $security_deposit,
                contract_start_date = " . ($contract_start_date ? "'$contract_start_date'" : "NULL") . ",
                contract_end_date = " . ($contract_end_date ? "'$contract_end_date'" : "NULL") . ",
                is_active = $is_active,
                remarks = " . ($remarks ? "'$remarks'" : "NULL") . ",
                photo_path = " . ($photo_path ? "'$photo_path'" : "NULL") . ",
                document_path = " . ($document_path ? "'$document_path'" : "NULL") . ",
                created_by = " . $_SESSION['user_id'] . ",
                created_at = NOW()";
            
            if ($conn->query($insert_query)) {
                $auctioneer_id = $conn->insert_id;
                $success = "Auctioneer added successfully! Code: $auctioneer_code";
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (" . $_SESSION['user_id'] . ", 'create', 'Added auctioneer: $auctioneer_name', 'auctioneers', $auctioneer_id)";
                $conn->query($log_query);
            } else {
                $error = "Error adding auctioneer: " . $conn->error;
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    
    // Update auctioneer
    elseif (isset($_POST['update_auctioneer'])) {
        $auctioneer_id = intval($_POST['auctioneer_id']);
        $auctioneer_name = mysqli_real_escape_string($conn, $_POST['auctioneer_name'] ?? '');
        $firm_name = mysqli_real_escape_string($conn, $_POST['firm_name'] ?? '');
        $license_number = mysqli_real_escape_string($conn, $_POST['license_number'] ?? '');
        $license_valid_upto = !empty($_POST['license_valid_upto']) ? $_POST['license_valid_upto'] : null;
        $gst_number = mysqli_real_escape_string($conn, $_POST['gst_number'] ?? '');
        $pan_number = mysqli_real_escape_string($conn, $_POST['pan_number'] ?? '');
        $mobile_number = mysqli_real_escape_string($conn, $_POST['mobile_number'] ?? '');
        $alternate_mobile = mysqli_real_escape_string($conn, $_POST['alternate_mobile'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $address_line1 = mysqli_real_escape_string($conn, $_POST['address_line1'] ?? '');
        $address_line2 = mysqli_real_escape_string($conn, $_POST['address_line2'] ?? '');
        $city = mysqli_real_escape_string($conn, $_POST['city'] ?? '');
        $state = mysqli_real_escape_string($conn, $_POST['state'] ?? '');
        $pincode = mysqli_real_escape_string($conn, $_POST['pincode'] ?? '');
        $country = mysqli_real_escape_string($conn, $_POST['country'] ?? 'India');
        $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
        $branch_name = mysqli_real_escape_string($conn, $_POST['branch_name'] ?? '');
        $account_number = mysqli_real_escape_string($conn, $_POST['account_number'] ?? '');
        $ifsc_code = mysqli_real_escape_string($conn, $_POST['ifsc_code'] ?? '');
        $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
        $upi_id = mysqli_real_escape_string($conn, $_POST['upi_id'] ?? '');
        $commission_rate = floatval($_POST['commission_rate'] ?? 0);
        $commission_type = mysqli_real_escape_string($conn, $_POST['commission_type'] ?? 'percentage');
        $security_deposit = floatval($_POST['security_deposit'] ?? 0);
        $contract_start_date = !empty($_POST['contract_start_date']) ? $_POST['contract_start_date'] : null;
        $contract_end_date = !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        // Get current auctioneer data
        $current_query = "SELECT * FROM auctioneers WHERE id = $auctioneer_id";
        $current_result = $conn->query($current_query);
        $current = $current_result->fetch_assoc();
        
        // Handle photo upload
        $photo_path = $current['photo_path'];
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['photo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/auctioneers/photos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'auc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    // Delete old photo
                    if ($current['photo_path'] && file_exists($current['photo_path'])) {
                        unlink($current['photo_path']);
                    }
                    $photo_path = $upload_path;
                }
            }
        }
        
        // Handle document upload
        $document_path = $current['document_path'];
        if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            $filename = $_FILES['document']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $upload_dir = 'uploads/auctioneers/documents/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'doc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_path)) {
                    // Delete old document
                    if ($current['document_path'] && file_exists($current['document_path'])) {
                        unlink($current['document_path']);
                    }
                    $document_path = $upload_path;
                }
            }
        }
        
        $update_query = "UPDATE auctioneers SET 
            auctioneer_name = '$auctioneer_name',
            firm_name = " . ($firm_name ? "'$firm_name'" : "NULL") . ",
            license_number = " . ($license_number ? "'$license_number'" : "NULL") . ",
            license_valid_upto = " . ($license_valid_upto ? "'$license_valid_upto'" : "NULL") . ",
            gst_number = " . ($gst_number ? "'$gst_number'" : "NULL") . ",
            pan_number = " . ($pan_number ? "'$pan_number'" : "NULL") . ",
            mobile_number = '$mobile_number',
            alternate_mobile = " . ($alternate_mobile ? "'$alternate_mobile'" : "NULL") . ",
            email = " . ($email ? "'$email'" : "NULL") . ",
            address_line1 = " . ($address_line1 ? "'$address_line1'" : "NULL") . ",
            address_line2 = " . ($address_line2 ? "'$address_line2'" : "NULL") . ",
            city = " . ($city ? "'$city'" : "NULL") . ",
            state = " . ($state ? "'$state'" : "NULL") . ",
            pincode = " . ($pincode ? "'$pincode'" : "NULL") . ",
            country = '$country',
            bank_name = " . ($bank_name ? "'$bank_name'" : "NULL") . ",
            branch_name = " . ($branch_name ? "'$branch_name'" : "NULL") . ",
            account_number = " . ($account_number ? "'$account_number'" : "NULL") . ",
            ifsc_code = " . ($ifsc_code ? "'$ifsc_code'" : "NULL") . ",
            account_type = '$account_type',
            upi_id = " . ($upi_id ? "'$upi_id'" : "NULL") . ",
            commission_rate = $commission_rate,
            commission_type = '$commission_type',
            security_deposit = $security_deposit,
            contract_start_date = " . ($contract_start_date ? "'$contract_start_date'" : "NULL") . ",
            contract_end_date = " . ($contract_end_date ? "'$contract_end_date'" : "NULL") . ",
            is_active = $is_active,
            remarks = " . ($remarks ? "'$remarks'" : "NULL") . ",
            photo_path = " . ($photo_path ? "'$photo_path'" : "NULL") . ",
            document_path = " . ($document_path ? "'$document_path'" : "NULL") . ",
            updated_at = NOW()
            WHERE id = $auctioneer_id";
        
        if ($conn->query($update_query)) {
            $success = "Auctioneer updated successfully!";
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (" . $_SESSION['user_id'] . ", 'update', 'Updated auctioneer: $auctioneer_name', 'auctioneers', $auctioneer_id)";
            $conn->query($log_query);
        } else {
            $error = "Error updating auctioneer: " . $conn->error;
        }
    }
    
    // Delete auctioneer
    elseif (isset($_POST['delete_auctioneer'])) {
        $auctioneer_id = intval($_POST['auctioneer_id']);
        
        // Get auctioneer details for log
        $name_query = "SELECT auctioneer_name, photo_path, document_path FROM auctioneers WHERE id = $auctioneer_id";
        $name_result = $conn->query($name_query);
        $auctioneer = $name_result->fetch_assoc();
        
        // Delete files
        if ($auctioneer['photo_path'] && file_exists($auctioneer['photo_path'])) {
            unlink($auctioneer['photo_path']);
        }
        if ($auctioneer['document_path'] && file_exists($auctioneer['document_path'])) {
            unlink($auctioneer['document_path']);
        }
        
        $delete_query = "DELETE FROM auctioneers WHERE id = $auctioneer_id";
        
        if ($conn->query($delete_query)) {
            $success = "Auctioneer deleted successfully!";
            
            // Log activity
            $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                          VALUES (" . $_SESSION['user_id'] . ", 'delete', 'Deleted auctioneer: " . $auctioneer['auctioneer_name'] . "', 'auctioneers', $auctioneer_id)";
            $conn->query($log_query);
        } else {
            $error = "Error deleting auctioneer: " . $conn->error;
        }
    }
    
    // Toggle auctioneer status
    elseif (isset($_POST['toggle_status'])) {
        $auctioneer_id = intval($_POST['auctioneer_id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status ? 0 : 1;
        
        $update_query = "UPDATE auctioneers SET is_active = $new_status WHERE id = $auctioneer_id";
        
        if ($conn->query($update_query)) {
            echo json_encode(['success' => true, 'new_status' => $new_status]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit();
        }
    }
    
    // Add auction history
    elseif (isset($_POST['add_history'])) {
        $auctioneer_id = intval($_POST['auctioneer_id']);
        $auction_date = $_POST['auction_date'] ?? date('Y-m-d');
        $loan_id = !empty($_POST['loan_id']) ? intval($_POST['loan_id']) : null;
        $lot_number = mysqli_real_escape_string($conn, $_POST['lot_number'] ?? '');
        $item_description = mysqli_real_escape_string($conn, $_POST['item_description'] ?? '');
        $reserve_price = floatval($_POST['reserve_price'] ?? 0);
        $final_bid_amount = floatval($_POST['final_bid_amount'] ?? 0);
        
        // Calculate commission
        $commission_query = "SELECT commission_rate, commission_type FROM auctioneers WHERE id = $auctioneer_id";
        $comm_result = $conn->query($commission_query);
        $comm_data = $comm_result->fetch_assoc();
        
        $commission_amount = 0;
        if ($comm_data['commission_type'] == 'percentage') {
            $commission_amount = ($final_bid_amount * $comm_data['commission_rate']) / 100;
        } else {
            $commission_amount = $comm_data['commission_rate'];
        }
        
        $net_amount = $final_bid_amount - $commission_amount;
        $buyer_name = mysqli_real_escape_string($conn, $_POST['buyer_name'] ?? '');
        $buyer_mobile = mysqli_real_escape_string($conn, $_POST['buyer_mobile'] ?? '');
        $payment_status = mysqli_real_escape_string($conn, $_POST['payment_status'] ?? 'pending');
        $history_remarks = mysqli_real_escape_string($conn, $_POST['history_remarks'] ?? '');
        
        $insert_history = "INSERT INTO auction_history SET 
            auctioneer_id = $auctioneer_id,
            auction_date = '$auction_date',
            loan_id = " . ($loan_id ? $loan_id : "NULL") . ",
            lot_number = " . ($lot_number ? "'$lot_number'" : "NULL") . ",
            item_description = " . ($item_description ? "'$item_description'" : "NULL") . ",
            reserve_price = $reserve_price,
            final_bid_amount = $final_bid_amount,
            commission_amount = $commission_amount,
            net_amount = $net_amount,
            buyer_name = " . ($buyer_name ? "'$buyer_name'" : "NULL") . ",
            buyer_mobile = " . ($buyer_mobile ? "'$buyer_mobile'" : "NULL") . ",
            payment_status = '$payment_status',
            remarks = " . ($history_remarks ? "'$history_remarks'" : "NULL") . ",
            created_by = " . $_SESSION['user_id'] . ",
            created_at = NOW()";
        
        if ($conn->query($insert_history)) {
            $success = "Auction history added successfully!";
        } else {
            $error = "Error adding auction history: " . $conn->error;
        }
    }
}

// Get all auctioneers
$auctioneers_query = "SELECT a.*, 
                     (SELECT COUNT(*) FROM auction_history WHERE auctioneer_id = a.id) as total_auctions,
                     (SELECT SUM(final_bid_amount) FROM auction_history WHERE auctioneer_id = a.id) as total_sales
                     FROM auctioneers a 
                     ORDER BY a.created_at DESC";
$auctioneers_result = $conn->query($auctioneers_query);

// Get active auctioneers for dropdown
$active_auctioneers = $conn->query("SELECT id, auctioneer_code, auctioneer_name, firm_name FROM auctioneers WHERE is_active = 1 ORDER BY auctioneer_name");

// Get loans available for auction (defaulted loans)
$available_loans = $conn->query("SELECT l.id, l.receipt_number, c.customer_name, l.loan_amount 
                                 FROM loans l 
                                 JOIN customers c ON l.customer_id = c.id 
                                 WHERE l.status IN ('defaulted', 'auctioned') 
                                 ORDER BY l.created_at DESC LIMIT 50");

// Get auction history
$history_query = "SELECT h.*, a.auctioneer_name, a.auctioneer_code,
                  l.receipt_number, c.customer_name
                  FROM auction_history h
                  LEFT JOIN auctioneers a ON h.auctioneer_id = a.id
                  LEFT JOIN loans l ON h.loan_id = l.id
                  LEFT JOIN customers c ON l.customer_id = c.id
                  ORDER BY h.auction_date DESC, h.created_at DESC
                  LIMIT 100";
$history_result = $conn->query($history_query);

// Calculate statistics
$total_auctioneers = $conn->query("SELECT COUNT(*) as count FROM auctioneers")->fetch_assoc()['count'];
$active_count = $conn->query("SELECT COUNT(*) as count FROM auctioneers WHERE is_active = 1")->fetch_assoc()['count'];
$total_auctions = $conn->query("SELECT COUNT(*) as count FROM auction_history")->fetch_assoc()['count'];
$total_sales = $conn->query("SELECT SUM(final_bid_amount) as total FROM auction_history")->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: #667eea;
            background: #f7fafc;
        }

        .tab-btn.active {
            color: #667eea;
            background: #ebf4ff;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .form-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: #667eea;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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

        textarea.form-control {
            padding-left: 12px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        .status-pending {
            background: #feebc8;
            color: #744210;
        }

        .status-completed {
            background: #c6f6d5;
            color: #22543d;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table tbody tr:hover {
            background: #f7fafc;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .action-btn {
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .action-btn.view {
            background: #667eea10;
            color: #667eea;
        }

        .action-btn.view:hover {
            background: #667eea;
            color: white;
        }

        .action-btn.edit {
            background: #4299e110;
            color: #4299e1;
        }

        .action-btn.edit:hover {
            background: #4299e1;
            color: white;
        }

        .action-btn.history {
            background: #48bb7810;
            color: #48bb78;
        }

        .action-btn.history:hover {
            background: #48bb78;
            color: white;
        }

        .action-btn.delete {
            background: #f5656510;
            color: #f56565;
        }

        .action-btn.delete:hover {
            background: #f56565;
            color: white;
        }

        /* Status Toggle */
        .status-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .status-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e0;
            transition: .3s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #48bb78;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
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

        .alert-info {
            background: #e6f7ff;
            color: #0050b3;
            border-left: 4px solid #1890ff;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            overflow-y: auto;
        }

        .modal-content {
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 20px;
            max-width: 800px;
            width: 95%;
            position: relative;
            animation: slideIn 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: #667eea;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #a0aec0;
            cursor: pointer;
            transition: all 0.3s;
        }

        .close-btn:hover {
            color: #f56565;
            transform: rotate(90deg);
        }

        /* Current File Display */
        .current-file {
            font-size: 12px;
            color: #4a5568;
            margin-top: 5px;
            padding: 5px;
            background: #ebf4ff;
            border-radius: 4px;
        }

        .current-file i {
            color: #667eea;
            margin-right: 5px;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab-btn {
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
                <div class="container-fluid">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="page-title">
                            <i class="bi bi-gavel"></i>
                            <h1>Auctioneer Management</h1>
                        </div>
                        <div>
                            <button class="btn btn-primary" onclick="openAddModal()">
                                <i class="bi bi-plus-circle"></i> Add Auctioneer
                            </button>
                            <button class="btn btn-info" onclick="refreshPage()">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Auctioneers</div>
                                <div class="stat-value"><?php echo $total_auctioneers; ?></div>
                                <div class="stat-sub"><?php echo $active_count; ?> active</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-hammer"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Auctions</div>
                                <div class="stat-value"><?php echo number_format($total_auctions); ?></div>
                                <div class="stat-sub">Completed auctions</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-currency-rupee"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">Total Sales</div>
                                <div class="stat-value">₹<?php echo number_format($total_sales, 2); ?></div>
                                <div class="stat-sub">Auction value</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="bi bi-calendar"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-label">This Month</div>
                                <div class="stat-value"><?php 
                                    $month_query = "SELECT COUNT(*) as count FROM auction_history WHERE MONTH(auction_date) = MONTH(CURDATE()) AND YEAR(auction_date) = YEAR(CURDATE())";
                                    $month_result = $conn->query($month_query);
                                    echo $month_result->fetch_assoc()['count'];
                                ?></div>
                                <div class="stat-sub">Auctions this month</div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <button class="tab-btn active" onclick="switchTab('auctioneers')">
                            <i class="bi bi-list-ul"></i> Auctioneers
                        </button>
                        <button class="tab-btn" onclick="switchTab('history')">
                            <i class="bi bi-clock-history"></i> Auction History
                        </button>
                    </div>

                    <!-- Auctioneers Tab -->
                    <div class="tab-pane active" id="tab-auctioneers">
                        <div class="form-card">
                            <div class="form-title">
                                <i class="bi bi-people"></i>
                                Auctioneers List
                            </div>
                            <div class="table-responsive">
                                <table class="data-table" id="auctioneersTable">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name/Firm</th>
                                            <th>Contact</th>
                                            <th>License</th>
                                            <th>Commission</th>
                                            <th>Auctions</th>
                                            <th>Sales</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($auctioneers_result && $auctioneers_result->num_rows > 0): ?>
                                            <?php while ($row = $auctioneers_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($row['auctioneer_code']); ?></strong></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($row['auctioneer_name']); ?></strong>
                                                        <?php if ($row['firm_name']): ?>
                                                            <br><small><?php echo htmlspecialchars($row['firm_name']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($row['mobile_number']); ?>
                                                        <?php if ($row['email']): ?>
                                                            <br><small><?php echo htmlspecialchars($row['email']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($row['license_number']): ?>
                                                            <?php echo htmlspecialchars($row['license_number']); ?>
                                                            <?php if ($row['license_valid_upto']): ?>
                                                                <br><small>Valid till: <?php echo date('d-m-Y', strtotime($row['license_valid_upto'])); ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $row['commission_rate']; ?>%
                                                        <br><small><?php echo ucfirst($row['commission_type']); ?></small>
                                                    </td>
                                                    <td class="text-center"><?php echo $row['total_auctions'] ?? 0; ?></td>
                                                    <td>₹<?php echo number_format($row['total_sales'] ?? 0, 2); ?></td>
                                                    <td>
                                                        <label class="status-toggle">
                                                            <input type="checkbox" class="status-checkbox" data-id="<?php echo $row['id']; ?>" <?php echo $row['is_active'] ? 'checked' : ''; ?> onchange="toggleStatus(<?php echo $row['id']; ?>, this)">
                                                            <span class="toggle-slider"></span>
                                                        </label>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button class="action-btn view" onclick="viewAuctioneer(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <button class="action-btn edit" onclick="editAuctioneer(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="action-btn history" onclick="viewHistory(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['auctioneer_name']); ?>')">
                                                                <i class="bi bi-clock-history"></i>
                                                            </button>
                                                            <button class="action-btn delete" onclick="deleteAuctioneer(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['auctioneer_name'])); ?>')">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="9" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-people" style="font-size: 48px; color: #cbd5e0; display: block; margin-bottom: 10px;"></i>
                                                    No auctioneers found. Click "Add Auctioneer" to add one.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Auction History Tab -->
                    <div class="tab-pane" id="tab-history">
                        <div class="form-card">
                            <div class="form-title" style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="bi bi-clock-history"></i> Auction History</span>
                                <button class="btn btn-primary btn-sm" onclick="openHistoryModal()">
                                    <i class="bi bi-plus-circle"></i> Add Auction Record
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="data-table" id="historyTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Auctioneer</th>
                                            <th>Lot #</th>
                                            <th>Item</th>
                                            <th>Reserve</th>
                                            <th>Final Bid</th>
                                            <th>Commission</th>
                                            <th>Net</th>
                                            <th>Buyer</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($history_result && $history_result->num_rows > 0): ?>
                                            <?php while ($history = $history_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($history['auction_date'])); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($history['auctioneer_name']); ?></strong>
                                                        <br><small><?php echo htmlspecialchars($history['auctioneer_code']); ?></small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($history['lot_number'] ?? '-'); ?></td>
                                                    <td>
                                                        <?php if ($history['receipt_number']): ?>
                                                            <strong>Loan: <?php echo htmlspecialchars($history['receipt_number']); ?></strong>
                                                            <br><small><?php echo htmlspecialchars($history['customer_name'] ?? ''); ?></small>
                                                        <?php else: ?>
                                                            <?php echo substr(htmlspecialchars($history['item_description'] ?? ''), 0, 30); ?>...
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right">₹<?php echo number_format($history['reserve_price'], 2); ?></td>
                                                    <td class="text-right"><strong>₹<?php echo number_format($history['final_bid_amount'], 2); ?></strong></td>
                                                    <td class="text-right">₹<?php echo number_format($history['commission_amount'], 2); ?></td>
                                                    <td class="text-right">₹<?php echo number_format($history['net_amount'], 2); ?></td>
                                                    <td>
                                                        <?php if ($history['buyer_name']): ?>
                                                            <?php echo htmlspecialchars($history['buyer_name']); ?>
                                                            <br><small><?php echo htmlspecialchars($history['buyer_mobile'] ?? ''); ?></small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php 
                                                            echo $history['payment_status'] == 'completed' ? 'status-completed' : 
                                                                ($history['payment_status'] == 'partial' ? 'status-pending' : 'status-inactive'); 
                                                        ?>">
                                                            <?php echo ucfirst($history['payment_status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button class="action-btn view" onclick="viewHistoryDetails(<?php echo htmlspecialchars(json_encode($history)); ?>)">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="11" class="text-center" style="padding: 40px;">
                                                    <i class="bi bi-clock-history" style="font-size: 48px; color: #cbd5e0; display: block; margin-bottom: 10px;"></i>
                                                    No auction history found
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <!-- Add/Edit Auctioneer Modal -->
    <div id="auctioneerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="bi bi-gavel"></i> <span id="modalTitle">Add Auctioneer</span></h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="auctioneerForm">
                <input type="hidden" name="auctioneer_id" id="auctioneer_id" value="">
                
                <div class="form-title">
                    <i class="bi bi-person"></i> Personal Information
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Auctioneer Name</label>
                        <input type="text" class="form-control" name="auctioneer_name" id="auctioneer_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Firm Name</label>
                        <input type="text" class="form-control" name="firm_name" id="firm_name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">License Number</label>
                        <input type="text" class="form-control" name="license_number" id="license_number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">License Valid Upto</label>
                        <input type="date" class="form-control" name="license_valid_upto" id="license_valid_upto">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">GST Number</label>
                        <input type="text" class="form-control" name="gst_number" id="gst_number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">PAN Number</label>
                        <input type="text" class="form-control" name="pan_number" id="pan_number">
                    </div>
                </div>

                <div class="form-title">
                    <i class="bi bi-telephone"></i> Contact Information
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Mobile Number</label>
                        <input type="text" class="form-control" name="mobile_number" id="mobile_number" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Alternate Mobile</label>
                        <input type="text" class="form-control" name="alternate_mobile" id="alternate_mobile">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="email">
                    </div>
                </div>

                <div class="form-title">
                    <i class="bi bi-house"></i> Address
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" class="form-control" name="address_line1" id="address_line1">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" class="form-control" name="address_line2" id="address_line2">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" class="form-control" name="city" id="city">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" class="form-control" name="state" id="state">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Pincode</label>
                        <input type="text" class="form-control" name="pincode" id="pincode">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="country" id="country" value="India">
                    </div>
                </div>

                <div class="form-title">
                    <i class="bi bi-bank"></i> Bank Details
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Bank Name</label>
                        <input type="text" class="form-control" name="bank_name" id="bank_name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Branch Name</label>
                        <input type="text" class="form-control" name="branch_name" id="branch_name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Account Number</label>
                        <input type="text" class="form-control" name="account_number" id="account_number">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">IFSC Code</label>
                        <input type="text" class="form-control" name="ifsc_code" id="ifsc_code">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Account Type</label>
                        <select class="form-select" name="account_type" id="account_type">
                            <option value="savings">Savings</option>
                            <option value="current">Current</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">UPI ID</label>
                        <input type="text" class="form-control" name="upi_id" id="upi_id">
                    </div>
                </div>

                <div class="form-title">
                    <i class="bi bi-gear"></i> Commission & Contract
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Commission Rate</label>
                        <input type="number" step="0.01" class="form-control" name="commission_rate" id="commission_rate" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Commission Type</label>
                        <select class="form-select" name="commission_type" id="commission_type">
                            <option value="percentage">Percentage (%)</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Security Deposit</label>
                        <input type="number" step="0.01" class="form-control" name="security_deposit" id="security_deposit" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contract Start Date</label>
                        <input type="date" class="form-control" name="contract_start_date" id="contract_start_date">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contract End Date</label>
                        <input type="date" class="form-control" name="contract_end_date" id="contract_end_date">
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check form-switch" style="margin-top: 30px;">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>

                <div class="form-title">
                    <i class="bi bi-file-earmark"></i> Documents & Photos
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Photo</label>
                        <input type="file" class="form-control" name="photo" accept="image/*">
                        <div id="current_photo"></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Document</label>
                        <input type="file" class="form-control" name="document" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <div id="current_document"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" id="remarks" rows="3"></textarea>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" name="add_auctioneer" id="submitBtn" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Save Auctioneer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Auction History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2><i class="bi bi-clock-history"></i> Add Auction Record</h2>
                <button class="close-btn" onclick="closeHistoryModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="add_history" value="1">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Auctioneer</label>
                        <select class="form-select" name="auctioneer_id" required>
                            <option value="">Select Auctioneer</option>
                            <?php 
                            if ($active_auctioneers && $active_auctioneers->num_rows > 0) {
                                while ($auc = $active_auctioneers->fetch_assoc()) {
                                    echo "<option value='{$auc['id']}'>{$auc['auctioneer_code']} - {$auc['auctioneer_name']}" . ($auc['firm_name'] ? " ({$auc['firm_name']})" : "") . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label required">Auction Date</label>
                        <input type="date" class="form-control" name="auction_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Loan (Optional)</label>
                        <select class="form-select" name="loan_id">
                            <option value="">Select Loan</option>
                            <?php 
                            if ($available_loans && $available_loans->num_rows > 0) {
                                while ($loan = $available_loans->fetch_assoc()) {
                                    echo "<option value='{$loan['id']}'>{$loan['receipt_number']} - {$loan['customer_name']} (₹{$loan['loan_amount']})</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Lot Number</label>
                        <input type="text" class="form-control" name="lot_number">
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Item Description</label>
                        <textarea class="form-control" name="item_description" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reserve Price (₹)</label>
                        <input type="number" step="0.01" class="form-control" name="reserve_price" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Final Bid Amount (₹)</label>
                        <input type="number" step="0.01" class="form-control" name="final_bid_amount" value="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" class="form-control" name="buyer_name">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Buyer Mobile</label>
                        <input type="text" class="form-control" name="buyer_mobile">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Payment Status</label>
                        <select class="form-select" name="payment_status">
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="history_remarks" rows="2"></textarea>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Add Record
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Auctioneer Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="bi bi-eye"></i> Auctioneer Details</h2>
                <button class="close-btn" onclick="closeViewModal()">&times;</button>
            </div>
            <div id="viewContent" style="padding: 10px;"></div>
        </div>
    </div>

    <!-- View History Details Modal -->
    <div id="viewHistoryModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2><i class="bi bi-clock-history"></i> Auction Details</h2>
                <button class="close-btn" onclick="closeViewHistoryModal()">&times;</button>
            </div>
            <div id="viewHistoryContent"></div>
        </div>
    </div>

    <!-- Include required JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Initialize date pickers
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Initialize DataTables
        $(document).ready(function() {
            $('#auctioneersTable').DataTable({
                pageLength: 10,
                order: [[0, 'desc']],
                language: {
                    search: "Search auctioneers:",
                    lengthMenu: "Show _MENU_ auctioneers",
                    info: "Showing _START_ to _END_ of _TOTAL_ auctioneers",
                    emptyTable: "No auctioneers found"
                }
            });
            
            $('#historyTable').DataTable({
                pageLength: 10,
                order: [[0, 'desc']],
                language: {
                    search: "Search history:",
                    lengthMenu: "Show _MENU_ records",
                    info: "Showing _START_ to _END_ of _TOTAL_ records",
                    emptyTable: "No auction history found"
                }
            });
        });

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        // Refresh page
        function refreshPage() {
            window.location.reload();
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Auctioneer';
            document.getElementById('auctioneer_id').value = '';
            document.getElementById('auctioneerForm').reset();
            document.getElementById('is_active').checked = true;
            document.getElementById('current_photo').innerHTML = '';
            document.getElementById('current_document').innerHTML = '';
            document.getElementById('submitBtn').name = 'add_auctioneer';
            document.getElementById('auctioneerModal').style.display = 'block';
        }

        function editAuctioneer(data) {
            document.getElementById('modalTitle').textContent = 'Edit Auctioneer';
            document.getElementById('auctioneer_id').value = data.id;
            document.getElementById('auctioneer_name').value = data.auctioneer_name || '';
            document.getElementById('firm_name').value = data.firm_name || '';
            document.getElementById('license_number').value = data.license_number || '';
            document.getElementById('license_valid_upto').value = data.license_valid_upto || '';
            document.getElementById('gst_number').value = data.gst_number || '';
            document.getElementById('pan_number').value = data.pan_number || '';
            document.getElementById('mobile_number').value = data.mobile_number || '';
            document.getElementById('alternate_mobile').value = data.alternate_mobile || '';
            document.getElementById('email').value = data.email || '';
            document.getElementById('address_line1').value = data.address_line1 || '';
            document.getElementById('address_line2').value = data.address_line2 || '';
            document.getElementById('city').value = data.city || '';
            document.getElementById('state').value = data.state || '';
            document.getElementById('pincode').value = data.pincode || '';
            document.getElementById('country').value = data.country || 'India';
            document.getElementById('bank_name').value = data.bank_name || '';
            document.getElementById('branch_name').value = data.branch_name || '';
            document.getElementById('account_number').value = data.account_number || '';
            document.getElementById('ifsc_code').value = data.ifsc_code || '';
            document.getElementById('account_type').value = data.account_type || 'savings';
            document.getElementById('upi_id').value = data.upi_id || '';
            document.getElementById('commission_rate').value = data.commission_rate || 0;
            document.getElementById('commission_type').value = data.commission_type || 'percentage';
            document.getElementById('security_deposit').value = data.security_deposit || 0;
            document.getElementById('contract_start_date').value = data.contract_start_date || '';
            document.getElementById('contract_end_date').value = data.contract_end_date || '';
            document.getElementById('remarks').value = data.remarks || '';
            document.getElementById('is_active').checked = data.is_active == 1;
            
            if (data.photo_path) {
                document.getElementById('current_photo').innerHTML = '<div class="current-file"><i class="bi bi-image"></i> Current: ' + data.photo_path.split('/').pop() + '</div>';
            }
            if (data.document_path) {
                document.getElementById('current_document').innerHTML = '<div class="current-file"><i class="bi bi-file-earmark"></i> Current: ' + data.document_path.split('/').pop() + '</div>';
            }
            
            document.getElementById('submitBtn').name = 'update_auctioneer';
            document.getElementById('auctioneerModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('auctioneerModal').style.display = 'none';
        }

        function openHistoryModal() {
            document.getElementById('historyModal').style.display = 'block';
        }

        function closeHistoryModal() {
            document.getElementById('historyModal').style.display = 'none';
        }

        function viewAuctioneer(data) {
            const content = document.getElementById('viewContent');
            const statusClass = data.is_active == 1 ? 'status-active' : 'status-inactive';
            const statusText = data.is_active == 1 ? 'Active' : 'Inactive';
            
            content.innerHTML = `
                <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                    ${data.photo_path ? 
                        `<img src="${data.photo_path}" style="width: 100px; height: 100px; object-fit: cover; border-radius: 10px;">` : 
                        `<div style="width: 100px; height: 100px; background: #f7fafc; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #a0aec0;"><i class="bi bi-person" style="font-size: 40px;"></i></div>`
                    }
                    <div>
                        <h3 style="font-size: 20px; font-weight: 600; margin-bottom: 5px;">${data.auctioneer_name}</h3>
                        ${data.firm_name ? `<p style="color: #718096;">${data.firm_name}</p>` : ''}
                        <p><span class="status-badge ${statusClass}">${statusText}</span></p>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Contact Information</h4>
                        <p><strong>Mobile:</strong> ${data.mobile_number || 'N/A'}</p>
                        ${data.alternate_mobile ? `<p><strong>Alternate:</strong> ${data.alternate_mobile}</p>` : ''}
                        ${data.email ? `<p><strong>Email:</strong> ${data.email}</p>` : ''}
                    </div>
                    
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">License Information</h4>
                        ${data.license_number ? `<p><strong>License No:</strong> ${data.license_number}</p>` : ''}
                        ${data.license_valid_upto ? `<p><strong>Valid Until:</strong> ${new Date(data.license_valid_upto).toLocaleDateString()}</p>` : ''}
                        ${data.gst_number ? `<p><strong>GST:</strong> ${data.gst_number}</p>` : ''}
                        ${data.pan_number ? `<p><strong>PAN:</strong> ${data.pan_number}</p>` : ''}
                    </div>
                    
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Address</h4>
                        <p>${data.address_line1 ? data.address_line1 + '<br>' : ''}${data.address_line2 ? data.address_line2 + '<br>' : ''}${data.city ? data.city + ', ' : ''}${data.state ? data.state + ' - ' : ''}${data.pincode ? data.pincode : ''}<br>${data.country || 'India'}</p>
                    </div>
                    
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Bank Details</h4>
                        ${data.bank_name ? `<p><strong>Bank:</strong> ${data.bank_name}</p>` : ''}
                        ${data.branch_name ? `<p><strong>Branch:</strong> ${data.branch_name}</p>` : ''}
                        ${data.account_number ? `<p><strong>Account:</strong> ${data.account_number}</p>` : ''}
                        ${data.ifsc_code ? `<p><strong>IFSC:</strong> ${data.ifsc_code}</p>` : ''}
                        ${data.upi_id ? `<p><strong>UPI:</strong> ${data.upi_id}</p>` : ''}
                    </div>
                    
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Commission & Contract</h4>
                        <p><strong>Commission:</strong> ${data.commission_rate} ${data.commission_type == 'percentage' ? '%' : '₹'}</p>
                        <p><strong>Security Deposit:</strong> ₹${parseFloat(data.security_deposit).toFixed(2)}</p>
                        ${data.contract_start_date ? `<p><strong>Contract From:</strong> ${new Date(data.contract_start_date).toLocaleDateString()}</p>` : ''}
                        ${data.contract_end_date ? `<p><strong>Contract To:</strong> ${new Date(data.contract_end_date).toLocaleDateString()}</p>` : ''}
                    </div>
                    
                    <div style="background: #f7fafc; padding: 15px; border-radius: 8px;">
                        <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Statistics</h4>
                        <p><strong>Total Auctions:</strong> ${data.total_auctions || 0}</p>
                        <p><strong>Total Sales:</strong> ₹${parseFloat(data.total_sales || 0).toFixed(2)}</p>
                    </div>
                </div>
                
                ${data.remarks ? `
                <div style="margin-top: 15px; background: #f7fafc; padding: 15px; border-radius: 8px;">
                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 10px; color: #4a5568;">Remarks</h4>
                    <p>${data.remarks}</p>
                </div>
                ` : ''}
                
                ${data.document_path ? `
                <div style="margin-top: 15px;">
                    <a href="${data.document_path}" target="_blank" class="btn btn-primary btn-sm">
                        <i class="bi bi-file-earmark"></i> View Document
                    </a>
                </div>
                ` : ''}
            `;
            
            document.getElementById('viewModal').style.display = 'block';
        }

        function viewHistoryDetails(history) {
            const content = document.getElementById('viewHistoryContent');
            
            content.innerHTML = `
                <div style="background: #f7fafc; padding: 20px; border-radius: 8px;">
                    <p><strong>Auction Date:</strong> ${new Date(history.auction_date).toLocaleDateString()}</p>
                    <p><strong>Auctioneer:</strong> ${history.auctioneer_name} (${history.auctioneer_code})</p>
                    ${history.lot_number ? `<p><strong>Lot Number:</strong> ${history.lot_number}</p>` : ''}
                    ${history.receipt_number ? `<p><strong>Loan Receipt:</strong> ${history.receipt_number}</p>` : ''}
                    ${history.customer_name ? `<p><strong>Customer:</strong> ${history.customer_name}</p>` : ''}
                    <p><strong>Item Description:</strong> ${history.item_description || 'N/A'}</p>
                    <p><strong>Reserve Price:</strong> ₹${parseFloat(history.reserve_price).toFixed(2)}</p>
                    <p><strong>Final Bid Amount:</strong> ₹${parseFloat(history.final_bid_amount).toFixed(2)}</p>
                    <p><strong>Commission:</strong> ₹${parseFloat(history.commission_amount).toFixed(2)}</p>
                    <p><strong>Net Amount:</strong> ₹${parseFloat(history.net_amount).toFixed(2)}</p>
                    ${history.buyer_name ? `<p><strong>Buyer:</strong> ${history.buyer_name} (${history.buyer_mobile || ''})</p>` : ''}
                    <p><strong>Payment Status:</strong> <span class="status-badge ${history.payment_status == 'completed' ? 'status-completed' : (history.payment_status == 'partial' ? 'status-pending' : 'status-inactive')}">${history.payment_status}</span></p>
                    ${history.remarks ? `<p><strong>Remarks:</strong> ${history.remarks}</p>` : ''}
                </div>
            `;
            
            document.getElementById('viewHistoryModal').style.display = 'block';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function closeViewHistoryModal() {
            document.getElementById('viewHistoryModal').style.display = 'none';
        }

        function viewHistory(id, name) {
            Swal.fire({
                title: 'View History',
                html: `View auction history for <strong>${name}</strong>`,
                icon: 'info',
                confirmButtonColor: '#667eea'
            });
        }

        function deleteAuctioneer(id, name) {
            Swal.fire({
                title: 'Delete Auctioneer?',
                html: `Are you sure you want to delete <strong>${name}</strong>?<br>This will also delete all auction history for this auctioneer.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#f56565',
                cancelButtonColor: '#a0aec0',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="delete_auctioneer" value="1">
                        <input type="hidden" name="auctioneer_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        function toggleStatus(id, checkbox) {
            const currentStatus = checkbox.checked ? 1 : 0;
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `toggle_status=1&auctioneer_id=${id}&current_status=${currentStatus ? 0 : 1}`
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    checkbox.checked = !checkbox.checked;
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.error || 'Failed to update status'
                    });
                }
            })
            .catch(error => {
                checkbox.checked = !checkbox.checked;
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to update status'
                });
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('auctioneerModal');
            const viewModal = document.getElementById('viewModal');
            const historyModal = document.getElementById('historyModal');
            const viewHistoryModal = document.getElementById('viewHistoryModal');
            
            if (event.target === modal) {
                closeModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === historyModal) {
                closeHistoryModal();
            }
            if (event.target === viewHistoryModal) {
                closeViewHistoryModal();
            }
        }
    </script>

    <?php include 'includes/scripts.php'; ?>
</body>
</html>