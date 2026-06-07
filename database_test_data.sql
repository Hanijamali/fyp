-- ============================================================
-- TutorFind Test Data (Realistic, Repeatable)
-- ============================================================
-- Run after:
-- 1) database_setup.sql
-- 2) database_migration.sql
--
-- Notes:
-- - This script is idempotent for the emails listed below.
-- - Test password for all inserted users: password
--   (bcrypt hash used below)

USE tutorfind_db;

START TRANSACTION;

-- ------------------------------------------------------------
-- Clean previous test records for these accounts only
-- ------------------------------------------------------------
DELETE FROM users
WHERE email IN (
  'admin.real@tutorfind.local',
  'tutor.math@tutorfind.local',
  'student.ali@tutorfind.local',
  'parent.sara@tutorfind.local'
);

-- ------------------------------------------------------------
-- Insert users
-- ------------------------------------------------------------
INSERT INTO users (first_name, last_name, email, password, role, status) VALUES
('Aina', 'Admin', 'admin.real@tutorfind.local',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',   'active'),
('Daniel', 'Tan', 'tutor.math@tutorfind.local',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'tutor',   'active'),
('Ali', 'Rahman', 'student.ali@tutorfind.local',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'active'),
('Sara', 'Rahman', 'parent.sara@tutorfind.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'parent',  'active');

SET @admin_user_id   := (SELECT user_id FROM users WHERE email = 'admin.real@tutorfind.local' LIMIT 1);
SET @tutor_user_id   := (SELECT user_id FROM users WHERE email = 'tutor.math@tutorfind.local' LIMIT 1);
SET @student_user_id := (SELECT user_id FROM users WHERE email = 'student.ali@tutorfind.local' LIMIT 1);
SET @parent_user_id  := (SELECT user_id FROM users WHERE email = 'parent.sara@tutorfind.local' LIMIT 1);

-- ------------------------------------------------------------
-- Insert tutor profile (approved so it appears in search)
-- ------------------------------------------------------------
INSERT INTO tutor_profiles
    (user_id, subject, rate_per_hour, bio, qualifications, experience_years, availability, rating, total_reviews, approved)
VALUES
    (@tutor_user_id, 'Mathematics', 85.00, 'Specializes in SPM and foundation-level mathematics with structured practice plans.', 'B.Sc. Mathematics (UM)', 6, 'Weekdays', 4.80, 12, 1);

SET @tutor_profile_id := (SELECT tutor_id FROM tutor_profiles WHERE user_id = @tutor_user_id LIMIT 1);

-- ------------------------------------------------------------
-- Insert bookings (for dashboard + status flows)
-- ------------------------------------------------------------
INSERT INTO bookings
    (student_id, tutor_id, subject, lesson_date, lesson_time, duration, notes, status, total_amount)
VALUES
    (@student_user_id, @tutor_profile_id, 'Mathematics', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '15:00:00', '1.5 hours', 'Focus on algebra and equation solving.', 'pending', 127.50),
    (@student_user_id, @tutor_profile_id, 'Mathematics', DATE_SUB(CURDATE(), INTERVAL 5 DAY), '14:00:00', '1 hour', 'Revision for school test.', 'completed', 85.00),
    (@parent_user_id,  @tutor_profile_id, 'Mathematics', DATE_ADD(CURDATE(), INTERVAL 4 DAY), '10:00:00', '2 hours', 'Lesson for child: fractions and ratios.', 'confirmed', 170.00);

SET @completed_booking_id := (
  SELECT booking_id
  FROM bookings
  WHERE student_id = @student_user_id
    AND tutor_id = @tutor_profile_id
    AND status = 'completed'
  ORDER BY booking_id DESC
  LIMIT 1
);

-- ------------------------------------------------------------
-- Insert feedback for completed lesson
-- ------------------------------------------------------------
INSERT INTO feedback (booking_id, student_id, tutor_id, rating, comment, status)
VALUES (@completed_booking_id, @student_user_id, @tutor_profile_id, 5, 'Clear explanations and useful exam tips.', 'reviewed');

-- Sync tutor aggregate rating
UPDATE tutor_profiles
SET
  rating = (SELECT ROUND(AVG(rating), 2) FROM feedback WHERE tutor_id = @tutor_profile_id),
  total_reviews = (SELECT COUNT(*) FROM feedback WHERE tutor_id = @tutor_profile_id)
WHERE tutor_id = @tutor_profile_id;

-- ------------------------------------------------------------
-- Insert one open dispute for admin testing
-- ------------------------------------------------------------
INSERT INTO disputes (booking_id, filed_by, against, issue, status)
VALUES (
  @completed_booking_id,
  @student_user_id,
  @tutor_user_id,
  'Lesson started late by 20 minutes. Requesting partial compensation review.',
  'open'
);

COMMIT;
