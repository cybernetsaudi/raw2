<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'shopkeeper') {
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
if(!isset($data['reminder_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing reminder ID']);
    exit;
}

$reminder_id = $data['reminder_id'];

// Add a note to the sale that customer was contacted
$query = "UPDATE sales SET notes = CONCAT(IFNULL(notes, ''), '\nCustomer contacted for payment on ', :date, ' by ', :username) WHERE id = :id";
$stmt = $db->prepare($query);
$date = date('Y-m-d H:i:s');
$username = $_SESSION['username'];
$stmt->bindParam(':date', $date);
$stmt->bindParam(':username', $username);
$stmt->bindParam(':id', $reminder_id);

if($stmt->execute()) {
    // Log the activity
    $auth->logActivity(
        $_SESSION['user_id'], 
        'update', 
        'payments', 
        'Contacted customer for payment on sale #' . $reminder_id, 
        $reminder_id
    );
    
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update record']);
}
?>