<?php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is an incharge
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
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
        if(!isset($data['fund_id']) || !isset($data['amount']) || !isset($data['type']) || !isset($data['reference_id'])) {
            throw new Exception('Missing required fields');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Check fund balance and ownership
        $fund_query = "SELECT balance FROM funds WHERE id = ? AND to_user_id = ? AND status = 'active'";
        $fund_stmt = $db->prepare($fund_query);
        $fund_stmt->execute([$data['fund_id'], $_SESSION['user_id']]);
        $fund = $fund_stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$fund) {
            throw new Exception('Invalid fund or insufficient balance');
        }
        
        if($fund['balance'] < $data['amount']) {
            throw new Exception('Insufficient fund balance');
        }
        
        // Record fund usage
        $usage_query = "INSERT INTO fund_usage (fund_id, amount, type, reference_id, used_by, notes) 
                       VALUES (?, ?, ?, ?, ?, ?)";
        $usage_stmt = $db->prepare($usage_query);
        $usage_stmt->execute([
            $data['fund_id'],
            $data['amount'],
            $data['type'],
            $data['reference_id'],
            $_SESSION['user_id'],
            $data['notes'] ?? null
        ]);
        
        // Update fund balance
        $update_query = "UPDATE funds SET balance = balance - ? WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$data['amount'], $data['fund_id']]);
        
        // Check if fund is depleted
        $check_query = "SELECT balance FROM funds WHERE id = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$data['fund_id']]);
        $new_balance = $check_stmt->fetchColumn();
        
        if($new_balance <= 0) {
            $status_query = "UPDATE funds SET status = 'depleted' WHERE id = ?";
            $status_stmt = $db->prepare($status_query);
            $status_stmt->execute([$data['fund_id']]);
        }
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'],
            'update',
            'funds',
            "Used {$data['amount']} from fund #{$data['fund_id']} for {$data['type']}",
            $data['fund_id']
        );
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Fund usage recorded successfully',
            'new_balance' => $new_balance
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