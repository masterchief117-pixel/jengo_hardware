<?php
/**
 * Shared writer for attendant approval requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/pos_sales.php';

const APPROVAL_SUCCESS_MESSAGE = 'Sent to Admin for Approval.';

/**
 * @return array<string, mixed>
 */
function approval_payload_from_request(array $source): array
{
    $raw = $source['payload'] ?? $source['action_payload'] ?? null;
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        throw new InvalidArgumentException('Request details must be a JSON object.');
    }
    if (strlen($raw) > 65535) {
        throw new InvalidArgumentException('Request details must be under 64 KB.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('Request details must be a JSON object.');
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $payload
 */
function assert_approval_payload(PDO $pdo, int $attendantId, string $actionType, array $payload): void
{
    if ($actionType === 'stock_adjustment') {
        $productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT);
        $newStock = filter_var($payload['new_stock'] ?? null, FILTER_VALIDATE_INT);
        $oldStock = filter_var($payload['old_stock'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($productId === false || $productId < 1 || $newStock === false || $newStock < 0 || $oldStock === false || $oldStock < 0) {
            throw new InvalidArgumentException('Enter the product and a physical count of zero or more.');
        }
        if ($newStock === $oldStock) {
            throw new InvalidArgumentException('The physical count matches the current stock.');
        }
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Enter a reason between 3 and 500 characters.');
        }
        $product = $pdo->prepare('SELECT stock_qty FROM products WHERE id = ? LIMIT 1');
        $product->execute([$productId]);
        $current = $product->fetchColumn();
        if ($current === false) {
            throw new InvalidArgumentException('That product was not found.');
        }
        if ((int) $current !== $oldStock) {
            throw new InvalidArgumentException('Stock changed while this form was open. Reload Inventory and try again.');
        }
        return;
    }

    if ($actionType === 'void_sale') {
        $saleId = filter_var($payload['sale_id'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($saleId === false || $saleId < 1 || mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Choose a sale and enter a reason.');
        }
        $sale = $pdo->prepare('SELECT attendant_id, status FROM sales WHERE id = ? LIMIT 1');
        $sale->execute([$saleId]);
        $row = $sale->fetch();
        if ($row === false || strcasecmp((string) $row['status'], 'completed') !== 0) {
            throw new InvalidArgumentException('Only a completed sale can be sent for void.');
        }
        if ((int) $row['attendant_id'] !== $attendantId && !has_role('admin')) {
            throw new InvalidArgumentException('You can only request a void for your own sale.');
        }
        $pending = $pdo->prepare(
            "SELECT action_payload FROM approval_requests
             WHERE action_type = 'void_sale' AND status = 'pending' AND attendant_id = ?"
        );
        $pending->execute([(int) $row['attendant_id']]);
        foreach ($pending->fetchAll() as $existing) {
            $existingPayload = json_decode((string) $existing['action_payload'], true);
            if (is_array($existingPayload) && (int) ($existingPayload['sale_id'] ?? 0) === $saleId) {
                throw new InvalidArgumentException('A void request for this sale is already pending.');
            }
        }
        return;
    }

    if ($actionType === 'high_discount') {
        $sale = normalize_sale_payload(
            $pdo,
            is_array($payload['items'] ?? null) ? $payload['items'] : [],
            (float) ($payload['discount_percent'] ?? 0),
            (string) ($payload['customer_name'] ?? ''),
            (string) ($payload['payment_method'] ?? 'cash')
        );
        if (!pos_sale_needs_approval($sale)) {
            throw new InvalidArgumentException('This discount is within your limit. Complete it from POS checkout.');
        }
        return;
    }

    if ($actionType === 'price_override') {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (trim((string) ($payload['customer_name'] ?? '')) === '' || $items === []) {
            throw new InvalidArgumentException('A price override needs a customer and at least one item.');
        }
        $belowCatalog = false;
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $product = $pdo->prepare('SELECT price FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
            $product->execute([$productId]);
            $price = $product->fetchColumn();
            if ($price === false) {
                throw new InvalidArgumentException('A quoted product is no longer available.');
            }
            if (money_cents($item['unit_price'] ?? $price) < money_cents($price)) {
                $belowCatalog = true;
            }
        }
        if (!$belowCatalog) {
            throw new InvalidArgumentException('Catalog prices can be saved as a quote without approval.');
        }
    }
}

/**
 * @param array<string, mixed> $payload
 */
function submit_approval_request(int $attendantId, string $actionType, array $payload): int
{
    $allowed = [
        'high_discount',
        'price_override',
        'void_sale',
        'stock_adjustment',
        'large_discount',
        'other_action',
    ];
    if (!in_array($actionType, $allowed, true)) {
        throw new InvalidArgumentException('Enter a valid action type.');
    }

    $pdo = db();
    ensure_pos_schema($pdo);
    if (in_array($actionType, ['high_discount', 'price_override', 'void_sale', 'stock_adjustment'], true)) {
        assert_approval_payload($pdo, $attendantId, $actionType, $payload);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare(
        'INSERT INTO approval_requests (attendant_id, action_type, action_payload, status)
         VALUES (?, ?, ?, \'pending\')'
    );
    $statement->execute([$attendantId, $actionType, $json]);

    return (int) $pdo->lastInsertId();
}
