<?php
// Initialize session and error reporting
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include database config (correct path)
include_once '../config/database.php';

// Check user authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Process form data
$product_id = $_POST['product_id'] ?? null;
$quantity = $_POST['quantity_produced'] ?? null;
$start_date = $_POST['start_date'] ?? null;
$expected_completion_date = $_POST['expected_completion_date'] ?? null;
$notes = $_POST['notes'] ?? '';
$created_by = $_SESSION['user_id'];

// Validate required fields
if (!$product_id || !$quantity || !$start_date || !$expected_completion_date) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Generate batch number
$batch_number = 'BATCH-' . date('Ymd') . '-' . rand(1000, 9999);

// Start transaction
$db->beginTransaction();

try {
    // Insert batch record
    $batch_query = "INSERT INTO manufacturing_batches 
                   (batch_number, product_id, quantity_produced, status, start_date, 
                   expected_completion_date, notes, created_by) 
                   VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->execute([
        $batch_number, 
        $product_id, 
        $quantity, 
        $start_date, 
        $expected_completion_date, 
        $notes, 
        $created_by
    ]);
    
    $batch_id = $db->lastInsertId();
    
    // Process materials
    if (isset($_POST['materials']) && is_array($_POST['materials'])) {
        foreach ($_POST['materials'] as $material) {
            if (isset($material['material_id']) && isset($material['quantity'])) {
                // Insert batch material
                $material_query = "INSERT INTO batch_materials 
                                  (batch_id, material_id, quantity_required) 
                                  VALUES (?, ?, ?)";
                $material_stmt = $db->prepare($material_query);
                $material_stmt->execute([
                    $batch_id,
                    $material['material_id'],
                    $material['quantity']
                ]);
                
                // Update material inventory
                $update_query = "UPDATE raw_materials 
                               SET stock_quantity = stock_quantity - ? 
                               WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([
                    $material['quantity'],
                    $material['material_id']
                ]);
            }
        }
    }
    
    // Commit transaction
    $db->commit();
    
    // Return success response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Batch created successfully',
        'batch_id' => $batch_id,
        'batch_number' => $batch_number
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    // Log error
    error_log('Error creating batch: ' . $e->getMessage());
    
    // Return error response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while creating the batch: ' . $e->getMessage()
    ]);
}
?>