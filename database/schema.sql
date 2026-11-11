CREATE DATABASE homedy;
USE homedy;

CREATE TABLE users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(50) NOT NULL,
  last_name VARCHAR(50) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  role ENUM('customer','worker','admin') NOT NULL
);

CREATE TABLE admins (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  contact_number VARCHAR(20),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE customers (
  customer_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  address VARCHAR(255),
  contact_number VARCHAR(20),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE workers (
  worker_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  address VARCHAR(255),
  contact_number VARCHAR(20),
  is_verified TINYINT(1) DEFAULT 0,
  date_registered DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE worker_documents (
  document_id INT AUTO_INCREMENT PRIMARY KEY,
  worker_id INT NOT NULL,
  doc_type VARCHAR(50) NOT NULL,            -- NBI Clearance, Police Clearance, Barangay Clearance, ...
  original_name VARCHAR(255) NOT NULL,
  stored_name CHAR(32) NOT NULL UNIQUE,     -- random file name inside storage/documents
  file_size INT NOT NULL,
  date_uploaded DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id) ON DELETE CASCADE
);

CREATE TABLE service_categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE worker_skills (
  worker_id INT NOT NULL,
  category_id INT NOT NULL,
  PRIMARY KEY (worker_id, category_id),
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES service_categories(category_id)
);

CREATE TABLE job_requests (
  request_id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  worker_id INT NULL,
  category_id INT NOT NULL,
  description TEXT,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('pending','accepted','completed','cancelled') DEFAULT 'pending',
  date_request DATETIME DEFAULT CURRENT_TIMESTAMP,
  date_accepted DATETIME NULL,
  date_completed DATETIME NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id),
  FOREIGN KEY (category_id) REFERENCES service_categories(category_id)
);

CREATE TABLE payments (
  payment_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(30),
  payment_status ENUM('unpaid','paid') DEFAULT 'unpaid',
  payment_date DATETIME NULL,
  FOREIGN KEY (request_id) REFERENCES job_requests(request_id)
);

CREATE TABLE reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  worker_id INT NOT NULL,
  rating TINYINT NOT NULL,                 -- Overall Satisfaction (required, 1-5)
  quality_rating TINYINT NULL,             -- Work Quality (optional, 1-5)
  punctuality_rating TINYINT NULL,         -- Punctuality / Timeliness (optional, 1-5)
  professionalism_rating TINYINT NULL,     -- Professionalism (optional, 1-5)
  efficiency_rating TINYINT NULL,          -- Efficiency / Speed (optional, 1-5)
  remarks VARCHAR(500) NULL,
  date_created DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES job_requests(request_id),
  FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
  FOREIGN KEY (worker_id) REFERENCES workers(worker_id),
  CHECK (rating BETWEEN 1 AND 5),
  CHECK (quality_rating IS NULL OR quality_rating BETWEEN 1 AND 5),
  CHECK (punctuality_rating IS NULL OR punctuality_rating BETWEEN 1 AND 5),
  CHECK (professionalism_rating IS NULL OR professionalism_rating BETWEEN 1 AND 5),
  CHECK (efficiency_rating IS NULL OR efficiency_rating BETWEEN 1 AND 5)
);

-- Starter categories
INSERT INTO service_categories (category_name) VALUES
('Housekeeper'), ('Plumber'), ('Electrician'), ('Driver'), ('Cook'), ('Gardener');
