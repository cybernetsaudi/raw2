<!-- owner/financial.php -->
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
$page_title = "Financial Overview";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';
// Add at the top of the file
function safeDivide($numerator, $denominator, $precision = 2) {
    if ($denominator == 0) {
        return 0;
    }
    return $numerator / $denominator;
}
// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get financial summary
$query = "SELECT 
    (SELECT COALESCE(SUM(amount), 0) FROM funds) AS total_investment,
    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases) AS total_purchases,
    (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs) AS total_manufacturing_costs,
    (SELECT COALESCE(SUM(net_amount), 0) FROM sales) AS total_sales,
    (SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_payments";
$stmt = $db->prepare($query);
$stmt->execute();
$financial = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate profit
$total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
$profit = $financial['total_sales'] - $total_cost;
$profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;

// Get recent funds transfers
$funds_query = "SELECT f.id, f.amount, u1.full_name as from_user, u2.full_name as to_user, 
               f.description, f.transfer_date 
               FROM funds f 
               JOIN users u1 ON f.from_user_id = u1.id 
               JOIN users u2 ON f.to_user_id = u2.id 
               ORDER BY f.transfer_date DESC LIMIT 10";
$funds_stmt = $db->prepare($funds_query);
$funds_stmt->execute();

// NEW: Calculate Cash Position across all phases
// 1. Cash on hand (funds not yet spent)
$cash_query = "SELECT COALESCE(SUM(amount), 0) AS cash_on_hand FROM funds 
              WHERE id NOT IN (SELECT DISTINCT fund_id FROM purchases WHERE fund_id IS NOT NULL)";
$cash_stmt = $db->prepare($cash_query);
$cash_stmt->execute();
$cash_on_hand = $cash_stmt->fetchColumn();

// 2. Value in raw materials inventory
$raw_materials_query = "SELECT COALESCE(SUM(stock_quantity * 
                      (SELECT AVG(unit_price) FROM purchases WHERE material_id = rm.id)), 0) 
                      AS raw_materials_value 
                      FROM raw_materials rm";
$raw_materials_stmt = $db->prepare($raw_materials_query);
$raw_materials_stmt->execute();
$raw_materials_value = $raw_materials_stmt->fetchColumn();

// 3. Value in manufacturing (work in progress)
$wip_query = "SELECT COALESCE(SUM(
              (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
              (SELECT COALESCE(SUM(
                mu.quantity_required * 
                (SELECT AVG(unit_price) FROM purchases WHERE material_id = mu.material_id)
              ), 0) FROM material_usage mu WHERE mu.batch_id = mb.id)
            ), 0) AS wip_value
            FROM manufacturing_batches mb
            WHERE mb.status != 'completed'";
$wip_stmt = $db->prepare($wip_query);
$wip_stmt->execute();
$wip_value = $wip_stmt->fetchColumn();

// 4. Value in finished goods inventory at manufacturing
$manufacturing_inventory_query = "SELECT COALESCE(SUM(
                                 i.quantity * (
                                    (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                                    (SELECT COALESCE(SUM(
                                      mu.quantity_required * 
                                      (SELECT AVG(unit_price) FROM purchases WHERE material_id = mu.material_id)
                                    ), 0) FROM material_usage mu WHERE mu.batch_id = mb.id)
                                 ) / NULLIF(mb.quantity_produced, 0)
                               ), 0) AS manufacturing_value
                               FROM inventory i
                               JOIN products p ON i.product_id = p.id
                               JOIN manufacturing_batches mb ON p.id = mb.product_id
                               WHERE i.location = 'manufacturing' AND mb.status = 'completed'";
$manufacturing_inventory_stmt = $db->prepare($manufacturing_inventory_query);
$manufacturing_inventory_stmt->execute();
$manufacturing_inventory_value = $manufacturing_inventory_stmt->fetchColumn();

// 5. Value in transit inventory (at cost)
$transit_inventory_query = "SELECT COALESCE(SUM(
                           i.quantity * (
                              (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                              (SELECT COALESCE(SUM(
                                mu.quantity_required * 
                                (SELECT AVG(unit_price) FROM purchases WHERE material_id = mu.material_id)
                              ), 0) FROM material_usage mu WHERE mu.batch_id = mb.id)
                           ) / NULLIF(mb.quantity_produced, 0)
                         ), 0) AS transit_value
                         FROM inventory i
                         JOIN products p ON i.product_id = p.id
                         JOIN manufacturing_batches mb ON p.id = mb.product_id
                         WHERE i.location = 'transit'";
$transit_inventory_stmt = $db->prepare($transit_inventory_query);
$transit_inventory_stmt->execute();
$transit_inventory_value = $transit_inventory_stmt->fetchColumn();

// 6. Value in wholesale inventory (at retail value with 30% margin)
$wholesale_inventory_query = "SELECT COALESCE(SUM(
                             i.quantity * (
                                (SELECT COALESCE(SUM(mc.amount), 0) FROM manufacturing_costs mc WHERE mc.batch_id = mb.id) +
                                (SELECT COALESCE(SUM(
                                  mu.quantity_required * 
                                  (SELECT AVG(unit_price) FROM purchases WHERE material_id = mu.material_id)
                                ), 0) FROM material_usage mu WHERE mu.batch_id = mb.id)
                             ) / NULLIF(mb.quantity_produced, 0) * 1.3
                           ), 0) AS wholesale_value
                           FROM inventory i
                           JOIN products p ON i.product_id = p.id
                           JOIN manufacturing_batches mb ON p.id = mb.product_id
                           WHERE i.location = 'wholesale'";
