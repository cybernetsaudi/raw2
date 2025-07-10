<?php
class Auth {
    private $conn;
    private $table_name = "users";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function login($username, $password) {
        $query = "SELECT id, username, password, full_name, email, role FROM " . $this->table_name . " WHERE username = ? AND is_active = 1 LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        
        if($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $id = $row['id'];
            $username = $row['username'];
            $hashed_password = $row['password'];
            $full_name = $row['full_name'];
            $email = $row['email'];
            $role = $row['role'];
            
            if(password_verify($password, $hashed_password)) {
                session_start();
                $_SESSION['user_id'] = $id;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;
                
                // Log login activity
                $this->logActivity($id, 'login', 'auth', 'User logged in', null);
                
                return true;
            }
        }
        
        return false;
    }
    
    public function isLoggedIn() {
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['user_id']);
    }
    
    public function getUserRole() {
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['role']) ? $_SESSION['role'] : null;
    }
    
    public function logout() {
        if(session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if(isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            // Log logout activity
            $this->logActivity($user_id, 'logout', 'auth', 'User logged out', null);
        }
        
        session_unset();
        session_destroy();
        
        return true;
    }
    
    public function logActivity($user_id, $action_type, $module, $description, $entity_id = null) {
        $query = "INSERT INTO activity_logs (user_id, action_type, module, description, entity_id, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        $stmt->bindParam(1, $user_id);
        $stmt->bindParam(2, $action_type);
        $stmt->bindParam(3, $module);
        $stmt->bindParam(4, $description);
        $stmt->bindParam(5, $entity_id);
        $stmt->bindParam(6, $ip_address);
        $stmt->bindParam(7, $user_agent);
        
        return $stmt->execute();
    }
}
?>