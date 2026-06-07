<?php
// ============================================================
// tutor-dashboard.php — Real data from DB
// ============================================================
session_start();
require_once "config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user and tutor profile
$stmt = $conn->prepare("
    SELECT u.*, tp.tutor_id, tp.subject, tp.rate_per_hour, tp.bio,
           tp.qualifications, tp.experience_years, tp.availability,
           tp.rating, tp.total_reviews, tp.approved
    FROM users u
    LEFT JOIN tutor_profiles tp ON tp.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tutor_id = $user['tutor_id'];

// Booking requests
$requests = $conn->prepare("
    SELECT b.*, CONCAT(u.first_name,' ',u.last_name) AS student_name
    FROM bookings b
    JOIN users u ON b.student_id = u.user_id
    WHERE b.tutor_id = ?
    ORDER BY b.created_at DESC
    LIMIT 20
");
$requests->bind_param("i", $tutor_id);
$requests->execute();
$all_bookings = $requests->get_result()->fetch_all(MYSQLI_ASSOC);
$requests->close();

// Stats
$total_lessons  = count($all_bookings);
$active_students = count(array_unique(array_column($all_bookings, 'student_id')));
$total_earned   = array_sum(array_column(
    array_filter($all_bookings, fn($b) => $b['status'] === 'completed'),
    'total_amount'
));

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tutor Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-tutor-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="tutor-dashboard.php" class="active">My Dashboard</a>
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
        <div class="sidebar-avatar">👩‍🏫</div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Tutor — <?= htmlspecialchars($user['subject'] ?? 'General') ?></div>
        <div class="menu-item active" onclick="switchTutorTab('t-overview')">📊 Overview</div>
        <div class="menu-item" onclick="switchTutorTab('t-requests')">📩 Lesson Requests</div>
        <div class="menu-item" onclick="switchTutorTab('t-profile')">👤 Profile</div>
        <div class="menu-item" onclick="switchTutorTab('t-payment')">💰 Payment</div>
      </div>

      <div class="main-dash" id="tutor-main">

        <!-- OVERVIEW -->
        <div id="t-overview">
          <div class="stats-row">
            <div class="glass stat-card"><div class="num"><?= $total_lessons ?></div><div class="lbl">Total Lessons</div></div>
            <div class="glass stat-card"><div class="num"><?= $active_students ?></div><div class="lbl">Students</div></div>
            <div class="glass stat-card"><div class="num"><?= $user['rating'] > 0 ? $user['rating'] . ' ⭐' : 'N/A' ?></div><div class="lbl">Rating</div></div>
            <div class="glass stat-card"><div class="num">RM <?= number_format($total_earned, 0) ?></div><div class="lbl">Earned</div></div>
          </div>

          <?php if (!$user['approved']): ?>
          <div class="glass section-card" style="border:1px solid rgba(255,193,7,0.4);">
            <p style="color:var(--amber);font-weight:700;">⏳ Your tutor profile is pending admin approval. You will appear in search results once approved.</p>
          </div>
          <?php endif; ?>

          <div class="glass section-card">
            <h3>📩 Pending <span>Requests</span></h3>
            <?php $pending = array_filter($all_bookings, fn($b) => $b['status'] === 'pending'); ?>
            <?php if (empty($pending)): ?>
              <p style="opacity:0.6;">No pending requests.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Student</th><th>Subject</th><th>Date</th><th>Time</th><th>Duration</th><th>Actions</th></tr>
              <?php foreach ($pending as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['student_name']) ?></td>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= date('d M Y', strtotime($b['lesson_date'])) ?></td>
                <td><?= date('g:i A', strtotime($b['lesson_time'])) ?></td>
                <td><?= htmlspecialchars($b['duration']) ?></td>
                <td style="display:flex;gap:0.4rem;">
                  <form method="POST" action="actions/update-booking.php" style="display:inline;">
                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                    <input type="hidden" name="action" value="confirmed">
                    <button type="submit" class="btn btn-primary btn-sm">Accept</button>
                  </form>
                  <form method="POST" action="actions/update-booking.php" style="display:inline;">
                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                    <input type="hidden" name="action" value="cancelled">
                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- ALL REQUESTS -->
        <div id="t-requests" style="display:none;">
          <div class="glass section-card">
            <h3>📩 All <span>Lesson Requests</span></h3>
            <?php if (empty($all_bookings)): ?>
              <p style="opacity:0.6;">No bookings yet.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Student</th><th>Subject</th><th>Date & Time</th><th>Duration</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
              <?php foreach ($all_bookings as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['student_name']) ?></td>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= date('d M', strtotime($b['lesson_date'])) ?>, <?= date('g:i A', strtotime($b['lesson_time'])) ?></td>
                <td><?= htmlspecialchars($b['duration']) ?></td>
                <td>RM <?= number_format($b['total_amount'], 2) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                <td>
                  <?php if ($b['status'] === 'pending'): ?>
                  <div style="display:flex;gap:0.4rem;">
                    <form method="POST" action="actions/update-booking.php" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                      <input type="hidden" name="action" value="confirmed">
                      <button type="submit" class="btn btn-primary btn-sm">Accept</button>
                    </form>
                    <form method="POST" action="actions/update-booking.php" style="display:inline;">
                      <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                      <input type="hidden" name="action" value="cancelled">
                      <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                    </form>
                  </div>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- PROFILE -->
        <div id="t-profile" style="display:none;">
          <div class="glass section-card">
            <h3>👤 Update <span>Profile</span></h3>
            <form method="POST" action="actions/update-tutor-profile.php">
              <div class="form-row">
                <div class="form-group">
                  <label>Subject</label>
                  <select name="subject">
                    <?php foreach (['Mathematics','Physics','Chemistry','Biology','English Language','Bahasa Malaysia','Computer Science','Additional Mathematics'] as $s): ?>
                    <option <?= ($user['subject'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Availability</label>
                  <select name="availability">
                    <?php foreach (['Weekdays','Weekends','Evenings'] as $a): ?>
                    <option <?= ($user['availability'] ?? '') === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Rate per Hour (RM)</label>
                  <input type="number" name="rate_per_hour" value="<?= htmlspecialchars($user['rate_per_hour'] ?? '') ?>" min="0" step="5">
                </div>
                <div class="form-group">
                  <label>Experience (Years)</label>
                  <input type="number" name="experience_years" value="<?= htmlspecialchars($user['experience_years'] ?? '') ?>" min="0">
                </div>
              </div>
              <div class="form-group">
                <label>Qualifications</label>
                <input type="text" name="qualifications" value="<?= htmlspecialchars($user['qualifications'] ?? '') ?>" placeholder="e.g. PhD Mathematics, UM">
              </div>
              <div class="form-group">
                <label>Bio</label>
                <textarea name="bio" rows="3" placeholder="Write a short professional bio"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
              </div>
              <button type="submit" class="btn btn-primary">Save Profile</button>
            </form>
          </div>
        </div>

        <!-- PAYMENT INFO (display only) -->
        <div id="t-payment" style="display:none;">
          <div class="glass section-card">
            <h3>💰 <span>Earnings Summary</span></h3>
            <div class="stats-row">
              <div class="glass stat-card"><div class="num">RM <?= number_format($total_earned, 2) ?></div><div class="lbl">Total Earned</div></div>
              <div class="glass stat-card">
                <div class="num">
                  <?php
                  $confirmed = array_filter($all_bookings, fn($b) => $b['status'] === 'confirmed');
                  echo 'RM ' . number_format(array_sum(array_column($confirmed, 'total_amount')), 2);
                  ?>
                </div>
                <div class="lbl">Upcoming Payouts</div>
              </div>
            </div>
            <p style="opacity:0.6;font-size:0.88rem;margin-top:1rem;">For payment setup (bank account etc.), please contact the TutorFind admin.</p>
          </div>
        </div>

      </div>
    </div>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>

<script src="js/script.js"></script>
<script>
function switchTutorTab(id) {
  ['t-overview','t-requests','t-profile','t-payment'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
  document.getElementById(id).style.display = 'block';
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
