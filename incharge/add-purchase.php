<?php
session_start();
$page_title = "Add Material Purchase";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if material_id is provided for pre-selection
$material_id = isset($_GET['material_id']) ? intval($_GET['material_id']) : 0;

// Get active funds for the user
try {
    $funds_query = "SELECT id, amount, balance, type 
                   FROM funds 
                   WHERE to_user_id = ? AND status = 'active' 
                   ORDER BY balance DESC";
    $funds_stmt = $db->prepare($funds_query);
    $funds_stmt->execute([$_SESSION['user_id']]);
    $available_funds = $funds_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total available balance
    $total_available = 0;
    foreach ($available_funds as $fund) {
        $total_available += $fund['balance'];
    }
} catch (PDOException $e) {
    error_log("Error in funds query: " . $e->getMessage());
    $available_funds = [];
    $total_available = 0;
}

// Get all raw materials
try {
    $materials_query = "SELECT id, name, unit, stock_quantity, min_stock_level FROM raw_materials ORDER BY name";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->execute();
    $materials = $materials_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error in materials query: " . $e->getMessage());
    $materials = [];
}

// Get pre-selected material details if provided
$selected_material = null;
if($material_id) {
    foreach($materials as $material) {
        if($material['id'] == $material_id) {
            $selected_material = $material;
            break;
        }
    }
}

// Get vendors for autocomplete
try {
    $vendors_query = "SELECT DISTINCT vendor_name FROM purchases ORDER BY vendor_name";
    $vendors_stmt = $db->prepare($vendors_query);
    $vendors_stmt->execute();
    $vendors = $vendors_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error in vendors query: " . $e->getMessage());
    $vendors = [];
}

// Check for error message from redirect
$error_message = isset($_SESSION['error']) ? $_SESSION['error'] : '';
unset($_SESSION['error']);
?>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<!-- Loading Indicator -->
<div id="loadingIndicator" class="loading-indicator" style="display: none;">
    <div class="spinner"></div>
    <p>Processing your request...</p>
</div>

<div class="breadcrumb" aria-label="breadcrumb">
    <a href="purchases.php">Purchases</a> &raquo; Add New Purchase
</div>

<div class="page-header">
    <h1 class="page-title">Add Material Purchase</h1>
</div>

