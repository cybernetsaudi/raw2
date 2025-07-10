<?php
session_start();
$page_title = "Payments";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up filters
$customer_filter = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build query with filters
$where_clause = "";
$params = array();

if(!empty($customer_filter)) {
    $where_clause .= " AND s.customer_id = :customer_id";
    $params[':customer_id'] = $customer_filter;
}

if(!empty($date_from)) {
    $where_clause .= " AND p.payment_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if(!empty($date_to)) {
    $where_clause .= " AND p.payment_date <= :date_to";
    $params[':date_to'] = $date_to;
}

if(!empty($payment_method)) {
    $where_clause .= " AND p.payment_method = :payment_method";
    $params[':payment_method'] = $payment_method;
}

if(!empty($search_query)) {
    $where_clause .= " AND (s.invoice_number LIKE :search 
                         OR c.name LIKE :search 
                         OR p.reference_number LIKE :search)";
    $params[':search'] = '%' . $search_query . '%';
}

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total 
               FROM payments p
               JOIN sales s ON p.sale_id = s.id
               JOIN customers c ON s.customer_id = c.id
               WHERE 1=1" . $where_clause;
$count_stmt = $db->prepare($count_query);
foreach($params as $param => $value) {
    $count_stmt->bindValue($param, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get payments with pagination and filters
$payments_query = "SELECT p.*, s.invoice_number, c.name as customer_name, u.username as received_by_name
                  FROM payments p
                  JOIN sales s ON p.sale_id = s.id
                  JOIN customers c ON s.customer_id = c.id
                  JOIN users u ON p.recorded_by = u.id
                  WHERE 1=1" . $where_clause . "
                  ORDER BY p.payment_date DESC
                  LIMIT :offset, :records_per_page";
$payments_stmt = $db->prepare($payments_query);
foreach($params as $param => $value) {
    $payments_stmt->bindValue($param, $value);
}
$payments_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$payments_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$payments_stmt->execute();

// Get payment summary
$summary_query = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(amount) as total_amount,
                    COUNT(DISTINCT sale_id) as sales_count
                 FROM payments p
                 JOIN sales s ON p.sale_id = s.id
                 WHERE 1=1" . $where_clause;
$summary_stmt = $db->prepare($summary_query);
foreach($params as $param => $value) {
    $summary_stmt->bindValue($param, $value);
}
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Get payment methods for filter
$methods_query = "SELECT DISTINCT payment_method FROM payments ORDER BY payment_method";
$methods_stmt = $db->prepare($methods_query);
$methods_stmt->execute();
$payment_methods = $methods_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get customers for filter
$customers_query = "SELECT id, name FROM customers ORDER BY name";
$customers_stmt = $db->prepare($customers_query);
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment summary by method
$method_summary_query = "SELECT 
                         payment_method,
                         COUNT(*) as count,
                         SUM(amount) as total
                         FROM payments p
                         JOIN sales s ON p.sale_id = s.id
                         WHERE 1=1" . $where_clause . "
                         GROUP BY payment_method
                         ORDER BY total DESC";
$method_summary_stmt = $db->prepare($method_summary_query);
foreach($params as $param => $value) {
    $method_summary_stmt->bindValue($param, $value);
}
$method_summary_stmt->execute();
$method_summary = $method_summary_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly payment trends (last 6 months)
$trend_query = "SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month,
                SUM(amount) as total
                FROM payments
                WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
                ORDER BY month ASC";
$trend_stmt = $db->prepare($trend_query);
$trend_stmt->execute();
$payment_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <div class="page-title">
        <h1><i class="fas fa-money-bill-wave"></i> Payments</h1>
        <p class="page-subtitle">Manage and track all customer payments</p>
    </div>
    <div class="page-actions">
        <a href="add-payment.php" class="button primary">
            <i class="fas fa-plus"></i> Record New Payment
        </a>
        <div class="dropdown">
            <button class="button secondary dropdown-toggle">
                <i class="fas fa-file-export"></i> Export <i class="fas fa-chevron-down"></i>
            </button>
            <div class="dropdown-menu">
                <a href="#" id="exportCSV" class="dropdown-item">
                    <i class="fas fa-file-csv"></i> Export to CSV
                </a>
                <a href="#" id="exportExcel" class="dropdown-item">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </a>
                <a href="#" id="exportPDF" class="dropdown-item">
                    <i class="fas fa-file-pdf"></i> Export to PDF
                </a>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon payments-icon">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div class="stat-content">
            <h3 class="stat-title">Total Payments</h3>
            <p class="stat-value"><?php echo number_format($summary['total_payments']); ?></p>
            <p class="stat-description">All recorded payments</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon amount-icon">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div class="stat-content">
            <h3 class="stat-title">Total Amount</h3>
            <p class="stat-value">$<?php echo number_format($summary['total_amount'], 2); ?></p>
            <p class="stat-description">Revenue collected</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon sales-icon">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="stat-content">
            <h3 class="stat-title">Sales Covered</h3>
            <p class="stat-value"><?php echo number_format($summary['sales_count']); ?></p>
            <p class="stat-description">Invoices with payments</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon average-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3 class="stat-title">Average Payment</h3>
            <p class="stat-value">$<?php echo $summary['total_payments'] > 0 ? number_format($summary['total_amount'] / $summary['total_payments'], 2) : '0.00'; ?></p>
            <p class="stat-description">Per transaction</p>
        </div>
    </div>
</div>

<div class="dashboard-charts-container">
    <div class="dashboard-chart">
        <div class="chart-header">
            <h3>Payment Methods</h3>
        </div>
        <div class="chart-body">
            <canvas id="paymentMethodChart" height="220"></canvas>
        </div>
        <div class="chart-footer">
            <div class="method-legend">
                <?php foreach($method_summary as $method): ?>
                <div class="legend-item">
                    <span class="legend-color" style="background-color: <?php echo getMethodColor($method['payment_method']); ?>"></span>
                    <span class="legend-label"><?php echo ucfirst($method['payment_method']); ?></span>
                    <span class="legend-value">$<?php echo number_format($method['total'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="dashboard-chart">
        <div class="chart-header">
            <h3>Payment Trends</h3>
        </div>
        <div class="chart-body">
            <canvas id="paymentTrendChart" height="220"></canvas>
        </div>
        <div class="chart-footer">
            <p class="chart-note">Last 6 months of payment activity</p>
        </div>
    </div>
</div>

<div class="filter-section">
    <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Filter Payments</h3>
        <button type="button" id="toggleFilters" class="button text-button">
            <span id="filterToggleText">Hide Filters</span> <i class="fas fa-chevron-up" id="filterToggleIcon"></i>
        </button>
    </div>
    
    <div class="filter-body" id="filterBody">
        <form id="filterForm" method="get" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="search">Search:</label>
                    <div class="search-input-wrapper">
                        <input type="text" id="search" name="search" placeholder="Invoice #, customer, reference..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="search-button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
                
                <div class="filter-group">
                    <label for="customer_id">Customer:</label>
                    <select id="customer_id" name="customer_id">
                        <option value="">All Customers</option>
                        <?php foreach($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $customer_filter == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="payment_method">Payment Method:</label>
                    <select id="payment_method" name="payment_method">
                        <option value="">All Methods</option>
                        <?php foreach($payment_methods as $method): ?>
                        <option value="<?php echo $method; ?>" <?php echo $payment_method == $method ? 'selected' : ''; ?>>
                            <?php echo ucfirst($method); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-row">
                <div class="filter-group">
                    <label for="date_from">From Date:</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                
                <div class="filter-group">
                    <label for="date_to">To Date:</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="button primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="payments.php" class="button secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="data-card">
    <div class="card-header">
        <h2>Payment Records</h2>
        <div class="card-tools">
            <div class="pagination-info">
                Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
                <?php echo min($page * $records_per_page, $total_records); ?> of 
                <?php echo $total_records; ?> payments
            </div>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="data-table payments-table">
            <thead>
                <tr>
                    <th class="date-col">Date</th>
                    <th class="invoice-col">Invoice #</th>
                    <th class="customer-col">Customer</th>
                    <th class="amount-col">Amount</th>
                    <th class="method-col">Method</th>
                    <th class="reference-col">Reference</th>
                    <th class="user-col">Recorded By</th>
                    <th class="actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($payments_stmt->rowCount() === 0): ?>
                <tr class="empty-row">
                    <td colspan="8">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-search-dollar"></i>
                            </div>
                            <h3>No payments found</h3>
                            <p>No payments match your search criteria. Try adjusting your filters.</p>
                            <?php if(!empty($search_query) || !empty($customer_filter) || !empty($payment_method) || !empty($date_from) || !empty($date_to)): ?>
                            <a href="payments.php" class="button secondary">Clear Filters</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php while($payment = $payments_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td data-label="Date"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></td>
                        <td data-label="Invoice">
                            <a href="view-sale.php?id=<?php echo $payment['sale_id']; ?>" class="invoice-link">
                                <?php echo htmlspecialchars($payment['invoice_number']); ?>
                            </a>
                        </td>
                        <td data-label="Customer"><?php echo htmlspecialchars($payment['customer_name']); ?></td>
                        <td data-label="Amount" class="amount-cell">$<?php echo number_format($payment['amount'], 2); ?></td>
                        <td data-label="Method">
                            <span class="method-badge method-<?php echo strtolower(str_replace(' ', '-', $payment['payment_method'])); ?>">
                                <?php echo ucfirst($payment['payment_method']); ?>
                            </span>
                        </td>
                        <td data-label="Reference"><?php echo htmlspecialchars($payment['reference_number'] ?: '-'); ?></td>
                        <td data-label="Recorded By"><?php echo htmlspecialchars($payment['received_by_name']); ?></td>
                        <td data-label="Actions">
                            <div class="table-actions">
                                <button type="button" class="action-button view-payment" 
                                        data-id="<?php echo $payment['id']; ?>"
                                        data-invoice="<?php echo htmlspecialchars($payment['invoice_number']); ?>"
                                        data-customer="<?php echo htmlspecialchars($payment['customer_name']); ?>"
                                        data-amount="<?php echo number_format($payment['amount'], 2); ?>"
                                        data-method="<?php echo htmlspecialchars($payment['payment_method']); ?>"
                                        data-reference="<?php echo htmlspecialchars($payment['reference_number'] ?: '-'); ?>"
                                        data-date="<?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>"
                                        data-notes="<?php echo htmlspecialchars($payment['notes'] ?: ''); ?>"
                                        aria-label="View payment details">
                                    <i class="fas fa-eye"></i>
                                    <span class="action-label">View</span>
                                </button>
                                
                                <button type="button" class="action-button print-receipt" 
                                        data-id="<?php echo $payment['id']; ?>"
                                        data-invoice="<?php echo htmlspecialchars($payment['invoice_number']); ?>"
                                        aria-label="Print receipt">
                                    <i class="fas fa-print"></i>
                                    <span class="action-label">Print</span>
                                </button>
                                
                                <div class="dropdown action-dropdown">
                                    <button type="button" class="action-button dropdown-toggle" aria-label="More actions">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <a href="view-sale.php?id=<?php echo $payment['sale_id']; ?>" class="dropdown-item">
                                            <i class="fas fa-file-invoice"></i> View Invoice
                                        </a>
                                        <a href="#" class="dropdown-item email-receipt" data-id="<?php echo $payment['id']; ?>">
                                            <i class="fas fa-envelope"></i> Email Receipt
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a href="#" class="dropdown-item text-danger void-payment" data-id="<?php echo $payment['id']; ?>">
                                            <i class="fas fa-ban"></i> Void Payment
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if($total_pages > 1): ?>
    <div class="pagination-container">
        <nav aria-label="Payments pagination">
            <ul class="pagination">
                <?php 
                // Build pagination query string with filters
                $pagination_query = '';
                if(!empty($customer_filter)) $pagination_query .= '&customer_id=' . urlencode($customer_filter);
                if(!empty($payment_method)) $pagination_query .= '&payment_method=' . urlencode($payment_method);
                if(!empty($date_from)) $pagination_query .= '&date_from=' . urlencode($date_from);
                if(!empty($date_to)) $pagination_query .= '&date_to=' . urlencode($date_to);
                if(!empty($search_query)) $pagination_query .= '&search=' . urlencode($search_query);
                ?>
                
                <?php if($page > 1): ?>
                <li class="page-item">
                    <a href="?page=1<?php echo $pagination_query; ?>" class="page-link" aria-label="First page">
                        <i class="fas fa-angle-double-left"></i>
                        <span class="sr-only">First</span>
                    </a>
                </li>
                <li class="page-item">
                    <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="page-link" aria-label="Previous page">
                        <i class="fas fa-angle-left"></i>
                        <span class="sr-only">Previous</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php
                // Determine the range of page numbers to display
                $range = 2; // Number of pages to show on either side of the current page
                $start_page = max(1, $page - $range);
                $end_page = min($total_pages, $page + $range);
                
                // Always show first page button
                if($start_page > 1) {
                    echo '<li class="page-item"><a href="?page=1' . $pagination_query . '" class="page-link">1</a></li>';
                    if($start_page > 2) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                }
                
                // Display the range of pages
                for($i = $start_page; $i <= $end_page; $i++) {
                    if($i == $page) {
                        echo '<li class="page-item active"><span class="page-link">' . $i . '<span class="sr-only">(current)</span></span></li>';
                    } else {
                        echo '<li class="page-item"><a href="?page=' . $i . $pagination_query . '" class="page-link">' . $i . '</a></li>';
                    }
                }
                
                // Always show last page button
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) {
                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    echo '<li class="page-item"><a href="?page=' . $total_pages . $pagination_query . '" class="page-link">' . $total_pages . '</a></li>';
                }
                ?>
                
                <?php if($page < $total_pages): ?>
                <li class="page-item">
                    <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="page-link" aria-label="Next page">
                        <i class="fas fa-angle-right"></i>
                        <span class="sr-only">Next</span>
                    </a>
                </li>
                <li class="page-item">
                    <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="page-link" aria-label="Last page">
                        <i class="fas fa-angle-double-right"></i>
                        <span class="sr-only">Last</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- View Payment Modal -->
<div id="paymentModal" class="modal" aria-labelledby="modalTitle" aria-modal="true" role="dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Payment Details</h2>
            <button type="button" class="close-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="payment-amount">
                <div class="amount-label">Amount Paid</div>
                <div class="amount-value" id="modal-amount">$0.00</div>
            </div>
            
            <div class="payment-details">
                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="detail-content">
                        <div class="detail-label">Invoice</div>
                        <div class="detail-value" id="modal-invoice"></div>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-user"></i></div>
                    <div class="detail-content">
                        <div class="detail-label">Customer</div>
                        <div class="detail-value" id="modal-customer"></div>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-credit-card"></i></div>
                    <div class="detail-content">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value" id="modal-method"></div>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-hashtag"></i></div>
                    <div class="detail-content">
                        <div class="detail-label">Reference Number</div>
                        <div class="detail-value" id="modal-reference"></div>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="detail-content">
                        <div class="detail-label">Payment Date</div>
                        <div class="detail-value" id="modal-date"></div>
                    </div>
                </div>
            </div>
            
            <div class="payment-notes" id="notes-container">
                <h3><i class="fas fa-sticky-note"></i> Notes</h3>
                <div class="notes-content" id="modal-notes"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" id="printReceiptBtn" class="button primary">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button type="button" id="emailReceiptBtn" class="button secondary">
                <i class="fas fa-envelope"></i> Email Receipt
            </button>
            <button type="button" id="closePaymentModal" class="button text-button">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Email Receipt Modal -->
<div id="emailReceiptModal" class="modal" aria-labelledby="emailModalTitle" aria-modal="true" role="dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="emailModalTitle">Email Receipt</h2>
            <button type="button" class="close-modal" aria-label="Close modal">&times;</button>
        </div>
        <div class="modal-body">
            <form id="emailForm" method="post">
                <div class="form-group">
                    <label for="recipient_email">Recipient Email:</label>
                    <input type="email" id="recipient_email" name="recipient_email" required>
                    <div class="form-text">Enter the email address to send the receipt to</div>
                </div>
                
                <div class="form-group">
                    <label for="email_subject">Subject:</label>
                    <input type="text" id="email_subject" name="email_subject" required>
                </div>
                
                <div class="form-group">
                    <label for="email_message">Message:</label>
                    <textarea id="email_message" name="email_message" rows="4" required></textarea>
                </div>
                
                <div class="form-check">
                    <input type="checkbox" id="include_pdf" name="include_pdf" checked>
                    <label for="include_pdf">Include receipt as PDF attachment</label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" id="sendEmailBtn" class="button primary">
                <i class="fas fa-paper-plane"></i> Send Email
            </button>
            <button type="button" class="button secondary close-modal-btn">Cancel</button>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
:root {
    --primary: #1a73e8;
    --primary-dark: #1565c0;
    --primary-light: #e8f0fe;
    --secondary: #5f6368;
    --success: #34a853;
    --info: #4285f4;
    --warning: #fbbc04;
    --danger: #ea4335;
    --light: #f8f9fa;
    --dark: #202124;
    --surface: #f5f5f5;
    --background: #f8f9fa;
    --text-primary: #202124;
    --text-secondary: #5f6368;
    --text-disabled: #9aa0a6;
    --border: #dadce0;
        --shadow-sm: 0 1px 2px 0 rgba(60, 64, 67, 0.3), 0 1px 3px 1px rgba(60, 64, 67, 0.15);
    --shadow-md: 0 4px 8px 0 rgba(60, 64, 67, 0.2), 0 2px 10px 0 rgba(60, 64, 67, 0.1);
    --shadow-lg: 0 8px 16px 0 rgba(60, 64, 67, 0.2), 0 4px 12px 0 rgba(60, 64, 67, 0.1);
    --border-radius-sm: 4px;
    --border-radius-md: 8px;
    --border-radius-lg: 12px;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-md: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
}

/* Page Layout */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--spacing-xl);
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.page-title h1 {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin: 0 0 var(--spacing-xs);
    color: var(--text-primary);
    font-size: 1.75rem;
    font-weight: 600;
}

.page-title h1 i {
    color: var(--primary);
}

.page-subtitle {
    color: var(--text-secondary);
    margin: 0;
    font-size: var(--font-size-md);
}

.page-actions {
    display: flex;
    gap: var(--spacing-sm);
}

/* Dashboard Stats */
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.stat-card {
    background-color: white;
    border-radius: var(--border-radius-md);
    padding: var(--spacing-lg);
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    align-items: center;
    gap: var(--spacing-lg);
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.payments-icon {
    background-color: #4285f4;
}

.amount-icon {
    background-color: #34a853;
}

.sales-icon {
    background-color: #673ab7;
}

.average-icon {
    background-color: #ea4335;
}

.stat-content {
    flex: 1;
}

.stat-title {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0 0 var(--spacing-xs);
    font-weight: 500;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 var(--spacing-xs);
}

.stat-description {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0;
}

/* Dashboard Charts */
.dashboard-charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: var(--spacing-lg);
    margin-bottom: var(--spacing-xl);
}

.dashboard-chart {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.chart-header {
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid var(--border);
}

.chart-header h3 {
    margin: 0;
    font-size: var(--font-size-lg);
    color: var(--text-primary);
    font-weight: 600;
}

.chart-body {
    padding: var(--spacing-lg);
    height: 250px;
}

.chart-footer {
    padding: var(--spacing-md) var(--spacing-lg);
    border-top: 1px solid var(--border);
    background-color: var(--surface);
}

.chart-note {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    text-align: center;
    font-style: italic;
}

.method-legend {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--spacing-md);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    font-size: var(--font-size-sm);
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
    flex-shrink: 0;
}

.legend-label {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.legend-value {
    font-weight: 600;
    color: var(--text-primary);
}

/* Filter Section */
.filter-section {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-xl);
    overflow: hidden;
}

.filter-header {
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
}

.filter-header h3 {
    margin: 0;
    font-size: var(--font-size-md);
    color: var(--text-primary);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.filter-header h3 i {
    color: var(--primary);
}

.filter-body {
    padding: var(--spacing-lg);
    transition: max-height 0.3s ease, padding 0.3s ease;
    overflow: hidden;
}

.filter-body.collapsed {
    max-height: 0;
    padding-top: 0;
    padding-bottom: 0;
}

.filter-form {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
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
    color: var(--text-secondary);
    font-weight: 500;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-md);
    color: var(--text-primary);
    background-color: white;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
}

.search-input-wrapper {
    position: relative;
}

.search-input-wrapper input {
    padding-right: 2.5rem;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 2.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    transition: color 0.2s ease;
}

.search-button:hover {
    color: var(--primary);
}

.filter-actions {
    display: flex;
    gap: var(--spacing-sm);
    align-items: flex-end;
}

/* Data Card */
.data-card {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--spacing-xl);
    overflow: hidden;
}

.card-header {
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: var(--spacing-md);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    color: var(--text-primary);
    font-weight: 600;
}

.card-tools {
    display: flex;
    align-items: center;
    gap: var(--spacing-md);
}

.pagination-info {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
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

.data-table thead th {
    padding: var(--spacing-md) var(--spacing-lg);
    background-color: var(--surface);
    color: var(--text-secondary);
    font-weight: 500;
    text-align: left;
    font-size: var(--font-size-sm);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}

.data-table tbody td {
    padding: var(--spacing-md) var(--spacing-lg);
    border-bottom: 1px solid var(--border);
    color: var(--text-primary);
    font-size: var(--font-size-md);
}

.data-table tbody tr:hover {
    background-color: var(--primary-light);
}

.data-table .amount-cell {
    font-weight: 600;
    color: var(--primary);
}

.invoice-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    transition: color 0.2s ease;
}

.invoice-link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}

.method-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-xs);
    font-weight: 500;
    text-transform: uppercase;
    white-space: nowrap;
}

