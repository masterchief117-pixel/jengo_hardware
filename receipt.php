<?php
/**
 * Printable receipt for a completed in-store sale.
 */

declare(strict_types=1);

$adminPortal = ($_GET['admin'] ?? '') === '1';
if ($adminPortal) {
    require_once __DIR__ . '/admin/auth.php';
    require_admin_login();
} else {
    require_once __DIR__ . '/includes/auth.php';
    require_attendant();
}

$saleId = filter_var($_GET['sale_id'] ?? null, FILTER_VALIDATE_INT);
if ($saleId === false || $saleId === null || $saleId < 1) {
    http_response_code(400);
    exit('A valid sale ID is required.');
}

$pdo = db();
$saleStatement = $pdo->prepare(
    "SELECT sales.id, sales.sale_number, sales.customer_name, sales.subtotal,
            sales.discount_amount, sales.total_amount, sales.payment_method,
            sales.status, sales.created_at, users.full_name AS attendant_name,
            customers.name AS registered_customer_name
     FROM sales
     LEFT JOIN users ON users.id = sales.attendant_id
     LEFT JOIN customers ON customers.id = sales.customer_id
     WHERE sales.id = ? AND sales.status = 'completed'
     LIMIT 1"
);
$saleStatement->execute([$saleId]);
$sale = $saleStatement->fetch();
if ($sale === false) {
    http_response_code(404);
    exit('Completed sale not found.');
}

$itemsStatement = $pdo->prepare(
    'SELECT product_name, quantity, unit_price, line_total
     FROM sale_items
     WHERE sale_id = ?
     ORDER BY id ASC'
);
$itemsStatement->execute([$saleId]);
$items = $itemsStatement->fetchAll();

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$base = rtrim((string) env('APP_URL', ''), '/');
$returnUrl = $adminPortal
    ? $base . '/admin/index.php'
    : $base . '/attendant/pos.php';
$returnLabel = $adminPortal ? 'Back to Approvals' : 'Back to POS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= $escape((string) $sale['sale_number']) ?> - Jengo Hardware</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, sans-serif;
        }
        .receipt-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .receipt-actions a,
        .receipt-actions button {
            border: 0;
            border-radius: 6px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .print-button { background: #b45309; color: #fff; }
        .back-button { background: #e5e7eb; color: #111827; }
        #receipt-content {
            width: 80mm;
            max-width: 100%;
            margin: 0 auto;
            padding: 5mm;
            background: #fff;
            font: 12px/1.45 "Courier New", monospace;
        }
        .center { text-align: center; }
        .receipt-title { margin: 0; font-size: 18px; }
        .muted { color: #4b5563; }
        .rule { border: 0; border-top: 1px dashed #111; margin: 10px 0; }
        .meta { margin: 2px 0; overflow-wrap: anywhere; }
        .item { margin: 8px 0; }
        .item-name { font-weight: 700; overflow-wrap: anywhere; }
        .item-total,
        .totals-row { display: flex; justify-content: space-between; gap: 8px; }
        .totals-row { margin: 3px 0; }
        .grand-total { font-size: 15px; font-weight: 700; }
        .thank-you { margin: 12px 0 0; }
        @media print {
            @page { size: 80mm auto; margin: 0; }
            body * { visibility: hidden; }
            #receipt-content, #receipt-content * { visibility: visible; }
            #receipt-content {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm;
                max-width: none;
                margin: 0;
                padding: 4mm;
                font-family: monospace;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="receipt-actions no-print">
        <button type="button" class="print-button btn btn-primary no-print" onclick="window.print()">Print Receipt</button>
        <a class="back-button" href="<?= $escape($returnUrl) ?>"><?= $escape($returnLabel) ?></a>
    </div>

    <main id="receipt-content">
        <header class="center">
            <h1 class="receipt-title">JENGO HARDWARE</h1>
            <div class="muted">SALES RECEIPT</div>
        </header>
        <hr class="rule">

        <p class="meta">Receipt: <?= $escape((string) $sale['sale_number']) ?></p>
        <p class="meta">Date: <?= $escape(date('d M Y H:i', strtotime((string) $sale['created_at']))) ?></p>
        <p class="meta">Attendant: <?= $escape((string) ($sale['attendant_name'] ?? 'Staff')) ?></p>
        <p class="meta">Status: <?= $escape(ucfirst((string) $sale['status'])) ?></p>
        <?php $receiptCustomerName = $sale['registered_customer_name'] ?: $sale['customer_name']; ?>
        <?php if (!empty($receiptCustomerName)): ?>
            <p class="meta">Customer: <?= $escape((string) $receiptCustomerName) ?></p>
        <?php endif; ?>

        <hr class="rule">
        <?php foreach ($items as $item): ?>
            <section class="item">
                <div class="item-name"><?= $escape((string) $item['product_name']) ?></div>
                <div class="item-total">
                    <span><?= (int) $item['quantity'] ?> x <?= number_format((float) $item['unit_price'], 2) ?></span>
                    <span><?= number_format((float) $item['line_total'], 2) ?></span>
                </div>
            </section>
        <?php endforeach; ?>

        <hr class="rule">
        <div class="totals-row">
            <span>Subtotal</span>
            <span>KES <?= number_format((float) $sale['subtotal'], 2) ?></span>
        </div>
        <?php if ((float) $sale['discount_amount'] > 0): ?>
            <div class="totals-row">
                <span>Discount</span>
                <span>- KES <?= number_format((float) $sale['discount_amount'], 2) ?></span>
            </div>
        <?php endif; ?>
        <div class="totals-row grand-total">
            <span>TOTAL</span>
            <span>KES <?= number_format((float) $sale['total_amount'], 2) ?></span>
        </div>
        <div class="totals-row">
            <span>Payment</span>
            <span><?= $escape(strtoupper((string) $sale['payment_method'])) ?></span>
        </div>
        <hr class="rule">
        <p class="center thank-you">Thank you for shopping with us!</p>
        <p class="center muted">Goods sold are subject to store policy.</p>
    </main>
</body>
</html>
