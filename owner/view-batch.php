<?php
// Add these lines at the very top of view-batch.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Rest of your code...
session_start();
$page_title = "View Manufacturing Batch";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Ensure user is an owner
if ($_SESSION['role'] !== 'owner') {
    header('Location: ../index.php');
    exit;
}
function formatValue($value, $default = 'Not specified') {
    return $value !== null ? htmlspecialchars($value) : '<span class="empty-value">' . $default . '</span>';
}
// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if batch ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: manufacturing.php');
    exit;
}

$batch_id = $_GET['id'];

// Get batch details - FIXED: Consistent parameter binding
$query = "SELECT b.*, p.name as product_name, p.sku, p.description as product_description,
          u.full_name as created_by_name
          FROM manufacturing_batches b
          JOIN products p ON b.product_id = p.id
          JOIN users u ON b.created_by = u.id
          WHERE b.id = :batch_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
$stmt->execute();

if($stmt->rowCount() === 0) {
    // Display a user-friendly error message instead of redirecting
    echo '<div class="alert alert-error">Batch not found. The requested batch either does not exist or you do not have permission to view it.</div>';
    include_once '../includes/footer.php';
    exit;
}

$batch = $stmt->fetch(PDO::FETCH_ASSOC);

// Get batch status history from activity logs
try {
    $history_query = "SELECT a.id, a.description, a.created_at as updated_at, 
                     u.full_name as updated_by_name,
                     SUBSTRING_INDEX(SUBSTRING_INDEX(a.description, 'from ', -1), ' to', 1) as previous_status,
                     SUBSTRING_INDEX(a.description, 'to ', -1) as status
                     FROM activity_logs a
                     JOIN users u ON a.user_id = u.id
                     WHERE a.module = 'manufacturing' 
                     AND a.action_type = 'update'
                     AND a.entity_id = :batch_id
                     AND a.description LIKE '%status from%to%'
                     ORDER BY a.created_at DESC";
    $history_stmt = $db->prepare($history_query);
    $history_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $history_stmt->execute();
    
    // Add current status as first item if no history exists
    if ($history_stmt->rowCount() === 0) {
        // Get creation log
        $creation_query = "SELECT a.id, a.description, a.created_at as updated_at, 
                          u.full_name as updated_by_name
                          FROM activity_logs a
                          JOIN users u ON a.user_id = u.id
                          WHERE a.module = 'manufacturing' 
                          AND a.action_type = 'create'
                          AND a.entity_id = :batch_id
                          ORDER BY a.created_at DESC
                          LIMIT 1";
        $creation_stmt = $db->prepare($creation_query);
        $creation_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
        $creation_stmt->execute();
        
        if ($creation_stmt->rowCount() > 0) {
            $creation_log = $creation_stmt->fetch(PDO::FETCH_ASSOC);
            // We'll handle this in the view
            $has_creation_log = true;
        } else {
            $has_creation_log = false;
        }
    } else {
        $has_creation_log = false;
    }
} catch (PDOException $e) {
    error_log("Error fetching status history: " . $e->getMessage());
    // Set empty result set
    $history_stmt = new PDOStatement();
    $has_creation_log = false;
}

// Get batch costs - FIXED: Consistent parameter binding
$costs_query = "SELECT c.*, u.full_name as recorded_by_name
               FROM manufacturing_costs c
               JOIN users u ON c.recorded_by = u.id
               WHERE c.batch_id = :batch_id
               ORDER BY c.recorded_date DESC";
$costs_stmt = $db->prepare($costs_query);
$costs_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
$costs_stmt->execute();

// Calculate total costs
$total_costs = 0;
$costs = [];
while($cost = $costs_stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_costs += $cost['amount'];
    $costs[] = $cost;
}

// Get materials used in this batch
$materials_query = "SELECT m.*, mu.quantity_required
                   FROM raw_materials m
                   INNER JOIN material_usage mu ON mu.material_id = m.id
                   WHERE mu.batch_id = :batch_id";
$materials_stmt = $db->prepare($materials_query);
$materials_stmt->bindParam(':batch_id', $batch_id);
$materials_stmt->execute();
$materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

