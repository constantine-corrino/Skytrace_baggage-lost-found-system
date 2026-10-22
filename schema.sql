-- SkyTrace database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Import with:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS skytrace
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE skytrace;

CREATE TABLE IF NOT EXISTS staff (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,          -- created with password_hash()
  name          VARCHAR(100) NOT NULL,
  role          VARCHAR(50)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS passengers (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  booking_ref   VARCHAR(10)  NOT NULL UNIQUE,
  last_name     VARCHAR(50)  NOT NULL,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL,
  flight_number VARCHAR(10)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bags (
  id             INT AUTO_INCREMENT PRIMARY KEY,   -- shown to users as BAG-<id>
  tag_number     VARCHAR(20)  NOT NULL,
  flight_number  VARCHAR(10)  NOT NULL,
  route          VARCHAR(30)  NOT NULL DEFAULT '',
  color          VARCHAR(30)  NOT NULL,
  brand          VARCHAR(50)  NOT NULL,
  `type`         VARCHAR(50)  NOT NULL,
  description    TEXT         NOT NULL,
  date_found     DATE         NOT NULL,
  found_location VARCHAR(100) NOT NULL,
  status         ENUM('Unclaimed','Claim Pending','Returned') NOT NULL DEFAULT 'Unclaimed',
  logged_by      INT NULL,                          -- which staff member logged it
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_flight (flight_number),
  INDEX idx_tag (tag_number),
  CONSTRAINT fk_bag_staff FOREIGN KEY (logged_by) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS claims (
  id            INT AUTO_INCREMENT PRIMARY KEY,    -- shown to users as CLM-<id>
  bag_id        INT NOT NULL,
  passenger_id  INT NOT NULL,
  submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status        ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
  confidence    TINYINT UNSIGNED NOT NULL DEFAULT 0, -- computed by the server, never trusted from the browser
  reviewed_by   INT NULL,
  reviewed_at   DATETIME NULL,
  INDEX idx_claim_status (status),
  CONSTRAINT fk_claim_bag       FOREIGN KEY (bag_id)       REFERENCES bags(id)       ON DELETE CASCADE,
  CONSTRAINT fk_claim_passenger FOREIGN KEY (passenger_id) REFERENCES passengers(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_staff     FOREIGN KEY (reviewed_by)  REFERENCES staff(id)      ON DELETE SET NULL
) ENGINE=InnoDB;
