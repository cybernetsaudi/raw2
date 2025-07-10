<?php
// Initialize session and error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "View Manufacturing Batch";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // Check if batch ID is provided
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Invalid batch ID");
    }

    $batch_id = intval($_GET['id']);

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Get batch details
    $batch_query = "SELECT b.*, p.name as product_name, p.sku as product_sku, 
                   u.full_name as created_by_name
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   JOIN users u ON b.created_by = u.id
                   WHERE b.id = ?";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([$batch_id]);

    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }

    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    // Get batch materials
    $materials_query = "SELECT mu.*, rm.name as material_name, rm.unit as material_unit
                       FROM material_usage mu
                       JOIN raw_materials rm ON mu.material_id = rm.id
                       WHERE mu.batch_id = ?";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->execute([$batch_id]);
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get manufacturing costs
    $costs_query = "SELECT mc.*, u.full_name as recorded_by_name
                   FROM manufacturing_costs mc
                   JOIN users u ON mc.recorded_by = u.id
                   WHERE mc.batch_id = ?
                   ORDER BY mc.recorded_date DESC";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->execute([$batch_id]);
    $costs = $costs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total cost
    $total_cost = 0;
    foreach ($costs as $cost) {
        $total_cost += $cost['amount'];
    }
    
    // Calculate progress percentage based on status
    $progress_percentage = 0;
    switch ($batch['status']) {
        case 'pending':
            $progress_percentage = 10;
            break;
        case 'cutting':
            $progress_percentage = 30;
            break;
        case 'stitching':
            $progress_percentage = 50;
            break;
        case 'ironing':
            $progress_percentage = 70;
            break;
        case 'packaging':
            $progress_percentage = 90;
            break;
        case 'completed':
            $progress_percentage = 100;
            break;
    }

} catch (Exception $e) {
    // Log the error
    error_log('View batch error: ' . $e->getMessage());
    
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

<div class="breadcrumb" aria-label="breadcrumb">
    <a href="dashboard.php">Dashboard</a> &gt; 
    <a href="manufacturing.php">Manufacturing Batches</a> &gt; 
    <span>View Batch <?php echo htmlspecialchars($batch['batch_number']); ?></span>
</div>

<div class="page-header">
    <div class="page-title">
        <h1>Batch <?php echo htmlspecialchars($batch['batch_number']); ?></h1>
        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
    </div>
    <div class="page-actions">
        <?php if ($batch['status'] !== 'completed'): ?>
        <a href="update-batch.php?id=<?php echo $batch_id; ?>" class="button primary">
            <i class="fas fa-edit" aria-hidden="true"></i> Update Status
        </a>
        <?php endif; ?>
        <a href="manufacturing.php" class="button secondary">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Batches
        </a>
    </div>
</div>

<div class="batch-details-container">
    <!-- Progress bar -->
    <div class="batch-progress-container">
        <div class="batch-progress-label">Production Progress</div>
        <div class="batch-progress-bar" role="progressbar" aria-valuenow="<?php echo $progress_percentage; ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="batch-progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
        </div>
        <div class="batch-progress-steps">
            <div class="progress-step <?php echo in_array($batch['status'], ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Pending</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['cutting', 'stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Cutting</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['stitching', 'ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Stitching</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['ironing', 'packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Ironing</div>
            </div>
            <div class="progress-step <?php echo in_array($batch['status'], ['packaging', 'completed']) ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Packaging</div>
            </div>
            <div class="progress-step <?php echo $batch['status'] === 'completed' ? 'completed' : ''; ?>">
                <div class="step-indicator"></div>
                <div class="step-label">Completed</div>
            </div>
        </div>
    </div>

    <div class="batch-info-grid">
        <!-- Batch Information Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h2>Batch Information</h2>
            </div>
            <div class="info-card-content">
                <div class="info-row">
                    <div class="info-label">Batch Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['batch_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Product:</div>
                    <div class="info-value">
                        <div class="primary-text"><?php echo htmlspecialchars($batch['product_name']); ?></div>
                        <div class="secondary-text">SKU: <?php echo htmlspecialchars($batch['product_sku']); ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Quantity:</div>
                    <div class="info-value"><?php echo number_format($batch['quantity_produced']); ?> units</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Start Date:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['start_date']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Expected Completion:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['expected_completion_date']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Actual Completion:</div>
                    <div class="info-value"><?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : 'Not completed yet'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Created By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($batch['created_by_name']); ?></div>
                </div>
                <?php if (!empty($batch['notes'])): ?>
                <div class="info-row">
                    <div class="info-label">Notes:</div>
                    <div class="info-value"><?php echo nl2br(htmlspecialchars($batch['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Materials Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h2>Materials Used</h2>
            </div>
            <div class="info-card-content">
                <?php if (count($materials) > 0): ?>
                <div class="materials-list">
                    <div class="table-responsive">
                        <table class="data-table" aria-label="Materials Used in Batch">
                            <thead>
                                <tr>
                                    <th scope="col">Material</th>
                                    <th scope="col">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials as $material): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($material['material_name']); ?></td>
                                    <td><?php echo number_format($material['quantity_required'], 2) . ' ' . htmlspecialchars($material['material_unit']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No materials recorded for this batch.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manufacturing Costs Card -->
        <div class="info-card">
            <div class="info-card-header">
                <h2>Manufacturing Costs</h2>
                <div class="card-actions">
                    <?php if ($batch['status'] !== 'completed'): ?>
                    <a href="add-cost.php?batch_id=<?php echo $batch_id; ?>" class="button small">
                        <i class="fas fa-plus-circle" aria-hidden="true"></i> Add Cost
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-card-content">
                <?php if (count($costs) > 0): ?>
                <div class="costs-summary">
                    <div class="cost-total">
                        <span class="cost-label">Total Cost:</span>
                        <span class="cost-value"><?php echo number_format($total_cost, 2); ?></span>
                    </div>
                    <div class="cost-per-unit">
                        <span class="cost-label">Cost Per Unit:</span>
                        <span class="cost-value">
                            <?php echo $batch['quantity_produced'] > 0 ? 
                                number_format($total_cost / $batch['quantity_produced'], 2) : 
                                'N/A'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="costs-list">
                    <div class="table-responsive">
                        <table class="data-table" aria-label="Manufacturing Costs">
                            <thead>
                                <tr>
                                    <th scope="col">Type</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Recorded By</th>
                                    <th scope="col">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($costs as $cost): ?>
                                <tr>
                                    <td><span class="cost-type cost-<?php echo $cost['cost_type']; ?>"><?php echo ucfirst($cost['cost_type']); ?></span></td>
                                    <td class="amount-cell"><?php echo number_format($cost['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($cost['description'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($cost['recorded_by_name']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($cost['recorded_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p>No costs recorded for this batch.</p>
                    <?php if ($batch['status'] !== 'completed'): ?>
                    <a href="add-cost.php?batch_id=<?php echo $batch_id; ?>" class="button small">
                        <i class="fas fa-plus-circle" aria-hidden="true"></i> Add First Cost
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
       
    /* View Batch Page Styles */
:root {
  --primary: #4361ee;
  --primary-dark: #3a56d4;
  --primary-light: #eef2ff;
  --success: #2ec4b6;
  --success-dark: #21a99d;
  --warning: #ff9f1c;
  --warning-dark: #e58e19;
  --danger: #e63946;
  --danger-dark: #d33241;
  --pending: #ff9f1c;
  --cutting: #4361ee;
  --stitching: #673ab7;
  --ironing: #f06292;
  --packaging: #ff7043;
  --completed: #2ec4b6;
  --text-primary: #212529;
  --text-secondary: #6c757d;
  --border: #dee2e6;
  --background: #f8f9fa;
  --surface: #ffffff;
  --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08);
  --shadow-md: 0 4px 8px rgba(0, 0, 0, 0.12);
  --radius-sm: 4px;
  --radius-md: 8px;
  --transition: all 0.2s ease-in-out;
}

/* Breadcrumb */
.breadcrumb {
  margin-bottom: 1rem;
  font-size: 0.9rem;
  color: var(--text-secondary);
}

.breadcrumb a {
  color: var(--primary);
  text-decoration: none;
  transition: var(--transition);
}

.breadcrumb a:hover {
  text-decoration: underline;
  color: var(--primary-dark);
}

/* Page Header */
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

.page-title h1 {
  margin: 0;
  font-size: 1.75rem;
  color: var(--text-primary);
}

.page-actions {
  display: flex;
  gap: 0.75rem;
}

/* Button Styles */
.button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: var(--radius-sm);
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
  border: none;
  text-decoration: none;
  font-size: 0.9rem;
}

.button.primary {
  background-color: var(--primary);
  color: white;
}

.button.primary:hover, .button.primary:focus {
  background-color: var(--primary-dark);
  box-shadow: var(--shadow-sm);
}

.button.secondary {
  background-color: var(--background);
  color: var(--text-secondary);
  border: 1px solid var(--border);
}

.button.secondary:hover, .button.secondary:focus {
  background-color: #eaecef;
}

.button.small {
  padding: 0.25rem 0.5rem;
  font-size: 0.8rem;
}

/* Batch Details Container */
.batch-details-container {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

/* Progress Bar */
.batch-progress-container {
  background-color: white;
  border-radius: var(--radius-md);
  padding: 1.5rem;
  box-shadow: var(--shadow-sm);
}

.batch-progress-label {
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: var(--text-primary);
}

.batch-progress-bar {
  height: 8px;
  background-color: var(--background);
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 1.5rem;
  position: relative;
}

.batch-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, 
    var(--pending) 0%, 
    var(--cutting) 20%, 
    var(--stitching) 40%, 
    var(--ironing) 60%, 
    var(--packaging) 80%, 
    var(--completed) 100%
  );
  border-radius: 4px;
  transition: width 0.5s ease;
}

.batch-progress-steps {
  display: flex;
  justify-content: space-between;
}

.progress-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  flex: 1;
}

.step-indicator {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background-color: var(--background);
  border: 2px solid var(--border);
  margin-bottom: 8px;
  z-index: 1;
  transition: var(--transition);
}

.progress-step:nth-child(1) .step-indicator { border-color: var(--pending); }
.progress-step:nth-child(2) .step-indicator { border-color: var(--cutting); }
.progress-step:nth-child(3) .step-indicator { border-color: var(--stitching); }
.progress-step:nth-child(4) .step-indicator { border-color: var(--ironing); }
.progress-step:nth-child(5) .step-indicator { border-color: var(--packaging); }
.progress-step:nth-child(6) .step-indicator { border-color: var(--completed); }

.progress-step.completed .step-indicator {
  background-color: var(--primary);
  border-color: var(--primary);
}

.progress-step:nth-child(1).completed .step-indicator { background-color: var(--pending); border-color: var(--pending); }
.progress-step:nth-child(2).completed .step-indicator { background-color: var(--cutting); border-color: var(--cutting); }
.progress-step:nth-child(3).completed .step-indicator { background-color: var(--stitching); border-color: var(--stitching); }
.progress-step:nth-child(4).completed .step-indicator { background-color: var(--ironing); border-color: var(--ironing); }
.progress-step:nth-child(5).completed .step-indicator { background-color: var(--packaging); border-color: var(--packaging); }
.progress-step:nth-child(6).completed .step-indicator { background-color: var(--completed); border-color: var(--completed); }

.step-label {
  font-size: 0.8rem;
  color: var(--text-secondary);
  text-align: center;
}

.progress-step.completed .step-label {
  color: var(--text-primary);
  font-weight: 500;
}

/* Info Grid */
.batch-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
  gap: 1.5rem;
}

.info-card {
  background-color: white;
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.info-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  background-color: var(--primary-light);
  border-bottom: 1px solid var(--border);
}

.info-card-header h2 {
  margin: 0;
  font-size: 1.1rem;
  color: var(--primary);
}

.card-actions {
  display: flex;
  gap: 0.5rem;
}

.info-card-content {
  padding: 1.5rem;
}

.info-row {
  display: flex;
  margin-bottom: 1rem;
}

.info-row:last-child {
  margin-bottom: 0;
}

.info-label {
  width: 40%;
  font-weight: 500;
  color: var(--text-secondary);
}

.info-value {
  width: 60%;
  color: var(--text-primary);
}

.primary-text {
  font-weight: 500;
  color: var(--text-primary);
}

.secondary-text {
  font-size: 0.9rem;
  color: var(--text-secondary);
  margin-top: 0.25rem;
}

/* Data Tables */
.table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  border-spacing: 0;
}

.data-table th,
.data-table td {
  padding: 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--border);
}

