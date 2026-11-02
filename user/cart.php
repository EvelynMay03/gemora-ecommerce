<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/user_paths.php';
require_once __DIR__ . '/include/cart_lib.php';

if (!isset($_SESSION['user_id'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? ($userBase . 'cart.php');
    header('Location: ' . $gemoraBase . 'login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$userId = (int) $_SESSION['user_id'];
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $result = ['ok' => false, 'message' => 'Unknown action.'];

    try {
        if ($action === 'add') {
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $result = cart_add_item($pdo, $userId, $variantId, $qty);
        } elseif ($action === 'update') {
            $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
            $qty = max(1, (int) ($_POST['qty'] ?? 1));
            $result = cart_update_qty($pdo, $userId, $cartItemId, $qty);
        } elseif ($action === 'remove') {
            $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
            $result = cart_remove_item($pdo, $userId, $cartItemId);
        }
    } catch (Throwable $e) {
        error_log('Cart action failed: ' . $e->getMessage());
        $result = ['ok' => false, 'message' => 'Could not update your cart. Please try again.'];
    }

    if ($isAjax) {
        $items = cart_fetch_items($pdo, $userId);
        $totals = cart_calculate_totals($items);
        cart_json_response(array_merge($result, [
            'totals' => $totals,
            'item_count' => $totals['total_qty'],
        ]));
    }

    header('Location: ' . $userBase . 'cart.php');
    exit;
}

$cartItems = cart_fetch_items($pdo, $userId);
$totals = cart_calculate_totals($cartItems);
$totalQty = $totals['total_qty'];
$cartKind = cart_cart_kind($cartItems);
$cartMixCheck = cart_validate_cart_items($cartItems);
$canCheckout = $cartMixCheck['ok'] && !empty($cartItems);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Gemora</title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
</head>
<body>
    <div class="page" id="app">
        <?php include __DIR__ . '/../include/header.php'; ?>

        <main class="cart-page">
            <div class="cart-header">
                <h1>Shopping Cart</h1>
                <p class="cart-count" data-cart-count><?php echo $totalQty; ?> <?php echo $totalQty === 1 ? 'item' : 'items'; ?></p>
            </div>

            <?php if (empty($cartItems)): ?>
            <div class="cart-container cart-container-empty">
                <div class="empty-cart">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="9" cy="21" r="1" stroke="currentColor" stroke-width="1.5"/>
                        <circle cx="20" cy="21" r="1" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" stroke="currentColor" stroke-width="1.5"/>
                    </svg>
                    <h2>Your cart is empty</h2>
                    <p>Add some items to get started!</p>
                    <a class="btn primary" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Continue Shopping</a>
                </div>
            </div>
            <?php else: ?>
            <?php if (!$cartMixCheck['ok']): ?>
            <div class="cart-mixed-alert" role="alert">
                <?php echo htmlspecialchars($cartMixCheck['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php elseif ($cartKind === 'preorder'): ?>
            <div class="cart-kind-note preorder">
                This cart contains preorder items only. In-stock items must be ordered separately.
            </div>
            <?php elseif ($cartKind === 'instock'): ?>
            <div class="cart-kind-note instock">
                This cart contains in-stock items only. Preorder items must be ordered separately.
            </div>
            <?php endif; ?>
            <div class="cart-container" id="cartContainer">
                <div class="cart-items" id="cartItems">
                    <?php foreach ($cartItems as $item): ?>
                    <?php
                    $cartItemId = (int) $item['cart_item_id'];
                    $qty = (int) $item['qty'];
                    $unitPrice = (int) $item['unit_price'];
                    $lineTotal = $qty * $unitPrice;
                    $maxQty = cart_max_qty_for_variant($item);
                    $isPreorder = cart_item_is_preorder($item);
                    $size = trim((string) ($item['size'] ?? ''));
                    $color = trim((string) ($item['color'] ?? ''));
                    ?>
                    <div class="cart-item" data-cart-item-id="<?php echo $cartItemId; ?>" data-unit-price="<?php echo $unitPrice; ?>" data-max-qty="<?php echo $maxQty; ?>">
                        <div class="cart-item-image">
                            <img src="<?php echo htmlspecialchars(cart_img_url($item['image_path'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                alt="<?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                        </div>
                        <div class="cart-item-details">
                            <h3 class="cart-item-title"><?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="cart-item-brand"><?php echo htmlspecialchars((string) $item['brand_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <div class="cart-item-options">
                                <?php if ($size !== ''): ?>
                                <span class="option-label">Size:</span>
                                <span class="option-value"><?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if ($size !== '' && $color !== ''): ?>
                                <span class="option-separator">•</span>
                                <?php endif; ?>
                                <?php if ($color !== ''): ?>
                                <span class="option-label">Color:</span>
                                <span class="option-value"><?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="cart-item-price">MMK <?php echo number_format($unitPrice); ?></div>
                            <?php if ($isPreorder): ?>
                            <div class="cart-item-note">
                                <span class="badge">Preorder</span>
                                <span class="note-text">ETA: 2–3 weeks</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="cart-item-controls">
                            <div class="quantity-controls">
                                <button type="button" class="quantity-btn minus" aria-label="Decrease quantity" <?php echo $qty <= 1 ? 'disabled' : ''; ?>>−</button>
                                <input type="number" class="quantity-input" value="<?php echo $qty; ?>" min="1" max="<?php echo $maxQty; ?>" readonly>
                                <button type="button" class="quantity-btn plus" aria-label="Increase quantity" <?php echo $qty >= $maxQty ? 'disabled' : ''; ?>>+</button>
                            </div>
                            <button type="button" class="remove-btn" aria-label="Remove item">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none">
                                    <path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </button>
                        </div>
                        <div class="cart-item-total">
                            <span class="total-label">Subtotal:</span>
                            <span class="total-price">MMK <?php echo number_format($lineTotal); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <aside class="cart-summary">
                    <div class="summary-card" id="cartSummary">
                        <h2>Order Summary</h2>

                        <div class="summary-row">
                            <span data-summary-label>Subtotal (<?php echo $totalQty; ?> <?php echo $totalQty === 1 ? 'item' : 'items'; ?>)</span>
                            <span data-summary-subtotal>MMK <?php echo number_format($totals['subtotal']); ?></span>
                        </div>

                        <div class="summary-row">
                            <span>Shipping</span>
                            <span data-summary-shipping><?php echo $totals['shipping'] > 0 ? 'MMK ' . number_format($totals['shipping']) : 'Free'; ?></span>
                        </div>

                        <div class="summary-divider"></div>

                        <div class="summary-row total">
                            <span>Total</span>
                            <span class="total-amount" data-summary-total>MMK <?php echo number_format($totals['total']); ?></span>
                        </div>

                        <a class="btn primary checkout-btn<?php echo $canCheckout ? '' : ' is-disabled'; ?>"
                            href="<?php echo $canCheckout ? htmlspecialchars($userBase . 'checkout.php', ENT_QUOTES, 'UTF-8') : '#'; ?>"
                            <?php echo $canCheckout ? '' : 'aria-disabled="true"'; ?>>
                            Proceed to Checkout
                        </a>

                        <a class="btn ghost continue-shopping-btn" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
                            Continue Shopping
                        </a>

                        <div class="shipping-info">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <div>
                                <strong>Free shipping</strong> on orders over MMK 100,000
                            </div>
                        </div>

                        <div class="secure-payment">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" aria-hidden="true">
                                <rect x="3" y="11" width="18" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <div>
                                <strong>Secure checkout</strong> with encrypted payments
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
            <?php endif; ?>
        </main>

        <?php include __DIR__ . '/../include/footer.php'; ?>
    </div>

    <style>
        /* Cart Page Styles */
        .cart-page {
            margin: 24px 0;
        }

        .cart-header {
            margin-bottom: 24px;
        }

        .cart-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: var(--text);
        }

        .cart-count {
            color: var(--muted);
            font-size: 14px;
            margin: 0;
        }

        .cart-mixed-alert {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 8px;
            background: rgba(155, 34, 38, 0.08);
            border: 1px solid rgba(155, 34, 38, 0.25);
            color: #9b2226;
            font-size: 14px;
        }

        .cart-kind-note {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid var(--glass-border);
            background: var(--accent-2);
            color: var(--text);
        }

        .cart-kind-note.preorder {
            border-color: rgba(19, 63, 76, 0.2);
        }

        .checkout-btn.is-disabled {
            pointer-events: none;
            opacity: 0.55;
            cursor: not-allowed;
        }

        .cart-container {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 24px;
            align-items: start;
        }

        /* Cart Items */
        .cart-items {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .cart-item {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 16px;
            display: grid;
            grid-template-columns: 120px 1fr auto 180px;
            gap: 16px;
            align-items: start;
            transition: box-shadow 0.2s ease;
        }

        .cart-item:hover {
            box-shadow: var(--shadow);
        }

        .cart-item-image {
            width: 120px;
            height: 120px;
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--bg);
        }

        .cart-item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cart-item-details {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .cart-item-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
            color: var(--text);
        }

        .cart-item-brand {
            font-size: 13px;
            color: var(--muted);
            margin: 0;
        }

        .cart-item-options {
            font-size: 13px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .option-label {
            font-weight: 500;
        }

        .option-separator {
            color: var(--muted);
        }

        .cart-item-price {
            font-size: 16px;
            font-weight: 700;
            color: var(--accent);
            margin-top: 4px;
        }

        .cart-item-note {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
        }

        .cart-item-note .badge {
            background: var(--accent);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        .note-text {
            font-size: 12px;
            color: var(--muted);
        }

        .cart-item-controls {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: flex-end;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            overflow: hidden;
            background: white;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: var(--text);
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }

        .quantity-btn:hover:not(:disabled) {
            background: var(--accent-2);
        }

        .quantity-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .cart-container-empty {
            display: block;
        }

        .quantity-input {
            width: 40px;
            height: 32px;
            text-align: center;
            border: none;
            border-left: 1px solid var(--glass-border);
            border-right: 1px solid var(--glass-border);
            font-size: 14px;
            font-weight: 600;
            background: white;
            -moz-appearance: textfield;
        }

        .quantity-input::-webkit-outer-spin-button,
        .quantity-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .remove-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--glass-border);
            border-radius: 6px;
            background: white;
            color: var(--muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .remove-btn:hover {
            background: #fee;
            border-color: #fcc;
            color: #c33;
        }

        .cart-item-total {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .total-label {
            font-size: 12px;
            color: var(--muted);
        }

        .total-price {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
        }

        /* Order Summary */
        .cart-summary {
            position: sticky;
            top: 20px;
        }

        .summary-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 20px;
            position: sticky;
            top: 20px;
        }

        .summary-card h2 {
            font-size: 20px;
            font-weight: 600;
            margin: 0 0 20px 0;
            color: var(--text);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 14px;
            color: var(--text);
        }

        .summary-row.discount {
            color: var(--success);
        }

        .discount-amount {
            color: var(--success);
            font-weight: 600;
        }

        .summary-divider {
            height: 1px;
            background: var(--glass-border);
            margin: 12px 0;
        }

        .summary-row.total {
            font-size: 18px;
            font-weight: 700;
            padding-top: 8px;
        }

        .total-amount {
            color: var(--accent);
            font-size: 20px;
        }

        .checkout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-sizing: border-box;
            width: 100%;
            margin-top: 16px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
        }

        .continue-shopping-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-sizing: border-box;
            width: 100%;
            margin-top: 8px;
        }

        .shipping-info,
        .secure-payment {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 12px;
            background: var(--bg);
            border-radius: 6px;
            font-size: 13px;
            color: var(--text);
        }

        .shipping-info svg,
        .secure-payment svg {
            color: var(--accent);
            flex-shrink: 0;
        }

        .shipping-info strong,
        .secure-payment strong {
            display: block;
            margin-bottom: 2px;
        }

        /* Empty Cart State */
        .empty-cart {
            text-align: center;
            padding: 60px 20px;
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
        }

        .empty-cart svg {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            color: var(--muted);
        }

        .empty-cart h2 {
            font-size: 24px;
            margin: 0 0 12px 0;
            color: var(--text);
        }

        .empty-cart p {
            color: var(--muted);
            margin: 0 0 24px 0;
        }

        /* Mobile Responsive */
        @media (max-width: 900px) {
            .cart-container {
                grid-template-columns: 1fr;
            }

            .cart-item {
                grid-template-columns: 100px 1fr;
                gap: 12px;
            }

            .cart-item-controls {
                grid-column: 1 / -1;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding-top: 12px;
                border-top: 1px solid var(--glass-border);
            }

            .cart-item-total {
                grid-column: 1 / -1;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding-top: 8px;
            }

            .summary-card {
                position: static;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cartPage = document.querySelector('.cart-page');
            if (!cartPage) return;

            const cartUrl = <?php echo json_encode($userBase . 'cart.php'); ?>;

            function formatMmk(amount) {
                return 'MMK ' + Number(amount || 0).toLocaleString('en-US');
            }

            function postCart(action, payload) {
                const body = new URLSearchParams(Object.assign({ action: action }, payload));
                return fetch(cartUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body,
                    credentials: 'same-origin'
                }).then(function(res) { return res.json(); });
            }

            function updateLineTotal(cartItem, qty) {
                const unitPrice = Number(cartItem.dataset.unitPrice || 0);
                const lineEl = cartItem.querySelector('.cart-item-total .total-price');
                if (lineEl) lineEl.textContent = formatMmk(unitPrice * qty);
            }

            function syncQtyButtons(cartItem, qty) {
                const maxQty = Number(cartItem.dataset.maxQty || 10);
                const minus = cartItem.querySelector('.quantity-btn.minus');
                const plus = cartItem.querySelector('.quantity-btn.plus');
                if (minus) minus.disabled = qty <= 1;
                if (plus) plus.disabled = qty >= maxQty;
            }

            function applyTotals(totals) {
                if (!totals) return;
                const qty = Number(totals.total_qty || 0);
                const countEl = document.querySelector('[data-cart-count]');
                if (countEl) {
                    countEl.textContent = qty + ' ' + (qty === 1 ? 'item' : 'items');
                }
                const labelEl = document.querySelector('[data-summary-label]');
                if (labelEl) {
                    labelEl.textContent = 'Subtotal (' + qty + ' ' + (qty === 1 ? 'item' : 'items') + ')';
                }
                const subtotalEl = document.querySelector('[data-summary-subtotal]');
                if (subtotalEl) subtotalEl.textContent = formatMmk(totals.subtotal);
                const shippingEl = document.querySelector('[data-summary-shipping]');
                if (shippingEl) {
                    shippingEl.textContent = Number(totals.shipping) > 0 ? formatMmk(totals.shipping) : 'Free';
                }
                const totalEl = document.querySelector('[data-summary-total]');
                if (totalEl) totalEl.textContent = formatMmk(totals.total);
            }

            function showEmptyCart() {
                window.location.reload();
            }

            document.querySelectorAll('.quantity-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const cartItem = this.closest('.cart-item');
                    if (!cartItem) return;
                    const input = cartItem.querySelector('.quantity-input');
                    const cartItemId = cartItem.dataset.cartItemId;
                    const maxQty = Number(cartItem.dataset.maxQty || 10);
                    let qty = parseInt(input.value, 10) || 1;

                    if (this.classList.contains('plus') && qty < maxQty) qty += 1;
                    else if (this.classList.contains('minus') && qty > 1) qty -= 1;
                    else return;

                    postCart('update', { cart_item_id: cartItemId, qty: qty }).then(function(data) {
                        if (!data.ok) {
                            alert(data.message || 'Could not update quantity.');
                            return;
                        }
                        input.value = qty;
                        syncQtyButtons(cartItem, qty);
                        updateLineTotal(cartItem, qty);
                        applyTotals(data.totals);
                    }).catch(function() {
                        alert('Could not update quantity. Please try again.');
                    });
                });
            });

            document.querySelectorAll('.remove-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const cartItem = this.closest('.cart-item');
                    if (!cartItem) return;
                    const cartItemId = cartItem.dataset.cartItemId;

                    postCart('remove', { cart_item_id: cartItemId }).then(function(data) {
                        if (!data.ok) {
                            alert(data.message || 'Could not remove item.');
                            return;
                        }
                        cartItem.style.opacity = '0';
                        cartItem.style.transform = 'translateX(-20px)';
                        cartItem.style.transition = 'all 0.3s ease';
                        setTimeout(function() {
                            cartItem.remove();
                            if (!document.querySelector('.cart-item')) {
                                showEmptyCart();
                            } else {
                                applyTotals(data.totals);
                            }
                        }, 300);
                    }).catch(function() {
                        alert('Could not remove item. Please try again.');
                    });
                });
            });
        });
    </script>
</body>
</html>

