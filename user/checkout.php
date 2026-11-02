<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/user_paths.php';
require_once __DIR__ . '/include/cart_lib.php';
require_once __DIR__ . '/include/checkout_lib.php';
require_once __DIR__ . '/include/address_lib.php';

if (!isset($_SESSION['user_id'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? ($userBase . 'checkout.php');
    header('Location: ' . $gemoraBase . 'login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$message = '';
$messageType = 'error';
$fieldErrors = [];

$userData = ['name' => $_SESSION['name'] ?? '', 'email' => $_SESSION['email'] ?? '', 'phone' => ''];
try {
    $uStmt = $pdo->prepare('SELECT name, email, phone FROM users WHERE user_id = ? LIMIT 1');
    $uStmt->execute([$userId]);
    $row = $uStmt->fetch();
    if ($row) {
        $userData = $row;
    }
} catch (Throwable $e) {
    error_log('Checkout user fetch failed: ' . $e->getMessage());
}

$userAddresses = [];
try {
    $userAddresses = address_fetch_for_user($pdo, $userId);
} catch (Throwable $e) {
    error_log('Checkout addresses fetch failed: ' . $e->getMessage());
}

$cartItems = cart_fetch_items($pdo, $userId);
if (empty($cartItems) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $userBase . 'cart.php');
    exit;
}

$cartMixCheck = cart_validate_cart_items($cartItems);
if (!$cartMixCheck['ok'] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $userBase . 'cart.php');
    exit;
}

$totals = cart_calculate_totals($cartItems);
$totalQty = $totals['total_qty'];

$formDefaults = [
    'recipient_name' => $userData['name'] ?? '',
    'phone' => $userData['phone'] ?? '',
    'email' => $userData['email'] ?? '',
    'street_address' => '',
    'region' => 'Yangon',
    'city_township' => '',
    'apartment_building' => '',
    'payment_method' => 'cod',
    'order_notes' => '',
    'save_address' => false,
    'selected_address_id' => '',
    'payment_screenshot_path' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $formDefaults = array_merge($formDefaults, [
        'recipient_name' => trim($_POST['recipient_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'street_address' => trim($_POST['street_address'] ?? ''),
        'region' => trim($_POST['region'] ?? ''),
        'city_township' => trim($_POST['city_township'] ?? ''),
        'apartment_building' => trim($_POST['apartment_building'] ?? ''),
        'payment_method' => trim($_POST['payment_method'] ?? ''),
        'order_notes' => trim($_POST['order_notes'] ?? ''),
        'save_address' => isset($_POST['save_address']),
        'selected_address_id' => (string) ($_POST['selected_address_id'] ?? ''),
        'payment_screenshot_path' => '',
    ]);

    $paymentMethod = trim((string) $formDefaults['payment_method']);
    if ($paymentMethod !== '' && $paymentMethod !== 'cod') {
        $uploadResult = checkout_save_payment_screenshot($_FILES['payment_screenshot'] ?? []);
        if (!empty($uploadResult['ok'])) {
            $formDefaults['payment_screenshot_path'] = (string) ($uploadResult['path'] ?? '');
        } else {
            $fieldErrors['payment_screenshot'] = (string) ($uploadResult['message'] ?? 'Please upload payment screenshot.');
            $message = $fieldErrors['payment_screenshot'];
        }
    }

    if (empty($fieldErrors)) {
        $result = checkout_place_order($pdo, $userId, $formDefaults);
        if ($result['ok'] && !empty($result['order_id'])) {
            header('Location: ' . $userBase . 'checkout_success.php?order_id=' . (int) $result['order_id']);
            exit;
        }

        $message = $result['message'];
        $fieldErrors = $result['errors'] ?? [];
        $cartItems = cart_fetch_items($pdo, $userId);
        $totals = cart_calculate_totals($cartItems);
        $totalQty = $totals['total_qty'];
    }
}

$regions = checkout_myanmar_regions();
$paymentMethods = checkout_payment_methods();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Gemora</title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
</head>
<body>
    <div class="page" id="app">
        <?php include __DIR__ . '/../include/header.php'; ?>

        <main class="checkout-page">
            <div class="checkout-header">
                <h1>Checkout</h1>
                <p class="checkout-subtitle"><?php echo $totalQty; ?> <?php echo $totalQty === 1 ? 'item' : 'items'; ?> · Myanmar</p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="checkout-feedback <?php echo $messageType; ?>" role="alert"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (empty($cartItems)): ?>
                <div class="checkout-empty">
                    <p>Your cart is empty.</p>
                    <a class="btn primary" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Continue Shopping</a>
                </div>
            <?php else: ?>
            <form method="post" action="<?php echo htmlspecialchars($userBase . 'checkout.php', ENT_QUOTES, 'UTF-8'); ?>" class="checkout-container" id="checkoutForm" enctype="multipart/form-data">
                <input type="hidden" name="place_order" value="1">

                <div class="checkout-main">
                    <section class="checkout-panel card">
                        <h2>Shipping Information</h2>

                        <?php if (!empty($userAddresses)): ?>
                        <label class="checkout-field full">
                            <span class="field-label">Saved address</span>
                            <select name="selected_address_id" id="savedAddressSelect" class="checkout-input">
                                <option value="">Enter a new address</option>
                                <?php foreach ($userAddresses as $addr): ?>
                                <option value="<?php echo (int) $addr['address_id']; ?>"
                                    data-region="<?php echo htmlspecialchars((string) ($addr['region'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-city="<?php echo htmlspecialchars((string) ($addr['city_township'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-building="<?php echo htmlspecialchars((string) ($addr['apartment_building'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-street="<?php echo htmlspecialchars((string) ($addr['street_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo (string) $formDefaults['selected_address_id'] === (string) $addr['address_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) ($addr['display_label'] ?? $addr['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php endif; ?>

                        <div class="checkout-grid">
                            <label class="checkout-field full">
                                <span class="field-label">Full name *</span>
                                <input type="text" name="recipient_name" class="checkout-input<?php echo isset($fieldErrors['recipient_name']) ? ' is-error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($formDefaults['recipient_name'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100">
                            </label>
                            <label class="checkout-field">
                                <span class="field-label">Phone *</span>
                                <input type="tel" name="phone" class="checkout-input<?php echo isset($fieldErrors['phone']) ? ' is-error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($formDefaults['phone'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="20">
                            </label>
                            <label class="checkout-field">
                                <span class="field-label">Email *</span>
                                <input type="email" name="email" class="checkout-input<?php echo isset($fieldErrors['email']) ? ' is-error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($formDefaults['email'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100">
                            </label>
                            <label class="checkout-field">
                                <span class="field-label">Region *</span>
                                <select name="region" id="checkoutRegion" class="checkout-input<?php echo isset($fieldErrors['region']) ? ' is-error' : ''; ?>" required>
                                    <?php foreach ($regions as $region): ?>
                                    <option value="<?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>"
                                        <?php echo $formDefaults['region'] === $region ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="checkout-field">
                                <span class="field-label">City / Township *</span>
                                <input type="text" name="city_township" id="checkoutCity" class="checkout-input<?php echo isset($fieldErrors['city_township']) ? ' is-error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($formDefaults['city_township'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="100">
                            </label>
                            <label class="checkout-field full">
                                <span class="field-label">Apartment / building (optional)</span>
                                <input type="text" name="apartment_building" id="checkoutBuilding" class="checkout-input"
                                    value="<?php echo htmlspecialchars($formDefaults['apartment_building'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255">
                            </label>
                            <label class="checkout-field full">
                                <span class="field-label">Street address *</span>
                                <input type="text" name="street_address" id="checkoutStreet" class="checkout-input<?php echo isset($fieldErrors['street_address']) ? ' is-error' : ''; ?>"
                                    value="<?php echo htmlspecialchars($formDefaults['street_address'], ENT_QUOTES, 'UTF-8'); ?>" required maxlength="255">
                            </label>
                            <label class="checkout-field full">
                                <span class="field-label">Delivery notes (optional)</span>
                                <textarea name="order_notes" class="checkout-input checkout-textarea" rows="2" maxlength="500"><?php echo htmlspecialchars($formDefaults['order_notes'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </label>
                            <label class="checkout-checkbox full">
                                <input type="checkbox" name="save_address" value="1" <?php echo $formDefaults['save_address'] ? 'checked' : ''; ?>>
                                Save this address to my profile
                            </label>
                        </div>
                    </section>

                    <section class="checkout-panel card">
                        <h2>Payment Method</h2>
                        <?php if (isset($fieldErrors['payment_method'])): ?>
                            <p class="field-hint error"><?php echo htmlspecialchars($fieldErrors['payment_method'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <div class="payment-options">
                            <?php foreach ($paymentMethods as $method): ?>
                            <label class="payment-option">
                                <input type="radio" name="payment_method" value="<?php echo htmlspecialchars($method, ENT_QUOTES, 'UTF-8'); ?>"
                                    <?php echo $formDefaults['payment_method'] === $method ? 'checked' : ''; ?> required>
                                <span class="payment-option-body">
                                    <strong><?php echo htmlspecialchars(checkout_payment_label($method), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if ($method === 'cod'): ?>
                                    <small>Pay when your order arrives</small>
                                    <?php elseif ($method === 'bank_transfer'): ?>
                                    <small>We will send account details after you place the order</small>
                                    <?php else: ?>
                                    <small>Mobile payment — instructions after checkout</small>
                                    <?php endif; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="payment-transfer-info">
                            <h3>Transfer Accounts</h3>
                            <ul>
                                <li><strong>KPay:</strong> 09 7888 11122 (May Myint Myat)</li>
                                <li><strong>WavePay:</strong> 09 7666 33344 (Gemora Shop)</li>
                                <li><strong>Bank Transfer:</strong> KBZ Bank 0011223344556677 (Gemora Co., Ltd)</li>
                            </ul>
                        </div>
                        <div class="payment-proof-block" id="paymentProofBlock">
                            <label class="checkout-field full">
                                <span class="field-label">Payment screenshot *</span>
                                <div class="payment-proof-field">
                                    <div class="payment-file-popover" id="paymentScreenshotPopover" role="alert" hidden>
                                        Please select a file
                                    </div>
                                    <input type="file" name="payment_screenshot" id="paymentScreenshotInput"
                                        class="checkout-input<?php echo isset($fieldErrors['payment_screenshot']) ? ' is-error' : ''; ?>"
                                        accept="image/jpeg,image/png,image/webp">
                                </div>
                            </label>
                            <?php if (isset($fieldErrors['payment_screenshot'])): ?>
                                <p class="field-hint error" id="paymentScreenshotError"><?php echo htmlspecialchars($fieldErrors['payment_screenshot'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <aside class="checkout-summary">
                    <div class="summary-card">
                        <h2>Order Summary</h2>

                        <ul class="checkout-line-items">
                            <?php foreach ($cartItems as $item): ?>
                            <?php
                            $qty = (int) $item['qty'];
                            $unitPrice = (int) $item['unit_price'];
                            $lineTotal = $qty * $unitPrice;
                            $size = trim((string) ($item['size'] ?? ''));
                            $color = trim((string) ($item['color'] ?? ''));
                            $opts = [];
                            if ($size !== '') {
                                $opts[] = 'Size ' . $size;
                            }
                            if ($color !== '') {
                                $opts[] = $color;
                            }
                            $isPreorder = (int) ($item['variant_stock'] ?? 0) <= 0 && (int) ($item['preorder_flag'] ?? 0) === 1;
                            ?>
                            <li class="checkout-line-item">
                                <div class="checkout-line-thumb">
                                    <img src="<?php echo htmlspecialchars(cart_img_url($item['image_path'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="" loading="lazy">
                                </div>
                                <div class="checkout-line-meta">
                                    <div class="checkout-line-title"><?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="checkout-line-brand"><?php echo htmlspecialchars((string) $item['brand_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if (!empty($opts)): ?>
                                    <div class="checkout-line-options"><?php echo htmlspecialchars(implode(' · ', $opts), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if ($isPreorder): ?>
                                    <span class="checkout-preorder-badge">Preorder</span>
                                    <?php endif; ?>
                                    <div class="checkout-line-qty">Qty <?php echo $qty; ?> × MMK <?php echo number_format($unitPrice); ?></div>
                                </div>
                                <div class="checkout-line-price">MMK <?php echo number_format($lineTotal); ?></div>
                            </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="summary-row">
                            <span>Subtotal (<?php echo $totalQty; ?> <?php echo $totalQty === 1 ? 'item' : 'items'; ?>)</span>
                            <span>MMK <?php echo number_format($totals['subtotal']); ?></span>
                        </div>
                        <div class="summary-row">
                            <span>Shipping</span>
                            <span><?php echo $totals['shipping'] > 0 ? 'MMK ' . number_format($totals['shipping']) : 'Free'; ?></span>
                        </div>
                        <div class="summary-divider"></div>
                        <div class="summary-row total">
                            <span>Total</span>
                            <span class="total-amount">MMK <?php echo number_format($totals['total']); ?></span>
                        </div>

                        <button type="submit" class="btn primary checkout-submit-btn">Place Order</button>
                        <a class="btn ghost checkout-back-btn" href="<?php echo htmlspecialchars($userBase . 'cart.php', ENT_QUOTES, 'UTF-8'); ?>">Back to Cart</a>

                        <div class="shipping-info">
                            <strong>Free shipping</strong> on orders over MMK 100,000
                        </div>
                        <div class="secure-payment">
                            <strong>Secure checkout</strong> — your data stays private
                        </div>
                    </div>
                </aside>
            </form>
            <?php endif; ?>
        </main>

        <?php include __DIR__ . '/../include/footer.php'; ?>
    </div>

    <style>
        .checkout-page { margin: 24px 0; }
        .checkout-header { margin-bottom: 20px; }
        .checkout-header h1 { font-size: 28px; font-weight: 700; margin: 0 0 6px; color: var(--text); }
        .checkout-subtitle { margin: 0; font-size: 14px; color: var(--muted); }
        .checkout-feedback {
            border-radius: var(--radius);
            padding: 12px 14px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .checkout-feedback.error {
            background: #fbeaea;
            border: 1px solid #e4b5b5;
            color: #7f2020;
        }
        .checkout-container {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
            align-items: start;
        }
        .checkout-main { display: flex; flex-direction: column; gap: 16px; }
        .checkout-panel {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .checkout-panel h2 {
            margin: 0 0 16px;
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .checkout-field { display: grid; gap: 6px; }
        .checkout-field.full { grid-column: 1 / -1; }
        .field-label { font-size: 13px; font-weight: 600; color: var(--text); }
        .checkout-input {
            width: 100%;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
            color: var(--text);
        }
        .checkout-input:focus {
            outline: none;
            border-color: var(--accent);
        }
        .checkout-input.is-error { border-color: #c33; }
        .checkout-textarea { resize: vertical; min-height: 64px; }
        .checkout-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text);
        }
        .checkout-checkbox input { width: auto; }
        .field-hint.error { color: #9b2226; font-size: 13px; margin: 0 0 10px; }
        .payment-options { display: grid; gap: 10px; }
        .payment-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            cursor: pointer;
            background: #fff;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .payment-option:has(input:checked) {
            border-color: var(--accent);
            background: var(--accent-2);
        }
        .payment-option input { margin-top: 3px; }
        .payment-option-body { display: grid; gap: 2px; }
        .payment-option-body small { color: var(--muted); font-size: 12px; }
        .payment-transfer-info {
            margin-top: 14px;
            background: var(--bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 12px;
        }
        .payment-transfer-info h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }
        .payment-transfer-info ul {
            margin: 0;
            padding-left: 16px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
        }
        .payment-proof-block {
            margin-top: 12px;
            display: none;
        }
        .payment-proof-block.is-visible {
            display: block;
        }
        .payment-proof-field {
            position: relative;
            margin-top: 6px;
        }
        .payment-file-popover {
            position: absolute;
            left: 0;
            bottom: calc(100% + 10px);
            z-index: 6;
            min-width: 180px;
            max-width: 280px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
            color: #334155;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.4;
        }
        .payment-file-popover::after {
            content: '';
            position: absolute;
            left: 28px;
            bottom: -6px;
            width: 10px;
            height: 10px;
            background: #f8fafc;
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            transform: rotate(45deg);
        }
        .payment-file-popover.is-error {
            background: #fff7f7;
            border-color: #e4b5b5;
            color: #7f2020;
        }
        .payment-file-popover.is-error::after {
            background: #fff7f7;
            border-color: #e4b5b5;
        }
        .checkout-summary { position: sticky; top: 20px; }
        .summary-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }
        .summary-card h2 { margin: 0 0 14px; font-size: 18px; font-weight: 600; }
        .checkout-line-items {
            list-style: none;
            margin: 0 0 14px;
            padding: 0;
            display: grid;
            gap: 12px;
            max-height: 280px;
            overflow-y: auto;
        }
        .checkout-line-item {
            display: grid;
            grid-template-columns: 56px 1fr auto;
            gap: 10px;
            align-items: start;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--glass-border);
        }
        .checkout-line-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .checkout-line-thumb {
            width: 56px;
            height: 56px;
            border-radius: 6px;
            overflow: hidden;
            background: var(--accent-2);
        }
        .checkout-line-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .checkout-line-title { font-size: 13px; font-weight: 600; color: var(--text); }
        .checkout-line-brand, .checkout-line-options, .checkout-line-qty {
            font-size: 12px;
            color: var(--muted);
        }
        .checkout-preorder-badge {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 600;
            color: #fff;
            background: var(--accent);
            border-radius: 4px;
        }
        .checkout-line-price { font-size: 13px; font-weight: 700; color: var(--text); white-space: nowrap; }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .summary-divider { height: 1px; background: var(--glass-border); margin: 10px 0; }
        .summary-row.total { font-size: 17px; font-weight: 700; }
        .total-amount { color: var(--accent); }
        .checkout-submit-btn, .checkout-back-btn { width: 100%; margin-top: 10px; }
        .checkout-back-btn { text-align: center; text-decoration: none; display: inline-flex; justify-content: center; }
        .shipping-info, .secure-payment {
            margin-top: 14px;
            padding: 10px 12px;
            background: var(--bg);
            border-radius: 6px;
            font-size: 12px;
            color: var(--muted);
        }
        .checkout-empty {
            text-align: center;
            padding: 48px 20px;
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
        }
        @media (max-width: 900px) {
            .checkout-container { grid-template-columns: 1fr; }
            .checkout-summary { position: static; }
            .checkout-grid { grid-template-columns: 1fr; }
        }
    </style>

    <script>
        (function() {
            var select = document.getElementById('savedAddressSelect');
            if (!select) return;

            function setField(id, value) {
                var el = document.getElementById(id);
                if (el) el.value = value || '';
            }

            select.addEventListener('change', function() {
                var opt = select.options[select.selectedIndex];
                if (!opt || !opt.value) return;
                setField('checkoutRegion', opt.dataset.region);
                setField('checkoutCity', opt.dataset.city);
                setField('checkoutBuilding', opt.dataset.building);
                setField('checkoutStreet', opt.dataset.street);
            });
        })();

        (function() {
            var proofBlock = document.getElementById('paymentProofBlock');
            var screenshotInput = document.getElementById('paymentScreenshotInput');
            var paymentPopover = document.getElementById('paymentScreenshotPopover');
            var checkoutForm = document.getElementById('checkoutForm');
            var paymentInputs = document.querySelectorAll('input[name="payment_method"]');
            if (!proofBlock || paymentInputs.length === 0) return;

            var hasServerScreenshotError = <?php echo isset($fieldErrors['payment_screenshot']) ? 'true' : 'false'; ?>;
            var serverScreenshotMessage = <?php echo json_encode($fieldErrors['payment_screenshot'] ?? 'Please upload payment screenshot.', JSON_UNESCAPED_UNICODE); ?>;

            function needsPaymentScreenshot() {
                var selected = document.querySelector('input[name="payment_method"]:checked');
                var selectedMethod = selected ? selected.value : '';
                return selectedMethod !== '' && selectedMethod !== 'cod';
            }

            function showPaymentPopover(message, isError) {
                if (!paymentPopover) return;
                paymentPopover.textContent = message || 'Please select a file';
                paymentPopover.hidden = false;
                paymentPopover.classList.toggle('is-error', !!isError);
                if (screenshotInput) {
                    screenshotInput.classList.add('is-error');
                }
                if (proofBlock && typeof proofBlock.scrollIntoView === 'function') {
                    proofBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            function hidePaymentPopover() {
                if (!paymentPopover) return;
                paymentPopover.hidden = true;
                paymentPopover.classList.remove('is-error');
            }

            function updatePaymentProofVisibility() {
                var show = needsPaymentScreenshot();
                if (show) {
                    proofBlock.classList.add('is-visible');
                } else {
                    proofBlock.classList.remove('is-visible');
                    hidePaymentPopover();
                    if (screenshotInput) {
                        screenshotInput.classList.remove('is-error');
                        screenshotInput.value = '';
                    }
                }
            }

            paymentInputs.forEach(function(input) {
                input.addEventListener('change', updatePaymentProofVisibility);
            });

            if (screenshotInput) {
                screenshotInput.addEventListener('change', function() {
                    if (screenshotInput.files && screenshotInput.files.length > 0) {
                        hidePaymentPopover();
                        screenshotInput.classList.remove('is-error');
                    }
                });
            }

            if (checkoutForm) {
                checkoutForm.addEventListener('submit', function(e) {
                    if (!needsPaymentScreenshot()) {
                        hidePaymentPopover();
                        return;
                    }
                    if (!screenshotInput || !screenshotInput.files || screenshotInput.files.length === 0) {
                        e.preventDefault();
                        showPaymentPopover('Please select a file', true);
                    }
                });
            }

            updatePaymentProofVisibility();

            if (hasServerScreenshotError && needsPaymentScreenshot()) {
                showPaymentPopover(serverScreenshotMessage, true);
            }
        })();
    </script>
</body>
</html>
