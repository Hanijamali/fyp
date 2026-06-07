<?php
// ============================================================
// actions/login-action.php — Login Handler (Secure)
// ============================================================
session_start();
require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../login.html");
    exit();
}

$email    = trim($_POST['email']    ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    header("Location: ../login.html?error=" . urlencode("Please enter your email and password."));
    exit();
}

// --- Fetch user by email (prepared statement) ---
$stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, status FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();
    $stmt->close();

    // Check account status
    if ($user['status'] === 'suspended') {
        header("Location: ../login.html?error=" . urlencode("Your account has been suspended. Contact support."));
        exit();
    }

    // Verify password
    if (password_verify($password, $user['password'])) {
        // Start session
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role']       = $user['role'];

        // Redirect based on role
        switch ($user['role']) {
            case 'student': header("Location: ../student-dashboard.php"); break;
            case 'parent':  header("Location: ../parent-dashboard.php");  break;
            case 'tutor':   header("Location: ../tutor-dashboard.php");   break;
            case 'admin':   header("Location: ../admin-dashboard.php");   break;
            default:        header("Location: ../index.html");
        }
        exit();
    }
}

// Invalid credentials
header("Location: ../login.html?error=" . urlencode("Invalid email or password. Please try again."));
exit();
?>
