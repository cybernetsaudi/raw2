<?php
// api/confirm-receipt.php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Set content type to JSON for consistent response format
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

// Check if required parameters are provided
if (!isset($_POST['transfer_id']) || empty($_POST['transfer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Transfer ID is required']);
    exit;
}

$transfer_id = intval($_POST['transfer_id']);
$notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : null;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

try {
    // Initialize database connection
    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    // 1. Get transfer details to confirm it exists and is pending
    $transfer_query = "SELECT t.*, p.name as product_name 
                      FROM inventory_transfers t
                      JOIN products p ON t.product_id = p.id
                      WHERE t.id = ? AND t.status = 'pending'";
    $transfer_stmt = $db->prepare($transfer_query);
    $transfer_stmt->execute([$transfer_id]);
    
    if ($transfer_stmt->rowCount() === 0) {
        throw new Exception('Transfer not found or already processed');
    }
    
    $transfer = $transfer_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Validate shopkeeper has permission to confirm this transfer
    if ($transfer['shopkeeper_id'] != $_SESSION['user_id'] && $_SESSION['role'] !== 'owner') {
        throw new Exception('You do not have permission to confirm this transfer');
    }
    
    // 2. Check if inventory record exists for this product in transit
    $inventory_query = "SELECT id, quantity FROM inventory 
                       WHERE product_id = ? AND location = 'transit'";
    $inventory_stmt = $db->prepare($inventory_query);
    $inventory_stmt->execute([$transfer['product_id']]);
    
    if ($inventory_stmt->rowCount() === 0) {
        throw new Exception('No inventory found in transit for this product');
    }
    
    $transit_inventory = $inventory_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure there's enough quantity in transit
    if ($transit_inventory['quantity'] < $transfer['quantity']) {
        throw new Exception('Insufficient quantity in transit. Available: ' . 
                           $transit_inventory['quantity'] . ', Expected: ' . $transfer['quantity']);
    }
    
    // 3. Update transit inventory (reduce quantity)
    $update_transit_query = "UPDATE inventory 
                           SET quantity = quantity - ?, updated_at = NOW() 
                           WHERE id = ?";
    $update_transit_stmt = $db->prepare($update_transit_query);
    $update_transit_stmt->execute([$transfer['quantity'], $transit_inventory['id']]);
    
    // 4. Check if wholesale inventory exists for this product and shopkeeper
    $wholesale_query = "SELECT id FROM inventory 
                       WHERE product_id = ? AND location = 'wholesale' AND shopkeeper_id = ?";
    $wholesale_stmt = $db->prepare($wholesale_query);
    $wholesale_stmt->execute([$transfer['product_id'], $_SESSION['user_id']]);
    
    if ($wholesale_stmt->rowCount() > 0) {
        // Update existing wholesale inventory
        $wholesale_inventory = $wholesale_stmt->fetch(PDO::FETCH_ASSOC);
        $update_wholesale_query = "UPDATE inventory 
                                 SET quantity = quantity + ?, updated_at = NOW() 
                                 WHERE id = ?";
        $update_wholesale_stmt = $db->prepare($update_wholesale_query);
        $update_wholesale_stmt->execute([$transfer['quantity'], $wholesale_inventory['id']]);
    } else {
        // Create new wholesale inventory record
        $insert_wholesale_query = "INSERT INTO inventory 
                                 (product_id, quantity, location, shopkeeper_id, updated_at) 
                                 VALUES (?, ?, 'wholesale', ?, NOW())";
        $insert_wholesale_stmt = $db->prepare($insert_wholesale_query);
        $insert_wholesale_stmt->execute([
            $transfer['product_id'], 
            $transfer['quantity'],
            $_SESSION['user_id']
        ]);
    }
    
    // 5. Update the transfer status
    $update_transfer_query = "UPDATE inventory_transfers 
                             SET status = 'confirmed', 
                                 confirmed_by = ?, 
                                 confirmation_date = NOW() 
                             WHERE id = ?";
    $update_transfer_stmt = $db->prepare($update_transfer_query);
    $update_transfer_stmt->execute([$_SESSION['user_id'], $transfer_id]);
    
    // 6. Mark notification as read if provided
    if ($notification_id) {
        $update_notification_query = "UPDATE notifications 
                                     SET is_read = 1 
                                     WHERE id = ? AND user_id = ?";
        $update_notification_stmt = $db->prepare($update_notification_query);
        $update_notification_stmt->execute([$notification_id, $_SESSION['user_id']]);
    }
    
    // 7. Log this activity
    $auth = new Auth($db);
    $log_description = "Confirmed receipt of {$transfer['quantity']} units of {$transfer['product_name']}";
    if (!empty($notes)) {
        $log_description .= ". Notes: $notes";
    }
    
    $auth->logActivity(
        $_SESSION['user_id'],
        'update',
        'inventory',
        $log_description,
        $transfer_id
    );
    
    // 8. Notify the transfer initiator
    $notification_query = "INSERT INTO notifications 
                         (user_id, type, message, related_id, created_at) 
                         VALUES (?, 'transfer_confirmed', ?, ?, NOW())";
    $notification_message = "Transfer of {$transfer['quantity']} units of {$transfer['product_name']} has been confirmed by " . 
                           $_SESSION['full_name'];
    
    $notification_stmt = $db->prepare($notification_query);
    $notification_stmt->execute([
        $transfer['initiated_by'],
        $notification_message,
        $transfer_id
    ]);
    
    // Commit transaction
    $db->commit();
    
    // Return success response with detailed information for UI updates
    echo json_encode([
        'success' => true, 
        'message' => 'Inventory receipt confirmed successfully',
        'transfer' => [
            'id' => $transfer_id,
            'product_id' => $transfer['product_id'],
            'product_name' => $transfer['product_name'],
            'quantity' => $transfer['quantity'],
            'from_location' => $transfer['from_location'],
            'to_location' => $transfer['to_location'],
            'date_confirmed' => date('Y-m-d H:i:s')
        ],
        'notification_id' => $notification_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log error
    error_log('Confirm receipt error: ' . $e->getMessage());
    
    // Return error response
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>