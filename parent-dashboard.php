<?php
// ============================================================
// parent-dashboard.php — Real data from DB
// ============================================================
require_once __DIR__ . "/config/session.php";
tf_session_start_role('parent');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: login.html"); exit();
}

$user_id = $_SESSION['user_id'];

// Fetch parent info
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = tf_stmt_one_assoc($stmt);
$user = $user ?: ['first_name' => '', 'last_name' => ''];
$stmt->close();

$linked_students = [];
$materials = [];
$assignments = [];
$link_notice = '';
$has_materials = tf_table_exists($conn, 'lesson_materials');
$has_assignments = tf_table_exists($conn, 'assignments') && tf_table_exists($conn, 'assignment_submissions');
$has_assignment_file_col = tf_column_exists($conn, 'assignments', 'file_path');
$has_quizzes_parent = tf_table_exists($conn, 'quizzes')
    && tf_table_exists($conn, 'quiz_questions')
    && tf_table_exists($conn, 'quiz_attempts')
    && tf_table_exists($conn, 'quiz_attempt_answers');
$has_quiz_due_date = tf_column_exists($conn, 'quizzes', 'due_date');
$parent_quiz_rows = [];
$quiz_detail_by_attempt = [];
$notif_unread = tf_table_exists($conn, 'notifications') ? tf_notification_unread_count($conn, $user_id) : 0;

