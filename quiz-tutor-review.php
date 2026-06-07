<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'tutor') {
    header("Location: login.html");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$quiz_id = (int) ($_GET['id'] ?? 0);

if ($quiz_id < 1 || !tf_table_exists($conn, 'quizzes')) {
    header("Location: tutor-dashboard.php?error=" . urlencode("Invalid quiz."));
    exit();
}

$has_qsub = tf_column_exists($conn, 'quizzes', 'quiz_subject');
$subSql = $has_qsub
    ? "COALESCE(NULLIF(TRIM(q.quiz_subject), ''), b.subject) AS subject"
    : "b.subject AS subject";

$q = $conn->prepare("
    SELECT q.quiz_id, q.title, {$subSql}
    FROM quizzes q
    LEFT JOIN bookings b ON b.booking_id = q.booking_id
    JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id
    WHERE q.quiz_id = ? AND tp.user_id = ?
    LIMIT 1
");
$q->bind_param("ii", $quiz_id, $user_id);
$q->execute();
$row = tf_stmt_one_assoc($q);
$q->close();

if (!$row) {
    header("Location: tutor-dashboard.php?error=" . urlencode("You cannot view this quiz."));
    exit();
}

$attempts = [];
if (tf_table_exists($conn, 'quiz_attempts')) {
    $at = $conn->prepare("
        SELECT qa.attempt_id, qa.student_id, qa.score, qa.total_questions, qa.submitted_at,
               CONCAT(u.first_name,' ',u.last_name) AS student_name
        FROM quiz_attempts qa
        JOIN users u ON u.user_id = qa.student_id
        WHERE qa.quiz_id = ?
        ORDER BY qa.submitted_at DESC
    ");
    $at->bind_param("i", $quiz_id);
    $at->execute();
    $attempts = tf_stmt_all_assoc($at);
    $at->close();
}

$want_attempt = (int) ($_GET['attempt'] ?? 0);
$chosen = null;
if ($want_attempt > 0) {
    foreach ($attempts as $a) {
        if ((int) $a['attempt_id'] === $want_attempt) {
            $chosen = $a;
            break;
        }
    }
}
if (!$chosen && !empty($attempts)) {
    $chosen = $attempts[0];
}

$lines = [];
$attempt_id = $chosen ? (int) $chosen['attempt_id'] : 0;
$row['subject'] = $row['subject'] ?? '';
$row['student_name'] = $chosen['student_name'] ?? '';
$row['score'] = $chosen['score'] ?? null;
$row['total_questions'] = $chosen['total_questions'] ?? null;
$row['submitted_at'] = $chosen['submitted_at'] ?? null;

if ($attempt_id > 0 && tf_table_exists($conn, 'quiz_questions') && tf_table_exists($conn, 'quiz_attempt_answers')) {
    $d = $conn->prepare("
        SELECT qq.question_text, qq.option_a, qq.option_b, qq.option_c, qq.option_d,
               qq.correct_option, qaa.selected_option, qaa.is_correct
        FROM quiz_questions qq
        JOIN quiz_attempt_answers qaa ON qaa.question_id = qq.question_id AND qaa.attempt_id = ?
        WHERE qq.quiz_id = ?
        ORDER BY qq.sort_order ASC, qq.question_id ASC
    ");
    $d->bind_param("ii", $attempt_id, $quiz_id);
    $d->execute();
    $lines = tf_stmt_all_assoc($d);
    $d->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quiz answers — TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='tutor-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="tutor-dashboard.php">← Dashboard</a>
      <a href="actions/logout.php?role=tutor">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:760px;">
    <div class="page-header">
      <h2><?= htmlspecialchars($row['title']) ?></h2>
      <p><?= htmlspecialchars($row['subject'] ?: '—') ?><?php if ($row['student_name'] !== ''): ?> · <?= htmlspecialchars($row['student_name']) ?><?php endif; ?></p>
    </div>

    <?php if (count($attempts) > 1): ?>
      <div class="glass section-card" style="margin-bottom:1rem;padding:0.85rem 1rem;">
        <label for="attempt-pick" style="display:block;font-weight:700;margin-bottom:0.35rem;">Submission</label>
        <select id="attempt-pick" onchange="if(this.value) location.href='quiz-tutor-review.php?id=<?= (int) $quiz_id ?>&attempt='+encodeURIComponent(this.value);">
          <?php foreach ($attempts as $a): ?>
          <option value="<?= (int) $a['attempt_id'] ?>" <?= (int) $a['attempt_id'] === $attempt_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['student_name']) ?> — <?= (int) $a['score'] ?>/<?= (int) $a['total_questions'] ?> (<?= date('d M Y', strtotime($a['submitted_at'])) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($attempt_id < 1): ?>
      <div class="glass section-card">
        <p>This student has not submitted the quiz yet.</p>
        <a class="btn btn-primary btn-sm" href="tutor-dashboard.php#t-quizzes" style="margin-top:1rem;">Back to quizzes</a>
      </div>
    <?php else: ?>
      <div class="glass section-card" style="margin-bottom:1rem;">
        <p style="margin:0;font-size:1.15rem;font-weight:800;color:var(--teal);">Score: <?= (int) $row['score'] ?> / <?= (int) $row['total_questions'] ?></p>
        <p style="margin:0.35rem 0 0;font-size:0.88rem;opacity:0.7;">Submitted <?= date('d M Y g:i A', strtotime($row['submitted_at'])) ?></p>
      </div>

      <?php if (empty($lines)): ?>
        <div class="glass section-card"><p>Could not load answer details.</p></div>
      <?php else: ?>
        <?php $n = 0; foreach ($lines as $ln): $n++; ?>
        <div class="glass section-card" style="margin-bottom:1rem;padding:1.1rem;">
          <div style="font-weight:800;margin-bottom:0.5rem;">Question <?= $n ?>
            <?php if ((int) $ln['is_correct'] === 1): ?>
              <span style="color:var(--teal);font-size:0.85rem;">✓ Correct</span>
            <?php else: ?>
              <span style="color:#f87171;font-size:0.85rem;">✗ Incorrect</span>
            <?php endif; ?>
          </div>
          <p style="margin:0 0 0.75rem;"><?= nl2br(htmlspecialchars($ln['question_text'])) ?></p>
          <?php
            $opts = ['a' => $ln['option_a'], 'b' => $ln['option_b'], 'c' => $ln['option_c'], 'd' => $ln['option_d']];
            $sel = strtolower((string) $ln['selected_option']);
            $cor = strtolower((string) $ln['correct_option']);
            foreach ($opts as $letter => $label):
                $letter = strtolower($letter);
                $isSel = ($letter === $sel);
                $isCor = ($letter === $cor);
                $style = 'padding:0.35rem 0.5rem;border-radius:8px;margin:0.25rem 0;';
                if ($isCor) {
                    $style .= 'background:rgba(46,196,182,0.2);border:1px solid rgba(46,196,182,0.5);';
                } elseif ($isSel) {
                    $style .= 'background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.45);';
                } else {
                    $style .= 'opacity:0.65;';
                }
          ?>
          <div style="<?= $style ?>">
            <strong><?= strtoupper($letter) ?>.</strong> <?= htmlspecialchars($label) ?>
            <?php if ($isCor): ?><span style="font-size:0.78rem;font-weight:700;"> (correct)</span><?php endif; ?>
            <?php if ($isSel && !$isCor): ?><span style="font-size:0.78rem;"> (student chose)</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <a class="btn btn-primary btn-sm" href="tutor-dashboard.php#t-quizzes">Back to quizzes</a>
    <?php endif; ?>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
