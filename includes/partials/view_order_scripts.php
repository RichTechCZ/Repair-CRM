<?php
/** View-order page scripts (PHP-rendered i18n). Included from view_order.php */
if (!isset($order)) { return; }
?>
<script>
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
        const shippingMethod = form.find('select[name="shipping_method"]').val();
        const finalCost = parseFloat(form.find('input[name="final_cost"]').val() || '0');
        const cancellationReason = form.find('textarea[name="cancellation_reason"]').val() || '';
        
        if (status === 'Issued' && (isNaN(finalCost) || finalCost <= 0 || !shippingMethod)) {
            showShippingRequiredModal();
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

    // Initialize Select2
    $('.select2-customer').select2({
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        allowClear: true,
        width: '100%'
    });

    $('select[name="inventory_id"]').select2({
        dropdownParent: $('#addPartModal'),
        placeholder: "<?php echo __('search_part_placeholder'); ?>",
        width: '100%'
    });

    $('input[name="mode"]').on('change', function() {
        const isManual = $(this).val() === 'manual';
        $('#manualPartFields').toggleClass('d-none', !isManual);
        $('select[name="inventory_id"]').prop('required', !isManual).closest('.mb-3').toggleClass('d-none', isManual);
        $('input[name="part_name"], input[name="source"], input[name="price"]').prop('required', isManual);
    });

    $('#shippingForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_shipping.php', $(this).serialize(), function(res) {
            if(res.success) {
                showAlert('<?php echo __('shipping_updated'); ?>');
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('select[name="shipping_method"]').on('change', function() {
        const method = $(this).val();
        if (['Zasilkovna', 'Ceska Posta', 'PPL', 'DPD', 'GLS'].includes(method)) {
            $('#shippingDetails').removeClass('d-none');
        } else {
            $('#shippingDetails').addClass('d-none');
        }
    });

    $('#addPartForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/add_order_item.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('#editPartForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_item.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
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
        
        $.post('api/update_order_full.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                btn.prop('disabled', false).html(oldHtml);
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html(oldHtml);
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __('network_error'); ?>';
            showAlert(msg);
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
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ...');

        $.post('api/update_order_status.php', form.serialize(), function(raw) {
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

            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            if (res && res.message) {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            } else if (typeof raw === 'string' && raw.trim() !== '') {
                showAlert('<?php echo __('error'); ?>: ' + raw.trim());
            } else {
                showAlert('<?php echo __('error'); ?>');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            const text = (xhr && xhr.responseText) ? xhr.responseText : '';
            showAlert('<?php echo __('error'); ?>' + (text ? ': ' + text : ''));
        });
    });
}

// Go to shipping section
function goToShipping() {
    $('#shippingRequiredModal').modal('hide');
    const target = $('#statusShippingMethodWrap:visible').length ? $('#statusShippingMethodWrap') : $('#shippingForm');
    if (!target.length) return;
    $('html, body').animate({
        scrollTop: target.offset().top - 100
    }, 500);
    
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
