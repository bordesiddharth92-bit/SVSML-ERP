/**
 * SVSML-ERP — Client-side field validators.
 *
 * Mirrors includes/validators.php so the operator gets the exact same
 * pass / fail decisions whether they're typing into the form or
 * submitting it. The server is still authoritative — these checks
 * are purely about a fast, friendly typing experience.
 *
 * Auto-binding: any input or textarea with a data-validate attribute
 * is picked up automatically. Recognised values:
 *
 *   data-validate="passport"   passport number
 *   data-validate="mobile"     E.164 mobile
 *   data-validate="email"      email
 *   data-validate="cdc"        CDC number
 *   data-validate="indos"      INDOS number
 *
 * Optional attributes:
 *
 *   data-required="1"            field is required (overrides HTML5 'required')
 *   data-country-source="#ctry"  CSS selector to a country / nationality
 *                                input — passport rule applies the country-
 *                                specific format when present
 *   data-indos-required-source="#ctry"
 *                                INDOS becomes required when the country
 *                                input matches "India" / "IN" / "IND"
 *
 * Behaviour:
 *   - On `blur`: normalise the value (trim, uppercase, strip spaces, ...)
 *     IN PLACE so what the operator sees in the field is what we'll save.
 *   - On `input`: clear any previous inline error so the user isn't yelled
 *     at while still typing.
 *   - On `submit`: normalise + validate every data-validate field in the
 *     form. If anything fails, prevent submission, scroll the first
 *     invalid field into view, and focus it.
 */
