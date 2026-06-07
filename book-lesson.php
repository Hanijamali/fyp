<?php
// ============================================================
// book-lesson.php — Book a Lesson (reads tutor from DB)
// ============================================================
session_start();
require_once "config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.html"); exit();
}

$tutor_id = intval($_GET['tutor_id'] ?? 0);
if (!$tutor_id) {
    header("Location: search.php"); exit();
}

// Fetch tutor info
$stmt = $conn->prepare("
    SELECT tp.*, CONCAT(u.first_name,' ',u.last_name) AS tutor_name
    FROM tutor_profiles tp
    JOIN users u ON tp.user_id = u.user_id
    WHERE tp.tutor_id = ? AND tp.approved = 1
");
$stmt->bind_param("i", $tutor_id);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tutor) {
    header("Location: search.php?error=" . urlencode("Tutor not found."));
    exit();
}

$error   = $_GET['error']   ?? '';
$subjects = ['Mathematics','Additional Mathematics','Physics','Chemistry','Biology','English Language','Computer Science','Bahasa Malaysia'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Lesson - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-book" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php">Back to Search</a>
      <a href="actions/logout.php">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:700px;">
    <div class="page-header">
      <h2>Book a <span>Lesson</span></h2>
      <p>Schedule a session with your chosen tutor</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form class="glass" style="padding:2rem;color:white;margin-bottom:1.5rem;" method="POST" action="actions/book-lesson.php">
      <input type="hidden" name="tutor_id" value="<?= $tutor['tutor_id'] ?>">

      <!-- Tutor Info -->
      <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
        <div class="tutor-avatar">👩‍🏫</div>
        <div>
          <div style="font-weight:900;font-size:1.15rem;"><?= htmlspecialchars($tutor['tutor_name']) ?></div>
          <div style="color:var(--teal);font-size:0.88rem;"><?= htmlspecialchars($tutor['subject']) ?></div>
          <div style="color:var(--amber);font-weight:700;">RM <?= number_format($tutor['rate_per_hour'], 0) ?>/hr</div>
        </div>
      </div>

      <div class="form-group">
        <label>Lesson Subject</label>
        <select name="subject" required>
          <?php foreach ($subjects as $s): ?>
          <option <?= $tutor['subject'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Preferred Date</label>
          <input type="date" name="lesson_date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
        </div>
        <div class="form-group">
          <label>Preferred Time</label>
          <select name="lesson_time" required>
            <option value="09:00">9:00 AM</option>
            <option value="10:00">10:00 AM</option>
            <option value="11:00">11:00 AM</option>
            <option value="13:00">1:00 PM</option>
            <option value="14:00">2:00 PM</option>
            <option value="15:00">3:00 PM</option>
            <option value="16:00">4:00 PM</option>
            <option value="17:00">5:00 PM</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Duration</label>
        <select name="duration" id="duration-sel" onchange="updateTotal()">
          <option value="1 hour">1 hour</option>
          <option value="1.5 hours">1.5 hours</option>
          <option value="2 hours">2 hours</option>
        </select>
      </div>

      <div class="form-group">
        <label>Notes (optional)</label>
        <textarea name="notes" rows="3" placeholder="Add lesson topics or learning requirements (optional)"></textarea>
      </div>

      <!-- Price Summary -->
      <div style="background:rgba(46,196,182,0.08);border:1px solid rgba(46,196,182,0.2);border-radius:10px;padding:1rem;margin-bottom:1.2rem;">
        <div style="display:flex;justify-content:space-between;font-size:0.9rem;margin-bottom:0.4rem;">
          <span>Rate</span><span>RM <?= number_format($tutor['rate_per_hour'], 0) ?>/hr</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.9rem;margin-bottom:0.4rem;">
          <span>Duration</span><span id="duration-display">1 hour</span>
        </div>
        <hr class="divider">
        <div style="display:flex;justify-content:space-between;font-weight:900;font-size:1.05rem;">
          <span>Total</span>
          <span style="color:var(--teal);" id="total-display">RM <?= number_format($tutor['rate_per_hour'], 0) ?></span>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
        🗓️ Confirm Booking
      </button>
    </form>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>

<script>
const baseRate = <?= $tutor['rate_per_hour'] ?>;
function updateTotal() {
  const sel = document.getElementById('duration-sel').value;
  let hours = 1;
  if (sel === '1.5 hours') hours = 1.5;
  if (sel === '2 hours') hours = 2;
  document.getElementById('duration-display').textContent = sel;
  document.getElementById('total-display').textContent = 'RM ' + Math.round(baseRate * hours);
}
</script>
</body>
</html>
