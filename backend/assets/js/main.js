/**
 * assets/js/main.js
 *
 * Jengo Hardware — frontend utilities (vanilla JS, no dependencies).
 *
 * Sections:
 *   1. Mobile menu toggle
 *   2. User dropdown toggle
 *   3. Kenyan phone number validation & formatter
 *   4. Flash message auto-dismiss
 *   5. Quantity stepper (cart +/- buttons)
 *   6. Form helpers (live validation, submit guard)
 *   7. Confirm-before-action (delete / cancel buttons)
 *   8. Page loader overlay
 *   9. Init — wires everything up on DOMContentLoaded
 */

'use strict';

/* ============================================================================
   1. MOBILE MENU TOGGLE
   ============================================================================
   Toggles #mobile-menu visibility when #mobile-menu-btn is clicked.
   Also updates aria-expanded and swaps the hamburger ↔ close icon.
   Closes the menu when the user clicks outside the navbar.
   ============================================================================ */

const MobileMenu = (() => {
    let btn, menu, nav;

    function open() {
        menu.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
        _swapIcon(true);
    }

    function close() {
        menu.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
        _swapIcon(false);
    }

    function toggle() {
        menu.classList.contains('hidden') ? open() : close();
    }

    // Swap hamburger ↔ X icon by rewriting the SVG path
    function _swapIcon(isOpen) {
        const icon = btn.querySelector('svg path');
        if (!icon) return;
        icon.setAttribute(
            'd',
            isOpen
                ? 'M6 18L18 6M6 6l12 12'   // X
                : 'M4 6h16M4 12h16M4 18h16' // Hamburger
        );
    }

    function init() {
        btn  = document.getElementById('mobile-menu-btn');
        menu = document.getElementById('mobile-menu');
        nav  = document.querySelector('nav[aria-label="Main navigation"]');

        if (!btn || !menu) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggle();
        });

        // Close when clicking outside the navbar
        document.addEventListener('click', (e) => {
            if (nav && !nav.contains(e.target)) {
                close();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') close();
        });
    }

    return { init, open, close };
})();


/* ============================================================================
   2. USER DROPDOWN TOGGLE
   ============================================================================
   Handles the desktop user dropdown (#user-menu / #user-menu-btn).
   Closes on outside click and Escape key.
   ============================================================================ */

const UserDropdown = (() => {
    let btn, menu;

    function open() {
        menu.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
    }

    function close() {
        menu.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
    }

    function toggle() {
        menu.classList.contains('hidden') ? open() : close();
    }

    function init() {
        btn  = document.getElementById('user-menu-btn');
        menu = document.getElementById('user-menu');

        if (!btn || !menu) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggle();
        });

        document.addEventListener('click', (e) => {
            if (!btn.contains(e.target) && !menu.contains(e.target)) {
                close();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') close();
        });
    }

    return { init, close };
})();


/* ============================================================================
   3. KENYAN PHONE NUMBER VALIDATION & FORMATTER
   ============================================================================
   Accepted input formats (all normalise to 254XXXXXXXXX):
     07XXXXXXXX    → 2547XXXXXXXX
     01XXXXXXXX    → 2541XXXXXXXX
     +254XXXXXXXXX → 254XXXXXXXXX
     254XXXXXXXXX  → 254XXXXXXXXX  (already normalised)

   Kenyan mobile prefixes covered (as of 2024):
     Safaricom : 0700–0729, 0740–0742, 0745, 0748, 0757–0759,
                 0768–0769, 0790–0799, 0110–0119, 0100–0109
     Airtel    : 0730–0739, 0750–0756, 0780–0789, 0100–0109
     Telkom    : 0770–0779

   The regex validates prefix + exactly 9 digits after the country code.
   ============================================================================ */

