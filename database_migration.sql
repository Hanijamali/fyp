-- ============================================================
-- TutorFind Database Migration (Safe / Idempotent)
-- ============================================================
-- Usage:
-- 1) Open phpMyAdmin
-- 2) Select tutorfind_db
-- 3) Run this script

USE tutorfind_db;

-- Ensure users.status exists for login/admin/search flows
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active';

-- Normalize any empty/NULL statuses from older schemas
UPDATE users
SET status = 'active'
WHERE status IS NULL OR status = '';

-- Ensure tutor approval column exists for tutor search visibility
ALTER TABLE tutor_profiles
    ADD COLUMN IF NOT EXISTS approved TINYINT(1) DEFAULT 0;

-- Ensure booking status + amount exist for dashboards/analytics
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS status ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS total_amount DECIMAL(8,2) DEFAULT 0;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS attendance_status ENUM('pending','present','absent') DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS progress_score TINYINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS tutor_comment VARCHAR(255) NULL;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','paid') DEFAULT 'unpaid';

-- Optional helpful indexes for common lookups
ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_email (email),
    ADD INDEX IF NOT EXISTS idx_users_role_status (role, status);

ALTER TABLE bookings
    ADD INDEX IF NOT EXISTS idx_bookings_student (student_id),
    ADD INDEX IF NOT EXISTS idx_bookings_tutor (tutor_id),
    ADD INDEX IF NOT EXISTS idx_bookings_date_status (lesson_date, status);

ALTER TABLE tutor_profiles
    ADD INDEX IF NOT EXISTS idx_tutor_profiles_approved_subject (approved, subject);

-- Link parent accounts to student accounts (lesson progress visibility)
CREATE TABLE IF NOT EXISTS parent_students (
    link_id       INT AUTO_INCREMENT PRIMARY KEY,
    parent_id     INT NOT NULL,
    student_id    INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parent_student (parent_id, student_id),
    FOREIGN KEY (parent_id)  REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Tutor-uploaded lesson files for students/parents
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

ALTER TABLE lesson_materials
    ADD INDEX IF NOT EXISTS idx_materials_booking (booking_id),
    ADD INDEX IF NOT EXISTS idx_materials_tutor (tutor_id);

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

ALTER TABLE assignments
    ADD INDEX IF NOT EXISTS idx_assignments_booking (booking_id),
    ADD INDEX IF NOT EXISTS idx_assignments_tutor (tutor_id);

ALTER TABLE assignment_submissions
    ADD INDEX IF NOT EXISTS idx_submissions_assignment (assignment_id),
    ADD INDEX IF NOT EXISTS idx_submissions_student (student_id);

CREATE TABLE IF NOT EXISTS payments (
    payment_id      INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    paid_by         INT NOT NULL,
    amount          DECIMAL(8,2) NOT NULL,
    method          ENUM('card','fpx','ewallet') NOT NULL DEFAULT 'card',
    status          ENUM('pending','paid','failed') NOT NULL DEFAULT 'paid',
    paid_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (paid_by)    REFERENCES users(user_id) ON DELETE CASCADE
);

ALTER TABLE payments
    ADD INDEX IF NOT EXISTS idx_payments_booking (booking_id),
    ADD INDEX IF NOT EXISTS idx_payments_paid_by (paid_by),
    ADD INDEX IF NOT EXISTS idx_payments_status_paid_at (status, paid_at);

-- Payment gateway-style metadata (demo checkout; never store full card numbers)
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS transaction_ref VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS card_last4 CHAR(4) NULL,
    ADD COLUMN IF NOT EXISTS channel_detail VARCHAR(120) NULL;

-- Admin dispute resolution (outcome + written decision visible to parties)
ALTER TABLE disputes
    ADD COLUMN IF NOT EXISTS resolution_outcome VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS admin_resolution_note TEXT NULL,
    ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS resolved_by INT NULL;

-- Quizzes (tutor creates for a lesson booking; student submits one attempt; parent read-only via dashboard)
CREATE TABLE IF NOT EXISTS quizzes (
    quiz_id       INT AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT NOT NULL,
    tutor_id      INT NOT NULL,
    title         VARCHAR(200) NOT NULL,
    due_date      DATE NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id)   REFERENCES tutor_profiles(tutor_id) ON DELETE CASCADE,
    INDEX idx_quizzes_booking (booking_id),
    INDEX idx_quizzes_tutor (tutor_id)
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
    FOREIGN KEY (quiz_id) REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    INDEX idx_quiz_questions_quiz (quiz_id)
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
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_quiz_attempts_student (student_id)
);

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
    answer_id     INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id    INT NOT NULL,
    question_id   INT NOT NULL,
    selected_option ENUM('a','b','c','d') NOT NULL,
    is_correct    TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (attempt_id)  REFERENCES quiz_attempts(attempt_id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(question_id) ON DELETE CASCADE,
    UNIQUE KEY uq_attempt_question (attempt_id, question_id),
    INDEX idx_quiz_answers_attempt (attempt_id)
);

-- Quiz optional due date for databases created before due_date existed
ALTER TABLE quizzes
    ADD COLUMN IF NOT EXISTS due_date DATE NULL;

-- Optional account profile fields (all roles)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_bio TEXT NULL;
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS phone VARCHAR(40) NULL;

-- Profile photo (stored path under uploads/profiles/)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL;

-- In-app notifications (dashboard bell)
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT NULL,
    link_url        VARCHAR(500) NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_user_created (user_id, created_at)
);

-- Quiz subject line (so multi-student quizzes show correct topic without relying on context booking's student)
ALTER TABLE quizzes
    ADD COLUMN IF NOT EXISTS quiz_subject VARCHAR(120) NULL;

UPDATE quizzes q
JOIN bookings b ON b.booking_id = q.booking_id
SET q.quiz_subject = b.subject
WHERE q.quiz_subject IS NULL OR q.quiz_subject = '';

-- Tutor can assign the same quiz to multiple students (one shared question set)
CREATE TABLE IF NOT EXISTS quiz_students (
    quiz_id    INT NOT NULL,
    student_id INT NOT NULL,
    PRIMARY KEY (quiz_id, student_id),
    FOREIGN KEY (quiz_id)    REFERENCES quizzes(quiz_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_quiz_students_student (student_id)
);

-- Backfill assignees from legacy single-booking quizzes
INSERT IGNORE INTO quiz_students (quiz_id, student_id)
SELECT q.quiz_id, b.student_id
FROM quizzes q
JOIN bookings b ON b.booking_id = q.booking_id;

-- Allow quizzes without tying visibility to context booking student (booking_id kept for FK / context)
ALTER TABLE quizzes
    MODIFY COLUMN booking_id INT NULL;
