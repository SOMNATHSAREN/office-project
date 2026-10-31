-- Password-reset requests (admin-mediated, no email server required).
-- A user submits a request on auth/forgot.php; this column records when.
-- The admin sees the flag in User Management and sets a new password,
-- which clears the flag.
ALTER TABLE users ADD COLUMN reset_requested_at DATETIME NULL DEFAULT NULL;
