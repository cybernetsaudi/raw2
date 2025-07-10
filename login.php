<?php
// Include database and auth config
include_once 'config/database.php';
include_once 'config/auth.php';

// Instantiate database object
$database = new Database();
$db = $database->getConnection();

// Instantiate auth object
$auth = new Auth($db);

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    // Attempt login
    if($auth->login($username, $password)) {
        // Get user role
        $role = $auth->getUserRole();
        
        // Redirect based on role
        switch($role) {
            case 'owner':
                header('Location: owner/dashboard.php');
                break;
            case 'incharge':
                header('Location: incharge/dashboard.php');
                break;
            case 'shopkeeper':
                header('Location: shopkeeper/dashboard.php');
                break;
            default:
                // Handle invalid role
                header('Location: index.php?error=1');
        }
        exit;
    } else {
        // Login failed
        header('Location: index.php?error=1');
        exit;
    }
} else {
    // Not a POST request
    header('Location: index.php');
    exit;
}
?>