(() => {
    const storageKey = 'jengoHardwareCart';
    const currencyFormatter = new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: 'KES',
        maximumFractionDigits: 2
    });

    function readCart() {
        let serialized;

        try {
            serialized = window.localStorage.getItem(storageKey);
        } catch (error) {
            console.error('Unable to access the browser cart storage.', error);
            return [];
        }

        if (serialized === null) return [];

        try {
            const parsed = JSON.parse(serialized);
            if (!Array.isArray(parsed)) {
                throw new TypeError('Stored cart data must be an array.');
            }

            return parsed.filter((item) =>
                item &&
                typeof item.id === 'string' &&
                typeof item.name === 'string' &&
                Number.isFinite(item.price) &&
                item.price >= 0 &&
                Number.isInteger(item.quantity) &&
                item.quantity > 0
            );
        } catch (error) {
            console.error('The saved cart data is invalid.', error);
            return [];
        }
    }

    function writeCart(items) {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(items));
        } catch (error) {
            console.error('Unable to save the browser cart.', error);
            if (typeof window.showToast === 'function') {
                window.showToast('Your cart could not be saved in this browser.', 'error');
            }
            return false;
        }

        return true;
    }

    function getCount(items = readCart()) {
        return items.reduce((count, item) => count + item.quantity, 0);
    }

    function getSubtotal(items = readCart()) {
        return items.reduce((subtotal, item) => subtotal + item.price * item.quantity, 0);
    }

    function render(items = readCart()) {
        const count = getCount(items);
        const subtotal = getSubtotal(items);

        document.querySelectorAll('[data-cart-count]').forEach((counter) => {
            counter.textContent = String(count);
            counter.setAttribute('aria-label', `${count} items in cart`);
        });

        document.querySelectorAll('[data-cart-subtotal]').forEach((element) => {
            element.textContent = currencyFormatter.format(subtotal);
            element.dataset.subtotal = String(subtotal);
        });

        document.dispatchEvent(new CustomEvent('jengo:cart-updated', {
            detail: { items, count, subtotal }
        }));
    }

    function addItem({ id, name, price, quantity = 1 }) {
        const normalizedId = String(id ?? '').trim();
        const normalizedName = String(name ?? '').trim();
        const numericPrice = Number(price);
        const numericQuantity = Number(quantity);

        if (!normalizedId || !normalizedName || !Number.isFinite(numericPrice) ||
            numericPrice < 0 || !Number.isInteger(numericQuantity) || numericQuantity < 1) {
            throw new TypeError('A cart item requires an id, name, non-negative price, and positive integer quantity.');
        }

        const items = readCart();
        const existingItem = items.find((item) => item.id === normalizedId);

        if (existingItem) {
            existingItem.quantity += numericQuantity;
            existingItem.name = normalizedName;
            existingItem.price = numericPrice;
        } else {
            items.push({
                id: normalizedId,
                name: normalizedName,
                price: numericPrice,
                quantity: numericQuantity
            });
        }

        if (!writeCart(items)) return false;

        render(items);
        if (typeof window.showToast === 'function') {
            window.showToast(`${normalizedName} added to cart.`, 'success');
        }
        return true;
    }

    function setQuantity(id, quantity) {
        const normalizedId = String(id ?? '');
        const numericQuantity = Number(quantity);
        const items = readCart();
        const item = items.find((cartItem) => cartItem.id === normalizedId);

        if (!item || !Number.isInteger(numericQuantity) || numericQuantity < 0) return false;

        const updatedItems = numericQuantity === 0
            ? items.filter((cartItem) => cartItem.id !== normalizedId)
            : items.map((cartItem) => cartItem.id === normalizedId
                ? { ...cartItem, quantity: numericQuantity }
                : cartItem);

        if (!writeCart(updatedItems)) return false;
        render(updatedItems);
        return true;
    }

    function removeItem(id) {
        const normalizedId = String(id ?? '');
        const items = readCart();
        const updatedItems = items.filter((item) => item.id !== normalizedId);

        if (updatedItems.length === items.length) return false;
        if (!writeCart(updatedItems)) return false;
        render(updatedItems);
        return true;
    }

    document.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-to-cart]');
        if (!addButton) return;

        event.preventDefault();
        addItem({
            id: addButton.dataset.productId,
            name: addButton.dataset.productName,
            price: addButton.dataset.productPrice,
            quantity: addButton.dataset.quantity || 1
        });
    });

    document.addEventListener('change', (event) => {
        const quantityInput = event.target.closest('[data-cart-quantity]');
        if (!quantityInput) return;

        setQuantity(quantityInput.dataset.productId, quantityInput.value);
    });

    document.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-cart-remove]');
        if (!removeButton) return;

        event.preventDefault();
        removeItem(removeButton.dataset.productId);
    });

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey || event.key === null) {
            render();
        }
    });

    window.JengoCart = Object.freeze({
        addItem,
        getItems: readCart,
        getCount,
        getSubtotal,
        setQuantity,
        removeItem,
        render
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => render(), { once: true });
    } else {
        render();
    }
})();