/**
 * CRM Main JavaScript
 */

// Global modal instances
let globalPreviewModal = null;
let globalAlertModal = null;
let globalConfirmModal = null;
let activePreviewUrl = null;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
    const sidebarCollapse = document.getElementById('sidebarCollapse');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');

    if (sidebarCollapse) {
        sidebarCollapse.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            content.classList.toggle('active');
        });
    }

    // Fix for aria-hidden on focusable elements inside modals (Accessibility)
    $(document).on('show.bs.modal shown.bs.modal', '.modal', function() {
        this.removeAttribute('aria-hidden');
        // Extra safety for some Bootstrap versions that re-add it during animation
        setTimeout(() => this.removeAttribute('aria-hidden'), 0);
    });

    // Initialize Global Modals
    initGlobalModals();

    // Select2 Global Initialization
    if (typeof $.fn.select2 === 'function') {
        $('.select2').select2({ width: '100%' });
        
        $('.select2-tags').select2({
            tags: true,
            width: '100%'
        });
    }

    // Fancybox Global
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind("[data-fancybox]", {
            // Your custom options
        });
    }
});

/**
 * Initialize global modal objects safely
 */
function initGlobalModals() {
    if (typeof bootstrap === 'undefined') return;

    const previewEl = document.getElementById('universalPreviewModal');
    if (previewEl && !globalPreviewModal) {
        globalPreviewModal = new bootstrap.Modal(previewEl);
        
        // Clean up when hidden
        previewEl.addEventListener('hidden.bs.modal', function() {
            document.getElementById('universalPreviewContent').innerHTML = '';
            activePreviewUrl = null;
            // Reset footer buttons
            const printBtn = document.getElementById('previewPrintBtn');
            if (printBtn) printBtn.disabled = true;
        });
    }

    const alertEl = document.getElementById('globalAlertModal');
    if (alertEl && !globalAlertModal) {
        globalAlertModal = new bootstrap.Modal(alertEl);
    }

    const confirmEl = document.getElementById('globalConfirmModal');
    if (confirmEl && !globalConfirmModal) {
        globalConfirmModal = new bootstrap.Modal(confirmEl);
    }
}

/**
 * Show a global alert
 */
function showAlert(message, title = window.LANG_NOTICE || 'Notice') {
    if (!globalAlertModal) initGlobalModals();
    
    document.getElementById('globalAlertTitle').innerText = title;
    document.getElementById('globalAlertBody').innerHTML = message;
    
    if (globalAlertModal) {
        globalAlertModal.show();
    } else {
        alert(message);
    }
}

/**
 * Show a global confirmation
 */
function showConfirm(message, onConfirm, title = window.LANG_CONFIRM || 'Confirm') {
    if (!globalConfirmModal) initGlobalModals();
    
    document.getElementById('globalConfirmTitle').innerText = title;
    document.getElementById('globalConfirmBody').innerHTML = message;
    
    const okBtn = document.getElementById('globalConfirmOk');
    const cancelBtn = document.getElementById('globalConfirmCancel');
    
    // Remote old listeners
    const newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    
    newOk.addEventListener('click', function() {
        globalConfirmModal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    if (globalConfirmModal) {
        globalConfirmModal.show();
    } else {
        if (confirm(message)) onConfirm();
    }
}

/**
 * Open universal preview modal with an iframe
 */
function openUniversalPreview(url, title = window.LANG_PREVIEW || 'Preview') {
    if (!globalPreviewModal) initGlobalModals();
    
    activePreviewUrl = url;
    const titleEl = document.getElementById('universalPreviewTitle');
    const contentEl = document.getElementById('universalPreviewContent');
    const printBtn = document.getElementById('previewPrintBtn');
    const openTabBtn = document.getElementById('previewOpenTabBtn');
    
    if (titleEl) titleEl.innerText = title;
    if (printBtn) printBtn.disabled = true;
    if (openTabBtn) openTabBtn.href = url;
    
    if (contentEl) {
        contentEl.innerHTML = '';
    }
    
    if (!globalPreviewModal) {
        // Fallback if modal initialization failed
        window.open(url, '_blank');
        return;
    }

    // Determine if this is a thermal/receipt document (narrow) or A4
    const isThermal = url.includes('thermal') || url.includes('reception');
    
    // Create iframe FIRST, add to DOM, THEN set src
    const iframe = document.createElement('iframe');
    iframe.id = 'previewIframe';
    iframe.style.width = '100%';
    iframe.style.minHeight = isThermal ? '60vh' : '80vh';
    iframe.style.height = isThermal ? '60vh' : '80vh';
    iframe.style.border = 'none';
    iframe.style.background = '#fff';
    iframe.style.display = 'none'; // Hidden until loaded
    
    // Add spinner placeholder
    const spinner = document.createElement('div');
    spinner.id = 'previewSpinner';
    spinner.className = 'text-center py-5';
    spinner.innerHTML = '<div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-white-75 small">Загрузка документа...</p>';
    
    contentEl.appendChild(spinner);
    contentEl.appendChild(iframe);
    
    // Handle load event
    iframe.onload = function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl) spinnerEl.remove();
        iframe.style.display = 'block';

        // Auto-resize iframe to fit content
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            if (doc && doc.body) {
                const h = doc.body.scrollHeight + 40;
                if (h > 200) {
                    iframe.style.height = Math.min(h, window.innerHeight * 0.85) + 'px';
                }
            }
        } catch(e) {
            // cross-origin, ignore
        }

        if (printBtn) printBtn.disabled = false;
    };

    iframe.onerror = function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl) {
            spinnerEl.innerHTML = '<div class="alert alert-warning m-3"><i class="fas fa-exclamation-triangle me-2"></i>Не удалось загрузить превью. <a href="' + url + '" target="_blank" class="alert-link">Открыть в новой вкладке</a></div>';
        }
    };
    
    // Set timeout fallback - if iframe doesn't load in 8 seconds
    const loadTimeout = setTimeout(function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl && iframe.style.display === 'none') {
            spinnerEl.innerHTML = '<div class="alert alert-info m-3"><i class="fas fa-info-circle me-2"></i>Загрузка занимает больше времени... <a href="' + url + '" target="_blank" class="alert-link">Открыть в новой вкладке</a></div>';
        }
    }, 8000);

    // Clean timeout on successful load
    const origOnload = iframe.onload;
    iframe.onload = function() {
        clearTimeout(loadTimeout);
        origOnload.call(this);
    };

    // Now set source - iframe is already in DOM so onload will fire
    // Add parameter to prevent auto-print when inside iframe
    const separator = url.includes('?') ? '&' : '?';
    iframe.src = url + separator + 'embed=1';

    globalPreviewModal.show();
}

/**
 * Print the content of the universal preview (directly from iframe)
 */
function printUniversalPreview() {
    if (!activePreviewUrl) return;
    
    const iframe = document.getElementById('previewIframe');
    
    if (iframe && iframe.contentWindow) {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch(e) {
            // Cross-origin fallback: open in new tab for printing
            const w = window.open(activePreviewUrl, '_blank');
            if (w) {
                w.onload = function() { w.print(); };
            }
        }
    } else {
        // No iframe available
        const w = window.open(activePreviewUrl, '_blank');
        if (w) {
            w.onload = function() { w.print(); };
        }
    }
}

/**
 * Open preview URL in a new tab
 */
function openPreviewInNewTab() {
    if (activePreviewUrl) {
        window.open(activePreviewUrl, '_blank');
    }
}
