<?php
// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Set content type to JSON for consistent response format
header('Content-Type: application/json');

// Ensure user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $address = isset($_POST['address']) ? trim($_POST['address']) : null;
        $created_by = $_SESSION['user_id']; // Use the logged-in user's ID

        // Validate data
        if (empty($name)) {
            throw new Exception("Customer name is required.");
        }

        if (empty($phone)) {
            throw new Exception("Phone number is required.");
        }

        // Validate email format if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Validate phone number (basic validation)
        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new Exception("Phone number must be 10-15 digits.");
        }

        // Start transaction
        $db->beginTransaction();

        if ($customer_id) {
            // Check if customer exists and belongs to this user
                        // Check if customer exists and belongs to this user
            $check_query = "SELECT id FROM customers WHERE id = ? AND created_by = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(1, $customer_id);
            $check_stmt->bindParam(2, $created_by);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                throw new Exception("You don't have permission to edit this customer or customer doesn't exist.");
            }
            
            // Update existing customer
            $query = "UPDATE customers 
                     SET name = :name, email = :email, phone = :phone, address = :address, updated_at = NOW()
                     WHERE id = :customer_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':customer_id', $customer_id, PDO::PARAM_INT);
            $stmt->execute();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'update',
                'customers',
                "Updated customer: {$name}",
                $customer_id
            );

            $message = "Customer updated successfully.";
        } else {
            // Check if customer with same phone already exists
            $check_query = "SELECT id FROM customers WHERE phone = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(1, $phone);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("A customer with this phone number already exists.");
            }
            
            // Create new customer
            $query = "INSERT INTO customers (name, email, phone, address, created_by, created_at, updated_at)
                     VALUES (:name, :email, :phone, :address, :created_by, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':created_by', $created_by, PDO::PARAM_INT);
            $stmt->execute();

            $customer_id = $db->lastInsertId();

            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'create',
                'customers',
                "Created new customer: {$name}",
                $customer_id
            );

            $message = "Customer created successfully.";
        }

        // Commit transaction
        $db->commit();

        // Return JSON response with customer details for front-end use
        echo json_encode([
            'success' => true,
            'message' => $message,
            'customer' => [
                'id' => $customer_id,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address
            ]
        ]);
        exit;

    } catch (Exception $e) {
        // Rollback transaction
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Return JSON error response for AJAX
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
} else {
    // Not a POST request
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Please use POST.'
    ]);
    exit;
}
?>