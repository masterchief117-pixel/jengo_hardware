-- Run once on databases where payroll_records.sql has already been applied.
ALTER TABLE employees
    ADD COLUMN mpesa_phone_number VARCHAR(15) NULL AFTER phone;

ALTER TABLE payroll_records
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Initiating',
    ADD COLUMN mpesa_phone_number VARCHAR(15) NULL,
    ADD COLUMN conversation_id VARCHAR(64) NULL,
    ADD COLUMN originator_conversation_id VARCHAR(64) NULL,
    ADD COLUMN mpesa_receipt VARCHAR(32) NULL,
    ADD COLUMN result_code VARCHAR(20) NULL,
    ADD COLUMN result_description VARCHAR(255) NULL,
    ADD KEY idx_payroll_conversation (conversation_id),
    ADD KEY idx_payroll_originator_conversation (originator_conversation_id);

-- Older payroll entries only recorded a clicked button, not a Daraja transfer.
UPDATE payroll_records
SET status = 'Legacy Record'
WHERE status = 'Paid' AND mpesa_receipt IS NULL;
