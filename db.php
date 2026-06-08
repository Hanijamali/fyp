<?php
// ============================================================
// config/db.php — Database Connection
// ============================================================
$host     = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
$user     = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
$database = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'tutorfind_db';
$port     = (int) (getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);

// Hosting (InfinityFree, etc.): copy config/db.local.php.example → config/db.local.php
if (is_readable(__DIR__ . '/db.local.php')) {
    require __DIR__ . '/db.local.php';
}

$conn = mysqli_connect($host, $user, $password, $database, $port);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

/**
 * Fetch all rows from an executed mysqli_stmt as associative arrays.
 * Works with and without mysqlnd.
 */
function tf_stmt_all_assoc(mysqli_stmt $stmt): array
{
    if (method_exists($stmt, "get_result")) {
        $result = $stmt->get_result();
        if ($result !== false) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return [];
    }

    $row = [];
    $bindRefs = [];
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $bindRefs[] = &$row[$field->name];
    }
    $meta->close();

    call_user_func_array([$stmt, "bind_result"], $bindRefs);

    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }
        $rows[] = $copy;
    }

    return $rows;
}

/**
 * Fetch first row from an executed mysqli_stmt as associative array.
 */
function tf_stmt_one_assoc(mysqli_stmt $stmt): ?array
{
    $rows = tf_stmt_all_assoc($stmt);
    return $rows[0] ?? null;
}

/**
 * Fetch all rows from mysqli_query result as associative arrays.
 */
function tf_query_all_assoc(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Fetch first row from mysqli_query result as associative array.
 */
function tf_query_one_assoc(mysqli $conn, string $sql): ?array
{
    $result = $conn->query($sql);
    if (!$result) {
        return null;
    }
    return $result->fetch_assoc() ?: null;
}

/**
 * Check whether a table exists in the current database.
 */
function tf_table_exists(mysqli $conn, string $table_name): bool
{
    $safe = $conn->real_escape_string($table_name);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $result !== false && $result->num_rows > 0;
}

/**
 * Check whether a column exists in a table.
 */
function tf_column_exists(mysqli $conn, string $table_name, string $column_name): bool
{
    $table = $conn->real_escape_string($table_name);
    $column = $conn->real_escape_string($column_name);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $result !== false && $result->num_rows > 0;
}

/**
 * True when quiz due_date (DATE, last inclusive day) is strictly before today.
 */
function tf_quiz_past_due(?string $due_date): bool
{
    if ($due_date === null || $due_date === '') {
        return false;
    }
    $d = date('Y-m-d', strtotime($due_date));
    if ($d === '1970-01-01') {
        return false;
    }
    return date('Y-m-d') > $d;
}

/**
 * Safe web-relative path for a stored profile picture, or null.
 */
function tf_profile_picture_url(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $stored = str_replace('\\', '/', trim($stored));
    if ($stored === '' || strpos($stored, '..') !== false) {
        return null;
    }
    // Safe basename only (new uploads are .jpg; older installs may have other extensions)
    if (!preg_match('#^uploads/profiles/u\d+_[a-zA-Z0-9]+\.(jpe?g|png|gif|webp)$#i', $stored)) {
        return null;
    }
    return $stored;
}

/**
 * In-app notification (requires notifications table from migration).
 */
function tf_notify_user(mysqli $conn, int $user_id, string $title, string $body = '', ?string $link_url = null): void
{
    if ($user_id < 1 || !tf_table_exists($conn, 'notifications')) {
        return;
    }
    $title = mb_substr($title, 0, 200);
    $link_url = $link_url !== null ? mb_substr($link_url, 0, 500) : null;
    $st = $conn->prepare("INSERT INTO notifications (user_id, title, body, link_url) VALUES (?, ?, ?, ?)");
    $st->bind_param("isss", $user_id, $title, $body, $link_url);
    $st->execute();
    $st->close();
}

function tf_notification_unread_count(mysqli $conn, int $user_id): int
{
    if ($user_id < 1 || !tf_table_exists($conn, 'notifications')) {
        return 0;
    }
    $st = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    $st->bind_param("i", $user_id);
    $st->execute();
    $row = tf_stmt_one_assoc($st);
    $st->close();
    return (int) ($row['c'] ?? 0);
}
?>
