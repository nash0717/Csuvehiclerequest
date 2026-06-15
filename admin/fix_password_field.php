<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

// Ensure admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Admin access required");
}

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Database Password Field Migration</h1>";
echo "<pre style='background:#f5f5f5;padding:15px;border-radius:8px;'>";

try {
    // 1. Check current password column
    echo "STEP 1: Checking current password column...\n";
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
    
    if (!$col) {
        echo "❌ ERROR: Password column does not exist!\n";
        exit;
    }
    
    echo "✓ Password column found\n";
    echo "  Type: {$col['Type']}\n";
    echo "  Null: {$col['Null']}\n";
    echo "  Key: {$col['Key']}\n";
    echo "  Default: {$col['Default']}\n";
    echo "  Extra: {$col['Extra']}\n\n";
    
    // 2. Check if column is too small
    echo "STEP 2: Checking if column size is adequate...\n";
    $colType = strtoupper($col['Type']);
    
    if (stripos($colType, 'VARCHAR(60)') !== false) {
        echo "⚠️  Column is VARCHAR(60) - TOO SMALL for bcrypt hashes!\n";
        echo "    Expanding to VARCHAR(255)...\n";
        
        $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL");
        echo "✓ Column expanded successfully!\n\n";
        
    } elseif (stripos($colType, 'VARCHAR(255)') !== false) {
        echo "✓ Column is VARCHAR(255) - correct size\n\n";
        
    } else {
        echo "⚠️  Column type is: {$colType}\n";
        echo "    Attempting to set to VARCHAR(255)...\n";
        
        $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL");
        echo "✓ Column updated!\n\n";
    }
    
    // 3. Check for truncated hashes
    echo "STEP 3: Checking for potentially truncated password hashes...\n";
    $users = $pdo->query("SELECT user_id, username, LENGTH(password) as len FROM users")->fetchAll();
    
    $truncated = [];
    foreach ($users as $u) {
        if ($u['len'] < 50 || $u['len'] > 255) {
            $truncated[] = $u;
        }
    }
    
    if (count($truncated) > 0) {
        echo "⚠️  Found " . count($truncated) . " user(s) with suspicious password hash lengths:\n";
        foreach ($truncated as $u) {
            echo "    ID: {$u['user_id']} | Username: {$u['username']} | Length: {$u['len']}\n";
        }
        echo "    These users may have issues logging in. Ask them to reset their password.\n\n";
    } else {
        echo "✓ All password hashes have normal length\n\n";
    }
    
    // 4. Show user count
    echo "STEP 4: User statistics\n";
    echo "  Total users: " . count($users) . "\n";
    foreach ($users as $u) {
        echo "    {$u['user_id']}: {$u['username']} (hash length: {$u['len']})\n";
    }
    echo "\n";
    
    // 5. Test password hash/verify
    echo "STEP 5: Testing password hash functionality...\n";
    $testPassword = "TestPassword123!";
    $testHash = password_hash($testPassword, PASSWORD_DEFAULT);
    $testVerify = password_verify($testPassword, $testHash);
    
    echo "  Test password: {$testPassword}\n";
    echo "  Generated hash: " . substr($testHash, 0, 30) . "...\n";
    echo "  Hash length: " . strlen($testHash) . "\n";
    echo "  Verify result: " . ($testVerify ? "✓ PASS" : "❌ FAIL") . "\n\n";
    
    echo "✅ MIGRATION COMPLETE\n";
    echo "Password field is now properly configured!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<br><a href='Users.php' style='padding:8px 16px;background:#800000;color:#fff;text-decoration:none;border-radius:4px;display:inline-block;'>Back to Users</a>";
?>
