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

    // ---- Module 4: source-type toggle on crew-edit.php ----
    // Show / hide the "source staff" select based on the source_type radio.
    function syncSourceStaffField() {
        var staffField = document.getElementById('source_staff_field');
        if (!staffField) return;
        var picked = document.querySelector('input[name="source_type"]:checked');
        var show = picked && picked.value === 'staff';
        staffField.classList.toggle('is-shown', show);
        // Keep the underlying select disabled when hidden so it isn't submitted.
        var sel = staffField.querySelector('select, input');
        if (sel) sel.disabled = !show;
    }
    document.addEventListener('change', function (ev) {
        if (ev.target.matches('input[name="source_type"]')) {
            syncSourceStaffField();
        }
    });
    document.addEventListener('DOMContentLoaded', syncSourceStaffField);
})();
