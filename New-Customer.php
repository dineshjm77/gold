<?php
session_start();
$currentPage = 'new-customer';
$pageTitle = 'New Customer';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Get customer ID for editing if provided
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$customer_data = [];
$is_edit = ($edit_id > 0);

// Fetch customer data if editing
if ($is_edit) {
    $query = "SELECT * FROM customers WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, 'i', $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $customer_data = mysqli_fetch_assoc($result);
    
    if (!$customer_data) {
        header('Location: customers.php?error=notfound');
        exit();
    }
}

// Get existing mobile numbers for suggestions
$mobile_suggestions_query = "SELECT mobile_number, customer_name FROM customers WHERE mobile_number IS NOT NULL AND mobile_number != '' ORDER BY created_at DESC LIMIT 10";
$mobile_suggestions_result = mysqli_query($conn, $mobile_suggestions_query);
$mobile_suggestions = [];
while ($row = mysqli_fetch_assoc($mobile_suggestions_result)) {
    $mobile_suggestions[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get customer ID if provided (for editing)
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $is_edit = ($customer_id > 0);
    
    // Customer Information
    $customer_name = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
    $guardian_type = mysqli_real_escape_string($conn, $_POST['guardian_type'] ?? '');
    $guardian_name = mysqli_real_escape_string($conn, trim($_POST['guardian_name'] ?? ''));
    $guardian_mobile = mysqli_real_escape_string($conn, trim($_POST['guardian_mobile'] ?? ''));
    $mobile_number = mysqli_real_escape_string($conn, trim($_POST['mobile_number'] ?? ''));
    $alternate_mobile = mysqli_real_escape_string($conn, trim($_POST['alternate_mobile'] ?? ''));
    
    // Check if WhatsApp same as mobile
    $whatsapp_same = isset($_POST['whatsapp_same']) ? 1 : 0;
    $whatsapp_number = $whatsapp_same ? $mobile_number : mysqli_real_escape_string($conn, trim($_POST['whatsapp_number'] ?? ''));
    
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    
    // Address Information with Location
    $door_no = mysqli_real_escape_string($conn, trim($_POST['door_no'] ?? ''));
    $house_name = mysqli_real_escape_string($conn, trim($_POST['house_name'] ?? ''));
    $street_name = mysqli_real_escape_string($conn, trim($_POST['street_name'] ?? ''));
    $street_name1 = mysqli_real_escape_string($conn, trim($_POST['street_name1'] ?? ''));
    $landmark = mysqli_real_escape_string($conn, trim($_POST['landmark'] ?? ''));
    $location = mysqli_real_escape_string($conn, trim($_POST['location'] ?? ''));
    $pincode = mysqli_real_escape_string($conn, trim($_POST['pincode'] ?? ''));
    $post = mysqli_real_escape_string($conn, trim($_POST['post'] ?? ''));
    $taluk = mysqli_real_escape_string($conn, trim($_POST['taluk'] ?? ''));
    $district = mysqli_real_escape_string($conn, trim($_POST['district'] ?? ''));
    
    // Location fields from map
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    $place_id = mysqli_real_escape_string($conn, trim($_POST['place_id'] ?? ''));
    $formatted_address = mysqli_real_escape_string($conn, trim($_POST['formatted_address'] ?? ''));
    
    // KYC Information
    $aadhaar_number = mysqli_real_escape_string($conn, trim($_POST['aadhaar_number'] ?? ''));
    
    // Bank Details with IFSC
    $account_holder_name = mysqli_real_escape_string($conn, trim($_POST['account_holder_name'] ?? ''));
    $bank_name = mysqli_real_escape_string($conn, trim($_POST['bank_name'] ?? ''));
    $branch_name = mysqli_real_escape_string($conn, trim($_POST['branch_name'] ?? ''));
    $bank_address = mysqli_real_escape_string($conn, trim($_POST['bank_address'] ?? ''));
    $account_number = mysqli_real_escape_string($conn, trim($_POST['account_number'] ?? ''));
    $confirm_account_number = $_POST['confirm_account_number'] ?? '';
    $ifsc_code = mysqli_real_escape_string($conn, trim($_POST['ifsc_code'] ?? ''));
    $account_type = mysqli_real_escape_string($conn, $_POST['account_type'] ?? 'savings');
    $upi_id = mysqli_real_escape_string($conn, trim($_POST['upi_id'] ?? ''));
    
    // Additional Information
    $company_name = mysqli_real_escape_string($conn, trim($_POST['company_name'] ?? ''));
    $referral_person = mysqli_real_escape_string($conn, trim($_POST['referral_person'] ?? ''));
    $referral_mobile = mysqli_real_escape_string($conn, trim($_POST['referral_mobile'] ?? ''));
    $alert_message = mysqli_real_escape_string($conn, trim($_POST['alert_message'] ?? ''));
    $loan_limit_amount = !empty($_POST['loan_limit_amount']) ? floatval($_POST['loan_limit_amount']) : 10000000.00;
    
    // Noted Person Information
    $is_noted_person = isset($_POST['is_noted_person']) ? 1 : 0;
    $noted_person_remarks = mysqli_real_escape_string($conn, trim($_POST['noted_person_remarks'] ?? ''));

    // Validate account numbers if provided
    if (!empty($account_number) && $account_number !== $confirm_account_number) {
        $error = "Account numbers do not match.";
    }

    // Validate mobile number (required)
    if (empty($mobile_number)) {
        $error = "Mobile number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $mobile_number)) {
        $error = "Please enter a valid 10-digit mobile number.";
    }

    // Check if mobile number already exists (for new customers only)
    if (empty($error) && !$is_edit) {
        $check_query = "SELECT id FROM customers WHERE mobile_number = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 's', $mobile_number);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Mobile number already exists. Please use a different number.";
        }
    }

    // Handle photo upload or camera capture
    $customer_photo = null;
    $old_photo = null;
    
    // For edit mode, get old photo
    if ($is_edit) {
        $photo_query = "SELECT customer_photo FROM customers WHERE id = ?";
        $photo_stmt = mysqli_prepare($conn, $photo_query);
        mysqli_stmt_bind_param($photo_stmt, 'i', $customer_id);
        mysqli_stmt_execute($photo_stmt);
        $photo_result = mysqli_stmt_get_result($photo_stmt);
        if ($photo_row = mysqli_fetch_assoc($photo_result)) {
            $old_photo = $photo_row['customer_photo'];
        }
    }

    // Check for camera capture
    if (empty($error) && isset($_POST['captured_photo']) && !empty($_POST['captured_photo'])) {
        $image_data = $_POST['captured_photo'];
        
        // Remove data:image/png;base64, part
        if (preg_match('/^data:image\/(\w+);base64,/', $image_data, $type)) {
            $image_data = substr($image_data, strpos($image_data, ',') + 1);
            $type = strtolower($type[1]); // jpg, jpeg, png, etc.
            
            if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
                $error = "Invalid image format.";
            } else {
                $image_data = base64_decode($image_data);
                if ($image_data === false) {
                    $error = "Failed to decode image.";
                }
            }
        } else {
            $error = "Invalid image data.";
        }
        
        if (empty($error)) {
            $upload_dir = "uploads/customers/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // If editing and has old photo, delete it
            if ($is_edit && $old_photo && file_exists($old_photo)) {
                @unlink($old_photo);
            }
            
            // Create customer-specific folder
            if ($is_edit) {
                $customer_folder = $upload_dir . $customer_id . '/';
                if (!file_exists($customer_folder)) {
                    mkdir($customer_folder, 0777, true);
                }
                $filename = 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $type;
                $filepath = $customer_folder . $filename;
            } else {
                // For new customers, use temp folder
                $temp_folder = $upload_dir . 'temp/';
                if (!file_exists($temp_folder)) {
                    mkdir($temp_folder, 0777, true);
                }
                $filename = 'temp_' . time() . '_' . rand(1000, 9999) . '.' . $type;
                $filepath = $temp_folder . $filename;
            }
            
            if (file_put_contents($filepath, $image_data)) {
                $customer_photo = $filepath;
            } else {
                $error = "Failed to save captured photo.";
            }
        }
    }
    // Handle file upload
    elseif (empty($error) && isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($_FILES['customer_photo']['type'], $allowed_types)) {
            $error = "Only JPG and PNG images are allowed for photo.";
        } elseif ($_FILES['customer_photo']['size'] > $max_size) {
            $error = "Photo size must be less than 2MB.";
        } else {
            $upload_dir = "uploads/customers/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // If editing and has old photo, delete it
            if ($is_edit && $old_photo && file_exists($old_photo)) {
                @unlink($old_photo);
            }
            
            $ext = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
            
            // Create customer-specific folder
            if ($is_edit) {
                $customer_folder = $upload_dir . $customer_id . '/';
                if (!file_exists($customer_folder)) {
                    mkdir($customer_folder, 0777, true);
                }
                $filename = 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $filepath = $customer_folder . $filename;
            } else {
                // For new customers, use temp folder
                $temp_folder = $upload_dir . 'temp/';
                if (!file_exists($temp_folder)) {
                    mkdir($temp_folder, 0777, true);
                }
                $filename = 'temp_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $filepath = $temp_folder . $filename;
            }
            
            if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $filepath)) {
                $customer_photo = $filepath;
            } else {
                $error = "Failed to upload photo.";
            }
        }
    } elseif ($is_edit && !$customer_photo) {
        // Keep old photo if no new photo uploaded
        $customer_photo = $old_photo;
    }

    // Handle Aadhaar photo uploads
    $aadhar_front = null;
    $aadhar_back = null;
    $aadhar_pdf = null;

    // Process Aadhaar Front (upload or captured)
    if (isset($_FILES['aadhar_front']) && $_FILES['aadhar_front']['error'] == 0) {
        $result = handleAadhaarUpload($_FILES['aadhar_front'], 'front', $customer_id, $is_edit);
        if ($result['success']) {
            $aadhar_front = $result['path'];
        } else {
            $error = $result['error'];
        }
    } elseif (isset($_POST['captured_aadhar_front']) && !empty($_POST['captured_aadhar_front'])) {
        // Handle captured Aadhaar front image
        $result = handleCapturedAadhaar($_POST['captured_aadhar_front'], 'front', $customer_id, $is_edit);
        if ($result['success']) {
            $aadhar_front = $result['path'];
        } else {
            $error = $result['error'];
        }
    }

    // Process Aadhaar Back (upload or captured)
    if (isset($_FILES['aadhar_back']) && $_FILES['aadhar_back']['error'] == 0) {
        $result = handleAadhaarUpload($_FILES['aadhar_back'], 'back', $customer_id, $is_edit);
        if ($result['success']) {
            $aadhar_back = $result['path'];
        } else {
            $error = $result['error'];
        }
    } elseif (isset($_POST['captured_aadhar_back']) && !empty($_POST['captured_aadhar_back'])) {
        // Handle captured Aadhaar back image
        $result = handleCapturedAadhaar($_POST['captured_aadhar_back'], 'back', $customer_id, $is_edit);
        if ($result['success']) {
            $aadhar_back = $result['path'];
        } else {
            $error = $result['error'];
        }
    }

    // Generate PDF if both images are available
    if (empty($error) && $aadhar_front && $aadhar_back) {
        require_once('includes/fpdf/fpdf.php');
        
        $pdf = new FPDF();
        $pdf->AddPage();
        
        // Add front image
        $pdf->Image($aadhar_front, 10, 10, 190);
        $pdf->AddPage();
        
        // Add back image
        $pdf->Image($aadhar_back, 10, 10, 190);
        
        $pdf_dir = "uploads/aadhaar_pdfs/";
        if (!file_exists($pdf_dir)) {
            mkdir($pdf_dir, 0777, true);
        }
        
        if ($is_edit) {
            $pdf_folder = $pdf_dir . $customer_id . '/';
            if (!file_exists($pdf_folder)) {
                mkdir($pdf_folder, 0777, true);
            }
            $pdf_filename = 'aadhaar_' . time() . '_' . rand(1000, 9999) . '.pdf';
            $pdf_path = $pdf_folder . $pdf_filename;
        } else {
            $temp_pdf_dir = $pdf_dir . 'temp/';
            if (!file_exists($temp_pdf_dir)) {
                mkdir($temp_pdf_dir, 0777, true);
            }
            $pdf_filename = 'temp_' . time() . '_' . rand(1000, 9999) . '.pdf';
            $pdf_path = $temp_pdf_dir . $pdf_filename;
        }
        
        $pdf->Output('F', $pdf_path);
        $aadhar_pdf = $pdf_path;
    }

    // If no errors, proceed with insert/update
    if (empty($error)) {
        if ($is_edit) {
            // For edit mode, keep existing Aadhaar files if not uploading new ones
            if (!$aadhar_front) {
                $aadhar_front = $customer_data['aadhar_front'] ?? null;
            }
            if (!$aadhar_back) {
                $aadhar_back = $customer_data['aadhar_back'] ?? null;
            }
            if (!$aadhar_pdf) {
                $aadhar_pdf = $customer_data['aadhar_pdf'] ?? null;
            }

            $update_query = "UPDATE customers SET 
                customer_name = ?, guardian_type = ?, guardian_name = ?, guardian_mobile = ?,
                mobile_number = ?, alternate_mobile = ?, whatsapp_number = ?, email = ?,
                door_no = ?, house_name = ?, street_name = ?, street_name1 = ?, 
                landmark = ?, location = ?, pincode = ?, post = ?, taluk = ?, district = ?,
                latitude = ?, longitude = ?, place_id = ?, formatted_address = ?,
                aadhaar_number = ?, aadhar_front = ?, aadhar_back = ?, aadhar_pdf = ?,
                account_holder_name = ?, bank_name = ?, branch_name = ?, bank_address = ?,
                account_number = ?, ifsc_code = ?, account_type = ?, upi_id = ?,
                company_name = ?, referral_person = ?, referral_mobile = ?, 
                alert_message = ?, loan_limit_amount = ?, is_noted_person = ?, 
                noted_person_remarks = ?, customer_photo = ?, updated_at = NOW()
                WHERE id = ?";
                
            $update_stmt = mysqli_prepare($conn, $update_query);
            
            $types = 'sssssssssssssssssssssssssssssssssssssssssssi'; // 45 's' + 1 'i'
            
            mysqli_stmt_bind_param($update_stmt, $types,
                $customer_name, $guardian_type, $guardian_name, $guardian_mobile,
                $mobile_number, $alternate_mobile, $whatsapp_number, $email,
                $door_no, $house_name, $street_name, $street_name1,
                $landmark, $location, $pincode, $post, $taluk, $district,
                $latitude, $longitude, $place_id, $formatted_address,
                $aadhaar_number, $aadhar_front, $aadhar_back, $aadhar_pdf,
                $account_holder_name, $bank_name, $branch_name, $bank_address,
                $account_number, $ifsc_code, $account_type, $upi_id,
                $company_name, $referral_person, $referral_mobile,
                $alert_message, $loan_limit_amount, $is_noted_person,
                $noted_person_remarks, $customer_photo, $customer_id
            );
            
            if (mysqli_stmt_execute($update_stmt)) {
                // Move photo if it's in temp folder
                if ($customer_photo && strpos($customer_photo, '/temp/') !== false) {
                    $new_folder = "uploads/customers/" . $customer_id . "/";
                    if (!file_exists($new_folder)) {
                        mkdir($new_folder, 0777, true);
                    }
                    $filename = basename($customer_photo);
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $new_path = $new_folder . 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    
                    if (rename($customer_photo, $new_path)) {
                        // Update database with new path
                        $update_photo = "UPDATE customers SET customer_photo = ? WHERE id = ?";
                        $photo_stmt = mysqli_prepare($conn, $update_photo);
                        mysqli_stmt_bind_param($photo_stmt, 'si', $new_path, $customer_id);
                        mysqli_stmt_execute($photo_stmt);
                    }
                }
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'update', ?, 'customers', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $log_description = "Customer updated: " . $customer_name;
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $customer_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: customers.php?success=updated');
                exit();
            } else {
                $error = "Error updating customer: " . mysqli_error($conn);
            }
        } else {
            $insert_query = "INSERT INTO customers (
                customer_name, guardian_type, guardian_name, guardian_mobile,
                mobile_number, alternate_mobile, whatsapp_number, email, 
                door_no, house_name, street_name, street_name1, landmark, 
                location, pincode, post, taluk, district,
                latitude, longitude, place_id, formatted_address,
                aadhaar_number, aadhar_front, aadhar_back, aadhar_pdf,
                account_holder_name, bank_name, branch_name, bank_address,
                account_number, ifsc_code, account_type, upi_id,
                company_name, referral_person, referral_mobile,
                alert_message, loan_limit_amount, is_noted_person, 
                noted_person_remarks, customer_photo, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )";
            
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            
            $types = 'ssssssssssssssssssssssssssssssssssssssssssss'; // 44 's' parameters
            
            mysqli_stmt_bind_param($insert_stmt, $types,
                $customer_name, $guardian_type, $guardian_name, $guardian_mobile,
                $mobile_number, $alternate_mobile, $whatsapp_number, $email,
                $door_no, $house_name, $street_name, $street_name1,
                $landmark, $location, $pincode, $post, $taluk, $district,
                $latitude, $longitude, $place_id, $formatted_address,
                $aadhaar_number, $aadhar_front, $aadhar_back, $aadhar_pdf,
                $account_holder_name, $bank_name, $branch_name, $bank_address,
                $account_number, $ifsc_code, $account_type, $upi_id,
                $company_name, $referral_person, $referral_mobile,
                $alert_message, $loan_limit_amount, $is_noted_person,
                $noted_person_remarks, $customer_photo
            );
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $new_customer_id = mysqli_insert_id($conn);
                
                // Move photo if it exists and is in temp folder
                if ($customer_photo && strpos($customer_photo, '/temp/') !== false) {
                    $new_folder = "uploads/customers/" . $new_customer_id . "/";
                    if (!file_exists($new_folder)) {
                        mkdir($new_folder, 0777, true);
                    }
                    $filename = basename($customer_photo);
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $new_path = $new_folder . 'customer_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                    
                    if (rename($customer_photo, $new_path)) {
                        // Update database with new path
                        $update_photo = "UPDATE customers SET customer_photo = ? WHERE id = ?";
                        $photo_stmt = mysqli_prepare($conn, $update_photo);
                        mysqli_stmt_bind_param($photo_stmt, 'si', $new_path, $new_customer_id);
                        mysqli_stmt_execute($photo_stmt);
                    }
                }
                
                // Move Aadhaar files if they exist
                if ($aadhar_front && strpos($aadhar_front, '/temp/') !== false) {
                    $new_folder = "uploads/aadhaar/" . $new_customer_id . "/";
                    if (!file_exists($new_folder)) {
                        mkdir($new_folder, 0777, true);
                    }
                    $new_front = $new_folder . 'aadhar_front_' . time() . '.jpg';
                    rename($aadhar_front, $new_front);
                    
                    $update_aadhar = "UPDATE customers SET aadhar_front = ? WHERE id = ?";
                    $aadhar_stmt = mysqli_prepare($conn, $update_aadhar);
                    mysqli_stmt_bind_param($aadhar_stmt, 'si', $new_front, $new_customer_id);
                    mysqli_stmt_execute($aadhar_stmt);
                }
                
                if ($aadhar_back && strpos($aadhar_back, '/temp/') !== false) {
                    $new_folder = "uploads/aadhaar/" . $new_customer_id . "/";
                    if (!file_exists($new_folder)) {
                        mkdir($new_folder, 0777, true);
                    }
                    $new_back = $new_folder . 'aadhar_back_' . time() . '.jpg';
                    rename($aadhar_back, $new_back);
                    
                    $update_aadhar = "UPDATE customers SET aadhar_back = ? WHERE id = ?";
                    $aadhar_stmt = mysqli_prepare($conn, $update_aadhar);
                    mysqli_stmt_bind_param($aadhar_stmt, 'si', $new_back, $new_customer_id);
                    mysqli_stmt_execute($aadhar_stmt);
                }
                
                if ($aadhar_pdf && strpos($aadhar_pdf, '/temp/') !== false) {
                    $new_folder = "uploads/aadhaar_pdfs/" . $new_customer_id . "/";
                    if (!file_exists($new_folder)) {
                        mkdir($new_folder, 0777, true);
                    }
                    $new_pdf = $new_folder . 'aadhaar_' . time() . '.pdf';
                    rename($aadhar_pdf, $new_pdf);
                    
                    $update_pdf = "UPDATE customers SET aadhar_pdf = ? WHERE id = ?";
                    $pdf_stmt = mysqli_prepare($conn, $update_pdf);
                    mysqli_stmt_bind_param($pdf_stmt, 'si', $new_pdf, $new_customer_id);
                    mysqli_stmt_execute($pdf_stmt);
                }
                
                // Log activity
                $log_query = "INSERT INTO activity_log (user_id, action, description, table_name, record_id) 
                              VALUES (?, 'create', ?, 'customers', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $log_description = "New customer created: " . $customer_name;
                mysqli_stmt_bind_param($log_stmt, 'isi', $_SESSION['user_id'], $log_description, $new_customer_id);
                mysqli_stmt_execute($log_stmt);
                
                header('Location: customers.php?success=added');
                exit();
            } else {
                $error = "Error creating customer: " . mysqli_error($conn);
            }
        }
    }
}

