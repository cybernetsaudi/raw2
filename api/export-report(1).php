<?php
// Start session if not already started
if(session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database config
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in and is an owner
if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header('Location: ../index.php');
    exit;
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize auth for activity logging
$auth = new Auth($db);

// Get report parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'all';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate dates
if(strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Generate filename
$filename = "report_{$report_type}_{$start_date}_to_{$end_date}.csv";

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add BOM to fix UTF-8 in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Process different report types
switch($report_type) {
    case 'sales':
        exportSalesReport($db, $output, $start_date, $end_date);
        break;
    case 'purchases':
        exportPurchasesReport($db, $output, $start_date, $end_date);
        break;
    case 'manufacturing':
        exportManufacturingReport($db, $output, $start_date, $end_date);
        break;
    case 'products':
        exportProductsReport($db, $output, $start_date, $end_date);
        break;
    case 'financial':
        exportFinancialReport($db, $output, $start_date, $end_date);
        break;
    case 'all':
    default:
        exportAllReports($db, $output, $start_date, $end_date);
        break;
}

// Log activity
$auth->logActivity(
    $_SESSION['user_id'], 
    'export', 
    'reports', 
    "Exported {$report_type} report for period {$start_date} to {$end_date}"
);

// Close the file pointer
fclose($output);
exit;

/**
 * Export sales report
 */
function exportSalesReport($db, $output, $start_date, $end_date) {
    // Set column headers
    fputcsv($output, array('Date', 'Invoice Number', 'Customer', 'Items', 'Sub Total', 'Tax', 'Discount', 'Net Amount', 'Status', 'Created By'));
    
    // Get sales data
    $query = "SELECT s.*, c.name as customer_name, u.username as created_by_name,
              (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count,
              (SELECT SUM(amount) FROM payments WHERE sale_id = s.id) as amount_paid
              FROM sales s
              LEFT JOIN customers c ON s.customer_id = c.id
              LEFT JOIN users u ON s.created_by = u.id
              WHERE s.sale_date BETWEEN ? AND ?
              ORDER BY s.sale_date";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $start_date);
    $stmt->bindParam(2, $end_date);
    $stmt->execute();
    
    // Fetch and write each row
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payment_status = 'Unpaid';
        if($row['amount_paid'] >= $row['net_amount']) {
            $payment_status = 'Paid';
        } else if($row['amount_paid'] > 0) {
            $payment_status = 'Partial';
        }
        
        fputcsv($output, array(
            $row['sale_date'],
            $row['invoice_number'],
            $row['customer_name'],
            $row['item_count'],
            $row['subtotal'],
            $row['tax_amount'],
            $row['discount'],
            $row['net_amount'],
            $payment_status,
            $row['created_by_name']
        ));
    }
}

/**
 * Export purchases report
 */
function exportPurchasesReport($db, $output, $start_date, $end_date) {
    // Set column headers
    fputcsv($output, array('Date', 'Material', 'Vendor', 'Quantity', 'Unit', 'Unit Price', 'Total Amount', 'Purchased By'));
    
    // Get purchases data
    $query = "SELECT p.*, m.name as material_name, m.unit, u.username as purchased_by_name
              FROM purchases p
              JOIN raw_materials m ON p.material_id = m.id
              JOIN users u ON p.purchased_by = u.id
              WHERE p.purchase_date BETWEEN ? AND ?
              ORDER BY p.purchase_date";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $start_date);
    $stmt->bindParam(2, $end_date);
    $stmt->execute();
    
    // Fetch and write each row
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array(
            $row['purchase_date'],
            $row['material_name'],
            $row['vendor_name'],
            $row['quantity'],
            $row['unit'],
            $row['unit_price'],
            $row['total_amount'],
            $row['purchased_by_name']
        ));
    }
}

/**
 * Export manufacturing report
 */
