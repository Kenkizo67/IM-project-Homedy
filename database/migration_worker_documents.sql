-- For an EXISTING homedy database (keeps your data).
-- Run AFTER the earlier migrations. Clean start instead: DROP DATABASE homedy; import schema.sql + seed_admin.sql.
USE homedy;

-- When the worker registered (old workers keep NULL)
ALTER TABLE workers ADD COLUMN date_registered DATETIME NULL;

-- Verification documents uploaded by workers (NBI, Police, Barangay clearance, ...)
-- The files themselves are stored on disk in storage/documents under random names; this table records them.
CREATE TABLE worker_documents (
  document_id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  doc_type VARCHAR(50) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(32) NOT NULL UNIQUE,
  file_size INT NOT NULL,
  date_uploaded DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id) ON DELETE CASCADE
);