$wholesale_inventory_stmt = $db->prepare($wholesale_inventory_query);
$wholesale_inventory_stmt->execute();
$wholesale_inventory_value = $wholesale_inventory_stmt->fetchColumn();

// 7. Value in accounts receivable (sales not yet paid)
$accounts_receivable = $financial['total_sales'] - $financial['total_payments'];

// 8. Calculate total business value (cash position)
$total_business_value = $cash_on_hand + $raw_materials_value + $wip_value + 
                       $manufacturing_inventory_value + $transit_inventory_value + 
                       $wholesale_inventory_value + $accounts_receivable;

// Get cash position breakdown for chart
$cash_position_data = [
    ['phase' => 'Cash on Hand', 'value' => $cash_on_hand],
    ['phase' => 'Raw Materials', 'value' => $raw_materials_value],
    ['phase' => 'Work in Progress', 'value' => $wip_value],
    ['phase' => 'Manufacturing Inventory', 'value' => $manufacturing_inventory_value],
    ['phase' => 'Transit Inventory', 'value' => $transit_inventory_value],
    ['phase' => 'Wholesale Inventory', 'value' => $wholesale_inventory_value],
    ['phase' => 'Accounts Receivable', 'value' => $accounts_receivable]
];

// Convert to JSON for JavaScript
$cash_position_json = json_encode($cash_position_data);

