<?php
// TEMPORARY diagnostic — upload, visit once in browser, then DELETE this file.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php';

echo "Trying to connect...\n";
echo 'Host: ' . ET_DB_HOST . "\n";
echo 'Port: ' . ET_DB_PORT . "\n";
echo 'Database: ' . ET_DB_NAME . "\n";
echo 'User: ' . ET_DB_USER . "\n\n";

$dsn = 'mysql:host=' . ET_DB_HOST . ';port=' . ET_DB_PORT . ';dbname=' . ET_DB_NAME . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, ET_DB_USER, ET_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "SUCCESS — connected to MySQL.\n";
    echo 'Server version: ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
    echo "\nNow DELETE this file (dbtest.php) from the server.\n";
} catch (PDOException $e) {
    echo "FAILED — exact MySQL error:\n\n";
    echo $e->getMessage() . "\n";
}
