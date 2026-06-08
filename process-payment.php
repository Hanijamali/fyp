<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent']);
require_once __DIR__ . "/../config/db.php";

/**
 * Luhn check for card numbers (demo gateway validation).
 */
function tf_luhn_valid(string $number): bool
{
    $number = preg_replace('/\D/', '', $number);
    $len = strlen($number);
    if ($len < 13 || $len > 19) {
        return false;
    }
    $number = strrev($number);
    $sum = 0;
    for ($i = 0; $i < $len; $i++) {
        $digit = (int) $number[$i];
        if ($i % 2 === 1) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    return ($sum % 10) === 0;
}

/**
 * Parse MM/YY; returns [month, year] integers or null.
 */
function tf_parse_card_expiry(string $raw): ?array
{
    $raw = trim($raw);
    if (!preg_match('/^(\d{2})\/(\d{2})$/', $raw, $m)) {
        return null;
    }
    $month = (int) $m[1];
    $yy = (int) $m[2];
    if ($month < 1 || $month > 12) {
        return null;
    }
    $year = 2000 + $yy;
    return [$month, $year];
}

/**
 * Compute fee for selected method.
 */
function tf_payment_fee(float $base, string $method): float
{
    if ($method === 'card') {
        return round(max(0.5, $base * 0.018), 2);
    }
    if ($method === 'fpx') {
        return 0.50;
    }
    return round(max(0.3, $base * 0.01), 2);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../payment.php");
    exit();
}
if (!tf_table_exists($conn, 'payments')) {
    header("Location: ../payment.php?error=" . urlencode("Missing payments table. Run database_migration.sql."));
    exit();
}

$booking_id = (int) ($_POST['booking_id'] ?? 0);
$method = $_POST['method'] ?? 'card';
$expected_total = (float) ($_POST['expected_total'] ?? 0);
$uid = (int) $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($booking_id < 1 || !in_array($method, ['card', 'fpx', 'ewallet'], true)) {
    header("Location: ../payment.php?error=" . urlencode("Invalid payment request."));
    exit();
}

$q = $conn->prepare("
    SELECT b.booking_id, b.student_id, b.total_amount, b.payment_status, b.status
    FROM bookings b
    WHERE b.booking_id = ?
    LIMIT 1
");
$q->bind_param("i", $booking_id);
$q->execute();
$booking = tf_stmt_one_assoc($q);
$q->close();

if (!$booking) {
    header("Location: ../payment.php?error=" . urlencode("Booking not found."));
    exit();
}

$allowed = false;
if ($role === 'student' && $uid === (int) $booking['student_id']) {
    $allowed = true;
} elseif ($role === 'parent' && tf_table_exists($conn, 'parent_students')) {
    $ps = $conn->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
    $sid = (int) $booking['student_id'];
    $ps->bind_param("ii", $uid, $sid);
    $ps->execute();
    $ps->store_result();
    $allowed = $ps->num_rows === 1;
    $ps->close();
}

if (!$allowed) {
    header("Location: ../payment.php?error=" . urlencode("You cannot pay for this booking."));
    exit();
}

$card_last4 = null;
$channel_detail = null;

if ($method === 'card') {
    $name = trim((string) ($_POST['card_name'] ?? ''));
    $pan = preg_replace('/\D/', '', (string) ($_POST['card_number'] ?? ''));
    $exp_raw = trim((string) ($_POST['card_expiry'] ?? ''));
    $cvv = preg_replace('/\D/', '', (string) ($_POST['card_cvv'] ?? ''));

    if (strlen($name) < 2) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Enter the name on card."));
        exit();
    }
    if (!tf_luhn_valid($pan)) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Card number failed validation. Check digits and try again."));
        exit();
    }
    $exp = tf_parse_card_expiry($exp_raw);
    if (!$exp) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Expiry must be MM/YY."));
        exit();
    }
    [$em, $ey] = $exp;
    $nowY = (int) date('Y');
    $nowM = (int) date('n');
    if ($ey < $nowY || ($ey === $nowY && $em < $nowM)) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Card appears expired."));
        exit();
    }
    if (strlen($cvv) < 3 || strlen($cvv) > 4) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Enter a valid CVV."));
        exit();
    }
    $card_last4 = substr($pan, -4);
    $first = $pan[0] ?? '0';
    $brand = ($first === '4') ? 'Visa' : (($first === '5') ? 'Mastercard' : 'Card');
    $channel_detail = $brand;
} elseif ($method === 'fpx') {
    $bank = trim((string) ($_POST['fpx_bank'] ?? ''));
    $allowed_banks = ['Maybank2u', 'CIMB Clicks', 'Public Bank', 'RHB Now', 'Hong Leong Connect', 'Bank Islam', 'AmOnline'];
    if (!in_array($bank, $allowed_banks, true)) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Select a valid bank."));
        exit();
    }
    $channel_detail = $bank;
} else {
    $wallet = trim((string) ($_POST['ewallet_provider'] ?? ''));
    $allowed_wallets = ['Touch n Go eWallet', 'GrabPay', 'ShopeePay', 'Boost'];
    if (!in_array($wallet, $allowed_wallets, true)) {
        header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode("Select a valid eWallet."));
        exit();
    }
    $channel_detail = $wallet;
}