if (!tf_table_exists($conn, 'parent_students')) {
    $link_notice = 'Database missing parent_students table. Run database_migration.sql.';
    $bookings = [];
} else {
    $ls = $conn->prepare("
        SELECT su.user_id, su.first_name, su.last_name, su.email
        FROM parent_students ps
        JOIN users su ON su.user_id = ps.student_id AND su.role = 'student'
        WHERE ps.parent_id = ?
        ORDER BY su.first_name, su.last_name
    ");
    $ls->bind_param("i", $user_id);
    $ls->execute();
    $linked_students = tf_stmt_all_assoc($ls);
    $ls->close();

    if (empty($linked_students)) {
        $link_notice = 'No linked student yet. Either sign up as Parent using your child\'s student email on the signup form, or add a link manually in phpMyAdmin (INSERT into parent_students).';
        $bookings = [];
    } else {
        $student_ids = array_column($linked_students, 'user_id');
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $types = str_repeat('i', count($student_ids));

        $sqlBook = "
            SELECT b.*,
                   CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name,
                   CONCAT(su.first_name,' ',su.last_name) AS student_name,
                   su.email AS student_email
            FROM bookings b
            JOIN tutor_profiles tp ON b.tutor_id = tp.tutor_id
            JOIN users tu ON tu.user_id = tp.user_id
            JOIN users su ON su.user_id = b.student_id
            WHERE b.student_id IN ($placeholders)
            ORDER BY b.lesson_date DESC, b.lesson_time DESC
            LIMIT 40
        ";
        $bookings_stmt = $conn->prepare($sqlBook);
        $bookings_stmt->bind_param($types, ...$student_ids);
        $bookings_stmt->execute();
        $bookings = tf_stmt_all_assoc($bookings_stmt);
        $bookings_stmt->close();

        if ($has_materials) {
            $sqlMat = "
                SELECT lm.material_id, lm.title, lm.file_path, lm.uploaded_at,
                       b.subject, b.lesson_date,
                       CONCAT(su.first_name,' ',su.last_name) AS student_name,
                       CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name
                FROM lesson_materials lm
                JOIN bookings b ON b.booking_id = lm.booking_id
                JOIN users su ON su.user_id = b.student_id
                JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
                JOIN users tu ON tu.user_id = tp.user_id
                WHERE b.student_id IN ($placeholders)
                ORDER BY lm.uploaded_at DESC
                LIMIT 30
            ";
            $mstmt = $conn->prepare($sqlMat);
            $mstmt->bind_param($types, ...$student_ids);
            $mstmt->execute();
            $materials = tf_stmt_all_assoc($mstmt);
            $mstmt->close();
        }

        if ($has_assignments) {
            $assignment_file_select = $has_assignment_file_col ? "a.file_path" : "NULL AS file_path";
            $sqlAssign = "
                SELECT a.assignment_id, a.title, a.instructions, {$assignment_file_select}, a.due_date, a.created_at,
                       b.booking_id, b.subject, b.lesson_date, b.student_id,
                       CONCAT(su.first_name,' ',su.last_name) AS student_name,
                       CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name,
                       s.submission_id, s.submitted_at
                FROM assignments a
                JOIN bookings b ON b.booking_id = a.booking_id
                JOIN tutor_profiles tp ON tp.tutor_id = a.tutor_id
                JOIN users tu ON tu.user_id = tp.user_id
                JOIN users su ON su.user_id = b.student_id
                LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.student_id = b.student_id
                WHERE b.student_id IN ($placeholders)
                ORDER BY a.created_at DESC
                LIMIT 40
            ";
            $as = $conn->prepare($sqlAssign);
            $as->bind_param($types, ...$student_ids);
            $as->execute();
            $assignments = tf_stmt_all_assoc($as);
            $as->close();
        }

        if ($has_quizzes_parent) {
            $dueSelP = $has_quiz_due_date ? ', q.due_date' : '';
            $has_qs_tbl = tf_table_exists($conn, 'quiz_students');
            $has_qsub = tf_column_exists($conn, 'quizzes', 'quiz_subject');
            if ($has_qs_tbl) {
                $subExpr = $has_qsub
                    ? "COALESCE(NULLIF(TRIM(q.quiz_subject), ''), b.subject)"
                    : "b.subject";
                $qr = $conn->prepare("
                    SELECT q.quiz_id, q.title, q.created_at{$dueSelP},
                           {$subExpr} AS subject, b.lesson_date, qs.student_id,
                           CONCAT(su.first_name,' ',su.last_name) AS student_name,
                           CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name,
                           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                           qa.attempt_id, qa.score, qa.total_questions, qa.submitted_at
                    FROM quizzes q
                    JOIN quiz_students qs ON qs.quiz_id = q.quiz_id
                    JOIN users su ON su.user_id = qs.student_id
                    JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
                    JOIN users tu ON tu.user_id = tp.user_id
                    LEFT JOIN bookings b ON b.booking_id = q.booking_id
                    LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.quiz_id AND qa.student_id = qs.student_id
                    WHERE qs.student_id IN ($placeholders)
                      AND (b.booking_id IS NULL OR b.status IN ('confirmed','completed'))
                    ORDER BY q.created_at DESC
                    LIMIT 60
                ");
            } else {
                $qr = $conn->prepare("
                    SELECT q.quiz_id, q.title, q.created_at{$dueSelP},
                           b.subject, b.lesson_date, b.student_id,
                           CONCAT(su.first_name,' ',su.last_name) AS student_name,
                           CONCAT(tu.first_name,' ',tu.last_name) AS tutor_name,
                           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.quiz_id) AS q_count,
                           qa.attempt_id, qa.score, qa.total_questions, qa.submitted_at
                    FROM quizzes q
                    JOIN bookings b ON b.booking_id = q.booking_id
                    JOIN users su ON su.user_id = b.student_id
                    JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
                    JOIN users tu ON tu.user_id = tp.user_id
                    LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.quiz_id AND qa.student_id = b.student_id
                    WHERE b.student_id IN ($placeholders)
                      AND b.status IN ('confirmed','completed')
                    ORDER BY q.created_at DESC
                    LIMIT 60
                ");
            }
            $qr->bind_param($types, ...$student_ids);
            $qr->execute();
            $parent_quiz_rows = tf_stmt_all_assoc($qr);
            $qr->close();

            $attempt_ids = array_filter(array_map('intval', array_column($parent_quiz_rows, 'attempt_id')));
            if (!empty($attempt_ids)) {
                $idph = implode(',', $attempt_ids);
                $dres = $conn->query("
                    SELECT qaa.attempt_id, qq.question_text, qaa.selected_option, qaa.is_correct, qq.correct_option
                    FROM quiz_attempt_answers qaa
                    JOIN quiz_questions qq ON qq.question_id = qaa.question_id
                    WHERE qaa.attempt_id IN ($idph)
                    ORDER BY qaa.attempt_id ASC, qq.sort_order ASC, qq.question_id ASC
                ");
                if ($dres) {
                    while ($row = $dres->fetch_assoc()) {
                        $aid = (int) $row['attempt_id'];
                        if (!isset($quiz_detail_by_attempt[$aid])) {
                            $quiz_detail_by_attempt[$aid] = [];
                        }
                        $quiz_detail_by_attempt[$aid][] = $row;
                    }
                }
            }
        }
    }
}

// Stats
$total_spent    = array_sum(array_column($bookings, 'total_amount'));
$active_tutors  = count(array_unique(array_column($bookings, 'tutor_id')));
$total_lessons  = count($bookings);
$upcoming       = array_filter($bookings, fn($b) => $b['lesson_date'] >= date('Y-m-d') && $b['status'] !== 'cancelled');
$completed      = array_filter($bookings, fn($b) => $b['status'] === 'completed');
$completion_rate = $total_lessons > 0 ? round((count($completed) / $total_lessons) * 100) : 0;

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
<title>Parent Dashboard - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf3">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-parent-dash" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='parent-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php?as=parent">Find Tutors</a>
      <a href="parent-dashboard.php" class="active">Dashboard</a>
      <?php if ($notif_unread > 0): ?>
      <a href="notifications.php" style="position:relative;">Notifications <span style="background:#e05c5c;color:#fff;border-radius:10px;padding:0.1rem 0.45rem;font-size:0.72rem;font-weight:800;margin-left:0.2rem;"><?= (int) $notif_unread ?></span></a>
      <?php else: ?>
      <a href="notifications.php">Notifications</a>
      <?php endif; ?>
      <a href="payment.php">Payments</a>
      <a href="dispute.php">Disputes</a>
      <a href="actions/logout.php?role=parent">Log Out</a>
    </div>
  </nav>

  <div class="page-content">
    <?php if ($success): ?>
      <div class="alert alert-success" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($link_notice): ?>
      <div class="alert alert-error" style="max-width:900px;margin:0 auto 1rem;"><?= htmlspecialchars($link_notice) ?></div>
    <?php endif; ?>

    <div class="dash-grid">
      <div class="glass sidebar">
        <div class="sidebar-avatar<?= tf_profile_picture_url($user['profile_picture'] ?? null) ? ' has-photo' : '' ?>">
          <?php if ($p = tf_profile_picture_url($user['profile_picture'] ?? null)): ?>
            <img src="<?= htmlspecialchars($p) ?>" alt="">
          <?php else: ?>
            👪
          <?php endif; ?>
        </div>
        <div class="sidebar-name"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
        <div class="sidebar-role">Parent</div>
        <div class="menu-item active" onclick="switchParentTab('p-overview')">📊 Overview</div>
        <div class="menu-item" onclick="switchParentTab('p-lessons')">🗓️ Lesson History</div>
        <div class="menu-item" onclick="switchParentTab('p-progress')">📈 Progress</div>
        <div class="menu-item" onclick="switchParentTab('p-assignments')">📝 Assignments</div>
        <div class="menu-item" onclick="switchParentTab('p-quizzes')">❓ Quiz results</div>
        <div class="menu-item" onclick="switchParentTab('p-materials')">📚 Materials</div>
        <div class="menu-item" onclick="window.location.href='search.php?as=parent'">🔍 Find Tutor</div>
        <div class="menu-item" onclick="window.location.href='my-profile.php'">👤 My profile</div>
        <div style="margin-top:1rem;padding:0 0.5rem;">
          <form method="POST" action="actions/link-student.php">
            <div class="form-group" style="margin:0;">
              <label style="font-size:0.75rem;opacity:0.75;">Link student by email</label>
              <input type="email" name="child_email" placeholder="student@email.com" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:0.5rem;width:100%;justify-content:center;">Link Student</button>
          </form>
        </div>
        <?php if (!empty($linked_students)): ?>
          <div style="margin-top:1rem;font-size:0.78rem;opacity:0.55;padding:0 0.5rem;text-transform:uppercase;letter-spacing:0.06em;">Linked students</div>
          <?php foreach ($linked_students as $ch): ?>
            <div class="menu-item" style="cursor:default;">👤 <?= htmlspecialchars($ch['first_name'] . ' ' . $ch['last_name']) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="main-dash">

        <!-- OVERVIEW -->
        <div id="p-overview">
          <div class="stats-row">
            <div class="glass stat-card"><div class="num"><?= $active_tutors ?></div><div class="lbl">Active Tutors</div></div>
            <div class="glass stat-card"><div class="num"><?= $total_lessons ?></div><div class="lbl">Lessons Booked</div></div>
            <div class="glass stat-card"><div class="num">RM <?= number_format($total_spent, 0) ?></div><div class="lbl">Total Spent</div></div>
            <div class="glass stat-card"><div class="num"><?= count($upcoming) ?></div><div class="lbl">Upcoming</div></div>
            <div class="glass stat-card"><div class="num"><?= $dispute_stats['open'] ?>/<?= $dispute_stats['total'] ?></div><div class="lbl"><a href="dispute.php" style="color:inherit;">Open Disputes</a></div></div>
          </div>

          <?php if (!empty($linked_students)): ?>
          <div class="glass section-card" style="margin-bottom:1rem;border:1px solid rgba(46,196,182,0.35);">
            <h3>📅 <span>Book for your child</span></h3>
            <p style="opacity:0.82;font-size:0.92rem;line-height:1.45;margin:0 0 0.75rem;">
              Use <strong>Find Tutors</strong>, pick a tutor, then <strong>Book Lesson</strong>.
              <?php if (count($linked_students) > 1): ?>
              On the booking page you will choose which linked child the lesson is for.
              <?php endif; ?>
            </p>
            <a href="search.php?as=parent" class="btn btn-primary btn-sm" style="justify-content:center;">Find tutors &amp; book</a>
          </div>
          <?php endif; ?>

          <div class="glass section-card">
            <h3>🗓️ <span>Upcoming Lessons</span></h3>
            <?php if (empty($upcoming)): ?>
              <p style="opacity:0.6;">No upcoming lessons. <a href="search.php?as=parent" style="color:var(--teal);">Find a tutor!</a></p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Student</th><th>Subject</th><th>Tutor</th><th>Date</th><th>Time</th><th>Status</th></tr>
              <?php foreach ($upcoming as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['student_name'] ?? '') ?></td>
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
              <tr><th>Student</th><th>Subject</th><th>Tutor</th><th>Date</th><th>Amount</th><th>Status</th><th>Payment</th></tr>
              <?php foreach ($bookings as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['student_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($b['subject']) ?></td>
                <td><?= htmlspecialchars($b['tutor_name']) ?></td>
                <td><?= date('d M Y', strtotime($b['lesson_date'])) ?></td>
                <td>RM <?= number_format($b['total_amount'], 2) ?></td>
                <td><span class="status-badge status-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                <td><?= htmlspecialchars(ucfirst($b['payment_status'] ?? 'unpaid')) ?></td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- PROGRESS -->
        <div id="p-progress" style="display:none;">
          <div class="stats-row">
            <div class="glass stat-card"><div class="num"><?= count($completed) ?></div><div class="lbl">Completed Lessons</div></div>
            <div class="glass stat-card"><div class="num"><?= $completion_rate ?>%</div><div class="lbl">Completion Rate</div></div>
            <div class="glass stat-card"><div class="num"><?= count($materials) ?></div><div class="lbl">Materials Shared</div></div>
          </div>
          <div class="glass section-card">
            <h3>📈 <span>Student Progress Overview</span></h3>
            <?php if (empty($linked_students)): ?>
              <p style="opacity:0.6;">Link a student first to track progress.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Student</th><th>Total Lessons</th><th>Completed</th><th>Upcoming</th><th>Avg Score</th></tr>
              <?php foreach ($linked_students as $ch): ?>
                <?php
                  $sid = (int) $ch['user_id'];
                  $student_bookings = array_values(array_filter($bookings, fn($b) => (int)$b['student_id'] === $sid));
                  $student_completed = array_values(array_filter($student_bookings, fn($b) => $b['status'] === 'completed'));
                  $student_upcoming = array_values(array_filter($student_bookings, fn($b) => $b['lesson_date'] >= date('Y-m-d') && $b['status'] !== 'cancelled'));
                  $scored = array_values(array_filter($student_bookings, fn($b) => isset($b['progress_score']) && $b['progress_score'] !== null));
                  $avg_score = empty($scored) ? null : round(array_sum(array_column($scored, 'progress_score')) / count($scored));
                ?>
                <tr>
                  <td><?= htmlspecialchars($ch['first_name'] . ' ' . $ch['last_name']) ?></td>
                  <td><?= count($student_bookings) ?></td>
                  <td><?= count($student_completed) ?></td>
                  <td><?= count($student_upcoming) ?></td>
                  <td><?= $avg_score !== null ? $avg_score : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- ASSIGNMENTS -->
        <div id="p-assignments" style="display:none;">
          <div class="glass section-card">
            <h3>📝 <span>Assignments</span></h3>
            <?php if (!$has_assignments): ?>
              <p style="opacity:0.6;">Assignments tables missing. Run database_migration.sql first.</p>
            <?php elseif (empty($assignments)): ?>
              <p style="opacity:0.6;">No assignments posted yet.</p>
            <?php else: ?>
              <?php foreach ($assignments as $a): ?>
              <div style="border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:1rem;margin-bottom:1rem;">
                <strong><?= htmlspecialchars($a['title']) ?></strong> — <?= htmlspecialchars($a['student_name']) ?> · <?= htmlspecialchars($a['subject']) ?><br>
                <span style="opacity:0.7;font-size:0.85rem;">Tutor: <?= htmlspecialchars($a['tutor_name']) ?> · Due: <?= $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : 'No due date' ?> · <?= $a['submission_id'] ? 'Submitted' : 'Not submitted' ?></span>
                <?php if (!empty($a['instructions'])): ?>
                  <p style="margin:0.6rem 0;opacity:0.85;"><?= nl2br(htmlspecialchars($a['instructions'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($a['file_path'])): ?>
                  <p style="margin:0.5rem 0;"><a href="actions/download-assignment.php?id=<?= (int)$a['assignment_id'] ?>">Download assignment file</a></p>
                <?php endif; ?>
                <div style="margin-top:0.7rem;opacity:0.8;font-size:0.9rem;">
                  Parents can view assignment status, but only the student account can submit.
                  <?php if (!empty($a['submission_id'])): ?>
                    <a href="actions/download-submission.php?id=<?= (int)$a['submission_id'] ?>" style="margin-left:0.5rem;">Download submitted file</a>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- QUIZ RESULTS (read-only: only students can submit answers) -->
        <div id="p-quizzes" style="display:none;">
          <div class="glass section-card">
            <h3>❓ <span>Quiz results</span></h3>
            <p style="opacity:0.75;font-size:0.9rem;margin-bottom:1rem;">You can view scores and how your child answered after they submit. Parents cannot take quizzes — only the student account can.</p>
            <?php if (!$has_quizzes_parent): ?>
              <p style="opacity:0.6;">Quiz feature is not installed. Run <code>database_migration.sql</code>.</p>
            <?php elseif (empty($linked_students)): ?>
              <p style="opacity:0.6;">Link a student first.</p>
            <?php elseif (empty($parent_quiz_rows)): ?>
              <p style="opacity:0.6;">No quizzes from tutors yet for your linked students.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Student</th><th>Quiz</th><th>Subject</th><th>Tutor</th><?php if ($has_quiz_due_date): ?><th>Due</th><?php endif; ?><th>Questions</th><th>Result</th><th>Details</th></tr>
              <?php foreach ($parent_quiz_rows as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['student_name']) ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['subject']) ?></td>
                <td><?= htmlspecialchars($row['tutor_name']) ?></td>
                <?php if ($has_quiz_due_date): ?>
                <td>
                  <?php if (!empty($row['due_date'])): ?>
                    <?= date('d M Y', strtotime($row['due_date'])) ?>
                  <?php else: ?>
                    <span style="opacity:0.5;">—</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?= (int)$row['q_count'] ?></td>
                <td>
                  <?php if (!empty($row['attempt_id'])): ?>
                    <strong style="color:var(--teal);"><?= (int)$row['score'] ?> / <?= (int)$row['total_questions'] ?></strong>
                    <div style="font-size:0.78rem;opacity:0.65;"><?= date('d M Y g:i A', strtotime($row['submitted_at'])) ?></div>
                  <?php elseif ((int)$row['q_count'] < 1): ?>
                    <span style="opacity:0.55;">No questions yet</span>
                  <?php else: ?>
                    <span style="opacity:0.65;">Not taken yet</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($row['attempt_id']) && !empty($quiz_detail_by_attempt[(int)$row['attempt_id']])): ?>
                  <details>
                    <summary style="cursor:pointer;color:var(--teal);font-weight:700;">View breakdown</summary>
                    <ol style="margin:0.5rem 0 0 1.1rem;padding:0;font-size:0.88rem;opacity:0.9;">
                      <?php foreach ($quiz_detail_by_attempt[(int)$row['attempt_id']] as $line): ?>
                      <li style="margin-bottom:0.45rem;">
                        <?= htmlspecialchars($line['question_text']) ?>
                        <div style="font-size:0.8rem;opacity:0.75;">
                          Child: <?= strtoupper(htmlspecialchars($line['selected_option'])) ?>
                          · Correct: <?= strtoupper(htmlspecialchars($line['correct_option'])) ?>
                          · <?= !empty($line['is_correct']) ? '✓' : '✗' ?>
                        </div>
                      </li>
                      <?php endforeach; ?>
                    </ol>
                  </details>
                  <?php elseif (!empty($row['attempt_id'])): ?>
                    <span style="opacity:0.5;">—</span>
                  <?php else: ?>
                    <span style="opacity:0.45;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- MATERIALS -->
        <div id="p-materials" style="display:none;">
          <div class="glass section-card">
            <h3>📚 <span>Lesson Materials</span></h3>
            <?php if (!$has_materials): ?>
              <p style="opacity:0.6;">Materials table missing. Run database_migration.sql first.</p>
            <?php elseif (empty($materials)): ?>
              <p style="opacity:0.6;">No materials uploaded yet.</p>
            <?php else: ?>
            <table class="lesson-table">
              <tr><th>Title</th><th>Student</th><th>Tutor</th><th>Subject</th><th>Date</th><th>File</th></tr>
              <?php foreach ($materials as $m): ?>
              <tr>
                <td><?= htmlspecialchars($m['title']) ?></td>
                <td><?= htmlspecialchars($m['student_name']) ?></td>
                <td><?= htmlspecialchars($m['tutor_name']) ?></td>
                <td><?= htmlspecialchars($m['subject']) ?></td>
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
function switchParentTab(id) {
  ['p-overview','p-lessons','p-progress','p-assignments','p-quizzes','p-materials'].forEach(t => {
    document.getElementById(t).style.display = 'none';
  });
  document.querySelectorAll('.menu-item').forEach(m => m.classList.remove('active'));
  document.getElementById(id).style.display = 'block';
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
