<!-- api/save-purchase.php -->
<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is an incharge
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
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
        $material_id = $_POST['material_id'];
        $quantity = $_POST['quantity'];
        $unit_price = $_POST['unit_price'];
        $total_amount = $_POST['total_amount'];
        $vendor_name = $_POST['vendor_name'];
        $vendor_contact = $_POST['vendor_contact'] ?? null;
        $invoice_number = $_POST['invoice_number'] ?? null;
        $purchase_date = $_POST['purchase_date'];
        $purchased_by = $_SESSION['user_id'];
        
        // Validate data
        if(!is_numeric($quantity) || $quantity <= 0) {
            throw new Exception("Invalid quantity. Please enter a positive number.");
        }
        
        if(!is_numeric($unit_price) || $unit_price <= 0) {
            throw new Exception("Invalid unit price. Please enter a positive number.");
        }
        
        if(!is_numeric($total_amount) || $total_amount <= 0) {
            throw new Exception("Invalid total amount. Please check your calculations.");
        }
        
        // Verify the total amount calculation
        $calculated_total = $quantity * $unit_price;
        if(abs($calculated_total - $total_amount) > 0.01) { // Allow for small floating point differences
            throw new Exception("Total amount calculation mismatch. Please check your inputs.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Insert purchase record
        $query = "INSERT INTO purchases (material_id, quantity, unit_price, total_amount, vendor_name, 
                 vendor_contact, invoice_number, purchase_date, purchased_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $material_id);
        $stmt->bindParam(2, $quantity);
        $stmt->bindParam(3, $unit_price);
        $stmt->bindParam(4, $total_amount);
        $stmt->bindParam(5, $vendor_name);
        $stmt->bindParam(6, $vendor_contact);
        $stmt->bindParam(7, $invoice_number);
        $stmt->bindParam(8, $purchase_date);
        $stmt->bindParam(9, $purchased_by);
        $stmt->execute();
        
        $purchase_id = $db->lastInsertId();
        
        // Update material stock
        $update_query = "UPDATE raw_materials SET stock_quantity = stock_quantity + ?, updated_at = NOW() WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(1, $quantity);
        $update_stmt->bindParam(2, $material_id);
        $update_stmt->execute();
        
        // Get material name for activity log
        $material_query = "SELECT name FROM raw_materials WHERE id = ?";
        $material_stmt = $db->prepare($material_query);
        $material_stmt->bindParam(1, $material_id);
        $material_stmt->execute();
        $material_name = $material_stmt->fetch(PDO::FETCH_ASSOC)['name'];
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'purchases', 
            "Purchased {$quantity} of {$material_name} for {$total_amount}", 
            $purchase_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Redirect back to purchases page
        header('Location: ../incharge/purchases.php?success=1');
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Redirect back with error
        header('Location: ../incharge/add-purchase.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../incharge/purchases.php');
    exit;
}
?>