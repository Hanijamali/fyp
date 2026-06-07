<?php
// ============================================================
// admin-dashboard.php — Real data from DB
// ============================================================
require_once __DIR__ . "/config/session.php";
tf_session_start_role('admin');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.html"); exit();
}

// Platform-wide stats
$total_users     = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$total_tutors    = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='tutor'")->fetch_assoc()['c'];
$total_students  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='student'")->fetch_assoc()['c'];
$total_parents   = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='parent'")->fetch_assoc()['c'];
$pending_tutors  = $conn->query("SELECT COUNT(*) AS c FROM tutor_profiles WHERE approved=0")->fetch_assoc()['c'];
$total_revenue   = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM bookings WHERE status='completed'")->fetch_assoc()['s'];
$total_bookings  = $conn->query("SELECT COUNT(*) AS c FROM bookings")->fetch_assoc()['c'];

// All users
$users_result = $conn->query("SELECT user_id, first_name, last_name, email, role, status, created_at FROM users ORDER BY created_at DESC LIMIT 50");
$all_users = $users_result->fetch_all(MYSQLI_ASSOC);

// Pending tutor approvals
$pending_result = $conn->query("
    SELECT tp.tutor_id, tp.subject, tp.qualifications, tp.approved, tp.user_id,
           CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
           u.email, u.created_at
    FROM tutor_profiles tp
    JOIN users u ON tp.user_id = u.user_id
    WHERE tp.approved = 0
    ORDER BY u.created_at DESC
");
$pending_tutors_list = $pending_result->fetch_all(MYSQLI_ASSOC);

// Feedback
$feedback_result = $conn->query("
    SELECT f.*, 
           CONCAT(su.first_name,' ',su.last_name) AS student_name,
           CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name
    FROM feedback f
    JOIN users su ON f.student_id = su.user_id
    JOIN tutor_profiles tp ON f.tutor_id = tp.tutor_id
    JOIN users tu ON tp.user_id = tu.user_id
    ORDER BY f.created_at DESC LIMIT 20
");
$feedbacks = $feedback_result->fetch_all(MYSQLI_ASSOC);

// Disputes (with booking context for real resolution)
$disputes = [];
if (tf_table_exists($conn, 'disputes')) {
    $has_resolved_by = tf_column_exists($conn, 'disputes', 'resolved_by');
    $res_join = $has_resolved_by ? "LEFT JOIN users ru ON ru.user_id = d.resolved_by" : "";
    $res_select = $has_resolved_by
        ? ", CONCAT(ru.first_name,' ',ru.last_name) AS resolved_by_name"
        : ", NULL AS resolved_by_name";
    $sql = "
        SELECT d.*,
               CONCAT(fu.first_name,' ',fu.last_name) AS filed_by_name,
               fu.email AS filed_by_email,
               CONCAT(au.first_name,' ',au.last_name) AS against_name,
               au.email AS against_email,
               b.subject AS booking_subject,
               b.lesson_date AS booking_lesson_date,
               b.total_amount AS booking_amount,
               b.status AS booking_status
               {$res_select}
        FROM disputes d
        JOIN users fu ON d.filed_by = fu.user_id
        JOIN users au ON d.against = au.user_id
        LEFT JOIN bookings b ON b.booking_id = d.booking_id
        {$res_join}
        ORDER BY (d.status = 'open') DESC, d.created_at DESC
        LIMIT 40
    ";
    $disputes_result = $conn->query($sql);
    if ($disputes_result) {
        $disputes = $disputes_result->fetch_all(MYSQLI_ASSOC);
    }
}

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
$tab     = $_GET['tab'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-admin-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='admin-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="admin-dashboard.php" class="active">Admin Panel</a>
      <a href="my-profile.php">My profile</a>
      <a href="actions/logout.php?role=admin">Log Out</a>
    </div>
  </nav>

  <div class="page-content">
    <?php if ($success): ?>
      <div class="alert alert-success" style="max-width:1100px;margin:0 auto 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error" style="max-width:1100px;margin:0 auto 1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="page-header">
      <h2>Admin <span>Panel</span> 🛡️</h2>
      <p>Real-time system overview</p>
    </div>

    <div class="stats-row" style="margin-bottom:1.5rem;">
      <div class="glass stat-card"><div class="num"><?= $total_users ?></div><div class="lbl">Total Users</div></div>
      <div class="glass stat-card"><div class="num"><?= $total_tutors ?></div><div class="lbl">Tutors</div></div>
      <div class="glass stat-card"><div class="num"><?= $total_students ?></div><div class="lbl">Students</div></div>
      <div class="glass stat-card"><div class="num"><?= $total_parents ?></div><div class="lbl">Parents</div></div>
      <div class="glass stat-card"><div class="num">RM <?= number_format($total_revenue, 0) ?></div><div class="lbl">Revenue</div></div>
      <div class="glass stat-card"><div class="num"><?= $total_bookings ?></div><div class="lbl">Bookings</div></div>
    </div>

    <div class="tab-wrap" style="margin-bottom:1.5rem;">
      <button class="tab-btn active" onclick="adminTab(this,'a-users')">👥 Users</button>
      <button class="tab-btn" onclick="adminTab(this,'a-tutors')">
        Tutor Approvals<?= $pending_tutors > 0 ? " ($pending_tutors)" : '' ?>
      </button>
      <button class="tab-btn" onclick="adminTab(this,'a-feedback')">Feedback</button>
      <button class="tab-btn" onclick="adminTab(this,'a-dispute')">Disputes</button>
    </div>

    <!-- USERS -->
    <div id="a-users">
      <div class="glass section-card">
        <h3>👥 <span>All Users</span></h3>
        <table class="lesson-table">
          <tr><th>#</th><th>Name</th><th>Role</th><th>Email</th><th>Joined</th><th>Status</th><th>Action</th></tr>
          <?php foreach ($all_users as $i => $u): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></td>
            <td><?= ucfirst($u['role']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td><span class="status-badge status-<?= $u['status'] === 'active' ? 'confirmed' : 'pending' ?>"><?= ucfirst($u['status']) ?></span></td>
            <td>
              <form method="POST" action="actions/admin-action.php" style="display:inline;">
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                <input type="hidden" name="action" value="<?= $u['status'] === 'active' ? 'suspend' : 'activate' ?>">
                <button type="submit" class="btn <?= $u['status'] === 'active' ? 'btn-danger' : 'btn-primary' ?> btn-sm">
                  <?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- TUTOR APPROVALS -->
    <div id="a-tutors" style="display:none;">
      <div class="glass section-card">
        <h3>✅ Pending Tutor <span>Approvals</span></h3>
        <?php if (empty($pending_tutors_list)): ?>
          <p style="opacity:0.6;">No pending tutor approvals. 🎉</p>
        <?php else: ?>
        <table class="lesson-table">
          <tr><th>Name</th><th>Email</th><th>Subject</th><th>Qualification</th><th>Applied</th><th>Action</th></tr>
          <?php foreach ($pending_tutors_list as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['tutor_name']) ?></td>
            <td><?= htmlspecialchars($t['email']) ?></td>
            <td><?= htmlspecialchars($t['subject'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['qualifications'] ?? '—') ?></td>
            <td><?= date('d M Y', strtotime($t['created_at'])) ?></td>
            <td style="display:flex;gap:0.4rem;">
              <form method="POST" action="actions/admin-action.php" style="display:inline;">
                <input type="hidden" name="tutor_id" value="<?= $t['tutor_id'] ?>">
                <input type="hidden" name="action" value="approve_tutor">
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              </form>
              <form method="POST" action="actions/admin-action.php" style="display:inline;">
                <input type="hidden" name="tutor_id" value="<?= $t['tutor_id'] ?>">
                <input type="hidden" name="user_id" value="<?= $t['user_id'] ?>">
                <input type="hidden" name="action" value="reject_tutor">
                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- FEEDBACK -->
    <div id="a-feedback" style="display:none;">
      <div class="glass section-card">
        <h3>💬 User <span>Feedback</span></h3>
        <?php if (empty($feedbacks)): ?>
          <p style="opacity:0.6;">No feedback submitted yet.</p>
        <?php else: ?>
        <table class="lesson-table">
          <tr><th>Student</th><th>Tutor</th><th>Rating</th><th>Comment</th><th>Date</th></tr>
          <?php foreach ($feedbacks as $f): ?>
          <tr>
            <td><?= htmlspecialchars($f['student_name']) ?></td>
            <td><?= htmlspecialchars($f['tutor_name']) ?></td>
            <td><?= str_repeat('⭐', $f['rating']) ?></td>
            <td><?= htmlspecialchars($f['comment'] ?? '—') ?></td>
            <td><?= date('d M Y', strtotime($f['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- DISPUTES -->
    <div id="a-dispute" style="display:none;">
      <div class="glass section-card">
        <h3>⚖️ <span>Dispute Resolution</span></h3>
        <p style="opacity:0.75;font-size:0.9rem;margin-bottom:1rem;">Open cases first. To close a dispute you must record an <strong>outcome</strong> and a clear <strong>written decision</strong> (users see this on their Disputes page).</p>
        <?php if (empty($disputes)): ?>
          <p style="opacity:0.6;">No disputes filed.</p>
        <?php else: ?>
          <?php
          $outcome_labels = [
              'refund_student' => 'Refund / compensation to student side',
              'credit_lesson' => 'Lesson credit or reschedule',
              'warn_party' => 'Warning or behaviour notice to a party',
              'no_action' => 'No further action (case dismissed)',
              'escalate_partner' => 'Escalated externally (bank / gateway)',
              'other' => 'Other (explain in note)',
          ];
          ?>
          <?php foreach ($disputes as $d): ?>
          <details style="border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:0.75rem 1rem;margin-bottom:0.85rem;background:rgba(0,0,0,0.12);" <?= $d['status'] === 'open' ? 'open' : '' ?>>
            <summary style="cursor:pointer;font-weight:800;list-style-position:outside;">
              #<?= (int)$d['dispute_id'] ?> · <?= htmlspecialchars($d['filed_by_name']) ?> vs <?= htmlspecialchars($d['against_name']) ?>
              <span class="status-badge status-<?= $d['status'] === 'open' ? 'pending' : 'confirmed' ?>" style="margin-left:0.35rem;"><?= ucfirst($d['status']) ?></span>
            </summary>
            <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid rgba(255,255,255,0.08);font-size:0.92rem;">
              <p style="margin:0 0 0.5rem;"><strong>Booking</strong> #<?= (int)$d['booking_id'] ?>
                <?php if (!empty($d['booking_subject'])): ?>
                  · <?= htmlspecialchars($d['booking_subject']) ?>
                  · <?= !empty($d['booking_lesson_date']) ? date('d M Y', strtotime($d['booking_lesson_date'])) : '—' ?>
                  · <?= !empty($d['booking_amount']) ? 'RM ' . number_format((float)$d['booking_amount'], 2) : '—' ?>
                  · <?= htmlspecialchars(ucfirst($d['booking_status'] ?? '')) ?>
                <?php endif; ?>
              </p>
              <p style="margin:0 0 0.35rem;"><strong>Filed by</strong> <?= htmlspecialchars($d['filed_by_name']) ?> &lt;<?= htmlspecialchars($d['filed_by_email'] ?? '') ?>&gt;</p>
              <p style="margin:0 0 0.75rem;"><strong>Against</strong> <?= htmlspecialchars($d['against_name']) ?> &lt;<?= htmlspecialchars($d['against_email'] ?? '') ?>&gt;</p>
              <p style="margin:0 0 0.35rem;"><strong>Issue reported</strong></p>
              <div style="white-space:pre-wrap;opacity:0.92;padding:0.65rem;background:rgba(0,0,0,0.2);border-radius:8px;margin-bottom:0.75rem;"><?= htmlspecialchars($d['issue']) ?></div>

              <?php if ($d['status'] === 'resolved'): ?>
                <?php if (!empty($d['admin_resolution_note']) || !empty($d['resolution_outcome'])): ?>
                <p style="margin:0 0 0.25rem;"><strong>Outcome</strong> <?= htmlspecialchars($outcome_labels[$d['resolution_outcome'] ?? ''] ?? ($d['resolution_outcome'] ?? '—')) ?></p>
                <p style="margin:0 0 0.25rem;"><strong>Admin decision</strong></p>
                <div style="white-space:pre-wrap;opacity:0.92;padding:0.65rem;background:rgba(46,196,182,0.1);border-radius:8px;border:1px solid rgba(46,196,182,0.25);"><?= htmlspecialchars($d['admin_resolution_note'] ?? '—') ?></div>
                <p style="opacity:0.65;font-size:0.82rem;margin-top:0.5rem;">
                  Resolved <?= !empty($d['resolved_at']) ? date('d M Y g:i A', strtotime($d['resolved_at'])) : '—' ?>
                  <?php if (!empty($d['resolved_by_name'])): ?> · <?= htmlspecialchars($d['resolved_by_name']) ?><?php endif; ?>
                </p>
                <?php else: ?>
                <p style="opacity:0.75;margin:0;">Marked resolved before written decisions were required. Future cases will show full outcome and notes here.</p>
                <?php endif; ?>
              <?php else: ?>
                <?php if (tf_column_exists($conn, 'disputes', 'admin_resolution_note')): ?>
                <form method="POST" action="actions/admin-action.php" style="max-width:640px;">
                  <input type="hidden" name="action" value="resolve_dispute">
                  <input type="hidden" name="dispute_id" value="<?= (int)$d['dispute_id'] ?>">
                  <div class="form-group">
                    <label>Resolution outcome</label>
                    <select name="resolution_outcome" required>
                      <?php foreach ($outcome_labels as $val => $lbl): ?>
                        <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Written decision (visible to both parties)</label>
                    <textarea name="admin_resolution_note" rows="5" minlength="15" maxlength="4000" required placeholder="Summarise findings, what you decided, and any next steps (e.g. manual refund, warning issued, case closed). Minimum 15 characters."></textarea>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Resolve this dispute and publish the decision to the users?');">Publish resolution &amp; close case</button>
                </form>
                <?php else: ?>
                  <p class="alert alert-error" style="margin:0;">Run <code>database_migration.sql</code> to add dispute resolution columns.</p>
                <?php endif; ?>
              <?php endif; ?>
              <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:1rem 0;">
              <form method="POST" action="actions/admin-action.php" style="display:inline;" onsubmit="return confirm('Permanently delete this dispute? This cannot be undone.');">
                <input type="hidden" name="action" value="delete_dispute">
                <input type="hidden" name="dispute_id" value="<?= (int)$d['dispute_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete dispute</button>
              </form>
            </div>
          </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>

<script src="js/script.js"></script>
<script>
function adminTab(btn, id) {
  ['a-users','a-tutors','a-feedback','a-dispute'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById(id).style.display = 'block';
}
document.addEventListener('DOMContentLoaded', function() {
  const tab = <?= json_encode($tab) ?>;
  if (tab === 'dispute') {
    const btn = document.querySelector('.tab-btn[onclick*="a-dispute"]');
    if (btn) adminTab(btn, 'a-dispute');
  }
});
</script>
</body>
</html>
