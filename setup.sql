-- setup.sql — CICS SRMS v3 (Role-Based)
-- Run once on a fresh install. Migration ALTERs at bottom handle existing installs.

CREATE DATABASE IF NOT EXISTS cics_srms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cics_srms;

-- ── Students ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_no  VARCHAR(20)  UNIQUE NOT NULL,
    full_name   VARCHAR(120) NOT NULL,
    email       VARCHAR(120) UNIQUE NOT NULL,
    program     ENUM('BS Information Technology','BS Information Systems') NOT NULL,
    year_level  TINYINT UNSIGNED NOT NULL CHECK (year_level BETWEEN 1 AND 4),
    section     VARCHAR(30),
    school_year VARCHAR(20),
    semester    ENUM('1st','2nd','Summer') DEFAULT '1st',
    status      ENUM('Active','Inactive','Graduated','LOA') DEFAULT 'Active',
    contact_no  VARCHAR(20),
    birthday    DATE,
    address     TEXT,
    photo       MEDIUMTEXT,
    is_locked   TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = self-registered, locked
    user_id     INT,                             -- FK → users.id for self-registered students
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ── Users ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(120) NOT NULL,
    email       VARCHAR(120) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('student','faculty') NOT NULL DEFAULT 'student',
    student_id  INT,
    program     ENUM('BS Information Technology','BS Information Systems'),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Activity Log ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS activity_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    action       VARCHAR(30) NOT NULL,
    student_id   INT,
    student_no   VARCHAR(20),
    student_name VARCHAR(120),
    performed_by VARCHAR(120),
    details      TEXT,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── Migrations (MySQL 8.0+ — safe to run on existing installs) ────────────────
ALTER TABLE students
    ADD COLUMN IF NOT EXISTS section     VARCHAR(30)  AFTER year_level,
    ADD COLUMN IF NOT EXISTS school_year VARCHAR(20)  AFTER section,
    ADD COLUMN IF NOT EXISTS semester    ENUM('1st','2nd','Summer') DEFAULT '1st' AFTER school_year,
    ADD COLUMN IF NOT EXISTS status      ENUM('Active','Inactive','Graduated','LOA') DEFAULT 'Active' AFTER semester,
    ADD COLUMN IF NOT EXISTS contact_no  VARCHAR(20)  AFTER status,
    ADD COLUMN IF NOT EXISTS birthday    DATE         AFTER contact_no,
    ADD COLUMN IF NOT EXISTS address     TEXT         AFTER birthday,
    ADD COLUMN IF NOT EXISTS photo       MEDIUMTEXT   AFTER address,
    ADD COLUMN IF NOT EXISTS is_locked   TINYINT(1) NOT NULL DEFAULT 0 AFTER photo,
    ADD COLUMN IF NOT EXISTS user_id     INT AFTER is_locked;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role       ENUM('student','faculty') NOT NULL DEFAULT 'student' AFTER password,
    ADD COLUMN IF NOT EXISTS student_id INT AFTER role,
    ADD COLUMN IF NOT EXISTS program    ENUM('BS Information Technology','BS Information Systems') AFTER student_id;

ALTER TABLE activity_log
    ADD COLUMN IF NOT EXISTS performed_by VARCHAR(120) AFTER student_name;

-- ── Sample student records (faculty-managed) ──────────────────────────────────
INSERT IGNORE INTO students (student_no, full_name, email, program, year_level, section, school_year, semester, status, contact_no)
VALUES
    ('2024-0001','Maria Santos',   'msantos@cics.edu.ph',   'BS Information Technology',2,'IT-2A','2025-2026','1st','Active','09171234567'),
    ('2024-0002','Juan Dela Cruz', 'jdelacruz@cics.edu.ph', 'BS Information Systems',   3,'IS-3B','2025-2026','1st','Active','09281234567'),
    ('2024-0003','Ana Reyes',      'areyes@cics.edu.ph',    'BS Information Technology',1,'IT-1C','2025-2026','1st','Active','09391234567'),
    ('2024-0004','Carlo Mendoza',  'cmendoza@cics.edu.ph',  'BS Information Systems',   4,'IS-4A','2025-2026','1st','LOA',   '09401234567');

-- NOTE: Run  php seed_faculty.php  to create default faculty accounts.
-- Faculty registration code (configurable in api.php): CICS-FACULTY-2026
