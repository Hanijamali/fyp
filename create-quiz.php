<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_role('tutor');
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'tutor') {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../tutor-dashboard.php");
    exit();
}
if (!tf_table_exists($conn, 'quizzes') || !tf_table_exists($conn, 'quiz_questions')) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Quiz tables missing. Run database_migration.sql."));
    exit();
}

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$tutor_user_id = (int) $_SESSION['user_id'];
$has_quiz_students = tf_table_exists($conn, 'quiz_students');
$has_quiz_subject = tf_column_exists($conn, 'quizzes', 'quiz_subject');

if ($booking_id < 1 || $title === '') {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Context lesson and quiz title are required."));
    exit();
}

$ck = $conn->prepare("
    SELECT b.booking_id, b.tutor_id, b.student_id, b.status, b.subject
    FROM bookings b
    JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
    WHERE b.booking_id = ? AND tp.user_id = ?
    LIMIT 1
");
$ck->bind_param("ii", $booking_id, $tutor_user_id);
$ck->execute();
$booking = tf_stmt_one_assoc($ck);
$ck->close();

if (!$booking || !in_array($booking['status'], ['confirmed', 'completed'], true)) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Pick a confirmed or completed lesson as context."));
    exit();
}

$tutor_id = (int) $booking['tutor_id'];
$context_student = (int) $booking['student_id'];
$quiz_subject = trim((string) ($booking['subject'] ?? ''));

$raw_ids = $_POST['quiz_student_ids'] ?? null;
$student_ids = [];
if ($has_quiz_students && is_array($raw_ids)) {
    foreach ($raw_ids as $x) {
        $sid = (int) $x;
        if ($sid > 0) {
            $student_ids[$sid] = true;
        }
    }
    $student_ids = array_keys($student_ids);
}
if (empty($student_ids)) {
    $student_ids = [$context_student];
}

