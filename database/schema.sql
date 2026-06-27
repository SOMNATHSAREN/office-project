-- Office Management System — MariaDB Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS office_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE office_db;

-- ─── Admin users ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,   -- bcrypt hash
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
-- Default admin: admin / admin123  (change after first login)
INSERT INTO users (username, password) VALUES
    ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- ─── Employee master ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employees (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno      VARCHAR(20)  NOT NULL UNIQUE,   -- SAIL_PERNO from CSV
    unit_perno      VARCHAR(20),
    unit_cd         VARCHAR(20),
    name            VARCHAR(100) NOT NULL,
    department      VARCHAR(100),
    designation     VARCHAR(100),
    date_of_joining DATE,
    status          ENUM('active','inactive') DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── Monthly attendance (one row per employee per month) ─────────────────────
CREATE TABLE IF NOT EXISTS attendance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno      VARCHAR(20)  NOT NULL,
    yyyymm          CHAR(6)      NOT NULL,   -- e.g. 202406
    unit_cd         VARCHAR(20),
    unit_perno      VARCHAR(20),
    duty            DECIMAL(5,2) DEFAULT 0,
    w_off           DECIMAL(5,2) DEFAULT 0,
    c_nhday         DECIMAL(5,2) DEFAULT 0,
    c_off           DECIMAL(5,2) DEFAULT 0,
    cl              DECIMAL(5,2) DEFAULT 0,
    fl_coff         DECIMAL(5,2) DEFAULT 0,
    el              DECIMAL(5,2) DEFAULT 0,
    hpl             DECIMAL(5,2) DEFAULT 0,
    com             DECIMAL(5,2) DEFAULT 0,
    eol             DECIMAL(5,2) DEFAULT 0,
    abs             DECIMAL(5,2) DEFAULT 0,
    others          DECIMAL(5,2) DEFAULT 0,
    night_shift     DECIMAL(5,2) DEFAULT 0,
    injury_lv       DECIMAL(5,2) DEFAULT 0,
    ex_grat         DECIMAL(5,2) DEFAULT 0,
    unaccounted     DECIMAL(5,2) DEFAULT 0,
    exwage          DECIMAL(5,2) DEFAULT 0,
    local_trg       DECIMAL(5,2) DEFAULT 0,
    scl_bd          DECIMAL(5,2) DEFAULT 0,
    scl             DECIMAL(5,2) DEFAULT 0,
    ccl             DECIMAL(5,2) DEFAULT 0,
    short_hrs       DECIMAL(5,2) DEFAULT 0,
    el_blns         DECIMAL(6,2) DEFAULT 0,
    hpl_blns        DECIMAL(6,2) DEFAULT 0,
    cl_blns         DECIMAL(6,2) DEFAULT 0,
    fl_blns         DECIMAL(6,2) DEFAULT 0,
    uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_emp_month (sail_perno, yyyymm)
);

-- ─── Leave ledger (opening / closing per employee per month) ──────────────────
CREATE TABLE IF NOT EXISTS leave_ledger (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno      VARCHAR(20) NOT NULL,
    yyyymm          CHAR(6)     NOT NULL,
    -- Opening balances (carried from previous month or annual credit)
    cl_open         DECIMAL(6,2) DEFAULT 0,
    el_open         DECIMAL(6,2) DEFAULT 0,
    hpl_open        DECIMAL(6,2) DEFAULT 0,
    fl_open         DECIMAL(6,2) DEFAULT 0,
    -- Availed this month (derived from attendance)
    cl_availed      DECIMAL(6,2) DEFAULT 0,
    el_availed      DECIMAL(6,2) DEFAULT 0,
    hpl_availed     DECIMAL(6,2) DEFAULT 0,
    fl_availed      DECIMAL(6,2) DEFAULT 0,
    com_availed     DECIMAL(6,0) DEFAULT 0,   -- COM days (whole numbers only; deducts 2× from HPL)
    -- Credited this month (manual / annual)
    cl_credit       DECIMAL(6,2) DEFAULT 0,
    el_credit       DECIMAL(6,2) DEFAULT 0,
    hpl_credit      DECIMAL(6,2) DEFAULT 0,
    fl_credit       DECIMAL(6,2) DEFAULT 0,
    -- Closing balances (open + credit - availed)
    cl_close        DECIMAL(6,2) GENERATED ALWAYS AS (cl_open + cl_credit - cl_availed) STORED,
    el_close        DECIMAL(6,2) GENERATED ALWAYS AS (el_open + el_credit - el_availed) STORED,
    hpl_close       DECIMAL(6,2) GENERATED ALWAYS AS (hpl_open + hpl_credit - hpl_availed - (com_availed * 2)) STORED,
    fl_close        DECIMAL(6,2) GENERATED ALWAYS AS (fl_open + fl_credit - fl_availed) STORED,
    notes           TEXT,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ledger (sail_perno, yyyymm)
);

