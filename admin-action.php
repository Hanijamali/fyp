<?php
// ============================================================
// actions/admin-action.php — Admin Actions Handler
// ============================================================
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('admin');
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
        $dispute_id = (int) ($_POST['dispute_id'] ?? 0);
        $outcome = trim((string) ($_POST['resolution_outcome'] ?? ''));
        $note = trim((string) ($_POST['admin_resolution_note'] ?? ''));
        $admin_id = (int) $_SESSION['user_id'];

        $allowed_outcomes = [
            'refund_student',
            'credit_lesson',
            'warn_party',
            'no_action',
            'escalate_partner',
            'other',
        ];

        if ($dispute_id < 1 || !in_array($outcome, $allowed_outcomes, true)) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Choose a valid resolution outcome."));
            exit();
        }
        if (strlen($note) < 15) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Resolution note must be at least 15 characters (explain the decision clearly)."));
            exit();
        }
        if (!tf_column_exists($conn, 'disputes', 'admin_resolution_note')) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Run database_migration.sql to enable full dispute resolution."));
            exit();
        }

        $chk = $conn->prepare("SELECT dispute_id FROM disputes WHERE dispute_id = ? AND status = 'open' LIMIT 1");
        $chk->bind_param("i", $dispute_id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows !== 1) {
            $chk->close();
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Dispute not found or already resolved."));
            exit();
        }
        $chk->close();

        $stmt = $conn->prepare("
            UPDATE disputes SET
                status = 'resolved',
                resolution_outcome = ?,
                admin_resolution_note = ?,
                resolved_at = CURRENT_TIMESTAMP,
                resolved_by = ?
            WHERE dispute_id = ? AND status = 'open'
        ");
        $stmt->bind_param("ssii", $outcome, $note, $admin_id, $dispute_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected !== 1) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Could not resolve dispute (it may have been closed already)."));
            exit();
        }
        header("Location: ../admin-dashboard.php?tab=dispute&success=" . urlencode("Dispute resolved with a recorded decision. Parties can see the admin note on their Disputes page."));
        exit();

    case 'delete_dispute':
        $dispute_id = (int) ($_POST['dispute_id'] ?? 0);
        if ($dispute_id < 1) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Invalid dispute."));
            exit();
        }
        $stmt = $conn->prepare("DELETE FROM disputes WHERE dispute_id = ? LIMIT 1");
        $stmt->bind_param("i", $dispute_id);
        $stmt->execute();
        $aff = $stmt->affected_rows;
        $stmt->close();
        if ($aff !== 1) {
            header("Location: ../admin-dashboard.php?tab=dispute&error=" . urlencode("Dispute could not be deleted (not found)."));
            exit();
        }
        header("Location: ../admin-dashboard.php?tab=dispute&success=" . urlencode("Dispute deleted permanently."));
        exit();

    default:
        header("Location: ../admin-dashboard.php?error=" . urlencode("Unknown action."));
}
exit();
?>
