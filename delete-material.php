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

$material_id = (int) ($_POST['material_id'] ?? 0);
if ($material_id < 1) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid material."));
    exit();
}

$uid = (int) $_SESSION['user_id'];
$st = $conn->prepare("
    SELECT lm.file_path
    FROM lesson_materials lm
    JOIN tutor_profiles tp ON tp.tutor_id = lm.tutor_id
    WHERE lm.material_id = ? AND tp.user_id = ?
    LIMIT 1
");
$st->bind_param("ii", $material_id, $uid);
$st->execute();
$row = tf_stmt_one_assoc($st);
$st->close();

if (!$row) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Material not found."));
    exit();
}

$del = $conn->prepare("DELETE FROM lesson_materials WHERE material_id = ?");
$del->bind_param("i", $material_id);
$ok = $del->execute();
$del->close();

if (!$ok) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Failed to delete material."));
    exit();
}

$relative = ltrim($row['file_path'], '/\\');
$full_path = realpath(__DIR__ . "/../" . $relative);
$base = realpath(__DIR__ . "/../uploads/materials");
if ($full_path && $base && strpos($full_path, $base) === 0 && is_file($full_path)) {
    @unlink($full_path);
}

header("Location: ../tutor-dashboard.php?success=" . urlencode("Material removed."));
exit();
?>
