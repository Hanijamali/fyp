<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/config/db.php";

$tutor_id = (int) ($_GET['tutor_id'] ?? 0);
if ($tutor_id < 1) {
    header("Location: search.php");
    exit();
}

$picSel = tf_column_exists($conn, 'users', 'profile_picture') ? ', u.profile_picture' : '';
$stmt = $conn->prepare("
    SELECT tp.tutor_id, tp.subject, tp.rate_per_hour, tp.bio, tp.qualifications,
           tp.experience_years, tp.availability, tp.rating, tp.total_reviews, tp.approved,
           u.first_name, u.last_name, u.created_at AS user_since
           {$picSel}
    FROM tutor_profiles tp
    JOIN users u ON u.user_id = tp.user_id
    WHERE tp.tutor_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $tutor_id);
$stmt->execute();
$t = tf_stmt_one_assoc($stmt);
$stmt->close();

if (!$t) {
    header("Location: search.php?error=" . urlencode("Tutor not found."));
    exit();
}

if (!(int) $t['approved']) {
    header("Location: search.php?error=" . urlencode("This tutor is not listed publicly yet."));
    exit();
}

$name = trim($t['first_name'] . ' ' . $t['last_name']);
$headPic = tf_profile_picture_url($t['profile_picture'] ?? null);
$feedbacks = [];
if (tf_table_exists($conn, 'feedback')) {
    $fb = $conn->prepare("
        SELECT f.rating, f.comment, f.created_at,
               CONCAT(LEFT(su.first_name,1),'. ',su.last_name) AS student_label
        FROM feedback f
        JOIN users su ON su.user_id = f.student_id
        WHERE f.tutor_id = ?
        ORDER BY f.created_at DESC
        LIMIT 8
    ");
    $fb->bind_param("i", $tutor_id);
    $fb->execute();
    $feedbacks = tf_stmt_all_assoc($fb);
    $fb->close();
}

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $_SESSION['role'] ?? '';
$canBookLesson = ($isLoggedIn && in_array($userRole, ['student', 'parent'], true));
$parent_linked_students = 0;
if ($isLoggedIn && $userRole === 'parent' && tf_table_exists($conn, 'parent_students')) {
    $ps = $conn->prepare("SELECT COUNT(*) AS c FROM parent_students WHERE parent_id = ?");
    $ps->bind_param("i", $_SESSION['user_id']);
    $ps->execute();
    $row = tf_stmt_one_assoc($ps);
    $ps->close();
    $parent_linked_students = (int) ($row['c'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($name) ?> — TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf5">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $isLoggedIn ? htmlspecialchars($_SESSION['role'] . '-dashboard.php') : 'index.html' ?>'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="search.php">← Find tutors</a>
      <?php if ($isLoggedIn): ?>
        <a href="<?= htmlspecialchars($_SESSION['role']) ?>-dashboard.php">Dashboard</a>
        <a href="actions/logout.php?role=<?= urlencode($userRole) ?>">Log Out</a>
      <?php else: ?>
        <a href="login.html">Log In</a>
      <?php endif; ?>
    </div>
  </nav>

  <div class="page-content" style="max-width:720px;">
    <div class="glass section-card" style="padding:1.75rem;">
      <div class="tutor-public-hero">
        <div class="tutor-avatar tutor-public-avatar<?= $headPic ? ' has-photo' : '' ?>"><?php if ($headPic): ?><img src="<?= htmlspecialchars($headPic) ?>" alt=""><?php else: ?>👩‍🏫<?php endif; ?></div>
        <div class="tutor-public-info">
          <h2 style="margin:0 0 0.35rem;"><?= htmlspecialchars($name) ?></h2>
          <p style="margin:0;opacity:0.85;font-weight:700;color:var(--teal);"><?= htmlspecialchars($t['subject'] ?? '') ?></p>
          <p style="margin:0.5rem 0 0;font-size:0.88rem;opacity:0.65;">Member since <?= date('M Y', strtotime($t['user_since'])) ?></p>
        </div>
        <div class="tutor-public-meta" style="text-align:right;">
          <div class="tutor-rate" style="font-size:1.35rem;">RM <?= number_format((float) $t['rate_per_hour'], 0) ?><span style="font-size:0.75rem;opacity:0.75">/hr</span></div>
          <div class="stars" style="margin-top:0.35rem;">
            <?= str_repeat('★', (int) floor((float) $t['rating'])) ?>
            <?= (float) $t['rating'] > 0 ? htmlspecialchars(number_format((float) $t['rating'], 1)) : 'New' ?>
          </div>
          <div style="font-size:0.78rem;opacity:0.6;"><?= (int) ($t['total_reviews'] ?? 0) ?> reviews</div>
        </div>
      </div>

      <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.1);">
        <p style="margin:0 0 0.35rem;font-size:0.82rem;opacity:0.65;text-transform:uppercase;letter-spacing:0.06em;">Availability</p>
        <p style="margin:0;font-weight:700;"><?= htmlspecialchars($t['availability'] ?? '—') ?></p>
      </div>

      <?php if (!empty($t['qualifications'])): ?>
      <div style="margin-top:1rem;">
        <p style="margin:0 0 0.35rem;font-size:0.82rem;opacity:0.65;text-transform:uppercase;letter-spacing:0.06em;">Qualifications</p>
        <p style="margin:0;"><?= htmlspecialchars($t['qualifications']) ?></p>
      </div>
      <?php endif; ?>

      <div style="margin-top:1rem;">
        <p style="margin:0 0 0.35rem;font-size:0.82rem;opacity:0.65;text-transform:uppercase;letter-spacing:0.06em;">Experience</p>
        <p style="margin:0;"><?= (int) ($t['experience_years'] ?? 0) ?> years tutoring</p>
      </div>

      <?php if (!empty($t['bio'])): ?>
      <div style="margin-top:1rem;">
        <p style="margin:0 0 0.35rem;font-size:0.82rem;opacity:0.65;text-transform:uppercase;letter-spacing:0.06em;">About</p>
        <p style="margin:0;line-height:1.55;white-space:pre-wrap;"><?= htmlspecialchars($t['bio']) ?></p>
      </div>
      <?php endif; ?>

      <div style="margin-top:1.5rem;display:flex;flex-wrap:wrap;gap:0.65rem;">
        <?php if ($canBookLesson): ?>
          <?php if ($userRole === 'parent' && $parent_linked_students < 1): ?>
            <a href="parent-dashboard.php" class="btn btn-primary">Link a student to book</a>
          <?php else: ?>
            <a href="book-lesson.php?tutor_id=<?= (int) $tutor_id ?>" class="btn btn-primary">Book a lesson</a>
          <?php endif; ?>
        <?php elseif (!$isLoggedIn): ?>
          <a href="login.html" class="btn btn-primary">Log in to book</a>
        <?php endif; ?>
        <a href="search.php" class="btn btn-primary btn-sm" style="opacity:0.9;">Back to search</a>
      </div>
    </div>

    <?php if (!empty($feedbacks)): ?>
    <div class="glass section-card" style="margin-top:1rem;padding:1.25rem;">
      <h3 style="margin:0 0 1rem;">Recent <span>feedback</span></h3>
      <?php foreach ($feedbacks as $f): ?>
      <div style="padding:0.75rem 0;border-bottom:1px solid rgba(255,255,255,0.08);">
        <div style="display:flex;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
          <span style="font-weight:700;"><?= str_repeat('⭐', (int) $f['rating']) ?></span>
          <span style="font-size:0.82rem;opacity:0.6;"><?= htmlspecialchars($f['student_label']) ?> · <?= date('d M Y', strtotime($f['created_at'])) ?></span>
        </div>
        <?php if (!empty($f['comment'])): ?>
        <p style="margin:0.5rem 0 0;font-size:0.92rem;opacity:0.88;"><?= nl2br(htmlspecialchars($f['comment'])) ?></p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
