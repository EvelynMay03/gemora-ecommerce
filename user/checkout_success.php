<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/user_paths.php';
require_once __DIR__ . '/include/checkout_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $gemoraBase . 'login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$orderId = (int) ($_GET['order_id'] ?? 0);
$order = $orderId > 0 ? checkout_fetch_order($pdo, $userId, $orderId) : null;

if (!$order) {
    header('Location: ' . $userBase . 'index.php');
    exit;
}

$itemCount = 0;
foreach ($order['items'] as $item) {
    $itemCount += (int) ($item['qty'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed - Gemora</title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
</head>
<body>
    <div class="page" id="app">
        <?php include __DIR__ . '/../include/header.php'; ?>

        <main class="checkout-success-page">
            <div class="success-card card">
                <div class="success-icon" aria-hidden="true">✓</div>
                <h1>Thank you for your order!</h1>
                <p class="success-lead">Order <strong><?php echo htmlspecialchars(checkout_display_order_number($order), ENT_QUOTES, 'UTF-8'); ?></strong> has been placed and is now <strong><?php echo htmlspecialchars((string) $order['status'], ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

                <div class="success-details">
                    <div class="success-row">
                        <span>Items</span>
                        <span><?php echo $itemCount; ?></span>
                    </div>
                    <div class="success-row">
                        <span>Payment</span>
                        <span><?php echo htmlspecialchars((string) $order['payment_method'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="success-row">
                        <span>Shipping</span>
                        <span><?php echo (int) $order['shipping'] > 0 ? 'MMK ' . number_format((int) $order['shipping']) : 'Free'; ?></span>
                    </div>
                    <div class="success-row total">
                        <span>Total paid</span>
                        <span>MMK <?php echo number_format((int) $order['total']); ?></span>
                    </div>
                </div>

                <?php if (!empty($order['items'])): ?>
                <ul class="success-items">
                    <?php foreach ($order['items'] as $item): ?>
                    <li>
                        <?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?>
                        · Qty <?php echo (int) $item['qty']; ?>
                        · MMK <?php echo number_format((int) $item['unit_price'] * (int) $item['qty']); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <div class="success-actions">
                    <a class="btn primary" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Continue Shopping</a>
                    <a class="btn ghost" href="<?php echo htmlspecialchars($gemoraBase . 'profile.php', ENT_QUOTES, 'UTF-8'); ?>">View Profile</a>
                </div>
            </div>
        </main>

        <?php include __DIR__ . '/../include/footer.php'; ?>
    </div>

    <style>
        .checkout-success-page { margin: 32px 0; display: flex; justify-content: center; }
        .success-card {
            width: min(560px, 100%);
            padding: 32px 28px;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .success-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #e8f4ea;
            color: #245d2b;
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .success-card h1 { margin: 0 0 10px; font-size: 26px; color: var(--text); }
        .success-lead { margin: 0 0 20px; color: var(--muted); font-size: 15px; line-height: 1.5; }
        .success-details {
            text-align: left;
            background: var(--bg);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 16px;
        }
        .success-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .success-row.total { font-weight: 700; color: var(--accent); }
        .success-items {
            list-style: none;
            margin: 0 0 20px;
            padding: 0;
            text-align: left;
            font-size: 13px;
            color: var(--muted);
        }
        .success-items li { padding: 6px 0; border-bottom: 1px solid var(--glass-border); }
        .success-items li:last-child { border-bottom: 0; }
        .success-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .success-actions .btn { min-width: 160px; text-decoration: none; }
    </style>
</body>
</html>
