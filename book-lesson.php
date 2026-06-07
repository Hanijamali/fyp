<?php
// ============================================================
// book-lesson.php — Book a Lesson (reads tutor from DB)
// ============================================================
require_once __DIR__ . "/config/session.php";
$bookAs = strtolower(trim((string) ($_GET['as'] ?? '')));
if ($bookAs === 'parent') {
    tf_session_start_role('parent');
} elseif ($bookAs === 'student') {
    tf_session_start_role('student');
} else {
    tf_session_start_any(['student', 'parent']);
}
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); exit();
}

$role = $_SESSION['role'] ?? '';
$user_id = (int) $_SESSION['user_id'];

if ($role !== 'student' && $role !== 'parent') {
    header("Location: login.html"); exit();
}

$tutor_id = intval($_GET['tutor_id'] ?? 0);
if (!$tutor_id) {
    header("Location: search.php"); exit();
}

// Fetch tutor info
$ppSel = tf_column_exists($conn, 'users', 'profile_picture') ? ', u.profile_picture' : '';
$stmt = $conn->prepare("
    SELECT tp.*, CONCAT(u.first_name,' ',u.last_name) AS tutor_name
    {$ppSel}
    FROM tutor_profiles tp
    JOIN users u ON tp.user_id = u.user_id
    WHERE tp.tutor_id = ? AND tp.approved = 1
");
$stmt->bind_param("i", $tutor_id);
$stmt->execute();
$tutor = tf_stmt_one_assoc($stmt);
$stmt->close();

if (!$tutor) {
    header("Location: search.php?error=" . urlencode("Tutor not found."));
    exit();
}

$linked_students = [];
if ($role === 'parent') {
    if (!tf_table_exists($conn, 'parent_students')) {
        header("Location: parent-dashboard.php?error=" . urlencode("Missing parent_students table. Run database_migration.sql."));
        exit();
    }
    $qs = $conn->prepare("
        SELECT su.user_id, CONCAT(su.first_name,' ',su.last_name) AS full_name, su.email
        FROM parent_students ps
        JOIN users su ON su.user_id = ps.student_id AND su.role='student'
        WHERE ps.parent_id = ?
        ORDER BY su.first_name, su.last_name
    ");
    $qs->bind_param("i", $user_id);
    $qs->execute();
    $linked_students = tf_stmt_all_assoc($qs);
    $qs->close();

    if (empty($linked_students)) {
        header("Location: parent-dashboard.php?error=" . urlencode("Link a student first (Parent signup uses child student email)."));
        exit();
    }
}

$error   = $_GET['error']   ?? '';
// Only restore child choice after a validation error (not from search links).
$preselect_student = 0;
if ($role === 'parent' && $error !== '' && isset($_GET['student_id'])) {
    $preselect_student = (int) $_GET['student_id'];
    $allowed_ids = array_map('intval', array_column($linked_students, 'user_id'));
    if (!in_array($preselect_student, $allowed_ids, true)) {
        $preselect_student = 0;
    }
}
$tutor_subject = trim((string) ($tutor['subject'] ?? ''));
if ($tutor_subject === '') {
    $tutor_subject = 'General';
}
$bookPic = tf_profile_picture_url($tutor['profile_picture'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book Lesson - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf7">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-book" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $role ?>-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php<?= tf_session_url_as('?') ?>">Back to Search</a>
      <a href="actions/logout.php?role=<?= urlencode($role) ?>">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:700px;">
    <div class="page-header">
      <h2>Book a <span>Lesson</span></h2>
      <p><?= $role === 'parent'
          ? 'Choose which linked child this lesson is for, then pick date and time.'
          : 'Schedule a session with your chosen tutor' ?></p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form class="glass" style="padding:2rem;color:white;margin-bottom:1.5rem;" method="POST" action="actions/book-lesson.php">
      <input type="hidden" name="tutor_id" value="<?= (int) $tutor['tutor_id'] ?>">
      <input type="hidden" name="subject" value="<?= htmlspecialchars($tutor_subject, ENT_QUOTES, 'UTF-8') ?>">
      <?php if (!empty($_GET['as'])): ?>
      <input type="hidden" name="as" value="<?= htmlspecialchars($_GET['as'], ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>

      <?php if ($role === 'parent'): ?>
      <div class="book-child-panel">
        <label for="book-child-select">👤 Which child is this lesson for?</label>
        <?php if (count($linked_students) > 1): ?>
        <p style="font-size:0.85rem;opacity:0.85;margin:0 0 0.65rem;">You have <?= count($linked_students) ?> linked children — choose one from the list.</p>
        <?php endif; ?>
        <select id="book-child-select" name="student_user_id" class="child-booking-select" required>
          <?php if (count($linked_students) > 1): ?>
          <option value="">— Select a child —</option>
          <?php endif; ?>
          <?php foreach ($linked_students as $ch):
            $sid = (int) $ch['user_id'];
            $sel = ($preselect_student > 0 && $preselect_student === $sid)
                || ($preselect_student === 0 && count($linked_students) === 1);
          ?>
          <option value="<?= $sid ?>" <?= $sel ? 'selected' : '' ?>>
            <?= htmlspecialchars($ch['full_name']) ?> — <?= htmlspecialchars($ch['email']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- Tutor Info -->
      <div class="tutor-public-hero book-lesson-tutor-head" style="margin-bottom:1.5rem;">
        <div class="tutor-avatar tutor-public-avatar<?= $bookPic ? ' has-photo' : '' ?>"><?php if ($bookPic): ?><img src="<?= htmlspecialchars($bookPic) ?>" alt=""><?php else: ?>👩‍🏫<?php endif; ?></div>
        <div class="tutor-public-info">
          <div style="font-weight:900;font-size:1.15rem;"><?= htmlspecialchars($tutor['tutor_name']) ?></div>
          <div style="color:var(--teal);font-size:0.88rem;"><?= htmlspecialchars($tutor_subject) ?></div>
          <div style="color:var(--amber);font-weight:700;">RM <?= number_format($tutor['rate_per_hour'], 0) ?>/hr</div>
          <a href="tutor-public.php?tutor_id=<?= (int) $tutor_id ?>" style="font-size:0.86rem;color:var(--teal);font-weight:700;margin-top:0.35rem;display:inline-block;">View full public profile →</a>
          <div style="font-size:0.82rem;opacity:0.75;margin-top:0.35rem;">Availability: <?= htmlspecialchars($tutor['availability'] ?? 'Weekdays') ?></div>
        </div>
      </div>

      <div class="form-group">
        <label>Lesson subject</label>
        <div style="padding:0.75rem 1rem;background:rgba(0,0,0,0.2);border:1px solid rgba(255,255,255,0.12);border-radius:10px;font-weight:700;">
          <?= htmlspecialchars($tutor_subject) ?>
        </div>
        <p style="font-size:0.78rem;opacity:0.65;margin:0.35rem 0 0;">This tutor lists this subject on their profile. It is sent with your booking automatically.</p>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Preferred Date</label>
          <input type="date" name="lesson_date" id="lesson-date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
          <div id="availability-date-note" style="font-size:0.8rem;opacity:0.7;margin-top:0.3rem;"></div>
        </div>
        <div class="form-group">
          <label>Preferred Time</label>
          <select name="lesson_time" id="lesson-time" required>
            <option value="09:00" data-slot="day">9:00 AM</option>
            <option value="10:00" data-slot="day">10:00 AM</option>
            <option value="11:00" data-slot="day">11:00 AM</option>
            <option value="13:00" data-slot="day">1:00 PM</option>
            <option value="14:00" data-slot="day">2:00 PM</option>
            <option value="15:00" data-slot="day">3:00 PM</option>
            <option value="16:00" data-slot="day">4:00 PM</option>
            <option value="17:00" data-slot="day">5:00 PM</option>
            <option value="18:00" data-slot="evening">6:00 PM</option>
            <option value="19:00" data-slot="evening">7:00 PM</option>
            <option value="20:00" data-slot="evening">8:00 PM</option>
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
const tutorAvailability = <?= json_encode(strtolower((string)($tutor['availability'] ?? 'weekdays'))) ?>;
function updateTotal() {
  const sel = document.getElementById('duration-sel').value;
  let hours = 1;
  if (sel === '1.5 hours') hours = 1.5;
  if (sel === '2 hours') hours = 2;
  document.getElementById('duration-display').textContent = sel;
  document.getElementById('total-display').textContent = 'RM ' + Math.round(baseRate * hours);
}

function isWeekend(dateValue) {
  const dt = new Date(dateValue + 'T00:00:00');
  const day = dt.getDay(); // 0 Sun, 6 Sat
  return day === 0 || day === 6;
}

function refreshAvailabilityUi() {
  const dateInput = document.getElementById('lesson-date');
  const timeSelect = document.getElementById('lesson-time');
  const note = document.getElementById('availability-date-note');
  const selectedDate = dateInput.value;

  if (tutorAvailability === 'weekdays') {
    note.textContent = 'Tutor accepts weekdays only (Mon-Fri).';
  } else if (tutorAvailability === 'weekends') {
    note.textContent = 'Tutor accepts weekends only (Sat-Sun).';
  } else if (tutorAvailability === 'evenings') {
    note.textContent = 'Tutor accepts evening slots only (6:00 PM onwards).';
  } else {
    note.textContent = '';
  }

  Array.from(timeSelect.options).forEach(opt => {
    const isEveningSlot = opt.dataset.slot === 'evening';
    opt.hidden = (tutorAvailability === 'evenings') ? !isEveningSlot : false;
  });
  if (timeSelect.options[timeSelect.selectedIndex] && timeSelect.options[timeSelect.selectedIndex].hidden) {
    const firstVisible = Array.from(timeSelect.options).find(o => !o.hidden);
    if (firstVisible) timeSelect.value = firstVisible.value;
  }

  if (!selectedDate) return;
  const weekend = isWeekend(selectedDate);
  if ((tutorAvailability === 'weekdays' && weekend) || (tutorAvailability === 'weekends' && !weekend)) {
    alert('Selected date does not match tutor availability.');
    dateInput.value = '';
  }
}

document.getElementById('lesson-date').addEventListener('change', refreshAvailabilityUi);
document.addEventListener('DOMContentLoaded', refreshAvailabilityUi);
</script>
</body>
</html>
