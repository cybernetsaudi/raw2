<!-- api/transfer-funds.php -->
<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is an owner
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
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
        $amount = $_POST['amount'];
        $to_user_id = $_POST['to_user_id'];
        $description = $_POST['description'] ?? null;
        $from_user_id = $_SESSION['user_id'];
        
        // Validate amount
        if(!is_numeric($amount) || $amount <= 0) {
            throw new Exception("Invalid amount. Please enter a positive number.");
        }
        
        // Validate recipient
        $user_query = "SELECT id, full_name, role FROM users WHERE id = ? AND is_active = 1";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(1, $to_user_id);
        $user_stmt->execute();
        
        if($user_stmt->rowCount() === 0) {
            throw new Exception("Invalid recipient selected.");
        }
        
        $recipient = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Ensure recipient is an incharge
        if($recipient['role'] !== 'incharge') {
            throw new Exception("Funds can only be transferred to incharge users.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Insert fund transfer record
        $query = "INSERT INTO funds (amount, from_user_id, to_user_id, description, transfer_date) 
                 VALUES (?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $amount);
        $stmt->bindParam(2, $from_user_id);
        $stmt->bindParam(3, $to_user_id);
        $stmt->bindParam(4, $description);
        $stmt->execute();
        
        $transfer_id = $db->lastInsertId();
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'funds', 
            "Transferred {$amount} to {$recipient['full_name']}", 
            $transfer_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Redirect back to financial page
        header('Location: ../owner/financial.php?success=1');
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Redirect back with error
        header('Location: ../owner/financial.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../owner/financial.php');
    exit;
}
?>