<?php if(!empty($error_message)): ?>
<div class="alert alert-error" role="alert">
    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button type="button" class="close-alert" aria-label="Close alert">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Purchase Information</h2>
        <div class="funds-summary">
            <span>Available Funds: <strong><?php echo formatCurrency($total_available); ?></strong></span>
        </div>
    </div>
    <div class="card-content">
        <form id="purchaseForm" action="../api/save-purchase.php" method="post" novalidate>
            <div class="form-grid">
                <!-- Material Selection Section -->
                <div class="form-section">
                    <h3>Material Information</h3>
                    
                    <div class="form-group">
                        <label for="material_id" class="required">Material:</label>
                        <select id="material_id" name="material_id" required aria-required="true">
                            <option value="">Select Material</option>
                            <?php foreach($materials as $material): ?>
                                <?php 
                                    $stock_status = '';
                                    if ($material['stock_quantity'] <= 0) {
                                        $stock_status = ' (Out of Stock)';
                                    } elseif ($material['stock_quantity'] < ($material['min_stock_level'] ?: 10)) {
                                        $stock_status = ' (Low Stock)';
                                    }
                                ?>
                                <option value="<?php echo $material['id']; ?>" 
                                        data-unit="<?php echo htmlspecialchars($material['unit']); ?>"
                                        data-stock="<?php echo $material['stock_quantity']; ?>"
                                        <?php echo ($material_id == $material['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($material['name']); ?> 
                                    (<?php echo htmlspecialchars($material['unit']); ?>) - 
                                    Current Stock: <?php echo number_format($material['stock_quantity'], 2); ?>
                                    <?php echo $stock_status; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="material_id-error"></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity" class="required">Quantity:</label>
                            <div class="input-with-unit">
                                <input type="number" id="quantity" name="quantity" step="0.01" min="0.01" required aria-required="true">
                                <span id="unit-display" class="unit-display"><?php echo $selected_material ? htmlspecialchars($selected_material['unit']) : ''; ?></span>
                            </div>
                            <div class="error-message" id="quantity-error"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="unit_price" class="required">Unit Price:</label>
                            <div class="input-with-unit">
                                <span class="currency-prefix">Rs.</span>
                                <input type="number" id="unit_price" name="unit_price" step="0.01" min="0.01" required aria-required="true">
                            </div>
                            <div class="error-message" id="unit_price-error"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="total_amount">Total Amount:</label>
                        <div class="input-with-unit">
                            <span class="currency-prefix">Rs.</span>
                            <input type="number" id="total_amount" name="total_amount" step="0.01" min="0.01" readonly>
                        </div>
                        <div class="form-hint">Calculated automatically from quantity and unit price</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="fund_id" class="required">Fund Source:</label>
                        <select id="fund_id" name="fund_id" required aria-required="true">
                            <option value="">Select Fund</option>
                            <?php foreach($available_funds as $fund): ?>
                            <option value="<?php echo $fund['id']; ?>" data-balance="<?php echo $fund['balance']; ?>">
                                Fund #<?php echo $fund['id']; ?> - Balance: <?php echo formatCurrency($fund['balance']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message" id="fund_id-error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="purchase_date" class="required">Purchase Date:</label>
                        <input type="date" id="purchase_date" name="purchase_date" value="<?php echo date('Y-m-d'); ?>" required aria-required="true">
                        <div class="error-message" id="purchase_date-error"></div>
                    </div>
                </div>
                
                <!-- Vendor Information Section -->
                <div class="form-section">
                    <h3>Vendor Information</h3>
                    
                    <div class="form-group">
                        <label for="vendor_name" class="required">Vendor Name:</label>
                        <input type="text" id="vendor_name" name="vendor_name" list="vendors-list" required aria-required="true">
                        <datalist id="vendors-list">
                            <?php foreach($vendors as $vendor): ?>
                            <option value="<?php echo htmlspecialchars($vendor); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="error-message" id="vendor_name-error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="vendor_contact">Vendor Contact:</label>
                        <input type="text" id="vendor_contact" name="vendor_contact">
                    </div>
                    
                    <div class="form-group">
                        <label for="invoice_number">Invoice Number:</label>
                        <input type="text" id="invoice_number" name="invoice_number">
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes:</label>
                        <textarea id="notes" name="notes" rows="3" placeholder="Add any additional notes about this purchase"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="purchases.php" class="button secondary">Cancel</a>
                <button type="submit" class="button primary" id="savePurchaseBtn">
                    <i class="fas fa-save" aria-hidden="true"></i> Save Purchase
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
    
    /* Add Purchase Page Styles */
@import 'variables.css';
@import 'components.css';

/* Breadcrumb */
.breadcrumb {
  margin-bottom: var(--space-3);
  font-size: var(--font-size-sm);
  color: var(--text-secondary);
}

.breadcrumb a {
  color: var(--primary);
  text-decoration: none;
  transition: var(--transition-normal);
}

.breadcrumb a:hover {
  text-decoration: underline;
  color: var(--primary-dark);
}

/* Form Grid Layout */
.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
  gap: var(--space-4);
  margin-bottom: var(--space-4);
}

.form-section {
  display: flex;
  flex-direction: column;
}

.form-section h3 {
  margin-top: 0;
  margin-bottom: var(--space-3);
  font-size: var(--font-size-lg);
  color: var(--primary);
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--border);
}

/* Input with Unit/Currency */
.input-with-unit {
  display: flex;
  align-items: center;
  position: relative;
}

.unit-display {
  padding: var(--space-2) var(--space-3);
  background-color: var(--background);
  border: 1px solid var(--border);
  border-left: none;
  border-top-right-radius: var(--radius-sm);
  border-bottom-right-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: var(--font-size-sm);
  white-space: nowrap;
}

.input-with-unit input {
  flex: 1;
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}

.currency-prefix {
  padding: var(--space-2) var(--space-3);
  background-color: var(--background);
  border: 1px solid var(--border);
  border-right: none;
  border-top-left-radius: var(--radius-sm);
  border-bottom-left-radius: var(--radius-sm);
  color: var(--text-secondary);
  font-size: var(--font-size-sm);
}

.input-with-unit input:focus + .unit-display,
.currency-prefix + input:focus {
  border-color: var(--primary);
}

/* Funds Summary */
.funds-summary {
  display: flex;
  align-items: center;
  font-size: var(--font-size-sm);
  color: var(--text-secondary);
}

.funds-summary strong {
  color: var(--primary);
  font-weight: var(--font-weight-semibold);
}

/* Form Validation Styles */
input.invalid,
select.invalid,
textarea.invalid {
  border-color: var(--danger) !important;
}

.error-message {
  color: var(--danger);
  font-size: var(--font-size-xs);
  margin-top: var(--space-1);
  min-height: 1.2em;
}

/* Responsive Adjustments */
@media (max-width: 992px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-3);
  }
  
  .card-header {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-2);
  }
  
  .form-row {
    flex-direction: column;
    gap: var(--space-3);
  }
  
  .form-actions {
    flex-direction: column;
  }
  
  .form-actions .button {
    width: 100%;
    margin-bottom: var(--space-2);
  }
  
  .form-actions .button:last-child {
    margin-bottom: 0;
  }
}