.method-cash {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.method-credit-card {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.method-bank-transfer {
    background-color: rgba(103, 58, 183, 0.1);
    color: #673ab7;
}

.method-check {
    background-color: rgba(251, 188, 4, 0.1);
    color: #fbbc04;
}

.method-other {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.table-actions {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    justify-content: flex-end;
}

.action-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-secondary);
    padding: var(--spacing-xs);
    border-radius: var(--border-radius-sm);
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease;
    position: relative;
}

.action-button:hover {
    background-color: var(--surface);
    color: var(--primary);
}

.action-label {
    display: none;
    font-size: var(--font-size-xs);
    margin-left: var(--spacing-xs);
}

.action-dropdown {
    position: relative;
}

.dropdown-toggle::after {
    display: inline-block;
    margin-left: 0.255em;
    vertical-align: 0.255em;
    content: "";
    border-top: 0.3em solid;
    border-right: 0.3em solid transparent;
    border-bottom: 0;
    border-left: 0.3em solid transparent;
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 1000;
    display: none;
    min-width: 10rem;
    padding: 0.5rem 0;
    margin: 0.125rem 0 0;
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-md);
}

.dropdown-menu.show {
    display: block;
    animation: fadeIn 0.2s ease;
}

.dropdown-menu-right {
    right: 0;
    left: auto;
}

