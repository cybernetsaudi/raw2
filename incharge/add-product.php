<?php
session_start();
$page_title = "Add New Product";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Process form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $category = isset($_POST['category']) ? trim($_POST['category']) : '';
        
        // Get specifications
        $size = isset($_POST['size']) ? trim($_POST['size']) : '';
        $color = isset($_POST['color']) ? trim($_POST['color']) : '';
        $fabric_type = isset($_POST['fabric_type']) ? trim($_POST['fabric_type']) : '';
        $care_instructions = isset($_POST['care_instructions']) ? trim($_POST['care_instructions']) : '';
        $technical_details = isset($_POST['technical_details']) ? trim($_POST['technical_details']) : '';
        
        // Get pricing
        $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
        
        // Validate required fields
        if (empty($name)) {
            throw new Exception("Product name is required");
        }
        
        if (empty($sku)) {
            throw new Exception("SKU is required");
        }
        
        // Check if SKU already exists
        $check_query = "SELECT id FROM products WHERE sku = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$sku]);
        
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("A product with this SKU already exists");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Insert product
        $product_query = "INSERT INTO products (name, description, sku, category, created_by, created_at) 
                         VALUES (?, ?, ?, ?, ?, NOW())";
        $product_stmt = $db->prepare($product_query);
        $product_stmt->execute([
            $name,
            $description,
            $sku,
            $category,
            $_SESSION['user_id']
        ]);
        
        $product_id = $db->lastInsertId();
        
        // Insert product specifications
        $specs_query = "INSERT INTO product_specifications 
                       (product_id, size, color, fabric_type, care_instructions, technical_details) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $specs_stmt = $db->prepare($specs_query);
        $specs_stmt->execute([
            $product_id,
            $size,
            $color,
            $fabric_type,
            $care_instructions,
            $technical_details
        ]);
        
        // Insert initial pricing if provided
        if ($price > 0) {
            $pricing_query = "INSERT INTO product_pricing_history 
                             (product_id, price, effective_from, changed_by, reason) 
                             VALUES (?, ?, CURDATE(), ?, 'Initial pricing')";
            $pricing_stmt = $db->prepare($pricing_query);
            $pricing_stmt->execute([
                $product_id,
                $price,
                $_SESSION['user_id']
            ]);
        }
        
        // Process product images if uploaded
        if (!empty($_FILES['product_images']['name'][0])) {
            $upload_dir = "../uploads/products/";
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Process each uploaded file
            $image_count = count($_FILES['product_images']['name']);
            
            for ($i = 0; $i < $image_count; $i++) {
                if ($_FILES['product_images']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['product_images']['tmp_name'][$i];
                    $name = basename($_FILES['product_images']['name'][$i]);
                    $extension = pathinfo($name, PATHINFO_EXTENSION);
                    
                    // Generate unique filename
                    $new_filename = $sku . '_' . uniqid() . '.' . $extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        // Save image path to database
                        $image_query = "INSERT INTO product_images 
                                       (product_id, image_path, is_primary, sort_order) 
                                       VALUES (?, ?, ?, ?)";
                        $image_stmt = $db->prepare($image_query);
                        $is_primary = ($i === 0) ? 1 : 0; // First image is primary
                        $image_stmt->execute([
                            $product_id,
                            'uploads/products/' . $new_filename,
                            $is_primary,
                            $i
                        ]);
                    }
                }
            }
        }
        
        // Log activity
        $activity_query = "INSERT INTO activity_logs 
                          (user_id, action_type, module, description, entity_id) 
                          VALUES (?, 'create', 'products', ?, ?)";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->execute([
            $_SESSION['user_id'],
            "Created new product: {$name} (SKU: {$sku})",
            $product_id
        ]);
        
        // Commit transaction
        $db->commit();
        
        $success_message = "Product added successfully";
        
        // Redirect to product list after short delay
        header("refresh:2;url=products.php");
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        $error_message = $e->getMessage();
    }
}

// Get existing categories for dropdown
$categories_query = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category";
$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="breadcrumb">
    <a href="products.php">Products</a> &gt; Add New Product
</div>

<div class="page-header">
    <h2>Add New Product</h2>
</div>