/* Accessibility Focus Styles */
input:focus,
select:focus,
textarea:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.3);
}

/* Reduced Motion Preferences */
@media (prefers-reduced-motion: reduce) {
  * {
    transition: none !important;
    animation: none !important;
  }
}
</style>
<script src="../assets/js/utils.js"></script>
<script>
    /**
 * Add Purchase JavaScript
 * Handles the purchase form with advanced validation and real-time calculations
 */
document.addEventListener('DOMContentLoaded', function() {
  // Elements
  const materialSelect = document.getElementById('material_id');
  const unitDisplay = document.getElementById('unit-display');
  const quantityInput = document.getElementById('quantity');
  const unitPriceInput = document.getElementById('unit_price');
  const totalAmountInput = document.getElementById('total_amount');
  const fundSelect = document.getElementById('fund_id');
  const purchaseForm = document.getElementById('purchaseForm');
  const savePurchaseBtn = document.getElementById('savePurchaseBtn');
  
  // Initialize event listeners
  initEventListeners();
  
  // Initialize form validation
  initFormValidation();
  
  // Initialize alert close buttons
  initAlertCloseButtons();
  
  /**
   * Initialize all event listeners
   */
  function initEventListeners() {
    // Update unit display when material changes
    if (materialSelect) {
      materialSelect.addEventListener('change', function() {
        updateUnitDisplay();
      });
    }
    
    // Calculate total when quantity or unit price changes
    if (quantityInput) {
      quantityInput.addEventListener('input', calculateTotal);
    }
    
    if (unitPriceInput) {
      unitPriceInput.addEventListener('input', calculateTotal);
    }
    
    // Form submission
    if (purchaseForm) {
      purchaseForm.addEventListener('submit', handleFormSubmit);
    }
  }
  
  /**
   * Initialize form validation
   */
  function initFormValidation() {
    if (!purchaseForm) return;
    
    const requiredFields = purchaseForm.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
      field.addEventListener('input', function() {
        validateField(this);
      });
      
      field.addEventListener('blur', function() {
        validateField(this);
      });
    });
    
    // Special validation for quantity
    if (quantityInput) {
      quantityInput.addEventListener('input', function() {
        validateQuantityField(this);
      });
    }
    
    // Special validation for unit price
    if (unitPriceInput) {
      unitPriceInput.addEventListener('input', function() {
        validatePriceField(this);
      });
    }
    
    // Special validation for fund selection
    if (fundSelect) {
      fundSelect.addEventListener('change', function() {
        validateFundField(this);
      });
    }
  }
  
  /**
   * Initialize close buttons for alerts
   */
  function initAlertCloseButtons() {
    document.querySelectorAll('.close-alert').forEach(button => {
      button.addEventListener('click', function() {
        this.closest('.alert').remove();
      });
    });
  }
  
  /**
   * Update unit display based on selected material
   */
  function updateUnitDisplay() {
    if (!materialSelect || !unitDisplay) return;
    
    const selectedOption = materialSelect.options[materialSelect.selectedIndex];
    if (selectedOption && selectedOption.value) {
      const unit = selectedOption.getAttribute('data-unit');
      unitDisplay.textContent = unit || '';
    } else {
      unitDisplay.textContent = '';
    }
    
    // Recalculate total if needed
    calculateTotal();
  }
  
  /**
   * Calculate total amount based on quantity and unit price
   */
  function calculateTotal() {
    if (!quantityInput || !unitPriceInput || !totalAmountInput) return;
    
    const quantity = parseFloat(quantityInput.value) || 0;
    const unitPrice = parseFloat(unitPriceInput.value) || 0;
    const total = quantity * unitPrice;
    
    totalAmountInput.value = total.toFixed(2);
    
    // Validate fund selection based on new total
    validateFundField(document.getElementById('fund_id'));
  }
  
  /**
   * Validate a standard form field
   * @param {HTMLElement} field - The field to validate
   * @returns {boolean} - Whether the field is valid
   */
  function validateField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = `${field.labels[0]?.textContent.replace(':', '').replace('*', '') || 'Field'} is required`;
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
   * Validate quantity field with special rules
   * @param {HTMLElement} field - The quantity field
   * @returns {boolean} - Whether the field is valid
   */
  function validateQuantityField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    const quantity = parseFloat(field.value);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Quantity is required';
      }
      return false;
    } else if (isNaN(quantity) || quantity <= 0) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Quantity must be greater than zero';
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
   * Validate price field with special rules
   * @param {HTMLElement} field - The price field
   * @returns {boolean} - Whether the field is valid
   */
  function validatePriceField(field) {
    const errorElement = document.getElementById(`${field.id}-error`);
    const price = parseFloat(field.value);
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Price is required';
      }
      return false;
    } else if (isNaN(price) || price <= 0) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Price must be greater than zero';
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
   * Validate fund field with total amount check
   * @param {HTMLElement} field - The fund field
   * @returns {boolean} - Whether the field is valid
   */
  function validateFundField(field) {
    if (!field) return true;
    
    const errorElement = document.getElementById(`${field.id}-error`);
    const totalAmount = parseFloat(totalAmountInput.value) || 0;
    
    if (!field.value.trim()) {
      field.classList.add('invalid');
      if (errorElement) {
        errorElement.textContent = 'Fund source is required';
      }
      return false;
    } else {
      const selectedOption = field.options[field.selectedIndex];
      const fundBalance = parseFloat(selectedOption.getAttribute('data-balance') || 0);
      
      if (totalAmount > fundBalance) {
        field.classList.add('invalid');
        if (errorElement) {
          errorElement.textContent = `Insufficient funds. Available balance: Rs.${fundBalance.toFixed(2)}`;
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
  }
  
   /**
   * Validate the entire form
   * @returns {boolean} - Whether the form is valid
   */
  function validateForm() {
    let isValid = true;
    
    // Validate required fields
    const requiredFields = purchaseForm.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      let fieldValid = true;
      
      // Apply specific validation based on field type
      if (field.id === 'quantity') {
        fieldValid = validateQuantityField(field);
      } else if (field.id === 'unit_price') {
        fieldValid = validatePriceField(field);
      } else if (field.id === 'fund_id') {
        fieldValid = validateFundField(field);
      } else {
        fieldValid = validateField(field);
      }
      
      if (!fieldValid) {
        isValid = false;
        // Focus the first invalid field for better user experience
        if (!document.querySelector(':focus.invalid')) {
          field.focus();
        }
      }
    });
    
    return isValid;
  }
  
  /**
   * Handle form submission with validation and error handling
   * @param {Event} event - The form submission event
   */
  function handleFormSubmit(event) {
    event.preventDefault();
    
    // Validate form
    if (!validateForm()) {
      // Scroll to first error for better UX
      const firstError = document.querySelector('.invalid, .error-message:not(:empty)');
      if (firstError) {
        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      
      // Show toast notification about validation errors
      showToast('Form Validation Error', 'Please correct the highlighted fields before submitting', 'error');
      return;
    }
    
    // Show loading state
    savePurchaseBtn.disabled = true;
    const originalBtnText = savePurchaseBtn.innerHTML;
    savePurchaseBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Processing...';
    
    // Show loading indicator
    showLoading(true);
    
    // Prepare form data
    const formData = new FormData(purchaseForm);
    
    // Send AJAX request
    fetch(purchaseForm.action, {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! Status: ${response.status}`);
      }
      return response.json();
    })
    .then(data => {
      // Hide loading indicator
      showLoading(false);
      
      if (data.success) {
        // Show success message
        showToast('Success', data.message || 'Purchase saved successfully', 'success');
        
        // Log activity
        const materialName = materialSelect.options[materialSelect.selectedIndex].text.split(' (')[0];
        logUserActivity(
          'create',
          'purchases',
          `Purchased ${quantityInput.value} ${unitDisplay.textContent} of ${materialName}`
        );
        
        // Redirect to purchases page after a short delay
        setTimeout(() => {
          window.location.href = 'purchases.php';
        }, 1500);
      } else {
        // Show error message
        showToast('Error', data.message || 'Failed to save purchase', 'error');
        
        // Reset button state
        savePurchaseBtn.disabled = false;
        savePurchaseBtn.innerHTML = originalBtnText;
      }
    })
    .catch(error => {
      // Hide loading indicator
      showLoading(false);
      
      console.error('Error saving purchase:', error);
      showToast('Error', 'An unexpected error occurred. Please try again.', 'error');
      
      // Reset button state
      savePurchaseBtn.disabled = false;
      savePurchaseBtn.innerHTML = originalBtnText;
    });
  }
  
  /**
   * Show or hide the loading indicator
   * @param {boolean} show - Whether to show the loading indicator
   */
  function showLoading(show) {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
      loadingIndicator.style.display = show ? 'flex' : 'none';
      
      // For screen readers
      if (show) {
        const ariaLive = document.createElement('div');
        ariaLive.className = 'sr-only';
        ariaLive.setAttribute('aria-live', 'assertive');
        ariaLive.textContent = 'Processing your request. Please wait.';
        document.body.appendChild(ariaLive);
        
        setTimeout(() => {
          document.body.removeChild(ariaLive);
        }, 1000);
      }
    }
  }
  
  // Initialize the page
  updateUnitDisplay();
  calculateTotal();
});
    
</script>

<?php
// Helper function to format currency
function formatCurrency($amount, $currency = 'Rs.') {
    return $currency . number_format($amount, 2);
}
?>

<?php include_once '../includes/footer.php'; ?>