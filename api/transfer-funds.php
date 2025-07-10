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
    // Return JSON for AJAX requests
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    } else {
        header('Location: ../index.php');
        exit;
    }
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
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        $to_user_id = isset($_POST['to_user_id']) ? trim($_POST['to_user_id']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : null;
        $from_user_id = $_SESSION['user_id'];
        
        // Validate amount
        if(empty($amount) || !is_numeric($amount) || floatval($amount) <= 0) {
            throw new Exception("Invalid amount. Please enter a positive number.");
        }
        
        // Format amount to 2 decimal places
        $amount = number_format(floatval($amount), 2, '.', '');
        
        // Validate recipient
        if(empty($to_user_id)) {
            throw new Exception("Please select a recipient.");
        }
        
        $user_query = "SELECT id, full_name, role FROM users WHERE id = ? AND is_active = 1";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(1, $to_user_id);
        $user_stmt->execute();
        
        if($user_stmt->rowCount() === 0) {
            throw new Exception("Invalid recipient selected or user is inactive.");
        }
        
        $recipient = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Ensure recipient is an incharge
        if($recipient['role'] !== 'incharge') {
            throw new Exception("Funds can only be transferred to incharge users.");
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Insert fund transfer record
        $query = "INSERT INTO funds (amount, from_user_id, to_user_id, description, transfer_date, type, status, balance) 
                 VALUES (?, ?, ?, ?, NOW(), 'investment', 'active', ?)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $amount);
        $stmt->bindParam(2, $from_user_id);
        $stmt->bindParam(3, $to_user_id);
        $stmt->bindParam(4, $description);
        $stmt->bindParam(5, $amount); // Set initial balance equal to amount
        $stmt->execute();
        
        $transfer_id = $db->lastInsertId();
        
        // Create notification for recipient
        $notification_query = "INSERT INTO notifications (user_id, type, message, related_id, created_at) 
                             VALUES (?, 'fund_transfer', ?, ?, NOW())";
        $notification_message = "You have received a fund transfer of Rs.{$amount} from " . $_SESSION['full_name'];
        
        $notification_stmt = $db->prepare($notification_query);
        $notification_stmt->bindParam(1, $to_user_id);
        $notification_stmt->bindParam(2, $notification_message);
        $notification_stmt->bindParam(3, $transfer_id);
        $notification_stmt->execute();
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'funds', 
            "Transferred Rs.{$amount} to {$recipient['full_name']}", 
            $transfer_id
        );
        
        // Commit transaction
        $db->commit();
        
        // Handle response based on request type (AJAX or form)
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => "Successfully transferred Rs.{$amount} to {$recipient['full_name']}",
                'transfer_id' => $transfer_id,
                'amount' => $amount,
                'recipient' => [
                    'id' => $recipient['id'],
                    'name' => $recipient['full_name']
                ]
            ]);
        } else {
            // Regular form submission
            header('Location: ../owner/financial.php?success=1&message=' . urlencode("Successfully transferred Rs.{$amount} to {$recipient['full_name']}"));
        }
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Log error
        error_log('Fund transfer error: ' . $e->getMessage());
        
        // Handle error response based on request type
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX request
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } else {
            // Regular form submission
            header('Location: ../owner/financial.php?error=' . urlencode($e->getMessage()));
        }
        exit;
    }
} else {
    // Not a POST request
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        // AJAX request
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request method. Please use POST.']);
    } else {
        // Regular request
        header('Location: ../owner/financial.php');
    }
    exit;
}
?>