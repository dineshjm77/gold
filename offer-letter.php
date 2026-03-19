<?php
session_start();
$currentPage = 'offer-letter';
$pageTitle = 'Employee Offer Letter';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user has admin permission
if (!in_array($_SESSION['user_role'], ['admin'])) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';
$employee = null;

// Get employee ID from URL
$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Debug: Check if ID is received
if ($employee_id <= 0) {
    $error = "Invalid employee ID. Please select a valid employee.";
} else {
    // Get employee details
    $query = "SELECT u.*, b.branch_name 
              FROM users u 
              LEFT JOIN branches b ON u.branch_id = b.id 
              WHERE u.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $employee_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $employee = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
    } else {
        $error = "Database error: " . mysqli_error($conn);
    }
}

// If employee not found, show error but don't redirect
if (!$employee && $employee_id > 0) {
    $error = "Employee not found with ID: " . $employee_id;
}

// Get company settings
$company_query = "SELECT * FROM branches WHERE id = 1 LIMIT 1";
$company_result = mysqli_query($conn, $company_query);
$company = mysqli_fetch_assoc($company_result);

if (!$company) {
    $company = [
        'branch_name' => 'WEALTHROT',
        'address' => 'Main Branch',
        'phone' => '',
        'email' => '',
        'website' => 'wealthrot.in'
    ];
}

// Format salary components
$basic_salary = floatval($employee['basic_salary'] ?? 0);
$hra = floatval($employee['hra'] ?? 0);
$conveyance = floatval($employee['conveyance'] ?? 0);
$medical_allowance = floatval($employee['medical_allowance'] ?? 0);
$special_allowance = floatval($employee['special_allowance'] ?? 0);
$bonus = floatval($employee['bonus'] ?? 0);

$total_salary = $basic_salary + $hra + $conveyance + $medical_allowance + $special_allowance;

// Format date function
function formatDate($date) {
    if (empty($date) || $date == '0000-00-00') return 'Not Specified';
    return date('d F Y', strtotime($date));
}

// Generate offer letter number
$offer_letter_no = 'OL/' . date('Y') . '/' . str_pad($employee_id, 4, '0', STR_PAD_LEFT);

// ============================================
// PDF GENERATION
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'download' && $employee) {
    
    // Check if mPDF is installed
    $use_mpdf = false;
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/vendor/autoload.php';
            $use_mpdf = true;
        } catch (Exception $e) {
            $use_mpdf = false;
            error_log("mPDF Error: " . $e->getMessage());
        }
    }
    
    if ($use_mpdf) {
        // Create temp directory
        $tempDir = __DIR__ . '/tmp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        
        // Generate HTML content
        $html = generateOfferLetterHTML($employee, $company, $offer_letter_no, $basic_salary, $hra, $conveyance, $medical_allowance, $special_allowance, $bonus, $total_salary);
        
        // Create mPDF instance
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 20,
            'margin_right' => 20,
            'margin_top' => 30,
            'margin_bottom' => 20,
            'tempDir' => $tempDir
        ]);
        
        $mpdf->WriteHTML($html);
        $filename = 'Offer_Letter_' . preg_replace('/[^a-zA-Z0-9]/', '_', $employee['name']) . '.pdf';
        $mpdf->Output($filename, 'D');
        exit();
    } else {
        $error = "PDF library not installed. Please install mPDF via Composer or use the print option.";
    }
}

