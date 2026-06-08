<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../dispute.php");
    exit();
}
if (!tf_table_exists($conn, 'disputes')) {
    header("Location: ../dispute.php?error=" . urlencode("Missing disputes table."));
    exit();
}

$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$booking_id = (int) ($_POST['booking_id'] ?? 0);
$issue = trim($_POST['issue'] ?? '');

if (!in_array($role, ['student', 'parent', 'tutor'], true) || $booking_id < 1 || $issue === '') {
    header("Location: ../dispute.php?error=" . urlencode("Invalid dispute request."));
    exit();
}
if ($role === 'parent' && !tf_table_exists($conn, 'parent_students')) {
    header("Location: ../parent-dashboard.php?error=" . urlencode("Missing parent_students table."));
    exit();
}

$against_user_id = 0;
if ($role === 'student') {
    $q = $conn->prepare("
        SELECT u.user_id AS against_user_id
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE b.booking_id = ? AND b.student_id = ?
        LIMIT 1
    ");
    $q->bind_param("ii", $booking_id, $uid);
} elseif ($role === 'parent') {
    $q = $conn->prepare("
        SELECT u.user_id AS against_user_id
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE b.booking_id = ?
          AND b.student_id IN (SELECT student_id FROM parent_students WHERE parent_id = ?)
        LIMIT 1
    ");
    $q->bind_param("ii", $booking_id, $uid);
} else {
    $q = $conn->prepare("
        SELECT b.student_id AS against_user_id
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        WHERE b.booking_id = ? AND tp.user_id = ?
        LIMIT 1
    ");
    $q->bind_param("ii", $booking_id, $uid);
}

$q->execute();
$row = tf_stmt_one_assoc($q);
$q->close();

if (!$row) {
    header("Location: ../dispute.php?error=" . urlencode("Booking is not available for dispute from your account."));
    exit();
}

$against_user_id = (int) $row['against_user_id'];
if ($against_user_id === $uid || $against_user_id < 1) {
    header("Location: ../dispute.php?error=" . urlencode("Invalid dispute target."));
    exit();
}

$ins = $conn->prepare("INSERT INTO disputes (booking_id, filed_by, against, issue, status) VALUES (?, ?, ?, ?, 'open')");
$ins->bind_param("iiis", $booking_id, $uid, $against_user_id, $issue);
$ok = $ins->execute();
$ins->close();

if (!$ok) {
    header("Location: ../dispute.php?error=" . urlencode("Could not submit dispute."));
    exit();
}

header("Location: ../dispute.php?success=" . urlencode("Dispute submitted successfully."));
exit();
?>
