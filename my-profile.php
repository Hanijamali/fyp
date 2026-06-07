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

$has_pic = tf_column_exists($conn, 'users', 'profile_picture');
$sel = 'user_id, first_name, last_name, email, role, created_at';
if ($has_pic) {
    $sel .= ', profile_picture';
}
$st = $conn->prepare("SELECT {$sel} FROM users WHERE user_id = ? LIMIT 1");
$st->bind_param("i", $user_id);
$st->execute();
$user = tf_stmt_one_assoc($st);
$st->close();
$user = $user ?: [];

$picUrl = $has_pic ? tf_profile_picture_url($user['profile_picture'] ?? null) : null;

$tutor_self = null;
if ($role === 'tutor' && tf_table_exists($conn, 'tutor_profiles')) {
    $tp = $conn->prepare("SELECT tutor_id, approved FROM tutor_profiles WHERE user_id = ? LIMIT 1");
    $tp->bind_param("i", $user_id);
    $tp->execute();
    $tutor_self = tf_stmt_one_assoc($tp);
    $tp->close();
}

$linked_students = [];
if ($role === 'parent' && tf_table_exists($conn, 'parent_students')) {
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
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$dash = $role . '-dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My profile - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=pf2">
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

  <div class="page-content" style="max-width:640px;">
    <div class="page-header">
      <h2>My <span>profile</span></h2>
      <p>Add a profile picture and keep your name up to date. Your login email is not shown on public tutor pages.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($has_pic): ?>
    <div class="glass section-card" style="padding:1.5rem;margin-bottom:1rem;">
      <h3 style="margin:0 0 1rem;">Profile <span>picture</span></h3>
      <div style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap;">
        <div class="sidebar-avatar sidebar-avatar-lg<?= $picUrl ? ' has-photo' : '' ?>" style="margin:0;font-size:2.4rem;">
          <?php if ($picUrl): ?>
            <img src="<?= htmlspecialchars($picUrl) ?>" alt="" width="96" height="96">
          <?php else: ?>
            <?= $role === 'tutor' ? '👩‍🏫' : ($role === 'parent' ? '👪' : '👨‍🎓') ?>
          <?php endif; ?>
        </div>
        <div style="flex:1;min-width:200px;">
          <form method="POST" action="actions/upload-profile-picture.php" enctype="multipart/form-data" style="margin-bottom:0.65rem;">
            <label class="btn btn-primary btn-sm" style="cursor:pointer;display:inline-block;">
              Choose photo
              <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" onchange="this.form.submit()">
            </label>
          </form>
          <p style="font-size:0.8rem;opacity:0.65;margin:0 0 0.5rem;">JPG, PNG, WebP, or GIF · max 2 MB. With <strong>PHP GD</strong> on, images are resized to a small JPEG. If GD is off: max <strong>600×600</strong> px and <strong>512 KB</strong>.</p>
          <?php if ($picUrl): ?>
          <form method="POST" action="actions/remove-profile-picture.php" onsubmit="return confirm('Remove your profile picture?');">
            <button type="submit" class="btn btn-danger btn-sm">Remove picture</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="glass section-card" style="padding:1.25rem;margin-bottom:1rem;opacity:0.85;">
      <p style="margin:0;">Run <code>database_migration.sql</code> to add the <strong>profile_picture</strong> column, then reload this page to upload a photo.</p>
    </div>
    <?php endif; ?>

    <div class="glass section-card" style="padding:1.5rem;">
      <h3 style="margin:0 0 1rem;">Name</h3>
      <form method="POST" action="actions/update-my-profile.php">
        <div class="form-row">
          <div class="form-group">
            <label>First name</label>
            <input type="text" name="first_name" required maxlength="100" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Last name</label>
            <input type="text" name="last_name" required maxlength="100" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled style="opacity:0.75;">
          <p style="font-size:0.78rem;opacity:0.55;margin:0.35rem 0 0;">Used for login only.</p>
        </div>
        <button type="submit" class="btn btn-primary">Save name</button>
      </form>
    </div>

    <?php if ($role === 'tutor' && $tutor_self): ?>
    <div class="glass section-card" style="margin-top:1rem;padding:1.25rem;">
      <h3 style="margin:0 0 0.5rem;">Teaching <span>details</span></h3>
      <p style="opacity:0.8;font-size:0.92rem;margin:0 0 1rem;">Subject, rates, qualifications, and bio are edited on your dashboard.</p>
      <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
        <a class="btn btn-primary btn-sm" href="tutor-dashboard.php#t-profile">Edit teaching profile</a>
        <?php if (!empty($tutor_self['approved'])): ?>
        <a class="btn btn-primary btn-sm" href="tutor-public.php?tutor_id=<?= (int) $tutor_self['tutor_id'] ?>">View public page</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($role === 'parent' && !empty($linked_students)): ?>
    <div class="glass section-card" style="margin-top:1rem;padding:1.25rem;">
      <h3 style="margin:0 0 0.5rem;">Linked <span>students</span></h3>
      <ul style="margin:0;padding-left:1.1rem;line-height:1.7;">
        <?php foreach ($linked_students as $ch): ?>
        <li><?= htmlspecialchars($ch['first_name'] . ' ' . $ch['last_name']) ?> <span style="opacity:0.6;font-size:0.85rem;">(<?= htmlspecialchars($ch['email']) ?>)</span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <p style="text-align:center;margin-top:1.25rem;opacity:0.55;font-size:0.88rem;"><?= htmlspecialchars(ucfirst($role)) ?> · Joined <?= !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '—' ?></p>
  </div>

  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
