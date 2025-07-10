<?php
session_start();
$page_title = "Record Payment";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if sale ID is provided
if(!isset($_GET['sale_id']) || empty($_GET['sale_id'])) {
    $_SESSION['error_message'] = "Invalid request: Sale ID is missing";
    header('Location: sales.php');
    exit;
}

$sale_id = $_GET['sale_id'];

try {
    // Get sale details
    $query = "SELECT s.*, c.name as customer_name, c.phone, c.email
              FROM sales s 
              JOIN customers c ON s.customer_id = c.id
              WHERE s.id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $sale_id);
    $stmt->execute();

    if($stmt->rowCount() === 0) {
        $_SESSION['error_message'] = "Sale not found";
        header('Location: sales.php');
        exit;
    }

    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get total payments already made
    $payments_query = "SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE sale_id = ?";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->bindParam(1, $sale_id);
    $payments_stmt->execute();
    $total_paid = $payments_stmt->fetch(PDO::FETCH_ASSOC)['total_paid'];

    // Calculate remaining balance
    $balance = $sale['net_amount'] - $total_paid;

    // Check if balance is already paid
    if($balance <= 0) {
        $_SESSION['error_message'] = "This invoice is already fully paid";
        header('Location: view-sale.php?id=' . $sale_id);
        exit;
    }

    // Get recent payments for this customer
    $recent_payments_query = "SELECT p.payment_method, p.amount, p.payment_date
                             FROM payments p
                             JOIN sales s ON p.sale_id = s.id
                             WHERE s.customer_id = ?
                             ORDER BY p.payment_date DESC
                             LIMIT 3";
    $recent_payments_stmt = $db->prepare($recent_payments_query);
    $recent_payments_stmt->bindParam(1, $sale['customer_id']);
    $recent_payments_stmt->execute();
    $recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: sales.php');
    exit;
}
?>

