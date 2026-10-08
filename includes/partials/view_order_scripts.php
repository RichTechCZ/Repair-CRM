<?php
/** View-order page scripts (PHP-rendered i18n). Included from view_order.php */
if (!isset($order)) { return; }
?>
<script<?php
    $crmScriptNonce = function_exists('crmCspNonce') ? (string)crmCspNonce() : '';
    echo $crmScriptNonce !== '' ? ' nonce="' . e($crmScriptNonce) . '"' : '';
?>>
function normalizeShippingMethod(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .replace(/[áíéýůú]/g, function(ch) {
            return ({á:'a',í:'i',é:'e',ý:'y',ů:'u',ú:'u'})[ch] || ch;
        });
}

function isSelfPickupShipping(value) {
    const normalized = normalizeShippingMethod(value);
    return [
        'self pickup',
        'self_pickup',
        'selfpickup',
        'pickup',
        'клиент забрал сам',
        'забрал сам',
        'самовывоз',
        'osobni odber',
        'osobniodeber'
    ].indexOf(normalized) !== -1;
}

function isReclamationOrderType(value) {
    const fromForm = $('#editOrderFullForm select[name="order_type"]').val();
    const normalized = normalizeShippingMethod(value || fromForm);
    return [
        'warranty',
        'reclamation',
        'reklamace',
        'рекламация',
        'гарантия',
        'гарантийный'
    ].indexOf(normalized) !== -1;
}

function effectiveShippingMethod(form) {
    const $form = form && form.jquery ? form : $(form);
    const fromStatus = $form.find('#statusShippingMethodWrap select[name="shipping_method"]').val();
    const fromShippingCard = $('#shippingForm select[name="shipping_method"]').val();
    const fromSaved = $form.attr('data-current-shipping');
    return fromStatus || fromShippingCard || fromSaved || '';
}

