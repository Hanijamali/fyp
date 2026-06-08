<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit("Unauthorized.");
}

$material_id = (int) ($_GET['id'] ?? 0);
if ($material_id < 1) {
    http_response_code(400);
    exit("Invalid material.");
}

$st = $conn->prepare("
    SELECT lm.title, lm.file_path, b.student_id, tp.user_id AS tutor_user_id
    FROM lesson_materials lm
    JOIN bookings b ON b.booking_id = lm.booking_id
    JOIN tutor_profiles tp ON tp.tutor_id = lm.tutor_id
    WHERE lm.material_id = ?
    LIMIT 1
");
$st->bind_param("i", $material_id);
$st->execute();
$row = tf_stmt_one_assoc($st);
$st->close();

if (!$row) {
    http_response_code(404);
    exit("Material not found.");
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$allowed = false;

if ($role === 'student' && $user_id === (int) $row['student_id']) {
    $allowed = true;
} elseif ($role === 'tutor' && $user_id === (int) $row['tutor_user_id']) {
    $allowed = true;
} elseif ($role === 'parent' && tf_table_exists($conn, 'parent_students')) {
    $ps = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $student_id = (int) $row['student_id'];
    $ps->bind_param("ii", $user_id, $student_id);
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
$full_path = realpath(__DIR__ . "/../" . $relative);
$base = realpath(__DIR__ . "/../uploads/materials");
if (!$full_path || !$base || strpos($full_path, $base) !== 0 || !is_file($full_path)) {
    http_response_code(404);
    exit("File not found.");
}

$filename = basename($full_path);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($full_path));
readfile($full_path);
exit();
?>
