<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('student');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../student-dashboard.php");
    exit();
}
if (!tf_table_exists($conn, 'quiz_attempts') || !tf_table_exists($conn, 'quiz_questions')) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Quiz tables missing. Run database_migration.sql."));
    exit();
}

$quiz_id = (int) ($_POST['quiz_id'] ?? 0);
$student_id = (int) $_SESSION['user_id'];

if ($quiz_id < 1) {
    header("Location: ../student-dashboard.php?error=" . urlencode("Invalid quiz."));
    exit();
}

$has_due = tf_column_exists($conn, 'quizzes', 'due_date');
$dueSel = $has_due ? ', q.due_date' : '';
$has_qs_tbl = tf_table_exists($conn, 'quiz_students');

if ($has_qs_tbl) {
    $q = $conn->prepare("
        SELECT q.quiz_id, b.status AS booking_status{$dueSel}
        FROM quizzes q
        JOIN quiz_students qs ON qs.quiz_id = q.quiz_id AND qs.student_id = ?
        LEFT JOIN bookings b ON b.booking_id = q.booking_id
        WHERE q.quiz_id = ?
        LIMIT 1
    ");
    $q->bind_param("ii", $student_id, $quiz_id);
} else {
    $q = $conn->prepare("
        SELECT q.quiz_id, b.student_id, b.status AS booking_status{$dueSel}
        FROM quizzes q
        JOIN bookings b ON b.booking_id = q.booking_id
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
        header("Location: ../student-dashboard.php?error=" . urlencode("You cannot take this quiz."));
        exit();
    }
    $bs = $quiz['booking_status'] ?? null;
    if ($bs !== null && $bs !== '' && !in_array($bs, ['confirmed', 'completed'], true)) {
        header("Location: ../student-dashboard.php?error=" . urlencode("This lesson is not active for quizzes."));
        exit();
    }
} else {
    if (!$quiz || (int) $quiz['student_id'] !== $student_id) {
        header("Location: ../student-dashboard.php?error=" . urlencode("You cannot take this quiz."));
        exit();
    }
    if (!in_array($quiz['booking_status'] ?? '', ['confirmed', 'completed'], true)) {
        header("Location: ../student-dashboard.php?error=" . urlencode("This lesson is not active for quizzes."));
        exit();
    }
}

if ($has_due && tf_quiz_past_due($quiz['due_date'] ?? null)) {
    header("Location: ../quiz-take.php?id=" . $quiz_id . "&error=" . urlencode("The due date for this quiz has passed."));
    exit();
}

$chk = $conn->prepare("SELECT attempt_id FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? LIMIT 1");
$chk->bind_param("ii", $quiz_id, $student_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows > 0) {
    $chk->close();
    header("Location: ../student-dashboard.php?error=" . urlencode("You already submitted this quiz."));
    exit();
}
$chk->close();

$qs = $conn->prepare("
    SELECT question_id, correct_option
    FROM quiz_questions
    WHERE quiz_id = ?
    ORDER BY sort_order ASC, question_id ASC
");
$qs->bind_param("i", $quiz_id);
$qs->execute();
$questions = tf_stmt_all_assoc($qs);
$qs->close();

if (empty($questions)) {
    header("Location: ../student-dashboard.php?error=" . urlencode("This quiz has no questions."));
    exit();
}

$answers_in = $_POST['ans'] ?? [];
if (!is_array($answers_in)) {
    header("Location: ../quiz-take.php?id=" . $quiz_id . "&error=" . urlencode("Invalid submission."));
    exit();
}

$score = 0;
$total = count($questions);
$rows_to_insert = [];

foreach ($questions as $row) {
    $qid = (int) $row['question_id'];
    $sel = strtolower(trim((string) ($answers_in[$qid] ?? $answers_in[(string) $qid] ?? '')));
    if (!in_array($sel, ['a', 'b', 'c', 'd'], true)) {
        header("Location: ../quiz-take.php?id=" . $quiz_id . "&error=" . urlencode("Answer every question before submitting."));
        exit();
    }
    $correct = strtolower($row['correct_option']);
    $is_ok = ($sel === $correct) ? 1 : 0;
    if ($is_ok) {
        $score++;
    }
    $rows_to_insert[] = [$qid, $sel, $is_ok];
}

mysqli_begin_transaction($conn);
try {
    $ins = $conn->prepare("
        INSERT INTO quiz_attempts (quiz_id, student_id, score, total_questions)
        VALUES (?, ?, ?, ?)
    ");
    $ins->bind_param("iiii", $quiz_id, $student_id, $score, $total);
    if (!$ins->execute()) {
        throw new RuntimeException("Could not save attempt.");
    }
    $attempt_id = (int) $conn->insert_id;
    $ins->close();

    $ans = $conn->prepare("
        INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option, is_correct)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($rows_to_insert as $r) {
        $qid = (int) $r[0];
        $sel = $r[1];
        $is_ok = (int) $r[2];
        $ans->bind_param("iisi", $attempt_id, $qid, $sel, $is_ok);
        if (!$ans->execute()) {
            throw new RuntimeException("Could not save answers.");
        }
    }
    $ans->close();
    mysqli_commit($conn);

    if (tf_table_exists($conn, 'notifications')) {
        $tu = $conn->prepare("SELECT tp.user_id FROM quizzes q JOIN tutor_profiles tp ON tp.tutor_id = q.tutor_id WHERE q.quiz_id = ? LIMIT 1");
        $tu->bind_param("i", $quiz_id);
        $tu->execute();
        $tr = tf_stmt_one_assoc($tu);
        $tu->close();
        $tuid = (int) ($tr['user_id'] ?? 0);
        if ($tuid > 0) {
            tf_notify_user($conn, $tuid, 'Quiz submitted', "A student submitted quiz #{$quiz_id} (score {$score}/{$total}).", 'tutor-dashboard.php#t-quizzes');
        }
    }
} catch (Throwable $e) {
    mysqli_rollback($conn);
    header("Location: ../quiz-take.php?id=" . $quiz_id . "&error=" . urlencode($e->getMessage()));
    exit();
}

header("Location: ../student-dashboard.php?success=" . urlencode("Quiz submitted: {$score} / {$total} correct."));
exit();
