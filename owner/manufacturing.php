<?php
session_start();
$page_title = "Manufacturing Overview";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get manufacturing summary
$summary_query = "SELECT 
    COUNT(*) as total_batches,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_batches,
    SUM(CASE WHEN status = 'cutting' THEN 1 ELSE 0 END) as cutting_batches,
    SUM(CASE WHEN status = 'stitching' THEN 1 ELSE 0 END) as stitching_batches,
    SUM(CASE WHEN status = 'ironing' THEN 1 ELSE 0 END) as ironing_batches,
    SUM(CASE WHEN status = 'packaging' THEN 1 ELSE 0 END) as packaging_batches,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_batches,
    SUM(quantity_produced) as total_produced
    FROM manufacturing_batches";
$summary_stmt = $db->prepare($summary_query);
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get manufacturing batches with pagination
$records_per_page = 10;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$count_query = "SELECT COUNT(*) as total FROM manufacturing_batches";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

$batches_query = "SELECT b.id, b.batch_number, p.name as product_name, b.quantity_produced, 
                 b.status, b.start_date, b.completion_date, u.full_name as created_by_name
                 FROM manufacturing_batches b 
                 JOIN products p ON b.product_id = p.id 
                 JOIN users u ON b.created_by = u.id
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
$batches_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$batches_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$batches_stmt->execute();

// Get recent manufacturing costs
$costs_query = "SELECT c.id, c.batch_id, b.batch_number, c.cost_type, c.amount, c.description, c.recorded_date
               FROM manufacturing_costs c
               JOIN manufacturing_batches b ON c.batch_id = b.id
               ORDER BY c.recorded_date DESC
               LIMIT 5";
