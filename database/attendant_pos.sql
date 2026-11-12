-- In-store POS, quotes, and shelf location for an existing Jengo database.
-- Safe to run more than once.

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS location VARCHAR(120) NULL DEFAULT 'Shop floor' AFTER sku;

CREATE TABLE IF NOT EXISTS customers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    phone        VARCHAR(40) NOT NULL,
    company_name VARCHAR(160) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customers_name (name),
    KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales (
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
    notes            TEXT               NULL,
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_number (sale_number),
    KEY idx_sales_attendant_date (attendant_id, created_at),
    KEY idx_sales_customer (customer_id),
    CONSTRAINT fk_sales_attendant
        FOREIGN KEY (attendant_id) REFERENCES users (id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS customer_id INT UNSIGNED NULL AFTER attendant_id;

ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS cancelled_reason VARCHAR(255) NULL AFTER status;

CREATE TABLE IF NOT EXISTS pos_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
