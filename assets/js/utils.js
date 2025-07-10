/**
 * Common utility functions for the garment manufacturing system
 */

/**
 * Display a toast notification
 * @param {string} title - The toast title
 * @param {string} message - The toast message
 * @param {string} type - The toast type (success, warning, error, info)
 * @param {number} duration - How long to display the toast (ms)
 */
function showToast(title, message, type = 'info', duration = 5000) {
  const toastContainer = document.getElementById('toastContainer');
  if (!toastContainer) {
    // Create toast container if it doesn't exist
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container';
    container.setAttribute('aria-live', 'polite');
    document.body.appendChild(container);
  }
  
  // Create toast element
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.setAttribute('role', 'alert');
  
  // Set icon based on type
  let icon = 'info-circle';
  if (type === 'success') icon = 'check-circle';
  if (type === 'warning') icon = 'exclamation-triangle';
  if (type === 'error') icon = 'exclamation-circle';
  
  toast.innerHTML = `
    <div class="toast-icon">
      <i class="fas fa-${icon}" aria-hidden="true"></i>
    </div>
    <div class="toast-content">
      <div class="toast-title">${title}</div>
      <div class="toast-message">${message}</div>
    </div>
    <button type="button" class="toast-close" aria-label="Close notification">
      <i class="fas fa-times" aria-hidden="true"></i>
    </button>
  `;
  
  // Add to container
  toastContainer.appendChild(toast);
  
  // Add close button functionality
  const closeBtn = toast.querySelector('.toast-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      removeToast(toast);
    });
  }
  
  // Auto remove after duration
  setTimeout(() => {
    removeToast(toast);
  }, duration);
}

/**
 * Remove a toast with animation
 * @param {HTMLElement} toast - The toast element to remove
 */
function removeToast(toast) {
  toast.style.opacity = '0';
  toast.style.transform = 'translateX(20px)';
  toast.style.transition = 'opacity 0.3s, transform 0.3s';
  
  setTimeout(() => {
    toast.remove();
  }, 300);
}

/**
 * Show or hide a loading indicator
 * @param {boolean} show - Whether to show the loading indicator
 * @param {string} containerId - ID of the container to show/hide
 */
function showLoading(show, containerId = 'loadingIndicator') {
  const loadingIndicator = document.getElementById(containerId);
  if (loadingIndicator) {
    loadingIndicator.style.display = show ? 'flex' : 'none';
  }
}

/**
 * Format a number as currency
 * @param {number} amount - The amount to format
 * @param {string} currency - The currency symbol
 * @param {number} decimals - Number of decimal places
 * @returns {string} Formatted currency string
 */
function formatCurrency(amount, currency = 'Rs.', decimals = 2) {
  return `${currency}${parseFloat(amount).toFixed(decimals).replace(/\d(?=(\d{3})+\.)/g, '$&,')}`;
}

/**
 * Format a date string
 * @param {string} dateString - The date string to format
 * @param {string} format - The format to use (short, medium, long)
 * @returns {string} Formatted date string
 */
function formatDate(dateString, format = 'medium') {
  const date = new Date(dateString);
  
  if (isNaN(date.getTime())) {
    return 'Invalid date';
  }
  
  switch (format) {
    case 'short':
      return date.toLocaleDateString();
    case 'medium':
      return date.toLocaleDateString(undefined, { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
      });
    case 'long':
      return date.toLocaleDateString(undefined, { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
      });
    default:
      return date.toLocaleDateString();
  }
}

/**
 * Log user activity to the server
 * @param {string} action - The action type (create, read, update, delete)
 * @param {string} module - The module name
 * @param {string} description - Description of the activity
 */
function logUserActivity(action, module, description) {
  const userId = document.getElementById('current-user-id')?.value;
  if (!userId) return;
  
  fetch('../api/log-activity.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      user_id: userId,
      action_type: action,
      module: module,
      description: description
    })
  }).catch(error => {
    console.error('Error logging activity:', error);
  });
}

/**
 * Validate a form field
 * @param {HTMLElement} field - The field to validate
 * @returns {boolean} Whether the field is valid
 */
function validateField(field) {
  const errorElement = document.getElementById(`${field.id}-error`);
  
  if (field.hasAttribute('required') && !field.value.trim()) {
    field.classList.add('invalid');
    if (errorElement) {
      const fieldName = field.labels?.[0]?.textContent.replace(':', '').replace('*', '') || 'Field';
      errorElement.textContent = `${fieldName} is required`;
    }
    return false;
  } else {
    field.classList.remove('invalid');
    if (errorElement) {
      errorElement.textContent = '';
    }
    return true;
  }
}

/**
 * Create a responsive table for mobile devices
 * @param {string} tableId - The ID of the table to make responsive
 */
function makeTableResponsive(tableId) {
  const table = document.getElementById(tableId);
  if (!table) return;
  
  // Add responsive class
  table.classList.add('responsive');
  
  // Get headers
  const headerCells = table.querySelectorAll('thead th');
  const headerTexts = Array.from(headerCells).map(cell => cell.textContent.trim());
  
  // Add data-label attribute to each cell
  const rows = table.querySelectorAll('tbody tr');
  rows.forEach(row => {
    const cells = row.querySelectorAll('td');
    cells.forEach((cell, index) => {
      if (headerTexts[index]) {
        cell.setAttribute('data-label', headerTexts[index]);
      }
    });
  });
}

// Export utilities for module usage
if (typeof module !== 'undefined' && module.exports) {
  module.exports = {
    showToast,
    removeToast,
    showLoading,
    formatCurrency,
    formatDate,
    logUserActivity,
    validateField,
    makeTableResponsive
  };
}