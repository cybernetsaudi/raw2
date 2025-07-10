<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Set content type to JSON for consistent response format
header('Content-Type: application/json');

// Ensure user is logged in and is an incharge
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $sku = isset($_POST['sku']) ? trim($_POST['sku']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : null;
        $category = isset($_POST['category']) ? trim($_POST['category']) : null;
        $created_by = $_SESSION['user_id'];
        
        // Validate input
        if(empty($name)) {
            throw new Exception("Product name is required.");
        }
        
        if(empty($sku)) {
            throw new Exception("Product SKU is required.");
        }
        
        // Validate SKU format (alphanumeric with optional hyphens)
        if(!preg_match('/^[A-Za-z0-9\-]+$/', $sku)) {
            throw new Exception("SKU can only contain letters, numbers, and hyphens.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Check if SKU already exists
        $check_query = "SELECT COUNT(*) as count FROM products WHERE sku = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(1, $sku);
        $check_stmt->execute();
        if($check_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            throw new Exception("A product with this SKU already exists.");
        }
        
        // Insert new product
        $query = "INSERT INTO products (name, sku, description, category, created_by) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $name);
        $stmt->bindParam(2, $sku);
        $stmt->bindParam(3, $description);
        $stmt->bindParam(4, $category);
        $stmt->bindParam(5, $created_by);
        $stmt->execute();
        
        $product_id = $db->lastInsertId();
        
        // Log activity
        $log_query = "INSERT INTO activity_logs (user_id, action_type, module, description, entity_id, created_at) 
                     VALUES (?, 'create', 'products', ?, ?, NOW())";
        $log_stmt = $db->prepare($log_query);
        $log_description = "Created new product: {$name} (SKU: {$sku})";
        $log_stmt->bindParam(1, $created_by);
        $log_stmt->bindParam(2, $log_description);
        $log_stmt->bindParam(3, $product_id);
        $log_stmt->execute();
        
        // Commit transaction
        $db->commit();
        
        // Return success response with detailed product info for UI updates
        echo json_encode([
            'success' => true, 
            'message' => 'Product added successfully',
            'product' => [
                'id' => $product_id,
                'name' => $name,
                'sku' => $sku,
                'description' => $description,
                'category' => $category,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch(Exception $e) {
        // Rollback transaction on error
        if(isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        // Log error
        error_log('Quick add product error: ' . $e->getMessage());
        
        // Return error response
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
} else {
    // Not a POST request
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method. Please use POST.'
    ]);
}
?>