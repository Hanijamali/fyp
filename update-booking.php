<?php
// ============================================================
// actions/update-booking.php — Tutor Accept / Reject Booking
// ============================================================
session_start();
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: ../login.html");
    exit();
}

$booking_id = intval($_POST['booking_id'] ?? 0);
$action     = $_POST['action'] ?? '';

if (!$booking_id || !in_array($action, ['confirmed', 'cancelled'])) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid action."));
    exit();
}

// Make sure this booking belongs to the logged-in tutor
$tutor_user_id = $_SESSION['user_id'];
$check = $conn->prepare("
    SELECT b.booking_id FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
");
$check->bind_param("ii", $booking_id, $tutor_user_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    $check->close();
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Booking not found."));
    exit();
}
$check->close();

$stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
$stmt->bind_param("si", $action, $booking_id);
$stmt->execute();
$stmt->close();

$msg = $action === 'confirmed' ? "Booking accepted!" : "Booking rejected.";
header("Location: ../tutor-dashboard.php?success=" . urlencode($msg));
exit();
?>
