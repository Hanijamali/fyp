<?php
// ============================================================
// actions/submit-feedback.php
// ============================================================
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('student');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html"); exit();
}

$student_id = $_SESSION['user_id'];
$booking_id = intval($_POST['booking_id'] ?? 0);
$tutor_id   = intval($_POST['tutor_id']   ?? 0);
$rating     = intval($_POST['rating']     ?? 0);
$comment    = trim($_POST['comment']      ?? '');

if (!$booking_id || !$tutor_id || $rating < 1 || $rating > 5) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Invalid feedback submission."));
    exit();
}

$stmt = $conn->prepare("INSERT INTO feedback (booking_id, student_id, tutor_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iiiis", $booking_id, $student_id, $tutor_id, $rating, $comment);
$stmt->execute();
$stmt->close();

// Update tutor average rating
$avg = $conn->prepare("
    UPDATE tutor_profiles SET
        rating = (SELECT ROUND(AVG(rating),2) FROM feedback WHERE tutor_id = ?),
        total_reviews = (SELECT COUNT(*) FROM feedback WHERE tutor_id = ?)
    WHERE tutor_id = ?
");
$avg->bind_param("iii", $tutor_id, $tutor_id, $tutor_id);
$avg->execute();
$avg->close();

header("Location: ../student-dashboard.php?success=" . urlencode("Feedback submitted. Thank you!"));
exit();
?>