// Get fund usage breakdown
try {
    $usage_query = "SELECT 
        fu.type,
        COUNT(*) as count,
        SUM(fu.amount) as total_amount,
        AVG(fu.amount) as avg_amount,
        MAX(fu.created_at) as last_used,
        CASE 
            WHEN MAX(fu.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'active'
            WHEN MAX(fu.created_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN 'inactive'
            ELSE 'deprecated'
        END as status
    FROM fund_usage fu
    GROUP BY fu.type
    ORDER BY total_amount DESC";
    $usage_stmt = $db->prepare($usage_query);
    $usage_stmt->execute();
    $usage_breakdown = $usage_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund usage query: " . $e->getMessage());
    $usage_breakdown = [];
}

// Get fund details with usage information
try {
    $fund_details_query = "SELECT 
        f.id,
        f.type,
        f.amount,
        f.status,
        f.transfer_date as created_at,
        u.full_name as created_by_name,
        COALESCE(SUM(fu.amount), 0) as used_amount,
        COUNT(fu.id) as usage_count
    FROM funds f
    JOIN users u ON f.created_by = u.id
    LEFT JOIN fund_usage fu ON f.id = fu.fund_id
    GROUP BY f.id, f.type, f.amount, f.status, f.transfer_date, u.full_name
    ORDER BY f.transfer_date DESC";
    $fund_details_stmt = $db->prepare($fund_details_query);
    $fund_details_stmt->execute();
    $fund_details = $fund_details_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund details query: " . $e->getMessage());
    $fund_details = [];
}
?>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_investment'], 2); ?></div>
        <div class="stat-label">Total Investment</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_purchases'], 2); ?></div>
        <div class="stat-label">Material Purchases</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_manufacturing_costs'], 2); ?></div>
        <div class="stat-label">Manufacturing Costs</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'], 2); ?></div>
        <div class="stat-label">Total Sales</div>
    </div>
</div>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit, 2); ?></div>
        <div class="stat-label">Profit</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($profit_margin, 2); ?>%</div>
        <div class="stat-label">Profit Margin</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_payments'], 2); ?></div>
        <div class="stat-label">Payments Received</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($financial['total_sales'] - $financial['total_payments'], 2); ?></div>
        <div class="stat-label">Outstanding Receivables</div>
    </div>
</div>

<div class="cash-position-section">
    <h2 class="section-title">Cash Position Analysis</h2>
    <div class="section-description">
        <p>This analysis shows the total business value distributed across different phases of your operations.</p>
    </div>
    
    <div class="cash-position-overview">
        <div class="total-business-value">
            <div class="value-amount"><?php echo number_format($total_business_value, 2); ?></div>
            <div class="value-label">Total Business Value</div>
        </div>
        
<div class="cash-position-metrics">
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($cash_on_hand, 2); ?></div>
        <div class="metric-label">Cash on Hand</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($cash_on_hand, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($raw_materials_value, 2); ?></div>
        <div class="metric-label">Raw Materials</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($raw_materials_value, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($wip_value + $manufacturing_inventory_value, 2); ?></div>
        <div class="metric-label">Manufacturing</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($wip_value + $manufacturing_inventory_value, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($transit_inventory_value, 2); ?></div>
        <div class="metric-label">In Transit</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($transit_inventory_value, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($wholesale_inventory_value, 2); ?></div>
        <div class="metric-label">Wholesale (30% Margin)</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($wholesale_inventory_value, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
    
    <div class="metric-card">
        <div class="metric-value"><?php echo number_format($accounts_receivable, 2); ?></div>
        <div class="metric-label">Accounts Receivable</div>
        <div class="metric-percentage">
            <?php echo number_format(safeDivide($accounts_receivable, $total_business_value) * 100, 1); ?>%
        </div>
    </div>
</div>    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Cash Position Distribution</h2>
            <div class="card-actions">
                <button class="button small" id="toggleChartViewBtn">Toggle Chart View</button>
                <button class="button small" id="toggleFlowViewBtn">Show Flow Diagram</button>
            </div>
        </div>
        <div class="card-content">
            <div class="chart-container">
                <canvas id="cashPositionChart" height="300"></canvas>
            </div>
            <div class="cash-flow-diagram" style="display: none;">
                <div class="cash-flow-stages">
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($cash_on_hand, 2); ?></div>
                        <div class="stage-label">Cash</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($raw_materials_value, 2); ?></div>
                        <div class="stage-label">Raw Materials</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-industry"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($wip_value, 2); ?></div>
                        <div class="stage-label">Work in Progress</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($manufacturing_inventory_value, 2); ?></div>
                        <div class="stage-label">Finished Goods</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($transit_inventory_value, 2); ?></div>
                        <div class="stage-label">In Transit</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($wholesale_inventory_value, 2); ?></div>
                        <div class="stage-label">Wholesale</div>
                        <div class="stage-arrow"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    
                    <div class="flow-stage">
                        <div class="stage-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="stage-value"><?php echo number_format($accounts_receivable, 2); ?></div>
                        <div class="stage-label">Receivables</div>
                    </div>
                </div>
                
                <div class="cash-flow-total">
                    <div class="total-label">Total Business Value</div>
                    <div class="total-value"><?php echo number_format($total_business_value, 2); ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Financial Performance</h2>
        </div>
        <div class="card-content">
            <div class="chart-container" id="financialChart" style="height: 300px;">
                <!-- Chart will be rendered here by JavaScript -->
            </div>
        </div>
    </div>
    
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Recent Fund Transfers</h2>
            <button class="button small" id="newFundTransferBtn">New Transfer</button>
        </div>
        <div class="card-content">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($fund = $funds_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo date('M j, Y', strtotime($fund['transfer_date'])); ?></td>
                        <td><?php echo htmlspecialchars($fund['from_user']); ?></td>
                        <td><?php echo htmlspecialchars($fund['to_user']); ?></td>
                        <td class="amount-cell"><?php echo number_format($fund['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($fund['description']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if($funds_stmt->rowCount() === 0): ?>
                    <tr>
                        <td colspan="5" class="no-data">No fund transfers found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="dashboard-card full-width">
        <div class="card-header">
            <h2>Fund Management</h2>
        </div>
        <div class="card-content">
            <div class="row">
                <div class="col-md-6">
                    <h3>Fund Usage Breakdown</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Count</th>
                                <th>Total Amount</th>
                                <th>Avg. Amount</th>
                                <th>Last Used</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($usage_breakdown)): ?>
                                <?php foreach($usage_breakdown as $usage): ?>
                                <tr>
                                    <td><?php echo ucfirst($usage['type']); ?></td>
                                    <td><?php echo number_format($usage['count']); ?></td>
                                    <td class="amount-cell">Rs.<?php echo number_format($usage['total_amount'], 2); ?></td>
                                    <td class="amount-cell">Rs.<?php echo number_format($usage['avg_amount'], 2); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($usage['last_used'])); ?></td>
                                    <td>
                                        <span class="status-badge <?php 
                                            echo $usage['status'] === 'active' ? 'success' : 
                                                ($usage['status'] === 'inactive' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst($usage['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="no-records">No fund usage records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h3>Fund Status Summary</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Total Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $status_summary = [];
                                if (!empty($fund_details)) {
                                    foreach($fund_details as $fund) {
                                        $status = $fund['status'];
                                        if(!isset($status_summary[$status])) {
                                            $status_summary[$status] = ['count' => 0, 'amount' => 0];
                                        }
                                        $status_summary[$status]['count']++;
                                        $status_summary[$status]['amount'] += $fund['amount'];
                                    }
                                }
                                
                                if (!empty($status_summary)):
                                    foreach($status_summary as $status => $summary):
                            ?>
                                <tr>
                                    <td>
                                        <span class="status-badge <?php echo $status === 'active' ? 'success' : ($status === 'depleted' ? 'danger' : 'info'); ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($summary['count']); ?></td>
                                    <td class="amount-cell">Rs.<?php echo number_format($summary['amount'], 2); ?></td>
                                </tr>
                            <?php 
                                    endforeach;
                                else:
                            ?>
                                <tr>
                                    <td colspan="3" class="no-records">No fund status records found</td>
                                </tr>
                            <?php 
                                endif;
                            } catch (Exception $e) {
                                error_log("Error in fund status summary: " . $e->getMessage());
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-12">
                    <h3>Fund Details</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Created By</th>
                                <th>Used Amount</th>
                                <th>Usage Count</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($fund_details)): ?>
                                <?php foreach($fund_details as $fund): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($fund['created_at'])); ?></td>
                                    <td><?php echo ucfirst($fund['type']); ?></td>
                                    <td class="amount-cell">Rs.<?php echo number_format($fund['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($fund['created_by_name']); ?></td>
                                    <td class="amount-cell">Rs.<?php echo number_format($fund['used_amount'], 2); ?></td>
                                    <td><?php echo number_format($fund['usage_count']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $fund['status'] === 'active' ? 'success' : ($fund['status'] === 'depleted' ? 'danger' : 'info'); ?>">
                                            <?php echo ucfirst($fund['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="no-records">No fund details found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Fund Transfer Modal -->
<div id="fundTransferModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Transfer Funds</h2>
        <form id="fundTransferForm" action="../api/transfer-funds.php" method="post">
            <div class="form-group">
                <label for="amount">Amount:</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label for="to_user_id">Transfer To:</label>
                <select id="to_user_id" name="to_user_id" required>
                    <option value="">Select User</option>
                    <?php
                    // Get all incharge users
                    $users_query = "SELECT id, full_name FROM users WHERE role = 'incharge' AND is_active = 1";
                    $users_stmt = $db->prepare($users_query);
                    $users_stmt->execute();
                    while($user = $users_stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo '<option value="' . $user['id'] . '">' . htmlspecialchars($user['full_name']) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea id="description" name="description" rows="3"></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelFundTransfer">Cancel</button>
                <button type="submit" class="button primary">Transfer Funds</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>document.addEventListener('DOMContentLoaded', function() {
    // Debug logging for initialization
    console.log('Financial overview page initialized');
    
    // ========================
    // Fund Transfer Modal functionality
    // ========================
    const transferModal = document.getElementById('fundTransferModal');
    const transferBtn = document.getElementById('newFundTransferBtn');
    const transferCloseBtn = document.querySelector('#fundTransferModal .close-modal');
    const transferCancelBtn = document.getElementById('cancelFundTransfer');
    const transferForm = document.getElementById('fundTransferForm');
    
    // Debug logging for element existence
    console.log('Transfer elements found:', {
        modal: !!transferModal,
        button: !!transferBtn,
        closeBtn: !!transferCloseBtn,
        cancelBtn: !!transferCancelBtn,
        form: !!transferForm
    });
    
    // Add toast container if it doesn't exist
    if (!document.querySelector('.toast-container')) {
        const toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        toastContainer.setAttribute('aria-live', 'polite');
        document.body.appendChild(toastContainer);
    }
    
    // Add modal styles if needed
    if (transferModal) {
        // Ensure modal has proper styling
        transferModal.style.display = 'none';
        transferModal.style.position = 'fixed';
        transferModal.style.top = '0';
        transferModal.style.left = '0';
        transferModal.style.width = '100%';
        transferModal.style.height = '100%';
        transferModal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        transferModal.style.zIndex = '2000';
        transferModal.style.overflow = 'auto';
    }
    
    // Open modal
    if (transferBtn && transferModal) {
        transferBtn.addEventListener('click', function(e) {
            console.log('Transfer button clicked');
            e.preventDefault();
            
            // Show modal with proper z-index
            transferModal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
            
            // Focus on first input for better accessibility
            setTimeout(() => {
                const amountInput = document.getElementById('amount');
                if (amountInput) {
                    amountInput.focus();
                    console.log('Focus set on amount input');
                }
            }, 100);
        });
    }
    
    // Close modal via X button
    if (transferCloseBtn) {
        transferCloseBtn.addEventListener('click', function(e) {
            console.log('Close button clicked');
            e.preventDefault();
            closeTransferModal();
        });
    }
    
    // Close modal via Cancel button
    if (transferCancelBtn) {
        transferCancelBtn.addEventListener('click', function(e) {
            console.log('Cancel button clicked');
            e.preventDefault();
            closeTransferModal();
        });
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === transferModal) {
            console.log('Clicked outside modal');
            closeTransferModal();
        }
    });
    
    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && transferModal && transferModal.style.display === 'block') {
            console.log('Escape key pressed');
            closeTransferModal();
        }
    });
    
    // Function to close transfer modal
    function closeTransferModal() {
        if (transferModal) {
            transferModal.style.display = 'none';
            document.body.style.overflow = ''; // Restore scrolling
            
            // Reset form if exists
            if (transferForm) {
                transferForm.reset();
            }
        }
    }
    
    // Handle fund transfer form submission
    if (transferForm) {
        transferForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('Fund transfer form submitted');
            
            // Form validation
            const amount = document.getElementById('amount').value;
            const toUserId = document.getElementById('to_user_id').value;
            const description = document.getElementById('description').value;
            
            // Validate amount
            if (!amount || isNaN(parseFloat(amount)) || parseFloat(amount) <= 0) {
                alert('Please enter a valid amount greater than zero.');
                document.getElementById('amount').focus();
                return;
            }
            
            // Validate recipient
            if (!toUserId) {
                alert('Please select a recipient for the fund transfer.');
                document.getElementById('to_user_id').focus();
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Processing...';
            
            // Get current user ID
            const fromUserId = document.getElementById('current-user-id').value;
            
            // Create FormData object
            const formData = new FormData();
            formData.append('amount', amount);
            formData.append('to_user_id', toUserId);
            formData.append('description', description);
            formData.append('from_user_id', fromUserId);
            
            // Log form data (for debugging)
            console.log('Form data being sent:', {
                amount,
                to_user_id: toUserId,
                description,
                from_user_id: fromUserId
            });
            
            // Send AJAX request
            fetch(this.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`Server responded with ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Transfer response:', data);
                
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                if (data.success) {
                    // Show success message
                    showNotification('Success', data.message || 'Funds transferred successfully!', 'success');
                    
                    // Close modal
                    closeTransferModal();
                    
                    // Reload page after a delay to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    showNotification('Error', data.message || 'Failed to transfer funds. Please try again.', 'error');
                }
            })
            .catch(error => {
                console.error('Transfer error:', error);
                
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                // Show error notification
                showNotification('Error', 'An unexpected error occurred. Please try again.', 'error');
            });
        });
    }
    
    // ========================
    // Cash Position Chart
    // ========================
    const cashPositionData = <?php echo $cash_position_json; ?> || [];
    const ctx = document.getElementById('cashPositionChart')?.getContext('2d');
    
    if (ctx) {
        try {
            console.log('Initializing cash position chart');
            
            // Prepare data for the chart
            const labels = cashPositionData.map(item => item.phase);
            const values = cashPositionData.map(item => item.value);
            
            // Color scheme for better accessibility and visual appeal
            const colors = {
                backgrounds: [
                    'rgba(54, 162, 235, 0.6)',  // Cash on Hand
                    'rgba(255, 206, 86, 0.6)',  // Raw Materials
                    'rgba(75, 192, 192, 0.6)',  // Work in Progress
                    'rgba(153, 102, 255, 0.6)', // Manufacturing Inventory
                    'rgba(255, 159, 64, 0.6)',  // Transit Inventory
                    'rgba(255, 99, 132, 0.6)',  // Wholesale Inventory
                    'rgba(201, 203, 207, 0.6)'  // Accounts Receivable
                ],
                borders: [
                    'rgb(54, 162, 235)',
                    'rgb(255, 206, 86)',
                    'rgb(75, 192, 192)',
                    'rgb(153, 102, 255)',
                    'rgb(255, 159, 64)',
                    'rgb(255, 99, 132)',
                    'rgb(201, 203, 207)'
                ]
            };
            
            // Format currency helper function
            const formatCurrency = (value) => {
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: 'PKR',
                    minimumFractionDigits: 2
                }).format(value).replace('PKR', 'Rs.');
            };
            
            // Create chart with accessibility enhancements
            const cashPositionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Value in Each Phase',
                        data: values,
                        backgroundColor: colors.backgrounds,
                        borderColor: colors.borders,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const formattedValue = formatCurrency(value);
                                    
                                    const total = values.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                    
                                    return `${formattedValue} (${percentage}% of total)`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return new Intl.NumberFormat('en-US', {
                                        style: 'currency',
                                        currency: 'PKR',
                                        notation: 'compact',
                                        compactDisplay: 'short'
                                    }).format(value);
                                }
                            }
                        }
                    }
                },
                plugins: [{
                    id: 'accessibility',
                    afterRender: (chart) => {
                        // Add screen reader description
                        const canvas = chart.canvas;
                        if (!canvas.hasAttribute('aria-label')) {
                            canvas.setAttribute('aria-label', 'Bar chart showing cash position distribution across business phases');
                        }
                        
                        // Create off-screen but accessible text description
                        if (!document.getElementById('chart-description')) {
                            const ariaLabel = document.createElement('div');
                            ariaLabel.id = 'chart-description';
                            ariaLabel.className = 'sr-only';
                            ariaLabel.textContent = 'Chart showing cash position across phases: ' + 
                                labels.map((label, i) => `${label}: ${formatCurrency(values[i])}`).join(', ');
                            canvas.parentNode.appendChild(ariaLabel);
                            canvas.setAttribute('aria-describedby', 'chart-description');
                        }
                    }
                }]
            });
            
            // ========================
            // Toggle Chart View Button
            // ========================
            const toggleChartViewBtn = document.getElementById('toggleChartViewBtn');
            let isBarChart = true;
            
            if (toggleChartViewBtn) {
                toggleChartViewBtn.addEventListener('click', function() {
                    console.log('Toggling chart view');
                    isBarChart = !isBarChart;
                    
                    try {
                        // Destroy the current chart
                        cashPositionChart.destroy();
                        
                        // Create a new chart with different type
                        const newChart = new Chart(ctx, {
                            type: isBarChart ? 'bar' : 'pie',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Value in Each Phase',
                                    data: values,
                                    backgroundColor: colors.backgrounds,
                                    borderColor: colors.borders,
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        display: !isBarChart,
                                        position: 'right'
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const value = context.raw;
                                                const formattedValue = formatCurrency(value);
                                                
                                                const total = values.reduce((a, b) => a + b, 0);
                                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                                
                                                return `${context.label}: ${formattedValue} (${percentage}%)`;
                                            }
                                        }
                                    }
                                },
                                scales: isBarChart ? {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            callback: function(value) {
                                                return new Intl.NumberFormat('en-US', {
                                                    style: 'currency',
                                                    currency: 'PKR',
                                                    notation: 'compact',
                                                    compactDisplay: 'short'
                                                }).format(value);
                                            }
                                        }
                                    }
                                } : {}
                            },
                            plugins: [{
                                id: 'accessibility',
                                afterRender: (chart) => {
                                    // Update screen reader description
                                    const chartType = isBarChart ? 'Bar' : 'Pie';
                                    const canvas = chart.canvas;
                                    canvas.setAttribute('aria-label', `${chartType} chart showing cash position distribution across business phases`);
                                    
                                    const description = document.getElementById('chart-description');
                                    if (description) {
                                        description.textContent = `${chartType} chart showing cash position across phases: ` + 
                                            labels.map((label, i) => `${label}: ${formatCurrency(values[i])}`).join(', ');
                                    }
                                }
                            }]
                        });
                        
                        // Update button text
                        this.textContent = isBarChart ? 'Show Pie Chart' : 'Show Bar Chart';
                        
                        // Update the chart reference
                        Object.assign(cashPositionChart, newChart);
                    } catch (error) {
                        console.error('Error toggling chart view:', error);
                        showNotification('Error', 'Failed to change chart view', 'error');
                    }
                });
            }
            
            // ========================
            // Toggle Flow View Button
            // ========================
            const toggleFlowViewBtn = document.getElementById('toggleFlowViewBtn');
            const chartContainer = document.querySelector('.chart-container');
            const cashFlowDiagram = document.querySelector('.cash-flow-diagram');
            
            if (toggleFlowViewBtn && chartContainer && cashFlowDiagram) {
                toggleFlowViewBtn.addEventListener('click', function() {
                    console.log('Toggling flow view');
                    const isChartVisible = chartContainer.style.display !== 'none';
                    
                    if (isChartVisible) {
                        chartContainer.style.display = 'none';
                        cashFlowDiagram.style.display = 'block';
                        this.textContent = 'Show Chart';
                        // Hide chart toggle button when showing flow diagram
                        if (toggleChartViewBtn) {
                            toggleChartViewBtn.style.display = 'none';
                        }
                    } else {
                        chartContainer.style.display = 'block';
                        cashFlowDiagram.style.display = 'none';
                        this.textContent = 'Show Flow Diagram';
                        // Show chart toggle button when showing chart
                        if (toggleChartViewBtn) {
                            toggleChartViewBtn.style.display = 'inline-block';
                        }
                    }
                });
            }
        } catch (error) {
            console.error('Error initializing chart:', error);
            
            // Show error message instead of chart
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.innerHTML = `
                    <div class="chart-error">
                        <p>Unable to load chart. Please refresh the page or try again later.</p>
                        <button class="button small" onclick="window.location.reload()">Refresh Page</button>
                    </div>
                `;
            }
        }
    }
    
    // ========================
    // Accessibility Enhancements
    // ========================
    
    // Format all currency values in the DOM for consistency
    const formatDOMCurrencyValues = () => {
        const currencyElements = document.querySelectorAll('.value-amount, .metric-value, .stage-value, .total-value, .amount-cell');
        
        currencyElements.forEach(element => {
            const rawValue = parseFloat(element.textContent.replace(/[^\d.-]/g, ''));
            if (!isNaN(rawValue)) {
                element.setAttribute('data-raw-value', rawValue);
                // Keep the formatted value but add screen reader text
                const srText = document.createElement('span');
                srText.className = 'sr-only';
                srText.textContent = ` Pakistani Rupees`;
                element.appendChild(srText);
            }
        });
    };
    
    // Add keyboard navigation for flow stages
    const setupFlowStageKeyboardNav = () => {
        const flowStages = document.querySelectorAll('.flow-stage');
        
        flowStages.forEach((stage, index) => {
            // Make focusable
            stage.setAttribute('tabindex', '0');
            
            // Add appropriate ARIA attributes
            const stageLabel = stage.querySelector('.stage-label')?.textContent || '';
            const stageValue = stage.querySelector('.stage-value')?.textContent || '';
            stage.setAttribute('aria-label', `${stageLabel} phase: ${stageValue} Pakistani Rupees`);
            
            // Add keyboard navigation
            stage.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowRight' && index < flowStages.length - 1) {
                    e.preventDefault();
                    flowStages[index + 1].focus();
                } else if (e.key === 'ArrowLeft' && index > 0) {
                    e.preventDefault();
                    flowStages[index - 1].focus();
                }
            });
        });
    };
    
    // Initialize accessibility enhancements
    formatDOMCurrencyValues();
    setupFlowStageKeyboardNav();
    
    // ========================
    // Loading States for Tables
    // ========================
    document.querySelectorAll('.fund-table').forEach(table => {
        if (table.querySelectorAll('tbody tr').length === 0) {
            table.querySelector('tbody').innerHTML = `
                <tr>
                    <td colspan="${table.querySelectorAll('th').length}" class="no-records">
                        No records found
                    </td>
                </tr>
            `;
        }
    });
    
    // ========================
    // Utility Functions
    // ========================
    
    /**
     * Show a notification toast message
     * @param {string} title - Notification title
     * @param {string} message - Notification message
     * @param {string} type - Type of notification (success, error, warning, info)
     * @param {number} duration - Duration in milliseconds
     */
    function showNotification(title, message, type = 'info', duration = 4000) {
        // Create toast container if it doesn't exist
        let toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            toastContainer.setAttribute('role', 'alert');
            toastContainer.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.setAttribute('role', 'status');
        
        // Set icon based on type
        let iconClass = 'info-circle';
        if (type === 'success') iconClass = 'check-circle';
        if (type === 'warning') iconClass = 'exclamation-triangle';
        if (type === 'error') iconClass = 'exclamation-circle';
        
        // Create toast content
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fas fa-${iconClass}" aria-hidden="true"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${escapeHtml(title)}</div>
                <div class="toast-message">${escapeHtml(message)}</div>
            </div>
            <button type="button" class="toast-close" aria-label="Close notification">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Add close button functionality
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                removeToast(toast);
            });
        }
        
        // Auto-remove after duration
        setTimeout(() => {
            if (document.body.contains(toast)) {
                removeToast(toast);
            }
        }, duration);
        
        // Animation for removing toast
        function removeToast(toast) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    }
    
    /**
     * Escape HTML to prevent XSS
     * @param {string} unsafeText - The text to escape
     * @returns {string} - Escaped HTML string
     */
    function escapeHtml(unsafeText) {
        if (!unsafeText) return '';
        
        return String(unsafeText)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
    /**
     * Log activity to the server
     * @param {string} action - The action performed
     * @param {string} module - The module name
     * @param {string} description - Action description
     */
    function logUserActivity(action, module, description) {
        const userId = document.getElementById('current-user-id')?.value;
        if (!userId) return;
        
        // Use fetch API with keepalive to ensure the request completes
        fetch('../api/log-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                user_id: userId,
                action_type: action,
                module: module,
                description: description
            }),
            keepalive: true
        }).catch(error => {
            console.error('Error logging activity:', error);
        });
    }
    
    // Log page view
    logUserActivity('read', 'financial', 'Viewed financial overview');
});</script>

<style>

/* Add to your CSS */
.loading-skeleton {
    height: 100%;
    width: 100%;
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading-skeleton 1.5s infinite;
}

@keyframes loading-skeleton {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

.loading {
    pointer-events: none;
    min-height: 100px;
    position: relative;
}

/* Add to your CSS */
.error-state {
    padding: 1rem;
    background-color: #fee2e2;
    border-radius: 8px;
    border-left: 4px solid #ef4444;
    margin-bottom: 1rem;
}

.error-state h3 {
    color: #b91c1c;
    margin-top: 0;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}

.error-state p {
    color: #7f1d1d;
    margin: 0;
    font-size: 0.875rem;
}
/* Cash Position Section Styles */
.cash-position-section {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.section-title {
    margin-top: 0;
    margin-bottom: 0.75rem;
    font-size: 1.25rem;
    color: #212529;
}

.section-description {
    margin-bottom: 1.5rem;
    color: #6c757d;
    font-size: 0.9rem;
}

.cash-position-overview {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.total-business-value {
    background-color: #e8f0fe;
    padding: 1.25rem;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    border-left: 4px solid #4285f4;
}

.value-amount {
    font-size: 2rem;
    font-weight: 700;
    color: #4285f4;
    margin-bottom: 0.5rem;
}

.value-label {
    font-size: 1rem;
    color: #4d4d4d;
    font-weight: 500;
}

.cash-position-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.metric-card {
    background-color: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.metric-card:focus-within {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    height: 4px;
    width: 100%;
    background-color: #4285f4;
}

.metric-card:nth-child(1)::before { background-color: #4285f4; } /* Cash */
.metric-card:nth-child(2)::before { background-color: #fbbc04; } /* Raw Materials */
.metric-card:nth-child(3)::before { background-color: #34a853; } /* Manufacturing */
.metric-card:nth-child(4)::before { background-color: #fa7b17; } /* Transit */
.metric-card:nth-child(5)::before { background-color: #ea4335; } /* Wholesale */
.metric-card:nth-child(6)::before { background-color: #9c27b0; } /* Receivables */

.metric-value {
    font-size: 1.25rem;
    font-weight: 600;
    color: #212529;
    margin-bottom: 0.25rem;
}

.metric-label {
    font-size: 0.875rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}

.metric-percentage {
    font-size: 0.875rem;
    font-weight: 500;
    color: #4285f4;
    background-color: rgba(66, 133, 244, 0.1);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    display: inline-block;
}

/* Cash Flow Diagram Styles */
.cash-flow-diagram {
    padding: 1.5rem;
    background-color: #f8f9fa;
    border-radius: 8px;
    margin-top: 1rem;
}

.cash-flow-stages {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
    justify-content: center;
    margin-bottom: 2rem;
}

.flow-stage {
    display: flex;
    flex-direction: column;
    align-items: center;
    background-color: white;
    padding: 1rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    min-width: 120px;
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.flow-stage:hover, .flow-stage:focus {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    outline: none;
}

.flow-stage:focus {
    outline: 2px solid #4285f4;
    outline-offset: 2px;
}

.stage-icon {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    color: #4285f4;
}

.stage-value {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #212529;
}

.stage-label {
    font-size: 0.75rem;
    color: #6c757d;
}

.stage-arrow {
    position: absolute;
    right: -12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    z-index: 1;
}

.flow-stage:nth-child(1) .stage-icon { color: #4285f4; }
.flow-stage:nth-child(2) .stage-icon { color: #fbbc04; }
.flow-stage:nth-child(3) .stage-icon { color: #34a853; }
.flow-stage:nth-child(4) .stage-icon { color: #673ab7; }
.flow-stage:nth-child(5) .stage-icon { color: #fa7b17; }
.flow-stage:nth-child(6) .stage-icon { color: #ea4335; }
.flow-stage:nth-child(7) .stage-icon { color: #9c27b0; }

.cash-flow-total {
    background-color: #e8f0fe;
    padding: 1rem;
    border-radius: 8px;
    text-align: center;
    border-left: 4px solid #4285f4;
}

.total-label {
    font-size: 0.875rem;
    color: #4d4d4d;
    margin-bottom: 0.25rem;
}

.total-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #4285f4;
}

/* Chart container */
.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}

/* Card actions */
.card-actions {
    display: flex;
    gap: 0.5rem;
}

/* Amount styling */
.amount-cell {
    font-family: monospace;
    text-align: right;
    font-weight: 500;
}

/* Screen reader only */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}

/* Responsive Styles */
@media (max-width: 768px) {
    .cash-position-metrics {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
    
    .cash-flow-stages {
        flex-direction: column;
        align-items: stretch;
    }
    
    .flow-stage {
        width: 100%;
        flex-direction: row;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
    }
    
    .stage-icon {
        margin-bottom: 0;
        margin-right: 0.5rem;
    }
    
    .stage-arrow {
        position: static;
        transform: none;
        margin-top: 0.5rem;
        transform: rotate(90deg);
    }
    
    .value-amount {
        font-size: 1.5rem;
    }
    
    .metric-value {
        font-size: 1rem;
    }
    
    .card-actions {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .card-actions .button {
        margin-bottom: 0.5rem;
    }
}

@media (max-width: 576px) {
    .cash-position-section {
        padding: 1rem;
    }
    
    .cash-position-metrics {
        grid-template-columns: 1fr 1fr;
    }
    
    .metric-card {
        padding: 0.75rem;
    }
    
    .metric-value {
        font-size: 0.9rem;
    }
    
    .metric-label, .metric-percentage {
        font-size: 0.75rem;
    }
    
    .section-title {
        font-size: 1.1rem;
    }
    
    .total-business-value {
        padding: 1rem;
    }
    
    .cash-flow-total {
        padding: 0.75rem;
    }
    
    .total-value {
        font-size: 1.25rem;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .metric-card, .flow-stage {
        transition: none;
    }
}

@media (prefers-color-scheme: dark) {
    .cash-position-section {
        background-color: #222;
    }
    
    .section-title {
        color: #f8f9fa;
    }
    
    .section-description {
        color: #adb5bd;
    }
    
    .total-business-value {
        background-color: rgba(66, 133, 244, 0.1);
    }
    
    .metric-card {
        background-color: #333;
    }
    
    .metric-value {
        color: #f8f9fa;
    }
    
    .metric-label {
        color: #adb5bd;
    }
    
    .cash-flow-diagram {
        background-color: #222;
    }
    
    .flow-stage {
        background-color: #333;
    }
    
    .stage-value {
        color: #f8f9fa;
    }
    
    .stage-label {
        color: #adb5bd;
    }
    
    .cash-flow-total {
        background-color: rgba(66, 133, 244, 0.15);
    }
}

/* High contrast mode support */
@media (forced-colors: active) {
    .metric-card, .flow-stage, .total-business-value, .cash-flow-total {
        border: 1px solid;
    }
    
    .metric-card::before {
        background-color: currentColor;
    }
}

/* Print styles */
@media print {
    .cash-position-section {
        break-inside: avoid;
        page-break-inside: avoid;
        background-color: white;
        box-shadow: none;
        padding: 0;
    }
    
    .metric-card, .flow-stage {
        box-shadow: none;
        border: 1px solid #ddd;
    }
    
    .chart-container {
        height: 200px;
        page-break-inside: avoid;
    }
    
    .cash-flow-diagram {
        page-break-inside: avoid;
    }
    
    .dashboard-card {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    
    .button, .modal, #toggleChartViewBtn, #toggleFlowViewBtn {
        display: none !important;
    }
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

.table th {
    font-weight: 600;
    color: #1e293b;
}

.table td {
    vertical-align: middle;
}

/* Fund Management Tables Styling */
.fund-management-section {
    margin-top: 30px;
}

.fund-management-section h3 {
    color: #333;
    margin-bottom: 20px;
    font-size: 1.5rem;
}

.fund-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 30px;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.fund-table th {
    background: #f8f9fa;
    color: #333;
    font-weight: 600;
    padding: 15px;
    text-align: left;
    border-bottom: 2px solid #dee2e6;
}

.fund-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #dee2e6;
    color: #444;
}

.fund-table tr:last-child td {
    border-bottom: none;
}

.fund-table tr:hover {
    background-color: #f8f9fa;
}

.fund-table .amount {
    font-family: 'Roboto Mono', monospace;
    font-weight: 500;
}

.fund-table .status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.fund-table .status.active {
    background-color: #e8f5e9;
    color: #2e7d32;
}

.fund-table .status.inactive {
    background-color: #ffebee;
    color: #c62828;
}

.fund-table .status.pending {
    background-color: #fff3e0;
    color: #ef6c00;
}

/* Mobile Responsive Styles */
@media (max-width: 768px) {
    .fund-table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .fund-table th,
    .fund-table td {
        min-width: 120px;
        padding: 10px;
    }

    .fund-table th:first-child,
    .fund-table td:first-child {
        position: sticky;
        left: 0;
        background: white;
        z-index: 1;
    }

    .fund-table th:first-child {
        background: #f8f9fa;
    }

    .fund-table tr:hover td:first-child {
        background: #f8f9fa;
    }

    .fund-table .status {
        padding: 4px 8px;
        font-size: 0.8rem;
        white-space: nowrap;
    }

    .fund-management-section h3 {
        font-size: 1.2rem;
        margin-bottom: 15px;
    }
}

/* Empty State Styling */
.fund-table tbody:empty::after {
    content: 'No records found';
    display: block;
    text-align: center;
    padding: 20px;
    color: #666;
    font-style: italic;
}

/* Loading State */
.fund-table.loading {
    position: relative;
    min-height: 200px;
}

.fund-table.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}

/* Table Header Styling */
.fund-table thead {
    background: #f8f9fa;
}

.fund-table th {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Alternating Row Colors */
.fund-table tbody tr:nth-child(even) {
    background-color: #fafafa;
}

/* Action Buttons in Table */
.fund-table .action-buttons {
    display: flex;
    gap: 8px;
}

.fund-table .button {
    padding: 6px 12px;
    font-size: 0.85rem;
}

@media (max-width: 768px) {
    .fund-table .action-buttons {
        flex-direction: column;
        gap: 4px;
    }

    .fund-table .button {
        width: 100%;
        text-align: center;
    }
}

/* Status Badge Styling */
.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-badge.success {
    background-color: #dcfce7;
    color: #166534;
}

.status-badge.danger {
    background-color: #fee2e2;
    color: #991b1b;
}

.status-badge.info {
    background-color: #dbeafe;
    color: #1e40af;
}

/* Section Headers */
.card-content h3 {
    color: #333;
    font-size: 1.1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
}

/* Row Spacing */
.row {
    margin-bottom: 2rem;
}

.row:last-child {
    margin-bottom: 0;
}

/* Amount Cell Styling */
.amount-cell {
    font-family: 'Roboto Mono', monospace;
    text-align: right;
    font-weight: 500;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .row {
        margin-bottom: 1.5rem;
    }
    
    .card-content h3 {
        font-size: 1rem;
        margin-bottom: 0.75rem;
    }
    
    .status-badge {
        padding: 3px 6px;
        font-size: 0.8rem;
    }
}

/* Add these styles to your existing CSS */
.status-badge.warning {
    background-color: #fff3e0;
    color: #ef6c00;
}

.data-table th,
.data-table td {
    white-space: nowrap;
}

.data-table td:first-child {
    min-width: 120px;
}

@media (max-width: 768px) {
    .data-table {
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .data-table th:first-child,
    .data-table td:first-child {
        position: sticky;
        left: 0;
        background: white;
        z-index: 1;
    }
    
    .data-table th:first-child {
        background: #f8f9fa;
    }
    
    .data-table tr:hover td:first-child {
        background: #f8f9fa;
    }
}
</style>


<?php include_once '../includes/footer.php'; ?>