<?php
// api/toggle-user-activation.php
header('Content-Type: application/json');

// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure only owner can access this endpoint
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(["success" => false, "message" => "Unauthorized access"]);
    exit;
}

// Get the posted data
$data = json_decode(file_get_contents("php://input"));

// Validate input
if (!isset($data->user_id) || !isset($data->activate)) {
    echo json_encode(["success" => false, "message" => "Missing required parameters"]);
    exit;
}

$user_id = intval($data->user_id);
$activate = boolval($data->activate);

// Prevent users from deactivating their own account
if ($user_id === intval($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "You cannot change the status of your own account"]);
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// First, get the user's current info for logging
$user_query = "SELECT username, is_active FROM users WHERE id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(":user_id", $user_id);
$user_stmt->execute();

if ($user_stmt->rowCount() === 0) {
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Check if the status is already set to the requested value
if (($activate && $user['is_active'] == 1) || (!$activate && $user['is_active'] == 0)) {
    echo json_encode([
        "success" => true, 
        "message" => "User status already set to " . ($activate ? "active" : "inactive")
    ]);
    exit;
}

// Prepare statement to update user status
$query = "UPDATE users SET is_active = :is_active WHERE id = :user_id";
$stmt = $db->prepare($query);
$is_active = $activate ? 1 : 0;
$stmt->bindParam(":is_active", $is_active);
$stmt->bindParam(":user_id", $user_id);

// Execute query
try {
    if ($stmt->execute()) {
        // Log activity
        $action = $activate ? "activated" : "deactivated";
        logUserActivity(
            $_SESSION['user_id'], 
            'update', 
            'users', 
            "User account {$action}: " . $user['username'],
            $user_id
        );
        
        echo json_encode([
            "success" => true, 
            "message" => "User has been " . ($activate ? "activated" : "deactivated") . " successfully"
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to update user status"]);
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>