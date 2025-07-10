<?php
// At the top of the file
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    session_start();
    $page_title = "Manufacturing Batches";
    include_once '../config/database.php';
    include_once '../config/auth.php';
    include_once '../includes/header.php';

    // This ensures $status_counts always exists with a default value
    $status_counts = [];

    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();

    // Set up filters
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';

    // Set up pagination
    $records_per_page = 10;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $records_per_page;

    // Build query with filters
    $where_clause = "";
    $params = array();

    if(!empty($status_filter)) {
        $where_clause .= " AND b.status = :status";
        $params[':status'] = $status_filter;
    }

    if(!empty($search)) {
        $where_clause .= " AND (b.batch_number LIKE :search OR p.name LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    // Get total records for pagination - with error handling
    $total_records = 0;
    $total_pages = 1;
    
    try {
        $count_query = "SELECT COUNT(*) as total 
                       FROM manufacturing_batches b
                       JOIN products p ON b.product_id = p.id
                       WHERE 1=1" . $where_clause;
        $count_stmt = $db->prepare($count_query);
        foreach($params as $param => $value) {
            $count_stmt->bindValue($param, $value);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_records / $records_per_page);
    } catch (PDOException $e) {
        error_log("Count query error: " . $e->getMessage());
        // Continue with defaults
    }

    // Get batches with pagination and filters - with error handling
    $batches = [];
    
    try {
        $batches_query = "SELECT b.id, b.batch_number, p.name as product_name, p.sku, 
                         b.quantity_produced, b.status, b.start_date, b.expected_completion_date, 
                         b.completion_date, u.full_name as created_by_name
                         FROM manufacturing_batches b 
                         JOIN products p ON b.product_id = p.id 
                         JOIN users u ON b.created_by = u.id
                         WHERE 1=1" . $where_clause . "
                         ORDER BY 
                            CASE b.status 
                                WHEN 'pending' THEN 1
                                WHEN 'cutting' THEN 2
                                WHEN 'stitching' THEN 3
                                WHEN 'ironing' THEN 4
                                WHEN 'packaging' THEN 5
                                WHEN 'completed' THEN 6
                            END,
                            b.start_date DESC
                         LIMIT :offset, :records_per_page";
        $batches_stmt = $db->prepare($batches_query);
        foreach($params as $param => $value) {
            $batches_stmt->bindValue($param, $value);
        }
        $batches_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $batches_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
        $batches_stmt->execute();
    } catch (PDOException $e) {
        error_log("Batches query error: " . $e->getMessage());
        // We'll handle empty results in the view
    }

    // Get manufacturing status counts - with error handling
    try {
        $status_query = "SELECT status, COUNT(*) as count
                        FROM manufacturing_batches
                        GROUP BY status";
        $status_stmt = $db->prepare($status_query);
        $status_stmt->execute();
        
        while($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
            $status_counts[$row['status']] = $row['count'];
        }
    } catch (PDOException $e) {
        error_log("Status count query error: " . $e->getMessage());
        // Continue with empty status counts
    }

    // Get products for batch creation - with error handling
    $products = [];
    
    try {
        $products_query = "SELECT id, name, sku FROM products ORDER BY name";
        $products_stmt = $db->prepare($products_query);
        $products_stmt->execute();
        $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Products query error: " . $e->getMessage());
        // We'll show a message for empty products
    }

    // Get materials for batch creation - with error handling
    $materials = [];
    
    try {
        $materials_query = "SELECT id, name, unit, stock_quantity FROM raw_materials ORDER BY stock_quantity > 0 DESC, name ASC";
        $materials_stmt = $db->prepare($materials_query);
        $materials_stmt->execute();
        $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Materials query error: " . $e->getMessage());
        // We'll show a message for empty materials
    }

    // Get material usage for existing batches
    $material_usage = [];
    try {
        $usage_query = "SELECT mu.batch_id, mu.material_id, mu.quantity_required 
                        FROM material_usage mu 
                        JOIN manufacturing_batches mb ON mu.batch_id = mb.id 
                        WHERE mb.status != 'completed'";
        $usage_stmt = $db->prepare($usage_query);
        $usage_stmt->execute();
        while ($row = $usage_stmt->fetch(PDO::FETCH_ASSOC)) {
            $material_usage[$row['batch_id']][$row['material_id']] = $row['quantity_required'];
        }
    } catch (PDOException $e) {
        error_log("Material usage query error: " . $e->getMessage());
        // Continue with empty material usage array
    }

    // Get batches for pipeline
    $pipeline_batches = [];
    try {
        $pipeline_query = "SELECT b.*, p.name as product_name, p.sku as product_sku, 
                          u.full_name as created_by_name,
                          (SELECT COUNT(*) FROM material_usage WHERE batch_id = b.id) as material_count
                          FROM manufacturing_batches b
                          JOIN products p ON b.product_id = p.id
                          JOIN users u ON b.created_by = u.id
                          WHERE b.status != 'completed'
                          ORDER BY b.created_at DESC";
        $pipeline_stmt = $db->prepare($pipeline_query);
        $pipeline_stmt->execute();
        $pipeline_batches = $pipeline_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Pipeline query error: " . $e->getMessage());
        // Continue with empty pipeline batches array
    }

} catch (Exception $e) {
    // Log the error
    error_log('Manufacturing page error: ' . $e->getMessage());
    
    // Display user-friendly error
    echo '<div class="error-container">
            <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <h2>We\'re experiencing technical difficulties</h2>
            <p>Our team has been notified and is working to fix the issue. Please try again later.</p>
            <p><a href="dashboard.php" class="button primary">Return to Dashboard</a></p>
          </div>';
    
    // Exit to prevent further processing
    include_once '../includes/footer.php';
    exit;
}

// Check for success messages
$success_message = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $success_message = 'Batch created successfully!';
            break;
        case 'updated':
            $success_message = 'Batch updated successfully!';
            break;
        case 'transfer':
            $success_message = 'Inventory transfer completed successfully!';
            break;
    }
}

// Check for error messages
$error_message = '';
if (isset($_GET['error'])) {
    $error_message = urldecode($_GET['error']);
}
?>

<div class="page-header">
    <h1 class="page-title">Manufacturing Batches</h1>
    <div class="page-actions">
       <button id="newBatchBtn" class="button primary">
           <i class="fas fa-plus-circle"></i> Create New Batch
       </button>
    </div>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success" id="successAlert">
    <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
    <div class="alert-content"><?php echo $success_message; ?></div>
    <button type="button" class="alert-close" onclick="closeAlert('successAlert')">&times;</button>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error" id="errorAlert">
    <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
    <div class="alert-content"><?php echo $error_message; ?></div>
    <button type="button" class="alert-close" onclick="closeAlert('errorAlert')">&times;</button>
</div>
<?php endif; ?>

