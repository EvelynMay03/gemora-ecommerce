<?php

require_once __DIR__ . '/cart_lib.php';
require_once __DIR__ . '/address_lib.php';

function checkout_ensure_schema(PDO $pdo): void
{
    cart_ensure_schema($pdo);

    static $ordersChecked = false;
    if ($ordersChecked) {
        return;
    }

    try {
        $pdo->query('SELECT shipping_address FROM orders LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN shipping_address TEXT NULL AFTER shipping');
        } catch (PDOException $ignored) {
            // Column may already exist with different migration state.
        }
    }

    try {
        $pdo->query('SELECT payment_screenshot FROM orders LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN payment_screenshot VARCHAR(255) NULL AFTER payment_method');
        } catch (PDOException $ignored) {
            // Column may already exist with different migration state.
        }
    }

    try {
        $pdo->query('SELECT order_kind FROM orders LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE orders ADD COLUMN order_kind ENUM('instock', 'preorder') NULL AFTER status");
        } catch (PDOException $ignored) {
            // Column may already exist with different migration state.
        }
    }

    try {
        $pdo->query('SELECT order_number FROM orders LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE orders ADD COLUMN order_number VARCHAR(20) NULL UNIQUE AFTER order_kind');
        } catch (PDOException $ignored) {
            // Column may already exist with different migration state.
        }
    }

    checkout_backfill_order_numbers($pdo);

    $ordersChecked = true;
}

/**
 * @return 'instock'|'preorder'
 */
function checkout_infer_order_kind(PDO $pdo, int $orderId): string
{
    $stmt = $pdo->prepare('
        SELECT v.stock_qty, v.preorder_flag
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        WHERE oi.order_id = ?
    ');
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return 'instock';
    }

    foreach ($rows as $row) {
        $isPreorderLine = (int) ($row['stock_qty'] ?? 0) <= 0
            && (int) ($row['preorder_flag'] ?? 0) === 1;
        if (!$isPreorderLine) {
            return 'instock';
        }
    }

    return 'preorder';
}

