<?php
require_once __DIR__ . "/../config/session.php";
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
 
// Some local databases may not have the `status` column yet.
// Detect schema first to avoid SQL exceptions on missing columns.
$status_col_result = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$has_status_column = $status_col_result && $status_col_result->num_rows > 0;

$sql = $has_status_column
    ? "SELECT user_id, first_name, last_name, email, password, role, status FROM users WHERE email = ?"
    : "SELECT user_id, first_name, last_name, email, password, role FROM users WHERE email = ?";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $email);

    if ($stmt->execute()) {
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            if ($has_status_column) {
                $stmt->bind_result($user_id, $first_name, $last_name, $user_email, $hashed_password, $role, $status);
            } else {
                $stmt->bind_result($user_id, $first_name, $last_name, $user_email, $hashed_password, $role);
                $status = 'active';
            }

            $stmt->fetch();

            if ($status === 'suspended') {
                header("Location: ../login.html?error=" . urlencode("Your account has been suspended."));
                exit();
            }

            if (password_verify($password, $hashed_password)) {
                tf_session_start_role($role);
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user_id;
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name']  = $last_name;
                $_SESSION['email']      = $user_email;
                $_SESSION['role']       = $role;

                if ($role === 'student') {
                    header("Location: ../student-dashboard.php");
                } elseif ($role === 'parent') {
                    header("Location: ../parent-dashboard.php");
                } elseif ($role === 'tutor') {
                    header("Location: ../tutor-dashboard.php");
                } elseif ($role === 'admin') {
                    header("Location: ../admin-dashboard.php");
                } else {
                    header("Location: ../index.html");
                }
                exit();
            }
        }
    }

    $stmt->close();
}

header("Location: ../login.html?error=" . urlencode("Invalid email or password. Please try again."));
exit();
?>