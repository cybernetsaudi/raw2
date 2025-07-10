<!-- owner/activity-logs.php -->
<?php
session_start();
$page_title = "Activity Logs";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up pagination
$records_per_page = 20;
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build query with filters
$where_clause = "";
$params = array();

if(isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $where_clause .= "l.user_id = :user_id AND ";
    $params[':user_id'] = $_GET['user_id'];
}

if(isset($_GET['action_type']) && !empty($_GET['action_type'])) {
    $where_clause .= "l.action_type = :action_type AND ";
    $params[':action_type'] = $_GET['action_type'];
}

if(isset($_GET['module']) && !empty($_GET['module'])) {
    $where_clause .= "l.module = :module AND ";
    $params[':module'] = $_GET['module'];
}

if(isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where_clause .= "DATE(l.created_at) >= :date_from AND ";
    $params[':date_from'] = $_GET['date_from'];
}

if(isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where_clause .= "DATE(l.created_at) <= :date_to AND ";
    $params[':date_to'] = $_GET['date_to'];
}

// Finalize where clause
if(!empty($where_clause)) {
    $where_clause = "WHERE " . substr($where_clause, 0, -5); // Remove the trailing AND
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total FROM activity_logs l $where_clause";
$count_stmt = $db->prepare($count_query);
foreach($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get activity logs with pagination
$logs_query = "SELECT l.id, u.username, l.action_type, l.module, l.description, l.ip_address, l.created_at 
              FROM activity_logs l 
              JOIN users u ON l.user_id = u.id 
              $where_clause
              ORDER BY l.created_at DESC 
              LIMIT :offset, :records_per_page";

$logs_stmt = $db->prepare($logs_query);
foreach($params as $param => $value) {
    $logs_stmt->bindValue($param, $value);
}
$logs_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logs_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$logs_stmt->execute();

// Get users for filter dropdown
$users_query = "SELECT id, username, full_name FROM users ORDER BY username";
$users_stmt = $db->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique modules for filter dropdown
$modules_query = "SELECT DISTINCT module FROM activity_logs ORDER BY module";
$modules_stmt = $db->prepare($modules_query);
$modules_stmt->execute();
$modules = $modules_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="filter-container">
    <form id="logFilterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="user_id">User:</label>
                <select id="user_id" name="user_id">
                    <option value="">All Users</option>
                    <?php foreach($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo (isset($_GET['user_id']) && $_GET['user_id'] == $user['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['username'] . ' (' . $user['full_name'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="action_type">Action:</label>
                <select id="action_type" name="action_type">
                    <option value="">All Actions</option>
                    <option value="login" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'login') ? 'selected' : ''; ?>>Login</option>
                    <option value="logout" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'logout') ? 'selected' : ''; ?>>Logout</option>
                    <option value="create" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'create') ? 'selected' : ''; ?>>Create</option>
                    <option value="read" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'read') ? 'selected' : ''; ?>>Read</option>
                    <option value="update" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'update') ? 'selected' : ''; ?>>Update</option>
                    <option value="delete" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] == 'delete') ? 'selected' : ''; ?>>Delete</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="module">Module:</label>
                <select id="module" name="module">
                    <option value="">All Modules</option>
                    <?php foreach($modules as $module): ?>
                    <option value="<?php echo $module['module']; ?>" <?php echo (isset($_GET['module']) && $_GET['module'] == $module['module']) ? 'selected' : ''; ?>>
                        <?php echo ucfirst(htmlspecialchars($module['module'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="filter-row">
            <div class="filter-group">
                <label for="date_from">From Date:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo isset($_GET['date_from']) ? $_GET['date_from'] : ''; ?>">
            </div>
            
            <div class="filter-group">
                <label for="date_to">To Date:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo isset($_GET['date_to']) ? $_GET['date_to'] : ''; ?>">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="button">Apply Filters</button>
                <a href="activity-logs.php" class="button secondary">Reset</a>
                <button type="button" id="exportCsv" class="button">Export CSV</button>
            </div>
        </div>
    </form>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h2>Activity Logs</h2>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> records
        </div>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Module</th>
                    <th>Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php while($log = $logs_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                <tr class="<?php echo $log['action_type'] === 'delete' ? 'critical-action' : ''; ?>">
                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($log['username']); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($log['action_type'])); ?></td>
                    <td><?php echo ucfirst(htmlspecialchars($log['module'])); ?></td>
                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                </tr>
                <?php endwhile; ?>
                
                <?php if($logs_stmt->rowCount() === 0): ?>
                <tr>
                    <td colspan="6" class="no-records">No activity logs found matching the selected filters.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=1<?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?><?php echo isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : ''; ?><?php echo isset($_GET['module']) ? '&module=' . $_GET['module'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>" class="pagination-link">&laquo; First</a>
                <a href="?page=<?php echo $page - 1; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?><?php echo isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : ''; ?><?php echo isset($_GET['module']) ? '&module=' . $_GET['module'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>" class="pagination-link">&laquo; Previous</a>
            <?php endif; ?>
            
            <?php
            // Determine the range of page numbers to display
            $range = 2; // Number of pages to show on either side of the current page
            $start_page = max(1, $page - $range);
            $end_page = min($total_pages, $page + $range);
            
            // Always show first page button
            if($start_page > 1) {
                echo '<a href="?page=1' . (isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : '') . (isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : '') . (isset($_GET['module']) ? '&module=' . $_GET['module'] : '') . (isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : '') . (isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : '') . '" class="pagination-link">1</a>';
                if($start_page > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            // Display the range of pages
            for($i = $start_page; $i <= $end_page; $i++) {
                if($i == $page) {
                    echo '<span class="pagination-link current">' . $i . '</span>';
                } else {
                    echo '<a href="?page=' . $i . (isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : '') . (isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : '') . (isset($_GET['module']) ? '&module=' . $_GET['module'] : '') . (isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : '') . (isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : '') . '" class="pagination-link">' . $i . '</a>';
                }
            }
            
            // Always show last page button
            if($end_page < $total_pages) {
                if($end_page < $total_pages - 1) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
                echo '<a href="?page=' . $total_pages . (isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : '') . (isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : '') . (isset($_GET['module']) ? '&module=' . $_GET['module'] : '') . (isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : '') . (isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : '') . '" class="pagination-link">' . $total_pages . '</a>';
            }
            ?>
            
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?><?php echo isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : ''; ?><?php echo isset($_GET['module']) ? '&module=' . $_GET['module'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>" class="pagination-link">Next &raquo;</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo isset($_GET['user_id']) ? '&user_id=' . $_GET['user_id'] : ''; ?><?php echo isset($_GET['action_type']) ? '&action_type=' . $_GET['action_type'] : ''; ?><?php echo isset($_GET['module']) ? '&module=' . $_GET['module'] : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>" class="pagination-link">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Export CSV functionality
    document.getElementById('exportCsv').addEventListener('click', function() {
        // Get current filter parameters
        const params = new URLSearchParams(window.location.search);
        params.delete('page'); // Remove page parameter
        params.append('export', 'csv'); // Add export parameter
        
        // Redirect to export endpoint
        window.location.href = '../api/export-logs.php?' + params.toString();
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>