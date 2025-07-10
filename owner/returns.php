<?php
// owner/returns.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

$page_title = "Fund Returns Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_return'])) {
        // Approve a return
        $return_id = $_POST['return_id'];
        $approve_query = "UPDATE fund_returns SET 
                         status = 'approved',
                         approved_by = :user_id,
                         approved_at = NOW()
                         WHERE id = :return_id";
        $stmt = $db->prepare($approve_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':return_id', $return_id);
        $stmt->execute();
        
        // Create a return fund entry
        $get_return_query = "SELECT sale_id, amount, returned_by FROM fund_returns WHERE id = :return_id";
        $get_stmt = $db->prepare($get_return_query);
        $get_stmt->bindParam(':return_id', $return_id);
        $get_stmt->execute();
        $return_data = $get_stmt->fetch(PDO::FETCH_ASSOC);
        
        $create_fund_query = "INSERT INTO funds 
                             (amount, from_user_id, to_user_id, description, type, reference_id)
                             VALUES 
                             (:amount, :from_user_id, :to_user_id, :description, 'return', :sale_id)";
        $fund_stmt = $db->prepare($create_fund_query);
        $fund_stmt->bindParam(':amount', $return_data['amount']);
        $fund_stmt->bindParam(':from_user_id', $return_data['returned_by']);
        $fund_stmt->bindParam(':to_user_id', $_SESSION['user_id']);
        $fund_stmt->bindValue(':description', 'Return from sale #' . $return_data['sale_id']);
        $fund_stmt->bindParam(':sale_id', $return_data['sale_id']);
        $fund_stmt->execute();
        
        $_SESSION['success_message'] = "Return approved and funds transferred successfully!";
    } elseif (isset($_POST['reject_return'])) {
        // Reject a return
        $return_id = $_POST['return_id'];
        $reject_query = "UPDATE fund_returns SET 
                        status = 'rejected',
                        approved_by = :user_id,
                        approved_at = NOW(),
                        notes = :notes
                        WHERE id = :return_id";
        $stmt = $db->prepare($reject_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':return_id', $return_id);
        $stmt->bindParam(':notes', $_POST['rejection_notes']);
        $stmt->execute();
        
        $_SESSION['success_message'] = "Return rejected successfully!";
    }
    
    // Redirect to prevent form resubmission
    header("Location: returns.php");
    exit();
}

// Get pending returns
$pending_returns_query = "
    SELECT fr.id, fr.sale_id, fr.amount, fr.returned_at, fr.notes,
           s.invoice_number, s.sale_date, s.net_amount,
           u.full_name AS returned_by_name,
           c.name AS customer_name
    FROM fund_returns fr
    JOIN sales s ON fr.sale_id = s.id
    JOIN users u ON fr.returned_by = u.id
    JOIN customers c ON s.customer_id = c.id
    WHERE fr.status = 'pending'
    ORDER BY fr.returned_at DESC
";
$pending_returns = $db->query($pending_returns_query)->fetchAll(PDO::FETCH_ASSOC);

// Get completed returns (approved/rejected)
$completed_returns_query = "
    SELECT fr.id, fr.sale_id, fr.amount, fr.returned_at, fr.approved_at, fr.status, fr.notes,
           s.invoice_number, s.sale_date, s.net_amount,
           u.full_name AS returned_by_name,
           au.full_name AS approved_by_name,
           c.name AS customer_name
    FROM fund_returns fr
    JOIN sales s ON fr.sale_id = s.id
    JOIN users u ON fr.returned_by = u.id
    LEFT JOIN users au ON fr.approved_by = au.id
    JOIN customers c ON s.customer_id = c.id
    WHERE fr.status != 'pending'
    ORDER BY fr.approved_at DESC, fr.returned_at DESC
    LIMIT 20
";
$completed_returns = $db->query($completed_returns_query)->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_pending = array_sum(array_column($pending_returns, 'amount'));
$total_approved = 0;
$total_rejected = 0;

foreach ($completed_returns as $return) {
    if ($return['status'] === 'approved') {
        $total_approved += $return['amount'];
    } else {
        $total_rejected += $return['amount'];
    }
}
?>