const Phone = (() => {

    // After normalisation the number must match 254 + valid prefix + 6 more digits
    const KENYA_REGEX = /^254(7[0-9]{8}|1[0-1][0-9]{7})$/;

    /**
     * Normalise a raw phone string to E.164-ish 254XXXXXXXXX.
     * Returns null if the input cannot be normalised.
     *
     * @param  {string} raw
     * @returns {string|null}
     */
    function normalise(raw) {
        if (typeof raw !== 'string') return null;

        // Strip spaces, dashes, parentheses
        let n = raw.replace(/[\s\-().]/g, '');

        if (n.startsWith('+254')) {
            n = '254' + n.slice(4);
        } else if (n.startsWith('254') && n.length === 12) {
            // Already in target format
        } else if (n.startsWith('0') && n.length === 10) {
            n = '254' + n.slice(1);
        } else {
            return null; // Unrecognised format
        }

        return n;
    }

    /**
     * Validate a phone number.
     * Returns { valid: true, normalised: '254...' } on success.
     * Returns { valid: false, error: 'message' } on failure.
     *
     * @param  {string} raw
     * @returns {{ valid: boolean, normalised?: string, error?: string }}
     */
    function validate(raw) {
        const n = normalise(raw);

        if (!n) {
            return {
                valid: false,
                error: 'Enter a valid Kenyan number (e.g. 0712 345 678 or +254712345678).',
            };
        }

        if (!KENYA_REGEX.test(n)) {
            return {
                valid: false,
                error: 'Number not recognised as a valid Kenyan mobile number.',
            };
        }

        return { valid: true, normalised: n };
    }

    /**
     * Format a normalised 254XXXXXXXXX string for display.
     * e.g. "254712345678" → "+254 712 345 678"
     *
     * @param  {string} normalised
     * @returns {string}
     */
    function format(normalised) {
        if (!normalised || normalised.length !== 12) return normalised;
        return `+${normalised.slice(0, 3)} ${normalised.slice(3, 6)} ${normalised.slice(6, 9)} ${normalised.slice(9)}`;
    }

    /**
     * Wire live validation to every input with data-phone-input attribute.
     *
     * HTML usage:
     *   <input type="tel" name="phone" data-phone-input
     *          data-error-id="phone-error" />
     *   <span id="phone-error" class="form-error-msg hidden"></span>
     */
    function bindLiveValidation() {
        document.querySelectorAll('[data-phone-input]').forEach((input) => {
            const errorId  = input.dataset.errorId;
            const errorEl  = errorId ? document.getElementById(errorId) : null;

            function showError(msg) {
                input.classList.add('is-error');
                input.classList.remove('is-valid');
                if (errorEl) {
                    errorEl.textContent = msg;
                    errorEl.classList.remove('hidden');
                }
            }

            function clearError() {
                input.classList.remove('is-error');
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.classList.add('hidden');
                }
            }

            function markValid() {
                input.classList.remove('is-error');
                input.classList.add('is-valid');
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.classList.add('hidden');
                }
            }

            input.addEventListener('input', () => {
                if (input.value.trim() === '') {
                    clearError();
                    return;
                }
                const result = validate(input.value.trim());
                result.valid ? markValid() : showError(result.error);
            });

            // On blur: normalise value in place if valid
            input.addEventListener('blur', () => {
                const result = validate(input.value.trim());
                if (result.valid) {
                    input.value = result.normalised; // Store 254XXXXXXXXX
                    markValid();
                } else if (input.value.trim() !== '') {
                    showError(result.error);
                }
            });
        });
    }

    return { validate, normalise, format, bindLiveValidation };
})();


/* ============================================================================
   4. FLASH MESSAGE AUTO-DISMISS
   ============================================================================
   Any element with data-flash-dismiss="SECONDS" fades out after that delay.
   ============================================================================ */

function initFlashAutoDismiss() {
    document.querySelectorAll('[role="alert"][data-flash-dismiss]').forEach((el) => {
        const delay = parseInt(el.dataset.flashDismiss, 10) || 5;

        setTimeout(() => {
            el.style.transition = 'opacity 500ms ease, max-height 400ms ease';
            el.style.opacity    = '0';
            el.style.maxHeight  = '0';
            el.style.overflow   = 'hidden';
            setTimeout(() => el.remove(), 500);
        }, delay * 1000);
    });
}


/* ============================================================================
   5. QUANTITY STEPPER
   ============================================================================
   Works with .qty-stepper elements generated by header/cart templates.

   HTML structure expected:
     <div class="qty-stepper">
       <button type="button" data-action="decrease" aria-label="Decrease quantity">−</button>
       <input type="number" name="qty" value="1" min="1" max="99" />
       <button type="button" data-action="increase" aria-label="Increase quantity">+</button>
     </div>
   ============================================================================ */

