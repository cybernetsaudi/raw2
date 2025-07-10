<?php
session_start();
$page_title = "Fund Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Ensure user is logged in and is an incharge
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Get fund summary
try {
    $summary_query = "SELECT 
                        COUNT(*) as total_funds,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_funds,
                        SUM(CASE WHEN status = 'depleted' THEN 1 ELSE 0 END) as depleted_funds,
                        SUM(balance) as total_balance,
                        SUM(amount) as total_allocated,
                        (SELECT COALESCE(SUM(amount), 0) FROM fund_usage WHERE used_by = ?) as total_used
                    FROM funds 
                    WHERE to_user_id = ?";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $fund_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in fund summary query: " . $e->getMessage());
    $fund_summary = [
        'total_funds' => 0,
        'active_funds' => 0,
        'depleted_funds' => 0,
        'total_balance' => 0,
        'total_allocated' => 0,
        'total_used' => 0
    ];
}

// Get active funds
try {
    $funds_query = "SELECT 
                        f.*,
                        u.full_name as from_user_name
                    FROM funds f
                    JOIN users u ON f.from_user_id = u.id
                    WHERE f.to_user_id = ?
                    ORDER BY f.transfer_date DESC";
    $funds_stmt = $db->prepare($funds_query);
    $funds_stmt->execute([$_SESSION['user_id']]);
} catch (PDOException $e) {
    error_log("Error in funds query: " . $e->getMessage());
    $funds_stmt = null;
}

// Get fund usage history
try {
    $usage_query = "SELECT 
                        fu.*,
                        f.amount as fund_amount,
                        f.balance as fund_balance,
                        u.full_name as used_by_name
                    FROM fund_usage fu
                    JOIN funds f ON fu.fund_id = f.id
                    JOIN users u ON fu.used_by = u.id
                    WHERE f.to_user_id = ?
                    ORDER BY fu.used_at DESC";
    $usage_stmt = $db->prepare($usage_query);
    $usage_stmt->execute([$_SESSION['user_id']]);
} catch (PDOException $e) {
    error_log("Error in fund usage query: " . $e->getMessage());
    $usage_stmt = null;
}
?>

<div class="page-header">
    <h1 class="page-title">Fund Management</h1>
</div>

<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<!-- Fund Summary Cards -->
<div class="fund-summary">
    <div class="summary-card">
        <div class="summary-icon">
            <i class="fas fa-wallet" aria-hidden="true"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value"><?php echo $fund_summary['total_funds']; ?></div>
            <div class="summary-label">Total Funds</div>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon">
            <i class="fas fa-coins" aria-hidden="true"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value"><?php echo $fund_summary['active_funds']; ?></div>
            <div class="summary-label">Active Funds</div>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon">
            <i class="fas fa-money-bill-wave" aria-hidden="true"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value">Rs.<?php echo number_format($fund_summary['total_balance'], 2); ?></div>
            <div class="summary-label">Total Balance</div>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon">
            <i class="fas fa-hand-holding-usd" aria-hidden="true"></i>
        </div>
        <div class="summary-content">
            <div class="summary-value">Rs.<?php echo number_format($fund_summary['total_allocated'], 2); ?></div>
            <div class="summary-label">Total Allocated</div>
        </div>
    </div>
</div>

