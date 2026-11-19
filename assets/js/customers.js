/* Customers directory & slide-over customer card controller. */
(function () {
    'use strict';

    const cfg = window.CUSTOMERS_CONFIG || {};
    const i18n = cfg.i18n || {};

    let activeCustomerId = null;
    let activeCardData = null;
    let requestCounter = 0;
    let activeFilter = 'all';
    let activeSearchQuery = '';
    let reopenCustomerAfterPreview = null;
    let receptionOrderId = null;
    let receptionSelected = false;

    function t(key, fallback) {
        return Object.prototype.hasOwnProperty.call(i18n, key) ? i18n[key] : (fallback || key);
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function iconEl(className) {
        const icon = document.createElement('i');
        icon.className = className;
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function formatMoney(amount, currency) {
        const num = Number(amount);
        if (!Number.isFinite(num)) return '—';
        const parts = num.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        return parts.join('.') + ' ' + (currency || cfg.currency || 'Kč');
    }

    function formatDate(dateStr, withTime) {
        if (!dateStr) return '—';
        const raw = String(dateStr).trim();
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/);
        if (match) {
            const base = match[3] + '.' + match[2] + '.' + match[1];
            return withTime && match[4] && match[5] ? (base + ' ' + match[4] + ':' + match[5]) : base;
        }
        const dt = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(dt.getTime())) return raw;
        const dd = String(dt.getDate()).padStart(2, '0');
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const yyyy = dt.getFullYear();
        if (!withTime) return dd + '.' + mm + '.' + yyyy;
        const hh = String(dt.getHours()).padStart(2, '0');
        const min = String(dt.getMinutes()).padStart(2, '0');
        return dd + '.' + mm + '.' + yyyy + ' ' + hh + ':' + min;
    }

    function computeInitials(name, isCompany) {
        const cleaned = String(name || '').trim();
        if (!cleaned) return isCompany ? 'CO' : 'CL';
        const words = cleaned.split(/\s+/).filter(Boolean);
        if (words.length >= 2) {
            return (words[0].charAt(0) + words[1].charAt(0)).toUpperCase();
        }
        return cleaned.slice(0, 2).toUpperCase();
    }

    function buildReturnUrl(customerId) {
        return 'customers.php?customer_id=' + encodeURIComponent(String(customerId));
    }

    function showCustomersToast(message, tone) {
        const toastEl = document.getElementById('customersToast');
        const toastBody = document.getElementById('customersToastBody');
        if (!toastEl || !toastBody || typeof bootstrap === 'undefined') {
            if (typeof window.showAlert === 'function') {
                window.showAlert(message);
            }
            return;
        }
        toastEl.classList.remove('crm-toast--success', 'crm-toast--danger');
        toastEl.classList.add(tone === 'danger' ? 'crm-toast--danger' : 'crm-toast--success');
        toastEl.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
        toastBody.textContent = message;
        bootstrap.Toast.getOrCreateInstance(toastEl).show();
    }

    function copyTextToClipboard(text, btn) {
        const value = String(text || '').trim();
        if (!value) return;

        const markCopied = function () {
            if (!btn) return;
            const prev = btn.innerHTML;
            btn.replaceChildren(iconEl('fas fa-check text-success'));
            btn.setAttribute('aria-label', t('copied', 'Copied'));
            showCustomersToast(t('copied', 'Copied') + ': ' + value, 'success');
            window.setTimeout(function () {
                btn.innerHTML = prev;
                btn.setAttribute('aria-label', t('copy_text', 'Copy'));
            }, 1400);
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value).then(markCopied).catch(function () {
                fallbackCopy(value, markCopied);
            });
        } else {
            fallbackCopy(value, markCopied);
        }
    }

    function fallbackCopy(value, onDone) {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            onDone();
        } catch (err) {
            showCustomersToast(t('error', 'Error'), 'danger');
        }
        document.body.removeChild(ta);
    }

    function makeCopyButton(value) {
        const btn = el('button', 'customer-copy-btn');
        btn.type = 'button';
        btn.setAttribute('aria-label', t('copy_text', 'Copy') + ': ' + value);
        btn.appendChild(iconEl('far fa-copy'));
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            copyTextToClipboard(value, btn);
        });
        return btn;
    }

    function updateUrlCustomerId(customerId) {
        if (!window.history || typeof window.history.replaceState !== 'function') return;
        try {
            const url = new URL(window.location.href);
            if (customerId) {
                url.searchParams.set('customer_id', String(customerId));
            } else {
                url.searchParams.delete('customer_id');
            }
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (e) {
            // ignore
        }
    }

    function getCardModal() {
        const modalEl = document.getElementById('customerCardModal');
        if (!modalEl || typeof bootstrap === 'undefined') return null;
        return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function switchCardTab(tabName) {
        const tabMap = {
            repairs: 'ccTabRepairs',
            devices: 'ccTabDevices',
            invoices: 'ccTabInvoices',
            edit: 'ccTabEdit'
        };
        const btnId = tabMap[tabName] || 'ccTabRepairs';
        const btn = document.getElementById(btnId);
        if (btn && typeof bootstrap !== 'undefined') {
            bootstrap.Tab.getOrCreateInstance(btn).show();
        }
    }

    function restoreLoadedCard() {
        if (reopenCustomerAfterPreview && activeCardData && activeCardData.customer && Number(activeCardData.customer.id) === Number(reopenCustomerAfterPreview)) {
            reopenCustomerAfterPreview = null;
            const modal = getCardModal();
            if (modal) modal.show();
            return;
        }
        if (reopenCustomerAfterPreview) {
            const cid = reopenCustomerAfterPreview;
            reopenCustomerAfterPreview = null;
            openCustomerCard(cid, 'repairs');
        }
    }

    function openPreviewFromCard(url, title) {
        if (typeof window.openUniversalPreview !== 'function') return;
        const cardModalEl = document.getElementById('customerCardModal');
        const previewEl = document.getElementById('universalPreviewModal');
        if (cardModalEl && cardModalEl.classList.contains('show') && previewEl && activeCustomerId) {
            reopenCustomerAfterPreview = activeCustomerId;
            if (typeof window.crmHidePhoneQrPopover === 'function') {
                window.crmHidePhoneQrPopover();
            }
            cardModalEl.addEventListener('hidden.bs.modal', function onCardHidden() {
                window.openUniversalPreview(url, title);
            }, { once: true });
            getCardModal().hide();
            return;
        }
        window.openUniversalPreview(url, title);
    }

    function openReceptionModalFromCard(orderId) {
        const modalEl = document.getElementById('receptionLangModal');
        if (!modalEl || typeof bootstrap === 'undefined') return;
        receptionOrderId = orderId;
        receptionSelected = false;
        const hiddenInput = document.getElementById('langOrderId');
        if (hiddenInput) hiddenInput.value = String(orderId);

        const cardModalEl = document.getElementById('customerCardModal');
        if (cardModalEl && cardModalEl.classList.contains('show') && activeCustomerId) {
            reopenCustomerAfterPreview = activeCustomerId;
            cardModalEl.addEventListener('hidden.bs.modal', function onCardHidden() {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }, { once: true });
            getCardModal().hide();
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function openMediaGalleryFromCard(mediaRow, startIndex) {
        if (typeof Fancybox === 'undefined') return false;
        const thumbs = Array.from(mediaRow.querySelectorAll('.customer-media-thumb'));
        if (!thumbs.length) return false;
        const items = thumbs.map(function (node) {
            const rawType = node.getAttribute('data-type') || 'image';
            return {
                src: node.href,
                type: rawType === 'video' ? 'html5video' : rawType,
                caption: node.getAttribute('data-caption') || ''
            };
        });
        const cardModalEl = document.getElementById('customerCardModal');
        const showBox = function () {
            Fancybox.show(items, {
                startIndex: startIndex,
                dragToClose: false,
                Image: { zoom: true },
                on: {
                    destroy: function () {
                        restoreLoadedCard();
                    }
                }
            });
        };
        if (cardModalEl && cardModalEl.classList.contains('show') && activeCustomerId) {
            reopenCustomerAfterPreview = activeCustomerId;
            cardModalEl.addEventListener('hidden.bs.modal', function onCardHidden() {
                showBox();
            }, { once: true });
            getCardModal().hide();
        } else {
            showBox();
        }
        return true;
    }

    function bindAresLookup(buttonEl, icoInputEl, targets) {
        if (!buttonEl || !icoInputEl) return;
        const defaultHtml = buttonEl.innerHTML;
        buttonEl.addEventListener('click', function () {
            const ico = String(icoInputEl.value || '').trim();
            if (!ico) {
                if (typeof window.showAlert === 'function') {
                    window.showAlert(t('enter_ico_prompt', 'Enter IČO'));
                }
                return;
            }
            buttonEl.disabled = true;
            buttonEl.replaceChildren(el('span', 'spinner-border spinner-border-sm'));

            fetch('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' + encodeURIComponent(ico))
                .then(function (resp) {
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    return resp.json();
                })
                .then(function (data) {
                    if (data && data.obchodniJmeno) {
                        if (targets.company) targets.company.value = data.obchodniJmeno;
                        if (targets.fillNamesFromCompany) {
                            if (targets.firstName) targets.firstName.value = 'Firma';
                            if (targets.lastName) targets.lastName.value = data.obchodniJmeno;
                        }
                        if (data.dic && targets.dic) {
                            targets.dic.value = data.dic;
                        }
                        if (data.sidlo && targets.address) {
                            const s = data.sidlo;
                            const street = [s.nazevUlice || '', (s.cisloDomovni || '') + (s.cisloOrientacni ? '/' + s.cisloOrientacni : '')]
                                .filter(Boolean)
                                .join(' ');
                            const city = [s.psc || '', s.nazevObce || ''].filter(Boolean).join(' ');
                            targets.address.value = [street, city].filter(Boolean).join(', ').trim();
                        }
                    } else if (typeof window.showAlert === 'function') {
                        window.showAlert(t('ares_data_not_found', 'Not found'));
                    }
                })
                .catch(function () {
                    if (typeof window.showAlert === 'function') {
                        window.showAlert(t('ares_fetch_error', 'ARES error'));
                    }
                })
                .finally(function () {
                    buttonEl.disabled = false;
                    buttonEl.innerHTML = defaultHtml;
                });
        });
    }

    function buildStatusPill(variant, label) {
        const safeVariant = /^[a-z-]+$/.test(String(variant || '')) ? variant : 'closed';
        return el('span', 'status-pill status-pill--' + safeVariant, label || '');
    }

    function buildCustomerPhone(phone, classes) {
        const digits = phone.replace(/[^0-9]/g, '');
        if (!digits) return el('span', classes, phone);

        const trigger = el('span', ('phone-qr-trigger ' + classes).trim());
        trigger.dataset.phone = (phone.trim().startsWith('+') ? '+' : '') + digits;
        trigger.setAttribute('role', 'button');
        trigger.tabIndex = 0;
        trigger.setAttribute('aria-label', phone + ' — ' + t('phone_show_qr', 'QR'));
        trigger.appendChild(iconEl('fas fa-phone text-success'));
        trigger.appendChild(el('span', '', phone));
        trigger.appendChild(iconEl('fas fa-qrcode phone-qr-trigger__hint'));
        return trigger;
    }

    function renderCardProfile(customer) {
        const profileWrap = document.getElementById('ccProfilePanel');
        if (!profileWrap) return;
        profileWrap.replaceChildren();

        const title = el('div', 'customer-card__profile-title', t('customer_contacts', 'Contacts'));
        const list = el('div', 'customer-profile-list');

        // Phone row
        const phoneRow = el('div', 'customer-profile-row');
        phoneRow.appendChild(el('div', 'customer-profile-row__label', t('phone', 'Phone')));
        const phoneVal = el('div', 'customer-profile-row__value');
        if (customer.phone) {
            phoneVal.appendChild(buildCustomerPhone(customer.phone, 'fw-semibold'));
            phoneVal.appendChild(makeCopyButton(customer.phone));
        } else {
            phoneVal.appendChild(el('span', 'text-tertiary', t('not_specified', '—')));
        }
        phoneRow.appendChild(phoneVal);
        list.appendChild(phoneRow);

        // Email row
        const emailRow = el('div', 'customer-profile-row');
        emailRow.appendChild(el('div', 'customer-profile-row__label', 'Email'));
        const emailVal = el('div', 'customer-profile-row__value');
        if (customer.email) {
            const mailLink = el('a', 'accounting-invoice-link', customer.email);
            mailLink.href = 'mailto:' + customer.email;
            emailVal.appendChild(mailLink);
            emailVal.appendChild(makeCopyButton(customer.email));
        } else {
            emailVal.appendChild(el('span', 'text-tertiary', t('not_specified', '—')));
        }
        emailRow.appendChild(emailVal);
        list.appendChild(emailRow);

        // Address row
        const addrRow = el('div', 'customer-profile-row');
        addrRow.appendChild(el('div', 'customer-profile-row__label', t('address', 'Address')));
        const addrVal = el('div', 'customer-profile-row__value');
        if (customer.address) {
            addrVal.appendChild(el('span', '', customer.address));
            addrVal.appendChild(makeCopyButton(customer.address));
        } else {
            addrVal.appendChild(el('span', 'text-tertiary', t('not_specified', '—')));
        }
        addrRow.appendChild(addrVal);
        list.appendChild(addrRow);

        // Company details when relevant
        const hasCompanyMeta = customer.customer_type === 'company' || customer.company || customer.ico || customer.dic;
        if (hasCompanyMeta) {
            if (customer.company) {
                const compRow = el('div', 'customer-profile-row');
                compRow.appendChild(el('div', 'customer-profile-row__label', t('company', 'Company')));
                const compVal = el('div', 'customer-profile-row__value');
                compVal.appendChild(el('span', 'fw-semibold', customer.company));
                if (customer.contact_name && customer.contact_name !== customer.company) {
                    compVal.appendChild(el('span', 'small text-tertiary', '(' + t('contact_person', 'Contact') + ': ' + customer.contact_name + ')'));
                }
                compRow.appendChild(compVal);
                list.appendChild(compRow);
            }
            if (customer.ico || customer.dic) {
                const taxRow = el('div', 'customer-profile-row');
                taxRow.appendChild(el('div', 'customer-profile-row__label', t('ico', 'IČO') + ' / ' + t('dic', 'DIČ')));
                const taxVal = el('div', 'customer-profile-row__value');
                if (customer.ico) {
                    taxVal.appendChild(el('span', 'font-monospace', 'IČO: ' + customer.ico));
                    taxVal.appendChild(makeCopyButton(customer.ico));
                }
                if (customer.dic) {
                    taxVal.appendChild(el('span', 'font-monospace', 'DIČ: ' + customer.dic));
                    taxVal.appendChild(makeCopyButton(customer.dic));
                }
                taxRow.appendChild(taxVal);
                list.appendChild(taxRow);
            }
        }

        profileWrap.appendChild(title);
        profileWrap.appendChild(list);

        if (typeof window.crmBindPhoneQrTriggers === 'function') {
            window.crmBindPhoneQrTriggers(profileWrap);
        }
    }

    function renderCardKpis(summary, currency) {
        const wrap = document.getElementById('ccKpisGrid');
        if (!wrap) return;
        wrap.replaceChildren();

        const issuedNote = summary.issued_orders + ' ' + t('repair_summary_issued', 'issued') +
            (summary.cancelled_orders > 0 ? (' · ' + summary.cancelled_orders + ' ' + t('repair_summary_cancelled', 'cancelled')) : '');
        const activeNote = summary.ready_orders > 0
            ? (t('status_ready', 'Ready') + ': ' + summary.ready_orders)
            : (summary.active_estimate_total > 0 ? (t('estimated_short', 'est.') + ' ' + formatMoney(summary.active_estimate_total, currency)) : t('last_repair', 'Last') + ': ' + formatDate(summary.last_visit_at, false));
        const deviceNote = summary.warranty_orders > 0
            ? (t('reclamation', 'Reclamation') + ': ' + summary.warranty_orders)
            : (summary.first_visit_at ? (t('customer_since', 'Since') + ': ' + formatDate(summary.first_visit_at, false)) : '—');
        const spendNote = summary.issued_orders > 0
            ? (t('average_check', 'Avg') + ': ' + formatMoney(summary.average_check, currency))
            : t('repair_summary_issued', 'issued') + ': 0';

        const cards = [
            {
                label: t('total_repairs', 'Total repairs'),
                value: String(summary.total_orders || 0),
                note: issuedNote,
                tone: 'info'
            },
            {
                label: t('active_repairs', 'Active repairs'),
                value: String(summary.active_orders || 0),
                note: activeNote,
                tone: summary.active_orders > 0 ? 'accent' : ''
            },
            {
                label: t('unique_devices', 'Devices'),
                value: String(summary.unique_devices || 0),
                note: deviceNote,
                tone: ''
            },
            {
                label: t('total_spent', 'Total spent'),
                value: summary.total_spent === null ? '—' : formatMoney(summary.total_spent, currency),
                note: summary.total_spent === null ? t('invoice_data_unavailable') : spendNote,
                tone: 'success'
            }
        ];

        cards.forEach(function (item) {
            const card = el('article', 'customer-kpi' + (item.tone ? ' customer-kpi--' + item.tone : ''));
            card.appendChild(el('div', 'customer-kpi__label', item.label));
            card.appendChild(el('div', 'customer-kpi__value financial-number', item.value));
            card.appendChild(el('div', 'customer-kpi__note', item.note));
            wrap.appendChild(card);
        });
    }

    let repairsSequence = 0;
    let searchTimer;
    function cardRequest(card, section, params = {}) {
        const query = new URLSearchParams({ customer_id: card.customer.id, section, ...params });
        return window.crmFetchJson('api/get_customer_orders.php?' + query);
    }

    async function loadRepairs(append = false) {
        const card = activeCardData;
        if (!card) return;
        const seq = ++repairsSequence;
        const wrap = document.getElementById('ccRepairsList');
        wrap.textContent = t('loading_text');
        try {
            const result = await cardRequest(card, 'repairs', {
                q: activeSearchQuery, category: activeFilter, offset: append ? card.orders.length : 0
            });
            if (card !== activeCardData || seq !== repairsSequence) return;
            card.orders = append ? card.orders.concat(result.orders) : result.orders;
            card.has_more = result.has_more;
            renderRepairsList();
        } catch (err) {
            if (card === activeCardData && seq === repairsSequence) {
                wrap.textContent = err.message;
                addLoadButton(wrap, () => loadRepairs(append));
            }
        }
    }

    function addLoadButton(wrap, action) {
        const btn = el('button', 'btn btn-sm btn-outline-secondary', t('load_more'));
        btn.type = 'button';
        btn.addEventListener('click', action);
        wrap.appendChild(btn);
    }

    async function loadTab(section, append = false) {
        const card = activeCardData;
        if (!card || card[section + 'Busy'] || (card[section + 'Loaded'] && !append)) return;
        const wrap = document.getElementById(section === 'devices' ? 'ccDevicesList' : 'ccInvoicesList');
        card[section + 'Busy'] = true;
        wrap.textContent = t('loading_text');
        try {
            const result = await cardRequest(card, section, { offset: append ? card[section].length : 0 });
            if (card !== activeCardData) return;
            card[section] = append ? card[section].concat(result[section]) : result[section];
            card[section + 'Loaded'] = true;
            if (section === 'invoices') card.moreInvoices = result.has_more;
            if (section === 'devices') renderDevicesList();
            else renderInvoicesList();
        } catch (err) {
            if (card === activeCardData) {
                wrap.textContent = err.message;
                addLoadButton(wrap, () => loadTab(section, append));
            }
        } finally {
            card[section + 'Busy'] = false;
        }
    }

    function createPrintMenuForOrder(orderId) {
        const dropdown = el('div', 'dropdown');
        const toggle = el('button', 'btn btn-outline-secondary btn-sm dropdown-toggle');
        toggle.type = 'button';
        toggle.setAttribute('data-bs-toggle', 'dropdown');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.appendChild(iconEl('fas fa-print me-1'));
        toggle.appendChild(document.createTextNode(t('print', 'Print')));
        dropdown.appendChild(toggle);

        const menu = el('ul', 'dropdown-menu dropdown-menu-end shadow');
        const items = [
            {
                icon: 'fas fa-file-invoice me-2 text-primary',
                label: t('a4_invoice', 'A4'),
                onClick: function () {
                    openPreviewFromCard('print_order.php?id=' + orderId, t('order', 'Order') + ' #' + orderId);
                }
            },
            {
                icon: 'fas fa-file-import me-2 text-info',
                label: t('reception_act_thermal', 'Reception Act'),
                onClick: function () {
                    openReceptionModalFromCard(orderId);
                }
            },
            {
                icon: 'fas fa-tools me-2 text-warning',
                label: t('work_order', 'Work order'),
                onClick: function () {
                    openPreviewFromCard('print_workshop.php?id=' + orderId, t('work_order', 'Work order') + ' #' + orderId);
                }
            },
            {
                icon: 'fas fa-receipt me-2 text-success',
                label: t('thermal_receipt', 'Receipt'),
                onClick: function () {
                    openPreviewFromCard('print_thermal.php?id=' + orderId, t('thermal_receipt', 'Receipt') + ' #' + orderId);
                }
            }
        ];

        items.forEach(function (spec) {
            const li = document.createElement('li');
            const btn = el('button', 'dropdown-item');
            btn.type = 'button';
            btn.appendChild(iconEl(spec.icon));
            btn.appendChild(document.createTextNode(spec.label));
            btn.addEventListener('click', spec.onClick);
            li.appendChild(btn);
            menu.appendChild(li);
        });

        dropdown.appendChild(menu);
        return dropdown;
    }

    function renderRepairsList() {
        const listWrap = document.getElementById('ccRepairsList');
        if (!listWrap || !activeCardData) return;
        listWrap.replaceChildren();

        const orders = Array.isArray(activeCardData.orders) ? activeCardData.orders : [];
        const customer = activeCardData.customer || {};
        const currency = customer.currency || cfg.currency || 'Kč';

        // Update filter pill counts
        const summary = activeCardData.summary;
        const counts = {all: summary.total_orders, active: summary.active_orders,
            completed: summary.ready_orders + summary.issued_orders, cancelled: summary.cancelled_orders};
        document.querySelectorAll('#ccRepairFilters .customer-filter-pill').forEach(function (pill) {
            const key = pill.dataset.filter || 'all';
            pill.classList.toggle('is-active', key === activeFilter);
            const badge = pill.querySelector('.customer-filter-pill__count');
            if (badge && Object.prototype.hasOwnProperty.call(counts, key)) {
                badge.textContent = String(counts[key]);
            }
        });

        if (!summary.total_orders) {
            const empty = el('div', 'empty-state');
            empty.appendChild(el('div', 'empty-state__mark'));
            empty.appendChild(el('p', 'mb-3', t('customer_no_repairs_hint', 'No repairs yet')));
            if (customer.can_create_order) {
                const cta = el('a', 'btn btn-primary btn-sm');
                cta.href = 'orders.php?new_order=1&customer_id=' + encodeURIComponent(String(customer.id));
                cta.appendChild(iconEl('fas fa-plus me-1'));
                cta.appendChild(document.createTextNode(t('new_order', 'New order')));
                empty.appendChild(cta);
            }
            listWrap.appendChild(empty);
            return;
        }

        const filtered = orders;
        if (filtered.length === 0) {
            const emptyFiltered = el('div', 'empty-state');
            emptyFiltered.appendChild(el('div', 'empty-state__mark'));
            emptyFiltered.appendChild(el('p', 'mb-3', t('no_matching_repairs', 'No matching repairs')));
            const resetBtn = el('button', 'btn btn-outline-secondary btn-sm', t('clear_filter', 'Clear filter'));
            resetBtn.type = 'button';
            resetBtn.addEventListener('click', function () {
                activeFilter = 'all';
                activeSearchQuery = '';
                const input = document.getElementById('ccRepairSearchInput');
                if (input) input.value = '';
                loadRepairs();
            });
            emptyFiltered.appendChild(resetBtn);
            listWrap.appendChild(emptyFiltered);
            return;
        }

        const returnUrl = encodeURIComponent(buildReturnUrl(customer.id));

        filtered.forEach(function (order) {
            const orderId = Number.parseInt(order.id, 10);
            const item = el('article', 'customer-repair-item' + (order.is_high_priority ? ' customer-repair-item--priority' : ''));

            // Header
            const head = el('div', 'customer-repair-item__head');
            const titleBlock = el('div', 'customer-repair-item__title');

            const idLink = el('a', 'customer-repair-item__id', '#' + orderId);
            idLink.href = 'view_order.php?id=' + orderId + '&return=' + returnUrl;
            titleBlock.appendChild(idLink);

            const deviceSpan = el('span', 'customer-repair-item__device');
            deviceSpan.appendChild(iconEl('fas ' + (order.device_icon_class || 'fa-tools') + ' device-row-icon me-1'));
            const devTitle = [order.device_brand, order.device_model].filter(Boolean).join(' ') || order.device_type_label || '—';
            deviceSpan.appendChild(document.createTextNode(devTitle));
            titleBlock.appendChild(deviceSpan);

            if (order.device_type_label) {
                titleBlock.appendChild(el('span', 'small text-tertiary', '· ' + order.device_type_label));
            }
            titleBlock.appendChild(el('span', 'customer-repair-item__date', formatDate(order.created_at, true)));

            const badges = el('div', 'customer-repair-item__badges');
            if (order.is_high_priority) {
                const prio = el('span', 'status-pill status-pill--priority-high');
                prio.appendChild(iconEl('fas fa-bolt'));
                prio.appendChild(document.createTextNode(t('high', 'High')));
                badges.appendChild(prio);
            }
            if (order.is_warranty) {
                badges.appendChild(el('span', 'status-pill status-pill--approval', t('reclamation', 'Warranty')));
            }
            badges.appendChild(buildStatusPill(order.status_variant, order.status_label));

            head.appendChild(titleBlock);
            head.appendChild(badges);
            item.appendChild(head);

            // Body
            const body = el('div', 'customer-repair-item__body');
            if (order.problem_description) {
                body.appendChild(el('div', 'customer-repair-item__problem', order.problem_description));
            }
            if (order.technician_notes) {
                const noteBox = el('div', 'customer-repair-item__note');
                noteBox.appendChild(el('strong', 'me-1', t('notes', 'Notes') + ':'));
                noteBox.appendChild(document.createTextNode(order.technician_notes));
                body.appendChild(noteBox);
            }
            if (order.cancellation_reason) {
                const cancelBox = el('div', 'customer-repair-item__cancel');
                cancelBox.appendChild(el('strong', 'me-1', t('cancellation_reason', 'Reason') + ':'));
                cancelBox.appendChild(document.createTextNode(order.cancellation_reason));
                body.appendChild(cancelBox);
            }

            // Metadata chips
            const meta = el('div', 'customer-repair-item__meta');
            if (order.serial_number) {
                const snChip = el('span', 'customer-repair-item__meta-chip font-monospace');
                snChip.appendChild(iconEl('fas fa-barcode'));
                snChip.appendChild(document.createTextNode(t('sn1', 'S/N 1') + ': ' + order.serial_number));
                meta.appendChild(snChip);
            }
            if (order.serial_number_2) {
                const sn2Chip = el('span', 'customer-repair-item__meta-chip font-monospace');
                sn2Chip.appendChild(iconEl('fas fa-barcode'));
                sn2Chip.appendChild(document.createTextNode(t('sn2', 'S/N 2') + ': ' + order.serial_number_2));
                meta.appendChild(sn2Chip);
            }
            if (order.tech_name) {
                const techChip = el('span', 'customer-repair-item__meta-chip');
                techChip.appendChild(iconEl('fas fa-user-cog'));
                techChip.appendChild(document.createTextNode(order.tech_name));
                meta.appendChild(techChip);
            }
            if (order.appearance) {
                const appChip = el('span', 'customer-repair-item__meta-chip');
                appChip.appendChild(iconEl('fas fa-box-open'));
                appChip.appendChild(document.createTextNode(order.appearance));
                meta.appendChild(appChip);
            }
            if (order.shipping_method_label) {
                const shipText = order.shipping_method_label +
                    (order.shipping_tracking ? (' · ' + order.shipping_tracking) : '') +
                    (order.shipping_date ? (' · ' + formatDate(order.shipping_date, false)) : '');
                const shipChip = el('span', 'customer-repair-item__meta-chip text-info');
                shipChip.appendChild(iconEl('fas fa-truck'));
                shipChip.appendChild(document.createTextNode(shipText));
                meta.appendChild(shipChip);
            }
            if (meta.childNodes.length > 0) {
                body.appendChild(meta);
            }

            if (!order.detailLoaded) {
                const btn = el('button', 'btn btn-sm btn-outline-secondary', t('repair_details'));
                btn.type = 'button';
                btn.addEventListener('click', async () => {
                    const card = activeCardData;
                    btn.disabled = true;
                    try {
                        const result = await cardRequest(card, 'detail', { order_id: orderId });
                        if (card !== activeCardData) return;
                        if (!result.orders.length) throw new Error(t('error_loading_data'));
                        Object.assign(order, result.orders[0], { detailLoaded: true });
                        renderRepairsList();
                    } catch (err) {
                        btn.disabled = false;
                        if (card === activeCardData) showCustomersToast(err.message, 'danger');
                    }
                });
                body.appendChild(btn);
            }
            // Parts used
            if (Array.isArray(order.items) && order.items.length > 0) {
                const partsRow = el('div', 'customer-repair-item__parts');
                partsRow.appendChild(el('span', 'small text-tertiary me-1', t('parts_used', 'Parts') + ':'));
                order.items.forEach(function (part) {
                    const chip = el('span', 'customer-part-chip');
                    let label = (part.name || '—') + ' ×' + (part.quantity || 1);
                    if (part.price !== null && part.price !== undefined) {
                        label += ' · ' + formatMoney(Number(part.price) * Number(part.quantity || 1), currency);
                    }
                    chip.textContent = label;
                    partsRow.appendChild(chip);
                });
                body.appendChild(partsRow);
            }

            // Media thumbnails (authorized via api/media.php?id=...)
            if (Array.isArray(order.attachments) && order.attachments.length > 0) {
                const mediaRow = el('div', 'customer-repair-item__media');
                const galleryName = 'customer-order-' + orderId;
                order.attachments.forEach(function (att, attIdx) {
                    const thumb = el('a', 'customer-media-thumb');
                    thumb.href = att.url;
                    thumb.setAttribute('data-fancybox', galleryName);
                    thumb.setAttribute('data-caption', '#' + orderId + ' — ' + (att.file_name || ''));
                    thumb.addEventListener('click', function (event) {
                        if (typeof Fancybox !== 'undefined') {
                            event.preventDefault();
                            event.stopPropagation();
                            openMediaGalleryFromCard(mediaRow, attIdx);
                        }
                    });
                    if (att.is_video) {
                        thumb.setAttribute('data-type', 'video');
                        thumb.appendChild(iconEl('fas fa-video'));
                    } else {
                        thumb.setAttribute('data-type', 'image');
                        const img = document.createElement('img');
                        img.src = att.url;
                        img.alt = att.file_name || t('media_files', 'Photo');
                        img.loading = 'lazy';
                        thumb.appendChild(img);
                    }
                    mediaRow.appendChild(thumb);
                });
                body.appendChild(mediaRow);
            }

            item.appendChild(body);

            // Footer
            const foot = el('div', 'customer-repair-item__foot');
            const priceBox = el('div', 'customer-repair-item__price');
            if (order.customer_total !== null && order.customer_total !== undefined) {
                priceBox.appendChild(el('span', 'customer-repair-item__price-main financial-number', formatMoney(order.customer_total, currency)));
                priceBox.appendChild(el('span', 'small text-tertiary', order.has_final_cost ? t('final_short', 'final') : t('estimated_short', 'est.')));
            } else {
                priceBox.appendChild(el('span', 'small text-tertiary', t('amount', 'Amount') + ': —'));
            }

            if (order.invoice && customer.is_admin) {
                const invBtn = el('button', 'btn btn-sm btn-outline-secondary');
                invBtn.type = 'button';
                invBtn.appendChild(iconEl('fas fa-file-invoice me-1 text-primary'));
                invBtn.appendChild(document.createTextNode(order.invoice.invoice_number));
                invBtn.addEventListener('click', function () {
                    openPreviewFromCard('print_invoice.php?id=' + Number.parseInt(order.invoice.id, 10), t('invoice', 'Invoice') + ' ' + order.invoice.invoice_number);
                });
                priceBox.appendChild(invBtn);
            }

            const actionsBox = el('div', 'customer-repair-item__actions');
            actionsBox.appendChild(createPrintMenuForOrder(orderId));

            const openLink = el('a', 'btn btn-sm btn-primary', t('open_btn', 'Open'));
            openLink.href = 'view_order.php?id=' + orderId + '&return=' + returnUrl;
            actionsBox.appendChild(openLink);

            foot.appendChild(priceBox);
            foot.appendChild(actionsBox);
            item.appendChild(foot);

            listWrap.appendChild(item);
        });
        if (activeCardData.has_more) addLoadButton(listWrap, () => loadRepairs(true));
    }

    function renderDevicesList() {
        const wrap = document.getElementById('ccDevicesList');
        if (!wrap || !activeCardData) return;
        wrap.replaceChildren();

        const devices = Array.isArray(activeCardData.devices) ? activeCardData.devices : [];
        const customer = activeCardData.customer || {};

        if (devices.length === 0) {
            const empty = el('div', 'empty-state');
            empty.appendChild(el('div', 'empty-state__mark'));
            empty.appendChild(el('p', 'mb-0', t('no_devices_found', 'No devices')));
            wrap.appendChild(empty);
            return;
        }

        const grid = el('div', 'customer-devices-grid');
        const returnUrl = encodeURIComponent(buildReturnUrl(customer.id));

        devices.forEach(function (dev) {
            const card = el('article', 'customer-device-card');

            const head = el('div', 'customer-device-card__head');
            const titleWrap = document.createElement('div');
            const title = el('div', 'customer-device-card__title');
            title.appendChild(iconEl('fas ' + (dev.device_icon_class || 'fa-tools') + ' device-row-icon me-1'));
            const devName = [dev.device_brand, dev.device_model].filter(Boolean).join(' ') || dev.device_type_label || '—';
            title.appendChild(document.createTextNode(devName));
            titleWrap.appendChild(title);
            if (dev.device_type_label) {
                titleWrap.appendChild(el('div', 'small text-tertiary', dev.device_type_label));
            }
            head.appendChild(titleWrap);
            head.appendChild(el('span', 'summary-chip', t('visits_count', 'Visits') + ': ' + (dev.visits_count || 1)));
            card.appendChild(head);

            const meta = el('div', 'customer-device-card__meta');
            if (dev.serial_number) {
                meta.appendChild(el('div', 'font-monospace', t('sn1', 'S/N 1') + ': ' + dev.serial_number));
            }
            if (dev.serial_number_2) {
                meta.appendChild(el('div', 'font-monospace', t('sn2', 'S/N 2') + ': ' + dev.serial_number_2));
            }
            meta.appendChild(el('div', '', t('last_repair', 'Last repair') + ': ' + formatDate(dev.last_visit_at, false)));
            card.appendChild(meta);

            const foot = el('div', 'customer-device-card__foot');
            foot.appendChild(buildStatusPill(dev.last_status_variant, dev.last_status_label));

            const btns = el('div', 'd-flex flex-wrap gap-2');
            const filterBtn = el('button', 'btn btn-sm btn-outline-secondary', t('show_device_repairs', 'Show repairs'));
            filterBtn.type = 'button';
            filterBtn.addEventListener('click', function () {
                activeFilter = 'all';
                activeSearchQuery = dev.serial_number || devName;
                const input = document.getElementById('ccRepairSearchInput');
                if (input) input.value = activeSearchQuery;
                loadRepairs();
                switchCardTab('repairs');
            });
            btns.appendChild(filterBtn);

            if (dev.last_order_id) {
                const openLatest = el('a', 'btn btn-sm btn-outline-primary', '#' + dev.last_order_id);
                openLatest.href = 'view_order.php?id=' + Number.parseInt(dev.last_order_id, 10) + '&return=' + returnUrl;
                btns.appendChild(openLatest);
            }

            foot.appendChild(btns);
            card.appendChild(foot);
            grid.appendChild(card);
        });

        wrap.appendChild(grid);
    }

    function renderInvoicesList() {
        const wrap = document.getElementById('ccInvoicesList');
        if (!wrap || !activeCardData) return;
        wrap.replaceChildren();

        const invoices = Array.isArray(activeCardData.invoices) ? activeCardData.invoices : [];
        const customer = activeCardData.customer || {};
        if (!customer.is_admin) return;

        if (invoices.length === 0) {
            const empty = el('div', 'empty-state');
            empty.appendChild(el('div', 'empty-state__mark'));
            empty.appendChild(el('p', 'mb-0', t('no_invoices', 'No invoices')));
            wrap.appendChild(empty);
            return;
        }

        const tableWrap = el('div', 'table-responsive table-scroll-touch');
        const table = el('table', 'table table-hover align-middle mb-0 table-mobile-cards');
        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        [
            t('invoice_number', 'Invoice #'),
            t('date_issue', 'Issue date'),
            t('order', 'Order'),
            t('status', 'Status'),
            t('amount', 'Amount'),
            t('action', 'Action')
        ].forEach(function (colText, idx) {
            const th = el('th', idx >= 4 ? 'text-end' : '', colText);
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        const returnUrl = encodeURIComponent(buildReturnUrl(customer.id));

        invoices.forEach(function (inv) {
            const invId = Number.parseInt(inv.id, 10);
            const tr = document.createElement('tr');

            const tdNum = el('td', '');
            tdNum.setAttribute('data-label', t('invoice_number', 'Invoice #'));
            const numBtn = el('button', 'customer-name-trigger', inv.invoice_number);
            numBtn.type = 'button';
            numBtn.addEventListener('click', function () {
                openPreviewFromCard('print_invoice.php?id=' + invId, t('invoice', 'Invoice') + ' ' + inv.invoice_number);
            });
            tdNum.appendChild(numBtn);
            tr.appendChild(tdNum);

            const tdDate = el('td', '', formatDate(inv.date_issue, false));
            tdDate.setAttribute('data-label', t('date_issue', 'Date'));
            tr.appendChild(tdDate);

            const tdOrder = el('td', '');
            tdOrder.setAttribute('data-label', t('order', 'Order'));
            if (inv.order_id) {
                const ordLink = el('a', 'accounting-invoice-link', '#' + inv.order_id);
                ordLink.href = 'view_order.php?id=' + Number.parseInt(inv.order_id, 10) + '&return=' + returnUrl;
                tdOrder.appendChild(ordLink);
            } else {
                tdOrder.appendChild(el('span', 'text-tertiary', '—'));
            }
            tr.appendChild(tdOrder);

            const tdStatus = el('td', '');
            tdStatus.setAttribute('data-label', t('status', 'Status'));
            tdStatus.appendChild(buildStatusPill(inv.status_variant, inv.status_label));
            tr.appendChild(tdStatus);

            const tdAmt = el('td', 'text-end financial-number fw-bold', formatMoney(inv.total_amount, inv.currency));
            tdAmt.setAttribute('data-label', t('amount', 'Amount'));
            tr.appendChild(tdAmt);

            const tdAct = el('td', 'text-end mobile-row-actions');
            tdAct.setAttribute('data-label', '');
            const previewBtn = el('button', 'btn btn-sm btn-outline-primary');
            previewBtn.type = 'button';
            previewBtn.appendChild(iconEl('fas fa-eye me-1'));
            previewBtn.appendChild(document.createTextNode(t('preview_btn', 'Preview')));
            previewBtn.addEventListener('click', function () {
                openPreviewFromCard('print_invoice.php?id=' + invId, t('invoice', 'Invoice') + ' ' + inv.invoice_number);
            });
            tdAct.appendChild(previewBtn);
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        tableWrap.appendChild(table);
        wrap.appendChild(tableWrap);
        if (activeCardData.moreInvoices) addLoadButton(wrap, () => loadTab('invoices', true));
    }

    function syncEditCompanyFields(isCompany) {
        const box = document.getElementById('ccEditCompanyFields');
        if (box) {
            box.classList.toggle('d-none', !isCompany);
        }
    }

    function populateEditForm(customer, summary) {
        const form = document.getElementById('customerCardEditForm');
        if (!form) return;

        const errEl = document.getElementById('ccEditError');
        if (errEl) {
            errEl.classList.add('d-none');
            errEl.textContent = '';
        }

        document.getElementById('ccEditId').value = String(customer.id);
        const isCompany = customer.customer_type === 'company';
        document.getElementById('ccEditTypePrivate').checked = !isCompany;
        document.getElementById('ccEditTypeCompany').checked = isCompany;
        syncEditCompanyFields(isCompany);

        document.getElementById('ccEditIco').value = customer.ico || '';
        document.getElementById('ccEditDic').value = customer.dic || '';
        document.getElementById('ccEditCompany').value = customer.company || '';
        document.getElementById('ccEditFirstName').value = customer.first_name || '';
        document.getElementById('ccEditLastName').value = customer.last_name || '';
        document.getElementById('ccEditPhone').value = customer.phone || '';
        document.getElementById('ccEditEmail').value = customer.email || '';
        document.getElementById('ccEditAddress').value = customer.address || '';

        const fullPageLink = document.getElementById('ccFullEditLink');
        if (fullPageLink) {
            fullPageLink.href = 'edit_customer.php?id=' + encodeURIComponent(String(customer.id));
        }

        const deleteBtn = document.getElementById('ccEditDeleteBtn');
        const deleteHint = document.getElementById('ccEditDeleteHint');
        const hasOrders = Number(summary.total_orders || 0) > 0;
        if (deleteBtn) {
            deleteBtn.disabled = hasOrders;
        }
        if (deleteHint) {
            deleteHint.classList.toggle('d-none', !hasOrders);
        }
    }

    function renderCustomerCard(data, initialTab) {
        activeCardData = data;
        document.getElementById('customerCardEditToggleBtn').disabled = !data.customer.can_edit_customer;
        const customer = data.customer || {};
        const summary = data.summary || {};

        const isCompany = customer.customer_type === 'company';
        const avatarEl = document.getElementById('customerCardAvatar');
        if (avatarEl) {
            avatarEl.textContent = computeInitials(customer.display_name, isCompany);
            avatarEl.classList.toggle('customer-card__avatar--company', isCompany);
        }

        const titleEl = document.getElementById('customerCardModalTitle');
        if (titleEl) {
            titleEl.textContent = customer.display_name || '';
        }

        const subtitleEl = document.getElementById('customerCardSubtitle');
        if (subtitleEl) {
            subtitleEl.replaceChildren();
            subtitleEl.appendChild(el('span', 'font-monospace', '#' + customer.id));
            subtitleEl.appendChild(el('span', 'customer-type-tag' + (isCompany ? ' customer-type-tag--company' : ''), isCompany ? t('company_entity', 'Company') : t('private_person', 'Private')));
            if (customer.created_at) {
                subtitleEl.appendChild(el('span', '', t('customer_since', 'Since') + ' ' + formatDate(customer.created_at, false)));
            }
        }

        const newOrderBtn = document.getElementById('customerCardNewOrderBtn');
        if (newOrderBtn) {
            newOrderBtn.href = 'orders.php?new_order=1&customer_id=' + encodeURIComponent(String(customer.id));
            newOrderBtn.classList.toggle('d-none', !customer.can_create_order);
        }

        const repairsCountBadge = document.getElementById('ccTabRepairsCount');
        if (repairsCountBadge) repairsCountBadge.textContent = String(summary.total_orders || 0);

        const devicesCountBadge = document.getElementById('ccTabDevicesCount');
        if (devicesCountBadge) devicesCountBadge.textContent = String(summary.unique_devices || 0);

        const invoicesTabItem = document.getElementById('ccTabInvoicesItem');
        const invoicesCountBadge = document.getElementById('ccTabInvoicesCount');
        if (invoicesTabItem) {
            invoicesTabItem.classList.toggle('d-none', !customer.is_admin);
        }
        if (invoicesCountBadge) {
            invoicesCountBadge.textContent = String(data.invoice_count ?? '—');
        }

        renderCardProfile(customer);
        renderCardKpis(summary, customer.currency);
        renderRepairsList();
        if (data.devicesLoaded) renderDevicesList();
        if (data.invoicesLoaded) renderInvoicesList();
        populateEditForm(customer, summary);

        switchCardTab(initialTab || 'repairs');
        if (initialTab === 'devices' || (initialTab === 'invoices' && customer.is_admin)) loadTab(initialTab);
    }

    function updateDirectoryRowFromCustomer(customer) {
        const row = document.querySelector('tr.customer-row[data-customer-id="' + customer.id + '"]');
        if (!row) return;

        const isCompany = customer.customer_type === 'company';
        const avatar = row.querySelector('.customer-avatar-chip');
        if (avatar) {
            avatar.textContent = computeInitials(customer.display_name, isCompany);
            avatar.classList.toggle('customer-avatar-chip--company', isCompany);
        }

        const nameText = row.querySelector('.customer-name-trigger__text');
        if (nameText) nameText.textContent = customer.display_name || '';

        const nameBtn = row.querySelector('.customer-name-trigger');
        if (nameBtn) nameBtn.dataset.customerName = customer.display_name || '';

        const submeta = row.querySelector('.customer-submeta');
        if (submeta) {
            submeta.replaceChildren();
            submeta.appendChild(el('span', 'font-monospace', '#' + customer.id));
            submeta.appendChild(el('span', 'customer-type-tag' + (isCompany ? ' customer-type-tag--company' : ''), isCompany ? t('company_entity', 'Company') : t('private_person', 'Private')));
            if (customer.ico) {
                submeta.appendChild(el('span', 'font-monospace', 'IČO: ' + customer.ico));
            }
            if (isCompany && customer.contact_name && customer.contact_name !== customer.display_name) {
                submeta.appendChild(el('span', '', customer.contact_name));
            }
        }

        const contactsCell = row.querySelector('[data-crm-col="contacts"]');
        if (contactsCell) {
            contactsCell.replaceChildren();
            if (customer.phone) {
                contactsCell.appendChild(buildCustomerPhone(customer.phone, 'small'));
            }
            if (customer.email) {
                const emailDiv = el('div', 'small text-white-75 text-truncate customer-contact-line');
                emailDiv.appendChild(iconEl('far fa-envelope me-1'));
                emailDiv.appendChild(document.createTextNode(customer.email));
                contactsCell.appendChild(emailDiv);
            }
            if (customer.address) {
                const addrDiv = el('div', 'small text-tertiary text-truncate customer-contact-line');
                addrDiv.title = customer.address;
                addrDiv.appendChild(iconEl('fas fa-location-dot me-1'));
                addrDiv.appendChild(document.createTextNode(customer.address));
                contactsCell.appendChild(addrDiv);
            }
            if (typeof window.crmBindPhoneQrTriggers === 'function') {
                window.crmBindPhoneQrTriggers(contactsCell);
            }
        }
    }

    function openCustomerCard(customerId, initialTab, fallbackName) {
        const id = Number.parseInt(customerId, 10);
        if (!Number.isSafeInteger(id) || id <= 0) return;

        const modal = getCardModal();
        if (!modal) return;

        clearTimeout(searchTimer);
        activeCardData = null;
        const action = document.getElementById('customerCardNewOrderBtn');
        action.removeAttribute('href');
        action.classList.add('d-none');
        document.getElementById('customerCardEditToggleBtn').disabled = true;
        activeCustomerId = id;
        activeFilter = 'all';
        activeSearchQuery = '';
        const searchInput = document.getElementById('ccRepairSearchInput');
        if (searchInput) searchInput.value = '';

        const titleEl = document.getElementById('customerCardModalTitle');
        if (titleEl) titleEl.textContent = fallbackName || (t('customer_card', 'Customer card') + ' #' + id);
        const subtitleEl = document.getElementById('customerCardSubtitle');
        if (subtitleEl) subtitleEl.replaceChildren(el('span', 'font-monospace', '#' + id));

        const loadingEl = document.getElementById('customerCardLoading');
        const errorEl = document.getElementById('customerCardError');
        const contentEl = document.getElementById('customerCardContent');
        if (loadingEl) loadingEl.classList.remove('d-none');
        if (errorEl) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
        }
        if (contentEl) contentEl.classList.add('d-none');

        updateUrlCustomerId(id);
        modal.show();

        const mySeq = ++requestCounter;
        window.crmFetchJson('api/get_customer_orders.php?customer_id=' + encodeURIComponent(String(id)))
            .then(function (res) {
                if (mySeq !== requestCounter) return;
                if (loadingEl) loadingEl.classList.add('d-none');
                if (contentEl) contentEl.classList.remove('d-none');
                renderCustomerCard(res, initialTab || 'repairs');
            })
            .catch(function (err) {
                if (mySeq !== requestCounter) return;
                if (loadingEl) loadingEl.classList.add('d-none');
                if (errorEl) {
                    errorEl.textContent = (err && err.message) ? err.message : t('error_loading_data', 'Error loading data');
                    errorEl.classList.remove('d-none');
                }
            });
    }

    window.openCustomerCard = openCustomerCard;
    window.showCustomerOrders = function (id, name) {
        openCustomerCard(id, 'repairs', name);
    };

    document.addEventListener('DOMContentLoaded', function () {
        // Click anywhere on customer directory row (except interactive child controls or text selection)
        document.addEventListener('click', function (event) {
            const row = event.target instanceof Element ? event.target.closest('tr.customer-row[data-customer-id]') : null;
            if (!row) return;
            if (event.target.closest('a, button, input, select, textarea, label, .phone-qr-trigger')) return;
            const sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel.trim() !== '') return;

            const id = Number.parseInt(row.dataset.customerId || '', 10);
            const nameBtn = row.querySelector('.customer-name-trigger');
            const name = nameBtn ? (nameBtn.dataset.customerName || '') : '';
            if (id > 0) {
                openCustomerCard(id, 'repairs', name);
            }
        });

        for (const [id, section] of [['ccTabDevices','devices'], ['ccTabInvoices','invoices']]) {
            document.getElementById(id)?.addEventListener('shown.bs.tab', () => loadTab(section));
        }
        // Customer card modal lifecycle
        const cardModalEl = document.getElementById('customerCardModal');
        if (cardModalEl) {
            cardModalEl.addEventListener('hidden.bs.modal', function () {
                if (typeof window.crmHidePhoneQrPopover === 'function') {
                    window.crmHidePhoneQrPopover();
                }
                if (!reopenCustomerAfterPreview) {
                    requestCounter++;
                    activeCardData = null;
                    activeCustomerId = null;
                    updateUrlCustomerId(null);
                }
            });
        }

        // Re-open customer card after universal preview or reception language modal closes
        const previewModalEl = document.getElementById('universalPreviewModal');
        if (previewModalEl) {
            previewModalEl.addEventListener('hidden.bs.modal', function () {
                restoreLoadedCard();
            });
        }

        const receptionModalEl = document.getElementById('receptionLangModal');
        if (receptionModalEl) {
            receptionModalEl.querySelectorAll('.btn-lang-select').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const lang = btn.dataset.lang || 'cs';
                    const orderId = receptionOrderId || Number.parseInt((document.getElementById('langOrderId') || {}).value || '0', 10);
                    receptionSelected = true;
                    bootstrap.Modal.getOrCreateInstance(receptionModalEl).hide();
                    if (orderId > 0 && typeof window.openUniversalPreview === 'function') {
                        window.openUniversalPreview(
                            'print_reception_thermal.php?id=' + orderId + '&lang=' + encodeURIComponent(lang),
                            t('reception_act_thermal', 'Reception Act') + ' #' + orderId
                        );
                    }
                });
            });

            receptionModalEl.addEventListener('hidden.bs.modal', function () {
                if (!receptionSelected) {
                    restoreLoadedCard();
                }
                receptionSelected = false;
            });
        }

        // Header edit toggle button
        const headerEditBtn = document.getElementById('customerCardEditToggleBtn');
        if (headerEditBtn) {
            headerEditBtn.addEventListener('click', function () {
                switchCardTab('edit');
                window.setTimeout(function () {
                    const firstInput = document.getElementById('ccEditFirstName');
                    if (firstInput) firstInput.focus({ preventScroll: true });
                }, 150);
            });
        }

        // Repairs filter pills & search input
        const filterGroup = document.getElementById('ccRepairFilters');
        if (filterGroup) {
            filterGroup.addEventListener('click', function (event) {
                const pill = event.target instanceof Element ? event.target.closest('.customer-filter-pill') : null;
                if (!pill) return;
                activeFilter = pill.dataset.filter || 'all';
                loadRepairs();
            });
        }

        const repairSearchInput = document.getElementById('ccRepairSearchInput');
        if (repairSearchInput) {
            repairSearchInput.addEventListener('input', function () {
                activeSearchQuery = repairSearchInput.value || '';
                repairsSequence++;
                clearTimeout(searchTimer);
                searchTimer = setTimeout(loadRepairs, 220);
            });
        }

        // New Customer modal: private/company toggle + ARES
        document.querySelectorAll('#newCustomerModal input[name="customer_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                const isCompany = radio.value === 'company';
                const companyFields = document.getElementById('company_fields');
                const firstName = document.getElementById('ares_first_name');
                const lastName = document.getElementById('ares_last_name');
                if (companyFields) companyFields.classList.toggle('d-none', !isCompany);
                if (firstName) firstName.value = isCompany ? 'Firma' : '';
                if (lastName) lastName.value = '';
            });
        });

        bindAresLookup(
            document.getElementById('btn_fetch_ares'),
            document.getElementById('ico_input'),
            {
                company: document.getElementById('ares_name'),
                dic: document.getElementById('ares_dic'),
                firstName: document.getElementById('ares_first_name'),
                lastName: document.getElementById('ares_last_name'),
                address: document.getElementById('ares_address'),
                fillNamesFromCompany: true
            }
        );

        // Card inline Edit form: private/company toggle + ARES + AJAX submit + delete
        document.querySelectorAll('#customerCardEditForm input[name="customer_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncEditCompanyFields(radio.value === 'company');
            });
        });

        bindAresLookup(
            document.getElementById('ccEditFetchAresBtn'),
            document.getElementById('ccEditIco'),
            {
                company: document.getElementById('ccEditCompany'),
                dic: document.getElementById('ccEditDic'),
                firstName: document.getElementById('ccEditFirstName'),
                lastName: document.getElementById('ccEditLastName'),
                address: document.getElementById('ccEditAddress'),
                fillNamesFromCompany: false
            }
        );

        const editForm = document.getElementById('customerCardEditForm');
        if (editForm) {
            editForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const errEl = document.getElementById('ccEditError');
                if (errEl) {
                    errEl.classList.add('d-none');
                    errEl.textContent = '';
                }

                const saveBtn = document.getElementById('ccEditSaveBtn');
                const prevHtml = saveBtn ? saveBtn.innerHTML : '';
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.replaceChildren(
                        el('span', 'spinner-border spinner-border-sm me-1'),
                        document.createTextNode(t('saving', 'Saving') + '...')
                    );
                }

                const formData = new FormData(editForm);
                if (!formData.get('csrf_token') && cfg.csrfToken) {
                    formData.set('csrf_token', cfg.csrfToken);
                }

                window.crmFetchJson('api/update_customer.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(function (res) {
                        if (res && res.customer && activeCardData && Number(res.customer.id) === activeCustomerId) {
                            activeCardData.customer = Object.assign({}, activeCardData.customer, res.customer);
                            renderCustomerCard(activeCardData, 'repairs');
                            updateDirectoryRowFromCustomer(activeCardData.customer);
                        }
                        showCustomersToast((res && res.message) || t('customer_updated_success', 'Updated'), 'success');
                    })
                    .catch(function (err) {
                        const msg = (err && err.message) ? err.message : t('error', 'Error');
                        if (errEl) {
                            errEl.textContent = msg;
                            errEl.classList.remove('d-none');
                        } else {
                            showCustomersToast(msg, 'danger');
                        }
                    })
                    .finally(function () {
                        if (saveBtn) {
                            saveBtn.disabled = false;
                            saveBtn.innerHTML = prevHtml;
                        }
                    });
            });
        }

        const deleteBtn = document.getElementById('ccEditDeleteBtn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                if (!activeCustomerId || deleteBtn.disabled) return;
                const targetId = activeCustomerId;

                const performDelete = function () {
                    const body = new URLSearchParams();
                    body.set('id', String(targetId));
                    if (cfg.csrfToken) body.set('csrf_token', cfg.csrfToken);

                    window.crmFetchJson('api/delete_customer.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: body.toString()
                    })
                        .then(function () {
                            updateUrlCustomerId(null);
                            window.location.href = 'customers.php';
                        })
                        .catch(function (err) {
                            showCustomersToast((err && err.message) || t('error', 'Error'), 'danger');
                        });
                };

                if (typeof window.showConfirm === 'function') {
                    const cardModalEl = document.getElementById('customerCardModal');
                    const confirmEl = document.getElementById('globalConfirmModal');
                    if (cardModalEl && cardModalEl.classList.contains('show') && confirmEl) {
                        let confirmed = false;
                        reopenCustomerAfterPreview = targetId;
                        cardModalEl.addEventListener('hidden.bs.modal', function onHidden() {
                            confirmEl.addEventListener('hidden.bs.modal', function onConfirmHidden() {
                                if (!confirmed && reopenCustomerAfterPreview) {
                                    const cid = reopenCustomerAfterPreview;
                                    reopenCustomerAfterPreview = null;
                                    openCustomerCard(cid, 'edit');
                                }
                            }, { once: true });

                            window.showConfirm(t('confirm_delete_customer', 'Delete customer?'), function () {
                                confirmed = true;
                                reopenCustomerAfterPreview = null;
                                performDelete();
                            }, undefined, 'danger');
                        }, { once: true });
                        getCardModal().hide();
                    } else {
                        window.showConfirm(t('confirm_delete_customer', 'Delete customer?'), performDelete, undefined, 'danger');
                    }
                }
            });
        }

        // Auto-open deep-linked customer card (?customer_id=123)
        if (cfg.initialCustomerId && Number(cfg.initialCustomerId) > 0) {
            openCustomerCard(Number(cfg.initialCustomerId), cfg.initialTab || 'repairs', '');
        }
    });
})();
