<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Unauthorized.");
}

$assignment_id = (int) ($_GET['id'] ?? 0);
if ($assignment_id < 1) {
    http_response_code(400);
    exit("Invalid assignment.");
}
$has_assignment_file_col = tf_column_exists($conn, 'assignments', 'file_path');
if (!$has_assignment_file_col) {
    http_response_code(400);
    exit("Assignment file feature not enabled yet. Run database_migration.sql.");
}

$st = $conn->prepare("
    SELECT a.file_path, b.student_id, tp.user_id AS tutor_user_id
    FROM assignments a
    JOIN bookings b ON b.booking_id = a.booking_id
    JOIN tutor_profiles tp ON tp.tutor_id = a.tutor_id
    WHERE a.assignment_id = ?
    LIMIT 1
");
$st->bind_param("i", $assignment_id);
$st->execute();
$row = tf_stmt_one_assoc($st);
$st->close();

if (!$row || empty($row['file_path'])) {
    http_response_code(404);
    exit("Assignment file not found.");
}

$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$allowed = false;

if ($role === 'student' && $uid === (int)$row['student_id']) {
    $allowed = true;
} elseif ($role === 'tutor' && $uid === (int)$row['tutor_user_id']) {
    $allowed = true;
} elseif ($role === 'parent' && tf_table_exists($conn, 'parent_students')) {
    $ps = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $sid = (int) $row['student_id'];
    $ps->bind_param("ii", $uid, $sid);
    $ps->execute();
    $ps->store_result();
    $allowed = $ps->num_rows === 1;
    $ps->close();
}

if (!$allowed) {
    http_response_code(403);
    exit("Access denied.");
}

$relative = ltrim($row['file_path'], '/\\');
$full = realpath(__DIR__ . "/../" . $relative);
$base = realpath(__DIR__ . "/../uploads/assignments");
if (!$full || !$base || strpos($full, $base) !== 0 || !is_file($full)) {
    http_response_code(404);
    exit("File not found.");
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($full) . '"');
header('Content-Length: ' . filesize($full));
readfile($full);
exit();
?>
