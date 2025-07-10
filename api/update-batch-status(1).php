<!-- api/update-batch-status.php -->
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
    header('Location: ../index.php');
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
        $batch_id = $_POST['batch_id'];
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? null;
        $updated_by = $_SESSION['user_id'];
        
        // Validate data
        if(empty($batch_id) || empty($status)) {
            throw new Exception("Missing required fields.");
        }
        
        // Validate status
        $valid_statuses = ['pending', 'cutting', 'stitching', 'ironing', 'packaging', 'completed'];
        if(!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid status selected.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Get current batch info
        $batch_query = "SELECT batch_number, status FROM manufacturing_batches WHERE id = ?";
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->bindParam(1, $batch_id);
        $batch_stmt->execute();
        
        if($batch_stmt->rowCount() === 0) {
            throw new Exception("Batch not found.");
        }
        
        $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if status is actually changing
        if($batch['status'] === $status) {
            throw new Exception("Batch is already in the {$status} status.");
        }
        
        // Update batch status
        $update_query = "UPDATE manufacturing_batches SET status = ?, updated_at = NOW()";
        
        // If status is completed, set completion date
        if($status === 'completed') {
            $update_query .= ", completion_date = CURDATE()";
        }
        
        $update_query .= " WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(1, $status);
        $update_stmt->bindParam(2, $batch_id);
        $update_stmt->execute();
        
        // Add status history record
        $history_query = "INSERT INTO batch_status_history (batch_id, status, notes, updated_by) 
                         VALUES (?, ?, ?, ?)";
        $history_stmt = $db->prepare($history_query);
        $history_stmt->bindParam(1, $batch_id);
        $history_stmt->bindParam(2, $status);
        $history_stmt->bindParam(3, $notes);
        $history_stmt->bindParam(4, $updated_by);
        $history_stmt->execute();
        
        // If status is completed, update inventory
        if($status === 'completed') {
            // Get batch details for inventory update
            $product_query = "SELECT product_id, quantity_produced FROM manufacturing_batches WHERE id = ?";
            $product_stmt = $db->prepare($product_query);
            $product_stmt->bindParam(1, $batch_id);
            $product_stmt->execute();
            $product_info = $product_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if product exists in manufacturing inventory
            $inventory_check = "SELECT id FROM inventory WHERE product_id = ? AND location = 'manufacturing'";
            $check_stmt = $db->prepare($inventory_check);
            $check_stmt->bindParam(1, $product_info['product_id']);
            $check_stmt->execute();
            
            if($check_stmt->rowCount() > 0) {
                // Update existing inventory
                $inventory_update = "UPDATE inventory 
                                    SET quantity = quantity + ?, updated_at = NOW() 
                                    WHERE product_id = ? AND location = 'manufacturing'";
                $update_stmt = $db->prepare($inventory_update);
                $update_stmt->bindParam(1, $product_info['quantity_produced']);
                $update_stmt->bindParam(2, $product_info['product_id']);
                $update_stmt->execute();
            } else {
                // Insert new inventory record
                $inventory_insert = "INSERT INTO inventory (product_id, location, quantity) 
                                    VALUES (?, 'manufacturing', ?)";
                $insert_stmt = $db->prepare($inventory_insert);
                $insert_stmt->bindParam(1, $product_info['product_id']);
                $insert_stmt->bindParam(2, $product_info['quantity_produced']);
                $insert_stmt->execute();
            }
        }
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'update', 
            'manufacturing', 
            "Updated batch #{$batch['batch_number']} status to: {$status}", 
            $batch_id
        );
        
                // Commit transaction
        $db->commit();
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back to batch view page
        header("Location: ../{$redirect_page}/view-batch.php?id={$batch_id}&success=1");
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back with error
        header("Location: ../{$redirect_page}/view-batch.php?id={$_POST['batch_id']}&error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../index.php');
    exit;
}
?>