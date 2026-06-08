<?php
require_once __DIR__ . "/../config/session.php";
tf_session_start_any(['student', 'parent', 'tutor', 'admin']);
require_once __DIR__ . "/../config/db.php";

/** Max longest side for stored profile images (pixels). */
define('TF_PROFILE_MAX_DIM', 288);

/**
 * Scale image so longest side <= TF_PROFILE_MAX_DIM, write as JPEG (quality 84).
 *
 * @param resource $im
 */
function tf_profile_write_jpeg_normalized($im, string $destFull): bool
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 1 || $h < 1) {
        return false;
    }
    $max = (int) TF_PROFILE_MAX_DIM;
    $tw = $w;
    $th = $h;
    if ($w > $max || $h > $max) {
        $scale = min($max / $w, $max / $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
    }
    $dst = imagecreatetruecolor($tw, $th);
    if (!$dst) {
        return false;
    }
    imagealphablending($dst, false);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($dst, $destFull, 84);
    imagedestroy($dst);
    return $ok;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../my-profile.php");
    exit();
}

if (!tf_column_exists($conn, 'users', 'profile_picture')) {
    header("Location: ../my-profile.php?error=" . urlencode("Run database_migration.sql to enable profile pictures."));
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$file = $_FILES['profile_photo'] ?? null;

if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    header("Location: ../my-profile.php?error=" . urlencode("Choose an image file first."));
    exit();
}
if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) < 1) {
    header("Location: ../my-profile.php?error=" . urlencode("Upload failed. Try a smaller image."));
    exit();
}

$max = 2 * 1024 * 1024;
if ($file['size'] > $max) {
    header("Location: ../my-profile.php?error=" . urlencode("Image must be 2 MB or smaller."));
    exit();
}

$tmp = $file['tmp_name'];
$info = @getimagesize($tmp);
if ($info === false) {
    header("Location: ../my-profile.php?error=" . urlencode("File is not a valid image."));
    exit();
}

$mime = $info['mime'] ?? '';
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mime, $allowed, true)) {
    header("Location: ../my-profile.php?error=" . urlencode("Use JPG, PNG, WebP, or GIF only."));
    exit();
}

$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
$srcExt = $mimeToExt[$mime];

$dir = __DIR__ . "/../uploads/profiles";
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    header("Location: ../my-profile.php?error=" . urlencode("Could not create upload folder."));
    exit();
}

$gd = extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg');

$basename = 'u' . $user_id . '_' . bin2hex(random_bytes(8)) . '.jpg';
$full = $dir . DIRECTORY_SEPARATOR . $basename;
$relative = 'uploads/profiles/' . $basename;

$ok = false;
$successMsg = 'Profile picture updated — resized for a smaller file and faster loading.';

if ($gd) {
    $blob = @file_get_contents($tmp);
    if ($blob !== false) {
        $im = @imagecreatefromstring($blob);
        if ($im) {
            $ok = tf_profile_write_jpeg_normalized($im, $full);
            imagedestroy($im);
        }
    }
}

if (!$ok) {
    $maxNoGdBytes = 512 * 1024;
    $maxNoGdPx = 600;
    $iw = (int) ($info[0] ?? 0);
    $ih = (int) ($info[1] ?? 0);
    if ($file['size'] > $maxNoGdBytes || $iw < 1 || $ih < 1 || $iw > $maxNoGdPx || $ih > $maxNoGdPx) {
        if ($gd) {
            header("Location: ../my-profile.php?error=" . urlencode("Could not process this image with GD. Use JPG or PNG under 600×600 and 512 KB, or fix php.ini GD/WebP support."));
        } else {
            header("Location: ../my-profile.php?error=" . urlencode("Without PHP GD: use an image at most 600×600 pixels and under 512 KB (yours is {$iw}×{$ih}). Or enable extension=gd in php.ini for any size up to 2 MB with auto-resize."));
        }
        exit();
    }
    $basename = 'u' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $srcExt;
    $full = $dir . DIRECTORY_SEPARATOR . $basename;
    $relative = 'uploads/profiles/' . $basename;
    $ok = @move_uploaded_file($tmp, $full);
    if ($gd) {
        $successMsg = 'Profile picture saved (original file — GD could not resize this type). Use JPG for best results, or enable full GD support.';
    } else {
        $successMsg = 'Profile picture saved. Enable PHP GD (php.ini: extension=gd) for automatic resize and larger uploads.';
    }
}

if (!$ok) {
    header("Location: ../my-profile.php?error=" . urlencode("Could not save image."));
    exit();
}

$old = $conn->prepare("SELECT profile_picture FROM users WHERE user_id = ? LIMIT 1");
$old->bind_param("i", $user_id);
$old->execute();
$row = tf_stmt_one_assoc($old);
$old->close();

$prev = tf_profile_picture_url($row['profile_picture'] ?? null);
if ($prev) {
    $prevPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $prev);
    if (is_file($prevPath)) {
        @unlink($prevPath);
    }
}

$up = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
$up->bind_param("si", $relative, $user_id);
if (!$up->execute()) {
    @unlink($full);
    header("Location: ../my-profile.php?error=" . urlencode("Could not update profile."));
    exit();
}
$up->close();

header("Location: ../my-profile.php?success=" . urlencode($successMsg));
exit();
