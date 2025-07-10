<?php
// Initialize session and error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "Add Manufacturing Cost";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // Check if batch ID is provided
    if (!isset($_GET['batch_id']) || !is_numeric($_GET['batch_id'])) {
        throw new Exception("Invalid batch ID");
    }

    $batch_id = intval($_GET['batch_id']);

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Get batch details
    $batch_query = "SELECT b.*, p.name as product_name, p.sku as product_sku
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   WHERE b.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);

    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }

    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    // Check if batch is already completed (can't add costs to completed batches)
    if ($batch['status'] === 'completed') {
        throw new Exception("Cannot add costs to a completed batch");
    }

    // Get existing costs for this batch (for summary display)
    $costs_query = "SELECT cost_type, SUM(amount) as total_amount
                   FROM manufacturing_costs
                   WHERE batch_id = ?
                   GROUP BY cost_type";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->execute([$batch_id]);
    $existing_costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total existing cost
    $total_existing_cost = 0;
    foreach ($existing_costs as $cost) {
        $total_existing_cost += $cost['total_amount'];
    }

    // Define cost types
    $cost_types = [
        'labor' => 'Labor',
        'material' => 'Material',
        'packaging' => 'Packaging',
        'zipper' => 'Zipper',
        'sticker' => 'Sticker',
        'logo' => 'Logo',
        'tag' => 'Tag',
        'misc' => 'Miscellaneous'
    ];

    // Process form submission
    $success_message = '';
    $error_message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Validate inputs
            $cost_type = $_POST['cost_type'] ?? '';
            $amount = $_POST['amount'] ?? '';
            $description = $_POST['description'] ?? '';

            // Basic validation
            if (!array_key_exists($cost_type, $cost_types)) {
                throw new Exception("Invalid cost type selected");
            }

            if (!is_numeric($amount) || $amount <= 0) {
                throw new Exception("Amount must be a positive number");
            }

            // Start transaction
            $db->beginTransaction();

            // Insert cost record
            $cost_query = "INSERT INTO manufacturing_costs 
                          (batch_id, cost_type, amount, description, recorded_by, recorded_date) 
                          VALUES (?, ?, ?, ?, ?, NOW())";
            $cost_stmt = $db->prepare($cost_query);
            $cost_stmt->execute([
                $batch_id,
                $cost_type,
                $amount,
                $description,
                $_SESSION['user_id']
            ]);

            // Log the activity
            $activity_query = "INSERT INTO activity_logs 
                              (user_id, action_type, module, description, entity_id) 
                              VALUES (?, 'create', 'manufacturing', ?, ?)";
            $activity_stmt = $db->prepare($activity_query);
            $activity_stmt->execute([
                $_SESSION['user_id'],
                "Added " . $cost_types[$cost_type] . " cost of " . number_format($amount, 2) . " to batch " . $batch['batch_number'],
                $batch_id
            ]);

            // Commit transaction
            $db->commit();

            $success_message = "Cost added successfully";

            // Refresh costs data
            $costs_stmt->execute([$batch_id]);
            $existing_costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recalculate total existing cost
            $total_existing_cost = 0;
            foreach ($existing_costs as $cost) {
                $total_existing_cost += $cost['total_amount'];
            }

        } catch (Exception $e) {
            // Rollback transaction
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $error_message = $e->getMessage();
        }
    }

} catch (Exception $e) {
    // Log the error
    error_log('Add cost error: ' . $e->getMessage());

    // Display user-friendly error
    echo '<div class="error-container">';
    echo '<h2>Error</h2>';
    echo '<p>' . $e->getMessage() . '</p>';
    echo '<a href="manufacturing.php" class="button secondary">Back to Manufacturing Batches</a>';
    echo '</div>';

    // Exit to prevent further processing
    include_once '../includes/footer.php';
    exit;
}
?>

<div class="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; 
    <a href="manufacturing.php">Manufacturing Batches</a> &gt; 
    <a href="view-batch.php?id=<?php echo $batch_id; ?>">View Batch <?php echo htmlspecialchars($batch['batch_number']); ?></a> &gt;
    <span>Add Cost</span>
