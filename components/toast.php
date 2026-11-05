<?php
$toastBasePath = isset($frontendBasePath) ? rtrim((string) $frontendBasePath, '/') : '';
?>
<div id="toast-container"
     class="fixed bottom-5 right-5 z-[100] flex w-[calc(100%-2.5rem)] max-w-sm flex-col gap-3"
     aria-live="polite"
     aria-relevant="additions">
</div>

<template id="toast-success-template">
    <div class="jengo-toast-slide-in flex items-start gap-3 rounded-lg border border-green-200 bg-white p-4 text-gray-800 shadow-lg">
        <span class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-green-100 font-bold text-green-700"
              aria-hidden="true">✓</span>
        <p class="min-w-0 flex-1 break-words text-sm font-medium" data-toast-message></p>
        <button type="button"
                class="ml-1 flex-shrink-0 text-lg leading-none text-gray-400 hover:text-gray-700"
                data-toast-dismiss
                aria-label="Dismiss notification">&times;</button>
    </div>
</template>

<template id="toast-error-template">
    <div class="jengo-toast-slide-in flex items-start gap-3 rounded-lg border border-red-200 bg-white p-4 text-gray-800 shadow-lg">
        <span class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-red-100 font-bold text-red-700"
              aria-hidden="true">!</span>
        <p class="min-w-0 flex-1 break-words text-sm font-medium" data-toast-message></p>
        <button type="button"
                class="ml-1 flex-shrink-0 text-lg leading-none text-gray-400 hover:text-gray-700"
                data-toast-dismiss
                aria-label="Dismiss notification">&times;</button>
    </div>
</template>

<script src="<?= htmlspecialchars($toastBasePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/toast.js" defer></script>