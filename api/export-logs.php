<?php
// api/export-logs.php
// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is owner
if (!hasRole('owner')) {
    header('Location: ../index.php');
    exit;
}

// Get filter parameters
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

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set filename for export
$filename = 'activity_logs_' . date('Y-m-d_His') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Write headers
fputcsv($output, ['Timestamp', 'User', 'Action', 'Module', 'Description', 'IP Address', 'User Agent']);

try {
    // Get activity logs
    $query = "SELECT l.created_at, u.username, l.action_type, l.module, l.description, l.ip_address, l.user_agent 
              FROM activity_logs l 
              JOIN users u ON l.user_id = u.id 
              $where_clause
              ORDER BY l.created_at DESC";
    
    $stmt = $db->prepare($query);
    
    // Bind parameters
    foreach($params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    
    $stmt->execute();
    
    // Write data
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    
    // Log activity
    $filter_description = "Exported activity logs";
    
    if (!empty($params)) {
        $filter_description .= " with filters: ";
        $filter_parts = [];
        
        if (isset($params[':user_id'])) {
            $user_query = "SELECT username FROM users WHERE id = :user_id";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindValue(':user_id', $params[':user_id']);
            $user_stmt->execute();
            $username = $user_stmt->fetchColumn();
            $filter_parts[] = "user: {$username}";
        }
        
        if (isset($params[':action_type'])) {
            $filter_parts[] = "action: {$params[':action_type']}";
        }
        
        if (isset($params[':module'])) {
            $filter_parts[] = "module: {$params[':module']}";
        }
        
        if (isset($params[':date_from'])) {
            $filter_parts[] = "from: {$params[':date_from']}";
        }
        
        if (isset($params[':date_to'])) {
            $filter_parts[] = "to: {$params[':date_to']}";
        }
        
        $filter_description .= implode(", ", $filter_parts);
    }
    
    logUserActivity($_SESSION['user_id'], 'export', 'activity_logs', $filter_description);
    
} catch (Exception $e) {
    // Write error to CSV
    fputcsv($output, ['Error: ' . $e->getMessage()]);
}

// Close the file pointer
fclose($output);
exit;
?>