<!-- api/save-user.php -->
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

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $user_id = isset($_POST['user_id']) && !empty($_POST['user_id']) ? $_POST['user_id'] : null;
        $username = $_POST['username'];
        $password = $_POST['password'];
        $full_name = $_POST['full_name'];
        $email = $_POST['email'];
        $role = $_POST['role'];
        $phone = $_POST['phone'] ?? null;
        $is_active = $_POST['is_active'];
        
        // Start transaction
        $db->beginTransaction();
        
        if($user_id) {
            // Update existing user
            if(!empty($password)) {
                // Update with new password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $query = "UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $username);
                $stmt->bindParam(2, $hashed_password);
                $stmt->bindParam(3, $full_name);
                $stmt->bindParam(4, $email);
                $stmt->bindParam(5, $role);
                $stmt->bindParam(6, $phone);
                $stmt->bindParam(7, $is_active);
                $stmt->bindParam(8, $user_id);
            } else {
                // Update without changing password
                $query = "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, phone = ?, is_active = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $username);
                $stmt->bindParam(2, $full_name);
                $stmt->bindParam(3, $email);
                $stmt->bindParam(4, $role);
                $stmt->bindParam(5, $phone);
                $stmt->bindParam(6, $is_active);
                $stmt->bindParam(7, $user_id);
            }
            
            $stmt->execute();
            
            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'], 
                'update', 
                'users', 
                "Updated user: $username", 
                $user_id
            );
        } else {
            // Create new user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO users (username, password, full_name, email, role, phone, is_active) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $username);
            $stmt->bindParam(2, $hashed_password);
            $stmt->bindParam(3, $full_name);
            $stmt->bindParam(4, $email);
            $stmt->bindParam(5, $role);
            $stmt->bindParam(6, $phone);
            $stmt->bindParam(7, $is_active);
            $stmt->execute();
            
            $user_id = $db->lastInsertId();
            
            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'], 
                'create', 
                'users', 
                "Created new user: $username", 
                $user_id
            );
        }
        
        // Commit transaction
        $db->commit();
        
        // Redirect to users page
        header('Location: ../owner/users.php?success=1');
        exit;
        
    } catch(PDOException $e) {
        // Rollback transaction
        $db->rollBack();
        
        // Check for duplicate entry error
        if($e->getCode() == 23000) {
            header('Location: ../owner/users.php?error=duplicate');
        } else {
            header('Location: ../owner/users.php?error=database');
        }
        exit;
    }
} else {
    // Not a POST request
    header('Location: ../owner/users.php');
    exit;
}
?>