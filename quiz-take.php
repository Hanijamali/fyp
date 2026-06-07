<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_role('student');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.html");
    exit();
}

$quiz_id = (int) ($_GET['id'] ?? 0);
$student_id = (int) $_SESSION['user_id'];
$error = $_GET['error'] ?? '';

if ($quiz_id < 1 || !tf_table_exists($conn, 'quizzes')) {
    header("Location: student-dashboard.php?error=" . urlencode("Invalid quiz."));
    exit();
}

$has_quiz_due = tf_column_exists($conn, 'quizzes', 'due_date');
$dueSel = $has_quiz_due ? ', q.due_date' : '';
$has_qs_tbl = tf_table_exists($conn, 'quiz_students');
$has_qsub = tf_column_exists($conn, 'quizzes', 'quiz_subject');
$subSel = $has_qsub ? ', q.quiz_subject' : '';

if ($has_qs_tbl) {
    $q = $conn->prepare("
        SELECT q.quiz_id, q.title{$dueSel}{$subSel}, q.created_at,
               b.lesson_date, b.status AS booking_status,
               COALESCE(NULLIF(TRIM(q.quiz_subject), ''), b.subject) AS subject,
               CONCAT(u.first_name,' ',u.last_name) AS tutor_name
        FROM quizzes q
        JOIN quiz_students qs ON qs.quiz_id = q.quiz_id AND qs.student_id = ?
        JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        LEFT JOIN bookings b ON b.booking_id = q.booking_id
        WHERE q.quiz_id = ?
        LIMIT 1
    ");
    $q->bind_param("ii", $student_id, $quiz_id);
} else {
    $q = $conn->prepare("
        SELECT q.quiz_id, q.title{$dueSel}, b.student_id, b.status AS booking_status, b.subject, b.lesson_date,
               CONCAT(u.first_name,' ',u.last_name) AS tutor_name
        FROM quizzes q
        JOIN bookings b ON b.booking_id = q.booking_id
        JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        WHERE q.quiz_id = ?
        LIMIT 1
    ");
    $q->bind_param("i", $quiz_id);
}
$q->execute();
$quiz = tf_stmt_one_assoc($q);
$q->close();

if ($has_qs_tbl) {
    if (!$quiz) {
        header("Location: student-dashboard.php?error=" . urlencode("You cannot access this quiz."));
        exit();
    }
    $bs = $quiz['booking_status'] ?? null;
    if ($bs !== null && $bs !== '' && !in_array($bs, ['confirmed', 'completed'], true)) {
        header("Location: student-dashboard.php?error=" . urlencode("This quiz is not available."));
        exit();
    }
} else {
    if (!$quiz || (int) $quiz['student_id'] !== $student_id) {
        header("Location: student-dashboard.php?error=" . urlencode("You cannot access this quiz."));
        exit();
    }
    if (!in_array($quiz['booking_status'] ?? $quiz['status'] ?? '', ['confirmed', 'completed'], true)) {
        header("Location: student-dashboard.php?error=" . urlencode("This quiz is not available."));
        exit();
    }
}

$lesson_date_disp = $quiz['lesson_date'] ?? null;
if (!$lesson_date_disp && !empty($quiz['created_at'])) {
    $lesson_date_disp = date('Y-m-d', strtotime($quiz['created_at']));
}

$past_due = $has_quiz_due && !empty($quiz['due_date']) && tf_quiz_past_due($quiz['due_date']);

$existing = null;
if (tf_table_exists($conn, 'quiz_attempts')) {
    $ex = $conn->prepare("SELECT score, total_questions, submitted_at FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? LIMIT 1");
    $ex->bind_param("ii", $quiz_id, $student_id);
    $ex->execute();
    $existing = tf_stmt_one_assoc($ex);
    $ex->close();
}

$questions = [];
if (!$existing && !$past_due && tf_table_exists($conn, 'quiz_questions')) {
    $qs = $conn->prepare("
        SELECT question_id, question_text, option_a, option_b, option_c, option_d
        FROM quiz_questions
        WHERE quiz_id = ?
        ORDER BY sort_order ASC, question_id ASC
    ");
    $qs->bind_param("i", $quiz_id);
    $qs->execute();
    $questions = tf_stmt_all_assoc($qs);
    $qs->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Take quiz - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='student-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="student-dashboard.php">← Dashboard</a>
      <a href="actions/logout.php?role=student">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:720px;">
    <div class="page-header">
      <h2><?= htmlspecialchars($quiz['title']) ?></h2>
      <p><?= htmlspecialchars($quiz['subject'] ?? '') ?> · <?= htmlspecialchars($quiz['tutor_name']) ?> · <?= $lesson_date_disp ? date('d M Y', strtotime($lesson_date_disp)) : '—' ?>
        <?php if ($has_quiz_due && !empty($quiz['due_date'])): ?>
          · <span style="opacity:0.85;">Due <?= date('d M Y', strtotime($quiz['due_date'])) ?></span>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($existing): ?>
      <div class="glass section-card">
        <p>You already submitted this quiz.</p>
        <p style="font-size:1.2rem;font-weight:800;color:var(--teal);">Score: <?= (int)$existing['score'] ?> / <?= (int)$existing['total_questions'] ?></p>
        <p style="opacity:0.7;font-size:0.88rem;">Submitted <?= date('d M Y g:i A', strtotime($existing['submitted_at'])) ?></p>
        <a class="btn btn-primary btn-sm" href="student-dashboard.php" style="margin-top:1rem;">Back to dashboard</a>
      </div>
    <?php elseif ($past_due): ?>
      <div class="glass section-card">
        <p>The due date for this quiz has passed. You can no longer submit answers.</p>
        <?php if ($has_quiz_due && !empty($quiz['due_date'])): ?>
          <p style="opacity:0.75;font-size:0.9rem;">Due was <?= date('d M Y', strtotime($quiz['due_date'])) ?>.</p>
        <?php endif; ?>
        <a class="btn btn-primary btn-sm" href="student-dashboard.php" style="margin-top:1rem;">Back to dashboard</a>
      </div>
    <?php elseif (empty($questions)): ?>
      <div class="glass section-card"><p>This quiz has no questions yet.</p></div>
    <?php else: ?>
      <form class="glass section-card" method="POST" action="actions/submit-quiz.php" style="padding:1.5rem;">
        <input type="hidden" name="quiz_id" value="<?= (int)$quiz_id ?>">
        <?php $n = 0; foreach ($questions as $qu): $n++; ?>
        <fieldset style="border:1px solid rgba(255,255,255,0.12);border-radius:10px;padding:1rem;margin-bottom:1rem;">
          <legend style="font-weight:800;padding:0 0.35rem;">Question <?= $n ?></legend>
          <p style="margin:0 0 0.75rem;"><?= nl2br(htmlspecialchars($qu['question_text'])) ?></p>
          <?php foreach (['a' => $qu['option_a'], 'b' => $qu['option_b'], 'c' => $qu['option_c'], 'd' => $qu['option_d']] as $letter => $label): ?>
          <label style="display:flex;align-items:flex-start;gap:0.5rem;margin:0.35rem 0;cursor:pointer;">
            <input type="radio" name="ans[<?= (int)$qu['question_id'] ?>]" value="<?= $letter ?>" required>
            <span><strong><?= strtoupper($letter) ?>.</strong> <?= htmlspecialchars($label) ?></span>
          </label>
          <?php endforeach; ?>
        </fieldset>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Submit answers</button>
      </form>
    <?php endif; ?>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
