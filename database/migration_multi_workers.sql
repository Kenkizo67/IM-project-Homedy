-- For an EXISTING homedy database (keeps your data).
-- Lets one request have several workers, and lets the customer accept/reject the workers who apply.
-- Run AFTER the earlier migrations. Clean start instead? DROP DATABASE homedy; then import schema.sql + seed_admin.sql.
USE homedy;

-- 1. How many workers a request needs, and whether other workers may apply
ALTER TABLE job_requests
  ADD COLUMN workers_needed TINYINT NOT NULL DEFAULT 1 AFTER price,
  ADD COLUMN open_to_all TINYINT(1) NOT NULL DEFAULT 1 AFTER workers_needed;

-- 2. One row per worker on a request
CREATE TABLE request_workers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  worker_id INT NOT NULL,
  status ENUM('invited','applied','selected','done','rejected','declined') NOT NULL,
  date_added DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_response DATETIME NULL,
  date_done DATETIME NULL,
  UNIQUE KEY uq_request_worker (request_id, worker_id),
  FOREIGN KEY (request_id) REFERENCES job_requests(request_id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id) ON DELETE CASCADE
);

-- 3. Move the existing one-worker-per-request data into the new table
INSERT INTO request_workers (request_id, worker_id, status, date_added, date_response, date_done)
SELECT request_id, worker_id,
       CASE WHEN status = 'completed' THEN 'done' ELSE 'selected' END,
       COALESCE(date_accepted, date_request), date_accepted, date_completed
FROM job_requests
WHERE worker_id IS NOT NULL AND status IN ('accepted', 'completed');

-- 4. Reviews are now one per worker per request (add the new key BEFORE dropping the old one)
ALTER TABLE reviews ADD UNIQUE KEY uq_review_worker (request_id, worker_id);
ALTER TABLE reviews DROP INDEX request_id;

-- The old job_requests.worker_id column is no longer used by the app. It is left in place so nothing breaks;
-- you can ignore it.
