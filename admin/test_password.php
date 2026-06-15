<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Ensure admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Admin access required");
}

echo "<h2>Password Field Diagnostic</h2>";
echo "<pre>";

try {
    // Check password column
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
    echo "Password Column Info:\n";
    echo json_encode($col, JSON_PRETTY_PRINT) . "\n\n";
    
    // Check if field is VARCHAR and its size
    if ($col) {
        $type = strtoupper($col['Type']);
        echo "Current Type: {$type}\n";
        
        if (strpos($type, 'VARCHAR(60)') !== false) {
            echo "⚠️  WARNING: Field is VARCHAR(60), which is TOO SMALL for bcrypt hashes!\n";
            echo "Expanding to VARCHAR(255)...\n";
            $pdo->exec("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL");
            echo "✓ Expanded to VARCHAR(255)\n\n";
        } elseif (strpos($type, 'VARCHAR(255)') !== false) {
            echo "✓ Field is VARCHAR(255) - correct size\n\n";
        } else {
            echo "Field type is: {$type}\n\n";
        }
    }
    
    // Show all users and their password field lengths
    echo "User Password Hashes:\n";
    $users = $pdo->query("SELECT user_id, username, LENGTH(password) as hash_length, password FROM users")->fetchAll();
    foreach ($users as $u) {
        echo "ID: {$u['user_id']} | Username: {$u['username']} | Hash Length: {$u['hash_length']} | Hash: " . substr($u['password'], 0, 20) . "...\n";
    }
    
    echo "\n\nPassword Hash Verification Test:\n";
    echo "Testing password_hash and password_verify:\n";
    $test_pass = "TestPassword123!";
    $test_hash = password_hash($test_pass, PASSWORD_DEFAULT);
    echo "Original password: {$test_pass}\n";
    echo "Generated hash: {$test_hash}\n";
    echo "Hash length: " . strlen($test_hash) . "\n";
    echo "Verify result: " . (password_verify($test_pass, $test_hash) ? "✓ PASS" : "✗ FAIL") . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
