<?php
require_once __DIR__ . '/../database/pdo.php';

$email = 'admin@gmail.com';
$password = 'Admin123456';
$name = 'Super Admin';
$role = 'super';
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    $existing = $check->fetch();

    if ($existing) {
        $update = $pdo->prepare('
            UPDATE users SET name = ?, password = ?, role = ?
            WHERE email = ?
        ');
        $update->execute([$name, $hashedPassword, $role, $email]);
        echo 'Super admin updated successfully! (existing account with this email was updated)';
    } else {
        $insert = $pdo->prepare('
            INSERT INTO users (name, email, password, role)
            VALUES (?, ?, ?, ?)
        ');
        $insert->execute([$name, $email, $hashedPassword, $role]);
        echo 'Super admin added successfully! User ID: ' . (int) $pdo->lastInsertId();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo 'Failed to create super admin: ' . htmlspecialchars($e->getMessage());
}