.dropdown-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    padding: 0.5rem 1rem;
    color: var(--text-primary);
    text-decoration: none;
    white-space: nowrap;
    transition: background-color 0.2s ease;
}

.dropdown-item:hover {
    background-color: var(--surface);
}

.dropdown-divider {
    height: 0;
    margin: 0.5rem 0;
    overflow: hidden;
    border-top: 1px solid var(--border);
}

.text-danger {
    color: var(--danger) !important;
}

/* Empty State */
.empty-row td {
    padding: 0;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--spacing-xl) var(--spacing-lg);
    text-align: center;
}

.empty-state-icon {
    width: 4rem;
    height: 4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--surface);
    border-radius: 50%;
    color: var(--primary);
    font-size: 2rem;
    margin-bottom: var(--spacing-md);
}

.empty-state h3 {
    margin: 0 0 var(--spacing-xs);
    font-size: var(--font-size-lg);
    color: var(--text-primary);
    font-weight: 600;
}

.empty-state p {
    margin: 0 0 var(--spacing-md);
    font-size: var(--font-size-md);
    color: var(--text-secondary);
    max-width: 500px;
}

/* Pagination */
.pagination-container {
    padding: var(--spacing-md) var(--spacing-lg);
    display: flex;
    justify-content: center;
    border-top: 1px solid var(--border);
}

