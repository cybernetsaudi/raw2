<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Validate required fields
if (!isset($_POST['batch_id']) || !isset($_POST['cost_type']) || !isset($_POST['amount']) || !isset($_POST['recorded_date'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$batch_id = intval($_POST['batch_id']);
$cost_type = trim($_POST['cost_type']);
$amount = floatval($_POST['amount']);
$recorded_date = trim($_POST['recorded_date']);
$description = isset($_POST['description']) ? trim($_POST['description']) : null;
$redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : null;

// Validate amount is positive
if (!is_numeric($amount) || $amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
    exit;
}

// Validate cost type
$valid_cost_types = ['labor', 'material', 'packaging', 'zipper', 'sticker', 'logo', 'tag', 'misc', 'overhead', 'electricity', 'maintenance', 'other'];
if (!in_array($cost_type, $valid_cost_types)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid cost type']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recorded_date)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Verify batch exists
    $batch_check = "SELECT id FROM manufacturing_batches WHERE id = ?";
    $batch_stmt = $db->prepare($batch_check);
    $batch_stmt->execute([$batch_id]);
    
    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }

    // Start transaction
    $db->beginTransaction();

    // Debug log the input data
    error_log("Attempting to save manufacturing cost with data: " . json_encode([
        'batch_id' => $batch_id,
        'cost_type' => $cost_type,
        'amount' => $amount,
        'recorded_date' => $recorded_date,
        'description' => $description,
        'recorded_by' => $_SESSION['user_id']
    ]));

    // Insert manufacturing cost record
    $query = "INSERT INTO manufacturing_costs (batch_id, cost_type, amount, recorded_date, description, recorded_by) 
              VALUES (:batch_id, :cost_type, :amount, :recorded_date, :description, :recorded_by)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $stmt->bindParam(':cost_type', $cost_type, PDO::PARAM_STR);
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':recorded_date', $recorded_date, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':recorded_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        $error_info = $stmt->errorInfo();
        $error_message = "Database error: " . $error_info[2] . "\nSQL State: " . $error_info[0] . "\nError Code: " . $error_info[1];
        error_log($error_message);
        throw new Exception($error_message);
    }

    // Log activity
    $activity_query = "INSERT INTO activity_logs (user_id, module, action_type, entity_id, description, created_at) 
                      VALUES (:user_id, 'manufacturing_costs', 'create', :entity_id, :description, NOW())";
    
    $activity_stmt = $db->prepare($activity_query);
    $description = "Recorded {$cost_type} cost of {$amount} for batch #" . $batch_id;
    
    $activity_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $activity_stmt->bindParam(':entity_id', $batch_id, PDO::PARAM_INT);
    $activity_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    
    if (!$activity_stmt->execute()) {
        $error_info = $activity_stmt->errorInfo();
        $error_message = "Activity logging error: " . $error_info[2] . "\nSQL State: " . $error_info[0] . "\nError Code: " . $error_info[1];
        error_log($error_message);
        throw new Exception($error_message);
    }

    // Commit transaction
    $db->commit();

    // If redirect URL is provided, redirect to it
    if ($redirect_url) {
        $separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
        header('Location: ' . $redirect_url . $separator . 'success=2');
        exit;
    }

    // Otherwise return JSON response
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Manufacturing cost recorded successfully']);

} catch (PDOException $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    $error_message = "Database error saving manufacturing cost: " . $e->getMessage() . "\nSQL State: " . $e->getCode() . "\nStack trace: " . $e->getTraceAsString();
    error_log($error_message);
    
    // If redirect URL is provided, redirect with error
    if ($redirect_url) {
        $separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
        header('Location: ' . $redirect_url . $separator . 'error=2&error_type=db&error_details=' . urlencode($e->getMessage()));
        exit;
    }

    // Otherwise return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    $error_message = "Error saving manufacturing cost: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString();
    error_log($error_message);
    
    // If redirect URL is provided, redirect with error
    if ($redirect_url) {
        $separator = (strpos($redirect_url, '?') !== false) ? '&' : '?';
        header('Location: ' . $redirect_url . $separator . 'error=2&error_type=general&error_details=' . urlencode($e->getMessage()));
        exit;
    }

    // Otherwise return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>