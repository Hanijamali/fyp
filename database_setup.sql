-- ============================================================
-- TutorFind Database Setup
-- Run this file in phpMyAdmin or MySQL to create all tables
-- ============================================================

CREATE DATABASE IF NOT EXISTS tutorfind_db;
USE tutorfind_db;

-- ===================== USERS TABLE =====================
CREATE TABLE IF NOT EXISTS users (
    user_id     INT AUTO_INCREMENT PRIMARY KEY,
    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('student','parent','tutor','admin') NOT NULL DEFAULT 'student',
    status      ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ===================== TUTOR PROFILES TABLE =====================
CREATE TABLE IF NOT EXISTS tutor_profiles (
    tutor_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    subject         VARCHAR(100),
    rate_per_hour   DECIMAL(8,2) DEFAULT 0,
    bio             TEXT,
    qualifications  VARCHAR(255),
    experience_years INT DEFAULT 0,
    availability    VARCHAR(100) DEFAULT 'Weekdays',
    rating          DECIMAL(3,2) DEFAULT 0.00,
    total_reviews   INT DEFAULT 0,
    approved        TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===================== BOOKINGS TABLE =====================
CREATE TABLE IF NOT EXISTS bookings (
    booking_id      INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    tutor_id        INT NOT NULL,
    subject         VARCHAR(100),
    lesson_date     DATE NOT NULL,
    lesson_time     TIME NOT NULL,
    duration        VARCHAR(20) DEFAULT '1 hour',
    notes           TEXT,
    status          ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
    total_amount    DECIMAL(8,2) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id)   REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE
);

-- ===================== FEEDBACK TABLE =====================
CREATE TABLE IF NOT EXISTS feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT NOT NULL,
    student_id  INT NOT NULL,
    tutor_id    INT NOT NULL,
    rating      TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment     TEXT,
    status      ENUM('pending','reviewed') DEFAULT 'pending',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id)  REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id)    REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE
);

-- ===================== DISPUTES TABLE =====================
CREATE TABLE IF NOT EXISTS disputes (
    dispute_id  INT AUTO_INCREMENT PRIMARY KEY,
    booking_id  INT NOT NULL,
    filed_by    INT NOT NULL,
    against     INT NOT NULL,
    issue       TEXT NOT NULL,
    status      ENUM('open','resolved') DEFAULT 'open',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (filed_by)   REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (against)    REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===================== SAMPLE DATA =====================
-- Admin account (password: Admin@1234)
INSERT INTO users (first_name, last_name, email, password, role, status) VALUES
('Admin', 'TutorFind', 'admin@tutorfind.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');

-- Sample tutor (password: Tutor@1234)
INSERT INTO users (first_name, last_name, email, password, role, status) VALUES
('Lim', 'Wei', 'lim@tutorfind.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'tutor', 'active');

INSERT INTO tutor_profiles (user_id, subject, rate_per_hour, bio, qualifications, experience_years, availability, rating, total_reviews, approved) VALUES
(2, 'Mathematics', 80.00, 'Experienced mathematics tutor with a passion for making numbers easy.', 'PhD Mathematics, UM', 8, 'Weekdays', 4.9, 47, 1);

-- Sample student (password: Student@1234)
INSERT INTO users (first_name, last_name, email, password, role, status) VALUES
('Hani', 'Jamali', 'hani@tutorfind.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active');

-- NOTE: All sample passwords above are 'password' hashed with bcrypt.
-- Change these immediately after setup!
