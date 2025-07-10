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
    
    // Process materials - handle both array format and flat format
    $materials = [];
    
    // Check if materials is submitted as an array directly
    if (isset($_POST['materials']) && is_array($_POST['materials'])) {
        $materials = $_POST['materials'];
    } 
    // Check if materials are submitted in a flat format (materials[0][material_id], etc.)
    else {
        $material_index = 0;
        $flat_materials = [];
        
        // Keep checking for material entries until we don't find any more
        while (isset($_POST["materials[{$material_index}][material_id]"]) || 
               isset($_POST["materials[{$material_index}][quantity]"])) {
            
            $material_id = $_POST["materials[{$material_index}][material_id]"] ?? null;
            $quantity = $_POST["materials[{$material_index}][quantity]"] ?? null;
            
            if ($material_id && $quantity) {
                $flat_materials[] = [
                    'material_id' => $material_id,
                    'quantity' => $quantity
                ];
            }
            
            $material_index++;
        }
        
        if (!empty($flat_materials)) {
            $materials = $flat_materials;
        }
    }
    
    // Process the materials array
    if (!empty($materials)) {
        foreach ($materials as $material) {
            if (isset($material['material_id']) && isset($material['quantity']) && 
                !empty($material['material_id']) && !empty($material['quantity'])) {
                
                // Check if we have enough material in stock
                $stock_check = "SELECT stock_quantity FROM raw_materials WHERE id = ?";
                $stock_stmt = $db->prepare($stock_check);
                $stock_stmt->execute([$material['material_id']]);
                $available_stock = $stock_stmt->fetchColumn();
                
                if ($available_stock < $material['quantity']) {
                    throw new Exception("Insufficient stock for material ID " . $material['material_id'] . 
                                      ". Available: " . $available_stock . ", Required: " . $material['quantity']);
                }
                
                // Insert material usage
                $material_query = "INSERT INTO material_usage 
                                  (batch_id, material_id, quantity_required, recorded_by) 
                                  VALUES (?, ?, ?, ?)";
                $material_stmt = $db->prepare($material_query);
                $material_stmt->execute([
                    $batch_id,
                    $material['material_id'],
                    $material['quantity'],
                    $created_by
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
    } else {
        // Log warning if no materials were provided
        error_log('Warning: Batch created without materials. Batch ID: ' . $batch_id);
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
    exit;
    
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
    exit;
}