$costs_stmt = $db->prepare($costs_query);
$costs_stmt->execute();
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['total_batches']); ?></div>
        <div class="stat-label">Total Batches</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['total_produced']); ?></div>
        <div class="stat-label">Total Produced</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['pending_batches'] + $summary['cutting_batches'] + $summary['stitching_batches'] + $summary['ironing_batches'] + $summary['packaging_batches']); ?></div>
        <div class="stat-label">In Progress</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($summary['completed_batches']); ?></div>
        <div class="stat-label">Completed</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Manufacturing Status</h2>
        </div>
        <div class="card-content">
            <div class="manufacturing-status">
                <div class="status-bar">
                    <?php
                    $total = $summary['total_batches'] > 0 ? $summary['total_batches'] : 1;
                    $pending_percent = ($summary['pending_batches'] / $total) * 100;
                    $cutting_percent = ($summary['cutting_batches'] / $total) * 100;
                    $stitching_percent = ($summary['stitching_batches'] / $total) * 100;
                    $ironing_percent = ($summary['ironing_batches'] / $total) * 100;
                    $packaging_percent = ($summary['packaging_batches'] / $total) * 100;
                    $completed_percent = ($summary['completed_batches'] / $total) * 100;
                    ?>
                    <div class="status-segment pending" style="width: <?php echo $pending_percent; ?>%" data-label="Pending (<?php echo $summary['pending_batches']; ?>)"></div>
                    <div class="status-segment cutting" style="width: <?php echo $cutting_percent; ?>%" data-label="Cutting (<?php echo $summary['cutting_batches']; ?>)"></div>
                    <div class="status-segment stitching" style="width: <?php echo $stitching_percent; ?>%" data-label="Stitching (<?php echo $summary['stitching_batches']; ?>)"></div>
                    <div class="status-segment ironing" style="width: <?php echo $ironing_percent; ?>%" data-label="Ironing (<?php echo $summary['ironing_batches']; ?>)"></div>
                    <div class="status-segment packaging" style="width: <?php echo $packaging_percent; ?>%" data-label="Packaging (<?php echo $summary['packaging_batches']; ?>)"></div>
                    <div class="status-segment completed" style="width: <?php echo $completed_percent; ?>%" data-label="Completed (<?php echo $summary['completed_batches']; ?>)"></div>
                </div>
                <div class="status-legend">
                    <div class="legend-item"><span class="color-box pending"></span> Pending</div>
                    <div class="legend-item"><span class="color-box cutting"></span> Cutting</div>
                    <div class="legend-item"><span class="color-box stitching"></span> Stitching</div>
                    <div class="legend-item"><span class="color-box ironing"></span> Ironing</div>
                    <div class="legend-item"><span class="color-box packaging"></span> Packaging</div>
                    <div class="legend-item"><span class="color-box completed"></span> Completed</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Manufacturing Batches</h2>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>Completion Date</th>
                        <th>Created By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($batch = $batches_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                        <td><?php echo number_format($batch['quantity_produced']); ?></td>
                        <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                        <td><?php echo $batch['completion_date'] ? htmlspecialchars($batch['completion_date']) : '-'; ?></td>
                        <td><?php echo htmlspecialchars($batch['created_by_name']); ?></td>
                        <td>
                            <a href="view-batch.php?id=<?php echo $batch['id']; ?>" class="button small">View Details</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if($batches_stmt->rowCount() === 0): ?>
                    <tr>
                        <td colspan="8" class="no-records">No manufacturing batches found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <?php if($page > 1): ?>
                    <a href="?page=1" class="pagination-link">&laquo; First</a>
                    <a href="?page=<?php echo $page - 1; ?>" class="pagination-link">&laquo; Previous</a>
                <?php endif; ?>
                
                <?php
                // Determine the range of page numbers to display
                $range = 2; // Number of pages to show on either side of the current page
                $start_page = max(1, $page - $range);
                $end_page = min($total_pages, $page + $range);
                
                // Always show first page button
                if($start_page > 1) {
                    echo '<a href="?page=1" class="pagination-link">1</a>';
                    if($start_page > 2) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                }
                
                // Display the range of pages
                for($i = $start_page; $i <= $end_page; $i++) {
                    if($i == $page) {
                        echo '<span class="pagination-link current">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . '" class="pagination-link">' . $i . '</a>';
                    }
                }
                
                // Always show last page button
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                    echo '<a href="?page=' . $total_pages . '" class="pagination-link">' . $total_pages . '</a>';
                }
                ?>
                
                <?php if($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="pagination-link">Next &raquo;</a>
                    <a href="?page=<?php echo $total_pages; ?>" class="pagination-link">Last &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Manufacturing Costs</h2>
            <a href="costs.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Cost Type</th>
                        <th>Amount</th>
                        <th>Description</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($cost = $costs_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cost['batch_number']); ?></td>
                        <td><?php echo ucfirst(str_replace('_', ' ', $cost['cost_type'])); ?></td>
                        <td><?php echo number_format($cost['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($cost['description']); ?></td>
                        <td><?php echo htmlspecialchars($cost['recorded_date']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if($costs_stmt->rowCount() === 0): ?>
                    <tr>
                        <td colspan="5" class="no-records">No manufacturing costs found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Manufacturing specific styles */
.manufacturing-status {
    margin-bottom: 1.5rem;
}

.status-bar {
    display: flex;
    height: 30px;
    border-radius: var(--border-radius-sm);
    overflow: hidden;
    margin-bottom: 1rem;
    background-color: #f5f5f5;
}

.status-segment {
    height: 100%;
    position: relative;
    min-width: 2%; /* Minimum visibility for small segments */
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.75rem;
    font-weight: bold;
    overflow: hidden;
    transition: width 0.3s ease;
}

.status-segment:hover::after {
    content: attr(data-label);
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: rgba(0, 0, 0, 0.7);
    z-index: 1;
}

.status-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: center;
}

.legend-item {
    display: flex;
    align-items: center;
    font-size: var(--font-size-sm);
}

.color-box {
    width: 16px;
    height: 16px;
    margin-right: 6px;
    border-radius: 3px;
}

/* Status colors */
.pending, .color-box.pending { background-color: #fbbc04; }
.cutting, .color-box.cutting { background-color: #4285f4; }
.stitching, .color-box.stitching { background-color: #673ab7; }
.ironing, .color-box.ironing { background-color: #f06292; }
.packaging, .color-box.packaging { background-color: #ff7043; }
.completed, .color-box.completed { background-color: #34a853; }

/* Responsive adjustments */
@media (max-width: 768px) {
    .status-legend {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .status-segment {
        font-size: 0;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>