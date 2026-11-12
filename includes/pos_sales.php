<?php
/**
 * In-store sales, quotes, and the stock changes an admin approval can apply.
 */

declare(strict_types=1);

const POS_MAX_DISCOUNT_PERCENT = 5.0;
const POS_MAX_DISCOUNT_AMOUNT = 1000.0;

function ensure_pos_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $location = $pdo->query("SHOW COLUMNS FROM products LIKE 'location'")->fetch();
    if ($location === false) {
        $pdo->exec(
            "ALTER TABLE products
             ADD COLUMN location VARCHAR(120) NULL DEFAULT 'Shop floor' AFTER sku"
        );
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(120) NOT NULL,
            phone        VARCHAR(40) NOT NULL,
            company_name VARCHAR(160) NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_customers_name (name),
            KEY idx_customers_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sales (
            id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            sale_number      VARCHAR(30)    NOT NULL,
            attendant_id     INT UNSIGNED   NOT NULL,
            customer_id      INT UNSIGNED       NULL,
            customer_name    VARCHAR(120)       NULL,
            subtotal         DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            discount_percent DECIMAL(6, 2)  NOT NULL DEFAULT 0.00,
            discount_amount  DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            total_amount     DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            payment_method   ENUM('cash', 'mpesa', 'card') NOT NULL DEFAULT 'cash',
            status           VARCHAR(20)    NOT NULL DEFAULT 'completed',
            cancelled_reason VARCHAR(255)       NULL,
            notes            TEXT               NULL,
            created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sales_number (sale_number),
            KEY idx_sales_attendant_date (attendant_id, created_at),
            KEY idx_sales_customer (customer_id),
            CONSTRAINT fk_sales_attendant
                FOREIGN KEY (attendant_id) REFERENCES users (id)
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $customerColumn = $pdo->query("SHOW COLUMNS FROM sales LIKE 'customer_id'")->fetch();
    if ($customerColumn === false) {
        $pdo->exec('ALTER TABLE sales ADD COLUMN customer_id INT UNSIGNED NULL AFTER attendant_id');
    }

    $cancelledReasonColumn = $pdo->query("SHOW COLUMNS FROM sales LIKE 'cancelled_reason'")->fetch();
    if ($cancelledReasonColumn === false) {
        $pdo->exec('ALTER TABLE sales ADD COLUMN cancelled_reason VARCHAR(255) NULL AFTER status');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sale_items (
            id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            sale_id       INT UNSIGNED   NOT NULL,
            product_id    INT UNSIGNED       NULL,
            product_name  VARCHAR(200)   NOT NULL,
            sku           VARCHAR(80)        NULL,
            catalog_price DECIMAL(12, 2) NOT NULL,
            unit_price    DECIMAL(12, 2) NOT NULL,
            quantity      INT            NOT NULL,
            line_total    DECIMAL(12, 2) NOT NULL,
            PRIMARY KEY (id),
            KEY fk_sale_items_sale (sale_id),
            KEY fk_sale_items_product (product_id),
            CONSTRAINT fk_sale_items_sale
                FOREIGN KEY (sale_id) REFERENCES sales (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_sale_items_product
                FOREIGN KEY (product_id) REFERENCES products (id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS pos_payments (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sale_id               INT UNSIGNED NOT NULL,
            payment_method        ENUM('mpesa') NOT NULL DEFAULT 'mpesa',
            amount                DECIMAL(12, 2) NOT NULL,
            phone_number          VARCHAR(20) NOT NULL,
            status                ENUM('pending', 'success', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
            merchant_request_id   VARCHAR(100) NULL,
            checkout_request_id   VARCHAR(100) NULL,
            gateway_receipt       VARCHAR(100) NULL,
            gateway_response      TEXT NULL,
            created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pos_payments_checkout (checkout_request_id),
            KEY idx_pos_payments_sale_status (sale_id, status),
            CONSTRAINT fk_pos_payments_sale
                FOREIGN KEY (sale_id) REFERENCES sales (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quotes (
            id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            quote_number   VARCHAR(30)    NOT NULL,
            attendant_id   INT UNSIGNED   NOT NULL,
            customer_id    INT UNSIGNED       NULL,
            customer_name  VARCHAR(120)   NOT NULL,
            customer_phone VARCHAR(40)        NULL,
            notes          TEXT               NULL,
            subtotal       DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            status         ENUM('draft', 'pending_approval', 'accepted', 'expired')
                                          NOT NULL DEFAULT 'draft',
            created_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quotes_number (quote_number),
            KEY fk_quotes_attendant (attendant_id),
            KEY fk_quotes_customer (customer_id),
            CONSTRAINT fk_quotes_attendant
                FOREIGN KEY (attendant_id) REFERENCES users (id)
                ON UPDATE CASCADE,
            CONSTRAINT fk_quotes_customer
                FOREIGN KEY (customer_id) REFERENCES users (id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS quote_items (
            id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            quote_id      INT UNSIGNED   NOT NULL,
            product_id    INT UNSIGNED       NULL,
            product_name  VARCHAR(200)   NOT NULL,
            catalog_price DECIMAL(12, 2) NOT NULL,
            unit_price    DECIMAL(12, 2) NOT NULL,
            quantity      INT            NOT NULL,
            line_total    DECIMAL(12, 2) NOT NULL,
            PRIMARY KEY (id),
            KEY fk_quote_items_quote (quote_id),
            CONSTRAINT fk_quote_items_quote
                FOREIGN KEY (quote_id) REFERENCES quotes (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function money_cents(float|int|string $amount): int
{
    return (int) round(((float) $amount) * 100);
}

function pos_reference(string $prefix): string
{
    return $prefix . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function format_pos_mpesa_phone(string $phone): ?string
{
    $phone = preg_replace('/[\s().-]/', '', trim($phone)) ?? '';
    if (str_starts_with($phone, '+254')) {
        $phone = substr($phone, 1);
    } elseif (str_starts_with($phone, '0') && strlen($phone) === 10) {
        $phone = '254' . substr($phone, 1);
    }

    return preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $phone) === 1
        ? $phone
        : null;
}

/**
 * A checkout needs admin approval when the discount is above 5% or KES 1,000,
 * or when any unit price was typed in below the shelf price.
 *
 * @param array<string, mixed> $sale
 */
function pos_sale_needs_approval(array $sale): bool
{
    if (!empty($sale['price_overridden'])) {
        return true;
    }

    return (float) $sale['discount_percent'] > POS_MAX_DISCOUNT_PERCENT
        || (float) $sale['discount_amount'] > POS_MAX_DISCOUNT_AMOUNT;
}

/**
 * Rebuild a cart from current catalog prices and the prices the attendant entered.
 *
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function normalize_sale_payload(
    PDO $pdo,
    array $items,
    float $discountPercent,
    string $customerName,
    string $paymentMethod,
    ?int $customerId = null
): array
{
    if ($items === []) {
        throw new InvalidArgumentException('Add at least one product before checkout.');
    }
    if ($discountPercent < 0 || $discountPercent > 100) {
        throw new InvalidArgumentException('Discount must be between 0 and 100 percent.');
    }
    if (!in_array($paymentMethod, ['cash', 'mpesa', 'card'], true)) {
        throw new InvalidArgumentException('Choose cash, M-Pesa, or card.');
    }
    if ($customerId !== null) {
        $customerStatement = $pdo->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
        $customerStatement->execute([$customerId]);
        $registeredCustomerName = $customerStatement->fetchColumn();
        if ($registeredCustomerName === false) {
            throw new InvalidArgumentException('Select a valid customer or choose Walk-in Customer.');
        }
        if (trim($customerName) === '') {
            $customerName = (string) $registeredCustomerName;
        }
    }

    $normalized = [];
    $subtotalCents = 0;
    $priceOverridden = false;

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        if ($productId < 1 || $quantity < 1 || $quantity > 10000) {
            throw new InvalidArgumentException('Each line needs a product and a quantity of at least 1.');
        }

        $statement = $pdo->prepare(
            'SELECT id, name, sku, price, stock_qty, is_active
             FROM products WHERE id = ? LIMIT 1'
        );
        $statement->execute([$productId]);
        $product = $statement->fetch();
        if ($product === false || (int) $product['is_active'] !== 1) {
            throw new InvalidArgumentException('A product in the cart is no longer available.');
        }
        if ((int) $product['stock_qty'] < $quantity) {
            throw new InvalidArgumentException($product['name'] . ' does not have enough stock.');
        }

        $catalogCents = money_cents($product['price']);
        $unitCents = array_key_exists('unit_price', $item)
            ? money_cents($item['unit_price'])
            : $catalogCents;
        if ($unitCents < 0) {
            throw new InvalidArgumentException('Unit price cannot be negative.');
        }
        if ($unitCents < $catalogCents) {
            $priceOverridden = true;
        }

        $lineCents = $unitCents * $quantity;
        $subtotalCents += $lineCents;
        $normalized[] = [
            'product_id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'sku' => (string) ($product['sku'] ?? ''),
            'catalog_price' => $catalogCents / 100,
            'unit_price' => $unitCents / 100,
            'quantity' => $quantity,
            'line_total' => $lineCents / 100,
        ];
    }

    $discountCents = (int) round($subtotalCents * $discountPercent / 100);
    if ($discountCents > $subtotalCents) {
        $discountCents = $subtotalCents;
    }

    return [
        'customer_id' => $customerId,
        'customer_name' => mb_substr(trim($customerName), 0, 120),
        'payment_method' => $paymentMethod,
        'discount_percent' => round($discountPercent, 2),
        'discount_amount' => $discountCents / 100,
        'subtotal' => $subtotalCents / 100,
        'total' => ($subtotalCents - $discountCents) / 100,
        'price_overridden' => $priceOverridden,
        'items' => $normalized,
    ];
}

/**
 * Insert a completed POS sale and reduce shelf stock.
 *
 * @param array<string, mixed> $sale
 * @return array{id: int, sale_number: string}
 */
function record_pos_sale(PDO $pdo, int $attendantId, array $sale): array
{
    $saleStatus = (string) ($sale['status'] ?? 'completed');
    if (!in_array($saleStatus, ['pending', 'completed'], true)) {
        throw new InvalidArgumentException('Unsupported POS sale status.');
    }

    $sale = normalize_sale_payload(
        $pdo,
        is_array($sale['items'] ?? null) ? $sale['items'] : [],
        (float) ($sale['discount_percent'] ?? 0),
        (string) ($sale['customer_name'] ?? ''),
        (string) ($sale['payment_method'] ?? 'cash'),
        isset($sale['customer_id']) && (int) $sale['customer_id'] > 0
            ? (int) $sale['customer_id']
            : null
    );

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $saleNumber = pos_reference('POS-');
        $insert = $pdo->prepare(
            'INSERT INTO sales
                (sale_number, attendant_id, customer_id, customer_name, subtotal, discount_percent,
                 discount_amount, total_amount, payment_method, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $saleNumber,
            $attendantId,
            $sale['customer_id'],
            $sale['customer_name'] !== '' ? $sale['customer_name'] : null,
            $sale['subtotal'],
            $sale['discount_percent'],
            $sale['discount_amount'],
            $sale['total'],
            $sale['payment_method'],
            $saleStatus,
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare(
            'INSERT INTO sale_items
                (sale_id, product_id, product_name, sku, catalog_price, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stockUpdate = $pdo->prepare(
            'UPDATE products
             SET stock_qty = stock_qty - ?
             WHERE id = ? AND stock_qty >= ? AND is_active = 1'
        );

        foreach ($sale['items'] as $item) {
            $itemInsert->execute([
                $saleId,
                $item['product_id'],
                $item['name'],
                $item['sku'] !== '' ? $item['sku'] : null,
                $item['catalog_price'],
                $item['unit_price'],
                $item['quantity'],
                $item['line_total'],
            ]);
            $stockUpdate->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new RuntimeException($item['name'] . ' does not have enough stock to complete this sale.');
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['id' => $saleId, 'sale_number' => $saleNumber];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Cancel an awaiting M-Pesa POS sale and release its reserved stock exactly once.
 *
 * @return array{cancelled: bool, message: string}
 */
function cancel_pos_sale_payment(
    PDO $pdo,
    int $saleId,
    int $attendantId,
    string $reason
): array {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $saleStatement = $pdo->prepare(
            'SELECT id, attendant_id, payment_method, status
             FROM sales
             WHERE id = ?
             FOR UPDATE'
        );
        $saleStatement->execute([$saleId]);
        $sale = $saleStatement->fetch();
        if ($sale === false || (int) $sale['attendant_id'] !== $attendantId) {
            throw new RuntimeException('Pending payment was not found.');
        }
        if ((string) $sale['payment_method'] !== 'mpesa') {
            throw new RuntimeException('Only an M-Pesa payment can be cancelled here.');
        }
        if ((string) $sale['status'] !== 'pending') {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'cancelled' => false,
                'message' => (string) $sale['status'] === 'cancelled'
                    ? 'Payment was already cancelled.'
                    : 'Payment is no longer pending.',
            ];
        }

        $itemsStatement = $pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity
             FROM sale_items
             WHERE sale_id = ? AND product_id IS NOT NULL
             GROUP BY product_id'
        );
        $itemsStatement->execute([$saleId]);
        $restoreStock = $pdo->prepare(
            'UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?'
        );
        foreach ($itemsStatement->fetchAll() as $item) {
            $restoreStock->execute([(int) $item['quantity'], (int) $item['product_id']]);
        }

        $cancelSale = $pdo->prepare(
            "UPDATE sales
             SET status = 'cancelled', cancelled_reason = ?
             WHERE id = ? AND status = 'pending'"
        );
        $cancelSale->execute([mb_substr($reason, 0, 255), $saleId]);
        if ($cancelSale->rowCount() !== 1) {
            throw new RuntimeException('Payment state changed before it could be cancelled.');
        }

        $pdo->prepare(
            "UPDATE pos_payments
             SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
             WHERE sale_id = ? AND status = 'pending'"
        )->execute([$saleId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['cancelled' => true, 'message' => 'Payment terminated safely.'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @param array<string, mixed> $payload
 */
function apply_stock_adjustment(PDO $pdo, array $payload): string
{
    $productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT);
    $newStock = filter_var($payload['new_stock'] ?? null, FILTER_VALIDATE_INT);
    $oldStock = filter_var($payload['old_stock'] ?? null, FILTER_VALIDATE_INT);
    if ($productId === false || $productId < 1 || $newStock === false || $newStock < 0 || $oldStock === false || $oldStock < 0) {
        throw new InvalidArgumentException('Stock adjustment is missing a product or a valid count.');
    }

    $statement = $pdo->prepare(
        'UPDATE products SET stock_qty = ? WHERE id = ? AND stock_qty = ?'
    );
    $statement->execute([$newStock, $productId, $oldStock]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Stock changed after this request was submitted. Ask the attendant to recount it.');
    }

    return 'Stock updated to ' . $newStock . '.';
}

/**
 * @param array<string, mixed> $payload
 */
function void_pos_sale(PDO $pdo, array $payload): string
{
    $saleId = filter_var($payload['sale_id'] ?? null, FILTER_VALIDATE_INT);
    if ($saleId === false || $saleId < 1) {
        throw new InvalidArgumentException('Void request is missing a sale.');
    }

    $saleStatement = $pdo->prepare('SELECT id, sale_number, status FROM sales WHERE id = ? LIMIT 1');
    $saleStatement->execute([$saleId]);
    $sale = $saleStatement->fetch();
    if ($sale === false) {
        throw new RuntimeException('That sale no longer exists.');
    }
    if (strcasecmp((string) $sale['status'], 'completed') !== 0) {
        throw new RuntimeException('Only a completed sale can be voided.');
    }

    $items = $pdo->prepare('SELECT product_id, quantity FROM sale_items WHERE sale_id = ?');
    $items->execute([$saleId]);
    $restore = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
    foreach ($items->fetchAll() as $item) {
        if ($item['product_id'] !== null) {
            $restore->execute([(int) $item['quantity'], (int) $item['product_id']]);
        }
    }

    $void = $pdo->prepare("UPDATE sales SET status = 'Voided' WHERE id = ? AND status = 'completed'");
    $void->execute([$saleId]);
    if ($void->rowCount() !== 1) {
        throw new RuntimeException('The sale could not be voided.');
    }

    return 'Sale ' . $sale['sale_number'] . ' marked Voided.';
}

/**
 * @param array<string, mixed> $payload
 */
function save_quote_from_payload(PDO $pdo, int $attendantId, array $payload, string $status): string
{
    $customerName = trim((string) ($payload['customer_name'] ?? ''));
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    if ($customerName === '' || $items === []) {
        throw new InvalidArgumentException('A quote needs a customer and at least one item.');
    }
    if (!in_array($status, ['draft', 'accepted'], true)) {
        throw new InvalidArgumentException('Unsupported quote status.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $normalized = [];
        $subtotalCents = 0;
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $productStatement = $pdo->prepare(
                'SELECT id, name, price, is_active FROM products WHERE id = ? LIMIT 1'
            );
            $productStatement->execute([$productId]);
            $product = $productStatement->fetch();
            if ($product === false || (int) $product['is_active'] !== 1 || $quantity < 1) {
                throw new InvalidArgumentException('Quote contains an invalid product line.');
            }
            $unitCents = money_cents($item['unit_price'] ?? $product['price']);
            if ($unitCents < 0) {
                throw new InvalidArgumentException('Quote price cannot be negative.');
            }
            $lineCents = $unitCents * $quantity;
            $subtotalCents += $lineCents;
            $normalized[] = [
                'product_id' => (int) $product['id'],
                'name' => (string) $product['name'],
                'catalog_price' => money_cents($product['price']) / 100,
                'unit_price' => $unitCents / 100,
                'quantity' => $quantity,
                'line_total' => $lineCents / 100,
            ];
        }

        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $pdo->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'customer' LIMIT 1"
            );
            $customer->execute([$customerId]);
            if ($customer->fetchColumn() === false) {
                $customerId = 0;
            }
        }
        $quoteNumber = pos_reference('QT-');
        $insert = $pdo->prepare(
            'INSERT INTO quotes
                (quote_number, attendant_id, customer_id, customer_name, customer_phone, notes, subtotal, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $phone = trim((string) ($payload['customer_phone'] ?? ''));
        $insert->execute([
            $quoteNumber,
            $attendantId,
            $customerId > 0 ? $customerId : null,
            mb_substr($customerName, 0, 120),
            $phone !== '' ? mb_substr($phone, 0, 40) : null,
            trim((string) ($payload['notes'] ?? '')) ?: null,
            $subtotalCents / 100,
            $status,
        ]);
        $quoteId = (int) $pdo->lastInsertId();
        $itemInsert = $pdo->prepare(
            'INSERT INTO quote_items
                (quote_id, product_id, product_name, catalog_price, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($normalized as $item) {
            $itemInsert->execute([
                $quoteId,
                $item['product_id'],
                $item['name'],
                $item['catalog_price'],
                $item['unit_price'],
                $item['quantity'],
                $item['line_total'],
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $quoteNumber;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Carry out an approved attendant request. Unknown types are recorded only.
 *
 * @param array<string, mixed> $request
 * @return array{message: string, sale_id: int|null}
 */
function execute_approval_payload(PDO $pdo, array $request): array
{
    ensure_pos_schema($pdo);
    $payload = json_decode((string) ($request['action_payload'] ?? ''), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Approval payload is not valid JSON.');
    }

    $attendantId = (int) $request['attendant_id'];
    $actionType = (string) $request['action_type'];

    switch ($actionType) {
        case 'stock_adjustment':
            return ['message' => apply_stock_adjustment($pdo, $payload), 'sale_id' => null];
        case 'high_discount':
            $sale = record_pos_sale($pdo, $attendantId, $payload);
            return [
                'message' => 'Sale ' . $sale['sale_number'] . ' completed.',
                'sale_id' => $sale['id'],
            ];
        case 'void_sale':
            return ['message' => void_pos_sale($pdo, $payload), 'sale_id' => null];
        case 'price_override':
            return [
                'message' => 'Quote ' . save_quote_from_payload($pdo, $attendantId, $payload, 'accepted') . ' saved.',
                'sale_id' => null,
            ];
        default:
            return [
                'message' => 'Request approved. No automatic store action is tied to this type.',
                'sale_id' => null,
            ];
    }
}
