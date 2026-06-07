<?php
// ============================================================
// search.php — Tutor Search from Database
// ============================================================
session_start();
require_once "config/db.php";

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

$sql = "
    SELECT tp.tutor_id, tp.subject, tp.rate_per_hour, tp.bio,
           tp.availability, tp.rating, tp.total_reviews, tp.qualifications,
           CONCAT(u.first_name,' ',u.last_name) AS tutor_name
    FROM tutor_profiles tp
    JOIN users u ON tp.user_id = u.user_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY tp.rating DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $tutors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $tutors = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

$subjects = ['Mathematics','Physics','Chemistry','Biology','English Language','Computer Science','Additional Mathematics','Bahasa Malaysia'];
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Find Tutors - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div id="page-search" class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='index.html'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="index.html">Home</a>
      <?php if ($isLoggedIn): ?>
        <a href="<?= $_SESSION['role'] ?>-dashboard.php">Dashboard</a>
        <a href="actions/logout.php">Log Out</a>
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
      <a class="chip <?= !$subject ? 'active' : '' ?>" href="search.php">All</a>
      <?php foreach ($subjects as $s): ?>
      <a class="chip <?= $subject === $s ? 'active' : '' ?>" href="search.php?subject=<?= urlencode($s) ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($tutors)): ?>
      <div style="text-align:center;color:rgba(255,255,255,0.5);padding:3rem;">
        No tutors found for this search. Try different filters!
      </div>
    <?php else: ?>
    <div class="grid-3">
      <?php foreach ($tutors as $t): ?>
      <div class="glass tutor-card">
        <div class="tutor-avatar">👩‍🏫</div>
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
            <div style="font-size:0.75rem;opacity:0.6;"><?= $t['total_reviews'] ?> reviews</div>
          </div>
          <div class="tutor-rate">RM <?= number_format($t['rate_per_hour'], 0) ?>/hr</div>
        </div>
        <div style="font-size:0.8rem;opacity:0.6;margin:0.4rem 0;">📅 <?= htmlspecialchars($t['availability']) ?></div>
        <?php if ($t['bio']): ?>
        <p style="font-size:0.82rem;opacity:0.7;margin:0.5rem 0 0.8rem;"><?= htmlspecialchars(substr($t['bio'], 0, 100)) ?>...</p>
        <?php endif; ?>
        <?php if ($isLoggedIn && $_SESSION['role'] === 'student'): ?>
          <a href="book-lesson.php?tutor_id=<?= $t['tutor_id'] ?>" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:0.8rem;">Book Lesson</a>
        <?php elseif (!$isLoggedIn): ?>
          <a href="login.html" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:0.8rem;">Log In to Book</a>
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
