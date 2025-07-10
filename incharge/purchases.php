<?php
session_start();
$page_title = "Material Purchases";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get fund status
try {
    $fund_query = "SELECT 
                    SUM(balance) as total_balance,
                    SUM(amount) as total_allocated,
                    (SELECT COALESCE(SUM(amount), 0) FROM fund_usage WHERE used_by = ?) as total_used
                FROM funds 
                WHERE to_user_id = ? AND status = 'active'";
    $fund_stmt = $db->prepare($fund_query);
    $fund_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $fund_status = $fund_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate available funds and overdraft
    $fund_status['available'] = $fund_status['total_balance'] ?? 0;
    $fund_status['overdraft'] = max(0, ($fund_status['total_used'] ?? 0) - ($fund_status['total_allocated'] ?? 0));
} catch (PDOException $e) {
    error_log("Error in fund status query: " . $e->getMessage());
    $fund_status = [
        'available' => 0,
        'total_used' => 0,
        'total_allocated' => 0,
        'overdraft' => 0
    ];
}

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$material_filter = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;

// Build where clause for filtering
$where_clause = "";
$params = [];

if (!empty($search)) {
    $where_clause .= " AND (m.name LIKE ? OR p.vendor_name LIKE ? OR p.invoice_number LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($date_from)) {
    $where_clause .= " AND p.purchase_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_clause .= " AND p.purchase_date <= ?";
    $params[] = $date_to;
}

if ($material_filter > 0) {
    $where_clause .= " AND m.id = ?";
    $params[] = $material_filter;
}

// Get total records for pagination
try {
    $count_query = "SELECT COUNT(*) as total FROM purchases p 
                   JOIN raw_materials m ON p.material_id = m.id 
                   WHERE 1=1 {$where_clause}";
    $count_stmt = $db->prepare($count_query);
    if (!empty($params)) {
        foreach ($params as $i => $param) {
            $count_stmt->bindValue($i + 1, $param);
        }
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);
} catch (PDOException $e) {
    error_log("Error in count query: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
}

// Get purchases with pagination
try {
    $query = "SELECT p.id, m.name as material_name, p.quantity, m.unit, p.unit_price, 
              p.total_amount, p.vendor_name, p.invoice_number, p.purchase_date, u.username as purchased_by
              FROM purchases p 
              JOIN raw_materials m ON p.material_id = m.id 
              JOIN users u ON p.purchased_by = u.id
              WHERE 1=1 {$where_clause}
              ORDER BY p.purchase_date DESC 
              LIMIT ?, ?";
    
    $stmt = $db->prepare($query);
    
    // Bind parameters for filtering
    $param_index = 1;
    foreach ($params as $param) {
        $stmt->bindValue($param_index++, $param);
    }
    
    // Bind pagination parameters
    $stmt->bindValue($param_index++, $offset, PDO::PARAM_INT);
    $stmt->bindValue($param_index++, $records_per_page, PDO::PARAM_INT);
    $stmt->execute();
} catch (PDOException $e) {
    error_log("Error in purchases query: " . $e->getMessage());
    $stmt = null;
}

// Get materials for filter dropdown
try {
    $materials_query = "SELECT id, name FROM raw_materials ORDER BY name";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->execute();
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in materials query: " . $e->getMessage());
    $materials = [];
}

// Check for success message from redirect
$success_message = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['success']);

// Check for error message from redirect
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['error']);
?>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading-indicator" style="display: none;">
    <div class="spinner"></div>
    <p>Loading...</p>
</div>

<div class="page-header">
    <h1 class="page-title">Material Purchases</h1>
    <div class="page-actions">
        <a href="add-purchase.php" class="button primary">
            <i class="fas fa-plus-circle" aria-hidden="true"></i> Add New Purchase
        </a>
    </div>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success" role="alert">
    <i class="fas fa-check-circle" aria-hidden="true"></i>
    <span><?php echo $success_message; ?></span>
    <button type="button" class="close-alert" aria-label="Close alert">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error" role="alert">
    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
    <span><?php echo $error_message; ?></span>
    <button type="button" class="close-alert" aria-label="Close alert">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
<?php endif; ?>