<!-- Active Funds Table -->
<div class="dashboard-card">
    <div class="card-header">
        <h2>Active Funds</h2>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table" aria-label="Active Funds">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">From</th>
                        <th scope="col">Amount</th>
                        <th scope="col">Balance</th>
                        <th scope="col">Status</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($funds_stmt && $funds_stmt->rowCount() > 0): ?>
                        <?php while($fund = $funds_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td data-label="Date"><?php echo date('M j, Y', strtotime($fund['transfer_date'])); ?></td>
                            <td data-label="From"><?php echo htmlspecialchars($fund['from_user_name']); ?></td>
                            <td data-label="Amount" class="amount-cell">Rs.<?php echo number_format($fund['amount'], 2); ?></td>
                            <td data-label="Balance" class="amount-cell">Rs.<?php echo number_format($fund['balance'], 2); ?></td>
                            <td data-label="Status">
                                <span class="status-badge status-<?php echo $fund['status']; ?>">
                                    <?php echo ucfirst($fund['status']); ?>
                                </span>
                            </td>
                            <td data-label="Actions">
                                <button class="button primary small use-fund-btn" 
                                        data-fund-id="<?php echo $fund['id']; ?>" 
                                        data-balance="<?php echo $fund['balance']; ?>"
                                        <?php echo $fund['status'] !== 'active' ? 'disabled' : ''; ?>>
                                    <i class="fas fa-hand-holding-usd" aria-hidden="true"></i> Use Funds
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="no-data">No funds available</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Fund Usage History -->
<div class="dashboard-card">
    <div class="card-header">
        <h2>Fund Usage History</h2>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table" aria-label="Fund Usage History">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Type</th>
                        <th scope="col">Amount</th>
                        <th scope="col">Used By</th>
                        <th scope="col">Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($usage_stmt && $usage_stmt->rowCount() > 0): ?>
                        <?php while($usage = $usage_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr>
                            <td data-label="Date"><?php echo date('M j, Y', strtotime($usage['used_at'])); ?></td>
                            <td data-label="Type"><span class="usage-type usage-<?php echo $usage['type']; ?>"><?php echo ucfirst($usage['type']); ?></span></td>
                            <td data-label="Amount" class="amount-cell">Rs.<?php echo number_format($usage['amount'], 2); ?></td>
                            <td data-label="Used By"><?php echo htmlspecialchars($usage['used_by_name']); ?></td>
                            <td data-label="Reference"><?php echo $usage['reference_id']; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="no-data">No fund usage history found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Use Funds Modal -->
<div id="useFundModal" class="modal" role="dialog" aria-labelledby="useFundModalTitle" aria-modal="true" tabindex="-1">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="useFundModalTitle">Use Funds</h2>
            <button type="button" class="close-modal" aria-label="Close modal">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="useFundForm">
                <input type="hidden" id="fund_id" name="fund_id">
                
                <div class="form-group">
                    <label for="amount" class="required">Amount:</label>
                    <div class="input-with-icon">
                        <span class="input-icon">Rs.</span>
                        <input type="number" id="amount" name="amount" step="0.01" min="0" required aria-required="true">
                    </div>
                    <div class="form-hint">
                        Available balance: <span id="available_balance" class="available-balance">0</span>
                    </div>
                    <div class="error-message" id="amount-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="type" class="required">Usage Type:</label>
                    <select id="type" name="type" required aria-required="true">
                        <option value="">Select Type</option>
                        <option value="purchase">Purchase</option>
                        <option value="expense">Expense</option>
                        <option value="other">Other</option>
                    </select>
                    <div class="error-message" id="type-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="reference_id" class="required">Reference ID:</label>
                    <input type="text" id="reference_id" name="reference_id" required aria-required="true">
                    <div class="form-hint">Enter invoice number, transaction ID, or other reference</div>
                    <div class="error-message" id="reference_id-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="notes">Notes:</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Add any additional details about this usage"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="button secondary" id="cancelUseFund">Cancel</button>
                    <button type="submit" class="button primary" id="recordUsageBtn">
                        <i class="fas fa-save" aria-hidden="true"></i> Record Usage
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading-indicator" style="display: none;">
    <div class="spinner"></div>
    <p>Processing your request...</p>
</div>

<style>
    /* Funds Management Page Styles */
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

/* Page Header */
.page-header {
  margin-bottom: 1.5rem;
}

.page-title {
  margin: 0;
  font-size: 1.75rem;
  color: var(--text-primary);
}

/* Fund Summary */
.fund-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 1.5rem;
}

.summary-card {
  background-color: var(--surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  padding: 1.5rem;
  display: flex;
  align-items: center;
  gap: 1.25rem;
  transition: var(--transition);
}

.summary-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-md);
}

.summary-icon {
  width: 50px;
  height: 50px;
  border-radius: var(--radius-md);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  color: white;
}

.summary-card:nth-child(1) .summary-icon {
  background-color: var(--primary);
}

.summary-card:nth-child(2) .summary-icon {
  background-color: var(--success);
}

.summary-card:nth-child(3) .summary-icon {
  background-color: var(--warning);
}

.summary-card:nth-child(4) .summary-icon {
  background-color: var(--danger);
}

.summary-content {
  flex: 1;
}

.summary-value {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 0.25rem;
}

.summary-label {
  font-size: 0.9rem;
  color: var(--text-secondary);
}

/* Card Styles */
.dashboard-card {
  background-color: var(--surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  margin-bottom: 1.5rem;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem;
  background-color: var(--primary-light);
  border-bottom: 1px solid var(--border);
}

.card-header h2 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--primary);
}