function initQtySteppers() {
    document.querySelectorAll('.qty-stepper').forEach((stepper) => {
        const input    = stepper.querySelector('input[type="number"]');
        const decBtn   = stepper.querySelector('[data-action="decrease"]');
        const incBtn   = stepper.querySelector('[data-action="increase"]');

        if (!input) return;

        const min = parseInt(input.min, 10) || 1;
        const max = parseInt(input.max, 10) || 99;

        function updateButtons(val) {
            if (decBtn) decBtn.disabled = val <= min;
            if (incBtn) incBtn.disabled = val >= max;
        }

        if (decBtn) {
            decBtn.addEventListener('click', () => {
                let val = parseInt(input.value, 10) || min;
                if (val > min) {
                    input.value = val - 1;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                updateButtons(parseInt(input.value, 10));
            });
        }

        if (incBtn) {
            incBtn.addEventListener('click', () => {
                let val = parseInt(input.value, 10) || min;
                if (val < max) {
                    input.value = val + 1;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                updateButtons(parseInt(input.value, 10));
            });
        }

        input.addEventListener('change', () => {
            let val = parseInt(input.value, 10);
            if (isNaN(val) || val < min) val = min;
            if (val > max) val = max;
            input.value = val;
            updateButtons(val);
        });

        // Initial state
        updateButtons(parseInt(input.value, 10) || min);
    });
}


/* ============================================================================
   6. FORM HELPERS
   ============================================================================
   a) Prevent double-submission by disabling the submit button after click.
   b) Show a loading spinner on the button while the form submits.

   Add data-submit-guard to any <form> to enable this behaviour.
   ============================================================================ */

function initFormGuards() {
    document.querySelectorAll('form[data-submit-guard]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            const btn = form.querySelector('[type="submit"]');
            if (!btn) return;

            // Basic HTML5 validity check — let browser report errors first
            if (!form.checkValidity()) return;

            const originalText = btn.innerHTML;

            btn.disabled   = true;
            btn.innerHTML  = `<span class="spinner" aria-hidden="true"></span>
                              <span>${btn.dataset.loadingText || 'Please wait…'}</span>`;

            // Safety net: re-enable after 15 s in case the page doesn't redirect
            setTimeout(() => {
                btn.disabled  = false;
                btn.innerHTML = originalText;
            }, 15_000);
        });
    });
}


/* ============================================================================
   7. CONFIRM-BEFORE-ACTION
   ============================================================================
   Any link or button with data-confirm="Your message here" will show a
   native confirm dialog before proceeding.

   HTML usage:
     <a href="/admin/products.php?delete=5"
        data-confirm="Delete this product? This cannot be undone.">Delete</a>
   ============================================================================ */

function initConfirmActions() {
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-confirm]');
        if (!el) return;

        const message = el.dataset.confirm || 'Are you sure?';
        if (!window.confirm(message)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    });
}


/* ============================================================================
   8. PAGE LOADER OVERLAY
   ============================================================================
   Show / hide a full-screen loading overlay.

   Usage from other scripts:
     PageLoader.show();
     PageLoader.hide();
   ============================================================================ */

const PageLoader = (() => {
    let overlay = null;

    function _getOrCreate() {
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className  = 'page-loader';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-label', 'Loading, please wait');
            overlay.innerHTML  = '<div class="spinner spinner-lg"></div>';
            document.body.appendChild(overlay);
        }
        return overlay;
    }

    function show() {
        _getOrCreate().style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function hide() {
        const el = _getOrCreate();
        el.style.display             = 'none';
        document.body.style.overflow = '';
    }

    return { show, hide };
})();


/* ============================================================================
   9. CART BADGE UPDATER
   ============================================================================
   Updates the cart item count badge in the navbar without a page reload.
   Call CartBadge.update(count) after an AJAX add-to-cart response.

   Usage:
     CartBadge.update(3);   // sets badge to 3
     CartBadge.update(0);   // hides badge
   ============================================================================ */

const CartBadge = (() => {
    function update(count) {
        document.querySelectorAll('[data-cart-badge]').forEach((badge) => {
            if (count > 0) {
                badge.textContent = count > 9 ? '9+' : String(count);
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        });
    }

    return { update };
})();


/* ============================================================================
   10. UTILITY HELPERS
   ============================================================================ */

/**
 * Format a number as Kenyan Shillings.
 * e.g. formatKES(1234.5) → "KES 1,234.50"
 *
 * @param  {number} amount
 * @returns {string}
 */
function formatKES(amount) {
    return new Intl.NumberFormat('en-KE', {
        style:    'currency',
        currency: 'KES',
        minimumFractionDigits: 2,
    }).format(amount);
}

/**
 * Debounce a function — delays execution until after `wait` ms have passed
 * since the last call. Useful for search inputs.
 *
 * @param  {Function} fn
 * @param  {number}   wait  Milliseconds
 * @returns {Function}
 */
function debounce(fn, wait = 300) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), wait);
    };
}

/**
 * Escape HTML special characters to prevent XSS when inserting
 * user-supplied strings into the DOM via innerHTML.
 *
 * @param  {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#039;');
}


/* ============================================================================
   INIT — wire everything up once the DOM is ready
   ============================================================================ */

document.addEventListener('DOMContentLoaded', () => {
    MobileMenu.init();
    UserDropdown.init();
    Phone.bindLiveValidation();
    initFlashAutoDismiss();
    initQtySteppers();
    initFormGuards();
    initConfirmActions();
});

/* Expose public APIs for use by page-specific scripts */
window.Jengo = {
    MobileMenu,
    UserDropdown,
    Phone,
    PageLoader,
    CartBadge,
    formatKES,
    debounce,
    escapeHtml,
};
