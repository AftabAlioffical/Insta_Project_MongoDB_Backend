<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;

$legacyMySqlHost = getenv('LEGACY_MYSQL_HOST') ?: '127.0.0.1';
$legacyMySqlPort = intval(getenv('LEGACY_MYSQL_PORT') ?: '3306');
$legacyMySqlName = getenv('LEGACY_MYSQL_DB') ?: 'insta_app';
$legacyMySqlUser = getenv('LEGACY_MYSQL_USER') ?: 'root';
$legacyMySqlPass = getenv('LEGACY_MYSQL_PASS') ?: '';

$mysqlDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $legacyMySqlHost, $legacyMySqlPort, $legacyMySqlName);

try {
    $pdo = new PDO($mysqlDsn, $legacyMySqlUser, $legacyMySqlPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $mongo = new Manager(MONGO_URI);
} catch (Throwable $e) {
    fwrite(STDERR, "Connection error: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$tables = [
    'users',
    'media',
    'comments',
    'likes',
    'ratings',
    'person_tags'
];

foreach ($tables as $table) {
    echo "Migrating {$table}..." . PHP_EOL;

    // Clear collection before import so reruns don't create duplicates.
    $dropCommand = new Command([
        'delete' => $table,
        'deletes' => [
            ['q' => (object) [], 'limit' => 0]
        ]
    ]);
    $mongo->executeCommand(MONGO_DB_NAME, $dropCommand);

    $rows = $pdo->query("SELECT * FROM {$table}")->fetchAll();

    if (empty($rows)) {
        echo "  - No records found" . PHP_EOL;
        continue;
    }

    $bulk = new BulkWrite();
    foreach ($rows as $row) {
        if ($table === 'users' && isset($row['email'])) {
            $seedPasswords = [
                'admin@insta.local' => 'admin123',
                'creator1@insta.local' => 'creator123',
                'creator2@insta.local' => 'creator123',
                'consumer1@insta.local' => 'consumer123',
                'consumer2@insta.local' => 'consumer123'
            ];

            if (isset($seedPasswords[$row['email']])) {
                $row['password_hash'] = password_hash($seedPasswords[$row['email']], PASSWORD_DEFAULT);
            }
        }

        $bulk->insert($row);
    }

    $namespace = MONGO_DB_NAME . '.' . $table;
    $mongo->executeBulkWrite($namespace, $bulk);
    echo "  - Inserted " . count($rows) . " documents" . PHP_EOL;
}

echo "MySQL to MongoDB migration completed." . PHP_EOL;