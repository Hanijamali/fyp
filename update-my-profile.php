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

$user_id = (int) $_SESSION['user_id'];
$fn = trim((string) ($_POST['first_name'] ?? ''));
$ln = trim((string) ($_POST['last_name'] ?? ''));

if ($fn === '' || $ln === '') {
    header("Location: ../my-profile.php?error=" . urlencode("First and last name are required."));
    exit();
}

$st = $conn->prepare("UPDATE users SET first_name=?, last_name=? WHERE user_id=?");
$st->bind_param("ssi", $fn, $ln, $user_id);
if (!$st->execute()) {
    $st->close();
    header("Location: ../my-profile.php?error=" . urlencode("Could not save."));
    exit();
}
$st->close();

header("Location: ../my-profile.php?success=" . urlencode("Name updated."));
exit();
