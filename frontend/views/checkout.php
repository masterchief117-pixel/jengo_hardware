<?php
declare(strict_types=1);

$pageTitle = 'Checkout';
$pageScript = 'checkout.js';
require __DIR__ . '/../components/header.php';
?>

<main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <header class="mb-8">
        <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Almost there</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Checkout</h1>
        <p class="mt-3 text-gray-600">Confirm how you would like to receive and pay for your order.</p>
    </header>

    <form id="checkout-form" action="checkout.php" method="post" class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <section class="space-y-6" aria-labelledby="checkout-details-heading">
            <h2 id="checkout-details-heading" class="sr-only">Checkout details</h2>

            <fieldset class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <legend class="px-1 text-lg font-bold text-gray-900">How would you like to receive your order?</legend>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                        <input type="radio" name="fulfillment" value="delivery" checked
                               class="mt-1 h-4 w-4 accent-orange-600 focus:ring-2 focus:ring-[#ff6600]/30"
                               data-fulfillment-option>
                        <span>
                            <span class="block font-semibold text-gray-900">Delivery</span>
                            <span class="mt-1 block text-sm text-gray-600">Deliver the order to your address.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                        <input type="radio" name="fulfillment" value="pickup"
                               class="mt-1 h-4 w-4 accent-orange-600 focus:ring-2 focus:ring-[#ff6600]/30"
                               data-fulfillment-option>
                        <span>
                            <span class="block font-semibold text-gray-900">Pickup</span>
                            <span class="mt-1 block text-sm text-gray-600">Pick up your order from the store.</span>
                        </span>
                    </label>
                </div>

                <div id="delivery-details" class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="delivery-address" class="mb-2 block text-sm font-semibold text-gray-700">Delivery address</label>
                        <input id="delivery-address" name="delivery_address" type="text" autocomplete="street-address"
                               required data-delivery-required
                               class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                               placeholder="Street address, building, or area">
                    </div>
                    <div>
                        <label for="delivery-town" class="mb-2 block text-sm font-semibold text-gray-700">Town / city</label>
                        <input id="delivery-town" name="delivery_town" type="text" autocomplete="address-level2"
                               required data-delivery-required
                               class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                               placeholder="e.g. Nairobi">
                    </div>
                    <div>
                        <label for="delivery-phone" class="mb-2 block text-sm font-semibold text-gray-700">Contact phone</label>
                        <input id="delivery-phone" name="delivery_phone" type="tel" autocomplete="tel" inputmode="tel"
                               required data-delivery-required data-format-phone
                               pattern="\+254 [17][0-9]{2} [0-9]{3} [0-9]{3}"
                               title="Enter a Kenyan mobile number in this format: +254 712 345 678"
                               class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                               placeholder="+254 712 345 678">
                    </div>
                </div>
            </fieldset>

            <fieldset class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <legend class="px-1 text-lg font-bold text-gray-900">Payment method</legend>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                        <input type="radio" name="payment_method" value="mpesa" checked
                               class="mt-1 h-4 w-4 accent-orange-600 focus:ring-2 focus:ring-[#ff6600]/30"
                               data-payment-option>
                        <span>
                            <span class="block font-semibold text-gray-900">M-Pesa</span>
                            <span class="mt-1 block text-sm text-gray-600">Pay securely using your mobile money account.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                        <input type="radio" name="payment_method" value="paystack"
                               class="mt-1 h-4 w-4 accent-orange-600 focus:ring-2 focus:ring-[#ff6600]/30"
                               data-payment-option>
                        <span>
                            <span class="block font-semibold text-gray-900">Paystack</span>
                            <span class="mt-1 block text-sm text-gray-600">Pay by card or another supported method.</span>
                        </span>
                    </label>
                </div>

                <div id="mpesa-details" class="mt-5">
                    <label for="mpesa-phone" class="mb-2 block text-sm font-semibold text-gray-700">M-Pesa phone number</label>
                    <input id="mpesa-phone" name="mpesa_phone" type="tel" autocomplete="tel" inputmode="tel"
                           required data-payment-required data-format-phone
                           pattern="\+254 [17][0-9]{2} [0-9]{3} [0-9]{3}"
                           title="Enter a Kenyan mobile number in this format: +254 712 345 678"
                           class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                           placeholder="+254 712 345 678">
                    <p class="mt-1.5 text-xs text-gray-500">A payment prompt will be sent to this number when payment is enabled.</p>
                </div>
                <div id="paystack-details" class="mt-5 hidden">
                    <label for="paystack-email" class="mb-2 block text-sm font-semibold text-gray-700">Payment email</label>
                    <input id="paystack-email" name="paystack_email" type="email" autocomplete="email"
                           data-payment-required disabled
                           class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                           placeholder="you@example.com">
                    <p class="mt-1.5 text-xs text-gray-500">Use an email address to receive your payment confirmation.</p>
                </div>
            </fieldset>

            <div id="checkout-feedback" class="hidden rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                 role="alert"></div>
        </section>

        <aside class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6 lg:sticky lg:top-6"
               aria-labelledby="order-summary-heading">
            <h2 id="order-summary-heading" class="text-lg font-bold text-gray-900">Cart summary</h2>
            <div id="checkout-cart-items" class="mt-5 divide-y divide-gray-100">
                <p class="py-4 text-sm text-gray-500">Loading your cart…</p>
            </div>

            <dl class="mt-5 space-y-3 border-t border-gray-200 pt-4 text-sm">
                <div class="flex justify-between gap-4 text-gray-600">
                    <dt>Subtotal</dt>
                    <dd data-cart-subtotal class="font-semibold text-gray-900">KES 0.00</dd>
                </div>
                <div class="flex justify-between gap-4 text-gray-600">
                    <dt>Delivery</dt>
                    <dd id="delivery-fee" class="font-semibold text-gray-900">Calculated after order</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-gray-200 pt-3 text-base font-bold text-gray-900">
                    <dt>Total</dt>
                    <dd data-checkout-total>KES 0.00</dd>
                </div>
            </dl>

            <button id="checkout-submit" type="submit"
                    class="jengo-hover-transition mt-6 inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-3.5 font-bold text-white hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-[#ff6600] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70"
                    style="background-color: var(--jengo-safety-orange);">
                <svg id="checkout-spinner" class="hidden h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span id="checkout-submit-label">Place order</span>
            </button>
            <p class="mt-3 text-center text-xs leading-5 text-gray-500">Payment processing will begin after your order is submitted.</p>
        </aside>
    </form>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>