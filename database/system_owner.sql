-- Add the owner role and maintenance-mode settings to an existing database.
-- This project identifies accounts by email; it does not have a username column.

ALTER TABLE users
    MODIFY role ENUM('customer', 'attendant', 'admin', 'owner')
    NOT NULL DEFAULT 'customer';

CREATE TABLE IF NOT EXISTS system_settings (
    id            TINYINT UNSIGNED NOT NULL,
    system_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_system_settings_singleton CHECK (id = 1),
    CONSTRAINT fk_system_settings_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (id, system_active) VALUES (1, 1);

-- Grant owner access to the confirmed account if it exists. Re-running this
-- statement after the account is created also safely assigns the role.
UPDATE users
SET role = 'owner'
WHERE email = 'david@jengohardware.co.ke';
