/* Phone behaviour: tab bar state, quick "new order", keyboard-aware layout. */
(function () {
    'use strict';

    var tabbar = document.querySelector('.ios-tabbar');
    if (!tabbar) { return; }

    var sidebarToggle = document.getElementById('sidebarCollapse');
    var moreButton = tabbar.querySelector('[data-tab-action="more"]');
    var newButton = tabbar.querySelector('[data-tab-action="new-order"]');

    function openNewOrderModal() {
        var modalEl = document.getElementById('newOrderModal');
        if (!modalEl || !window.bootstrap) { return false; }
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        return true;
    }

    if (moreButton && sidebarToggle) {
        moreButton.addEventListener('click', function () {
            sidebarToggle.click();
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function (event) {
            // Pages without the modal follow the link to orders.php?new_order=1.
            if (openNewOrderModal()) {
                event.preventDefault();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        try {
            var params = new URLSearchParams(window.location.search);
            if (params.get('new_order') === '1' && openNewOrderModal()) {
                params.delete('new_order');
                var query = params.toString();
                window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
            }
        } catch (e) { /* very old WebView: ignore */ }
    });

    // Hide the tab bar while the on-screen keyboard is open so it never floats over inputs.
    var textFields = 'input:not([type=checkbox]):not([type=radio]):not([type=button]):not([type=submit]), textarea, select';
    document.addEventListener('focusin', function (event) {
        if (event.target && event.target.matches && event.target.matches(textFields)) {
            document.body.classList.add('keyboard-open');
        }
    });
    document.addEventListener('focusout', function () {
        window.setTimeout(function () {
            var active = document.activeElement;
            if (!active || !active.matches || !active.matches(textFields)) {
                document.body.classList.remove('keyboard-open');
            }
        }, 80);
    });
})();
