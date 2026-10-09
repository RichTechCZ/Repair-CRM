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
    const sidebar = document.getElementById('sidebar');
    const sidebarCollapse = document.getElementById('sidebarCollapse');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebarCollapse) {
        sidebarCollapse.addEventListener('click', function() {
            setSidebarOpen(!document.body.classList.contains('sidebar-open'));
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', function() {
            closeSidebar(true);
        });
    }

    // Close drawer after choosing a destination (full navigation still unloads the page).
    if (sidebar) {
        sidebar.addEventListener('click', function(event) {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (link && isCompactSidebar() && document.body.classList.contains('sidebar-open')) {
                closeSidebar(false);
            }
        });
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            closeSidebar(true);
        }
    });

    let sidebarResizeTimer = 0;
    window.addEventListener('resize', function() {
        window.clearTimeout(sidebarResizeTimer);
        sidebarResizeTimer = window.setTimeout(function() {
            setSidebarOpen(document.body.classList.contains('sidebar-open'));
        }, 100);
    });
    setSidebarOpen(false);

    prepareSharedAccessibility();
    initDeclarativeActions();
    initFormGuards();
    enhanceMobileChrome();

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

    // Fancybox: the single global binding for every [data-fancybox] gallery.
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind('[data-fancybox]', {
            dragToClose: false,
            Image: {
                zoom: true,
            },
        });
    }
});

function callPageAction(name, ...args) {
    if (typeof window[name] === 'function') {
        return window[name](...args);
    }
    return undefined;
}

function readActionId(element) {
    const id = Number.parseInt(element.dataset.crmId || '', 10);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

/**
 * Form-level guards that replace inline onsubmit/onclick handlers (blocked by CSP):
 * - data-confirm on the submit button or the form asks via showConfirm() first;
 * - data-crm-once disables submit buttons after a valid submit (no duplicate records).
 */
/**
 * fetch() wrapper for JSON endpoints: rejects on HTTP errors, non-JSON bodies
 * (login redirects, PHP fatals) and {success:false}, with a user-safe message.
 */
function crmFetchJson(url, options) {
    const fallback = window.LANG_NETWORK_ERROR || 'Network error';
    return fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}))
        .then(function(response) {
            return response.text().then(function(body) {
                let data = null;
                try { data = JSON.parse(body); } catch (error) { data = null; }
                if (!data || typeof data !== 'object') throw new Error(fallback);
                if (!response.ok || data.success === false) {
                    throw new Error(data.message || data.error || fallback);
                }
                return data;
            });
        });
}
window.crmFetchJson = crmFetchJson;

function initFormGuards() {
    document.addEventListener('submit', function(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const submitter = event.submitter || null;
        const message = (submitter && submitter.dataset.confirm) || form.dataset.confirm || '';

        if (message && form.dataset.crmConfirmed !== '1') {
            event.preventDefault();
            showConfirm(message, function() {
                form.dataset.crmConfirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                    // If constraint validation blocked the submit, the next attempt must confirm again.
                    delete form.dataset.crmConfirmed;
                } else {
                    if (submitter && submitter.name) {
                        const carrier = document.createElement('input');
                        carrier.type = 'hidden';
                        carrier.name = submitter.name;
                        carrier.value = submitter.value;
                        form.appendChild(carrier);
                    }
                    form.submit();
                }
            });
            return;
        }
        delete form.dataset.crmConfirmed;

        if (form.hasAttribute('data-crm-once')) {
            if (form.dataset.crmSubmitting === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.crmSubmitting = '1';
            // Defer so the submitter's name/value is still part of this submission.
            setTimeout(function() {
                if (event.defaultPrevented) {
                    // A later handler cancelled the submit (e.g. AJAX or validation): stay usable.
                    delete form.dataset.crmSubmitting;
                    return;
                }
                form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
            }, 0);
        }
    }, true);

    // bfcache restores (Back button) must not leave the form permanently locked.
    window.addEventListener('pageshow', function() {
        document.querySelectorAll('form[data-crm-once]').forEach(function(form) {
            delete form.dataset.crmSubmitting;
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            });
        });
    });
}

/**
 * Shared replacement for inline event attributes. Keeping the action
 * allowlist here lets CSP enforce script-src-attr 'none' without breaking
 * dynamically rendered controls.
 */
