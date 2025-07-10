<?php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is a shopkeeper
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'shopkeeper') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if(!isset($data['sale_id']) || !isset($data['amount']) || !isset($data['notes'])) {
            throw new Exception('Missing required fields');
        }
        
        // Validate sale exists and belongs to shopkeeper
        $sale_query = "SELECT s.*, c.name as customer_name 
                      FROM sales s 
                      JOIN customers c ON s.customer_id = c.id 
                      WHERE s.id = ? AND s.shopkeeper_id = ?";
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->execute([$data['sale_id'], $_SESSION['user_id']]);
        $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$sale) {
            throw new Exception('Invalid sale or unauthorized access');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Record fund return
        $return_query = "INSERT INTO fund_returns (sale_id, amount, returned_by, notes) 
                        VALUES (?, ?, ?, ?)";
        $return_stmt = $db->prepare($return_query);
        $return_stmt->execute([
            $data['sale_id'],
            $data['amount'],
            $_SESSION['user_id'],
            $data['notes']
        ]);
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'],
            'create',
            'fund_returns',
            "Returned {$data['amount']} from sale #{$data['sale_id']} to owner",
            $data['sale_id']
        );
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Fund return request submitted successfully'
        ]);
        
    } catch(Exception $e) {
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}