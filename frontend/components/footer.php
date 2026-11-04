<?php require __DIR__ . '/toast.php'; ?>
<footer class="mt-auto bg-gray-900 text-gray-300">
    <div class="mx-auto max-w-7xl px-4 py-6 text-center text-sm sm:px-6 lg:px-8">
        <p>&copy; <?= date('Y') ?> Jengo Hardware. All rights reserved.</p>
    </div>
</footer>
<script src="<?= htmlspecialchars($frontendBasePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/main.js" defer></script>
<script src="<?= htmlspecialchars($frontendBasePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/cart.js" defer></script>
<?php if (($pageScript ?? '') === 'checkout.js'): ?>
    <script src="<?= htmlspecialchars($frontendBasePath, ENT_QUOTES, 'UTF-8') ?>/assets/js/checkout.js" defer></script>
<?php endif; ?>
</body>
</html>