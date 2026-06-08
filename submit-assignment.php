<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('student');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../student-dashboard.php");
    exit();
}
if (!tf_table_exists($conn, 'assignment_submissions')) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Missing assignment_submissions table. Run database_migration.sql."));
    exit();
}

$assignment_id = (int) ($_POST['assignment_id'] ?? 0);
$note = trim($_POST['note'] ?? '');
$file = $_FILES['submission_file'] ?? null;
$role = $_SESSION['role'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if ($assignment_id < 1 || !$file) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Assignment and file are required."));
    exit();
}

$q = $conn->prepare("
    SELECT a.assignment_id, b.student_id
    FROM assignments a
    JOIN bookings b ON b.booking_id = a.booking_id
    WHERE a.assignment_id = ?
    LIMIT 1
");
$q->bind_param("i", $assignment_id);
$q->execute();
$assignment = tf_stmt_one_assoc($q);
$q->close();

if (!$assignment) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Assignment not found."));
    exit();
}

$student_id = (int) $assignment['student_id'];
if ($role === 'parent') {
    header("Location: ../parent-dashboard.php?error=" . urlencode("Parents cannot submit assignments. Student must submit directly."));
    exit();
}

$allowed = ($role === 'student' && $user_id === $student_id);

if (!$allowed) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Not allowed to submit this assignment."));
    exit();
}

$allowed_ext = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];
$max_size = 8 * 1024 * 1024;
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext, true) || ($file['size'] ?? 0) > $max_size || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Invalid submission file."));
    exit();
}

$dir = __DIR__ . "/../uploads/submissions";
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Could not create submission folder."));
    exit();
}
$safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$name = 'sub_' . $assignment_id . '_' . $student_id . '_' . time() . '_' . $safe . '.' . $ext;
$target = $dir . '/' . $name;
$db_path = 'uploads/submissions/' . $name;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Could not save file."));
    exit();
}

$up = $conn->prepare("
    INSERT INTO assignment_submissions (assignment_id, student_id, file_path, note)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), note = VALUES(note), submitted_at = CURRENT_TIMESTAMP
");
$up->bind_param("iiss", $assignment_id, $student_id, $db_path, $note);
$ok = $up->execute();
$up->close();

if (!$ok) {
    @unlink($target);
    header("Location: ../student-dashboard.php?error=" . urlencode("Could not save submission."));
    exit();
}

$redirect = ($role === 'parent') ? "../parent-dashboard.php" : "../student-dashboard.php";
header("Location: " . $redirect . "?success=" . urlencode("Assignment submitted."));
exit();
?>
