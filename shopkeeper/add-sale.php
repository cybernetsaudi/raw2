<?php
session_start();
$page_title = "Create New Sale";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get all customers
    $customers_query = "SELECT id, name, contact_person, phone, email, address FROM customers ORDER BY name";
    $customers_stmt = $db->prepare($customers_query);
    $customers_stmt->execute();
    $customers = $customers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get products in wholesale inventory
    $products_query = "SELECT p.id, p.name, p.sku, p.description, i.quantity, 
                      (SELECT AVG(unit_price) FROM sale_items WHERE product_id = p.id) as avg_price
                       FROM products p 
                       JOIN inventory i ON p.id = i.product_id 
                       WHERE i.location = 'wholesale' AND i.quantity > 0
                       ORDER BY p.name";
    $products_stmt = $db->prepare($products_query);
    $products_stmt->execute();
    $products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate invoice number (current date + random number)
    $invoice_number = 'INV-' . date('Ymd') . '-' . rand(1000, 9999);
} catch(PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<?php if(isset($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <button class="close-alert">&times;</button>
</div>
<?php endif; ?>

<div class="breadcrumb">
    <a href="sales.php">Sales</a> &raquo; Create New Sale
</div>

<div class="form-container wide-form">
    <h2>Create New Sale</h2>
    
    <form id="saleForm" action="../api/save-sale.php" method="post">
        <div class="form-section">
            <h3>Sale Information</h3>
            <div class="form-row">
                <div class="form-col">
                                       <div class="form-group">
                        <label for="sale_date">Sale Date: <span class="required">*</span></label>
                        <input type="date" id="sale_date" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required>
                        <div class="error-message" id="sale-date-error"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="customer_id">Customer: <span class="required">*</span></label>
                        <div class="select-with-action">
                            <select id="customer_id" name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php if(count($customers) > 0): ?>
                                    <?php foreach($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-contact="<?php echo htmlspecialchars($customer['contact_person']); ?>" 
                                            data-phone="<?php echo htmlspecialchars($customer['phone']); ?>"
                                            data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                            data-address="<?php echo htmlspecialchars($customer['address']); ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <button type="button" id="addCustomerBtn" class="action-button">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="error-message" id="customer-error"></div>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="payment_due_date">Payment Due Date: <span class="required">*</span></label>
                        <input type="date" id="payment_due_date" name="payment_due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        <div class="error-message" id="due-date-error"></div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label>Customer Details:</label>
                        <div id="customer_details" class="info-box">
                            <p class="empty-message">Select a customer to see contact information</p>
                        </div>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="notes">Notes:</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Add any special instructions or notes for this sale"></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Products</h3>
            <div class="product-selection">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="product_id">Product: <span class="required">*</span></label>
                            <select id="product_id">
                                <option value="">Select Product</option>
                                <?php if(count($products) > 0): ?>
                                    <?php foreach($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                            data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                                            data-stock="<?php echo $product['quantity']; ?>"
                                            data-price="<?php echo number_format($product['avg_price'] ? $product['avg_price'] : 0, 2); ?>"
                                            data-description="<?php echo htmlspecialchars($product['description']); ?>">
                                        <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ') - Stock: ' . $product['quantity']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <div class="error-message" id="product-error"></div>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="product_quantity">Quantity: <span class="required">*</span></label>
                            <input type="number" id="product_quantity" min="1" value="1">
                            <div class="error-message" id="quantity-error"></div>
                            <small id="stock_info" class="form-text"></small>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="product_price">Unit Price: <span class="required">*</span></label>
                            <input type="number" id="product_price" min="0" step="0.01" value="0">
                            <div class="error-message" id="price-error"></div>
                        </div>
                    </div>
                    <div class="form-col form-col-auto">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" id="add_product" class="button">
                                <i class="fas fa-plus"></i> Add Product
                            </button>
                        </div>
                    </div>
                </div>
                <div id="product_preview" class="product-preview"></div>
            </div>
            
            <div class="product-list">
                <table class="data-table" id="products_table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="no_products_row">
                            <td colspan="6" class="no-records">No products added to this sale yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="form-section">
            <h3>Sale Totals</h3>
            <div class="totals-container">
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="total_amount">Subtotal:</label>
                            <input type="number" id="total_amount" name="total_amount" value="0.00" readonly>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="discount_amount">Discount Amount:</label>
                            <input type="number" id="discount_amount" name="discount_amount" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="tax_amount">Tax Amount:</label>
                            <input type="number" id="tax_amount" name="tax_amount" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="shipping_cost">Shipping Cost:</label>
                            <input type="number" id="shipping_cost" name="shipping_cost" min="0" step="0.01" value="0.00">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group total-field">
                            <label for="net_amount">Total Amount:</label>
                            <input type="number" id="net_amount" name="net_amount" value="0.00" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add this hidden field to the form in add-sale.php -->
        <input type="hidden" id="invoice_number" name="invoice_number" value="<?php echo $invoice_number; ?>">
        <!-- Hidden product items container -->
        <div id="product_items_container"></div>
        
        <div class="form-actions">
            <a href="sales.php" class="button secondary">Cancel</a>
            <button type="submit" class="button" id="createSaleBtn">Create Sale</button>
        </div>
    </form>
</div>

<!-- Add Customer Modal -->
<div id="customerModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Add New Customer</h2>
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
                <label for="contact_person">Contact Person:</label>
                <input type="text" id="contact_person" name="contact_person">
            </div>
            
            <div class="form-group">
                <label for="address">Address:</label>
                <textarea id="address" name="address" rows="3"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelCustomer">Cancel</button>
                <button type="submit" class="button" id="saveCustomerBtn">Save & Select</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

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

/* Enhanced styles for the sale form */
.wide-form {
    max-width: 1200px;
}

.form-section {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background-color: #fff;
    border-radius: var(--border-radius-md, 8px);
    box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,0.1));
    transition: box-shadow 0.2s;
}

.form-section:hover {
    box-shadow: var(--shadow-md, 0 4px 6px rgba(0,0,0,0.1));
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border, #e0e0e0);
    color: var(--primary, #1a73e8);
    display: flex;
    align-items: center;
}

.form-section h3::before {
    content: '';
    display: inline-block;
    width: 4px;
    height: 18px;
    background-color: var(--primary, #1a73e8);
    margin-right: 0.5rem;
    border-radius: 2px;
}

.product-selection {
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px dashed var(--border, #e0e0e0);
}

.info-box {
    padding: 0.75rem;
    background-color: var(--surface, #f5f5f5);
    border-radius: var(--border-radius-sm, 4px);
    border: 1px solid var(--border, #e0e0e0);
    min-height: 100px;
}

.info-box .empty-message {
    color: var(--text-secondary, #6c757d);
    font-style: italic;
}

.customer-info-item {
    margin-bottom: 0.5rem;
}

.customer-info-item:last-child {
    margin-bottom: 0;
}

.customer-info-label {
    font-weight: 500;
    color: var(--text-secondary, #6c757d);
}

.total-field {
    font-weight: bold;
}

.total-field input {
    font-size: 1.2rem;
    font-weight: bold;
    color: var(--primary, #1a73e8);
    background-color: rgba(26, 115, 232, 0.05);
}

.form-col-auto {
    flex: 0 0 auto;
}

.breadcrumb {
    margin-bottom: 1.5rem;
    font-size: var(--font-size-sm, 0.875rem);
    display: flex;
    align-items: center;
}

.breadcrumb a {
    color: var(--primary, #1a73e8);
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.breadcrumb::after {
    content: '\f054';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    margin: 0 0.5rem;
    font-size: 0.75rem;
    color: var(--text-secondary, #6c757d);
}

.no-records {
    text-align: center;
    padding: 1.5rem;
    color: var(--text-secondary, #6c757d);
    font-style: italic;
}

/* Product preview */
.product-preview {
    margin-top: 1rem;
    padding: 1rem;
    background-color: var(--surface, #f5f5f5);
    border-radius: var(--border-radius-sm, 4px);
    border: 1px dashed var(--border, #e0e0e0);
    display: none;
}

.product-preview-content {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.product-preview-image {
    width: 80px;
    height: 80px;
    background-color: #e9ecef;
    border-radius: var(--border-radius-sm, 4px);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary, #6c757d);
    font-size: 1.5rem;
}

.product-preview-details {
    flex: 1;
    min-width: 200px;
}

.product-preview-name {
    font-weight: 500;
    margin-bottom: 0.25rem;
}

.product-preview-sku {
    color: var(--text-secondary, #6c757d);
    font-size: var(--font-size-sm, 0.875rem);
    margin-bottom: 0.5rem;
}

.product-preview-description {
    font-size: var(--font-size-sm, 0.875rem);
    color: var(--text-secondary, #6c757d);
}

.product-preview-stock {
    margin-top: 0.5rem;
    font-size: var(--font-size-sm, 0.875rem);
}

.stock-available {
    color: #34a853;
}

.stock-low {
    color: #fbbc04;
}

.stock-critical {
    color: #ea4335;
}

/* Form validation */
.required {
    color: var(--error, #ea4335);
}

.error-message {
    color: var(--error, #ea4335);
    font-size: var(--font-size-sm, 0.875rem);
    margin-top: 0.25rem;
    display: none;
}

.invalid-input {
    border-color: var(--error, #ea4335) !important;
    background-color: rgba(234, 67, 53, 0.05);
}

/* Select with action button */
.select-with-action {
    display: flex;
    gap: 0.5rem;
}

.select-with-action select {
    flex: 1;
}

.action-button {
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--primary, #1a73e8);
    color: white;
    border: none;
    border-radius: var(--border-radius-sm, 4px);
    cursor: pointer;
    transition: background-color 0.2s;
}

.action-button:hover {
    background-color: var(--primary-dark, #1565c0);
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .form-row {
        flex-direction: column;
    }
    
    .form-col {
        width: 100%;
        margin-bottom: 1rem;
    }
    
    .form-col:last-child {
        margin-bottom: 0;
    }
}

@media (max-width: 768px) {
    .product-selection .form-row {
        flex-direction: column;
    }
    
    .product-selection .form-col {
        width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .form-actions .button {
        width: 100%;
    }
    
    .product-preview-content {
        flex-direction: column;
    }
    
    .data-table th:nth-child(2),
    .data-table td:nth-child(2) {
        display: none;
    }
}

/* Accessibility enhancements */
.button:focus,
input:focus,
select:focus,
textarea:focus {
    outline: 2px solid var(--primary, #1a73e8);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .alert {
        animation: none;
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

    // Initialize variables
    const productItems = [];
    let productItemCounter = 0;
    
    // DOM elements
    const productIdSelect = document.getElementById('product_id');
    const productQuantityInput = document.getElementById('product_quantity');
    const productPriceInput = document.getElementById('product_price');
    const addProductButton = document.getElementById('add_product');
    const productsTable = document.getElementById('products_table');
    const noProductsRow = document.getElementById('no_products_row');
    const productItemsContainer = document.getElementById('product_items_container');
    const customerSelect = document.getElementById('customer_id');
    const customerDetails = document.getElementById('customer_details');
    const productPreview = document.getElementById('product_preview');
    const stockInfo = document.getElementById('stock_info');
    const createSaleBtn = document.getElementById('createSaleBtn');
    
    // Total fields
    const totalAmountInput = document.getElementById('total_amount');
    const discountAmountInput = document.getElementById('discount_amount');
    const taxAmountInput = document.getElementById('tax_amount');
    const shippingCostInput = document.getElementById('shipping_cost');
    const netAmountInput = document.getElementById('net_amount');
    
    // Add event listeners to inputs that affect totals
    [discountAmountInput, taxAmountInput, shippingCostInput].forEach(input => {
        input.addEventListener('input', calculateTotals);
    });
    
    // Product select change event
    productIdSelect.addEventListener('change', function() {
        if (this.value) {
            const selectedOption = this.options[this.selectedIndex];
            const productName = selectedOption.getAttribute('data-name');
            const productSku = selectedOption.getAttribute('data-sku');
            const productStock = parseInt(selectedOption.getAttribute('data-stock'));
            const productPrice = parseFloat(selectedOption.getAttribute('data-price'));
            const productDescription = selectedOption.getAttribute('data-description');
            
            // Update price field with suggested price
            productPriceInput.value = productPrice || 0;
            
            // Update stock info
            updateStockInfo(productStock);
            
            // Show product preview
            showProductPreview(productName, productSku, productDescription, productStock);
        } else {
            // Clear preview
            productPreview.style.display = 'none';
            stockInfo.textContent = '';
        }
    });
    
    // Quantity input change event
    productQuantityInput.addEventListener('input', function() {
        if (productIdSelect.value) {
            const selectedOption = productIdSelect.options[productIdSelect.selectedIndex];
            const productStock = parseInt(selectedOption.getAttribute('data-stock'));
            
            // Update stock info
            updateStockInfo(productStock);
        }
    });
    
    // Customer select change event
    customerSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (this.value) {
            const contactPerson = selectedOption.getAttribute('data-contact') || 'N/A';
            const phone = selectedOption.getAttribute('data-phone') || 'N/A';
            const email = selectedOption.getAttribute('data-email') || 'N/A';
            const address = selectedOption.getAttribute('data-address') || 'N/A';
            
            customerDetails.innerHTML = `
                <div class="customer-info-item">
                    <span class="customer-info-label">Contact Person:</span> ${contactPerson}
                </div>
                <div class="customer-info-item">
                    <span class="customer-info-label">Phone:</span> ${phone}
                </div>
                <div class="customer-info-item">
                    <span class="customer-info-label">Email:</span> ${email}
                </div>
                <div class="customer-info-item">
                    <span class="customer-info-label">Address:</span> ${address}
                </div>
            `;
        } else {
            customerDetails.innerHTML = '<p class="empty-message">Select a customer to see contact information</p>';
        }
    });
    
    // Helper function to update stock info
    function updateStockInfo(availableStock) {
        const quantity = parseInt(productQuantityInput.value) || 0;
        
        if (quantity > availableStock) {
            stockInfo.textContent = `Warning: Only ${availableStock} units available in stock`;
            stockInfo.className = 'form-text stock-critical';
        } else if (quantity > availableStock * 0.8) {
            stockInfo.textContent = `Limited stock: ${availableStock} units available`;
            stockInfo.className = 'form-text stock-low';
        } else {
            stockInfo.textContent = `In stock: ${availableStock} units available`;
            stockInfo.className = 'form-text stock-available';
        }
    }
    
    // Helper function to show product preview
    function showProductPreview(name, sku, description, stock) {
        productPreview.innerHTML = `
            <div class="product-preview-content">
                <div class="product-preview-image">
                    <i class="fas fa-box"></i>
                </div>
                <div class="product-preview-details">
                    <div class="product-preview-name">${name}</div>
                    <div class="product-preview-sku">SKU: ${sku}</div>
                    <div class="product-preview-description">${description || 'No description available'}</div>
                    <div class="product-preview-stock ${stock < 10 ? 'stock-low' : 'stock-available'}">
                        <i class="fas ${stock < 10 ? 'fa-exclamation-triangle' : 'fa-check-circle'}"></i>
                        ${stock} units in stock
                    </div>
                </div>
            </div>
        `;
        productPreview.style.display = 'block';
    }
    
    // Add product button click event
    addProductButton.addEventListener('click', function() {
        // Reset validation errors
        resetValidationErrors();
        
        // Validate inputs
        let isValid = true;
        
        if (!productIdSelect.value) {
            showFieldError(productIdSelect, document.getElementById('product-error'), 'Please select a product');
            isValid = false;
        }
        
        const quantity = parseInt(productQuantityInput.value);
        if (isNaN(quantity) || quantity <= 0) {
            showFieldError(productQuantityInput, document.getElementById('quantity-error'), 'Please enter a valid quantity');
            isValid = false;
        }
        
        const price = parseFloat(productPriceInput.value);
        if (isNaN(price) || price <= 0) {
            showFieldError(productPriceInput, document.getElementById('price-error'), 'Please enter a valid price');
            isValid = false;
        }
        
        if (!isValid) {
            return;
        }
        
        // Get selected product details
        const selectedOption = productIdSelect.options[productIdSelect.selectedIndex];
        const productId = productIdSelect.value;
        const productName = selectedOption.getAttribute('data-name');
        const productSku = selectedOption.getAttribute('data-sku');
        const availableStock = parseInt(selectedOption.getAttribute('data-stock'));
        
        // Check stock availability
        if (quantity > availableStock) {
            showFieldError(productQuantityInput, document.getElementById('quantity-error'), `Only ${availableStock} units available in stock`);
            return;
        }
        
        // Check if product already exists in the list
        const existingProductIndex = productItems.findIndex(item => item.product_id === productId);
        
        if (existingProductIndex !== -1) {
            // Update existing product
            const existingItem = productItems[existingProductIndex];
            const newQuantity = existingItem.quantity + quantity;
            
            if (newQuantity > availableStock) {
                showFieldError(productQuantityInput, document.getElementById('quantity-error'), `Cannot add more. Total would exceed available stock (${availableStock})`);
                return;
            }
            
            existingItem.quantity = newQuantity;
            existingItem.price = price; // Update price in case it changed
            existingItem.total = (newQuantity * price).toFixed(2);
            
            // Update the table row
            const rowId = `product_row_${existingItem.id}`;
            const row = document.getElementById(rowId);
            
            if (row) {
                const cells = row.getElementsByTagName('td');
                cells[2].textContent = newQuantity;
                cells[3].textContent = formatCurrency(price);
                cells[4].textContent = formatCurrency(existingItem.total);
            }
            
            // Show success message
            showToast(`Updated ${productName} quantity to ${newQuantity}`);
        } else {
            // Add new product
            const newItem = {
                id: productItemCounter++,
                product_id: productId,
                name: productName,
                sku: productSku,
                quantity: quantity,
                price: price,
                total: (quantity * price).toFixed(2)
            };
            
            productItems.push(newItem);
            
            // Hide "no products" row if visible
            if (noProductsRow.style.display !== 'none') {
                noProductsRow.style.display = 'none';
            }
            
            // Add row to table
            const newRow = document.createElement('tr');
            newRow.id = `product_row_${newItem.id}`;
            
            newRow.innerHTML = `
                <td>${escapeHtml(newItem.name)}</td>
                <td>${escapeHtml(newItem.sku)}</td>
                <td>${newItem.quantity}</td>
                <td>${formatCurrency(newItem.price)}</td>
                <td>${formatCurrency(newItem.total)}</td>
                <td>
                    <div class="action-buttons">
                        <button type="button" class="button small edit-product" data-id="${newItem.id}">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="button small remove-product" data-id="${newItem.id}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                                </td>
            </tr>
            `;
            
            productsTable.querySelector('tbody').appendChild(newRow);
            
            // Add event listeners to buttons
            newRow.querySelector('.remove-product').addEventListener('click', function() {
                removeProduct(this.getAttribute('data-id'));
            });
            
            newRow.querySelector('.edit-product').addEventListener('click', function() {
                editProduct(this.getAttribute('data-id'));
            });
            
            // Show success message
            showToast(`Added ${productName} to the sale`);
        }
        
        // Reset product selection fields
        productIdSelect.value = '';
        productQuantityInput.value = '1';
        productPriceInput.value = '0';
        productPreview.style.display = 'none';
        stockInfo.textContent = '';
        
        // Update hidden inputs and calculate totals
        updateHiddenInputs();
        calculateTotals();
    });
    
    // Edit product function
    function editProduct(id) {
        const index = productItems.findIndex(item => item.id == id);
        
        if (index !== -1) {
            const item = productItems[index];
            
            // Find the product in the dropdown
            let productOption = null;
            for (let i = 0; i < productIdSelect.options.length; i++) {
                if (productIdSelect.options[i].value === item.product_id) {
                    productOption = productIdSelect.options[i];
                    break;
                }
            }
            
            if (productOption) {
                // Set values in the form
                productIdSelect.value = item.product_id;
                productQuantityInput.value = item.quantity;
                productPriceInput.value = item.price;
                
                // Trigger change event to update preview
                const event = new Event('change');
                productIdSelect.dispatchEvent(event);
                
                // Scroll to product selection
                document.querySelector('.product-selection').scrollIntoView({ behavior: 'smooth' });
                
                // Remove the product from the list
                removeProduct(id);
                
                // Focus on quantity input
                setTimeout(() => {
                    productQuantityInput.focus();
                    productQuantityInput.select();
                }, 300);
            }
        }
    }
    
    // Remove product function
    function removeProduct(id) {
        const index = productItems.findIndex(item => item.id == id);
        
        if (index !== -1) {
            const productName = productItems[index].name;
            productItems.splice(index, 1);
            
            // Remove row from table
            const row = document.getElementById(`product_row_${id}`);
            if (row) {
                // Add fadeout animation
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                
                setTimeout(() => {
                    row.remove();
                    
                    // Show "no products" row if no products left
                    if (productItems.length === 0) {
                        noProductsRow.style.display = '';
                    }
                }, 300);
            }
            
            // Update hidden inputs and calculate totals
            updateHiddenInputs();
            calculateTotals();
            
            // Show toast message
            showToast(`Removed ${productName} from the sale`);
        }
    }
    
    // Update hidden inputs for form submission
    function updateHiddenInputs() {
        // Clear existing hidden inputs
        productItemsContainer.innerHTML = '';
        
        // Create hidden inputs for each product
        productItems.forEach((item, index) => {
            productItemsContainer.innerHTML += `
                <input type="hidden" name="product_items[${index}][product_id]" value="${item.product_id}">
                <input type="hidden" name="product_items[${index}][quantity]" value="${item.quantity}">
                <input type="hidden" name="product_items[${index}][unit_price]" value="${item.price}">
            `;
        });
    }
    
    // Calculate totals based on products and adjustments
    function calculateTotals() {
        // Calculate subtotal from product items
        const subtotal = productItems.reduce((sum, item) => sum + parseFloat(item.total), 0);
        
        // Get other values
        const discount = parseFloat(discountAmountInput.value) || 0;
        const tax = parseFloat(taxAmountInput.value) || 0;
        const shipping = parseFloat(shippingCostInput.value) || 0;
        
        // Calculate net amount
        const netAmount = subtotal - discount + tax + shipping;
        
        // Update display
        totalAmountInput.value = subtotal.toFixed(2);
        netAmountInput.value = netAmount.toFixed(2);
    }
    
    // Format currency helper
    function formatCurrency(value) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(value);
    }
    
    // HTML escape helper
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Form validation and submission
    const saleForm = document.getElementById('saleForm');
    
    if (saleForm) {
// Replace or update the existing form submission code in add-sale.php
saleForm.addEventListener('submit', function(event) {
    event.preventDefault();
    
    // Reset validation errors
    resetValidationErrors();
    
    // Validate form
    let isValid = true;
    
    // Validate customer
    if (!customerSelect.value) {
        showFieldError(customerSelect, document.getElementById('customer-error'), 'Please select a customer');
        isValid = false;
    }
    
    // Validate sale date
    const saleDateInput = document.getElementById('sale_date');
    if (!saleDateInput.value) {
        showFieldError(saleDateInput, document.getElementById('sale-date-error'), 'Please select a sale date');
        isValid = false;
    }
    
    // Validate due date
    const dueDateInput = document.getElementById('payment_due_date');
    if (!dueDateInput.value) {
        showFieldError(dueDateInput, document.getElementById('due-date-error'), 'Please select a payment due date');
        isValid = false;
    }
    
    // Validate products
    if (productItems.length === 0) {
        showToast('Please add at least one product to the sale', 'error');
        isValid = false;
    }
    
    // If validation passes, submit the form
    if (isValid) {
        // Show loading state
        const originalBtnText = createSaleBtn.innerHTML;
        createSaleBtn.disabled = true;
        createSaleBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Sale...';
        
        // Ensure all data is included
        updateHiddenInputs();
        
        // Log what we're submitting for debugging
        console.log('Submitting form with data:', new FormData(saleForm));
        
        // Submit form via AJAX
        fetch(saleForm.action, {
            method: 'POST',
            body: new FormData(saleForm)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Log activity
                logUserActivity('create', 'sales', `Created new sale invoice: ${document.getElementById('invoice_number').value}`);
                
                // Show success message
                showToast('Sale created successfully!', 'success');
                
                // Redirect to view the sale
                setTimeout(() => {
                    window.location.href = `view-sale.php?id=${data.sale_id}&success=1`;
                }, 1000);
            } else {
                // Show error message
                showToast(data.message || 'An error occurred while creating the sale', 'error');
                
                // Reset button state
                createSaleBtn.disabled = false;
                createSaleBtn.innerHTML = originalBtnText;
            }
        })
        .catch(error => {
            // Show error message
            showToast('An error occurred: ' + error.message, 'error');
            
            // Reset button state
            createSaleBtn.disabled = false;
            createSaleBtn.innerHTML = originalBtnText;
        });
    } else {
        // Focus the first invalid field
        const firstInvalid = document.querySelector('.invalid-input');
        if (firstInvalid) {
            firstInvalid.focus();
        }
    }
});
}
    
    // Add Customer Modal functionality
    const customerModal = document.getElementById('customerModal');
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    const closeModalBtn = customerModal.querySelector('.close-modal');
    const cancelCustomerBtn = document.getElementById('cancelCustomer');
    const customerForm = document.getElementById('customerForm');
    const saveCustomerBtn = document.getElementById('saveCustomerBtn');
    
    // Open customer modal
    if (addCustomerBtn) {
        addCustomerBtn.addEventListener('click', function() {
            // Reset form
            customerForm.reset();
            
            // Reset validation errors
            const errorMessages = customerForm.querySelectorAll('.error-message');
            errorMessages.forEach(error => {
                error.style.display = 'none';
                error.textContent = '';
            });
            
            // Show modal
            customerModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Focus first field
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);
        });
    }
    
    // Close modal functions
    function closeCustomerModal() {
        customerModal.style.display = 'none';
        document.body.style.overflow = '';
    }
    
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', closeCustomerModal);
    }
    
    if (cancelCustomerBtn) {
        cancelCustomerBtn.addEventListener('click', closeCustomerModal);
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === customerModal) {
            closeCustomerModal();
        }
    });
    
    // Customer form submission
    if (customerForm) {
        customerForm.addEventListener('submit', function(event) {
            event.preventDefault();
            
            // Validate form
            let isValid = true;
            
            // Validate name
            const nameInput = document.getElementById('name');
            const nameError = document.getElementById('name-error');
            if (!nameInput.value.trim()) {
                showFieldError(nameInput, nameError, 'Customer name is required');
                isValid = false;
            }
            
            // Validate email if provided
            const emailInput = document.getElementById('email');
            const emailError = document.getElementById('email-error');
            if (emailInput.value.trim() && !isValidEmail(emailInput.value.trim())) {
                showFieldError(emailInput, emailError, 'Please enter a valid email address');
                isValid = false;
            }
            
            // Validate phone
            const phoneInput = document.getElementById('phone');
            const phoneError = document.getElementById('phone-error');
            if (!phoneInput.value.trim()) {
                showFieldError(phoneInput, phoneError, 'Phone number is required');
                isValid = false;
            }
            
            if (isValid) {
                // Show loading state
                const originalBtnText = saveCustomerBtn.innerHTML;
                saveCustomerBtn.disabled = true;
                saveCustomerBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                
                // Submit form via AJAX
                fetch(customerForm.action, {
                    method: 'POST',
                    body: new FormData(customerForm)
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Close modal
                        closeCustomerModal();
                        
                        // Show success message
                        showToast('Customer added successfully!', 'success');
                        
                        // Add new customer to dropdown and select it
                        const newOption = document.createElement('option');
                        newOption.value = data.customer_id;
                        newOption.text = nameInput.value;
                        newOption.setAttribute('data-contact', document.getElementById('contact_person').value || '');
                        newOption.setAttribute('data-phone', phoneInput.value);
                        newOption.setAttribute('data-email', emailInput.value || '');
                        newOption.setAttribute('data-address', document.getElementById('address').value || '');
                        
                        // Add to dropdown and select
                        customerSelect.appendChild(newOption);
                        customerSelect.value = data.customer_id;
                        
                        // Trigger change event
                        const event = new Event('change');
                        customerSelect.dispatchEvent(event);
                    } else {
                        // Show error message
                        showToast(data.message || 'An error occurred while saving the customer', 'error');
                    }
                })
                .catch(error => {
                    // Show error message
                    showToast('An error occurred: ' + error.message, 'error');
                })
                .finally(() => {
                    // Reset button state
                    saveCustomerBtn.disabled = false;
                    saveCustomerBtn.innerHTML = originalBtnText;
                });
            }
        });
    }
    
    // Validation helper functions
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
    
    function showFieldError(inputElement, errorElement, message) {
        inputElement.classList.add('invalid-input');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    // Toast notification system
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
        
        // Set icon based on type
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas fa-${icon}"></i></div>
            <div class="toast-content">${message}</div>
            <button class="toast-close"><i class="fas fa-times"></i></button>
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
    }
});

// Add this debugging code to help troubleshoot form submission issues
function debugFormSubmission() {
    console.log('Form action:', document.getElementById('saleForm').action);
    console.log('Product items:', productItems);
    
    // Check if invoice number is set
    const invoiceNumberField = document.getElementById('invoice_number');
    if (invoiceNumberField) {
        console.log('Invoice number:', invoiceNumberField.value);
    } else {
        console.error('Invoice number field is missing!');
    }
    
    // Check other required fields
    console.log('Customer:', document.getElementById('customer_id').value);
    console.log('Sale date:', document.getElementById('sale_date').value);
    console.log('Payment due date:', document.getElementById('payment_due_date').value);
    console.log('Total amount:', document.getElementById('total_amount').value);
    console.log('Net amount:', document.getElementById('net_amount').value);
}

// Call this function when you click the submit button
document.getElementById('createSaleBtn').addEventListener('click', function() {
    debugFormSubmission();
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

<!-- Add toast notification styles -->
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