$(document).ready(function() {
    // Express Invoice Form
    $('#expressInvoiceForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __("saving"); ?>...');
        
        $.post('api/create_express_invoice.php', $(this).serialize(), function(res) {
            if(res.success) {
                // Just reload to show updated invoice status and amounts
                location.reload();
            } else {
                btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
                showAlert('<?php echo __("error"); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __("error"); ?>';
            showAlert(msg);
        });
    });

    $('#statusForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const status = form.find('select[name="status"]').val();
        const shippingMethod = effectiveShippingMethod(form);
        const finalCost = parseFloat(form.find('input[name="final_cost"]').val() || '0');
        const cancellationReason = form.find('textarea[name="cancellation_reason"]').val() || '';
        const isSelfPickup = isSelfPickupShipping(shippingMethod);
        const isReclamation = isReclamationOrderType(form.attr('data-order-type'));

        if (status === 'Issued' && !shippingMethod) {
            showShippingRequiredModal();
            return false;
        }

        if (status === 'Issued' && !isSelfPickup && !isReclamation && (isNaN(finalCost) || finalCost <= 0)) {
            showAlert('<?php echo __('required_final_cost_for_issue'); ?>');
            return false;
        }

        if ((status === 'Issued Without Repair' || status === 'Repair Cancelled') && !cancellationReason.trim()) {
            showAlert('<?php echo __('cancellation_reason'); ?>');
            return false;
        }

        showStatusConfirmModal(form);
    });

    function syncStatusConditionalFields() {
        const status = $('#statusForm select[name="status"]').val();
        $('#statusCancellationReasonWrap').toggleClass('d-none', !['Issued Without Repair', 'Repair Cancelled'].includes(status));
        $('#statusShippingMethodWrap').toggleClass('d-none', status !== 'Issued');
    }

    $('#statusForm select[name="status"]').on('change', syncStatusConditionalFields);
    syncStatusConditionalFields();
    
    // ... existing scripts ...
    $('#editOrderDatesForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_dates.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('.edit-attachment-date').on('click', function() {
        $('#edit_attachment_id').val($(this).data('id'));
        $('#edit_attachment_date').val($(this).data('date'));
        $('#editAttachmentDateModal').modal('show');
    });

    $('#editAttachmentDateForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_attachment_date.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    // Initialize Fancybox 5
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind("[data-fancybox]", {
            dragToClose: false,
            Image: {
                zoom: true,
            },
        });
    }

    // Select2 is optional: a missing/broken plugin must not kill mode toggle or form submit.
    if (typeof $.fn.select2 === 'function') {
        $('.select2-customer').select2({
            placeholder: "<?php echo __('search_client_placeholder'); ?>",
            allowClear: true,
            width: '100%'
        });
    }

    // ── Add part modal: inventory vs manual ─────────────────────────────
    const $addPartForm = $('#addPartForm');
    const $addPartModal = $('#addPartModal');
    const $inventoryPartFields = $('#inventoryPartFields');
    const $manualPartFields = $('#manualPartFields');
    const $inventorySelect = $('#addPartInventoryId');
    const $manualPartInputs = $manualPartFields.find('input[name="part_name"], input[name="source"], input[name="price"]');

    function addPartIsManual() {
        return $addPartForm.find('input[name="mode"]:checked').val() === 'manual';
    }

    function syncAddPartMode() {
        const isManual = addPartIsManual();

        $inventoryPartFields.toggleClass('d-none', isManual);
        $manualPartFields.toggleClass('d-none', !isManual);

        // Disabled controls are omitted from serialize() and skip HTML5 validation.
        $inventorySelect.prop('disabled', isManual).prop('required', false);
        $manualPartInputs.prop('disabled', !isManual).prop('required', isManual);

        if (typeof $.fn.select2 === 'function' && $inventorySelect.data('select2')) {
            $inventorySelect.trigger('change.select2');
            if (isManual) {
                try { $inventorySelect.select2('close'); } catch (e) { /* ignore */ }
            }
        }
    }

    $addPartForm.find('input[name="mode"]').on('change', syncAddPartMode);

    if (typeof $.fn.select2 === 'function' && $inventorySelect.length) {
        $inventorySelect.select2({
            dropdownParent: $addPartModal,
            placeholder: "<?php echo __('search_part_placeholder'); ?>",
            allowClear: true,
            width: '100%'
        });
    }

    // Reset UI each time the modal opens so a previous manual session does not stick.
    $addPartModal.on('show.bs.modal', function() {
        $addPartForm[0].reset();
        $('#partModeInventory').prop('checked', true);
        $inventorySelect.val(null).trigger('change');
        syncAddPartMode();
    });

    syncAddPartMode();

    $('#shippingForm').on('submit', function(e) {
        e.preventDefault();
        const shippingMethod = $(this).find('select[name="shipping_method"]').val();
        const isSelfPickup = String(shippingMethod || '').trim().toLowerCase() === 'self pickup';
        $.post('api/update_shipping.php', $(this).serialize(), function(res) {
            if(res.success) {
                if (!isSelfPickup) {
                    showAlert('<?php echo __('shipping_updated'); ?>');
                }
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('select[name="shipping_method"]').on('change', function() {
        const method = $(this).val();
        $('select[name="shipping_method"]').not(this).val(method);
        if (['Zasilkovna', 'Ceska Posta', 'PPL', 'DPD', 'GLS'].includes(method)) {
            $('#shippingDetails').removeClass('d-none');
        } else {
            $('#shippingDetails').addClass('d-none');
        }
    });

    $addPartForm.on('submit', function(e) {
        e.preventDefault();
        const form = this;
        const isManual = addPartIsManual();
        const qty = parseInt($addPartForm.find('input[name="quantity"]').val(), 10);

        if (!Number.isFinite(qty) || qty < 1) {
            showAlert('<?php echo __('missing_data'); ?>');
            $addPartForm.find('input[name="quantity"]').trigger('focus');
            return;
        }

        if (!isManual) {
            if (!$inventorySelect.val()) {
                showAlert('<?php echo __('select_part_from_warehouse'); ?>');
                if (typeof $.fn.select2 === 'function' && $inventorySelect.data('select2')) {
                    $inventorySelect.select2('open');
                } else {
                    $inventorySelect.trigger('focus');
                }
                return;
            }
        } else {
            const partName = String($manualPartFields.find('input[name="part_name"]').val() || '').trim();
            const source = String($manualPartFields.find('input[name="source"]').val() || '').trim();
            const priceRaw = $manualPartFields.find('input[name="price"]').val();
            const price = parseFloat(priceRaw);
            if (!partName || !source || priceRaw === '' || !Number.isFinite(price) || price < 0) {
                showAlert('<?php echo __('missing_data'); ?>');
                return;
            }
        }

        const $btn = $addPartForm.find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true);

        $.ajax({
            url: 'api/add_order_item.php',
            type: 'POST',
            data: $(form).serialize(),
            dataType: 'json',
            success: function(res) {
                if (res && res.success) {
                    location.reload();
                    return;
                }
                $btn.prop('disabled', false).html(oldHtml);
                showAlert('<?php echo __('error'); ?>: ' + ((res && res.message) ? res.message : '<?php echo __('error'); ?>'));
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(oldHtml);
                const message = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : '<?php echo __('network_error'); ?>';
                showAlert('<?php echo __('error'); ?>: ' + message);
            }
        });
    });

    $('#editPartForm').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $form.find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true);
        $.ajax({
            url: 'api/update_order_item.php',
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(res) {
                if (res && res.success) {
                    location.reload();
                    return;
                }
                $btn.prop('disabled', false).html(oldHtml);
                showAlert('<?php echo __('error'); ?>: ' + ((res && res.message) ? res.message : '<?php echo __('error'); ?>'));
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(oldHtml);
                const message = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : '<?php echo __('network_error'); ?>';
                showAlert('<?php echo __('error'); ?>: ' + message);
            }
        });
    });

    $('#uploadMediaForm').on('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        $('#uploadProgress').removeClass('d-none');
        
        $.ajax({
            url: 'api/upload_media.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            success: function(res) {
                $('#uploadProgress').addClass('d-none');
                if (res && res.success) {
                    showAlert('<?php echo __('files_uploaded'); ?>' + res.count);
                    location.reload();
                } else {
                    const message = (res && res.message) ? res.message : '<?php echo __('upload_error'); ?>';
                    showAlert('<?php echo __('error'); ?>: ' + message);
                }
            },
            error: function(xhr) {
                $('#uploadProgress').addClass('d-none');
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : '<?php echo __('upload_error'); ?>';
                showAlert(message);
            }
        });
    });

    // Full Edit Form AJAX
    $('#editOrderFullForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const oldHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');

        const parseApiPayload = function(text) {
            if (!text) {
                return null;
            }
            try {
                return JSON.parse(text);
            } catch (err) {
                // Tolerate accidental BOM / leading whitespace from older hosts.
                const cleaned = String(text).replace(/^\uFEFF/, '').trim();
                const start = cleaned.indexOf('{');
                const end = cleaned.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    try {
                        return JSON.parse(cleaned.slice(start, end + 1));
                    } catch (err2) {
                        return null;
                    }
                }
                return null;
            }
        };

        $.ajax({
            url: 'api/update_order_full.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'text',
            success: function(text, _status, xhr) {
                const res = parseApiPayload(text);
                if (res && res.success) {
                    location.reload();
                    return;
                }
                btn.prop('disabled', false).html(oldHtml);
                if (res && res.message) {
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                    return;
                }
                const snippet = (text || '').toString().replace(/\s+/g, ' ').slice(0, 180);
                showAlert('<?php echo __('error'); ?>: <?php echo __('network_error'); ?>'
                    + (xhr && xhr.status ? ' [' + xhr.status + ']' : '')
                    + (snippet ? ' — ' + snippet : ''));
            },
            error: function(xhr) {
                btn.prop('disabled', false).html(oldHtml);
                const text = xhr && xhr.responseText ? xhr.responseText : '';
                const res = parseApiPayload(text);
                if (res && res.message) {
                    showAlert(res.message);
                    return;
                }
                const snippet = text.toString().replace(/\s+/g, ' ').slice(0, 180);
                showAlert('<?php echo __('network_error'); ?>'
                    + (xhr && xhr.status ? ' [' + xhr.status + ']' : '')
                    + (snippet ? ' — ' + snippet : ''));
            }
        });
    });

    // Initialize Select2 in modal
    $('.select2-modal-customer').select2({
        dropdownParent: $('#editOrderFullModal'),
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        minimumInputLength: 0,
        ajax: {
            url: 'api/search_customers.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term || '', page: params.page || 1 };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.results, pagination: { more: !!(data.pagination && data.pagination.more) } };
            }
        },
        width: '100%'
    });

    $('.select2-tags-modal').select2({
        dropdownParent: $('#editOrderFullModal'),
        tags: true,
        width: '100%'
    });
});

