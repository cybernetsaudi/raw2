<?php
// api/get-user.php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in and is an owner
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if user ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'User ID is required']);
    exit;
}

$user_id = $_GET['id'];

try {
    // Get user details
    $query = "SELECT id, username, full_name, email, role, is_active 
              FROM users 
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $user_id);
    $stmt->execute();
    
    if($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Return user data
    header('Content-Type: application/json');
    echo json_encode($user);
    
} catch(PDOException $e) {
    // Log the error server-side
    error_log('Database error in get-user.php: ' . $e->getMessage());
    
    // Return a generic error message to the client
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error occurred']);
}
?>