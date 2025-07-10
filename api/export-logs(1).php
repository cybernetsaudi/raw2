<!-- api/export-logs.php -->
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

// Build query with filters
$where_clause = "";
$params = array();

if(isset($_GET['user_id']) && !empty($_GET['user_id'])) {
    $where_clause .= "l.user_id = :user_id AND ";
    $params[':user_id'] = $_GET['user_id'];
}

if(isset($_GET['action_type']) && !empty($_GET['action_type'])) {
    $where_clause .= "l.action_type = :action_type AND ";
    $params[':action_type'] = $_GET['action_type'];
}

if(isset($_GET['module']) && !empty($_GET['module'])) {
    $where_clause .= "l.module = :module AND ";
    $params[':module'] = $_GET['module'];
}

if(isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where_clause .= "DATE(l.created_at) >= :date_from AND ";
    $params[':date_from'] = $_GET['date_from'];
}

if(isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where_clause .= "DATE(l.created_at) <= :date_to AND ";
    $params[':date_to'] = $_GET['date_to'];
}

// Finalize where clause
if(!empty($where_clause)) {
    $where_clause = "WHERE " . substr($where_clause, 0, -5); // Remove the trailing AND
}

// Get activity logs
$logs_query = "SELECT l.id, u.username, l.action_type, l.module, l.description, l.ip_address, l.created_at 
              FROM activity_logs l 
              JOIN users u ON l.user_id = u.id 
              $where_clause
              ORDER BY l.created_at DESC";

$logs_stmt = $db->prepare($logs_query);
foreach($params as $param => $value) {
    $logs_stmt->bindValue($param, $value);
}
$logs_stmt->execute();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=activity_logs_' . date('Y-m-d') . '.csv');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add BOM to fix UTF-8 in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Set column headers
fputcsv($output, array('ID', 'User', 'Action', 'Module', 'Description', 'IP Address', 'Timestamp'));

// Fetch and write each row
while($row = $logs_stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, array(
        $row['id'],
        $row['username'],
        ucfirst($row['action_type']),
        ucfirst($row['module']),
        $row['description'],
        $row['ip_address'],
        $row['created_at']
    ));
}

// Log activity
$auth->logActivity(
    $_SESSION['user_id'], 
    'export', 
    'activity_logs', 
    "Exported activity logs to CSV"
);

// Close the file pointer
fclose($output);
exit;
?>