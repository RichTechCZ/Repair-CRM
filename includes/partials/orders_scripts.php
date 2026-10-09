<?php
/** Orders page scripts (PHP-rendered i18n). Included from orders.php */
?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
// Phone QR popover. Hover, click, and keyboard open the same QR; Call is
// keyboard-reachable (Enter/Space focuses the call button; Escape restores focus).
document.addEventListener('DOMContentLoaded', function() {
    const popover = document.getElementById('phoneQrPopover');
    const qrContainer = document.getElementById('qrContainer');
    const phoneLabel = document.getElementById('qrPhoneLabel');
    const callBtn = document.getElementById('qrCallBtn');
    if (!popover || !qrContainer || !phoneLabel || !callBtn) return;

    let hideTimeout;
    let activePhone = '';
    let activeTrigger = null;

    function isPopoverOpen() {
        return popover.style.display === 'block';
    }

    function setExpanded(trigger, expanded) {
        if (!trigger) return;
        trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function hidePopover(options) {
        const restoreFocus = options && options.restoreFocus;
        const trigger = activeTrigger;
        clearTimeout(hideTimeout);
        popover.style.display = 'none';
        popover.setAttribute('aria-hidden', 'true');
        setExpanded(trigger, false);
        activeTrigger = null;
        if (restoreFocus && trigger) {
            trigger.focus({ preventScroll: true });
        }
    }

    function positionPopover(trigger) {
        const rect = trigger.getBoundingClientRect();
        const gap = 12;
        const pad = 8;
        const pw = popover.offsetWidth || 160;
        const ph = popover.offsetHeight || 200;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const isNarrow = vw < 768;

        // Desktop: to the right of the phone cell. Phone: below the trigger, clamped to viewport.
        let left = isNarrow ? rect.left : (rect.right + gap);
        let top = isNarrow ? (rect.bottom + gap) : rect.top;

        if (left + pw > vw - pad) {
            left = Math.max(pad, rect.right - pw);
        }
        if (top + ph > vh - pad) {
            top = Math.max(pad, rect.top - gap - ph);
        }

        left = Math.min(Math.max(pad, left), Math.max(pad, vw - pw - pad));
        top = Math.min(Math.max(pad, top), Math.max(pad, vh - ph - pad));

        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    }

    function renderQr(phone) {
        if (activePhone === phone && qrContainer.querySelector('img')) return;

        activePhone = phone;
        const image = document.createElement('img');
        // Same-origin generator: customer phone numbers are not sent to a third-party service.
        image.src = 'api/qr.php?d=' + encodeURIComponent('tel:' + phone);
        image.width = 120;
        image.height = 120;
        image.alt = 'QR: ' + phone;
        image.decoding = 'async';
        image.referrerPolicy = 'no-referrer';
        image.addEventListener('error', function() {
            if (activePhone !== phone) return;
            qrContainer.replaceChildren();
            const message = document.createElement('span');
            message.className = 'small text-white-75';
            message.textContent = '<?php echo e(__('error')); ?>';
            qrContainer.appendChild(message);
        });
        qrContainer.replaceChildren(image);
    }

    function focusIsInsidePopoverOrTrigger() {
        const active = document.activeElement;
        if (!active) return false;
        if (popover.contains(active)) return true;
        if (activeTrigger && (active === activeTrigger || activeTrigger.contains(active))) return true;
        return false;
    }

    function scheduleHide() {
        clearTimeout(hideTimeout);
        hideTimeout = setTimeout(function() {
            if (focusIsInsidePopoverOrTrigger()) return;
            hidePopover();
        }, 180);
    }

    function showPopover(trigger, options) {
        clearTimeout(hideTimeout);
        const phone = trigger.dataset.phone || '';
        if (!phone) return;

        if (activeTrigger && activeTrigger !== trigger) {
            setExpanded(activeTrigger, false);
        }

        renderQr(phone);
        phoneLabel.textContent = phone;
        callBtn.href = 'tel:' + phone;
        activeTrigger = trigger;
        setExpanded(trigger, true);
        popover.style.display = 'block';
        popover.setAttribute('aria-hidden', 'false');
        positionPopover(trigger);

        if (options && options.focusCall) {
            callBtn.focus({ preventScroll: true });
        }
    }

    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    document.querySelectorAll('.phone-qr-trigger').forEach(el => {
        el.setAttribute('aria-controls', 'phoneQrPopover');
        el.setAttribute('aria-haspopup', 'dialog');
        if (!el.hasAttribute('aria-expanded')) {
            el.setAttribute('aria-expanded', 'false');
        }

        // Tap toggles on touch devices; second tap closes. Outside-tap also closes (below).
        el.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            if (activeTrigger === this && isPopoverOpen()) {
                hidePopover();
                return;
            }
            showPopover(this);
        });

        // Hover-only open/close on real desktop pointers — avoids sticky open states on iOS/Android.
        if (canHover) {
            el.addEventListener('mouseenter', function() {
                showPopover(this);
            });
            el.addEventListener('mouseleave', scheduleHide);
        }

        el.addEventListener('focusin', function() {
            showPopover(this);
        });
        el.addEventListener('focusout', scheduleHide);
        el.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                showPopover(this, { focusCall: true });
            }
        });
    });

    if (canHover) {
        popover.addEventListener('mouseenter', function() {
            clearTimeout(hideTimeout);
        });
        popover.addEventListener('mouseleave', scheduleHide);
    }
    popover.addEventListener('focusin', function() {
        clearTimeout(hideTimeout);
    });
    popover.addEventListener('focusout', scheduleHide);
    // Keep clicks inside the popover from bubbling to the document closer.
    popover.addEventListener('click', function(event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function(event) {
        if (!isPopoverOpen()) return;
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (popover.contains(target)) return;
        if (activeTrigger && (activeTrigger === target || activeTrigger.contains(target))) return;
        hidePopover();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && isPopoverOpen()) {
            event.preventDefault();
            hidePopover({ restoreFocus: true });
        }
    });
    window.addEventListener('resize', function() {
        if (activeTrigger && isPopoverOpen()) {
            positionPopover(activeTrigger);
        }
    });
    window.addEventListener('scroll', function() {
        if (activeTrigger && isPopoverOpen()) {
            // Hide on scroll — avoids orphaned fixed popovers while the table moves under a finger.
            hidePopover();
        }
    }, { passive: true, capture: true });
});
</script>