<!-- Fund Status Panel -->
<div class="fund-status-panel <?php echo $fund_status['overdraft'] > 0 ? 'has-warning' : ''; ?>">
    <div class="fund-status-item">
        <div class="status-icon">
            <i class="fas fa-wallet" aria-hidden="true"></i>
        </div>
        <div class="status-content">
            <div class="status-label">Available Funds</div>
            <div class="status-value"><?php echo formatCurrency($fund_status['available']); ?></div>
        </div>
    </div>
    
    <div class="fund-status-item">
        <div class="status-icon">
            <i class="fas fa-hand-holding-usd" aria-hidden="true"></i>
        </div>
        <div class="status-content">
            <div class="status-label">Used Funds</div>
            <div class="status-value"><?php echo formatCurrency($fund_status['total_used'] ?? 0); ?></div>
        </div>
    </div>
    
    <?php if($fund_status['overdraft'] > 0): ?>
    <div class="fund-status-item warning">
        <div class="status-icon">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
        </div>
        <div class="status-content">
            <div class="status-label">Overdraft</div>
            <div class="status-value"><?php echo formatCurrency($fund_status['overdraft']); ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="fund-status-actions">
        <a href="funds.php" class="button small">
            <i class="fas fa-external-link-alt" aria-hidden="true"></i> Manage Funds
        </a>
    </div>
</div>

<!-- Filter Section -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Material name, vendor, invoice...">
                    <button type="submit" class="search-button" aria-label="Search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-group">
                <label for="material_id">Material:</label>
                <select id="material_id" name="material_id">
                    <option value="">All Materials</option>
                    <?php foreach($materials as $material): ?>
                    <option value="<?php echo $material['id']; ?>" <?php echo $material_filter == $material['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($material['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="date_from">From Date:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="filter-group">
                <label for="date_to">To Date:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="button">
                    <i class="fas fa-filter" aria-hidden="true"></i> Apply Filters
                </button>
                <a href="purchases.php" class="button secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2>Material Purchases</h2>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> records
        </div>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table responsive" id="purchasesTable" aria-label="Material Purchases">
                <thead>
                    <tr>
                        <th scope="col">Purchase Date</th>
                        <th scope="col">Material</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Unit Price</th>
                        <th scope="col">Total Amount</th>
                        <th scope="col">Vendor</th>
                        <th scope="col">Invoice #</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($stmt && $stmt->rowCount() > 0): ?>
                        <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td data-label="Purchase Date"><?php echo formatDate($row['purchase_date']); ?></td>
                            <td data-label="Material"><?php echo htmlspecialchars($row['material_name']); ?></td>
                            <td data-label="Quantity"><?php echo number_format($row['quantity'], 2) . ' ' . htmlspecialchars($row['unit']); ?></td>
                            <td data-label="Unit Price" class="amount-cell"><?php echo formatCurrency($row['unit_price']); ?></td>
                            <td data-label="Total Amount" class="amount-cell"><?php echo formatCurrency($row['total_amount']); ?></td>
                            <td data-label="Vendor"><?php echo htmlspecialchars($row['vendor_name']); ?></td>
                            <td data-label="Invoice #"><?php echo htmlspecialchars($row['invoice_number'] ?: 'N/A'); ?></td>
                            <td data-label="Actions">
                                <div class="action-buttons">
                                    <a href="view-purchase.php?id=<?php echo $row['id']; ?>" class="button small">
                                        <i class="fas fa-eye" aria-hidden="true"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="no-data">No purchases found. <?php echo !empty($where_clause) ? 'Try adjusting your filters.' : 'Click "Add New Purchase" to add one.'; ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <nav class="pagination-container" aria-label="Purchases pagination">
            <ul class="pagination">
                <?php if($page > 1): ?>
                    <li class="pagination-item">
                        <a href="?page=1<?php echo buildQueryString(['page']); ?>" class="pagination-link" aria-label="Go to first page">
                            <i class="fas fa-angle-double-left" aria-hidden="true"></i> First
                        </a>
                    </li>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $page - 1; ?><?php echo buildQueryString(['page']); ?>" class="pagination-link" aria-label="Go to previous page">
                            <i class="fas fa-angle-left" aria-hidden="true"></i> Previous
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php
                // Determine the range of page numbers to display
                $range = 2; // Number of pages to show on either side of the current page
                $start_page = max(1, $page - $range);
                $end_page = min($total_pages, $page + $range);
                
                // Always show first page button
                if($start_page > 1) {
                    echo '<li class="pagination-item">';
                    echo '<a href="?page=1' . buildQueryString(['page']) . '" class="pagination-link">1</a>';
                    echo '</li>';
                    if($start_page > 2) {
                        echo '<li class="pagination-item pagination-ellipsis">...</li>';
                    }
                }
                
                // Display the range of pages
                for($i = $start_page; $i <= $end_page; $i++) {
                    echo '<li class="pagination-item' . ($i == $page ? ' active' : '') . '">';
                    if($i == $page) {
                        echo '<span class="pagination-link current" aria-current="page">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . buildQueryString(['page']) . '" class="pagination-link">' . $i . '</a>';
                    }
                    echo '</li>';
                }
                
                // Always show last page button
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<li class="pagination-item pagination-ellipsis">...</li>';
                    }
                    echo '<li class="pagination-item">';
                    echo '<a href="?page=' . $total_pages . buildQueryString(['page']) . '" class="pagination-link">' . $total_pages . '</a>';
                    echo '</li>';
                }
                ?>
                
                <?php if($page < $total_pages): ?>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $page + 1; ?><?php echo buildQueryString(['page']); ?>" class="pagination-link" aria-label="Go to next page">
                            Next <i class="fas fa-angle-right" aria-hidden="true"></i>
                        </a>
                    </li>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $total_pages; ?><?php echo buildQueryString(['page']); ?>" class="pagination-link" aria-label="Go to last page">
                            Last <i class="fas fa-angle-double-right" aria-hidden="true"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