// Helper function to handle Aadhaar uploads
function handleAadhaarUpload($file, $type, $customer_id, $is_edit) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
    $max_size = 2 * 1024 * 1024; // 2MB
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'error' => "Only JPG and PNG images are allowed for Aadhaar $type."];
    }
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => "Aadhaar $type image size must be less than 2MB."];
    }
    
    $upload_dir = "uploads/aadhaar/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    if ($is_edit) {
        $customer_folder = $upload_dir . $customer_id . '/';
        if (!file_exists($customer_folder)) {
            mkdir($customer_folder, 0777, true);
        }
        $filename = 'aadhar_' . $type . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $filepath = $customer_folder . $filename;
    } else {
        $temp_folder = $upload_dir . 'temp/';
        if (!file_exists($temp_folder)) {
            mkdir($temp_folder, 0777, true);
        }
        $filename = 'temp_' . $type . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $filepath = $temp_folder . $filename;
    }
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'path' => $filepath];
    } else {
        return ['success' => false, 'error' => "Failed to upload Aadhaar $type image."];
    }
}

// Helper function to handle captured Aadhaar images
function handleCapturedAadhaar($image_data, $type, $customer_id, $is_edit) {
    // Remove data:image/png;base64, part
    if (preg_match('/^data:image\/(\w+);base64,/', $image_data, $matches)) {
        $image_data = substr($image_data, strpos($image_data, ',') + 1);
        $ext = strtolower($matches[1]); // jpg, jpeg, png
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            return ['success' => false, 'error' => "Invalid image format for Aadhaar $type."];
        }
        
        $image_data = base64_decode($image_data);
        if ($image_data === false) {
            return ['success' => false, 'error' => "Failed to decode Aadhaar $type image."];
        }
        
        $upload_dir = "uploads/aadhaar/";
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        if ($is_edit) {
            $customer_folder = $upload_dir . $customer_id . '/';
            if (!file_exists($customer_folder)) {
                mkdir($customer_folder, 0777, true);
            }
            $filename = 'aadhar_' . $type . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $filepath = $customer_folder . $filename;
        } else {
            $temp_folder = $upload_dir . 'temp/';
            if (!file_exists($temp_folder)) {
                mkdir($temp_folder, 0777, true);
            }
            $filename = 'temp_' . $type . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $filepath = $temp_folder . $filename;
        }
        
        if (file_put_contents($filepath, $image_data)) {
            return ['success' => true, 'path' => $filepath];
        } else {
            return ['success' => false, 'error' => "Failed to save captured Aadhaar $type image."];
        }
    }
    
    return ['success' => false, 'error' => "Invalid image data for Aadhaar $type."];
}