-- ─── Supplementary attendance (retroactive leave sanction) ──────────────────
CREATE TABLE IF NOT EXISTS supplementary_attendance (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno       VARCHAR(20)  NOT NULL,
    absence_yyyymm   CHAR(6)      NOT NULL,   -- month the employee was absent
    sanction_yyyymm  CHAR(6)      NOT NULL,   -- month the leave is sanctioned
    leave_type       ENUM('CL','EL','COM','HPL','SCL','EXWAGE') NOT NULL,
    days             DECIMAL(5,2) NOT NULL DEFAULT 0,   -- COM must be whole number; EXWAGE = days
    remarks          TEXT,
    status           ENUM('pending','applied','cancelled') DEFAULT 'pending',
    applied_at       TIMESTAMP NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supp_perno (sail_perno),
    INDEX idx_supp_absence (absence_yyyymm)
);

-- ─── Supplementary rectification audit log ──────────────────────────────────
CREATE TABLE IF NOT EXISTS supplementary_rectify_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    sail_perno       VARCHAR(20) NOT NULL,
    rectify_yyyymm   CHAR(6)     NOT NULL,
    sanction_yyyymm  CHAR(6)     NOT NULL,
    before_data      TEXT        NOT NULL,
    after_data       TEXT        NOT NULL,
    before_ledger    TEXT        NULL,
    after_ledger     TEXT        NULL,
    remarks          TEXT,
    created_by       VARCHAR(50),
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rlog_perno (sail_perno),
    INDEX idx_rlog_month (rectify_yyyymm)
);

-- ─── Month lock (prevents edits on a specific month) ────────────────────────
CREATE TABLE IF NOT EXISTS month_locks (
    yyyymm    CHAR(6)     NOT NULL PRIMARY KEY,
    locked    TINYINT(1)  NOT NULL DEFAULT 1,
    locked_at TIMESTAMP   NULL,
    locked_by VARCHAR(50) NULL
);

-- ─── Year lock (prevents edits once a year is closed) ───────────────────────
CREATE TABLE IF NOT EXISTS year_locks (
    year        YEAR        NOT NULL PRIMARY KEY,
    locked      TINYINT(1)  NOT NULL DEFAULT 0,
    locked_at   TIMESTAMP   NULL,
    locked_by   VARCHAR(50) NULL
);

-- Annual leave entitlements (used to seed opening balances at year start)
CREATE TABLE IF NOT EXISTS leave_entitlements (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    year    YEAR NOT NULL UNIQUE,
    cl      DECIMAL(5,2) DEFAULT 8,
    el      DECIMAL(5,2) DEFAULT 15,
    hpl     DECIMAL(5,2) DEFAULT 20,
    fl      DECIMAL(5,2) DEFAULT 3
);
INSERT INTO leave_entitlements (year, cl, el, hpl, fl) VALUES (YEAR(NOW()), 8, 15, 20, 3)
    ON DUPLICATE KEY UPDATE cl=cl;
