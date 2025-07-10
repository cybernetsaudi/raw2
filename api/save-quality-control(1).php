<?php
// api/save-quality-control.php
// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../owner/manufacturing.php');
    exit;
}

// Get form data
$batch_id = isset($_POST['batch_id']) ? intval($_POST['batch_id']) : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';
$defects_found = isset($_POST['defects_found']) ? intval($_POST['defects_found']) : 0;
$defect_description = isset($_POST['defect_description']) ? htmlspecialchars(trim($_POST['defect_description'])) : '';
$remedial_action = isset($_POST['remedial_action']) ? htmlspecialchars(trim($_POST['remedial_action'])) : '';
$notes = isset($_POST['notes']) ? htmlspecialchars(trim($_POST['notes'])) : '';
$redirect_url = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : '../owner/manufacturing.php';

// Validate input
if ($batch_id === 0 || empty($status)) {
    $_SESSION['error_message'] = "Batch ID and status are required";
    header('Location: ' . $redirect_url);
    exit;
}

// Validate status
$valid_statuses = ['passed', 'failed', 'pending_rework'];
if (!in_array($status, $valid_statuses)) {
    $_SESSION['error_message'] = "Invalid quality control status";
    header('Location: ' . $redirect_url);
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Begin transaction
    $db->beginTransaction();
    
    // Check if batch exists
    $batch_query = "SELECT batch_number FROM manufacturing_batches WHERE id = :batch_id";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->bindParam(":batch_id", $batch_id);
    $batch_stmt->execute();
    
    if ($batch_stmt->rowCount() === 0) {
        throw new Exception("Batch not found");
    }
    
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    $batch_number = $batch['batch_number'];
    
    // Insert quality control record
    $query = "INSERT INTO quality_control 
              (batch_id, inspector_id, status, defects_found, defect_description, remedial_action, notes) 
              VALUES (:batch_id, :inspector_id, :status, :defects_found, :defect_description, :remedial_action, :notes)";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":batch_id", $batch_id);
    $stmt->bindParam(":inspector_id", $_SESSION['user_id']);
    $stmt->bindParam(":status", $status);
    $stmt->bindParam(":defects_found", $defects_found);
    $stmt->bindParam(":defect_description", $defect_description);
    $stmt->bindParam(":remedial_action", $remedial_action);
    $stmt->bindParam(":notes", $notes);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to record quality control");
    }
    
    $qc_id = $db->lastInsertId();
    
    // If status is 'failed' or 'pending_rework', update batch status to reflect this
    if ($status === 'failed' || $status === 'pending_rework') {
        // Only update batch if it's in a completed or packaging stage
        $update_batch_query = "UPDATE manufacturing_batches 
                              SET status = 'ironing'
                              WHERE id = :batch_id 
                              AND status IN ('completed', 'packaging')";
        $update_batch_stmt = $db->prepare($update_batch_query);
        $update_batch_stmt->bindParam(":batch_id", $batch_id);
        $update_batch_stmt->execute();
        
        // If batch status was changed, record in history
        if ($update_batch_stmt->rowCount() > 0) {
            $history_query = "INSERT INTO batch_status_history 
                             (batch_id, previous_status, new_status, changed_by, notes) 
                             VALUES (:batch_id, 'packaging', 'ironing', :changed_by, :notes)";
            $history_stmt = $db->prepare($history_query);
            $history_stmt->bindParam(":batch_id", $batch_id);
            $history_stmt->bindParam(":changed_by", $_SESSION['user_id']);
            $history_notes = "Reverted to ironing due to quality control issues: " . ($defect_description ?: 'No details provided');
            $history_stmt->bindParam(":notes", $history_notes);
            $history_stmt->execute();
        }
    }
    
    // Log activity
    $status_display = str_replace('_', ' ', $status);
    $log_description = "Recorded quality control for batch #{$batch_number} - Status: " . ucfirst($status_display);
    
    if ($defects_found > 0) {
        $log_description .= ", Defects: {$defects_found}";
    }
    
    logUserActivity($_SESSION['user_id'], 'create', 'quality_control', $log_description, $qc_id);
    
    // Commit transaction
    $db->commit();
    
    // Set success message
    $_SESSION['success_message'] = "Quality control record has been added successfully";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    // Set error message
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
}

// Redirect back
header('Location: ' . $redirect_url);
exit;
?>