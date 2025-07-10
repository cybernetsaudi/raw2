<?php
session_start();
$page_title = "Inventory Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get query parameters for filtering
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$location_filter = isset($_GET['location']) ? $_GET['location'] : 'manufacturing'; // Default to manufacturing

// Set up pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $records_per_page;

// Build where clause for filtering
$where_clause = "WHERE i.location = :location";
$params = [':location' => $location_filter];

if(!empty($search)) {
    $where_clause .= " AND (p.name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Get total records for pagination
try {
    $count_query = "SELECT COUNT(*) as total 
                   FROM inventory i
                   JOIN products p ON i.product_id = p.id
                   $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach($params as $param => $value) {
        $count_stmt->bindValue($param, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);
} catch (PDOException $e) {
    error_log("Count query error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
}

// Get inventory items with pagination and filters
try {
    $inventory_query = "SELECT i.id, p.id as product_id, p.name as product_name, p.sku, i.quantity, i.location, 
                       i.updated_at, COALESCE(mb.batch_number, 'N/A') as batch_number,
                       COALESCE(mb.id, 0) as batch_id
                       FROM inventory i 
                       JOIN products p ON i.product_id = p.id
                       LEFT JOIN manufacturing_batches mb ON p.id = mb.product_id AND mb.status = 'completed'
                       $where_clause
                       GROUP BY i.id
                       ORDER BY i.updated_at DESC
                       LIMIT :offset, :records_per_page";
    $inventory_stmt = $db->prepare($inventory_query);
    foreach($params as $param => $value) {
        $inventory_stmt->bindValue($param, $value);
    }
    $inventory_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $inventory_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
    $inventory_stmt->execute();
} catch (PDOException $e) {
    error_log("Inventory query error: " . $e->getMessage());
    $inventory_stmt = null;
}

// Get locations for dropdown
$locations = ['manufacturing', 'wholesale', 'transit'];

// Get inventory summary by location
try {
    $summary_query = "SELECT location, SUM(quantity) as total_quantity, COUNT(DISTINCT product_id) as product_count
                     FROM inventory
                     GROUP BY location";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->execute();
    $inventory_summary = [];
    while($row = $summary_stmt->fetch(PDO::FETCH_ASSOC)) {
        $inventory_summary[$row['location']] = $row;
    }
} catch (PDOException $e) {
    error_log("Summary query error: " . $e->getMessage());
    $inventory_summary = [];
}

// Get inventory timeline data for the chart
try {
    $timeline_query = "SELECT DATE(t.transfer_date) as date, t.from_location, t.to_location, SUM(t.quantity) as quantity
                      FROM inventory_transfers t
                      WHERE t.transfer_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      GROUP BY DATE(t.transfer_date), t.from_location, t.to_location
                      ORDER BY t.transfer_date";
    $timeline_stmt = $db->prepare($timeline_query);
    $timeline_stmt->execute();
    $timeline_data = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Timeline query error: " . $e->getMessage());
    $timeline_data = [];
}

// Get recent inventory transfers
try {
    $transfers_query = "SELECT t.id, p.name as product_name, t.quantity, t.from_location, 
                       t.to_location, t.transfer_date, t.status, u.username as initiated_by_user
                       FROM inventory_transfers t
                       JOIN products p ON t.product_id = p.id
                       JOIN users u ON t.initiated_by = u.id
                       ORDER BY t.transfer_date DESC
                       LIMIT 5";
    $transfers_stmt = $db->prepare($transfers_query);
    $transfers_stmt->execute();
} catch (PDOException $e) {
    error_log("Transfers query error: " . $e->getMessage());
    $transfers_stmt = null;
}

// Check for transfer success message from redirect
$transfer_success = isset($_GET['transfer']) && $_GET['transfer'] === 'success';
?>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading-indicator" style="display: none;">
    <div class="spinner"></div>
    <p>Loading...</p>
</div>

<div class="page-header">
    <h1 class="page-title">Inventory Management</h1>
    <div class="page-actions">
        <button id="initiateTransferBtn" class="button primary">
            <i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer Inventory
        </button>
    </div>
</div>

<?php if ($transfer_success): ?>
<div class="alert alert-success" role="alert">
    <i class="fas fa-check-circle" aria-hidden="true"></i>
    <span>Inventory transfer initiated successfully</span>
    <button type="button" class="close-alert" aria-label="Close alert">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
<?php endif; ?>

<!-- Inventory Summary Cards -->
<div class="inventory-summary">
    <?php foreach ($locations as $location): ?>
        <?php 
        $locationName = ucfirst($location);
        $isActive = $location === $location_filter;
        $totalQuantity = $inventory_summary[$location]['total_quantity'] ?? 0;
        $productCount = $inventory_summary[$location]['product_count'] ?? 0;
        ?>
        <a href="?location=<?php echo $location; ?>" class="summary-card <?php echo $isActive ? 'active' : ''; ?>" aria-current="<?php echo $isActive ? 'page' : 'false'; ?>">
            <div class="summary-icon">
                <?php if ($location === 'manufacturing'): ?>
                    <i class="fas fa-industry" aria-hidden="true"></i>
                <?php elseif ($location === 'transit'): ?>
                    <i class="fas fa-truck" aria-hidden="true"></i>
                <?php elseif ($location === 'wholesale'): ?>
                    <i class="fas fa-warehouse" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div class="summary-details">
                <h3><?php echo $locationName; ?></h3>
                <div class="summary-stats">
                    <div class="stat">
                        <span class="stat-value"><?php echo number_format($totalQuantity); ?></span>
                        <span class="stat-label">Items</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value"><?php echo number_format($productCount); ?></span>
                        <span class="stat-label">Products</span>
                    </div>
                </div>
            </div>
            <?php if ($isActive): ?>
                <div class="active-indicator" aria-hidden="true"></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Inventory Timeline Chart -->
<div class="card full-width">
    <div class="card-header">
        <h2>Inventory Movement (Last 30 Days)</h2>
    </div>
    <div class="card-content">
        <div class="inventory-timeline">
            <canvas id="inventoryTimelineChart" height="300" aria-label="Inventory movement over the last 30 days" role="img"></canvas>
            <div id="chartFallback" class="chart-fallback" style="display: none;">
                <p>Chart data visualization is not available. Below is a summary of recent inventory movements:</p>
                <ul class="fallback-list">
                    <?php foreach(array_slice($timeline_data, 0, 5) as $item): ?>
                    <li>
                        <?php echo date('M j, Y', strtotime($item['date'])); ?>: 
                        <?php echo $item['quantity']; ?> items moved from 
                        <?php echo ucfirst($item['from_location']); ?> to 
                        <?php echo ucfirst($item['to_location']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="timeline-legend">
            <div class="legend-item">
                <span class="color-box manufacturing-color" aria-hidden="true"></span>
                <span>Manufacturing</span>
            </div>
            <div class="legend-item">
                <span class="color-box transit-color" aria-hidden="true"></span>
                <span>Transit</span>
            </div>
            <div class="legend-item">
                <span class="color-box wholesale-color" aria-hidden="true"></span>
                <span>Wholesale</span>
            </div>
        </div>
    </div>
</div>

<!-- Filter and Search -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <input type="hidden" name="location" value="<?php echo htmlspecialchars($location_filter); ?>">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search Products:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name, SKU">
                    <button type="submit" class="search-button" aria-label="Search">
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="inventory.php?location=<?php echo $location_filter; ?>" class="button secondary">
                    <i class="fas fa-times" aria-hidden="true"></i> Reset Filters
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Inventory Table -->
<div class="card full-width">
    <div class="card-header">
        <h2><?php echo ucfirst($location_filter); ?> Inventory</h2>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> items
            <?php else: ?>
            No inventory items found
            <?php endif; ?>
        </div>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table responsive" id="inventoryTable" aria-label="Inventory Items">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Batch</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Last Updated</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($inventory_stmt && $inventory_stmt->rowCount() > 0): ?>
                        <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td data-label="Product"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td data-label="SKU"><?php echo htmlspecialchars($item['sku']); ?></td>
                            <td data-label="Batch">
                                <?php if ($item['batch_id'] > 0): ?>
                                    <a href="view-batch.php?id=<?php echo $item['batch_id']; ?>" class="batch-link">
                                        <?php echo htmlspecialchars($item['batch_number']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($item['batch_number']); ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Quantity"><?php echo number_format($item['quantity']); ?></td>
                            <td data-label="Last Updated"><?php echo date('Y-m-d', strtotime($item['updated_at'])); ?></td>
                            <td data-label="Status">
                                <span class="location-badge location-<?php echo $item['location']; ?>"><?php echo ucfirst($item['location']); ?></span>
                            </td>
                            <td data-label="Actions">
                                <button class="button small transfer-item-btn" 
                                        data-product-id="<?php echo $item['product_id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>"
                                        data-quantity="<?php echo $item['quantity']; ?>"
                                        data-location="<?php echo $item['location']; ?>">
                                    <i class="fas fa-exchange-alt" aria-hidden="true"></i> Transfer
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-data">No inventory items found. 
                                <?php if (!empty($search)): ?>
                                    Try adjusting your search criteria.
                                <?php else: ?>
                                    No products are currently in this location.
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <nav class="pagination-container" aria-label="Inventory pagination">
            <ul class="pagination">
                <?php 
                // Build pagination query string with filters
                $pagination_query = '';
                if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
                $pagination_query .= '&location=' . urlencode($location_filter);
                ?>
                
                <?php if($page > 1): ?>
                    <li class="pagination-item">
                        <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link" aria-label="Go to first page">
                            <i class="fas fa-angle-double-left" aria-hidden="true"></i> First
                        </a>
                    </li>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Go to previous page">
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
                    echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
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
                        echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                    }
                    echo '</li>';
                }
                
                // Always show last page button
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<li class="pagination-item pagination-ellipsis">...</li>';
                    }
                    echo '<li class="pagination-item">';
                    echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
                    echo '</li>';
                }
                ?>
                
                <?php if($page < $total_pages): ?>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Go to next page">
                            Next <i class="fas fa-angle-right" aria-hidden="true"></i>
                        </a>
                    </li>
                    <li class="pagination-item">
                        <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Go to last page">
                            Last <i class="fas fa-angle-double-right" aria-hidden="true"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Transfers Table -->
<div class="card full-width">
    <div class="card-header">
        <h2>Recent Inventory Transfers</h2>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table responsive" aria-label="Recent Inventory Transfers">
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">From</th>
                        <th scope="col">To</th>
                        <th scope="col">Date</th>
                        <th scope="col">Status</th>
                        <th scope="col">Initiated By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transfers_stmt && $transfers_stmt->rowCount() > 0): ?>
                        <?php while($transfer = $transfers_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td data-label="Product"><?php echo htmlspecialchars($transfer['product_name']); ?></td>
                            <td data-label="Quantity"><?php echo number_format($transfer['quantity']); ?></td>
                            <td data-label="From"><span class="location-badge location-<?php echo $transfer['from_location']; ?>"><?php echo ucfirst($transfer['from_location']); ?></span></td>
                            <td data-label="To"><span class="location-badge location-<?php echo $transfer['to_location']; ?>"><?php echo ucfirst($transfer['to_location']); ?></span></td>
                            <td data-label="Date"><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></td>
                            <td data-label="Status"><span class="status-badge status-<?php echo $transfer['status']; ?>"><?php echo ucfirst($transfer['status']); ?></span></td>
                            <td data-label="Initiated By"><?php echo htmlspecialchars($transfer['initiated_by_user']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-data">No recent transfers found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Transfer Inventory Modal -->
<div id="transferModal" class="modal" role="dialog" aria-labelledby="transferModalTitle" aria-modal="true" tabindex="-1">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="transferModalTitle">Transfer Inventory</h2>
            <button type="button" class="close-modal" aria-label="Close modal">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="transferForm" action="../api/transfer-inventory.php" method="post">
                <input type="hidden" id="product_id" name="product_id">
                <input type="hidden" id="from_location" name="from_location">
                
                <div class="form-group">
                    <label for="product_name">Product:</label>
                    <input type="text" id="product_name" readonly>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="current_location">Current Location:</label>
                        <input type="text" id="current_location" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="to_location" class="required">Transfer To:</label>
                        <select id="to_location" name="to_location" required aria-required="true">
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo $location; ?>"><?php echo ucfirst($location); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="to_location-error"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="available_quantity">Available Quantity:</label>
                    <input type="text" id="available_quantity" readonly>
                </div>
                
                <div class="form-group">
                    <label for="quantity" class="required">Quantity to Transfer:</label>
                    <input type="number" id="quantity" name="quantity" min="1" required aria-required="true">
                    <div class="error-message" id="quantity-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Add any additional notes about this transfer"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="button secondary" id="cancelTransfer">Cancel</button>
                    <button type="submit" class="button primary" id="submitTransferBtn">
                        <i class="fas fa-exchange-alt" aria-hidden="true"></i> Initiate Transfer
                    </button>
                </div>
            </form>
        </div>
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
    --border-radius-pill: 2rem;
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

/* Toast Notification Container */
.toast-container {
    position: fixed;
    bottom: var(--spacing-lg);
    right: var(--spacing-lg);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

/* Toast Notification */
.toast {
    display: flex;
    align-items: center;
    padding: var(--spacing-md);
    border-radius: var(--border-radius-md);
    background-color: var(--surface);
    box-shadow: var(--shadow-md);
    min-width: 280px;
    max-width: 350px;
    animation: toast-in 0.3s ease-out;
}

.toast-success {
    border-left: 4px solid var(--success);
}

.toast-error {
    border-left: 4px solid var(--error);
}

.toast-warning {
    border-left: 4px solid var(--warning);
}

.toast-info {
    border-left: 4px solid var(--primary);
}

.toast-icon {
    margin-right: var(--spacing-md);
    color: var(--text-secondary);
    font-size: var(--font-size-lg);
}

.toast-success .toast-icon {
    color: var(--success);
}

.toast-error .toast-icon {
    color: var(--error);
}

.toast-warning .toast-icon {
    color: var(--warning);
}

.toast-info .toast-icon {
    color: var(--primary);
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
}

.toast-message {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.toast-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: var(--spacing-xs);
    margin-left: var(--spacing-xs);
    font-size: var(--font-size-lg);
    opacity: 0.7;
    transition: opacity var(--transition-fast);
}

.toast-close:hover {
    opacity: 1;
}

@keyframes toast-in {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Loading Indicator */
.loading-indicator {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.7);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(26, 115, 232, 0.2);
    border-radius: 50%;
    border-top-color: var(--primary);
    animation: spin 1s ease infinite;
    margin-bottom: var(--spacing-md);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Page Structure */
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

/* Alert Component */
.alert {
    display: flex;
    align-items: center;
    padding: var(--spacing-md);
    border-radius: var(--border-radius-md);
    margin-bottom: var(--spacing-lg);
    background-color: var(--surface);
    box-shadow: var(--shadow-sm);
    animation: alert-in 0.3s ease-out;
}

.alert-success {
    border-left: 4px solid var(--success);
    color: var(--success);
}

.alert-error {
    border-left: 4px solid var(--error);
    color: var(--error);
}

.alert-warning {
    border-left: 4px solid var(--warning);
    color: var(--warning);
}

.alert i {
    margin-right: var(--spacing-md);
}

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    padding: 0;
    font-size: var(--font-size-lg);
    opacity: 0.7;
    transition: opacity var(--transition-fast);
}

.close-alert:hover {
    opacity: 1;
}

@keyframes alert-in {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Inventory Summary Cards */
.inventory-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.summary-card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-md);
    display: flex;
    align-items: center;
    text-decoration: none;
    color: var(--text-primary);
    position: relative;
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.summary-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.summary-card.active {
    border-left: 4px solid var(--primary);
    padding-left: calc(var(--spacing-md) - 4px);
}

.active-indicator {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: var(--primary);
}

.summary-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--border-radius-md);
    background-color: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
    margin-right: var(--spacing-md);
}

.summary-details {
    flex: 1;
}

.summary-details h3 {
    margin: 0 0 var(--spacing-xs) 0;
    font-size: var(--font-size-md);
    font-weight: 500;
}

.summary-stats {
    display: flex;
    gap: var(--spacing-md);
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-value {
    font-weight: 600;
    font-size: var(--font-size-lg);
}

.stat-label {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
}

/* Card Component */
.card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-lg);
    overflow: hidden;
}

