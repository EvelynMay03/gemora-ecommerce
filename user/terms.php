<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';

$pageTitle = 'Terms & Conditions — Gemora';
$pageHeading = 'Terms & Conditions';
$pageSubtitle = 'Please read these terms carefully before using the Gemora website and placing an order.';
$headerNavActive = '';
$pageUpdated = 'June 19, 2026';

$pageSections = [
    [
        'title' => '1. Agreement',
        'body' => '<p>By accessing gemora.com (or our related pages) and placing an order, you agree to these Terms & Conditions. If you do not agree, please do not use our services.</p>
        <p>These terms apply to all visitors, registered users, and customers in Myanmar unless otherwise stated at checkout.</p>',
    ],
    [
        'title' => '2. Products & pricing',
        'body' => '<ul>
            <li>Product descriptions, images, colours, and sizes are provided for guidance. Slight variations may occur due to screens, lighting, or manufacturing batches.</li>
            <li>All prices are listed in Myanmar Kyat (MMK) unless stated otherwise.</li>
            <li>We reserve the right to correct pricing errors and to limit quantities on promotional items.</li>
            <li>Preorder items are subject to estimated arrival timelines shown at the time of purchase.</li>
        </ul>',
    ],
    [
        'title' => '3. Orders & payment',
        'body' => '<ul>
            <li>An order is confirmed only after we accept it and you receive an order confirmation.</li>
            <li>You are responsible for providing accurate contact, shipping, and payment details.</li>
            <li>We may cancel or refuse an order if items are unavailable, payment cannot be verified, or we suspect fraud or abuse.</li>
            <li>Payment methods available at checkout must be completed according to the instructions provided.</li>
        </ul>',
    ],
    [
        'title' => '4. Shipping & delivery',
        'body' => '<p>Delivery times are estimates and may vary by region, courier capacity, and public holidays. Risk of loss passes to you upon delivery to the address you provide.</p>
        <p>Please inspect your package on arrival and contact us promptly if there is a problem with delivery.</p>',
    ],
    [
        'title' => '5. Returns & refunds',
        'body' => '<ul>
            <li>Intimate apparel may be returned only when items are unworn, unwashed, with original tags and hygiene seals intact, within the return window stated on our Shipping & Returns information.</li>
            <li>Sale, clearance, and preorder items may have different return rules as disclosed at purchase.</li>
            <li>Approved refunds are processed to the original payment method where possible, minus any non-refundable shipping fees if applicable.</li>
        </ul>',
    ],
    [
        'title' => '6. Accounts & acceptable use',
        'body' => '<p>You are responsible for keeping your account credentials secure. You agree not to misuse the website, interfere with its operation, or use it for unlawful purposes.</p>
        <p>We may suspend or terminate accounts that violate these terms or harm other customers or our business.</p>',
    ],
    [
        'title' => '7. Limitation of liability',
        'body' => '<p>To the fullest extent permitted by applicable law, Gemora is not liable for indirect, incidental, or consequential damages arising from use of the site or products. Our total liability for any claim relating to an order is limited to the amount you paid for that order.</p>',
    ],
    [
        'title' => '8. Changes & contact',
        'body' => '<p>We may update these terms from time to time. Continued use of the site after changes are posted constitutes acceptance of the revised terms.</p>
        <p>Questions about these terms: <a href="mailto:info@gemora.com">info@gemora.com</a></p>',
    ],
];

require __DIR__ . '/include/info_page_layout.php';
