<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Set content type to JSON
header('Content-Type: application/json');

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is a shopkeeper
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'shopkeeper') {
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
        // Start transaction
        $db->beginTransaction();
        
        // Get form data
        $invoice_number = $_POST['invoice_number'];
        $customer_id = $_POST['customer_id'];
        $sale_date = $_POST['sale_date'];
        $payment_due_date = $_POST['payment_due_date'];
        $total_amount = $_POST['total_amount'];
        $discount_amount = $_POST['discount_amount'] ?? 0;
        $tax_amount = $_POST['tax_amount'] ?? 0;
        $shipping_cost = $_POST['shipping_cost'] ?? 0;
        $net_amount = $_POST['net_amount'];
        $notes = $_POST['notes'] ?? null;
        $created_by = $_SESSION['user_id'];
        
        // Validate product items
        if(!isset($_POST['product_items']) || empty($_POST['product_items'])) {
            throw new Exception("No products added to sale.");
        }
        
        // Insert sale record
        $sale_query = "INSERT INTO sales (invoice_number, customer_id, sale_date, total_amount, 
                      discount_amount, tax_amount, shipping_cost, net_amount, payment_status, 
                      payment_due_date, notes, created_by) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?, ?)";
        
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->bindParam(1, $invoice_number);
        $sale_stmt->bindParam(2, $customer_id);
        $sale_stmt->bindParam(3, $sale_date);
        $sale_stmt->bindParam(4, $total_amount, PDO::PARAM_STR);
        $sale_stmt->bindParam(5, $discount_amount, PDO::PARAM_STR);
        $sale_stmt->bindParam(6, $tax_amount, PDO::PARAM_STR);
        $sale_stmt->bindParam(7, $shipping_cost, PDO::PARAM_STR);
        $sale_stmt->bindParam(8, $net_amount, PDO::PARAM_STR);
        $sale_stmt->bindParam(9, $payment_due_date);
        $sale_stmt->bindParam(10, $notes);
        $sale_stmt->bindParam(11, $created_by);
        
        $sale_stmt->execute();
        $sale_id = $db->lastInsertId();
        
        // Process product items
        foreach($_POST['product_items'] as $item) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            $unit_price = $item['unit_price'];
            $total_price = $quantity * $unit_price;
            
            // Check inventory availability
            $inventory_query = "SELECT quantity FROM inventory 
                               WHERE product_id = ? AND location = 'wholesale'";
            $inventory_stmt = $db->prepare($inventory_query);
            $inventory_stmt->bindParam(1, $product_id);
            $inventory_stmt->execute();
            $available = $inventory_stmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$available || $available['quantity'] < $quantity) {
                throw new Exception("Insufficient stock for one or more products.");
            }
            
            // Add sale item
            $item_query = "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price) 
                          VALUES (?, ?, ?, ?, ?)";
            $item_stmt = $db->prepare($item_query);
            $item_stmt->bindParam(1, $sale_id);
            $item_stmt->bindParam(2, $product_id);
            $item_stmt->bindParam(3, $quantity);
            $item_stmt->bindParam(4, $unit_price, PDO::PARAM_STR);
            $item_stmt->bindParam(5, $total_price, PDO::PARAM_STR);
            $item_stmt->execute();
            
            // Update inventory
            $update_inventory = "UPDATE inventory 
                                SET quantity = quantity - ? 
                                WHERE product_id = ? AND location = 'wholesale'";
            $update_stmt = $db->prepare($update_inventory);
            $update_stmt->bindParam(1, $quantity);
            $update_stmt->bindParam(2, $product_id);
            $update_stmt->execute();
        }
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'sales', 
            "Created new sale: Invoice #" . $invoice_number, 
            $sale_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Return success response
        echo json_encode([
            'success' => true,
            'message' => 'Sale created successfully',
            'sale_id' => $sale_id
        ]);
        
    } catch(Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        
        // Return error response
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    // Not a POST request
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
?>