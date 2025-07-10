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
$page = isset($_GET['page']) ? $_GET['page'] : 1;
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
    $inventory_query = "SELECT i.id, p.name as product_name, p.sku, i.quantity, i.location, 
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
}

// Get shopkeepers for transfer modal
try {
    $shopkeepers_query = "SELECT id, full_name, username FROM users WHERE role = 'shopkeeper' AND is_active = 1";
    $shopkeepers_stmt = $db->prepare($shopkeepers_query);
    $shopkeepers_stmt->execute();
    $shopkeepers = $shopkeepers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Shopkeepers query error: " . $e->getMessage());
    $shopkeepers = [];
}

// Process inventory transfer if submitted
$transfer_message = '';
$transfer_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_inventory'])) {
    try {
        // Validate inputs
        $product_id = $_POST['product_id'] ?? 0;
        $quantity = $_POST['quantity'] ?? 0;
        $from_location = $_POST['from_location'] ?? '';
        $to_location = $_POST['to_location'] ?? '';
        
        if (!$product_id || !$quantity || !$from_location || !$to_location) {
            throw new Exception("All fields are required");
        }
        
        if ($from_location === $to_location) {
            throw new Exception("Source and destination locations cannot be the same");
        }
        
        // Check if there's enough inventory
        $check_query = "SELECT quantity FROM inventory WHERE product_id = ? AND location = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$product_id, $from_location]);
        $current_quantity = $check_stmt->fetch(PDO::FETCH_COLUMN);
        
        if (!$current_quantity || $current_quantity < $quantity) {
            throw new Exception("Not enough inventory available for transfer");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Reduce quantity from source location
        $reduce_query = "UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND location = ?";
        $reduce_stmt = $db->prepare($reduce_query);
        $reduce_stmt->execute([$quantity, $product_id, $from_location]);
        
        // Check if destination already has this product
        $check_dest_query = "SELECT id FROM inventory WHERE product_id = ? AND location = ?";
        $check_dest_stmt = $db->prepare($check_dest_query);
        $check_dest_stmt->execute([$product_id, $to_location]);
        
        if ($check_dest_stmt->rowCount() > 0) {
            // Update existing inventory
            $update_query = "UPDATE inventory SET quantity = quantity + ? WHERE product_id = ? AND location = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([$quantity, $product_id, $to_location]);
        } else {
            // Create new inventory record
            $insert_query = "INSERT INTO inventory (product_id, quantity, location) VALUES (?, ?, ?)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([$product_id, $quantity, $to_location]);
        }
        
        // Record the transfer
        $transfer_query = "INSERT INTO inventory_transfers 
                          (product_id, quantity, from_location, to_location, initiated_by, status) 
                          VALUES (?, ?, ?, ?, ?, 'pending')";
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->execute([
            $product_id, 
            $quantity, 
            $from_location, 
            $to_location, 
            $_SESSION['user_id']
        ]);
        
        // Commit transaction
        $db->commit();
        
        $transfer_message = "Inventory transfer initiated successfully";
        
        // Refresh the page to show updated inventory
        header("Location: inventory.php?location=$location_filter&transfer=success");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $transfer_error = $e->getMessage();
    }
}

// Check for transfer success message from redirect
$transfer_success = isset($_GET['transfer']) && $_GET['transfer'] === 'success';
?>

<div class="page-header">
    <h2>Inventory Management</h2>
    <div class="page-actions">
        <button id="transferInventoryBtn" class="button primary">
            <i class="fas fa-exchange-alt"></i> Transfer Inventory
        </button>
    </div>
</div>

