<?php
// ============================================================
// actions/update-booking.php — Tutor Accept / Reject Booking
// ============================================================
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: ../login.html");
    exit();
}

$booking_id = intval($_POST['booking_id'] ?? 0);
$action     = $_POST['action'] ?? '';

if (!$booking_id || !in_array($action, ['confirmed', 'cancelled', 'completed'], true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid action."));
    exit();
}

// Make sure this booking belongs to the logged-in tutor
$tutor_user_id = $_SESSION['user_id'];
$check = $conn->prepare("
    SELECT b.booking_id FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
");
$check->bind_param("ii", $booking_id, $tutor_user_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking not found."));
    exit();
}
$check->close();

$info = $conn->prepare("
    SELECT b.student_id, tp.user_id AS tutor_user_id
    FROM bookings b
    JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$info->bind_param("i", $booking_id);
$info->execute();
$binfo = tf_stmt_one_assoc($info);
$info->close();

$stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
$stmt->bind_param("si", $action, $booking_id);
$stmt->execute();
$stmt->close();

if (tf_table_exists($conn, 'notifications') && $binfo) {
    $sid = (int) ($binfo['student_id'] ?? 0);
    $tid = (int) ($binfo['tutor_user_id'] ?? 0);
    if ($action === 'confirmed' && $sid > 0) {
        tf_notify_user($conn, $sid, 'Lesson confirmed', "Your booking #{$booking_id} was accepted by the tutor.", 'student-dashboard.php#s-lessons');
    }
    if ($action === 'cancelled' && $sid > 0) {
        tf_notify_user($conn, $sid, 'Lesson not available', "Booking #{$booking_id} was declined or cancelled.", 'student-dashboard.php#s-lessons');
    }
    if ($action === 'completed' && $sid > 0) {
        tf_notify_user($conn, $sid, 'Lesson completed', "Booking #{$booking_id} was marked completed. Leave feedback from your dashboard.", 'student-dashboard.php#s-feedback');
    }
}

$msg = "Booking updated.";
if ($action === 'confirmed') $msg = "Booking accepted!";
if ($action === 'cancelled') $msg = "Booking rejected.";
if ($action === 'completed') $msg = "Lesson marked as completed.";
header("Location: ../tutor-dashboard.php?success=" . urlencode($msg));
exit();
?>
