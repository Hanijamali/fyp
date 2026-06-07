<?php
// ============================================================
// student-dashboard.php — Real data from DB
// ============================================================
session_start();
require_once "config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user info
$user = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$user->bind_param("i", $user_id);
$user->execute();
$user = $user->get_result()->fetch_assoc();

// Upcoming lessons
$lessons_stmt = $conn->prepare("
    SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS tutor_name
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    JOIN users u ON tp.user_id = u.user_id
    WHERE b.student_id = ? AND b.lesson_date >= CURDATE()
    ORDER BY b.lesson_date ASC, b.lesson_time ASC
    LIMIT 10
");
$lessons_stmt->bind_param("i", $user_id);
$lessons_stmt->execute();
$upcoming_lessons = $lessons_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lessons_stmt->close();

// Stats
$stats_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_lessons,
        COUNT(DISTINCT tutor_id) AS active_tutors
    FROM bookings WHERE student_id = ?
");
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Feedback given (for completed lessons)
$completed_stmt = $conn->prepare("
    SELECT b.booking_id, b.subject, b.lesson_date, b.tutor_id,
           CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
           f.feedback_id
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
    JOIN users u ON tp.user_id = u.user_id
    LEFT JOIN feedback f ON f.booking_id = b.booking_id AND f.student_id = ?
    WHERE b.student_id = ? AND b.status = 'completed'
    ORDER BY b.lesson_date DESC LIMIT 5
");
$completed_stmt->bind_param("ii", $user_id, $user_id);
$completed_stmt->execute();
$completed_lessons = $completed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$completed_stmt->close();

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-student-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php">Find Tutors</a>
      <a href="student-dashboard.php" class="active">Dashboard</a>
      <a href="actions/logout.php">Log Out</a>
    </div>
  </nav>

  <div class="page-content">
    <?php if ($success): ?>
      <div class="alert alert-success" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="dash-grid">
      <div class="glass sidebar">
        <div class="sidebar-avatar">👨‍🎓</div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Student</div>
        <div class="menu-item active" onclick="switchTab('s-overview')">📊 Overview</div>
        <div class="menu-item" onclick="switchTab('s-lessons')">🗓️ My Lessons</div>
        <div class="menu-item" onclick="switchTab('s-feedback')">⭐ Leave Feedback</div>
        <div class="menu-item" onclick="window.location.href='search.php'">🔍 Find Tutors</div>
      </div>

      <div class="dash-main">

        <!-- OVERVIEW TAB -->
        <div id="s-overview">
          <div class="stats-row">
            <div class="glass stat-card">
              <div class="num"><?= $stats['total_lessons'] ?></div>
              <div class="lbl">Lessons Booked</div>
            </div>
            <div class="glass stat-card">
              <div class="num"><?= $stats['active_tutors'] ?></div>
              <div class="lbl">Active Tutors</div>
            </div>
            <div class="glass stat-card">
              <div class="num"><?= count($upcoming_lessons) ?></div>
              <div class="lbl">Upcoming</div>
            </div>
          </div>

          <div class="glass section-card">
            <h3>🗓️ <span>Upcoming Lessons</span></h3>
            <?php if (empty($upcoming_lessons)): ?>
              <p style="opacity:0.6;">No upcoming lessons. <a href="search.php" style="color:var(--teal);">Find a tutor!</a></p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Subject</th><th>Tutor</th><th>Date</th><th>Time</th><th>Duration</th><th>Status</th></tr>
              <?php foreach ($upcoming_lessons as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['subject']) ?></td>
                <td><?= htmlspecialchars($l['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($l['lesson_date'])) ?></td>
                <td><?= date('g:i A', strtotime($l['lesson_time'])) ?></td>
                <td><?= htmlspecialchars($l['duration']) ?></td>
                <td>
                  <span class="status-badge status-<?= $l['status'] ?>">
                    <?= ucfirst($l['status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- MY LESSONS TAB -->
        <div id="s-lessons" style="display:none;">
          <div class="glass section-card">
            <h3>🗓️ <span>All My Lessons</span></h3>
            <?php if (empty($upcoming_lessons) && empty($completed_lessons)): ?>
              <p style="opacity:0.6;">No lessons yet. <a href="search.php" style="color:var(--teal);">Book one now!</a></p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Subject</th><th>Tutor</th><th>Date</th><th>Status</th><th>Amount</th></tr>
              <?php foreach (array_merge($upcoming_lessons, $completed_lessons) as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['subject']) ?></td>
                <td><?= htmlspecialchars($l['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($l['lesson_date'])) ?></td>
                <td><span class="status-badge status-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
                <td>RM <?= number_format($l['total_amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- FEEDBACK TAB -->
        <div id="s-feedback" style="display:none;">
          <div class="glass section-card">
            <h3>⭐ <span>Leave Feedback</span></h3>
            <?php if (empty($completed_lessons)): ?>
              <p style="opacity:0.6;">Complete a lesson first to leave feedback.</p>
            <?php else: ?>
              <?php foreach ($completed_lessons as $l): ?>
              <?php if (!$l['feedback_id']): ?>
              <div style="border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:1.2rem;margin-bottom:1rem;">
                <strong><?= htmlspecialchars($l['subject']) ?></strong> with <?= htmlspecialchars($l['tutor_name']) ?>
                — <?= date('d M Y', strtotime($l['lesson_date'])) ?>
                <form method="POST" action="actions/submit-feedback.php" style="margin-top:0.8rem;">
                  <input type="hidden" name="booking_id" value="<?= $l['booking_id'] ?>">
                  <input type="hidden" name="tutor_id" value="<?= $l['tutor_id'] ?>">
                  <div class="form-group">
                    <label>Rating</label>
                    <select name="rating" required>
                      <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                      <option value="4">⭐⭐⭐⭐ Good</option>
                      <option value="3">⭐⭐⭐ Average</option>
                      <option value="2">⭐⭐ Poor</option>
                      <option value="1">⭐ Very Poor</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Comment</label>
                    <textarea name="comment" rows="2" placeholder="Share your experience..."></textarea>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm">Submit Feedback</button>
                </form>
              </div>
              <?php else: ?>
              <div style="opacity:0.5;margin-bottom:0.5rem;">
                ✅ Feedback submitted for <?= htmlspecialchars($l['subject']) ?> with <?= htmlspecialchars($l['tutor_name']) ?>
              </div>
              <?php endif; ?>
              <?php endforeach; ?>
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
function switchTab(id) {
  ['s-overview','s-lessons','s-feedback'].forEach(t => {
    document.getElementById(t).style.display = 'none';
  });
  document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
  document.getElementById(id).style.display = 'block';
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