<script nonce="<?php echo e(crmCspNonce()); ?>">
// ── IMEI Duplicate Badge Handler ──────────────────────────────────────────
(function () {
    var imeiModal = null;

    function getImeiModal() {
        if (!imeiModal) {
            imeiModal = new bootstrap.Modal(document.getElementById('imeiDuplicatesModal'));
        }
        return imeiModal;
    }

    document.addEventListener('click', function (e) {
        var badge = e.target.closest('.imei-dup-badge');
        if (!badge) return;
        e.stopPropagation();

        var sn = badge.dataset.sn || '';
        if (!sn) return;

        document.getElementById('imeiModalSn').textContent = sn;
        document.getElementById('imeiModalBody').innerHTML =
            '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Загрузка...</span></div></div>';

        getImeiModal().show();

        fetch('api/get_imei_duplicates.php?sn=' + encodeURIComponent(sn))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    document.getElementById('imeiModalBody').innerHTML =
                        '<div class="alert alert-danger">Ошибка: ' + escHtml(data.error) + '</div>';
                    return;
                }
                renderImeiDuplicates(data.orders, sn);
            })
            .catch(function () {
                document.getElementById('imeiModalBody').innerHTML =
                    '<div class="alert alert-danger">Ошибка загрузки данных</div>';
            });
    });

    function renderImeiDuplicates(orders, sn) {
        var body = document.getElementById('imeiModalBody');
        if (!orders || !orders.length) {
            body.innerHTML = '<div class="alert alert-info">Заявок не найдено</div>';
            return;
        }

        var statusVariants = {
            'Accepted': 'accepted',
            'Diagnostics': 'diagnostics',
            'Approval': 'approval',
            'In Repair': 'repair',
            'Ready': 'ready',
            'Issued': 'issued',
            'Issued Without Repair': 'closed',
            'Repair Cancelled': 'cancelled',
            'New': 'accepted',
            'Pending Approval': 'approval',
            'In Progress': 'repair',
            'Waiting for Parts': 'waiting',
            'Completed': 'ready',
            'Collected': 'issued',
            'Cancelled': 'cancelled'
        };
        var statusLabels = <?php echo json_encode([
            'Accepted' => getStatusLabel('Accepted'),
            'Diagnostics' => getStatusLabel('Diagnostics'),
            'Approval' => getStatusLabel('Approval'),
            'In Repair' => getStatusLabel('In Repair'),
            'Ready' => getStatusLabel('Ready'),
            'Issued' => getStatusLabel('Issued'),
            'Issued Without Repair' => getStatusLabel('Issued Without Repair'),
            'Repair Cancelled' => getStatusLabel('Repair Cancelled'),
            'New' => getStatusLabel('New'),
            'Pending Approval' => getStatusLabel('Pending Approval'),
            'In Progress' => getStatusLabel('In Progress'),
            'Waiting for Parts' => getStatusLabel('Waiting for Parts'),
            'Completed' => getStatusLabel('Completed'),
            'Collected' => getStatusLabel('Collected'),
            'Cancelled' => getStatusLabel('Cancelled'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        var html = '<div class="table-responsive">'
            + '<table class="table table-hover align-middle mb-0">'
            + '<thead><tr>'
            + '<th>#</th><th>Дата</th><th>Клиент</th>'
            + '<th>Устройство</th><th>Серийный номер</th><th>Статус</th>'
            + '</tr></thead><tbody>';

        orders.forEach(function (o) {
            var variant = statusVariants[o.status] || 'closed';
            var statusText = statusLabels[o.status] || o.status;

            var sns = [];
            if (o.serial_number)   sns.push({ val: o.serial_number,   match: o.serial_number === sn });
            if (o.serial_number_2) sns.push({ val: o.serial_number_2, match: o.serial_number_2 === sn });

            var snHtml = sns.map(function (s) {
                return s.match
                    ? '<span class="fw-bold text-warning font-monospace">' + escHtml(s.val) + '</span>'
                    : '<span class="font-monospace">' + escHtml(s.val) + '</span>';
            }).join('<br>');

            var dateStr = o.created_at ? o.created_at.substring(0, 10) : '';

            html += '<tr>'
                + '<td><a href="view_order.php?id=' + parseInt(o.id, 10) + '" class="fw-bold text-decoration-none" target="_blank">#' + parseInt(o.id, 10) + '</a></td>'
                + '<td class="small">' + escHtml(dateStr) + '</td>'
                + '<td>' + escHtml(o.first_name + ' ' + o.last_name) + '</td>'
                + '<td class="small">' + escHtml(o.device_brand + ' ' + o.device_model) + '</td>'
                + '<td class="small">' + snHtml + '</td>'
                + '<td><span class="status-pill status-pill--' + variant + '">' + escHtml(statusText) + '</span></td>'
                + '</tr>';
        });

        html += '</tbody></table></div>';
        body.innerHTML = html;
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}());
</script>

<script nonce="<?php echo e(crmCspNonce()); ?>">
// FIX #3: escHTML prevents XSS when injecting data into innerHTML/template literals
function escHTML(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}

/** Bootstrap 5 has no jQuery modal plugin — always use the native API. */
function showBsModal(elementId) {
    const el = document.getElementById(elementId);
    if (!el || typeof bootstrap === 'undefined') return null;
    const instance = bootstrap.Modal.getOrCreateInstance(el);
    instance.show();
    return instance;
}

function hideBsModal(elementId) {
    const el = document.getElementById(elementId);
    if (!el || typeof bootstrap === 'undefined') return;
    const instance = bootstrap.Modal.getInstance(el) || bootstrap.Modal.getOrCreateInstance(el);
    instance.hide();
}

window.showBsModal = showBsModal;
window.hideBsModal = hideBsModal;

$(document).ready(function() {
    $('.order-modal-trigger').on('click', function() {
        const id = $(this).data('id');
        $('#quickOrderTitle').text('<?php echo __('order_header'); ?> #' + id);
        showBsModal('quickOrderModal');
        $('#quickOrderBody').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');
        $('#fullViewBtn').attr('href', 'view_order.php?id=' + id);
        $('#saveQuickOrderBtn').prop('disabled', true);

        $.get('api/get_order_details.php', {id: id}, function(res) {
            if (res && res.success) {
                const o = res.order;
                const attachments = res.attachments || [];
                
                let mediaHtml = '';
                if (attachments.length > 0) {
                    mediaHtml = '<div class="row g-2 mt-2">';
                    attachments.forEach(file => {
                        const isVideo = file.file_type.includes('video');
                        mediaHtml += `
                            <div class="col-3 col-md-2" id="media-item-${(+file.id) || 0}">
                                <div class="card h-100 shadow-sm border position-relative">
                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-1 line-height-1" style="z-index: 10; font-size: 0.6rem;" data-crm-action="delete-media" data-crm-id="${(+file.id) || 0}">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="ratio ratio-1x1 bg-dark bg-opacity-25 border-secondary">
                                        ${isVideo ? 
                                            `<div class="d-flex align-items-center justify-content-center bg-dark"><i class="fas fa-video text-white"></i></div>` : 
                                            `<img src="${escHTML(file.url)}" class="object-fit-cover" alt="${escHTML(<?php echo json_encode(__('attachment_photo_alt'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)}">`
                                        }
                                    </div>
                                </div>
                            </div>`;
                    });
                    mediaHtml += '</div>';
                } else {
                    mediaHtml = '<div class="text-white-75 small mt-2"><?php echo __('no_media_files'); ?></div>';
                }

                let html = `
                    <form id="quickOrderForm">
                        <input type="hidden" name="order_id" value="${(+o.id) || 0}">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('client_and_date'); ?></label>
                                <div class="fw-bold">${escHTML(o.first_name)} ${escHTML(o.last_name)}</div>
                                <div class="small text-primary"><i class="far fa-clock me-1"></i>${new Date(o.created_at).toLocaleString()}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('device'); ?></label>
                                <div class="fw-bold">${escHTML(o.device_brand)} ${escHTML(o.device_model)}</div>
                                <div class="badge ${o.order_type == 'Warranty' ? 'bg-success' : 'bg-secondary'}">${o.order_type == 'Warranty' ? '<?php echo __('warranty'); ?>' : '<?php echo __('non_warranty'); ?>'}</div>
                                ${o.shipping_method ? `<div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i>${escHTML(o.shipping_method)}</div>` : ''}
                                ${res.role == 'admin' && o.extra_expenses > 0 ? `<div class="mt-1 small text-danger"><i class="fas fa-minus-circle me-1"></i><?php echo __('extra_expenses'); ?>: ${escHTML(o.extra_expenses)}</div>` : ''}
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-white-75 small mb-1"><?php echo __('serial_numbers'); ?></label>
                                <div class="small"><?php echo __('sn1'); ?>: <strong>${escHTML(o.serial_number) || '---'}</strong></div>
                                <div class="small"><?php echo __('sn2'); ?>: <strong>${escHTML(o.serial_number_2) || '---'}</strong></div>
                            </div>
                            
                            <hr class="my-3">

                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('status'); ?></label>
                                <select name="status" class="form-select">
                                    <option value="Accepted" ${o.status=='Accepted' ? 'selected':''}><?php echo getStatusLabel('Accepted'); ?></option>
                                    <option value="Diagnostics" ${o.status=='Diagnostics' ? 'selected':''}><?php echo getStatusLabel('Diagnostics'); ?></option>
                                    <option value="Approval" ${o.status=='Approval' ? 'selected':''}><?php echo getStatusLabel('Approval'); ?></option>
                                    <option value="In Repair" ${o.status=='In Repair' ? 'selected':''}><?php echo getStatusLabel('In Repair'); ?></option>
                                    <option value="Ready" ${o.status=='Ready' ? 'selected':''}><?php echo getStatusLabel('Ready'); ?></option>
                                    <option value="Issued" ${o.status=='Issued' ? 'selected':''}><?php echo getStatusLabel('Issued'); ?></option>
                                    <option value="Issued Without Repair" ${o.status=='Issued Without Repair' ? 'selected':''}><?php echo getStatusLabel('Issued Without Repair'); ?></option>
                                    <option value="Repair Cancelled" ${o.status=='Repair Cancelled' ? 'selected':''}><?php echo getStatusLabel('Repair Cancelled'); ?></option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('technician'); ?></label>
                                <select name="technician_id" class="form-select" ${res.role != 'admin' ? 'disabled' : ''}>
                                    <option value="">-- <?php echo __('edit'); ?> --</option>
                                    <?php
                                    // FIX #5: use pre-loaded $techs_list
                                    foreach ($techs_list as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('price_estimated'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="estimated_cost" class="form-control" value="${o.estimated_cost || 0}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('price_final'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="final_cost" class="form-control" value="${o.final_cost || o.estimated_cost || 0}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-12 ${res.role == 'admin' ? '' : 'd-none'}">
                                <label class="form-label"><?php echo __('extra_expenses_desc'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="extra_expenses" class="form-control" step="0.01" value="${o.extra_expenses || 0}">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('problem'); ?></label>
                                <textarea name="problem_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('notes'); ?></label>
                                <textarea name="technician_notes" class="form-control" rows="3"></textarea>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <label class="form-label"><?php echo __('media_files'); ?></label>
                                ${mediaHtml}
                            </div>
                        </div>
                    </form>
                `;
                $('#quickOrderBody').html(html);
                // FIX #3: Use .val() for textarea content to prevent XSS via innerHTML
                $('#quickOrderBody textarea[name="problem_description"]').val(o.problem_description || '');
                $('#quickOrderBody textarea[name="technician_notes"]').val(o.technician_notes || '');
                $('#quickOrderBody select[name="technician_id"]').val(o.technician_id);
                
                // Update print links in modal footer
                const footerLinks = $('#quickOrderModal .modal-footer .dropdown-item');
                footerLinks.eq(0)
                    .attr('data-preview-url', `print_order.php?id=${o.id}`)
                    .attr('data-preview-title', `<?php echo e(__('order_header')); ?> #${o.id}`);
                footerLinks.eq(1).attr('data-crm-id', o.id);
                footerLinks.eq(2)
                    .attr('data-preview-url', `print_workshop.php?id=${o.id}`)
                    .attr('data-preview-title', `<?php echo e(__('work_order')); ?> #${o.id}`);
                footerLinks.eq(3)
                    .attr('data-preview-url', `print_thermal.php?id=${o.id}`)
                    .attr('data-preview-title', `<?php echo e(__('thermal_receipt')); ?> #${o.id}`);

                $('#saveQuickOrderBtn').prop('disabled', false);
                
                // Set delete order button action
                $('#deleteQuickOrderBtn').off('click').on('click', function() {
                    deleteOrder(o.id);
                });
            } else {
                const message = (res && res.message) ? res.message : '<?php echo __('error'); ?>';
                $('#quickOrderBody').html($('<div class="alert alert-danger"></div>').text(message));
            }
        }).fail(function() {
            // Expired session / 5xx: replace the spinner instead of spinning forever.
            $('#quickOrderBody').html($('<div class="alert alert-danger"></div>').text(window.LANG_NETWORK_ERROR || '<?php echo e(__('error')); ?>'));
        });
    });

    $('#saveQuickOrderBtn').off('click').on('click', function(e) {
        e.preventDefault();
        const form = $('#quickOrderForm');
        const formData = form.serialize();
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');
        
        $.ajax({
            url: 'api/update_order_full.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            cache: false,
            success: function(res) {
                if (res.success) {
                    hideBsModal('quickOrderModal');
                    // Small delay before reload to ensure UI state is clean
                    setTimeout(() => {
                        window.location.reload();
                    }, 150);
                } else {
                    btn.prop('disabled', false).text('<?php echo __('save_changes'); ?>');
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                }
            },
            error: function(xhr, status, error) {
                btn.prop('disabled', false).text('<?php echo __('save_changes'); ?>');
                showAlert('<?php echo __('error'); ?>');
            }
        });
    });

    let currentCustomerSearch = '';
    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }
    function highlightMatch(text, term) {
        if (!term) return escapeHtml(text);
        const safe = escapeHtml(text);
        const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        return safe.replace(re, '<span class="match">$1</span>');
    }

    $('.select2-customer').select2({
        dropdownParent: $('#newOrderModal'),
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            url: 'api/search_customers.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                currentCustomerSearch = params.term || '';
                return { q: params.term, page: params.page || 1 };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.results, pagination: { more: data.pagination.more } };
            }
        },
        templateResult: function(item) {
            if (item.loading) return item.text;
            const name = item.name || item.text || '';
            const phone = item.phone || '';
            const title = highlightMatch(name, currentCustomerSearch);
            const meta = phone ? '<span class="meta">' + highlightMatch(phone, currentCustomerSearch) + '</span>' : '';
            return $('<div class="customer-option"><div>' + title + '</div>' + meta + '</div>');
        },
        templateSelection: function(item) {
            // escapeMarkup is a no-op (templateResult returns pre-escaped nodes), so the
            // selection must be a text node: customer names are user-controlled.
            return $('<span>').text(item.text || item.name || '');
        },
        escapeMarkup: function(markup) { return markup; }
    });

    $('.select2-brand').select2({
        dropdownParent: $('#newOrderModal'),
        placeholder: "<?php echo __('brand'); ?>",
        tags: true
    });

    // ── Model autocomplete (Select2 AJAX) ──
    $('#deviceModelSelect').select2({
        dropdownParent: $('#newOrderModal'),
        placeholder: "<?php echo __('model_placeholder'); ?>",
        allowClear: true,
        tags: true,
        minimumInputLength: 1,
        ajax: {
            url: 'api/get_device_models.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term,
                    brand: $('select[name="device_brand"]').val() || ''
                };
            },
            processResults: function(data) {
                return { results: data.results };
            }
        }
    });

    // Reset model when brand changes
    $('select[name="device_brand"]').on('change', function() {
        $('#deviceModelSelect').val(null).trigger('change');
    });

    // ── S/N / IMEI uppercase ──
    $(document).on('input', '.sn-uppercase', function() {
        const pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });

    // ── Auto-focus customer search when modal opens ──
    $('#newOrderModal').on('shown.bs.modal', function() {
        setTimeout(function() {
            $('#newOrderModal .select2-customer').select2('open');
        }, 100);
    });

    // ── Copy Order # ──
    $('#copyOrderBtn').on('click', function() {
        const orderId = parseInt($('#copyOrderIdInput').val(), 10);
        if (!orderId || orderId < 1) {
            showAlert('<?php echo __('copy_order_enter_id'); ?>');
            return;
        }
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.get('api/copy_order.php', { id: orderId }, function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-copy me-1"></i><?php echo __('copy_order_btn'); ?>');
            if (!res.success) {
                showAlert(res.message || '<?php echo __('copy_order_not_found'); ?>');
                return;
            }
            const o = res.order;

            // Build confirmation message
            let info = '<?php echo __('copy_order_confirm'); ?>\n\n';
            info += '<?php echo __('client'); ?>: ' + (o.first_name || '') + ' ' + (o.last_name || '') + '\n';
            info += '<?php echo __('device_brand'); ?>: ' + (o.device_brand || '') + '\n';
            info += '<?php echo __('device_model'); ?>: ' + (o.device_model || '') + '\n';
            info += 'S/N: ' + (o.serial_number || '') + '\n';
            info += 'IMEI 2: ' + (o.serial_number_2 || '') + '\n';
            info += '<?php echo __('problem'); ?>: ' + (o.problem_description || '') + '\n';

            showConfirm(info, function() {
                // Fill customer
                if (o.customer_id) {
                    const custLabel = ((o.first_name || '') + ' ' + (o.last_name || '')).trim();
                    const $sel = $('.select2-customer');
                    const opt = new Option(custLabel, o.customer_id, true, true);
                    $sel.append(opt).trigger('change');
                }
                // Fill device type
                if (o.device_type) {
                    $('input[name="device_type"]').filter(function() {
                        return this.value === o.device_type;
                    }).prop('checked', true);
                }
                // Fill order type
                if (o.order_type) $('select[name="order_type"]').val(o.order_type);
                // Fill brand
                if (o.device_brand) {
                    const $brand = $('select[name="device_brand"]');
                    if ($brand.find('option[value="' + o.device_brand + '"]').length === 0) {
                        $brand.append(new Option(o.device_brand, o.device_brand, true, true));
                    } else {
                        $brand.val(o.device_brand);
                    }
                    $brand.trigger('change');
                }
                // Fill model
                if (o.device_model) {
                    const $model = $('#deviceModelSelect');
                    $model.append(new Option(o.device_model, o.device_model, true, true)).trigger('change');
                }
                // Fill S/N
                if (o.serial_number) $('input[name="serial_number"]').val(o.serial_number.toUpperCase());
                if (o.serial_number_2) $('input[name="serial_number_2"]').val(o.serial_number_2.toUpperCase());
                // Appearance, PIN
                if (o.appearance) $('input[name="appearance"]').val(o.appearance);
                // PIN intentionally not copied from API payloads.
                // Problem
                if (o.problem_description) $('textarea[name="problem_description"]').val(o.problem_description);
                if (o.technician_notes) $('textarea[name="technician_notes"]').val(o.technician_notes);
                // Priority
                if (o.priority === 'High') $('#priorityHighOrders').prop('checked', true);
                // Estimated cost
                if (o.estimated_cost) $('input[name="estimated_cost"]').val(o.estimated_cost);
                // Technician
                if (o.technician_id) $('select[name="technician_id"]').val(o.technician_id);
            });
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="fas fa-copy me-1"></i><?php echo __('copy_order_btn'); ?>');
            showAlert('<?php echo __('error'); ?>');
        });
    });

    // Allow Enter in copy order input
    $('#copyOrderIdInput').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#copyOrderBtn').trigger('click');
        }
    });

    $('.order-template-select').on('change', function() {
        const value = $(this).val();
        if (!value) return;
        const targetName = $(this).data('target');
        const $area = $(this).closest('form').find('textarea[name="' + targetName + '"]');
        if (!$area.length) return;
        const current = $area.val().trim();
        $area.val(current ? (current + "\n" + value) : value).trigger('input');
        $(this).val('');
    });

    function showQuickToast(message, type) {
        const toastEl = document.getElementById('quickStatusToast');
        const toastBody = document.getElementById('quickStatusToastBody');
        if (!toastEl || !toastBody) return showAlert(message);
        toastEl.classList.remove('crm-toast--success', 'crm-toast--danger');
        toastEl.classList.add(type === 'success' ? 'crm-toast--success' : 'crm-toast--danger');
        toastEl.setAttribute('role', type === 'success' ? 'status' : 'alert');
        toastBody.textContent = message;
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    }

    function performQuickStatusUpdate(id, status, btn, cancellationReason) {
        $.post('api/update_order_status.php', {
            order_id: id,
            status: status,
            cancellation_reason: cancellationReason || '',
            csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
        }, function(res) {
            if (res.success) {
                showQuickToast('<?php echo __('updated_success'); ?>', 'success');
                setTimeout(() => window.location.reload(), 300);
            } else {
                if (btn) btn.prop('disabled', false);
                showQuickToast(res.message || '<?php echo __('error'); ?>', 'danger');
            }
        }, 'json').fail(function() {
            if (btn) btn.prop('disabled', false);
            showQuickToast('<?php echo __('error'); ?>', 'danger');
        });
    }

    // Delegate: status items live inside Bootstrap dropdowns and must not rely on inline handlers.
    $(document).on('click', '.quick-status-btn', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        const status = $(this).data('status');
        if (!id || !status) return;
        const btn = $(this);
        btn.prop('disabled', true);

        if (status === 'Repair Cancelled') {
            // Re-enable if the operator dismisses the confirm dialog without accepting.
            btn.prop('disabled', false);
            return showConfirm('<?php echo __('delete_confirm'); ?>', function() {
                btn.prop('disabled', true);
                const reason = window.prompt('<?php echo __('cancellation_reason'); ?>');
                if (reason === null || !reason.trim()) {
                    btn.prop('disabled', false);
                    return;
                }
                performQuickStatusUpdate(id, status, btn, reason.trim());
            }, undefined, 'danger');
        }

        performQuickStatusUpdate(id, status, btn);
    });

    // Inline New Customer: company/private toggle
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#inline_company_fields').removeClass('d-none');
            $('#inline_first_name').val('Firma');
            $('#inline_last_name').val('');
        } else {
            $('#inline_company_fields').addClass('d-none');
            $('#inline_first_name').val('');
            $('#inline_last_name').val('');
        }
    });

    // Inline New Customer: ARES fetch
    $('#inline_btn_fetch_ares').on('click', function() {
        const ico = $('#inline_ico_input').val().trim();
        if (!ico) return showAlert('<?php echo __('enter_ico'); ?>');
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                if (data && data.obchodniJmeno) {
                    $('#inline_ares_name').val(data.obchodniJmeno);
                    $('#inline_last_name').val(data.obchodniJmeno);
                    $('#inline_first_name').val('Firma');
                    
                    if (data.dic) {
                        $('#inline_ares_dic').val(data.dic);
                    }

                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#inline_address').val(addr.trim());
                    }
                } else {
                    showAlert('<?php echo __('ares_data_not_found'); ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                showAlert('<?php echo __('ares_fetch_error'); ?>');
            }
        });
    });

    // Inline New Customer: AJAX submit and bind to New Order select
    $('#saveNewCustomerBtn').on('click', function() {
        const $panel = $('#newCustomerInlineForm');
        const firstName = String($('#inline_first_name').val() || '').trim();
        const lastName = String($('#inline_last_name').val() || '').trim();
        const phone = String($('#inline_phone').val() || '').trim();

        if (!firstName || !lastName || !phone) {
            showAlert('<?php echo __('fill_required_fields'); ?>');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');

        const csrfFromForm = String($panel.closest('form').find('input[name="csrf_token"]').val() || '');
        const csrfFromMeta = String($('meta[name="csrf-token"]').attr('content') || '');
        const formData = {
            first_name: firstName,
            last_name: lastName,
            phone: phone,
            email: $panel.find('input[name="inline_email"]').val() || '',
            address: $('#inline_address').val() || '',
            customer_type: $panel.find('input[name="customer_type"]:checked').val() || 'private',
            ico: $('#inline_ico_input').val() || '',
            company_name: $('#inline_ares_name').val() || '',
            dic: $('#inline_ares_dic').val() || '',
            response_format: 'json',
            csrf_token: csrfFromForm || csrfFromMeta
        };

        $.ajax({
            url: 'api/add_customer.php',
            method: 'POST',
            dataType: 'json',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            data: formData
        }).done(function(res) {
            const id = Number(res && res.id);
            if (res && res.success && Number.isInteger(id) && id > 0) {
                const label = (lastName + ' ' + firstName).trim() + (phone ? ' (' + phone + ')' : '');
                const $select = $('.select2-customer');
                if ($select.length) {
                    const newOption = new Option(label, id, true, true);
                    $select.append(newOption).trigger('change');
                }
                // Reset inline form fields
                $('#inline_first_name, #inline_last_name, #inline_phone, #inline_ares_name, #inline_ares_dic, #inline_ico_input').val('');
                $panel.find('input[name="inline_email"]').val('');
                $('#inline_address').val('');
                $('#inline_company_fields').addClass('d-none');
                $panel.find('#inline_type_private').prop('checked', true);
                // Collapse the panel
                const collapseEl = document.getElementById('inlineNewCustomerPanel');
                if (collapseEl && window.bootstrap) {
                    bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false }).hide();
                }
            } else {
                showAlert((res && res.message) || '<?php echo __('add_client_error'); ?>');
            }
        }).fail(function(xhr) {
            let message = xhr.responseJSON && xhr.responseJSON.message;
            if (!message && xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.message) {
                        message = parsed.message;
                    }
                } catch (e) { /* non-JSON body (empty 500, HTML login, etc.) */ }
            }
            if (!message && xhr.status === 403) {
                message = '<?php echo __('csrf_token_invalid'); ?>';
            } else if (!message && xhr.status === 401) {
                message = '<?php echo __('unauthorized'); ?>';
            }
            showAlert(message || '<?php echo __('network_error_client'); ?>');
        }).always(function() {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
        });
    });

    // Accounting Modal Logic
    $(document).on('click', '.accounting-btn', function(e) {
        e.preventDefault();
        const orderId = $(this).data('id');
        if (!orderId) return;

        showBsModal('invoiceModal');
        $('#invoiceForm').trigger('reset');
        $('#invoiceOrderId').val(orderId);
        $('#dynamic-items-container').html(`
            <div class="row g-2 mb-2 item-row">
                <div class="col-8">
                    <input type="text" name="item_name[]" class="form-control form-control-sm" value="<?php echo __('repair_service'); ?> #${orderId}" required>
                </div>
                <div class="col-3">
                    <input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" required>
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-sm btn-outline-primary add-item-btn"><i class="fas fa-plus"></i></button>
                </div>
            </div>
        `);
        
        $.get('api/get_invoice_data.php', {order_id: orderId}, function(res) {
            if (res.success) {
                // Next number of the shared invoice series (reserved again under lock on save).
                $('#invoiceNumber').val(res.next_invoice_number);
                $('#variableSymbol').val(res.variable_symbol);
                $('#dateIssue').val(res.date_issue);
                $('#dateTax').val(res.date_tax);
                $('#dateDue').val(res.date_due);
                $('#totalAmount').val(res.total_amount);
                $('#invoiceCustomerName').text(res.order.company || (res.order.first_name + ' ' + res.order.last_name));
                
                // Show hints
                $('#orderProblemHint').text(res.order.problem_description);
                $('#orderNotesHint').text(res.order.technician_notes || '---');
                
                // Set initial price to total amount
                $('.item-price').val(res.total_amount);
            } else {
                showAlert(res.message);
                hideBsModal('invoiceModal');
            }
        }).fail(function() {
            showAlert('<?php echo __('error'); ?>');
            hideBsModal('invoiceModal');
        });
    });

    // Dynamic items logic
    $(document).on('click', '.add-item-btn', function() {
        const newRow = `
            <div class="row g-2 mb-2 item-row">
                <div class="col-8">
                    <input type="text" name="item_name[]" class="form-control form-control-sm" placeholder="<?php echo __('invoice_item_placeholder'); ?>" required>
                </div>
                <div class="col-3">
                    <input type="number" name="item_price[]" class="form-control form-control-sm item-price" step="0.01" required>
                </div>
                <div class="col-1">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-item-btn"><i class="fas fa-minus"></i></button>
                </div>
            </div>
        `;
        $('#dynamic-items-container').append(newRow);
    });

    $(document).on('click', '.remove-item-btn', function() {
        $(this).closest('.item-row').remove();
        calculateInvoiceTotal();
    });

    $(document).on('input', '.item-price', function() {
        calculateInvoiceTotal();
    });

    function calculateInvoiceTotal() {
        let total = 0;
        $('.item-price').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#totalAmount').val(total.toFixed(2));
    }

    $('#saveInvoiceBtn').on('click', function() {
        const form = document.getElementById('invoiceForm');
        if (form && typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        let formData = $('#invoiceForm').serialize();
        const csrf = $('meta[name="csrf-token"]').attr('content') || '';
        if (csrf && formData.indexOf('csrf_token=') === -1) {
            formData += (formData ? '&' : '') + 'csrf_token=' + encodeURIComponent(csrf);
        }

        const saveBtn = $(this);
        saveBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        
        $.post('api/create_invoice.php', formData, function(res) {
            if (res.success) {
                window.open('print_invoice.php?id=' + res.id, '_blank');
                location.reload();
            } else {
                showAlert(res.message);
                saveBtn.prop('disabled', false).text('<?php echo __('create_invoice'); ?>');
            }
        }, 'json').fail(function(xhr) {
            const message = (xhr.responseJSON && xhr.responseJSON.message) || '<?php echo __('error'); ?>';
            showAlert(message);
            saveBtn.prop('disabled', false).text('<?php echo __('create_invoice'); ?>');
        });
    });
});
</script>

