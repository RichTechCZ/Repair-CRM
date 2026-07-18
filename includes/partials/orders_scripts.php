<?php
/** Orders page scripts (PHP-rendered i18n). Included from orders.php */
?>
<script>
// Phone QR Popover using Google Charts API (no library needed)
document.addEventListener('DOMContentLoaded', function() {
    const popover = document.getElementById('phoneQrPopover');
    const qrContainer = document.getElementById('qrContainer');
    const phoneLabel = document.getElementById('qrPhoneLabel');
    const callBtn = document.getElementById('qrCallBtn');
    let hideTimeout;

    document.querySelectorAll('.phone-qr-trigger').forEach(el => {
        el.addEventListener('mouseenter', function(e) {
            clearTimeout(hideTimeout);
            const phone = this.dataset.phone;
            if (!phone) return;

            // Generate QR code using QR Server API
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent('tel:' + phone);
            qrContainer.innerHTML = '<img src="' + qrUrl + '" alt="QR" style="width:120px;height:120px;">';

            phoneLabel.textContent = phone;
            callBtn.href = 'tel:' + phone;

            // Position popover
            const rect = e.target.getBoundingClientRect();
            popover.style.left = (rect.right + 10) + 'px';
            popover.style.top = rect.top + 'px';
            popover.style.display = 'block';
        });

        el.addEventListener('mouseleave', function() {
            hideTimeout = setTimeout(() => {
                popover.style.display = 'none';
            }, 300);
        });
    });

    popover.addEventListener('mouseenter', function() {
        clearTimeout(hideTimeout);
    });

    popover.addEventListener('mouseleave', function() {
        popover.style.display = 'none';
    });
});
</script>

