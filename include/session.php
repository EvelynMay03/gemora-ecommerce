<?php
if (session_status() === PHP_SESSION_NONE) {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (preg_match('#^(.+?)/(admin|user)(/|$)#', $scriptName, $matches)) {
        $cookiePath = $matches[1] . '/';
    } else {
        $dir = rtrim(dirname($scriptName), '/');
        $cookiePath = ($dir === '' || $dir === '.') ? '/' : $dir . '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function gemora_is_admin_role(?string $role): bool
{
    $role = strtolower(trim((string) $role));
    return in_array($role, ['super', 'admin'], true);
}

function gemora_is_superadmin_role(?string $role): bool
{
    return strtolower(trim((string) $role)) === 'super';
}