function initDeclarativeActions() {
    document.addEventListener('click', function(event) {
        const element = event.target instanceof Element
            ? event.target.closest('[data-crm-action]')
            : null;
        if (!element) return;

        const action = element.dataset.crmAction;
        const id = readActionId(element);
        let handled = true;

        switch (action) {
            case 'show-new-invoice':
                callPageAction('showNewInvoiceModal');
                break;
            case 'open-preview':
                callPageAction('openUniversalPreview', element.dataset.previewUrl || '', element.dataset.previewTitle || '');
                break;
            case 'open-reception-language':
                if (id !== null) callPageAction('openReceptionLangModal', id);
                break;
            case 'edit-invoice':
                if (id !== null) callPageAction('editInvoice', id);
                break;
            case 'create-credit-note':
                if (id !== null) callPageAction('createCreditNote', id);
                break;
            case 'export-pohoda':
                if (id !== null) callPageAction('exportPohoda', id);
                break;
            case 'export-s3':
                if (id !== null) callPageAction('exportS3', id);
                break;
            case 'delete-invoice':
                if (id !== null) callPageAction('deleteInvoice', id);
                break;
            case 'toggle-customer-override':
                callPageAction('toggleCustomerOverride');
                break;
            case 'load-from-order':
                callPageAction('loadFromOrder');
                break;
            case 'add-invoice-item':
                callPageAction('addInvItem');
                break;
            case 'remove-invoice-item':
                element.closest('tr')?.remove();
                callPageAction('calcTotals');
                break;
            case 'show-customer-orders':
                if (id !== null) callPageAction('showCustomerOrders', id, element.dataset.customerName || '');
                break;
            case 'delete-customer':
                if (id !== null) callPageAction('deleteCustomer', id);
                break;
            case 'delete-media':
                if (id !== null) callPageAction('deleteMedia', id);
                break;
            case 'delete-order':
                if (id !== null) callPageAction('deleteOrder', id);
                break;
            case 'delete-part':
                if (id !== null) callPageAction('deletePart', id);
                break;
            case 'show-report-orders':
                if (id !== null) {
                    callPageAction(
                        'showOrdersModal',
                        id,
                        element.dataset.reportType || '',
                        element.dataset.reportTitle || ''
                    );
                }
                break;
            case 'test-technician-telegram':
                if (id !== null) callPageAction('testTechTG', id);
                break;
            case 'run-backup':
                callPageAction('runBackup');
                break;
            case 'check-updates':
                callPageAction('checkForUpdates', true);
                break;
            case 'edit-order-part':
                try {
                    callPageAction('openEditPartModal', JSON.parse(element.dataset.crmItem || '{}'));
                } catch (error) {
                    console.error('Invalid order-item action payload.', error);
                }
                break;
            case 'go-to-shipping':
                callPageAction('goToShipping');
                break;
            case 'open-preview-new-tab':
                callPageAction('openPreviewInNewTab');
                break;
            case 'print-preview':
                callPageAction('printUniversalPreview');
                break;
            case 'print-window':
                window.print();
                break;
            case 'close-window':
                window.close();
                break;
            case 'navigate-back': {
                // Prefer same-origin history when there was no safe ?return= URL.
                // Always keep a real href (orders.php / return) so CSP and no-JS still work.
                const explicitReturn = element.dataset.crmExplicitReturn === '1';
                if (!explicitReturn && window.history.length > 1 && document.referrer) {
                    try {
                        const referrer = new URL(document.referrer);
                        if (referrer.origin === window.location.origin) {
                            window.history.back();
                            break;
                        }
                    } catch (error) {
                        // Fall through to the safe href navigation.
                    }
                }
                handled = false;
                break;
            }
            default:
                handled = false;
        }

        if (handled) {
            event.preventDefault();
        }
    });

    document.addEventListener('change', function(event) {
        const element = event.target instanceof Element
            ? event.target.closest('[data-crm-change-action]')
            : null;
        if (!element) return;

        if (element.dataset.crmChangeAction === 'calc-totals') {
            callPageAction('calcTotals');
        } else if (element.dataset.crmChangeAction === 'submit-form' && element.form) {
            element.form.requestSubmit();
        }
    });

    document.addEventListener('submit', function(event) {
        const form = event.target;
        if (
            form instanceof HTMLFormElement &&
            form.dataset.crmSubmitAction === 'confirm-catalog-update' &&
            callPageAction('confirmCatalogUpdate', form) === false
        ) {
            event.preventDefault();
        }
    });
}

function isCompactSidebar() {
    return window.matchMedia('(max-width: 991.98px)').matches;
}

