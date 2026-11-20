/* Compact navigation and on-screen keyboard layout. */
(function () {
    'use strict';

    const newOrderLink = document.querySelector('.ios-tabbar [data-tab-action="new-order"]');
    newOrderLink?.addEventListener('click', function (event) {
        const modal = document.getElementById('newOrderModal');
        if (!modal || !window.bootstrap || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        modal.addEventListener('hidden.bs.modal', function () {
            window.requestAnimationFrame(function () {
                newOrderLink.focus({ preventScroll: true });
            });
        }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(modal).show(newOrderLink);
    });

    const compact = window.matchMedia('(max-width: 991.98px)');
    const viewport = window.visualViewport;
    const textFields = 'input:is([type="text"], [type="search"], [type="number"], [type="tel"], [type="email"], [type="url"], [type="password"], :not([type])), textarea, [contenteditable="true"]';

    function updateKeyboardLayout() {
        const keyboardOpen = compact.matches && viewport.scale === 1
            && window.innerHeight - viewport.height > 120
            && document.activeElement?.matches(textFields);
        document.body.classList.toggle('keyboard-open', Boolean(keyboardOpen));
        if (keyboardOpen) {
            document.body.style.setProperty('--keyboard-viewport-height', viewport.height + 'px');
            document.body.style.setProperty('--keyboard-viewport-top', viewport.offsetTop + 'px');
        }
    }

    if (viewport) {
        viewport.addEventListener('resize', updateKeyboardLayout);
        viewport.addEventListener('scroll', updateKeyboardLayout);
        document.addEventListener('focusin', updateKeyboardLayout);
        document.addEventListener('focusout', function () {
            window.requestAnimationFrame(updateKeyboardLayout);
        });
    }

    const settingsTabs = document.getElementById('settingsTabs');
    const activeSettingsTab = settingsTabs?.querySelector('.nav-link.active');
    if (activeSettingsTab) {
        settingsTabs.scrollLeft += activeSettingsTab.getBoundingClientRect().left
            - settingsTabs.getBoundingClientRect().left;
    }
})();
