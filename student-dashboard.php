<?php
// ============================================================
// student-dashboard.php — Real data from DB
// ============================================================
require_once __DIR__ . "/config/session.php";
tf_session_start_role('student');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];
$schema_error = '';

// Fetch user info
$user = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$user->bind_param("i", $user_id);
$user->execute();
$user = tf_stmt_one_assoc($user);
$user = $user ?: ['first_name' => '', 'last_name' => ''];

$upcoming_lessons = [];
$completed_lessons = [];
$materials = [];
$assignments = [];
$stats = ['total_lessons' => 0, 'active_tutors' => 0];

$has_bookings = tf_table_exists($conn, 'bookings');
$has_tutor_profiles = tf_table_exists($conn, 'tutor_profiles');
$has_feedback = tf_table_exists($conn, 'feedback');
$has_materials = tf_table_exists($conn, 'lesson_materials');
$has_assignments = tf_table_exists($conn, 'assignments') && tf_table_exists($conn, 'assignment_submissions');
$has_assignment_file_col = tf_column_exists($conn, 'assignments', 'file_path');
$has_quiz_due_date = tf_column_exists($conn, 'quizzes', 'due_date');
$has_quizzes = tf_table_exists($conn, 'quizzes')
    && tf_table_exists($conn, 'quiz_questions')
    && tf_table_exists($conn, 'quiz_attempts');
$student_quizzes = [];
$notif_unread = tf_table_exists($conn, 'notifications') ? tf_notification_unread_count($conn, $user_id) : 0;