</div>

<div class="page-header">
    <div class="page-title">
        <h2>Add Manufacturing Cost</h2>
        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
    </div>
    <div class="page-actions">
        <a href="view-batch.php?id=<?php echo $batch_id; ?>" class="button secondary">
            <i class="fas fa-arrow-left"></i> Back to Batch Details
        </a>
    </div>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $success_message; ?></span>
    <span class="alert-close">&times;</span>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <span class="alert-close">&times;</span>
</div>
<?php endif; ?>

<div class="add-cost-container">
    <div class="batch-summary-card">
        <div class="card-header">
            <h3>Batch Information</h3>
        </div>
        <div class="card-content">
            <div class="batch-info">
                <div class="batch-number">
                    <span class="label">Batch Number:</span>
                    <span class="value"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                </div>
                <div class="product-info">
                    <span class="label">Product:</span>
                    <span class="value">
                        <?php echo htmlspecialchars($batch['product_name']); ?>
                        <span class="sku">(<?php echo htmlspecialchars($batch['product_sku']); ?>)</span>
                    </span>
                </div>
                <div class="quantity-info">
                    <span class="label">Quantity:</span>
                    <span class="value"><?php echo number_format($batch['quantity_produced']); ?> units</span>
                </div>
                <div class="status-info">
                    <span class="label">Status:</span>
                    <span class="value">
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </span>
                </div>
            </div>
            
            <?php if (!empty($existing_costs)): ?>
            <div class="cost-summary">
                <h4>Existing Costs</h4>
                <div class="cost-summary-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Cost Type</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($existing_costs as $cost): ?>
                            <tr>
                                <td><span class="cost-type cost-<?php echo $cost['cost_type']; ?>"><?php echo $cost_types[$cost['cost_type']]; ?></span></td>
                                <td class="amount-cell"><?php echo number_format($cost['total_amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td><strong>Total</strong></td>
                                <td class="amount-cell"><strong><?php echo number_format($total_existing_cost, 2); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($batch['quantity_produced'] > 0): ?>
                <div class="cost-per-unit">
                    <span class="label">Cost Per Unit:</span>
                    <span class="value"><?php echo number_format($total_existing_cost / $batch['quantity_produced'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="add-cost-form-card">
        <div class="card-header">
            <h3>Add New Cost</h3>
        </div>
        <div class="card-content">
            <form id="addCostForm" method="post" action="">
                <div class="form-group">
                    <label for="cost_type">Cost Type:</label>
                    <select id="cost_type" name="cost_type" required>
                        <option value="">Select Cost Type</option>
                        <?php foreach ($cost_types as $value => $label): ?>
                            <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="amount">Amount:</label>
                    <div class="amount-input-container">
                        <span class="currency-symbol">Rs.</span>
                        <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description (Optional):</label>
                    <textarea id="description" name="description" rows="3" placeholder="Enter any additional details about this cost"></textarea>
                </div>
                
                <div class="form-actions">
                    <a href="view-batch.php?id=<?php echo $batch_id; ?>" class="button secondary">Cancel</a>
                    <button type="submit" class="button primary">Add Cost</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="cost-types-card">
        <div class="card-header">
            <h3>Cost Type Guide</h3>
        </div>
        <div class="card-content">
            <div class="cost-types-guide">
                <div class="cost-type-item">
                    <span class="cost-type cost-labor">Labor</span>
                    <p>Costs related to workers' wages, salaries, and other personnel expenses.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-material">Material</span>
                    <p>Costs for raw materials not tracked in the system (misc fabrics, threads, etc.).</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-packaging">Packaging</span>
                    <p>Costs for boxes, bags, wrapping materials, and other packaging supplies.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-zipper">Zipper</span>
                    <p>Costs specifically for zippers used in the production.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-sticker">Sticker</span>
                    <p>Costs for stickers, labels, and other adhesive markings.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-logo">Logo</span>
                    <p>Costs for logo printing, embroidery, or application.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-tag">Tag</span>
                    <p>Costs for hang tags, care labels, and other product tags.</p>
                </div>
                <div class="cost-type-item">
                    <span class="cost-type cost-misc">Miscellaneous</span>
                    <p>Other costs that don't fit into the categories above.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Add Cost Page Styles */
.breadcrumb {
    margin-bottom: 1rem;
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
}

.breadcrumb a {
    color: var(--primary, #1a73e8);
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-title {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-title h2 {
    margin: 0;
}

.page-actions {
    display: flex;
    gap: 0.5rem;
}

/* Alert Styles */
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

.alert-close {
    margin-left: auto;
    cursor: pointer;
    font-size: 1.25rem;
    opacity: 0.7;
}

.alert-close:hover {
    opacity: 1;
}

/* Add Cost Container */
.add-cost-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.cost-types-card {
    grid-column: span 2;
}

/* Card Styles */
.batch-summary-card,
.add-cost-form-card,
.cost-types-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.card-header {
    padding: 1rem 1.5rem;
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

.card-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.card-content {
    padding: 1.5rem;
}

/* Batch Summary Styles */
.batch-info > div {
    margin-bottom: 1rem;
    display: flex;
    flex-direction: column;
}

.batch-info > div:last-child {
    margin-bottom: 0;
}

.batch-info .label {
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}

.batch-info .value {
    font-weight: 500;
}

.batch-info .sku {
    font-weight: normal;
    color: var(--text-secondary, #6c757d);
    margin-left: 0.5rem;
}

/* Cost Summary Styles */
.cost-summary {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e9ecef;
}

.cost-summary h4 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1rem;
}

.cost-summary-table table {
    width: 100%;
    border-collapse: collapse;
}

.cost-summary-table th,
.cost-summary-table td {
    padding: 0.5rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.cost-summary-table th {
    font-weight: 600;
    color: var(--text-secondary, #6c757d);
}

.amount-cell {
    text-align: right;
    font-weight: 500;
}

.total-row {
    background-color: #f8f9fa;
}

.total-row td {
    border-bottom: none;
}

.cost-per-unit {
    margin-top: 1rem;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 0.5rem;
}

.cost-per-unit .label {
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
}

.cost-per-unit .value {
    font-weight: 600;
    color: var(--primary, #1a73e8);
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

.form-group select,
.form-group input[type="number"],
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border, #dee2e6);
    border-radius: 4px;
    font-size: 1rem;
}

.form-group select:focus,
.form-group input[type="number"]:focus,
.form-group textarea:focus {
    border-color: var(--primary, #1a73e8);
    outline: none;
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
}

/* Amount Input with Currency Symbol */
.amount-input-container {
    display: flex;
    align-items: center;
}

.currency-symbol {
    padding: 0.5rem 0.75rem;
    background-color: #f8f9fa;
    border: 1px solid var(--border, #dee2e6);
    border-right: none;
    border-top-left-radius: 4px;
    border-bottom-left-radius: 4px;
    color: var(--text-secondary, #6c757d);
}

.amount-input-container input {
    flex: 1;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
}

/* Cost Type Styles */
.cost-type {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 500;
}

.cost-labor { background-color: #e3f2fd; color: #0d47a1; }
.cost-material { background-color: #e8f5e9; color: #1b5e20; }
.cost-packaging { background-color: #fff3e0; color: #e65100; }
.cost-zipper { background-color: #f3e5f5; color: #6a1b9a; }
.cost-sticker { background-color: #e1f5fe; color: #01579b; }
.cost-logo { background-color: #e8eaf6; color: #283593; }
.cost-tag { background-color: #fce4ec; color: #880e4f; }
.cost-misc { background-color: #f5f5f5; color: #424242; }

/* Cost Types Guide */
.cost-types-guide {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.cost-type-item {
    background-color: #f8f9fa;
    border-radius: 4px;
    padding: 1rem;
}

.cost-type-item p {
    margin: 0.5rem 0 0 0;
    font-size: 0.9rem;
    color: var(--text-secondary, #6c757d);
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-pending { background-color: #fff3cd; color: #856404; }
.status-cutting { background-color: #cce5ff; color: #004085; }
.status-stitching { background-color: #d1c4e9; color: #4527a0; }
.status-ironing { background-color: #f8d7da; color: #721c24; }
.status-packaging { background-color: #fff3e0; color: #e65100; }
.status-completed { background-color: #d4edda; color: #155724; }

/* Error Container */
.error-container {
    background-color: #f8d7da;
    color: #721c24;
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
    margin: 2rem 0;
}

.error-container h2 {
    margin-top: 0;
}

.error-container .button {
    margin-top: 1rem;
}

/* Button Styles */
.button {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    text-decoration: none;
}

.button.primary {
    background-color: var(--primary, #1a73e8);
    color: white;
}

.button.primary:hover {
    background-color: var(--primary-dark, #0d47a1);
}

.button.secondary {
    background-color: #f8f9fa;
    color: var(--text-secondary, #6c757d);
    border: 1px solid #dee2e6;
}

.button.secondary:hover {
    background-color: #e9ecef;
}

/* Responsive Styles */
@media (max-width: 992px) {
    .add-cost-container {
        grid-template-columns: 1fr;
    }
    
    .cost-types-card {
        grid-column: span 1;
    }
    
    .cost-types-guide {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
}

/* Animation for new costs */
@keyframes highlightRow {
    0% { background-color: #fff9c4; }
    100% { background-color: transparent; }
}

.highlight-row {
    animation: highlightRow 2s ease-out;
}

/* Validation Styling */
.validation-error {
    color: #dc3545;
    font-size: 0.85rem;
    margin-top: 0.25rem;
}

.invalid-input {
    border-color: #dc3545 !important;
}

.invalid-input:focus {
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.25) !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.alert-close');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.parentElement.style.display = 'none';
        });
    });
    
    // Form validation
    const addCostForm = document.getElementById('addCostForm');
    if (addCostForm) {
        addCostForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Validate cost type
            const costType = document.getElementById('cost_type');
            if (!costType.value) {
                showValidationError(costType, 'Please select a cost type');
                isValid = false;
            }
            
            // Validate amount
            const amount = document.getElementById('amount');
            if (!amount.value || parseFloat(amount.value) <= 0) {
                showValidationError(amount, 'Please enter a valid amount greater than zero');
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
            } else {
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Adding...';
                
                // Log activity
                if (typeof logUserActivity === 'function') {
                    logUserActivity(
                        'create', 
                        'manufacturing', 
                        'Added cost to batch <?php echo htmlspecialchars($batch['batch_number']); ?>'
                    );
                }
            }
        });
    }
    
    // Helper function to show validation errors
    function showValidationError(element, message) {
        // Remove any existing error message
        const existingError = element.parentElement.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
        
        // Add error class to element
        element.classList.add('invalid-input');
        
        // Create and append error message
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        
        // Handle the special case of amount input with currency symbol
        if (element.id === 'amount') {
            element.parentElement.parentElement.appendChild(errorElement);
        } else {
            element.parentElement.appendChild(errorElement);
        }
        
        // Focus the element
        element.focus();
    }
    
    // Remove validation error when input changes
    const formInputs = document.querySelectorAll('input, select, textarea');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            
            // Find and remove any validation error
            let errorElement;
            if (this.id === 'amount') {
                errorElement = this.parentElement.parentElement.querySelector('.validation-error');
            } else {
                errorElement = this.parentElement.querySelector('.validation-error');
            }
            
            if (errorElement) {
                errorElement.remove();
            }
        });
    });
    
    <?php if (!empty($success_message)): ?>
    // Highlight the newly added cost in the summary table
    const costRows = document.querySelectorAll('.cost-summary-table tbody tr:not(.total-row)');
    if (costRows.length > 0) {
        costRows[0].classList.add('highlight-row');
    }
    <?php endif; ?>
});
</script>

<?php include_once '../includes/footer.php'; ?>