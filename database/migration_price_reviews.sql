-- For an EXISTING homedy database (keeps your data).
-- If you prefer a clean start instead: DROP DATABASE homedy; then import schema.sql + seed_admin.sql again.
USE homedy;

-- 1. The customer sets the price on the request
ALTER TABLE job_requests ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER description;

-- Old requests that already have a payment keep that amount as their price
UPDATE job_requests r JOIN payments p ON p.request_id = r.request_id SET r.price = p.amount;

-- 2. Customer ratings and remarks about a completed job
CREATE TABLE reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  worker_id INT NOT NULL,
  rating TINYINT NOT NULL,
  remarks VARCHAR(500) NULL,
  date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES job_requests(request_id),
  FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id),
  CHECK (rating BETWEEN 1 AND 5)
);

-- Optional: old test requests that still have no price (workers cannot complete a job with price 0)
-- UPDATE job_requests SET price = 500 WHERE price = 0;
