(() => {
    const hideDelay = 3000;
    const templateIds = {
        success: 'toast-success-template',
        error: 'toast-error-template'
    };

    function showToast(message, type = 'success') {
        if (!Object.prototype.hasOwnProperty.call(templateIds, type)) {
            throw new TypeError('Toast type must be "success" or "error".');
        }

        const container = document.getElementById('toast-container');
        const template = document.getElementById(templateIds[type]);

        if (!container || !(template instanceof HTMLTemplateElement)) {
            throw new Error('Toast component is missing. Include components/toast.php before calling showToast().');
        }

        const toast = template.content.firstElementChild.cloneNode(true);
        const messageElement = toast.querySelector('[data-toast-message]');
        const dismissButton = toast.querySelector('[data-toast-dismiss]');

        messageElement.textContent = String(message ?? '');

        let timeoutId;
        const dismiss = () => {
            window.clearTimeout(timeoutId);
            toast.remove();
        };

        dismissButton.addEventListener('click', dismiss, { once: true });
        container.appendChild(toast);
        timeoutId = window.setTimeout(dismiss, hideDelay);
    }

    window.showToast = showToast;
})();