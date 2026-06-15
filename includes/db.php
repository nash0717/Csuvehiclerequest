<?php
$host     = 'localhost';
$db_name  = 'vehicle_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Ensure password field is large enough for bcrypt hashes (up to 255 chars)
    try {
        $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
        if ($col) {
            $colType = strtoupper($col['Type']);
            $nullable = strtoupper($col['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
            
            // Check if field size is too small
            if (stripos($colType, 'VARCHAR(60)') !== false) {
                // Expand the column
                $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL");
            } elseif (stripos($colType, 'VARCHAR') !== false && !stripos($colType, '255')) {
                // Some other VARCHAR size that's not 255
                if (preg_match('/VARCHAR\((\d+)\)/', $colType, $matches)) {
                    $size = (int)$matches[1];
                    if ($size < 255) {
                        $pdo->exec("ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL");
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Silently ignore - table might not exist yet
    }
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// MySQLi connection for files that use $conn
$conn = mysqli_connect($host, $username, $password, $db_name);
if (!$conn) {
    die("MySQLi connection failed: " . mysqli_connect_error());
}
mysqli_set_charset($conn, 'utf8mb4');