// Generate HTML for offer letter
function generateOfferLetterHTML($employee, $company, $offer_letter_no, $basic_salary, $hra, $conveyance, $medical_allowance, $special_allowance, $bonus, $total_salary) {
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Offer Letter - ' . htmlspecialchars($employee['name'] ?? 'Employee') . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }
        .company-details {
            font-size: 12px;
            color: #718096;
        }
        .letter-title {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
            margin: 20px 0;
            text-align: center;
        }
        .reference {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 12px;
            color: #4a5568;
        }
        .greeting {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .content {
            margin-bottom: 30px;
            text-align: justify;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #2d3748;
            margin: 20px 0 10px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
        }
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .details-table td {
            padding: 8px;
            border: 1px solid #e2e8f0;
        }
        .details-table td:first-child {
            font-weight: bold;
            width: 40%;
            background: #f7fafc;
        }
        .salary-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        .salary-table th {
            background: #667eea;
            color: white;
            padding: 10px;
            text-align: left;
        }
        .salary-table td {
            padding: 8px;
            border: 1px solid #e2e8f0;
        }
        .salary-table tr:last-child {
            font-weight: bold;
            background: #f0fff4;
        }
        .terms {
            margin: 20px 0;
            padding: 15px;
            background: #f8fafc;
            border-left: 4px solid #667eea;
            font-size: 12px;
        }
        .signature {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            text-align: center;
            width: 45%;
        }
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 12px;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #a0aec0;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }
        .highlight {
            color: #48bb78;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">';
    
    if (!$employee) {
        $html .= '<p style="color: red; text-align: center;">Employee data not available.</p>';
        return $html . '</div></body></html>';
    }
    
    // Header
    $html .= '<div class="header">
            <div class="company-name">' . htmlspecialchars($company['branch_name']) . '</div>
            <div class="company-details">' . htmlspecialchars($company['address'] ?? '') . '</div>
            <div class="company-details">Phone: ' . htmlspecialchars($company['phone'] ?? '') . ' | Email: ' . htmlspecialchars($company['email'] ?? '') . '</div>
            <div class="company-details">Website: ' . htmlspecialchars($company['website'] ?? '') . '</div>
        </div>

        <div class="letter-title">OFFER OF EMPLOYMENT</div>

        <div class="reference">
            <div><strong>Ref No:</strong> ' . $offer_letter_no . '</div>
            <div><strong>Date:</strong> ' . date('d F Y') . '</div>
        </div>

        <div class="greeting">
            Dear ' . htmlspecialchars($employee['name'] ?? 'Candidate') . ',
        </div>

        <div class="content">
            <p>We are pleased to offer you the position of <strong>' . htmlspecialchars($employee['designation'] ?? 'Employee') . '</strong> at ' . htmlspecialchars($company['branch_name']) . '. We were impressed with your qualifications and experience, and we believe you will be a valuable asset to our organization.</p>';
            
    if (!empty($employee['joining_date']) && $employee['joining_date'] != '0000-00-00') {
        $html .= '<p>Your expected date of joining is <strong>' . formatDate($employee['joining_date']) . '</strong>. Please report to our office at ' . htmlspecialchars($company['address'] ?? '') . ' on your joining date by 9:00 AM.</p>';
    } else {
        $html .= '<p>Your joining date will be communicated to you shortly. Please report to our office at ' . htmlspecialchars($company['address'] ?? '') . ' on your joining date by 9:00 AM.</p>';
    }
    
    $html .= '</div>';

    // Employee Details
    $html .= '<div class="section-title">Employee Details</div>
        <table class="details-table">
            <tr><td>Full Name</td><td>' . htmlspecialchars($employee['name'] ?? '') . '</td></tr>
            <tr><td>Employee ID</td><td>' . htmlspecialchars($employee['employee_id'] ?? 'Not Assigned') . '</td></tr>
            <tr><td>Department</td><td>' . htmlspecialchars($employee['department'] ?? 'Not Assigned') . '</td></tr>
            <tr><td>Designation</td><td>' . htmlspecialchars($employee['designation'] ?? 'Not Assigned') . '</td></tr>
            <tr><td>Date of Birth</td><td>' . formatDate($employee['date_of_birth'] ?? '') . '</td></tr>
            <tr><td>Gender</td><td>' . htmlspecialchars($employee['gender'] ?? 'Not Specified') . '</td></tr>
            <tr><td>Blood Group</td><td>' . htmlspecialchars($employee['blood_group'] ?? 'Not Specified') . '</td></tr>
            <tr><td>Mobile Number</td><td>' . htmlspecialchars($employee['mobile'] ?? 'Not Provided') . '</td></tr>
            <tr><td>Email</td><td>' . htmlspecialchars($employee['email'] ?? 'Not Provided') . '</td></tr>';
    
    // Current Address
    $current_address = '';
    if (!empty($employee['address_line1'])) $current_address .= $employee['address_line1'] . ' ';
    if (!empty($employee['address_line2'])) $current_address .= $employee['address_line2'] . ', ';
    if (!empty($employee['city'])) $current_address .= $employee['city'] . ', ';
    if (!empty($employee['state'])) $current_address .= $employee['state'] . ' - ';
    if (!empty($employee['pincode'])) $current_address .= $employee['pincode'];
    
    $html .= '<tr><td>Current Address</td><td>' . ($current_address ? htmlspecialchars($current_address) : 'Not Provided') . '</td></tr>';
    
    // Permanent Address
    if (!empty($employee['permanent_address_same']) && $employee['permanent_address_same'] == 1) {
        $html .= '<tr><td>Permanent Address</td><td>Same as Current Address</td></tr>';
    } else {
        $permanent_address = '';
        if (!empty($employee['permanent_address_line1'])) $permanent_address .= $employee['permanent_address_line1'] . ' ';
        if (!empty($employee['permanent_address_line2'])) $permanent_address .= $employee['permanent_address_line2'] . ', ';
        if (!empty($employee['permanent_city'])) $permanent_address .= $employee['permanent_city'] . ', ';
        if (!empty($employee['permanent_state'])) $permanent_address .= $employee['permanent_state'] . ' - ';
        if (!empty($employee['permanent_pincode'])) $permanent_address .= $employee['permanent_pincode'];
        
        $html .= '<tr><td>Permanent Address</td><td>' . ($permanent_address ? htmlspecialchars($permanent_address) : 'Not Provided') . '</td></tr>';
    }
    
    $html .= '</table>';

    // Compensation Details
    if ($total_salary > 0) {
        $html .= '<div class="section-title">Compensation Details</div>
            <table class="salary-table">
                <thead>
                    <tr>
                        <th>Component</th>
                        <th>Amount (per month)</th>
                    </tr>
                </thead>
                <tbody>';
        
        if ($basic_salary > 0) {
            $html .= '<tr><td>Basic Salary</td><td>₹ ' . number_format($basic_salary, 2) . '</td></tr>';
        }
        if ($hra > 0) {
            $html .= '<tr><td>House Rent Allowance (HRA)</td><td>₹ ' . number_format($hra, 2) . '</td></tr>';
        }
        if ($conveyance > 0) {
            $html .= '<tr><td>Conveyance Allowance</td><td>₹ ' . number_format($conveyance, 2) . '</td></tr>';
        }
        if ($medical_allowance > 0) {
            $html .= '<tr><td>Medical Allowance</td><td>₹ ' . number_format($medical_allowance, 2) . '</td></tr>';
        }
        if ($special_allowance > 0) {
            $html .= '<tr><td>Special Allowance</td><td>₹ ' . number_format($special_allowance, 2) . '</td></tr>';
        }
        
        $html .= '<tr><td><strong>Total Monthly Salary</strong></td><td><strong>₹ ' . number_format($total_salary, 2) . '</strong></td></tr>';
        
        if ($bonus > 0) {
            $html .= '<tr><td>Annual Bonus (approx)</td><td>₹ ' . number_format($bonus, 2) . '</td></tr>';
        }
        
        $html .= '</tbody></table>';
    }

    // Employment Terms
    $html .= '<div class="section-title">Terms of Employment</div>
        <table class="details-table">
            <tr><td>Employment Type</td><td>' . ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? 'Full Time')) . '</td></tr>
            <tr><td>Reporting Manager</td><td>' . htmlspecialchars($employee['reporting_manager'] ?? 'To be assigned') . '</td></tr>
            <tr><td>Work Location</td><td>' . htmlspecialchars($employee['work_location'] ?? $company['branch_name']) . '</td></tr>
            <tr><td>Shift Timing</td><td>' . htmlspecialchars($employee['shift_timing'] ?? '9:00 AM - 6:00 PM') . '</td></tr>
            <tr><td>Weekly Off</td><td>' . ucfirst($employee['weekly_off'] ?? 'Sunday') . '</td></tr>
        </table>';

    // Terms and Conditions
    $html .= '<div class="terms">
            <strong>Terms and Conditions:</strong>
            <ol style="margin-top: 10px; padding-left: 20px;">
                <li>You will be on probation for a period of 6 months from the date of joining.</li>
                <li>Confirmation of employment will be based on performance review at the end of probation period.</li>
                <li>You are required to maintain confidentiality of all company information.</li>
                <li>This offer is subject to verification of your documents and references.</li>
                <li>Any changes to the terms of employment will be communicated in writing.</li>
                <li>You are expected to adhere to company policies and code of conduct.</li>
            </ol>
        </div>';

    // Documents to bring
    $html .= '<p style="font-size: 12px; margin-top: 20px;">
            <strong>Please bring the following documents on your joining date:</strong>
            </p>
            <ul style="font-size: 12px; margin-left: 20px;">
                <li>Signed copy of this offer letter</li>
                <li>PAN Card and Aadhaar Card</li>
                <li>Educational certificates (originals for verification)</li>
                <li>Previous employment experience letters</li>
                <li>Passport size photographs (4 copies)</li>
                <li>Bank account details and cancelled cheque</li>
            </ul>';

    // Acceptance
    $html .= '<p style="margin-top: 30px;">
            Kindly confirm your acceptance of this offer by signing the duplicate copy of this letter and returning it to us by ' . date('d F Y', strtotime('+7 days')) . '.
        </p>';

    // Signature
    $html .= '<div class="signature">
            <div class="signature-box">
                <div class="signature-line">' . htmlspecialchars($employee['name'] ?? '') . '</div>
                <div>Employee Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized Signatory</div>
                <div>For ' . htmlspecialchars($company['branch_name']) . '</div>
            </div>
        </div>';

    // Footer
    $html .= '<div class="footer">
            <p>This is a computer generated offer letter. No signature is required for this copy.</p>
            <p>Offer Letter No: ' . $offer_letter_no . ' | Generated on: ' . date('d-m-Y H:i:s') . '</p>
        </div>';

    $html .= '</div></body></html>';
    
    return $html;
}
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

        .container {
            max-width: 900px;
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

        .btn-secondary {
            background: #a0aec0;
            color: white;
        }

        .btn-secondary:hover {
            background: #718096;
        }

        .btn-info {
            background: #4299e1;
            color: white;
        }

        .btn-info:hover {
            background: #3182ce;
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

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .preview-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .employee-info {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
            border-radius: 12px;
        }

        .employee-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .employee-details h2 {
            font-size: 24px;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .employee-details p {
            color: #718096;
            font-size: 14px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .info-item {
            padding: 10px;
            background: #f7fafc;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #718096;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .offer-letter-preview {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 30px;
            margin: 20px 0;
            font-family: Arial, sans-serif;
            line-height: 1.6;
            max-height: 600px;
            overflow-y: auto;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .no-data {
            text-align: center;
            padding: 50px;
            color: #718096;
        }

        .no-data i {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .employee-info {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
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
                <div class="container">
                    <!-- Page Header -->
                    <div class="page-header">
                        <h1 class="page-title">
                            <i class="bi bi-file-text"></i>
                            Employee Offer Letter
                        </h1>
                        <div>
                            <a href="employees.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Employees
                            </a>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$employee && $employee_id > 0): ?>
                        <div class="preview-card no-data">
                            <i class="bi bi-person-x"></i>
                            <h3>Employee Not Found</h3>
                            <p>The employee you're looking for doesn't exist or has been removed.</p>
                            <a href="employees.php" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="bi bi-arrow-left"></i> Back to Employees
                            </a>
                        </div>
                    <?php elseif ($employee): ?>
                        <!-- Employee Information -->
                        <div class="preview-card">
                            <div class="employee-info">
                                <?php if (!empty($employee['employee_photo']) && file_exists($employee['employee_photo'])): ?>
                                    <img src="<?php echo htmlspecialchars($employee['employee_photo']); ?>" class="employee-avatar" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="employee-avatar">
                                        <?php 
                                        $name_parts = explode(' ', $employee['name']);
                                        $initials = '';
                                        foreach ($name_parts as $part) {
                                            if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
                                        }
                                        echo substr($initials, 0, 2);
                                        ?>
                                    </div>
                                <?php endif; ?>
                                <div class="employee-details">
                                    <h2><?php echo htmlspecialchars($employee['name']); ?></h2>
                                    <p><?php echo htmlspecialchars($employee['designation'] ?? 'Employee'); ?> | <?php echo htmlspecialchars($employee['employee_id'] ?? 'ID Not Assigned'); ?></p>
                                </div>
                            </div>

                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Department</div>
                                    <div class="info-value"><?php echo htmlspecialchars($employee['department'] ?? 'Not Assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Joining Date</div>
                                    <div class="info-value"><?php echo formatDate($employee['joining_date']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Employment Type</div>
                                    <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $employee['employment_type'] ?? 'Full Time')); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Reporting Manager</div>
                                    <div class="info-value"><?php echo htmlspecialchars($employee['reporting_manager'] ?? 'To be assigned'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Offer Letter Preview -->
                        <div class="preview-card">
                            <h3 style="margin-bottom: 15px;">Offer Letter Preview</h3>
                            <div class="offer-letter-preview">
                                <?php echo generateOfferLetterHTML($employee, $company, $offer_letter_no, $basic_salary, $hra, $conveyance, $medical_allowance, $special_allowance, $bonus, $total_salary); ?>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="?id=<?php echo $employee_id; ?>&action=download" class="btn btn-success">
                                    <i class="bi bi-download"></i> Download PDF
                                </a>
                                <a href="?id=<?php echo $employee_id; ?>" class="btn btn-info" onclick="window.print(); return false;">
                                    <i class="bi bi-printer"></i> Print
                                </a>
                                <a href="edit-user.php?id=<?php echo $employee_id; ?>" class="btn btn-primary">
                                    <i class="bi bi-pencil"></i> Edit Employee
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- No ID provided -->
                        <div class="preview-card no-data">
                            <i class="bi bi-person"></i>
                            <h3>Select an Employee</h3>
                            <p>Please select an employee from the employees list to generate an offer letter.</p>
                            <a href="employees.php" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="bi bi-arrow-left"></i> View Employees
                            </a>
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