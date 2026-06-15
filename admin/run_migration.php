<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
    if (!empty($col)) {
        echo "email column already exists.\n";
        echo json_encode($col) . "\n";
        exit(0);
    }
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username");
    echo "ALTER executed. Re-checking...\n";
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
    if (!empty($col)) {
        echo "email column now exists.\n";
        echo json_encode($col) . "\n";
        exit(0);
    } else {
        echo "ALTER executed but column still missing.\n";
        exit(2);
    }
} catch (PDOException $e) {
    echo "PDOException: " . $e->getMessage() . "\n";
    exit(3);
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    exit(4);
}