<!-- Advanced Batch Progress Visualization -->
<div class="batch-status-progress">
    <?php
    // Define statuses array
    $statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
    
    // Fetch all active batches for visualization (not just counts)
    $active_batches = [];
    try {
        $active_batches_query = "SELECT b.id, b.batch_number, p.name as product_name,
                               b.quantity_produced, b.status, b.start_date,
                               b.expected_completion_date, b.completion_date
                               FROM manufacturing_batches b
                               JOIN products p ON b.product_id = p.id
                               WHERE b.status != 'completed' OR b.completion_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                               ORDER BY b.expected_completion_date ASC";
        $active_batches_stmt = $db->prepare($active_batches_query);
        $active_batches_stmt->execute();
        $active_batches = $active_batches_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Active batches query error: " . $e->getMessage());
    }
    
    // Only show progress bar if we have batches
    if (!empty($status_counts)): 
        $total_batches = array_sum($status_counts);
        if ($total_batches > 0):
    ?>
    <div class="production-pipeline">
        <h3 class="pipeline-title">Manufacturing Pipeline</h3>
        
        <div class="pipeline-container">
            <div class="pipeline-stages">
                <?php foreach($statuses as $status): ?>
                <div class="pipeline-stage" data-status="<?php echo $status; ?>">
                    <div class="stage-header">
                        <span class="stage-name"><?php echo ucfirst($status); ?></span>
                        <span class="stage-count"><?php echo $status_counts[$status] ?? 0; ?></span>
                    </div>
                    <div class="stage-content">
                        <?php
                        // Group batches by status
                        $status_batches = array_filter($active_batches, function($batch) use ($status) {
                            return $batch['status'] === $status;
                        });
                        
                        // Sort batches - urgent ones first
                        usort($status_batches, function($a, $b) {
                            // Calculate days until expected completion
                            $a_days = (strtotime($a['expected_completion_date']) - time()) / (60 * 60 * 24);
                            $b_days = (strtotime($b['expected_completion_date']) - time()) / (60 * 60 * 24);
                            
                            // Urgent batches first (less days remaining)
                            return $a_days <=> $b_days;
                        });
                        
                        // Display batch balloons
                        foreach($status_batches as $index => $batch):
                            // Calculate if batch is urgent
                            $days_remaining = (strtotime($batch['expected_completion_date']) - time()) / (60 * 60 * 24);
                            $is_urgent = false;
                            $urgency_class = '';
                            
                            // Define urgency based on status and days remaining
                            if ($days_remaining < 0) {
                                // Past due date
                                $urgency_class = 'batch-overdue';
                                $is_urgent = true;
                            } elseif ($days_remaining < 3) {
                                // Less than 3 days remaining
                                $status_index = array_search($batch['status'], $statuses);
                                $stages_remaining = count($statuses) - $status_index - 1;
                                
                                // If many stages remaining but little time
                                if ($stages_remaining > 1 && $days_remaining < 2) {
                                    $urgency_class = 'batch-urgent';
                                    $is_urgent = true;
                                } elseif ($stages_remaining > 0) {
                                    $urgency_class = 'batch-warning';
                                }
                            }
                            
                            // Generate a unique color based on batch ID
                            $color_index = $batch['id'] % 8; // 8 different colors
                            $color_class = 'batch-color-' . $color_index;
                        ?>
                        <!-- Add tabindex and ARIA attributes for accessibility -->
                            <div class="batch-balloon <?php echo $color_class . ' ' . $urgency_class; ?>"
                                 tabindex="0"
                                 role="button"
                                 aria-label="Batch <?php echo htmlspecialchars($batch['batch_number']); ?> - <?php echo htmlspecialchars($batch['product_name']); ?>"
                                 data-batch-id="<?php echo $batch['id']; ?>"
                                 data-batch-number="<?php echo htmlspecialchars($batch['batch_number']); ?>"
                                 data-product-name="<?php echo htmlspecialchars($batch['product_name']); ?>"
                                 data-quantity="<?php echo $batch['quantity_produced']; ?>"
                                 data-start-date="<?php echo htmlspecialchars($batch['start_date']); ?>"
                                 data-expected-date="<?php echo htmlspecialchars($batch['expected_completion_date']); ?>"
                                 data-days-remaining="<?php echo round($days_remaining, 1); ?>">
                            <span class="batch-label"><?php echo htmlspecialchars($batch['batch_number']); ?></span>
                            <?php if ($is_urgent): ?>
                            <span class="batch-alert"><i class="fas fa-exclamation-triangle"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($status_batches)): ?>
                        <div class="empty-stage">
                            <span class="empty-message">No batches</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Batch details popup -->
        <div id="batchDetailPopup" class="batch-detail-popup">
            <div class="popup-header">
                <h4 id="popupBatchNumber"></h4>
                <button type="button" class="close-popup" aria-label="Close popup">&times;</button>
            </div>
            <div class="popup-content">
                <div class="detail-row">
                    <span class="detail-label">Product:</span>
                    <span id="popupProductName" class="detail-value"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Quantity:</span>
                    <span id="popupQuantity" class="detail-value"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Start Date:</span>
                    <span id="popupStartDate" class="detail-value"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Expected Completion:</span>
                    <span id="popupExpectedDate" class="detail-value"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Time Remaining:</span>
                    <span id="popupTimeRemaining" class="detail-value"></span>
                </div>
            </div>
            <div class="popup-actions">
                <a id="popupViewLink" href="#" class="button small">View Details</a>
                <a id="popupUpdateLink" href="#" class="button primary small">Update Status</a>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <div class="empty-progress-message">
        <i class="fas fa-info-circle"></i>
        <p>No manufacturing batches available. Create your first batch to see production status.</p>
    </div>
    <?php endif; else: ?>
    <div class="empty-progress-message">
        <i class="fas fa-info-circle"></i>
        <p>No manufacturing batches available. Create your first batch to see production status.</p>
    </div>
    <?php endif; ?>
    
    <div class="status-legend">
        <div class="legend-section">
            <h4 class="legend-title">Manufacturing Stages</h4>
            <div class="legend-items">
                <?php foreach($statuses as $status): ?>
                <div class="legend-item">
                    <span class="color-box status-<?php echo $status; ?>"></span>
                    <span class="legend-label"><?php echo ucfirst($status); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="legend-section">
            <h4 class="legend-title">Batch Status</h4>
            <div class="legend-items">
                <div class="legend-item">
                    <span class="batch-indicator batch-normal"></span>
                    <span class="legend-label">Normal</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-warning"></span>
                    <span class="legend-label">Approaching Deadline</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-urgent"></span>
                    <span class="legend-label">Urgent</span>
                </div>
                <div class="legend-item">
                    <span class="batch-indicator batch-overdue"></span>
                    <span class="legend-label">Overdue</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="status">Status:</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $status_counts['pending'] ?? 0; ?>)</option>
                    <option value="cutting" <?php echo $status_filter === 'cutting' ? 'selected' : ''; ?>>Cutting (<?php echo $status_counts['cutting'] ?? 0; ?>)</option>
                    <option value="stitching" <?php echo $status_filter === 'stitching' ? 'selected' : ''; ?>>Stitching (<?php echo $status_counts['stitching'] ?? 0; ?>)</option>
                    <option value="ironing" <?php echo $status_filter === 'ironing' ? 'selected' : ''; ?>>Ironing (<?php echo $status_counts['ironing'] ?? 0; ?>)</option>
                    <option value="packaging" <?php echo $status_filter === 'packaging' ? 'selected' : ''; ?>>Packaging (<?php echo $status_counts['packaging'] ?? 0; ?>)</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed (<?php echo $status_counts['completed'] ?? 0; ?>)</option>
                </select>
            </div>
            
            <div class="filter-group search-group">
                <label for="search">Search:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Batch #, Product name, SKU">
                    <button type="submit" class="search-button" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-actions">
                <a href="manufacturing.php" class="button secondary">Reset Filters</a>
            </div>
        </div>
    </form>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Manufacturing Batches</h3>
        <div class="pagination-info">
            <?php if ($total_records > 0): ?>
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> batches
            <?php else: ?>
            No batches found
            <?php endif; ?>
        </div>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Expected Completion</th>
                        <th>Actual Completion</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (isset($batches_stmt) && $batches_stmt->rowCount() > 0): ?>
                        <?php while($batch = $batches_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                            <td>
                                <div class="product-cell">
                                    <div class="product-name"><?php echo htmlspecialchars($batch['product_name']); ?></div>
                                    <div class="product-sku"><?php echo htmlspecialchars($batch['sku']); ?></div>
                                </div>
                            </td>
                            <td><?php echo number_format($batch['quantity_produced']); ?></td>
                            <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($batch['expected_completion_date']); ?></td>
                            <td><?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : '-'; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view-batch.php?id=<?php echo $batch['id']; ?>" class="button small">View</a>
                                    <?php if($batch['status'] !== 'completed'): ?>
                                    <a href="update-batch.php?id=<?php echo $batch['id']; ?>" class="button small primary">Update</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="8" class="no-records">
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <p>No manufacturing batches found matching your criteria.</p>
                                <button id="emptyStateNewBatchBtn" class="button primary">Create New Batch</button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            // Build pagination query string with filters
            $pagination_query = '';
            if(!empty($status_filter)) $pagination_query .= '&status=' . urlencode($status_filter);
            if(!empty($search)) $pagination_query .= '&search=' . urlencode($search);
            ?>
            
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link" aria-label="First page">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Previous page">&laquo; Previous</a>
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
                <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Next page">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link" aria-label="Last page">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create New Batch Modal -->
<div id="batchModal" class="modal" aria-labelledby="modalTitle" aria-modal="true" role="dialog">
    <div class="modal-content wide-modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Manufacturing Batch</h2>
            <button type="button" class="close-modal" id="closeBatchModal" aria-label="Close modal">&times;</button>
        </div>
        
        <div class="modal-body">
            <div id="quickProductSection" style="display: none;">
                <h3>Quick Add Product</h3>
                <form id="quickProductForm" class="embedded-form">
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="quick_product_name">Product Name:</label>
                                <input type="text" id="quick_product_name" name="name" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="quick_product_sku">SKU:</label>
                                <input type="text" id="quick_product_sku" name="sku" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="button secondary" id="cancelQuickProduct">Cancel</button>
                        <button type="submit" class="button primary">Add Product</button>
                    </div>
                </form>
                <hr class="section-divider">
            </div>
            
            <form id="batchForm" action="../api/save-batch.php" method="post">
                <div class="form-section">
                    <h3>Batch Information</h3>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="product_id">Product:</label>
                                <select id="product_id" name="product_id" required>
                                    <option value="">Select Product</option>
                                    <?php if (!empty($products)): ?>
                                        <?php foreach($products as $product): ?>
                                                                                <option value="<?php echo $product['id']; ?>">
                                            <?php echo htmlspecialchars($product['name']); ?> (<?php echo htmlspecialchars($product['sku']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <?php if (empty($products)): ?>
                                    <div class="field-note">No products available. Use the button below to add one.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="quantity_produced">Quantity to Produce:</label>
                                <input type="number" id="quantity_produced" name="quantity_produced" min="1" required>
                                <div class="field-hint">Enter the number of units to produce</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="quick-add-product">
                        <button type="button" id="showQuickAddProduct" class="button small">
                            <i class="fas fa-plus-circle"></i> Add New Product
                        </button>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="start_date">Start Date:</label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="expected_completion_date">Expected Completion Date:</label>
                                <input type="date" id="expected_completion_date" name="expected_completion_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                                <div class="field-hint">Must be after the start date</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="notes">Notes:</label>
                                <textarea id="notes" name="notes" rows="3" placeholder="Add any additional information about this batch"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Material Requirements</h3>
                    <div class="materials-container">
                        <div class="materials-table-wrapper">
                            <table class="materials-table" id="materialsTable">
                                <thead>
                                    <tr>
                                        <th>Material</th>
                                        <th>Quantity</th>
                                        <th>Available Stock</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr id="noMaterialsRow">
                                        <td colspan="4" class="no-records">No materials added yet. Use the form below to add materials.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="add-material-form">
                            <div class="form-row">
                                <div class="form-col">
                                    <div class="form-group">
                                        <label for="material_id">Material:</label>
                                        <select id="material_id">
                                            <option value="">Select Material</option>
                                            <?php if (!empty($materials)): ?>
                                                <?php foreach($materials as $material): ?>
                                                <option value="<?php echo $material['id']; ?>" 
                                                        data-name="<?php echo htmlspecialchars($material['name']); ?>"
                                                        data-unit="<?php echo htmlspecialchars($material['unit']); ?>"
                                                        data-stock="<?php echo $material['stock_quantity']; ?>"
                                                        <?php echo $material['stock_quantity'] <= 0 ? 'disabled' : ''; ?>>
                                                    <?php echo htmlspecialchars($material['name']); ?> 
                                                    (<?php echo htmlspecialchars($material['unit']); ?>) - 
                                                    Stock: <?php echo number_format($material['stock_quantity'], 2); ?>
                                                    <?php echo $material['stock_quantity'] <= 0 ? ' - OUT OF STOCK' : ''; ?>
                                                </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <?php if (empty($materials)): ?>
                                            <div class="field-note error">No materials available. Please add materials in the Raw Materials section first.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-col">
                                    <div class="form-group">
                                        <label for="material_quantity">Quantity:</label>
                                        <div class="input-with-unit">
                                            <input type="number" id="material_quantity" step="0.01" min="0.01">
                                            <span id="material_unit"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-col form-col-auto">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="button" id="addMaterialBtn" class="button">Add Material</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hidden container for materials data -->
                    <div id="materialsDataContainer"></div>
                </div>
                
                <div class="form-actions">
                    <div class="form-submit-message" id="formSubmitMessage"></div>
                    <div class="button-group">
                        <button type="button" class="button secondary" id="cancelBatch">Cancel</button>
                        <button type="submit" class="button primary" id="submitBatchBtn">Create Batch</button>
                    </div>
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

.alert-icon {
    flex-shrink: 0;
    margin-right: var(--spacing-md);
    font-size: var(--font-size-lg);
}

.alert-content {
    flex: 1;
}

.alert-close {
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

.alert-close:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Error Container */
.error-container {
    background-color: var(--error-light);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-xl);
    margin: var(--spacing-xl) 0;
    text-align: center;
    max-width: 600px;
    margin-left: auto;
    margin-right: auto;
}

.error-icon {
    font-size: 3rem;
    color: var(--error);
    margin-bottom: var(--spacing-md);
}

.error-container h2 {
    color: var(--error);
    margin-top: 0;
    margin-bottom: var(--spacing-md);
}

.error-container p {
    margin-bottom: var(--spacing-md);
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

.button-group {
    display: flex;
    gap: var(--spacing-sm);
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

/* Dashboard Card Component */
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

.product-cell {
    display: flex;
    flex-direction: column;
}

.product-name {
    font-weight: 500;
}

.product-sku {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
}

.action-buttons {
    display: flex;
    gap: var(--spacing-xs);
}

.no-records {
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--text-secondary);
}

/* Empty State */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--spacing-xl);
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 3rem;
    margin-bottom: var(--spacing-md);
    opacity: 0.5;
}

.empty-state p {
    margin-bottom: var(--spacing-md);
}

/* Status Badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-xs);
    font-weight: 500;
    text-transform: capitalize;
}

.status-pending { 
    background-color: #fff8e1; 
    color: #f57f17; 
}

.status-cutting { 
    background-color: #e3f2fd; 
    color: #0d47a1; 
}

.status-stitching { 
    background-color: #ede7f6; 
    color: #4527a0; 
}

.status-ironing { 
    background-color: #fce4ec; 
    color: #880e4f; 
}

.status-packaging { 
    background-color: #fff3e0; 
    color: #e65100; 
}

.status-completed { 
    background-color: #e8f5e9; 
    color: #1b5e20; 
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

.wide-modal {
    max-width: 900px;
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
.form-section {
    margin-bottom: var(--spacing-lg);
}

.form-section h3 {
    font-size: var(--font-size-md);
    font-weight: 500;
    margin-top: 0;
    margin-bottom: var(--spacing-md);
    color: var(--text-primary);
    padding-bottom: var(--spacing-xs);
    border-bottom: 1px solid var(--border);
}

.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.form-col {
    flex: 1;
    min-width: 250px;
}

.form-col-auto {
    flex: 0 0 auto;
}

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

.field-hint {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    margin-top: var(--spacing-xs);
}

.field-note {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    margin-top: var(--spacing-xs);
    padding: var(--spacing-xs);
    background-color: #f8f9fa;
    border-radius: var(--border-radius-sm);
}

.field-note.error {
    color: var(--error);
    background-color: var(--error-light);
}

.input-with-unit {
    display: flex;
    align-items: stretch;
}

.input-with-unit input {
    flex: 1;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.input-with-unit span {
    display: flex;
    align-items: center;
    padding: 0 0.75rem;
    background-color: #f1f3f4;
    border: 1px solid var(--border);
    border-left: none;
    border-top-right-radius: var(--border-radius-sm);
    border-bottom-right-radius: var(--border-radius-sm);
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
    white-space: nowrap;
}

.quick-add-product {
    margin-bottom: var(--spacing-md);
}

.embedded-form {
    background-color: #f8f9fa;
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.section-divider {
    margin: var(--spacing-md) 0;
    border: none;
    border-top: 1px solid var(--border);
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: var(--spacing-lg);
    padding-top: var(--spacing-md);
    border-top: 1px solid var(--border);
}

.form-submit-message {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

/* Materials Table */
.materials-container {
    margin-bottom: var(--spacing-md);
}

.materials-table-wrapper {
    margin-bottom: var(--spacing-md);
    max-height: 250px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
}

.materials-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--font-size-sm);
}