.pagination {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.page-item {
    display: inline-block;
}

.page-item.active .page-link {
    background-color: var(--primary);
    color: white;
    border-color: var(--primary);
}

.page-item.disabled .page-link {
    color: var(--text-disabled);
    pointer-events: none;
    background-color: var(--surface);
    border-color: var(--border);
}

.page-link {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.5rem;
    margin: 0;
    color: var(--text-primary);
    text-decoration: none;
    background-color: white;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}

.page-link:hover {
    background-color: var(--surface);
    color: var(--primary);
    border-color: var(--primary);
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
    z-index: 1050;
    overflow-y: auto;
    animation: fadeIn 0.3s ease;
}

.modal-content {
    position: relative;
    background-color: white;
    margin: 2rem auto;
    max-width: 500px;
    width: 90%;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-lg);
    animation: slideIn 0.3s ease;
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--spacing-lg);
    border-bottom: 1px solid var(--border);
}

.modal-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    color: var(--text-primary);
    font-weight: 600;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
}

.modal-body {
    padding: var(--spacing-lg);
}

.payment-amount {
    text-align: center;
    margin-bottom: var(--spacing-lg);
    padding-bottom: var(--spacing-lg);
    border-bottom: 1px solid var(--border);
}

.amount-label {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-bottom: var(--spacing-xs);
}

