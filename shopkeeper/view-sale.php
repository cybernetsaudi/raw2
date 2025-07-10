<?php
session_start();
$page_title = "View Sale";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if sale ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid request: Sale ID is missing";
    header('Location: sales.php');
    exit;
}

$sale_id = $_GET['id'];

try {
    // Get sale details
    $query = "SELECT s.*, c.name as customer_name, c.contact_person, c.phone, c.email, c.address,
              u.username as created_by_user
              FROM sales s 
              JOIN customers c ON s.customer_id = c.id
              JOIN users u ON s.created_by = u.id
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

    // Get sale items
    $items_query = "SELECT si.*, p.name as product_name, p.sku
                    FROM sale_items si
                    JOIN products p ON si.product_id = p.id
                    WHERE si.sale_id = ?";
    $items_stmt = $db->prepare($items_query);
    $items_stmt->bindParam(1, $sale_id);
    $items_stmt->execute();
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get payments
    $payments_query = "SELECT p.*, u.username as recorded_by_user
                      FROM payments p
                      JOIN users u ON p.recorded_by = u.id
                      WHERE p.sale_id = ?
                      ORDER BY p.payment_date DESC";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->bindParam(1, $sale_id);
    $payments_stmt->execute();
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate total paid and balance
    $total_paid = array_reduce($payments, function($carry, $payment) {
        return $carry + $payment['amount'];
    }, 0);
    
    $balance = $sale['net_amount'] - $total_paid;

    // Determine payment status class
    $status_class = 'status-' . $sale['payment_status'];

    // Check if due date is past
    $today = new DateTime();
    $due_date = new DateTime($sale['payment_due_date']);
    $is_overdue = ($sale['payment_status'] !== 'paid' && $today > $due_date);
    
    // Calculate days overdue or remaining
    $days_diff = $today->diff($due_date)->days;
    $days_text = '';
    
    if ($is_overdue) {
        $days_text = $days_diff . ' day' . ($days_diff != 1 ? 's' : '') . ' overdue';
    } else if ($sale['payment_status'] !== 'paid') {
        $days_text = $days_diff . ' day' . ($days_diff != 1 ? 's' : '') . ' remaining';
    }
} catch(PDOException $e) {
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: sales.php');
    exit;
}
?>

<div class="breadcrumb">
    <a href="sales.php">Sales</a> &raquo; View Sale
</div>

<?php if(isset($_GET['success'])): ?>
<div class="alert alert-success">
    <?php if($_GET['success'] == 1): ?>
    <i class="fas fa-check-circle"></i>
    <span>Sale has been created successfully.</span>
    <?php elseif($_GET['success'] == 2): ?>
    <i class="fas fa-check-circle"></i>
    <span>Payment has been recorded successfully.</span>
    <?php endif; ?>
    <button class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if(isset($_SESSION['error_message'])): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $_SESSION['error_message']; ?></span>
    <button class="close-alert">&times;</button>
</div>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<div class="invoice-container">
    <div class="invoice-actions-top">
        <?php if($balance > 0): ?>
        <a href="add-payment.php?sale_id=<?php echo $sale_id; ?>" class="button primary">
            <i class="fas fa-money-bill"></i> Record Payment
        </a>
        <?php endif; ?>
        <button id="printInvoiceBtn" class="button secondary">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <div class="dropdown">
            <button class="button secondary dropdown-toggle">
                <i class="fas fa-ellipsis-v"></i> More Actions
            </button>
            <div class="dropdown-menu">
                <a href="#" id="emailInvoiceBtn" class="dropdown-item">
                    <i class="fas fa-envelope"></i> Email Invoice
                </a>
                <a href="#" id="downloadPdfBtn" class="dropdown-item">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </a>
                <div class="dropdown-divider"></div>
                <a href="#" id="shareInvoiceBtn" class="dropdown-item">
                    <i class="fas fa-share-alt"></i> Share
                </a>
                <!-- Add this to the dropdown menu in view-sale.php -->
<a href="#" id="downloadExcelBtn" class="dropdown-item">
    <i class="fas fa-file-excel"></i> Download as Excel
