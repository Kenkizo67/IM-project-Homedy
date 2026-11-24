-- Run AFTER schema.sql.
-- Default login:  username: admin   password: Admin@12345
-- Change this password (or replace the hash) before using the system for real.
USE homedy;

INSERT INTO users (username, password_hash, first_name, last_name, email, role)
VALUES ('admin', '$2y$10$pDDMjcx8uqPA0wssI/aUVuNgAyrBfpe3x5RJTFtZTS5jsRX/Czv1S', 'System', 'Admin', 'admin@example.com', 'admin');

INSERT INTO admins (user_id, contact_number)
VALUES (LAST_INSERT_ID(), '09123456789');
