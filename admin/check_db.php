<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    // Check if email column exists
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
    echo "Email column exists: " . (!empty($col) ? "YES" : "NO") . "\n";
    if (!empty($col)) echo "Details: " . json_encode($col) . "\n\n";
    
    // Fetch all users to see email values
    echo "All users in DB:\n";
    $users = $pdo->query("SELECT user_id, username, email FROM users ORDER BY user_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        echo "ID:" . $u['user_id'] . " | Username: " . $u['username'] . " | Email: " . (empty($u['email']) ? '[NULL/EMPTY]' : $u['email']) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
