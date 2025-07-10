<?php
// api/get-user.php
header('Content-Type: application/json');

// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure only owner can access this endpoint
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(["error" => "Unauthorized access"]);
    exit;
}

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(["error" => "User ID is required"]);
    exit;
}

$user_id = intval($_GET['id']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Prepare statement
$query = "SELECT id, username, full_name, email, role, phone, is_active 
          FROM users 
          WHERE id = :user_id";

$stmt = $db->prepare($query);
$stmt->bindParam(":user_id", $user_id);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    // Fetch user data
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log activity
    logUserActivity($_SESSION['user_id'], 'read', 'users', "Viewed user details for: " . $user['username'], $user_id);
    
    // Return user data
    echo json_encode($user);
} else {
    echo json_encode(["error" => "User not found"]);
}
?>