.amount-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--success);
}

.payment-details {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-lg);
}

.detail-row {
    display: flex;
    align-items: flex-start;
    gap: var(--spacing-md);
}

.detail-icon {
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--primary-light);
    color: var(--primary);
    border-radius: 50%;
    flex-shrink: 0;
}

.detail-content {
    flex: 1;
}

.detail-label {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.detail-value {
    font-weight: 500;
    color: var(--text-primary);
}

.payment-notes {
    background-color: var(--surface);
    border-radius: var(--border-radius-md);
    padding: var(--spacing-md);
}

.payment-notes h3 {
    margin: 0 0 var(--spacing-sm);
    font-size: var(--font-size-md);
    color: var(--text-primary);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.payment-notes h3 i {
    color: var(--primary);
}

.notes-content {
    font-size: var(--font-size-md);
    color: var(--text-secondary);
    font-style: italic;
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--spacing-sm);
    padding: var(--spacing-md) var(--spacing-lg);
    border-top: 1px solid var(--border);
}

/* Form Styles */
.form-group {
    margin-bottom: var(--spacing-md);
}

.form-group label {
    display: block;
    margin-bottom: var(--spacing-xs);
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    font-weight: 500;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 0.625rem;
    border: 1px solid var(--border);
    border-radius: var(--border-radius-sm);
    font-size: var(--font-size-md);
    color: var(--text-primary);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.2);
}

.form-text {
    font-size: var(--font-size-xs);
    color: var(--text-secondary);
    margin-top: var(--spacing-xs);
}

.form-check {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
}

.form-check input[type="checkbox"] {
    width: 1rem;
    height: 1rem;
}

.form-check label {
    font-size: var(--font-size-sm);
    color: var(--text-primary);
    margin-bottom: 0;
}

/* Button Styles */
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-xs);
    padding: 0.625rem 1rem;
    font-size: var(--font-size-md);
    font-weight: 500;
    border: none;
    border-radius: var(--border-radius-md);
    cursor: pointer;
    transition: background-color 0.2s ease, color 0.2s ease, transform 0.1s ease;
    text-decoration: none;
    white-space: nowrap;
}

.button:active {
    transform: translateY(1px);
}

.button.primary {
    background-color: var(--primary);
    color: white;
}

.button.primary:hover {
    background-color: var(--primary-dark);
}

.button.secondary {
    background-color: var(--surface);
    color: var(--text-primary);
    border: 1px solid var(--border);
}

.button.secondary:hover {
    background-color: var(--border);
}

