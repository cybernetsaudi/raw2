<!-- api/save-manufacturing-cost.php -->
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
        $cost_type = $_POST['cost_type'];
        $amount = $_POST['amount'];
        $recorded_date = $_POST['recorded_date'];
        $description = $_POST['description'] ?? null;
        $recorded_by = $_SESSION['user_id'];
        
        // Validate data
        if(empty($batch_id) || empty($cost_type) || empty($amount) || empty($recorded_date)) {
            throw new Exception("Missing required fields.");
        }
        
        if(!is_numeric($amount) || $amount <= 0) {
            throw new Exception("Amount must be a positive number.");
        }
        
        // Validate cost type
        $valid_cost_types = ['labor', 'overhead', 'electricity', 'maintenance', 'other'];
        if(!in_array($cost_type, $valid_cost_types)) {
            throw new Exception("Invalid cost type selected.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Verify batch exists
        $batch_query = "SELECT batch_number FROM manufacturing_batches WHERE id = ?";
        $batch_stmt = $db->prepare($batch_query);
        $batch_stmt->bindParam(1, $batch_id);
        $batch_stmt->execute();
        
        if($batch_stmt->rowCount() === 0) {
            throw new Exception("Batch not found.");
        }
        
        $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Insert cost record
        $insert_query = "INSERT INTO manufacturing_costs (batch_id, cost_type, amount, recorded_date, description, recorded_by) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(1, $batch_id);
        $insert_stmt->bindParam(2, $cost_type);
        $insert_stmt->bindParam(3, $amount);
        $insert_stmt->bindParam(4, $recorded_date);
        $insert_stmt->bindParam(5, $description);
        $insert_stmt->bindParam(6, $recorded_by);
        $insert_stmt->execute();
        
        $cost_id = $db->lastInsertId();
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'manufacturing_costs', 
            "Recorded {$cost_type} cost of {$amount} for batch #{$batch['batch_number']}", 
            $cost_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Determine which page to redirect to based on user role
        $redirect_page = $_SESSION['role'] === 'owner' ? 'owner' : 'incharge';
        
        // Redirect back to batch view page
        header("Location: ../{$redirect_page}/view-batch.php?id={$batch_id}&success=2");
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