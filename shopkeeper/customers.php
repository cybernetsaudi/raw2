<?php
session_start();
$page_title = "Customers";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set up search filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Set up pagination
$records_per_page = 15;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$page = $page < 1 ? 1 : $page; // Ensure page is at least 1
$offset = ($page - 1) * $records_per_page;

try {
    // Build query with filters
    $where_clause = "";
    $params = [];

    if (!empty($search)) {
        $where_clause = "WHERE name LIKE :search OR email LIKE :search OR phone LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    // Get total records for pagination
    $count_query = "SELECT COUNT(*) as total FROM customers $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $param => $value) {
        $count_stmt->bindValue($param, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $records_per_page);

    // Get customers with pagination and filters
    $customers_query = "SELECT c.*, 
                       (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) as sales_count,
                       (SELECT SUM(net_amount) FROM sales WHERE customer_id = c.id) as total_sales,
                       (SELECT SUM(amount) FROM payments p JOIN sales s ON p.sale_id = s.id WHERE s.customer_id = c.id) as total_paid
                       FROM customers c
                       $where_clause
                       ORDER BY name
                       LIMIT :offset, :records_per_page";
    $customers_stmt = $db->prepare($customers_query);
    foreach ($params as $param => $value) {
        $customers_stmt->bindValue($param, $value);
    }
    $customers_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $customers_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
    $customers_stmt->execute();

    // Get customer summary
    $summary_query = "SELECT 
                        COUNT(*) as total_customers,
                        (SELECT COUNT(*) FROM sales) as total_sales,
                        (SELECT SUM(net_amount) FROM sales) as total_sales_amount,
                        (SELECT SUM(amount) FROM payments) as total_payments
                     FROM customers";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate total outstanding
    $total_outstanding = $summary['total_sales_amount'] - $summary['total_payments'];
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!-- Error and Success Messages -->
<?php if (isset($error_message)) : ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($error_message); ?></span>
        <button class="close-alert">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success_message'])) : ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
        <button class="close-alert">&times;</button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header">
    <h2>Customers</h2>
    <div class="page-actions">
        <button id="addCustomerBtn" class="button">
            <i class="fas fa-plus"></i> Add New Customer
        </button>
    </div>
</div>

<!-- Customer Summary Cards -->
<div class="customer-summary">
    <div class="summary-cards">
        <div class="summary-card">
            <div class="card-icon customers-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Total Customers</div>
                <div class="card-value"><?php echo number_format($summary['total_customers']); ?></div>
            </div>
        </div>
        <div class="summary-card">
            <div class="card-icon sales-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Total Sales</div>
                <div class="card-value"><?php echo number_format($summary['total_sales']); ?></div>
            </div>
        </div>
        <div class="summary-card">
            <div class="card-icon amount-icon">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Sales Amount</div>
                <div class="card-value"><?php echo number_format($summary['total_sales_amount'], 2); ?></div>
            </div>
        </div>
        <div class="summary-card <?php echo $total_outstanding > 0 ? 'alert-card' : ''; ?>">
            <div class="card-icon outstanding-icon">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="card-content">
                <div class="card-title">Outstanding</div>
                <div class="card-value"><?php echo number_format($total_outstanding, 2); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filters -->
<div class="filter-container">
    <form id="filterForm" method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label for="search">Search Customers:</label>
                <div class="search-input-container">
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, Email or Phone">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            <div class="filter-actions">
                <a href="customers.php" class="button secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Customer List -->
<div class="dashboard-card full-width">
    <div class="card-header">
        <h3>Customer List</h3>
        <div class="pagination-info">
            Showing <?php echo min(($page - 1) * $records_per_page + 1, $total_records); ?> to 
            <?php echo min($page * $records_per_page, $total_records); ?> of 
            <?php echo $total_records; ?> customers
        </div>
    </div>
    <div class="card-content">
        <?php if ($customers_stmt->rowCount() > 0) : ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Address</th>
                        <th>Sales</th>
                        <th>Total Amount</th>
                        <th>Outstanding</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($customer = $customers_stmt->fetch(PDO::FETCH_ASSOC)) : ?>
                        <?php
                        $outstanding = $customer['total_sales'] - $customer['total_paid'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($customer['name']); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></div>
                                <div><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($customer['address'] ?: 'N/A'); ?></td>
                            <td><?php echo number_format($customer['sales_count']); ?></td>
                            <td><?php echo number_format($customer['total_sales'], 2); ?></td>
                            <td class="<?php echo $outstanding > 0 ? 'outstanding-cell' : ''; ?>">
                                <?php echo number_format($outstanding, 2); ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="button small edit-customer"
                                            data-id="<?php echo $customer['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                            data-phone="<?php echo htmlspecialchars($customer['phone']); ?>"
                                            data-address="<?php echo htmlspecialchars($customer['address']); ?>">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <a href="sales.php?customer_id=<?php echo $customer['id']; ?>" class="button small">
                                        <i class="fas fa-file-invoice"></i> View Sales
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else : ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>No Customers Found</h3>
                <p>No customers match your search criteria. Try adjusting your search or add a new customer.</p>
                <button id="emptyStateAddBtn" class="button">Add New Customer</button>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1) : ?>
            <div class="pagination">
                <?php
                // Build pagination query string with filters
                $pagination_query = '';
                if (!empty($search)) $pagination_query .= '&search=' . urlencode($search);
                ?>
                <?php if ($page > 1) : ?>
                    <a href="?page=1<?php echo $pagination_query; ?>" class="pagination-link">&laquo; First</a>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="pagination-link">&laquo; Previous</a>
                <?php endif; ?>
                <?php
                // Determine the range of page numbers to display
                $range = 2; // Number of pages to show on either side of the current page
                $start_page = max(1, $page - $range);
                $end_page = min($total_pages, $page + $range);

                // Always show first page button
                if ($start_page > 1) {
                    echo '<a href="?page=1' . $pagination_query . '" class="pagination-link">1</a>';
                    if ($start_page > 2) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                }

                // Display the range of pages
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $page) {
                        echo '<span class="pagination-link current">' . $i . '</span>';
                    } else {
                        echo '<a href="?page=' . $i . $pagination_query . '" class="pagination-link">' . $i . '</a>';
                    }
                }

                // Always show last page button
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                    echo '<a href="?page=' . $total_pages . $pagination_query . '" class="pagination-link">' . $total_pages . '</a>';
                }
                ?>
                <?php if ($page < $total_pages) : ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="pagination-link">Next &raquo;</a>
                    <a href="?page=<?php echo $total_pages; ?><?php echo $pagination_query; ?>" class="pagination-link">Last &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Customer Modal -->
