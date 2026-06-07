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
    profile_bio TEXT NULL,
    phone       VARCHAR(40) NULL,
    profile_picture VARCHAR(255) NULL,
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
    payment_status  ENUM('unpaid','paid') DEFAULT 'unpaid',
    attendance_status ENUM('pending','present','absent') DEFAULT 'pending',
    progress_score  TINYINT UNSIGNED NULL,
    tutor_comment   VARCHAR(255) NULL,
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
    resolution_outcome VARCHAR(40) NULL,
    admin_resolution_note TEXT NULL,
    resolved_at TIMESTAMP NULL,
    resolved_by INT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (filed_by)   REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (against)    REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ===================== PARENT–STUDENT LINKS =====================
CREATE TABLE IF NOT EXISTS parent_students (
    link_id       INT AUTO_INCREMENT PRIMARY KEY,
    parent_id     INT NOT NULL,
    student_id    INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parent_student (parent_id, student_id),
    FOREIGN KEY (parent_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===================== LESSON MATERIALS =====================
CREATE TABLE IF NOT EXISTS lesson_materials (
    material_id     INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    tutor_id        INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id)   REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE
);

-- ===================== ASSIGNMENTS =====================
CREATE TABLE IF NOT EXISTS assignments (
    assignment_id   INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    tutor_id        INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    instructions    TEXT,
    file_path       VARCHAR(255) NULL,
    due_date        DATE NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id)   REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
    payment_id      INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    paid_by         INT NOT NULL,
    amount          DECIMAL(8,2) NOT NULL,
    method          ENUM('card','fpx','ewallet') NOT NULL DEFAULT 'card',
    status          ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid',
    paid_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    transaction_ref VARCHAR(64) NULL,
    card_last4      CHAR(4) NULL,
    channel_detail  VARCHAR(120) NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by)    REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS assignment_submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id   INT NOT NULL,
    student_id      INT NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    note            VARCHAR(255) NULL,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assignment_student (assignment_id, student_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(assignment_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)    REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===================== QUIZZES =====================
CREATE TABLE IF NOT EXISTS quizzes (
    quiz_id       INT AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT NULL,
    tutor_id      INT NOT NULL,
    title         VARCHAR(200) NOT NULL,
    quiz_subject  VARCHAR(120) NULL,
    due_date      DATE NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL,
    FOREIGN KEY (tutor_id)   REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_students (
    quiz_id    INT NOT NULL,
    student_id INT NOT NULL,
    PRIMARY KEY (quiz_id, student_id),
    FOREIGN KEY (quiz_id)    REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_quiz_students_student (student_id)
);

CREATE TABLE IF NOT EXISTS quiz_questions (
    question_id   INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id       INT NOT NULL,
    question_text VARCHAR(600) NOT NULL,
    option_a      VARCHAR(300) NOT NULL,
    option_b      VARCHAR(300) NOT NULL,
    option_c      VARCHAR(300) NOT NULL,
    option_d      VARCHAR(300) NOT NULL,
    correct_option ENUM('a','b','c','d') NOT NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_attempts (
    attempt_id      INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id         INT NOT NULL,
    student_id      INT NOT NULL,
    score           SMALLINT UNSIGNED NOT NULL,
    total_questions SMALLINT UNSIGNED NOT NULL,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_student_attempt (quiz_id, student_id),
    FOREIGN KEY (quiz_id)    REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
    answer_id     INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id    INT NOT NULL,
    question_id   INT NOT NULL,
    selected_option ENUM('a','b','c','d') NOT NULL,
    is_correct    TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (attempt_id)  REFERENCES quiz_attempts(attempt_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(question_id) ON DELETE CASCADE,
    UNIQUE KEY uq_attempt_question (attempt_id, question_id)
);

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT NULL,
    link_url        VARCHAR(500) NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, is_read)
);

-- ===================== INITIAL DATA =====================
-- No demo/sample user data is inserted automatically.
-- Create real user accounts via signup flow after setup.
