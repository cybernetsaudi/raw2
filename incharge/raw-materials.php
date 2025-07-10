<!-- incharge/raw-materials.php -->
<?php
session_start();
$page_title = "Raw Materials";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get all raw materials
try {
    $query = "SELECT id, name, description, unit, stock_quantity, min_stock_level, created_at, updated_at 
              FROM raw_materials 
              ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!-- Toast Notification Container -->
<div id="toastContainer" class="toast-container" aria-live="polite"></div>

<div class="page-header">
    <h1 class="page-title">Raw Materials</h1>
    <div class="page-actions">
        <button id="addMaterialBtn" class="button primary">
            <i class="fas fa-plus-circle"></i> Add New Material
        </button>
    </div>
</div>

<?php if (isset($error_message)): ?>
<div class="alert alert-error" role="alert">
    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
    <span><?php echo $error_message; ?></span>
    <button type="button" class="close-alert" aria-label="Close alert">
        <i class="fas fa-times" aria-hidden="true"></i>
    </button>
</div>
<?php endif; ?>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h2>Raw Materials Inventory</h2>
        <div class="card-actions">
            <div class="search-container">
                <input type="text" id="materialSearch" class="search-input" placeholder="Search materials..." aria-label="Search materials">
                <i class="fas fa-search search-icon" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="card-content">
        <div class="table-responsive">
            <table class="data-table" id="materialsTable" aria-label="Raw Materials">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Description</th>
                        <th scope="col">Unit</th>
                        <th scope="col">Stock Quantity</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last Updated</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stmt && $stmt->rowCount() > 0): ?>
                        <?php while($row = $stmt->fetch(PDO::FETCH_ASSOC)): 
                            $stock_status = 'normal';
                            $status_text = 'Normal';
                            
                            if ($row['stock_quantity'] <= 0) {
                                $stock_status = 'out';
                                $status_text = 'Out of Stock';
                            } elseif ($row['stock_quantity'] < ($row['min_stock_level'] ?: 10)) {
                                $stock_status = 'low';
                                $status_text = 'Low Stock';
                            }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['description'] ?: 'No description'); ?></td>
                            <td><?php echo htmlspecialchars($row['unit']); ?></td>
                            <td data-quantity="<?php echo $row['stock_quantity']; ?>">
                                <?php echo number_format($row['stock_quantity'], 2); ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $stock_status; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($row['updated_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="button small edit-material" data-id="<?php echo $row['id']; ?>" aria-label="Edit <?php echo htmlspecialchars($row['name']); ?>">
                                        <i class="fas fa-edit" aria-hidden="true"></i> Edit
                                    </button>
                                    <a href="add-purchase.php?material_id=<?php echo $row['id']; ?>" class="button small success" aria-label="Purchase <?php echo htmlspecialchars($row['name']); ?>">
                                        <i class="fas fa-shopping-cart" aria-hidden="true"></i> Purchase
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-records">No raw materials found. Click "Add New Material" to add one.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Loading Indicator -->
        <div id="loadingIndicator" class="loading-indicator" style="display: none;">
            <div class="spinner"></div>
            <p>Loading...</p>
        </div>
    </div>
</div>

<!-- Add/Edit Material Modal -->
<div id="materialModal" class="modal" role="dialog" aria-labelledby="modalTitle" aria-modal="true" tabindex="-1">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Material</h2>
            <button type="button" class="close-modal" aria-label="Close modal">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="materialForm" action="../api/save-material.php" method="post" novalidate>
                <input type="hidden" id="material_id" name="material_id" value="">
                
                <div class="form-group">
                    <label for="name" class="required">Material Name:</label>
                    <input type="text" id="name" name="name" required aria-required="true">
                    <div class="error-message" id="name-error"></div>
                </div>
                
                <div class="form-group">
                    <label for="description">Description:</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="unit" class="required">Unit:</label>
                        <select id="unit" name="unit" required aria-required="true">
                            <option value="">Select Unit</option>
                            <option value="meter">Meter</option>
                            <option value="kg">Kilogram</option>
                            <option value="piece">Piece</option>
                        </select>
                        <div class="error-message" id="unit-error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="min_stock_level">Minimum Stock Level:</label>
                        <input type="number" id="min_stock_level" name="min_stock_level" step="0.01" min="0" value="10">
                        <div class="form-hint">Alert will show when stock falls below this level</div>
                    </div>
                </div>
                
                <div class="form-group" id="stock-quantity-group">
                    <label for="stock_quantity">Initial Stock Quantity:</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" step="0.01" min="0" value="0">
                    <div class="form-hint">Only applicable when adding a new material</div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="button secondary" id="cancelMaterial">Cancel</button>
                    <button type="submit" class="button primary" id="saveMaterialBtn">
                        <i class="fas fa-save" aria-hidden="true"></i> Save Material
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
    /* Raw Materials Page Styles */
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
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.page-title {
  margin: 0;
  font-size: 1.75rem;
  color: var(--text-primary);
}

.page-actions {
  display: flex;
  gap: 0.75rem;
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

.button.success {
  background-color: var(--success);
  color: white;
}

.button.success:hover, .button.success:focus {
  background-color: var(--success-dark);
}

.button.small {
  padding: 0.25rem 0.5rem;
  font-size: 0.8rem;
}

/* Card Styles */
.dashboard-card {
  background-color: var(--surface);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  margin-bottom: 1.5rem;
}

.dashboard-card.full-width {
  width: 100%;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  background-color: var(--primary-light);
  border-bottom: 1px solid var(--border);
}

.card-header h2 {
  margin: 0;
  font-size: 1.25rem;
  color: var(--primary);
}

.card-actions {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.card-content {
  padding: 1.5rem;
  position: relative;
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

.data-table .action-buttons {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.no-records {
  text-align: center;
  padding: 2rem;
  color: var(--text-secondary);
  font-style: italic;
}

/* Status Badges */
.status-badge {
  display: inline-block;
  padding: 0.25rem 0.5rem;
  border-radius: 50px;
  font-size: 0.75rem;
  font-weight: 500;
  text-align: center;
}

.status-normal {
  background-color: #d1e7dd;
  color: #0f5132;
}

.status-low {
  background-color: #fff3cd;
  color: #856404;
}

.status-out {
  background-color: #f8d7da;
  color: #842029;
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
  max-width: 600px;
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

.form-row {
  display: flex;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.form-row .form-group {
  flex: 1;
  margin-bottom: 0;
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

/* Search Styles */
.search-container {
  position: relative;
  width: 300px;
}

.search-input {
  width: 100%;
  padding: 0.5rem 2.25rem 0.5rem 0.75rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  font-size: 0.9rem;
}

.search-icon {
  position: absolute;
  right: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-secondary);
  pointer-events: none;
}

/* Loading indicator */
.loading-indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  color: var(--text-secondary);
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid rgba(67, 97, 238, 0.2);
  border-radius: 50%;
  border-top-color: var(--primary);
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

/* Alert component */
.alert {
  padding: 1rem;
  border-radius: var(--radius-sm);
  margin-bottom: 1.5rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.alert-error {
  background-color: #f8d7da;
  border-left: 4px solid var(--danger);
  color: #842029;
}

.alert i {
  font-size: 1.25rem;
}

.close-alert {
  margin-left: auto;
  background: none;
  border: none;
  font-size: 1.25rem;
  cursor: pointer;
  color: inherit;
  opacity: 0.7;
}

.close-alert:hover {
  opacity: 1;
}

/* Responsive adjustments */
@media (max-width: 768px) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }

  .form-row {
    flex-direction: column;
    gap: 1.25rem;
  }

  .search-container {
    width: 100%;
  }

  .card-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }

  .card-actions {
    width: 100%;
  }

  .modal-content {
    margin: 0 auto;
  }

  .form-actions {
    flex-direction: column-reverse;
  }

  .form-actions button {
    width: 100%;
  }

  .action-buttons {
    flex-direction: column;
    width: 100%;
  }

  .action-buttons .button {
    width: 100%;
    text-align: center;
  }
}

/* Accessibility focus styles */
button:focus,
a:focus,
input:focus,
select:focus,
textarea:focus,
[tabindex]:focus {
  outline: 3px solid rgba(67, 97, 238, 0.5);
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  * {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
</style>
<script>/**
 * Raw Materials Management JavaScript
 * Handles CRUD operations for raw materials with improved UX and accessibility
 */
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const modal = document.getElementById('materialModal');
    const addBtn = document.getElementById('addMaterialBtn');
    const closeBtn = document.querySelector('.close-modal');
    const cancelBtn = document.getElementById('cancelMaterial');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('materialForm');
    const searchInput = document.getElementById('materialSearch');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const toastContainer = document.getElementById('toastContainer');
    
    // Track last focused element for accessibility
    let lastFocusedElement = null;
    
    // Initialize event listeners
    initEventListeners();
    
    // Initialize material search
    initSearch();
    
    // Initialize close buttons for any alerts
    initAlertCloseButtons();
    
    // Log page view
    logUserActivity('read', 'raw-materials', 'Viewed raw materials page');
    
    /**
     * Initialize all event listeners
     */
    function initEventListeners() {
        // Add new material button
        if (addBtn) {
            addBtn.addEventListener('click', openAddMaterialModal);
        }
        
        // Close modal buttons
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
        
        // Close modal on outside click
        window.addEventListener('click', function(event) {
            if (event.target === modal) closeModal();
        });
        
        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal && modal.style.display === 'block') {
                closeModal();
            }
        });
        
        // Edit material buttons (using event delegation)
        initEditButtons();
        
        // Form submission
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }
        
        // Form input validation
        initFormValidation();
    }
    
    /**
     * Initialize material search functionality
     */
    function initSearch() {
        if (!searchInput) return;
        
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const table = document.getElementById('materialsTable');
            if (!table) return;
            
            const rows = table.querySelectorAll('tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const name = row.cells[0].textContent.toLowerCase();
                const description = row.cells[1].textContent.toLowerCase();
                const unit = row.cells[2].textContent.toLowerCase();
                
                if (name.includes(searchTerm) || description.includes(searchTerm) || unit.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // If no results, show a message
            const noResultsRow = document.getElementById('noResultsRow');
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    const tbody = table.querySelector('tbody');
                    const newRow = document.createElement('tr');
                    newRow.id = 'noResultsRow';
                    newRow.innerHTML = `<td colspan="7" class="no-records">No materials found matching "${this.value}"</td>`;
                    tbody.appendChild(newRow);
                } else {
                    noResultsRow.style.display = '';
                }
            } else if (noResultsRow) {
                noResultsRow.style.display = 'none';
            }
        });
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
     * Initialize edit buttons using event delegation
     */
    function initEditButtons() {
        // Use event delegation for better performance and to handle dynamically added buttons
        document.addEventListener('click', function(event) {
            // Find closest edit button if clicked on or within one
            const editButton = event.target.closest('.edit-material');
            
            if (editButton) {
                const materialId = editButton.getAttribute('data-id');
                if (materialId) {
                    openEditMaterialModal(materialId);
                }
            }
        });
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
     * Validate the entire form
     * @returns {boolean} - Whether the form is valid
     */
    function validateForm() {
        if (!form) return false;
        
        const requiredFields = form.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!validateField(field)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    /**
     * Open modal for adding a new material
     */
    function openAddMaterialModal() {
        modalTitle.textContent = 'Add New Material';
        form.reset();
        document.getElementById('material_id').value = '';
        
        const stockQuantityField = document.getElementById('stock_quantity');
        if (stockQuantityField) {
            stockQuantityField.disabled = false;
            stockQuantityField.value = '0';
        }
        
        // Show the stock quantity field for new materials
        const stockQuantityGroup = document.getElementById('stock-quantity-group');
        if (stockQuantityGroup) {
            stockQuantityGroup.style.display = 'block';
        }
        
        // Clear any validation errors
        clearValidationErrors();
        
        // Store currently focused element
        lastFocusedElement = document.activeElement;
        
        // Open modal
        modal.style.display = 'block';
        
        // Setup focus trap for accessibility
        setupFocusTrap();
        
        // Focus the first input field after modal is visible
        setTimeout(() => {
            document.getElementById('name').focus();
        }, 100);
    }
    
    /**
     * Open modal for editing an existing material
     * @param {string} materialId - The ID of the material to edit
     */
    function openEditMaterialModal(materialId) {
        // Store currently focused element
        lastFocusedElement = document.activeElement;
        
        modalTitle.textContent = 'Edit Material';
        
        // Show loading indicator
        showLoading(true);
        
        console.log(`Fetching material data for ID: ${materialId}`);
        
        // Try to get data from API first
        fetch(`../api/get-material.php?id=${materialId}`)
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Received data:', data);
                
                // Check if data is valid
                if (!data || !data.id) {
                    throw new Error('Invalid data received from server');
                }
                
                // Populate form fields
                document.getElementById('material_id').value = data.id;
                document.getElementById('name').value = data.name || '';
                document.getElementById('description').value = data.description || '';
                document.getElementById('unit').value = data.unit || '';
                document.getElementById('min_stock_level').value = data.min_stock_level || 10;
                
                // Hide stock quantity field for editing
                const stockQuantityGroup = document.getElementById('stock-quantity-group');
                if (stockQuantityGroup) {
                    stockQuantityGroup.style.display = 'none';
                }
                
                // Clear validation errors
                clearValidationErrors();
                
                // Open modal
                modal.style.display = 'block';
                
                // Setup focus trap for accessibility
                setupFocusTrap();
                
                // Focus the first input field after modal is visible
                setTimeout(() => {
                    document.getElementById('name').focus();
                }, 100);
                
                // Hide loading indicator
                showLoading(false);
            })
            .catch(error => {
                console.error('Error fetching material data:', error);
                
                // Try fallback method - get data from the table row
                tryGetDataFromTable(materialId);
                
                showLoading(false);
            });
    }
    
    /**
     * Fallback method to get material data from the table
     * @param {string} materialId - The ID of the material to edit
     */
    function tryGetDataFromTable(materialId) {
        try {
            // Find the button with this material ID
            const editButton = document.querySelector(`.edit-material[data-id="${materialId}"]`);
            
            if (!editButton) {
                throw new Error('Cannot find material in the table');
            }
            
            // Get the row containing this button
            const row = editButton.closest('tr');
            
            if (!row || !row.cells || row.cells.length < 4) {
                throw new Error('Invalid table structure');
            }
            
            // Extract data from the row
            const name = row.cells[0].textContent.trim();
            const description = row.cells[1].textContent.trim();
            const unit = row.cells[2].textContent.trim();
            const stockQuantity = parseFloat(row.cells[3].getAttribute('data-quantity') || '0');
            
            // Populate form
            document.getElementById('material_id').value = materialId;
            document.getElementById('name').value = name;
            document.getElementById('description').value = description === 'No description' ? '' : description;
            document.getElementById('unit').value = unit.toLowerCase();
            
            // Get min stock level from status logic (approximate)
            let minStockLevel = 10; // Default
            
            // Hide stock quantity field for editing
            const stockQuantityGroup = document.getElementById('stock-quantity-group');
            if (stockQuantityGroup) {
                stockQuantityGroup.style.display = 'none';
            }
            
            document.getElementById('min_stock_level').value = minStockLevel;
            
            // Clear validation errors
            clearValidationErrors();
            
            // Open modal
            modal.style.display = 'block';
            
            // Setup focus trap for accessibility
            setupFocusTrap();
            
            // Focus first field
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);
            
        } catch (error) {
            console.error('Fallback method failed:', error);
            showToast('Error', 'Could not load material data. Please try again or contact support.', 'error');
        }
    }
    
    /**
     * Set up focus trap for modal accessibility
     */
    function setupFocusTrap() {
        // Get all focusable elements in the modal
        const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        
        if (focusableElements.length === 0) return;
        
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        
        // Add event listener for tab key to trap focus within modal
        const handleTabKey = function(e) {
            if (e.key === 'Tab') {
                // If shift+tab on first element, focus last element
                if (e.shiftKey && document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                }
                // If tab on last element, focus first element
                else if (!e.shiftKey && document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        };
        
        // Remove any existing event listener
        modal.removeEventListener('keydown', handleTabKey);
        
        // Add the new event listener
        modal.addEventListener('keydown', handleTabKey);
    }
    
    /**
     * Close the material modal
     */
    function closeModal() {
        if (!modal) return;
        
        modal.style.display = 'none';
        
        // Reset form and clear validation errors
        if (form) {
            form.reset();
            clearValidationErrors();
        }
        
        // Return focus to the element that had focus before the modal was opened
        if (lastFocusedElement) {
            setTimeout(() => {
                lastFocusedElement.focus();
            }, 0);
        }
    }
    
    /**
     * Clear all validation errors in the form
     */
    function clearValidationErrors() {
        if (!form) return;
        
        form.querySelectorAll('.invalid').forEach(field => {
            field.classList.remove('invalid');
        });
        
        form.querySelectorAll('.error-message').forEach(error => {
            error.textContent = '';
        });
    }
    
    /**
     * Handle form submission with validation and error handling
     * @param {Event} event - The form submission event
     */
    function handleFormSubmit(event) {
        event.preventDefault();
        
        // Validate form
        if (!validateForm()) {
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('saveMaterialBtn');
        if (!submitBtn) return;
        
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Saving...';
        
        // Get form data
        const formData = new FormData(form);
        
        // Send AJAX request
        fetch(form.action, {
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
            if (data.success) {
                // Show success message
                showToast('Success', data.message || 'Material saved successfully', 'success');
                
                // Close modal
                closeModal();
                
                // Log activity
                logUserActivity(
                    formData.get('material_id') ? 'update' : 'create',
                    'raw-materials',
                    `${formData.get('material_id') ? 'Updated' : 'Created'} material: ${formData.get('name')}`
                );
                
                // Reload page to show updated data
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                // Show error message
                showToast('Error', data.message || 'Failed to save material', 'error');
                
                // Reset button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        })
        .catch(error => {
            console.error('Error saving material:', error);
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
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (document.body.contains(toast)) {
                removeToast(toast);
            }
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
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }
    
    /**
     * Escape HTML to prevent XSS
     * @param {string} unsafe - The unsafe string to escape
     * @returns {string} - The escaped string
     */
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return String(unsafe)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    
    /**
     * Log user activity to the server
     * @param {string} action - The action type (create, read, update, delete)
     * @param {string} module - The module name
     * @param {string} description - Description of the activity
     */
    function logUserActivity(action, module, description) {
        const userId = document.getElementById('current-user-id')?.value;
        if (!userId) return;
        
        fetch('../api/log-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_id: userId,
                action_type: action,
                module: module,
                description: description
            })
        }).catch(error => {
            console.error('Error logging activity:', error);
        });
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>