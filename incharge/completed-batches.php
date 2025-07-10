<?php
session_start();
$page_title = "Completed Batches";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get completed batches that are still in manufacturing location
$completed_query = "SELECT b.id, b.batch_number, p.name as product_name, p.sku, 
                   b.quantity_produced, b.completion_date, i.quantity as inventory_quantity
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   LEFT JOIN inventory i ON i.product_id = p.id AND i.location = 'manufacturing' AND i.batch_id = b.id
                   WHERE b.status = 'completed'
                   AND (i.quantity > 0 OR i.quantity IS NULL)
                   ORDER BY b.completion_date DESC";
$completed_stmt = $db->prepare($completed_query);
$completed_stmt->execute();

// Get shopkeepers for transfer dropdown
$shopkeepers_query = "SELECT id, full_name, username FROM users WHERE role = 'shopkeeper' AND is_active = 1";
$shopkeepers_stmt = $db->prepare($shopkeepers_query);
$shopkeepers_stmt->execute();
$shopkeepers = $shopkeepers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process transfer submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_inventory'])) {
    try {
        // Validate inputs
        $batch_id = isset($_POST['batch_id']) ? intval($_POST['batch_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
        $shopkeeper_id = isset($_POST['shopkeeper_id']) ? intval($_POST['shopkeeper_id']) : 0;
        
        if (!$batch_id || !$product_id || !$quantity || !$shopkeeper_id) {
            throw new Exception("All fields are required");
        }
        
        // Check if there's enough inventory
        $check_query = "SELECT i.id, i.quantity 
                       FROM inventory i 
                       WHERE i.product_id = ? AND i.location = 'manufacturing' AND i.batch_id = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$product_id, $batch_id]);
        
        $inventory_id = null;
        $current_quantity = 0;
        
        if ($check_stmt->rowCount() > 0) {
            $inventory = $check_stmt->fetch(PDO::FETCH_ASSOC);
            $inventory_id = $inventory['id'];
            $current_quantity = $inventory['quantity'];
        }
        
        if ($current_quantity < $quantity) {
            throw new Exception("Not enough inventory available for transfer");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Update or create inventory record
        if ($inventory_id) {
            // Reduce quantity from manufacturing
            $reduce_query = "UPDATE inventory SET quantity = quantity - ? WHERE id = ?";
            $reduce_stmt = $db->prepare($reduce_query);
            $reduce_stmt->execute([$quantity, $inventory_id]);
        } else {
            throw new Exception("Inventory record not found");
        }
        
        // Check if transit inventory exists
        $check_transit_query = "SELECT id FROM inventory WHERE product_id = ? AND location = 'transit'";
        $check_transit_stmt = $db->prepare($check_transit_query);
        $check_transit_stmt->execute([$product_id]);
        
        if ($check_transit_stmt->rowCount() > 0) {
            // Update existing transit inventory
            $transit_id = $check_transit_stmt->fetch(PDO::FETCH_COLUMN);
            $update_transit_query = "UPDATE inventory SET 
                                    quantity = quantity + ?, 
                                    updated_at = NOW() 
                                    WHERE id = ?";
            $update_transit_stmt = $db->prepare($update_transit_query);
            $update_transit_stmt->execute([$quantity, $transit_id]);
        } else {
            // Create new transit inventory
            $insert_transit_query = "INSERT INTO inventory 
                                    (product_id, batch_id, quantity, location, updated_at) 
                                    VALUES (?, ?, ?, 'transit', NOW())";
            $insert_transit_stmt = $db->prepare($insert_transit_query);
            $insert_transit_stmt->execute([$product_id, $batch_id, $quantity]);
        }
        
        // Record the transfer
        $transfer_query = "INSERT INTO inventory_transfers 
                          (product_id, quantity, from_location, to_location, initiated_by, status, shopkeeper_id) 
                          VALUES (?, ?, 'manufacturing', 'transit', ?, 'pending', ?)";
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->execute([
            $product_id, 
            $quantity, 
            $_SESSION['user_id'],
            $shopkeeper_id
        ]);
        
        $transfer_id = $db->lastInsertId();
        
        // Create notification for shopkeeper
        $notification_query = "INSERT INTO notifications 
                              (user_id, type, message, related_id, is_read, created_at) 
                              VALUES (?, 'inventory_transfer', ?, ?, 0, NOW())";
        $notification_stmt = $db->prepare($notification_query);
        $notification_stmt->execute([
            $shopkeeper_id,
            "New inventory shipment of {$quantity} units is on the way",
            $transfer_id
        ]);
        
        // Log the activity
        $activity_query = "INSERT INTO activity_logs 
                          (user_id, action_type, module, description, entity_id) 
                          VALUES (?, 'create', 'inventory_transfer', ?, ?)";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->execute([
            $_SESSION['user_id'],
            "Transferred {$quantity} units from manufacturing to transit for shopkeeper ID: {$shopkeeper_id}",
            $transfer_id
        ]);
        
        // Commit transaction
        $db->commit();
        
        $success_message = "Inventory transfer initiated successfully";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        $error_message = $e->getMessage();
    }
}
?>

