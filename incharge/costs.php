<?php
session_start();
$page_title = "Manufacturing Costs";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up filters
$batch_filter = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$cost_type_filter = isset($_GET['cost_type']) ? $_GET['cost_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build query with filters
$where_clause = "";
$params = array();

if(!empty($batch_filter)) {
    $where_clause .= " AND c.batch_id = :batch_id";
    $params[':batch_id'] = $batch_filter;
}

if(!empty($cost_type_filter)) {
    $where_clause .= " AND c.cost_type = :cost_type";
    $params[':cost_type'] = $cost_type_filter;
}

if(!empty($date_from)) {
    $where_clause .= " AND c.recorded_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if(!empty($date_to)) {
    $where_clause .= " AND c.recorded_date <= :date_to";
    $params[':date_to'] = $date_to;
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total 
               FROM manufacturing_costs c
               WHERE 1=1" . $where_clause;
$count_stmt = $db->prepare($count_query);
foreach($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get costs with pagination and filters
$costs_query = "SELECT c.*, b.batch_number, u.full_name as recorded_by_name
               FROM manufacturing_costs c
               JOIN manufacturing_batches b ON c.batch_id = b.id
               JOIN users u ON c.recorded_by = u.id
               WHERE 1=1" . $where_clause . "
               ORDER BY c.recorded_date DESC
               LIMIT :offset, :records_per_page";
$costs_stmt = $db->prepare($costs_query);
foreach($params as $param => $value) {
    $costs_stmt->bindValue($param, $value);
}
$costs_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$costs_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$costs_stmt->execute();

// Get batches for filter dropdown
$batches_query = "SELECT id, batch_number FROM manufacturing_batches ORDER BY batch_number";
$batches_stmt = $db->prepare($batches_query);
$batches_stmt->execute();
$batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate cost summary
$summary_query = "SELECT 
                    COUNT(*) as total_entries,
                    SUM(amount) as total_amount,
                    AVG(amount) as average_amount,
                    cost_type,
                    COUNT(DISTINCT batch_id) as batch_count
                 FROM manufacturing_costs
                 WHERE 1=1" . $where_clause . "
                 GROUP BY cost_type
                 ORDER BY total_amount DESC";
$summary_stmt = $db->prepare($summary_query);
foreach($params as $param => $value) {
    $summary_stmt->bindValue($param, $value);
}
$summary_stmt->execute();
$cost_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate total across all types
$total_cost = 0;
foreach($cost_summary as $summary) {
    $total_cost += $summary['total_amount'];
}
?>

<div class="page-header">
    <h2>Manufacturing Costs</h2>
</div>

<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="batch_id">Batch:</label>
                <select id="batch_id" name="batch_id">
                    <option value="">All Batches</option>
                    <?php foreach($batches as $batch): ?>
                    <option value="<?php echo $batch['id']; ?>" <?php echo $batch_filter == $batch['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($batch['batch_number']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="cost_type">Cost Type:</label>
                <select id="cost_type" name="cost_type">
                    <option value="">All Types</option>
                    <option value="labor" <?php echo $cost_type_filter === 'labor' ? 'selected' : ''; ?>>Labor</option>
                    <option value="overhead" <?php echo $cost_type_filter === 'overhead' ? 'selected' : ''; ?>>Overhead</option>
                    <option value="electricity" <?php echo $cost_type_filter === 'electricity' ? 'selected' : ''; ?>>Electricity</option>
                    <option value="maintenance" <?php echo $cost_type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="other" <?php echo $cost_type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="date_from">From Date:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            
            <div class="filter-group">
                <label for="date_to">To Date:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a href="costs.php" class="button secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="costs-summary">
    <div class="summary-header">
        <h3>Cost Summary</h3>
        <div class="total-cost">
            <span class="label">Total Cost:</span>
            <span class="value"><?php echo number_format($total_cost, 2); ?></span>
        </div>
    </div>
    
    <div class="summary-grid">
        <?php if(!empty($cost_summary)): ?>
            <?php foreach($cost_summary as $summary): ?>
            <div class="summary-card">
                <div class="summary-title"><?php echo ucfirst(str_replace('_', ' ', $summary['cost_type'])); ?></div>
                <div class="summary-amount"><?php echo number_format($summary['total_amount'], 2); ?></div>
                <div class="summary-details">
                    <div class="summary-detail">
                        <span class="detail-label">Entries:</span>
                        <span class="detail-value"><?php echo $summary['total_entries']; ?></span>
                    </div>
                    <div class="summary-detail">
                        <span class="detail-label">Batches:</span>
                        <span class="detail-value"><?php echo $summary['batch_count']; ?></span>
                    </div>
                    <div class="summary-detail">
                        <span class="detail-label">Average:</span>
                        <span class="detail-value"><?php echo number_format($summary['average_amount'], 2); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-data">No cost data found for the selected filters.</div>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Cost Entries</h3>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> entries
        </div>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Batch #</th>
                    <th>Cost Type</th>
                    <th>Amount</th>
                    <th>Description</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php while($cost = $costs_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cost['recorded_date']); ?></td>
                    <td>
                        <a href="view-batch.php?id=<?php echo $cost['batch_id']; ?>" class="batch-link">
                            <?php echo htmlspecialchars($cost['batch_number']); ?>
                        </a>
                    </td>
                    <td><span class="cost-type cost-type-<?php echo $cost['cost_type']; ?>"><?php echo ucfirst(str_replace('_', ' ', $cost['cost_type'])); ?></span></td>
                    <td class="amount-cell"><?php echo number_format($cost['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($cost['description'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($cost['recorded_by_name']); ?></td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($costs_stmt->rowCount() === 0): ?>
                <tr>
                    <td colspan="6" class="no-records">No cost entries found matching your criteria.</td>
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
            if(!empty($batch_filter)) $pagination_query .= '&batch_id=' . urlencode($batch_filter);
            if(!empty($cost_type_filter)) $pagination_query .= '&cost_type=' . urlencode($cost_type_filter);
            if(!empty($date_from)) $pagination_query .= '&date_from=' . urlencode($date_from);
            if(!empty($date_to)) $pagination_query .= '&date_to=' . urlencode($date_to);
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

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>:root {
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

/* Page Structure */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-lg);
}

.page-header h2 {
    font-size: var(--font-size-xxl);
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
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

.filter-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* Cost Summary Section */
.costs-summary {
    margin-bottom: var(--spacing-xl);
}

.summary-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-md);
}

.summary-header h3 {
    font-size: var(--font-size-lg);
    font-weight: 500;
    color: var(--text-primary);
    margin: 0;
}

.total-cost {
    font-size: var(--font-size-lg);
    display: flex;
    align-items: center;
}

.total-cost .label {
    color: var(--text-secondary);
    margin-right: var(--spacing-sm);
}

.total-cost .value {
    font-weight: 600;
    color: var(--primary);
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: var(--spacing-md);
}

.summary-card {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-lg);
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.summary-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-md);
}

.summary-title {
    font-size: var(--font-size-md);
    font-weight: 500;
    color: var(--primary);
    margin: 0 0 var(--spacing-sm) 0;
}

.summary-amount {
    font-size: var(--font-size-xl);
    font-weight: 600;
    margin-bottom: var(--spacing-md);
}

.summary-details {
    border-top: 1px solid var(--border);
    padding-top: var(--spacing-md);
}

.summary-detail {
    display: flex;
    justify-content: space-between;
    margin-bottom: var(--spacing-xs);
}

.summary-detail:last-child {
    margin-bottom: 0;
}

.detail-label {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.detail-value {
    font-weight: 500;
    font-size: var(--font-size-sm);
}

.no-data {
    grid-column: 1 / -1;
    padding: var(--spacing-lg);
    text-align: center;
    color: var(--text-secondary);
    background-color: var(--background);
    border-radius: var(--border-radius-md);
    border: 1px dashed var(--border);
}

/* Dashboard Card */
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

.batch-link {
    color: var(--primary);
    text-decoration: none;
    transition: color var(--transition-fast);
}

.batch-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.cost-type {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-xs);
    font-weight: 500;
    text-transform: capitalize;
}

.cost-type-labor {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.cost-type-overhead {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.cost-type-electricity {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.cost-type-maintenance {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.cost-type-other {
    background-color: rgba(103, 58, 183, 0.1);
    color: #673ab7;
}

.amount-cell {
    font-weight: 500;
    text-align: right;
}

.no-records {
    padding: var(--spacing-xl);
    text-align: center;
    color: var(--text-secondary);
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

/* Responsive adjustments */
@media (max-width: 992px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }
    
    .summary-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-sm);
    }
    
    .summary-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .filter-actions .button {
        width: 100%;
        margin-bottom: var(--spacing-xs);
    }
    
    .data-table {
        display: block;
        overflow-x: auto;
    }
}

@media (max-width: 576px) {
    .data-table th:nth-child(5),
    .data-table td:nth-child(5) {
        display: none;
    }
}

/* Accessibility focus styles */
:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

:focus:not(:focus-visible) {
    outline: none;
}

/* Print styles */
@media print {
    .filter-container,
    .pagination {
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

/* Reduced motion preferences */
@media (prefers-reduced-motion: reduce) {
    .summary-card, .button::after {
        transition: none;
    }
    
    .summary-card:hover {
        transform: none;
    }
}</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Date validation for filters
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    
    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', function() {
            if (dateToInput.value && this.value > dateToInput.value) {
                alert('From date cannot be later than To date.');
                this.value = dateToInput.value;
            }
        });
        
        dateToInput.addEventListener('change', function() {
            if (dateFromInput.value && this.value < dateFromInput.value) {
                alert('To date cannot be earlier than From date.');
                this.value = dateFromInput.value;
            }
        });
    }
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'manufacturing_costs', 'Viewed manufacturing costs');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>