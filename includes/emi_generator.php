<?php
/**
 * EMI Generator Helper Functions
 * Simplified version with error handling
 */

function getEMIStats($loan_id, $loan_type, $conn) {
    // Default stats
    $stats = [
        'total_emis' => 0,
        'paid_emis' => 0,
        'unpaid_emis' => 0,
        'overdue_emis' => 0,
        'total_paid' => 0,
        'total_pending' => 0
    ];
    
    // Check if table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'emi_schedules'");
    if (!$table_check || mysqli_num_rows($table_check) == 0) {
        // Table doesn't exist, return default stats
        return $stats;
    }
    
    // Table exists, try to get stats
    $query = "SELECT 
                COUNT(*) as total_emis,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_emis,
                SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_emis,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_emis,
                COALESCE(SUM(paid_amount), 0) as total_paid,
                COALESCE(SUM(CASE WHEN status != 'paid' THEN total_amount - COALESCE(paid_amount, 0) ELSE 0 END), 0) as total_pending
              FROM emi_schedules 
              WHERE loan_id = ? AND loan_type = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'is', $loan_id, $loan_type);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            $stats = array_merge($stats, $row);
        }
        mysqli_stmt_close($stmt);
    }
    
    return $stats;
}

/**
 * Generate EMI schedule for a loan
 */
function generateEMISchedule($loan_id, $loan_type, $loan_amount, $interest_rate, $tenure_months, $receipt_date, $conn) {
    // Check if table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'emi_schedules'");
    if (!$table_check || mysqli_num_rows($table_check) == 0) {
        // Create table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS `emi_schedules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `loan_id` INT NOT NULL,
            `loan_type` VARCHAR(50) DEFAULT 'personal',
            `installment_no` INT NOT NULL,
            `principal_amount` DECIMAL(15,2) NOT NULL,
            `interest_amount` DECIMAL(15,2) NOT NULL,
            `total_amount` DECIMAL(15,2) NOT NULL,
            `paid_amount` DECIMAL(15,2) DEFAULT 0.00,
            `remaining_amount` DECIMAL(15,2) NOT NULL,
            `due_date` DATE NOT NULL,
            `paid_date` DATE DEFAULT NULL,
            `overdue_days` INT DEFAULT 0,
            `status` ENUM('paid','unpaid','overdue','partial','closed') DEFAULT 'unpaid',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `loan_id` (`loan_id`),
            KEY `due_date` (`due_date`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($conn, $create_table);
    }
    
    // Calculate EMI
    $monthly_rate = $interest_rate / 12 / 100;
    if ($monthly_rate > 0) {
        $emi = $loan_amount * $monthly_rate * pow(1 + $monthly_rate, $tenure_months) / (pow(1 + $monthly_rate, $tenure_months) - 1);
    } else {
        $emi = $loan_amount / $tenure_months;
    }
    
    // Clear existing schedules
    $clear_query = "DELETE FROM emi_schedules WHERE loan_id = ? AND loan_type = ?";
    $clear_stmt = mysqli_prepare($conn, $clear_query);
    if ($clear_stmt) {
        mysqli_stmt_bind_param($clear_stmt, 'is', $loan_id, $loan_type);
        mysqli_stmt_execute($clear_stmt);
        mysqli_stmt_close($clear_stmt);
    }
    
    // Generate monthly EMIs
    $start_date = new DateTime($receipt_date);
    $remaining_principal = $loan_amount;
    
    for ($i = 1; $i <= $tenure_months; $i++) {
        $due_date = clone $start_date;
        $due_date->modify('+' . $i . ' months');
        
        // Calculate interest for this month
        $interest_for_month = $remaining_principal * ($interest_rate / 100 / 12);
        
        // Principal portion
        $principal_for_month = $emi - $interest_for_month;
        if ($principal_for_month < 0) $principal_for_month = 0;
        
        // Update remaining principal
        $remaining_principal -= $principal_for_month;
        if ($remaining_principal < 0) $remaining_principal = 0;
        
        $insert_query = "INSERT INTO emi_schedules 
            (loan_id, loan_type, installment_no, principal_amount, interest_amount, 
             total_amount, remaining_amount, due_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unpaid')";
        
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        if ($insert_stmt) {
            mysqli_stmt_bind_param($insert_stmt, 'isidddds', 
                $loan_id, $loan_type, $i,
                $principal_for_month, $interest_for_month,
                $emi, $remaining_principal,
                $due_date->format('Y-m-d')
            );
            mysqli_stmt_execute($insert_stmt);
            mysqli_stmt_close($insert_stmt);
        }
    }
    
    return true;
}
?>