<?php if (!empty($success_message)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo $success_message; ?></span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<?php if (!empty($error_message)): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo $error_message; ?></span>
    <button type="button" class="close-alert">&times;</button>
</div>
<?php endif; ?>

<div class="product-form-container">
    <form id="productForm" method="post" enctype="multipart/form-data">
        <div class="form-tabs">
            <button type="button" class="tab-button active" data-tab="basic-info">Basic Info</button>
            <button type="button" class="tab-button" data-tab="specifications">Specifications</button>
            <button type="button" class="tab-button" data-tab="pricing">Pricing</button>
            <button type="button" class="tab-button" data-tab="images">Images</button>
        </div>
        
        <div class="tab-content active" id="basic-info">
            <div class="form-section">
                <h3>Basic Information</h3>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="name">Product Name:</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="sku">SKU:</label>
                            <input type="text" id="sku" name="sku" required>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="category">Category:</label>
                            <div class="category-input-container">
                                <input type="text" id="category" name="category" list="category-list">
                                <datalist id="category-list">
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-hint">Type a new category or select from existing ones</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="description">Description:</label>
                            <textarea id="description" name="description" rows="5"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="specifications">
            <div class="form-section">
                <h3>Product Specifications</h3>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="size">Size:</label>
                            <input type="text" id="size" name="size">
                        </div>
                    </div>
                    <div class="form-col">
                        <div class="form-group">
                            <label for="color">Color:</label>
                            <input type="text" id="color" name="color">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="fabric_type">Fabric Type:</label>
                            <input type="text" id="fabric_type" name="fabric_type">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="care_instructions">Care Instructions:</label>
                            <textarea id="care_instructions" name="care_instructions" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="technical_details">Technical Details:</label>
                            <textarea id="technical_details" name="technical_details" rows="3"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="pricing">
            <div class="form-section">
                <h3>Product Pricing</h3>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="price">Initial Price:</label>
                            <div class="input-with-prefix">
                                <span class="input-prefix">Rs.</span>
                                <input type="number" id="price" name="price" step="0.01" min="0">
                            </div>
                            <div class="form-hint">You can update pricing later as needed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tab-content" id="images">
            <div class="form-section">
                <h3>Product Images</h3>
                
                <div class="form-row">
                    <div class="form-col">
                        <div class="form-group">
                            <label for="product_images">Upload Images:</label>
                            <div class="file-upload-container">
                                <input type="file" id="product_images" name="product_images[]" multiple accept="image/*">
                                <div class="file-upload-preview" id="imagePreview"></div>
                            </div>
                            <div class="form-hint">You can upload multiple images. The first image will be set as the primary image.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="products.php" class="button secondary">Cancel</a>
            <button type="submit" class="button primary">Save Product</button>
        </div>
    </form>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Form container */
.product-form-container {
    background-color: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
}

/* Form tabs */
.form-tabs {
    display: flex;
    border-bottom: 1px solid #e0e0e0;
    background-color: #f8f9fa;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
    overflow-x: auto;
    scrollbar-width: none; /* Firefox */
}

.form-tabs::-webkit-scrollbar {
    display: none; /* Chrome, Safari, Edge */
}

.tab-button {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 500;
    color: #5f6368;
    cursor: pointer;
    white-space: nowrap;
}

.tab-button:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.tab-button.active {
    color: #1a73e8;
    border-bottom-color: #1a73e8;
}

/* Tab content */
.tab-content {
    display: none;
    padding: 1.5rem;
}

.tab-content.active {
    display: block;
}

/* Form section */
.form-section {
    margin-bottom: 1.5rem;
}

.form-section h3 {
    margin-top: 0;
    margin-bottom: 1.5rem;
    color: #202124;
    font-size: 1.1rem;
}

/* Form rows and columns */
.form-row {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -0.75rem;
    margin-bottom: 1rem;
}

.form-col {
    flex: 1;
    padding: 0 0.75rem;
    min-width: 250px;
}

/* Form inputs */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #3c4043;
}

.form-group input[type="text"],
.form-group input[type="number"],
.form-group textarea,
.form-group select {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid #dadce0;
    border-radius: 4px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    border-color: #1a73e8;
    outline: none;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
}

.form-hint {
    font-size: 0.85rem;
    color: #5f6368;
    margin-top: 0.5rem;
}

/* Input with prefix */
.input-with-prefix {
    display: flex;
    align-items: center;
}

.input-prefix {
    padding: 0.625rem 0.75rem;
    background-color: #f1f3f4;
    border: 1px solid #dadce0;
    border-right: none;
    border-top-left-radius: 4px;
    border-bottom-left-radius: 4px;
    color: #5f6368;
}

.input-with-prefix input {
    flex: 1;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}

/* Category input container */
.category-input-container {
    position: relative;
}