<div class="page-container">
    <div class="breadcrumb">
        <a href="sales.php"><i class="fas fa-file-invoice-dollar"></i> Sales</a> &raquo; 
        <a href="view-sale.php?id=<?php echo $sale_id; ?>">Invoice #<?php echo htmlspecialchars($sale['invoice_number']); ?></a> &raquo; 
        <span>Record Payment</span>
    </div>

    <?php if(isset($_SESSION['error_message'])): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo $_SESSION['error_message']; ?></span>
        <button class="close-alert">&times;</button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="payment-container">
        <div class="payment-header">
            <div class="header-content">
                <h1>Record Payment</h1>
                <p class="subtitle">Invoice #<?php echo htmlspecialchars($sale['invoice_number']); ?></p>
            </div>
            <div class="header-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
        </div>

        <div class="payment-content">
            <div class="payment-info">
                <div class="info-card customer-info">
                    <div class="card-header">
                        <h2><i class="fas fa-user"></i> Customer Information</h2>
                    </div>
                    <div class="card-body">
                        <div class="customer-details">
                            <div class="customer-name"><?php echo htmlspecialchars($sale['customer_name']); ?></div>
                            <?php if(!empty($sale['phone'])): ?>
                                <div class="customer-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($sale['phone']); ?></div>
                            <?php endif; ?>
                            <?php if(!empty($sale['email'])): ?>
                                <div class="customer-contact"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($sale['email']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="info-card payment-summary">
                    <div class="card-header">
                        <h2><i class="fas fa-file-invoice-dollar"></i> Invoice Summary</h2>
                    </div>
                    <div class="card-body">
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="label">Invoice Total</div>
                                <div class="value">$<?php echo number_format($sale['net_amount'], 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Amount Paid</div>
                                <div class="value">$<?php echo number_format($total_paid, 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Balance Due</div>
                                <div class="value balance-value">$<?php echo number_format($balance, 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Due Date</div>
                                <div class="value <?php echo (strtotime($sale['payment_due_date']) < time()) ? 'overdue' : ''; ?>">
                                    <?php echo date('F j, Y', strtotime($sale['payment_due_date'])); ?>
                                    <?php if(strtotime($sale['payment_due_date']) < time()): ?>
                                        <span class="status-badge status-overdue">Overdue</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="payment-progress">
                            <div class="progress-label">
                                <span>Payment Progress</span>
                                <span><?php echo round(($total_paid / $sale['net_amount']) * 100); ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo ($total_paid / $sale['net_amount']) * 100; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if(count($recent_payments) > 0): ?>
                <div class="info-card recent-payments">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Recent Payments</h2>
                    </div>
                    <div class="card-body">
                        <ul class="payment-history">
                            <?php foreach($recent_payments as $payment): ?>
                            <li>
                                <div class="payment-icon">
                                    <?php
                                    $icon = 'money-bill-wave';
                                    if($payment['payment_method'] == 'bank_transfer') $icon = 'university';
                                    elseif($payment['payment_method'] == 'check') $icon = 'money-check';
                                    elseif($payment['payment_method'] == 'credit_card') $icon = 'credit-card';
                                    ?>
                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                </div>
                                <div class="payment-details">
                                    <div class="payment-amount">$<?php echo number_format($payment['amount'], 2); ?></div>
                                    <div class="payment-meta">
                                        <span class="payment-date"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></span>
                                        <span class="payment-method">
                                            <span class="method-badge method-<?php echo strtolower(str_replace('_', '-', $payment['payment_method'])); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="payment-form-container">
                <div class="info-card payment-form-card">
                    <div class="card-header">
                        <h2><i class="fas fa-credit-card"></i> Payment Details</h2>
                    </div>
                    <div class="card-body">
                        <form id="paymentForm" action="../api/save-payment.php" method="post">
                            <input type="hidden" name="sale_id" value="<?php echo $sale_id; ?>">
                            
                            <div class="form-group">
                                <label for="amount">Payment Amount <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-dollar-sign"></i>
                                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" max="<?php echo $balance; ?>" value="<?php echo $balance; ?>" required>
                                </div>
                                <div class="error-message" id="amount-error"></div>
                                <div class="form-text">Maximum allowed: $<?php echo number_format($balance, 2); ?></div>
                                
                                <div class="quick-amount-buttons">
                                    <button type="button" class="quick-amount" data-amount="<?php echo $balance; ?>">
                                        <i class="fas fa-money-bill-wave"></i> Full Amount
                                    </button>
                                    <button type="button" class="quick-amount" data-amount="<?php echo round($balance / 2, 2); ?>">
                                        <i class="fas fa-cut"></i> Half
                                    </button>
                                    <button type="button" class="quick-amount" data-amount="1000">
                                        <i class="fas fa-dollar-sign"></i> $1000
                                    </button>
                                    <button type="button" class="quick-amount" data-amount="500">
                                        <i class="fas fa-dollar-sign"></i> $500
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="payment_date">Payment Date <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-calendar-alt"></i>
                                    <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="error-message" id="date-error"></div>
                            </div>
                            
                            <div class="form-group">
                                <label>Payment Method <span class="required">*</span></label>
                                <div class="method-selector">
                                    <div class="method-option">
                                        <input type="radio" id="method_cash" name="payment_method" value="cash" checked>
                                        <label for="method_cash" class="method-label">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <span>Cash</span>
                                        </label>
                                    </div>
                                    <div class="method-option">
                                        <input type="radio" id="method_bank_transfer" name="payment_method" value="bank_transfer">
                                        <label for="method_bank_transfer" class="method-label">
                                            <i class="fas fa-university"></i>
                                            <span>Bank Transfer</span>
                                        </label>
                                    </div>
                                    <div class="method-option">
                                        <input type="radio" id="method_check" name="payment_method" value="check">
                                        <label for="method_check" class="method-label">
                                            <i class="fas fa-money-check"></i>
                                            <span>Check</span>
                                        </label>
                                    </div>
                                    <div class="method-option">
                                        <input type="radio" id="method_credit_card" name="payment_method" value="credit_card">
                                        <label for="method_credit_card" class="method-label">
                                            <i class="fas fa-credit-card"></i>
                                            <span>Credit Card</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="error-message" id="method-error"></div>
                            </div>
                            
                            <div class="form-group" id="referenceNumberGroup" style="display: none;">
                                <label for="reference_number">Reference Number <span class="required">*</span></label>
                                <div class="input-with-icon">
                                    <i class="fas fa-hashtag"></i>
                                    <input type="text" id="reference_number" name="reference_number" placeholder="Check #, Transaction ID, etc.">
                                </div>
                                <div class="error-message" id="reference-error"></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea id="notes" name="notes" rows="3" placeholder="Additional payment details or comments"></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <a href="view-sale.php?id=<?php echo $sale_id; ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary" id="recordPaymentBtn">
                                    <i class="fas fa-check-circle"></i> Record Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
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
    --success: #34a853;
    --danger: #ea4335;
    --warning: #fbbc04;
    --info: #4285f4;
    --light: #f8f9fa;
    --dark: #202124;
    --border: #dadce0;
    --surface: #f5f5f5;
    
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
    
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;
    
    --font-xs: 0.75rem;
    --font-sm: 0.875rem;
    --font-md: 1rem;
    --font-lg: 1.125rem;
    --font-xl: 1.25rem;
    --font-xxl: 1.5rem;
    
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
}

/* Page Layout */
.page-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--spacing-md);
}

/* Breadcrumb */
.breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-lg);
    font-size: var(--font-sm);
    color: var(--secondary);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.breadcrumb a:hover {
    text-decoration: underline;
}

