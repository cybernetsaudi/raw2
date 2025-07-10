<?php
session_start();
$page_title = "User Management";
include_once '../config/database.php';
include_once '../config/auth.php';
include_once '../includes/header.php';

// Ensure user is an owner
if($_SESSION['role'] !== 'owner') {
    header('Location: ../index.php');
    exit;
}

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get all users
$query = "SELECT id, username, full_name, email, role, phone, is_active, created_at 
          FROM users 
          ORDER BY username";
$stmt = $db->prepare($query);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-actions">
    <button id="addUserBtn" class="button">Add New User</button>
</div>

<div class="dashboard-card full-width">
    <div class="card-header">
        <h2>System Users</h2>
    </div>
    <div class="card-content">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $user): ?>
                <tr class="<?php echo $user['is_active'] ? '' : 'inactive-row'; ?>">
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                    <td><?php echo htmlspecialchars($user['phone']); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars(date('Y-m-d', strtotime($user['created_at']))); ?></td>
                    <td>
                        <div class="action-buttons">
                            <button class="button small edit-user" data-id="<?php echo $user['id']; ?>">Edit</button>
                            <?php if($user['id'] != $_SESSION['user_id']): // Can't toggle your own account ?>
                            <button class="button small <?php echo $user['is_active'] ? 'deactivate-user' : 'activate-user'; ?>" 
                                    data-id="<?php echo $user['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($user['full_name']); ?>">
                                <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(count($users) === 0): ?>
                <tr>
                    <td colspan="8" class="no-records">No users found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2 id="modalTitle">Add New User</h2>
        <form id="userForm" action="../api/save-user.php" method="post">
            <input type="hidden" id="user_id" name="user_id" value="">
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password">
                        <small class="form-text" id="passwordHint">Leave blank to keep current password when editing.</small>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="full_name">Full Name:</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="role">Role:</label>
                        <select id="role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="owner">Owner</option>
                            <option value="incharge">Incharge</option>
                            <option value="shopkeeper">Shopkeeper</option>
                        </select>
                    </div>
                </div>
                <div class="form-col">
                    <div class="form-group">
                        <label for="phone">Phone:</label>
                        <input type="text" id="phone" name="phone">
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-col">
                    <div class="form-group">
                        <label for="is_active">Status:</label>
                        <select id="is_active" name="is_active" required>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="button secondary" id="cancelUser">Cancel</button>
                <button type="submit" class="button">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmationModal" class="modal">
    <div class="modal-content confirmation-modal">
        <h2 id="confirmationTitle">Confirm Action</h2>
        <p id="confirmationMessage">Are you sure you want to perform this action?</p>
        <div class="form-actions">
            <button type="button" class="button secondary" id="cancelConfirmation">Cancel</button>
            <button type="button" class="button danger" id="confirmAction">Confirm</button>
        </div>
    </div>
</div>

<!-- Hidden user ID for JS activity logging -->
<input type="hidden" id="current-user-id" value="<?php echo $_SESSION['user_id']; ?>">

<style>
/* User management specific styles */
.role-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.role-owner {
    background-color: rgba(66, 133, 244, 0.1);
    color: #4285f4;
}

.role-incharge {
    background-color: rgba(251, 188, 4, 0.1);
    color: #b06000;
}