</a>
            </div>
        </div>
    </div>
    
    <div class="invoice-header">
        <div class="company-info">
            <h2>Garment Manufacturing System</h2>
            <p>123 Textile Street, Fashion District</p>
            <p>Phone: +1 234 567 8900</p>
            <p>Email: sales@garmentmfg.com</p>
        </div>
        <div class="invoice-info">
            <h1>INVOICE</h1>
            <table>
                <tr>
                    <th>Invoice Number:</th>
                    <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                </tr>
                <tr>
                    <th>Date:</th>
                    <td><?php echo date('F j, Y', strtotime($sale['sale_date'])); ?></td>
                </tr>
                <tr>
                    <th>Payment Due:</th>
                    <td class="<?php echo $is_overdue ? 'overdue-date' : ''; ?>">
                        <?php echo date('F j, Y', strtotime($sale['payment_due_date'])); ?>
                        <?php if (!empty($days_text)): ?>
                        <span class="days-indicator <?php echo $is_overdue ? 'overdue' : 'upcoming'; ?>">
                            (<?php echo $days_text; ?>)
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Status:</th>
                    <td>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo ucfirst($sale['payment_status']); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
    <div class="invoice-addresses">
        <div class="billing-address">
            <h3>Bill To:</h3>
            <p class="customer-name"><?php echo htmlspecialchars($sale['customer_name']); ?></p>
            <?php if (!empty($sale['contact_person'])): ?>
            <p><?php echo htmlspecialchars($sale['contact_person']); ?></p>
            <?php endif; ?>
            <?php if (!empty($sale['address'])): ?>
            <p><?php echo nl2br(htmlspecialchars($sale['address'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($sale['phone'])): ?>
            <p>Phone: <?php echo htmlspecialchars($sale['phone']); ?></p>
            <?php endif; ?>
            <?php if (!empty($sale['email'])): ?>
            <p>Email: <?php echo htmlspecialchars($sale['email']); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="payment-summary">
            <h3>Payment Summary</h3>
            <div class="payment-progress">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $sale['net_amount'] > 0 ? ($total_paid / $sale['net_amount'] * 100) : 0; ?>%"></div>
                </div>
                <div class="progress-labels">
                    <span>0%</span>
                    <span>50%</span>
                    <span>100%</span>
                </div>
            </div>
            <div class="payment-stats">
                <div class="payment-stat">
                    <span class="stat-label">Total Paid:</span>
                    <span class="stat-value paid-amount">$<?php echo number_format($total_paid, 2); ?></span>
                </div>
                <div class="payment-stat">
                    <span class="stat-label">Balance Due:</span>
                    <span class="stat-value balance-amount <?php echo $balance > 0 ? 'outstanding' : ''; ?>">
                        $<?php echo number_format($balance, 2); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="invoice-items">
        <h3>Invoice Items</h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['sku']); ?></td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td>$<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td>$<?php echo number_format($item['total_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="invoice-summary">
        <div class="summary-table">
            <table>
                <tr>
                    <th>Subtotal:</th>
                    <td>$<?php echo number_format($sale['total_amount'], 2); ?></td>
                </tr>
                <?php if($sale['discount_amount'] > 0): ?>
                <tr>
                    <th>Discount:</th>
                    <td>-$<?php echo number_format($sale['discount_amount'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if($sale['tax_amount'] > 0): ?>
                <tr>
                    <th>Tax:</th>
                    <td>$<?php echo number_format($sale['tax_amount'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if($sale['shipping_cost'] > 0): ?>
                <tr>
                    <th>Shipping:</th>
                    <td>$<?php echo number_format($sale['shipping_cost'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <th>Total:</th>
                    <td>$<?php echo number_format($sale['net_amount'], 2); ?></td>
                </tr>
                <tr>
                    <th>Amount Paid:</th>
                    <td>$<?php echo number_format($total_paid, 2); ?></td>
                </tr>
                <tr class="balance-row <?php echo $balance > 0 ? 'outstanding-balance' : ''; ?>">
                    <th>Balance Due:</th>
                    <td>$<?php echo number_format($balance, 2); ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <?php if(!empty($sale['notes'])): ?>
    <div class="invoice-notes">
        <h3>Notes:</h3>
        <p><?php echo nl2br(htmlspecialchars($sale['notes'])); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="invoice-payments">
        <h3>Payment History</h3>
        <?php if(empty($payments)): ?>
        <div class="empty-state small">
            <div class="empty-state-icon">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <p>No payments recorded yet.</p>
            <?php if($balance > 0): ?>
            <a href="add-payment.php?sale_id=<?php echo $sale_id; ?>" class="button small">Record First Payment</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                                   <tr>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Recorded By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($payments as $payment): ?>
                <tr>
                    <td data-label="Date"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                    <td data-label="Amount" class="amount-cell">$<?php echo number_format($payment['amount'], 2); ?></td>
                    <td data-label="Method">
                        <span class="method-badge method-<?php echo strtolower(str_replace('_', '-', $payment['payment_method'])); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                        </span>
                    </td>
                    <td data-label="Reference"><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                    <td data-label="Recorded By"><?php echo htmlspecialchars($payment['recorded_by_user']); ?></td>
                    <td>
                        <button class="button small view-payment" 
                                data-id="<?php echo $payment['id']; ?>"
                                data-amount="<?php echo number_format($payment['amount'], 2); ?>"
                                data-method="<?php echo htmlspecialchars($payment['payment_method']); ?>"
                                data-reference="<?php echo htmlspecialchars($payment['reference_number'] ?: '-'); ?>"
                                data-date="<?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>"
                                data-notes="<?php echo htmlspecialchars($payment['notes'] ?: ''); ?>"
                                aria-label="View payment details">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="invoice-footer">
        <p>Created by: <?php echo htmlspecialchars($sale['created_by_user']); ?> on <?php echo date('F j, Y', strtotime($sale['created_at'])); ?></p>
        <p class="footer-note">Thank you for your business!</p>
    </div>
    
    <div class="invoice-actions">
        <?php if($balance > 0): ?>
        <a href="add-payment.php?sale_id=<?php echo $sale_id; ?>" class="button primary">
            <i class="fas fa-money-bill"></i> Record Payment
        </a>
        <?php endif; ?>
        <button id="printInvoiceBtn2" class="button secondary">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <a href="sales.php" class="button tertiary">
            <i class="fas fa-arrow-left"></i> Back to Sales
        </a>
    </div>
</div>

<!-- Payment View Modal -->
<div id="paymentModal" class="modal" aria-labelledby="modalTitle" aria-modal="true" role="dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Payment Details</h2>
            <button class="close-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="payment-details">
                <div class="payment-amount-display">
                    <span id="modal-amount">$0.00</span>
                </div>
                <div class="payment-info-grid">
                    <div class="info-item">
                        <div class="info-label">Payment Method</div>
                        <div id="modal-method" class="info-value"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Payment Date</div>
                        <div id="modal-date" class="info-value"></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Reference Number</div>
                        <div id="modal-reference" class="info-value"></div>
                    </div>
                </div>
                <div class="payment-notes">
                    <h3>Notes</h3>
                    <div id="modal-notes" class="notes-content"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button id="printReceiptBtn" class="button">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button id="closePaymentModal" class="button secondary">Close</button>
        </div>
    </div>
</div>

<!-- Email Invoice Modal -->
<div id="emailModal" class="modal" aria-labelledby="emailModalTitle" aria-modal="true" role="dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="emailModalTitle">Email Invoice</h2>
            <button class="close-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="emailForm">
                <div class="form-group">
                    <label for="recipient_email">Recipient Email:</label>
                    <input type="email" id="recipient_email" name="recipient_email" 
                           value="<?php echo htmlspecialchars($sale['email'] ?: ''); ?>" required>
                    <div class="error-message" id="email-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="email_subject">Subject:</label>
                    <input type="text" id="email_subject" name="email_subject" 
                           value="Invoice #<?php echo htmlspecialchars($sale['invoice_number']); ?> from Garment Manufacturing System" required>
                </div>
                
                <div class="form-group">
                    <label for="email_message">Message:</label>
                    <textarea id="email_message" name="email_message" rows="4" required>Dear <?php echo htmlspecialchars($sale['customer_name']); ?>,

Please find attached your invoice #<?php echo htmlspecialchars($sale['invoice_number']); ?> for your recent purchase.

Thank you for your business!

Garment Manufacturing System</textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="include_pdf" name="include_pdf" checked>
                    <label for="include_pdf">Include PDF attachment</label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button id="sendEmailBtn" class="button primary">
                <i class="fas fa-paper-plane"></i> Send Email
            </button>
            <button class="button secondary close-modal-btn">Cancel</button>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">
<input type="hidden" id="invoice-number" value="<?php echo htmlspecialchars($sale['invoice_number']); ?>">
<input type="hidden" id="customer-name" value="<?php echo htmlspecialchars($sale['customer_name']); ?>">

<style>
/* Alert Styles */
.alert {
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: var(--border-radius-md, 8px);
    display: flex;
    align-items: center;
    animation: fadeIn 0.3s ease-in-out;
}

.alert i {
    margin-right: 0.75rem;
    font-size: 1.25rem;
}

.alert-success {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
    border: 1px solid rgba(52, 168, 83, 0.3);
}

.alert-error {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
    border: 1px solid rgba(234, 67, 53, 0.3);
}

.close-alert {
    margin-left: auto;
    background: none;
    border: none;
    font-size: 1.25rem;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.close-alert:hover {
    opacity: 1;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Invoice styles */
.invoice-container {
    max-width: 1000px;
    margin: 0 auto 2rem;
    background-color: #fff;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-md, 0 4px 6px rgba(0,0,0,0.1));
    padding: 2rem;
    position: relative;
}

.invoice-actions-top {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-bottom: 2rem;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    padding-bottom: 1rem;
}

.company-info h2 {
    color: var(--primary, #1a73e8);
    margin-top: 0;
    margin-bottom: 0.5rem;
}

.company-info p {
    margin: 0.25rem 0;
    color: var(--text-secondary, #6c757d);
}

.invoice-info {
    text-align: right;
}

.invoice-info h1 {
    color: var(--primary, #1a73e8);
    margin-top: 0;
    margin-bottom: 1rem;
}

.invoice-info table {
    width: auto;
    margin-left: auto;
}

.invoice-info th {
    text-align: right;
    padding-right: 1rem;
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.invoice-addresses {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
    gap: 2rem;
}

.billing-address, .payment-summary {
    flex: 1;
}

.billing-address h3, .payment-summary h3 {
    margin-top: 0;
    color: var(--text-secondary, #6c757d);
    font-size: var(--font-size-md, 1rem);
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.customer-name {
    font-weight: bold;
    font-size: var(--font-size-lg, 1.125rem);
    margin-bottom: 0.5rem;
}

.payment-progress {
    margin-bottom: 1rem;
}

.progress-bar {
    height: 8px;
    background-color: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.progress-fill {
    height: 100%;
    background-color: var(--primary, #1a73e8);
    border-radius: 4px;
    transition: width 0.5s ease-out;
}

.progress-labels {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: var(--text-secondary, #6c757d);
}

.payment-stats {
    display: flex;
    justify-content: space-between;
    margin-top: 1rem;
}

.payment-stat {
    display: flex;
    flex-direction: column;
}

.stat-label {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.25rem;
}

.stat-value {
    font-weight: bold;
    font-size: var(--font-size-lg, 1.125rem);
}

.paid-amount {
    color: #34a853;
}

.balance-amount {
    color: var(--text-primary, #212529);
}

.balance-amount.outstanding {
    color: #ea4335;
}

.invoice-items, .invoice-payments {
    margin-bottom: 2rem;
}

.invoice-items h3, .invoice-payments h3 {
    margin-top: 0;
    color: var(--text-secondary, #6c757d);
    font-size: var(--font-size-md, 1rem);
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
}

.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background-color: var(--surface, #f5f5f5);
    font-weight: 600;
    text-align: left;
    padding: 0.75rem 1rem;
    color: var(--text-secondary, #6c757d);
    border-bottom: 2px solid var(--border, #e0e0e0);
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    vertical-align: middle;
}

.amount-cell {
    font-weight: 500;
}

.method-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    text-align: center;
}

.method-cash {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.method-bank-transfer {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.method-check {
    background-color: rgba(251, 188, 4, 0.1);
    color: #fbbc04;
}

.method-other {
    background-color: rgba(103, 58, 183, 0.1);
    color: #673ab7;
}

.invoice-summary {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 2rem;
}

.summary-table {
    width: 350px;
}

.summary-table table {
    width: 100%;
}

.summary-table th {
    text-align: left;
    padding: 0.5rem 1rem 0.5rem 0;
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.summary-table td {
    text-align: right;
    padding: 0.5rem 0;
    font-weight: 500;
}

.total-row {
    font-weight: bold;
    font-size: var(--font-size-lg, 1.125rem);
    border-top: 1px solid var(--border, #e0e0e0);
}

.balance-row {
    font-weight: bold;
}

.outstanding-balance {
    color: #ea4335;
}

.invoice-notes {
    margin-bottom: 2rem;
    padding: 1rem;
    background-color: var(--surface, #f5f5f5);
    border-radius: var(--border-radius-sm, 4px);
}

.invoice-notes h3 {
    margin-top: 0;
    color: var(--text-secondary, #6c757d);
    font-size: var(--font-size-md, 1rem);
    margin-bottom: 0.5rem;
}

.invoice-footer {
    margin-top: 3rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border, #e0e0e0);
    color: var(--text-secondary, #6c757d);
    font-size: var(--font-size-sm, 0.875rem);
    text-align: center;
}

.footer-note {
    font-style: italic;
    margin-top: 0.5rem;
}

.invoice-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
}

.overdue-date {
    color: #ea4335;
    font-weight: bold;
}

.days-indicator {
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    margin-left: 0.5rem;
}

.days-indicator.overdue {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.days-indicator.upcoming {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

/* Empty state styles */
.empty-state {
    text-align: center;
    padding: 2rem 1rem;
}

.empty-state.small {
    padding: 1.5rem 1rem;
}

.empty-state-icon {
    font-size: 2.5rem;
    color: var(--text-secondary, #6c757d);
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state.small .empty-state-icon {
    font-size: 1.75rem;
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--text-secondary, #6c757d);
    margin-bottom: 1rem;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s;
}

.modal-content {
    background-color: #fff;
    margin: 10% auto;
    max-width: 500px;
    width: 90%;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-lg, 0 10px 25px rgba(0,0,0,0.2));
    animation: slideIn 0.3s;
}

.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: var(--text-primary, #212529);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border, #e0e0e0);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: var(--text-secondary, #6c757d);
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
}

.close-modal:hover {
    color: var(--text-primary, #212529);
}

/* Payment details in modal */
.payment-amount-display {
    text-align: center;
    margin-bottom: 1.5rem;
}

.payment-amount-display span {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--primary, #1a73e8);
}

.payment-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.info-item {
    background-color: var(--surface, #f5f5f5);
    padding: 1rem;
    border-radius: var(--border-radius-sm, 4px);
}

.info-label {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
    margin-bottom: 0.5rem;
}

.info-value {
    font-weight: 500;
}

.payment-notes {
    background-color: var(--surface, #f5f5f5);
    padding: 1rem;
    border-radius: var(--border-radius-sm, 4px);
}

.payment-notes h3 {
    margin-top: 0;
    margin-bottom: 0.5rem;
    font-size: 1rem;
    color: var(--text-secondary, #6c757d);
}

.notes-content {
    color: var(--text-primary, #212529);
    font-style: italic;
}

/* Form styles for email modal */
.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border, #e0e0e0);
    border-radius: var(--border-radius-sm, 4px);
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input[type="text"]:focus,
.form-group input[type="email"]:focus,
.form-group textarea:focus {
    border-color: var(--primary, #1a73e8);
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
    outline: none;
}

.checkbox-group {
    display: flex;
    align-items: center;
}

.checkbox-group input[type="checkbox"] {
    margin-right: 0.5rem;
}

.error-message {
    color: #ea4335;
    font-size: var(--font-size-sm, 0.875rem);
    margin-top: 0.25rem;
    display: none;
}

/* Dropdown menu */
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 1000;
    display: none;
    min-width: 200px;
    padding: 0.5rem 0;
    margin: 0.125rem 0 0;
    background-color: #fff;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-md, 0 4px 6px rgba(0,0,0,0.1));
    animation: fadeIn 0.2s;
}

.dropdown-menu.show {
    display: block;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    color: var(--text-primary, #212529);
    text-decoration: none;
    transition: background-color 0.2s;
}

.dropdown-item:hover {
    background-color: var(--surface, #f5f5f5);
}

.dropdown-divider {
    height: 0;
    margin: 0.5rem 0;
    overflow: hidden;
    border-top: 1px solid var(--border, #e0e0e0);
}

/* Animations */
@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .invoice-container {
        padding: 1.5rem;
    }
    
    .invoice-header {
        flex-direction: column;
    }
    
    .invoice-info {
        text-align: left;
        margin-top: 1.5rem;
    }
    
    .invoice-info table {
        margin-left: 0;
    }
    
    .invoice-info th {
        text-align: left;
    }
    
    .invoice-addresses {
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .invoice-summary {
        justify-content: flex-start;
    }
    
    .summary-table {
        width: 100%;
    }
    
    .invoice-actions, .invoice-actions-top {
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .invoice-actions .button, .invoice-actions-top .button {
        width: 100%;
    }
    
    .modal-content {
        margin: 20% auto;
        width: 95%;
    }
    
    .payment-info-grid {
        grid-template-columns: 1fr;
    }
    
    .data-table, .data-table tbody, .data-table tr, .data-table td {
        display: block;
    }
    
    .data-table thead {
        display: none;
    }
    
    .data-table tbody tr {
        margin-bottom: 1rem;
        border: 1px solid var(--border, #e0e0e0);
        border-radius: var(--border-radius-sm, 4px);
        padding: 0.5rem;
    }
    
    .data-table td {
        padding: 0.5rem;
        border: none;
        text-align: right;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .data-table td::before {
        content: attr(data-label);
        font-weight: 600;
        margin-right: 1rem;
        text-align: left;
        flex: 1;
    }
}

/* Print styles */
@media print {
    body, html {
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        background-color: white;
        font-size: 12pt;
    }
    
    .breadcrumb, .invoice-actions, .invoice-actions-top, .main-header, .sidebar, .alert,
    .modal, .footer, .view-payment, #printInvoiceBtn, #printInvoiceBtn2 {
        display: none !important;
    }
    
    .invoice-container {
        box-shadow: none;
        padding: 0;
        max-width: 100%;
        margin: 0;
    }
    
    .data-table th {
        background-color: #f5f5f5 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .status-badge, .method-badge, .days-indicator {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    @page {
        margin: 1.5cm;
    }
}

/* Accessibility improvements */
@media (prefers-reduced-motion: reduce) {
    .modal, .modal-content, .dropdown-menu, .alert {
        animation: none;
    }
    
    .progress-fill {
        transition: none;
    }
}

/* Focus styles for better keyboard navigation */
button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 2px solid var(--primary, #1a73e8);
    outline-offset: 2px;
}

/* High contrast mode support */
@media (forced-colors: active) {
    .status-badge, .method-badge, .days-indicator,
    .progress-bar, .progress-fill {
        border: 1px solid;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Close alert buttons
    const closeAlertButtons = document.querySelectorAll('.close-alert');
    closeAlertButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });

    // Print functionality
    const printInvoiceBtns = document.querySelectorAll('#printInvoiceBtn, #printInvoiceBtn2');
    printInvoiceBtns.forEach(button => {
        button.addEventListener('click', function() {
            // Log activity before printing
            logUserActivity('read', 'sales', 'Printed invoice: <?php echo htmlspecialchars($sale['invoice_number']); ?>');
            
            // Print the document
            window.print();
        });
    });
    
    // Dropdown toggle
    const dropdownToggle = document.querySelector('.dropdown-toggle');
    const dropdownMenu = document.querySelector('.dropdown-menu');
    
    if (dropdownToggle && dropdownMenu) {
        dropdownToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropdownMenu.classList.toggle('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            dropdownMenu.classList.remove('show');
        });
        
        // Prevent dropdown from closing when clicking inside
                // Prevent dropdown from closing when clicking inside
        dropdownMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Close dropdown with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dropdownMenu.classList.contains('show')) {
                dropdownMenu.classList.remove('show');
            }
        });
        
        // Add keyboard navigation for dropdown items
        const dropdownItems = dropdownMenu.querySelectorAll('.dropdown-item');
        dropdownItems.forEach((item, index) => {
            item.addEventListener('keydown', function(e) {
                // Arrow down - focus next item
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (index + 1) % dropdownItems.length;
                    dropdownItems[nextIndex].focus();
                }
                // Arrow up - focus previous item
                else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = (index - 1 + dropdownItems.length) % dropdownItems.length;
                    dropdownItems[prevIndex].focus();
                }
                // Escape - close dropdown
                else if (e.key === 'Escape') {
                    e.preventDefault();
                    dropdownMenu.classList.remove('show');
                    dropdownToggle.focus();
                }
            });
        });
    }
    
    // Payment modal functionality
    const paymentModal = document.getElementById('paymentModal');
    const viewPaymentButtons = document.querySelectorAll('.view-payment');
    const closeModalButtons = document.querySelectorAll('.close-modal, #closePaymentModal');
    
    // Open payment modal
    viewPaymentButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Get payment data from data attributes
            const amount = this.getAttribute('data-amount');
            const method = this.getAttribute('data-method');
            const reference = this.getAttribute('data-reference');
            const date = this.getAttribute('data-date');
            const notes = this.getAttribute('data-notes');
            
            // Set values in modal
            document.getElementById('modal-amount').textContent = '$' + amount;
            document.getElementById('modal-method').textContent = method.replace('_', ' ');
            document.getElementById('modal-reference').textContent = reference;
            document.getElementById('modal-date').textContent = date;
            
            const notesElement = document.getElementById('modal-notes');
            if (notes && notes.trim() !== '') {
                notesElement.textContent = notes;
            } else {
                notesElement.textContent = 'No notes available for this payment.';
                notesElement.classList.add('empty-notes');
            }
            
            // Show modal
            paymentModal.style.display = 'block';
            
            // Set focus for accessibility
            setTimeout(() => {
                const firstFocusableElement = paymentModal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (firstFocusableElement) {
                    firstFocusableElement.focus();
                }
            }, 100);
            
            // Announce to screen readers
            announceToScreenReader('Payment details dialog opened');
        });
    });
    
    // Close modal functionality
    closeModalButtons.forEach(button => {
        button.addEventListener('click', closeModal);
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === paymentModal) {
            closeModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && paymentModal.style.display === 'block') {
            closeModal();
        }
    });
    
    // Print receipt functionality
    const printReceiptBtn = document.getElementById('printReceiptBtn');
    if (printReceiptBtn) {
        printReceiptBtn.addEventListener('click', function() {
            // Create a printable version of the receipt
            const receiptWindow = window.open('', '_blank', 'width=600,height=600');
            
            // Get payment details
            const amount = document.getElementById('modal-amount').textContent;
            const method = document.getElementById('modal-method').textContent;
            const reference = document.getElementById('modal-reference').textContent;
            const date = document.getElementById('modal-date').textContent;
            const notes = document.getElementById('modal-notes').textContent;
            const invoiceNumber = document.getElementById('invoice-number').value;
            const customerName = document.getElementById('customer-name').value;
            
            // Create receipt HTML
            const receiptHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Payment Receipt - ${invoiceNumber}</title>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            margin: 0;
                            padding: 20px;
                            color: #333;
                            font-size: 14px;
                        }
                        .receipt {
                            max-width: 400px;
                            margin: 0 auto;
                            border: 1px solid #ddd;
                            padding: 20px;
                        }
                        .receipt-header {
                            text-align: center;
                            margin-bottom: 20px;
                            border-bottom: 1px dashed #ddd;
                            padding-bottom: 10px;
                        }
                        .receipt-title {
                            font-size: 24px;
                            font-weight: bold;
                            margin: 0 0 5px;
                        }
                        .company-name {
                            font-size: 18px;
                            margin: 0 0 5px;
                        }
                        .receipt-date {
                            margin-top: 10px;
                            font-style: italic;
                        }
                        .receipt-details {
                            margin-bottom: 20px;
                        }
                        .detail-row {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 10px;
                        }
                        .detail-label {
                            font-weight: bold;
                        }
                        .amount {
                            font-size: 24px;
                            font-weight: bold;
                            text-align: center;
                            margin: 20px 0;
                        }
                        .receipt-footer {
                            text-align: center;
                            margin-top: 30px;
                            font-size: 12px;
                            color: #666;
                        }
                        .receipt-notes {
                            margin-top: 20px;
                            padding-top: 10px;
                            border-top: 1px dashed #ddd;
                        }
                        .notes-label {
                            font-weight: bold;
                            margin-bottom: 5px;
                        }
                        @media print {
                            body {
                                padding: 0;
                            }
                            .receipt {
                                border: none;
                                max-width: none;
                                width: 100%;
                            }
                            .print-button {
                                display: none;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt">
                        <div class="receipt-header">
                            <h1 class="receipt-title">Payment Receipt</h1>
                            <p class="company-name">Garment Manufacturing System</p>
                            <p class="receipt-date">Receipt Date: ${new Date().toLocaleDateString()}</p>
                        </div>
                        
                        <div class="amount">${amount}</div>
                        
                        <div class="receipt-details">
                            <div class="detail-row">
                                <span class="detail-label">Invoice Number:</span>
                                <span>${invoiceNumber}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Customer:</span>
                                <span>${customerName}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment Date:</span>
                                <span>${date}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Payment Method:</span>
                                <span>${method}</span>
                            </div>
                            ${reference !== '-' ? `
                            <div class="detail-row">
                                <span class="detail-label">Reference Number:</span>
                                <span>${reference}</span>
                            </div>
                            ` : ''}
                        </div>
                        
                        ${notes !== 'No notes available for this payment.' ? `
                        <div class="receipt-notes">
                            <div class="notes-label">Notes:</div>
                            <p>${notes}</p>
                        </div>
                        ` : ''}
                        
                        <div class="receipt-footer">
                            <p>Thank you for your business!</p>
                            <p>123 Textile Street, Fashion District</p>
                            <p>Phone: +1 234 567 8900 | Email: sales@garmentmfg.com</p>
                        </div>
                    </div>
                    
                    <div class="print-button" style="text-align: center; margin-top: 20px;">
                        <button onclick="window.print();" style="padding: 10px 20px; cursor: pointer;">Print Receipt</button>
                    </div>
                </body>
                </html>
            `;
            
            // Write to the new window
            receiptWindow.document.write(receiptHTML);
            receiptWindow.document.close();
            
            // Log activity
            logUserActivity('read', 'payments', 'Printed payment receipt for invoice: ' + invoiceNumber);
            
            // Focus the new window
            receiptWindow.focus();
        });
    }
    
    // Email invoice functionality
    const emailInvoiceBtn = document.getElementById('emailInvoiceBtn');
    const emailModal = document.getElementById('emailModal');
    const closeEmailModalBtns = document.querySelectorAll('#emailModal .close-modal, #emailModal .close-modal-btn');
    const sendEmailBtn = document.getElementById('sendEmailBtn');
    
    if (emailInvoiceBtn && emailModal) {
        emailInvoiceBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Show email modal
            emailModal.style.display = 'block';
            
            // Set focus for accessibility
            setTimeout(() => {
                const recipientInput = document.getElementById('recipient_email');
                if (recipientInput) {
                    recipientInput.focus();
                    recipientInput.select();
                }
            }, 100);
            
            // Announce to screen readers
            announceToScreenReader('Email invoice dialog opened');
        });
        
        // Close email modal
        closeEmailModalBtns.forEach(button => {
            button.addEventListener('click', function() {
                emailModal.style.display = 'none';
                
                // Announce to screen readers
                announceToScreenReader('Dialog closed');
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === emailModal) {
                emailModal.style.display = 'none';
                
                // Announce to screen readers
                announceToScreenReader('Dialog closed');
            }
        });
        
        // Send email functionality
        if (sendEmailBtn) {
            sendEmailBtn.addEventListener('click', function() {
                // Get form values
                const emailForm = document.getElementById('emailForm');
                const recipientEmail = document.getElementById('recipient_email').value;
                const emailSubject = document.getElementById('email_subject').value;
                const emailMessage = document.getElementById('email_message').value;
                const includePdf = document.getElementById('include_pdf').checked;
                
                // Validate email
                const emailError = document.getElementById('email-error');
                
                if (!isValidEmail(recipientEmail)) {
                    emailError.textContent = 'Please enter a valid email address';
                    emailError.style.display = 'block';
                    document.getElementById('recipient_email').classList.add('invalid-input');
                    return;
                } else {
                    emailError.style.display = 'none';
                    document.getElementById('recipient_email').classList.remove('invalid-input');
                }
                
                // Show loading state
                const originalBtnText = sendEmailBtn.innerHTML;
                sendEmailBtn.disabled = true;
                sendEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
                
                // Simulate API call to send email (replace with actual API call)
                setTimeout(() => {
                    // Log activity
                    logUserActivity('create', 'communications', 'Sent invoice email to: ' + recipientEmail);
                    
                    // Hide modal
                    emailModal.style.display = 'none';
                    
                    // Show success toast
                    showToast('Invoice successfully sent to ' + recipientEmail, 'success');
                    
                    // Reset button state
                    sendEmailBtn.disabled = false;
                    sendEmailBtn.innerHTML = originalBtnText;
                }, 1500);
            });
        }
    }
    
    // Download PDF functionality
    const downloadPdfBtn = document.getElementById('downloadPdfBtn');
    if (downloadPdfBtn) {
        downloadPdfBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Show loading state
            showToast('Generating PDF...', 'info');
            
            // Simulate PDF generation (replace with actual PDF generation)
            setTimeout(() => {
                // Log activity
                logUserActivity('read', 'sales', 'Downloaded invoice PDF: <?php echo htmlspecialchars($sale['invoice_number']); ?>');
                
                // Show success toast
                showToast('PDF downloaded successfully', 'success');
                
                // Create a dummy download (in a real app, this would be an actual PDF)
                const invoiceNumber = document.getElementById('invoice-number').value;
                const link = document.createElement('a');
                link.href = 'data:application/pdf;base64,JVBERi0xLjcKJeLjz9MKNSAwIG9iago8PC9GaWx0ZXIvRmxhdGVEZWNvZGUvTGVuZ3RoIDM4Pj5zdHJlYW0KeJwr5HIK4TI2UwhXMFAIUTBUCORyDeEK5AIAAP//Lu0FeAplbmRzdHJlYW0KZW5kb2JqCjMgMCBvYmoKPDwvVHlwZS9QYWdlL01lZGlhQm94WzAgMCA2MTIgNzkyXS9SZXNvdXJjZXM8PC9Gb250PDwvRjEgNCAwIFI+Pj4+L0NvbnRlbnRzIDUgMCBSL1BhcmVudCAyIDAgUj4+CmVuZG9iagoyIDAgb2JqCjw8L1R5cGUvUGFnZXMvQ291bnQgMS9LaWRzWzMgMCBSXT4+CmVuZG9iagoxIDAgb2JqCjw8L1R5cGUvQ2F0YWxvZy9QYWdlcyAyIDAgUj4+CmVuZG9iago2IDAgb2JqCjw8L1R5cGUvRm9udERlc2NyaXB0b3IvRm9udE5hbWUvSGVsdmV0aWNhL0ZsYWdzIDMyL0ZvbnRCQm94Wy0xNjYgLTIyNSA3MDYgOTMxXS9JdGFsaWNBbmdsZSAwL0FzY2VudCA3MDYvRGVzY2VudCAtMjI1L0NhcEhlaWdodCA3MDYvU3RlbVYgODA+PgplbmRvYmoKNCAwIG9iago8PC9UeXBlL0ZvbnQvU3VidHlwZS9UeXBlMS9CYXNlRm9udC9IZWx2ZXRpY2EvRW5jb2RpbmcvV2luQW5zaUVuY29kaW5nL0ZvbnREZXNjcmlwdG9yIDYgMCBSPj4KZW5kb2JqCjcgMCBvYmoKPDwvU2l6ZSA4L1Jvb3QgMSAwIFIvSW5mbyA8PC9DcmVhdGlvbkRhdGUoRDoyMDIzMDUwMTEyMDAwMFopPj4vSUQgWzw0ZjNjZjVjMzg2NGVmZGJmMjkxOTIzN2FlYTJkMmEyZT48NGYzY2Y1YzM4NjRlZmRiZjI5MTkyMzdhZWEyZDJhMmU+XS9UeXBlL1hSZWY+PgpzdGFydHhyZWYKNjU3CiUlRU9GCg==';
                link.download = `Invoice_${invoiceNumber}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }, 1500);
        });
    }
    
    // Share invoice functionality
    const shareInvoiceBtn = document.getElementById('shareInvoiceBtn');
    if (shareInvoiceBtn) {
        shareInvoiceBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Check if Web Share API is available
            if (navigator.share) {
                const invoiceNumber = document.getElementById('invoice-number').value;
                const customerName = document.getElementById('customer-name').value;
                
                navigator.share({
                    title: `Invoice ${invoiceNumber}`,
                    text: `Invoice ${invoiceNumber} for ${customerName}`,
                    url: window.location.href
                })
                .then(() => {
                    // Log activity
                    logUserActivity('share', 'sales', 'Shared invoice: ' + invoiceNumber);
                    
                    showToast('Invoice shared successfully', 'success');
                })
                .catch((error) => {
                    console.error('Error sharing invoice:', error);
                    showToast('Could not share invoice', 'error');
                });
            } else {
                // Fallback for browsers that don't support Web Share API
                // Create a temporary input to copy the URL
                const tempInput = document.createElement('input');
                tempInput.value = window.location.href;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                
                showToast('Invoice URL copied to clipboard', 'success');
            }
        });
    }
    
    // Helper functions
    function closeModal() {
        if (paymentModal) {
            paymentModal.style.display = 'none';
        }
        
        // Announce to screen readers
        announceToScreenReader('Dialog closed');
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    // Toast notification system
    window.showToast = function(message, type = 'info') {
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
                toast.remove();
                
                // Remove container if empty
                if (toastContainer.children.length === 0) {
                    toastContainer.remove();
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
    };
    
    // Helper function to announce messages to screen readers
    function announceToScreenReader(message) {
        let announcer = document.getElementById('sr-announcer');
        
        if (!announcer) {
            announcer = document.createElement('div');
            announcer.id = 'sr-announcer';
            announcer.className = 'sr-only';
            announcer.setAttribute('aria-live', 'polite');
            announcer.setAttribute('aria-atomic', 'true');
            document.body.appendChild(announcer);
        }
        
        announcer.textContent = message;
        
        setTimeout(() => {
            announcer.textContent = '';
        }, 3000);
    }
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'sales', 'Viewed sale invoice: <?php echo htmlspecialchars($sale['invoice_number']); ?>');
    }
});

// Activity logging function
function logUserActivity(actionType, module, description) {
    const userId = document.getElementById('current-user-id').value;
    
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

<!-- Toast notification styles -->
<style>
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
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
    background-color: #f8f9fa;
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
    color: #6c757d;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close:hover {
    opacity: 1;
}

.toast-info {
    border-left: 4px solid #1a73e8;
}

.toast-info .toast-icon {
    color: #1a73e8;
}

.toast-success {
    border-left: 4px solid #34a853;
}

.toast-success .toast-icon {
    color: #34a853;
}

.toast-warning {
    border-left: 4px solid #fbbc04;
}

.toast-warning .toast-icon {
    color: #fbbc04;
}

.toast-error {
    border-left: 4px solid #ea4335;
}

.toast-error .toast-icon {
    color: #ea4335;
}

@keyframes toast-in {
    from {
        opacity: 0;
        transform: translateX(50px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes toast-out {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(50px);
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

@media (max-width: 480px) {
    .toast-container {
        top: auto;
        bottom: 20px;
        left: 20px;
        right: 20px;
        max-width: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .toast, .toast-hiding {
        animation: none;
        opacity: 1;
        transform: translateX(0);
    }
}
</style>