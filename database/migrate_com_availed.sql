-- Run this on existing installs to add COM tracking to leave_ledger
USE office_db;

-- 1. Add com_availed column (whole numbers only)
ALTER TABLE leave_ledger
    ADD COLUMN com_availed DECIMAL(6,0) DEFAULT 0
    AFTER fl_availed;

-- 2. Redefine hpl_close to include 2× COM deduction
ALTER TABLE leave_ledger
    MODIFY COLUMN hpl_close DECIMAL(6,2)
    GENERATED ALWAYS AS (hpl_open + hpl_credit - hpl_availed - (com_availed * 2)) STORED;
