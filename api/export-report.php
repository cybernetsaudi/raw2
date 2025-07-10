<?php
// api/export-report.php
// Include database configuration
include_once '../config/database.php';
include_once '../config/auth.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit;
}

// Get report parameters
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'sales';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $_SESSION['error_message'] = "Invalid date format";
    header('Location: ../owner/reports.php');
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Set filename for export
$filename = $report_type . '_report_' . $start_date . '_to_' . $end_date . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Process report based on type
try {
    switch ($report_type) {
        case 'sales':
            // Write headers
            fputcsv($output, ['Date', 'Invoice Number', 'Customer', 'Total Amount', 'Discount', 'Tax', 'Shipping', 'Net Amount', 'Payment Status']);
            
            // Get sales data
            $query = "SELECT s.sale_date, s.invoice_number, c.name as customer_name, 
                     s.total_amount, s.discount_amount, s.tax_amount, s.shipping_cost, 
                     s.net_amount, s.payment_status
                     FROM sales s
                     JOIN customers c ON s.customer_id = c.id
                     WHERE s.sale_date BETWEEN :start_date AND :end_date
                     ORDER BY s.sale_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            // Write data
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'purchases':
            // Write headers
            fputcsv($output, ['Date', 'Material', 'Quantity', 'Unit', 'Unit Price', 'Total Amount', 'Vendor', 'Invoice Number']);
            
            // Get purchases data
            $query = "SELECT p.purchase_date, m.name as material_name, p.quantity, m.unit, 
                     p.unit_price, p.total_amount, p.vendor_name, p.invoice_number
                     FROM purchases p
                     JOIN raw_materials m ON p.material_id = m.id
                     WHERE p.purchase_date BETWEEN :start_date AND :end_date
                     ORDER BY p.purchase_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            // Write data
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'manufacturing':
            // Write headers
            fputcsv($output, ['Batch Number', 'Product', 'Quantity Produced', 'Status', 'Start Date', 'Completion Date', 'Total Cost']);
            
            // Get manufacturing data
            $query = "SELECT b.batch_number, p.name as product_name, b.quantity_produced, 
                     b.status, b.start_date, b.completion_date,
                     (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE batch_id = b.id) as total_cost
                     FROM manufacturing_batches b
                     JOIN products p ON b.product_id = p.id
                     WHERE b.start_date BETWEEN :start_date AND :end_date
                     ORDER BY b.start_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            // Write data
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'products':
            // Write headers
            fputcsv($output, ['Product', 'SKU', 'Category', 'Quantity Sold', 'Total Sales', 'Average Price']);
            
            // Get product sales data
            $query = "SELECT p.name as product_name, p.sku, p.category,
                     SUM(si.quantity) as quantity_sold,
                     SUM(si.total_price) as total_sales,
                     AVG(si.unit_price) as average_price
                     FROM sale_items si
                     JOIN products p ON si.product_id = p.id
                     JOIN sales s ON si.sale_id = s.id
                     WHERE s.sale_date BETWEEN :start_date AND :end_date
                     GROUP BY si.product_id
                     ORDER BY quantity_sold DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            // Write data
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            break;
            
        case 'financial':
            // Write headers
            fputcsv($output, ['Category', 'Amount']);
            
            // Get financial summary
            $query = "SELECT 
                    'Total Sales' as category, 
                    (SELECT COALESCE(SUM(net_amount), 0) FROM sales WHERE sale_date BETWEEN :start_date AND :end_date) as amount
                    UNION ALL SELECT 
                    'Material Purchases' as category,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN :start_date AND :end_date) as amount
                    UNION ALL SELECT 
                    'Manufacturing Costs' as category,
                    (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE recorded_date BETWEEN :start_date AND :end_date) as amount
                    UNION ALL SELECT 
                    'Payments Received' as category,
                    (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN :start_date AND :end_date) as amount";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            // Write data
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            
            // Calculate profit
            $financial_query = "SELECT 
                (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN :start_date AND :end_date) as total_purchases,
                (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE recorded_date BETWEEN :start_date AND :end_date) as total_manufacturing_costs,
                (SELECT COALESCE(SUM(net_amount), 0) FROM sales WHERE sale_date BETWEEN :start_date AND :end_date) as total_sales";
            
            $financial_stmt = $db->prepare($financial_query);
            $financial_stmt->bindParam(':start_date', $start_date);
            $financial_stmt->bindParam(':end_date', $end_date);
            $financial_stmt->execute();
            
            $financial = $financial_stmt->fetch(PDO::FETCH_ASSOC);
            $total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
            $profit = $financial['total_sales'] - $total_cost;
            
            // Add profit to CSV
            fputcsv($output, ['Profit', $profit]);
            
            // Calculate profit margin
            $profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;
            fputcsv($output, ['Profit Margin (%)', $profit_margin]);
            break;
            
        case 'all':
            // Create a detailed report with all data
            // Sales section
            fputcsv($output, ['SALES REPORT', '', '', '', '', '', '', '', '']);
            fputcsv($output, ['Date', 'Invoice Number', 'Customer', 'Total Amount', 'Discount', 'Tax', 'Shipping', 'Net Amount', 'Payment Status']);
            
            $sales_query = "SELECT s.sale_date, s.invoice_number, c.name as customer_name, 
                           s.total_amount, s.discount_amount, s.tax_amount, s.shipping_cost, 
                           s.net_amount, s.payment_status
                           FROM sales s
                           JOIN customers c ON s.customer_id = c.id
                           WHERE s.sale_date BETWEEN :start_date AND :end_date
                           ORDER BY s.sale_date DESC";
            
            $sales_stmt = $db->prepare($sales_query);
            $sales_stmt->bindParam(':start_date', $start_date);
            $sales_stmt->bindParam(':end_date', $end_date);
            $sales_stmt->execute();
            
            while ($row = $sales_stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            
            // Blank line between sections
            fputcsv($output, ['']);
            
            // Purchases section
            fputcsv($output, ['PURCHASES REPORT', '', '', '', '', '', '', '']);
            fputcsv($output, ['Date', 'Material', 'Quantity', 'Unit', 'Unit Price', 'Total Amount', 'Vendor', 'Invoice Number']);
            
            $purchases_query = "SELECT p.purchase_date, m.name as material_name, p.quantity, m.unit, 
                               p.unit_price, p.total_amount, p.vendor_name, p.invoice_number
                               FROM purchases p
                               JOIN raw_materials m ON p.material_id = m.id
                               WHERE p.purchase_date BETWEEN :start_date AND :end_date
                               ORDER BY p.purchase_date DESC";
            
            $purchases_stmt = $db->prepare($purchases_query);
            $purchases_stmt->bindParam(':start_date', $start_date);
            $purchases_stmt->bindParam(':end_date', $end_date);
            $purchases_stmt->execute();
            
            while ($row = $purchases_stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            
            // Blank line between sections
            fputcsv($output, ['']);
            
            // Manufacturing section
            fputcsv($output, ['MANUFACTURING REPORT', '', '', '', '', '', '']);
            fputcsv($output, ['Batch Number', 'Product', 'Quantity Produced', 'Status', 'Start Date', 'Completion Date', 'Total Cost']);
            
            $manufacturing_query = "SELECT b.batch_number, p.name as product_name, b.quantity_produced, 
                                   b.status, b.start_date, b.completion_date,
                                   (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE batch_id = b.id) as total_cost
                                   FROM manufacturing_batches b
                                   JOIN products p ON b.product_id = p.id
                                   WHERE b.start_date BETWEEN :start_date AND :end_date
                                   ORDER BY b.start_date DESC";
            
            $manufacturing_stmt = $db->prepare($manufacturing_query);
            $manufacturing_stmt->bindParam(':start_date', $start_date);
            $manufacturing_stmt->bindParam(':end_date', $end_date);
            $manufacturing_stmt->execute();
            
            while ($row = $manufacturing_stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, $row);
            }
            
            // Blank line between sections
            fputcsv($output, ['']);
            
            // Financial summary section
            fputcsv($output, ['FINANCIAL SUMMARY', '']);
            
            // Get financial summary
            $financial_query = "SELECT 
                               (SELECT COALESCE(SUM(total_amount), 0) FROM purchases WHERE purchase_date BETWEEN :start_date AND :end_date) as total_purchases,
                               (SELECT COALESCE(SUM(amount), 0) FROM manufacturing_costs WHERE recorded_date BETWEEN :start_date AND :end_date) as total_manufacturing_costs,
                               (SELECT COALESCE(SUM(net_amount), 0) FROM sales WHERE sale_date BETWEEN :start_date AND :end_date) as total_sales,
                               (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN :start_date AND :end_date) as total_payments";
            
            $financial_stmt = $db->prepare($financial_query);
            $financial_stmt->bindParam(':start_date', $start_date);
            $financial_stmt->bindParam(':end_date', $end_date);
            $financial_stmt->execute();
            
            $financial = $financial_stmt->fetch(PDO::FETCH_ASSOC);
            
            fputcsv($output, ['Total Sales', $financial['total_sales']]);
            fputcsv($output, ['Total Purchases', $financial['total_purchases']]);
            fputcsv($output, ['Total Manufacturing Costs', $financial['total_manufacturing_costs']]);
            fputcsv($output, ['Total Payments Received', $financial['total_payments']]);
            
            // Calculate profit
            $total_cost = $financial['total_purchases'] + $financial['total_manufacturing_costs'];
            $profit = $financial['total_sales'] - $total_cost;
            
            fputcsv($output, ['Total Profit', $profit]);
            
            // Calculate profit margin
            $profit_margin = $financial['total_sales'] > 0 ? ($profit / $financial['total_sales'] * 100) : 0;
            fputcsv($output, ['Profit Margin (%)', $profit_margin]);
            break;
            
        default:
            throw new Exception("Invalid report type");
    }
    
    // Log activity
    logUserActivity($_SESSION['user_id'], 'export', 'reports', "Exported {$report_type} report for {$start_date} to {$end_date}");
    
} catch (Exception $e) {
    // Write error to CSV
    fputcsv($output, ['Error: ' . $e->getMessage()]);
}

// Close the file pointer
fclose($output);
exit;
?>