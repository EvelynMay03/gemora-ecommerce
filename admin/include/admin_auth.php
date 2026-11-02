<?php
require_once __DIR__ . '/../../include/session.php';

if (!isset($_SESSION['user_id'])) {
    $returnUrl = $_SERVER['REQUEST_URI'] ?? '/admin/admin.php';
    header('Location: ../login.php?redirect=' . urlencode($returnUrl));
    exit;
}

if (!gemora_is_admin_role($_SESSION['role'] ?? '')) {
    header('Location: ../profile.php');
    exit;
}
