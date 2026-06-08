<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../my-profile.php");
    exit();
}

if (!tf_column_exists($conn, 'users', 'profile_picture')) {
    header("Location: ../my-profile.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$st = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ? LIMIT 1");
$st->bind_param("i", $user_id);
$st->execute();
$row = tf_stmt_one_assoc($st);
$st->close();

$prev = tf_profile_picture_url($row['profile_picture'] ?? null);
if ($prev) {
    $prevPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $prev);
    if (is_file($prevPath)) {
        @unlink($prevPath);
    }
}

$up = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?");
$up->bind_param("i", $user_id);
$up->execute();
$up->close();

header("Location: ../my-profile.php?success=" . urlencode("Profile picture removed."));
exit();