// Check for success messages from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $message = "Customer created successfully!";
            break;
        case 'updated':
            $message = "Customer updated successfully!";
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Include jQuery UI for autocomplete -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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

        .customer-container {
            width: 100%;
            max-width: 1400px;
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
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 28px;
            border-radius: 50px;
            font-size: 15px;
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
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.4);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.5);
        }

        .btn-info {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(66, 153, 225, 0.4);
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.5);
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

        .customer-id-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .customer-id-badge span {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 50px;
            font-size: 18px;
        }

        .form-card {
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
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
            font-size: 20px;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100px;
            height: 2px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
            position: relative;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
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
            padding: 12px 20px 12px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f8fafc;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .form-control[readonly] {
            background: #f1f5f9;
            cursor: default;
        }

        .ui-autocomplete {
            max-height: 200px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }

        .ui-menu-item {
            padding: 10px 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .ui-menu-item:last-child {
            border-bottom: none;
        }

        .ui-menu-item .ui-menu-item-wrapper {
            padding: 5px;
        }

        .ui-state-active, .ui-widget-content .ui-state-active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            margin: 0;
        }

        .suggestion-item {
            display: flex;
            flex-direction: column;
        }

        .suggestion-number {
            font-weight: 600;
            color: #2d3748;
        }

        .suggestion-name {
            font-size: 12px;
            color: #718096;
        }

        .ui-state-active .suggestion-number,
        .ui-state-active .suggestion-name {
            color: white;
        }

        /* Photo Section - Compact */
        .photo-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 10px;
        }

        .photo-upload-area {
            border: 2px dashed #667eea;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            cursor: pointer;
            transition: all 0.3s;
            min-height: 130px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .photo-upload-area:hover {
            border-color: #48bb78;
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(72, 187, 120, 0.2);
        }

        .photo-upload-area i {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 5px;
        }

        .photo-upload-area:hover i {
            color: #48bb78;
        }

        .photo-upload-area p {
            margin: 3px 0;
            color: #4a5568;
            font-weight: 500;
            font-size: 13px;
        }

        .photo-upload-area small {
            color: #718096;
            font-size: 10px;
            display: block;
        }

        .camera-section {
            border: 2px dashed #48bb78;
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            background: linear-gradient(135deg, #48bb7810 0%, #38a16910 100%);
            min-height: 130px;
            display: flex;
            flex-direction: column;
        }

        .camera-section .form-label {
            margin-top: 0;
            font-size: 13px;
        }

        .camera-preview {
            width: 100%;
            max-width: 200px;
            height: 100px;
            margin: 0 auto 5px;
            border-radius: 6px;
            overflow: hidden;
            background: #000;
            display: none;
        }

        .camera-preview video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .camera-controls {
            display: flex;
            gap: 5px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 5px;
        }

        .camera-btn {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.3s;
        }

        .camera-btn-start {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
        }

        .camera-btn-capture {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
        }

        .camera-btn-stop {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
        }

        .camera-btn-switch {
            background: linear-gradient(135deg, #9f7aea 0%, #805ad5 100%);
            color: white;
        }

        .camera-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .photo-preview {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            margin: 8px auto 0;
            border: 2px solid #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            object-fit: cover;
            display: none;
        }

        .photo-preview.show {
            display: block;
        }

        /* Aadhaar Upload Section - Compact */
        .aadhaar-upload-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .aadhaar-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
            border: 1px solid #e2e8f0;
        }

        .aadhaar-card .form-label {
            font-size: 13px;
            margin-bottom: 8px;
            color: #4a5568;
        }

        .aadhaar-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .aadhaar-upload-box, .aadhaar-camera-box {
            border: 2px dashed #9f7aea;
            border-radius: 8px;
            padding: 8px 5px;
            text-align: center;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            min-height: 65px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .aadhaar-camera-box {
            border-color: #48bb78;
        }

        .aadhaar-upload-box:hover, .aadhaar-camera-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .aadhaar-upload-box i, .aadhaar-camera-box i {
            font-size: 22px;
            margin-bottom: 3px;
        }

        .aadhaar-upload-box i {
            color: #9f7aea;
        }

        .aadhaar-camera-box i {
            color: #48bb78;
        }

        .aadhaar-upload-box p, .aadhaar-camera-box p {
            margin: 0;
            font-size: 11px;
            font-weight: 500;
        }

        .aadhaar-upload-box small, .aadhaar-camera-box small {
            font-size: 9px;
            color: #718096;
        }

        .aadhaar-preview {
            width: 100%;
            max-height: 50px;
            border-radius: 6px;
            margin-top: 8px;
            border: 2px solid #9f7aea;
            object-fit: cover;
            display: none;
        }

        .aadhaar-preview.show {
            display: block;
        }

        /* Map Styles */
        .map-container {
            height: 250px;
            margin: 15px 0 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f0f4ff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .map-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .search-container input {
            flex: 1;
        }

        /* Search Results */
        #search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            position: absolute;
            z-index: 1000;
            width: calc(100% - 120px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: none;
        }

        #search-results div {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
        }

        #search-results div:hover {
            background: #f0f4ff;
        }

        #search-results div:last-child {
            border-bottom: none;
        }

        /* Selected Address Display */
        .selected-address-display {
            margin-top: 10px;
            margin-bottom: 15px;
            padding: 12px;
            background: #f0f4ff;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            display: none;
        }

        .selected-address-display i {
            color: #667eea;
            margin-right: 5px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            padding: 8px 12px;
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 8px;
            border: 1px solid #667eea30;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-group label {
            font-weight: 500;
            color: #4a5568;
            cursor: pointer;
            font-size: 14px;
        }

        .remarks-field {
            margin-top: 10px;
            display: none;
        }

        .remarks-field.show {
            display: block;
        }

        .remarks-field textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
            background: #f8fafc;
        }

        .remarks-field textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            background: white;
        }

        .bank-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            background: linear-gradient(135deg, #667eea05 0%, #764ba205 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .info-card {
            background: linear-gradient(135deg, #667eea10 0%, #764ba210 100%);
            border-radius: 12px;
            padding: 15px;
            border: 2px solid #667eea30;
            height: 100%;
            display: flex;
            align-items: center;
        }

        .info-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 22px;
        }

        .info-text {
            flex: 1;
        }

        .info-text strong {
            color: #2d3748;
            font-size: 15px;
            display: block;
            margin-bottom: 3px;
        }

        .info-text small {
            color: #718096;
            font-size: 12px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        .account-match {
            font-size: 12px;
            margin-top: 5px;
        }

        .account-match.valid {
            color: #48bb78;
        }

        .account-match.invalid {
            color: #f56565;
        }

        .account-number-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #667eea;
            z-index: 2;
        }

        .pdf-download-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            color: white;
            border-radius: 30px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
        }

        .pdf-download-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 101, 101, 0.4);
        }

        .ifsc-fetching {
            font-size: 11px;
            color: #667eea;
            margin-top: 3px;
            display: none;
        }

        .ifsc-fetching.show {
            display: block;
        }

        .ifsc-success {
            font-size: 11px;
            color: #48bb78;
            margin-top: 3px;
            display: none;
        }

        .ifsc-success.show {
            display: block;
        }

        .ifsc-error {
            font-size: 11px;
            color: #f56565;
            margin-top: 3px;
            display: none;
        }

        .ifsc-error.show {
            display: block;
        }

        .spinner {
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Aadhaar Camera Modal */
        #aadhaarCameraModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            padding: 15px;
        }

        .modal-content {
            max-width: 450px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 15px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .modal-header h4 {
            margin: 0;
            font-size: 16px;
            color: #2d3748;
        }

        .modal-header button {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #718096;
        }

        .camera-container {
            width: 100%;
            height: 280px;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 10px;
        }

        .camera-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .modal-controls {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .camera-status {
            font-size: 11px;
            color: #666;
            margin-top: 8px;
            text-align: center;
        }

        @media (max-width: 1200px) {
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
            
            .header-actions {
                justify-content: center;
            }
            
            .customer-id-badge {
                width: 100%;
                justify-content: center;
            }
            
            .form-grid, .form-grid-2, .bank-details-grid, .aadhaar-upload-section {
                grid-template-columns: 1fr;
            }
            
            .photo-section {
                grid-template-columns: 1fr;
            }
            
            .search-container {
                flex-direction: column;
            }
            
            #search-results {
                width: 100%;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }
            
            .info-content {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .page-content {
                padding: 20px;
            }
            
            .customer-container {
                padding: 0 10px;
            }
            
            .form-card {
                padding: 20px;
            }
            
            .photo-upload-area {
                padding: 10px;
                min-height: 120px;
            }
            
            .photo-upload-area i {
                font-size: 24px;
            }
            
            .camera-section {
                padding: 8px;
                min-height: 120px;
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
            <div class="customer-container">
                <!-- Page Header -->
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="bi bi-person-plus" style="margin-right: 10px;"></i>
                        <?php echo $is_edit ? 'Edit Customer' : 'New Customer'; ?>
                    </h1>
                    <div class="header-actions">
                        <?php if ($is_edit): ?>
                        <div class="customer-id-badge">
                            <i class="bi bi-qr-code"></i>
                            Customer ID <span>#<?php echo str_pad($edit_id, 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <?php endif; ?>
                        <a href="Customer-Details.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Back to List
                        </a>
                    </div>
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

                <!-- Customer Form -->
                <form method="POST" action="" enctype="multipart/form-data" id="customerForm">
                    <?php if ($is_edit): ?>
                        <input type="hidden" name="customer_id" value="<?php echo $edit_id; ?>">
                    <?php endif; ?>
                    
                    <!-- Photo Section with Camera -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-camera"></i>
                            Customer Photo
                        </div>

                        <div class="photo-section">
                            <!-- Upload Section -->
                            <div class="form-group">
                                <label class="form-label">Upload Photo</label>
                                <div class="photo-upload-area" onclick="document.getElementById('customer_photo').click();">
                                    <i class="bi bi-cloud-upload"></i>
                                    <p>Click to upload</p>
                                    <small>JPG, PNG (2MB)</small>
                                    <input type="file" id="customer_photo" name="customer_photo" accept="image/*" style="display: none;" onchange="previewPhoto(this)">
                                </div>
                                <img class="photo-preview" id="photoPreview" src="#" alt="Preview">
                            </div>

                            <!-- Camera Capture Section -->
                            <div class="camera-section">
                                <label class="form-label">Take Photo</label>
                                
                                <!-- Camera Preview -->
                                <div class="camera-preview" id="cameraPreview">
                                    <video id="video" autoplay playsinline></video>
                                    <canvas id="canvas" style="display: none;"></canvas>
                                </div>
                                
                                <!-- Camera Controls -->
                                <div class="camera-controls">
                                    <button type="button" class="camera-btn camera-btn-start" id="startCameraBtn">
                                        <i class="bi bi-camera-video"></i> Start
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-switch" id="switchCameraBtn" disabled>
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-capture" id="capturePhotoBtn" disabled>
                                        <i class="bi bi-camera"></i>
                                    </button>
                                    <button type="button" class="camera-btn camera-btn-stop" id="stopCameraBtn" disabled>
                                        <i class="bi bi-stop-circle"></i>
                                    </button>
                                </div>
                                
                                <!-- Camera Status -->
                                <div id="cameraStatus" style="font-size: 10px; color: #718096; margin-top: 5px; text-align: center;">
                                    Camera off
                                </div>
                                
                                <input type="hidden" name="captured_photo" id="capturedPhoto">
                            </div>
                        </div>

                        <!-- Current Photo Display (for edit mode) -->
                        <?php if ($is_edit && !empty($customer_data['customer_photo'])): ?>
                        <div style="text-align: center; margin-top: 10px;">
                            <p style="color: #718096; margin-bottom: 5px; font-size: 12px;">Current:</p>
                            <img src="<?php echo htmlspecialchars($customer_data['customer_photo']); ?>" 
                                 style="width: 60px; height: 60px; border-radius: 6px; object-fit: cover; border: 2px solid #667eea;">
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Personal Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-person"></i>
                            Personal Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label required">Customer Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person-badge input-icon"></i>
                                    <input type="text" class="form-control" name="customer_name" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['customer_name'] ?? '') : ''; ?>" 
                                           placeholder="Enter full name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Nominee Type</label>
                                <div class="input-group">
                                    <i class="bi bi-people input-icon"></i>
                                    <select class="form-select" name="guardian_type">
                                        <option value="">Select Type</option>
                                        <option value="Father" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Father') ? 'selected' : ''; ?>>Father</option>
                                        <option value="Mother" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Mother') ? 'selected' : ''; ?>>Mother</option>
                                        <option value="Husband" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Husband') ? 'selected' : ''; ?>>Husband</option>
                                        <option value="Wife" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Wife') ? 'selected' : ''; ?>>Wife</option>
                                        <option value="Son" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Son') ? 'selected' : ''; ?>>Son</option>
                                        <option value="Daughter" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Daughter') ? 'selected' : ''; ?>>Daughter</option>
                                        <option value="Brother" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Brother') ? 'selected' : ''; ?>>Brother</option>
                                        <option value="Sister" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Sister') ? 'selected' : ''; ?>>Sister</option>
                                        <option value="Other" <?php echo ($is_edit && ($customer_data['guardian_type'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Nominee Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" class="form-control" name="guardian_name" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['guardian_name'] ?? '') : ''; ?>" 
                                           placeholder="Enter nominee name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Nominee Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" class="form-control" name="guardian_mobile" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['guardian_mobile'] ?? '') : ''; ?>" 
                                           placeholder="Enter nominee mobile" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Mobile Number</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" class="form-control" name="mobile_number" id="mobile_number"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['mobile_number'] ?? '') : ''; ?>" 
                                           placeholder="Enter 10-digit mobile number" maxlength="10" required
                                           autocomplete="off">
                                </div>
                                <small style="color: #718096; margin-top: 5px; display: block; font-size: 11px;">
                                    <i class="bi bi-info-circle"></i> Start typing for suggestions
                                </small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alternate Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone-flip input-icon"></i>
                                    <input type="tel" class="form-control" name="alternate_mobile" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['alternate_mobile'] ?? '') : ''; ?>" 
                                           placeholder="Enter alternate number" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="checkbox-group" style="margin-bottom: 5px;">
                                    <input type="checkbox" id="whatsapp_same" name="whatsapp_same" 
                                           <?php echo ($is_edit && ($customer_data['whatsapp_number'] ?? '') == ($customer_data['mobile_number'] ?? '')) ? 'checked' : ''; ?>
                                           onchange="toggleWhatsAppField()">
                                    <label for="whatsapp_same">Same as mobile</label>
                                </div>
                                <label class="form-label">WhatsApp Number</label>
                                <div class="input-group">
                                    <i class="bi bi-whatsapp input-icon"></i>
                                    <input type="tel" class="form-control" name="whatsapp_number" id="whatsapp_number"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['whatsapp_number'] ?? '') : ''; ?>" 
                                           placeholder="Enter WhatsApp number" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['email'] ?? '') : ''; ?>" 
                                           placeholder="Enter email address">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address Information with Map -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-geo-alt"></i>
                            Address Information with Map
                        </div>

                        <div class="form-group">
                            <label class="form-label">Search Address</label>
                            <div class="search-container">
                                <input type="text" id="address-search" class="form-control" placeholder="Type your address (e.g., Gollapatti, Dharmapuri)...">
                                <button type="button" class="btn btn-primary" onclick="searchAddress()">
                                    <i class="bi bi-search"></i> Search
                                </button>
                            </div>
                            <div id="search-results"></div>
                            <div id="map" class="map-container">
                                <div style="text-align: center; color: #667eea;">
                                    <i class="bi bi-map" style="font-size: 48px;"></i>
                                    <p>Search for an address to see map</p>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Address Display -->
                        <div id="selected-address-display" class="selected-address-display">
                            <i class="bi bi-geo-alt-fill"></i>
                            <span id="selected-address-text"></span>
                        </div>

                        <input type="hidden" name="latitude" id="latitude" value="<?php echo $is_edit ? htmlspecialchars($customer_data['latitude'] ?? '') : ''; ?>">
                        <input type="hidden" name="longitude" id="longitude" value="<?php echo $is_edit ? htmlspecialchars($customer_data['longitude'] ?? '') : ''; ?>">
                        <input type="hidden" name="place_id" id="place_id" value="<?php echo $is_edit ? htmlspecialchars($customer_data['place_id'] ?? '') : ''; ?>">
                        <input type="hidden" name="formatted_address" id="formatted_address" value="<?php echo $is_edit ? htmlspecialchars($customer_data['formatted_address'] ?? '') : ''; ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Door No</label>
                                <div class="input-group">
                                    <i class="bi bi-hash input-icon"></i>
                                    <input type="text" class="form-control" name="door_no" id="door_no"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['door_no'] ?? '') : ''; ?>" 
                                           placeholder="Enter door number">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">House Name</label>
                                <div class="input-group">
                                    <i class="bi bi-house input-icon"></i>
                                    <input type="text" class="form-control" name="house_name" id="house_name"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['house_name'] ?? '') : ''; ?>" 
                                           placeholder="Enter house name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Street Name</label>
                                <div class="input-group">
                                    <i class="bi bi-signpost input-icon"></i>
                                    <input type="text" class="form-control" name="street_name" id="street_name"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['street_name'] ?? '') : ''; ?>" 
                                           placeholder="Enter street name" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Street Name 2</label>
                                <div class="input-group">
                                    <i class="bi bi-signpost-2 input-icon"></i>
                                    <input type="text" class="form-control" name="street_name1" id="street_name1"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['street_name1'] ?? '') : ''; ?>" 
                                           placeholder="Enter additional street info">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Landmark</label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <input type="text" class="form-control" name="landmark" id="landmark"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['landmark'] ?? '') : ''; ?>" 
                                           placeholder="Enter landmark">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Location</label>
                                <div class="input-group">
                                    <i class="bi bi-pin-map input-icon"></i>
                                    <input type="text" class="form-control" name="location" id="location"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['location'] ?? '') : ''; ?>" 
                                           placeholder="Enter location" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Pincode</label>
                                <div class="input-group">
                                    <i class="bi bi-mailbox input-icon"></i>
                                    <input type="text" class="form-control" name="pincode" id="pincode"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['pincode'] ?? '') : ''; ?>" 
                                           placeholder="Enter pincode" maxlength="6" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Post</label>
                                <div class="input-group">
                                    <i class="bi bi-envelope-paper input-icon"></i>
                                    <input type="text" class="form-control" name="post" id="post"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['post'] ?? '') : ''; ?>" 
                                           placeholder="Enter post office" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Taluk</label>
                                <div class="input-group">
                                    <i class="bi bi-diagram-3 input-icon"></i>
                                    <input type="text" class="form-control" name="taluk" id="taluk"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['taluk'] ?? '') : ''; ?>" 
                                           placeholder="Enter taluk" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">District</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" class="form-control" name="district" id="district"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['district'] ?? '') : ''; ?>" 
                                           placeholder="Enter district" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KYC Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-shield-check"></i>
                            KYC Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group full-width">
                                <label class="form-label">Aadhaar Number</label>
                                <div class="input-group">
                                    <i class="bi bi-person-vcard input-icon"></i>
                                    <input type="text" class="form-control" name="aadhaar_number" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['aadhaar_number'] ?? '') : ''; ?>" 
                                           placeholder="Enter 12-digit Aadhaar number" maxlength="12">
                                </div>
                            </div>
                        </div>

                        <!-- Aadhaar Photo Upload Section with Camera Option -->
                        <div class="section-title" style="margin-top: 20px; font-size: 16px;">
                            <i class="bi bi-card-image"></i>
                            Aadhaar Card Upload
                        </div>

                        <div class="aadhaar-upload-section">
                            <!-- Front Side -->
                            <div class="aadhaar-card">
                                <label class="form-label">Front Side</label>
                                <div class="aadhaar-options">
                                    <!-- Upload Option -->
                                    <div class="aadhaar-upload-box" onclick="document.getElementById('aadhar_front').click();">
                                        <i class="bi bi-cloud-upload"></i>
                                        <p>Upload</p>
                                        <small>JPG/PNG</small>
                                        <input type="file" id="aadhar_front" name="aadhar_front" accept="image/*" style="display: none;" onchange="previewAadhaar(this, 'frontPreview')">
                                    </div>
                                    
                                    <!-- Camera Option -->
                                    <div class="aadhaar-camera-box" onclick="openAadhaarCamera('front')">
                                        <i class="bi bi-camera"></i>
                                        <p>Camera</p>
                                        <small>Take photo</small>
                                    </div>
                                </div>
                                <img class="aadhaar-preview" id="frontPreview" src="#" alt="Front Preview">
                                
                                <?php if ($is_edit && !empty($customer_data['aadhar_front'])): ?>
                                <div style="margin-top: 5px; text-align: center;">
                                    <small><a href="<?php echo htmlspecialchars($customer_data['aadhar_front']); ?>" target="_blank" style="color: #667eea;">View Current</a></small>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Back Side -->
                            <div class="aadhaar-card">
                                <label class="form-label">Back Side</label>
                                <div class="aadhaar-options">
                                    <!-- Upload Option -->
                                    <div class="aadhaar-upload-box" onclick="document.getElementById('aadhar_back').click();">
                                        <i class="bi bi-cloud-upload"></i>
                                        <p>Upload</p>
                                        <small>JPG/PNG</small>
                                        <input type="file" id="aadhar_back" name="aadhar_back" accept="image/*" style="display: none;" onchange="previewAadhaar(this, 'backPreview')">
                                    </div>
                                    
                                    <!-- Camera Option -->
                                    <div class="aadhaar-camera-box" onclick="openAadhaarCamera('back')">
                                        <i class="bi bi-camera"></i>
                                        <p>Camera</p>
                                        <small>Take photo</small>
                                    </div>
                                </div>
                                <img class="aadhaar-preview" id="backPreview" src="#" alt="Back Preview">
                                
                                <?php if ($is_edit && !empty($customer_data['aadhar_back'])): ?>
                                <div style="margin-top: 5px; text-align: center;">
                                    <small><a href="<?php echo htmlspecialchars($customer_data['aadhar_back']); ?>" target="_blank" style="color: #667eea;">View Current</a></small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Hidden fields for captured Aadhaar images -->
                        <input type="hidden" name="captured_aadhar_front" id="capturedAadharFront">
                        <input type="hidden" name="captured_aadhar_back" id="capturedAadharBack">

                        <!-- PDF Download Link (for existing customers) -->
                        <?php if ($is_edit && !empty($customer_data['aadhar_pdf'])): ?>
                        <div style="text-align: center; margin-top: 10px;">
                            <a href="<?php echo htmlspecialchars($customer_data['aadhar_pdf']); ?>" class="pdf-download-link" download>
                                <i class="bi bi-file-pdf"></i> Download PDF
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Bank Details with IFSC Auto-fill -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-bank"></i>
                            Bank Details (Optional)
                        </div>

                        <div class="bank-details-grid">
                            <div class="form-group">
                                <label class="form-label">Account Holder Name</label>
                                <div class="input-group">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" class="form-control" name="account_holder_name" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['account_holder_name'] ?? '') : ''; ?>" 
                                           placeholder="As per bank records">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">IFSC Code</label>
                                <div class="input-group">
                                    <i class="bi bi-upc-scan input-icon"></i>
                                    <input type="text" class="form-control" name="ifsc_code" id="ifsc_code"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['ifsc_code'] ?? '') : ''; ?>" 
                                           placeholder="Enter IFSC code" maxlength="11" oninput="fetchBankDetails()" style="text-transform: uppercase;">
                                </div>
                                <div class="ifsc-fetching" id="ifscFetching">
                                    <i class="bi bi-arrow-repeat spinner"></i> Fetching bank details...
                                </div>
                                <div class="ifsc-success" id="ifscSuccess">
                                    <i class="bi bi-check-circle"></i> Bank details fetched successfully
                                </div>
                                <div class="ifsc-error" id="ifscError">
                                    <i class="bi bi-exclamation-triangle"></i> Invalid IFSC code
                                </div>
                                <small style="color: #718096; font-size: 11px;">Enter 11-character IFSC to auto-fill</small>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bank Name</label>
                                <div class="input-group">
                                    <i class="bi bi-bank input-icon"></i>
                                    <input type="text" class="form-control" name="bank_name" id="bank_name"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['bank_name'] ?? '') : ''; ?>" 
                                           placeholder="Auto-filled from IFSC" readonly>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Branch Name</label>
                                <div class="input-group">
                                    <i class="bi bi-diagram-3 input-icon"></i>
                                    <input type="text" class="form-control" name="branch_name" id="branch_name"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['branch_name'] ?? '') : ''; ?>" 
                                           placeholder="Auto-filled from IFSC" readonly>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Bank Address</label>
                                <div class="input-group">
                                    <i class="bi bi-geo-alt input-icon"></i>
                                    <input type="text" class="form-control" name="bank_address" id="bank_address"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['bank_address'] ?? '') : ''; ?>" 
                                           placeholder="Auto-filled from IFSC" readonly>
                                </div>
                            </div>

                            <div class="form-group account-number-container">
                                <label class="form-label">Account Number</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card input-icon"></i>
                                    <input type="password" class="form-control" name="account_number" id="account_number"
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['account_number'] ?? '') : ''; ?>" 
                                           placeholder="Enter account number">
                                    <i class="bi bi-eye toggle-password" onclick="toggleAccountNumber('account_number', this)"></i>
                                </div>
                            </div>

                            <div class="form-group account-number-container">
                                <label class="form-label">Confirm Account Number</label>
                                <div class="input-group">
                                    <i class="bi bi-credit-card-2-back input-icon"></i>
                                    <input type="password" class="form-control" name="confirm_account_number" id="confirm_account_number"
                                           placeholder="Re-enter account number">
                                    <i class="bi bi-eye toggle-password" onclick="toggleAccountNumber('confirm_account_number', this)"></i>
                                </div>
                                <div class="account-match" id="accountMatch"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Account Type</label>
                                <div class="input-group">
                                    <i class="bi bi-tag input-icon"></i>
                                    <select class="form-select" name="account_type">
                                        <option value="savings" <?php echo ($is_edit && ($customer_data['account_type'] ?? '') == 'savings') ? 'selected' : ''; ?>>Savings Account</option>
                                        <option value="current" <?php echo ($is_edit && ($customer_data['account_type'] ?? '') == 'current') ? 'selected' : ''; ?>>Current Account</option>
                                        <option value="salary" <?php echo ($is_edit && ($customer_data['account_type'] ?? '') == 'salary') ? 'selected' : ''; ?>>Salary Account</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">UPI ID</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="text" class="form-control" name="upi_id" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['upi_id'] ?? '') : ''; ?>" 
                                           placeholder="Enter UPI ID (optional)">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="form-card">
                        <div class="section-title">
                            <i class="bi bi-info-circle"></i>
                            Additional Information
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Company Name</label>
                                <div class="input-group">
                                    <i class="bi bi-building input-icon"></i>
                                    <input type="text" class="form-control" name="company_name" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['company_name'] ?? '') : ''; ?>" 
                                           placeholder="Enter company name (if applicable)">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Referral Person</label>
                                <div class="input-group">
                                    <i class="bi bi-person-plus input-icon"></i>
                                    <input type="text" class="form-control" name="referral_person" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['referral_person'] ?? '') : ''; ?>" 
                                           placeholder="Enter referral name">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Referral Mobile</label>
                                <div class="input-group">
                                    <i class="bi bi-phone input-icon"></i>
                                    <input type="tel" class="form-control" name="referral_mobile" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['referral_mobile'] ?? '') : ''; ?>" 
                                           placeholder="Enter referral mobile" maxlength="10">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alert Message</label>
                                <div class="input-group">
                                    <i class="bi bi-exclamation-triangle input-icon"></i>
                                    <input type="text" class="form-control" name="alert_message" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['alert_message'] ?? '') : ''; ?>" 
                                           placeholder="Enter any alert message">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Loan Limit (₹)</label>
                                <div class="input-group">
                                    <i class="bi bi-cash-stack input-icon"></i>
                                    <input type="number" class="form-control" name="loan_limit_amount" 
                                           value="<?php echo $is_edit ? htmlspecialchars($customer_data['loan_limit_amount'] ?? '10000000') : '10000000'; ?>" 
                                           placeholder="Enter loan limit" step="1000" min="0">
                                </div>
                            </div>
                        </div>

                        <!-- Noted Person Checkbox -->
                        <div class="checkbox-group">
                            <input type="checkbox" id="is_noted_person" name="is_noted_person" 
                                   <?php echo ($is_edit && !empty($customer_data['is_noted_person']) && $customer_data['is_noted_person'] == 1) ? 'checked' : ''; ?> 
                                   onchange="toggleNotedPersonRemarks()">
                            <label for="is_noted_person">Mark as Noted Person</label>
                        </div>

                        <!-- Noted Person Remarks -->
                        <div class="remarks-field <?php echo ($is_edit && !empty($customer_data['is_noted_person']) && $customer_data['is_noted_person'] == 1) ? 'show' : ''; ?>" id="notedPersonRemarks">
                            <label class="form-label">Remarks</label>
                            <textarea name="noted_person_remarks" placeholder="Enter remarks for noted person..."><?php echo $is_edit ? htmlspecialchars($customer_data['noted_person_remarks'] ?? '') : ''; ?></textarea>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='Customer-Details.php'">
                                <i class="bi bi-x-circle"></i>
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i>
                                <?php echo $is_edit ? 'Update Customer' : 'Create Customer'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Include footer -->
        <?php include 'includes/footer.php'; ?>
    </div>
