CREATE TABLE IF NOT EXISTS payroll_records (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id               INT UNSIGNED NOT NULL,
    amount_paid               DECIMAL(12, 2) NOT NULL,
    payment_date              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status                    VARCHAR(50) NOT NULL DEFAULT 'Initiating',
    mpesa_phone_number        VARCHAR(15) NULL,
    conversation_id           VARCHAR(64) NULL,
    originator_conversation_id VARCHAR(64) NULL,
    mpesa_receipt             VARCHAR(32) NULL,
    result_code               VARCHAR(20) NULL,
    result_description        VARCHAR(255) NULL,

    PRIMARY KEY (id),
    KEY idx_payroll_employee_date (employee_id, payment_date),
    KEY idx_payroll_conversation (conversation_id),
    KEY idx_payroll_originator_conversation (originator_conversation_id),
    CONSTRAINT fk_payroll_records_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
