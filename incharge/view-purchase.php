<?php
session_start();
$page_title = "View Purchase";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check if purchase ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: purchases.php');
    exit;
}

$purchase_id = $_GET['id'];

// Get purchase details
$query = "SELECT p.*, m.name as material_name, m.unit, u.full_name as purchased_by_name
          FROM purchases p
          JOIN raw_materials m ON p.material_id = m.id
          JOIN users u ON p.purchased_by = u.id
          WHERE p.id = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $purchase_id);
$stmt->execute();

if($stmt->rowCount() === 0) {
    header('Location: purchases.php');
    exit;
}

$purchase = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="breadcrumb">
    <a href="purchases.php">Purchases</a> &raquo; Purchase #<?php echo htmlspecialchars($purchase['id']); ?>
</div>

<div class="purchase-container">
    <div class="purchase-header">
        <div class="purchase-title">
            <h2>Purchase #<?php echo htmlspecialchars($purchase['id']); ?></h2>
            <span class="purchase-date"><?php echo htmlspecialchars($purchase['purchase_date']); ?></span>
        </div>
        <div class="purchase-actions">
            <button id="printPurchaseBtn" class="button secondary">Print Details</button>
            <a href="purchases.php" class="button">Back to Purchases</a>
        </div>
    </div>
    
    <div class="purchase-details-grid">
        <div class="purchase-details-card">
            <h3>Purchase Information</h3>
            <div class="details-list">
                <div class="detail-item">
                    <span class="detail-label">Material:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['material_name']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Quantity:</span>
                    <span class="detail-value"><?php echo number_format($purchase['quantity'], 2); ?> <?php echo htmlspecialchars($purchase['unit']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Unit Price:</span>
                    <span class="detail-value"><?php echo number_format($purchase['unit_price'], 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Amount:</span>
                    <span class="detail-value total-amount"><?php echo number_format($purchase['total_amount'], 2); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Purchase Date:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['purchase_date']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Purchased By:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['purchased_by_name']); ?></span>
                </div>
            </div>
        </div>
        
        <div class="purchase-details-card">
            <h3>Vendor Information</h3>
            <div class="details-list">
                <div class="detail-item">
                    <span class="detail-label">Vendor Name:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['vendor_name']); ?></span>
                </div>
                <?php if(!empty($purchase['vendor_contact'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Vendor Contact:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['vendor_contact']); ?></span>
                </div>
                <?php endif; ?>
                <?php if(!empty($purchase['invoice_number'])): ?>
                <div class="detail-item">
                    <span class="detail-label">Invoice Number:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($purchase['invoice_number']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="purchase-details-card full-width">
            <h3>Additional Information</h3>
            <div class="details-content">
                <?php if(!empty($purchase['notes'])): ?>
                <div class="notes-section">
                    <h4>Notes</h4>
                    <p><?php echo nl2br(htmlspecialchars($purchase['notes'])); ?></p>
                </div>
                <?php else: ?>
                <p class="no-data">No additional information available for this purchase.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* Purchase details specific styles */
.purchase-container {
    margin-bottom: 2rem;
}

.purchase-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.purchase-title {
    display: flex;
    flex-direction: column;
}

.purchase-title h2 {
    margin: 0;
    margin-bottom: 0.25rem;
}

.purchase-date {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.purchase-actions {
    display: flex;
    gap: 1rem;
}

.purchase-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
}

.purchase-details-card {
    background-color: white;
    border-radius: var(--border-radius-md);
    box-shadow: var(--shadow-sm);
    padding: 1.5rem;
}

.purchase-details-card.full-width {
    grid-column: 1 / -1;
}

.purchase-details-card h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border);
    color: var(--primary);
}

.details-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-item {
    display: flex;
    gap: 0.5rem;
}

.detail-label {
    font-weight: 500;
    color: var(--text-secondary);
    min-width: 140px;
}

.detail-value {
    flex: 1;
}

.total-amount {
    font-weight: bold;
    font-size: var(--font-size-lg);
    color: var(--primary);
}

.notes-section h4 {
    margin-top: 0;
    margin-bottom: 0.5rem;
    font-size: var(--font-size-md);
    color: var(--text-secondary);
}

.no-data {
    padding: 1rem;
    background-color: var(--surface);
    border-radius: var(--border-radius-sm);
    color: var(--text-secondary);
    text-align: center;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .purchase-details-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .purchase-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .purchase-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .purchase-actions .button {
        width: 100%;
        text-align: center;
    }
    
    .detail-item {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .detail-label {
        min-width: auto;
    }
}

/* Print styles */
@media print {
    .breadcrumb, .purchase-actions, .main-header, .sidebar, .footer {
        display: none !important;
    }
    
    .purchase-container {
        margin: 0;
        padding: 0;
    }
    
    .purchase-details-card {
        box-shadow: none;
        border: 1px solid #eee;
        page-break-inside: avoid;
    }
    
    body, .main-content, .content-wrapper {
        background-color: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    @page {
        margin: 0.5cm;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Print functionality
    const printBtn = document.getElementById('printPurchaseBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            window.print();
        });
    }
    
    // Log view activity
    if (typeof logUserActivity === 'function') {
        logUserActivity('read', 'purchases', 'Viewed purchase #<?php echo $purchase['id']; ?> details');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>