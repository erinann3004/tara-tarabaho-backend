-- Legacy clean-install schema for the earlier tara_* database layout.
-- Do not import this file into the existing tara_trabaho Navicat database.
-- Configure DB_NAME=tara_trabaho and run:
--   php console/migrate-tara-trabaho.php
USE productdb;

CREATE TABLE IF NOT EXISTS tara_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student', 'employer', 'provider', 'admin') NOT NULL DEFAULT 'student',
  location VARCHAR(160) NOT NULL DEFAULT '',
  organization VARCHAR(180) NOT NULL DEFAULT '',
  skills JSON NOT NULL,
  email_alerts TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY tara_users_email_unique (email),
  KEY tara_users_role_idx (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tara_listings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('job', 'training') NOT NULL,
  title VARCHAR(180) NOT NULL,
  organization VARCHAR(180) NOT NULL,
  location VARCHAR(160) NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT '',
  listing_type VARCHAR(60) NOT NULL DEFAULT '',
  salary VARCHAR(100) NOT NULL DEFAULT '',
  deadline DATE NULL,
  starts DATE NULL,
  ends DATE NULL,
  duration VARCHAR(80) NOT NULL DEFAULT '',
  slots SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  description TEXT NOT NULL,
  skills JSON NOT NULL,
  status ENUM('pending', 'published', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY tara_listings_public_idx (kind, status, created_at),
  KEY tara_listings_owner_idx (owner_id, created_at),
  CONSTRAINT tara_listings_owner_fk FOREIGN KEY (owner_id) REFERENCES tara_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tara_applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('Submitted', 'Reviewing', 'Shortlisted', 'Interview', 'Hired', 'Rejected') NOT NULL DEFAULT 'Submitted',
  note TEXT NOT NULL,
  applicant_phone VARCHAR(40) NOT NULL DEFAULT '',
  applicant_location VARCHAR(160) NOT NULL DEFAULT '',
  resume_storage_name VARCHAR(100) NULL,
  resume_original_name VARCHAR(180) NULL,
  resume_mime VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY tara_application_once_idx (listing_id, user_id),
  KEY tara_applications_user_idx (user_id, created_at),
  CONSTRAINT tara_applications_listing_fk FOREIGN KEY (listing_id) REFERENCES tara_listings (id) ON DELETE CASCADE,
  CONSTRAINT tara_applications_user_fk FOREIGN KEY (user_id) REFERENCES tara_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tara_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NULL,
  kind ENUM('job', 'training', 'application', 'update') NOT NULL DEFAULT 'update',
  title VARCHAR(180) NOT NULL,
  details VARCHAR(500) NOT NULL,
  url VARCHAR(255) NOT NULL DEFAULT '/',
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY tara_notifications_inbox_idx (user_id, read_at, created_at),
  KEY tara_notifications_listing_idx (listing_id),
  CONSTRAINT tara_notifications_user_fk FOREIGN KEY (user_id) REFERENCES tara_users (id) ON DELETE CASCADE,
  CONSTRAINT tara_notifications_listing_fk FOREIGN KEY (listing_id) REFERENCES tara_listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tara_push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY tara_push_endpoint_hash_unique (endpoint_hash),
  KEY tara_push_user_idx (user_id),
  CONSTRAINT tara_push_user_fk FOREIGN KEY (user_id) REFERENCES tara_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create the first administrator through the documented CLI command; do not
-- ship a shared administrator password in a database seed.