<div class="container-fluid">
    <div class="row">
        
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Fund Returns Management</h1>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-white bg-warning mb-3">
                        <div class="card-header">Pending Returns</div>
                        <div class="card-body">
                            <h5 class="card-title"><?= number_format($total_pending, 2) ?></h5>
                            <p class="card-text"><?= count($pending_returns) ?> requests</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-success mb-3">
                        <div class="card-header">Approved Returns</div>
                        <div class="card-body">
                            <h5 class="card-title"><?= number_format($total_approved, 2) ?></h5>
                            <p class="card-text">Funds returned to owner</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-white bg-danger mb-3">
                        <div class="card-header">Rejected Returns</div>
                        <div class="card-body">
                            <h5 class="card-title"><?= number_format($total_rejected, 2) ?></h5>
                            <p class="card-text">Requests not approved</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Returns Section -->
            <div class="dashboard-card full-width">
                <div class="card-header">
                    <h2>Pending Return Requests</h2>
                </div>
                <div class="card-content">
                    <?php if (!empty($pending_returns)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sale</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Returned By</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_returns as $return): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($return['returned_at'])) ?></td>
                                    <td>
                                        #<?= htmlspecialchars($return['invoice_number']) ?><br>
                                        <small><?= date('M j, Y', strtotime($return['sale_date'])) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($return['customer_name']) ?></td>
                                    <td class="amount-cell"><?= number_format($return['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($return['returned_by_name']) ?></td>
                                    <td><?= htmlspecialchars($return['notes'] ?? 'N/A') ?></td>
                                    <td>
                                        <form method="POST" style="display: inline-block;">
                                            <input type="hidden" name="return_id" value="<?= $return['id'] ?>">
                                            <button type="submit" name="approve_return" class="button small success">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <button type="button" class="button small danger" 
                                            onclick="showRejectModal(<?= $return['id'] ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No pending return requests found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed Returns Section -->
            <div class="dashboard-card full-width mt-4">
                <div class="card-header">
                    <h2>Recently Processed Returns</h2>
                </div>
                <div class="card-content">
                    <?php if (!empty($completed_returns)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Sale</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Returned By</th>
                                    <th>Status</th>
                                    <th>Processed By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completed_returns as $return): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($return['returned_at'])) ?></td>
                                    <td>
                                        #<?= htmlspecialchars($return['invoice_number']) ?><br>
                                        <small><?= date('M j, Y', strtotime($return['sale_date'])) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($return['customer_name']) ?></td>
                                    <td class="amount-cell"><?= number_format($return['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($return['returned_by_name']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $return['status'] === 'approved' ? 'success' : 'danger' ?>">
                                            <?= ucfirst($return['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $return['approved_by_name'] ?? 'N/A' ?><br>
                                        <small><?= $return['approved_at'] ? date('M j, Y', strtotime($return['approved_at'])) : '' ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($return['notes'] ?? 'N/A') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info">
                            No processed returns found.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Reject Return Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Reject Return Request</h2>
        <form id="rejectForm" method="POST">
            <input type="hidden" name="return_id" id="modal_return_id">
            <div class="form-group">
                <label for="rejection_notes">Reason for Rejection:</label>
                <textarea id="rejection_notes" name="rejection_notes" rows="4" required></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelReject">Cancel</button>
                <button type="submit" name="reject_return" class="button danger">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal functionality
    const modal = document.getElementById('rejectModal');
    const closeBtn = document.querySelector('#rejectModal .close-modal');
    const cancelBtn = document.getElementById('cancelReject');
    
    function closeModal() {
        modal.style.display = 'none';
    }
    
    window.showRejectModal = function(returnId) {
        document.getElementById('modal_return_id').value = returnId;
        modal.style.display = 'block';
    };
    
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    
    window.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    
    // Show success message if exists
    <?php if (isset($_SESSION['success_message'])): ?>
        showNotification('Success', '<?= $_SESSION['success_message'] ?>', 'success');
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    // Notification function
    function showNotification(title, message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fas fa-${type === 'success' ? 'check' : (type === 'error' ? 'exclamation' : 'info')}-circle"></i>
            </div>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close">&times;</button>
        `;
        
        const container = document.querySelector('.toast-container') || document.body;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 100);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 5000);
        
        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        });
    }
});
</script>

<style>
/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 600px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.close-modal {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close-modal:hover {
    color: #333;
}

/* Form Styles */
.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.form-group textarea {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 100px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1rem;
}

/* Toast Notifications */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1100;
}

.toast {
    display: flex;
    align-items: center;
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 4px;
    color: #fff;
    transform: translateX(100%);
    opacity: 0;
    transition: all 0.3s ease;
}

.toast.show {
    transform: translateX(0);
    opacity: 1;
}

.toast.success {
    background-color: #28a745;
}

.toast.error {
    background-color: #dc3545;
}

.toast.warning {
    background-color: #ffc107;
    color: #212529;
}

.toast.info {
    background-color: #17a2b8;
}

.toast-icon {
    margin-right: 1rem;
    font-size: 1.5rem;
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-weight: bold;
    margin-bottom: 0.25rem;
}

.toast-close {
    background: none;
    border: none;
    color: inherit;
    font-size: 1.25rem;
    cursor: pointer;
    margin-left: 1rem;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}

.status-badge.success {
    background-color: #d4edda;
    color: #155724;
}

.status-badge.danger {
    background-color: #f8d7da;
    color: #721c24;
}

.status-badge.warning {
    background-color: #fff3cd;
    color: #856404;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .modal-content {
        width: 90%;
        margin: 10% auto;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions button {
        width: 100%;
    }
}
</style>

<?php include_once '../includes/footer.php'; ?>