/* Alert */
.alert {
    display: flex;
    align-items: center;
    padding: var(--spacing-md);
    border-radius: var(--radius-md);
    margin-bottom: var(--spacing-lg);
    animation: fadeIn 0.3s ease;
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    border-left: 4px solid var(--danger);
    color: var(--danger);
}

.alert i {
    margin-right: var(--spacing-sm);
    font-size: var(--font-lg);
}

.close-alert {
    margin-left: auto;
    background: none;
    border: none;
    color: currentColor;
    font-size: var(--font-lg);
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.close-alert:hover {
    opacity: 1;
}

/* Payment Container */
.payment-container {
    background-color: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    margin-bottom: var(--spacing-xl);
}

/* Payment Header */
.payment-header {
    background-color: var(--primary);
    color: white;
    padding: var(--spacing-lg) var(--spacing-xl);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-content h1 {
    margin: 0;
    font-size: var(--font-xxl);
    font-weight: 600;
}

.subtitle {
    margin: var(--spacing-xs) 0 0;
    opacity: 0.9;
    font-size: var(--font-md);
}

.header-icon {
    width: 60px;
    height: 60px;
    background-color: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
}

/* Payment Content */
.payment-content {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: var(--spacing-xl);
    padding: var(--spacing-xl);
}

/* Info Cards */
.info-card {
    background-color: white;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: var(--spacing-lg);
    border: 1px solid var(--border);
}

.card-header {
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid var(--border);
    background-color: var(--light);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-lg);
    font-weight: 600;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.card-header h2 i {
    color: var(--primary);
}

.card-body {
    padding: var(--spacing-lg);
}

/* Customer Info */
.customer-details {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
}

.customer-name {
    font-size: var(--font-lg);
    font-weight: 600;
    color: var(--dark);
}

.customer-contact {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    color: var(--secondary);
}

/* Payment Summary */
.summary-grid {
    display: grid;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: var(--spacing-sm);
    border-bottom: 1px solid var(--border);
}

.summary-item:last-child {
    border-bottom: none;
}

.summary-item .label {
    font-weight: 500;
    color: var(--secondary);
}

.summary-item .value {
    font-weight: 600;
}

.balance-value {
    color: var(--primary);
    font-size: var(--font-lg);
}

.overdue {
    color: var(--danger);
}

.status-badge {
    display: inline-block;
    padding: 0.25em 0.6em;
    font-size: var(--font-xs);
    font-weight: 600;
    border-radius: 1em;
    text-transform: uppercase;
    margin-left: var(--spacing-sm);
}

.status-overdue {
    background-color: rgba(234, 67, 53, 0.1);
    color: var(--danger);
}

/* Payment Progress */
.payment-progress {
    margin-top: var(--spacing-lg);
}

.progress-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: var(--spacing-xs);
    font-size: var(--font-sm);
    color: var(--secondary);
}

.progress-bar {
    height: 8px;
    background-color: var(--surface);
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background-color: var(--success);
    border-radius: 4px;
    transition: width 0.5s ease;
}

/* Recent Payments */
.payment-history {
    list-style: none;
    padding: 0;
    margin: 0;
}

.payment-history li {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    padding: var(--spacing-md) 0;
    border-bottom: 1px solid var(--border);
}

.payment-history li:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.payment-icon {
    width: 40px;
    height: 40px;
    background-color: var(--primary-light);
    color: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--font-md);
}