.card-content {
  padding: 1.5rem;
}

/* Table Styles */
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
  padding: 0.75rem 1rem;
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

.no-data {
  text-align: center;
  padding: 2rem;
  color: var(--text-secondary);
  font-style: italic;
  background-color: var(--background);
}

/* Status & Usage Type Badges */
.status-badge,
.usage-type {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 50px;
  font-size: 0.8rem;
  font-weight: 500;
  text-align: center;
  white-space: nowrap;
}

.status-active {
  background-color: #d1e7dd;
  color: #0f5132;
}

.status-depleted {
  background-color: #f8d7da;
  color: #842029;
}

.status-returned {
  background-color: #cfe2ff;
  color: #084298;
}

.usage-purchase {
  background-color: #e2d9f3;
  color: #3c2a80;
}

.usage-expense {
  background-color: #ffe5d0;
  color: #883c00;
}

.usage-other {
  background-color: #f5f5f5;
  color: #424242;
}

.usage-manufacturing_cost {
  background-color: #d1e7dd;
  color: #0f5132;
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

.button:disabled {
  opacity: 0.65;
  cursor: not-allowed;
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
  padding: 2rem 1rem;
}

.modal-content {
  background-color: var(--surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-md);
  margin: 0 auto;
  max-width: 500px;
  animation: modalFadeIn 0.3s ease-out;
  position: relative;
  width: 100%;
}

.modal-content {
  background-color: var(--surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-md);
  margin: 0 auto;
  max-width: 500px;
  animation: modalFadeIn 0.3s ease-out;
  position: relative;
  width: 100%;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1.25rem 1.5rem;
  background-color: var(--primary-light);
  border-bottom: 1px solid var(--border);
}

.modal-header h2 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--primary);
}

.close-modal {
  background: none;
  border: none;
  font-size: 1.25rem;
  color: var(--text-secondary);
  cursor: pointer;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: var(--transition);
}

.close-modal:hover, .close-modal:focus {
  background-color: rgba(0, 0, 0, 0.1);
  color: var(--text-primary);
}

.modal-body {
  padding: 1.5rem;
}

/* Form Styles */
.form-group {
  margin-bottom: 1.25rem;
}

label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: var(--text-primary);
}

label.required::after {
  content: "*";
  color: var(--danger);
  margin-left: 0.25rem;
}

input[type="text"],
input[type="number"],
select,
textarea {
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 1rem;
  transition: var(--transition);
}

input[type="text"]:focus,
input[type="number"]:focus,
select:focus,
textarea:focus {
  border-color: var(--primary);
  outline: none;
  box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.25);
}

.form-hint {
  font-size: 0.8rem;
  color: var(--text-secondary);
  margin-top: 0.25rem;
}

.error-message {
  color: var(--danger);
  font-size: 0.85rem;
  margin-top: 0.25rem;
  min-height: 1.25rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
  margin-top: 1.5rem;
}

/* Input with icon */
.input-with-icon {
  position: relative;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: 0.75rem;
  color: var(--text-secondary);
  pointer-events: none;
}

.input-with-icon input {
  padding-left: 2.5rem;
}

.available-balance {
  font-weight: 600;
  color: var(--primary);
}

/* Invalid input styles */
input.invalid,
select.invalid,
textarea.invalid {
  border-color: var(--danger);
}

input.invalid:focus,
select.invalid:focus,
textarea.invalid:focus {
  box-shadow: 0 0 0 3px rgba(230, 57, 70, 0.25);
}

/* Loading indicator */
.loading-indicator {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.5);
  z-index: 2000;
  color: white;
}

.spinner {
  width: 50px;
  height: 50px;
  border: 5px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: white;
  animation: spin 1s linear infinite;
  margin-bottom: 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Toast notifications */
.toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 1100;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 350px;
}

