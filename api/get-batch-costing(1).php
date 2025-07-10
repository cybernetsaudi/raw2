<?php
/**
 * Batch Costing API
 * 
 * This API calculates and returns detailed costing information for manufacturing batches,
 * including material costs, labor costs, other expenses, and per-unit cost analysis.
 * 
 * @endpoint GET api/get-batch-costing.php?batch_id=123
 * @returns JSON with detailed cost breakdown
 */

// Initialize session and include required files
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../config/auth.php';

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Check if batch_id is provided
if (!isset($_GET['batch_id']) || empty($_GET['batch_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Batch ID is required']);
    exit;
}

$batch_id = intval($_GET['batch_id']);

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

try {
    // Get batch information
    $batch_query = "SELECT b.*, p.name as product_name, p.sku 
                   FROM manufacturing_batches b
                   JOIN products p ON b.product_id = p.id
                   WHERE b.id = :batch_id";
    $batch_stmt = $db->prepare($batch_query);
    $batch_stmt->bindParam(':batch_id', $batch_id);
    $batch_stmt->execute();
    
    if ($batch_stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch not found']);
        exit;
    }
    
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Initialize cost breakdown structure
    $cost_breakdown = [
        'batch_info' => [
            'id' => $batch['id'],
            'batch_number' => $batch['batch_number'],
            'product_name' => $batch['product_name'],
            'sku' => $batch['sku'],
            'quantity_produced' => intval($batch['quantity_produced']),
            'status' => $batch['status']
        ],
        'material_costs' => [
            'items' => [],
            'total' => 0
        ],
        'other_costs' => [
            'items' => [],
            'categories' => [
                'labor' => 0,
                'packaging' => 0,
                'zipper' => 0,
                'sticker' => 0,
                'logo' => 0,
                'tag' => 0,
                'misc' => 0
            ],
            'total' => 0
        ],
        'summary' => [
            'total_cost' => 0,
            'cost_per_unit' => 0,
            'cost_breakdown_percentage' => [
                'materials' => 0,
                'labor' => 0,
                'other' => 0
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // 1. Calculate material costs
    $materials_query = "SELECT m.id, m.name, m.unit, bm.quantity_required as quantity, 
                      (SELECT AVG(unit_price) FROM purchases WHERE material_id = m.id ORDER BY purchase_date DESC LIMIT 5) as avg_unit_price
                      FROM batch_materials bm
                      JOIN raw_materials m ON bm.material_id = m.id
                      WHERE bm.batch_id = :batch_id";
    $materials_stmt = $db->prepare($materials_query);
    $materials_stmt->bindParam(':batch_id', $batch_id);
    $materials_stmt->execute();
    
    while ($material = $materials_stmt->fetch(PDO::FETCH_ASSOC)) {
        $unit_price = $material['avg_unit_price'] ?: 0;
        $total_cost = $material['quantity'] * $unit_price;
        
        $material_item = [
            'id' => $material['id'],
            'name' => $material['name'],
            'quantity' => floatval($material['quantity']),
            'unit' => $material['unit'],
            'unit_price' => floatval($unit_price),
            'total_cost' => floatval($total_cost)
        ];
        
        $cost_breakdown['material_costs']['items'][] = $material_item;
        $cost_breakdown['material_costs']['total'] += $total_cost;
    }
    
    // 2. Calculate other manufacturing costs
    $costs_query = "SELECT cost_type, SUM(amount) as total_amount
                   FROM manufacturing_costs
                   WHERE batch_id = :batch_id
                   GROUP BY cost_type";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->bindParam(':batch_id', $batch_id);
    $costs_stmt->execute();
    
    while ($cost = $costs_stmt->fetch(PDO::FETCH_ASSOC)) {
        $cost_type = $cost['cost_type'];
        $amount = floatval($cost['total_amount']);
        
        // Add to appropriate category
        if (isset($cost_breakdown['other_costs']['categories'][$cost_type])) {
            $cost_breakdown['other_costs']['categories'][$cost_type] = $amount;
        }
        
        // Add to items list
        $cost_breakdown['other_costs']['items'][] = [
            'type' => $cost_type,
            'amount' => $amount,
            'display_name' => ucfirst(str_replace('_', ' ', $cost_type))
        ];
        
        // Add to total
        $cost_breakdown['other_costs']['total'] += $amount;
    }
    
    // 3. Get detailed breakdown of individual costs
    $cost_details_query = "SELECT id, cost_type, amount, description, recorded_date
                         FROM manufacturing_costs
                         WHERE batch_id = :batch_id
                         ORDER BY recorded_date DESC";
    $cost_details_stmt = $db->prepare($cost_details_query);
    $cost_details_stmt->bindParam(':batch_id', $batch_id);
    $cost_details_stmt->execute();
    
    $cost_breakdown['cost_details'] = [];
    
    while ($detail = $cost_details_stmt->fetch(PDO::FETCH_ASSOC)) {
        $cost_breakdown['cost_details'][] = [
            'id' => $detail['id'],
            'type' => $detail['cost_type'],
            'display_name' => ucfirst(str_replace('_', ' ', $detail['cost_type'])),
            'amount' => floatval($detail['amount']),
            'description' => $detail['description'],
            'date' => $detail['recorded_date']
        ];
    }
    
    // 4. Calculate summary and totals
    $total_cost = $cost_breakdown['material_costs']['total'] + $cost_breakdown['other_costs']['total'];
    $cost_breakdown['summary']['total_cost'] = $total_cost;
    
    // Calculate cost per unit (avoid division by zero)
    $quantity_produced = max(1, intval($batch['quantity_produced']));
    $cost_breakdown['summary']['cost_per_unit'] = $total_cost / $quantity_produced;
    
    // Calculate percentage breakdown
    if ($total_cost > 0) {
        $cost_breakdown['summary']['cost_breakdown_percentage']['materials'] = 
            ($cost_breakdown['material_costs']['total'] / $total_cost) * 100;
            
        $cost_breakdown['summary']['cost_breakdown_percentage']['labor'] = 
            ($cost_breakdown['other_costs']['categories']['labor'] / $total_cost) * 100;
            
        $other_percentage = 100 - 
            $cost_breakdown['summary']['cost_breakdown_percentage']['materials'] - 
            $cost_breakdown['summary']['cost_breakdown_percentage']['labor'];
            
        $cost_breakdown['summary']['cost_breakdown_percentage']['other'] = $other_percentage;
    }
    
    // Return the cost breakdown as JSON
    echo json_encode([
        'success' => true,
        'data' => $cost_breakdown
    ]);
    
} catch (PDOException $e) {
    error_log('Batch costing API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'An error occurred while processing your request',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}
?>