:root {
    --primary: #1a73e8;
    --primary-dark: #0d47a1;
    --primary-light: #e8f0fe;
    --secondary: #5f6368;
    --success: #0f9d58;
    --warning: #f4b400;
    --error: #d93025;
    --error-light: #fee8e7;
    --surface: #ffffff;
    --background: #f8f9fa;
    --border: #dadce0;
    --text-primary: #202124;
    --text-secondary: #5f6368;
    --text-disabled: #9aa0a6;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-md: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-xxl: 1.5rem;
    --border-radius-sm: 4px;
    --border-radius-md: 8px;
    --border-radius-lg: 12px;
    --shadow-sm: 0 1px 2px rgba(60, 64, 67, 0.3), 0 1px 3px 1px rgba(60, 64, 67, 0.15);
    --shadow-md: 0 2px 6px rgba(60, 64, 67, 0.3), 0 1px 8px 1px rgba(60, 64, 67, 0.15);
    --shadow-lg: 0 4px 12px rgba(60, 64, 67, 0.3), 0 1px 16px 2px rgba(60, 64, 67, 0.15);
    --transition-fast: 0.15s ease;
    --transition-normal: 0.25s ease;
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
}

/* General Page Structure */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
}

.page-title {
    font-size: var(--font-size-xxl);
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
}

.page-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* Button Component */
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-xs);
    padding: 0.5rem 1rem;
    border-radius: var(--border-radius-sm);
    font-weight: 500;
    font-size: var(--font-size-sm);
    cursor: pointer;
    transition: all var(--transition-fast);
    border: none;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}

.button::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 5px;
    height: 5px;
    background: rgba(255, 255, 255, 0.5);
    opacity: 0;
    border-radius: 100%;
    transform: scale(1, 1) translate(-50%, -50%);
    transform-origin: 50% 50%;
}

.button:active::after {
    animation: ripple 0.6s ease-out;
}

@keyframes ripple {
    0% {
        transform: scale(0, 0);
        opacity: 0.5;
    }
    100% {
        transform: scale(100, 100);
        opacity: 0;
    }
}

.button.primary {
    background-color: var(--primary);
    color: white;
    box-shadow: 0 1px 2px rgba(60, 64, 67, 0.3);
}

.button.primary:hover, .button.primary:focus {
    background-color: #1765cc;
    box-shadow: 0 1px 3px rgba(60, 64, 67, 0.4);
}

.button.primary:active {
    background-color: #165fc9;
    box-shadow: 0 1px 2px rgba(60, 64, 67, 0.2);
}

.button.secondary {
    background-color: var(--background);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}

.button.secondary:hover, .button.secondary:focus {
    background-color: #f1f3f4;
    color: var(--text-primary);
}

.button.secondary:active {
    background-color: #e8eaed;
}

