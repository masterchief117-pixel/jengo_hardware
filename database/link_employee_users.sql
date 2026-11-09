-- Apply once to an existing installation with the employees table.
ALTER TABLE employees
    ADD COLUMN user_id INT UNSIGNED NULL AFTER email,
    ADD UNIQUE KEY uq_employees_user_id (user_id),
    ADD CONSTRAINT fk_employees_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
