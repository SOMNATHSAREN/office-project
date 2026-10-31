USE office_db;

CREATE TABLE IF NOT EXISTS supplementary_attendance (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno       VARCHAR(20)  NOT NULL,
    absence_yyyymm   CHAR(6)      NOT NULL,
    sanction_yyyymm  CHAR(6)      NOT NULL,
    leave_type       ENUM('CL','EL','COM','HPL','SCL','EXWAGE') NOT NULL,
    days             DECIMAL(5,2) NOT NULL DEFAULT 0,
    remarks          TEXT,
    status           ENUM('pending','applied','cancelled') DEFAULT 'pending',
    applied_at       TIMESTAMP NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supp_perno (sail_perno),
    INDEX idx_supp_absence (absence_yyyymm)
);