.data-table th {
  background-color: var(--background);
  font-weight: 600;
  color: var(--text-secondary);
  white-space: nowrap;
}

.data-table tbody tr:hover {
  background-color: var(--primary-light);
}

.amount-cell {
  font-weight: 500;
  text-align: right;
}

/* Cost Summary */
.costs-summary {
  display: flex;
  justify-content: space-between;
  padding: 1rem;
  background-color: var(--background);
  border-radius: var(--radius-sm);
  margin-bottom: 1rem;
}

.cost-label {
  font-weight: 500;
  margin-right: 0.5rem;
  color: var(--text-secondary);
}

.cost-value {
  font-weight: 600;
  color: var(--primary);
}

.cost-total .cost-value {
  font-size: 1.1rem;
}

/* Cost Types */
.cost-type {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.8rem;
  font-weight: 500;
  text-align: center;
}

.cost-labor { background-color: #e3f2fd; color: #0d47a1; }
.cost-material { background-color: #e8f5e9; color: #1b5e20; }
.cost-packaging { background-color: #fff3e0; color: #e65100; }
.cost-zipper { background-color: #f3e5f5; color: #6a1b9a; }
.cost-sticker { background-color: #e1f5fe; color: #01579b; }
.cost-logo { background-color: #e8eaf6; color: #283593; }
.cost-tag { background-color: #fce4ec; color: #880e4f; }
.cost-misc { background-color: #f5f5f5; color: #424242; }
.cost-overhead { background-color: #ffebee; color: #b71c1c; }
.cost-electricity { background-color: #fff8e1; color: #ff6f00; }
.cost-maintenance { background-color: #e0f2f1; color: #004d40; }
.cost-other { background-color: #f5f5f5; color: #424242; }

/* Empty State */
.empty-state {
  text-align: center;
  padding: 2rem;
  color: var(--text-secondary);
  background-color: var(--background);
  border-radius: var(--radius-sm);
}

.empty-state .button {
  margin-top: 1rem;
}

/* Status Badge */
.status-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 500;
  text-transform: capitalize;
  text-align: center;
}

.status-pending { background-color: #fff3cd; color: #664d03; }
.status-cutting { background-color: #cfe2ff; color: #084298; }
.status-stitching { background-color: #e2d9f3; color: #3c2a80; }
.status-ironing { background-color: #f8d7da; color: #58151c; }
.status-packaging { background-color: #ffe5d0; color: #883c00; }
.status-completed { background-color: #d1e7dd; color: #0a3622; }

/* Error Container */
.error-container {
  background-color: #f8d7da;
  color: #58151c;
  padding: 2rem;
  border-radius: var(--radius-md);
  text-align: center;
  margin: 2rem 0;
  border-left: 5px solid var(--danger);
  box-shadow: var(--shadow-sm);
}

.error-container h2 {
  margin-top: 0;
  color: var(--danger);
}

.error-container .button {
  margin-top: 1rem;
}

/* Responsive Adjustments */
@media (max-width: 992px) {
  .batch-info-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
  
  .page-actions {
    width: 100%;
    flex-direction: column;
  }
  
  .page-actions .button {
    width: 100%;
    justify-content: center;
  }
  
  .info-row {
    flex-direction: column;
  }
  
  .info-label, .info-value {
    width: 100%;
  }
  
  .info-label {
    margin-bottom: 0.25rem;
  }
  
  .costs-summary {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  .batch-progress-steps {
    overflow-x: auto;
    padding-bottom: 1rem;
  }
  
  .progress-step {
    min-width: 70px;
  }
}

/* Print Styles */
@media print {
  .breadcrumb, .page-actions, .header, .footer, .sidebar {
    display: none !important;
  }
  
  body, .main-content {
    background-color: white !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  
  .batch-details-container {
    display: block;
  }
  
  .info-card {
    break-inside: avoid;
    margin-bottom: 1cm;
    box-shadow: none;
    border: 1px solid #ddd;
  }
  
  .button {
    display: none !important;
  }
  
  @page {
    margin: 1cm;
  }
}

/* Accessibility Enhancements */
@media (prefers-reduced-motion: reduce) {
  *, ::before, ::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}

button:focus,
a:focus,
[tabindex]:focus {
  outline: 3px solid rgba(67, 97, 238, 0.5);
  outline-offset: 2px;
}
    
</style>
<script>
 /**
 * View Batch JavaScript
 * Enhances the batch details page with interactive features and activity logging
 */
document.addEventListener('DOMContentLoaded', function() {
  // Log page view for analytics
  logUserActivity();
  
  // Initialize print functionality
  initPrintButton();
  
  /**
   * Log user activity to the server
   */
  function logUserActivity() {
    const userId = document.getElementById('current-user-id')?.value;
    const batchNumber = document.querySelector('.page-title h1')?.textContent.trim();
    
    if (!userId || !batchNumber) return;
    
    fetch('../api/log-activity.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        user_id: userId,
        action_type: 'read',
        module: 'manufacturing',
        description: `Viewed batch details for ${batchNumber}`
      })
    }).catch(error => {
      console.error('Error logging activity:', error);
    });
  }
  
  /**
   * Initialize print button functionality
   */
  function initPrintButton() {
    const printButtons = document.querySelectorAll('.print-batch');
    
    printButtons.forEach(button => {
      button.addEventListener('click', function(e) {
        e.preventDefault();
        window.print();
      });
    });
  }
  
  /**
   * Create a progress animation for the progress bar
   * for better visual engagement
   */
  function animateProgressBar() {
    const progressFill = document.querySelector('.batch-progress-fill');
    if (!progressFill) return;
    
    const targetWidth = progressFill.style.width;
    progressFill.style.width = '0%';
    
    // Use requestAnimationFrame for smoother animation
    requestAnimationFrame(() => {
      setTimeout(() => {
        progressFill.style.transition = 'width 1s ease-out';
        progressFill.style.width = targetWidth;
      }, 200);
    });
  }
  
  // Run the animation
  animateProgressBar();
  
  /**
   * Make tables responsive for mobile devices
   */
  function enhanceTableResponsiveness() {
    const tables = document.querySelectorAll('.data-table');
    
    tables.forEach(table => {
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
    });
  }
  
  // Enhance tables for mobile
  enhanceTableResponsiveness();
});
</script>

<?php include_once '../includes/footer.php'; ?>