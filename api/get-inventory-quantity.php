<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in and has appropriate role
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge' && $_SESSION['role'] !== 'shopkeeper')) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access'
    ]);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if required parameters are provided
if(!isset($_GET['product_id']) || !isset($_GET['location'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Missing required parameters. Please provide product_id and location.'
    ]);
    exit;
}

$product_id = intval($_GET['product_id']);
$location = trim($_GET['location']);

// Validate location parameter
$valid_locations = ['manufacturing', 'wholesale', 'transit'];
if(!in_array($location, $valid_locations)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid location parameter. Must be one of: manufacturing, wholesale, transit'
    ]);
    exit;
}

try {
    // Get product information first
    $product_query = "SELECT id, name, sku, description FROM products WHERE id = ?";
    $product_stmt = $db->prepare($product_query);
    $product_stmt->bindParam(1, $product_id);
    $product_stmt->execute();
    
    if($product_stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Product not found'
        ]);
        exit;
    }
    
    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get inventory quantity with additional metadata
    $query = "SELECT i.id, i.quantity, i.location, i.updated_at, 
             u.full_name as shopkeeper_name 
             FROM inventory i
             LEFT JOIN users u ON i.shopkeeper_id = u.id
             WHERE i.product_id = ? AND i.location = ?";
             
    // Add shopkeeper filter if applicable
    if($location === 'wholesale' && $_SESSION['role'] === 'shopkeeper') {
        $query .= " AND i.shopkeeper_id = ?";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $product_id);
    $stmt->bindParam(2, $location);
    
    // Bind shopkeeper ID if needed
    if($location === 'wholesale' && $_SESSION['role'] === 'shopkeeper') {
        $stmt->bindParam(3, $_SESSION['user_id']);
    }
    
    $stmt->execute();
    
    // Get recent activity for this product in this location
    $activity_query = "SELECT a.action_type, a.description, a.created_at, u.full_name as user_name
                      FROM activity_logs a
                      JOIN users u ON a.user_id = u.id
                      WHERE a.module = 'inventory' 
                      AND a.description LIKE ? 
                      ORDER BY a.created_at DESC 
                      LIMIT 5";
    $activity_stmt = $db->prepare($activity_query);
    $activity_param = '%' . $product['name'] . '%' . $location . '%';
    $activity_stmt->bindParam(1, $activity_param);
    $activity_stmt->execute();
    
    $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare transfers information if requested
    $transfers = [];
    if(isset($_GET['include_transfers']) && $_GET['include_transfers'] === 'true') {
        $transfers_query = "SELECT t.*, 
                          u1.full_name as initiated_by_name,
                          u2.full_name as confirmed_by_name
                          FROM inventory_transfers t
                          JOIN users u1 ON t.initiated_by = u1.id
                          LEFT JOIN users u2 ON t.confirmed_by = u2.id
                          WHERE t.product_id = ? 
                          AND (t.from_location = ? OR t.to_location = ?)
                          ORDER BY t.transfer_date DESC
                          LIMIT 10";
        $transfers_stmt = $db->prepare($transfers_query);
        $transfers_stmt->bindParam(1, $product_id);
        $transfers_stmt->bindParam(2, $location);
        $transfers_stmt->bindParam(3, $location);
        $transfers_stmt->execute();
        
        $transfers = $transfers_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if($stmt->rowCount() > 0) {
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Format the response with all necessary information
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'available' => true,
            'inventory' => [
                'id' => $inventory['id'],
                'quantity' => intval($inventory['quantity']),
                'location' => $inventory['location'],
                'last_updated' => $inventory['updated_at'],
                'shopkeeper' => $inventory['shopkeeper_name'] ?? null
            ],
            'product' => $product,
            'recent_activity' => $activities,
            'transfers' => $transfers,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        // Product exists but no inventory in this location
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'available' => false,
            'inventory' => [
                'quantity' => 0,
                'location' => $location
            ],
            'product' => $product,
            'recent_activity' => $activities,
            'transfers' => $transfers,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'No inventory found for this product in ' . $location . ' location'
        ]);
    }
} catch(Exception $e) {
    // Log error
    error_log('Inventory quantity API error: ' . $e->getMessage());
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while fetching inventory data: ' . $e->getMessage()
    ]);
}
?>