<script nonce="<?php echo e(crmCspNonce()); ?>">
function openReceptionLangModal(orderId) {
    $('#langOrderId').val(orderId);
    showBsModal('receptionLangModal');
}

// Keep page-action dispatcher (data-crm-action) able to call this handler.
window.openReceptionLangModal = openReceptionLangModal;

$(document).ready(function() {
    $(document).on('click', '.btn-lang-select', function() {
        const lang = $(this).data('lang');
        const orderId = $('#langOrderId').val();
        hideBsModal('receptionLangModal');
        
        // Client reception act only; workshop uses print_workshop.php (work order).
        const url = `print_reception_thermal.php?id=${orderId}&lang=${lang}`;
        openUniversalPreview(url, `<?php echo __('reception_act_thermal'); ?> #${orderId}`);
    });
});

function deleteMedia(id) {
    const mediaNode = $('#media-item-' + id);
    const requestData = {
        id: id,
        csrf_token: $('meta[name="csrf-token"]').attr('content')
    };

    const performDelete = function() {
        $.ajax({
            url: 'api/delete_media.php',
            type: 'POST',
            dataType: 'json',
            data: requestData,
            success: function(res) {
                if (res && res.success) {
                    mediaNode.fadeOut(180, function() {
                        $(this).remove();
                    });
                } else {
                    const message = (res && res.message) ? res.message : '<?php echo __('error'); ?>';
                    if (typeof showAlert === 'function') {
                        showAlert('<?php echo __('error'); ?>: ' + message);
                    } else {
                        alert('Error: ' + message);
                    }
                }
            },
            error: function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : '<?php echo __('error'); ?>';
                if (typeof showAlert === 'function') {
                    showAlert('<?php echo __('error'); ?>: ' + message);
                } else {
                    alert('Error: ' + message);
                }
            }
        });
    };

    if (typeof showConfirm !== 'function') {
        if (confirm('<?php echo __('confirm_delete_file'); ?>')) {
            performDelete();
        }
        return;
    }
    showConfirm('<?php echo __('confirm_delete_file'); ?>', function() {
        performDelete();
    }, undefined, 'danger');
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert((res && res.message) || '<?php echo e(__('error')); ?>');
            }
        }).fail(function() {
            showAlert(window.LANG_NETWORK_ERROR || '<?php echo e(__('error')); ?>');
        });
    }, undefined, 'danger');
}
</script>