.toast {
  background-color: var(--surface);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  padding: 1rem;
  animation: toastFadeIn 0.3s ease-out;
  border-left: 4px solid var(--primary);
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.toast.success {
  border-left-color: var(--success);
}

.toast.warning {
  border-left-color: var(--warning);
}

.toast.error {
  border-left-color: var(--danger);
}

.toast-icon {
  font-size: 1.25rem;
  margin-top: 0.125rem;
}

.toast-content {
  flex: 1;
}

.toast-title {
  font-weight: 600;
  margin-bottom: 0.25rem;
}

.toast-message {
  color: var(--text-secondary);
  font-size: 0.9rem;
}

.toast-close {
  background: none;
  border: none;
  color: var(--text-secondary);
  cursor: pointer;
  font-size: 1rem;
  padding: 0.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.toast.success .toast-icon {
  color: var(--success);
}

.toast.warning .toast-icon {
  color: var(--warning);
}

.toast.error .toast-icon {
  color: var(--danger);
}

@keyframes toastFadeIn {
  from { opacity: 0; transform: translateX(20px); }
  to { opacity: 1; transform: translateX(0); }
}

@keyframes modalFadeIn {
  from { opacity: 0; transform: translateY(-20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Responsive Adjustments */
@media (max-width: 768px) {
  .fund-summary {
    grid-template-columns: 1fr;
  }
  
  .form-actions {
    flex-direction: column-reverse;
  }
  
  .form-actions button {
    width: 100%;
  }
  
  .data-table {
    display: block;
  }
  
  .data-table thead {
    display: none;
  }
  
  .data-table tbody {
    display: block;
  }
  
  .data-table tr {
    display: block;
    margin-bottom: 1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
  }
  
  .data-table td {
    display: flex;
    justify-content: space-between;
    padding: 0.75rem;
    text-align: right;
    border-bottom: 1px solid var(--border);
  }
  
  .data-table td:last-child {
    border-bottom: none;
  }
  
  .data-table td::before {
    content: attr(data-label);
    font-weight: 600;
    text-align: left;
  }
  
  .data-table td.amount-cell {
    text-align: right;
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
input:focus,
select:focus,
textarea:focus,
[tabindex]:focus {
  outline: 3px solid rgba(67, 97, 238, 0.5);
  outline-offset: 2px;
}
    
</style>
<script>
    
    /**
 * Funds Management JavaScript
 * Handles fund usage recording and interactive features
 */
document.addEventListener('DOMContentLoaded', function() {
  // DOM Elements
  const modal = document.getElementById('useFundModal');
  const useFundButtons = document.querySelectorAll('.use-fund-btn');
  const closeBtn = document.querySelector('.close-modal');
  const cancelBtn = document.getElementById('cancelUseFund');
  const form = document.getElementById('useFundForm');
  const loadingIndicator = document.getElementById('loadingIndicator');
  const toastContainer = document.getElementById('toastContainer');
  
  // Initialize event listeners
  initEventListeners();
  
  /**
   * Initialize all event listeners
   */
  function initEventListeners() {
    // Use fund buttons
    useFundButtons.forEach(button => {
      button.addEventListener('click', function() {
        const fundId = this.getAttribute('data-fund-id');
        const balance = this.getAttribute('data-balance');
        openUseFundModal(fundId, balance);
      });
    });
    
    // Close modal buttons
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    
    // Close modal on outside click
    window.addEventListener('click', function(event) {
      if (event.target === modal) closeModal();
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && modal.style.display === 'block') {
        closeModal();
      }
    });
    
    // Form submission
    if (form) {
      form.addEventListener('submit', handleFormSubmit);
    }
    
    // Form input validation
    initFormValidation();
  }
  
  /**
   * Initialize form validation
   */
  function initFormValidation() {
    if (!form) return;
    
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
      field.addEventListener('input', function() {
        validateField(this);
      });
      
      field.addEventListener('blur', function() {
        validateField(this);
      });
    });
    
    // Special validation for amount field
    const amountField = document.getElementById('amount');
    if (amountField) {
      amountField.addEventListener('input', function() {
        validateAmountField(this);
      });
    }
  }
  
  /**
   * Validate a single form field
   * @param {HTMLElement} field - The field to validate
   * @returns {boolean} - Whether the field is valid
   */
  function validateField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = `${field.labels[0].textContent.replace(':', '').replace('*', '')} is required`;
      }
      return false;
    } else {
      field.classList.remove('invalid');
      if (errorElement) {
        errorElement.textContent = '';
      }
      return true;
    }
  }
  
  /**
   * Validate amount field with special rules
   * @param {HTMLElement} field - The amount field
   * @returns {boolean} - Whether the field is valid
   */
  function validateAmountField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    const availableBalance = parseFloat(document.getElementById('available_balance').textContent.replace(/[^\d.-]/g, ''));
    const amount = parseFloat(field.value);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Amount is required';
      }
      return false;
    } else if (isNaN(amount) || amount <= 0) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Amount must be greater than zero';
      }
      return false;
    } else if (amount > availableBalance) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = `Amount exceeds available balance (Rs.${availableBalance.toFixed(2)})`;
      }
      return false;
    } else {
      field.classList.remove('invalid');
      if (errorElement) {
        errorElement.textContent = '';
      }
      return true;
    }
  }
  
  /**
   * Validate the entire form
   * @returns {boolean} - Whether the form is valid
   */
  function validateForm() {
    let isValid = true;
    
    // Validate required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (field.id === 'amount') {
        if (!validateAmountField(field)) {
          isValid = false;
        }
      } else if (!validateField(field)) {
        isValid = false;
      }
    });
    
    return isValid;
  }
  
  /**
   * Open the use fund modal
   * @param {string} fundId - The ID of the fund to use
   * @param {string} balance - The available balance
   */
  function openUseFundModal(fundId, balance) {
    // Set modal data
    document.getElementById('fund_id').value = fundId;
    document.getElementById('available_balance').textContent = `Rs.${parseFloat(balance).toFixed(2)}`;
    
    // Clear form and validation errors
    form.reset();
    clearValidationErrors();
    
    // Show modal
    modal.style.display = 'block';
    
    // Focus first field
    setTimeout(() => {
      document.getElementById('amount').focus();
    }, 100);
  }
  
  /**
   * Close the modal
   */
  function closeModal() {
    modal.style.display = 'none';
    
    // Reset form and clear validation errors
    form.reset();
    clearValidationErrors();
  }
  
  /**
   * Clear all validation errors
   */
  function clearValidationErrors() {
    form.querySelectorAll('.invalid').forEach(field => {
      field.classList.remove('invalid');
    });
    
    form.querySelectorAll('.error-message').forEach(error => {
      error.textContent = '';
    });
  }
  
  /**
   * Handle form submission
   * @param {Event} event - The form submission event
   */
  function handleFormSubmit(event) {
    event.preventDefault();
    
    // Validate form
    if (!validateForm()) {
      return;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('recordUsageBtn');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Processing...';
    
    // Show loading indicator
    showLoading(true);
    
    // Get form data
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    // Send AJAX request
    fetch('../api/record-fund-usage.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      return response.json();
    })
    .then(result => {
      // Hide loading indicator
      showLoading(false);
      
      if (result.success) {
        // Show success message
        showToast('Success', result.message || 'Fund usage recorded successfully', 'success');
        
        // Close modal
        closeModal();
        
        // Reload page after a short delay
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        // Show error message
        showToast('Error', result.message || 'Failed to record fund usage', 'error');
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
      }
    })
    .catch(error => {
      // Hide loading indicator
      showLoading(false);
      
      console.error('Error recording fund usage:', error);
      showToast('Error', 'An unexpected error occurred. Please try again.', 'error');
      
      // Reset button state
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalBtnText;
    });
  }
  
  /**
   * Show or hide the loading indicator
   * @param {boolean} show - Whether to show the loading indicator
   */
  function showLoading(show) {
    if (loadingIndicator) {
      loadingIndicator.style.display = show ? 'flex' : 'none';
    }
  }
  
  /**
   * Display a toast notification
   * @param {string} title - The toast title
   * @param {string} message - The toast message
   * @param {string} type - The toast type (success, warning, error)
   */
  function showToast(title, message, type = 'info') {
    if (!toastContainer) return;
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    
    // Set icon based on type
    let icon = 'info-circle';
    if (type === 'success') icon = 'check-circle';
    if (type === 'warning') icon = 'exclamation-triangle';
    if (type === 'error') icon = 'exclamation-circle';
    
    toast.innerHTML = `
      <div class="toast-icon">
        <i class="fas fa-${icon}" aria-hidden="true"></i>
      </div>
      <div class="toast-content">
        <div class="toast-title">${title}</div>
        <div class="toast-message">${message}</div>
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
    
    // Auto remove after 5 seconds
    setTimeout(() => {
      removeToast(toast);
    }, 5000);
  }
  
  /**
   * Remove a toast with animation
   * @param {HTMLElement} toast - The toast element to remove
   */
  function removeToast(toast) {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(20px)';
    toast.style.transition = 'opacity 0.3s, transform 0.3s';
    
    setTimeout(() => {
      toast.remove();
    }, 300);
  }
});
</script>

<?php include_once '../includes/footer.php'; ?>