.button.text-button {
    background: none;
    color: var(--primary);
    padding: 0.5rem;
}

.button.text-button:hover {
    background-color: var(--primary-light);
}

.button.small {
    padding: 0.25rem 0.5rem;
    font-size: var(--font-size-sm);
}

/* Utility Classes */
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

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive Design */
@media (max-width: 1200px) {
    .dashboard-charts-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 992px) {
    .dashboard-stats {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .reference-col,
    .user-col {
        display: none;
    }
    
    .action-label {
        display: inline-block;
    }
}

@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-actions {
        width: 100%;
        justify-content: space-between;
    }
    
    .dashboard-stats {
        grid-template-columns: 1fr;
    }
    
    .filter-group {
        flex: 1 0 100%;
    }
    
    .filter-actions {
        flex: 1 0 100%;
        justify-content: stretch;
    }
    
    .filter-actions .button {
        flex: 1;
    }
    
    .customer-col {
        display: none;
    }
    
    .data-table tbody td {
        padding: var(--spacing-sm) var(--spacing-md);
    }
    
    .modal-content {
        width: 95%;
        margin: 1rem auto;
    }
}

@media (max-width: 576px) {
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
        border: 1px solid var(--border);
        border-radius: var(--border-radius-md);
        margin-bottom: var(--spacing-md);
        padding: var(--spacing-sm);
    }
    
    .data-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: none;
        padding: var(--spacing-xs) var(--spacing-sm);
    }
    
    .data-table td::before {
        content: attr(data-label);
        font-weight: 500;
        color: var(--text-secondary);
        margin-right: var(--spacing-sm);
    }
    
    .reference-col,
    .user-col,
    .customer-col {
        display: flex;
    }
    
    .actions-col {
        justify-content: flex-start;
    }
    
    .table-actions {
        justify-content: flex-start;
    }
}