<?php if ($transfer_success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span>Inventory transfer initiated successfully</span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if ($transfer_error): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $transfer_error; ?></span>
    <button type="button" class="close-alert">&times;</button>
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
        <a href="?location=<?php echo $location; ?>" class="summary-card <?php echo $isActive ? 'active' : ''; ?>">
            <div class="summary-icon">
                <?php if ($location === 'manufacturing'): ?>
                    <i class="fas fa-industry"></i>
                <?php elseif ($location === 'wholesale'): ?>
                    <i class="fas fa-warehouse"></i>
                <?php elseif ($location === 'transit'): ?>
                    <i class="fas fa-truck"></i>
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
                <div class="active-indicator"></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filter and Search -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <input type="hidden" name="location" value="<?php echo $location_filter; ?>">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name, SKU">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="inventory.php?location=<?php echo $location_filter; ?>" class="button secondary">Reset Filters</a>
            </div>
        </div>
    </form>
</div>

<!-- Inventory Table -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3><?php echo ucfirst($location_filter); ?> Inventory</h3>
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
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Batch</th>
                    <th>Quantity</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($inventory_stmt && $inventory_stmt->rowCount() > 0): ?>
                    <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td>
                            <?php if ($item['batch_id'] > 0): ?>
                                <a href="view-batch.php?id=<?php echo $item['batch_id']; ?>" class="batch-link">
                                    <?php echo htmlspecialchars($item['batch_number']); ?>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($item['batch_number']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($item['quantity']); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($item['updated_at'])); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button type="button" class="button small transfer-btn" 
                                        data-id="<?php echo $item['id']; ?>"
                                        data-product-id="<?php echo $item['product_id'] ?? 0; ?>"
                                        data-product="<?php echo htmlspecialchars($item['product_name']); ?>"
                                        data-quantity="<?php echo $item['quantity']; ?>"
                                        data-location="<?php echo $item['location']; ?>">
                                    Transfer
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                <tr>
                    <td colspan="6" class="no-records">No inventory items found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
            $pagination_query .= '&location=' . urlencode($location_filter);
            ?>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link">&laquo; Previous</a>
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
                    echo '<span class="pagination-link current">' . $i . '</span>';
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
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Transfer Inventory Modal -->
<div id="transferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" id="closeTransferModal">&times;</span>
        <h2>Transfer Inventory</h2>
        
        <form id="transferForm" method="post" action="">
            <input type="hidden" name="transfer_inventory" value="1">
            <input type="hidden" id="product_id" name="product_id" value="">
            <input type="hidden" id="from_location" name="from_location" value="">
            
            <div class="form-group">
                <label for="product_name">Product:</label>
                <input type="text" id="product_name" readonly>
            </div>
            
            <div class="form-group">
                <label for="current_location">Current Location:</label>
                <input type="text" id="current_location" readonly>
            </div>
            
            <div class="form-group">
                <label for="available_quantity">Available Quantity:</label>
                <input type="text" id="available_quantity" readonly>
            </div>
            
            <div class="form-group">
                <label for="quantity">Quantity to Transfer:</label>
                <input type="number" id="quantity" name="quantity" min="1" required>
                <div class="form-hint">Cannot exceed available quantity</div>
            </div>
            
            <div class="form-group">
                <label for="to_location">Destination:</label>
                <select id="to_location" name="to_location" required>
                    <option value="">Select Destination</option>
                    <?php foreach ($locations as $loc): ?>
                        <?php if ($loc !== $location_filter): ?>
                        <option value="<?php echo $loc; ?>"><?php echo ucfirst($loc); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelTransfer">Cancel</button>
                <button type="submit" class="button primary">Transfer Inventory</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Inventory specific styles */
.inventory-summary {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.summary-card {
    flex: 1;
    min-width: 250px;
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1.25rem;
    display: flex;
    align-items: center;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
}

.summary-card:hover {
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.summary-card.active {
    border-left: 4px solid var(--primary, #1a73e8);
    padding-left: calc(1.25rem - 4px);
}

.active-indicator {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background-color: var(--primary, #1a73e8);
}

.summary-icon {
    font-size: 2.5rem;
    color: var(--primary, #1a73e8);
    margin-right: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 60px;
}

.summary-details {
    flex: 1;
}

.summary-details h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.1rem;
}

.summary-stats {
    display: flex;
    gap: 1.5rem;
}

.stat {
    display: flex;
    flex-direction: column;
}

.stat-value {
    font-weight: 600;
    font-size: 1.2rem;
    color: var(--text-primary, #333);
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-secondary, #6c757d);
}

/* Batch link style */
.batch-link {
    color: var(--primary, #1a73e8);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}

.batch-link:hover {
    color: var(--primary-dark, #0d47a1);
    text-decoration: underline;
}

/* Alert styles */
.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-success {
    background-color: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background-color: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.alert i {
    font-size: 1.25rem;
}

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.close-alert:hover {
    opacity: 1;
}

/* Form hint */
.form-hint {
    font-size: 0.85rem;
    color: var(--text-secondary, #6c757d);
    margin-top: 0.3rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .inventory-summary {
        flex-direction: column;
    }
    
    .summary-card {
        width: 100%;
    }
    
    .summary-stats {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal elements
    const transferModal = document.getElementById('transferModal');
    const closeTransferModal = document.getElementById('closeTransferModal');
    const cancelTransfer = document.getElementById('cancelTransfer');
    const transferInventoryBtn = document.getElementById('transferInventoryBtn');
    
    // Transfer form elements
    const transferForm = document.getElementById('transferForm');
    const productIdInput = document.getElementById('product_id');
    const productNameInput = document.getElementById('product_name');
    const fromLocationInput = document.getElementById('from_location');
    const currentLocationInput = document.getElementById('current_location');
    const availableQuantityInput = document.getElementById('available_quantity');
    const quantityInput = document.getElementById('quantity');
    
    // Individual transfer buttons
    const transferButtons = document.querySelectorAll('.transfer-btn');
    
    // Open modal from main transfer button
    if (transferInventoryBtn) {
        transferInventoryBtn.addEventListener('click', function() {
            openTransferModal();
        });
    }
    
    // Open modal from individual transfer buttons
    transferButtons.forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product');
            const quantity = this.getAttribute('data-quantity');
            const location = this.getAttribute('data-location');
            
            // Set form values
            productIdInput.value = productId;
            productNameInput.value = productName;
            fromLocationInput.value = location;
            currentLocationInput.value = ucfirst(location);
            availableQuantityInput.value = quantity;
            quantityInput.value = '';
            quantityInput.max = quantity;
            
            openTransferModal();
        });
    });
    
    // Close modal functions
    if (closeTransferModal) {
        closeTransferModal.addEventListener('click', closeTransferModal);
    }
    
    if (cancelTransfer) {
        cancelTransfer.addEventListener('click', closeTransferModal);
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            closeTransferModal();
        }
    });
    
    // Form validation
    if (transferForm) {
        transferForm.addEventListener('submit', function(event) {
            const quantity = parseInt(quantityInput.value);
            const availableQuantity = parseInt(availableQuantityInput.value);
            
            if (isNaN(quantity) || quantity <= 0) {
                event.preventDefault();
                showError('Please enter a valid quantity');
                return;
            }
            
            if (quantity > availableQuantity) {
                event.preventDefault();
                showError('Transfer quantity cannot exceed available quantity');
                return;
            }
        });
    }
    
    // Quantity input validation
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            const quantity = parseInt(this.value);
            const availableQuantity = parseInt(availableQuantityInput.value);
            
            if (quantity > availableQuantity) {
                this.setCustomValidity('Transfer quantity cannot exceed available quantity');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // Alert close buttons
    const alertCloseButtons = document.querySelectorAll('.close-alert');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Helper functions
    function openTransferModal() {
        if (transferModal) {
            transferModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeTransferModal() {
        if (transferModal) {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    
    function showError(message) {
        alert(message);
    }
    
    function ucfirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>