function checkout_backfill_order_numbers(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    try {
        $pdo->query('SELECT order_number FROM orders LIMIT 1');
    } catch (PDOException $e) {
        return;
    }

    $missing = $pdo->query('
        SELECT order_id
        FROM orders
        WHERE order_number IS NULL OR order_number = ""
        ORDER BY order_id ASC
    ')->fetchAll();

    if ($missing === []) {
        $backfilled = true;
        return;
    }

    $goNext = 1;
    $gpNext = 1;

    $maxGo = $pdo->query("
        SELECT order_number
        FROM orders
        WHERE order_number LIKE 'GO%'
        ORDER BY CAST(SUBSTRING(order_number, 3) AS UNSIGNED) DESC
        LIMIT 1
    ")->fetchColumn();
    if ($maxGo && preg_match('/^GO(\d+)$/', (string) $maxGo, $matches)) {
        $goNext = (int) $matches[1] + 1;
    }

    $maxGp = $pdo->query("
        SELECT order_number
        FROM orders
        WHERE order_number LIKE 'GP%'
        ORDER BY CAST(SUBSTRING(order_number, 3) AS UNSIGNED) DESC
        LIMIT 1
    ")->fetchColumn();
    if ($maxGp && preg_match('/^GP(\d+)$/', (string) $maxGp, $matches)) {
        $gpNext = (int) $matches[1] + 1;
    }

    $updateStmt = $pdo->prepare('
        UPDATE orders
        SET order_kind = ?, order_number = ?
        WHERE order_id = ?
    ');

    foreach ($missing as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }

        $kind = checkout_infer_order_kind($pdo, $orderId);
        if ($kind === 'preorder') {
            $orderNumber = 'GP' . str_pad((string) $gpNext, 5, '0', STR_PAD_LEFT);
            $gpNext++;
        } else {
            $orderNumber = 'GO' . str_pad((string) $goNext, 5, '0', STR_PAD_LEFT);
            $goNext++;
        }

        $updateStmt->execute([$kind, $orderNumber, $orderId]);
    }

    $backfilled = true;
}

/**
 * @param 'instock'|'preorder' $orderKind
 */
function checkout_allocate_order_number(PDO $pdo, string $orderKind): string
{
    $prefix = $orderKind === 'preorder' ? 'GP' : 'GO';
    $lockKey = $orderKind === 'preorder' ? 'gemora_order_gp' : 'gemora_order_go';
    $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 15)');
    $gotLock = (int) ($lockStmt ? $lockStmt->fetchColumn() : 0);

    if ($gotLock !== 1) {
        throw new RuntimeException('order_number_lock');
    }

    try {
        $stmt = $pdo->prepare('
            SELECT order_number
            FROM orders
            WHERE order_number LIKE ?
            ORDER BY CAST(SUBSTRING(order_number, 3) AS UNSIGNED) DESC
            LIMIT 1
        ');
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if ($last && preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
    }
}

function checkout_display_order_number(array $order): string
{
    $orderNumber = trim((string) ($order['order_number'] ?? ''));
    if ($orderNumber !== '') {
        return $orderNumber;
    }

    return '#' . (int) ($order['order_id'] ?? 0);
}

/**
 * @return array{ok: bool, path?: string, message?: string}
 */
function checkout_save_payment_screenshot(array $file): array
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Please upload payment screenshot.'];
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Payment screenshot upload failed.'];
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Payment screenshot is too large (max 5MB).'];
    }
    if (!class_exists('finfo')) {
        return ['ok' => false, 'message' => 'File info extension is not enabled.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Allowed screenshot types: JPEG, PNG, WebP.'];
    }

    $uploadsRoot = __DIR__ . '/../../uploads';
    $dir = $uploadsRoot . '/payments';
    if (!is_dir($uploadsRoot) && !@mkdir($uploadsRoot, 0775, true) && !is_dir($uploadsRoot)) {
        return ['ok' => false, 'message' => 'Could not create uploads folder.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Could not create payment screenshots folder.'];
    }

    $name = 'pay_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'message' => 'Could not save payment screenshot.'];
    }

    return ['ok' => true, 'path' => 'uploads/payments/' . $name];
}

/**
 * @return list<string>
 */
function checkout_payment_methods(): array
{
    return ['cod', 'kbz_pay', 'wave_pay', 'bank_transfer'];
}

function checkout_payment_label(string $method): string
{
    $labels = [
        'cod' => 'Cash on Delivery',
        'kbz_pay' => 'KBZ Pay',
        'wave_pay' => 'Wave Pay',
        'bank_transfer' => 'Bank Transfer',
    ];

    return $labels[$method] ?? $method;
}

/**
 * @return list<string>
 */
function checkout_myanmar_regions(): array
{
    return address_myanmar_regions();
}

/**
 * @param array<string, string> $input
 */
function checkout_format_shipping_address(array $input): string
{
    $lines = [
        'Recipient: ' . trim($input['recipient_name'] ?? ''),
        'Phone: ' . trim($input['phone'] ?? ''),
        'Email: ' . trim($input['email'] ?? ''),
        'Region: ' . trim($input['region'] ?? ''),
        'City/Township: ' . trim($input['city_township'] ?? ''),
    ];

    $building = trim($input['apartment_building'] ?? $input['address_extra'] ?? '');
    if ($building !== '') {
        $lines[] = 'Apartment/Building: ' . $building;
    }

    $street = trim($input['street_address'] ?? $input['address_line'] ?? '');
    if ($street !== '') {
        $lines[] = 'Street: ' . $street;
    }

    $notes = trim($input['order_notes'] ?? '');
    if ($notes !== '') {
        $lines[] = 'Notes: ' . $notes;
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, string> $input
 * @return array{ok: bool, message: string, errors?: array<string, string>}
 */
function checkout_validate_shipping(array $input): array
{
    $errors = [];

    if (trim($input['recipient_name'] ?? '') === '') {
        $errors['recipient_name'] = 'Enter recipient name.';
    }
    if (trim($input['phone'] ?? '') === '') {
        $errors['phone'] = 'Enter phone number.';
    }
    if (trim($input['email'] ?? '') === '' || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email.';
    }
    $addrValidation = address_validate($input);
    if (!$addrValidation['ok'] && !empty($addrValidation['errors'])) {
        $errors = array_merge($errors, $addrValidation['errors']);
    }

    $street = trim($input['street_address'] ?? $input['address_line'] ?? '');
    if ($street === '') {
        $errors['street_address'] = 'Enter street address.';
    }

    $payment = trim($input['payment_method'] ?? '');
    if (!in_array($payment, checkout_payment_methods(), true)) {
        $errors['payment_method'] = 'Select a payment method.';
    }
    if ($payment !== '' && $payment !== 'cod' && trim((string) ($input['payment_screenshot_path'] ?? '')) === '') {
        $errors['payment_screenshot'] = 'Upload payment screenshot for this payment method.';
    }

    if (!empty($errors)) {
        return ['ok' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok: bool, message: string, order_id?: int}
 */
function checkout_place_order(PDO $pdo, int $userId, array $shippingInput): array
{
    checkout_ensure_schema($pdo);

    $validation = checkout_validate_shipping($shippingInput);
    if (!$validation['ok']) {
        return $validation;
    }

    $cartItems = cart_fetch_items($pdo, $userId);
    if (empty($cartItems)) {
        return ['ok' => false, 'message' => 'Your cart is empty.'];
    }

    $mixCheck = cart_validate_cart_items($cartItems);
    if (!$mixCheck['ok']) {
        return $mixCheck;
    }

    foreach ($cartItems as $item) {
        $maxQty = cart_max_qty_for_variant($item);
        $requested = (int) ($item['qty'] ?? 0);
        if ($maxQty < 1) {
            return ['ok' => false, 'message' => '"' . ($item['title'] ?? 'An item') . '" is no longer available.'];
        }
        if ($requested > $maxQty) {
            return [
                'ok' => false,
                'message' => 'Only ' . $maxQty . ' left for "' . ($item['title'] ?? 'an item') . '". Update your cart and try again.',
            ];
        }
    }

    $totals = cart_calculate_totals($cartItems);
    $paymentMethod = trim($shippingInput['payment_method']);
    $shippingAddress = checkout_format_shipping_address($shippingInput);
    $orderKind = cart_cart_kind($cartItems) ?? 'instock';
    if ($orderKind !== 'instock' && $orderKind !== 'preorder') {
        $orderKind = 'instock';
    }

    try {
        $pdo->beginTransaction();

        $orderNumber = checkout_allocate_order_number($pdo, $orderKind);

        $orderStmt = $pdo->prepare('
            INSERT INTO orders (user_id, status, order_kind, order_number, total, payment_method, payment_screenshot, shipping, shipping_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $orderStmt->execute([
            $userId,
            'Pending',
            $orderKind,
            $orderNumber,
            $totals['total'],
            checkout_payment_label($paymentMethod),
            trim((string) ($shippingInput['payment_screenshot_path'] ?? '')) ?: null,
            $totals['shipping'],
            $shippingAddress,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('
            INSERT INTO order_items (order_id, variant_id, qty, unit_price)
            VALUES (?, ?, ?, ?)
        ');
        $stockStmt = $pdo->prepare('
            UPDATE variants SET stock_qty = stock_qty - ?
            WHERE variant_id = ? AND stock_qty >= ?
        ');

        foreach ($cartItems as $item) {
            $variantId = (int) $item['variant_id'];
            $qty = (int) $item['qty'];
            $unitPrice = (int) $item['unit_price'];

            $itemStmt->execute([$orderId, $variantId, $qty, $unitPrice]);

            $stock = (int) ($item['variant_stock'] ?? 0);
            $preorder = (int) ($item['preorder_flag'] ?? 0) === 1;
            if ($stock > 0 && !$preorder) {
                $stockStmt->execute([$qty, $variantId, $qty]);
                if ($stockStmt->rowCount() === 0) {
                    throw new RuntimeException('stock_conflict');
                }
            }
        }

        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);

        if (!empty($shippingInput['save_address'])) {
            address_save($pdo, $userId, [
                'region' => $shippingInput['region'] ?? '',
                'city_township' => $shippingInput['city_township'] ?? '',
                'apartment_building' => $shippingInput['apartment_building'] ?? $shippingInput['address_extra'] ?? '',
                'street_address' => $shippingInput['street_address'] ?? $shippingInput['address_line'] ?? '',
            ]);
        }

        $phone = trim($shippingInput['phone'] ?? '');
        if ($phone !== '') {
            $pdo->prepare('UPDATE users SET phone = ? WHERE user_id = ? AND (phone IS NULL OR phone = "")')
                ->execute([$phone, $userId]);
        }

        $pdo->commit();

        return ['ok' => true, 'message' => 'Order placed successfully.', 'order_id' => $orderId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getMessage() === 'stock_conflict') {
            return ['ok' => false, 'message' => 'Stock changed while checking out. Please review your cart.'];
        }
        if ($e->getMessage() === 'order_number_lock') {
            return ['ok' => false, 'message' => 'Could not assign an order number. Please try again.'];
        }
        error_log('Checkout failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not place your order. Please try again.'];
    }
}

/**
 * @return array<string, mixed>|null
 */
function checkout_fetch_order(PDO $pdo, int $userId, int $orderId): ?array
{
    checkout_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT order_id, user_id, status, order_kind, order_number, total, payment_method, payment_screenshot, shipping, shipping_address, created_at
        FROM orders
        WHERE order_id = ? AND user_id = ?
        LIMIT 1
    ');
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $itemsStmt = $pdo->prepare('
        SELECT oi.qty, oi.unit_price, p.title,
            v.size, v.color,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        WHERE oi.order_id = ?
    ');
    $itemsStmt->execute([$orderId]);
    $order['items'] = $itemsStmt->fetchAll();

    return $order;
}

function profile_order_status_class(string $status): string
{
    $map = [
        'Pending' => 'pending',
        'Confirmed' => 'processing',
        'Shipped' => 'processing',
        'Delivered' => 'delivered',
        'Returned' => 'returned',
        'Refunded' => 'refunded',
    ];

    return $map[$status] ?? 'pending';
}

function profile_order_tracking_message(string $status): string
{
    $messages = [
        'Pending' => 'Your order has been received and is waiting to be confirmed by our team.',
        'Confirmed' => 'Your order is confirmed and is being prepared for shipping.',
        'Shipped' => 'Your order is on the way and should arrive within a few business days.',
        'Delivered' => 'Your order has been delivered. We hope you love your purchase!',
        'Returned' => 'This order has been returned. Contact us if you need any assistance.',
        'Refunded' => 'This order has been refunded. Please allow time for the refund to appear.',
    ];

    return $messages[$status] ?? 'We are updating your order status. Please check back soon.';
}

/**
 * @return list<array<string, mixed>>
 */
function profile_fetch_user_orders(PDO $pdo, int $userId, int $limit = 50): array
{
    checkout_ensure_schema($pdo);

    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare('
        SELECT order_id, status, order_kind, order_number, total, payment_method, shipping, shipping_address, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY order_id DESC
        LIMIT ' . (int) $limit . '
    ');
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        return [];
    }

    $orderIds = array_values(array_filter(array_map(static function ($row) {
        return (int) ($row['order_id'] ?? 0);
    }, $orders), static fn (int $id): bool => $id > 0));

    if (empty($orderIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare('
        SELECT
            oi.order_id,
            oi.order_item_id,
            oi.qty,
            oi.unit_price,
            p.product_id,
            p.title,
            v.variant_id,
            v.size,
            v.color,
            v.preorder_flag,
            v.stock_qty,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name,
            (
                SELECT COALESCE(
                    (SELECT i.image FROM images i WHERE i.image_id = v.image_id LIMIT 1),
                    (
                        SELECT i2.image
                        FROM images i2
                        WHERE i2.variant_id = v.variant_id
                        ORDER BY i2.sort_order, i2.image_id
                        LIMIT 1
                    )
                )
            ) AS image_path
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        WHERE oi.order_id IN (' . $placeholders . ')
        ORDER BY oi.order_id DESC, oi.order_item_id ASC
    ');
    $itemsStmt->execute($orderIds);
    $allItems = $itemsStmt->fetchAll();

    $itemsByOrder = [];
    foreach ($allItems as $item) {
        $oid = (int) ($item['order_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (!isset($itemsByOrder[$oid])) {
            $itemsByOrder[$oid] = [];
        }
        $itemsByOrder[$oid][] = $item;
    }

    foreach ($orders as &$order) {
        $orderId = (int) ($order['order_id'] ?? 0);
        $order['items'] = $itemsByOrder[$orderId] ?? [];
    }
    unset($order);

    return $orders;
}

function profile_format_order_date($createdAt): string
{
    $timestamp = strtotime((string) $createdAt);
    if ($timestamp === false) {
        return '';
    }

    return date('M j, Y', $timestamp);
}

function profile_order_img_url(?string $path): string
{
    if ($path) {
        return ltrim((string) $path, '/');
    }

    return 'uploads/lace.jpg';
}

function profile_user_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    return ltrim($path, '/');
}

function profile_remove_user_image_file(?string $relativePath): void
{
    $relativePath = ltrim(trim((string) $relativePath), '/');
    if ($relativePath === '' || strpos($relativePath, 'uploads/avatars/') !== 0) {
        return;
    }

    $full = __DIR__ . '/../../' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/**
 * @return array{ok: bool, path?: string, message?: string}
 */
function profile_save_user_image(int $userId, array $file): array
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => 'Please choose a photo to upload.'];
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'Photo exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Photo is too large.',
            UPLOAD_ERR_PARTIAL => 'Photo upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded photo.',
            UPLOAD_ERR_EXTENSION => 'Photo upload was blocked by the server.',
        ];
        $code = (int) $file['error'];
        return ['ok' => false, 'message' => $uploadErrors[$code] ?? 'Photo upload failed.'];
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['ok' => false, 'message' => 'Photo is too large (max 2MB).'];
    }
    if (!class_exists('finfo')) {
        return ['ok' => false, 'message' => 'File info extension is not enabled.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'message' => 'Allowed photo types: JPEG, PNG, WebP.'];
    }

    $uploadsRoot = __DIR__ . '/../../uploads';
    $dir = $uploadsRoot . '/avatars';
    if (!is_dir($uploadsRoot) && !@mkdir($uploadsRoot, 0775, true) && !is_dir($uploadsRoot)) {
        return ['ok' => false, 'message' => 'Could not create uploads folder.'];
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'message' => 'Could not create profile photos folder.'];
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'message' => 'Profile photos folder is not writable.'];
    }

    $name = 'user_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'message' => 'Could not save profile photo.'];
    }

    return ['ok' => true, 'path' => 'uploads/avatars/' . $name];
}

/**
 * @return array{email_notifications: bool, sms_notifications: bool, marketing_emails: bool}
 */
function profile_default_preferences(): array
{
    return [
        'email_notifications' => true,
        'sms_notifications' => false,
        'marketing_emails' => true,
    ];
}

/**
 * @return array{email_notifications: bool, sms_notifications: bool, marketing_emails: bool}
 */
function profile_parse_preferences(?string $raw): array
{
    $defaults = profile_default_preferences();
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'email_notifications' => !empty($decoded['email_notifications']),
        'sms_notifications' => !empty($decoded['sms_notifications']),
        'marketing_emails' => !empty($decoded['marketing_emails']),
    ];
}

/**
 * @param array<string, mixed> $prefs
 */
function profile_encode_preferences(array $prefs): string
{
    $normalized = [
        'email_notifications' => !empty($prefs['email_notifications']),
        'sms_notifications' => !empty($prefs['sms_notifications']),
        'marketing_emails' => !empty($prefs['marketing_emails']),
    ];

    return json_encode($normalized, JSON_UNESCAPED_UNICODE) ?: '{}';
}
