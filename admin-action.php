<?php
// ============================================================
// actions/admin-action.php — Admin Actions Handler
// ============================================================
session_start();
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.html"); exit();
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'suspend':
    case 'activate':
        $user_id = intval($_POST['user_id'] ?? 0);
        $status  = $action === 'suspend' ? 'suspended' : 'active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->bind_param("si", $status, $user_id);
        $stmt->execute();
        $stmt->close();
        $msg = $action === 'suspend' ? "User suspended." : "User activated.";
        header("Location: ../admin-dashboard.php?success=" . urlencode($msg));
        break;

    case 'approve_tutor':
        $tutor_id = intval($_POST['tutor_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE tutor_profiles SET approved = 1 WHERE tutor_id = ?");
        $stmt->bind_param("i", $tutor_id);
        $stmt->execute();
        $stmt->close();
        header("Location: ../admin-dashboard.php?success=" . urlencode("Tutor approved! They can now appear in search results."));
        break;

    case 'reject_tutor':
        $tutor_id = intval($_POST['tutor_id'] ?? 0);
        $user_id  = intval($_POST['user_id']  ?? 0);
        // Delete tutor profile and suspend account
        $stmt = $conn->prepare("DELETE FROM tutor_profiles WHERE tutor_id = ?");
        $stmt->bind_param("i", $tutor_id);
        $stmt->execute();
        $stmt->close();
        $stmt2 = $conn->prepare("UPDATE users SET status = 'suspended' WHERE user_id = ?");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $stmt2->close();
        header("Location: ../admin-dashboard.php?success=" . urlencode("Tutor application rejected."));
        break;

    case 'resolve_dispute':
        $dispute_id = intval($_POST['dispute_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE disputes SET status = 'resolved' WHERE dispute_id = ?");
        $stmt->bind_param("i", $dispute_id);
        $stmt->execute();
        $stmt->close();
        header("Location: ../admin-dashboard.php?success=" . urlencode("Dispute marked as resolved."));
        break;

    default:
        header("Location: ../admin-dashboard.php?error=" . urlencode("Unknown action."));
}
exit();
?>
