<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'tutor') {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../tutor-dashboard.php");
    exit();
}

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$attendance = $_POST['attendance_status'] ?? 'pending';
$score_raw = trim($_POST['progress_score'] ?? '');
$comment = trim($_POST['tutor_comment'] ?? '');

if ($booking_id < 1 || !in_array($attendance, ['pending','present','absent'], true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid progress payload."));
    exit();
}

$score = null;
if ($score_raw !== '') {
    if (!ctype_digit($score_raw)) {
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Score must be 0-100."));
        exit();
    }
    $score = (int) $score_raw;
    if ($score < 0 || $score > 100) {
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Score must be 0-100."));
        exit();
    }
}

$ck = $conn->prepare("
    SELECT b.booking_id
    FROM bookings b
    JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
    LIMIT 1
");
$uid = (int) $_SESSION['user_id'];
$ck->bind_param("ii", $booking_id, $uid);
$ck->execute();
$ck->store_result();
if ($ck->num_rows !== 1) {
    $ck->close();
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking not found."));
    exit();
}
$ck->close();

$up = $conn->prepare("UPDATE bookings SET attendance_status = ?, progress_score = ?, tutor_comment = ? WHERE booking_id = ?");
$up->bind_param("sisi", $attendance, $score, $comment, $booking_id);
$up->execute();
$up->close();

header("Location: ../tutor-dashboard.php?success=" . urlencode("Progress updated."));
exit();
?>
