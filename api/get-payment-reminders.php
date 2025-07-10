<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in
if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'shopkeeper') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get today's date
$today = date('Y-m-d');

// Query for sales with payments due today or overdue
$query = "SELECT s.id, s.invoice_number, c.name as customer_name, 
          s.net_amount, 
          (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id) as amount_paid,
          s.payment_due_date
          FROM sales s 
          JOIN customers c ON s.customer_id = c.id 
          WHERE s.payment_status IN ('unpaid', 'partial') 
          AND s.payment_due_date <= :today
          ORDER BY s.payment_due_date ASC";

$stmt = $db->prepare($query);
$stmt->bindParam(':today', $today);
$stmt->execute();

$reminders = [];

while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $amount_due = $row['net_amount'] - $row['amount_paid'];
    
    $reminders[] = [
        'id' => $row['id'],
        'invoice_number' => $row['invoice_number'],
        'customer_name' => $row['customer_name'],
        'amount_due' => $amount_due,
        'due_date' => $row['payment_due_date']
    ];
}

// Return reminders as JSON
header('Content-Type: application/json');
echo json_encode(['reminders' => $reminders]);
?>