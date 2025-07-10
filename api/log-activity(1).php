<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in
if(!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
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
if(!isset($data['action_type']) || !isset($data['module']) || !isset($data['description'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Log the activity
$user_id = $_SESSION['user_id'];
$action_type = $data['action_type'];
$module = $data['module'];
$description = $data['description'];
$entity_id = isset($data['entity_id']) ? $data['entity_id'] : null;

// Log activity
$result = $auth->logActivity($user_id, $action_type, $module, $description, $entity_id);

if($result) {
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to log activity']);
}
?>