.card.full-width {
    width: 100%;
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

/* Inventory Timeline Chart */
.inventory-timeline {
    width: 100%;
    height: 300px;
    position: relative;
    margin-bottom: var(--spacing-md);
}

.timeline-legend {
    display: flex;
    justify-content: center;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    font-size: var(--font-size-sm);
}

.color-box {
    width: 16px;
    height: 16px;
    border-radius: 3px;
}

.manufacturing-color { background-color: #4285f4; }
.transit-color { background-color: #fbbc04; }
.wholesale-color { background-color: #34a853; }

.chart-fallback {
    padding: var(--spacing-md);
    background-color: var(--background);
    border-radius: var(--border-radius-md);
    border: 1px dashed var(--border);
}

.fallback-list {
    padding-left: var(--spacing-lg);
    margin-top: var(--spacing-sm);
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

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding: 0.5rem 2.5rem 0.5rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-sm);
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

.data-table.responsive {
    min-width: 100%;
}


Share

manufacturing (4).php
take style inspiration from manufacturing.php and give me siimilar style code for the other two files
costs.php

inventory (1).php

Copy Code
Copy Code
Modern CSS Styles for Manufacturing Costs Page and Inventory Management Page

Based on the style inspiration from the manufacturing.php file, I'll provide modern, consistent CSS for both the costs.php and inventory.php pages. These styles maintain the clean, professional design language while adapting to each page's specific needs.

CSS for costs.php

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

/* Page Structure */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
}

.page-header h2 {
    font-size: var(--font-size-xxl);
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
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

.filter-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* Cost Summary Section */
.costs-summary {
    margin-bottom: var(--spacing-xl);
}

.summary-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-md);
}

.summary-header h3 {
    font-size: var(--font-size-lg);
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
}

.total-cost {
    font-size: var(--font-size-lg);
    display: flex;
    align-items: center;
}

.total-cost .label {
    color: var(--text-secondary);
    margin-right: var(--spacing-sm);
}

.total-cost .value {
    font-weight: 600;
    color: var(--primary);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--spacing-md);
}

.summary-card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-lg);
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.summary-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.summary-title {
    font-size: var(--font-size-md);
    font-weight: 500;
    color: var(--primary);
    margin: 0 0 var(--spacing-sm) 0;
}

