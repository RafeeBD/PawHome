CREATE DATABASE IF NOT EXISTS pawhome CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pawhome;

CREATE TABLE IF NOT EXISTS users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  role          ENUM('adopter','shelter','vet','admin') NOT NULL,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  phone         VARCHAR(30)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status        ENUM('active','pending','rejected') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at   DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shelter_profiles (
  user_id             INT PRIMARY KEY,
  contact_person      VARCHAR(100) NOT NULL,
  registration_number VARCHAR(50)  NOT NULL,
  address             VARCHAR(255) NOT NULL,
  description         TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vet_profiles (
  user_id          INT PRIMARY KEY,
  clinic           VARCHAR(150) NOT NULL,
  licence_number   VARCHAR(50)  NOT NULL,
  qualification    VARCHAR(150) NOT NULL,
  specialization   VARCHAR(100) NOT NULL,
  years_experience TINYINT UNSIGNED NOT NULL,
  bio              TEXT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_documents (
  document_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path   VARCHAR(255) NOT NULL,
  uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  category_id INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(50) NOT NULL UNIQUE,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pets (
  pet_id           INT AUTO_INCREMENT PRIMARY KEY,
  shelter_id       INT NOT NULL,
  category_id      INT NOT NULL,
  name             VARCHAR(60)  NOT NULL,
  breed            VARCHAR(80)  NOT NULL,
  gender           ENUM('Male','Female') NOT NULL,
  birth_date       DATE NOT NULL,
  dob_known        TINYINT(1) NOT NULL DEFAULT 1,
  size             ENUM('Small','Medium','Large') NOT NULL,
  weight_kg        DECIMAL(5,2) NULL,
  colour           VARCHAR(60)  NOT NULL DEFAULT '',
  health_condition VARCHAR(120) NOT NULL DEFAULT 'Healthy',
  vaccinated       TINYINT(1) NOT NULL DEFAULT 0,
  spayed_neutered  TINYINT(1) NOT NULL DEFAULT 0,
  special_needs    VARCHAR(255) NOT NULL DEFAULT '',
  behaviour        VARCHAR(255) NOT NULL DEFAULT '',
  availability     ENUM('Available','Pending','Adopted') NOT NULL DEFAULT 'Available',
  location         VARCHAR(255) NOT NULL,
  description      TEXT NOT NULL,
  photo_path       VARCHAR(255) NOT NULL,
  medical_doc_path VARCHAR(255) NULL,
  medical_doc_name VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shelter_id)  REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
  application_id INT AUTO_INCREMENT PRIMARY KEY,
  pet_id         INT NOT NULL,
  adopter_id     INT NOT NULL,
  full_name      VARCHAR(100) NOT NULL,
  email          VARCHAR(150) NOT NULL,
  phone          VARCHAR(30)  NOT NULL,
  household      VARCHAR(255) NOT NULL,
  experience     VARCHAR(255) NOT NULL,
  message        TEXT NOT NULL,
  document_path  VARCHAR(255) NULL,
  document_name  VARCHAR(255) NULL,
  status         ENUM('Pending','Approved','Rejected','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at    DATETIME NULL,
  FOREIGN KEY (pet_id)     REFERENCES pets(pet_id) ON DELETE CASCADE,
  FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS handovers (
  handover_id    INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL UNIQUE,
  scheduled_at   DATETIME NOT NULL,
  location       VARCHAR(255) NOT NULL,
  notes          VARCHAR(500) NOT NULL DEFAULT '',
  status         ENUM('Scheduled','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  completed_at   DATETIME NULL,
  FOREIGN KEY (application_id) REFERENCES applications(application_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
  appointment_id INT AUTO_INCREMENT PRIMARY KEY,
  pet_id         INT NOT NULL,
  adopter_id     INT NOT NULL,
  vet_id         INT NOT NULL,
  scheduled_at   DATETIME NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  document_path  VARCHAR(255) NULL,
  document_name  VARCHAR(255) NULL,
  status         ENUM('Pending','Confirmed','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  slot_hold      DATETIME AS (IF(status <> 'Cancelled', scheduled_at, NULL)) STORED,
  FOREIGN KEY (pet_id)     REFERENCES pets(pet_id) ON DELETE CASCADE,
  FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (vet_id)     REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX (vet_id, scheduled_at),
  UNIQUE KEY uniq_vet_slot (vet_id, slot_hold),
  UNIQUE KEY uniq_adopter_slot (adopter_id, slot_hold)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checkups (
  checkup_id     INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL UNIQUE,
  pet_id         INT NOT NULL,
  vet_id         INT NOT NULL,
  weight_kg      DECIMAL(5,2) NULL,
  temperature_c  DECIMAL(4,1) NULL,
  symptoms       VARCHAR(255) NOT NULL DEFAULT '',
  findings       TEXT NOT NULL,
  diagnosis      VARCHAR(255) NOT NULL,
  treatment      VARCHAR(255) NOT NULL DEFAULT '',
  follow_up_date DATE NULL,
  notes          VARCHAR(255) NOT NULL DEFAULT '',
  no_medication  TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
  FOREIGN KEY (pet_id) REFERENCES pets(pet_id) ON DELETE CASCADE,
  FOREIGN KEY (vet_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescriptions (
  prescription_id INT AUTO_INCREMENT PRIMARY KEY,
  checkup_id      INT NOT NULL,
  medicine_name   VARCHAR(100) NOT NULL,
  dosage          VARCHAR(50)  NOT NULL,
  frequency       VARCHAR(50)  NOT NULL,
  duration        VARCHAR(50)  NOT NULL,
  instructions    VARCHAR(255) NOT NULL DEFAULT '',
  FOREIGN KEY (checkup_id) REFERENCES checkups(checkup_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vaccinations (
  vaccination_id INT AUTO_INCREMENT PRIMARY KEY,
  pet_id         INT NOT NULL,
  vaccine_name   VARCHAR(80) NOT NULL,
  status         ENUM('Due','Given') NOT NULL,
  priority       ENUM('Routine','Priority','Urgent') NOT NULL DEFAULT 'Routine',
  due_date       DATE NULL,
  given_date     DATE NULL,
  dose           VARCHAR(30)  NOT NULL DEFAULT '',
  batch_number   VARCHAR(50)  NOT NULL DEFAULT '',
  vet_id         INT NULL,
  next_due_date  DATE NULL,
  notes          VARCHAR(255) NOT NULL DEFAULT '',
  document_path  VARCHAR(255) NULL,
  document_name  VARCHAR(255) NULL,
  checkup_id     INT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pet_id)     REFERENCES pets(pet_id) ON DELETE CASCADE,
  FOREIGN KEY (vet_id)     REFERENCES users(user_id) ON DELETE SET NULL,
  FOREIGN KEY (checkup_id) REFERENCES checkups(checkup_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS health_notes (
  note_id       INT AUTO_INCREMENT PRIMARY KEY,
  pet_id        INT NOT NULL,
  adopter_id    INT NOT NULL,
  note_date     DATE NOT NULL,
  body          TEXT NOT NULL,
  document_path VARCHAR(255) NULL,
  document_name VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pet_id)     REFERENCES pets(pet_id) ON DELETE CASCADE,
  FOREIGN KEY (adopter_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  message_id  INT AUTO_INCREMENT PRIMARY KEY,
  sender_id   INT NOT NULL,
  receiver_id INT NOT NULL,
  body        TEXT NOT NULL,
  sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     DATETIME NULL,
  FOREIGN KEY (sender_id)   REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX (sender_id, receiver_id, sent_at)
) ENGINE=InnoDB;
