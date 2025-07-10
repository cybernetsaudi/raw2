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
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
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
        $product_id = $_POST['product_id'] ?? null;
        $from_location = $_POST['from_location'] ?? null;
        $to_location = $_POST['to_location'] ?? null;
        $quantity = $_POST['quantity'] ?? null;
        $notes = $_POST['notes'] ?? null;
        $shopkeeper_id = $_POST['shopkeeper_id'] ?? null; // Added shopkeeper_id for transfers to wholesale
        $initiated_by = $_SESSION['user_id'];
        
        // Validate data
        if(empty($product_id) || empty($from_location) || empty($to_location) || empty($quantity)) {
            throw new Exception("Missing required fields. Please fill in all required fields.");
        }
        
        if(!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("Quantity must be a positive number.");
        }
        
        if($from_location === $to_location) {
            throw new Exception("Source and destination locations cannot be the same.");
        }
        
        // Validate location values
        $valid_locations = ['manufacturing', 'wholesale', 'transit'];
        if(!in_array($from_location, $valid_locations) || !in_array($to_location, $valid_locations)) {
            throw new Exception("Invalid location selected.");
        }
        
        // If transferring to wholesale, shopkeeper is required
        if($to_location === 'wholesale' && empty($shopkeeper_id)) {
            throw new Exception("Please select a shopkeeper for transfers to wholesale location.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Check if product exists in source location with sufficient quantity
        $check_query = "SELECT i.id, i.quantity, p.name as product_name 
                       FROM inventory i
                       JOIN products p ON i.product_id = p.id
                       WHERE i.product_id = ? AND i.location = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(1, $product_id);
        $check_stmt->bindParam(2, $from_location);
        $check_stmt->execute();
        
        if($check_stmt->rowCount() === 0) {
            throw new Exception("Product not found in the source location.");
        }
        
        $source_inventory = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if($source_inventory['quantity'] < $quantity) {
            throw new Exception("Insufficient quantity in source location. Available: {$source_inventory['quantity']}, Requested: {$quantity}");
        }
        
        // Deduct from source location
        $update_source_query = "UPDATE inventory 
                              SET quantity = quantity - ?, updated_at = NOW() 
                              WHERE id = ?";
        $update_source_stmt = $db->prepare($update_source_query);
        $update_source_stmt->bindParam(1, $quantity);
        $update_source_stmt->bindParam(2, $source_inventory['id']);
        $update_source_stmt->execute();
        
        // Handle destination - different logic based on to_location
        if($to_location === 'transit') {
            // Check if product exists in transit location
            $check_dest_query = "SELECT id FROM inventory 
                               WHERE product_id = ? AND location = 'transit'";
            $check_dest_stmt = $db->prepare($check_dest_query);
            $check_dest_stmt->bindParam(1, $product_id);
            $check_dest_stmt->execute();
            
            if($check_dest_stmt->rowCount() > 0) {
                // Update existing transit inventory
                $dest_inventory = $check_dest_stmt->fetch(PDO::FETCH_ASSOC);
                
                $update_dest_query = "UPDATE inventory 
                                    SET quantity = quantity + ?, updated_at = NOW() 
                                    WHERE id = ?";
                $update_dest_stmt = $db->prepare($update_dest_query);
                $update_dest_stmt->bindParam(1, $quantity);
                $update_dest_stmt->bindParam(2, $dest_inventory['id']);
                $update_dest_stmt->execute();
            } else {
                // Create new inventory record in transit
                $insert_dest_query = "INSERT INTO inventory 
                                    (product_id, location, quantity, updated_at) 
                                    VALUES (?, ?, ?, NOW())";
                $insert_dest_stmt = $db->prepare($insert_dest_query);
                $insert_dest_stmt->bindParam(1, $product_id);
                $insert_dest_stmt->bindParam(2, $to_location);
                $insert_dest_stmt->bindParam(3, $quantity);
                $insert_dest_stmt->execute();
            }
        } else if($to_location === 'wholesale') {
            // For wholesale, we need to include shopkeeper_id
            // Check if product exists in wholesale location with this shopkeeper
            $check_dest_query = "SELECT id FROM inventory 
                               WHERE product_id = ? AND location = 'wholesale' AND shopkeeper_id = ?";
            $check_dest_stmt = $db->prepare($check_dest_query);
            $check_dest_stmt->bindParam(1, $product_id);
            $check_dest_stmt->bindParam(2, $shopkeeper_id);
            $check_dest_stmt->execute();
            
            if($check_dest_stmt->rowCount() > 0) {
                // Update existing wholesale inventory
                $dest_inventory = $check_dest_stmt->fetch(PDO::FETCH_ASSOC);
                
                $update_dest_query = "UPDATE inventory 
                                    SET quantity = quantity + ?, updated_at = NOW() 
                                    WHERE id = ?";
                $update_dest_stmt = $db->prepare($update_dest_query);
                $update_dest_stmt->bindParam(1, $quantity);
                $update_dest_stmt->bindParam(2, $dest_inventory['id']);
                $update_dest_stmt->execute();
            } else {
                // Create new inventory record in wholesale
                $insert_dest_query = "INSERT INTO inventory 
                                    (product_id, location, quantity, shopkeeper_id, updated_at) 
                                    VALUES (?, ?, ?, ?, NOW())";
                $insert_dest_stmt = $db->prepare($insert_dest_query);
                $insert_dest_stmt->bindParam(1, $product_id);
                $insert_dest_stmt->bindParam(2, $to_location);
                $insert_dest_stmt->bindParam(3, $quantity);
                $insert_dest_stmt->bindParam(4, $shopkeeper_id);
                $insert_dest_stmt->execute();
            }
        } else {
            // For manufacturing or other locations
            $check_dest_query = "SELECT id FROM inventory 
                               WHERE product_id = ? AND location = ?";
            $check_dest_stmt = $db->prepare($check_dest_query);
            $check_dest_stmt->bindParam(1, $product_id);
            $check_dest_stmt->bindParam(2, $to_location);
            $check_dest_stmt->execute();
            
            if($check_dest_stmt->rowCount() > 0) {
                // Update existing destination inventory
                $dest_inventory = $check_dest_stmt->fetch(PDO::FETCH_ASSOC);
                
                $update_dest_query = "UPDATE inventory 
                                    SET quantity = quantity + ?, updated_at = NOW() 
                                    WHERE id = ?";
                $update_dest_stmt = $db->prepare($update_dest_query);
                $update_dest_stmt->bindParam(1, $quantity);
                $update_dest_stmt->bindParam(2, $dest_inventory['id']);
                $update_dest_stmt->execute();
            } else {
                // Create new inventory record in destination
                $insert_dest_query = "INSERT INTO inventory 
                                    (product_id, location, quantity, updated_at) 
                                    VALUES (?, ?, ?, NOW())";
                $insert_dest_stmt = $db->prepare($insert_dest_query);
                $insert_dest_stmt->bindParam(1, $product_id);
                $insert_dest_stmt->bindParam(2, $to_location);
                $insert_dest_stmt->bindParam(3, $quantity);
                $insert_dest_stmt->execute();
            }
        }
        
        // Record the transfer
        $transfer_query = "INSERT INTO inventory_transfers 
                          (product_id, from_location, to_location, quantity, transfer_date, 
                           status, notes, initiated_by, shopkeeper_id) 
                          VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)";
                          
        // Status is 'completed' immediately only if not going to transit, otherwise 'pending'
        $transfer_status = ($to_location === 'transit') ? 'pending' : 'completed';
        
        $transfer_stmt = $db->prepare($transfer_query);
        $transfer_stmt->bindParam(1, $product_id);
        $transfer_stmt->bindParam(2, $from_location);
        $transfer_stmt->bindParam(3, $to_location);
        $transfer_stmt->bindParam(4, $quantity);
        $transfer_stmt->bindParam(5, $transfer_status);
        $transfer_stmt->bindParam(6, $notes);
        $transfer_stmt->bindParam(7, $initiated_by);
        $transfer_stmt->bindParam(8, $shopkeeper_id);
        $transfer_stmt->execute();
        
        $transfer_id = $db->lastInsertId();
        
        // If transferring to transit, create notification for shopkeeper
        if($to_location === 'transit' && $shopkeeper_id) {
            $notification_query = "INSERT INTO notifications 
                                 (user_id, type, message, related_id, created_at) 
                                 VALUES (?, 'inventory_transfer', ?, ?, NOW())";
            $notification_message = "New inventory transfer: {$quantity} units of {$source_inventory['product_name']} are in transit to you.";
            
            $notification_stmt = $db->prepare($notification_query);
            $notification_stmt->bindParam(1, $shopkeeper_id);
            $notification_stmt->bindParam(2, $notification_message);
            $notification_stmt->bindParam(3, $transfer_id);
            $notification_stmt->execute();
        }
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'inventory_transfers', 
            "Transferred {$quantity} units of {$source_inventory['product_name']} from {$from_location} to {$to_location}", 
            $transfer_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Respond based on request format (AJAX or form)
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => "Successfully transferred {$quantity} units of {$source_inventory['product_name']} from {$from_location} to {$to_location}",
                'transfer_id' => $transfer_id
            ]);
            exit;
        } else {
            // Form submission
            // Determine which page to redirect to based on user role
            $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
            
            // Redirect back to inventory page
            header("Location: ../{$redirect_page}/inventory.php?success=transfer");
            exit;
        }
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Log error
        error_log('Inventory transfer error: ' . $e->getMessage());
        
        // Respond based on request format (AJAX or form)
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        } else {
            // Form submission
            // Determine which page to redirect to based on user role
            $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
            
            // Redirect back with error
            header("Location: ../{$redirect_page}/inventory.php?error=" . urlencode($e->getMessage()));
            exit;
        }
    }
} else {
    // Not a POST request
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Please use POST.']);
    exit;
}
?>