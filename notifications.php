<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';
$dash = $role . '-dashboard.php';

if (!tf_table_exists($conn, 'notifications')) {
    header("Location: " . $dash . "?error=" . urlencode("Notifications are not installed. Run database_migration.sql."));
    exit();
}

$mark = (int) ($_GET['mark_read'] ?? 0);
if ($mark > 0) {
    $m = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $m->bind_param("ii", $mark, $user_id);
    $m->execute();
    $m->close();
    header("Location: notifications.php");
    exit();
}

if (($_GET['mark_all'] ?? '') === '1') {
    $m = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $m->bind_param("i", $user_id);
    $m->execute();
    $m->close();
    header("Location: notifications.php");
    exit();
}

$st = $conn->prepare("
    SELECT notification_id, title, body, link_url, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 80
");
$st->bind_param("i", $user_id);
$st->execute();
$rows = tf_stmt_all_assoc($st);
$st->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf3">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= htmlspecialchars($dash) ?>'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="<?= htmlspecialchars($dash) ?>">← Dashboard</a>
      <a href="actions/logout.php?role=<?= urlencode($role) ?>">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:720px;">
    <div class="page-header" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:1rem;">
      <div>
        <h2>Notifications</h2>
        <p style="margin:0;">Lesson updates, quiz assignments, and booking status.</p>
      </div>
      <?php if (!empty($rows)): ?>
      <a class="btn btn-primary btn-sm" href="notifications.php?mark_all=1">Mark all read</a>
      <?php endif; ?>
    </div>

    <?php if (empty($rows)): ?>
      <div class="glass section-card"><p style="opacity:0.7;margin:0;">No notifications yet.</p></div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
      <div class="glass section-card" style="margin-bottom:0.75rem;padding:1rem;opacity:<?= (int)$r['is_read'] ? '0.72' : '1' ?>;">
        <div style="display:flex;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;">
          <strong style="color:var(--teal);"><?= htmlspecialchars($r['title']) ?></strong>
          <span style="font-size:0.78rem;opacity:0.55;"><?= date('d M Y g:i A', strtotime($r['created_at'])) ?></span>
        </div>
        <?php if (!empty($r['body'])): ?>
        <p style="margin:0.5rem 0 0;font-size:0.92rem;"><?= nl2br(htmlspecialchars($r['body'])) ?></p>
        <?php endif; ?>
        <div style="margin-top:0.65rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
          <?php if (!empty($r['link_url'])): ?>
          <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($r['link_url']) ?>">Open</a>
          <?php endif; ?>
          <?php if (!(int)$r['is_read']): ?>
          <a class="btn btn-primary btn-sm" href="notifications.php?mark_read=<?= (int)$r['notification_id'] ?>" style="opacity:0.85;">Mark read</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
