<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');

// ── Check / auto-add email column ─────────────────────────────────────────────
$emailColumnExists = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
    if (!empty($col)) {
        $emailColumnExists = true;
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username");
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
        if (!empty($col)) $emailColumnExists = true;
    }
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "emailColumnExists = " . var_export($emailColumnExists, true) . "\n\n";

// Simulate the data query
$usrWhere = officeScopeSQL('u', '');
echo "usrWhere = " . var_export($usrWhere, true) . "\n\n";

$query = "SELECT u.*, o.office_name FROM users u LEFT JOIN offices o ON u.office_id = o.office_id {$usrWhere} ORDER BY u.user_id DESC";
echo "Query:\n$query\n\n";

$users = $pdo->query($query)->fetchAll();
echo "Rows returned: " . count($users) . "\n\n";
echo "First few users:\n";
foreach (array_slice($users, 0, 3) as $u) {
    echo "ID:" . $u['user_id'] . " | Username: " . $u['username'] . " | Email: " . ($u['email'] ?? '[NULL]') . " | Keys: " . implode(',', array_keys($u)) . "\n";
}
?>
