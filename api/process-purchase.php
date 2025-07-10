<?php
session_start();
include_once '../config/database.php';
include_once '../config/auth.php';

// Check if user is logged in and is an incharge
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'incharge') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Validate required fields
    if (!isset($_POST['fund_id']) || !isset($_POST['supplier_id']) || !isset($_POST['total_amount'])) {
        throw new Exception('Missing required fields: fund_id, supplier_id, or total_amount');
    }
    
    if (!isset($_POST['items']) || !is_array($_POST['items']) || empty($_POST['items'])) {
        throw new Exception('No purchase items provided');
    }
    
    // Parse and validate total amount
    $total_amount = floatval($_POST['total_amount']);
    if ($total_amount <= 0) {
        throw new Exception('Total amount must be greater than zero');
    }
    
    // Start transaction
    $db->beginTransaction();

    // Validate fund balance
    $fund_query = "SELECT balance FROM funds WHERE id = ? AND to_user_id = ? AND status = 'active'";
    $fund_stmt = $db->prepare($fund_query);
    $fund_stmt->execute([$_POST['fund_id'], $_SESSION['user_id']]);
    $fund = $fund_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fund) {
        throw new Exception('Invalid fund selected or fund is not active');
    }

    if ($total_amount > $fund['balance']) {
        throw new Exception('Insufficient funds for this purchase. Available: ' . number_format($fund['balance'], 2));
    }

    // Validate purchase items
    $calculated_total = 0;
    $validated_items = [];
    
    foreach ($_POST['items'] as $item) {
        // Check if item has all required fields
        if (!isset($item['product_id']) || !isset($item['quantity']) || !isset($item['unit_price'])) {
            throw new Exception('Invalid item data: missing required fields');
        }
        
        $item_quantity = floatval($item['quantity']);
        $item_price = floatval($item['unit_price']);
        $item_total = floatval($item['total_price'] ?? ($item_quantity * $item_price));
        
        // Validate item data
        if ($item_quantity <= 0) {
            throw new Exception('Item quantity must be greater than zero');
        }
        
        if ($item_price <= 0) {
            throw new Exception('Item price must be greater than zero');
        }
        
        // Check if calculated total matches provided total
        $calculated_item_total = $item_quantity * $item_price;
        if (abs($calculated_item_total - $item_total) > 0.01) {
            throw new Exception('Item total price calculation mismatch. Expected: ' . 
                               number_format($calculated_item_total, 2));
        }
        
        $calculated_total += $calculated_item_total;
        
        $validated_items[] = [
            'product_id' => $item['product_id'],
            'quantity' => $item_quantity,
            'unit_price' => $item_price,
            'total_price' => $calculated_item_total
        ];
    }
    
    // Verify total matches sum of items
    if (abs($calculated_total - $total_amount) > 0.01) {
        throw new Exception('Total amount mismatch. Sum of items: ' . number_format($calculated_total, 2) . 
                           ', Provided total: ' . number_format($total_amount, 2));
    }

    // Insert purchase record
    $purchase_query = "INSERT INTO purchases (
        supplier_id, 
        total_amount, 
        payment_status, 
        created_by,
        fund_id
    ) VALUES (?, ?, 'completed', ?, ?)";
    
    $purchase_stmt = $db->prepare($purchase_query);
    $purchase_stmt->execute([
        $_POST['supplier_id'],
        $total_amount,
        $_SESSION['user_id'],
        $_POST['fund_id']
    ]);
    
    $purchase_id = $db->lastInsertId();

    // Record fund usage
    $fund_usage_query = "INSERT INTO fund_usage (
        fund_id,
        amount,
        type,
        reference_id,
        used_by,
        notes
    ) VALUES (?, ?, 'purchase', ?, ?, ?)";
    
    $fund_usage_stmt = $db->prepare($fund_usage_query);
    $fund_usage_stmt->execute([
        $_POST['fund_id'],
        $total_amount,
        $purchase_id,
        $_SESSION['user_id'],
        'Purchase #' . $purchase_id
    ]);

    // Update fund balance
    $update_fund_query = "UPDATE funds 
                         SET balance = balance - ? 
                         WHERE id = ?";
    $update_fund_stmt = $db->prepare($update_fund_query);
    $update_fund_stmt->execute([$total_amount, $_POST['fund_id']]);

    // Check if fund is depleted
    $check_balance_query = "SELECT balance FROM funds WHERE id = ?";
    $check_balance_stmt = $db->prepare($check_balance_query);
    $check_balance_stmt->execute([$_POST['fund_id']]);
    $current_balance = $check_balance_stmt->fetchColumn();

    if ($current_balance <= 0) {
        $deplete_fund_query = "UPDATE funds SET status = 'depleted' WHERE id = ?";
        $deplete_fund_stmt = $db->prepare($deplete_fund_query);
        $deplete_fund_stmt->execute([$_POST['fund_id']]);
    }

    // Insert purchase items
    $items_query = "INSERT INTO purchase_items (
        purchase_id,
        product_id,
        quantity,
        unit_price,
        total_price
    ) VALUES (?, ?, ?, ?, ?)";
    
    $items_stmt = $db->prepare($items_query);
    
    foreach ($validated_items as $item) {
        $items_stmt->execute([
            $purchase_id,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['total_price']
        ]);
    }

    // Commit transaction
    $db->commit();

    // Log activity
    $activity_query = "INSERT INTO activities (
        user_id,
        action,
        details,
        reference_id
    ) VALUES (?, 'purchase', ?, ?)";
    
    $activity_stmt = $db->prepare($activity_query);
    $activity_stmt->execute([
        $_SESSION['user_id'],
        "Created purchase #$purchase_id for Rs." . number_format($total_amount, 2),
        $purchase_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Purchase recorded successfully',
        'purchase_id' => $purchase_id
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    // Log error
    error_log("Error in process-purchase.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to process purchase',
        'message' => $e->getMessage()
    ]);
} 