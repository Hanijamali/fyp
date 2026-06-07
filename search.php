<?php
// ============================================================
// search.php — Tutor Search from Database
// ============================================================
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/config/db.php";

$subject  = trim($_GET['subject']  ?? '');
$avail    = trim($_GET['avail']    ?? '');
$query    = trim($_GET['q']        ?? '');

// Build search query
$where = ["tp.approved = 1", "u.status = 'active'"];
$params = [];
$types  = '';

if ($subject) {
    $where[]  = "tp.subject = ?";
    $params[] = $subject;
    $types   .= 's';
}
if ($avail) {
    $where[]  = "tp.availability = ?";
    $params[] = $avail;
    $types   .= 's';
}
if ($query) {
    $where[]  = "(CONCAT(u.first_name,' ',u.last_name) LIKE ? OR tp.subject LIKE ?)";
    $params[] = "%$query%";
    $params[] = "%$query%";
    $types   .= 'ss';
}

$picSel = tf_column_exists($conn, 'users', 'profile_picture') ? ', u.profile_picture' : '';
$sql = "
    SELECT tp.tutor_id, tp.subject, tp.rate_per_hour, tp.bio,
           tp.availability, tp.rating, tp.total_reviews, tp.qualifications,
           CONCAT(u.first_name,' ',u.last_name) AS tutor_name
           {$picSel}
    FROM tutor_profiles tp
    JOIN users u ON tp.user_id = u.user_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY tp.rating DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $tutors = tf_stmt_all_assoc($stmt);
    $stmt->close();
} else {
    $tutors = tf_query_all_assoc($conn, $sql);
}

