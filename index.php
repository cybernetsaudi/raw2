<?php
// Start session
session_start();

// Include database and auth config
include_once 'config/database.php';
include_once 'config/auth.php';

// Instantiate database object
$database = new Database();
$db = $database->getConnection();

// Instantiate auth object
$auth = new Auth($db);

// Check if user is logged in
if($auth->isLoggedIn()) {
    // Redirect based on role
    $role = $auth->getUserRole();
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
            session_unset();
            session_destroy();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Garment Manufacturing System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-form">
            <h1>Garment Manufacturing System</h1>
            <form id="loginForm" action="login.php" method="post">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <button type="submit">Login</button>
                </div>
                <?php if(isset($_GET['error'])): ?>
                <div class="error-message">
                    Invalid username or password
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script src="assets/js/script.js"></script>
</body>
</html>