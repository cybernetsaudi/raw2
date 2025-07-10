<!-- api/toggle-user-activation.php -->
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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if(!isset($data['user_id']) || !isset($data['activate'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$user_id = $data['user_id'];
$activate = $data['activate'] ? 1 : 0;

// Prevent deactivating your own account
if($user_id == $_SESSION['user_id']) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You cannot change the status of your own account']);
    exit;
}

try {
    // Get user information for logging
    $user_query = "SELECT username FROM users WHERE id = ?";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(1, $user_id);
    $user_stmt->execute();
    $username = $user_stmt->fetch(PDO::FETCH_ASSOC)['username'] ?? 'Unknown user';
    
    // Update user status
    $query = "UPDATE users SET is_active = ? WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $activate);
    $stmt->bindParam(2, $user_id);
    $stmt->execute();
    
    // Log activity
    $action_description = $activate ? "Activated user: $username" : "Deactivated user: $username";
    $auth->logActivity(
        $_SESSION['user_id'], 
        'update', 
        'users', 
        $action_description, 
        $user_id
    );
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'User status updated successfully']);
    
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>