/* Print Styles */
@media print {
    body {
        background-color: white;
    }
    
    .page-header, 
    .dashboard-stats, 
    .dashboard-charts-container, 
    .filter-section, 
    .pagination-container, 
    .action-button, 
    .dropdown,
    .card-tools {
        display: none !important;
    }
    
    .data-card {
        box-shadow: none;
        border: 1px solid var(--border);
    }
    
    .card-header {
        border-bottom: 1px solid var(--border);
    }
    
    .data-table th {
        background-color: #f5f5f5 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .method-badge {
        border: 1px solid currentColor;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}

/* Accessibility Improvements */
.button:focus,
input:focus,
select:focus,
textarea:focus,
.action-button:focus,
.page-link:focus,
.dropdown-item:focus {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle filters visibility
    const toggleFiltersBtn = document.getElementById('toggleFilters');
    const filterBody = document.getElementById('filterBody');
    const filterToggleText = document.getElementById('filterToggleText');
    const filterToggleIcon = document.getElementById('filterToggleIcon');
    
    if (toggleFiltersBtn && filterBody) {
        toggleFiltersBtn.addEventListener('click', function() {
            filterBody.classList.toggle('collapsed');
            
            if (filterBody.classList.contains('collapsed')) {
                filterToggleText.textContent = 'Show Filters';
                filterToggleIcon.classList.replace('fa-chevron-up', 'fa-chevron-down');
            } else {
                filterToggleText.textContent = 'Hide Filters';
                filterToggleIcon.classList.replace('fa-chevron-down', 'fa-chevron-up');
            }
        });
    }
    
    // Dropdown functionality
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const dropdown = this.closest('.dropdown');
            const menu = dropdown.querySelector('.dropdown-menu');
            
            // Close all other dropdowns first
            document.querySelectorAll('.dropdown-menu.show').forEach(openMenu => {
                if (openMenu !== menu) {
                    openMenu.classList.remove('show');
                }
            });
            
            // Toggle this dropdown
            menu.classList.toggle('show');
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
    
    // Payment method chart
    if (document.getElementById('paymentMethodChart')) {
        const methodData = <?php echo json_encode($method_summary); ?>;
        
        if (methodData && methodData.length > 0) {
            const ctx = document.getElementById('paymentMethodChart').getContext('2d');
            
            // Prepare data for chart
            const labels = methodData.map(item => item.payment_method);
            const data = methodData.map(item => parseFloat(item.total));
            const backgroundColor = [
                'rgba(66, 133, 244, 0.8)',  // Blue
                'rgba(52, 168, 83, 0.8)',   // Green
                'rgba(251, 188, 4, 0.8)',   // Yellow
                'rgba(234, 67, 53, 0.8)',   // Red
                'rgba(103, 58, 183, 0.8)',  // Purple
                'rgba(0, 188, 212, 0.8)',   // Cyan
                'rgba(255, 152, 0, 0.8)'    // Orange
            ];
            
            // Create chart
            const paymentMethodChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels.map(label => label.charAt(0).toUpperCase() + label.slice(1)),
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColor.slice(0, data.length),
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 15,
                                padding: 15,
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: $${new Intl.NumberFormat('en-US', { 
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }).format(value)} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '65%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
        }
    }
    
    // Payment trends chart
    if (document.getElementById('paymentTrendChart')) {
        const trendData = <?php echo json_encode($payment_trends); ?>;
        
        if (trendData && trendData.length > 0) {
            const ctx = document.getElementById('paymentTrendChart').getContext('2d');
            
            // Prepare data for chart
            const labels = trendData.map(item => {
                const date = new Date(item.month + '-01');
                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
            });
            const data = trendData.map(item => parseFloat(item.total));
            
            // Create chart
            const paymentTrendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Payment Amount',
                        data: data,
                        backgroundColor: 'rgba(66, 133, 244, 0.1)',
                        borderColor: 'rgba(66, 133, 244, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(66, 133, 244, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.3
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
                                    return `Amount: $${new Intl.NumberFormat('en-US', { 
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }).format(context.raw)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '$' + new Intl.NumberFormat('en-US', { 
                                        minimumFractionDigits: 0,
                                        maximumFractionDigits: 0
                                    }).format(value);
                                }
                            }
                        }
                    }
                }
            });
        }
    }
    
    // Date validation for filters
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    
    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', function() {
            if (dateToInput.value && this.value > dateToInput.value) {
                showToast('From date cannot be later than To date.', 'warning');
                this.value = dateToInput.value;
            }
        });
        
        dateToInput.addEventListener('change', function() {
            if (dateFromInput.value && this.value < dateFromInput.value) {
                showToast('To date cannot be earlier than From date.', 'warning');
                this.value = dateFromInput.value;
            }
        });
    }
    
    // Payment modal functionality
    const paymentModal = document.getElementById('paymentModal');
    const viewButtons = document.querySelectorAll('.view-payment');
    const closeModalBtns = document.querySelectorAll('#paymentModal .close-modal, #closePaymentModal');
    const printReceiptBtn = document.getElementById('printReceiptBtn');
    const emailReceiptBtn = document.getElementById('emailReceiptBtn');
    
    // Open payment modal
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Get payment data from data attributes
            const invoice = this.getAttribute('data-invoice');
            const customer = this.getAttribute('data-customer');
            const amount = this.getAttribute('data-amount');
            const method = this.getAttribute('data-method');
            const reference = this.getAttribute('data-reference');
            const date = this.getAttribute('data-date');
            const notes = this.getAttribute('data-notes');
            
            // Set values in modal
            document.getElementById('modal-invoice').textContent = invoice;
            document.getElementById('modal-customer').textContent = customer;
            document.getElementById('modal-amount').textContent = '$' + amount;
            document.getElementById('modal-method').textContent = method;
            document.getElementById('modal-reference').textContent = reference;
            document.getElementById('modal-date').textContent = date;
            
            const notesContainer = document.getElementById('notes-container');
            const notesContent = document.getElementById('modal-notes');
            
            if (notes && notes.trim() !== '') {
                notesContent.textContent = notes;
                notesContainer.style.display = 'block';
            } else {
                notesContent.textContent = 'No additional notes provided.';
                notesContainer.style.display = 'block';
            }
            
            // Show modal
            showModal(paymentModal);
            
            // Log activity
            logUserActivity('read', 'payments', 'Viewed payment details for invoice: ' + invoice);
        });
    });
    
    // Close modal functions
    function closeModal(modal) {
        if (!modal) return;
        
        // Add closing animation
        modal.classList.add('closing');
        
        // After animation completes, hide the modal
        setTimeout(() => {
            modal.style.display = 'none';
            modal.classList.remove('closing');
            document.body.style.overflow = '';
        }, 300);
        
        // Announce to screen readers
        announceToScreenReader('Dialog closed');
    }
    
    // Show modal function
    function showModal(modal) {
        if (!modal) return;
        
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Focus the first focusable element for accessibility
        setTimeout(() => {
            const focusable = modal.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable.length) focusable[0].focus();
        }, 100);
        
        // Announce to screen readers
        announceToScreenReader('Dialog opened');
    }
    
    // Close modal events
    closeModalBtns.forEach(btn => {
        if (btn) {
            btn.addEventListener('click', () => closeModal(paymentModal));
        }
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === paymentModal) {
            closeModal(paymentModal);
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            if (paymentModal && paymentModal.style.display === 'block') {
                closeModal(paymentModal);
            }
        }
    });
    
    // Print receipt functionality
    if (printReceiptBtn) {
        printReceiptBtn.addEventListener('click', function() {
            const invoice = document.getElementById('modal-invoice').textContent;
            const customer = document.getElementById('modal-customer').textContent;
            const amount = document.getElementById('modal-amount').textContent;
            const method = document.getElementById('modal-method').textContent;
            const reference = document.getElementById('modal-reference').textContent;
            const date = document.getElementById('modal-date').textContent;
            const notes = document.getElementById('modal-notes').textContent;
            
            // Create a printable version of the receipt
            const receiptWindow = window.open('', '_blank', 'width=600,height=600');
            
            // Create receipt HTML
            const receiptHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Payment Receipt - ${invoice}</title>
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
                                <span>${invoice}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Customer:</span>
                                <span>${customer}</span>
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
                        
                        ${notes !== 'No additional notes provided.' ? `
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
                        <button onclick="window.print(); setTimeout(() => window.close(), 500);" style="padding: 10px 20px; cursor: pointer; background-color: #1a73e8; color: white; border: none; border-radius: 4px;">Print Receipt</button>
                    </div>
                </body>
                </html>
            `;
            
            // Write to the new window
            receiptWindow.document.write(receiptHTML);
            receiptWindow.document.close();
            
            // Log activity
            logUserActivity('read', 'payments', 'Printed payment receipt for invoice: ' + invoice);
        });
    }
    
    // Email receipt functionality
    const emailReceiptModal = document.getElementById('emailReceiptModal');
    
    if (emailReceiptBtn) {
        emailReceiptBtn.addEventListener('click', function() {
            const invoice = document.getElementById('modal-invoice').textContent;
            const customer = document.getElementById('modal-customer').textContent;
            
            // Pre-fill email form
            document.getElementById('email_subject').value = `Payment Receipt for Invoice ${invoice}`;
            document.getElementById('email_message').value = `Dear ${customer},

Thank you for your payment on invoice ${invoice}.

Please find attached your payment receipt. If you have any questions, please feel free to contact us.

Best regards,
Garment Manufacturing System`;
            
            // Close the payment modal
            closeModal(paymentModal);
            
            // Show email modal
            showModal(emailReceiptModal);
        });
    }
    
    // Email form submission
    const emailForm = document.getElementById('emailForm');
    const sendEmailBtn = document.getElementById('sendEmailBtn');
    
    if (emailForm && sendEmailBtn) {
        sendEmailBtn.addEventListener('click', function() {
            // Validate email
            const emailInput = document.getElementById('recipient_email');
            if (!isValidEmail(emailInput.value)) {
                showToast('Please enter a valid email address', 'error');
                emailInput.focus();
                return;
            }
            
            // Show loading state
            sendEmailBtn.disabled = true;
            const originalText = sendEmailBtn.innerHTML;
            sendEmailBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            // Simulate email sending (replace with actual API call)
            setTimeout(() => {
                // Close modal
                closeModal(emailReceiptModal);
                
                // Reset button
                sendEmailBtn.disabled = false;
                sendEmailBtn.innerHTML = originalText;
                
                // Show success message
                showToast('Receipt sent successfully', 'success');
                
                // Log activity
                logUserActivity('create', 'communications', 'Sent payment receipt to: ' + emailInput.value);
            }, 1500);
        });
    }
    
    // Close email modal
    const closeEmailModalBtns = document.querySelectorAll('#emailReceiptModal .close-modal, #emailReceiptModal .close-modal-btn');
    
    closeEmailModalBtns.forEach(btn => {
        if (btn) {
            btn.addEventListener('click', () => closeModal(emailReceiptModal));
        }
    });
    
    // Export functionality
    const exportCSV = document.getElementById('exportCSV');
    const exportExcel = document.getElementById('exportExcel');
    const exportPDF = document.getElementById('exportPDF');
    
    if (exportCSV) {
        exportCSV.addEventListener('click', function(e) {
            e.preventDefault();
            exportTableData('csv');
        });
    }
    
    if (exportExcel) {
        exportExcel.addEventListener('click', function(e) {
            e.preventDefault();
            exportTableData('excel');
        });
    }
    
    if (exportPDF) {
        exportPDF.addEventListener('click', function(e) {
            e.preventDefault();
            showToast('Generating PDF...', 'info');
            
            // Simulate PDF generation (replace with actual implementation)
            setTimeout(() => {
                showToast('PDF downloaded successfully', 'success');
                logUserActivity('read', 'payments', 'Exported payments to PDF');
            }, 1500);
        });
    }
    
    // Helper function for exporting table data
    function exportTableData(format) {
        const table = document.querySelector('.payments-table');
        if (!table) return;
        
        // Get table headers
        const headers = [];
        table.querySelectorAll('thead th').forEach(th => {
            // Skip the actions column
            if (!th.classList.contains('actions-col')) {
                headers.push(th.textContent.trim());
            }
        });
        
        // Get table data
        const rows = [];
        table.querySelectorAll('tbody tr:not(.empty-row)').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                // Skip the actions column
                if (!td.classList.contains('actions-col')) {
                    // Get text content, removing any child elements
                    let content = td.textContent.trim();
                    row.push(content);
                }
            });
            if (row.length > 0) rows.push(row);
        });
        
        // Create CSV content
        let csv = headers.join(',') + '\n';
        rows.forEach(row => {
            csv += row.map(cell => {
                // Escape quotes and wrap in quotes if contains comma
                if (cell.includes(',') || cell.includes('"')) {
                    return `"${cell.replace(/"/g, '""')}"`;
                }
                return cell;
            }).join(',') + '\n';
        });
        
        // Create download link
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', `payments_export_${new Date().toISOString().slice(0, 10)}.${format === 'excel' ? 'xls' : 'csv'}`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Show success message
        showToast(`Payments exported to ${format.toUpperCase()} successfully`, 'success');
        
        // Log activity
        logUserActivity('read', 'payments', `Exported payments to ${format.toUpperCase()}`);
    }
    
    // Helper function to check if email is valid
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
    logUserActivity('read', 'payments', 'Viewed payments list');
});

