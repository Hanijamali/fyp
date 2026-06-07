<?php
// ============================================================
// actions/register.php — Signup Handler (Secure)
// ============================================================
session_start();
include __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../signup.html");
    exit();
}

$first_name      = trim($_POST['first_name'] ?? '');
$last_name       = trim($_POST['last_name']  ?? '');
$email           = trim($_POST['email']      ?? '');
$password        = $_POST['password']        ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$role            = $_POST['role']            ?? 'student';

// --- Validation ---
$errors = [];

if (empty($first_name) || empty($last_name)) $errors[] = "Name fields are required.";
if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = "Invalid email address.";
if (strlen($password) < 8)                       $errors[] = "Password must be at least 8 characters.";
if ($password !== $confirm_password)             $errors[] = "Passwords do not match.";
if (!in_array($role, ['student','parent','tutor'])) $errors[] = "Invalid role selected.";

if (!empty($errors)) {
    $msg = implode(" ", $errors);
    header("Location: ../signup.html?error=" . urlencode($msg));
    exit();
}

// --- Check if email already exists (prepared statement) ---
$check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    header("Location: ../signup.html?error=" . urlencode("An account with this email already exists."));
    exit();
}
$check->close();

// --- Insert user (prepared statement) ---
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $first_name, $last_name, $email, $hashed_password, $role);

if ($stmt->execute()) {
    $new_user_id = $conn->insert_id;
    $stmt->close();

    // If tutor, create their profile row
    if ($role === 'tutor') {
        $subject = $_POST['subject'] ?? 'General';
        $tp = $conn->prepare("INSERT INTO tutor_profiles (user_id, subject, approved) VALUES (?, ?, 0)");
        $tp->bind_param("is", $new_user_id, $subject);
        $tp->execute();
        $tp->close();
    }

    header("Location: ../login.html?success=" . urlencode("Account created! Please log in."));
    exit();
} else {
    $stmt->close();
    header("Location: ../signup.html?error=" . urlencode("Registration failed. Please try again."));
    exit();
}
?>