<script>
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

        var statusColors = {
            'Accepted': 'primary',
            'Diagnostics': 'info',
            'Approval': 'warning',
            'In Repair': 'warning',
            'Ready': 'success',
            'Issued': 'secondary',
            'Issued Without Repair': 'dark',
            'Repair Cancelled': 'danger',
            'New': 'primary',
            'Pending Approval': 'warning',
            'In Progress': 'warning',
            'Waiting for Parts': 'warning',
            'Completed': 'success',
            'Collected': 'secondary',
            'Cancelled': 'danger'
        };

        var html = '<div class="table-responsive">'
            + '<table class="table table-hover align-middle mb-0">'
            + '<thead><tr>'
            + '<th>#</th><th>Дата</th><th>Клиент</th>'
            + '<th>Устройство</th><th>Серийный номер</th><th>Статус</th>'
            + '</tr></thead><tbody>';

        orders.forEach(function (o) {
            var color = statusColors[o.status] || 'secondary';

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
                + '<td><span class="badge bg-' + color + ' text-white">' + escHtml(o.status) + '</span></td>'
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

<script>
// FIX #3: escHTML prevents XSS when injecting data into innerHTML/template literals
function escHTML(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}

$(document).ready(function() {
    $('.order-modal-trigger').on('click', function() {
        const id = $(this).data('id');
        $('#quickOrderTitle').text('<?php echo __('order_header'); ?> #' + id);
        $('#quickOrderModal').modal('show');
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
                            <div class="col-3 col-md-2" id="media-item-${file.id}">
                                <div class="card h-100 shadow-sm border position-relative">
                                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-1 line-height-1" style="z-index: 10; font-size: 0.6rem;" onclick="deleteMedia(${file.id})">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <div class="ratio ratio-1x1 bg-dark bg-opacity-25 border-secondary">
                                        ${isVideo ? 
                                            `<div class="d-flex align-items-center justify-content-center bg-dark"><i class="fas fa-video text-white"></i></div>` : 
                                            `<img src="${file.file_path}" class="object-fit-cover" alt="Photo">`
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
                footerLinks.eq(0).attr('onclick', `openUniversalPreview('print_order.php?id=${o.id}', '<?php echo __('order_header'); ?> #${o.id}')`);
                footerLinks.eq(1).attr('onclick', `openReceptionLangModal(${o.id})`);
                footerLinks.eq(2).attr('onclick', `openUniversalPreview('print_workshop.php?id=${o.id}', '<?php echo __('work_order'); ?> #${o.id}')`);
                footerLinks.eq(3).attr('onclick', `openUniversalPreview('print_thermal.php?id=${o.id}', '<?php echo __('thermal_receipt'); ?> #${o.id}')`);

                $('#saveQuickOrderBtn').prop('disabled', false);
                
                // Set delete order button action
                $('#deleteQuickOrderBtn').off('click').on('click', function() {
                    deleteOrder(o.id);
                });
            } else {
                const message = (res && res.message) ? res.message : '<?php echo __('error'); ?>';
                $('#quickOrderBody').html('<div class="alert alert-danger">' + message + '</div>');
            }
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
                    // Hide modal first
                    const modalEl = document.getElementById('quickOrderModal');
                    const modalInstance = bootstrap.Modal.getInstance(modalEl);
                    if (modalInstance) modalInstance.hide();
                    
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
            return item.text || item.name || '';
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
                if (o.device_type) $('select[name="device_type"]').val(o.device_type);
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
                if (o.pin_code) $('input[name="pin_code"]').val(o.pin_code);
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
        toastEl.classList.remove('text-bg-success', 'text-bg-danger');
        toastEl.classList.add(type === 'success' ? 'text-bg-success' : 'text-bg-danger');
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

    $('.quick-status-btn').on('click', function() {
        const id = $(this).data('id');
        const status = $(this).data('status');
        if (!id || !status) return;
        const btn = $(this);
        btn.prop('disabled', true);

        if (status === 'Repair Cancelled') {
            return showConfirm('<?php echo __('delete_confirm'); ?>', function() {
                const reason = window.prompt('<?php echo __('cancellation_reason'); ?>');
                if (reason === null || !reason.trim()) {
                    btn.prop('disabled', false);
                    return;
                }
                performQuickStatusUpdate(id, status, btn, reason.trim());
            });
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
        const firstName = $('#inline_first_name').val().trim();
        const lastName = $('#inline_last_name').val().trim();
        const phone = $('#inline_phone').val().trim();
        
        if (!firstName || !lastName || !phone) {
            showAlert('<?php echo __('fill_required_fields'); ?>');
            return;
        }
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');

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
            csrf_token: $('input[name="csrf_token"]').first().val()
        };

        $.post('api/add_customer.php', formData, function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            if (res.success) {
                const id = res.id;
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
                const bsCollapse = bootstrap.Collapse.getInstance(collapseEl);
                if (bsCollapse) bsCollapse.hide();
            } else {
                showAlert(res.message || '<?php echo __('add_client_error'); ?>');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            showAlert('<?php echo __('network_error_client'); ?>');
        });
    });

    // Accounting Modal Logic
    $('.accounting-btn').on('click', function() {
        const orderId = $(this).data('id');
        $('#invoiceModal').modal('show');
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
                // Number and VS are now Order ID
                $('#invoiceNumber').val(orderId);
                $('#variableSymbol').val(orderId);
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
                $('#invoiceModal').modal('hide');
            }
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
        const formData = $('#invoiceForm').serialize();
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
        
        $.post('api/create_invoice.php', formData, function(res) {
            if (res.success) {
                window.open('print_invoice.php?id=' + res.id, '_blank');
                location.reload();
            } else {
                showAlert(res.message);
                $('#saveInvoiceBtn').prop('disabled', false).text('<?php echo __('create_invoice'); ?>');
            }
        });
    });
});
</script>

<script>
function openReceptionLangModal(orderId) {
    $('#langOrderId').val(orderId);
    $('#receptionLangModal').modal('show');
}

$(document).ready(function() {
    $('.btn-lang-select').on('click', function() {
        const lang = $(this).data('lang');
        const orderId = $('#langOrderId').val();
        $('#receptionLangModal').modal('hide');
        
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
    });
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>

