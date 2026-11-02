<?php

if (!isset($gemoraBase)) {
    $userBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    $gemoraBase = dirname($userBase);
    if ($gemoraBase === '' || $gemoraBase === '.') {
        $gemoraBase = '/';
    } else {
        $gemoraBase .= '/';
    }
    $inUserArea = true;
}

if (!function_exists('gemora_url')) {
    function gemora_url(string $path): string
    {
        global $gemoraBase;
        return htmlspecialchars($gemoraBase . ltrim($path, '/'), ENT_QUOTES, 'UTF-8');
    }
}
