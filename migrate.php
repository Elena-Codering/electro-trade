<?php
require_once __DIR__ . '/config/db.php';

$dir = __DIR__ . '/migrations';
if (!is_dir($dir)) {
    echo "Migration directory not found: $dir\n";
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$files = glob($dir . '/*.sql');
sort($files, SORT_NATURAL);

foreach ($files as $path) {
    $filename = basename($path);

    $stmt = $pdo->prepare('SELECT 1 FROM migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    if ($stmt->fetch()) {
        echo "Skipping already applied migration: $filename\n";
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        echo "Failed to read migration file: $filename\n";
        continue;
    }

    echo "Applying migration: $filename\n";
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$filename]);
}

echo "Migration run complete.\n";
