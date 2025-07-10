<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Set content type for AJAX requests
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
}

// Ensure user is logged in and is a shopkeeper
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'shopkeeper') {
    if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    } else {
        header('Location: ../index.php');
        exit;
    }
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Check if form was submitted
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Get form data
        $sale_id = isset($_POST['sale_id']) ? intval($_POST['sale_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $payment_date = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
        $payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
        $reference_number = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
        $recorded_by = $_SESSION['user_id'];
        
        // Validate required fields
        if($sale_id <= 0 || $amount <= 0 || empty($payment_date) || empty($payment_method)) {
            throw new Exception("Please fill in all required fields with valid values.");
        }
        
        // Validate date format
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new Exception("Invalid date format. Please use YYYY-MM-DD format.");
        }
        
        // Validate payment method
        $valid_methods = ['cash', 'bank_transfer', 'check', 'other'];
        if(!in_array($payment_method, $valid_methods)) {
            throw new Exception("Invalid payment method selected.");
        }
        
        // Validate sale exists and get current details
        $sale_query = "SELECT s.*, c.name as customer_name, 
                      (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as total_paid 
                      FROM sales s
                      JOIN customers c ON s.customer_id = c.id 
                      WHERE s.id = ?";
        $sale_stmt = $db->prepare($sale_query);
        $sale_stmt->bindParam(1, $sale_id);
        $sale_stmt->execute();
        
        if($sale_stmt->rowCount() === 0) {
            throw new Exception("Sale not found.");
        }
        
        $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate remaining balance
        $balance = $sale['net_amount'] - $sale['total_paid'];
        
        // Validate payment amount
        if($amount <= 0) {
            throw new Exception("Payment amount must be greater than zero.");
        }
        
        if($amount > $balance) {
            throw new Exception("Payment amount cannot exceed the remaining balance of " . number_format($balance, 2) . ".");
        }
        
        // Insert payment record
        $payment_query = "INSERT INTO payments (sale_id, amount, payment_date, payment_method, reference_number, notes, recorded_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $payment_stmt = $db->prepare($payment_query);
        $payment_stmt->bindParam(1, $sale_id);
        $payment_stmt->bindParam(2, $amount, PDO::PARAM_STR);
        $payment_stmt->bindParam(3, $payment_date);
        $payment_stmt->bindParam(4, $payment_method);
        $payment_stmt->bindParam(5, $reference_number);
        $payment_stmt->bindParam(6, $notes);
        $payment_stmt->bindParam(7, $recorded_by);
        
        $payment_stmt->execute();
        $payment_id = $db->lastInsertId();
        
        // Update sale payment status
        $new_total_paid = $sale['total_paid'] + $amount;
        $new_payment_status = 'unpaid';
        
        if($new_total_paid >= $sale['net_amount']) {
            $new_payment_status = 'paid';
        } else if($new_total_paid > 0) {
            $new_payment_status = 'partial';
        }
        
        $update_sale_query = "UPDATE sales SET payment_status = ? WHERE id = ?";
        $update_sale_stmt = $db->prepare($update_sale_query);
        $update_sale_stmt->bindParam(1, $new_payment_status);
        $update_sale_stmt->bindParam(2, $sale_id);
        $update_sale_stmt->execute();
        
        // Log activity
        $auth->logActivity(
            $_SESSION['user_id'], 
            'create', 
            'payments', 
            "Recorded payment of " . number_format($amount, 2) . " for sale #" . $sale_id . " (" . $sale['customer_name'] . ")", 
            $payment_id
        );
        
        // Calculate new remaining balance
        $new_balance = $sale['net_amount'] - $new_total_paid;
        
        // Commit transaction
        $db->commit();
        
        // Handle response based on request type
        if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX response
            echo json_encode([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'payment_id' => $payment_id,
                'payment' => [
                    'amount' => number_format($amount, 2),
                    'method' => ucfirst(str_replace('_', ' ', $payment_method)),
                    'date' => $payment_date,
                    'reference' => $reference_number
                ],
                'sale' => [
                    'id' => $sale_id,
                    'invoice' => $sale['invoice_number'],
                    'customer' => $sale['customer_name'],
                    'new_status' => $new_payment_status,
                    'total_paid' => number_format($new_total_paid, 2),
                    'remaining_balance' => number_format($new_balance, 2),
                    'is_fully_paid' => ($new_payment_status === 'paid')
                ]
            ]);
        } else {
            // Regular form submission
            // Redirect to sale view page
            header('Location: ../shopkeeper/view-sale.php?id=' . $sale_id . '&success=payment_added');
        }
        exit;
        
    } catch(Exception $e) {
        // Rollback transaction on error
        if($db->inTransaction()) {
            $db->rollBack();
        }
        
        // Log error
        error_log('Payment recording error: ' . $e->getMessage());
        
        // Handle error response based on request type
        if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            // AJAX error response
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        } else {
            // Regular form submission
            // Redirect back with error
            header('Location: ../shopkeeper/add-payment.php?sale_id=' . $_POST['sale_id'] . '&error=' . urlencode($e->getMessage()));
        }
        exit;
    }
} else {
    // Not a POST request
    if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        // AJAX error response
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method. Please use POST.'
        ]);
    } else {
        // Regular request
        header('Location: ../shopkeeper/sales.php');
    }
    exit;
}
?>