.materials-table th, 
.materials-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border);
}

.materials-table th {
    font-weight: 500;
    color: var(--text-secondary);
    background-color: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 10;
}

.add-material-form {
    background-color: #f8f9fa;
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
}

/* Production Pipeline Visualization */
.batch-status-progress {
    margin-bottom: var(--spacing-lg);
}

.empty-progress-message {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-md);
    padding: var(--spacing-lg);
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    color: var(--text-secondary);
    text-align: center;
}

.empty-progress-message i {
    font-size: 1.5rem;
    color: var(--primary);
}

.empty-progress-message p {
    margin: 0;
}

.production-pipeline {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-md);
}

.pipeline-title {
    font-size: var(--font-size-lg);
    font-weight: 500;
    margin: 0 0 var(--spacing-md) 0;
    color: var(--text-primary);
}

.pipeline-container {
    position: relative;
    padding: var(--spacing-md) 0;
}

.pipeline-stages {
    display: flex;
    justify-content: space-between;
    position: relative;
    min-height: 150px;
}

/* Add a connecting line between stages */
.pipeline-stages::before {
    content: '';
    position: absolute;
    top: 30px;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(to right, 
        #f4b400 calc(100%/6), 
        #4285f4 calc(100%/6), 
        #4285f4 calc(100%/3), 
        #673ab7 calc(100%/3), 
        #673ab7 calc(100%/2), 
                #db4437 calc(100%/2), 
        #db4437 calc(2*100%/3), 
        #ff7043 calc(2*100%/3), 
        #ff7043 calc(5*100%/6), 
        #0f9d58 calc(5*100%/6), 
        #0f9d58 100%);
    z-index: 1;
}

.pipeline-stage {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
    z-index: 2;
    padding: 0 8px;
}

.stage-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 1.5rem;
    position: relative;
}

