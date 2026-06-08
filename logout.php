<?php
// ============================================================
// actions/logout.php — Logout one role (others can stay logged in)
// ============================================================
require_once __DIR__ . "/../config/session.php";

$role = strtolower(trim($_GET['role'] ?? ''));

if ($role !== '' && in_array($role, tf_session_roles(), true)) {
    tf_session_destroy_role($role);
} else {
    tf_session_destroy_all();
}

header("Location: ../login.html?success=" . urlencode("You have been logged out."));
exit();
