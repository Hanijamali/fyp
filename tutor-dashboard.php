<?php
// ============================================================
// tutor-dashboard.php — Real data from DB
// ============================================================
require_once __DIR__ . "/config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];
$notif_unread = tf_table_exists($conn, 'notifications') ? tf_notification_unread_count($conn, $user_id) : 0;

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
$user = tf_stmt_one_assoc($stmt);
$user = $user ?: [];
$stmt->close();

$tutor_id = (int)($user['tutor_id'] ?? 0);
$has_materials = tf_table_exists($conn, 'lesson_materials');
$has_assignments = tf_table_exists($conn, 'assignments') && tf_table_exists($conn, 'assignment_submissions');
$has_assignment_file_col = tf_column_exists($conn, 'assignments', 'file_path');
$has_quiz_due_date = tf_column_exists($conn, 'quizzes', 'due_date');
$has_quizzes = tf_table_exists($conn, 'quizzes')
    && tf_table_exists($conn, 'quiz_questions')
    && tf_table_exists($conn, 'quiz_attempts')
    && tf_table_exists($conn, 'quiz_attempt_answers');
$materials = [];
$assignments = [];
$submissions = [];
$quizzes = [];
$eligible_quiz_students = [];

// Booking requests
$all_bookings = [];
$eligible_for_quiz = [];
if ($tutor_id > 0) {
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
    $all_bookings = tf_stmt_all_assoc($requests);
    $requests->close();

    $eligible_for_quiz = array_values(array_filter($all_bookings, fn($b) => in_array($b['status'], ['confirmed', 'completed'], true)));

    if ($tutor_id > 0 && tf_table_exists($conn, 'quiz_students')) {
        $eq = $conn->prepare("
            SELECT DISTINCT b.student_id, CONCAT(u.first_name,' ',u.last_name) AS student_name
            FROM bookings b
            JOIN users u ON u.user_id = b.student_id
            WHERE b.tutor_id = ? AND b.status IN ('confirmed','completed')
            ORDER BY student_name
        ");
        $eq->bind_param("i", $tutor_id);
        $eq->execute();
        $eligible_quiz_students = tf_stmt_all_assoc($eq);
        $eq->close();
    }

    if ($has_materials) {
        $mstmt = $conn->prepare("
            SELECT lm.material_id, lm.booking_id, lm.title, lm.file_path, lm.uploaded_at,
                   b.subject, b.lesson_date,
                   CONCAT(u.first_name,' ',u.last_name) AS student_name
            FROM lesson_materials lm
            JOIN bookings b ON b.booking_id = lm.booking_id
            JOIN users u ON u.user_id = b.student_id
            WHERE lm.tutor_id = ?
            ORDER BY lm.uploaded_at DESC
            LIMIT 30
        ");
        $mstmt->bind_param("i", $tutor_id);
        $mstmt->execute();
        $materials = tf_stmt_all_assoc($mstmt);
        $mstmt->close();
    }

    if ($has_assignments) {
        $assignment_file_select = $has_assignment_file_col ? "a.file_path" : "NULL AS file_path";
        $astmt = $conn->prepare("
            SELECT a.assignment_id, a.booking_id, a.title, a.instructions, {$assignment_file_select}, a.due_date, a.created_at,
                   b.subject, b.lesson_date, CONCAT(u.first_name,' ',u.last_name) AS student_name,
                   COUNT(s.submission_id) AS submissions_count,
                   MAX(s.submitted_at) AS latest_submission_at
            FROM assignments a
            JOIN bookings b ON b.booking_id = a.booking_id
            JOIN users u ON u.user_id = b.student_id
            LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id
            WHERE a.tutor_id = ?
            GROUP BY a.assignment_id
            ORDER BY a.created_at DESC
            LIMIT 30
        ");
        $astmt->bind_param("i", $tutor_id);
        $astmt->execute();
        $assignments = tf_stmt_all_assoc($astmt);
        $astmt->close();

        $sst = $conn->prepare("
            SELECT s.submission_id, s.submitted_at, s.note,
                   a.title AS assignment_title,
                   CONCAT(u.first_name,' ',u.last_name) AS student_name
            FROM assignment_submissions s
            JOIN assignments a ON a.assignment_id = s.assignment_id
            JOIN users u ON u.user_id = s.student_id
            WHERE a.tutor_id = ?
            ORDER BY s.submitted_at DESC
            LIMIT 30
        ");
        $sst->bind_param("i", $tutor_id);
        $sst->execute();
        $submissions = tf_stmt_all_assoc($sst);
        $sst->close();
    }

    if ($has_quizzes) {
        $dueSel = $has_quiz_due_date ? ', q.due_date' : '';
        $has_qs_tbl = tf_table_exists($conn, 'quiz_students');
        $has_qsub = tf_column_exists($conn, 'quizzes', 'quiz_subject');
        if ($has_qs_tbl) {
            $subSql = $has_qsub
                ? "COALESCE(NULLIF(TRIM(q.quiz_subject), ''), b.subject) AS subject"
                : "b.subject AS subject";
            $qz = $conn->prepare("
                SELECT q.quiz_id, q.title, q.created_at{$dueSel},
                       {$subSql},
                       b.lesson_date,
                       (SELECT GROUP_CONCAT(DISTINCT CONCAT(u2.first_name,' ',u2.last_name) ORDER BY u2.last_name, u2.first_name SEPARATOR ', ')
                        FROM quiz_students qs2 JOIN users u2 ON u2.user_id = qs2.student_id WHERE qs2.quiz_id = q.quiz_id) AS student_names,
                       CONCAT(uc.first_name,' ',uc.last_name) AS booking_student_name,
                       (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                       (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.quiz_id) AS attempt_count
                FROM quizzes q
                LEFT JOIN bookings b ON b.booking_id = q.booking_id
                LEFT JOIN users uc ON uc.user_id = b.student_id
                WHERE q.tutor_id = ?
                ORDER BY q.created_at DESC
                LIMIT 25
            ");
        } else {
            $qz = $conn->prepare("
                SELECT q.quiz_id, q.title, q.created_at{$dueSel}, b.subject, b.lesson_date,
                       CONCAT(u.first_name,' ',u.last_name) AS student_name,
                       (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                       (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.quiz_id) AS attempt_count
                FROM quizzes q
                JOIN bookings b ON b.booking_id = q.booking_id
                JOIN users u ON u.user_id = b.student_id
                WHERE q.tutor_id = ?
                ORDER BY q.created_at DESC
                LIMIT 25
            ");
        }
        $qz->bind_param("i", $tutor_id);
        $qz->execute();
        $quizzes = tf_stmt_all_assoc($qz);
        $qz->close();
    }
}

// Stats
$total_lessons  = count($all_bookings);
$active_students = count(array_unique(array_column($all_bookings, 'student_id')));
$total_earned   = array_sum(array_column(
    array_filter($all_bookings, fn($b) => $b['status'] === 'completed'),
    'total_amount'
));

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
<title>Tutor Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf3">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-tutor-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='tutor-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="tutor-dashboard.php" class="active">My Dashboard</a>
      <?php if ($notif_unread > 0): ?>
      <a href="notifications.php" style="position:relative;">Notifications <span style="background:#e05c5c;color:#fff;border-radius:10px;padding:0.1rem 0.45rem;font-size:0.72rem;font-weight:800;margin-left:0.2rem;"><?= (int) $notif_unread ?></span></a>
      <?php else: ?>
      <a href="notifications.php">Notifications</a>
      <?php endif; ?>
      <a href="dispute.php">Disputes</a>
      <a href="actions/logout.php?role=tutor">Log Out</a>
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
        <div class="sidebar-avatar<?= tf_profile_picture_url($user['profile_picture'] ?? null) ? ' has-photo' : '' ?>">
          <?php if ($p = tf_profile_picture_url($user['profile_picture'] ?? null)): ?>
            <img src="<?= htmlspecialchars($p) ?>" alt="">
          <?php else: ?>
            👩‍🏫
          <?php endif; ?>
        </div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Tutor — <?= htmlspecialchars($user['subject'] ?? 'General') ?></div>
        <div class="menu-item active" data-tutor-tab="t-overview" onclick="switchTutorTab('t-overview')">📊 Overview</div>
        <div class="menu-item" data-tutor-tab="t-requests" onclick="switchTutorTab('t-requests')">📩 Lesson Requests</div>
        <div class="menu-item" data-tutor-tab="t-materials" onclick="switchTutorTab('t-materials')">📚 Materials</div>
        <div class="menu-item" data-tutor-tab="t-quizzes" onclick="switchTutorTab('t-quizzes')">❓ Quizzes</div>
        <div class="menu-item" data-tutor-tab="t-profile" onclick="switchTutorTab('t-profile')">👤 Profile</div>
        <div class="menu-item" data-tutor-tab="t-payment" onclick="switchTutorTab('t-payment')">💰 Payment</div>
        <div class="menu-item" onclick="window.location.href='my-profile.php'">👤 My profile</div>
      </div>

      <div class="main-dash" id="tutor-main">

        <!-- OVERVIEW -->
        <div id="t-overview">
          <div class="stats-row">
            <div class="glass stat-card"><div class="num"><?= $total_lessons ?></div><div class="lbl">Total Lessons</div></div>
            <div class="glass stat-card"><div class="num"><?= $active_students ?></div><div class="lbl">Students</div></div>
            <div class="glass stat-card"><div class="num"><?= $user['rating'] > 0 ? $user['rating'] . ' ⭐' : 'N/A' ?></div><div class="lbl">Rating</div></div>
            <div class="glass stat-card"><div class="num">RM <?= number_format($total_earned, 0) ?></div><div class="lbl">Earned</div></div>
            <div class="glass stat-card"><div class="num"><?= $dispute_stats['open'] ?>/<?= $dispute_stats['total'] ?></div><div class="lbl"><a href="dispute.php" style="color:inherit;">Open Disputes</a></div></div>
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

        <!-- MATERIALS -->
        <div id="t-materials" style="display:none;">
          <div class="glass section-card">
            <h3>📚 Upload <span>Lesson Materials</span></h3>
            <?php if (!$has_materials): ?>
              <p style="opacity:0.6;">Materials table missing. Run database_migration.sql first.</p>
            <?php else: ?>
              <?php $eligible = array_filter($all_bookings, fn($b) => in_array($b['status'], ['confirmed','completed'], true)); ?>
              <?php if (empty($eligible)): ?>
                <p style="opacity:0.6;">You can upload materials after a lesson is confirmed.</p>
              <?php else: ?>
                <form method="POST" action="actions/upload-material.php" enctype="multipart/form-data" style="margin-bottom:1rem;">
                  <div class="form-row">
                    <div class="form-group">
                      <label>Lesson</label>
                      <select name="booking_id" required>
                        <?php foreach ($eligible as $b): ?>
                          <option value="<?= (int)$b['booking_id'] ?>">
                            #<?= (int)$b['booking_id'] ?> · <?= htmlspecialchars($b['student_name']) ?> · <?= htmlspecialchars($b['subject']) ?> · <?= date('d M Y', strtotime($b['lesson_date'])) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label>Material title</label>
                      <input type="text" name="title" placeholder="e.g. Chapter 3 Exercises" required>
                    </div>
                  </div>
                  <div class="form-group">
                    <label>File (pdf/doc/docx/ppt/pptx/txt/png/jpg, max 8MB)</label>
                    <input type="file" name="material_file" required>
                  </div>
                  <button type="submit" class="btn btn-primary btn-sm">Upload Material</button>
                </form>
              <?php endif; ?>

              <?php if ($has_assignments): ?>
              <h3 style="margin-top:1.4rem;">📝 <span>Create Assignment</span></h3>
              <form method="POST" action="actions/create-assignment.php" enctype="multipart/form-data" style="margin-bottom:1rem;">
                <div class="form-row">
                  <div class="form-group">
                    <label>Lesson</label>
                    <select name="booking_id" required>
                      <?php foreach ($eligible as $b): ?>
                        <option value="<?= (int)$b['booking_id'] ?>">
                          #<?= (int)$b['booking_id'] ?> · <?= htmlspecialchars($b['student_name']) ?> · <?= htmlspecialchars($b['subject']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" placeholder="e.g. Algebra Worksheet" required>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Instructions</label>
                    <input type="text" name="instructions" placeholder="What student should do">
                  </div>
                  <div class="form-group">
                    <label>Due date</label>
                    <input type="date" name="due_date">
                  </div>
                </div>
                <div class="form-group">
                  <label>Assignment file (optional)</label>
                  <input type="file" name="assignment_file">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Create Assignment</button>
              </form>
              <?php endif; ?>

              <h3 style="margin-top:1.4rem;">🗂️ <span>Uploaded Materials</span></h3>
              <?php if (empty($materials)): ?>
                <p style="opacity:0.6;">No materials uploaded yet.</p>
              <?php else: ?>
                <table class="lesson-table">
                  <tr><th>Title</th><th>Student</th><th>Subject</th><th>Lesson Date</th><th>File</th><th>Action</th></tr>
                  <?php foreach ($materials as $m): ?>
                  <tr>
                    <td><?= htmlspecialchars($m['title']) ?></td>
                    <td><?= htmlspecialchars($m['student_name']) ?></td>
                    <td><?= htmlspecialchars($m['subject']) ?></td>
                    <td><?= date('d M Y', strtotime($m['lesson_date'])) ?></td>
                    <td><a href="actions/download-material.php?id=<?= (int)$m['material_id'] ?>">Download</a></td>
                    <td>
                      <form method="POST" action="actions/delete-material.php" onsubmit="return confirm('Delete this material?');">
                        <input type="hidden" name="material_id" value="<?= (int)$m['material_id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </table>
              <?php endif; ?>

              <?php if ($has_assignments): ?>
                <h3 style="margin-top:1.4rem;">📋 <span>Assignments & Submissions</span></h3>
                <?php if (empty($assignments)): ?>
                  <p style="opacity:0.6;">No assignments created yet.</p>
                <?php else: ?>
                  <table class="lesson-table">
                    <tr><th>Title</th><th>Student</th><th>Subject</th><th>Due</th><th>Submissions</th></tr>
                    <?php foreach ($assignments as $a): ?>
                    <tr>
                      <td><?= htmlspecialchars($a['title']) ?></td>
                      <td><?= htmlspecialchars($a['student_name']) ?></td>
                      <td><?= htmlspecialchars($a['subject']) ?></td>
                      <td><?= $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : '—' ?></td>
                      <td>
                        <?= (int)$a['submissions_count'] ?>
                        <?php if (!empty($a['file_path'])): ?>
                          <div><a href="actions/download-assignment.php?id=<?= (int)$a['assignment_id'] ?>">Assignment file</a></div>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </table>
                <?php endif; ?>
                <h3 style="margin-top:1.4rem;">📥 <span>Student Submission Files</span></h3>
                <?php if (empty($submissions)): ?>
                  <p style="opacity:0.6;">No submissions yet.</p>
                <?php else: ?>
                  <table class="lesson-table">
                    <tr><th>Assignment</th><th>Student</th><th>Submitted</th><th>File</th></tr>
                    <?php foreach ($submissions as $s): ?>
                    <tr>
                      <td><?= htmlspecialchars($s['assignment_title']) ?></td>
                      <td><?= htmlspecialchars($s['student_name']) ?></td>
                      <td><?= date('d M Y g:i A', strtotime($s['submitted_at'])) ?></td>
                      <td><a href="actions/download-submission.php?id=<?= (int)$s['submission_id'] ?>">Download</a></td>
                    </tr>
                    <?php endforeach; ?>
                  </table>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- QUIZZES -->
        <div id="t-quizzes" style="display:none;">
          <div class="glass section-card" style="margin-bottom:1rem;">
            <h3>❓ <span>Create quiz</span></h3>
            <?php if (!$has_quizzes): ?>
              <p style="opacity:0.6;">Quiz tables missing. Run <code>database_migration.sql</code> to enable quizzes.</p>
            <?php elseif (empty($eligible_for_quiz)): ?>
              <p style="opacity:0.6;">Confirm a lesson first, then you can attach a quiz to that booking.</p>
            <?php else: ?>
            <p style="opacity:0.75;font-size:0.9rem;margin-bottom:1rem;">Pick a <strong>lesson for context</strong> (topic and tutor). Then choose <strong>which student(s)</strong> get the same quiz—tick several to assign one quiz to multiple students at once.</p>
            <form id="create-quiz-form" method="POST" action="actions/create-quiz.php">
              <div class="form-row">
                <div class="form-group">
                  <label>Lesson (context)</label>
                  <select name="booking_id" id="create-quiz-booking" required>
                    <?php foreach ($eligible_for_quiz as $b): ?>
                      <option value="<?= (int) $b['booking_id'] ?>" data-student-id="<?= (int) $b['student_id'] ?>">
                        #<?= (int) $b['booking_id'] ?> · <?= htmlspecialchars($b['student_name']) ?> · <?= htmlspecialchars($b['subject']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Quiz title</label>
                  <input type="text" name="title" placeholder="e.g. Chapter 5 check-up" required maxlength="200">
                </div>
                <?php if ($has_quiz_due_date): ?>
                <div class="form-group">
                  <label>Due date <span style="font-weight:400;opacity:0.65;">(optional)</span></label>
                  <input type="date" name="due_date" min="<?= htmlspecialchars(date('Y-m-d')) ?>" style="min-width:11rem;">
                  <p style="font-size:0.78rem;opacity:0.6;margin:0.35rem 0 0;">Students can submit until the end of this day (server date). Leave empty for no deadline.</p>
                </div>
                <?php endif; ?>
              </div>
              <?php if (!empty($eligible_quiz_students)): ?>
              <div class="form-group" id="quiz-assign-students" style="margin-bottom:1rem;">
                <label>Assign to student(s)</label>
                <p style="font-size:0.78rem;opacity:0.65;margin:0 0 0.5rem;">Same questions for everyone selected. Defaults to the student on the lesson above when you change lesson.</p>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem 1.25rem;">
                  <?php foreach ($eligible_quiz_students as $es): ?>
                  <label style="display:flex;align-items:center;gap:0.35rem;font-weight:600;cursor:pointer;">
                    <input type="checkbox" name="quiz_student_ids[]" value="<?= (int) $es['student_id'] ?>">
                    <?= htmlspecialchars($es['student_name']) ?>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
              <p style="opacity:0.75;font-size:0.88rem;margin:0 0 0.5rem;">Add as many questions as you need (up to <strong>30</strong>). Use <strong>+ Add question</strong> for more rows. Empty rows are ignored.</p>
              <div id="quiz-questions-wrap"></div>
              <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-bottom:1rem;">
                <button type="button" class="btn btn-primary btn-sm" id="btn-add-quiz-question">+ Add question</button>
                <span id="quiz-q-count-hint" style="font-size:0.82rem;opacity:0.65;"></span>
              </div>
              <button type="submit" class="btn btn-primary btn-sm">Save quiz</button>
            </form>
            <script>
            (function(){
              var sel = document.getElementById('create-quiz-booking');
              var boxes = document.querySelectorAll('#quiz-assign-students input[type="checkbox"][name="quiz_student_ids[]"]');
              function syncFromBooking() {
                if (!sel || !boxes.length) return;
                var opt = sel.options[sel.selectedIndex];
                var sid = opt ? String(opt.getAttribute('data-student-id') || '') : '';
                boxes.forEach(function(cb) {
                  cb.checked = (sid !== '' && String(cb.value) === sid);
                });
              }
              if (sel) {
                sel.addEventListener('change', syncFromBooking);
                syncFromBooking();
              }
            })();
            </script>
            <script>
            (function(){
              const wrap = document.getElementById('quiz-questions-wrap');
              const addBtn = document.getElementById('btn-add-quiz-question');
              const hint = document.getElementById('quiz-q-count-hint');
              const MAX = 30;
              let serial = 0;
              function countBlocks() { return wrap.querySelectorAll('.quiz-q-block').length; }
              function updateHint() {
                const n = countBlocks();
                hint.textContent = n + ' / ' + MAX + ' question slots';
                addBtn.disabled = n >= MAX;
              }
              function addBlock() {
                if (countBlocks() >= MAX) return;
                const idx = serial++;
                const div = document.createElement('div');
                div.className = 'quiz-q-block';
                div.dataset.idx = String(idx);
                div.style.cssText = 'border:1px solid rgba(255,255,255,0.1);border-radius:10px;padding:0.85rem;margin-bottom:0.75rem;position:relative;';
                div.innerHTML =
                  '<button type="button" class="btn btn-danger btn-sm quiz-q-remove" style="position:absolute;top:0.6rem;right:0.6rem;">Remove</button>' +
                  '<strong class="quiz-q-label" style="opacity:0.85;display:block;padding-right:4.5rem;">Question</strong>' +
                  '<div class="form-group" style="margin-top:0.5rem;">' +
                    '<label>Question text</label>' +
                    '<input type="text" name="questions[' + idx + '][text]" placeholder="Enter the question (leave whole row empty to skip)" maxlength="600">' +
                  '</div>' +
                  '<div class="form-row">' +
                    '<div class="form-group"><label>A</label><input type="text" name="questions[' + idx + '][a]" maxlength="300"></div>' +
                    '<div class="form-group"><label>B</label><input type="text" name="questions[' + idx + '][b]" maxlength="300"></div>' +
                  '</div>' +
                  '<div class="form-row">' +
                    '<div class="form-group"><label>C</label><input type="text" name="questions[' + idx + '][c]" maxlength="300"></div>' +
                    '<div class="form-group"><label>D</label><input type="text" name="questions[' + idx + '][d]" maxlength="300"></div>' +
                  '</div>' +
                  '<div class="form-group">' +
                    '<label>Correct answer</label>' +
                    '<select name="questions[' + idx + '][correct]">' +
                      '<option value="">—</option><option value="a">A</option><option value="b">B</option><option value="c">C</option><option value="d">D</option>' +
                    '</select>' +
                  '</div>';
                div.querySelector('.quiz-q-remove').addEventListener('click', function() {
                  if (countBlocks() <= 1) return;
                  div.remove();
                  renumberLabels();
                  updateHint();
                });
                wrap.appendChild(div);
                renumberLabels();
                updateHint();
              }
              function renumberLabels() {
                wrap.querySelectorAll('.quiz-q-block').forEach(function(el, i) {
                  const lbl = el.querySelector('.quiz-q-label');
                  if (lbl) lbl.textContent = 'Question ' + (i + 1);
                });
              }
              addBtn.addEventListener('click', addBlock);
              addBlock();
            })();
            </script>
            <?php endif; ?>
          </div>
          <div class="glass section-card">
            <h3>📋 <span>Your quizzes</span></h3>
            <?php if (!$has_quizzes): ?>
              <p style="opacity:0.6;">—</p>
            <?php elseif (empty($quizzes)): ?>
              <p style="opacity:0.6;">No quizzes created yet.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Title</th><th>Student(s)</th><th>Subject</th><th>Questions</th><?php if ($has_quiz_due_date): ?><th>Due</th><?php endif; ?><th>Submissions</th><th>Created</th><th></th></tr>
              <?php foreach ($quizzes as $qz): ?>
              <?php
                $stuDisp = '';
                if (isset($qz['student_names']) && trim((string) $qz['student_names']) !== '') {
                    $stuDisp = $qz['student_names'];
                } elseif (!empty($qz['booking_student_name'])) {
                    $stuDisp = $qz['booking_student_name'];
                } elseif (!empty($qz['student_name'])) {
                    $stuDisp = $qz['student_name'];
                } else {
                    $stuDisp = '—';
                }
              ?>
              <tr>
                <td><?= htmlspecialchars($qz['title']) ?></td>
                <td><?= htmlspecialchars($stuDisp) ?></td>
                <td><?= htmlspecialchars($qz['subject']) ?></td>
                <td><?= (int)$qz['q_count'] ?></td>
                <?php if ($has_quiz_due_date): ?>
                <td>
                  <?php if (!empty($qz['due_date'])): ?>
                    <?= date('d M Y', strtotime($qz['due_date'])) ?>
                    <?php if (tf_quiz_past_due($qz['due_date'])): ?><span style="font-size:0.72rem;color:#f87171;">Past</span><?php endif; ?>
                  <?php else: ?>
                    <span style="opacity:0.5;">—</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?= (int)$qz['attempt_count'] ?></td>
                <td><?= date('d M Y', strtotime($qz['created_at'])) ?></td>
                <td>
                  <?php if ((int)$qz['attempt_count'] > 0): ?>
                    <a class="btn btn-primary btn-sm" href="quiz-tutor-review.php?id=<?= (int)$qz['quiz_id'] ?>">View answers</a>
                  <?php else: ?>
                    <span style="opacity:0.45;font-size:0.85rem;">—</span>
                  <?php endif; ?>
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
              <tr><th>Student</th><th>Subject</th><th>Date & Time</th><th>Duration</th><th>Amount</th><th>Status</th><th>Progress</th><th>Actions</th></tr>
              <?php foreach ($all_bookings as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['student_name']) ?></td>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= date('d M', strtotime($b['lesson_date'])) ?>, <?= date('g:i A', strtotime($b['lesson_time'])) ?></td>
                <td><?= htmlspecialchars($b['duration']) ?></td>
                <td>RM <?= number_format($b['total_amount'], 2) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                <td>
                  <?php if (in_array($b['status'], ['confirmed','completed'], true)): ?>
                  <form method="POST" action="actions/update-progress.php" style="display:flex;gap:0.25rem;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                    <select name="attendance_status" style="min-width:100px;">
                      <?php $att = $b['attendance_status'] ?? 'pending'; ?>
                      <option value="pending" <?= $att === 'pending' ? 'selected' : '' ?>>Pending</option>
                      <option value="present" <?= $att === 'present' ? 'selected' : '' ?>>Present</option>
                      <option value="absent" <?= $att === 'absent' ? 'selected' : '' ?>>Absent</option>
                    </select>
                    <input type="number" name="progress_score" min="0" max="100" value="<?= isset($b['progress_score']) && $b['progress_score'] !== null ? (int)$b['progress_score'] : '' ?>" placeholder="Score" style="width:80px;">
                    <input type="text" name="tutor_comment" value="<?= htmlspecialchars($b['tutor_comment'] ?? '') ?>" placeholder="Comment" style="min-width:130px;">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                  </form>
                  <?php else: ?>—<?php endif; ?>
                </td>
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
                  <?php elseif ($b['status'] === 'confirmed'): ?>
                  <form method="POST" action="actions/update-booking.php" style="display:inline;">
                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                    <input type="hidden" name="action" value="completed">
                    <button type="submit" class="btn btn-primary btn-sm">Mark Completed</button>
                  </form>
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
  ['t-overview','t-requests','t-materials','t-quizzes','t-profile','t-payment'].forEach(t => {
    const el = document.getElementById(t);
    if (el) el.style.display = 'none';
  });
  document.querySelectorAll('[data-tutor-tab]').forEach(m => m.classList.remove('active'));
  const pane = document.getElementById(id);
  if (pane) pane.style.display = 'block';
  const mi = document.querySelector('[data-tutor-tab="' + id + '"]');
  if (mi) mi.classList.add('active');
}
document.addEventListener('DOMContentLoaded', function() {
  var h = (location.hash || '').replace(/^#/, '');
  if (h === 't-quizzes' || h === 't-requests' || h === 't-materials' || h === 't-profile' || h === 't-payment') {
    switchTutorTab(h);
  }
});
</script>
</body>
</html>
