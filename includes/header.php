<?php
// Check if user is logged in
if(!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// Get user role to ensure they're accessing the right pages
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$current_directory = basename(dirname($_SERVER['PHP_SELF']));

// Validate user is in the correct directory for their role
if($role !== $current_directory && $current_directory !== 'includes') {
    header('Location: ../' . $role . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Garment Manufacturing System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <script src="../assets/js/script.js" defer></script>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2>GMS</h2>
                <span class="close-sidebar" id="closeSidebar">×</span>
            </div>
            
            <div class="user-info">
                <div class="user-name"><?php echo $_SESSION['full_name']; ?></div>
                <div class="user-role"><?php echo ucfirst($_SESSION['role']); ?></div>
            </div>
            
            <nav class="sidebar-nav">
                <?php if($role === 'owner'): ?>
                    <a href="../owner/dashboard.php" class="nav-link">Dashboard</a>
                    <a href="../owner/financial.php" class="nav-link">Financial Overview</a>
                    <a href="../owner/manufacturing.php" class="nav-link">Manufacturing</a>
                    <a href="../owner/inventory.php" class="nav-link">Inventory</a>
                    <a href="../owner/sales.php" class="nav-link">Sales</a>
                    <a href="../owner/reports.php" class="nav-link">Reports</a>
                    <a href="../owner/activity-logs.php" class="nav-link">Activity Logs</a>
                    <a href="../owner/users.php" class="nav-link">User Management</a>
                <?php elseif($role === 'incharge'): ?>
                    <a href="../incharge/dashboard.php" class="nav-link">Dashboard</a>
                    <a href="../incharge/funds.php" class="nav-link">Fund Management</a>
                    <a href="../incharge/raw-materials.php" class="nav-link">Raw Materials</a>
                    <a href="../incharge/purchases.php" class="nav-link">Purchases</a>
                    <a href="../incharge/manufacturing.php" class="nav-link">Manufacturing</a>
                    <a href="../incharge/costs.php" class="nav-link">Cost Management</a>
                    <a href="../incharge/inventory.php" class="nav-link">Inventory</a>
                <?php elseif($role === 'shopkeeper'): ?>
                    <a href="../shopkeeper/dashboard.php" class="nav-link">Dashboard</a>
                    <a href="../shopkeeper/fund-returns.php" class="nav-link">Fund Returns</a>
                    <a href="../shopkeeper/inventory.php" class="nav-link">Inventory</a>
                    <a href="../shopkeeper/customers.php" class="nav-link">Customers</a>
                    <a href="../shopkeeper/sales.php" class="nav-link">Sales</a>
                    <a href="../shopkeeper/payments.php" class="nav-link">Payments</a>
                <?php endif; ?>
                <a href="../logout.php" class="nav-link logout">Logout</a>
            </nav>
        </aside>
        
        <main class="main-content">
            <header class="main-header">
                <button id="toggleSidebar" class="toggle-sidebar">☰</button>
                <h1 class="page-title"><?php echo isset($page_title) ? $page_title : 'Dashboard'; ?></h1>
                <div class="user-actions">
                    <span class="user-greeting">Hello, <?php echo $_SESSION['full_name']; ?></span>
                </div>
            </header>
            
            <div class="content-wrapper">