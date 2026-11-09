-- Apply once to an existing employees table to support roster-only account creation.
ALTER TABLE employees
    MODIFY COLUMN date_of_birth DATE NULL,
    MODIFY COLUMN sex ENUM('female', 'male', 'other', 'prefer_not_to_say') NULL;
