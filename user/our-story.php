<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';

$pageTitle = 'Our Story — Gemora';
$pageHeading = 'Our Story';
$pageSubtitle = 'Imported-quality lingerie for everyday confidence — thoughtfully curated for women in Myanmar.';
$headerNavActive = '';

$pageSections = [
    [
        'title' => 'How Gemora began',
        'body' => '<p>Gemora started with a simple belief: every woman deserves lingerie that feels as good as it looks. We saw a gap between fast, low-quality options and overpriced imports — and set out to build something better for Myanmar.</p>
        <p>What began as a small curated collection has grown into a destination for bras, panties, and intimates selected for comfort, fit, and lasting quality. We partner with trusted brands and suppliers so you can shop with confidence.</p>',
    ],
    [
        'title' => 'What we stand for',
        'body' => '<ul>
            <li><strong>Comfort first</strong> — Soft fabrics, reliable support, and fits designed for real bodies.</li>
            <li><strong>Honest quality</strong> — We focus on imported-standard materials and construction you can feel.</li>
            <li><strong>Accessible style</strong> — Elegant essentials at fair prices, in MMK.</li>
            <li><strong>Care in every order</strong> — From product photos to delivery, we aim for a smooth, respectful experience.</li>
        </ul>',
    ],
    [
        'title' => 'Serving Myanmar',
        'body' => '<p>We are proud to serve customers across Myanmar with clear pricing, local payment options, and delivery to your region. Whether you are shopping for everyday basics or something special, Gemora is here to help you find pieces that support your day — and your confidence.</p>
        <p>Thank you for being part of our story. We are always listening to your feedback as we grow our range and improve our service.</p>',
    ],
];

require __DIR__ . '/include/info_page_layout.php';
