<?php
// Database configuration
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'Gemora';
$username = getenv('DB_USER');
$password = getenv('DB_PASSWORD');

try {
    if ($username === false || $password === false) {
        throw new RuntimeException('Database credentials are not configured.');
    }

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your configuration.");

} catch (RuntimeException $e) {
    error_log($e->getMessage());
    die("Database configuration is missing.");
}