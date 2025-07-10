<?php
// api/log-activity.php
header('Content-Type: application/json');

// Include database configuration
include_once '../config/database.php';

// Get the posted data
$data = json_decode(file_get_contents("php://input"));

// Validate input
if(!isset($data->user_id) || !isset($data->action_type) || !isset($data->module) || !isset($data->description)) {
    echo json_encode(["success" => false, "message" => "Missing required parameters."]);
    exit;
}

// Sanitize input
$user_id = intval($data->user_id);
$action_type = htmlspecialchars(strip_tags($data->action_type));
$module = htmlspecialchars(strip_tags($data->module));
$description = htmlspecialchars(strip_tags($data->description));
$entity_id = isset($data->entity_id) ? intval($data->entity_id) : null;

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Prepare statement
$query = "INSERT INTO activity_logs (user_id, action_type, module, description, entity_id, ip_address, user_agent) 
          VALUES (:user_id, :action_type, :module, :description, :entity_id, :ip_address, :user_agent)";

$stmt = $db->prepare($query);

// Bind parameters
$stmt->bindParam(":user_id", $user_id);
$stmt->bindParam(":action_type", $action_type);
$stmt->bindParam(":module", $module);
$stmt->bindParam(":description", $description);
$stmt->bindParam(":entity_id", $entity_id);

// Get additional data
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];

$stmt->bindParam(":ip_address", $ip_address);
$stmt->bindParam(":user_agent", $user_agent);

// Execute query
try {
    if($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Activity logged successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to log activity."]);
    }
} catch(PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>