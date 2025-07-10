<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in and is an incharge
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    header('Content-Type: application/json');
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
        $name = $_POST['name'];
        $sku = $_POST['sku'];
        $created_by = $_SESSION['user_id'];
        
        // Validate input
        if(empty($name) || empty($sku)) {
            throw new Exception("Product name and SKU are required.");
        }
        
        // Check if SKU already exists
        $check_query = "SELECT COUNT(*) as count FROM products WHERE sku = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(1, $sku);
        $check_stmt->execute();
        if($check_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            throw new Exception("A product with this SKU already exists.");
        }
        
        // Insert new product
        $query = "INSERT INTO products (name, sku, created_by) VALUES (?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $name);
        $stmt->bindParam(2, $sku);
        $stmt->bindParam(3, $created_by);
        $stmt->execute();
        
        $product_id = $db->lastInsertId();
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'product_id' => $product_id,
            'name' => $name,
            'sku' => $sku
        ]);
        
    } catch(Exception $e) {
        // Return error response
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    // Not a POST request
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>