$transaction_ref = 'TF-' . strtoupper(bin2hex(random_bytes(8)));
$has_pay_meta = tf_column_exists($conn, 'payments', 'transaction_ref');
$charged_total = 0.0;

mysqli_begin_transaction($conn);
try {
    $lock = $conn->prepare("
        SELECT booking_id, total_amount, payment_status, status
        FROM bookings
        WHERE booking_id = ?
        FOR UPDATE
    ");
    $lock->bind_param("i", $booking_id);
    $lock->execute();
    $locked_booking = tf_stmt_one_assoc($lock);
    $lock->close();

    if (!$locked_booking) {
        throw new RuntimeException("Booking not found.");
    }
    if (!in_array($locked_booking['status'], ['confirmed', 'completed'], true)) {
        throw new RuntimeException("Only confirmed/completed bookings can be paid.");
    }
    if (($locked_booking['payment_status'] ?? 'unpaid') === 'paid') {
        throw new RuntimeException("Booking already paid.");
    }

    $already_paid = $conn->prepare("SELECT 1 FROM payments WHERE booking_id = ? AND status = 'paid' LIMIT 1");
    $already_paid->bind_param("i", $booking_id);
    $already_paid->execute();
    $already_paid->store_result();
    $has_paid_row = $already_paid->num_rows > 0;
    $already_paid->close();
    if ($has_paid_row) {
        throw new RuntimeException("Payment already exists for this booking.");
    }

    $amount = (float) $locked_booking['total_amount'];
    if ($amount <= 0) {
        throw new RuntimeException("Invalid booking amount.");
    }
    $fee = tf_payment_fee($amount, $method);
    $charged_total = round($amount + $fee, 2);
    if ($expected_total > 0 && abs($charged_total - $expected_total) > 0.01) {
        throw new RuntimeException("Payment total changed. Please review checkout and try again.");
    }

    if ($has_pay_meta) {
        $channel_detail_with_fee = trim(($channel_detail ?? $method) . ' | fee RM ' . number_format($fee, 2) . ' on RM ' . number_format($amount, 2));
        $ins = $conn->prepare("
            INSERT INTO payments (booking_id, paid_by, amount, method, status, transaction_ref, card_last4, channel_detail)
            VALUES (?, ?, ?, ?, 'paid', ?, ?, ?)
        ");
        $ins->bind_param("iidssss", $booking_id, $uid, $charged_total, $method, $transaction_ref, $card_last4, $channel_detail_with_fee);
    } else {
        $ins = $conn->prepare("INSERT INTO payments (booking_id, paid_by, amount, method, status) VALUES (?, ?, ?, ?, 'paid')");
        $ins->bind_param("iids", $booking_id, $uid, $charged_total, $method);
    }
    $ok = $ins->execute();
    $ins->close();
    if (!$ok) {
        throw new RuntimeException("Payment failed. Try again.");
    }

    $up = $conn->prepare("UPDATE bookings SET payment_status = 'paid' WHERE booking_id = ?");
    $up->bind_param("i", $booking_id);
    $ok2 = $up->execute();
    $up->close();
    if (!$ok2) {
        throw new RuntimeException("Could not update booking payment status.");
    }

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    header("Location: ../payment-checkout.php?booking_id=" . $booking_id . "&error=" . urlencode($e->getMessage()));
    exit();
}

$msg = $has_pay_meta
    ? ("Payment successful. Charged RM " . number_format($charged_total, 2) . ". Receipt: " . $transaction_ref . ".")
    : "Payment successful.";
header("Location: ../payment.php?success=" . urlencode($msg));
exit();