<div class="page-header">
    <h2>Completed Batches Ready for Transfer</h2>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $success_message; ?></span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Completed Batches in Manufacturing</h3>
    </div>
    <div class="card-content">
        <?php if ($completed_stmt->rowCount() > 0): ?>
            <p class="info-text">
                These batches have been completed and are ready to be transferred to shopkeepers for wholesale distribution.
            </p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Completion Date</th>
                        <th>Available Inventory</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($batch = $completed_stmt->fetch(PDO::FETCH_ASSOC)): 
                        // Calculate available inventory
                        $available_quantity = isset($batch['inventory_quantity']) ? $batch['inventory_quantity'] : $batch['quantity_produced'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td>
                            <div class="product-cell">
                                <div class="product-name"><?php echo htmlspecialchars($batch['product_name']); ?></div>
                                <div class="product-sku"><?php echo htmlspecialchars($batch['sku']); ?></div>
                            </div>
                        </td>
                        <td><?php echo number_format($batch['quantity_produced']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($batch['completion_date'])); ?></td>
                        <td>
                            <span class="quantity-badge <?php echo $available_quantity > 0 ? 'in-stock' : 'out-of-stock'; ?>">
                                <?php echo number_format($available_quantity); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($available_quantity > 0 && count($shopkeepers) > 0): ?>
                                <button type="button" class="button small transfer-btn" 
                                        data-batch-id="<?php echo $batch['id']; ?>"
                                        data-product-id="<?php echo isset($batch['product_id']) ? $batch['product_id'] : ''; ?>"
                                        data-product-name="<?php echo htmlspecialchars($batch['product_name']); ?>"
                                        data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                        data-available="<?php echo $available_quantity; ?>">
                                    <i class="fas fa-exchange-alt"></i> Transfer to Shopkeeper
                                </button>
                            <?php elseif ($available_quantity <= 0): ?>
                                <span class="status-badge status-transferred">No Inventory</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">No Shopkeepers Available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-box-open"></i>
                </div>
                <h3>No Completed Batches</h3>
                <p>There are no completed manufacturing batches ready for transfer at this time.</p>
                <a href="manufacturing.php" class="button secondary">View All Batches</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Transfers Table -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Recent Transfers</h3>
    </div>
    <div class="card-content">
        <?php
        // Get recent transfers
        $transfers_query = "SELECT t.id, p.name as product_name, t.quantity, t.from_location, 
                           t.to_location, t.transfer_date, t.status, u.full_name as shopkeeper_name
                           FROM inventory_transfers t
                           JOIN products p ON t.product_id = p.id
                           LEFT JOIN users u ON t.shopkeeper_id = u.id
                           ORDER BY t.transfer_date DESC
                           LIMIT 10";
        $transfers_stmt = $db->prepare($transfers_query);
        $transfers_stmt->execute();
        ?>
        
        <?php if ($transfers_stmt->rowCount() > 0): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Shopkeeper</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($transfer = $transfers_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo date('M j, Y', strtotime($transfer['transfer_date'])); ?></td>
                        <td><?php echo htmlspecialchars($transfer['product_name']); ?></td>
                        <td><?php echo number_format($transfer['quantity']); ?></td>
                        <td>
                            <span class="location-badge location-<?php echo $transfer['from_location']; ?>">
                                <?php echo ucfirst($transfer['from_location']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="location-badge location-<?php echo $transfer['to_location']; ?>">
                                <?php echo ucfirst($transfer['to_location']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($transfer['shopkeeper_name'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="status-badge status-<?php echo $transfer['status']; ?>">
                                <?php echo ucfirst($transfer['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <p>No recent transfers found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Transfer Modal -->
<div id="transferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Transfer to Shopkeeper</h2>
        
        <form id="transferForm" method="post" action="">
            <input type="hidden" name="transfer_inventory" value="1">
            <input type="hidden" id="batch_id" name="batch_id" value="">
            <input type="hidden" id="product_id" name="product_id" value="">
            
            <div class="form-group">
                <label for="batch_number">Batch:</label>
                <input type="text" id="batch_number" readonly>
            </div>
            
            <div class="form-group">
                <label for="product_name">Product:</label>
                <input type="text" id="product_name" readonly>
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
                <label for="shopkeeper_id">Transfer to Shopkeeper:</label>
                <select id="shopkeeper_id" name="shopkeeper_id" required>
                    <option value="">Select Shopkeeper</option>
                    <?php foreach ($shopkeepers as $shopkeeper): ?>
                    <option value="<?php echo $shopkeeper['id']; ?>">
                        <?php echo htmlspecialchars($shopkeeper['full_name']); ?> (<?php echo htmlspecialchars($shopkeeper['username']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="shipping-info">
                <h3>Transfer Information</h3>
                <p>The inventory will be moved to transit status until the shopkeeper confirms receipt.</p>
                <p>A notification will be sent to the shopkeeper about this transfer.</p>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelTransfer">Cancel</button>
                <button type="submit" class="button primary">Initiate Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Quantity Badge */
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

/* Product Cell */
.product-cell {
    display: flex;
    flex-direction: column;
}

.product-name {
    font-weight: 500;
}

.product-sku {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Empty State */
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

/* Location Badge */
.location-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.875rem;
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

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    text-align: center;
    min-width: 80px;
}

.status-pending {
    background-color: #fff3cd;
    color: #856404;
}

.status-confirmed {
    background-color: #d4edda;
    color: #155724;
}

.status-cancelled {
    background-color: #f8d7da;
    color: #721c24;
}

.status-transferred {
    background-color: #e8f0fe;
    color: #1967d2;
}

/* Info Text */
.info-text {
    background-color: #e8f0fe;
    border-left: 4px solid #1a73e8;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 4px;
    color: #174ea6;
}

/* Modal Styles */
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
}

.modal-content {
    background-color: white;
    margin: 50px auto;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    max-width: 500px;
    width: 100%;
    position: relative;
    animation: modalFadeIn 0.3s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

.close-modal {
    position: absolute;
    top: 1rem;
    right: 1.5rem;
    font-size: 1.5rem;
    color: #6c757d;
    cursor: pointer;
    transition: color 0.2s;
}

.close-modal:hover {
    color: #343a40;
}

/* Form Styles */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 1rem;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #4285f4;
    box-shadow: 0 0 0 3px rgba(66, 133, 244, 0.25);
    outline: none;
}

.form-group input[readonly] {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

.form-hint {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 0.5rem;
}

/* Shipping Info Section */
.shipping-info {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 1rem;
    margin: 1.5rem 0;
    border-left: 4px solid #6c757d;
}

.shipping-info h3 {
    margin-top: 0;
    font-size: 1rem;
    color: #495057;
}

.shipping-info p {
    margin: 0.5rem 0 0;
    font-size: 0.9rem;
    color: #6c757d;
}

.shipping-info p:last-child {
    margin-bottom: 0;
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 2rem;
}

.button {
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.button.primary {
    background-color: #4285f4;
    color: white;
}

.button.primary:hover {
    background-color: #3367d6;
}

.button.secondary {
    background-color: #f8f9fa;
    color: #495057;
    border: 1px solid #ced4da;
}

.button.secondary:hover {
    background-color: #e9ecef;
}

.button.small {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Alert Styles */
.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: fadeIn 0.3s ease-out;
    transition: opacity 0.3s ease, transform 0.3s ease;
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

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    .modal-content {
        margin: 20px;
        padding: 1.5rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Transfer Modal Functionality
    const transferModal = document.getElementById('transferModal');
    const transferButtons = document.querySelectorAll('.transfer-btn');
    const closeModalBtn = document.querySelector('.close-modal');
    const cancelTransferBtn = document.getElementById('cancelTransfer');
    
    // Open modal when transfer button is clicked
    transferButtons.forEach(button => {
        button.addEventListener('click', function() {
            const batchId = this.getAttribute('data-batch-id');
            const productId = this.getAttribute('data-product-id');
            const productName = this.getAttribute('data-product-name');
            const batchNumber = this.getAttribute('data-batch-number');
            const availableQuantity = this.getAttribute('data-available');
            
            // Set form values
            document.getElementById('batch_id').value = batchId;
            document.getElementById('product_id').value = productId;
            document.getElementById('batch_number').value = batchNumber;
            document.getElementById('product_name').value = productName;
            document.getElementById('available_quantity').value = availableQuantity;
            document.getElementById('quantity').max = availableQuantity;
            document.getElementById('quantity').value = availableQuantity;
            
            // Show modal
            transferModal.style.display = 'block';
        });
    });
    
    // Close modal when X is clicked
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
        });
    }
    
    // Close modal when Cancel button is clicked
    if (cancelTransferBtn) {
        cancelTransferBtn.addEventListener('click', function() {
            transferModal.style.display = 'none';
        });
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            transferModal.style.display = 'none';
        }
    });
    
    // Form validation
    const transferForm = document.getElementById('transferForm');
    if (transferForm) {
        transferForm.addEventListener('submit', function(event) {
            const quantity = parseInt(document.getElementById('quantity').value);
            const availableQuantity = parseInt(document.getElementById('available_quantity').value);
            
            if (isNaN(quantity) || quantity <= 0) {
                event.preventDefault();
                alert('Please enter a valid quantity');
                return;
            }
            
            if (quantity > availableQuantity) {
                event.preventDefault();
                alert('Transfer quantity cannot exceed available quantity');
                return;
            }
            
            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            // Log activity
            if (typeof logUserActivity === 'function') {
                const productName = document.getElementById('product_name').value;
                const batchNumber = document.getElementById('batch_number').value;
                const shopkeeperSelect = document.getElementById('shopkeeper_id');
                const shopkeeperName = shopkeeperSelect.options[shopkeeperSelect.selectedIndex].text;
                
                logUserActivity(
                    'create', 
                    'inventory_transfer', 
                    `Transferred ${quantity} units of ${productName} (Batch: ${batchNumber}) to ${shopkeeperName}`
                );
            }
        });
    }
    
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.close-alert');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.parentElement;
            // Add fade-out animation
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            // Remove from DOM after animation completes
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });
    
    // Auto-dismiss success alerts after 5 seconds
    const successAlerts = document.querySelectorAll('.alert-success');
    successAlerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
    
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'completed_batches', 'Viewed completed batches ready for transfer');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>