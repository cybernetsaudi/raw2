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
if (!isset($_POST['batch_id']) || !isset($_POST['qc_status']) || !isset($_POST['qc_date'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$batch_id = $_POST['batch_id'];
$qc_status = $_POST['qc_status'];
$qc_date = $_POST['qc_date'];
$qc_notes = isset($_POST['qc_notes']) ? $_POST['qc_notes'] : null;
$redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : null;

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Start transaction
    $db->beginTransaction();

    // Insert quality check record
    $query = "INSERT INTO quality_control (batch_id, inspector_id, inspection_date, check_date, status, notes, created_by) 
              VALUES (:batch_id, :created_by, :check_date, :check_date, :status, :notes, :created_by)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $stmt->bindParam(':status', $qc_status, PDO::PARAM_STR);
    $stmt->bindParam(':check_date', $qc_date, PDO::PARAM_STR);
    $stmt->bindParam(':notes', $qc_notes, PDO::PARAM_STR);
    $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to save quality check record");
    }

    // Log activity
    $activity_query = "INSERT INTO activity_logs (user_id, module, action_type, entity_id, description, created_at) 
                      VALUES (:user_id, 'quality_control', 'create', :entity_id, :description, NOW())";
    
    $activity_stmt = $db->prepare($activity_query);
    $description = "Recorded quality check for batch #" . $batch_id . " with status: " . $qc_status;
    
    $activity_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $activity_stmt->bindParam(':entity_id', $batch_id, PDO::PARAM_INT);
    $activity_stmt->bindParam(':description', $description, PDO::PARAM_STR);
    
    if (!$activity_stmt->execute()) {
        throw new Exception("Failed to log activity");
    }

    // Commit transaction
    $db->commit();

    // If redirect URL is provided, redirect to it
    if ($redirect_url) {
        header('Location: ' . $redirect_url);
        exit;
    }

    // Otherwise return JSON response
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Quality check recorded successfully']);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Error saving quality check: " . $e->getMessage());
    
    // If redirect URL is provided, redirect with error
    if ($redirect_url) {
        header('Location: ' . $redirect_url . '&error=1');
        exit;
    }

    // Otherwise return JSON error response
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to save quality check']);
} 