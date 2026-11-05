(() => {
    const money = new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: 'KES',
        maximumFractionDigits: 2
    });

    function updateFulfillment() {
        const selected = document.querySelector('[data-fulfillment-option]:checked');
        const deliveryDetails = document.getElementById('delivery-details');
        const deliveryFee = document.getElementById('delivery-fee');
        const deliveryInputs = document.querySelectorAll('[data-delivery-required]');
        const isDelivery = selected?.value === 'delivery';

        deliveryDetails.classList.toggle('hidden', !isDelivery);
        deliveryInputs.forEach((input) => {
            input.disabled = !isDelivery;
            input.required = isDelivery;
        });
        deliveryFee.textContent = isDelivery ? 'Calculated after order' : 'Free pickup';
    }

    function updatePaymentMethod() {
        const selected = document.querySelector('[data-payment-option]:checked');
        const usePaystack = selected?.value === 'paystack';
        const mpesaDetails = document.getElementById('mpesa-details');
        const paystackDetails = document.getElementById('paystack-details');

        mpesaDetails.classList.toggle('hidden', usePaystack);
        paystackDetails.classList.toggle('hidden', !usePaystack);
        document.querySelectorAll('[data-payment-required]').forEach((input) => {
            const isActive = input.closest('#paystack-details') ? usePaystack : !usePaystack;
            input.disabled = !isActive;
            input.required = isActive;
        });
    }

    function renderSummary(items) {
        const list = document.getElementById('checkout-cart-items');
        const totalElement = document.querySelector('[data-checkout-total]');
        const subtotalElement = document.querySelector('[data-cart-subtotal]');
        const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0);

        if (items.length === 0) {
            list.innerHTML = '<p class="py-4 text-sm text-gray-500">Your cart is empty.</p>';
        } else {
            list.replaceChildren(...items.map((item) => {
                const row = document.createElement('div');
                row.className = 'flex items-start justify-between gap-4 py-3';

                const details = document.createElement('div');
                details.className = 'min-w-0';

                const name = document.createElement('p');
                name.className = 'truncate text-sm font-semibold text-gray-900';
                name.textContent = item.name;

                const quantity = document.createElement('p');
                quantity.className = 'mt-1 text-xs text-gray-500';
                quantity.textContent = `Qty ${item.quantity}`;

                const price = document.createElement('p');
                price.className = 'whitespace-nowrap text-sm font-semibold text-gray-900';
                price.textContent = money.format(item.price * item.quantity);

                details.append(name, quantity);
                row.append(details, price);
                return row;
            }));
        }

        subtotalElement.textContent = money.format(subtotal);
        subtotalElement.dataset.subtotal = String(subtotal);
        totalElement.textContent = money.format(subtotal);
    }

    function initialize() {
        const form = document.getElementById('checkout-form');
        if (!form) return;

        document.querySelectorAll('[data-fulfillment-option]').forEach((input) => {
            input.addEventListener('change', updateFulfillment);
        });
        document.querySelectorAll('[data-payment-option]').forEach((input) => {
            input.addEventListener('change', updatePaymentMethod);
        });

        updateFulfillment();
        updatePaymentMethod();

        const cart = window.JengoCart;
        if (!cart) {
            throw new Error('Checkout requires assets/js/cart.js to be loaded first.');
        }

        renderSummary(cart.getItems());
        document.addEventListener('jengo:cart-updated', (event) => {
            renderSummary(event.detail.items);
        });

        form.addEventListener('submit', (event) => {
            const items = cart.getItems();
            if (items.length === 0) {
                event.preventDefault();
                const feedback = document.getElementById('checkout-feedback');
                feedback.textContent = 'Add at least one product to your cart before checking out.';
                feedback.classList.remove('hidden');
                if (typeof window.showToast === 'function') {
                    window.showToast('Your cart is empty.', 'error');
                }
                return;
            }

            const button = document.getElementById('checkout-submit');
            button.disabled = true;
            document.getElementById('checkout-spinner').classList.remove('hidden');
            document.getElementById('checkout-submit-label').textContent = 'Initializing payment…';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();