// Option 2: Show all materials with zeros for unused ones (better for material selection)
// $materials_query = "SELECT m.id, m.name, m.unit, COALESCE(bm.quantity, 0) as quantity
//                    FROM raw_materials m
//                    LEFT JOIN batch_materials bm ON bm.material_id = m.id AND bm.batch_id = :batch_id";
// $materials_stmt = $db->prepare($materials_query);
// 

// Debug information (comment out in production)
/*
if ($materials_stmt->rowCount() === 0) {
    echo '<div class="alert alert-info">No materials found for this batch (ID: ' . $batch_id . ').</div>';
}
*/
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &raquo; 
    <a href="manufacturing.php">Manufacturing</a> &raquo; 
    Batch #<?php echo htmlspecialchars($batch['batch_number']); ?>
</div>

<?php if(isset($_GET['success'])): ?>
<div class="alert alert-success">
    <?php if($_GET['success'] == 1): ?>
    Batch status has been updated successfully.
    <?php elseif($_GET['success'] == 2): ?>
    Manufacturing cost has been recorded successfully.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="batch-container">
    <div class="batch-header">
        <div class="batch-title">
            <h2>Batch #<?php echo htmlspecialchars($batch['batch_number']); ?></h2>
            <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
        </div>
        <div class="batch-actions">
            <?php if($batch['status'] !== 'completed'): ?>
            <button id="updateStatusBtn" class="button">Update Status</button>
            <?php endif; ?>
            <button id="addCostBtn" class="button">Record Cost</button>
            <a href="manufacturing.php" class="button secondary">Back to List</a>
        </div>
    </div>
    
    <div class="batch-details-grid">
        <div class="batch-details-card">
            <h3>Batch Information</h3>
            <div class="details-list">
                <div class="detail-item">
                    <span class="detail-label">Product:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['product_name']); ?> (<?php echo htmlspecialchars($batch['sku']); ?>)</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Quantity Produced:</span>
                    <span class="detail-value"><?php echo number_format($batch['quantity_produced']); ?> units</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Start Date:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['start_date']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Expected Completion:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['expected_completion_date']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Actual Completion:</span>
                    <span class="detail-value">
                        <?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : 'Not completed yet'; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Costs:</span>
                    <span class="detail-value"><?php echo number_format($total_costs, 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Cost Per Unit:</span>
                    <span class="detail-value">
                        <?php echo $batch['quantity_produced'] > 0 ? number_format($total_costs / $batch['quantity_produced'], 2) : 'N/A'; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Created By:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['created_by_name']); ?></span>
                </div>
            </div>
        </div>
        
        <div class="batch-details-card">
            <h3>Product Details</h3>
            <div class="product-details">
                <div class="detail-item">
                    <span class="detail-label">Product Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['product_name']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">SKU:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($batch['sku']); ?></span>
                </div>
                <!-- Then in your HTML -->
                <div class="detail-item full-width">
                    <span class="detail-label">Description:</span>
                    <span class="detail-value">
                        <?php if (!empty($batch['product_description'])): ?>
                            <?php echo nl2br(htmlspecialchars($batch['product_description'])); ?>
                        <?php else: ?>
                            <span class="empty-value">No description available</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
 <div class="batch-details-card full-width">
    <h3>Status Timeline</h3>
    <div class="status-timeline">
        <?php if($history_stmt->rowCount() > 0 || (isset($has_creation_log) && $has_creation_log)): ?>
        <ul class="timeline">
            <!-- Current status -->
            <li class="timeline-item current">
                <div class="timeline-marker status-<?php echo $batch['status']; ?>"></div>
                <div class="timeline-content">
                    <h4 class="timeline-title">Current: <?php echo ucfirst($batch['status']); ?></h4>
                    <p class="timeline-info">
                        <?php if($batch['updated_at'] !== $batch['created_at']): ?>
                            Last updated on <?php echo date('M j, Y, g:i a', strtotime($batch['updated_at'])); ?>
                        <?php else: ?>
                            Set when batch was created
                        <?php endif; ?>
                    </p>
                </div>
            </li>
            
            <!-- Status history from activity logs -->
            <?php while($history_stmt && $history = $history_stmt->fetch(PDO::FETCH_ASSOC)): ?>
            <li class="timeline-item">
                <div class="timeline-marker status-<?php echo htmlspecialchars($history['status']); ?>"></div>
                <div class="timeline-content">
                    <h4 class="timeline-title">
                        Changed to: <?php echo ucfirst(htmlspecialchars($history['status'])); ?>
                    </h4>
                    <p class="timeline-info">
                        From: <?php echo ucfirst(htmlspecialchars($history['previous_status'])); ?><br>
                        Updated by <?php echo htmlspecialchars($history['updated_by_name']); ?> 
                        on <?php echo date('M j, Y, g:i a', strtotime($history['updated_at'])); ?>
                    </p>
                    <?php 
                    // Extract notes if they exist in the description
                    $description = $history['description'];
                    $notes_pos = strpos($description, "Notes:");
                    if ($notes_pos !== false):
                        $notes = substr($description, $notes_pos + 6); // +6 to skip "Notes:"
                    ?>
                    <p class="timeline-notes"><?php echo nl2br(htmlspecialchars(trim($notes))); ?></p>
                    <?php endif; ?>
                </div>
            </li>
            <?php endwhile; ?>
            
            <!-- Creation log if no status updates exist -->
            <?php if(isset($has_creation_log) && $has_creation_log): ?>
            <li class="timeline-item">
                <div class="timeline-marker status-created"></div>
                <div class="timeline-content">
                    <h4 class="timeline-title">Batch Created</h4>
                    <p class="timeline-info">
                        Created by <?php echo htmlspecialchars($creation_log['updated_by_name']); ?> 
                        on <?php echo date('M j, Y, g:i a', strtotime($creation_log['updated_at'])); ?>
                    </p>
                    <p class="timeline-notes">Initial status: <?php echo ucfirst($batch['status']); ?></p>
                </div>
            </li>
            <?php endif; ?>
        </ul>
        <?php else: ?>
        <div class="timeline-fallback">
            <p class="no-data">No detailed status history available.</p>
            <div class="current-status-display">
                <h4>Current Status: <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></h4>
                <p>This batch was created on <?php echo date('M j, Y', strtotime($batch['created_at'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
        
       
        <div class="batch-details-card full-width">
    <h3>Cost Analysis</h3>
    <div id="batch-costing-container">
        <div class="loading-spinner">Loading cost analysis...</div>
    </div>
</div>
        <div class="batch-details-card full-width">
            <h3>Notes</h3>
            <div class="batch-notes">
                <?php if(!empty($batch['notes'])): ?>
                <p><?php echo nl2br(htmlspecialchars($batch['notes'])); ?></p>
                <?php else: ?>
                <p class="no-data">No notes available for this batch.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div id="statusModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Update Batch Status</h2>
        <form id="statusForm" action="../api/update-batch-status.php" method="post">
            <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
            <input type="hidden" name="redirect_url" value="../owner/view-batch.php?id=<?php echo $batch_id; ?>&success=1">
            
            <div class="form-group">
                <label for="status">New Status:</label>
                <select id="status" name="status" required>
                    <option value="">Select Status</option>
                    <option value="pending" <?php echo $batch['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="cutting" <?php echo $batch['status'] === 'cutting' ? 'selected' : ''; ?>>Cutting</option>
                    <option value="stitching" <?php echo $batch['status'] === 'stitching' ? 'selected' : ''; ?>>Stitching</option>
                    <option value="ironing" <?php echo $batch['status'] === 'ironing' ? 'selected' : ''; ?>>Ironing</option>
                    <option value="packaging" <?php echo $batch['status'] === 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                    <option value="completed" <?php echo $batch['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes:</label>
                <textarea id="notes" name="notes" rows="3" placeholder="Add any notes about this status change"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelStatus">Cancel</button>
                <button type="submit" class="button">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Cost Modal -->
<div id="costModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Record Manufacturing Cost</h2>
        <form id="costForm" action="../api/save-manufacturing-cost.php" method="post">
            <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
            <input type="hidden" name="redirect_url" value="../owner/view-batch.php?id=<?php echo $batch_id; ?>&success=2">
            
            <div class="form-group">
                <label for="cost_type">Cost Type:</label>
                <select id="cost_type" name="cost_type" required>
                    <option value="">Select Cost Type</option>
                    <option value="labor">Labor</option>
                    <option value="overhead">Overhead</option>
                    <option value="electricity">Electricity</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="other">Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="amount">Amount:</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
            </div>
            
            <div class="form-group">
                <label for="recorded_date">Date:</label>
                <input type="date" id="recorded_date" name="recorded_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3" placeholder="Describe this cost"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelCost">Cancel</button>
                <button type="submit" class="button">Record Cost</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Define CSS variables for consistent styling */
:root {
    --primary: #1a73e8;
    --border: #e0e0e0;
    --border-radius-sm: 4px;
    --border-radius-md: 8px;
    --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.1);
    --surface: #f8f9fa;
    --text-secondary: #6c757d;
    --font-size-sm: 0.875rem;
    --font-size-md: 1rem;
}

/* Batch details specific styles */
.batch-container {
    margin-bottom: 2rem;
}

.batch-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.batch-title {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.batch-title h2 {
    margin: 0;
}

.batch-actions {
    display: flex;
    gap: 1rem;
}

.batch-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
}

.batch-details-card {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.5rem;
}

.batch-details-card.full-width {
    grid-column: 1 / -1;
}

.batch-details-card h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border);
    color: var(--primary);
}

.details-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-item {
    display: flex;
    gap: 0.5rem;
}

.detail-item.full-width {
    flex-direction: column;
    gap: 0.25rem;
}
.empty-value {
    color: #6c757d;
    font-style: italic;
    opacity: 0.8;
}

.no-description {
    color: #6c757d;
    font-style: italic;
    display: block;
    padding: 0.5rem;
    background-color: #f8f9fa;
    border-radius: 4px;
    text-align: center;
}
.detail-label {
    font-weight: 500;
    color: var(--text-secondary);
    min-width: 140px;
}

.detail-value {
    flex: 1;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: white;
}

.status-pending { background-color: #fbbc04; }
.status-cutting { background-color: #4285f4; }
.status-stitching { background-color: #673ab7; }
.status-ironing { background-color: #f06292; }
.status-packaging { background-color: #ff7043; }
.status-completed { background-color: #34a853; }

/* Status timeline enhancements */
.timeline {
    position: relative;
    padding-left: 2rem;
    margin: 0;
    list-style: none;
}

.timeline::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    left: 16px;
    width: 2px;
    background-color: #e0e0e0;
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item.current .timeline-marker::after {
    content: '';
    position: absolute;
    top: -4px;
    left: -4px;
    right: -4px;
    bottom: -4px;
    border: 2px solid currentColor;
    border-radius: 50%;
    opacity: 0.5;
}

.timeline-marker {
    position: absolute;
    top: 4px;
    left: -30px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background-color: #9e9e9e;
    z-index: 1;
}

.timeline-marker.status-pending { background-color: #fbbc04; }
.timeline-marker.status-cutting { background-color: #4285f4; }
.timeline-marker.status-stitching { background-color: #673ab7; }
.timeline-marker.status-ironing { background-color: #f06292; }
.timeline-marker.status-packaging { background-color: #ff7043; }
.timeline-marker.status-completed { background-color: #34a853; }
.timeline-marker.status-created { background-color: #1a73e8; }

.timeline-content {
    padding: 0.75rem 1rem;
    background-color: #f8f9fa;
    border-radius: 4px;
}

.timeline-title {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    font-weight: 600;
}

.timeline-info {
    margin: 0 0 0.5rem;
    font-size: 0.875rem;
    color: #6c757d;
}

.timeline-notes {
    margin: 0.5rem 0 0;
    padding-top: 0.5rem;
    border-top: 1px dashed #e0e0e0;
    font-size: 0.875rem;
}

.timeline-fallback {
    text-align: center;
    padding: 1.5rem;
    background-color: #f8f9fa;
    border-radius: 4px;
}

.current-status-display {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 4px;
    background-color: #e8f0fe;
}

.current-status-display h4 {
    margin: 0 0 0.5rem;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: white;
}

.status-badge.status-pending { background-color: #fbbc04; }
.status-badge.status-cutting { background-color: #4285f4; }
.status-badge.status-stitching { background-color: #673ab7; }
.status-badge.status-ironing { background-color: #f06292; }
.status-badge.status-packaging { background-color: #ff7043; }
.status-badge.status-completed { background-color: #34a853; }

.no-data {
    padding: 1rem;
    background-color: var(--surface);
    border-radius: var(--border-radius-sm);
    color: var(--text-secondary);
    text-align: center;
}

/* Alert styles */
.alert {
    padding: 1rem;
    border-radius: var(--border-radius-sm);
    margin-bottom: 1.5rem;
}

.alert-success {
    background-color: rgba(52, 168, 83, 0.1);
    color: #137333;
    border: 1px solid rgba(52, 168, 83, 0.3);
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    color: #c5221f;
    border: 1px solid rgba(234, 67, 53, 0.3);
}

.alert-info {
    background-color: rgba(66, 133, 244, 0.1);
    color: #1967d2;
    border: 1px solid rgba(66, 133, 244, 0.3);
}

/* Button styles */
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    border: none;
    background-color: #1a73e8;
    color: white;
    transition: background-color 0.2s;
}

.button:hover {
    background-color: #1765cc;
}

.button.secondary {
    background-color: #f8f9fa;
    color: #3c4043;
    border: 1px solid #dadce0;
}

.button.secondary:hover {
    background-color: #f1f3f4;
    border-color: #d2d4d7;
}

/* Modal styles */
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
    margin: 10vh auto;
    padding: 2rem;
    border-radius: 8px;
    max-width: 500px;
    position: relative;
}

.close-modal {
    position: absolute;
    top: 1rem;
    right: 1.5rem;
    font-size: 1.5rem;
    cursor: pointer;
    color: #6c757d;
}

.close-modal:hover {
    color: #343a40;
}

/* Form styles */
.form-group {
    margin-bottom: 1.5rem;
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
    padding: 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 4px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 1.5rem;
}

/* Data table styles */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #e0e0e0;
    text-align: left;
}

.data-table th {
    font-weight: 600;
    background-color: #f8f9fa;
}

.data-table tfoot th {
    background-color: #f0f0f0;
}

/* Breadcrumb styles */
.breadcrumb {
    margin-bottom: 1.5rem;
    font-size: 0.875rem;
    color: #6c757d;
}

.breadcrumb a {
    color: #1a73e8;
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .batch-details-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .batch-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .batch-actions {
        width: 100%;
        flex-wrap: wrap;
    }
    
    .batch-actions .button {
        flex: 1;
    }
    
    .detail-item {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .detail-label {
        min-width: auto;
    }
    
    .modal-content {
        margin: 5vh auto;
        padding: 1.5rem;
        max-width: calc(100% - 2rem);
    }
}

@media (max-width: 576px) {
    .batch-actions {
        flex-direction: column;
    }
    
    .batch-actions .button {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Batch view script loaded');
    
    // Status modal elements
    const statusModal = document.getElementById('statusModal');
    const updateStatusBtn = document.getElementById('updateStatusBtn');
    const statusCloseBtn = statusModal ? statusModal.querySelector('.close-modal') : null;
    const cancelStatusBtn = document.getElementById('cancelStatus');
    
    // Cost modal elements
    const costModal = document.getElementById('costModal');
    const addCostBtn = document.getElementById('addCostBtn');
    const costCloseBtn = costModal ? costModal.querySelector('.close-modal') : null;
    const cancelCostBtn = document.getElementById('cancelCost');
    
    // Status modal controls
    if (updateStatusBtn && statusModal) {
        updateStatusBtn.addEventListener('click', function() {
            statusModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        });
    }
    
    if (statusCloseBtn) {
        statusCloseBtn.addEventListener('click', function() {
            statusModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    if (cancelStatusBtn) {
        cancelStatusBtn.addEventListener('click', function() {
            statusModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Cost modal controls
    if (addCostBtn && costModal) {
        addCostBtn.addEventListener('click', function() {
            costModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        });
    }
    
        if (costCloseBtn) {
        costCloseBtn.addEventListener('click', function() {
            costModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    if (cancelCostBtn) {
        cancelCostBtn.addEventListener('click', function() {
            costModal.style.display = 'none';
            document.body.style.overflow = '';
        });
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === statusModal) {
            statusModal.style.display = 'none';
            document.body.style.overflow = '';
        }
        if (event.target === costModal) {
            costModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
    
    // Status form validation
    const statusForm = document.getElementById('statusForm');
    if (statusForm) {
        statusForm.addEventListener('submit', function(event) {
            const status = document.getElementById('status').value;
            if (!status) {
                event.preventDefault();
                alert('Please select a status.');
                return;
            }
            
            // Add loading state to button
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="loading-spinner"></span> Updating...';
            }
            
            // Log activity if function exists
            if (typeof logUserActivity === 'function') {
                logUserActivity(
                    'update', 
                    'manufacturing', 
                    `Updated batch #${<?php echo json_encode($batch['batch_number']); ?>} status to: ${status}`
                );
            }
        });
    }
    
    // Cost form validation
    const costForm = document.getElementById('costForm');
    if (costForm) {
        costForm.addEventListener('submit', function(event) {
            const costType = document.getElementById('cost_type').value;
            const amount = document.getElementById('amount').value;
            
            let isValid = true;
            
            if (!costType) {
                alert('Please select a cost type.');
                isValid = false;
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                alert('Please enter a valid amount greater than zero.');
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
                return;
            }
            
            // Add loading state to button
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="loading-spinner"></span> Saving...';
            }
            
            // Log activity if function exists
            if (typeof logUserActivity === 'function') {
                logUserActivity(
                    'create', 
                    'manufacturing_costs', 
                    `Recorded ${costType} cost of ${amount} for batch #${<?php echo json_encode($batch['batch_number']); ?>}`
                );
            }
        });
    }
    
    // Add focus trap for better accessibility in modals
    function trapFocus(element) {
        const focusableEls = element.querySelectorAll('a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]), input[type="number"]:not([disabled]), input[type="date"]:not([disabled]), input[type="checkbox"]:not([disabled]), select:not([disabled])');
        const firstFocusableEl = focusableEls[0];
        const lastFocusableEl = focusableEls[focusableEls.length - 1];
        
        element.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                if (e.shiftKey && document.activeElement === firstFocusableEl) {
                    e.preventDefault();
                    lastFocusableEl.focus();
                } else if (!e.shiftKey && document.activeElement === lastFocusableEl) {
                    e.preventDefault();
                    firstFocusableEl.focus();
                }
            } else if (e.key === 'Escape') {
                // Close modal on Escape key
                element.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
    }
    
    // Initialize focus traps for modals
    if (statusModal) trapFocus(statusModal);
    if (costModal) trapFocus(costModal);
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'manufacturing', 'Viewed batch #<?php echo $batch['batch_number']; ?> details');
    }
    
    // Initialize tooltips if any
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', function() {
            const tooltipText = this.getAttribute('data-tooltip');
            const tooltipEl = document.createElement('div');
            tooltipEl.className = 'tooltip';
            tooltipEl.textContent = tooltipText;
            document.body.appendChild(tooltipEl);
            
            const rect = this.getBoundingClientRect();
            tooltipEl.style.left = rect.left + (rect.width / 2) - (tooltipEl.offsetWidth / 2) + 'px';
            tooltipEl.style.top = rect.top - tooltipEl.offsetHeight - 10 + 'px';
            
            this.addEventListener('mouseleave', function() {
                tooltipEl.remove();
            }, { once: true });
        });
    });
     // Fetch batch costing data
    const batchId = <?php echo $batch_id; ?>;
    const costingContainer = document.getElementById('batch-costing-container');
    
    if (costingContainer) {
        fetchBatchCosting(batchId);
    }
    
    function fetchBatchCosting(batchId) {
        fetch(`../api/get-batch-costing.php?batch_id=${batchId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    renderCostingData(data.data);
                } else {
                    costingContainer.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
                }
            })
            .catch(error => {
                console.error('Error fetching costing data:', error);
                costingContainer.innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Unable to load cost analysis. Please try again later.
                    </div>
                `;
            });
    }
    
    function renderCostingData(costing) {
        // Format currency values
        const formatCurrency = (value) => {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'PKR',
                minimumFractionDigits: 2
            }).format(value);
        };
        
        // Format percentage values
        const formatPercentage = (value) => {
            return new Intl.NumberFormat('en-US', {
                style: 'percent',
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }).format(value / 100);
        };
        
        // Build the HTML for the costing view
        const html = `
           <div class="costing-summary">
                <div class="costing-header">
                    <h4>Batch #${costing.batch_info.batch_number} Cost Summary</h4>
                    <div class="costing-metrics">
                        <div class="metric">
                            <span class="metric-value">${formatCurrency(costing.summary.total_cost)}</span>
                            <span class="metric-label">Total Cost</span>
                        </div>
                        <div class="metric">
                            <span class="metric-value">${formatCurrency(costing.summary.cost_per_unit)}</span>
                            <span class="metric-label">Cost Per Unit</span>
                        </div>
                        <div class="metric">
                            <span class="metric-value">${costing.batch_info.quantity_produced}</span>
                            <span class="metric-label">Units Produced</span>
                        </div>
                    </div>
                </div>
                
                <div class="cost-breakdown-chart">
                    <div class="chart-container">
                        <canvas id="costBreakdownChart" height="200"></canvas>
                    </div>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <span class="color-box materials-color"></span>
                            <span class="legend-label">Materials (${formatPercentage(costing.summary.cost_breakdown_percentage.materials)})</span>
                        </div>
                        <div class="legend-item">
                            <span class="color-box labor-color"></span>
                            <span class="legend-label">Labor (${formatPercentage(costing.summary.cost_breakdown_percentage.labor)})</span>
                        </div>
                        <div class="legend-item">
                            <span class="color-box other-color"></span>
                            <span class="legend-label">Other (${formatPercentage(costing.summary.cost_breakdown_percentage.other)})</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="costing-details">
                <!-- Material Costs Section -->
                <div class="cost-section">
                    <h5>Material Costs <span class="cost-total">${formatCurrency(costing.material_costs.total)}</span></h5>
                    ${costing.material_costs.items.length > 0 ? `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${costing.material_costs.items.map(item => `
                                    <tr>
                                        <td>${item.name}</td>
                                        <td>${item.quantity} ${item.unit}</td>
                                        <td>${formatCurrency(item.unit_price)}</td>
                                        <td>${formatCurrency(item.total_cost)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    ` : '<p class="no-data">No material costs recorded for this batch.</p>'}
                </div>
                
                <!-- Consolidated Cost Details Section -->
                <div class="cost-section">
                    <h5>All Manufacturing Costs <span class="cost-total">${formatCurrency(costing.other_costs.total)}</span></h5>
                    ${costing.cost_details.length > 0 ? `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Description</th>
                                    <th>Recorded By</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${costing.cost_details.map(detail => `
                                    <tr>
                                        <td>${new Date(detail.date).toLocaleDateString()}</td>
                                        <td>
                                            <span class="cost-category ${detail.type}-category">${detail.display_name}</span>
                                        </td>
                                        <td>${formatCurrency(detail.amount)}</td>
                                        <td>${detail.description || 'N/A'}</td>
                                        <td>${detail.recorded_by || 'Owner'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Total Other Costs</th>
                                    <th>${formatCurrency(costing.other_costs.total)}</th>
                                    <th colspan="2"></th>
                                </tr>
                                <tr class="grand-total">
                                    <th colspan="2">Grand Total (Materials + Other)</th>
                                    <th>${formatCurrency(costing.summary.total_cost)}</th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    ` : '<p class="no-data">No manufacturing costs recorded for this batch.</p>'}
                </div>
            </div>
        `;
        
        // Update the container with the HTML
        costingContainer.innerHTML = html;
        
        // Initialize the chart if Chart.js is available
        if (typeof Chart !== 'undefined') {
            const ctx = document.getElementById('costBreakdownChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Materials', 'Labor', 'Other'],
                        datasets: [{
                            data: [
                                costing.summary.cost_breakdown_percentage.materials,
                                costing.summary.cost_breakdown_percentage.labor,
                                costing.summary.cost_breakdown_percentage.other
                            ],
                            backgroundColor: [
                                '#4285f4', // Materials - Blue
                                '#ea4335', // Labor - Red
                                '#fbbc04'  // Other - Yellow
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw;
                                        return `${label}: ${formatPercentage(value)}`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* Loading spinner for buttons */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Add this to your CSS file or in a style tag */
.costing-summary {
    margin-bottom: 2rem;
}

.costing-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.costing-header h4 {
    margin: 0;
    font-size: 1.1rem;
}
/* Cost category styling */
.cost-category {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 500;
    background-color: #f1f3f4;
}

.labor-category { background-color: rgba(234, 67, 53, 0.1); color: #c5221f; }
.material-category { background-color: rgba(66, 133, 244, 0.1); color: #1967d2; }
.packaging-category { background-color: rgba(52, 168, 83, 0.1); color: #137333; }
.zipper-category, .sticker-category, .logo-category, .tag-category { 
    background-color: rgba(251, 188, 4, 0.1); 
    color: #b06000; 
}
.misc-category { background-color: rgba(26, 115, 232, 0.1); color: #174ea6; }

/* Table footer styling */
.data-table tfoot th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.data-table tfoot tr.grand-total th {
    background-color: #e8f0fe;
    color: #1967d2;
    font-weight: 700;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .data-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .cost-section {
        margin-bottom: 2.5rem;
    }
}

.costing-metrics {
    display: flex;
    gap: 1.5rem;
}

.metric {
    display: flex;
    flex-direction: column;
    text-align: center;
    min-width: 100px;
}

.metric-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: #333;
}

.metric-label {
    font-size: 0.85rem;
    color: #6c757d;
}

.cost-breakdown-chart {
    display: flex;
    align-items: center;
    gap: 2rem;
    margin-bottom: 2rem;
}

.chart-container {
    width: 300px;
    height: 200px;
}

.chart-legend {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.color-box {
    width: 16px;
    height: 16px;
    border-radius: 4px;
}

.materials-color { background-color: #4285f4; }
.labor-color { background-color: #ea4335; }
.other-color { background-color: #fbbc04; }

.cost-section {
    margin-bottom: 2rem;
}

.cost-section h5 {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e0e0e0;
}

.cost-total {
    font-weight: 600;
    color: #333;
}

.loading-spinner {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 200px;
    color: #6c757d;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .costing-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .cost-breakdown-chart {
        flex-direction: column;
        align-items: center;
    }
    
    .chart-legend {
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .costing-metrics {
        width: 100%;
        justify-content: space-between;
    }
    
    .metric {
        min-width: auto;
    }
}

.loading-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 0.8s linear infinite;
    margin-right: 8px;
}

/* Tooltip styles */
.tooltip {
    position: absolute;
    background-color: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 0.75rem;
    z-index: 1000;
    pointer-events: none;
}

.tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    margin-left: -5px;
    border-width: 5px;
    border-style: solid;
    border-color: rgba(0, 0, 0, 0.8) transparent transparent transparent;
}

/* Focus outline for accessibility */
:focus {
    outline: 2px solid #1a73e8;
    outline-offset: 2px;
}

/* Reduce motion for users who prefer it */
@media (prefers-reduced-motion: reduce) {
    .loading-spinner {
        animation: none;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>