.summary-amount {
    font-size: var(--font-size-xl);
    font-weight: 600;
    margin-bottom: var(--spacing-md);
}

.summary-details {
    border-top: 1px solid var(--border);
    padding-top: var(--spacing-md);
}

.summary-detail {
    display: flex;
    justify-content: space-between;
    margin-bottom: var(--spacing-xs);
}

.summary-detail:last-child {
    margin-bottom: 0;
}

.detail-label {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.detail-value {
    font-weight: 500;
    font-size: var(--font-size-sm);
}

.no-data {
    grid-column: 1 / -1;
    padding: var(--spacing-lg);
    text-align: center;
    color: var(--text-secondary);
    background-color: var(--background);
    border-radius: var(--border-radius-md);
    border: 1px dashed var(--border);
}

/* Dashboard Card */
.dashboard-card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-lg);
    overflow: hidden;
}

.dashboard-card.full-width {
    width: 100%;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--spacing-md);
    border-bottom: 1px solid var(--border);
}

.card-header h3 {
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

.batch-link {
    color: var(--primary);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.batch-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.cost-type {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-xs);
    font-weight: 500;
    text-transform: capitalize;
}

.cost-type-labor {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.cost-type-overhead {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.cost-type-electricity {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.cost-type-maintenance {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.cost-type-other {
    background-color: rgba(103, 58, 183, 0.1);
    color: #673ab7;
}

.amount-cell {
    font-weight: 500;
    text-align: right;
}

.no-records {
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--text-secondary);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 0.25rem;
    margin-top: var(--spacing-lg);
    flex-wrap: wrap;
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

/* Responsive adjustments */
@media (max-width: 992px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }
    
    .summary-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-sm);
    }
    
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        flex-direction: column;
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
        margin-bottom: var(--spacing-xs);
    }
    
    .data-table {
        display: block;
        overflow-x: auto;
    }
}

@media (max-width: 576px) {
    .data-table th:nth-child(5),
    .data-table td:nth-child(5) {
        display: none;
    }
}

/* Accessibility focus styles */
:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

:focus:not(:focus-visible) {
    outline: none;
}

/* Print styles */
@media print {
    .filter-container,
    .pagination {
        display: none !important;
    }
    
    .dashboard-card {
        box-shadow: none;
        border: 1px solid #ccc;
        break-inside: avoid;
    }
    
    .data-table th {
        background-color: #f1f3f4 !important;
        color: black !important;
    }
    
    body {
        font-size: 12pt;
    }
}

/* Reduced motion preferences */
@media (prefers-reduced-motion: reduce) {
    .summary-card, .button::after {
        transition: none;
    }
    
    .summary-card:hover {
        transform: none;
    }
}

CSS for inventory.php

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
    --border-radius-pill: 2rem;
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

/* Toast Notification Container */
.toast-container {
    position: fixed;
    bottom: var(--spacing-lg);
    right: var(--spacing-lg);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

/* Toast Notification */
.toast {
    display: flex;
    align-items: center;
    padding: var(--spacing-md);
    border-radius: var(--border-radius-md);
    background-color: var(--surface);
    box-shadow: var(--shadow-md);
    min-width: 280px;
    max-width: 350px;
    animation: toast-in 0.3s ease-out;
}

.toast-success {
    border-left: 4px solid var(--success);
}

.toast-error {
    border-left: 4px solid var(--error);
}

.toast-warning {
    border-left: 4px solid var(--warning);
}

.toast-info {
    border-left: 4px solid var(--primary);
}

.toast-icon {
    margin-right: var(--spacing-md);
    color: var(--text-secondary);
    font-size: var(--font-size-lg);
}

.toast-success .toast-icon {
    color: var(--success);
}

.toast-error .toast-icon {
    color: var(--error);
}

.toast-warning .toast-icon {
    color: var(--warning);
}

.toast-info .toast-icon {
    color: var(--primary);
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-weight: 600;
    margin-bottom: var(--spacing-xs);
}

.toast-message {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.toast-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: var(--spacing-xs);
    margin-left: var(--spacing-xs);
    font-size: var(--font-size-lg);
    opacity: 0.7;
    transition: opacity var(--transition-fast);
}

.toast-close:hover {
    opacity: 1;
}

@keyframes toast-in {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Loading Indicator */
.loading-indicator {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.7);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.spinner {
    width: 50px;
    height: 50px;
    border: 4px solid rgba(26, 115, 232, 0.2);
    border-radius: 50%;
    border-top-color: var(--primary);
    animation: spin 1s ease infinite;
    margin-bottom: var(--spacing-md);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Page Structure */
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

/* Alert Component */
.alert {
    display: flex;
    align-items: center;
    padding: var(--spacing-md);
    border-radius: var(--border-radius-md);
    margin-bottom: var(--spacing-lg);
    background-color: var(--surface);
    box-shadow: var(--shadow-sm);
    animation: alert-in 0.3s ease-out;
}

.alert-success {
    border-left: 4px solid var(--success);
    color: var(--success);
}

.alert-error {
    border-left: 4px solid var(--error);
    color: var(--error);
}

.alert-warning {
    border-left: 4px solid var(--warning);
    color: var(--warning);
}

.alert i {
    margin-right: var(--spacing-md);
}

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    padding: 0;
    font-size: var(--font-size-lg);
    opacity: 0.7;
    transition: opacity var(--transition-fast);
}

.close-alert:hover {
    opacity: 1;
}

@keyframes alert-in {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Inventory Summary Cards */
.inventory-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.summary-card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-md);
    display: flex;
    align-items: center;
    text-decoration: none;
    color: var(--text-primary);
    position: relative;
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.summary-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.summary-card.active {
    border-left: 4px solid var(--primary);
    padding-left: calc(var(--spacing-md) - 4px);
}

.active-indicator {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: var(--primary);
}

.summary-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--border-radius-md);
    background-color: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-size-xl);
    margin-right: var(--spacing-md);
}

