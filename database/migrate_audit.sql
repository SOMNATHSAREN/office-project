USE office_db;

-- Audit log: tracks every write action across the system
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    action      VARCHAR(50)  NOT NULL,   -- upload | edit | supp_add | supp_apply | supp_cancel | rectify | ledger_credit | lock | unlock
    sail_perno  VARCHAR(20)  NOT NULL DEFAULT '',
    yyyymm      CHAR(6)      NOT NULL DEFAULT '',
    username    VARCHAR(50)  NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
    description TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_perno  (sail_perno),
    INDEX idx_audit_yyyymm (yyyymm),
    INDEX idx_audit_user   (username),
    INDEX idx_audit_action (action)
);

-- Add uploaded_by / updated_by to attendance (skip if already exists)
ALTER TABLE attendance
    ADD COLUMN uploaded_by VARCHAR(50) NULL AFTER uploaded_at,
    ADD COLUMN updated_by  VARCHAR(50) NULL AFTER updated_at;
