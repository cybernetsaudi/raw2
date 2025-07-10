<?php
// api/save-material.php
header('Content-Type: application/json');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to perform this action']);
    exit;
}

// Include database connection
include_once '../config/database.php';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Get POST data
    $material_id = isset($_POST['material_id']) ? intval($_POST['material_id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $unit = isset($_POST['unit']) ? $_POST['unit'] : '';
    $stock_quantity = isset($_POST['stock_quantity']) ? floatval($_POST['stock_quantity']) : 0;
    
    // Validate input
    if (empty($name)) {
        throw new Exception("Material name is required");
    }
    
    if (empty($unit)) {
        throw new Exception("Unit is required");
    }
    
    // Start transaction
    $db->beginTransaction();
    
    if ($material_id > 0) {
        // Update existing material
        $query = "UPDATE raw_materials 
                 SET name = :name, description = :description, unit = :unit 
                 WHERE id = :material_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':unit', $unit);
        $stmt->bindParam(':material_id', $material_id);
        $stmt->execute();
        
        // Log activity
        $activity_query = "INSERT INTO activity_logs 
                          (user_id, action_type, module, description, entity_id) 
                          VALUES (:user_id, 'update', 'raw-materials', :description, :entity_id)";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $description = "Updated material: " . $name;
        $activity_stmt->bindParam(':description', $description);
        $activity_stmt->bindParam(':entity_id', $material_id);
        $activity_stmt->execute();
        
        $message = "Material updated successfully";
    } else {
        // Create new material
        $query = "INSERT INTO raw_materials 
                 (name, description, unit, stock_quantity) 
                 VALUES (:name, :description, :unit, :stock_quantity)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':unit', $unit);
        $stmt->bindParam(':stock_quantity', $stock_quantity);
        $stmt->execute();
        
        $material_id = $db->lastInsertId();
        
        // Log activity
        $activity_query = "INSERT INTO activity_logs 
                          (user_id, action_type, module, description, entity_id) 
                          VALUES (:user_id, 'create', 'raw-materials', :description, :entity_id)";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $description = "Added new material: " . $name;
        $activity_stmt->bindParam(':description', $description);
        $activity_stmt->bindParam(':entity_id', $material_id);
        $activity_stmt->execute();
        
        $message = "Material added successfully";
    }
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'material_id' => $material_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>