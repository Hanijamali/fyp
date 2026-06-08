<?php
// ============================================================
// actions/update-tutor-profile.php
// ============================================================
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: ../login.html"); exit();
}

$user_id         = $_SESSION['user_id'];
$subject         = trim($_POST['subject']          ?? '');
$rate            = floatval($_POST['rate_per_hour'] ?? 0);
$bio             = trim($_POST['bio']              ?? '');
$qualifications  = trim($_POST['qualifications']   ?? '');
$experience      = intval($_POST['experience_years'] ?? 0);
$availability    = trim($_POST['availability']     ?? 'Weekdays');

$stmt = $conn->prepare("
    UPDATE tutor_profiles
    SET subject=?, rate_per_hour=?, bio=?, qualifications=?, experience_years=?, availability=?
    WHERE user_id=?
");
$stmt->bind_param("sdssisi", $subject, $rate, $bio, $qualifications, $experience, $availability, $user_id);
$stmt->execute();
$stmt->close();

header("Location: ../tutor-dashboard.php?success=" . urlencode("Profile updated successfully!"));
exit();
?>
