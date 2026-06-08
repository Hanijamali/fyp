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
if (!tf_table_exists($conn, 'assignments')) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Missing assignments table. Run database_migration.sql."));
    exit();
}
$has_assignment_file_col = tf_column_exists($conn, 'assignments', 'file_path');

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');
$due_date = trim($_POST['due_date'] ?? '');
$file = $_FILES['assignment_file'] ?? null;
$tutor_user_id = (int) $_SESSION['user_id'];

if ($booking_id < 1 || $title === '') {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking and title are required."));
    exit();
}

$ck = $conn->prepare("
    SELECT b.tutor_id, b.status
    FROM bookings b
    JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
    LIMIT 1
");
$ck->bind_param("ii", $booking_id, $tutor_user_id);
$ck->execute();
$booking = tf_stmt_one_assoc($ck);
$ck->close();

if (!$booking || !in_array($booking['status'], ['confirmed','completed'], true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Assignment can only be created for confirmed/completed lessons."));
    exit();
}

$tutor_id = (int) $booking['tutor_id'];
$due = ($due_date !== '') ? $due_date : null;
$assignment_file_path = null;
if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $allowed_ext = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];
    $max_size = 8 * 1024 * 1024;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true) || ($file['size'] ?? 0) <= 0 || $file['size'] > $max_size || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid assignment file."));
        exit();
    }

    $dir = __DIR__ . "/../uploads/assignments";
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Could not create assignment folder."));
        exit();
    }
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $name = 'asg_' . $booking_id . '_' . time() . '_' . $safe . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Could not save assignment file."));
        exit();
    }
    $assignment_file_path = 'uploads/assignments/' . $name;
}

$ok = false;
if ($has_assignment_file_col) {
    $in = $conn->prepare("INSERT INTO assignments (booking_id, tutor_id, title, instructions, file_path, due_date) VALUES (?, ?, ?, ?, ?, ?)");
    $in->bind_param("iissss", $booking_id, $tutor_id, $title, $instructions, $assignment_file_path, $due);
    $ok = $in->execute();
    $in->close();
} else {
    $in = $conn->prepare("INSERT INTO assignments (booking_id, tutor_id, title, instructions, due_date) VALUES (?, ?, ?, ?, ?)");
    $in->bind_param("iisss", $booking_id, $tutor_id, $title, $instructions, $due);
    $ok = $in->execute();
    $in->close();
}

if (!$ok) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Failed to create assignment."));
    exit();
}
header("Location: ../tutor-dashboard.php?success=" . urlencode("Assignment created."));
exit();
?>