function setSidebarOpen(shouldOpen, restoreFocus = false) {
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    const mainContent = document.getElementById('main-content');
    const sidebarCollapse = document.getElementById('sidebarCollapse');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const isOpen = isCompactSidebar() && Boolean(shouldOpen);

    document.body.classList.toggle('sidebar-open', isOpen);
    document.body.classList.toggle('sidebar-drawer-mode', isCompactSidebar());
    if (sidebar) {
        sidebar.classList.toggle('active', isOpen);
        if (isCompactSidebar()) {
            sidebar.setAttribute('aria-hidden', String(!isOpen));
            sidebar.toggleAttribute('inert', !isOpen);
        } else {
            sidebar.removeAttribute('aria-hidden');
            sidebar.removeAttribute('inert');
        }
    }
    if (content) {
        content.classList.toggle('active', isOpen);
    }
    // Inert only the page body — keep the topbar hamburger usable to close the drawer.
    if (mainContent) {
        mainContent.toggleAttribute('inert', isOpen);
    }
    if (sidebarCollapse) {
        sidebarCollapse.setAttribute('aria-expanded', String(isOpen));
        sidebarCollapse.setAttribute(
            'aria-label',
            isOpen ? (window.LANG_CLOSE_NAVIGATION || 'Close navigation') : (window.LANG_OPEN_NAVIGATION || 'Open navigation')
        );
    }
    if (sidebarBackdrop) {
        // Decorative backdrop is never part of the reading order; pointer interaction stays enabled in CSS.
        sidebarBackdrop.setAttribute('aria-hidden', 'true');
    }

    if (isOpen && sidebar) {
        const firstNavigationLink = sidebar.querySelector('a[href]');
        if (firstNavigationLink) {
            firstNavigationLink.focus({ preventScroll: true });
        }
    } else if (restoreFocus && sidebarCollapse) {
        sidebarCollapse.focus({ preventScroll: true });
    }
}

function closeSidebar(restoreFocus = false) {
    setSidebarOpen(false, restoreFocus);
}

/**
 * Phone/tablet affordances that do not change desktop layout.
 *
 * Do NOT inject Bootstrap's modal-dialog-scrollable / modal-fullscreen-sm-down:
 * several CRM modals wrap header/body/footer in a <form>, and Bootstrap's
 * scrollable/fullscreen flex rules then collapse the dialog (New Order broke).
 * Mobile modal sizing is handled in CSS instead.
 */
function enhanceMobileChrome() {
    const isCoarse = window.matchMedia('(pointer: coarse)').matches
        || window.matchMedia('(hover: none)').matches;
    document.documentElement.classList.toggle('input-coarse', isCoarse);

    // Strip classes injected by older main.js builds if still present in markup.
    document.querySelectorAll('.modal-dialog.modal-lg, .modal-dialog.modal-xl').forEach(function(dialog) {
        dialog.classList.remove('modal-fullscreen-sm-down');
        // Keep author-declared scrollable only when the dialog was designed for it.
        if (dialog.dataset.crmKeepScrollable !== '1'
            && dialog.querySelector(':scope > .modal-content > form > .modal-body')) {
            dialog.classList.remove('modal-dialog-scrollable');
        }
    });
}

function prepareSharedAccessibility() {
    const closeLabel = window.LANG_CLOSE || 'Close';

    document.querySelectorAll('.btn-close:not([aria-label])').forEach(function(button) {
        button.setAttribute('aria-label', closeLabel);
    });

    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        if (!modal.hasAttribute('aria-labelledby')) {
            const title = modal.querySelector('.modal-title');
            if (title) {
                if (!title.id) {
                    title.id = modal.id ? modal.id + 'Title' : 'modalTitle';
                }
                modal.setAttribute('aria-labelledby', title.id);
            }
        }
    });

    let generatedFieldId = 0;
    document.querySelectorAll('label.form-label:not([for])').forEach(function(label) {
        const controls = label.parentElement
            ? Array.from(label.parentElement.querySelectorAll('input:not([type="hidden"]), select, textarea'))
            : [];

        if (controls.length !== 1) {
            return;
        }

        const control = controls[0];
        if (!control.id) {
            do {
                generatedFieldId += 1;
                control.id = 'crm-field-' + generatedFieldId;
            } while (document.querySelectorAll('#' + control.id).length > 1);
        }
        label.htmlFor = control.id;
    });

    document.querySelectorAll('a[title]:not([aria-label]), button[title]:not([aria-label])').forEach(function(control) {
        if (control.textContent.trim() === '') {
            control.setAttribute('aria-label', control.getAttribute('title'));
        }
    });
}

/**
 * Initialize global modal objects safely
 */
function initGlobalModals() {
    if (typeof bootstrap === 'undefined') return;

    const previewEl = document.getElementById('universalPreviewModal');
    if (previewEl && !globalPreviewModal) {
        globalPreviewModal = bootstrap.Modal.getOrCreateInstance(previewEl);
        
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
        globalAlertModal = bootstrap.Modal.getOrCreateInstance(alertEl);
    }

    const confirmEl = document.getElementById('globalConfirmModal');
    if (confirmEl && !globalConfirmModal) {
        globalConfirmModal = bootstrap.Modal.getOrCreateInstance(confirmEl);
    }
}

/**
 * Show a global alert
 */
