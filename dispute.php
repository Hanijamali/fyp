<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'parent', 'tutor'], true)) {
    header("Location: login.html");
    exit();
}

if (!tf_table_exists($conn, 'disputes')) {
    header("Location: {$role}-dashboard.php?error=" . urlencode("Missing disputes table. Run database_setup.sql."));
    exit();
}
if ($role === 'parent' && !tf_table_exists($conn, 'parent_students')) {
    header("Location: parent-dashboard.php?error=" . urlencode("Missing parent_students table. Run database_migration.sql."));
    exit();
}

$bookings = [];
if ($role === 'student') {
    $bs = $conn->prepare("
        SELECT b.booking_id, b.subject, b.lesson_date,
               CONCAT(u.first_name,' ',u.last_name) AS counterparty_name
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE b.student_id = ?
        ORDER BY b.lesson_date DESC
        LIMIT 50
    ");
    $bs->bind_param("i", $uid);
} elseif ($role === 'parent') {
    $bs = $conn->prepare("
        SELECT b.booking_id, b.subject, b.lesson_date,
               CONCAT(u.first_name,' ',u.last_name) AS counterparty_name
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE b.student_id IN (
            SELECT student_id FROM parent_students WHERE parent_id = ?
        )
        ORDER BY b.lesson_date DESC
        LIMIT 50
    ");
    $bs->bind_param("i", $uid);
} else {
    $bs = $conn->prepare("
        SELECT b.booking_id, b.subject, b.lesson_date,
               CONCAT(u.first_name,' ',u.last_name) AS counterparty_name
        FROM bookings b
        JOIN users u ON u.user_id = b.student_id
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        WHERE tp.user_id = ?
        ORDER BY b.lesson_date DESC
        LIMIT 50
    ");
    $bs->bind_param("i", $uid);
}
$bs->execute();
$bookings = tf_stmt_all_assoc($bs);
$bs->close();

$my_disputes = [];
$dispute_extra = '';
if (tf_column_exists($conn, 'disputes', 'resolution_outcome')) {
    $dispute_extra = ', d.resolution_outcome, d.admin_resolution_note, d.resolved_at';
}
$ds = $conn->prepare("
    SELECT d.dispute_id, d.booking_id, d.issue, d.status, d.created_at, d.filed_by, d.against
    {$dispute_extra},
           CONCAT(fu.first_name,' ',fu.last_name) AS filed_by_name,
           CONCAT(au.first_name,' ',au.last_name) AS against_name
    FROM disputes d
    JOIN users fu ON fu.user_id = d.filed_by
    JOIN users au ON au.user_id = d.against
    WHERE d.filed_by = ? OR d.against = ?
    ORDER BY d.created_at DESC
    LIMIT 50
");
$ds->bind_param("ii", $uid, $uid);
$ds->execute();
$my_disputes = tf_stmt_all_assoc($ds);
$ds->close();

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$outcome_labels = [
    'refund_student' => 'Refund / compensation to student side',
    'credit_lesson' => 'Lesson credit or reschedule',
    'warn_party' => 'Warning or behaviour notice',
    'no_action' => 'No further action (case dismissed)',
    'escalate_partner' => 'Escalated externally (bank / gateway)',
    'other' => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Disputes - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $role ?>-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="<?= $role ?>-dashboard.php">Dashboard</a>
      <a href="dispute.php" class="active">Disputes</a>
      <a href="actions/logout.php?role=<?= urlencode($role) ?>">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:980px;">
    <div class="page-header">
      <h2>Dispute <span>Center</span></h2>
      <p>Report a booking issue for admin review</p>
    </div>
    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="glass section-card" style="margin-bottom:1rem;">
      <h3>⚖️ <span>File New Dispute</span></h3>
      <?php if (empty($bookings)): ?>
        <p style="opacity:0.6;">No bookings available.</p>
      <?php else: ?>
      <form method="POST" action="actions/create-dispute.php">
        <div class="form-row">
          <div class="form-group">
            <label>Booking</label>
            <select name="booking_id" required>
              <?php foreach ($bookings as $b): ?>
              <option value="<?= (int)$b['booking_id'] ?>">
                #<?= (int)$b['booking_id'] ?> · <?= htmlspecialchars($b['subject']) ?> · <?= date('d M Y', strtotime($b['lesson_date'])) ?> · <?= htmlspecialchars($b['counterparty_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Issue details</label>
          <textarea name="issue" rows="4" maxlength="2000" placeholder="Explain what happened and what outcome you need." required></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Submit Dispute</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="glass section-card">
      <h3>📋 <span>My Disputes</span></h3>
      <?php if (empty($my_disputes)): ?>
        <p style="opacity:0.6;">No disputes filed yet.</p>
      <?php else: ?>
      <table class="lesson-table">
        <tr><th>ID</th><th>Booking</th><th>Your role</th><th>Other party</th><th>Status</th><th>Resolution</th><th>Date</th></tr>
        <?php foreach ($my_disputes as $d): ?>
        <?php
          $i_filed = ((int)$d['filed_by'] === $uid);
          $role_lbl = $i_filed ? 'You filed' : 'Named party';
          $other = $i_filed ? $d['against_name'] : $d['filed_by_name'];
        ?>
        <tr>
          <td>#<?= (int)$d['dispute_id'] ?></td>
          <td>#<?= (int)$d['booking_id'] ?></td>
          <td><?= htmlspecialchars($role_lbl) ?></td>
          <td><?= htmlspecialchars($other) ?></td>
          <td><?= ucfirst($d['status']) ?></td>
          <td style="max-width:220px;font-size:0.85rem;">
            <?php if ($d['status'] === 'resolved'): ?>
              <?php if (!empty($d['resolution_outcome']) || !empty($d['admin_resolution_note'])): ?>
                <?php if (!empty($d['resolution_outcome'])): ?>
                  <strong><?= htmlspecialchars($outcome_labels[$d['resolution_outcome']] ?? $d['resolution_outcome']) ?></strong>
                <?php endif; ?>
                <?php if (!empty($d['admin_resolution_note'])): ?>
                <details style="margin-top:0.35rem;">
                  <summary style="cursor:pointer;opacity:0.85;">Admin decision</summary>
                  <div style="margin-top:0.4rem;white-space:pre-wrap;opacity:0.9;"><?= htmlspecialchars($d['admin_resolution_note']) ?></div>
                  <?php if (!empty($d['resolved_at'])): ?>
                    <div style="opacity:0.6;font-size:0.78rem;margin-top:0.35rem;"><?= date('d M Y g:i A', strtotime($d['resolved_at'])) ?></div>
                  <?php endif; ?>
                </details>
                <?php endif; ?>
              <?php else: ?>
                <span style="opacity:0.75;">Resolved</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="opacity:0.6;">Pending admin review</span>
            <?php endif; ?>
          </td>
          <td><?= date('d M Y g:i A', strtotime($d['created_at'])) ?></td>
        </tr>
        <tr>
          <td colspan="7" style="font-size:0.88rem;opacity:0.85;padding-top:0;padding-bottom:0.75rem;">
            <strong>Issue:</strong> <?= nl2br(htmlspecialchars(strlen($d['issue']) > 280 ? substr($d['issue'], 0, 280) . '…' : $d['issue'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
