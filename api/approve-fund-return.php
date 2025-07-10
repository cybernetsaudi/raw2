<?php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Set content type for consistent response format
header('Content-Type: application/json');

// Ensure user is logged in and is an owner
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
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
        if(!isset($data['return_id']) || !isset($data['action'])) {
            throw new Exception('Missing required fields');
        }
        
        $return_id = intval($data['return_id']);
        $action = trim($data['action']);
        $notes = isset($data['notes']) ? trim($data['notes']) : null;
        
        if(!in_array($action, ['approve', 'reject'])) {
            throw new Exception('Invalid action. Must be "approve" or "reject"');
        }
        
        // Get fund return details
        $return_query = "SELECT fr.*, s.net_amount, s.invoice_number, u.full_name as shopkeeper_name 
                        FROM fund_returns fr 
                        JOIN sales s ON fr.sale_id = s.id 
                        JOIN users u ON fr.returned_by = u.id 
                        WHERE fr.id = ? AND fr.status = 'pending'";
        $return_stmt = $db->prepare($return_query);
        $return_stmt->execute([$return_id]);
        $fund_return = $return_stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$fund_return) {
            throw new Exception('Invalid fund return or already processed');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Update fund return status
        $status_query = "UPDATE fund_returns 
                        SET status = ?, 
                            approved_by = ?, 
                            approved_at = NOW(),
                            notes = CONCAT(IFNULL(notes, ''), '\n', ?)
                        WHERE id = ?";
        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        $status_notes = $action === 'approve' ? 
                        "Approved by " . $_SESSION['full_name'] . ($notes ? ": $notes" : "") : 
                        "Rejected by " . $_SESSION['full_name'] . ($notes ? ": $notes" : "");
        
        $status_stmt = $db->prepare($status_query);
        $status_stmt->execute([
            $new_status,
            $_SESSION['user_id'],
            $status_notes,
            $return_id
        ]);
        
        if($action === 'approve') {
            // Create a new fund record for the returned amount
            $fund_query = "INSERT INTO funds (
                          amount, 
                          from_user_id, 
                          to_user_id, 
                          description, 
                          type, 
                          status, 
                          balance,
                          transfer_date
                          ) VALUES (?, ?, ?, ?, 'return', 'active', ?, NOW())";
            
            $description = "Return from sale #{$fund_return['invoice_number']} by {$fund_return['shopkeeper_name']}";
            if ($notes) {
                $description .= " - Notes: $notes";
            }
            
            $fund_stmt = $db->prepare($fund_query);
            $fund_stmt->execute([
                $fund_return['amount'],
                $fund_return['returned_by'],
                $_SESSION['user_id'],
                $description,
                $fund_return['amount']
            ]);
            
            $fund_id = $db->lastInsertId();
            
            // Create notification for shopkeeper
            $notification_query = "INSERT INTO notifications (
                                 user_id, 
                                 type, 
                                 message, 
                                 related_id, 
                                 created_at
                                 ) VALUES (?, 'fund_return_approved', ?, ?, NOW())";
            
            $notification_message = "Your fund return of {$fund_return['amount']} from sale #{$fund_return['invoice_number']} has been approved";
            
            $notification_stmt = $db->prepare($notification_query);
            $notification_stmt->execute([
                $fund_return['returned_by'],
                $notification_message,
                $return_id
            ]);
            
            // Log activity
            $auth->logActivity(
                $_SESSION['user_id'],
                'approve',
                'fund_returns',
                "Approved fund return of {$fund_return['amount']} from {$fund_return['shopkeeper_name']} for sale #{$fund_return['invoice_number']}",
                $fund_id
            );
        } else {
            // Create notification for shopkeeper about rejection
            $notification_query = "INSERT INTO notifications (
                                 user_id, 
                                 type, 
                                 message, 
                                 related_id, 
                                 created_at
                                 ) VALUES (?, 'fund_return_rejected', ?, ?, NOW())";
            
            $notification_message = "Your fund return of {$fund_return['amount']} from sale #{$fund_return['invoice_number']} has been rejected" . 
                                   ($notes ? ": $notes" : "");
            
            $notification_stmt = $db->prepare($notification_query);
            $notification_stmt->execute([
                $fund_return['returned_by'],
                $notification_message,
                $return_id
            ]);
            
            // Log activity for rejection
            $auth->logActivity(
                $_SESSION['user_id'],
                'reject',
                'fund_returns',
                "Rejected fund return of {$fund_return['amount']} from {$fund_return['shopkeeper_name']} for sale #{$fund_return['invoice_number']}" .
                ($notes ? ": $notes" : ""),
                $return_id
            );
        }
        
        $db->commit();
        
        // Return detailed response for UI updates
        echo json_encode([
            'success' => true,
            'message' => 'Fund return ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully',
            'return' => [
                'id' => $return_id,
                'status' => $new_status,
                'amount' => $fund_return['amount'],
                'shopkeeper' => $fund_return['shopkeeper_name'],
                'invoice' => $fund_return['invoice_number'],
                'sale_id' => $fund_return['sale_id'],
                'processed_at' => date('Y-m-d H:i:s'),
                'fund_id' => $action === 'approve' ? $fund_id : null
            ]
        ]);
        
    } catch(Exception $e) {
        if(isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        
        // Log error
        error_log('Fund return approval error: ' . $e->getMessage());
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Please use POST.']);
} 