<?php
session_start();
$page_title = "Inventory";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up search filter
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = $page < 1 ? 1 : $page; // Ensure page is at least 1
$offset = ($page - 1) * $records_per_page;

try {
    // Build query with filters
    $where_clause = "WHERE i.location = 'wholesale'"; // Shopkeepers only see wholesale inventory
    $params = array();

    if(!empty($search)) {
        $where_clause .= " AND (p.name LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = "%{$search}%";
    }
    
    if(!empty($category)) {
        $where_clause .= " AND p.category = :category";
        $params[':category'] = $category;
    }
    
    if($stock_status === 'in_stock') {
        $where_clause .= " AND i.quantity > 10";
    } elseif($stock_status === 'low_stock') {
        $where_clause .= " AND i.quantity BETWEEN 1 AND 10";
    } elseif($stock_status === 'out_of_stock') {
        $where_clause .= " AND i.quantity = 0";
    }

    // Get total records for pagination
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

    // Get inventory items with pagination and filters
    $inventory_query = "SELECT i.*, p.name as product_name, p.sku, p.description, p.category,
                       (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.product_id = p.id AND s.sale_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)) as sales_last_30_days
                       FROM inventory i
                       JOIN products p ON i.product_id = p.id
                       $where_clause
                       ORDER BY 
                       CASE 
                           WHEN i.quantity = 0 THEN 1
                           WHEN i.quantity <= 10 THEN 2
                           ELSE 3
                       END, 
                       p.name ASC
                       LIMIT :offset, :records_per_page";
    $inventory_stmt = $db->prepare($inventory_query);
    foreach($params as $param => $value) {
        $inventory_stmt->bindValue($param, $value);
    }
    $inventory_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $inventory_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
    $inventory_stmt->execute();

    // Get inventory summary
    $summary_query = "SELECT 
                        COUNT(*) as product_count,
                        SUM(i.quantity) as total_items,
                        SUM(CASE WHEN i.quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                                                SUM(CASE WHEN i.quantity BETWEEN 1 AND 10 THEN 1 ELSE 0 END) as low_stock_count,
                        COUNT(DISTINCT p.category) as category_count
                     FROM inventory i
                     JOIN products p ON i.product_id = p.id
                     WHERE i.location = 'wholesale'";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // Get categories for filter dropdown
    $categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category";
    $categories_stmt = $db->prepare($categories_query);
    $categories_stmt->execute();
    $categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get pending transfers
    $transfers_query = "SELECT COUNT(*) as count FROM inventory_transfers 
                       WHERE to_location = 'wholesale' AND status = 'pending'";
    $transfers_stmt = $db->prepare($transfers_query);
    $transfers_stmt->execute();
    $pending_transfers = $transfers_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<?php if(isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <button class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if(isset($_SESSION['success_message'])): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $_SESSION['success_message']; ?></span>
    <button class="close-alert">&times;</button>
</div>
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<div class="page-header">
    <h2>Inventory Management</h2>
    <div class="page-actions">
        <?php if($pending_transfers > 0): ?>
        <a href="pending-transfers.php" class="button primary">
            <i class="fas fa-truck-loading"></i> Pending Transfers
            <span class="badge"><?php echo $pending_transfers; ?></span>
        </a>
        <?php endif; ?>
        <a href="inventory-report.php" class="button secondary">
            <i class="fas fa-file-export"></i> Export Inventory
        </a>
    </div>
</div>

<div class="inventory-summary">
    <div class="summary-cards">
        <div class="summary-card">
            <div class="card-icon products-icon">
                <i class="fas fa-box"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Products</div>
                <div class="card-value"><?php echo number_format($summary['product_count']); ?></div>
                <div class="card-subtitle"><?php echo $summary['category_count']; ?> categories</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="card-icon quantity-icon">
                <i class="fas fa-cubes"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Total Stock</div>
                <div class="card-value"><?php echo number_format($summary['total_items']); ?></div>
                <div class="card-subtitle">items</div>
            </div>
        </div>
        <div class="summary-card <?php echo $summary['low_stock_count'] > 0 ? 'warning-card' : ''; ?>">
            <div class="card-icon low-stock-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Low Stock</div>
                <div class="card-value"><?php echo number_format($summary['low_stock_count']); ?></div>
                <div class="card-subtitle">items below 10 units</div>
            </div>
        </div>
        <div class="summary-card <?php echo $summary['out_of_stock_count'] > 0 ? 'alert-card' : ''; ?>">
            <div class="card-icon out-of-stock-icon">
                <i class="fas fa-times-circle"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Out of Stock</div>
                <div class="card-value"><?php echo number_format($summary['out_of_stock_count']); ?></div>
                <div class="card-subtitle">items unavailable</div>
            </div>
        </div>
    </div>
</div>

<div class="filter-container">
    <button id="toggleFiltersBtn" class="button secondary toggle-filters-btn">
        <i class="fas fa-filter"></i> Filters
        <?php if(!empty($search) || !empty($category) || !empty($stock_status)): ?>
        <span class="filter-badge"><?php echo countActiveFilters($search, $category, $stock_status); ?></span>
        <?php endif; ?>
    </button>
    
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search Products:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name or SKU">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-group">
                <label for="category">Category:</label>
                <select id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="stock_status">Stock Status:</label>
                <select id="stock_status" name="stock_status">
                    <option value="">All Stock Levels</option>
                    <option value="in_stock" <?php echo $stock_status === 'in_stock' ? 'selected' : ''; ?>>In Stock (>10)</option>
                    <option value="low_stock" <?php echo $stock_status === 'low_stock' ? 'selected' : ''; ?>>Low Stock (1-10)</option>
                    <option value="out_of_stock" <?php echo $stock_status === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                </select>
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a href="inventory.php" class="button secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Inventory Items</h3>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> items
        </div>
    </div>
    <div class="card-content">
        <?php if($inventory_stmt->rowCount() > 0): ?>
        <div class="table-responsive">
            <table class="data-table" id="inventoryTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>30-Day Sales</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr class="expandable-row" data-product-id="<?php echo $item['product_id']; ?>" data-description="<?php echo htmlspecialchars($item['description']); ?>">
                        <td data-label="Product">
                            <div class="product-cell">
                                <div class="product-image">
                                    <i class="fas fa-box"></i>
                                </div>
                                <div class="product-info">
                                    <div class="product-name"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="SKU"><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td data-label="Category"><?php echo htmlspecialchars($item['category'] ?: 'Uncategorized'); ?></td>
                        <td data-label="Quantity" class="quantity-cell">
                            <span class="quantity-value <?php echo getQuantityClass($item['quantity']); ?>">
                                <?php echo number_format($item['quantity']); ?>
                            </span>
                        </td>
                        <td data-label="30-Day Sales">
                            <?php if($item['sales_last_30_days'] > 0): ?>
                            <span class="sales-badge"><?php echo number_format($item['sales_last_30_days']); ?></span>
                            <?php else: ?>
                            <span class="no-sales">No sales</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="status-badge status-<?php echo getStockStatus($item['quantity']); ?>">
                                <?php echo getStockStatusLabel($item['quantity']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button type="button" class="button small view-details-btn" aria-label="View details">
                                    <i class="fas fa-info-circle"></i>
                                </button>
                                <button type="button" class="button small request-stock-btn" 
                                        data-product-id="<?php echo $item['product_id']; ?>"
                                        data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>"
                                        data-current-stock="<?php echo $item['quantity']; ?>"
                                        <?php echo $item['quantity'] > 10 ? 'disabled' : ''; ?>
                                        aria-label="Request stock">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr class="description-row">
                        <td colspan="7">
                            <div class="product-details">
                                <div class="details-section">
                                    <h4>Product Details</h4>
                                    <div class="detail-item">
                                        <span class="detail-label">Description:</span>
                                        <span class="detail-value"><?php echo $item['description'] ? nl2br(htmlspecialchars($item['description'])) : 'No description available'; ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">SKU:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($item['sku']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Category:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($item['category'] ?: 'Uncategorized'); ?></span>
                                    </div>
                                </div>
                                <div class="details-section">
                                    <h4>Inventory Status</h4>
                                    <div class="detail-item">
                                        <span class="detail-label">Current Stock:</span>
                                        <span class="detail-value quantity-value <?php echo getQuantityClass($item['quantity']); ?>"><?php echo number_format($item['quantity']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">30-Day Sales:</span>
                                        <span class="detail-value"><?php echo number_format($item['sales_last_30_days']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Status:</span>
                                        <span class="detail-value status-badge status-<?php echo getStockStatus($item['quantity']); ?>"><?php echo getStockStatusLabel($item['quantity']); ?></span>
                                    </div>
                                </div>
                                <div class="details-actions">
                                    <button type="button" class="button request-stock-btn" 
                                            data-product-id="<?php echo $item['product_id']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>"
                                            data-current-stock="<?php echo $item['quantity']; ?>"
                                            <?php echo $item['quantity'] > 10 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-sync-alt"></i> Request Stock
                                    </button>
                                    <button type="button" class="button secondary close-details-btn">
                                        <i class="fas fa-times"></i> Close
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-boxes"></i>
            </div>
            <h3>No Inventory Items Found</h3>
            <p>No items match your search criteria. Try adjusting your filters or check back later for new inventory.</p>
            <a href="inventory.php" class="button">View All Inventory</a>
        </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
            if(!empty($category)) $pagination_query .= '&category=' . urlencode($category);
            if(!empty($stock_status)) $pagination_query .= '&stock_status=' . urlencode($stock_status);
            ?>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link" aria-label="First page">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Previous page">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if($start_page > 1) {
                echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                if($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo '<span class="pagination-link current" aria-current="page">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Next page">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Last page">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Request Stock Modal -->
<div id="requestStockModal" class="modal" aria-labelledby="requestStockModalTitle" aria-modal="true" role="dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="requestStockModalTitle">Request Additional Stock</h2>
            <button class="close-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="requestStockForm" action="../api/request-stock.php" method="post">
                <input type="hidden" id="product_id" name="product_id" value="">
                
                <div class="stock-info">
                    <div class="info-item">
                        <div class="info-label">Product</div>
                        <div id="product_name" class="info-value"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Current Stock</div>
                        <div id="current_stock" class="info-value"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="requested_quantity">Quantity Needed: <span class="required">*</span></label>
                    <input type="number" id="requested_quantity" name="requested_quantity" min="1" value="20" required>
                    <div class="error-message" id="quantity-error"></div>
                    <div class="form-text">Enter the quantity you need for your shop</div>
                </div>
                
                <div class="form-group">
                    <label for="priority">Priority:</label>
                    <select id="priority" name="priority">
                        <option value="normal">Normal</option>
                        <option value="high">High - Needed Soon</option>
                        <option value="urgent">Urgent - Critical Shortage</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Any specific requirements or details about this request"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="button secondary close-modal-btn">Cancel</button>
            <button type="button" id="submitRequestBtn" class="button primary">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Alert Styles */
.alert {
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: var(--border-radius-md, 8px);
    display: flex;
    align-items: center;
    animation: fadeIn 0.3s ease-in-out;
}

.alert i {
    margin-right: 0.75rem;
    font-size: 1.25rem;
}

.alert-success {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
    border: 1px solid rgba(52, 168, 83, 0.3);
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
    border: 1px solid rgba(234, 67, 53, 0.3);
}

.close-alert {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.close-alert:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Inventory summary styles */
.inventory-summary {
    margin-bottom: 1.5rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.summary-card {
    background-color: white;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md, 0 4px 6px rgba(0,0,0,0.1));
}

.card-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.products-icon {
    background-color: #4285f4;
}

.quantity-icon {
    background-color: #34a853;
}

.low-stock-icon {
    background-color: #fbbc04;
}

.out-of-stock-icon {
    background-color: #ea4335;
}

.warning-card .low-stock-icon {
    animation: pulse 2s infinite;
}

.alert-card .out-of-stock-icon {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.card-content {
    flex: 1;
}

.card-title {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}

.card-value {
    font-size: var(--font-size-xl, 1.5rem);
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.card-subtitle {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
}

/* Filter styles */
.filter-container {
    margin-bottom: 1.5rem;
}

.toggle-filters-btn {
    position: relative;
    margin-bottom: 1rem;
}

.filter-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background-color: var(--primary, #1a73e8);
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: bold;
}

.filter-form {
    background-color: white;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    overflow: hidden;
    transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out, margin 0.3s ease-in-out, padding 0.3s ease-in-out;
}

.filter-form.collapsed {
    max-height: 0;
    opacity: 0;
    margin: 0;
    padding-top: 0;
    padding-bottom: 0;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-row:last-child {
    margin-bottom: 0;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}

.search-group {
    flex: 2;
}

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding-right: 40px;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 40px;
    background: none;
    border: none;
    color: var(--text-secondary, #6c757d);
    cursor: pointer;
    transition: color 0.2s;
}

.search-button:hover {
    color: var(--primary, #1a73e8);
}

/* Table styles */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background-color: var(--surface, #f5f5f5);
    font-weight: 600;
    text-align: left;
    padding: 0.75rem 1rem;
    color: var(--text-secondary, #6c757d);
    border-bottom: 2px solid var(--border, #e0e0e0);
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    vertical-align: middle;
}

.expandable-row {
    cursor: pointer;
    transition: background-color 0.2s;
}

.expandable-row:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.expandable-row.expanded {
    background-color: rgba(26, 115, 232, 0.05);
    border-bottom: none;
}

.description-row {
    display: none;
    background-color: rgba(26, 115, 232, 0.05);
}

.product-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.product-image {
    width: 40px;
    height: 40px;
    background-color: var(--surface, #f5f5f5);
    border-radius: var(--border-radius-sm, 4px);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary, #6c757d);
    font-size: 1.25rem;
}

.product-info {
    flex: 1;
}

.product-name {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.quantity-cell {
    font-weight: 500;
}

.quantity-value {
    font-weight: 600;
}

.quantity-normal {
    color: #34a853;
}

.quantity-low {
    color: #fbbc04;
}

.quantity-out {
    color: #ea4335;
}

.sales-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.no-sales {
    color: var(--text-secondary, #6c757d);
    font-style: italic;
    font-size: 0.875rem;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

.status-in-stock {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.status-low-stock {
    background-color: rgba(251, 188, 4, 0.1);
    color: #fbbc04;
}

.status-out-of-stock {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

/* Product details styles */
.product-details {
    padding: 1.5rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.details-section {
    background-color: white;
    border-radius: var(--border-radius-sm, 4px);
    padding: 1rem;
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
}

.details-section h4 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    color: var(--text-primary, #212529);
    font-size: 1rem;
}

.detail-item {
    margin-bottom: 0.75rem;
}

.detail-item:last-child {
    margin-bottom: 0;
}

.detail-label {
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
    display: block;
}

.details-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
}

/* Empty state styles */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-state-icon {
    font-size: 3rem;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin-bottom: 0.5rem;
    color: var(--text-primary, #212529);
}

.empty-state p {
    color: var(--text-secondary, #6c757d);
    max-width: 500px;
    margin: 0 auto 1.5rem;
}

/* Pagination styles */
.pagination {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1.5rem;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 0.5rem;
    background-color: white;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: var(--border-radius-sm, 4px);
    color: var(--text-primary, #212529);
    text-decoration: none;
    transition: all 0.2s;
}

.pagination-link:hover {
    background-color: var(--surface, #f5f5f5);
    border-color: var(--border-dark, #c0c0c0);
}

.pagination-link.current {
    background-color: var(--primary, #1a73e8);
    border-color: var(--primary, #1a73e8);
    color: white;
    font-weight: 500;
}

.pagination-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
}

/* Badge styles */
.badge {
    display: inline-block;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background-color: var(--error, #ea4335);
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 20px;
    text-align: center;
    margin-left: 0.5rem;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s;
}

.modal-content {
    background-color: #fff;
    margin: 10% auto;
    max-width: 500px;
    width: 90%;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-lg, 0 10px 25px rgba(0,0,0,0.2));
    animation: slideIn 0.3s;
}

.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--text-primary, #212529);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border, #e0e0e0);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: var(--text-secondary, #6c757d);
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
}

.close-modal:hover {
    color: var(--text-primary, #212529);
}

/* Stock request form styles */
.stock-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
    background-color: var(--surface, #f5f5f5);
    border-radius: var(--border-radius-sm, 4px);
    padding: 1rem;
}

.info-item {
    margin-bottom: 0.5rem;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-label {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}

.info-value {
    font-weight: 500;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: var(--border-radius-sm, 4px);
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: var(--primary, #1a73e8);
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
    outline: none;
}

.form-text {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-top: 0.25rem;
}

.required {
    color: var(--error, #ea4335);
}

.error-message {
    color: var(--error, #ea4335);
    font-size: var(--font-size-sm, 0.875rem);
    margin-top: 0.25rem;
    display: none;
}

/* Animations */
@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        flex-direction: row;
        width: 100%;
    }
    
    .product-details {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }
    
    .data-table, .data-table tbody, .data-table tr, .data-table td {
        display: block;
    }
    
    .data-table thead {
        display: none;
    }
    
    .data-table tbody tr {
        margin-bottom: 1rem;
        border: 1px solid var(--border, #e0e0e0);
        border-radius: var(--border-radius-md, 8px);
        padding: 0.5rem;
    }
    
    .data-table td {
        padding: 0.5rem;
        border: none;
        text-align: right;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .data-table td::before {
        content: attr(data-label);
        font-weight: 600;
        margin-right: 1rem;
        text-align: left;
        flex: 1;
    }
    
    .data-table .product-cell {
        justify-content: flex-end;
    }
    
    .data-table .product-image {
        order: 2;
        margin-left: 0.75rem;
    }
    
    .data-table .product-info {
        order: 1;
        text-align: right;
    }
    
    .details-actions {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .details-actions .button {
        width: 100%;
    }
    
    .modal-content {
        margin: 20% auto;
        width: 95%;
    }
    
    .stock-info {
        grid-template-columns: 1fr;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .summary-card:hover {
        transform: none;
    }
    
    .alert {
        animation: none;
    }
    
    .filter-form {
        transition: none;
    }
    
    .modal, .modal-content {
        animation: none;
    }
    
    .warning-card .low-stock-icon,
    .alert-card .out-of-stock-icon {
        animation: none;
    }
}

/* Focus styles for better keyboard navigation */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 2px solid var(--primary, #1a73e8);
    outline-offset: 2px;
}

/* High contrast mode support */
@media (forced-colors: active) {
    .status-badge, .sales-badge, .badge,
    .card-icon {
        border: 1px solid;
    }
    
    .pagination-link.current {
        border: 2px solid;
    }
}

/* Replace the dark mode section with this improved version */
@media (prefers-color-scheme: dark) {
    /* Only apply dark mode if user has explicitly enabled it */
    body.dark-mode-enabled .summary-card, 
    body.dark-mode-enabled .filter-form, 
    body.dark-mode-enabled .dashboard-card, 
    body.dark-mode-enabled .details-section {
        background-color: #2d2d2d;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
    }
    
    /* Add a class to control dark mode instead of applying it automatically */
    body:not(.dark-mode-enabled) .summary-card,
    body:not(.dark-mode-enabled) .filter-form,
    body:not(.dark-mode-enabled) .dashboard-card,
    body:not(.dark-mode-enabled) .details-section {
        background-color: #ffffff;
    }
    
    /* Make sure light backgrounds are enforced for these elements */
    .modal-content,
    .toast,
    .product-details,
    .pagination-link {
        background-color: #ffffff !important;
    }
    
    .data-table th {
        background-color: #f5f5f5 !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const closeAlertButtons = document.querySelectorAll('.close-alert');
    closeAlertButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });

    // Toggle filters visibility
    const toggleFiltersBtn = document.getElementById('toggleFiltersBtn');
    const filterForm = document.getElementById('filterForm');
    
    // Check if filters should be shown by default (if any filter is active)
    const hasActiveFilters = <?php echo (!empty($search) || !empty($category) || !empty($stock_status)) ? 'true' : 'false'; ?>;
    
    if (!hasActiveFilters) {
        filterForm.classList.add('collapsed');
    }
    
    toggleFiltersBtn.addEventListener('click', function() {
        filterForm.classList.toggle('collapsed');
        
        // Change button text based on state
        if (filterForm.classList.contains('collapsed')) {
            this.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
            if (hasActiveFilters) {
                this.innerHTML += ' <span class="filter-badge"><?php echo countActiveFilters($search, $category, $stock_status); ?></span>';
            }
        } else {
            this.innerHTML = '<i class="fas fa-times"></i> Hide Filters';
        }
        
        // Announce to screen readers
        announceToScreenReader(filterForm.classList.contains('collapsed') ? 'Filters hidden' : 'Filters shown');
    });
    
    // Expandable rows functionality
    const inventoryTable = document.getElementById('inventoryTable');
    
    if (inventoryTable) {
        // Delegate click event for better performance
        inventoryTable.addEventListener('click', function(event) {
            // Handle view details button click
            if (event.target.closest('.view-details-btn')) {
                const row = event.target.closest('tr');
                toggleRowDetails(row);
                event.stopPropagation();
                return;
            }
            
            // Handle close details button click
            if (event.target.closest('.close-details-btn')) {
                const detailsRow = event.target.closest('.description-row');
                const productRow = detailsRow.previousElementSibling;
                
                detailsRow.style.display = 'none';
                productRow.classList.remove('expanded');
                
                event.stopPropagation();
                return;
            }
            
            // Handle request stock button click
            if (event.target.closest('.request-stock-btn')) {
                const button = event.target.closest('.request-stock-btn');
                
                if (!button.disabled) {
                    openRequestStockModal(
                        button.getAttribute('data-product-id'),
                        button.getAttribute('data-product-name'),
                        button.getAttribute('data-current-stock')
                    );
                }
                
                event.stopPropagation();
                return;
            }
            
            // Handle expandable row click
            if (event.target.closest('.expandable-row')) {
                const row = event.target.closest('.expandable-row');
                toggleRowDetails(row);
            }
        });
        
        // Helper function to toggle row details
        function toggleRowDetails(row) {
            const detailsRow = row.nextElementSibling;
            const isExpanded = row.classList.contains('expanded');
            
            // Close any other open rows
            const allExpandedRows = inventoryTable.querySelectorAll('.expandable-row.expanded');
            allExpandedRows.forEach(expandedRow => {
                if (expandedRow !== row) {
                    expandedRow.classList.remove('expanded');
                    expandedRow.nextElementSibling.style.display = 'none';
                }
            });
            
            // Toggle this row
            if (isExpanded) {
                row.classList.remove('expanded');
                detailsRow.style.display = 'none';
            } else {
                row.classList.add('expanded');
                detailsRow.style.display = 'table-row';
            }
        }
    }
    
    // Request Stock Modal functionality
    const requestStockModal = document.getElementById('requestStockModal');
    const closeModalButtons = document.querySelectorAll('.close-modal, .close-modal-btn');
    const submitRequestBtn = document.getElementById('submitRequestBtn');
    
    // Close modal functions
    function closeModal() {
        if (requestStockModal) {
            requestStockModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    // Open request stock modal
    function openRequestStockModal(productId, productName, currentStock) {
        // Set form values
        document.getElementById('product_id').value = productId;
        document.getElementById('product_name').textContent = productName;
        
        const stockElement = document.getElementById('current_stock');
        stockElement.textContent = currentStock;
        
        // Add appropriate class based on stock level
        stockElement.className = 'info-value';
        if (currentStock == 0) {
            stockElement.classList.add('quantity-out');
        } else if (currentStock <= 10) {
            stockElement.classList.add('quantity-low');
        } else {
            stockElement.classList.add('quantity-normal');
        }
        
        // Set suggested quantity based on current stock
        const quantityInput = document.getElementById('requested_quantity');
        if (currentStock == 0) {
            quantityInput.value = 50; // Higher quantity for out of stock items
            document.getElementById('priority').value = 'high';
        } else if (currentStock <= 5) {
            quantityInput.value = 30; // Medium quantity for very low stock
            document.getElementById('priority').value = 'normal';
        } else {
            quantityInput.value = 20; // Standard quantity for low stock
            document.getElementById('priority').value = 'normal';
        }
        
        // Show modal
        requestStockModal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Focus first input for accessibility
        setTimeout(() => {
            quantityInput.focus();
            quantityInput.select();
        }, 100);
    }
    
    // Close modal events
    closeModalButtons.forEach(button => {
        button.addEventListener('click', closeModal);
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === requestStockModal) {
            closeModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && requestStockModal.style.display === 'block') {
            closeModal();
        }
    });
    
    // Submit request form
    if (submitRequestBtn) {
        submitRequestBtn.addEventListener('click', function() {
            // Get form values
            const form = document.getElementById('requestStockForm');
            const productId = document.getElementById('product_id').value;
            const quantityInput = document.getElementById('requested_quantity');
            const quantity = parseInt(quantityInput.value);
            const priority = document.getElementById('priority').value;
            const notes = document.getElementById('notes').value;
            
            // Validate quantity
            if (isNaN(quantity) || quantity <= 0) {
                const quantityError = document.getElementById('quantity-error');
                quantityError.textContent = 'Please enter a valid quantity';
                quantityError.style.display = 'block';
                quantityInput.classList.add('invalid-input');
                quantityInput.focus();
                return;
            }
            
            // Clear any errors
            const quantityError = document.getElementById('quantity-error');
            quantityError.style.display = 'none';
            quantityInput.classList.remove('invalid-input');
            
            // Show loading state
            const originalBtnText = submitRequestBtn.innerHTML;
            submitRequestBtn.disabled = true;
            submitRequestBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            
            // Submit form via AJAX
            const formData = new FormData(form);
            
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Close modal
                    closeModal();
                    
                    // Show success message
                    showToast('Stock request submitted successfully!', 'success');
                    
                    // Log activity
                    logUserActivity(
                        'create', 
                        'inventory', 
                        `Requested ${quantity} units of product ID: ${productId}`
                    );
                } else {
                    // Show error message
                    showToast(data.message || 'Failed to submit request', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                // Reset button state
                submitRequestBtn.disabled = false;
                submitRequestBtn.innerHTML = originalBtnText;
            });
        });
    }
    
    // Toast notification system
    window.showToast = function(message, type = 'info') {
        // Check if toast container exists, if not create it
        let toastContainer = document.querySelector('.toast-container');
        
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        // Set icon based on type
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></div>
            <div class="toast-content">${message}</div>
            <button class="toast-close" aria-label="Close notification"><i class="fas fa-times" aria-hidden="true"></i></button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Add event listener to close button
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.add('toast-hiding');
            setTimeout(() => {
                toast.remove();
                
                // Remove container if empty
                if (toastContainer.children.length === 0) {
                    toastContainer.remove();
                }
            }, 300);
        });
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.add('toast-hiding');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                        
                        // Remove container if empty
                        if (toastContainer.children.length === 0) {
                            toastContainer.remove();
                        }
                    }
                }, 300);
            }
        }, 5000);
    };
    
    // Helper function to announce messages to screen readers
    function announceToScreenReader(message) {
        let announcer = document.getElementById('sr-announcer');
        
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'sr-announcer';
            announcer.className = 'sr-only';
            announcer.setAttribute('aria-live', 'polite');
            announcer.setAttribute('aria-atomic', 'true');
            document.body.appendChild(announcer);
        }
        
        announcer.textContent = message;
        
        setTimeout(() => {
            announcer.textContent = '';
        }, 3000);
    }
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'inventory', 'Viewed inventory list');
    }
});

// Activity logging function
function logUserActivity(actionType, module, description) {
    const userId = document.getElementById('current-user-id').value;
    
    if (!userId) return;
    
    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            action_type: actionType,
            module: module,
            description: description
        })
    })
    .catch(error => {
        console.error('Error logging activity:', error);
    });
}
</script>

<!-- Toast notification styles -->
<style>
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 350px;
}

.toast {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: toast-in 0.3s ease-out forwards;
    opacity: 0;
    transform: translateX(50px);
}

.toast-hiding {
    animation: toast-out 0.3s ease-in forwards;
}

.toast-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background-color: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.toast-content {
    flex: 1;
    font-size: 14px;
}

.toast-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: #6c757d;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

.toast-info {
    border-left: 4px solid #1a73e8;
}

.toast-info .toast-icon {
    color: #1a73e8;
}

.toast-success {
    border-left: 4px solid #34a853;
}

.toast-success .toast-icon {
    color: #34a853;
}

.toast-warning {
    border-left: 4px solid #fbbc04;
}

.toast-warning .toast-icon {
    color: #fbbc04;
}

.toast-error {
    border-left: 4px solid #ea4335;
}

.toast-error .toast-icon {
    color: #ea4335;
}

@keyframes toast-in {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes toast-out {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(50px);
    }
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@media (max-width: 480px) {
    .toast-container {
        top: auto;
        bottom: 20px;
        left: 20px;
        right: 20px;
        max-width: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .toast, .toast-hiding {
        animation: none;
        opacity: 1;
        transform: translateX(0);
    }
}
</style>

<?php
// Helper functions
function getQuantityClass($quantity) {
    if ($quantity <= 0) {
        return 'quantity-out';
    } elseif ($quantity <= 10) {
        return 'quantity-low';
    } else {
        return 'quantity-normal';
    }
}

function getStockStatus($quantity) {
    if ($quantity <= 0) {
        return 'out-of-stock';
    } elseif ($quantity <= 10) {
        return 'low-stock';
    } else {
        return 'in-stock';
    }
}

function getStockStatusLabel($quantity) {
    if ($quantity <= 0) {
        return 'Out of Stock';
    } elseif ($quantity <= 10) {
        return 'Low Stock';
    } else {
        return 'In Stock';
    }
}

function countActiveFilters($search, $category, $stock_status) {
    $count = 0;
    if (!empty($search)) $count++;
    if (!empty($category)) $count++;
    if (!empty($stock_status)) $count++;
     return $count;
}
?>