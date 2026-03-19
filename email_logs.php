<?php
// email_logs.php
require_once 'includes/db.php';
session_start();

if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$logs_query = "SELECT e.*, l.receipt_number, c.customer_name 
               FROM email_logs e
               JOIN loans l ON e.loan_id = l.id
               JOIN customers c ON l.customer_id = c.id
               ORDER BY e.created_at DESC
               LIMIT 50";
$logs_result = mysqli_query($conn, $logs_query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Logs</title>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th { background: #667eea; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
        .sent { color: green; }
        .failed { color: red; }
    </style>
</head>
<body>
    <h2>📧 Email Logs</h2>
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Receipt #</th>
                <th>Customer</th>
                <th>Email</th>
                <th>Status</th>
                <th>Sent At</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($log = mysqli_fetch_assoc($logs_result)): ?>
            <tr>
                <td><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                <td><?php echo $log['receipt_number']; ?></td>
                <td><?php echo htmlspecialchars($log['customer_name']); ?></td>
                <td><?php echo $log['customer_email'] ?: 'N/A'; ?></td>
                <td class="<?php echo $log['status']; ?>"><?php echo ucfirst($log['status']); ?></td>
                <td><?php echo $log['sent_at'] ? date('d/m/Y H:i', strtotime($log['sent_at'])) : '-'; ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>