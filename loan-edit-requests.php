<?php
session_start();
$currentPage = 'loan-edit-requests';
$pageTitle = 'Loan Edit Requests';
require_once 'includes/db.php';
require_once 'auth_check.php';

// Only admin can view all requests
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build query
$query = "SELECT er.*, l.receipt_number, c.customer_name, 
          u_req.name as requester_name,
          u_app.name as approver_name,
          u_rej.name as rejecter_name
          FROM loan_edit_requests er
          JOIN loans l ON er.loan_id = l.id
          JOIN customers c ON l.customer_id = c.id
          JOIN users u_req ON er.requested_by = u_req.id
          LEFT JOIN users u_app ON er.approved_by = u_app.id
          LEFT JOIN users u_rej ON er.rejected_by = u_rej.id
          WHERE 1=1";

if (!empty($status_filter)) {
    $query .= " AND er.status = '$status_filter'";
}
$query .= " ORDER BY er.created_at DESC";

$requests_result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'includes/head.php'; ?>
    <style>
        /* Add your styles here - similar to other admin pages */
        .container { padding: 20px; }
        .filters { margin-bottom: 20px; }
        .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-pending { background: #feebc8; color: #744210; }
        .status-approved { background: #c6f6d5; color: #22543d; }
        .status-rejected { background: #fed7d7; color: #742a2a; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/topbar.php'; ?>
        
        <div class="page-content">
            <div class="container">
                <h2>Loan Edit Requests</h2>
                
                <!-- Filters -->
                <div class="filters">
                    <form method="GET">
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </form>
                </div>
                
                <!-- Requests Table -->
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Loan #</th>
                            <th>Customer</th>
                            <th>Requested By</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Request Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($request = mysqli_fetch_assoc($requests_result)): ?>
                        <tr>
                            <td>#<?php echo $request['id']; ?></td>
                            <td><a href="view-loan.php?id=<?php echo $request['loan_id']; ?>"><?php echo $request['receipt_number']; ?></a></td>
                            <td><?php echo htmlspecialchars($request['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                            <td><?php echo htmlspecialchars($request['request_reason']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d-m-Y H:i', strtotime($request['created_at'])); ?></td>
                            <td>
                                <?php if ($request['status'] == 'pending'): ?>
                                    <a href="approve-edit-request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success">Approve</a>
                                    <a href="reject-edit-request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
                                <?php elseif ($request['status'] == 'approved'): ?>
                                    Approved by <?php echo $request['approver_name']; ?><br>
                                    <small><?php echo date('d-m-Y H:i', strtotime($request['approved_at'])); ?></small>
                                <?php else: ?>
                                    Rejected by <?php echo $request['rejecter_name']; ?><br>
                                    <small><?php echo date('d-m-Y H:i', strtotime($request['rejected_at'])); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php include 'includes/scripts.php'; ?>
</body>
</html>