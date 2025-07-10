<?php
session_start();
$page_title = "Owner Dashboard";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get financial summary
$financial_query = "SELECT 
    (SELECT COALESCE(SUM(amount), 0) FROM funds) AS total_investment,
    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases) AS total_purchases,
    (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs) AS total_manufacturing_costs,
    (SELECT COALESCE(SUM(net_amount), 0) FROM sales) AS total_sales,
    (SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_payments";
$financial_stmt = $db->prepare($financial_query);
$financial_stmt->execute();
$financial = $financial_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate profit
$total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
$profit = $financial['total_sales'] - $total_cost;
$profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;

// Get recent manufacturing batches
$batches_query = "SELECT b.id, b.batch_number, p.name as product_name, b.quantity_produced, b.status, b.start_date, b.completion_date 
                 FROM manufacturing_batches b 
                 JOIN products p ON b.product_id = p.id 
                 ORDER BY b.created_at DESC LIMIT 5";
$batches_stmt = $db->prepare($batches_query);
$batches_stmt->execute();

// Get pending receivables
$receivables_query = "SELECT s.id, s.invoice_number, c.name as customer_name, s.sale_date, s.net_amount, 
                     (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as amount_paid,
                     s.payment_due_date
                     FROM sales s 
                     JOIN customers c ON s.customer_id = c.id 
                     WHERE s.payment_status IN ('unpaid', 'partial')
                     ORDER BY s.payment_due_date ASC LIMIT 5";
$receivables_stmt = $db->prepare($receivables_query);
$receivables_stmt->execute();

// Get recent activity logs
$logs_query = "SELECT l.id, u.username, l.action_type, l.module, l.description, l.created_at 
              FROM activity_logs l 
              JOIN users u ON l.user_id = u.id 
              ORDER BY l.created_at DESC LIMIT 10";
$logs_stmt = $db->prepare($logs_query);
$logs_stmt->execute();

// Get fund tracking summary
try {
    $fund_query = "SELECT 
                    (SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'initial' AND status = 'active') as total_invested,
                    (SELECT COALESCE(SUM(amount), 0) FROM funds WHERE type = 'return' AND status = 'active') as total_returns,
                    (SELECT COALESCE(SUM(amount), 0) FROM fund_usage) as total_used,
                    (SELECT COUNT(*) FROM funds WHERE status = 'depleted') as depleted_funds,
                    (SELECT COUNT(*) FROM fund_returns WHERE status = 'pending') as pending_returns";
    $fund_stmt = $db->prepare($fund_query);
    $fund_stmt->execute();
    $fund_summary = $fund_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund summary query: " . $e->getMessage());
    $fund_summary = [
        'total_invested' => 0,
        'total_returns' => 0,
        'total_used' => 0,
        'depleted_funds' => 0,
        'pending_returns' => 0
    ];
}

// Get recent fund activities
try {
    $activities_query = "SELECT 
                        CASE 
                            WHEN f.type = 'initial' THEN 'Initial Investment'
                            WHEN f.type = 'return' THEN 'Fund Return'
                            WHEN fu.type = 'purchase' THEN 'Purchase'
                            WHEN fu.type = 'manufacturing' THEN 'Manufacturing'
                            ELSE 'Other'
                        END as activity_type,
                        CASE 
                            WHEN f.id IS NOT NULL THEN f.amount
                            ELSE fu.amount
                        END as amount,
                        CASE 
                            WHEN f.transfer_date IS NOT NULL THEN f.transfer_date
                            ELSE fu.used_at
                        END as activity_date,
                        CASE 
                            WHEN f.id IS NOT NULL THEN u.full_name
                            ELSE fu.used_by_name
                        END as user_name,
                        CASE 
                            WHEN f.id IS NOT NULL THEN f.status
                            ELSE 'completed'
                        END as status
                        FROM (
                            SELECT id, type, amount, transfer_date, status, created_by,
                                   (SELECT full_name FROM users WHERE id = created_by) as created_by_name
                            FROM funds
                            UNION ALL
                            SELECT id, type, amount, used_at as transfer_date, 'completed' as status, used_by as created_by,
                                   (SELECT full_name FROM users WHERE id = used_by) as used_by_name
                            FROM fund_usage
                        ) as activities
                        LEFT JOIN funds f ON activities.id = f.id
                        LEFT JOIN fund_usage fu ON activities.id = fu.id
                        LEFT JOIN users u ON activities.created_by = u.id
                        ORDER BY activity_date DESC
                        LIMIT 10";
    $activities_stmt = $db->prepare($activities_query);
    $activities_stmt->execute();
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund activities query: " . $e->getMessage());
    $recent_activities = [];
}
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_investment'], 2); ?></div>
        <div class="stat-label">Total Investment</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'], 2); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit, 2); ?></div>
        <div class="stat-label">Profit</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit_margin, 2); ?>%</div>
        <div class="stat-label">Profit Margin</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Recent Manufacturing Batches</h2>
            <a href="manufacturing.php" class="view-all">View All</a>
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
                    </tr>
                </thead>
                <tbody>
                    <?php while($batch = $batches_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($batch['batch_number']); ?></td>
                        <td><?php echo htmlspecialchars($batch['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($batch['quantity_produced']); ?></td>
                        <td><span class="status-badge status-<?php echo $batch['status']; ?>"><?php echo ucfirst($batch['status']); ?></span></td>
                        <td><?php echo htmlspecialchars($batch['start_date']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="dashboard-card">
        <div class="card-header">
            <h2>Pending Receivables</h2>
            <a href="sales.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($receivable = $receivables_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($receivable['invoice_number']); ?></td>
                        <td><?php echo htmlspecialchars($receivable['customer_name']); ?></td>
                        <td><?php echo number_format($receivable['net_amount'] - $receivable['amount_paid'], 2); ?></td>
                        <td><?php echo htmlspecialchars($receivable['payment_due_date']); ?></td>
                        <td>
                            <?php 
                            $today = new DateTime();
                            $due_date = new DateTime($receivable['payment_due_date']);
                            $status = 'upcoming';
                            if($today > $due_date) {
                                $status = 'overdue';
                            }
                            ?>
                            <span class="status-badge status-<?php echo $status; ?>">
                                <?php echo $status === 'overdue' ? 'Overdue' : 'Upcoming'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Activity</h2>
            <a href="activity-logs.php" class="view-all">View All</a>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Description</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($log = $logs_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($log['username']); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($log['action_type'])); ?></td>
                        <td><?php echo ucfirst(htmlspecialchars($log['module'])); ?></td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Fund Tracking Overview</h2>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card">
                            <h3>Total Invested</h3>
                            <p class="stat-value">Rs.<?php echo number_format($fund_summary['total_invested'], 2); ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <h3>Total Returns</h3>
                            <p class="stat-value">Rs.<?php echo number_format($fund_summary['total_returns'], 2); ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <h3>Total Used</h3>
                            <p class="stat-value">Rs.<?php echo number_format($fund_summary['total_used'], 2); ?></p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card">
                            <h3>Net Balance</h3>
                            <p class="stat-value">Rs.<?php echo number_format($fund_summary['total_invested'] + $fund_summary['total_returns'] - $fund_summary['total_used'], 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="alert alert-warning">
                            <strong><?php echo $fund_summary['depleted_funds']; ?> Depleted Funds</strong>
                            <p class="mb-0">Some funds have been fully utilized and may need replenishment.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <strong><?php echo $fund_summary['pending_returns']; ?> Pending Returns</strong>
                            <p class="mb-0">Fund returns from shopkeepers awaiting your approval.</p>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mt-4">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity</th>
                                <th>Amount</th>
                                <th>User</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_activities as $activity): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></td>
                                <td><?php echo htmlspecialchars($activity['activity_type']); ?></td>
                                <td>Rs.<?php echo number_format($activity['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($activity['user_name']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $activity['status'] === 'active' ? 'success' : ($activity['status'] === 'depleted' ? 'danger' : 'info'); ?>">
                                        <?php echo ucfirst($activity['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card {
    background: #f8fafc;
    padding: 1.5rem;
    border-radius: 8px;
    text-align: center;
}

.stat-card h3 {
    margin: 0;
    font-size: 1rem;
    color: #64748b;
}

.stat-value {
    margin: 0.5rem 0 0;
    font-size: 1.5rem;
    font-weight: 600;
    color: #1e293b;
}

.badge {
    padding: 0.5em 0.75em;
    border-radius: 4px;
    font-size: 0.875rem;
    font-weight: 500;
}

.badge-success {
    background-color: #dcfce7;
    color: #166534;
}

.badge-danger {
    background-color: #fee2e2;
    color: #991b1b;
}

.badge-info {
    background-color: #dbeafe;
    color: #1e40af;
}

.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 0;
}

.alert-warning {
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

.alert-info {
    background-color: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}
</style>

<?php include_once '../includes/footer.php'; ?>