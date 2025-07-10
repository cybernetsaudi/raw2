<!-- api/adjust-inventory.php -->
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
        $inventory_id = $_POST['inventory_id'];
        $adjustment_type = $_POST['adjustment_type'];
        $quantity = $_POST['quantity'];
        $reason = $_POST['reason'];
        $notes = $_POST['notes'] ?? null;
        $adjusted_by = $_SESSION['user_id'];
        
        // Validate data
        if(empty($inventory_id) || empty($adjustment_type) || empty($quantity) || empty($reason)) {
            throw new Exception("Missing required fields.");
        }
        
        if(!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("Quantity must be a positive number.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Get current inventory details
        $inventory_query = "SELECT i.*, p.name as product_name 
                           FROM inventory i
                           JOIN products p ON i.product_id = p.id
                           WHERE i.id = ?";
        $inventory_stmt = $db->prepare($inventory_query);
        $inventory_stmt->bindParam(1, $inventory_id);
        $inventory_stmt->execute();
        
        if($inventory_stmt->rowCount() === 0) {
            throw new Exception("Inventory record not found.");
        }
        
        $inventory = $inventory_stmt->fetch(PDO::FETCH_ASSOC);
        $new_quantity = 0;
        
        // Calculate new quantity based on adjustment type
        switch($adjustment_type) {
            case 'add':
                $new_quantity = $inventory['quantity'] + $quantity;
                break;
            case 'remove':
                if($quantity > $inventory['quantity']) {
                    throw new Exception("Cannot remove more than the current stock ({$inventory['quantity']}).");
                }
                $new_quantity = $inventory['quantity'] - $quantity;
                break;
            case 'set':
                $new_quantity = $quantity;
                break;
            default:
                throw new Exception("Invalid adjustment type.");
        }
        
        // Update inventory quantity
        $update_query = "UPDATE inventory 
                        SET quantity = ?, updated_at = NOW() 
                        WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(1, $new_quantity);
        $update_stmt->bindParam(2, $inventory_id);
        $update_stmt->execute();
        
        // Record the adjustment
        $adjustment_query = "INSERT INTO inventory_adjustments 
                            (inventory_id, product_id, location, previous_quantity, new_quantity, 
                             adjustment_type, quantity_changed, reason, notes, adjusted_by, adjustment_date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $adjustment_stmt = $db->prepare($adjustment_query);
        $adjustment_stmt->bindParam(1, $inventory_id);
        $adjustment_stmt->bindParam(2, $inventory['product_id']);
        $adjustment_stmt->bindParam(3, $inventory['location']);
        $adjustment_stmt->bindParam(4, $inventory['quantity']);
        $adjustment_stmt->bindParam(5, $new_quantity);
        $adjustment_stmt->bindParam(6, $adjustment_type);
        $adjustment_stmt->bindParam(7, $quantity);
        $adjustment_stmt->bindParam(8, $reason);
        $adjustment_stmt->bindParam(9, $notes);
        $adjustment_stmt->bindParam(10, $adjusted_by);
        $adjustment_stmt->execute();
        
        $adjustment_id = $db->lastInsertId();
        
        // Log activity
        $action_desc = "";
        switch($adjustment_type) {
            case 'add':
                $action_desc = "Added {$quantity} units to {$inventory['product_name']} in {$inventory['location']}";
                break;
            case 'remove':
                $action_desc = "Removed {$quantity} units from {$inventory['product_name']} in {$inventory['location']}";
                break;
            case 'set':
                $action_desc = "Set {$inventory['product_name']} quantity to {$quantity} in {$inventory['location']}";
                break;
        }
        
        $auth->logActivity(
            $_SESSION['user_id'], 
            'update', 
            'inventory', 
            $action_desc, 
            $adjustment_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back to inventory page
        header("Location: ../{$redirect_page}/inventory.php?success=adjustment");
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back with error
        header("Location: ../{$redirect_page}/inventory.php?error=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../index.php');
    exit;
}
?>