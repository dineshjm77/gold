<?php
// sidebar.php

// Assume $conn is already included from db.php
// Get current user data
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user_role'] ?? 'sale';
$user_name = $_SESSION['user_name'] ?? 'User';

// Calculate initials for avatar
$name_parts = explode(' ', trim($user_name));
$initials = '';
foreach ($name_parts as $part) {
    if (!empty($part)) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
if (strlen($initials) > 2) $initials = substr($initials, 0, 2);

// Current page for active highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Get pending personal loan requests count for admin
$pending_pl_count = 0;
if ($user_role === 'admin') {
    $pending_query = "SELECT COUNT(*) as cnt FROM personal_loan_requests WHERE status = 'pending'";
    $pending_result = mysqli_query($conn, $pending_query);
    if ($pending_result) {
        $pending_row = mysqli_fetch_assoc($pending_result);
        $pending_pl_count = $pending_row['cnt'] ?? 0;
    }
}

// Full menu structure with increased font sizes
$menu_items = [
    'main' => [
        'label' => 'MAIN',
        'items' => [
            'dashboard' => [
                'title' => 'Dashboard',
                'icon' => 'bi-grid-1x2-fill',
                'url' => 'index.php',
                'roles' => ['admin', 'sale']
            ],
            'chart-dashboard' => [
                'title' => 'Chart Dashboard',
                'icon' => 'bi-bar-chart-fill',
                'url' => 'chat-dashboard.php',
                'roles' => ['admin', 'sale']
            ]
        ],
    ],
    
    'branch' => [
        'label' => 'BRANCH',
        'items' => [
            'add-branch' => [
                'title' => 'Add Branch',
                'icon' => 'bi-building-add',
                'url' => 'add_branch.php',
                'roles' => ['admin']
            ],
            'manage-branch' => [
                'title' => 'Manage Branch',
                'icon' => 'bi-building',
                'url' => 'manage_branches.php',
                'roles' => ['admin']
            ],
            'manage-product-price' => [
                'title' => 'Manage Product Price',
                'icon' => 'bi-tags',
                'url' => 'product-value-settings.php',
                'roles' => ['admin']
            ],
        ]
    ],
    
    'loan_details' => [
        'label' => 'LOAN DETAILS',
        'items' => [
            'new-loan' => [
                'title' => 'New Loan',
                'icon' => 'bi-plus-circle',
                'url' => 'New-Loan.php',
                'roles' => ['admin', 'sale']
            ],
            'reloan' => [
                'title' => 'ReLoan',
                'icon' => 'bi-arrow-repeat',
                'url' => 'reloan.php',
                'roles' => ['admin', 'sale']
            ],
            'loan-collection' => [
                'title' => 'Loan Collection',
                'icon' => 'bi-cash-stack',
                'url' => 'loan-collection.php',
                'roles' => ['admin', 'sale']
            ],
            'close-loan' => [
                'title' => 'Close Loan',
                'icon' => 'bi-check-circle',
                'url' => 'Close-Loan.php',
                'roles' => ['admin', 'sale']
            ],
            'bulk-loan-close' => [
                'title' => 'Bulk Loan Close',
                'icon' => 'bi-stack',
                'url' => 'bulk-loan-close.php',
                'roles' => ['admin']
            ],
            'print-receipt-copy' => [
                'title' => 'Print Receipt Copy',
                'icon' => 'bi-printer',
                'url' => 'Print-Receipt-Copy.php',
                'roles' => ['admin', 'sale']
            ],
            'loan-reports' => [
                'title' => 'Loan Reports',
                'icon' => 'bi-graph-up',
                'url' => 'Loan-Reports.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'personal_loan' => [
        'label' => 'PERSONAL LOAN',
        'items' => [
            'create-personal-loan' => [
                'title' => 'Create Personal Loan',
                'icon' => 'bi-plus-circle',
                'url' => 'create-personal-loan.php',
                'roles' => ['admin', 'sale']
            ],
            'personal-loan-requests' => [
                'title' => 'Personal Loan Requests',
                'icon' => 'bi-send',
                'url' => 'personal-loan-requests.php',
                'roles' => ['admin'],
                'badge' => $pending_pl_count > 0 ? $pending_pl_count : null,
                'badge_class' => 'bg-danger'
            ],
            'personal-loans' => [
                'title' => 'All Personal Loans',
                'icon' => 'bi-cash-stack',
                'url' => 'personal-loans.php',
                'roles' => ['admin', 'sale']
            ],
            'collect-emi' => [
                'title' => 'Collect EMI',
                'icon' => 'bi-cash-coin',
                'url' => 'collect-emi.php',
                'roles' => ['admin', 'sale']
            ],
            'monthly-collections' => [
                'title' => 'Monthly Collections',
                'icon' => 'bi-calendar-month',
                'url' => 'monthly-collections.php',
                'roles' => ['admin', 'sale']
            ],
            'close-personal-loan' => [
                'title' => 'Close Personal Loan',
                'icon' => 'bi-check-circle',
                'url' => 'select-loan-to-close.php',
                'roles' => ['admin', 'sale']
            ],
        ]
    ],

    'customers' => [
        'label' => 'CUSTOMERS',
        'items' => [
            'new-customer' => [
                'title' => 'New Customer',
                'icon' => 'bi-person-plus',
                'url' => 'New-Customer.php',
                'roles' => ['admin', 'sale']
            ],
            'customer-details' => [
                'title' => 'Customer Details',
                'icon' => 'bi-people',
                'url' => 'Customer-Details.php',
                'roles' => ['admin', 'sale']
            ],
            'customer-reports' => [
                'title' => 'Customer Reports',
                'icon' => 'bi-graph-up',
                'url' => 'Customer-Reports.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'employees' => [
        'label' => 'EMPLOYEES',
        'items' => [
            'new-employee' => [
                'title' => 'New Employee',
                'icon' => 'bi-person-plus',
                'url' => 'New-Employee.php',
                'roles' => ['admin']
            ],
            'employees' => [
                'title' => 'Employees Details',
                'icon' => 'bi-people',
                'url' => 'employees.php',
                'roles' => ['admin', 'sale']
            ],
        ]
    ],
    
    'interest_details' => [
        'label' => 'INTEREST DETAILS',
        'items' => [
            'interest-type' => ['title' => 'Interest Type', 'icon' => 'bi-percent', 'url' => 'Interest-Type.php', 'roles' => ['admin']],
            'fixed-interest' => ['title' => 'Fixed Interest', 'icon' => 'bi-lock', 'url' => 'Fixed-Interest.php', 'roles' => ['admin', 'sale']],
            'dynamic-interest' => ['title' => 'Dynamic Interest', 'icon' => 'bi-arrow-left-right', 'url' => 'Dynamic-Interest.php', 'roles' => ['admin']],
            'amount-based-interest' => ['title' => 'Amount Based Interest', 'icon' => 'bi-currency-rupee', 'url' => 'Amount-Based-Interest.php', 'roles' => ['admin']],
        ]
    ],
    
    'investment' => [
        'label' => 'INVESTMENT',
        'items' => [
            'add-gods-name' => ['title' => "Add God's Name", 'icon' => 'bi-journal-plus', 'url' => 'Add-Gods-Name.php', 'roles' => ['admin']],
            'investment-type' => ['title' => 'Investment Type', 'icon' => 'bi-tags', 'url' => 'Investment-Type.php', 'roles' => ['admin']],
            'investor-creation' => ['title' => 'Investor Creation', 'icon' => 'bi-person-plus-fill', 'url' => 'Investor-Creation.php', 'roles' => ['admin', 'sale']],
            'investment' => ['title' => 'Investment', 'icon' => 'bi-currency-rupee', 'url' => 'Investment.php', 'roles' => ['admin', 'sale']],
            'investment-return' => ['title' => 'Investment Return', 'icon' => 'bi-arrow-up-right-square', 'url' => 'Investment-Return.php', 'roles' => ['admin', 'sale']],
            'investment-reports' => ['title' => 'Investment Reports', 'icon' => 'bi-graph-up-arrow', 'url' => 'Investment-Reports.php', 'roles' => ['admin', 'sale']],
        ]
    ],
    
    'finance_details' => [
        'label' => 'FINANCE DETAILS',
        'items' => [
            'expense-details' => [
                'title' => 'Expense Details', 
                'icon' => 'bi-cash', 
                'url' => 'Expense-Details.php', 
                'roles' => ['admin', 'sale']
            ],
            'expense-type' => [
                'title' => 'Expense Type', 
                'icon' => 'bi-tag', 
                'url' => 'Expense-Type.php', 
                'roles' => ['admin']
            ],
            'expense-reports' => [
                'title' => 'Expense Reports', 
                'icon' => 'bi-cash-coin', 
                'url' => 'Expense-Reports.php', 
                'roles' => ['admin', 'sale']
            ],
        ]
    ],
    
    'bank_details' => [
        'label' => 'BANK DETAILS',
        'items' => [
            'bank-master' => [
                'title' => 'Bank Master', 
                'icon' => 'bi-bank', 
                'url' => 'Bank-Master.php', 
                'roles' => ['admin']
            ],
            'bank-account-details' => [
                'title' => 'Bank Account Details', 
                'icon' => 'bi-bank2', 
                'url' => 'Bank-Account-Details.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-loan' => [
                'title' => 'Bank Loan', 
                'icon' => 'bi-cash-stack', 
                'url' => 'Bank-Loan.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-loan-close' => [
                'title' => 'Bank Loan Close', 
                'icon' => 'bi-check-circle', 
                'url' => 'Bank-Loan-Close.php', 
                'roles' => ['admin', 'sale']
            ],
            'payment-type' => [
                'title' => 'Payment Type', 
                'icon' => 'bi-credit-card', 
                'url' => 'Payment-Type.php', 
                'roles' => ['admin']
            ],
            'upi-type' => [
                'title' => 'UPI Type', 
                'icon' => 'bi-phone', 
                'url' => 'UPI-Type.php', 
                'roles' => ['admin']
            ],
            'bank-ledger-reports' => [
                'title' => 'Bank Ledger Reports', 
                'icon' => 'bi-journal-bookmark-fill', 
                'url' => 'Bank-Ledger-Reports.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-expiring-loans' => [
                'title' => 'Bank Expiring Loans', 
                'icon' => 'bi-clock-history', 
                'url' => 'Bank-Expiring-Loans.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-outstanding-loan-reports' => [
                'title' => 'Bank Outstanding Loan Reports', 
                'icon' => 'bi-pie-chart', 
                'url' => 'Bank-Outstanding-Loan-Reports.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-stocks' => [
                'title' => 'Bank Stocks', 
                'icon' => 'bi-bar-chart', 
                'url' => 'Bank-Stocks.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-interest-difference' => [
                'title' => 'Bank Interest Difference', 
                'icon' => 'bi-percent', 
                'url' => 'Bank-Interest-Difference.php', 
                'roles' => ['admin', 'sale']
            ],
            'closed-receipt-in-bank' => [
                'title' => 'Closed Receipt In Bank', 
                'icon' => 'bi-archive', 
                'url' => 'Closed-Receipt-In-Bank.php', 
                'roles' => ['admin', 'sale']
            ],
            'bank-loan-reports' => [
                'title' => 'Bank Loan Reports', 
                'icon' => 'bi-file-earmark-bar-graph', 
                'url' => 'Bank-Loan-Reports.php', 
                'roles' => ['admin', 'sale']
            ],
            'closed-bank-loan-reports' => [
                'title' => 'Closed Bank Loan Reports', 
                'icon' => 'bi-file-earmark-check', 
                'url' => 'Closed-Bank-Loan-Reports.php', 
                'roles' => ['admin', 'sale']
            ]
        ]
    ],
    
    'master' => [
        'label' => 'MASTER',
        'items' => [
            'product-type' => ['title' => 'Product Type', 'icon' => 'bi-tags', 'url' => 'Product-Type.php', 'roles' => ['admin']],
            'product-name' => ['title' => 'Product Name', 'icon' => 'bi-jewel', 'url' => 'Product-Name.php', 'roles' => ['admin']],
            'karat-details' => ['title' => 'Karat Details', 'icon' => 'bi-gem', 'url' => 'Karat-Details.php', 'roles' => ['admin']],
            'defect-details' => ['title' => 'Defect Details', 'icon' => 'bi-exclamation-diamond', 'url' => 'Defect-Details.php', 'roles' => ['admin']],
            'stone-details' => ['title' => 'Stone Details', 'icon' => 'bi-shield-check', 'url' => 'Stone-Details.php', 'roles' => ['admin']],
        ]
    ],
    
    'auction_details' => [
        'label' => 'AUCTION DETAILS',
        'items' => [
            'auctioneer-details' => ['title' => 'Auctioneer Details', 'icon' => 'bi-person-badge-fill', 'url' => 'Auctioneer-Details.php', 'roles' => ['admin']],
        ]
    ],
    
    'reports' => [
        'label' => 'REPORTS',
        'items' => [
            'overall-collection-reports' => [
                'title' => 'Overall Collection Reports', 
                'icon' => 'bi-calendar-day', 
                'url' => 'overall-collection-reports.php', 
                'roles' => ['admin', 'sale', 'manager', 'accountant']
            ],
            'daily-reports' => [
                'title' => 'Daily Reports', 
                'icon' => 'bi-calendar-day', 
                'url' => 'Daily-Reports.php', 
                'roles' => ['admin', 'sale', 'manager', 'accountant']
            ],
            'weekly-reports' => [
                'title' => 'Weekly Reports', 
                'icon' => 'bi-calendar-week', 
                'url' => 'weekly-Reports.php', 
                'roles' => ['admin', 'sale', 'manager', 'accountant']
            ],
            'monthly-reports' => [
                'title' => 'Monthly Reports', 
                'icon' => 'bi-calendar-month', 
                'url' => 'monthly-Reports.php', 
                'roles' => ['admin', 'sale', 'manager', 'accountant']
            ],
            'profit-reports' => [
                'title' => 'Profit Reports', 
                'icon' => 'bi-graph-up-arrow', 
                'url' => 'Profit-Reports.php', 
                'roles' => ['admin', 'manager', 'accountant']
            ],
            'principal-reports' => [
                'title' => 'Principal Reports', 
                'icon' => 'bi-cash-stack', 
                'url' => 'Principle-Reports.php', 
                'roles' => ['admin', 'manager', 'accountant']
            ],
            'interest-reports' => [
                'title' => 'Interest Reports', 
                'icon' => 'bi-percent', 
                'url' => 'Intrest-Reports.php', 
                'roles' => ['admin', 'manager', 'accountant']
            ],
        ]
    ],
    
    'notes' => [
        'label' => 'NOTES',
        'items' => [
            'upcoming-collections' => ['title' => 'Upcoming Collections', 'icon' => 'bi-journal-text', 'url' => 'upcoming-collections.php', 'roles' => ['admin', 'sale']],
            'loan-notes' => ['title' => 'Loan Notes', 'icon' => 'bi-journal-text', 'url' => 'Loan-Notes.php', 'roles' => ['admin', 'sale']],
            'loan-receipt-notes' => ['title' => 'Loan Receipt Notes', 'icon' => 'bi-journal-text', 'url' => 'loan-receipt-notes.php', 'roles' => ['admin', 'sale']],
            'close-loan-receipt-notes' => ['title' => 'Close Loan Receipt Notes', 'icon' => 'bi-journal-text', 'url' => 'close-loan-receipt-notes.php', 'roles' => ['admin', 'sale']],
        ]
    ],
    
    'admin' => [
        'label' => 'ADMINISTRATION',
        'items' => [
            'bank-accounts' => [
                'title' => 'Bank Accounts', 
                'icon' => 'bi-building', 
                'url' => 'Bank-Accounts.php', 
                'roles' => ['admin']
            ],
            'company-details' => [
                'title' => 'Company Details', 
                'icon' => 'bi-building', 
                'url' => 'company-settings.php', 
                'roles' => ['admin']
            ],
            'branch-management' => [
                'title' => 'Branch Management', 
                'icon' => 'bi-diagram-3', 
                'url' => 'branch-management.php', 
                'roles' => ['admin']
            ],
            'user-details' => [
                'title' => 'User Details', 
                'icon' => 'bi-people-fill', 
                'url' => 'User-Details.php', 
                'roles' => ['admin']
            ],
            'user-rights' => [
                'title' => 'User Rights & Permissions', 
                'icon' => 'bi-shield-lock', 
                'url' => 'user-rights.php', 
                'roles' => ['admin']
            ],
            'activity-logs' => [
                'title' => 'Activity Logs', 
                'icon' => 'bi-clock-history', 
                'url' => 'activity-logs.php', 
                'roles' => ['admin']
            ],
            'backup' => [
                'title' => 'Backup & Restore', 
                'icon' => 'bi-database', 
                'url' => 'backup.php', 
                'roles' => ['admin']
            ],
            'email-settings' => [
                'title' => 'Email Settings', 
                'icon' => 'bi-envelope', 
                'url' => 'email-settings.php', 
                'roles' => ['admin']
            ],
            'notification-settings' => [
                'title' => 'Notification Settings', 
                'icon' => 'bi-bell', 
                'url' => 'notification-settings.php', 
                'roles' => ['admin']
            ],
            'system-health' => [
                'title' => 'System Health', 
                'icon' => 'bi-heart-pulse', 
                'url' => 'system-health.php', 
                'roles' => ['admin']
            ],
            'cache-clear' => [
                'title' => 'Clear Cache', 
                'icon' => 'bi-trash', 
                'url' => 'cache-clear.php', 
                'roles' => ['admin']
            ],
            'logout' => [
                'title' => 'Logout',
                'icon' => 'bi-box-arrow-right',
                'url' => 'logout.php',
                'roles' => ['admin', 'sale']
            ]
        ]
    ]
];

// Filter menu items based on user role
function filterMenuByRole($menu_items, $user_role) {
    $filtered = [];
    foreach ($menu_items as $key => $section) {
        $filtered_items = [];
        foreach ($section['items'] as $item_key => $item) {
            if (in_array($user_role, $item['roles'])) {
                $filtered_items[$item_key] = $item;
            }
        }
        if (!empty($filtered_items)) {
            $filtered[$key] = [
                'label' => $section['label'],
                'items' => $filtered_items
            ];
        }
    }
    return $filtered;
}

$filtered_menu = filterMenuByRole($menu_items, $user_role);

// Check if any item in section is active (for auto-open collapse)
function sectionHasActive($section, $current_page) {
    foreach ($section['items'] as $item) {
        if ($current_page === basename($item['url'])) {
            return true;
        }
    }
    return false;
}
?>

<!-- Sidebar Overlay (for mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- Sidebar Navigation -->
<nav class="sidebar" id="sidebar">
    <!-- Brand / Logo -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <img src="assets/wealthrot.png" alt="Logo" style="width: 45px; height: 45px; object-fit: contain;">
        </div>
        <div>
            <h2 style="font-size: 22px;">WEALTHROT</h2>
            
        </div>
    </div>

    <!-- Navigation Menu with Increased Font Sizes -->
    <div class="sidebar-nav">
        <?php foreach ($filtered_menu as $section_key => $section): 
            $is_open = sectionHasActive($section, $current_page) ? 'show' : '';
            $expanded = sectionHasActive($section, $current_page) ? 'true' : 'false';
        ?>
            <div class="sidebar-section">
                <a href="#collapse-<?php echo $section_key; ?>" 
                   class="sidebar-label dropdown-toggle <?php echo $is_open ? 'active' : ''; ?>" 
                   data-bs-toggle="collapse" 
                   aria-expanded="<?php echo $expanded; ?>"
                   aria-controls="collapse-<?php echo $section_key; ?>"
                   style="font-size: 15px; font-weight: 700; letter-spacing: 0.5px; padding: 15px 20px;">
                    <?php echo $section['label']; ?>
                    <i class="bi bi-chevron-down ms-auto" style="font-size: 14px;"></i>
                </a>

                <div class="collapse <?php echo $is_open; ?>" id="collapse-<?php echo $section_key; ?>">
                    <?php foreach ($section['items'] as $item_key => $item): 
                        $is_active = ($current_page === basename($item['url'])) ? 'active' : '';
                    ?>
                        <a href="<?php echo $item['url']; ?>" 
                           class="nav-link <?php echo $is_active; ?>"
                           style="font-size: 14px; padding: 12px 20px 12px 45px;">
                            <i class="bi <?php echo $item['icon']; ?>" style="font-size: 16px;"></i>
                            <span><?php echo $item['title']; ?></span>
                            <?php if (isset($item['badge']) && $item['badge'] > 0): ?>
                                <span class="badge <?php echo $item['badge_class'] ?? 'bg-danger'; ?>" style="font-size: 11px; padding: 4px 8px;"><?php echo $item['badge']; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- User Footer with Increased Font Sizes -->
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar" style="width: 45px; height: 45px; font-size: 20px;"><?php echo $initials; ?></div>
            <div>
                <div class="user-name" style="font-size: 15px;"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="user-role" style="font-size: 13px;"><?php echo ucfirst($user_role); ?></div>
            </div>
        </div>
    </div>

    <!-- Compact Toggle -->
    <button class="sidebar-toggle-compact" onclick="toggleCompactSidebar()" style="font-size: 14px; padding: 12px;">
        <i class="bi bi-layout-sidebar-inset" id="compactIcon" style="font-size: 16px;"></i>
        <span>Compact View</span>
    </button>
</nav>

<style>
/* Additional styles for increased font sizes and removed duplicates */
.sidebar {
    width: 250px;
    background: linear-gradient(180deg, #2d3748 0%, #1a202c 100%);
    color: white;
    transition: all 0.3s;
    margin-top: 0px;
}

.sidebar-brand {
    padding: 25px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-label {
    display: flex;
    align-items: center;
    color: rgba(255,255,255,0.8) !important;
    text-decoration: none;
    transition: all 0.3s;
}

.sidebar-label:hover {
    color: white !important;
    background: rgba(255,255,255,0.1);
}

.sidebar-label.active {
    color: white !important;
    background: rgba(255,255,255,0.15);
    border-left: 4px solid #667eea;
}

.nav-link {
    display: flex;
    align-items: center;
    color: rgba(255,255,255,0.7) !important;
    text-decoration: none;
    transition: all 0.3s;
    position: relative;
}

.nav-link:hover {
    color: white !important;
    background: rgba(255,255,255,0.1);
}

.nav-link.active {
    color: white !important;
    background: rgba(255,255,255,0.15);
    border-left: 4px solid #667eea;
}

.nav-link i {
    margin-right: 12px;
    width: 20px;
    text-align: center;
}

.badge {
    margin-left: auto;
    border-radius: 30px;
    font-weight: 600;
}

.sidebar-footer {
    border-top: 1px solid rgba(255,255,255,0.1);
    padding: 20px;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
}

.sidebar-toggle-compact {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: none;
    color: rgba(255,255,255,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.3s;
    border-top: 1px solid rgba(255,255,255,0.1);
}

.sidebar-toggle-compact:hover {
    background: rgba(255,255,255,0.1);
    color: white;
}

/* Collapse animations */
.collapse {
    transition: all 0.3s ease;
}

/* Mobile overlay */
.sidebar-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 999;
}

@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        left: -280px;
        top: 0;
        bottom: 0;
        z-index: 1000;
    }
    
    .sidebar.show {
        left: 0;
    }
    
    .sidebar-overlay.show {
        display: block;
    }
}
</style>