<div id="customerModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2 id="modalTitle">Add New Customer</h2>
        <form id="customerForm" action="../api/save-customer.php" method="post">
            <input type="hidden" id="customer_id" name="customer_id" value="">
            <div class="form-group">
                <label for="name">Name: <span class="required">*</span></label>
                <input type="text" id="name" name="name" required>
                <div class="error-message" id="name-error"></div>
            </div>
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email">
                <div class="error-message" id="email-error"></div>
            </div>
            <div class="form-group">
                <label for="phone">Phone: <span class="required">*</span></label>
                <input type="text" id="phone" name="phone" required>
                <div class="error-message" id="phone-error"></div>
            </div>
            <div class="form-group">
                <label for="address">Address:</label>
                <textarea id="address" name="address" rows="3"></textarea>
                <div class="error-message" id="address-error"></div>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelCustomer">Cancel</button>
                <button type="submit" class="button" id="saveCustomerBtn">Save Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<!-- JavaScript -->
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

    // Modal functionality
    const customerModal = document.getElementById('customerModal');
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    const closeModalBtn = customerModal.querySelector('.close-modal');
    const cancelBtn = document.getElementById('cancelCustomer');

    // Open modal for adding customer
    addCustomerBtn.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = 'Add New Customer';
        document.getElementById('customerForm').reset();
        customerModal.style.display = 'block';
    });

    // Close modal
    closeModalBtn.addEventListener('click', () => customerModal.style.display = 'none');
    cancelBtn.addEventListener('click', () => customerModal.style.display = 'none');

    // Close modal when clicking outside
    window.addEventListener('click', (event) => {
        if (event.target === customerModal) {
            customerModal.style.display = 'none';
        }
    });

    // Form validation
    const customerForm = document.getElementById('customerForm');
    customerForm.addEventListener('submit', function(event) {
        event.preventDefault();
        const name = document.getElementById('name').value.trim();
        const phone = document.getElementById('phone').value.trim();

        // Reset errors
        document.querySelectorAll('.error-message').forEach(error => error.style.display = 'none');

        let isValid = true;

        if (!name) {
            document.getElementById('name-error').textContent = 'Name is required';
            document.getElementById('name-error').style.display = 'block';
            isValid = false;
        }

        if (!phone) {
            document.getElementById('phone-error').textContent = 'Phone is required';
            document.getElementById('phone-error').style.display = 'block';
            isValid = false;
        }

        if (isValid) {
            // Submit form via AJAX
            const formData = new FormData(customerForm);
            fetch(customerForm.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'An error occurred');
                }
            })
            .catch(error => console.error('Error:', error));
        }
    });
});
</script>

