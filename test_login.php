<?php
// Quick test to verify driver login flow
session_start();
require_once 'includes/db.php';
require_once 'includes/auth.php';

echo "<h2>🔐 Driver Login Diagnostic</h2>";

// Check if any driver users exist
try {
    $stmt = $pdo->prepare("SELECT user_id, username, email, role FROM users WHERE role = ? LIMIT 5");
    $stmt->execute(['driver']);
    $drivers = $stmt->fetchAll();
    
    echo "<h3>Driver Users in Database:</h3>";
    if (empty($drivers)) {
        echo "<p style='color:red'>❌ <strong>No driver users found!</strong></p>";
        echo "<p>You must create at least one driver account via Admin > Drivers panel first.</p>";
    } else {
        echo "<p style='color:green'>✓ Found " . count($drivers) . " driver user(s):</p>";
        echo "<table border='1' style='padding:10px'>";
        echo "<tr><th>User ID</th><th>Username</th><th>Email</th><th>Role</th></tr>";
        foreach ($drivers as $d) {
            echo "<tr><td>{$d['user_id']}</td><td>{$d['username']}</td><td>{$d['email']}</td><td>{$d['role']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test password verification functions
echo "<h3>Password Verification Test:</h3>";
$testPassword = "test123";
$bcryptHash = password_hash($testPassword, PASSWORD_DEFAULT);
$md5Hash = md5($testPassword);

echo "<p><strong>Test password:</strong> $testPassword</p>";
echo "<p><strong>Bcrypt hash:</strong> $bcryptHash</p>";
echo "<p><strong>MD5 hash:</strong> $md5Hash</p>";

// Test bcrypt verification
$bcryptVerify = password_verify($testPassword, $bcryptHash);
echo "<p>✓ Bcrypt verify: " . ($bcryptVerify ? "PASS" : "FAIL") . "</p>";

// Test MD5 fallback
$md5Verify = (md5($testPassword) === $md5Hash);
echo "<p>✓ MD5 verify: " . ($md5Verify ? "PASS" : "FAIL") . "</p>";

// Test role normalization
echo "<h3>Role Normalization Test:</h3>";
$testRole = "  DRIVER  ";
$normalized = strtolower(trim($testRole));
echo "<p>Input: '$testRole'</p>";
echo "<p>Normalized: '$normalized'</p>";
echo "<p>Matches 'driver': " . ($normalized === 'driver' ? "✓ YES" : "❌ NO") . "</p>";

echo "<p style='margin-top:20px'><a href='login.php'>← Back to Login</a></p>";
?>