.button.small {
    padding: 0.25rem 0.5rem;
    font-size: var(--font-size-xs);
}

.button[disabled] {
    opacity: 0.7;
    cursor: not-allowed;
}

/* Fund Status Panel */
.fund-status-panel {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-lg);
    margin-bottom: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.fund-status-panel.has-warning {
    background-color: rgba(244, 180, 0, 0.05);
    border-left: 4px solid var(--warning);
}

.fund-status-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    flex: 1;
    min-width: 200px;
}

.fund-status-item.warning .status-value {
    color: var(--warning);
}

.status-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--border-radius-md);
    background-color: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
}

.fund-status-item.warning .status-icon {
    background-color: rgba(244, 180, 0, 0.15);
    color: var(--warning);
}

.status-content {
    display: flex;
    flex-direction: column;
}

.status-label {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-bottom: var(--spacing-xs);
}

.status-value {
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--text-primary);
}

.fund-status-actions {
    display: flex;
    align-items: center;
    margin-left: auto;
}

/* Alert Component */
.alert {
    display: flex;
    align-items: flex-start;
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
    animation: fadeIn 0.3s ease-in-out;
}

.alert-success {
    background-color: rgba(15, 157, 88, 0.1);
    border-left: 4px solid var(--success);
    color: #0a7d45;
}

.alert-error {
    background-color: var(--error-light);
    border-left: 4px solid var(--error);
    color: var(--error);
}

.alert i {
    flex-shrink: 0;
    margin-right: var(--spacing-md);
    font-size: var(--font-size-lg);
}

.alert-content {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    color: inherit;
    font-size: var(--font-size-xl);
    cursor: pointer;
    opacity: 0.7;
    transition: opacity var(--transition-fast);
    padding: 0;
    margin-left: var(--spacing-md);
}

.close-alert:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Filter Container */
.filter-container {
    margin-bottom: var(--spacing-lg);
}

.filter-form {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
    box-shadow: var(--shadow-sm);
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: var(--spacing-xs);
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text-secondary);
}

.filter-group select, 
.filter-group input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    background-color: var(--surface);
    transition: all var(--transition-fast);
}

.filter-group select:focus, 
.filter-group input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

.search-input-container {
    position: relative;
}

.search-input-container input {
    padding-right: 2.5rem;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    background: none;
    border: none;
    width: 2.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    transition: color var(--transition-fast);
}

.search-button:hover {
    color: var(--primary);
}

.filter-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* Card Component */
.card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-lg);
    overflow: hidden;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-md);
    border-bottom: 1px solid var(--border);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 500;
    color: var(--text-primary);
}

.pagination-info {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
}

.card-content {
    padding: var(--spacing-md);
}

/* Table Component */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--font-size-sm);
}

.data-table th, 
.data-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.data-table th {
    font-weight: 500;
    color: var(--text-secondary);
    background-color: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table tbody tr:hover {
    background-color: #f1f3f4;
}

.data-table.responsive td {
    position: relative;
}

.amount-cell {
    text-align: right;
    font-weight: 500;
    color: var(--primary);
}

.action-buttons {
    display: flex;
    gap: var(--spacing-xs);
    justify-content: flex-end;
}

.no-data {
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--text-secondary);
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    font-style: italic;
}

/* Pagination */
.pagination-container {
    margin-top: var(--spacing-lg);
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.25rem;
    margin-top: var(--spacing-lg);
}

.pagination-item {
    list-style: none;
    margin: 0;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.5rem;
    border-radius: var(--border-radius-sm);
    text-decoration: none;
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    background-color: var(--surface);
    border: 1px solid var(--border);
    transition: all var(--transition-fast);
}

.pagination-link:hover, 
.pagination-link:focus {
    background-color: #f1f3f4;
    border-color: #d2d6dc;
}

.pagination-link.current {
    background-color: var(--primary);
    color: white;
    border-color: var(--primary);
    font-weight: 500;
}

.pagination-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    color: var(--text-secondary);
}