function showAlert(message, title = window.LANG_NOTICE || 'Notice') {
    if (!globalAlertModal) initGlobalModals();
    
    document.getElementById('globalAlertTitle').innerText = title;
    document.getElementById('globalAlertBody').textContent = message;
    announceStatus(message);
    
    if (globalAlertModal) {
        globalAlertModal.show();
    } else {
        alert(message);
    }
}

/**
 * Show a global confirmation.
 * tone: omitted/'primary' for ordinary confirmations, 'danger' for destructive actions.
 */
function showConfirm(message, onConfirm, title, tone) {
    if (!globalConfirmModal) initGlobalModals();

    document.getElementById('globalConfirmTitle').innerText = title || window.LANG_CONFIRM || 'Confirm';
    document.getElementById('globalConfirmBody').textContent = message;

    const okBtn = document.getElementById('globalConfirmOk');

    // Replace the button to drop listeners from a previous confirmation.
    const newOk = okBtn.cloneNode(true);
    newOk.classList.toggle('btn-danger', tone === 'danger');
    newOk.classList.toggle('btn-primary', tone !== 'danger');
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

    let safeUrl;
    try {
        safeUrl = new URL(String(url), window.location.href);
        if (safeUrl.origin !== window.location.origin || !['http:', 'https:'].includes(safeUrl.protocol)) {
            throw new Error('Cross-origin document previews are not allowed.');
        }
    } catch (error) {
        console.error('Blocked unsafe preview URL', error);
        return;
    }

    activePreviewUrl = safeUrl.href;
    const titleEl = document.getElementById('universalPreviewTitle');
    const contentEl = document.getElementById('universalPreviewContent');
    const printBtn = document.getElementById('previewPrintBtn');
    const openTabBtn = document.getElementById('previewOpenTabBtn');
    
    if (titleEl) titleEl.innerText = title;
    if (printBtn) printBtn.disabled = true;
    if (openTabBtn) {
        openTabBtn.href = safeUrl.href;
        openTabBtn.rel = 'noopener noreferrer';
    }
    
    if (contentEl) {
        contentEl.innerHTML = '';
    }
    
    if (!globalPreviewModal) {
        // Fallback if modal initialization failed
        window.open(safeUrl.href, '_blank', 'noopener,noreferrer');
        return;
    }

    // Determine if this is a thermal/receipt document (narrow) or A4
    const isThermal = safeUrl.pathname.includes('thermal') || safeUrl.pathname.includes('reception');
    
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
    spinner.innerHTML = '<div class="spinner-border text-primary" aria-hidden="true"></div><p class="mt-2 text-white-75 small" role="status"></p>';
    spinner.querySelector('p').textContent = window.LANG_PREVIEW_LOADING || 'Loading document...';
    
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
            renderPreviewNotice(spinnerEl, 'alert-warning', 'fa-exclamation-triangle',
                window.LANG_PREVIEW_FAILED || 'Could not load the preview.', safeUrl.href);
        }
    };
    
    // Set timeout fallback - if iframe doesn't load in 8 seconds
    const loadTimeout = setTimeout(function() {
        const spinnerEl = document.getElementById('previewSpinner');
        if (spinnerEl && iframe.style.display === 'none') {
            renderPreviewNotice(spinnerEl, 'alert-info', 'fa-info-circle',
                window.LANG_PREVIEW_SLOW || 'Loading is taking longer than usual...', safeUrl.href);
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
    safeUrl.searchParams.set('embed', '1');
    iframe.src = safeUrl.href;

    globalPreviewModal.show();
}

/**
 * Replace the preview spinner with a notice and an "open in new tab" link.
 * All text goes through textContent; the URL was validated as same-origin.
 */
function renderPreviewNotice(container, alertClass, iconClass, message, href) {
    const notice = document.createElement('div');
    notice.className = 'alert m-3 ' + alertClass;
    notice.setAttribute('role', 'status');

    const icon = document.createElement('i');
    icon.className = 'fas me-2 ' + iconClass;
    icon.setAttribute('aria-hidden', 'true');

    const link = document.createElement('a');
    link.className = 'alert-link';
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.href = href;
    link.textContent = window.LANG_OPEN_NEW_TAB || 'Open in a new tab';

    notice.append(icon, document.createTextNode(message + ' '), link);
    container.replaceChildren(notice);
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

function announceStatus(message) {
    const liveRegion = document.getElementById('appStatusLive');
    if (!liveRegion || !message) return;

    liveRegion.textContent = '';
    window.setTimeout(function() {
        liveRegion.textContent = message;
    }, 25);
}

/**
 * Trigger a file download using a temporary anchor.
 */
function triggerDownload(url) {
    if (!url) return;

    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    link.target = '_blank';
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
