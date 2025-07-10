<?php
// api/save-user.php
// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure only owner can access this endpoint
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    header('Location: ../index.php');
    exit;
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../owner/users.php');
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get form data
$user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
$username = htmlspecialchars(trim($_POST['username']));
$password = isset($_POST['password']) ? $_POST['password'] : '';
$full_name = htmlspecialchars(trim($_POST['full_name']));
$email = htmlspecialchars(trim($_POST['email']));
$role = htmlspecialchars(trim($_POST['role']));
$phone = htmlspecialchars(trim($_POST['phone']));
$is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

// Validate input
$errors = [];

if (empty($username)) {
    $errors[] = "Username is required";
} elseif (strlen($username) < 3) {
    $errors[] = "Username must be at least 3 characters";
}

if ($user_id === 0 && empty($password)) {
    $errors[] = "Password is required for new users";
} elseif (!empty($password) && strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters";
}

if (empty($full_name)) {
    $errors[] = "Full name is required";
}

if (empty($email)) {
    $errors[] = "Email is required";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format";
}

if (empty($role)) {
    $errors[] = "Role is required";
} elseif (!in_array($role, ['owner', 'incharge', 'shopkeeper'])) {
    $errors[] = "Invalid role selected";
}

// Check if username or email already exists
if ($user_id === 0) { // New user
    $check_query = "SELECT id FROM users WHERE username = :username OR email = :email";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(":username", $username);
    $check_stmt->bindParam(":email", $email);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $errors[] = "Username or email already exists";
    }
} else { // Existing user
    $check_query = "SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :user_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(":username", $username);
    $check_stmt->bindParam(":email", $email);
    $check_stmt->bindParam(":user_id", $user_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $errors[] = "Username or email already exists";
    }
}

// If there are errors, redirect back with error message
if (!empty($errors)) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
    header('Location: ../owner/users.php');
    exit;
}

try {
    // Begin transaction
    $db->beginTransaction();
    
    if ($user_id === 0) {
        // Create new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, password, full_name, email, role, phone, is_active) 
                  VALUES (:username, :password, :full_name, :email, :role, :phone, :is_active)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":password", $hashed_password);
        
        $action = "created";
    } else {
        // Update existing user
        if (!empty($password)) {
            // Update with new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET 
                      username = :username, password = :password, full_name = :full_name, 
                      email = :email, role = :role, phone = :phone, is_active = :is_active 
                      WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":password", $hashed_password);
            $stmt->bindParam(":user_id", $user_id);
        } else {
            // Update without changing password
            $query = "UPDATE users SET 
                      username = :username, full_name = :full_name, 
                      email = :email, role = :role, phone = :phone, is_active = :is_active 
                      WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":user_id", $user_id);
        }
        
        $action = "updated";
    }
    
    // Bind common parameters
    $stmt->bindParam(":username", $username);
    $stmt->bindParam(":full_name", $full_name);
    $stmt->bindParam(":email", $email);
    $stmt->bindParam(":role", $role);
    $stmt->bindParam(":phone", $phone);
    $stmt->bindParam(":is_active", $is_active);
    
    // Execute query
    if ($stmt->execute()) {
        // Get the user ID for new users
        if ($user_id === 0) {
            $user_id = $db->lastInsertId();
        }
        
        // Log activity
        logUserActivity($_SESSION['user_id'], $action === 'created' ? 'create' : 'update', 'users', "User {$action}: {$username}", $user_id);
        
        // Commit transaction
        $db->commit();
        
        // Set success message
        $_SESSION['success_message'] = "User has been {$action} successfully";
    } else {
        throw new Exception("Failed to {$action} user");
    }
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    // Set error message
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
}

// Redirect back to users page
header('Location: ../owner/users.php');
exit;
?>