// Activity logging function
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

// Helper function to get color for payment method
function getMethodColor(method) {
    const colorMap = {
        'cash': 'rgba(52, 168, 83, 0.8)',
        'credit_card': 'rgba(66, 133, 244, 0.8)',
        'bank_transfer': 'rgba(103, 58, 183, 0.8)',
        'check': 'rgba(251, 188, 4, 0.8)',
        'mobile_payment': 'rgba(234, 67, 53, 0.8)',
        'other': 'rgba(0, 188, 212, 0.8)'
    };
    
    // Convert method name to a key format (lowercase, underscores)
    const key = method.toLowerCase().replace(/\s+/g, '_');
    
    return colorMap[key] || 'rgba(158, 158, 158, 0.8)'; // Default gray if not found
}
</script>

<!-- Toast styles -->
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
    border-radius: var(--border-radius-md);
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
    color: var(--text-secondary);
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

@media (max-width: 576px) {
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
        animation: none !important;
        opacity: 1;
        transform: translateX(0);
    }
}
</style>

<?php
// Helper function to get color for payment method (used in the charts)
function getMethodColor($method) {
    $colorMap = [
        'cash' => 'rgba(52, 168, 83, 0.8)',
        'credit_card' => 'rgba(66, 133, 244, 0.8)',
        'bank_transfer' => 'rgba(103, 58, 183, 0.8)',
        'check' => 'rgba(251, 188, 4, 0.8)',
        'mobile_payment' => 'rgba(234, 67, 53, 0.8)',
        'other' => 'rgba(0, 188, 212, 0.8)'
    ];
    
    // Convert method name to a key format (lowercase, underscores)
    $key = strtolower(str_replace(' ', '_', $method));
    
    return isset($colorMap[$key]) ? $colorMap[$key] : 'rgba(158, 158, 158, 0.8)';
}
?>

<?php include_once '../includes/footer.php'; ?>