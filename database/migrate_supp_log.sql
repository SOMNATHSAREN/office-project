USE office_db;

-- Audit log for supplementary rectifications (before/after snapshot)
CREATE TABLE IF NOT EXISTS supplementary_rectify_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno       VARCHAR(20) NOT NULL,
    rectify_yyyymm   CHAR(6)     NOT NULL,   -- attendance month that was rectified
    sanction_yyyymm  CHAR(6)     NOT NULL,   -- month when rectification was done
    before_data      TEXT        NOT NULL,   -- JSON: all attendance field values before change
    after_data       TEXT        NOT NULL,   -- JSON: all attendance field values after change
    remarks          TEXT,
    created_by       VARCHAR(50),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rlog_perno  (sail_perno),
    INDEX idx_rlog_month  (rectify_yyyymm)
);
