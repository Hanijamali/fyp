<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent']);
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); exit();
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student','parent'], true)) {
    header("Location: login.html"); exit();
}

$uid = (int) $_SESSION['user_id'];
$has_payments = tf_table_exists($conn, 'payments');
$has_parent_links = tf_table_exists($conn, 'parent_students');

$student_ids = [];
if ($role === 'student') {
    $student_ids = [$uid];
} else {
    if ($has_parent_links) {
        $ps = $conn->prepare("SELECT student_id FROM parent_students WHERE parent_id = ?");
        $ps->bind_param("i", $uid);
        $ps->execute();
        $rows = tf_stmt_all_assoc($ps);
        $ps->close();
        $student_ids = array_map(fn($r) => (int)$r['student_id'], $rows);
    }
}

$bookings = [];
$payments = [];
$paid_total = 0.0;
$pending_total = 0.0;
if (!empty($student_ids)) {
    $ph = implode(',', array_fill(0, count($student_ids), '?'));
    $types = str_repeat('i', count($student_ids));

    $bq = "
        SELECT b.booking_id, b.subject, b.lesson_date, b.total_amount, b.payment_status,
               CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
               CONCAT(su.first_name,' ',su.last_name) AS student_name
        FROM bookings b
        JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
        JOIN users u ON u.user_id = tp.user_id
        JOIN users su ON su.user_id = b.student_id
        WHERE b.student_id IN ($ph) AND b.status IN ('confirmed','completed')
        ORDER BY b.lesson_date DESC
        LIMIT 50
    ";
    $bs = $conn->prepare($bq);
    $bs->bind_param($types, ...$student_ids);
    $bs->execute();
    $bookings = tf_stmt_all_assoc($bs);
    $bs->close();
    foreach ($bookings as $row) {
        $amt = (float) ($row['total_amount'] ?? 0);
        if (($row['payment_status'] ?? 'unpaid') === 'paid') {
            $paid_total += $amt;
        } else {
            $pending_total += $amt;
        }
    }

    if ($has_payments) {
        $pay_extra_cols = tf_column_exists($conn, 'payments', 'transaction_ref')
            ? ', p.transaction_ref, p.card_last4, p.channel_detail'
            : '';
        $pq = "
            SELECT p.payment_id, p.amount, p.method, p.status, p.paid_at, p.booking_id
            {$pay_extra_cols}
            FROM payments p
            JOIN bookings b ON b.booking_id = p.booking_id
            WHERE b.student_id IN ($ph)
            ORDER BY p.paid_at DESC
            LIMIT 50
        ";
        $ps = $conn->prepare($pq);
        $ps->bind_param($types, ...$student_ids);
        $ps->execute();
        $payments = tf_stmt_all_assoc($ps);
        $ps->close();
    }
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $role ?>-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="<?= $role ?>-dashboard.php">Dashboard</a>
      <a href="payment.php" class="active">Payments</a>
      <a href="dispute.php">Disputes</a>
      <a href="actions/logout.php?role=<?= urlencode($role) ?>">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:980px;">
    <div class="page-header">
      <h2>Payment <span>Center</span></h2>
      <p>Pay confirmed lessons and view payment history</p>
    </div>
    <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="stats-row" style="margin-bottom:1rem;">
      <div class="glass stat-card"><div class="num">RM <?= number_format($pending_total, 2) ?></div><div class="lbl">Pending (lesson subtotal)</div></div>
      <div class="glass stat-card"><div class="num">RM <?= number_format($paid_total, 2) ?></div><div class="lbl">Booked lesson value paid</div></div>
      <div class="glass stat-card"><div class="num"><?= count($payments) ?></div><div class="lbl">Transactions</div></div>
    </div>

    <div class="glass section-card" style="margin-bottom:1rem;">
      <h3>💳 <span>Pending Payments</span></h3>
      <?php if (empty($bookings)): ?>
        <p style="opacity:0.6;">No confirmed/completed lessons available for payment.</p>
      <?php else: ?>
      <table class="lesson-table">
        <tr><th>Booking</th><th>Student</th><th>Tutor</th><th>Date</th><th>Amount</th><th>Status</th><th>Action</th></tr>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td>#<?= (int)$b['booking_id'] ?> · <?= htmlspecialchars($b['subject']) ?></td>
            <td><?= htmlspecialchars($b['student_name']) ?></td>
            <td><?= htmlspecialchars($b['tutor_name']) ?></td>
            <td><?= date('d M Y', strtotime($b['lesson_date'])) ?></td>
            <td>RM <?= number_format($b['total_amount'], 2) ?></td>
            <td><?= ucfirst($b['payment_status'] ?? 'unpaid') ?></td>
            <td>
              <?php if (($b['payment_status'] ?? 'unpaid') === 'paid'): ?>
                <span style="opacity:0.7;">Paid</span>
              <?php elseif (!$has_payments): ?>
                <span style="opacity:0.7;">Run migration</span>
              <?php else: ?>
                <a class="btn btn-primary btn-sm" href="payment-checkout.php?booking_id=<?= (int)$b['booking_id'] ?>">Pay securely</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>

    <div class="glass section-card">
      <h3>🧾 <span>Payment History</span></h3>
      <?php if (!$has_payments): ?>
        <p style="opacity:0.6;">Payments table missing. Run database_migration.sql first.</p>
      <?php elseif (empty($payments)): ?>
        <p style="opacity:0.6;">No payment records yet.</p>
      <?php else: ?>
      <?php $show_pay_receipt = tf_column_exists($conn, 'payments', 'transaction_ref'); ?>
      <table class="lesson-table">
        <tr>
          <th>Payment ID</th><th>Booking</th><th>Amount</th><th>Method</th>
          <?php if ($show_pay_receipt): ?><th>Receipt</th><?php endif; ?>
          <th>Status</th><th>Date</th>
        </tr>
        <?php foreach ($payments as $p): ?>
        <tr>
          <td>#<?= (int)$p['payment_id'] ?></td>
          <td>#<?= (int)$p['booking_id'] ?></td>
          <td>RM <?= number_format($p['amount'], 2) ?></td>
          <td><?= strtoupper($p['method']) ?><?php if (!empty($p['channel_detail'])): ?><div style="font-size:0.78rem;opacity:0.7;"><?= htmlspecialchars($p['channel_detail']) ?><?php if (!empty($p['card_last4'])): ?> · •••• <?= htmlspecialchars($p['card_last4']) ?><?php endif; ?></div><?php endif; ?></td>
          <?php if ($show_pay_receipt): ?>
          <td style="font-size:0.82rem;"><?= !empty($p['transaction_ref']) ? htmlspecialchars($p['transaction_ref']) : '—' ?></td>
          <?php endif; ?>
          <td><?= ucfirst($p['status']) ?></td>
          <td><?= date('d M Y g:i A', strtotime($p['paid_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php endif; ?>
    </div>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>
</body>
</html>