.role-shopkeeper {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.status-active {
    background-color: rgba(52, 168, 83, 0.1);
    color: #34a853;
}

.status-inactive {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.inactive-row {
    background-color: rgba(0, 0, 0, 0.03);
    color: var(--text-secondary);
}

.button.danger {
    background-color: var(--error);
}

.button.danger:hover {
    background-color: #d32f2f;
}

.confirmation-modal {
    max-width: 400px;
    text-align: center;
}

#confirmationMessage {
    margin-bottom: 1.5rem;
}

/* Responsive adjustments */
@media (max-width: 992px) {
    .data-table th:nth-child(5),
    .data-table td:nth-child(5),
    .data-table th:nth-child(7),
    .data-table td:nth-child(7) {
        display: none;
    }
}

@media (max-width: 768px) {
    .data-table th:nth-child(3),
    .data-table td:nth-child(3) {
        display: none;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .action-buttons .button {
        width: 100%;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal elements
    const userModal = document.getElementById('userModal');
    const confirmationModal = document.getElementById('confirmationModal');
    const addBtn = document.getElementById('addUserBtn');
    const closeBtn = document.querySelector('.close-modal');
    const cancelBtn = document.getElementById('cancelUser');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('userForm');
    const passwordHint = document.getElementById('passwordHint');
    
    // Confirmation modal elements
    const confirmationTitle = document.getElementById('confirmationTitle');
    const confirmationMessage = document.getElementById('confirmationMessage');
    const cancelConfirmation = document.getElementById('cancelConfirmation');
    const confirmAction = document.getElementById('confirmAction');
    
    let actionToConfirm = null;
    let actionParams = null;
    
    // Open modal for adding new user
    addBtn.addEventListener('click', function() {
        modalTitle.textContent = 'Add New User';
        form.reset();
        document.getElementById('user_id').value = '';
        document.getElementById('password').required = true;
        passwordHint.style.display = 'none';
        userModal.style.display = 'block';
    });
    
    // Close modal on X click
    closeBtn.addEventListener('click', function() {
        userModal.style.display = 'none';
    });
    
    // Close modal on Cancel click
    cancelBtn.addEventListener('click', function() {
        userModal.style.display = 'none';
    });
    
    // Close modals on outside click
    window.addEventListener('click', function(event) {
        if (event.target === userModal) {
            userModal.style.display = 'none';
        }
        if (event.target === confirmationModal) {
            confirmationModal.style.display = 'none';
        }
    });
    
    // Cancel confirmation
    cancelConfirmation.addEventListener('click', function() {
        confirmationModal.style.display = 'none';
    });
    
    // Confirm action
    confirmAction.addEventListener('click', function() {
        if (actionToConfirm === 'toggleActivation') {
            toggleUserActivation(actionParams.userId, actionParams.activate);
        }
        confirmationModal.style.display = 'none';
    });
    
// Improved version with better error handling
document.querySelectorAll('.edit-user').forEach(button => {
    button.addEventListener('click', function() {
        const userId = this.getAttribute('data-id');
        
        // Show loading indicator
        const modalTitle = document.getElementById('modalTitle');
        const originalTitle = modalTitle.textContent;
        modalTitle.textContent = 'Loading user data...';
        
        // Open the modal first to show loading state
        document.getElementById('userModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Add a timestamp to prevent caching
        fetch(`../api/get-user.php?id=${userId}&t=${Date.now()}`)
            .then(response => {
                // Check if response is OK before parsing JSON
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if(data.error) {
                    throw new Error(data.error);
                }
                
                console.log('User data received:', data); // Debug log
                
                // Populate form with user data
                document.getElementById('user_id').value = data.id;
                document.getElementById('username').value = data.username;
                document.getElementById('full_name').value = data.full_name;
                document.getElementById('email').value = data.email;
                document.getElementById('role').value = data.role;
                
                // Reset modal title
                modalTitle.textContent = originalTitle;
            })
            .catch(error => {
                console.error('Error fetching user data:', error);
                
                // Close modal and show error
                document.getElementById('userModal').style.display = 'none';
                document.body.style.overflow = '';
                
                // Show a more user-friendly error message
                showToast('Failed to load user data. Please try again.', 'error');
            });
    });
});

// Helper function to show toast notifications
function showToast(message, type = 'info') {
    // Check if toast container exists, create if not
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    
    // Add to container
    toastContainer.appendChild(toast);
    
    // Remove after timeout
    setTimeout(() => {
        toast.classList.add('toast-hide');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, 3000);
}
    
    // Activate/Deactivate user buttons
    const activateButtons = document.querySelectorAll('.activate-user, .deactivate-user');
    activateButtons.forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-id');
            const userName = this.getAttribute('data-name');
            const activate = this.classList.contains('activate-user');
            
            confirmationTitle.textContent = activate ? 'Activate User' : 'Deactivate User';
            confirmationMessage.textContent = `Are you sure you want to ${activate ? 'activate' : 'deactivate'} ${userName}?`;
            
            actionToConfirm = 'toggleActivation';
            actionParams = { userId, activate };
            
            confirmationModal.style.display = 'block';
        });
    });
    
    // Function to toggle user activation
    function toggleUserActivation(userId, activate) {
        fetch('../api/toggle-user-activation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                user_id: userId,
                activate: activate
            }),
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload the page to reflect changes
                window.location.reload();
            } else {
                alert(data.message || 'Failed to update user status.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    }
    
    // Form validation
    form.addEventListener('submit', function(event) {
        let isValid = true;
        
        // Username validation
        const username = document.getElementById('username').value.trim();
        if (!username) {
            showInputError(document.getElementById('username'), 'Username is required');
            isValid = false;
        } else if (username.length < 3) {
            showInputError(document.getElementById('username'), 'Username must be at least 3 characters');
            isValid = false;
        }
        
        // Password validation for new users
        const password = document.getElementById('password').value;
        const userId = document.getElementById('user_id').value;
        if (!userId && !password) {
            showInputError(document.getElementById('password'), 'Password is required for new users');
            isValid = false;
        } else if (password && password.length < 6) {
            showInputError(document.getElementById('password'), 'Password must be at least 6 characters');
            isValid = false;
        }
        
        // Email validation
        const email = document.getElementById('email').value.trim();
        if (!email) {
            showInputError(document.getElementById('email'), 'Email is required');
            isValid = false;
        } else if (!isValidEmail(email)) {
            showInputError(document.getElementById('email'), 'Please enter a valid email address');
            isValid = false;
        }
        
        // Role validation
        if (!document.getElementById('role').value) {
            showInputError(document.getElementById('role'), 'Please select a role');
            isValid = false;
        }
        
        if (!isValid) {
            event.preventDefault();
        } else {
            // Log activity
            if (typeof logUserActivity === 'function') {
                const action = userId ? 'update' : 'create';
                logUserActivity(
                    action, 
                    'users', 
                    `${action === 'update' ? 'Updated' : 'Created'} user: ${username}`
                );
            }
        }
    });
    
    // Helper functions
    function showInputError(inputElement, message) {
        inputElement.classList.add('invalid-input');
        
        // Remove any existing error message
        let nextElement = inputElement.nextElementSibling;
        if (nextElement && nextElement.classList.contains('error-message')) {
            nextElement.remove();
        }
        
        // Skip the small hint element for password field
        if (inputElement.id === 'password' && nextElement && nextElement.classList.contains('form-text')) {
            nextElement = nextElement.nextElementSibling;
            if (nextElement && nextElement.classList.contains('error-message')) {
                nextElement.remove();
            }
        }
        
        // Create and insert error message
        const errorElement = document.createElement('div');
        errorElement.className = 'error-message';
        errorElement.textContent = message;
        
        if (inputElement.id === 'password' && passwordHint.style.display === 'block') {
            passwordHint.parentNode.insertBefore(errorElement, passwordHint.nextSibling);
        } else {
            inputElement.parentNode.insertBefore(errorElement, inputElement.nextSibling);
        }
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>