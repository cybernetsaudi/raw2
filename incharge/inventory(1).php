<?php
session_start();
$page_title = "Inventory Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up filters
$location_filter = isset($_GET['location']) ? $_GET['location'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build query with filters
$where_clause = "";
$params = array();

if(!empty($location_filter)) {
    $where_clause .= " AND i.location = :location";
    $params[':location'] = $location_filter;
}

if(!empty($search)) {
    $where_clause .= " AND (p.name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total 
               FROM inventory i
               JOIN products p ON i.product_id = p.id
               WHERE 1=1" . $where_clause;
$count_stmt = $db->prepare($count_query);
foreach($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get inventory items with pagination and filters
$inventory_query = "SELECT i.*, p.name as product_name, p.sku, p.price
                   FROM inventory i
                   JOIN products p ON i.product_id = p.id
                   WHERE 1=1" . $where_clause . "
                   ORDER BY p.name
                   LIMIT :offset, :records_per_page";
$inventory_stmt = $db->prepare($inventory_query);
foreach($params as $param => $value) {
    $inventory_stmt->bindValue($param, $value);
}
$inventory_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$inventory_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$inventory_stmt->execute();

// Get inventory summary by location
$summary_query = "SELECT location, COUNT(*) as item_count, SUM(quantity) as total_quantity
                 FROM inventory
                 GROUP BY location";
$summary_stmt = $db->prepare($summary_query);
$summary_stmt->execute();
$location_summary = [];
while($row = $summary_stmt->fetch(PDO::FETCH_ASSOC)) {
    $location_summary[$row['location']] = $row;
}

// Get products for transfer
$products_query = "SELECT p.id, p.name, p.sku FROM products p ORDER BY p.name";
$products_stmt = $db->prepare($products_query);
$products_stmt->execute();
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h2>Inventory Management</h2>
    <div class="page-actions">
        <button id="transferStockBtn" class="button">Transfer Stock</button>
    </div>
</div>

<div class="inventory-summary">
    <div class="summary-cards">
        <div class="summary-card">
            <div class="card-icon manufacturing-icon">
                <i class="fas fa-industry"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Manufacturing</div>
                <div class="card-value"><?php echo number_format($location_summary['manufacturing']['total_quantity'] ?? 0); ?></div>
                <div class="card-subtitle"><?php echo $location_summary['manufacturing']['item_count'] ?? 0; ?> products</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="card-icon transit-icon">
                <i class="fas fa-truck"></i>
            </div>
            <div class="card-content">
                <div class="card-title">In Transit</div>
                <div class="card-value"><?php echo number_format($location_summary['transit']['total_quantity'] ?? 0); ?></div>
                <div class="card-subtitle"><?php echo $location_summary['transit']['item_count'] ?? 0; ?> products</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="card-icon wholesale-icon">
                <i class="fas fa-store"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Wholesale</div>
                <div class="card-value"><?php echo number_format($location_summary['wholesale']['total_quantity'] ?? 0); ?></div>
                <div class="card-subtitle"><?php echo $location_summary['wholesale']['item_count'] ?? 0; ?> products</div>
            </div>
        </div>
    </div>
</div>

<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="location">Location:</label>
                <select id="location" name="location" onchange="this.form.submit()">
                    <option value="">All Locations</option>
                    <option value="manufacturing" <?php echo $location_filter === 'manufacturing' ? 'selected' : ''; ?>>Manufacturing</option>
                    <option value="transit" <?php echo $location_filter === 'transit' ? 'selected' : ''; ?>>In Transit</option>
                    <option value="wholesale" <?php echo $location_filter === 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                </select>
            </div>
            
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name or SKU">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
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
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Location</th>
                    <th>Quantity</th>
                    <th>Value</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($item = $inventory_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['sku']); ?></td>
                    <td><span class="location-badge location-<?php echo $item['location']; ?>"><?php echo ucfirst($item['location']); ?></span></td>
                    <td><?php echo number_format($item['quantity']); ?></td>
                    <td><?php echo number_format($item['quantity'] * $item['price'], 2); ?></td>
                    <td><?php echo htmlspecialchars($item['updated_at']); ?></td>
                    <td>
                        <button class="button small adjust-stock" 
                                data-id="<?php echo $item['id']; ?>"
                                data-product="<?php echo htmlspecialchars($item['product_name']); ?>"
                                data-quantity="<?php echo $item['quantity']; ?>"
                                data-location="<?php echo $item['location']; ?>">
                            Adjust
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($inventory_stmt->rowCount() === 0): ?>
                <tr>
                    <td colspan="7" class="no-records">No inventory items found matching your criteria.</td>
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
            if(!empty($location_filter)) $pagination_query .= '&location=' . urlencode($location_filter);
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
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

<!-- Transfer Stock Modal -->
<div id="transferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Transfer Inventory Stock</h2>
        <form id="transferForm" action="../api/transfer-inventory.php" method="post">
            <div class="form-group">
                <label for="product_id">Product:</label>
                <select id="product_id" name="product_id" required>
                    <option value="">Select Product</option>
                    <?php foreach($products as $product): ?>
                    <option value="<?php echo $product['id']; ?>">
                        <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['sku']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="from_location">From Location:</label>
                <select id="from_location" name="from_location" required>
                    <option value="">Select Location</option>
                    <option value="manufacturing">Manufacturing</option>
                    <option value="transit">In Transit</option>
                    <option value="wholesale">Wholesale</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="to_location">To Location:</label>
                <select id="to_location" name="to_location" required>
                    <option value="">Select Location</option>
                    <option value="manufacturing">Manufacturing</option>
                    <option value="transit">In Transit</option>
                    <option value="wholesale">Wholesale</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="quantity">Quantity to Transfer:</label>
                <input type="number" id="quantity" name="quantity" min="1" required>
                <div id="available-quantity" class="form-text"></div>
            </div>
            
            <div class="form-group">
                <label for="transfer_notes">Notes:</label>
                <textarea id="transfer_notes" name="notes" rows="3" placeholder="Add any notes about this transfer"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelTransfer">Cancel</button>
                <button type="submit" class="button">Transfer Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Adjust Inventory Stock</h2>
        <form id="adjustForm" action="../api/adjust-inventory.php" method="post">
            <input type="hidden" id="inventory_id" name="inventory_id">
            
            <div class="product-info">
                <div class="info-item">
                    <span class="info-label">Product:</span>
                    <span id="product_name" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Location:</span>
                    <span id="location_display" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Current Quantity:</span>
                    <span id="current_quantity" class="info-value"></span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="adjustment_type">Adjustment Type:</label>
                <select id="adjustment_type" name="adjustment_type" required>
                    <option value="">Select Type</option>
                    <option value="add">Add Stock</option>
                    <option value="remove">Remove Stock</option>
                    <option value="set">Set Exact Quantity</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="adjustment_quantity">Quantity:</label>
                <input type="number" id="adjustment_quantity" name="quantity" min="1" required>
            </div>
            
            <div class="form-group">
                <label for="adjustment_reason">Reason:</label>
                <select id="adjustment_reason" name="reason" required>
                    <option value="">Select Reason</option>
                    <option value="inventory_count">Inventory Count</option>
                    <option value="damaged">Damaged/Lost</option>
                    <option value="returned">Returned Items</option>
                    <option value="correction">Data Correction</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="adjustment_notes">Notes:</label>
                <textarea id="adjustment_notes" name="notes" rows="3" placeholder="Explain the reason for this adjustment"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelAdjust">Cancel</button>
                <button type="submit" class="button">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Inventory management specific styles */
.inventory-summary {
    margin-bottom: 2rem;
}

.summary-cards {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
}

.summary-card {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.25rem;
    flex: 1;
    min-width: 200px;
    display: flex;
    align-items: center;
    gap: 1rem;
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

.manufacturing-icon {
    background-color: #4285f4;
}

.transit-icon {
    background-color: #fbbc04;
}

.wholesale-icon {
    background-color: #34a853;
}

.card-content {
    flex: 1;
}

.card-title {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.card-value {
    font-size: var(--font-size-xl);
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.card-subtitle {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
}

.location-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm);
    font-weight: 500;
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

.product-info {
    background-color: var(--surface);
    border-radius: var(--border-radius-sm);
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.info-item {
    display: flex;
    margin-bottom: 0.5rem;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-label {
    width: 140px;
    font-weight: 500;
    color: var(--text-secondary);
}

.info-value {
    flex: 1;
    font-weight: 500;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .summary-cards {
        flex-direction: column;
    }
    
    .data-table th:nth-child(6),
    .data-table td:nth-child(6) {
        display: none;
    }
}

@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .filter-actions .button {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .data-table th:nth-child(2),
    .data-table td:nth-child(2),
    .data-table th:nth-child(5),
    .data-table td:nth-child(5) {
        display: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Transfer modal elements
    const transferModal = document.getElementById('transferModal');
    const transferBtn = document.getElementById('transferStockBtn');
    const transferCloseBtn = transferModal.querySelector('.close-modal');
    const cancelTransferBtn = document.getElementById('cancelTransfer');
    
    // Adjust modal elements
    const adjustModal = document.getElementById('adjustModal');
    const adjustBtns = document.querySelectorAll('.adjust-stock');
    const adjustCloseBtn = adjustModal.querySelector('.close-modal');
    const cancelAdjustBtn = document.getElementById('cancelAdjust');
    
    // Transfer form elements
    const productSelect = document.getElementById('product_id');
    const fromLocationSelect = document.getElementById('from_location');
    const toLocationSelect = document.getElementById('to_location');
    const quantityInput = document.getElementById('quantity');
    const availableQuantityDiv = document.getElementById('available-quantity');
    
    // Open transfer modal
    if (transferBtn && transferModal) {
        transferBtn.addEventListener('click', function() {
            transferModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Reset form
            document.getElementById('transferForm').reset();
            availableQuantityDiv.textContent = '';
        });
    }
    
    // Close transfer modal
    if (transferCloseBtn) {
        transferCloseBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    if (cancelTransferBtn) {
        cancelTransferBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Open adjust modal
    adjustBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const inventoryId = this.getAttribute('data-id');
            const productName = this.getAttribute('data-product');
            const quantity = this.getAttribute('data-quantity');
            const location = this.getAttribute('data-location');
            
            // Set values in the modal
            document.getElementById('inventory_id').value = inventoryId;
            document.getElementById('product_name').textContent = productName;
            document.getElementById('location_display').textContent = location.charAt(0).toUpperCase() + location.slice(1);
            document.getElementById('current_quantity').textContent = quantity;
            
            // Reset form
            document.getElementById('adjustForm').reset();
            
            // Show the modal
            adjustModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        });
    });
    
    // Close adjust modal
    if (adjustCloseBtn) {
        adjustCloseBtn.addEventListener('click', function() {
            adjustModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    if (cancelAdjustBtn) {
        cancelAdjustBtn.addEventListener('click', function() {
            adjustModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            transferModal.style.display = 'none';
            document.body.style.overflow = '';
        }
        if (event.target === adjustModal) {
            adjustModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
    
    // Handle product and location selection for transfer
    if (productSelect && fromLocationSelect) {
        const checkAvailableQuantity = function() {
            const productId = productSelect.value;
            const location = fromLocationSelect.value;
            
            if (productId && location) {
                // Fetch available quantity via AJAX
                fetch(`../api/get-inventory-quantity.php?product_id=${productId}&location=${location}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.available) {
                            availableQuantityDiv.textContent = `Available quantity: ${data.quantity}`;
                            quantityInput.max = data.quantity;
                            
                            // Enable quantity input
                            quantityInput.disabled = false;
                        } else {
                            availableQuantityDiv.textContent = 'No inventory available for this product at the selected location.';
                            quantityInput.disabled = true;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching inventory quantity:', error);
                        availableQuantityDiv.textContent = 'Error checking available quantity.';
                    });
            } else {
                availableQuantityDiv.textContent = '';
                quantityInput.disabled = true;
            }
        };
        
        productSelect.addEventListener('change', checkAvailableQuantity);
        fromLocationSelect.addEventListener('change', checkAvailableQuantity);
        
        // Prevent selecting same location for from and to
        toLocationSelect.addEventListener('change', function() {
            if (this.value === fromLocationSelect.value && this.value !== '') {
                showToast('Source and destination locations cannot be the same', 'error');
                this.value = '';
            }
        });
        
        fromLocationSelect.addEventListener('change', function() {
            if (this.value === toLocationSelect.value && this.value !== '') {
                toLocationSelect.value = '';
            }
        });
    }
    
    // Transfer form validation
    const transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Validate product selection
            if (!productSelect.value) {
                showToast('Please select a product', 'error');
                isValid = false;
            }
            
            // Validate locations
            if (!fromLocationSelect.value) {
                showToast('Please select a source location', 'error');
                isValid = false;
            }
            
            if (!toLocationSelect.value) {
                showToast('Please select a destination location', 'error');
                isValid = false;
            }
            
            if (fromLocationSelect.value === toLocationSelect.value && fromLocationSelect.value !== '') {
                showToast('Source and destination locations cannot be the same', 'error');
                isValid = false;
            }
            
            // Validate quantity
            if (quantityInput.disabled) {
                showToast('No inventory available for transfer', 'error');
                isValid = false;
            } else {
                const quantity = parseInt(quantityInput.value);
                const max = parseInt(quantityInput.max);
                
                if (isNaN(quantity) || quantity <= 0) {
                    showToast('Please enter a valid quantity', 'error');
                    isValid = false;
                } else if (quantity > max) {
                    showToast(`Quantity cannot exceed available stock (${max})`, 'error');
                    isValid = false;
                }
            }
            
            if (!isValid) {
                event.preventDefault();
            } else {
                // Log activity
                if (typeof logUserActivity === 'function') {
                    const productName = productSelect.options[productSelect.selectedIndex].text;
                    const fromLocation = fromLocationSelect.options[fromLocationSelect.selectedIndex].text;
                    const toLocation = toLocationSelect.options[toLocationSelect.selectedIndex].text;
                    
                    logUserActivity(
                        'create', 
                        'inventory_transfers', 
                        `Transferred ${quantityInput.value} units of ${productName} from ${fromLocation} to ${toLocation}`
                    );
                }
            }
        });
    }
    
    // Adjust form validation
    const adjustForm = document.getElementById('adjustForm');
    if (adjustForm) {
        const adjustTypeSelect = document.getElementById('adjustment_type');
        const adjustQuantityInput = document.getElementById('adjustment_quantity');
        const adjustReasonSelect = document.getElementById('adjustment_reason');
        
        adjustForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Validate adjustment type
            if (!adjustTypeSelect.value) {
                showToast('Please select an adjustment type', 'error');
                isValid = false;
            }
            
            // Validate quantity
            const quantity = parseInt(adjustQuantityInput.value);
            const currentQuantity = parseInt(document.getElementById('current_quantity').textContent);
            
            if (isNaN(quantity) || quantity <= 0) {
                showToast('Please enter a valid quantity', 'error');
                isValid = false;
            } else if (adjustTypeSelect.value === 'remove' && quantity > currentQuantity) {
                showToast(`Cannot remove more than the current stock (${currentQuantity})`, 'error');
                isValid = false;
            }
            
            // Validate reason
            if (!adjustReasonSelect.value) {
                showToast('Please select a reason for the adjustment', 'error');
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
            } else {
                // Log activity
                if (typeof logUserActivity === 'function') {
                    const productName = document.getElementById('product_name').textContent;
                    const adjustmentType = adjustTypeSelect.options[adjustTypeSelect.selectedIndex].text;
                    
                    logUserActivity(
                        'update', 
                        'inventory', 
                        `${adjustmentType} for ${productName}: ${quantity} units`
                    );
                }
            }
        });
    }
    
    // Helper function to show toast notifications
    function showToast(message, type = 'info') {
        // Check if toast container exists, create if not
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Remove after timeout
        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    }
    
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'inventory', 'Viewed inventory management');
    }
});
</script>

<!-- Toast notification styles -->
<style>
#toast-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toast {
    min-width: 250px;
    max-width: 350px;
    padding: 12px 16px;
    border-radius: var(--border-radius-sm);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
    color: white;
    font-size: var(--font-size-sm);
    animation: toast-in 0.3s ease-out;
    transition: transform 0.3s ease, opacity 0.3s ease;
    position: relative;
    padding-right: 36px; /* Space for close button */
}

.toast::after {
    content: '×';
    position: absolute;
    top: 8px;
    right: 12px;
    font-size: 18px;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast::after:hover {
    opacity: 1;
}

.toast-info {
    background-color: var(--primary);
}

.toast-error {
    background-color: var(--error);
}

.toast-success {
    background-color: #34a853;
}

.toast-warning {
    background-color: #fbbc04;
    color: #333;
}

.toast-hide {
    transform: translateX(100%);
    opacity: 0;
}

@keyframes toast-in {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Ensure toasts are accessible */
.toast {
    role: "alert";
    aria-live: "assertive";
}

/* Make toasts responsive on small screens */
@media (max-width: 480px) {
    #toast-container {
        left: 20px;
        right: 20px;
    }
    
    .toast {
        min-width: auto;
        width: 100%;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>