<?php
$host = 'localhost';
$dbname = 'intern_track_db';
$username = 'root'; // Default XAMPP/WAMP username
$password = ''; // Default XAMPP/WAMP password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Shared helpers (validation, settings, layout, etc.)
require_once __DIR__ . '/functions.php';

// Upgrades an existing database automatically the first time this version runs,
// so you do NOT need to import anything into phpMyAdmin for an existing database.
// (A brand-new database can simply import intern_track_db.sql.)
ensure_schema($pdo);
?>