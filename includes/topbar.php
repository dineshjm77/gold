<?php
// topbar.php
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? 'sale';
?>
<!-- Topbar -->
<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <!-- Mobile menu toggle -->
        <button class="btn btn-link d-lg-none" type="button" onclick="toggleSidebar()">
            <i class="bi bi-list fs-4"></i>
        </button>
        
        <!-- Page title -->
        <h4 class="mb-0"><?php echo $page_title ?? 'Dashboard'; ?></h4>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <!-- Search -->
        <div class="search-box d-none d-md-block">
            <div class="position-relative">
                <input type="text" class="form-control" placeholder="Search jobs, customers...">
                <i class="bi bi-search position-absolute top-50 translate-middle-y" style="right: 12px;"></i>
            </div>
        </div>
        
        <!-- User dropdown -->
        <div class="dropdown">
            <button class="btn btn-link text-dark text-decoration-none dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <div class="d-flex align-items-center gap-2">
                    <div class="user-avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                    <span class="d-none d-md-inline"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="change-password.php"><i class="bi bi-lock me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirmLogout()"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
        
        <!-- Role badge -->
        <span class="badge bg-soft-<?php echo $user_role === 'admin' ? 'primary' : 'success'; ?> d-none d-md-inline">
            <?php echo ucfirst($user_role); ?>
        </span>
    </div>
</div>

<style>
.topbar {
    background: white;
    padding: 12px 25px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.search-box .form-control {
    width: 300px;
    padding-right: 35px;
    border-radius: 20px;
    border: 1px solid #e9ecef;
}
.search-box .form-control:focus {
    border-color: #667eea;
    box-shadow: none;
}
.bg-soft-primary {
    background: rgba(102, 126, 234, 0.15);
    color: #667eea;
    padding: 5px 12px;
    border-radius: 20px;
    font-weight: 500;
}
.bg-soft-success {
    background: rgba(40, 167, 69, 0.15);
    color: #28a745;
    padding: 5px 12px;
    border-radius: 20px;
    font-weight: 500;
}
</style>

<script>
function confirmLogout() {
    Swal.fire({
        title: 'Are you sure?',
        text: "You will be logged out of the system",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, logout',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'logout.php';
        }
    });
    return false;
}
</script>