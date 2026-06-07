<?php
require_once __DIR__ . "/config/session.php";
tf_session_start_any(['student', 'parent']);
require_once __DIR__ . "/config/db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student', 'parent'], true)) {
    header("Location: login.html");
    exit();
}

$booking_id = (int) ($_GET['booking_id'] ?? 0);
if ($booking_id < 1) {
    header("Location: payment.php?error=" . urlencode("Invalid checkout link."));
    exit();
}

$uid = (int) $_SESSION['user_id'];
$has_parent_links = tf_table_exists($conn, 'parent_students');

$q = $conn->prepare("
    SELECT b.booking_id, b.subject, b.lesson_date, b.lesson_time, b.total_amount, b.payment_status, b.status,
           CONCAT(u.first_name,' ',u.last_name) AS tutor_name,
           CONCAT(su.first_name,' ',su.last_name) AS student_name,
           b.student_id
    FROM bookings b
    JOIN tutor_profiles tp ON tp.tutor_id = b.tutor_id
    JOIN users u ON u.user_id = tp.user_id
    JOIN users su ON su.user_id = b.student_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$q->bind_param("i", $booking_id);
$q->execute();
$b = tf_stmt_one_assoc($q);
$q->close();

if (!$b) {
    header("Location: payment.php?error=" . urlencode("Booking not found."));
    exit();
}

$allowed = false;
if ($role === 'student' && $uid === (int) $b['student_id']) {
    $allowed = true;
} elseif ($role === 'parent' && $has_parent_links) {
    $ps = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $sid = (int) $b['student_id'];
    $ps->bind_param("ii", $uid, $sid);
    $ps->execute();
    $ps->store_result();
    $allowed = $ps->num_rows === 1;
    $ps->close();
}

if (!$allowed) {
    header("Location: payment.php?error=" . urlencode("You cannot pay for this booking."));
    exit();
}

if (!in_array($b['status'], ['confirmed', 'completed'], true)) {
    header("Location: payment.php?error=" . urlencode("This booking is not payable yet."));
    exit();
}

if (($b['payment_status'] ?? 'unpaid') === 'paid') {
    header("Location: payment.php?success=" . urlencode("This lesson is already paid."));
    exit();
}

$amount = (float) $b['total_amount'];
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Secure checkout - TutorFind</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css">
<style>
.checkout-grid { display:grid; grid-template-columns:1fr 1.15fr; gap:1.25rem; align-items:start; }
@media (max-width:900px){ .checkout-grid { grid-template-columns:1fr; } }
.pay-tabs { display:flex; gap:0.35rem; flex-wrap:wrap; margin-bottom:1rem; }
.pay-tab { padding:0.45rem 0.85rem; border-radius:999px; border:1px solid rgba(255,255,255,0.2); background:rgba(0,0,0,0.15); cursor:pointer; font-weight:700; font-size:0.88rem; color:rgba(255,255,255,0.85); }
.pay-tab.active { background:rgba(46,196,182,0.25); border-color:rgba(46,196,182,0.55); color:#fff; }
.pay-panel { display:none; }
.pay-panel.active { display:block; }
.fake-card { background:linear-gradient(135deg,#1a1f2e 0%,#2d3548 100%); border-radius:14px; padding:1.1rem 1.25rem; margin-bottom:1rem; border:1px solid rgba(255,255,255,0.12); max-width:380px; }
.fake-card .chip { width:42px; height:32px; border-radius:6px; background:linear-gradient(135deg,#d4af37,#f5e6a8); margin-bottom:1rem; }
.secure-line { font-size:0.8rem; opacity:0.65; display:flex; align-items:center; gap:0.35rem; margin-top:0.75rem; }
.charge-line { display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.35rem; opacity:0.85; }
.ew-secure-bar { display:flex; align-items:center; gap:0.65rem; padding:0.65rem 0.85rem; border-radius:12px; background:linear-gradient(90deg,rgba(46,196,182,0.18),rgba(0,0,0,0.25)); border:1px solid rgba(46,196,182,0.35); margin-bottom:1rem; font-size:0.86rem; font-weight:700; }
.ew-secure-bar svg { flex-shrink:0; }
.ew-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:0.65rem; margin-bottom:0.75rem; }
@media (min-width:520px){ .ew-grid { grid-template-columns:repeat(4,1fr); } }
.ew-tile { position:relative; border:2px solid rgba(255,255,255,0.12); border-radius:14px; padding:0.75rem 0.5rem; text-align:center; cursor:pointer; background:rgba(0,0,0,0.2); transition:border-color .15s, background .15s, transform .12s; }
.ew-tile:hover { border-color:rgba(46,196,182,0.45); transform:translateY(-1px); }
.ew-tile.selected { border-color:var(--teal); background:rgba(46,196,182,0.12); box-shadow:0 0 0 1px rgba(46,196,182,0.25); }
.ew-logo { width:44px; height:44px; margin:0 auto 0.45rem; border-radius:12px; display:flex; align-items:center; justify-content:center; }
.ew-logo svg { width:32px; height:32px; display:block; }
.ew-name { font-size:0.78rem; font-weight:800; line-height:1.2; opacity:0.92; }
.ew-hint { font-size:0.76rem; opacity:0.6; margin-top:0.5rem; }
.card-brands { display:flex; gap:0.5rem; align-items:center; margin-top:0.5rem; opacity:0.55; flex-wrap:wrap; }
.card-brands span { font-size:0.72rem; letter-spacing:0.06em; }
</style>
</head>
<body>
<div class="bg-layer"></div>
<div class="bg-overlay"></div>

<div class="page active">
  <nav class="navbar">
    <div class="navbar-brand" onclick="window.location.href='<?= $role ?>-dashboard.php'">Tutor<span>Find</span></div>
    <div class="nav-links">
      <a href="payment.php">← Back to payments</a>
      <a href="<?= $role ?>-dashboard.php">Dashboard</a>
      <a href="actions/logout.php?role=<?= urlencode($role) ?>">Log Out</a>
    </div>
  </nav>

  <div class="page-content" style="max-width:1040px;">
    <div class="page-header">
      <h2>Secure <span>Checkout</span></h2>
      <p>Secure checkout (demo gateway). Card numbers are never stored; eWallet opens your selected app flow (simulated).</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form class="checkout-grid" method="POST" action="actions/process-payment.php" id="checkout-form" autocomplete="off">
      <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
      <input type="hidden" name="method" id="pay-method" value="card">
      <input type="hidden" name="expected_total" id="expected-total" value="<?= number_format($amount, 2, '.', '') ?>">

      <div class="glass section-card">
        <h3>🧾 <span>Order summary</span></h3>
        <p style="opacity:0.85;margin:0.25rem 0;"><strong><?= htmlspecialchars($b['subject']) ?></strong></p>
        <p style="opacity:0.75;font-size:0.9rem;margin:0;">Booking #<?= (int) $b['booking_id'] ?> · <?= date('d M Y', strtotime($b['lesson_date'])) ?> <?= date('g:i A', strtotime($b['lesson_time'])) ?></p>
        <p style="opacity:0.75;font-size:0.9rem;margin:0.35rem 0;">Student: <?= htmlspecialchars($b['student_name']) ?></p>
        <p style="opacity:0.75;font-size:0.9rem;margin:0;">Tutor: <?= htmlspecialchars($b['tutor_name']) ?></p>
        <hr class="divider" style="margin:1rem 0;">
        <div class="charge-line"><span>Lesson subtotal</span><span id="subtotal-display">RM <?= number_format($amount, 2) ?></span></div>
        <div class="charge-line"><span>Payment fee</span><span id="fee-display">RM 0.00</span></div>
        <div class="charge-line"><span>Method</span><span id="method-display">Card</span></div>
        <div style="display:flex;justify-content:space-between;font-weight:900;font-size:1.2rem;">
          <span>Charged total</span>
          <span style="color:var(--teal);" id="total-display">RM <?= number_format($amount, 2) ?></span>
        </div>
        <div class="secure-line">🔒 TLS-encrypted checkout (simulation) · TutorFind Pay</div>
      </div>

      <div class="glass section-card">
        <h3>💳 <span>Payment method</span></h3>
        <div class="pay-tabs" role="tablist">
          <button type="button" class="pay-tab active" data-method="card">Debit / Credit card</button>
          <button type="button" class="pay-tab" data-method="fpx">FPX online banking</button>
          <button type="button" class="pay-tab" data-method="ewallet">eWallet</button>
        </div>

        <div class="pay-panel active" id="panel-card">
          <div class="fake-card">
            <div class="chip"></div>
            <div style="font-size:0.75rem;opacity:0.6;letter-spacing:0.12em;">TUTORFIND PAY</div>
          </div>
          <div class="form-group">
            <label>Name on card</label>
            <input type="text" name="card_name" id="card_name" placeholder="As shown on card" maxlength="80">
          </div>
          <div class="form-group">
            <label>Card number</label>
            <input type="text" name="card_number" id="card_number" placeholder="4242 4242 4242 4242" inputmode="numeric" autocomplete="cc-number">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Expiry (MM/YY)</label>
              <input type="text" name="card_expiry" id="card_expiry" placeholder="12/28" maxlength="5" autocomplete="cc-exp">
            </div>
            <div class="form-group">
              <label>CVV</label>
              <input type="password" name="card_cvv" id="card_cvv" placeholder="123" maxlength="4" autocomplete="cc-csc">
            </div>
          </div>
          <p style="font-size:0.78rem;opacity:0.55;margin:0;">Full card number and CVV are verified then discarded. Only last 4 digits are saved with your receipt.</p>
          <div class="card-brands" aria-hidden="true">
            <span>VISA</span><span>MASTERCARD</span><span>MYDEBIT</span>
          </div>
        </div>

        <div class="pay-panel" id="panel-fpx">
          <div class="form-group">
            <label>Bank</label>
            <select name="fpx_bank" id="fpx_bank">
              <option value="">Select your bank</option>
              <option>Maybank2u</option>
              <option>CIMB Clicks</option>
              <option>Public Bank</option>
              <option>RHB Now</option>
              <option>Hong Leong Connect</option>
              <option>Bank Islam</option>
              <option>AmOnline</option>
            </select>
          </div>
          <p style="font-size:0.78rem;opacity:0.55;margin:0;">You will be redirected to your bank’s login (simulated — no real bank connection).</p>
        </div>

        <div class="pay-panel" id="panel-ewallet">
          <div class="ew-secure-bar" role="status">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
              <circle cx="12" cy="16" r="1.5" fill="currentColor"/>
            </svg>
            <span>Secured checkout — PIN / biometric may be required in your wallet app</span>
          </div>
          <p style="font-size:0.85rem;opacity:0.88;margin:0 0 0.65rem;font-weight:700;">Choose your eWallet</p>
          <input type="hidden" name="ewallet_provider" id="ewallet_provider" value="">
          <div class="ew-grid" id="ewallet-tiles" role="radiogroup" aria-label="eWallet provider">
            <label class="ew-tile" data-wallet="Touch n Go eWallet" tabindex="0" role="radio" aria-checked="false">
              <div class="ew-logo" style="background:linear-gradient(145deg,#003d82,#005eb8);">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="15" font-weight="900" font-family="system-ui,sans-serif">TnG</text></svg>
              </div>
              <div class="ew-name">Touch ‘n Go<br>eWallet</div>
            </label>
            <label class="ew-tile" data-wallet="GrabPay" tabindex="0" role="radio" aria-checked="false">
              <div class="ew-logo" style="background:#00b14f;">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="16" font-weight="900" font-family="system-ui,sans-serif">G</text></svg>
              </div>
              <div class="ew-name">GrabPay</div>
            </label>
            <label class="ew-tile" data-wallet="ShopeePay" tabindex="0" role="radio" aria-checked="false">
              <div class="ew-logo" style="background:#ee4d2d;">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="15" font-weight="900" font-family="system-ui,sans-serif">S</text></svg>
              </div>
              <div class="ew-name">ShopeePay</div>
            </label>
            <label class="ew-tile" data-wallet="Boost" tabindex="0" role="radio" aria-checked="false">
              <div class="ew-logo" style="background:#00c5b0;">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="15" font-weight="900" font-family="system-ui,sans-serif">B</text></svg>
              </div>
              <div class="ew-name">Boost</div>
            </label>
          </div>
          <p class="ew-hint">Tap a wallet to select. You will confirm the amount in your app (simulated — no real charge).</p>
        </div>

        <button type="submit" class="btn btn-primary" id="pay-btn" style="width:100%;justify-content:center;margin-top:1rem;font-size:1rem;">
          Pay RM <?= number_format($amount, 2) ?>
        </button>
      </div>
    </form>
  </div>
  <footer><strong>TutorFind</strong> — Multimedia University Malaysia © 2026</footer>
</div>

<script>
(function(){
  const baseAmount = <?= json_encode((float) $amount) ?>;
  const methodInput = document.getElementById('pay-method');
  const tabs = document.querySelectorAll('.pay-tab');
  const panels = { card: document.getElementById('panel-card'), fpx: document.getElementById('panel-fpx'), ewallet: document.getElementById('panel-ewallet') };
  const subtotalEl = document.getElementById('subtotal-display');
  const feeEl = document.getElementById('fee-display');
  const totalEl = document.getElementById('total-display');
  const methodEl = document.getElementById('method-display');
  const expectedTotalEl = document.getElementById('expected-total');
  const payBtn = document.getElementById('pay-btn');

  function round2(v) {
    return Math.round((v + Number.EPSILON) * 100) / 100;
  }

  function feeFor(method) {
    if (method === 'card') return round2(Math.max(0.5, baseAmount * 0.018));
    if (method === 'fpx') return round2(0.5);
    return round2(Math.max(0.3, baseAmount * 0.01));
  }

  function refreshSummary() {
    const m = methodInput.value;
    const fee = feeFor(m);
    const total = round2(baseAmount + fee);
    const labels = { card: 'Card', fpx: 'FPX', ewallet: 'eWallet' };
    subtotalEl.textContent = 'RM ' + baseAmount.toFixed(2);
    feeEl.textContent = 'RM ' + fee.toFixed(2);
    totalEl.textContent = 'RM ' + total.toFixed(2);
    methodEl.textContent = labels[m] || 'Card';
    expectedTotalEl.value = total.toFixed(2);
    payBtn.textContent = 'Pay RM ' + total.toFixed(2);
  }

  function setMethod(m) {
    methodInput.value = m;
    tabs.forEach(t => t.classList.toggle('active', t.dataset.method === m));
    Object.keys(panels).forEach(k => panels[k].classList.toggle('active', k === m));
    refreshSummary();
  }
  tabs.forEach(t => t.addEventListener('click', () => setMethod(t.dataset.method)));

  const ewHidden = document.getElementById('ewallet_provider');
  const ewTiles = document.querySelectorAll('#ewallet-tiles .ew-tile');
  function selectWallet(val) {
    ewHidden.value = val || '';
    ewTiles.forEach(t => {
      const on = t.getAttribute('data-wallet') === val;
      t.classList.toggle('selected', on);
      t.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }
  ewTiles.forEach(tile => {
    tile.addEventListener('click', function(e) {
      e.preventDefault();
      selectWallet(tile.getAttribute('data-wallet'));
    });
    tile.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        selectWallet(tile.getAttribute('data-wallet'));
      }
    });
  });

  const cn = document.getElementById('card_number');
  if (cn) {
    cn.addEventListener('input', function() {
      let v = this.value.replace(/\D/g, '').slice(0, 19);
      this.value = v.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
    });
  }

  document.getElementById('checkout-form').addEventListener('submit', function(e) {
    const m = methodInput.value;
    if (m === 'card') {
      const name = document.getElementById('card_name').value.trim();
      const num = document.getElementById('card_number').value.replace(/\s+/g, '');
      const exp = document.getElementById('card_expiry').value.trim();
      const cvv = document.getElementById('card_cvv').value.trim();
      if (name.length < 2) { e.preventDefault(); alert('Enter the name on card.'); return; }
      if (num.length < 13) { e.preventDefault(); alert('Enter a valid card number.'); return; }
      if (!/^\d{2}\/\d{2}$/.test(exp)) { e.preventDefault(); alert('Expiry must be MM/YY.'); return; }
      if (!/^\d{3,4}$/.test(cvv)) { e.preventDefault(); alert('Enter CVV (3 or 4 digits).'); return; }
    }
    if (m === 'fpx' && !document.getElementById('fpx_bank').value) {
      e.preventDefault(); alert('Select your bank.'); return;
    }
    if (m === 'ewallet' && !document.getElementById('ewallet_provider').value.trim()) {
      e.preventDefault(); alert('Select an eWallet.'); return;
    }
    payBtn.disabled = true;
    payBtn.textContent = 'Processing...';
  });
  refreshSummary();
})();
</script>
</body>
</html>
