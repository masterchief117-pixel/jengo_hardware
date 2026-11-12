CREATE TABLE IF NOT EXISTS requests (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendant_id        INT UNSIGNED NOT NULL,
    request_text        TEXT NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_status        VARCHAR(50) NOT NULL DEFAULT 'Pending',
    admin_reply         TEXT NULL,
    hidden_by_attendant TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_requests_attendant_created (attendant_id, created_at),
    KEY idx_requests_status_created (admin_status, created_at),
    CONSTRAINT fk_requests_attendant
        FOREIGN KEY (attendant_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
