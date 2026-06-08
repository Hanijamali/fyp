<?php
// ============================================================
// actions/book-lesson.php — Save Booking to Database
// ============================================================
require_once __DIR__ . "/../config/session.php";
$bookAs = strtolower(trim((string) ($_GET['as'] ?? $_POST['as'] ?? '')));
if ($bookAs === 'parent') {
    tf_session_start_role('parent');
} elseif ($bookAs === 'student') {
    tf_session_start_role('student');
} else {
    tf_session_start_any(['student', 'parent']);
}
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../search.php");
    exit();
}

$logged_user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$tutor_id    = intval($_POST['tutor_id']   ?? 0);
$subject     = trim($_POST['subject']      ?? '');
$lesson_date = trim($_POST['lesson_date']  ?? '');
$lesson_time = trim($_POST['lesson_time']  ?? '');
$duration    = trim($_POST['duration']     ?? '1 hour');
$notes       = trim($_POST['notes']        ?? '');
$posted_student_user_id = isset($_POST['student_user_id']) ? (int) $_POST['student_user_id'] : 0;

$book_back_qs = static function (int $tid, string $r, int $sid = 0): string {
    $q = 'tutor_id=' . urlencode((string) $tid);
    if ($r !== '') {
        $q .= '&as=' . rawurlencode($r);
    }
    if ($r === 'parent' && $sid > 0) {
        $q .= '&student_id=' . $sid;
    }
    return $q;
};

$student_id = $logged_user_id;
if ($role === 'student') {
    $student_id = $logged_user_id;
} elseif ($role === 'parent') {
    if (!$posted_student_user_id) {
        header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, 0) . "&error=" . urlencode("Please choose which child this lesson is for."));
        exit();
    }
    if (!tf_table_exists($conn, 'parent_students')) {
        header("Location: ../parent-dashboard.php?error=" . urlencode("Missing parent_students table. Run database_migration.sql."));
        exit();
    }

    $check = $conn->prepare("
        SELECT student_id FROM parent_students
        WHERE parent_id = ? AND student_id = ?
        LIMIT 1
    ");
    $check->bind_param("ii", $logged_user_id, $posted_student_user_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows !== 1) {
        $check->close();
        header("Location: ../parent-dashboard.php?error=" . urlencode("That student is not linked to your parent account."));
        exit();
    }
    $check->close();

    $student_id = $posted_student_user_id;
} else {
    header("Location: ../login.html");
    exit();
}

// --- Validation ---
$back_sid = ($role === 'parent') ? $posted_student_user_id : 0;

if (!$tutor_id || empty($subject) || empty($lesson_date) || empty($lesson_time)) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Please fill in all required fields."));
    exit();
}

// Validate date is not in the past
if (strtotime($lesson_date) < strtotime('today')) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Please select a future date."));
    exit();
}

// --- Get tutor rate + availability ---
$rate_stmt = $conn->prepare("SELECT rate_per_hour, availability, subject FROM tutor_profiles WHERE tutor_id = ?");
$rate_stmt->bind_param("i", $tutor_id);
$rate_stmt->execute();
$rate_result = tf_stmt_one_assoc($rate_stmt);
$rate_stmt->close();

$rate = $rate_result['rate_per_hour'] ?? 0;
$availability = strtolower(trim((string)($rate_result['availability'] ?? 'weekdays')));
$allowed_subject = trim((string) ($rate_result['subject'] ?? ''));
if ($allowed_subject === '') {
    $allowed_subject = 'General';
}
if (trim($subject) !== $allowed_subject) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Lesson subject must match this tutor's profile."));
    exit();
}

$lesson_ts = strtotime($lesson_date);
$day_num = (int) date('N', $lesson_ts); // 1 Mon .. 7 Sun
$is_weekend = ($day_num >= 6);
$lesson_hour = (int) date('G', strtotime($lesson_time));

if ($availability === 'weekdays' && $is_weekend) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Tutor is available on weekdays only."));
    exit();
}
if ($availability === 'weekends' && !$is_weekend) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Tutor is available on weekends only."));
    exit();
}
if ($availability === 'evenings' && $lesson_hour < 18) {
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Tutor is available in evenings only (6:00 PM onwards)."));
    exit();
}
$hours = 1;
if ($duration === '1.5 hours') $hours = 1.5;
if ($duration === '2 hours')   $hours = 2;
$total = $rate * $hours;

// --- Insert booking ---
$stmt = $conn->prepare("INSERT INTO bookings (student_id, tutor_id, subject, lesson_date, lesson_time, duration, notes, status, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
$stmt->bind_param("iisssssd", $student_id, $tutor_id, $subject, $lesson_date, $lesson_time, $duration, $notes, $total);

if ($stmt->execute()) {
    $new_booking_id = (int) $conn->insert_id;
    $stmt->close();

    if (tf_table_exists($conn, 'notifications')) {
        $tu = $conn->prepare("SELECT tp.user_id FROM tutor_profiles tp WHERE tp.tutor_id = ? LIMIT 1");
        $tu->bind_param("i", $tutor_id);
        $tu->execute();
        $trow = tf_stmt_one_assoc($tu);
        $tu->close();
        $tutor_user = (int) ($trow['user_id'] ?? 0);
        $when = date('d M Y', strtotime($lesson_date)) . ' ' . date('g:i A', strtotime($lesson_time));
        if ($tutor_user > 0) {
            tf_notify_user(
                $conn,
                $tutor_user,
                'New lesson request',
                "Subject: {$subject}\nDate: {$when}\nBooking #{$new_booking_id} is pending your response.",
                'tutor-dashboard.php#t-requests'
            );
        }
        tf_notify_user(
            $conn,
            $student_id,
            'Lesson request sent',
            "You requested {$subject} on {$when}. The tutor will confirm soon.",
            'student-dashboard.php#s-lessons'
        );
        if ($role === 'parent' && $logged_user_id !== $student_id) {
            tf_notify_user(
                $conn,
                $logged_user_id,
                'Lesson booked for your child',
                "Booking #{$new_booking_id} — {$subject} on {$when}.",
                'parent-dashboard.php#p-lessons'
            );
        }
    }

    if ($role === 'parent') {
        header("Location: ../parent-dashboard.php?success=" . urlencode("Lesson booked successfully for your student!"));
    } else {
        header("Location: ../student-dashboard.php?success=" . urlencode("Lesson booked successfully!"));
    }
} else {
    $stmt->close();
    header("Location: ../book-lesson.php?" . $book_back_qs($tutor_id, $role, $back_sid) . "&error=" . urlencode("Booking failed. Please try again."));
}
exit();
?>
