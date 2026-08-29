<?php
declare(strict_types=1);

// Shared bootstrap loads /dollar/.env before database settings are read.
require_once __DIR__ . '/config.php';

$dsn = getenv('DB_DSN');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

if ($dsn === false || $user === false) {
    http_response_code(500);
    error_log('Dollar Topup: DB_DSN or DB_USER is missing from .env');
    exit('Database configuration missing.');
}

try {
    return new PDO(
        $dsn,
        $user,
        $pass === false ? '' : $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Dollar Topup DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Please check configuration.');
}