(function () {
    'use strict';

    var COUNTRY_PASSPORT_FORMATS = {
        IN:    /^[A-Z][0-9]{7}$/,
        IND:   /^[A-Z][0-9]{7}$/,
        INDIA: /^[A-Z][0-9]{7}$/,
        US:                          /^[A-Z0-9]{6,9}$/,
        USA:                         /^[A-Z0-9]{6,9}$/,
        'UNITED STATES':             /^[A-Z0-9]{6,9}$/,
        'UNITED STATES OF AMERICA':  /^[A-Z0-9]{6,9}$/,
        GB:               /^[0-9]{9}$/,
        GBR:              /^[0-9]{9}$/,
        UK:               /^[0-9]{9}$/,
        'UNITED KINGDOM': /^[0-9]{9}$/,
        AE:                       /^[A-Z0-9]{8,9}$/,
        ARE:                      /^[A-Z0-9]{8,9}$/,
        'UNITED ARAB EMIRATES':   /^[A-Z0-9]{8,9}$/,
        PH:           /^[A-Z]{2}[0-9]{7}$/,
        PHL:          /^[A-Z]{2}[0-9]{7}$/,
        PHILIPPINES:  /^[A-Z]{2}[0-9]{7}$/
    };

    var INDIA_KEYS = { IN: 1, IND: 1, INDIA: 1 };

    function trim(v)             { return (v == null) ? '' : String(v).trim(); }
    function normalizeUpper(v)   { return trim(v).toUpperCase(); }
    function normalizeLower(v)   { return trim(v).toLowerCase(); }
    function normalizeMobile(v)  { return trim(v).replace(/[\s\-()]+/g, ''); }

    var rules = {
        passport: {
            normalize: normalizeUpper,
            requiredMsg: 'Passport number is required.',
            validate: function (v, opts) {
                if (!v) return null;
                // Relaxed universal pattern per spec: letters / digits /
                // hyphens / spaces, 5–20 chars. Country-specific patterns
                // are intentionally NOT consulted any more — they were
                // rejecting real-world passport numbers during onboarding.
                return /^[A-Z0-9\-\s]{5,20}$/i.test(v)
                    ? null
                    : 'Invalid passport number format.';
            }
        },
        mobile: {
            normalize: normalizeMobile,
            requiredMsg: 'Mobile number is required.',
            validate: function (v) {
                if (!v) return null;
                return /^\+[1-9]\d{5,14}$/.test(v)
                    ? null
                    : 'Please enter a valid mobile number.';
            }
        },
        email: {
            normalize: normalizeLower,
            requiredMsg: 'Email is required.',
            validate: function (v) {
                if (!v) return null;
                return /^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/.test(v)
                    ? null
                    : 'Please enter a valid email address.';
            }
        },
        cdc: {
            normalize: normalizeUpper,
            requiredMsg: 'CDC number is required.',
            validate: function (v) {
                if (!v) return null;
                return /^[A-Z0-9]{5,20}$/.test(v)
                    ? null
                    : 'Invalid CDC number format.';
            }
        },
        indos: {
            normalize: normalizeUpper,
            requiredMsg: 'INDOS number is required for Indian nationality.',
            validate: function (v) {
                if (!v) return null;
                return /^[0-9]{2}[A-Z]{2}[0-9]{4}$/.test(v)
                    ? null
                    : 'Please enter a valid INDOS number.';
            }
        }
    };

    function getRule(el) {
        var key = el.getAttribute('data-validate');
        return key ? rules[key] : null;
    }

    function readSourceValue(el, attr) {
        var sel = el.getAttribute(attr);
        if (!sel) return null;
        var src = document.querySelector(sel);
        return src ? src.value : null;
    }

    function fieldIsRequired(el) {
        if (el.hasAttribute('data-required')) {
            return el.getAttribute('data-required') === '1';
        }
        if (el.required) return true;
        // Conditional: INDOS becomes required when country-source = India.
        var key = el.getAttribute('data-validate');
        if (key === 'indos') {
            var country = readSourceValue(el, 'data-indos-required-source');
            if (country) {
                var k = String(country).trim().toUpperCase();
                if (INDIA_KEYS[k]) return true;
            }
        }
        return false;
    }

    function setError(el, msg) {
        // Find or create the inline error container next to this input.
        var holder = null;
        if (el.parentNode) {
            var siblings = el.parentNode.children;
            for (var i = 0; i < siblings.length; i++) {
                if (siblings[i].classList && siblings[i].classList.contains('field-error')) {
                    holder = siblings[i];
                    break;
                }
            }
        }
        if (msg) {
            if (!holder) {
                holder = document.createElement('div');
                holder.className = 'field-error';
                el.parentNode.appendChild(holder);
            }
            holder.textContent = msg;
            el.classList.add('has-error');
            el.setAttribute('aria-invalid', 'true');
        } else {
            if (holder && holder.parentNode) holder.parentNode.removeChild(holder);
            el.classList.remove('has-error');
            el.removeAttribute('aria-invalid');
        }
    }

    /**
     * Normalise + validate a single field. Returns the error string on
     * failure or null on success. The input value is overwritten with
     * the normalised version so what the user sees == what's submitted.
     */
    function checkField(el) {
        var rule = getRule(el);
        if (!rule) return null;

        var raw = el.value;
        var normalised = rule.normalize(raw);
        if (normalised !== raw) el.value = normalised;

        if (!normalised) {
            if (fieldIsRequired(el)) {
                setError(el, rule.requiredMsg);
                return rule.requiredMsg;
            }
            setError(el, null);
            return null;
        }

        var opts = {};
        if (el.getAttribute('data-country-source')) {
            opts.country = readSourceValue(el, 'data-country-source');
        }

        var err = rule.validate(normalised, opts);
        setError(el, err);
        return err;
    }

    // ---- Event wiring ------------------------------------------------

    // Use capture-phase blur because the 'blur' event doesn't bubble.
    document.addEventListener('blur', function (ev) {
        if (ev.target && ev.target.matches && ev.target.matches('[data-validate]')) {
            checkField(ev.target);
        }
    }, true);

    // Clear errors as the user types so we're not nagging mid-keystroke.
    document.addEventListener('input', function (ev) {
        if (ev.target && ev.target.matches && ev.target.matches('[data-validate]')) {
            setError(ev.target, null);
        }
    });

    // Block submit if any data-validate field fails.
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.querySelectorAll) return;
        var fields = form.querySelectorAll('[data-validate]');
        if (!fields.length) return;
        var firstInvalid = null;
        for (var i = 0; i < fields.length; i++) {
            var err = checkField(fields[i]);
            if (err && !firstInvalid) firstInvalid = fields[i];
        }
        if (firstInvalid) {
            ev.preventDefault();
            try { firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
            firstInvalid.focus();
        }
    });

    // Expose a tiny helper for pages that want to validate ad-hoc.
    window.SVSMLValidate = {
        check: checkField,
        rules: rules
    };
})();
