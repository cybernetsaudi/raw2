<?php
session_start();
$page_title = "Product Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get search and filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';

// Build query with filters
$where_clause = "";
$params = array();

if (!empty($search)) {
    $where_clause .= " WHERE (p.name LIKE :search OR p.sku LIKE :search OR p.description LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($category_filter)) {
    if (empty($where_clause)) {
        $where_clause .= " WHERE p.category = :category";
    } else {
        $where_clause .= " AND p.category = :category";
    }
    $params[':category'] = $category_filter;
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total FROM products p $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get products with pagination and filters
$products_query = "SELECT p.*, 
                  (SELECT COUNT(*) FROM manufacturing_batches WHERE product_id = p.id) as batch_count,
                  (SELECT SUM(quantity) FROM inventory WHERE product_id = p.id) as inventory_count,
                  u.full_name as created_by_name
                  FROM products p
                  LEFT JOIN users u ON p.created_by = u.id
                  $where_clause
                  ORDER BY p.name
                  LIMIT :offset, :records_per_page";
$products_stmt = $db->prepare($products_query);
foreach ($params as $param => $value) {
    $products_stmt->bindValue($param, $value);
}
$products_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$products_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$products_stmt->execute();

// Get categories for filter dropdown
$categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
    <h2>Product Management</h2>
    <div class="page-actions">
        <a href="add-product.php" class="button primary">
            <i class="fas fa-plus-circle"></i> Add New Product
        </a>
    </div>
</div>

<!-- Filter and Search -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="category">Category:</label>
                <select id="category" name="category" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($category); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, SKU, or description">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="products.php" class="button secondary">Reset Filters</a>
            </div>
        </div>
    </form>
</div>

<!-- Products Table -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Products</h3>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> products
            <?php else: ?>
            No products found
            <?php endif; ?>
        </div>
    </div>
    <div class="card-content">
        <?php if ($products_stmt->rowCount() > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Batches</th>
                    <th>Inventory</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($product = $products_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td>
                        <div class="product-name">
                            <?php echo htmlspecialchars($product['name']); ?>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($product['sku']); ?></td>
                    <td>
                        <?php if (!empty($product['category'])): ?>
                        <span class="category-badge">
                            <?php echo htmlspecialchars($product['category']); ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $product['batch_count']; ?></td>
                    <td>
                        <?php if ($product['inventory_count'] > 0): ?>
                        <span class="quantity-badge in-stock"><?php echo number_format($product['inventory_count']); ?></span>
                        <?php else: ?>
                        <span class="quantity-badge out-of-stock">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($product['created_by_name']); ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="edit-product.php?id=<?php echo $product['id']; ?>" class="button small">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="view-product.php?id=<?php echo $product['id']; ?>" class="button small secondary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if (!empty($search)) $pagination_query .= '&search=' . urlencode($search);
                      <?php if (!empty($category_filter)) $pagination_query .= '&category=' . urlencode($category_filter); ?>
            
            <?php if ($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link">&laquo; Previous</a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if ($start_page > 1) {
                echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                if ($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for ($i = $start_page; $i <= $end_page; $i++) {
                if ($i == $page) {
                    echo '<span class="pagination-link current">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-box"></i>
            </div>
            <h3>No Products Found</h3>
            <p>There are no products matching your criteria.</p>
            <?php if (!empty($search) || !empty($category_filter)): ?>
            <a href="products.php" class="button secondary">Clear Filters</a>
            <?php else: ?>
            <a href="add-product.php" class="button primary">Add Your First Product</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Product-specific styles */
.product-name {
    font-weight: 500;
}

.category-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    background-color: #f1f3f4;
    color: #5f6368;
}

.quantity-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    text-align: center;
    min-width: 60px;
}

.quantity-badge.in-stock {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.quantity-badge.out-of-stock {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.text-muted {
    color: #80868b;
    font-style: italic;
}

/* Empty state styling */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.empty-state-icon {
    font-size: 3rem;
    color: #dadce0;
    margin-bottom: 1rem;
}

.empty-state h3 {
    margin-bottom: 0.5rem;
    color: #5f6368;
}

.empty-state p {
    color: #80868b;
    margin-bottom: 1.5rem;
}

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .action-buttons {
        flex-direction: column;
    }
    
    .data-table th:nth-child(3),
    .data-table td:nth-child(3),
    .data-table th:nth-child(6),
    .data-table td:nth-child(6) {
        display: none;
    }
}

@media (max-width: 576px) {
    .data-table th:nth-child(4),
    .data-table td:nth-child(4) {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'products', 'Viewed product management page');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>