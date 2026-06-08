<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('parent');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'parent') {
    header("Location: ../login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../parent-dashboard.php");
    exit();
}

$parent_id = (int) $_SESSION['user_id'];
$child_email = trim($_POST['child_email'] ?? '');

if ($child_email === '' || !filter_var($child_email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../parent-dashboard.php?error=" . urlencode("Please enter a valid student email."));
    exit();
}

if (!tf_table_exists($conn, 'parent_students')) {
    header("Location: ../parent-dashboard.php?error=" . urlencode("Missing parent_students table. Run database_migration.sql."));
    exit();
}

$st = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND role = 'student' LIMIT 1");
$st->bind_param("s", $child_email);
$st->execute();
$student = tf_stmt_one_assoc($st);
$st->close();

if (!$student) {
    header("Location: ../parent-dashboard.php?error=" . urlencode("No student account found for that email."));
    exit();
}

$student_id = (int) $student['user_id'];

$link = $conn->prepare("INSERT IGNORE INTO parent_students (parent_id, student_id) VALUES (?, ?)");
$link->bind_param("ii", $parent_id, $student_id);
$link->execute();
$affected = $link->affected_rows;
$link->close();

if ($affected > 0) {
    header("Location: ../parent-dashboard.php?success=" . urlencode("Student linked successfully."));
} else {
    header("Location: ../parent-dashboard.php?error=" . urlencode("This student is already linked to your account."));
}
exit();
?>
