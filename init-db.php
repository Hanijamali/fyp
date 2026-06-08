<?php
/**
 * On first boot, import schema + demo accounts so testers can log in immediately.
 */
$host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'db';
$user = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
$name = getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'tutorfind_db';
$port = (int) (getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306);

$maxAttempts = 30;
$conn = null;

for ($i = 0; $i < $maxAttempts; $i++) {
    $conn = @mysqli_connect($host, $user, $pass, $name, $port);
    if ($conn) {
        break;
    }
    sleep(2);
}

if (!$conn) {
    fwrite(STDERR, "Database not ready: " . mysqli_connect_error() . PHP_EOL);
    exit(1);
}

$result = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if ($result && mysqli_num_rows($result) > 0) {
    mysqli_close($conn);
    exit(0);
}

$files = [
    __DIR__ . '/../database_setup.sql',
    __DIR__ . '/../database_test_data.sql',
];

foreach ($files as $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Missing SQL file: {$file}" . PHP_EOL);
        exit(1);
    }

    $sql = file_get_contents($file);
    if ($sql === false || $sql === '') {
        continue;
    }

    if (!mysqli_multi_query($conn, $sql)) {
        fwrite(STDERR, "SQL import failed ({$file}): " . mysqli_error($conn) . PHP_EOL);
        exit(1);
    }

    while (mysqli_more_results($conn)) {
        mysqli_next_result($conn);
    }
}

mysqli_close($conn);
fwrite(STDOUT, "Database initialized with demo accounts." . PHP_EOL);
