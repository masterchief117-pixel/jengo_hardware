(() => {
    function initializeMobileMenu() {
        const toggle = document.getElementById('mobile-menu-toggle');
        const menu = document.getElementById('mobile-menu');

        if (!toggle || !menu) return;

        toggle.addEventListener('click', () => {
            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!isExpanded));
            toggle.setAttribute('aria-label', isExpanded ? 'Open navigation menu' : 'Close navigation menu');
            menu.classList.toggle('hidden', isExpanded);
        });
    }

    function formatKenyanPhoneNumber(value) {
        let digits = value.replace(/\D/g, '');

        if (digits.startsWith('00')) {
            digits = digits.slice(2);
        }

        if (digits.startsWith('254')) {
            digits = digits.slice(3);
        }

        if (digits.startsWith('0')) {
            digits = digits.slice(1);
        }

        digits = digits.slice(0, 9);

        if (!digits) return '';

        const firstGroup = digits.slice(0, 3);
        const secondGroup = digits.slice(3, 6);
        const thirdGroup = digits.slice(6, 9);

        return [
            '+254',
            firstGroup,
            secondGroup,
            thirdGroup
        ].filter(Boolean).join(' ');
    }

    function initializePhoneInputs() {
        document.querySelectorAll('input[type="tel"], input[name="phone"], input[data-format-phone]')
            .forEach((input) => {
                input.addEventListener('input', () => {
                    input.value = formatKenyanPhoneNumber(input.value);
                });

                if (input.value) {
                    input.value = formatKenyanPhoneNumber(input.value);
                }
            });
    }

    function initialize() {
        initializeMobileMenu();
        initializePhoneInputs();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();