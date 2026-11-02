<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';

$pageTitle = 'Privacy Policy — Gemora';
$pageHeading = 'Privacy Policy';
$pageSubtitle = 'How Gemora collects, uses, and protects your personal information.';
$headerNavActive = '';
$pageUpdated = 'June 19, 2026';

$pageSections = [
    [
        'title' => '1. Who we are',
        'body' => '<p>Gemora (“we”, “us”, “our”) operates an online lingerie store serving customers in Myanmar. This Privacy Policy explains what data we collect when you use our website, create an account, or place an order.</p>',
    ],
    [
        'title' => '2. Information we collect',
        'body' => '<ul>
            <li><strong>Account details</strong> — Name, email address, phone number, and password (stored securely).</li>
            <li><strong>Order information</strong> — Shipping address, items purchased, payment method, and order history.</li>
            <li><strong>Technical data</strong> — Browser type, device information, and pages visited, collected through standard server logs and cookies where used.</li>
            <li><strong>Communications</strong> — Messages you send us by email or through support channels.</li>
        </ul>',
    ],
    [
        'title' => '3. How we use your information',
        'body' => '<ul>
            <li>Process and deliver your orders</li>
            <li>Manage your account, wishlist, and cart</li>
            <li>Send order updates and respond to enquiries</li>
            <li>Improve our website, products, and customer experience</li>
            <li>Prevent fraud and protect the security of our platform</li>
            <li>Send marketing communications only where you have agreed to receive them</li>
        </ul>',
    ],
    [
        'title' => '4. Sharing your information',
        'body' => '<p>We do not sell your personal data. We may share information only with:</p>
        <ul>
            <li>Delivery partners to fulfil your order</li>
            <li>Payment processors to complete transactions</li>
            <li>Service providers who help us operate the website (under confidentiality obligations)</li>
            <li>Authorities when required by law or to protect our legal rights</li>
        </ul>',
    ],
    [
        'title' => '5. Data retention & security',
        'body' => '<p>We keep your information only as long as needed for the purposes above, including legal, tax, and accounting requirements. We use reasonable technical and organisational measures to protect your data, but no online service can guarantee absolute security.</p>',
    ],
    [
        'title' => '6. Your rights',
        'body' => '<p>Depending on applicable law, you may request access to, correction of, or deletion of your personal data. You may also withdraw marketing consent at any time.</p>
        <p>To make a request, contact us at <a href="mailto:info@gemora.com">info@gemora.com</a>. We may need to verify your identity before processing a request.</p>',
    ],
    [
        'title' => '7. Cookies',
        'body' => '<p>We use essential cookies and similar technologies to keep you signed in and to remember items in your cart. You can control non-essential cookies through your browser settings, though some features may not work correctly if they are disabled.</p>',
    ],
    [
        'title' => '8. Changes to this policy',
        'body' => '<p>We may update this Privacy Policy from time to time. The “Last updated” date at the bottom of this page will reflect the latest version. We encourage you to review this page periodically.</p>
        <p>Contact: <a href="mailto:info@gemora.com">info@gemora.com</a></p>',
    ],
];

require __DIR__ . '/include/info_page_layout.php';