function exportManufacturingReport($db, $output, $start_date, $end_date) {
    // Set column headers
    fputcsv($output, array('Batch Number', 'Product', 'Quantity', 'Status', 'Start Date', 'Expected Completion', 'Actual Completion', 'Created By', 'Total Cost', 'Cost Per Unit'));
    
    // Get manufacturing data
    $query = "SELECT b.*, p.name as product_name, p.sku, u.username as created_by_name,
              (SELECT SUM(amount) FROM manufacturing_costs WHERE batch_id = b.id) as total_cost
              FROM manufacturing_batches b
              JOIN products p ON b.product_id = p.id
              JOIN users u ON b.created_by = u.id
              WHERE b.start_date BETWEEN ? AND ?
              ORDER BY b.start_date";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $start_date);
    $stmt->bindParam(2, $end_date);
    $stmt->execute();
    
    // Fetch and write each row
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cost_per_unit = 0;
        if($row['quantity_produced'] > 0 && $row['total_cost'] > 0) {
            $cost_per_unit = $row['total_cost'] / $row['quantity_produced'];
        }
        
        fputcsv($output, array(
            $row['batch_number'],
            $row['product_name'] . ' (' . $row['sku'] . ')',
            $row['quantity_produced'],
            ucfirst($row['status']),
            $row['start_date'],
            $row['expected_completion_date'],
            $row['completion_date'] ?: 'Not completed',
            $row['created_by_name'],
            $row['total_cost'] ?: '0.00',
            number_format($cost_per_unit, 2)
        ));
    }
}

/**
 * Export products report
 */
function exportProductsReport($db, $output, $start_date, $end_date) {
    // Set column headers
    fputcsv($output, array('Product', 'SKU', 'Total Quantity Sold', 'Total Sales Amount', 'Manufacturing Cost', 'Profit', 'Profit Margin %'));
    
    // Get products sales data
    $query = "SELECT p.id, p.name, p.sku, 
              SUM(si.quantity) as total_quantity, 
              SUM(si.total_price) as total_amount,
              (
                  SELECT SUM(mc.amount) 
                  FROM manufacturing_costs mc 
                  JOIN manufacturing_batches mb ON mc.batch_id = mb.id 
                  WHERE mb.product_id = p.id AND mb.start_date BETWEEN ? AND ?
              ) as manufacturing_cost
              FROM products p
              JOIN sale_items si ON p.id = si.product_id
              JOIN sales s ON si.sale_id = s.id
              WHERE s.sale_date BETWEEN ? AND ?
              GROUP BY p.id
              ORDER BY total_quantity DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $start_date);
    $stmt->bindParam(2, $end_date);
    $stmt->bindParam(3, $start_date);
    $stmt->bindParam(4, $end_date);
    $stmt->execute();
    
    // Fetch and write each row
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $manufacturing_cost = $row['manufacturing_cost'] ?: 0;
        $profit = $row['total_amount'] - $manufacturing_cost;
        $profit_margin = 0;
        
        if($row['total_amount'] > 0) {
            $profit_margin = ($profit / $row['total_amount']) * 100;
        }
        
        fputcsv($output, array(
            $row['name'],
            $row['sku'],
            $row['total_quantity'],
            $row['total_amount'],
            $manufacturing_cost,
            $profit,
            number_format($profit_margin, 2)
        ));
    }
}

/**
 * Export financial report
 */
function exportFinancialReport($db, $output, $start_date, $end_date) {
    // Set column headers
    fputcsv($output, array('Category', 'Type', 'Amount', 'Date', 'Description'));
    
    // Get sales data for income
    $sales_query = "SELECT 'Income' as category, 'Sales' as type, net_amount as amount, sale_date as date, 
                   CONCAT('Invoice #', invoice_number) as description
                   FROM sales 
                   WHERE sale_date BETWEEN ? AND ?";
    $sales_stmt = $db->prepare($sales_query);
    $sales_stmt->bindParam(1, $start_date);
    $sales_stmt->bindParam(2, $end_date);
    $sales_stmt->execute();
    
    // Get purchases data for expenses
    $purchases_query = "SELECT 'Expense' as category, 'Material Purchase' as type, total_amount as amount, 
                       purchase_date as date, 
                       CONCAT('Vendor: ', vendor_name) as description
                       FROM purchases 
                       WHERE purchase_date BETWEEN ? AND ?";
    $purchases_stmt = $db->prepare($purchases_query);
    $purchases_stmt->bindParam(1, $start_date);
    $purchases_stmt->bindParam(2, $end_date);
    $purchases_stmt->execute();
    
    // Get manufacturing costs data for expenses
    $costs_query = "SELECT 'Expense' as category, 
                   CONCAT('Manufacturing - ', UPPER(SUBSTRING(cost_type, 1, 1)), LOWER(SUBSTRING(cost_type, 2))) as type, 
                   amount, recorded_date as date, 
                   CONCAT('Batch #', (SELECT batch_number FROM manufacturing_batches WHERE id = batch_id)) as description
                   FROM manufacturing_costs 
                   WHERE recorded_date BETWEEN ? AND ?";
    $costs_stmt = $db->prepare($costs_query);
    $costs_stmt->bindParam(1, $start_date);
    $costs_stmt->bindParam(2, $end_date);
    $costs_stmt->execute();
    
    // Write all sales records
    while($row = $sales_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array(
            $row['category'],
            $row['type'],
            $row['amount'],
            $row['date'],
            $row['description']
        ));
    }
    
    // Write all purchases records
    while($row = $purchases_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array(
            $row['category'],
            $row['type'],
            $row['amount'],
            $row['date'],
            $row['description']
        ));
    }
    
    // Write all manufacturing costs records
    while($row = $costs_stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array(
            $row['category'],
            $row['type'],
            $row['amount'],
            $row['date'],
            $row['description']
        ));
    }
}