.summary-details {
    flex: 1;
}

.summary-details h3 {
    margin: 0 0 var(--spacing-xs) 0;
    font-size: var(--font-size-md);
    font-weight: 500;
}

.summary-stats {
    display: flex;
    gap: var(--spacing-md);
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-value {
    font-weight: 600;
    font-size: var(--font-size-lg);
}

.stat-label {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
}

/* Card Component */
.card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-lg);
    overflow: hidden;
}

.card.full-width {
    width: 100%;
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

/* Inventory Timeline Chart */
.inventory-timeline {
    width: 100%;
    height: 300px;
    position: relative;
    margin-bottom: var(--spacing-md);
}

.timeline-legend {
    display: flex;
    justify-content: center;
    gap: var(--spacing-lg);
    flex-wrap: wrap;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    font-size: var(--font-size-sm);
}

.color-box {
    width: 16px;
    height: 16px;
    border-radius: 3px;
}

.manufacturing-color { background-color: #4285f4; }
.transit-color { background-color: #fbbc04; }
.wholesale-color { background-color: #34a853; }

.chart-fallback {
    padding: var(--spacing-md);
    background-color: var(--background);
    border-radius: var(--border-radius-md);
    border: 1px dashed var(--border);
}

.fallback-list {
    padding-left: var(--spacing-lg);
    margin-top: var(--spacing-sm);
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

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding: 0.5rem 2.5rem 0.5rem 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-sm);
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

.data-table.responsive {
    min-width: 100%;
}

/* Location and Status Badges */
.location-badge, .status-badge {
    display: inline-flex;
continue



Copy Code
.location-badge, .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.5rem;
    border-radius: var(--border-radius-pill);
    font-size: var(--font-size-xs);
    font-weight: 500;
    white-space: nowrap;
}

.location-manufacturing {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.location-transit {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.location-wholesale {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.status-badge.status-pending {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.status-badge.status-completed {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

/* Pagination Container */
.pagination-container {
    margin-top: var(--spacing-lg);
}

.pagination {
    display: flex;
    justify-content: center;
    list-style: none;
    padding: 0;
    margin: 0;
    flex-wrap: wrap;
    gap: var(--spacing-xs);
}

.pagination-item {
    display: inline-block;
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

.pagination-item.active .pagination-link {
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

/* Modal Component */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
    padding: var(--spacing-md);
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background-color: var(--surface);
    margin: 2rem auto;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-lg);
    max-width: 600px;
    width: 100%;
    position: relative;
    animation: modalContentSlideIn 0.3s ease-out;
}

@keyframes modalContentSlideIn {
    from { transform: translateY(-30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-header {
    padding: var(--spacing-md);
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: var(--font-size-xl);
    font-weight: 500;
    color: var(--text-primary);
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    transition: color var(--transition-fast);
}

.close-modal:hover, 
.close-modal:focus {
    color: var(--text-primary);
}

.modal-body {
    padding: var(--spacing-md);
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}

/* Form Components */
.form-group {
    margin-bottom: var(--spacing-md);
}

.form-group label {
    display: block;
    margin-bottom: var(--spacing-xs);
    font-size: var(--font-size-sm);
    font-weight: 500;
    color: var(--text-secondary);
}

.form-group label.required::after {
    content: '*';
    color: var(--error);
    margin-left: var(--spacing-xs);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    background-color: var(--surface);
    transition: all var(--transition-fast);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

.form-group input[readonly],
.form-group select[readonly],
.form-group textarea[readonly] {
    background-color: var(--background);
    cursor: not-allowed;
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.form-row .form-group {
    flex: 1;
    min-width: 200px;
    margin-bottom: 0;
}

.error-message {
    color: var(--error);
    font-size: var(--font-size-xs);
    margin-top: var(--spacing-xs);
    min-height: 1.2em;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-lg);
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--border);
}

/* Invalid input styling */
.invalid {
    border-color: var(--error) !important;
    background-color: var(--error-light) !important;
}

/* No data state */
.no-data {
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--text-secondary);
    background-color: var(--background);
    border-radius: var(--border-radius-md);
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .inventory-summary {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .inventory-timeline {
        height: 250px;
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
    
    .inventory-summary {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        width: 100%;
    }
    
    .form-row {
        flex-direction: column;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    /* Responsive table */
    .data-table.responsive thead {
        display: none;
    }
    
    .data-table.responsive tbody tr {
        display: block;
        margin-bottom: var(--spacing-md);
        border: 1px solid var(--border);
        border-radius: var(--border-radius-sm);
    }
    
    .data-table.responsive tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem;
        text-align: right;
        border-bottom: 1px solid var(--border);
    }
    
    .data-table.responsive tbody td:last-child {
        border-bottom: none;
    }
    
    .data-table.responsive tbody td::before {
        content: attr(data-label);
        font-weight: 500;
        text-align: left;
        color: var(--text-secondary);
    }
}

/* Accessibility focus styles */
:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

:focus:not(:focus-visible) {
    outline: none;
}

/* Print styles */
@media print {
    .page-actions,
    .filter-container,
    .pagination-container,
    .inventory-timeline {
        display: none !important;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #ccc;
        break-inside: avoid;
    }
    
    .data-table th {
        background-color: #f1f3f4 !important;
        color: black !important;
    }
    
    body {
        font-size: 12pt;
    }
}

/* Reduced motion preferences */
@media (prefers-reduced-motion: reduce) {
    .modal,
    .modal-content,
    .alert,
    .button::after,
    .summary-card {
        animation: none !important;
        transition: none !important;
    }
    
    .summary-card:hover {
        transform: none;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script src="../assets/js/utils.js"></script>
<script>
    
    /**
 * Inventory Management JavaScript
 * Provides interactive features for inventory management with robust error handling
 * and accessibility enhancements
 */
document.addEventListener('DOMContentLoaded', function() {
  // DOM Elements
  const transferModal = document.getElementById('transferModal');
  const initiateTransferBtn = document.getElementById('initiateTransferBtn');
  const transferItemButtons = document.querySelectorAll('.transfer-item-btn');
  const closeModalBtn = document.querySelector('.close-modal');
  const cancelTransferBtn = document.getElementById('cancelTransfer');
  const transferForm = document.getElementById('transferForm');
  
  // Initialize event listeners
  initEventListeners();
  
  // Initialize inventory chart
  initInventoryChart();
  
  // Make tables responsive
  makeTableResponsive('inventoryTable');
  
  // Initialize alert close buttons
  initAlertCloseButtons();
  
  // Log page view
  logUserActivity('read', 'inventory', 'Viewed inventory management dashboard');
  
  /**
   * Initialize all event listeners
   */
  function initEventListeners() {
    // Initiate transfer button
    if (initiateTransferBtn) {
      initiateTransferBtn.addEventListener('click', openTransferModal);
    }
    
    // Transfer item buttons
    transferItemButtons.forEach(button => {
      button.addEventListener('click', function() {
        const productId = this.getAttribute('data-product-id');
        const productName = this.getAttribute('data-product-name');
        const quantity = this.getAttribute('data-quantity');
        const location = this.getAttribute('data-location');
        
        openTransferModalWithProduct(productId, productName, quantity, location);
      });
    });
    
    // Close modal buttons
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelTransferBtn) cancelTransferBtn.addEventListener('click', closeModal);
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
      if (event.target === transferModal) closeModal();
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && transferModal && transferModal.style.display === 'block') {
        closeModal();
      }
    });
    
    // Form submission
    if (transferForm) {
      transferForm.addEventListener('submit', handleFormSubmit);
    }
    
    // Form input validation
    initFormValidation();
  }
  
  /**
   * Initialize form validation
   */
  function initFormValidation() {
    if (!transferForm) return;
    
    const requiredFields = transferForm.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
      field.addEventListener('input', function() {
        validateField(this);
      });
      
      field.addEventListener('blur', function() {
        validateField(this);
      });
    });
    
    // Special validation for quantity field
    const quantityField = document.getElementById('quantity');
    if (quantityField) {
      quantityField.addEventListener('input', function() {
        validateQuantityField(this);
      });
    }
    
    // Special validation for to_location field
    const toLocationField = document.getElementById('to_location');
    if (toLocationField) {
      toLocationField.addEventListener('change', function() {
        validateToLocationField(this);
      });
    }
  }
  
  /**
   * Validate a standard form field
   * @param {HTMLElement} field - The field to validate
   * @returns {boolean} - Whether the field is valid
   */
  function validateField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = `${field.labels[0]?.textContent.replace(':', '').replace('*', '') || 'Field'} is required`;
      }
      return false;
    } else {
      field.classList.remove('invalid');
      if (errorElement) {
        errorElement.textContent = '';
      }
      return true;
    }
  }
  
  /**
   * Validate quantity field with special rules
   * @param {HTMLElement} field - The quantity field
   * @returns {boolean} - Whether the field is valid
   */
  function validateQuantityField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    const availableQuantity = parseInt(document.getElementById('available_quantity').value.replace(/[^\d]/g, ''));
    const quantity = parseInt(field.value);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Quantity is required';
      }
      return false;
    } else if (isNaN(quantity) || quantity <= 0) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Quantity must be greater than zero';
      }
      return false;
    } else if (quantity > availableQuantity) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = `Quantity exceeds available amount (${availableQuantity})`;
      }
      return false;
    } else {
      field.classList.remove('invalid');
      if (errorElement) {
        errorElement.textContent = '';
      }
      return true;
    }
  }
  
  /**
   * Validate to_location field to ensure it's different from from_location
   * @param {HTMLElement} field - The to_location field
   * @returns {boolean} - Whether the field is valid
   */
  function validateToLocationField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    const fromLocation = document.getElementById('from_location').value;
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Destination location is required';
      }
      return false;
    } else if (field.value === fromLocation) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Destination must be different from current location';
      }
      return false;
    } else {
      field.classList.remove('invalid');
      if (errorElement) {
        errorElement.textContent = '';
      }
      return true;
    }
  }
  
  /**
   * Validate the entire form
   * @returns {boolean} - Whether the form is valid
   */
  function validateForm() {
    if (!transferForm) return false;
    
    let isValid = true;
    
    // Validate required fields
    const requiredFields = transferForm.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (field.id === 'quantity') {
        if (!validateQuantityField(field)) {
          isValid = false;
        }
      } else if (field.id === 'to_location') {
        if (!validateToLocationField(field)) {
          isValid = false;
        }
      } else if (!validateField(field)) {
        isValid = false;
      }
    });
    
    return isValid;
  }
  
  /**
   * Open the transfer modal without a specific product
   */
  function openTransferModal() {
    // Reset form and validation errors
    transferForm.reset();
    clearValidationErrors();
    
    // Hide product-specific fields
    document.getElementById('product_name').value = '';
    document.getElementById('current_location').value = '';
    document.getElementById('available_quantity').value = '';
    
    // Show modal
    transferModal.style.display = 'block';
    
    // Focus first field
    setTimeout(() => {
      document.getElementById('to_location').focus();
    }, 100);
  }
  
  /**
   * Open the transfer modal with a specific product
   * @param {string} productId - The product ID
   * @param {string} productName - The product name
   * @param {string} quantity - The available quantity
   * @param {string} location - The current location
   */
  function openTransferModalWithProduct(productId, productName, quantity, location) {
    // Reset form and validation errors
    transferForm.reset();
    clearValidationErrors();
    
    // Set product-specific fields
    document.getElementById('product_id').value = productId;
    document.getElementById('product_name').value = productName;
    document.getElementById('from_location').value = location;
    document.getElementById('current_location').value = ucfirst(location);
    document.getElementById('available_quantity').value = formatNumber(quantity);
    
    // Filter to_location options to exclude current location
    const toLocationSelect = document.getElementById('to_location');
    Array.from(toLocationSelect.options).forEach(option => {
      if (option.value === location) {
        option.disabled = true;
      } else {
        option.disabled = false;
      }
    });
    
    // Show modal
    transferModal.style.display = 'block';
    
    // Focus first field
    setTimeout(() => {
      document.getElementById('to_location').focus();
    }, 100);
  }
  
  /**
   * Close the modal
   */
  function closeModal() {
    if (transferModal) {
      transferModal.style.display = 'none';
    
      // Reset form and clear validation errors
      transferForm.reset();
      clearValidationErrors();
    }
  }
  
  /**
   * Clear all validation errors
   */
  function clearValidationErrors() {
    document.querySelectorAll('.invalid').forEach(field => {
      field.classList.remove('invalid');
    });
    
    document.querySelectorAll('.error-message').forEach(error => {
      error.textContent = '';
    });
  }
  
  /**
   * Handle form submission
   * @param {Event} event - The form submission event
   */
  function handleFormSubmit(event) {
    event.preventDefault();
    
    // Validate form
    if (!validateForm()) {
      return;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitTransferBtn');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Processing...';
    
    // Show loading indicator
    showLoading(true);
    
    // Get form data
    const formData = new FormData(transferForm);
    
    // Send AJAX request
    fetch(transferForm.action, {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      return response.json();
    })
    .then(result => {
      // Hide loading indicator
      showLoading(false);
      
      if (result.success) {
        // Show success message
        showToast('Success', result.message || 'Inventory transfer initiated successfully', 'success');
        
        // Close modal
        closeModal();
        
        // Reload page after a short delay
        setTimeout(() => {
          window.location.href = 'inventory.php?transfer=success';
        }, 1500);
      } else {
        // Show error message
        showToast('Error', result.message || 'Failed to initiate transfer', 'error');
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    })
    .catch(error => {
      // Hide loading indicator
      showLoading(false);
      
      console.error('Error initiating transfer:', error);
      showToast('Error', 'An unexpected error occurred. Please try again.', 'error');
      
      // Reset button state
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnText;
    });
  }
  
  /**
   * Initialize inventory chart with error handling and accessibility
   */
  function initInventoryChart() {
    const chartCanvas = document.getElementById('inventoryTimelineChart');
    const chartFallback = document.getElementById('chartFallback');
    
    if (!chartCanvas) return;
    
    // Check if Chart.js is available
    if (typeof Chart === 'undefined') {
      console.error('Chart.js library not loaded');
      if (chartFallback) {
        chartFallback.style.display = 'block';
      }
      chartCanvas.style.display = 'none';
      return;
    }
    
    try {
      // Prepare data for chart
      const timelineLabels = getTimelineLabels();
      
      // Prepare datasets
      const manufacturingData = Array(timelineLabels.length).fill(0);
      const transitData = Array(timelineLabels.length).fill(0);
      const wholesaleData = Array(timelineLabels.length).fill(0);
      
      // Fill in the data from the timeline_data
      const timelineData = getTimelineData();
      timelineData.forEach(item => {
        const dateIndex = timelineLabels.indexOf(item.date);
        if (dateIndex !== -1) {
          if (item.to_location === 'manufacturing') {
            manufacturingData[dateIndex] += parseInt(item.quantity);
          } else if (item.to_location === 'transit') {
            transitData[dateIndex] += parseInt(item.quantity);
          } else if (item.to_location === 'wholesale') {
            wholesaleData[dateIndex] += parseInt(item.quantity);
          }
          
          if (item.from_location === 'manufacturing') {
            manufacturingData[dateIndex] -= parseInt(item.quantity);
          } else if (item.from_location === 'transit') {
            transitData[dateIndex] -= parseInt(item.quantity);
          } else if (item.from_location === 'wholesale') {
            wholesaleData[dateIndex] -= parseInt(item.quantity);
          }
        }
      });
      
      // Create the chart
      new Chart(chartCanvas, {
        type: 'line',
        data: {
          labels: timelineLabels.map(date => {
            const d = new Date(date);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
          }),
          datasets: [
            {
              label: 'Manufacturing',
              data: manufacturingData,
              borderColor: '#4285f4',
              backgroundColor: 'rgba(66, 133, 244, 0.1)',
              tension: 0.4,
              fill: true
            },
            {
              label: 'Transit',
              data: transitData,
              borderColor: '#fbbc04',
              backgroundColor: 'rgba(251, 188, 4, 0.1)',
              tension: 0.4,
              fill: true
            },
            {
              label: 'Wholesale',
              data: wholesaleData,
              borderColor: '#34a853',
              backgroundColor: 'rgba(52, 168, 83, 0.1)',
              tension: 0.4,
              fill: true
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              mode: 'index',
              intersect: false,
              callbacks: {
                label: function(context) {
                  let label = context.dataset.label || '';
                  if (label) {
                    label += ': ';
                  }
                  if (context.raw !== null) {
                    label += context.raw > 0 ? '+' : '';
                    label += context.raw;
                  }
                  return label;
                }
              }
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                maxRotation: 45,
                minRotation: 45
              }
            },
            y: {
              title: {
                display: true,
                text: 'Inventory Movement'
              },
              grid: {
                color: 'rgba(0, 0, 0, 0.05)'
              }
            }
          },
          interaction: {
            mode: 'nearest',
            axis: 'x',
            intersect: false
          },
          animation: {
            duration: 1000,
            easing: 'easeOutQuart'
          }
        }
      });
    } catch (error) {
      console.error('Error initializing chart:', error);
      if (chartFallback) {
        chartFallback.style.display = 'block';
      }
      chartCanvas.style.display = 'none';
    }
  }
  
  /**
   * Get timeline labels for the last 30 days
   * @returns {Array} - Array of date strings in YYYY-MM-DD format
   */
  function getTimelineLabels() {
    const dates = [];
    const today = new Date();
    
    for (let i = 29; i >= 0; i--) {
      const date = new Date(today);
      date.setDate(today.getDate() - i);
      dates.push(formatDateYMD(date));
    }
    
    return dates;
  }
  
  /**
   * Get timeline data from the server-rendered data
   * @returns {Array} - Array of timeline data objects
   */
  function getTimelineData() {
    // This would normally be populated from server-side data
    // For this implementation, we'll return an empty array which will be populated
    // by PHP when the page is rendered
    return window.timelineData || [];
  }
  
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
   * Format a date as YYYY-MM-DD
   * @param {Date} date - The date to format
   * @returns {string} - Formatted date string
   */
  function formatDateYMD(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
  }
  
  /**
   * Format a number with thousands separators
   * @param {number|string} number - The number to format
   * @returns {string} - Formatted number string
   */
  function formatNumber(number) {
    return new Intl.NumberFormat().format(number);
  }
  
  /**
   * Capitalize the first letter of a string
   * @param {string} string - The string to capitalize
   * @returns {string} - Capitalized string
   */
  function ucfirst(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
  }
  
  /**
   * Show or hide the loading indicator
   * @param {boolean} show - Whether to show the loading indicator
   */
  function showLoading(show) {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
      loadingIndicator.style.display = show ? 'flex' : 'none';
    }
  }
});
</script>

<?php include_once '../includes/footer.php'; ?>