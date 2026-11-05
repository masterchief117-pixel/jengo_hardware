-- Employee and delivery tracking for existing Jengo Hardware databases.

CREATE TABLE IF NOT EXISTS employees (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name        VARCHAR(120) NOT NULL,
    job_title        VARCHAR(120) NOT NULL,
    monthly_salary   DECIMAL(12, 2) NOT NULL,
    date_of_birth    DATE NOT NULL,
    sex              ENUM('female', 'male', 'other', 'prefer_not_to_say') NOT NULL,
    phone            VARCHAR(40) NULL,
    email            VARCHAR(180) NULL,
    hired_at         DATE NOT NULL,
    employment_status ENUM('employed', 'terminated') NOT NULL DEFAULT 'employed',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employees_status_name (employment_status, full_name),
    KEY idx_employees_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_employment_events (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id    INT UNSIGNED NOT NULL,
    event_type     ENUM('hired', 'rehired', 'terminated') NOT NULL,
    event_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_user_id  INT UNSIGNED NULL,
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
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_reference     VARCHAR(80) NULL,
    recipient_name      VARCHAR(120) NOT NULL,
    recipient_phone     VARCHAR(40) NULL,
    destination         VARCHAR(500) NOT NULL,
    items_description   TEXT NOT NULL,
    scheduled_for       DATE NULL,
    notes               TEXT NULL,
    delivery_status     ENUM('pending', 'delivered') NOT NULL DEFAULT 'pending',
    created_by          INT UNSIGNED NULL,
    delivered_by        INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at        DATETIME NULL,
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