</div>

<!-- Aadhaar Camera Modal -->
<div id="aadhaarCameraModal">
    <div class="modal-content">
        <div class="modal-header">
            <h4 id="aadhaarCameraTitle">Capture Aadhaar Front</h4>
            <button onclick="closeAadhaarCamera()">&times;</button>
        </div>
        
        <!-- Camera Preview -->
        <div class="camera-container">
            <video id="aadhaarVideo" autoplay playsinline></video>
            <canvas id="aadhaarCanvas" style="display: none;"></canvas>
        </div>
        
        <!-- Camera Controls -->
        <div class="modal-controls">
            <button class="camera-btn camera-btn-switch" onclick="switchAadhaarCamera()">
                <i class="bi bi-arrow-repeat"></i> Switch
            </button>
            <button class="camera-btn camera-btn-capture" onclick="captureAadhaarPhoto()">
                <i class="bi bi-camera"></i> Capture
            </button>
            <button class="camera-btn camera-btn-stop" onclick="closeAadhaarCamera()">
                <i class="bi bi-x-circle"></i> Close
            </button>
        </div>
        
        <div class="camera-status" id="aadhaarCameraStatus">
            Camera ready
        </div>
    </div>
</div>

<script>
    // Mobile number autocomplete
    $(function() {
        var mobileSuggestions = [
            <?php foreach ($mobile_suggestions as $suggestion): ?>
            {
                label: '<?php echo $suggestion['mobile_number']; ?> - <?php echo addslashes($suggestion['customer_name']); ?>',
                value: '<?php echo $suggestion['mobile_number']; ?>',
                name: '<?php echo addslashes($suggestion['customer_name']); ?>'
            },
            <?php endforeach; ?>
        ];

        $("#mobile_number").autocomplete({
            source: mobileSuggestions,
            minLength: 1,
            select: function(event, ui) {
                if (confirm('Do you want to use this mobile number?')) {
                    $("#mobile_number").val(ui.item.value);
                }
                return false;
            }
        }).autocomplete("instance")._renderItem = function(ul, item) {
            return $("<li>")
                .append("<div class='suggestion-item'><span class='suggestion-number'>" + item.value + "</span><span class='suggestion-name'>" + item.name + "</span></div>")
                .appendTo(ul);
        };
    });

    // Customer Camera functionality
    let videoStream = null;
    let currentFacingMode = 'user';
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const cameraPreview = document.getElementById('cameraPreview');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const switchCameraBtn = document.getElementById('switchCameraBtn');
    const captureBtn = document.getElementById('capturePhotoBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const capturedPhotoInput = document.getElementById('capturedPhoto');
    const photoPreview = document.getElementById('photoPreview');
    const cameraStatus = document.getElementById('cameraStatus');

    async function startCamera(facingMode = currentFacingMode) {
        try {
            cameraStatus.textContent = 'Requesting camera...';
            const constraints = {
                video: { facingMode: facingMode },
                audio: false
            };
            videoStream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = videoStream;
            cameraPreview.style.display = 'block';
            startCameraBtn.disabled = true;
            switchCameraBtn.disabled = false;
            captureBtn.disabled = false;
            stopBtn.disabled = false;
            cameraStatus.textContent = 'Camera active';
        } catch (err) {
            console.error('Camera error:', err);
            alert('Error accessing camera: ' + err.message);
            cameraStatus.textContent = 'Camera error';
            startCameraBtn.disabled = false;
        }
    }

    if (startCameraBtn) {
        startCameraBtn.addEventListener('click', function() {
            currentFacingMode = 'user';
            startCamera(currentFacingMode);
        });
    }

    if (switchCameraBtn) {
        switchCameraBtn.addEventListener('click', function() {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';
                startCamera(currentFacingMode);
            }
        });
    }

    if (captureBtn) {
        captureBtn.addEventListener('click', function() {
            if (!videoStream || video.videoWidth === 0) {
                alert('Camera is not ready');
                return;
            }
            try {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                const imageData = canvas.toDataURL('image/jpeg', 0.8);
                capturedPhotoInput.value = imageData;
                photoPreview.src = imageData;
                photoPreview.classList.add('show');
                cameraStatus.textContent = 'Photo captured';
            } catch (err) {
                console.error('Capture error:', err);
                alert('Failed to capture photo');
            }
        });
    }

    if (stopBtn) {
        stopBtn.addEventListener('click', function() {
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                video.srcObject = null;
                cameraPreview.style.display = 'none';
                startCameraBtn.disabled = false;
                switchCameraBtn.disabled = true;
                captureBtn.disabled = true;
                stopBtn.disabled = true;
                cameraStatus.textContent = 'Camera off';
                videoStream = null;
            }
        });
    }

    function previewPhoto(input) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                input.value = '';
                return;
            }
            if (!input.files[0].type.match('image.*')) {
                alert('Please select an image file');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                photoPreview.classList.add('show');
                capturedPhotoInput.value = '';
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function previewAadhaar(input, previewId) {
        if (input.files && input.files[0]) {
            if (input.files[0].size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
                input.value = '';
                return;
            }
            if (!input.files[0].type.match('image.*')) {
                alert('Please select an image file');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById(previewId);
                preview.src = e.target.result;
                preview.classList.add('show');
                
                // Clear captured data if any
                if (previewId === 'frontPreview') {
                    document.getElementById('capturedAadharFront').value = '';
                } else {
                    document.getElementById('capturedAadharBack').value = '';
                }
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function toggleNotedPersonRemarks() {
        const checkbox = document.getElementById('is_noted_person');
        const remarks = document.getElementById('notedPersonRemarks');
        if (checkbox.checked) {
            remarks.classList.add('show');
        } else {
            remarks.classList.remove('show');
        }
    }

    function toggleWhatsAppField() {
        const checkbox = document.getElementById('whatsapp_same');
        const whatsappField = document.getElementById('whatsapp_number');
        const mobileField = document.getElementById('mobile_number');
        
        if (checkbox.checked) {
            whatsappField.value = mobileField.value;
            whatsappField.readOnly = true;
        } else {
            whatsappField.readOnly = false;
        }
    }

    // Initialize WhatsApp field on page load
    document.addEventListener('DOMContentLoaded', function() {
        toggleWhatsAppField();
        
        // If editing and has formatted address, show it
        <?php if ($is_edit && !empty($customer_data['formatted_address'])): ?>
        document.getElementById('selected-address-display').style.display = 'block';
        document.getElementById('selected-address-text').textContent = '<?php echo addslashes($customer_data['formatted_address']); ?>';
        <?php endif; ?>
    });

    // Update WhatsApp when mobile changes if checkbox is checked
    document.getElementById('mobile_number').addEventListener('input', function() {
        const checkbox = document.getElementById('whatsapp_same');
        if (checkbox && checkbox.checked) {
            document.getElementById('whatsapp_number').value = this.value;
        }
    });

    function toggleAccountNumber(fieldId, icon) {
        const field = document.getElementById(fieldId);
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // IFSC Code Auto-fill Function
    function fetchBankDetails() {
        const ifsc = document.getElementById('ifsc_code').value.trim().toUpperCase();
        const fetchingDiv = document.getElementById('ifscFetching');
        const successDiv = document.getElementById('ifscSuccess');
        const errorDiv = document.getElementById('ifscError');
        
        // Hide all status divs initially
        if (fetchingDiv) fetchingDiv.classList.remove('show');
        if (successDiv) successDiv.classList.remove('show');
        if (errorDiv) errorDiv.classList.remove('show');
        
        // Clear fields if IFSC is empty
        if (ifsc.length === 0) {
            document.getElementById('bank_name').value = '';
            document.getElementById('branch_name').value = '';
            document.getElementById('bank_address').value = '';
            return;
        }
        
        // Check if IFSC is 11 characters (standard length)
        if (ifsc.length === 11) {
            // Show fetching indicator
            if (fetchingDiv) fetchingDiv.classList.add('show');
            
            // Using Razorpay IFSC API (free, no API key required)
            fetch(`https://ifsc.razorpay.com/${ifsc}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('IFSC not found');
                    }
                    return response.json();
                })
                .then(data => {
                    // Hide fetching indicator
                    if (fetchingDiv) fetchingDiv.classList.remove('show');
                    
                    if (data.BANK) {
                        document.getElementById('bank_name').value = data.BANK || '';
                        document.getElementById('branch_name').value = data.BRANCH || '';
                        
                        // Construct full address
                        let address = data.ADDRESS || '';
                        if (data.CITY) address += (address ? ', ' : '') + data.CITY;
                        if (data.DISTRICT) address += (address ? ', ' : '') + data.DISTRICT;
                        if (data.STATE) address += (address ? ', ' : '') + data.STATE;
                        document.getElementById('bank_address').value = address;
                        
                        // Show success message
                        if (successDiv) {
                            successDiv.classList.add('show');
                            setTimeout(() => {
                                successDiv.classList.remove('show');
                            }, 3000);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching bank details:', error);
                    if (fetchingDiv) fetchingDiv.classList.remove('show');
                    
                    // Show error message
                    if (errorDiv) {
                        errorDiv.classList.add('show');
                        setTimeout(() => {
                            errorDiv.classList.remove('show');
                        }, 3000);
                    }
                    
                    // Clear fields
                    document.getElementById('bank_name').value = '';
                    document.getElementById('branch_name').value = '';
                    document.getElementById('bank_address').value = '';
                });
        } else if (ifsc.length > 0) {
            // IFSC is not 11 characters, clear fields but don't show error until 11 chars
            document.getElementById('bank_name').value = '';
            document.getElementById('branch_name').value = '';
            document.getElementById('bank_address').value = '';
        }
    }

    // Account number match validation
    const accountNumber = document.getElementById('account_number');
    const confirmAccount = document.getElementById('confirm_account_number');
    const accountMatch = document.getElementById('accountMatch');
    
    if (confirmAccount) {
        confirmAccount.addEventListener('input', function() {
            const accountNum = accountNumber ? accountNumber.value : '';
            const confirm = this.value;
            if (confirm.length === 0) {
                if (accountMatch) {
                    accountMatch.textContent = '';
                    accountMatch.className = 'account-match';
                }
                return;
            }
            if (accountMatch) {
                if (accountNum === confirm) {
                    accountMatch.textContent = '✓ Numbers match';
                    accountMatch.className = 'account-match valid';
                } else {
                    accountMatch.textContent = '✗ Numbers do not match';
                    accountMatch.className = 'account-match invalid';
                }
            }
        });
    }

    // Aadhaar Camera functionality
    let aadhaarVideoStream = null;
    let currentAadhaarSide = 'front';
    let aadhaarFacingMode = 'environment';

    function openAadhaarCamera(side) {
        currentAadhaarSide = side;
        document.getElementById('aadhaarCameraTitle').textContent = `Capture Aadhaar ${side === 'front' ? 'Front' : 'Back'}`;
        document.getElementById('aadhaarCameraModal').style.display = 'block';
        startAadhaarCamera('environment');
    }

    async function startAadhaarCamera(facingMode) {
        try {
            document.getElementById('aadhaarCameraStatus').textContent = 'Requesting camera...';
            const constraints = {
                video: { facingMode: facingMode },
                audio: false
            };
            aadhaarVideoStream = await navigator.mediaDevices.getUserMedia(constraints);
            const video = document.getElementById('aadhaarVideo');
            video.srcObject = aadhaarVideoStream;
            document.getElementById('aadhaarCameraStatus').textContent = 'Camera ready';
        } catch (err) {
            console.error('Camera error:', err);
            alert('Error accessing camera: ' + err.message);
            closeAadhaarCamera();
        }
    }

    function switchAadhaarCamera() {
        if (aadhaarVideoStream) {
            aadhaarVideoStream.getTracks().forEach(track => track.stop());
            aadhaarFacingMode = aadhaarFacingMode === 'user' ? 'environment' : 'user';
            startAadhaarCamera(aadhaarFacingMode);
        }
    }

    function captureAadhaarPhoto() {
        const video = document.getElementById('aadhaarVideo');
        const canvas = document.getElementById('aadhaarCanvas');
        
        if (!aadhaarVideoStream || video.videoWidth === 0) {
            alert('Camera is not ready');
            return;
        }
        
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        const imageData = canvas.toDataURL('image/jpeg', 0.8);
        
        if (currentAadhaarSide === 'front') {
            document.getElementById('capturedAadharFront').value = imageData;
            const preview = document.getElementById('frontPreview');
            preview.src = imageData;
            preview.classList.add('show');
            document.getElementById('aadhar_front').value = '';
        } else {
            document.getElementById('capturedAadharBack').value = imageData;
            const preview = document.getElementById('backPreview');
            preview.src = imageData;
            preview.classList.add('show');
            document.getElementById('aadhar_back').value = '';
        }
        
        closeAadhaarCamera();
        
        Swal.fire({
            icon: 'success',
            title: 'Captured!',
            text: 'Aadhaar image captured successfully',
            timer: 1500,
            showConfirmButton: false
        });
    }

    function closeAadhaarCamera() {
        if (aadhaarVideoStream) {
            aadhaarVideoStream.getTracks().forEach(track => track.stop());
            aadhaarVideoStream = null;
        }
        document.getElementById('aadhaarCameraModal').style.display = 'none';
    }

    // OpenStreetMap Nominatim API (Free, no API key required)
    let searchResults = [];

    function searchAddress() {
        const query = document.getElementById('address-search').value.trim();
        if (query.length < 3) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Search',
                text: 'Please enter at least 3 characters'
            });
            return;
        }
        
        // Show loading
        document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center;">Searching...</div>';
        document.getElementById('search-results').style.display = 'block';
        
        // Call Nominatim API
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=5&addressdetails=1`)
            .then(response => response.json())
            .then(data => {
                searchResults = data;
                
                if (data.length === 0) {
                    document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center;">No results found</div>';
                    return;
                }
                
                let html = '';
                data.forEach((item, index) => {
                    html += `<div onclick="selectAddress(${index})">
                            <i class="bi bi-geo-alt" style="margin-right: 5px;"></i> 
                            ${item.display_name}
                            </div>`;
                });
                document.getElementById('search-results').innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('search-results').innerHTML = '<div style="padding: 10px; text-align: center; color: red;">Error searching address</div>';
            });
    }

    function selectAddress(index) {
        const data = searchResults[index];
        if (!data) return;
        
        // Update hidden fields
        document.getElementById('latitude').value = data.lat;
        document.getElementById('longitude').value = data.lon;
        document.getElementById('formatted_address').value = data.display_name;
        document.getElementById('place_id').value = data.place_id || '';
        
        // Update address fields
        updateAddressFieldsFromNominatim(data);
        
        // Show selected address
        const addressDisplay = document.getElementById('selected-address-display');
        const addressText = document.getElementById('selected-address-text');
        if (addressDisplay && addressText) {
            addressText.textContent = data.display_name;
            addressDisplay.style.display = 'block';
        }
        
        // Hide results
        document.getElementById('search-results').style.display = 'none';
        
        // Update map (using OpenStreetMap static map)
        updateMap(data.lat, data.lon);
    }

    function updateMap(lat, lon) {
        const mapContainer = document.getElementById('map');
        
        // Using OpenStreetMap static map
        mapContainer.innerHTML = `
            <img src="https://staticmap.openstreetmap.de/staticmap.php?center=${lat},${lon}&zoom=15&size=600x250&markers=${lat},${lon}" 
                 style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;"
                 alt="Map showing selected location">
        `;
    }

    function updateAddressFieldsFromNominatim(data) {
        // Parse address components
        const address = data.address || {};
        
        // Address Line 1 (House/Road)
        const doorNo = document.getElementById('door_no');
        const streetName = document.getElementById('street_name');
        
        if (doorNo && streetName) {
            if (address.house_number) doorNo.value = address.house_number;
            if (address.road) streetName.value = address.road;
            else if (address.pedestrian) streetName.value = address.pedestrian;
        }
        
        // Address Line 2 (Area/Suburb)
        const addressLine2 = document.getElementById('street_name1');
        if (addressLine2) {
            let line2 = '';
            if (address.suburb) line2 += address.suburb;
            else if (address.neighbourhood) line2 += address.neighbourhood;
            else if (address.village) line2 += address.village;
            else if (address.town) line2 += address.town;
            addressLine2.value = line2;
        }
        
        // City
        const cityField = document.getElementById('location');
        if (cityField) {
            cityField.value = address.city || address.town || address.village || address.municipality || '';
        }
        
        // State
        const stateField = document.getElementById('district');
        if (stateField) {
            stateField.value = address.state || address.county || '';
        }
        
        // Pincode
        const pincodeField = document.getElementById('pincode');
        if (pincodeField) {
            pincodeField.value = address.postcode || '';
        }
        
        // Taluk (if available)
        const talukField = document.getElementById('taluk');
        if (talukField && address.county) {
            talukField.value = address.county;
        }
        
        console.log('Address fields filled:', {
            door_no: doorNo?.value,
            street_name: streetName?.value,
            street_name1: addressLine2?.value,
            location: cityField?.value,
            district: stateField?.value,
            pincode: pincodeField?.value,
            taluk: talukField?.value
        });
    }

    // Close search results when clicking outside
    document.addEventListener('click', function(event) {
        const searchResults = document.getElementById('search-results');
        const searchInput = document.getElementById('address-search');
        const searchButton = document.querySelector('button[onclick="searchAddress()"]');
        
        if (searchResults && searchInput && searchButton) {
            if (!searchInput.contains(event.target) && !searchResults.contains(event.target) && !searchButton.contains(event.target)) {
                searchResults.style.display = 'none';
            }
        }
    });

    // Allow search on Enter key
    document.getElementById('address-search')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchAddress();
        }
    });

    // Add debounce for better performance
    let searchTimeout;
    document.getElementById('address-search')?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            if (this.value.length >= 3) {
                searchAddress();
            }
        }, 500);
    });

    // Form submission validation
    document.getElementById('customerForm').addEventListener('submit', function(e) {
        const mobile = document.querySelector('input[name="mobile_number"]').value;
        const accountNum = accountNumber ? accountNumber.value : '';
        const confirmAccountVal = confirmAccount ? confirmAccount.value : '';
        
        if (!mobile || mobile.length !== 10 || isNaN(mobile)) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Mobile Number',
                text: 'Please enter a valid 10-digit mobile number'
            });
            document.querySelector('input[name="mobile_number"]').focus();
            return;
        }
        
        if (accountNum && accountNum !== confirmAccountVal) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Account Number Mismatch',
                text: 'Account numbers do not match'
            });
            if (confirmAccount) confirmAccount.focus();
            return;
        }
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);

    // Clean up camera on page unload
    window.addEventListener('beforeunload', function() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
        }
        if (aadhaarVideoStream) {
            aadhaarVideoStream.getTracks().forEach(track => track.stop());
        }
    });
</script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php include 'includes/scripts.php'; ?>
</body>
</html>