.payment-details {
    flex: 1;
}

.payment-amount {
    font-weight: 600;
    font-size: var(--font-md);
    color: var(--dark);
}

.payment-meta {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
    margin-top: var(--spacing-xs);
    font-size: var(--font-sm);
    color: var(--secondary);
}

.method-badge {
    display: inline-block;
    padding: 0.25em 0.6em;
    font-size: var(--font-xs);
    font-weight: 600;
    border-radius: 1em;
    text-transform: uppercase;
}

.method-cash {
    background-color: rgba(52, 168, 83, 0.1);
    color: var(--success);
}

.method-bank-transfer {
    background-color: rgba(66, 133, 244, 0.1);
    color: var(--info);
}

.method-check {
    background-color: rgba(251, 188, 4, 0.1);
    color: var(--warning);
}

.method-credit-card {
    background-color: rgba(103, 58, 183, 0.1);
    color: #673ab7;
}

/* Form Styles */
.form-group {
    margin-bottom: var(--spacing-lg);
}

.form-group label {
    display: block;
    margin-bottom: var(--spacing-sm);
    font-weight: 500;
    color: var(--dark);
}

.input-with-icon {
    position: relative;
}

.input-with-icon input {
    padding-left: 2.5rem;
    width: 100%;
}

.input-with-icon i {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--secondary);
}

input[type="text"],
input[type="number"],
input[type="date"],
textarea {
    padding: 0.75rem;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    font-size: var(--font-md);
    width: 100%;
    transition: border-color 0.2s, box-shadow 0.2s;
}

input:focus,
textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
}

.required {
    color: var(--danger);
}

.error-message {
    display: none;
    color: var(--danger);
    font-size: var(--font-sm);
    margin-top: var(--spacing-xs);
}

.form-text {
    font-size: var(--font-sm);
    color: var(--secondary);
    margin-top: var(--spacing-xs);
}

.quick-amount-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-sm);
    margin-top: var(--spacing-sm);
}

.quick-amount {
    background-color: var(--light);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 0.5rem 0.75rem;
    font-size: var(--font-sm);
    color: var(--dark);
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.quick-amount:hover {
    background-color: var(--primary-light);
    border-color: var(--primary);
    color: var(--primary);
}

/* Method Selector */
.method-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--spacing-md);
}

.method-option {
    position: relative;
}

.method-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.method-label {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background-color: var(--light);
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--spacing-lg) var(--spacing-md);
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    height: 100%;
}

.method-label i {
    font-size: 1.5rem;
    margin-bottom: var(--spacing-sm);
    color: var(--secondary);
    transition: color 0.2s;
}

