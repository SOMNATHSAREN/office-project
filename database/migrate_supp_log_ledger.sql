USE office_db;

ALTER TABLE supplementary_rectify_log
    ADD COLUMN before_ledger TEXT NULL AFTER after_data,
    ADD COLUMN after_ledger  TEXT NULL AFTER before_ledger;