/**
 * Export all reports combined
 */
function exportAllReports($db, $output, $start_date, $end_date) {
    // Write Sales section
    fputcsv($output, array('SALES REPORT', $start_date, 'to', $end_date));
    fputcsv($output, array()); // Empty row
    exportSalesReport($db, $output, $start_date, $end_date);
    
    // Add spacing
    fputcsv($output, array());
    fputcsv($output, array());
    
    // Write Purchases section
    fputcsv($output, array('PURCHASES REPORT', $start_date, 'to', $end_date));
    fputcsv($output, array()); // Empty row
    exportPurchasesReport($db, $output, $start_date, $end_date);
    
    // Add spacing
    fputcsv($output, array());
    fputcsv($output, array());
    
    // Write Manufacturing section
    fputcsv($output, array('MANUFACTURING REPORT', $start_date, 'to', $end_date));
    fputcsv($output, array()); // Empty row
    exportManufacturingReport($db, $output, $start_date, $end_date);
    
    // Add spacing
    fputcsv($output, array());
    fputcsv($output, array());
    
    // Write Products section
    fputcsv($output, array('PRODUCTS REPORT', $start_date, 'to', $end_date));
    fputcsv($output, array()); // Empty row
    exportProductsReport($db, $output, $start_date, $end_date);
    
    // Add spacing
    fputcsv($output, array());
    fputcsv($output, array());
    
       // Write Financial section
    fputcsv($output, array('FINANCIAL REPORT', $start_date, 'to', $end_date));
    fputcsv($output, array()); // Empty row
    exportFinancialReport($db, $output, $start_date, $end_date);
    
    // Add summary section
    fputcsv($output, array());
    fputcsv($output, array());
    
    // Financial Summary
    fputcsv($output, array('FINANCIAL SUMMARY', $start_date, 'to', $end_date));
    fputcsv($output, array());
    
    // Get total sales
    $sales_total_query = "SELECT SUM(net_amount) as total FROM sales WHERE sale_date BETWEEN ? AND ?";
    $sales_total_stmt = $db->prepare($sales_total_query);
    $sales_total_stmt->bindParam(1, $start_date);
    $sales_total_stmt->bindParam(2, $end_date);
    $sales_total_stmt->execute();
    $total_sales = $sales_total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?: 0;
    
    // Get total purchases
    $purchases_total_query = "SELECT SUM(total_amount) as total FROM purchases WHERE purchase_date BETWEEN ? AND ?";
    $purchases_total_stmt = $db->prepare($purchases_total_query);
    $purchases_total_stmt->bindParam(1, $start_date);
    $purchases_total_stmt->bindParam(2, $end_date);
    $purchases_total_stmt->execute();
    $total_purchases = $purchases_total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?: 0;
    
    // Get total manufacturing costs
    $costs_total_query = "SELECT SUM(amount) as total FROM manufacturing_costs WHERE recorded_date BETWEEN ? AND ?";
    $costs_total_stmt = $db->prepare($costs_total_query);
    $costs_total_stmt->bindParam(1, $start_date);
    $costs_total_stmt->bindParam(2, $end_date);
    $costs_total_stmt->execute();
    $total_costs = $costs_total_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?: 0;
    
    // Calculate totals
    $total_expenses = $total_purchases + $total_costs;
    $profit = $total_sales - $total_expenses;
    $profit_margin = $total_sales > 0 ? ($profit / $total_sales) * 100 : 0;
    
    // Write summary
    fputcsv($output, array('Category', 'Amount'));
    fputcsv($output, array('Total Sales', number_format($total_sales, 2)));
    fputcsv($output, array('Total Material Purchases', number_format($total_purchases, 2)));
    fputcsv($output, array('Total Manufacturing Costs', number_format($total_costs, 2)));
    fputcsv($output, array('Total Expenses', number_format($total_expenses, 2)));
    fputcsv($output, array('Profit', number_format($profit, 2)));
    fputcsv($output, array('Profit Margin', number_format($profit_margin, 2) . '%'));
}
?>