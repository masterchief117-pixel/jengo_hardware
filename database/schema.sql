-- =============================================================================
-- JENGO HARDWARE — DATABASE SCHEMA
-- Engine : InnoDB (foreign key support)
-- Charset: utf8mb4 (full Unicode)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS jengo_hardware
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE jengo_hardware;

-- =============================================================================
-- TABLE: users
-- Stores all system users: customers, attendants, and admins.
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(120)      NOT NULL,
    email         VARCHAR(180)      NOT NULL,
    phone         VARCHAR(20)       NOT NULL,                 -- Used for M-Pesa STK Push
    password_hash VARCHAR(255)      NOT NULL,
    role          ENUM('customer', 'attendant', 'admin')
                                    NOT NULL DEFAULT 'customer',
    is_active     TINYINT(1)        NOT NULL DEFAULT 1,       -- 0 = deactivated by admin
    created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: admin_users
-- Separate identities and credentials for the independent admin portal.
-- =============================================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(180) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLES: employee and delivery operations
-- =============================================================================
CREATE TABLE IF NOT EXISTS employees (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name         VARCHAR(120) NOT NULL,
    job_title         VARCHAR(120) NOT NULL,
    monthly_salary    DECIMAL(12, 2) NOT NULL,
    date_of_birth     DATE NOT NULL,
    sex               ENUM('female', 'male', 'other', 'prefer_not_to_say') NOT NULL,
    phone             VARCHAR(40) NULL,
    email             VARCHAR(180) NULL,
    hired_at          DATE NOT NULL,
    employment_status ENUM('employed', 'terminated') NOT NULL DEFAULT 'employed',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employees_status_name (employment_status, full_name),
    KEY idx_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_employment_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id   INT UNSIGNED NOT NULL,
    event_type    ENUM('hired', 'rehired', 'terminated') NOT NULL,
    event_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_user_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_employee_events_employee_date (employee_id, event_at),
    CONSTRAINT fk_employee_events_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_employee_events_admin
        FOREIGN KEY (admin_user_id) REFERENCES admin_users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deliveries (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_reference   VARCHAR(80) NULL,
    recipient_name    VARCHAR(120) NOT NULL,
    recipient_phone   VARCHAR(40) NULL,
    destination       VARCHAR(500) NOT NULL,
    items_description TEXT NOT NULL,
    scheduled_for     DATE NULL,
    notes             TEXT NULL,
    delivery_status   ENUM('pending', 'delivered') NOT NULL DEFAULT 'pending',
    created_by        INT UNSIGNED NULL,
    delivered_by      INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at      DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_deliveries_status_date (delivery_status, scheduled_for),
    KEY idx_deliveries_created_date (created_at),
    CONSTRAINT fk_deliveries_created_by
        FOREIGN KEY (created_by) REFERENCES admin_users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_deliveries_delivered_by
        FOREIGN KEY (delivered_by) REFERENCES admin_users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- TABLE: categories
-- Product categories (e.g. Hand Tools, Power Tools, Plumbing, Electrical).
-- =============================================================================
CREATE TABLE IF NOT EXISTS categories (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name       VARCHAR(100)  NOT NULL,
    slug       VARCHAR(110)  NOT NULL,
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- TABLE: products
-- Hardware items available for sale, with stock and pricing.
-- =============================================================================
CREATE TABLE IF NOT EXISTS products (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    category_id   INT UNSIGNED          NULL DEFAULT NULL,
    name          VARCHAR(200)      NOT NULL,
    slug          VARCHAR(220)      NOT NULL,
    description   TEXT                  NULL,
    sku           VARCHAR(80)           NULL,                 -- Stock Keeping Unit / barcode
    price         DECIMAL(12, 2)    NOT NULL DEFAULT 0.00,    -- Selling price (KES)
    cost_price    DECIMAL(12, 2)    NOT NULL DEFAULT 0.00,    -- Purchase / cost price (KES)
    stock_qty     INT               NOT NULL DEFAULT 0,       -- Current units in stock
    low_stock_threshold INT         NOT NULL DEFAULT 5,       -- Alert when stock falls to this
    image_url     VARCHAR(300)          NULL,
    is_active     TINYINT(1)        NOT NULL DEFAULT 1,       -- 0 = hidden from shop
    created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    UNIQUE KEY uq_products_sku  (sku),
    KEY fk_products_category (category_id),

    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- TABLE: orders
-- A single transaction header — one row per purchase session.
-- =============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    customer_id     INT UNSIGNED      NULL DEFAULT NULL,      -- NULL for walk-in (attendant)
    attendant_id    INT UNSIGNED      NULL DEFAULT NULL,      -- Set when processed in-store
    order_number    VARCHAR(30)   NOT NULL,                   -- Human-readable e.g. ORD-20260001
    status          ENUM(
                        'pending',      -- Cart / not yet paid
                        'processing',   -- Payment initiated
                        'paid',         -- Payment confirmed
                        'completed',    -- Order fulfilled / dispatched
                        'cancelled',    -- Cancelled by customer or admin
                        'refunded'      -- Payment reversed
                    )             NOT NULL DEFAULT 'pending',
    total_amount    DECIMAL(12, 2) NOT NULL DEFAULT 0.00,     -- Sum of all order_items
    notes           TEXT              NULL,                   -- Customer or attendant notes
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (order_number),
    KEY fk_orders_customer  (customer_id),
    KEY fk_orders_attendant (attendant_id),

    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT fk_orders_attendant
        FOREIGN KEY (attendant_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- TABLE: order_items
-- Individual line items inside an order.
-- =============================================================================
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED   NOT NULL,
    product_id  INT UNSIGNED       NULL DEFAULT NULL,         -- NULL if product later deleted
    product_name VARCHAR(200)  NOT NULL,                      -- Snapshot at time of purchase
    unit_price  DECIMAL(12, 2) NOT NULL,                      -- Price at time of purchase
    quantity    INT            NOT NULL DEFAULT 1,
    subtotal    DECIMAL(12, 2) NOT NULL,                      -- unit_price × quantity

    PRIMARY KEY (id),
    KEY fk_order_items_order   (order_id),
    KEY fk_order_items_product (product_id),

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- TABLE: payments
-- One payment record per payment attempt (an order may have multiple attempts).
-- =============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    order_id            INT UNSIGNED  NOT NULL,
    user_id             INT UNSIGNED      NULL DEFAULT NULL,  -- Who triggered the payment
    payment_method      ENUM('mpesa', 'paystack', 'cash')
                                      NOT NULL,
    amount              DECIMAL(12, 2) NOT NULL,
    currency            CHAR(3)       NOT NULL DEFAULT 'KES',

    -- Status reflects the gateway response lifecycle
    status              ENUM(
                            'pending',   -- Initiated, awaiting callback
                            'success',   -- Confirmed by gateway
                            'failed',    -- Gateway returned failure
                            'cancelled', -- User cancelled on gateway
                            'refunded'   -- Payment reversed
                        )             NOT NULL DEFAULT 'pending',

    -- Gateway reference fields (populated from callback / verify response)
    gateway_reference   VARCHAR(100)      NULL,               -- M-Pesa MpesaReceiptNumber / Paystack reference
    gateway_transaction_id VARCHAR(100)   NULL,               -- Internal gateway tx ID
    gateway_response    TEXT              NULL,               -- Raw JSON response (for debugging)
    phone_number        VARCHAR(20)       NULL,               -- Phone used for M-Pesa push

    paid_at             TIMESTAMP         NULL DEFAULT NULL,  -- Set when status → success
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                               ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY fk_payments_order (order_id),
    KEY fk_payments_user  (user_id),
    KEY idx_payments_gateway_ref (gateway_reference),

    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- SEED DATA — Default admin account
-- Password: Admin@1234  (bcrypt hash — change immediately after first login)
-- =============================================================================
INSERT INTO users (full_name, email, phone, password_hash, role) VALUES
(
    'System Admin',
    'admin@jengohardware.co.ke',
    '0700000000',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.',  -- Admin@1234
    'admin'
);

-- Sample categories
INSERT INTO categories (name, slug) VALUES
    ('Hand Tools',    'hand-tools'),
    ('Power Tools',   'power-tools'),
    ('Plumbing',      'plumbing'),
    ('Electrical',    'electrical'),
    ('Building Materials', 'building-materials'),
    ('Safety & PPE',  'safety-ppe');

-- Sample products
INSERT INTO products (category_id, name, slug, description, sku, price, cost_price, stock_qty) VALUES
    (1, 'Claw Hammer 16oz',       'claw-hammer-16oz',      'Steel head with fiberglass handle', 'HT-001', 450.00,  300.00, 50),
    (1, 'Measuring Tape 5m',      'measuring-tape-5m',     'Heavy-duty retractable tape',       'HT-002', 350.00,  220.00, 80),
    (2, 'Angle Grinder 4.5"',     'angle-grinder-4-5',     '850W with disc guard',              'PT-001', 3200.00, 2100.00, 15),
    (3, 'PVC Pipe 1/2" (1m)',     'pvc-pipe-half-inch-1m', 'Pressure-rated water supply pipe',  'PL-001', 120.00,  75.00, 200),
    (4, 'MCB Circuit Breaker 32A','mcb-32a',               'Single pole, DIN rail mount',       'EL-001', 580.00,  380.00, 40),
    (5, 'Portland Cement 50kg',   'portland-cement-50kg',  'High-strength OPC cement',          'BM-001', 720.00,  580.00, 100),
    (6, 'Safety Helmet',          'safety-helmet',         'HDPE, adjustable ratchet',          'PP-001', 650.00,  400.00, 30);
