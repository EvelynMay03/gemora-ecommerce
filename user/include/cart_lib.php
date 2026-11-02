<?php

function cart_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cart_items (
            cart_item_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            variant_id INT NOT NULL,
            qty INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cart_user_variant (user_id, variant_id),
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (variant_id) REFERENCES variants(variant_id) ON DELETE CASCADE
        )
    ');
    $done = true;
}

function cart_img_url(?string $path): string
{
    if ($path) {
        return gemora_url(ltrim((string) $path, '/'));
    }

    return gemora_url('uploads/lace.jpg');
}

/**
 * @return list<array<string, mixed>>
 */
function cart_fetch_items(PDO $pdo, int $userId): array
{
    cart_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT
            ci.cart_item_id,
            ci.qty,
            ci.variant_id,
            v.size,
            v.color,
            v.preorder_flag,
            v.stock_qty AS variant_stock,
            p.product_id,
            p.title,
            p.price AS unit_price,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name,
            (
                SELECT i3.image
                FROM variants v3
                INNER JOIN images i3 ON i3.variant_id = v3.variant_id
                WHERE v3.product_id = v.product_id
                  AND (
                    (v.color IS NULL OR v.color = "") AND (v3.color IS NULL OR v3.color = "")
                    OR v3.color = v.color
                  )
                ORDER BY
                  CASE WHEN v3.variant_id = v.variant_id THEN 0 ELSE 1 END,
                  i3.sort_order,
                  i3.image_id
                LIMIT 1
            ) AS image_path
        FROM cart_items ci
        INNER JOIN variants v ON v.variant_id = ci.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        WHERE ci.user_id = ?
        ORDER BY ci.cart_item_id DESC
    ');
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function cart_max_qty_for_variant(array $variant): int
{
    $stock = (int) ($variant['variant_stock'] ?? $variant['stock_qty'] ?? 0);
    $preorder = (int) ($variant['preorder_flag'] ?? $variant['preorder'] ?? 0) === 1;

    if ($stock > 0) {
        return min(10, $stock);
    }
    if ($preorder) {
        return 10;
    }

    return 0;
}

function cart_item_is_preorder(array $item): bool
{
    $stock = (int) ($item['variant_stock'] ?? $item['stock_qty'] ?? 0);
    $preorder = (int) ($item['preorder_flag'] ?? $item['preorder'] ?? 0) === 1;

    return $stock <= 0 && $preorder;
}

/**
 * @return 'instock'|'preorder'|null
 */
function cart_cart_kind(array $items): ?string
{
    if ($items === []) {
        return null;
    }

    $kind = null;
    foreach ($items as $item) {
        $itemKind = cart_item_is_preorder($item) ? 'preorder' : 'instock';
        if ($kind === null) {
            $kind = $itemKind;
            continue;
        }
        if ($kind !== $itemKind) {
            return null;
        }
    }

    return $kind;
}

/**
 * @return array{ok: bool, message: string}
 */
function cart_validate_cart_items(array $items): array
{
    if ($items === []) {
        return ['ok' => true, 'message' => ''];
    }

    $kind = cart_cart_kind($items);
    if ($kind !== null) {
        return ['ok' => true, 'message' => ''];
    }

    return [
        'ok' => false,
        'message' => 'Your cart mixes in-stock and preorder items. Please remove one group before checkout.',
    ];
}

/**
 * @param list<array<string, mixed>> $existingItems
 * @param array<string, mixed> $newVariant
 * @return array{ok: bool, message: string}
 */
function cart_validate_mixing(array $existingItems, array $newVariant): array
{
    if ($existingItems === []) {
        return ['ok' => true, 'message' => ''];
    }

    $newIsPreorder = cart_item_is_preorder($newVariant);
    foreach ($existingItems as $item) {
        if (cart_item_is_preorder($item) === $newIsPreorder) {
            continue;
        }

        if ($newIsPreorder) {
            return [
                'ok' => false,
                'message' => 'Preorder items must be ordered separately. Checkout or remove in-stock items from your cart first.',
            ];
        }

        return [
            'ok' => false,
            'message' => 'In-stock items must be ordered separately. Checkout or remove preorder items from your cart first.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok: bool, message: string}
 */
function cart_add_item(PDO $pdo, int $userId, int $variantId, int $qty = 1): array
{
    cart_ensure_schema($pdo);

    if ($variantId <= 0 || $qty < 1) {
        return ['ok' => false, 'message' => 'Invalid item.'];
    }

    $vStmt = $pdo->prepare('SELECT variant_id, stock_qty, preorder_flag FROM variants WHERE variant_id = ?');
    $vStmt->execute([$variantId]);
    $variant = $vStmt->fetch();
    if (!$variant) {
        return ['ok' => false, 'message' => 'Variant not found.'];
    }

    $maxQty = cart_max_qty_for_variant($variant);
    if ($maxQty < 1) {
        return ['ok' => false, 'message' => 'This item is out of stock.'];
    }

    $qty = min($qty, $maxQty);

    $existingItems = cart_fetch_items($pdo, $userId);
    $otherItems = array_values(array_filter($existingItems, static function (array $item) use ($variantId): bool {
        return (int) ($item['variant_id'] ?? 0) !== $variantId;
    }));
    $mixCheck = cart_validate_mixing($otherItems, [
        'variant_stock' => (int) ($variant['stock_qty'] ?? 0),
        'preorder_flag' => (int) ($variant['preorder_flag'] ?? 0),
    ]);
    if (!$mixCheck['ok']) {
        return $mixCheck;
    }

    $existing = $pdo->prepare('SELECT cart_item_id, qty FROM cart_items WHERE user_id = ? AND variant_id = ?');
    $existing->execute([$userId, $variantId]);
    $row = $existing->fetch();

    if ($row) {
        $newQty = min((int) $row['qty'] + $qty, $maxQty);
        $pdo->prepare('UPDATE cart_items SET qty = ? WHERE cart_item_id = ? AND user_id = ?')
            ->execute([$newQty, (int) $row['cart_item_id'], $userId]);
    } else {
        $pdo->prepare('INSERT INTO cart_items (user_id, variant_id, qty) VALUES (?, ?, ?)')
            ->execute([$userId, $variantId, $qty]);
    }

    return ['ok' => true, 'message' => 'Added to cart.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function cart_update_qty(PDO $pdo, int $userId, int $cartItemId, int $qty): array
{
    cart_ensure_schema($pdo);

    if ($cartItemId <= 0 || $qty < 1) {
        return ['ok' => false, 'message' => 'Invalid quantity.'];
    }

    $stmt = $pdo->prepare('
        SELECT ci.cart_item_id, ci.qty, v.stock_qty AS variant_stock, v.preorder_flag
        FROM cart_items ci
        INNER JOIN variants v ON v.variant_id = ci.variant_id
        WHERE ci.cart_item_id = ? AND ci.user_id = ?
    ');
    $stmt->execute([$cartItemId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Item not found in your cart.'];
    }

    $maxQty = cart_max_qty_for_variant($row);
    if ($maxQty < 1) {
        return ['ok' => false, 'message' => 'This item is no longer available.'];
    }

    $qty = min($qty, $maxQty);
    $pdo->prepare('UPDATE cart_items SET qty = ? WHERE cart_item_id = ? AND user_id = ?')
        ->execute([$qty, $cartItemId, $userId]);

    return ['ok' => true, 'message' => 'Quantity updated.'];
}

/**
 * @return array{ok: bool, message: string}
 */
function cart_remove_item(PDO $pdo, int $userId, int $cartItemId): array
{
    cart_ensure_schema($pdo);

    if ($cartItemId <= 0) {
        return ['ok' => false, 'message' => 'Invalid item.'];
    }

    $stmt = $pdo->prepare('DELETE FROM cart_items WHERE cart_item_id = ? AND user_id = ?');
    $stmt->execute([$cartItemId, $userId]);

    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Item not found in your cart.'];
    }

    return ['ok' => true, 'message' => 'Item removed.'];
}

/**
 * @param list<array<string, mixed>> $items
 * @return array{subtotal: int, total_qty: int, shipping: int, total: int}
 */
function cart_calculate_totals(array $items): array
{
    $subtotal = 0;
    $totalQty = 0;
    foreach ($items as $item) {
        $qty = (int) ($item['qty'] ?? 0);
        $unit = (int) ($item['unit_price'] ?? 0);
        $subtotal += $qty * $unit;
        $totalQty += $qty;
    }

    $shipping = ($subtotal > 0 && $subtotal < 100000) ? 5000 : 0;
    $total = $subtotal + $shipping;

    return [
        'subtotal' => $subtotal,
        'total_qty' => $totalQty,
        'shipping' => $shipping,
        'total' => $total,
    ];
}

function cart_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