$subjects = ['Mathematics','Physics','Chemistry','Biology','English Language','Computer Science','Additional Mathematics','Bahasa Malaysia'];
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
<title>Find Tutors - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf6">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-search" class="page active page-search">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $isLoggedIn ? ($_SESSION['role'] . '-dashboard.php') : 'index.html' ?>'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="index.html">Home</a>
      <?php if ($isLoggedIn): ?>
        <a href="<?= $_SESSION['role'] ?>-dashboard.php">Dashboard</a>
        <a href="actions/logout.php?role=<?= urlencode($userRole) ?>">Log Out</a>
      <?php else: ?>
        <a href="login.html">Log In</a>
        <a class="nav-cta" href="signup.html">Sign Up</a>
      <?php endif; ?>
    </div>
  </nav>

  <div class="page-content">
    <div class="page-header">
      <h2>Find Your <span>Perfect</span> Tutor</h2>
      <p>Browse verified tutors by subject, availability, and rating</p>
    </div>

    <form method="GET" action="search.php">
      <?php if (!empty($_GET['as'])): ?>
      <input type="hidden" name="as" value="<?= htmlspecialchars($_GET['as'], ENT_QUOTES, 'UTF-8') ?>">
      <?php elseif ($isLoggedIn && $userRole !== ''): ?>
      <input type="hidden" name="as" value="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>">
      <?php endif; ?>
      <div class="search-wrap">
        <span>🔍</span>
        <input type="text" name="q" placeholder="Search by name or subject..." value="<?= htmlspecialchars($query) ?>">
        <select name="subject">
          <option value="">All Subjects</option>
          <?php foreach ($subjects as $s): ?>
          <option <?= $subject === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <select name="avail">
          <option value="">Any Availability</option>
          <?php foreach (['Weekdays','Weekends','Evenings'] as $a): ?>
          <option <?= $avail === $a ? 'selected' : '' ?>><?= $a ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
      </div>
    </form>

    <div class="filter-chips">
      <a class="chip <?= !$subject ? 'active' : '' ?>" href="<?= htmlspecialchars(tf_build_url('search.php')) ?>">All</a>
      <?php foreach ($subjects as $s): ?>
      <a class="chip <?= $subject === $s ? 'active' : '' ?>" href="<?= htmlspecialchars(tf_build_url('search.php', ['subject' => $s])) ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($canBookLesson): ?>
    <p style="max-width:720px;margin:0 auto 1rem;font-size:0.88rem;opacity:0.78;line-height:1.45;text-align:center;">
      <?php if ($userRole === 'parent'): ?>
        Tap <strong>Book Lesson</strong> on a tutor — you will choose which child on the booking page.
      <?php else: ?>
        Open a tutor card and use <strong>Book Lesson</strong> to request a time. Your tutor confirms it on their dashboard.
      <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if (empty($tutors)): ?>
      <div style="text-align:center;color:rgba(255,255,255,0.5);padding:3rem;">
        No tutors found for this search. Try different filters!
      </div>
    <?php else: ?>
    <div class="grid-3">
      <?php foreach ($tutors as $t): ?>
      <?php $tPic = tf_profile_picture_url($t['profile_picture'] ?? null); ?>
      <div class="glass tutor-card">
        <div class="tutor-avatar<?= $tPic ? ' has-photo' : '' ?>"><?php if ($tPic): ?><img src="<?= htmlspecialchars($tPic) ?>" alt=""><?php else: ?>👩‍🏫<?php endif; ?></div>
        <div class="tutor-name"><?= htmlspecialchars($t['tutor_name']) ?></div>
        <div class="tutor-subject"><?= htmlspecialchars($t['subject']) ?></div>
        <?php if ($t['qualifications']): ?>
        <div class="tutor-tags"><span class="tag"><?= htmlspecialchars($t['qualifications']) ?></span></div>
        <?php endif; ?>
        <div class="tutor-meta">
          <div>
            <div class="stars">
              <?= str_repeat('★', floor($t['rating'])) ?> <?= $t['rating'] > 0 ? $t['rating'] : 'New' ?>
            </div>
            <div style="font-size:0.75rem;opacity:0.6;"><?= (int)($t['total_reviews'] ?? 0) ?> reviews</div>
          </div>
          <div class="tutor-rate">RM <?= number_format($t['rate_per_hour'], 0) ?>/hr</div>
        </div>
        <div style="font-size:0.8rem;opacity:0.6;margin:0.4rem 0;">📅 <?= htmlspecialchars($t['availability']) ?></div>
        <?php if ($t['bio']): ?>
        <p style="font-size:0.82rem;opacity:0.7;margin:0.5rem 0 0.8rem;"><?= htmlspecialchars(substr($t['bio'], 0, 100)) ?>...</p>
        <?php endif; ?>
        <div class="tutor-card-cta">
        <a href="tutor-public.php?tutor_id=<?= (int) $t['tutor_id'] ?>" class="btn btn-primary btn-sm tutor-card-cta-view">View profile</a>
        <?php if ($canBookLesson): ?>
          <?php if ($userRole === 'parent' && $parent_linked_students < 1): ?>
            <a href="parent-dashboard.php?error=<?= rawurlencode('Link a student first: sign up Parent using child student email, or insert into parent_students in phpMyAdmin.') ?>" class="btn btn-primary btn-sm">Link student to book</a>
          <?php else: ?>
            <a href="<?= htmlspecialchars(tf_build_url('book-lesson.php', ['tutor_id' => (int) $t['tutor_id']])) ?>" class="btn btn-primary btn-sm tutor-card-cta-book">Book Lesson</a>
          <?php endif; ?>
        <?php elseif (!$isLoggedIn): ?>
          <a href="login.html" class="btn btn-primary btn-sm">Log In to Book</a>
        <?php else: ?>
          <div class="tutor-card-book-hint">
            <?php if ($userRole === 'tutor'): ?>
              Tutor accounts cannot book lessons. Use a <strong>student</strong> account (or ask a parent to book for their child).
            <?php elseif ($userRole === 'admin'): ?>
              Admins cannot book as a user. Log in as <strong>student</strong> or <strong>parent</strong> to book, or browse only from this account.
            <?php else: ?>
              Booking is only available for student and parent accounts.
            <?php endif; ?>
          </div>
        <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