/* Toast Container */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Loading Indicator */
.loading-indicator {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.8);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.spinner {
    border: 4px solid rgba(0, 0, 0, 0.1);
    border-radius: 50%;
    border-top: 4px solid var(--primary);
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
    margin-bottom: var(--spacing-md);
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .filter-row {
        flex-direction: column;
        gap: var(--spacing-md);
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .filter-actions .button {
        width: 100%;
    }
    
    .fund-status-panel {
        flex-direction: column;
    }
    
    .fund-status-actions {
        margin-left: 0;
        width: 100%;
    }
    
    .fund-status-actions .button {
        width: 100%;
    }
    
    .data-table.responsive thead {
        display: none;
    }
    
    .data-table.responsive tr {
        display: block;
        border: 1px solid var(--border);
        border-radius: var(--border-radius-md);
        margin-bottom: var(--spacing-md);
        padding: var(--spacing-sm);
    }
    
    .data-table.responsive td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: var(--spacing-xs) var(--spacing-sm);
        border-bottom: none;
        text-align: right;
    }
    
    .data-table.responsive td::before {
        content: attr(data-label);
        font-weight: 500;
        color: var(--text-secondary);
        margin-right: var(--spacing-md);
        text-align: left;
    }
    
    .action-buttons {
        justify-content: flex-start;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }
    
    .page-actions {
        width: 100%;
    }
    
    .page-actions .button {
        width: 100%;
    }
    
    .pagination {
        gap: var(--spacing-xs);
    }
    
    .pagination-link {
        min-width: 1.75rem;
        height: 1.75rem;
        font-size: var(--font-size-xs);
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .alert,
    .button::after,
    .spinner,
    .pagination-link,
    .filter-group select:focus, 
    .filter-group input:focus {
        animation: none !important;
        transition: none !important;
    }
}

/* Focus styles for keyboard navigation */
.button:focus,
input:focus,
select:focus,
textarea:focus,
.pagination-link:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.button:focus:not(:focus-visible),
input:focus:not(:focus-visible),
select:focus:not(:focus-visible),
textarea:focus:not(:focus-visible),
.pagination-link:focus:not(:focus-visible) {
    outline: none;
}
</style>
<script src="../assets/js/utils.js"></script>
<script>
    /**
 * Purchases Page JavaScript
 * Handles purchases listing functionality with filters and pagination
 */
document.addEventListener('DOMContentLoaded', function() {
  // Initialize components
  initAlertCloseButtons();
  initDateRangeValidation();
  makeTableResponsive('purchasesTable');
  
  // Log page view
  logUserActivity('read', 'purchases', 'Viewed purchases list');
  
  /**
   * Initialize close buttons for alerts
   */
  function initAlertCloseButtons() {
    document.querySelectorAll('.close-alert').forEach(button => {
      button.addEventListener('click', function() {
        this.closest('.alert').remove();
      });
    });
  }
  
  /**
   * Make sure the date range is valid
   */
  function initDateRangeValidation() {
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    
    if (dateFromInput && dateToInput) {
      dateFromInput.addEventListener('change', function() {
        if (dateToInput.value && this.value > dateToInput.value) {
          showToast('Invalid Date Range', 'From date cannot be later than To date', 'warning');
          this.value = dateToInput.value;
        }
      });
      
      dateToInput.addEventListener('change', function() {
        if (dateFromInput.value && this.value < dateFromInput.value) {
          showToast('Invalid Date Range', 'To date cannot be earlier than From date', 'warning');
          this.value = dateFromInput.value;
        }
      });
    }
  }
  
  /**
   * Make table responsive for mobile devices
   * @param {string} tableId - The ID of the table
   */
  function makeTableResponsive(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const headerCells = table.querySelectorAll('thead th');
    const headerTexts = Array.from(headerCells).map(cell => cell.textContent.trim());
    
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
      const cells = row.querySelectorAll('td');
      cells.forEach((cell, index) => {
        if (headerTexts[index]) {
          cell.setAttribute('data-label', headerTexts[index]);
        }
      });
    });
  }
});
</script>

<?php
// Helper function to build query string from current GET parameters
function buildQueryString($exclude = []) {
    $params = $_GET;
    foreach ($exclude as $param) {
        unset($params[$param]);
    }
    return !empty($params) ? '&' . http_build_query($params) : '';
}

// Helper function to format currency
function formatCurrency($amount, $currency = 'Rs.') {
    return $currency . number_format($amount, 2);
}

// Helper function to format date
function formatDate($dateString) {
    return date('M j, Y', strtotime($dateString));
}
?>

<?php include_once '../includes/footer.php'; ?>