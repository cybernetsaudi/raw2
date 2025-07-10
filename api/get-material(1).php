<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';

// Ensure user is logged in and has appropriate role
if(!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'incharge')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Check if material ID is provided
if(!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Material ID is required']);
    exit;
}

$material_id = $_GET['id'];

try {
    // Get material details
    $query = "SELECT * FROM raw_materials WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $material_id);
    $stmt->execute();
    
    if($stmt->rowCount() === 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Material not found']);
        exit;
    }
    
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get purchase history
    $purchases_query = "SELECT p.*, u.full_name as purchased_by_name
                       FROM purchases p
                       JOIN users u ON p.purchased_by = u.id
                       WHERE p.material_id = ?
                       ORDER BY p.purchase_date DESC
                       LIMIT 10";
    $purchases_stmt = $db->prepare($purchases_query);
    $purchases_stmt->bindParam(1, $material_id);
    $purchases_stmt->execute();
    $purchases = $purchases_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get usage history
    $usage_query = "SELECT bm.*, b.batch_number, p.name as product_name
                   FROM batch_materials bm
                   JOIN manufacturing_batches b ON bm.batch_id = b.id
                   JOIN products p ON b.product_id = p.id
                   WHERE bm.material_id = ?
                   ORDER BY b.start_date DESC
                   LIMIT 10";
    $usage_stmt = $db->prepare($usage_query);
    $usage_stmt->bindParam(1, $material_id);
    $usage_stmt->execute();
    $usage = $usage_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return material data with history
    $response = [
        'success' => true,
        'material' => $material,
        'purchases' => $purchases,
        'usage' => $usage
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>