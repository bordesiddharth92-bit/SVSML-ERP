/* SVSML-ERP — small JS helpers
   Currently: confirm-on-delete only. More will land with later modules. */

(function () {
    'use strict';

    // Confirm before any element with data-confirm="..." is clicked.
    document.addEventListener('click', function (ev) {
        var t = ev.target.closest('[data-confirm]');
        if (!t) return;
        var msg = t.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    });

    // Auto-dismiss flash messages after 6 seconds.
    setTimeout(function () {
        document.querySelectorAll('.flash').forEach(function (el) {
            el.style.transition = 'opacity 300ms';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 350);
        });
    }, 6000);
})();
