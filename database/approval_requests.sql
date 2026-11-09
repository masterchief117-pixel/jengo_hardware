-- Apply once to an existing installation after users and admin_users exist.
CREATE TABLE IF NOT EXISTS approval_requests (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendant_id    INT UNSIGNED NOT NULL,
    action_type     VARCHAR(80) NOT NULL,
    action_payload  JSON NOT NULL,
    status          ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_id        INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at     TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_approval_requests_status_date (status, created_at),
    KEY idx_approval_requests_attendant_date (attendant_id, created_at),
    CONSTRAINT fk_approval_requests_attendant
        FOREIGN KEY (attendant_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_approval_requests_admin
        FOREIGN KEY (admin_id) REFERENCES admin_users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
