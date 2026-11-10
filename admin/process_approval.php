<?php
/**
 * Approve or reject a pending attendant request.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/pos_sales.php';

require_admin_login();

$base = rtrim((string) env('APP_URL', ''), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $base . '/admin/index.php');
    exit;
}

$csrfToken = (string) ($_SESSION['admin_approval_csrf'] ?? '');
$submittedToken = $_POST['csrf_token'] ?? '';
if ($csrfToken === '' || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    $_SESSION['flash_message'] = 'Your approval form expired. Reload and try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . $base . '/admin/index.php');
    exit;
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
$decision = $_POST['decision'] ?? '';
if ($requestId === false || $requestId === null || $requestId < 1 || !in_array($decision, ['approve', 'reject'], true)) {
    $_SESSION['flash_message'] = 'Invalid approval action.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . $base . '/admin/index.php');
    exit;
}

$pdo = db();

try {
    $tableExists = static function (PDO $pdo, string $tableName): bool {
        $check = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $check->execute([$tableName]);

        return (int) $check->fetchColumn() > 0;
    };

    /*
     * If these tables are missing, create them using the canonical definitions
     * in database/schema.sql (products) and database/attendant_pos.sql (sales):
     *
     * CREATE TABLE products (
     *   id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     *   category_id INT UNSIGNED NULL,
     *   name VARCHAR(200) NOT NULL,
     *   slug VARCHAR(220) NOT NULL,
     *   description TEXT NULL,
     *   sku VARCHAR(80) NULL,
     *   location VARCHAR(120) NULL DEFAULT 'Shop floor',
     *   price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     *   cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     *   stock_qty INT NOT NULL DEFAULT 0,
     *   low_stock_threshold INT NOT NULL DEFAULT 5,
     *   image_url VARCHAR(300) NULL,
     *   is_active TINYINT(1) NOT NULL DEFAULT 1,
     *   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     *   updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
     *     ON UPDATE CURRENT_TIMESTAMP,
     *   UNIQUE KEY uq_products_slug (slug),
     *   UNIQUE KEY uq_products_sku (sku),
     *   KEY fk_products_category (category_id),
     *   CONSTRAINT fk_products_category FOREIGN KEY (category_id)
     *     REFERENCES categories (id) ON DELETE SET NULL ON UPDATE CASCADE
     * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     *
     * CREATE TABLE sales (
     *   id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     *   sale_number VARCHAR(30) NOT NULL UNIQUE,
     *   attendant_id INT UNSIGNED NOT NULL,
     *   customer_name VARCHAR(120) NULL,
     *   subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     *   discount_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
     *   discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     *   total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
     *   payment_method ENUM('cash','mpesa','card') NOT NULL DEFAULT 'cash',
     *   status VARCHAR(20) NOT NULL DEFAULT 'completed',
     *   notes TEXT NULL,
     *   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     *   KEY idx_sales_attendant_date (attendant_id, created_at),
     *   CONSTRAINT fk_sales_attendant FOREIGN KEY (attendant_id)
     *     REFERENCES users (id) ON UPDATE CASCADE
     * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     */
    if (!$tableExists($pdo, 'products')) {
        throw new RuntimeException('Required products table is missing. Create it using the SQL documented above.');
    }

    ensure_pos_schema($pdo);
    if (!$tableExists($pdo, 'sales')) {
        throw new RuntimeException('Required sales table is missing. Create it using the SQL documented above.');
    }

    $pdo->beginTransaction();
    $request = $pdo->prepare(
        'SELECT id, attendant_id, action_type, action_payload, status
         FROM approval_requests
         WHERE id = ? AND status = \'pending\'
         FOR UPDATE'
    );
    $request->execute([$requestId]);
    $pending = $request->fetch();
    if ($pending === false) {
        $pdo->rollBack();
        $_SESSION['flash_message'] = 'That request was already reviewed or could not be found.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $base . '/admin/index.php');
        exit;
    }

    $detail = 'Approval request rejected.';
    $status = 'rejected';
    $receiptSaleId = null;
    if ($decision === 'approve') {
        $result = execute_approval_payload($pdo, $pending);
        $detail = 'Approved. ' . $result['message'];
        $receiptSaleId = $result['sale_id'];
        $status = 'approved';
    }

    $statement = $pdo->prepare(
        'UPDATE approval_requests
         SET status = ?, admin_id = ?, reviewed_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = \'pending\''
    );
    $statement->execute([$status, admin_user_id(), $requestId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('That request was already reviewed or could not be found.');
    }

    $pdo->commit();
    if ($receiptSaleId !== null) {
        header('Location: ' . $base . '/receipt.php?sale_id=' . $receiptSaleId . '&admin=1');
        exit;
    }

    $_SESSION['flash_message'] = $detail;
    $_SESSION['flash_type'] = 'success';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    die('Database Error: ' . $exception->getMessage() . ' on line ' . $exception->getLine());
}

header('Location: ' . $base . '/admin/index.php');
exit;