function deletePart(id) {
    showConfirm('<?php echo __('confirm_delete_part'); ?>', function() {
        $.post('api/delete_order_item.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

function openEditPartModal(item) {
    $('#edit_item_id').val(item.id);
    $('#edit_item_name').val(item.part_name);
    $('#edit_item_quantity').val(item.quantity);
    $('#edit_item_price').val(item.price);
    
    var editModal = new bootstrap.Modal(document.getElementById('editPartModal'));
    editModal.show();
}

function testTechTG(id) {
    if (!id) return;
    $.post('api/test_tech_tg.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
        if (res.success) {
            showAlert('<?php echo __('test_msg_sent'); ?>');
        } else {
            showAlert('<?php echo __('error'); ?>: ' + res.message);
        }
    });
}

function deleteMedia(id) {
    const mediaNode = $('#media-item-' + id);
    const requestData = {
        id: id,
        csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
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

// Show animated modal when shipping method is required for Issued status
function showShippingRequiredModal() {
    const modal = $('#shippingRequiredModal');
    modal.modal('show');
    
    // Add shake animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-shake');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-shake');
        }, 600);
    }, 100);
}

// Show status confirmation modal with animation
function showStatusConfirmModal(form) {
    const modal = $('#statusConfirmModal');
    const status = form.find('select[name="status"]').val();
    const statusLabels = {
        'Accepted': '<?php echo getStatusLabel("Accepted"); ?>',
        'Diagnostics': '<?php echo getStatusLabel("Diagnostics"); ?>',
        'Approval': '<?php echo getStatusLabel("Approval"); ?>',
        'In Repair': '<?php echo getStatusLabel("In Repair"); ?>',
        'Ready': '<?php echo getStatusLabel("Ready"); ?>',
        'Issued': '<?php echo getStatusLabel("Issued"); ?>',
        'Issued Without Repair': '<?php echo getStatusLabel("Issued Without Repair"); ?>',
        'Repair Cancelled': '<?php echo getStatusLabel("Repair Cancelled"); ?>'
    };
    
    $('#confirmStatusText').text(statusLabels[status] || status);
    modal.modal('show');
    
    // Add pulse animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-pulse');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-pulse');
        }, 500);
    }, 100);
    
    // Handle confirm button
    $('#confirmStatusBtn').off('click').on('click', function() {
        const btn = $(this);
        const confirmLabel = '<?php echo __("confirm"); ?>';
        const restoreBtn = function() {
            btn.prop('disabled', false).html(confirmLabel);
        };
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ...');

        try {
            let payload = form.serialize();
            const shippingMethod = effectiveShippingMethod(form);
            if (shippingMethod) {
                if (/(?:^|&)shipping_method=/.test(payload)) {
                    payload = payload.replace(/(^|&)shipping_method=[^&]*/, '$1shipping_method=' + encodeURIComponent(shippingMethod));
                } else {
                    payload += '&shipping_method=' + encodeURIComponent(shippingMethod);
                }
            }

            $.post('api/update_order_status.php', payload, function(raw) {
                let res = null;
                try {
                    res = (typeof raw === 'string') ? JSON.parse(raw) : raw;
                } catch (e) {
                    res = null;
                }

                if (res && res.success) {
                    modal.modal('hide');
                    location.reload();
                    return;
                }

                restoreBtn();
                if (res && res.message) {
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                } else if (typeof raw === 'string' && raw.trim() !== '') {
                    showAlert('<?php echo __('error'); ?>: ' + raw.trim());
                } else {
                    showAlert('<?php echo __('error'); ?>');
                }
            }).fail(function(xhr) {
                restoreBtn();
                const text = (xhr && xhr.responseText) ? xhr.responseText : '';
                showAlert('<?php echo __('error'); ?>' + (text ? ': ' + text : ''));
            });
        } catch (err) {
            restoreBtn();
            showAlert('<?php echo __('error'); ?>');
        }
    });
}

// Go to shipping section
function goToShipping() {
    $('#shippingRequiredModal').modal('hide');
    const target = $('#statusShippingMethodWrap:visible').length ? $('#statusShippingMethodWrap') : $('#shippingForm');
    if (!target.length) return;
    const scrollTarget = target.offset().top - 100;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        $('html, body').scrollTop(scrollTarget);
    } else {
        $('html, body').animate({
            scrollTop: scrollTarget
        }, 250);
    }
    
    target.find('select[name="shipping_method"]').addClass('border-danger border-2');
    setTimeout(function() {
        target.find('select[name="shipping_method"]').focus();
    }, 600);
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                window.location.href = 'orders.php';
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>