.stage-header::before {
    content: '';
    width: 20px;
    height: 20px;
    border-radius: 50%;
    position: absolute;
    top: -32px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 3;
}

.pipeline-stage[data-status="pending"] .stage-header::before { background-color: #f4b400; }
.pipeline-stage[data-status="cutting"] .stage-header::before { background-color: #4285f4; }
.pipeline-stage[data-status="stitching"] .stage-header::before { background-color: #673ab7; }
.pipeline-stage[data-status="ironing"] .stage-header::before { background-color: #db4437; }
.pipeline-stage[data-status="packaging"] .stage-header::before { background-color: #ff7043; }
.pipeline-stage[data-status="completed"] .stage-header::before { background-color: #0f9d58; }

.stage-name {
    font-weight: 500;
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.stage-count {
    font-size: 0.8rem;
    color: var(--text-secondary);
    background-color: #f1f3f4;
    padding: 0.1rem 0.4rem;
    border-radius: 10px;
}

.stage-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    min-height: 80px;
}

/* Batch balloon styles */
.batch-balloon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    position: relative;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s, box-shadow 0.2s;
    font-size: 0.75rem;
    font-weight: 500;
    color: white;
    text-align: center;
}

.batch-balloon:hover, .batch-balloon:focus {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    z-index: 10;
    outline: none;
}

/* Batch color variations */
.batch-color-0 { background-color: #4285f4; }
.batch-color-1 { background-color: #0f9d58; }
.batch-color-2 { background-color: #db4437; }
.batch-color-3 { background-color: #f4b400; }
.batch-color-4 { background-color: #673ab7; }
.batch-color-5 { background-color: #ff7043; }
.batch-color-6 { background-color: #03a9f4; }
.batch-color-7 { background-color: #8bc34a; }

/* Urgency indicators */
.batch-warning {
    border: 2px solid #f4b400;
    animation: pulse-warning 2s infinite;
}

.batch-urgent {
    border: 3px solid #db4437;
    animation: pulse-urgent 1.5s infinite;
    transform: scale(1.15);
}

.batch-urgent:hover, .batch-urgent:focus {
    transform: scale(1.25);
}

.batch-overdue {
    border: 3px solid #db4437;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0),
        rgba(0, 0, 0, 0) 10px,
        rgba(219, 68, 55, 0.2) 10px,
        rgba(219, 68, 55, 0.2) 20px
    );
    animation: pulse-urgent 1.5s infinite;
    transform: scale(1.15);
}

.batch-overdue:hover, .batch-overdue:focus {
    transform: scale(1.25);
}

@keyframes pulse-warning {
    0% { box-shadow: 0 0 0 0 rgba(244, 180, 0, 0.4); }
    70% { box-shadow: 0 0 0 6px rgba(244, 180, 0, 0); }
    100% { box-shadow: 0 0 0 0 rgba(244, 180, 0, 0); }
}

@keyframes pulse-urgent {
    0% { box-shadow: 0 0 0 0 rgba(219, 68, 55, 0.4); }
    70% { box-shadow: 0 0 0 8px rgba(219, 68, 55, 0); }
    100% { box-shadow: 0 0 0 0 rgba(219, 68, 55, 0); }
}

.batch-label {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding: 0 4px;
}

.batch-alert {
    position: absolute;
    top: -5px;
    right: -5px;
    background-color: #db4437;
    color: white;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    border: 1px solid white;
}

/* Empty stage styles */
.empty-stage {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 80px;
    padding: 1rem;
}

.empty-message {
    font-size: 0.8rem;
    color: var(--text-secondary);
    font-style: italic;
}

/* Modern Glassy Batch Detail Popup */
.batch-detail-popup {
    position: absolute;
    display: none;
    width: 320px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px); /* Safari support */
    border-radius: 16px;
    box-shadow: 
        0 4px 20px rgba(0, 0, 0, 0.15),
        0 1px 2px rgba(255, 255, 255, 0.3) inset,
        0 -1px 2px rgba(0, 0, 0, 0.05) inset;
    border: 1px solid rgba(255, 255, 255, 0.18);
    overflow: hidden;
    z-index: 100;
    animation: popup-float-in 0.3s ease-out;
    transform-origin: top center;
}

@keyframes popup-float-in {
    from { 
        opacity: 0; 
        transform: translateY(10px) scale(0.95); 
        box-shadow: 0 0 0 rgba(0, 0, 0, 0);
    }
    to { 
        opacity: 1; 
        transform: translateY(0) scale(1); 
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    }
}

/* Redesigned header with subtle gradient */
.popup-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(to right, rgba(245, 247, 250, 0.9), rgba(240, 242, 245, 0.9));
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.popup-header h4 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
    text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5);
}

.close-popup {
    background: none;
    border: none;
    color: rgba(80, 80, 80, 0.7);
    font-size: 1.3rem;
    line-height: 1;
    cursor: pointer;
    transition: all 0.2s ease;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-popup:hover {
    background: rgba(0, 0, 0, 0.05);
    color: rgba(60, 60, 60, 0.9);
    transform: rotate(90deg);
}

/* Content area with soft padding */
.popup-content {
    padding: 20px;
}

.detail-row {
    display: flex;
    margin-bottom: 12px;
    align-items: baseline;
}

.detail-row:last-child {
    margin-bottom: 0;
}

.detail-label {
    width: 45%;
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.detail-value {
    width: 55%;
    font-size: 0.95rem;
    color: var(--text-primary);
    font-weight: 400;
}

/* Glassy action buttons */
.popup-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 16px 20px;
    background: rgba(248, 249, 250, 0.5);
    border-top: 1px solid rgba(255, 255, 255, 0.3);
}

/* Status Legend */
.status-legend {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-md);
    padding: var(--spacing-md);
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-top: var(--spacing-md);
}

.legend-section {
    flex: 1;
    min-width: 240px;
}

.legend-title {
    font-size: var(--font-size-sm);
    font-weight: 500;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--text-secondary);
}

.legend-items {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm) var(--spacing-md);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    font-size: var(--font-size-xs);
}

.color-box, .batch-indicator {
    width: 16px;
    height: 16px;
    border-radius: 3px;
    flex-shrink: 0;
}

.color-box.status-pending { background-color: #f4b400; }
.color-box.status-cutting { background-color: #4285f4; }
.color-box.status-stitching { background-color: #673ab7; }
.color-box.status-ironing { background-color: #db4437; }
.color-box.status-packaging { background-color: #ff7043; }
.color-box.status-completed { background-color: #0f9d58; }

.batch-indicator.batch-normal {
    background-color: #4285f4;
    border-radius: 50%;
}

.batch-indicator.batch-warning {
    background-color: #4285f4;
    border: 2px solid #f4b400;
    border-radius: 50%;
}

.batch-indicator.batch-urgent {
    background-color: #4285f4;
    border: 2px solid #db4437;
    border-radius: 50%;
}

.batch-indicator.batch-overdue {
    background-color: #4285f4;
    border: 2px solid #db4437;
    border-radius: 50%;
    background-image: repeating-linear-gradient(
        45deg,
        rgba(0, 0, 0, 0),
        rgba(0, 0, 0, 0) 3px,
        rgba(219, 68, 55, 0.2) 3px,
        rgba(219, 68, 55, 0.2) 6px
    );
}

.legend-label {
    color: var(--text-primary);
}

/* Invalid input styling */
.invalid-input {
    border-color: var(--error) !important;
}

.validation-error {
    color: var(--error);
    font-size: var(--font-size-xs);
    margin-top: var(--spacing-xs);
}

/* Loading spinner */
.spinner {
    display: inline-block;
    width: 1rem;
    height: 1rem;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 1s ease-in-out infinite;
    margin-right: var(--spacing-xs);
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .pipeline-stages {
        overflow-x: auto;
        padding-bottom: 1rem;
        justify-content: flex-start;
        min-height: 180px;
        scroll-padding: 0 20px;
        -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
    }
    
    .pipeline-stage {
        min-width: 120px;
        flex-shrink: 0;
    }
    
    /* Add visual indicator that content is scrollable */
    .pipeline-container::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 30px;
        height: 100%;
        background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.8));
        pointer-events: none;
    }
    
    .modal-content {
        margin: 1rem auto;
        width: calc(100% - 2rem);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group, .filter-actions {
        width: 100%;
    }
    
    .status-legend {
        flex-direction: column;
    }
    
    .form-actions {
        flex-direction: column;
        gap: var(--spacing-md);
    }
    
    .form-actions .button-group {
        width: 100%;
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: var(--spacing-xs);
    }
    
    .action-buttons .button {
        width: 100%;
        text-align: center;
    }
}

/* Accessibility enhancements */
@media (prefers-reduced-motion: reduce) {
    .modal,
    .modal-content,
    .alert,
    .button::after,
    .batch-balloon,
    .batch-warning,
    .batch-urgent,
    .batch-overdue,
    .batch-detail-popup,
    .close-popup:hover,
    .spinner {
        animation: none !important;
        transition: none !important;
    }
    
    .batch-urgent, .batch-overdue {
        transform: none !important;
    }
    
    .batch-balloon:hover, 
    .batch-urgent:hover, 
    .batch-overdue:hover {
        transform: scale(1.1) !important;
    }
}

/* Focus styles for keyboard navigation */
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
    .action-buttons,
    .pagination,
    .batch-status-progress {
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Load FontAwesome if not present
    if (!document.querySelector('link[href*="font-awesome"]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css';
        document.head.appendChild(link);
    }
    
    // DOM Elements - Batch Modal
    const newBatchBtn = document.getElementById('newBatchBtn');
    const emptyStateNewBatchBtn = document.getElementById('emptyStateNewBatchBtn');
    const batchModal = document.getElementById('batchModal');
    const closeBatchModalBtn = document.getElementById('closeBatchModal');
    const cancelBatchBtn = document.getElementById('cancelBatch');
    
    // DOM Elements - Quick Product
    const quickProductSection = document.getElementById('quickProductSection');
    const showQuickAddProduct = document.getElementById('showQuickAddProduct');
    const cancelQuickProduct = document.getElementById('cancelQuickProduct');
    const quickProductForm = document.getElementById('quickProductForm');
    const productSelect = document.getElementById('product_id');
    
    // DOM Elements - Materials
    const materialSelect = document.getElementById('material_id');
    const materialQuantity = document.getElementById('material_quantity');
    const materialUnit = document.getElementById('material_unit');
    const addMaterialBtn = document.getElementById('addMaterialBtn');
    const materialsTable = document.getElementById('materialsTable');
    const noMaterialsRow = document.getElementById('noMaterialsRow');
    const materialsDataContainer = document.getElementById('materialsDataContainer');
    const batchForm = document.getElementById('batchForm');
    const submitBatchBtn = document.getElementById('submitBatchBtn');
    const formSubmitMessage = document.getElementById('formSubmitMessage');
    
    // Material tracking
    let materialItems = [];
    let materialCounter = 0;
    
    // Alert handling
    function closeAlert(alertId) {
        const alert = document.getElementById(alertId);
        if (alert) {
            alert.style.animation = 'fadeOut 0.3s forwards';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }
    }
    
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.animation = 'fadeOut 0.3s forwards';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });

    // Modal open/close functions
    function openBatchModal() {
        if (batchModal) {
            batchModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Reset quick add product section
            if (quickProductSection) {
                quickProductSection.style.display = 'none';
            }
            
            // Reset materials
            materialItems = [];
            if (materialsDataContainer) {
                materialsDataContainer.innerHTML = '';
            }
            
            // Reset material rows
            if (materialsTable) {
                const tbody = materialsTable.querySelector('tbody');
                if (tbody) {
                    const rows = tbody.querySelectorAll('tr:not(#noMaterialsRow)');
                    rows.forEach(row => row.remove());
                }
            }
            
            // Show no materials row
            if (noMaterialsRow) {
                noMaterialsRow.style.display = '';
            }
            
            // Reset form validation state
            resetFormValidation(batchForm);
        }
    }
    
    function closeBatchModal() {
        if (batchModal) {
            batchModal.style.display = 'none';
            document.body.style.overflow = '';
            
            // Reset the form
            if (batchForm) {
                batchForm.reset();
                resetFormValidation(batchForm);
            }
        }
    }
    
    // Reset form validation state
    function resetFormValidation(form) {
        if (!form) return;
        
        const invalidInputs = form.querySelectorAll('.invalid-input');
        invalidInputs.forEach(input => {
            input.classList.remove('invalid-input');
        });
        
        const validationErrors = form.querySelectorAll('.validation-error');
        validationErrors.forEach(error => {
            error.remove();
        });
        
        if (formSubmitMessage) {
            formSubmitMessage.textContent = '';
            formSubmitMessage.classList.remove('error');
        }
    }
    
    // Main batch modal functionality
    if (newBatchBtn && batchModal) {
        // Open batch modal
        newBatchBtn.addEventListener('click', openBatchModal);
        
        // Empty state button
        if (emptyStateNewBatchBtn) {
            emptyStateNewBatchBtn.addEventListener('click', openBatchModal);
        }
        
        // Close batch modal
        if (closeBatchModalBtn) {
            closeBatchModalBtn.addEventListener('click', closeBatchModal);
        }
        
        if (cancelBatchBtn) {
            cancelBatchBtn.addEventListener('click', closeBatchModal);
        }
        
        // Close when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === batchModal) {
                closeBatchModal();
            }
        });
        
        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && batchModal.style.display === 'block') {
                closeBatchModal();
            }
        });
    }
    
    // Quick Product functionality
    if (showQuickAddProduct && quickProductSection) {
        showQuickAddProduct.addEventListener('click', function() {
            quickProductSection.style.display = 'block';
            
            // Scroll to the quick add section
            quickProductSection.scrollIntoView({behavior: 'smooth', block: 'start'});
            
            // Focus on first input
            document.getElementById('quick_product_name').focus();
        });
        
        if (cancelQuickProduct) {
            cancelQuickProduct.addEventListener('click', function() {
                quickProductSection.style.display = 'none';
                resetFormValidation(quickProductForm);
                quickProductForm.reset();
            });
        }
        
        if (quickProductForm) {
            quickProductForm.addEventListener('submit', function(event) {
                event.preventDefault();
                
                // Validate form
                let isValid = true;
                const nameInput = document.getElementById('quick_product_name');
                const skuInput = document.getElementById('quick_product_sku');
                
                if (!nameInput.value.trim()) {
                    showValidationError(nameInput, 'Product name is required');
                    isValid = false;
                }
                
                if (!skuInput.value.trim()) {
                    showValidationError(skuInput, 'SKU is required');
                    isValid = false;
                }
                
                if (!isValid) return;
                
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                const originalText = submitButton.textContent;
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner"></span> Adding...';
                
                // Get form data
                const formData = new FormData(this);
                
                // Add user ID if needed
                const userId = document.getElementById('current-user-id')?.value;
                if (userId) {
                    formData.append('created_by', userId);
                }
                
                fetch('../api/quick-add-product.php', {
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
                    // Reset button state
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    
                    if (data.success) {
                        // Add the new product to the dropdown
                        const newOption = document.createElement('option');
                        newOption.value = data.product.id;
                        newOption.textContent = `${data.product.name} (${data.product.sku})`;
                        productSelect.appendChild(newOption);
                        
                        // Select the new product
                        productSelect.value = data.product.id;
                        
                        // Hide the quick add section
                        quickProductSection.style.display = 'none';
                        
                        // Reset the form
                        quickProductForm.reset();
                        
                        // Show success message
                        showToast('Product added successfully', 'success');
                        
                        // Log activity
                        if (typeof logUserActivity === 'function') {
                            logUserActivity('create', 'products', `Created new product: ${data.product.name}`);
                        }
                    } else {
                        showToast(data.message || 'Failed to add product', 'error');
                    }
                })
                .catch(error => {
                    // Reset button state
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                    
                    console.error('Error:', error);
                    showToast('An error occurred while adding the product', 'error');
                });
            });
        }
    }
    
    // Material handling
    if (materialSelect) {
        materialSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const unit = selectedOption.getAttribute('data-unit');
                if (materialUnit) {
                    materialUnit.textContent = unit || '';
                }
                
                // Focus quantity field
                if (materialQuantity) {
                    materialQuantity.focus();
                }
            } else if (materialUnit) {
                materialUnit.textContent = '';
            }
        });
    }
    
    // Add material to the list
    if (addMaterialBtn) {
        addMaterialBtn.addEventListener('click', function() {
            // Validate inputs
            let isValid = true;
            
            if (!materialSelect || !materialSelect.value) {
                showValidationError(materialSelect, 'Please select a material');
                isValid = false;
            }
            
            if (!materialQuantity || !materialQuantity.value || parseFloat(materialQuantity.value) <= 0) {
                showValidationError(materialQuantity, 'Please enter a valid quantity');
                isValid = false;
            }
            
            if (!isValid) return;
            
            // Get selected material details
            const selectedOption = materialSelect.options[materialSelect.selectedIndex];
            if (!selectedOption) {
                showToast('Error retrieving selected material', 'error');
                return;
            }
            
            const materialId = materialSelect.value;
            const materialName = selectedOption.getAttribute('data-name');
            const materialUnitText = selectedOption.getAttribute('data-unit');
            const availableStock = parseFloat(selectedOption.getAttribute('data-stock'));
            
            // Check if quantity exceeds available stock
            const quantity = parseFloat(materialQuantity.value);
            if (quantity > availableStock) {
                showValidationError(materialQuantity, `Not enough stock available. Maximum available: ${availableStock} ${materialUnitText}`);
                return;
            }
            
            // Check if material already exists in the list
            const existingMaterialIndex = materialItems.findIndex(item => item.material_id === materialId);
            
            if (existingMaterialIndex !== -1) {
                // Update existing material
                const existingItem = materialItems[existingMaterialIndex];
                const newQuantity = existingItem.quantity + quantity;
                
                if (newQuantity > availableStock) {
                    showValidationError(materialQuantity, `Cannot add more. Total would exceed available stock (${availableStock} ${materialUnitText})`);
                    return;
                }
                
                existingItem.quantity = newQuantity;
                
                // Update the table row
                const rowId = `material_row_${existingItem.id}`;
                const row = document.getElementById(rowId);
                
                if (row) {
                    const cells = row.getElementsByTagName('td');
                    if (cells.length > 1) {
                        cells[1].textContent = `${newQuantity} ${materialUnitText}`;
                        
                        // Highlight updated row
                        row.classList.add('updated-row');
                        setTimeout(() => {
                            row.classList.remove('updated-row');
                        }, 2000);
                    }
                }
            } else {
                // Add new material
                const newItem = {
                    id: materialCounter++,
                    material_id: materialId,
                    name: materialName,
                    quantity: quantity,
                    unit: materialUnitText,
                    available_stock: availableStock
                };
                
                materialItems.push(newItem);
                
                // Hide "no materials" row if visible
                if (noMaterialsRow) {
                    noMaterialsRow.style.display = 'none';
                }
                
                // Add row to table
                if (materialsTable) {
                    const tbody = materialsTable.querySelector('tbody');
                    if (tbody) {
                        const newRow = document.createElement('tr');
                        newRow.id = `material_row_${newItem.id}`;
                                               newRow.classList.add('new-row');
                        
                        newRow.innerHTML = `
                            <td>${escapeHtml(newItem.name)}</td>
                            <td>${newItem.quantity} ${newItem.unit}</td>
                            <td>${newItem.available_stock} ${newItem.unit}</td>
                            <td>
                                <button type="button" class="button small remove-material" data-id="${newItem.id}" aria-label="Remove ${newItem.name}">
                                    <i class="fas fa-trash-alt"></i> Remove
                                </button>
                            </td>
                        `;
                        
                        tbody.appendChild(newRow);
                        
                        // Add event listener to remove button
                        const removeBtn = newRow.querySelector('.remove-material');
                        if (removeBtn) {
                            removeBtn.addEventListener('click', function() {
                                removeMaterial(this.getAttribute('data-id'));
                            });
                        }
                        
                        // Animate new row
                        setTimeout(() => {
                            newRow.classList.remove('new-row');
                        }, 2000);
                    }
                }
            }
            
            // Reset material selection fields
            if (materialSelect) materialSelect.value = '';
            if (materialQuantity) materialQuantity.value = '';
            if (materialUnit) materialUnit.textContent = '';
            
            // Remove validation errors
            resetValidationErrors(materialSelect);
            resetValidationErrors(materialQuantity);
            
            // Update hidden inputs
            updateMaterialsData();
            
            // Focus back on material select
            materialSelect.focus();
        });
    }
    
    // Remove material from the list
    function removeMaterial(id) {
        const index = materialItems.findIndex(item => item.id == id);
        
        if (index !== -1) {
            const removedItem = materialItems[index];
            materialItems.splice(index, 1);
            
            // Remove row from table with animation
            const row = document.getElementById(`material_row_${id}`);
            if (row) {
                row.classList.add('removing-row');
                setTimeout(() => {
                    row.remove();
                    
                    // Show "no materials" row if no materials left
                    if (materialItems.length === 0 && noMaterialsRow) {
                        noMaterialsRow.style.display = '';
                    }
                }, 300);
            }
            
            // Update hidden inputs
            updateMaterialsData();
            
            // Show toast
            showToast(`Removed ${removedItem.name}`, 'info');
        }
    }
    
    // Update hidden inputs for materials
    function updateMaterialsData() {
        if (!materialsDataContainer) return;
        
        // Clear existing hidden inputs
        materialsDataContainer.innerHTML = '';
        
        // Create hidden inputs for each material
        materialItems.forEach((item, index) => {
            materialsDataContainer.innerHTML += `
                <input type="hidden" name="materials[${index}][material_id]" value="${item.material_id}">
                <input type="hidden" name="materials[${index}][quantity]" value="${item.quantity}">
            `;
        });
    }
    
    // Date validation for batch form
    const startDateInput = document.getElementById('start_date');
    const expectedCompletionDateInput = document.getElementById('expected_completion_date');
    
    if (startDateInput && expectedCompletionDateInput) {
        startDateInput.addEventListener('change', function() {
            validateDates();
        });
        
        expectedCompletionDateInput.addEventListener('change', function() {
            validateDates();
        });
        
        function validateDates() {
            if (startDateInput.value && expectedCompletionDateInput.value) {
                if (new Date(startDateInput.value) > new Date(expectedCompletionDateInput.value)) {
                    showValidationError(expectedCompletionDateInput, 'Expected completion date must be after start date');
                } else {
                    resetValidationErrors(expectedCompletionDateInput);
                }
            }
        }
    }
    
    // Form validation and submission
    if (batchForm) {
        batchForm.addEventListener('submit', function(event) {
            event.preventDefault();
            
            // Validate form
            let isValid = true;
            
            // Required fields validation
            const requiredFields = [
                { element: document.getElementById('product_id'), message: 'Please select a product' },
                { element: document.getElementById('quantity_produced'), message: 'Please enter quantity to produce' },
                { element: document.getElementById('start_date'), message: 'Please select a start date' },
                { element: document.getElementById('expected_completion_date'), message: 'Please select an expected completion date' }
            ];
            
            requiredFields.forEach(field => {
                if (!field.element || !field.element.value) {
                    showValidationError(field.element, field.message);
                    isValid = false;
                }
            });
            
            // Validate quantity
            const quantityInput = document.getElementById('quantity_produced');
            if (quantityInput && (isNaN(parseInt(quantityInput.value)) || parseInt(quantityInput.value) <= 0)) {
                showValidationError(quantityInput, 'Quantity must be a positive number');
                isValid = false;
            }
            
            // Validate dates
            if (startDateInput && expectedCompletionDateInput && startDateInput.value && expectedCompletionDateInput.value) {
                if (new Date(startDateInput.value) > new Date(expectedCompletionDateInput.value)) {
                    showValidationError(expectedCompletionDateInput, 'Expected completion date must be after start date');
                    isValid = false;
                }
            }
            
            // Validate materials
            if (materialItems.length === 0) {
                if (formSubmitMessage) {
                    formSubmitMessage.textContent = 'Please add at least one material';
                    formSubmitMessage.classList.add('error');
                }
                isValid = false;
            } else {
                if (formSubmitMessage) {
                    formSubmitMessage.textContent = '';
                    formSubmitMessage.classList.remove('error');
                }
            }
            
            if (!isValid) {
                // Scroll to the first error
                const firstError = document.querySelector('.invalid-input, .validation-error, .error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }
            
            // Show loading state
            if (submitBatchBtn) {
                submitBatchBtn.disabled = true;
                submitBatchBtn.innerHTML = '<span class="spinner"></span> Creating Batch...';
            }
            
            // Submit form with AJAX
            const formData = new FormData(this);
            
            // Log what we're submitting (for debugging)
            console.log("Creating batch with product ID:", formData.get('product_id'));
            console.log("Quantity:", formData.get('quantity_produced'));
            
            // Submit form
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.message || `Server responded with status: ${response.status}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                // Reset button state
                if (submitBatchBtn) {
                    submitBatchBtn.disabled = false;
                    submitBatchBtn.innerHTML = 'Create Batch';
                }
                
                if (data.success) {
                    // Show success message
                    showToast(`Batch ${data.batch_number} created successfully!`, 'success');
                    
                    // Close modal
                    closeBatchModal();
                    
                    // Reload the page after a short delay
                    setTimeout(() => {
                        window.location.href = `manufacturing.php?success=created`;
                    }, 1000);
                    
                    // Log activity
                    if (typeof logUserActivity === 'function') {
                        const productSelect = document.getElementById('product_id');
                        const productName = productSelect ? productSelect.options[productSelect.selectedIndex].text : 'unknown product';
                        const quantity = document.getElementById('quantity_produced').value;
                        
                        logUserActivity(
                            'create', 
                            'manufacturing', 
                            `Created batch ${data.batch_number} for ${quantity} units of ${productName}`
                        );
                    }
                } else {
                    // Show error message
                    showToast(data.message || 'Failed to create batch', 'error');
                    
                    // Add error message to form
                    if (formSubmitMessage) {
                        formSubmitMessage.textContent = data.message || 'Failed to create batch';
                        formSubmitMessage.classList.add('error');
                    }
                }
            })
            .catch(error => {
                console.error('Error creating batch:', error);
                
                // Reset button state
                if (submitBatchBtn) {
                    submitBatchBtn.disabled = false;
                    submitBatchBtn.innerHTML = 'Create Batch';
                }
                
                // Show error message
                showToast('An error occurred while creating the batch. Please try again.', 'error');
                
                // Add error message to form
                if (formSubmitMessage) {
                    formSubmitMessage.textContent = error.message || 'An error occurred while creating the batch';
                    formSubmitMessage.classList.add('error');
                }
            });
        });
    }
    
    // Initialize batch balloons
    initBatchBalloons();
    
    // Function to initialize interactive batch balloons
    function initBatchBalloons() {
        const batchBalloons = document.querySelectorAll('.batch-balloon');
        const popup = document.getElementById('batchDetailPopup');
        
        if (!popup || batchBalloons.length === 0) {
            return;
        }
        
        // Initialize popup elements
        const popupBatchNumber = document.getElementById('popupBatchNumber');
        const popupProductName = document.getElementById('popupProductName');
        const popupQuantity = document.getElementById('popupQuantity');
        const popupStartDate = document.getElementById('popupStartDate');
        const popupExpectedDate = document.getElementById('popupExpectedDate');
        const popupTimeRemaining = document.getElementById('popupTimeRemaining');
        const popupViewLink = document.getElementById('popupViewLink');
        const popupUpdateLink = document.getElementById('popupUpdateLink');
        const closePopupBtn = document.querySelector('.close-popup');
        
        // Add click event to each batch balloon
        batchBalloons.forEach(balloon => {
            balloon.addEventListener('click', function(e) {
                e.stopPropagation();
                
                // Get batch data from data attributes
                const batchId = this.getAttribute('data-batch-id');
                const batchNumber = this.getAttribute('data-batch-number');
                const productName = this.getAttribute('data-product-name');
                const quantity = this.getAttribute('data-quantity');
                const startDate = this.getAttribute('data-start-date');
                const expectedDate = this.getAttribute('data-expected-date');
                const daysRemaining = parseFloat(this.getAttribute('data-days-remaining'));
                
                // Format time remaining text with enhanced styling
                let timeRemainingText;
                if (daysRemaining < 0) {
                    timeRemainingText = `<span style="color: #db4437">${Math.abs(daysRemaining).toFixed(1)} days overdue</span>`;
                } else if (daysRemaining < 1) {
                    timeRemainingText = `<span style="color: #db4437">${(daysRemaining * 24).toFixed(1)} hours remaining</span>`;
                } else if (daysRemaining < 3) {
                    timeRemainingText = `<span style="color: #f4b400">${daysRemaining.toFixed(1)} days remaining</span>`;
                } else {
                    timeRemainingText = `<span>${daysRemaining.toFixed(1)} days remaining</span>`;
                }
                
                // Update popup content
                popupBatchNumber.textContent = batchNumber;
                popupProductName.textContent = productName;
                popupQuantity.textContent = quantity + ' units';
                popupStartDate.textContent = formatDate(startDate);
                popupExpectedDate.textContent = formatDate(expectedDate);
                popupTimeRemaining.innerHTML = timeRemainingText;
                
                // Update action links
                popupViewLink.href = `view-batch.php?id=${batchId}`;
                popupUpdateLink.href = `update-batch.php?id=${batchId}`;
                
                // Position and show popup with enhanced positioning
                const balloonRect = this.getBoundingClientRect();
                const scrollTop = window.scrollY || document.documentElement.scrollTop;
                
                // Adjust top position to account for popup height
                const popupHeight = 280; // Approximate height
                let topPosition = balloonRect.bottom + scrollTop + 15;
                
                // Check if popup would go off the bottom of the viewport
                if (topPosition + popupHeight > window.innerHeight + scrollTop) {
                    // Position above the balloon instead
                    topPosition = balloonRect.top + scrollTop - popupHeight - 15;
                }
                
                popup.style.top = topPosition + 'px';
                
                // Center popup horizontally relative to the balloon
                const popupWidth = 320; // Match this to the CSS width
                const leftPosition = balloonRect.left + (balloonRect.width / 2) - (popupWidth / 2);
                
                // Ensure popup stays within viewport
                const viewportWidth = window.innerWidth;
                let finalLeft = leftPosition;
                
                if (finalLeft < 20) finalLeft = 20;
                if (finalLeft + popupWidth > viewportWidth - 20) finalLeft = viewportWidth - popupWidth - 20;
                
                popup.style.left = finalLeft + 'px';
                
                // Add a subtle entrance animation
                popup.style.animation = 'none';
                popup.offsetHeight; // Force reflow
                popup.style.animation = 'popup-float-in 0.3s ease-out';
                
                popup.style.display = 'block';
                
                // Add arrow pointing to the balloon
                const arrowOffset = balloonRect.left + (balloonRect.width / 2) - finalLeft;
                
                // Remove any existing arrow
                const existingArrow = popup.querySelector('.popup-arrow');
                if (existingArrow) existingArrow.remove();
                
                // Create and add new arrow
                const arrow = document.createElement('div');
                arrow.className = 'popup-arrow';
                arrow.style.left = `${arrowOffset}px`;
                arrow.style.transform = 'translateX(-50%)';
                popup.appendChild(arrow);
            });
            
            // Add keyboard support
            balloon.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
        
        // Close popup when clicking outside
        document.addEventListener('click', function(e) {
            if (popup.style.display === 'block' && !popup.contains(e.target)) {
                closePopupWithAnimation();
            }
        });
        
        // Close popup with close button
        if (closePopupBtn) {
            closePopupBtn.addEventListener('click', closePopupWithAnimation);
        }
        
        // Add escape key support
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && popup.style.display === 'block') {
                closePopupWithAnimation();
            }
        });
        
        // Close popup with animation
        function closePopupWithAnimation() {
            popup.style.animation = 'popup-float-out 0.2s ease-in forwards';
            
            setTimeout(() => {
                popup.style.display = 'none';
            }, 200);
        }
    }
    
    // Helper function: Format date
    function formatDate(dateString) {
        const options = { year: 'numeric', month: 'short', day: 'numeric' };
        return new Date(dateString).toLocaleDateString(undefined, options);
    }
    
    // Helper function: Show validation errors
    function showValidationError(element, message) {
        if (!element) return;
        
        // Remove any existing error message
        resetValidationErrors(element);
        
        // Add error class to element
        element.classList.add('invalid-input');
        
        // Create and append error message
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        
        // Handle special case for material quantity with unit
        if (element === materialQuantity) {
            element.parentElement.parentElement.appendChild(errorElement);
        } else {
            element.parentElement.appendChild(errorElement);
        }
    }
    
    // Helper function: Reset validation errors
    function resetValidationErrors(element) {
        if (!element) return;
        
        // Remove invalid input class
        element.classList.remove('invalid-input');
        
        // Find and remove error message
        let errorContainer;
        if (element === materialQuantity) {
            errorContainer = element.parentElement.parentElement;
        } else {
            errorContainer = element.parentElement;
        }
        
        const errorElement = errorContainer.querySelector('.validation-error');
        if (errorElement) {
            errorElement.remove();
        }
    }
    
    // Helper function: Show toast notifications
    function showToast(message, type = 'info') {
        // Check if toast container exists, create if not
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            document.body.appendChild(toastContainer);
            
            // Add styles if not already in document
            if (!document.getElementById('toast-styles')) {
                const style = document.createElement('style');
                style.id = 'toast-styles';
                style.textContent = `
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
                        display: flex;
                        align-items: center;
                        min-width: 250px;
                        max-width: 350px;
                        padding: 12px 16px;
                        border-radius: 8px;
                        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.15), 0 1px 3px rgba(0, 0, 0, 0.1);
                        color: white;
                        font-size: 14px;
                        animation: toast-in 0.3s ease-out;
                        transition: transform 0.3s ease, opacity 0.3s ease;
                    }
                    .toast-icon {
                        margin-right: 12px;
                        font-size: 18px;
                    }
                    .toast-message {
                        flex: 1;
                    }
                    .toast-close {
                        background: none;
                        border: none;
                        color: white;
                        opacity: 0.7;
                        cursor: pointer;
                        font-size: 16px;
                        padding: 0;
                        margin-left: 12px;
                        transition: opacity 0.2s;
                    }
                    .toast-close:hover {
                        opacity: 1;
                    }
                    .toast-info {
                        background-color: #1a73e8;
                    }
                    .toast-success {
                        background-color: #0f9d58;
                    }
                    .toast-error {
                        background-color: #d93025;
                    }
                    .toast-warning {
                        background-color: #f4b400;
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
                    @media (max-width: 480px) {
                        #toast-container {
                            bottom: 10px;
                            right: 10px;
                            left: 10px;
                        }
                        .toast {
                            min-width: 0;
                            max-width: none;
                            width: 100%;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        // Add icon based on type
        let icon = '';
        switch (type) {
            case 'success': icon = 'fa-check-circle'; break;
            case 'error': icon = 'fa-exclamation-circle'; break;
            case 'warning': icon = 'fa-exclamation-triangle'; break;
            default: icon = 'fa-info-circle';
        }
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas ${icon}"></i></div>
            <div class="toast-message">${escapeHtml(message)}</div>
            <button type="button" class="toast-close" aria-label="Close notification">&times;</button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Add close functionality
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                toast.classList.add('toast-hide');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            });
        }
        
        // Auto-remove after timeout
        setTimeout(() => {
            if (document.body.contains(toast)) {
                toast.classList.add('toast-hide');
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        toast.remove();
                    }
                }, 300);
            }
        }, 5000);
    }
    
    // Helper function: Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Activity logging function
    window.logUserActivity = function(action, module, description) {
        // Get user ID
        const userId = document.getElementById('current-user-id')?.value;
        if (!userId) return;
        
        // Send activity data to server
        fetch('../api/log-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action_type: action,
                module: module,
                description: description
            })
        }).catch(error => console.error('Error logging activity:', error));
    };
    
    // Log page view
    logUserActivity('read', 'manufacturing', 'Viewed manufacturing batches');
});
</script>