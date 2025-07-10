<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and has appropriate role
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $material_id = isset($_POST['material_id']) ? $_POST['material_id'] : null;
        $name = $_POST['name'];
        $unit = $_POST['unit'];
        $description = isset($_POST['description']) ? $_POST['description'] : null;
        $min_stock_level = isset($_POST['min_stock_level']) ? $_POST['min_stock_level'] : 0;
        
        // Validate data
        if(empty($name) || empty($unit)) {
            throw new Exception("Name and unit are required.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        if($material_id) {
            // Update existing material
            $query = "UPDATE raw_materials 
                     SET name = ?, unit = ?, description = ?, min_stock_level = ?, updated_at = NOW()
                     WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $name);
            $stmt->bindParam(2, $unit);
            $stmt->bindParam(3, $description);
            $stmt->bindParam(4, $min_stock_level);
            $stmt->bindParam(5, $material_id);
            $stmt->execute();
            
            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'], 
                'update', 
                'raw_materials', 
                "Updated material: {$name}", 
                $material_id
            );
            
            $message = "Material updated successfully.";
        } else {
            // Create new material
            $query = "INSERT INTO raw_materials (name, unit, description, min_stock_level, stock_quantity, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 0, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $name);
            $stmt->bindParam(2, $unit);
            $stmt->bindParam(3, $description);
            $stmt->bindParam(4, $min_stock_level);
            $stmt->execute();
            
            $material_id = $db->lastInsertId();
            
            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'], 
                'create', 
                'raw_materials', 
                "Created new material: {$name}", 
                $material_id
            );
            
            $message = "Material created successfully.";
        }
        
        // Commit transaction
        $db->commit();
        
        // Return success response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'material_id' => $material_id
        ]);
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Return error response
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
} else {
    // Not a POST request
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request method'
    ]);
}
?>