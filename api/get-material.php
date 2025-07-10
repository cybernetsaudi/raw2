<?php
// api/get-material.php
header('Content-Type: application/json');

// Include necessary files
require_once '../config/database.php';
require_once '../config/auth.php';

// Check for required parameter
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Material ID is required']);
    exit;
}

$material_id = $_GET['id'];

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Prepare query
    $query = "SELECT id, name, description, unit, stock_quantity, min_stock_level, created_at, updated_at 
              FROM raw_materials 
              WHERE id = :id";
    $stmt = $db->prepare($query);
    
    // Bind parameter
    $stmt->bindParam(':id', $material_id);
    
    // Execute query
    $stmt->execute();
    
    // Check if material exists
    if ($stmt->rowCount() > 0) {
        // Fetch material data
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Return success response
        echo json_encode($material);
    } else {
        // Material not found
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Material not found']);
    }
} catch (PDOException $e) {
    // Database error
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>