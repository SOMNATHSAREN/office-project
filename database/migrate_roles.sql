-- Add role column to users table
ALTER TABLE users ADD COLUMN role ENUM('admin','manager','hr') NOT NULL DEFAULT 'hr' AFTER username;

-- Set existing admin user to admin role (adjust username if different)
UPDATE users SET role = 'admin' WHERE username = 'admin';
