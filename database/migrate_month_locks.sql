USE office_db;

CREATE TABLE IF NOT EXISTS month_locks (
    yyyymm    CHAR(6)     NOT NULL PRIMARY KEY,
    locked    TINYINT(1)  NOT NULL DEFAULT 1,
    locked_at TIMESTAMP   NULL,
    locked_by VARCHAR(50) NULL
);
