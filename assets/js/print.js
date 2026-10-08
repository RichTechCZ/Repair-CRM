document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(event) {
        const element = event.target instanceof Element
            ? event.target.closest('[data-print-action]')
            : null;
        if (!element) return;

        if (element.dataset.printAction === 'print') {
            event.preventDefault();
            window.print();
        } else if (element.dataset.printAction === 'close') {
            event.preventDefault();
            window.close();
        }
    });

    if (document.body.dataset.autoPrint === '1') {
        window.print();
    }
});
