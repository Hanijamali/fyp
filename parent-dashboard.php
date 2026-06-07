<?php
// ============================================================
// parent-dashboard.php — Real data from DB
// ============================================================
session_start();
require_once "config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];

// Fetch parent info
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch all bookings made by this parent (parents can also book for their child)
$bookings_stmt = $conn->prepare("
    SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS tutor_name
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    JOIN users u ON tp.user_id = u.user_id
    WHERE b.student_id = ?
    ORDER BY b.lesson_date DESC
    LIMIT 20
");
$bookings_stmt->bind_param("i", $user_id);
$bookings_stmt->execute();
$bookings = $bookings_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$bookings_stmt->close();

// Stats
$total_spent    = array_sum(array_column($bookings, 'total_amount'));
$active_tutors  = count(array_unique(array_column($bookings, 'tutor_id')));
$total_lessons  = count($bookings);
$upcoming       = array_filter($bookings, fn($b) => $b['lesson_date'] >= date('Y-m-d') && $b['status'] !== 'cancelled');

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parent Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-parent-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php">Find Tutors</a>
      <a href="parent-dashboard.php" class="active">Dashboard</a>
      <a href="actions/logout.php">Log Out</a>
    </div>
  </nav>

  <div class="page-content">
    <?php if ($success): ?>
      <div class="alert alert-success" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="dash-grid">
      <div class="glass sidebar">
        <div class="sidebar-avatar">👪</div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Parent</div>
        <div class="menu-item active" onclick="switchParentTab('p-overview')">📊 Overview</div>
        <div class="menu-item" onclick="switchParentTab('p-lessons')">🗓️ Lesson History</div>
        <div class="menu-item" onclick="window.location.href='search.php'">🔍 Find Tutor</div>
      </div>

      <div class="main-dash">

        <!-- OVERVIEW -->
        <div id="p-overview">
          <div class="stats-row">
            <div class="glass stat-card"><div class="num"><?= $active_tutors ?></div><div class="lbl">Active Tutors</div></div>
            <div class="glass stat-card"><div class="num"><?= $total_lessons ?></div><div class="lbl">Lessons Booked</div></div>
            <div class="glass stat-card"><div class="num">RM <?= number_format($total_spent, 0) ?></div><div class="lbl">Total Spent</div></div>
            <div class="glass stat-card"><div class="num"><?= count($upcoming) ?></div><div class="lbl">Upcoming</div></div>
          </div>

          <div class="glass section-card">
            <h3>🗓️ <span>Upcoming Lessons</span></h3>
            <?php if (empty($upcoming)): ?>
              <p style="opacity:0.6;">No upcoming lessons. <a href="search.php" style="color:var(--teal);">Find a tutor!</a></p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Subject</th><th>Tutor</th><th>Date</th><th>Time</th><th>Status</th></tr>
              <?php foreach ($upcoming as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= htmlspecialchars($b['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($b['lesson_date'])) ?></td>
                <td><?= date('g:i A', strtotime($b['lesson_time'])) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- LESSON HISTORY -->
        <div id="p-lessons" style="display:none;">
          <div class="glass section-card">
            <h3>📋 <span>All Lessons</span></h3>
            <?php if (empty($bookings)): ?>
              <p style="opacity:0.6;">No lessons booked yet.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Subject</th><th>Tutor</th><th>Date</th><th>Amount</th><th>Status</th></tr>
              <?php foreach ($bookings as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= htmlspecialchars($b['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($b['lesson_date'])) ?></td>
                <td>RM <?= number_format($b['total_amount'], 2) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>

<script src="js/script.js"></script>
<script>
function switchParentTab(id) {
  ['p-overview','p-lessons'].forEach(t => {
    document.getElementById(t).style.display = 'none';
  });
  document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
  document.getElementById(id).style.display = 'block';
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
