<?php
// Include database and auth config
include_once 'config/database.php';
include_once 'config/auth.php';

// Instantiate database object
$database = new Database();
$db = $database->getConnection();

// Instantiate auth object
$auth = new Auth($db);

// Logout user
$auth->logout();

// Redirect to login page
header('Location: index.php');
exit;
?>