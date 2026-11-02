<?php
$adminOrdersConfig = [
    'script' => 'admin_preorders.php',
    'nav' => 'preorders',
    'kind' => 'preorder',
    'title' => 'Preorders',
    'subtitle' => 'Preorder orders only (latest 10) · Manage status and details',
    'empty_label' => 'No preorder orders found.',
    'count_label' => 'preorder',
    'page_title' => 'Preorders — Gemora Admin',
    'load_error' => 'Could not load preorders.',
];
require __DIR__ . '/admin_orders.php';