if ($has_bookings && $has_tutor_profiles) {
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
    $upcoming_lessons = tf_stmt_all_assoc($lessons_stmt);
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
    $stats = tf_stmt_one_assoc($stats_stmt);
    $stats = $stats ?: ['total_lessons' => 0, 'active_tutors' => 0];
    $stats_stmt->close();

    // Feedback given (for completed lessons)
    if ($has_feedback) {
        $completed_stmt = $conn->prepare("
            SELECT b.*, 
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
        $completed_lessons = tf_stmt_all_assoc($completed_stmt);
        $completed_stmt->close();
    }

    if ($has_materials) {
        $materials_stmt = $conn->prepare("
            SELECT lm.material_id, lm.title, lm.file_path, lm.uploaded_at,
                   b.subject, b.lesson_date,
                   CONCAT(u.first_name,' ',u.last_name) AS tutor_name
            FROM lesson_materials lm
            JOIN bookings b ON b.booking_id = lm.booking_id
            JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
            JOIN users u ON tp.user_id = u.user_id
            WHERE b.student_id = ?
            ORDER BY lm.uploaded_at DESC
            LIMIT 20
        ");
        $materials_stmt->bind_param("i", $user_id);
        $materials_stmt->execute();
        $materials = tf_stmt_all_assoc($materials_stmt);
        $materials_stmt->close();
    }

    if ($has_assignments) {
        $assignment_file_select = $has_assignment_file_col ? "a.file_path" : "NULL AS file_path";
        $a_stmt = $conn->prepare("
            SELECT a.assignment_id, a.title, a.instructions, {$assignment_file_select}, a.due_date, a.created_at,
                   b.subject, b.lesson_date, b.booking_id,
                   CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
                   s.submission_id, s.submitted_at
            FROM assignments a
            JOIN bookings b ON b.booking_id = a.booking_id
            JOIN tutor_profiles tp ON tp.tutor_id = a.tutor_id
            JOIN users u ON u.user_id = tp.user_id
            LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.student_id = ?
            WHERE b.student_id = ?
            ORDER BY a.created_at DESC
            LIMIT 30
        ");
        $a_stmt->bind_param("ii", $user_id, $user_id);
        $a_stmt->execute();
        $assignments = tf_stmt_all_assoc($a_stmt);
        $a_stmt->close();
    }

    if ($has_quizzes) {
        $dueSel = $has_quiz_due_date ? ', q.due_date' : '';
        $has_qs_tbl = tf_table_exists($conn, 'quiz_students');
        $has_qsub = tf_column_exists($conn, 'quizzes', 'quiz_subject');
        $subSel = $has_qsub ? ', q.quiz_subject' : '';
        if ($has_qs_tbl) {
            $sq = $conn->prepare("
                SELECT q.quiz_id, q.title, q.created_at{$dueSel}{$subSel},
                       COALESCE(NULLIF(TRIM(q.quiz_subject), ''), b.subject) AS subject, b.booking_id,
                       CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
                       (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                       qa.score, qa.total_questions, qa.attempt_id, qa.submitted_at
                FROM quizzes q
                JOIN quiz_students qs ON qs.quiz_id = q.quiz_id AND qs.student_id = ?
                JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
                JOIN users u ON u.user_id = tp.user_id
                LEFT JOIN bookings b ON b.booking_id = q.booking_id
                LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.quiz_id AND qa.student_id = ?
                WHERE (b.booking_id IS NULL OR b.status IN ('confirmed','completed'))
                ORDER BY q.created_at DESC
                LIMIT 30
            ");
            $sq->bind_param("ii", $user_id, $user_id);
        } else {
            $sq = $conn->prepare("
                SELECT q.quiz_id, q.title, q.created_at{$dueSel}, b.subject, b.booking_id,
                       CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
                       (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                       qa.score, qa.total_questions, qa.attempt_id, qa.submitted_at
                FROM quizzes q
                JOIN bookings b ON b.booking_id = q.booking_id
                JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
                JOIN users u ON u.user_id = tp.user_id
                LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.quiz_id AND qa.student_id = ?
                WHERE b.student_id = ? AND b.status IN ('confirmed','completed')
                ORDER BY q.created_at DESC
                LIMIT 30
            ");
            $sq->bind_param("ii", $user_id, $user_id);
        }
        $sq->execute();
        $student_quizzes = tf_stmt_all_assoc($sq);
        $sq->close();
    }
} else {
    $schema_error = "Database tables are incomplete. Please run database_setup.sql and database_migration.sql in phpMyAdmin.";
}

$dispute_stats = ['total' => 0, 'open' => 0];
if (tf_table_exists($conn, 'disputes')) {
    $dstmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count
        FROM disputes
        WHERE filed_by = ?
    ");
    $dstmt->bind_param("i", $user_id);
    $dstmt->execute();
    $drow = tf_stmt_one_assoc($dstmt);
    $dstmt->close();
    $dispute_stats['total'] = (int)($drow['total'] ?? 0);
    $dispute_stats['open'] = (int)($drow['open_count'] ?? 0);
}

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
<link rel="stylesheet" href="css/style.css?v=pf3">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-student-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='student-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php?as=student">Find Tutors</a>
      <a href="student-dashboard.php" class="active">Dashboard</a>
      <?php if ($notif_unread > 0): ?>
      <a href="notifications.php" style="position:relative;">Notifications <span style="background:#e05c5c;color:#fff;border-radius:10px;padding:0.1rem 0.45rem;font-size:0.72rem;font-weight:800;margin-left:0.2rem;"><?= (int)$notif_unread ?></span></a>
      <?php else: ?>
      <a href="notifications.php">Notifications</a>
      <?php endif; ?>
      <a href="payment.php">Payments</a>
      <a href="dispute.php">Disputes</a>
      <a href="actions/logout.php?role=student">Log Out</a>
    </div>
  </nav>

  <div class="page-content">
    <?php if ($success): ?>
      <div class="alert alert-success" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($schema_error): ?>
      <div class="alert alert-error" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($schema_error) ?></div>
    <?php endif; ?>

    <div class="dash-grid">
      <div class="glass sidebar">
        <div class="sidebar-avatar<?= tf_profile_picture_url($user['profile_picture'] ?? null) ? ' has-photo' : '' ?>">
          <?php if ($p = tf_profile_picture_url($user['profile_picture'] ?? null)): ?>
            <img src="<?= htmlspecialchars($p) ?>" alt="">
          <?php else: ?>
            👨‍🎓
          <?php endif; ?>
        </div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Student</div>
        <div class="menu-item active" onclick="switchTab('s-overview')">📊 Overview</div>
        <div class="menu-item" onclick="switchTab('s-lessons')">🗓️ My Lessons</div>
        <div class="menu-item" onclick="switchTab('s-feedback')">⭐ Leave Feedback</div>
        <div class="menu-item" onclick="switchTab('s-assignments')">📝 Assignments</div>
        <div class="menu-item" onclick="switchTab('s-quizzes')">❓ Quizzes</div>
        <div class="menu-item" onclick="switchTab('s-materials')">📚 Materials</div>
        <div class="menu-item" onclick="window.location.href='search.php?as=student'">🔍 Find Tutors</div>
        <div class="menu-item" onclick="window.location.href='my-profile.php'">👤 My profile</div>
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
            <div class="glass stat-card">
              <div class="num"><?= $dispute_stats['open'] ?>/<?= $dispute_stats['total'] ?></div>
              <div class="lbl"><a href="dispute.php" style="color:inherit;">Open Disputes</a></div>
            </div>
          </div>

          <div class="glass section-card">
            <h3>🗓️ <span>Upcoming Lessons</span></h3>
            <?php if (empty($upcoming_lessons)): ?>
              <p style="opacity:0.6;">No upcoming lessons. <a href="search.php?as=student" style="color:var(--teal);">Find a tutor!</a></p>
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
              <p style="opacity:0.6;">No lessons yet. <a href="search.php?as=student" style="color:var(--teal);">Book one now!</a></p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Subject</th><th>Tutor</th><th>Date</th><th>Status</th><th>Attendance</th><th>Score</th><th>Payment</th><th>Amount</th></tr>
              <?php foreach (array_merge($upcoming_lessons, $completed_lessons) as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l['subject']) ?></td>
                <td><?= htmlspecialchars($l['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($l['lesson_date'])) ?></td>
                <td><span class="status-badge status-<?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span></td>
                <td><?= htmlspecialchars(ucfirst($l['attendance_status'] ?? 'pending')) ?></td>
                <td><?= isset($l['progress_score']) && $l['progress_score'] !== null ? (int)$l['progress_score'] : '—' ?></td>
                <td><?= htmlspecialchars(ucfirst($l['payment_status'] ?? 'unpaid')) ?></td>
                <td>RM <?= number_format($l['total_amount'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- ASSIGNMENTS TAB -->
        <div id="s-assignments" style="display:none;">
          <div class="glass section-card">
            <h3>📝 <span>Assignments</span></h3>
            <?php if (!$has_assignments): ?>
              <p style="opacity:0.6;">Assignments tables are missing. Run database_migration.sql to enable this feature.</p>
            <?php elseif (empty($assignments)): ?>
              <p style="opacity:0.6;">No assignments posted yet.</p>
            <?php else: ?>
              <?php foreach ($assignments as $a): ?>
              <div style="border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:1rem;margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                  <div>
                    <strong><?= htmlspecialchars($a['title']) ?></strong> — <?= htmlspecialchars($a['subject']) ?><br>
                    <span style="opacity:0.7;font-size:0.85rem;">Tutor: <?= htmlspecialchars($a['tutor_name']) ?> · Due: <?= $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : 'No due date' ?></span>
                  </div>
                  <div style="font-size:0.85rem;opacity:0.75;"><?= $a['submission_id'] ? 'Submitted' : 'Not submitted' ?></div>
                </div>
                <?php if (!empty($a['instructions'])): ?>
                  <p style="margin:0.6rem 0;opacity:0.85;"><?= nl2br(htmlspecialchars($a['instructions'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($a['file_path'])): ?>
                  <p style="margin:0.5rem 0;"><a href="actions/download-assignment.php?id=<?= (int)$a['assignment_id'] ?>">Download assignment file</a></p>
                <?php endif; ?>
                <form method="POST" action="actions/submit-assignment.php" enctype="multipart/form-data">
                  <input type="hidden" name="assignment_id" value="<?= (int)$a['assignment_id'] ?>">
                  <div class="form-row">
                    <div class="form-group">
                      <label>Submission file</label>
                      <input type="file" name="submission_file" required>
                    </div>
                    <div class="form-group">
                      <label>Note</label>
                      <input type="text" name="note" placeholder="Optional note to tutor">
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm">Submit / Replace Submission</button>
                </form>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- QUIZZES -->
        <div id="s-quizzes" style="display:none;">
          <div class="glass section-card">
            <h3>❓ <span>Quizzes</span></h3>
            <p style="opacity:0.75;font-size:0.9rem;margin-bottom:0.75rem;">Only you can submit answers here. Linked parents can view your score after you finish.</p>
            <?php if (!$has_quizzes): ?>
              <p style="opacity:0.6;">Quiz feature is not installed. Run database_migration.sql.</p>
            <?php elseif (empty($student_quizzes)): ?>
              <p style="opacity:0.6;">No quizzes from your tutors yet.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Quiz</th><th>Subject</th><th>Tutor</th><?php if ($has_quiz_due_date): ?><th>Due</th><?php endif; ?><th>Questions</th><th>Your result</th><th></th></tr>
              <?php foreach ($student_quizzes as $qz): ?>
              <?php
                $past = $has_quiz_due_date && !empty($qz['due_date']) && tf_quiz_past_due($qz['due_date']);
                $can_take = empty($qz['attempt_id']) && (int)$qz['q_count'] > 0 && !$past;
              ?>
              <tr>
                <td><?= htmlspecialchars($qz['title']) ?></td>
                <td><?= htmlspecialchars($qz['subject']) ?></td>
                <td><?= htmlspecialchars($qz['tutor_name']) ?></td>
                <?php if ($has_quiz_due_date): ?>
                <td>
                  <?php if (!empty($qz['due_date'])): ?>
                    <?= date('d M Y', strtotime($qz['due_date'])) ?>
                    <?php if ($past): ?><div style="font-size:0.72rem;color:#f87171;">Closed</div><?php endif; ?>
                  <?php else: ?><span style="opacity:0.5;">—</span><?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?= (int)$qz['q_count'] ?></td>
                <td>
                  <?php if (!empty($qz['attempt_id'])): ?>
                    <strong style="color:var(--teal);"><?= (int)$qz['score'] ?> / <?= (int)$qz['total_questions'] ?></strong>
                    <div style="font-size:0.78rem;opacity:0.65;"><?= date('d M Y', strtotime($qz['submitted_at'])) ?></div>
                  <?php else: ?>
                    <span style="opacity:0.6;">Not taken</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($can_take): ?>
                    <a class="btn btn-primary btn-sm" href="quiz-take.php?id=<?= (int)$qz['quiz_id'] ?>">Take quiz</a>
                  <?php elseif (!empty($qz['attempt_id'])): ?>
                    <span style="opacity:0.5;">Done</span>
                  <?php elseif ($past): ?>
                    <span style="opacity:0.55;font-size:0.85rem;">Past due</span>
                  <?php else: ?>—<?php endif; ?>
                </td>
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

        <!-- MATERIALS TAB -->
        <div id="s-materials" style="display:none;">
          <div class="glass section-card">
            <h3>📚 <span>Lesson Materials</span></h3>
            <?php if (!$has_materials): ?>
              <p style="opacity:0.6;">Materials table is missing. Run database_migration.sql to enable this feature.</p>
            <?php elseif (empty($materials)): ?>
              <p style="opacity:0.6;">No materials uploaded yet. Your tutor can upload files after confirming the lesson.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Title</th><th>Subject</th><th>Tutor</th><th>Lesson Date</th><th>File</th></tr>
              <?php foreach ($materials as $m): ?>
              <tr>
                <td><?= htmlspecialchars($m['title']) ?></td>
                <td><?= htmlspecialchars($m['subject']) ?></td>
                <td><?= htmlspecialchars($m['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($m['lesson_date'])) ?></td>
                <td><a href="actions/download-material.php?id=<?= (int)$m['material_id'] ?>">Download</a></td>
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
function switchTab(id) {
  ['s-overview','s-lessons','s-feedback','s-assignments','s-quizzes','s-materials'].forEach(t => {
    document.getElementById(t).style.display = 'none';
  });
  document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
  document.getElementById(id).style.display = 'block';
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