/* File upload */
.file-upload-container {
    margin-bottom: 1rem;
}

.file-upload-container input[type="file"] {
    width: 100%;
    padding: 0.625rem 0;
}

.file-upload-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-top: 1rem;
}

.preview-item {
    position: relative;
    width: 100px;
    height: 100px;
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.preview-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.preview-remove {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 20px;
    height: 20px;
    background-color: rgba(0, 0, 0, 0.5);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
}

/* Form actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid #e0e0e0;
}

/* Alert styles */
.alert {
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-success {
    background-color: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background-color: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.alert i {
    font-size: 1.25rem;
}

.alert span {
    flex: 1;
}

.close-alert {
    background: none;
    border: none;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
}

.close-alert:hover {
    opacity: 1;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .form-tabs {
        justify-content: flex-start;
    }
    
    .tab-button {
        padding: 0.75rem 1rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .button {
        width: 100%;
    }
}

@media (max-width: 576px) {
    .tab-content {
        padding: 1rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab navigation
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });
    
    // Image preview functionality
    const imageInput = document.getElementById('product_images');
    const imagePreview = document.getElementById('imagePreview');
    
    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function() {
            // Clear previous previews
            imagePreview.innerHTML = '';
            
            // Create previews for each selected file
            Array.from(this.files).forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    
                    reader.onload = function(e) {
                        const previewItem = document.createElement('div');
                        previewItem.className = 'preview-item';
                        
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.alt = 'Preview';
                        
                        const removeBtn = document.createElement('div');
                        removeBtn.className = 'preview-remove';
                        removeBtn.innerHTML = '×';
                        removeBtn.setAttribute('data-index', index);
                        removeBtn.addEventListener('click', function() {
                            // This is a placeholder - file input can't easily remove individual files
                            // In a real implementation, you might use a custom file upload solution
                            previewItem.remove();
                        });
                        
                        previewItem.appendChild(img);
                        previewItem.appendChild(removeBtn);
                        imagePreview.appendChild(previewItem);
                    };
                    
                    reader.readAsDataURL(file);
                }
            });
        });
    }
    
    // Form validation
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(event) {
            let isValid = true;
            
            // Basic validation for required fields
            const name = document.getElementById('name').value.trim();
            const sku = document.getElementById('sku').value.trim();
            
            if (!name) {
                showValidationError('name', 'Product name is required');
                isValid = false;
            }
            
            if (!sku) {
                showValidationError('sku', 'SKU is required');
                isValid = false;
            }
            
            if (!isValid) {
                event.preventDefault();
                
                // Switch to the tab with the first error
                const firstErrorField = document.querySelector('.validation-error');
                if (firstErrorField) {
                    const parentTab = firstErrorField.closest('.tab-content');
                    if (parentTab) {
                        const tabId = parentTab.id;
                        tabButtons.forEach(btn => {
                            if (btn.getAttribute('data-tab') === tabId) {
                                btn.click();
                            }
                        });
                    }
                }
            } else {
                // Show loading state
                const submitButton = this.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                
                // Log activity
                if (typeof logUserActivity === 'function') {
                    logUserActivity(
                        'create', 
                        'products', 
                        `Created new product: ${name} (SKU: ${sku})`
                    );
                }
            }
        });
    }
    
    // Helper function to show validation errors
    function showValidationError(fieldId, message) {
        const field = document.getElementById(fieldId);
        field.classList.add('invalid-input');
        
        // Remove any existing error message
        const existingError = field.parentElement.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
        
                // Create and append error message
        const errorElement = document.createElement('div');
        errorElement.className = 'validation-error';
        errorElement.textContent = message;
        field.parentElement.appendChild(errorElement);
    }
    
    // Remove validation errors when field is edited
    const formInputs = document.querySelectorAll('input, textarea, select');
    formInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.classList.remove('invalid-input');
            
            // Remove any validation error message
            const errorMessage = this.parentElement.querySelector('.validation-error');
            if (errorMessage) {
                errorMessage.remove();
            }
        });
    });
    
    // Close alert buttons
    const alertCloseButtons = document.querySelectorAll('.close-alert');
    alertCloseButtons.forEach(button => {
        button.addEventListener('click', function() {
            const alert = this.parentElement;
            // Add fade-out animation
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            // Remove from DOM after animation completes
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        });
    });
    
    // Auto-dismiss success alerts after 5 seconds
    const successAlerts = document.querySelectorAll('.alert-success');
    successAlerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
    
    // Log page view
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'products', 'Viewed add product page');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>