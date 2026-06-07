<?php
// ============================================================
// admin-dashboard.php — Real data from DB
// ============================================================
session_start();
require_once "config/db.php";

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

// Disputes
$disputes_result = $conn->query("
    SELECT d.*,
           CONCAT(fu.first_name,' ',fu.last_name) AS filed_by_name,
           CONCAT(au.first_name,' ',au.last_name) AS against_name
    FROM disputes d
    JOIN users fu ON d.filed_by = fu.user_id
    JOIN users au ON d.against = au.user_id
    ORDER BY d.created_at DESC LIMIT 20
");
$disputes = $disputes_result->fetch_all(MYSQLI_ASSOC);

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
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
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="admin-dashboard.php" class="active">Admin Panel</a>
      <a href="actions/logout.php">Log Out</a>
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
        <?php if (empty($disputes)): ?>
          <p style="opacity:0.6;">No disputes filed.</p>
        <?php else: ?>
        <table class="lesson-table">
          <tr><th>ID</th><th>Filed By</th><th>Against</th><th>Issue</th><th>Status</th><th>Action</th></tr>
          <?php foreach ($disputes as $d): ?>
          <tr>
            <td>#<?= $d['dispute_id'] ?></td>
            <td><?= htmlspecialchars($d['filed_by_name']) ?></td>
            <td><?= htmlspecialchars($d['against_name']) ?></td>
            <td><?= htmlspecialchars(substr($d['issue'], 0, 60)) ?>...</td>
            <td><span class="status-badge status-<?= $d['status'] === 'open' ? 'pending' : 'confirmed' ?>"><?= ucfirst($d['status']) ?></span></td>
            <td>
              <?php if ($d['status'] === 'open'): ?>
              <form method="POST" action="actions/admin-action.php" style="display:inline;">
                <input type="hidden" name="dispute_id" value="<?= $d['dispute_id'] ?>">
                <input type="hidden" name="action" value="resolve_dispute">
                <button type="submit" class="btn btn-primary btn-sm">Resolve</button>
              </form>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
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
</script>
</body>
</html>
