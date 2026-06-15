<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Hardcode the project subfolder name once here ──
define('APP_FOLDER', 'csuweb');  // change if you rename the folder

function base_url($path = '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];  // e.g. "localhost:3000" — includes port automatically
    return $scheme . '://' . $host . '/' . APP_FOLDER . '/' . ltrim($path, '/');
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function currentRole() {
    return strtolower(trim($_SESSION['role'] ?? ''));
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . base_url("login.php?error=unauthorized"));
        exit;
    }
}

function getDashboardByRole($role) {
    $map = [
        'admin'      => 'admin/dashboard.php',
        'superadmin' => 'admin/dashboard.php',
        'driver'     => 'driver/dashboard.php',
        'staff'      => 'staff/dashboard.php',
        'requestor'  => 'requestor/dashboard.php',
        'user'       => 'requestor/dashboard.php',
    ];
    return $map[strtolower(trim($role))] ?? 'login.php';
}

function redirectByRole() {
    if (!isLoggedIn()) {
        header("Location: " . base_url("login.php"));
        exit;
    }
    $path = getDashboardByRole($_SESSION['role'] ?? '');
    header("Location: " . base_url($path));
    exit;
}

function hasRole($requiredRole) {
    return isLoggedIn() &&
           currentRole() === strtolower(trim($requiredRole));
}

function requireRole($requiredRole) {
    if (!isLoggedIn()) {
        header("Location: " . base_url("login.php?error=unauthorized"));
        exit;
    }
    if (!hasRole($requiredRole)) {
        // Send to their own dashboard — avoids redirect loop
        $path = getDashboardByRole($_SESSION['role'] ?? '');
        header("Location: " . base_url($path));
        exit;
    }
}

function requireAdmin()      { requireRole('admin');      }
function requireSuperAdmin() { requireRole('superadmin'); }
function requireStaff()      { requireRole('staff');      }
function requireDriver()     { requireRole('driver');     }
function requireRequestor()  { requireRole('requestor');  }
?>