<!-- CSS -->
<style>
/* General Styles */
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f5f6fa;
    color: #333;
    margin: 0;
    padding: 0;
}

h2, h3 {
    color: #2c3e50;
    margin-bottom: 1.5rem;
}

/* Alert Styles */
.alert {
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 8px;
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

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.page-header h2 {
    margin: 0;
}

.page-actions {
    display: flex;
    gap: 1rem;
}

/* Summary Cards */
.customer-summary {
    margin-bottom: 2rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
}

.summary-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.card-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.customers-icon {
    background-color: #4285f4;
}

.sales-icon {
    background-color: #34a853;
}

.amount-icon {
    background-color: #673ab7;
}

.outstanding-icon {
    background-color: #fbbc04;
}

.alert-card .outstanding-icon {
    background-color: #ea4335;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.card-content {
    flex: 1;
}

.card-title {
    font-size: 0.875rem;
    color: #6c757d;
    margin-bottom: 0.25rem;
}

.card-value {
    font-size: 1.5rem;
    font-weight: bold;
    margin-bottom: 0.25rem;
}

/* Filter Container */
.filter-container {
    margin-bottom: 1.5rem;
}

.filter-form {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
}

.filter-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group {
    flex: 1;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #6c757d;
}

.search-input-container {
    position: relative;
}

.search-input-container input {
    width: 100%;
    padding: 0.5rem 2.5rem 0.5rem 1rem;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    font-size: 1rem;
}

.search-button {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 40px;
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    transition: color 0.2s;
}

.search-button:hover {
    color: #1a73e8;
}

.filter-actions {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}

/* Data Table */
.dashboard-card {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
}

.card-header {
    padding: 1.25rem;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header h3 {
    margin: 0;
}

.pagination-info {
    font-size: 0.875rem;
    color: #6c757d;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background-color: #f5f5f5;
    font-weight: 600;
    text-align: left;
    padding: 0.75rem 1rem;
    color: #6c757d;
    border-bottom: 2px solid #e0e0e0;
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.outstanding-cell {
    color: #ea4335;
    font-weight: bold;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 1rem;
    background-color: #1a73e8;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background-color 0.2s;
}

.button:hover {
    background-color: #1565c0;
}

.button.secondary {
    background-color: #6c757d;
}

.button.secondary:hover {
    background-color: #5a6268;
}

.button.small {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
}

.empty-state-icon {
    font-size: 3rem;
    color: #6c757d;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h3 {
    margin-bottom: 0.5rem;
    color: #212529;
}

.empty-state p {
    color: #6c757d;
    max-width: 500px;
    margin: 0 auto 1.5rem;
}

/* Modal Styles */
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
    background-color: white;
    margin: 10% auto;
    max-width: 500px;
    width: 90%;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    animation: slideIn 0.3s;
}

.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    transition: color 0.2s;
}

.close-modal:hover {
    color: #212529;
}

.modal-body {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus,
.form-group textarea:focus {
    border-color: #1a73e8;
    box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.25);
    outline: none;
}

.error-message {
    color: #ea4335;
    font-size: 0.875rem;
    margin-top: 0.25rem;
    display: none;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

/* Animations */
@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .summary-cards {
        grid-template-columns: 1fr;
    }

    .filter-row {
        flex-direction: column;
    }

    .filter-group {
        width: 100%;
    }

    .data-table th,
    .data-table td {
        padding: 0.5rem;
    }

    .action-buttons {
        flex-direction: column;
        gap: 0.5rem;
    }

    .modal-content {
        margin: 20% auto;
        width: 95%;
    }
}
</style>