.method-option input[type="radio"]:checked + .method-label {
    border-color: var(--primary);
    background-color: var(--primary-light);
}

.method-option input[type="radio"]:checked + .method-label i {
    color: var(--primary);
}

.method-option input[type="radio"]:focus + .method-label {
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--spacing-md);
    margin-top: var(--spacing-xl);
}

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: 0.75rem 1.5rem;
    font-size: var(--font-md);
    font-weight: 500;
    border-radius: var(--radius-md);
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-primary {
    background-color: var(--primary);
    color: white;
}

.btn-primary:hover {
    background-color: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: var(--shadow-md);
}

.btn-secondary {
    background-color: var(--light);
    color: var(--dark);
    border: 1px solid var(--border);
}

.btn-secondary:hover {
    background-color: var(--surface);
    transform: translateY(-1px);
    box-shadow: var(--shadow-sm);
}

.btn-primary:active,
.btn-secondary:active {
    transform: translateY(0);
}

/* Invalid input styles */
.invalid-input {
    border-color: var(--danger) !important;
    background-color: rgba(234, 67, 53, 0.05);
}

/* Animation keyframes */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Success Animation Styles */
.success-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(255, 255, 255, 0.95);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.success-overlay.visible {
    opacity: 1;
}

.success-animation {
    text-align: center;
    transform: translateY(20px);
    transition: transform 0.5s ease;
}

.success-overlay.visible .success-animation {
    transform: translateY(0);
}

.checkmark {
    width: 100px;
    height: 100px;
    margin: 0 auto 20px;
    position: relative;
}

.checkmark svg {
    transform-origin: center;
    transform: scale(0);
}

.checkmark.animate svg {
    animation: scale-in 0.5s ease forwards, check-draw 0.8s ease-out 0.5s forwards;
}

.success-message {
    font-size: var(--font-xl);
    color: var(--success);
    font-weight: 600;
    opacity: 0;
    animation: fade-in 0.5s ease 1s forwards;
}

@keyframes scale-in {
    0% { transform: scale(0); }
    70% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

@keyframes check-draw {
    0% { stroke-dasharray: 0, 100; stroke-dashoffset: 0; }
    100% { stroke-dasharray: 100, 100; stroke-dashoffset: 0; }
}

@keyframes fade-in {
    0% { opacity: 0; }
    100% { opacity: 1; }
}

/* Toast Notification System */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 350px;
}

.toast {
    background-color: white;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-md);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    animation: toast-in 0.3s ease-out forwards;
    opacity: 0;
    transform: translateX(50px);
}

.toast-hiding {
    animation: toast-out 0.3s ease-in forwards;
}

