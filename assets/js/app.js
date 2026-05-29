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

    // ---- Module 9: travel sheet — colour the status select based on its
    // current value so the sheet reads at a glance. The CSS uses
    // .travel-status-{valid|pending|invalid} classes on the parent <td>.
    function paintTravelStatus(select) {
        var td = select.closest('td');
        if (!td) return;
        td.classList.remove(
            'travel-status-valid', 'travel-status-pending', 'travel-status-invalid'
        );
        td.classList.add('travel-status-' + (select.value || 'pending'));
    }
    function paintAllTravelStatuses() {
        document.querySelectorAll('select.travel-status-select').forEach(paintTravelStatus);
    }
    document.addEventListener('change', function (ev) {
        if (ev.target.matches('select.travel-status-select')) paintTravelStatus(ev.target);
    });
    document.addEventListener('DOMContentLoaded', paintAllTravelStatuses);

    // ---- Module 9: warn before "Clear" wipes unsaved row edits.
    // Reset is naturally form-scoped so this only fires for the travel form.
    document.addEventListener('reset', function (ev) {
        var form = ev.target;
        if (!form || form.id !== 'travel-form') return;
        if (!window.confirm(
            'Discard unsaved changes in the travel sheet and revert to last-saved values?'
        )) {
            ev.preventDefault();
        } else {
            // After the native reset settles, repaint the status colour cells.
            setTimeout(paintAllTravelStatuses, 0);
        }
    });
})();