// Each selected student must have at least one confirmed/completed lesson with this tutor
$chkStu = $conn->prepare("
    SELECT 1 FROM bookings b
    WHERE b.tutor_id = ? AND b.student_id = ? AND b.status IN ('confirmed','completed')
    LIMIT 1
");
foreach ($student_ids as $sid) {
    $chkStu->bind_param("ii", $tutor_id, $sid);
    $chkStu->execute();
    $chkStu->store_result();
    if ($chkStu->num_rows < 1) {
        $chkStu->close();
        header("Location: ../tutor-dashboard.php?error=" . urlencode("Each selected student must have a confirmed or completed lesson with you."));
        exit();
    }
}
$chkStu->close();

$max_questions = 30;
$raw = $_POST['questions'] ?? null;
$questions = [];
if (is_array($raw)) {
    $count = 0;
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($count >= $max_questions) {
            break;
        }
        $text = trim((string) ($row['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $a = trim((string) ($row['a'] ?? ''));
        $b = trim((string) ($row['b'] ?? ''));
        $c = trim((string) ($row['c'] ?? ''));
        $d = trim((string) ($row['d'] ?? ''));
        $correct = strtolower(trim((string) ($row['correct'] ?? '')));
        if ($a === '' || $b === '' || $c === '' || $d === '') {
            header("Location: ../tutor-dashboard.php?error=" . urlencode("Each quiz question needs all four answer options."));
            exit();
        }
        if (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
            header("Location: ../tutor-dashboard.php?error=" . urlencode("Each question needs a valid correct answer (A–D)."));
            exit();
        }
        $questions[] = [
            'text' => $text,
            'a' => $a,
            'b' => $b,
            'c' => $c,
            'd' => $d,
            'correct' => $correct,
        ];
        $count++;
    }
}

if (count($questions) < 1) {
    header("Location: ../tutor-dashboard.php?error=" . urlencode("Add at least one complete question."));
    exit();
}

$has_due_col = tf_column_exists($conn, 'quizzes', 'due_date');
$due_val = null;
if ($has_due_col) {
    $raw_due = trim((string) ($_POST['due_date'] ?? ''));
    if ($raw_due !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $raw_due);
        if (!$dt || $dt->format('Y-m-d') !== $raw_due) {
            header("Location: ../tutor-dashboard.php?error=" . urlencode("Invalid due date."));
            exit();
        }
        if ($raw_due < date('Y-m-d')) {
            header("Location: ../tutor-dashboard.php?error=" . urlencode("Due date cannot be in the past."));
            exit();
        }
        $due_val = $raw_due;
    }
}

mysqli_begin_transaction($conn);
try {
    if ($has_quiz_subject && $has_due_col) {
        if ($due_val !== null) {
            $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title, quiz_subject, due_date) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param("iisss", $booking_id, $tutor_id, $title, $quiz_subject, $due_val);
        } else {
            $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title, quiz_subject, due_date) VALUES (?, ?, ?, ?, NULL)");
            $ins->bind_param("iiss", $booking_id, $tutor_id, $title, $quiz_subject);
        }
    } elseif ($has_quiz_subject) {
        $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title, quiz_subject) VALUES (?, ?, ?, ?)");
        $ins->bind_param("iiss", $booking_id, $tutor_id, $title, $quiz_subject);
    } elseif ($has_due_col) {
        if ($due_val !== null) {
            $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title, due_date) VALUES (?, ?, ?, ?)");
            $ins->bind_param("iiss", $booking_id, $tutor_id, $title, $due_val);
        } else {
            $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title, due_date) VALUES (?, ?, ?, NULL)");
            $ins->bind_param("iis", $booking_id, $tutor_id, $title);
        }
    } else {
        $ins = $conn->prepare("INSERT INTO quizzes (booking_id, tutor_id, title) VALUES (?, ?, ?)");
        $ins->bind_param("iis", $booking_id, $tutor_id, $title);
    }
    if (!$ins->execute()) {
        throw new RuntimeException("Could not create quiz.");
    }
    $quiz_id = (int) $conn->insert_id;
    $ins->close();

    if ($has_quiz_students) {
        $insQs = $conn->prepare("INSERT IGNORE INTO quiz_students (quiz_id, student_id) VALUES (?, ?)");
        foreach ($student_ids as $sid) {
            $insQs->bind_param("ii", $quiz_id, $sid);
            if (!$insQs->execute()) {
                throw new RuntimeException("Could not assign students to quiz.");
            }
        }
        $insQs->close();
    }

    $qst = $conn->prepare("
        INSERT INTO quiz_questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ord = 0;
    foreach ($questions as $row) {
        $ord++;
        $qst->bind_param(
            "issssssi",
            $quiz_id,
            $row['text'],
            $row['a'],
            $row['b'],
            $row['c'],
            $row['d'],
            $row['correct'],
            $ord
        );
        if (!$qst->execute()) {
            throw new RuntimeException("Could not save questions.");
        }
    }
    $qst->close();
    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    header("Location: ../tutor-dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}

$nStudents = count($student_ids);
foreach ($student_ids as $sid) {
    tf_notify_user(
        $conn,
        $sid,
        'New quiz: ' . $title,
        ($quiz_subject !== '' ? "Subject: {$quiz_subject}\n" : '') . "You have a new quiz to complete.",
        'quiz-take.php?id=' . $quiz_id
    );
    if (tf_table_exists($conn, 'parent_students')) {
        $ps = $conn->prepare("SELECT parent_id FROM parent_students WHERE student_id = ?");
        $ps->bind_param("i", $sid);
        $ps->execute();
        $prows = tf_stmt_all_assoc($ps);
        $ps->close();
        foreach ($prows as $pr) {
            $pid = (int) ($pr['parent_id'] ?? 0);
            if ($pid > 0) {
                tf_notify_user(
                    $conn,
                    $pid,
                    'Quiz assigned to your child',
                    'A tutor posted a quiz. Ask your child to complete it from their dashboard.',
                    'parent-dashboard.php#p-quizzes'
                );
            }
        }
    }
}

header("Location: ../tutor-dashboard.php?success=" . urlencode("Quiz created with {$ord} question(s) for {$nStudents} student(s)."));
exit();
