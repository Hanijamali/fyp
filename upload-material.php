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

if (!tf_table_exists($conn, 'lesson_materials')) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Missing lesson_materials table. Run database_migration.sql."));
    exit();
}

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$file = $_FILES['material_file'] ?? null;
$tutor_user_id = (int) $_SESSION['user_id'];

if ($booking_id < 1 || $title === '' || !$file) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking, title, and file are required."));
    exit();
}

$allowed_ext = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];
$max_size = 8 * 1024 * 1024; // 8MB
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext, true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Unsupported file type."));
    exit();
}
if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_size) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("File must be between 1 byte and 8MB."));
    exit();
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("File upload failed."));
    exit();
}

$check = $conn->prepare("
    SELECT b.booking_id, b.status, b.tutor_id
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
    LIMIT 1
");
$check->bind_param("ii", $booking_id, $tutor_user_id);
$check->execute();
$booking = tf_stmt_one_assoc($check);
$check->close();

if (!$booking) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking not found or not yours."));
    exit();
}

if (!in_array($booking['status'], ['confirmed', 'completed'], true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Materials can only be uploaded for confirmed/completed lessons."));
    exit();
}

$upload_dir_fs = __DIR__ . "/../uploads/materials";
if (!is_dir($upload_dir_fs) && !mkdir($upload_dir_fs, 0775, true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Could not create upload folder."));
    exit();
}

$safe_base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$new_name = 'mat_' . $booking_id . '_' . time() . '_' . $safe_base . '.' . $ext;
$target_fs = $upload_dir_fs . '/' . $new_name;
$target_db = 'uploads/materials/' . $new_name;

if (!move_uploaded_file($file['tmp_name'], $target_fs)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Could not save uploaded file."));
    exit();
}

$ins = $conn->prepare("INSERT INTO lesson_materials (booking_id, tutor_id, title, file_path) VALUES (?, ?, ?, ?)");
$tutor_id = (int) $booking['tutor_id'];
$ins->bind_param("iiss", $booking_id, $tutor_id, $title, $target_db);
$ok = $ins->execute();
$ins->close();

if (!$ok) {
    @unlink($target_fs);
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Failed to save material record."));
    exit();
}

header("Location: ../tutor-dashboard.php?success=" . urlencode("Material uploaded successfully."));
exit();
?>