.toast-icon {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.toast-content {
    flex: 1;
    font-size: 14px;
}

.toast-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    color: var(--secondary);
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

.toast-info {
    border-left: 4px solid var(--info);
}

.toast-info .toast-icon {
    background-color: rgba(66, 133, 244, 0.1);
    color: var(--info);
}

.toast-success {
    border-left: 4px solid var(--success);
}

.toast-success .toast-icon {
    background-color: rgba(52, 168, 83, 0.1);
    color: var(--success);
}

.toast-warning {
    border-left: 4px solid var(--warning);
}

.toast-warning .toast-icon {
    background-color: rgba(251, 188, 4, 0.1);
    color: var(--warning);
}

.toast-error {
    border-left: 4px solid var(--danger);
}

.toast-error .toast-icon {
    background-color: rgba(234, 67, 53, 0.1);
    color: var(--danger);
}

@keyframes toast-in {
    from { opacity: 0; transform: translateX(50px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes toast-out {
    from { opacity: 1; transform: translateX(0); }
    to { opacity: 0; transform: translateX(50px); }
}

/* Responsive Adjustments */
@media (max-width: 1024px) {
    .payment-content {
        grid-template-columns: 1fr;
    }
    
    .payment-info {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: var(--spacing-lg);
    }
    
    .recent-payments {
        grid-column: span 2;
    }
}

@media (max-width: 768px) {
    .payment-info {
        grid-template-columns: 1fr;
    }
    
    .recent-payments {
        grid-column: auto;
    }
    
    .method-selector {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .form-actions {
        flex-direction: column-reverse;
    }
    
    .form-actions .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .payment-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--spacing-md);
    }
    
    .header-icon {
        display: none;
    }
    
    .quick-amount-buttons {
        flex-direction: column;
        width: 100%;
    }
    
    .quick-amount {
        width: 100%;
        justify-content: center;
    }
    
    .toast-container {
        left: 20px;
        right: 20px;
        top: auto;
        bottom: 20px;
        max-width: none;
    }
}

/* Accessibility Improvements */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.001ms !important;
        transition-duration: 0.001ms !important;
    }
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Cache DOM elements
    const paymentForm = document.getElementById('paymentForm');
    const amountInput = document.getElementById('amount');
    const maxAmount = parseFloat(amountInput.getAttribute('max'));
    const paymentMethodRadios = document.querySelectorAll('input[name="payment_method"]');
    const referenceNumberGroup = document.getElementById('referenceNumberGroup');
    const referenceNumberInput = document.getElementById('reference_number');
    const recordPaymentBtn = document.getElementById('recordPaymentBtn');
    const closeAlertButtons = document.querySelectorAll('.close-alert');
    
    // ===== Event Listeners =====
    
    // Close alert buttons
    closeAlertButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });

    // Quick amount buttons
    const quickAmountButtons = document.querySelectorAll('.quick-amount');
    quickAmountButtons.forEach(button => {
        button.addEventListener('click', function() {
            const amount = parseFloat(this.getAttribute('data-amount'));
            if (!isNaN(amount) && amount <= maxAmount) {
                amountInput.value = amount.toFixed(2);
                // Remove any validation errors
                clearError(amountInput, document.getElementById('amount-error'));
                // Add visual feedback
                button.classList.add('active');
                setTimeout(() => {
                    button.classList.remove('active');
                }, 300);
            }
        });
    });
    
    // Show/hide reference number field based on payment method
    paymentMethodRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            updateReferenceFieldVisibility(this.value);
        });
    });
    
    // Trigger change event on page load to set initial state
    const checkedMethod = document.querySelector('input[name="payment_method"]:checked');
    if (checkedMethod) {
        updateReferenceFieldVisibility(checkedMethod.value);
    }
    
    // Input validation on change
    amountInput.addEventListener('input', function() {
        validateAmount(this.value);
    });
    
    // Form validation
    paymentForm.addEventListener('submit', function(event) {
        event.preventDefault();
        
        // Reset validation errors
        resetValidationErrors();
        
        // Validate all fields
        let isValid = true;
        
        // Validate amount
        isValid = validateAmount(amountInput.value) && isValid;
        
        // Validate payment date
        const paymentDateInput = document.getElementById('payment_date');
        if (!paymentDateInput.value) {
            showFieldError(paymentDateInput, document.getElementById('date-error'), 'Please select a payment date');
            isValid = false;
        }
        
        // Validate payment method
        const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
        if (!selectedMethod) {
            showFieldError(document.querySelector('.method-selector'), document.getElementById('method-error'), 'Please select a payment method');
            isValid = false;
        }
        
        // Validate reference number if required
        if ((selectedMethod && (selectedMethod.value === 'bank_transfer' || selectedMethod.value === 'check' || selectedMethod.value === 'credit_card')) && 
            !referenceNumberInput.value.trim()) {
            showFieldError(referenceNumberInput, document.getElementById('reference-error'), 'Reference number is required for this payment method');
            isValid = false;
        }
        
        if (isValid) {
            // Show loading state
            setButtonLoading(recordPaymentBtn, true);
            
            // Submit form via fetch API
            submitPaymentForm();
        } else {
            // Focus the first invalid field
            const firstInvalid = document.querySelector('.invalid-input');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }
    });
    
    // ===== Helper Functions =====
    
    /**
     * Updates the visibility of the reference number field based on payment method
     * @param {string} method - The selected payment method
     */
    function updateReferenceFieldVisibility(method) {
        // Show reference field for bank transfers, checks, and credit cards
        const requiresReference = ['bank_transfer', 'check', 'credit_card'].includes(method);
        
        if (requiresReference) {
            referenceNumberGroup.style.display = 'block';
            referenceNumberInput.setAttribute('required', 'required');
            
            // Set focus after a brief delay to allow for DOM update
            setTimeout(() => {
                referenceNumberInput.focus();
            }, 100);
        } else {
            referenceNumberGroup.style.display = 'none';
            referenceNumberInput.removeAttribute('required');
            // Clear any validation errors
            clearError(referenceNumberInput, document.getElementById('reference-error'));
        }
    }
    
    /**
     * Validates the payment amount
     * @param {string} value - The amount to validate
     * @returns {boolean} - Whether the amount is valid
     */
    function validateAmount(value) {
        const amount = parseFloat(value);
        const amountError = document.getElementById('amount-error');
        
        if (isNaN(amount) || amount <= 0) {
            showFieldError(amountInput, amountError, 'Please enter a valid payment amount');
            return false;
        } else if (amount > maxAmount) {
            showFieldError(amountInput, amountError, `Amount cannot exceed the balance due ($${maxAmount.toFixed(2)})`);
            return false;
        }
        
        clearError(amountInput, amountError);
        return true;
    }
    
    /**
     * Resets all validation errors
     */
    function resetValidationErrors() {
        // Hide error messages
        const errorMessages = document.querySelectorAll('.error-message');
        errorMessages.forEach(error => {
            error.style.display = 'none';
            error.textContent = '';
        });
        
        // Remove invalid classes
        const invalidInputs = document.querySelectorAll('.invalid-input');
        invalidInputs.forEach(input => {
            input.classList.remove('invalid-input');
        });
    }
    
    /**
     * Shows an error message for a field
     * @param {HTMLElement} inputElement - The input element
     * @param {HTMLElement} errorElement - The error message element
     * @param {string} message - The error message
     */
    function showFieldError(inputElement, errorElement, message) {
        inputElement.classList.add('invalid-input');
        
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.style.display = 'block';
            
            // Add shake animation for visual feedback
            errorElement.classList.add('shake');
            setTimeout(() => {
                errorElement.classList.remove('shake');
            }, 600);
        }
    }
    
    /**
     * Clears an error message for a field
     * @param {HTMLElement} inputElement - The input element
     * @param {HTMLElement} errorElement - The error message element
     */
    function clearError(inputElement, errorElement) {
        inputElement.classList.remove('invalid-input');
        
        if (errorElement) {
            errorElement.textContent = '';
            errorElement.style.display = 'none';
        }
    }
    
    /**
     * Sets a button's loading state
     * @param {HTMLElement} button - The button element
     * @param {boolean} isLoading - Whether the button is loading
     */
    function setButtonLoading(button, isLoading) {
        if (isLoading) {
            button.disabled = true;
            button.dataset.originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        } else {
            button.disabled = false;
            if (button.dataset.originalText) {
                button.innerHTML = button.dataset.originalText;
            }
        }
    }
    
    /**
     * Submits the payment form via fetch API
     */
    function submitPaymentForm() {
        const formData = new FormData(paymentForm);
        const amount = formData.get('amount');
        const invoiceNumber = '<?php echo htmlspecialchars($sale['invoice_number']); ?>';
        
        fetch(paymentForm.action, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // Check if the response is JSON
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                return response.json().then(data => {
                    if (!response.ok) throw data;
                    return data;
                });
            } else {
                // If not JSON, it's probably an error page
                return response.text().then(text => {
                    throw new Error('The server returned an invalid response. This could be due to a PHP error.');
                });
            }
        })
        .then(data => {
            if (data.success) {
                // Log activity
                logUserActivity(
                    'create', 
                    'payments', 
                    `Recorded payment of $${amount} for invoice #${invoiceNumber}`
                );
                
                // Show success animation
                showSuccessAnimation();
                
                // Redirect after animation
                setTimeout(() => {
                    window.location.href = `view-sale.php?id=<?php echo $sale_id; ?>&success=2`;
                }, 1500);
            } else {
                // Show error message
                showToast(data.message || 'An error occurred while processing the payment', 'error');
                setButtonLoading(recordPaymentBtn, false);
            }
        })
        .catch(error => {
            console.error('Error submitting payment:', error);
            
            // Show error message
            showToast(
                error.message || 'An unexpected error occurred. Please try again later.', 
                'error'
            );
            
            // Reset button state
            setButtonLoading(recordPaymentBtn, false);
        });
    }
    
    /**
     * Shows the success animation overlay
     */
    function showSuccessAnimation() {
        // Create overlay
        const overlay = document.createElement('div');
        overlay.className = 'success-overlay';
        overlay.setAttribute('role', 'alert');
        overlay.setAttribute('aria-live', 'assertive');
        
        // Create animation container
        const animationContainer = document.createElement('div');
        animationContainer.className = 'success-animation';
        
        // Create checkmark
        const checkmark = document.createElement('div');
        checkmark.className = 'checkmark';
        checkmark.innerHTML = '<svg width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="45" fill="none" stroke="#34a853" stroke-width="8" /><polyline points="30,50 45,65 70,35" fill="none" stroke="#34a853" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" /></svg>';
        
        // Create message
        const message = document.createElement('div');
        message.className = 'success-message';
        message.textContent = 'Payment Recorded Successfully!';
        
        // Assemble elements
        animationContainer.appendChild(checkmark);
        animationContainer.appendChild(message);
        overlay.appendChild(animationContainer);
        document.body.appendChild(overlay);
        
        // Apply animation classes after a small delay
        setTimeout(() => {
            overlay.classList.add('visible');
            checkmark.classList.add('animate');
        }, 100);
    }
    
    /**
     * Shows a toast notification
     * @param {string} message - The message to show
     * @param {string} type - The type of toast (info, success, warning, error)
     */
    function showToast(message, type = 'info') {
        // Check if toast container exists, if not create it
        let toastContainer = document.querySelector('.toast-container');
        
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }
        
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        // Set icon based on type
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></div>
            <div class="toast-content">${message}</div>
            <button class="toast-close" aria-label="Close notification"><i class="fas fa-times" aria-hidden="true"></i></button>
        `;
        
        // Add to container
        toastContainer.appendChild(toast);
        
        // Add event listener to close button
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.add('toast-hiding');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                    
                    // Remove container if empty
                    if (toastContainer.children.length === 0) {
                        toastContainer.remove();
                    }
                }
            }, 300);
        });
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.add('toast-hiding');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                        
                        // Remove container if empty
                        if (toastContainer.children.length === 0) {
                            toastContainer.remove();
                        }
                    }
                }, 300);
            }
        }, 5000);
    }
});

/**
 * Logs user activity to the server
 * @param {string} actionType - The type of action
 * @param {string} module - The module name
 * @param {string} description - Description of the activity
 */
function logUserActivity(actionType, module, description) {
    const userId = document.getElementById('current-user-id')?.value;
    
    if (!userId) return;
    
    fetch('../api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            action_type: actionType,
            module: module,
            description: description
        })
    })
    .catch(error => {
        console.error('Error logging activity:', error);
    });
}
</script>