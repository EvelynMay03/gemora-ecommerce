<?php

require_once __DIR__ . '/../../user/include/checkout_lib.php';

/**
 * @return list<string>
 */
function admin_order_statuses(): array
{
    return ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Returned', 'Refunded'];
}

/**
 * @param array{status?: string, q?: string, order_kind?: string, limit?: int, offset?: int} $filters
 * @return array{rows: list<array<string, mixed>>, total: int}
 */
function admin_orders_fetch_list(PDO $pdo, array $filters = []): array
{
    checkout_ensure_schema($pdo);

    $where = ['1=1'];
    $params = [];

    $orderKind = trim((string) ($filters['order_kind'] ?? ''));
    if ($orderKind === 'instock') {
        $where[] = "(
            o.order_kind = 'instock'
            OR (
                o.order_kind IS NULL
                AND (o.order_number LIKE 'GO%' OR o.order_number IS NULL OR o.order_number = '')
            )
        )";
    } elseif ($orderKind === 'preorder') {
        $where[] = "(
            o.order_kind = 'preorder'
            OR (o.order_kind IS NULL AND o.order_number LIKE 'GP%')
        )";
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && in_array($status, admin_order_statuses(), true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        if (preg_match('/^(GO|GP)\d+$/i', $q)) {
            $where[] = 'o.order_number = ?';
            $params[] = strtoupper($q);
        } elseif (ctype_digit($q)) {
            $where[] = 'o.order_id = ?';
            $params[] = (int) $q;
        } else {
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders o
        INNER JOIN users u ON u.user_id = o.user_id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $limit = max(1, min(100, (int) ($filters['limit'] ?? 25)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $listStmt = $pdo->prepare("
        SELECT
            o.order_id,
            o.order_kind,
            o.order_number,
            o.user_id,
            o.status,
            o.total,
            o.payment_method,
            o.payment_screenshot,
            o.shipping,
            o.shipping_address,
            o.created_at,
            u.name AS customer_name,
            u.email AS customer_email,
            u.phone AS customer_phone,
            (
                SELECT COALESCE(SUM(oi.qty), 0)
                FROM order_items oi
                WHERE oi.order_id = o.order_id
            ) AS item_qty
        FROM orders o
        INNER JOIN users u ON u.user_id = o.user_id
        WHERE {$whereSql}
        ORDER BY o.order_id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $listStmt->execute($params);

    return [
        'rows' => $listStmt->fetchAll(),
        'total' => $total,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function admin_orders_fetch_detail(PDO $pdo, int $orderId): ?array
{
    checkout_ensure_schema($pdo);

    $stmt = $pdo->prepare('
        SELECT
            o.order_id,
            o.order_kind,
            o.order_number,
            o.user_id,
            o.status,
            o.total,
            o.payment_method,
            o.payment_screenshot,
            o.shipping,
            o.shipping_address,
            o.created_at,
            u.name AS customer_name,
            u.email AS customer_email,
            u.phone AS customer_phone
        FROM orders o
        INNER JOIN users u ON u.user_id = o.user_id
        WHERE o.order_id = ?
        LIMIT 1
    ');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $itemsStmt = $pdo->prepare('
        SELECT
            oi.order_item_id,
            oi.qty,
            oi.unit_price,
            p.product_id,
            p.title,
            v.variant_id,
            v.size,
            v.color,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name,
            (
                SELECT i.image
                FROM images i
                WHERE i.variant_id = v.variant_id
                ORDER BY i.sort_order, i.image_id
                LIMIT 1
            ) AS image_path
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id
    ');
    $itemsStmt->execute([$orderId]);
    $order['items'] = $itemsStmt->fetchAll();

    return $order;
}

/**
 * @return array{ok: bool, message: string}
 */
function admin_orders_update_status(PDO $pdo, int $orderId, string $status): array
{
    checkout_ensure_schema($pdo);

    if (!in_array($status, admin_order_statuses(), true)) {
        return ['ok' => false, 'message' => 'Invalid order status.'];
    }

    $check = $pdo->prepare('SELECT order_id FROM orders WHERE order_id = ? LIMIT 1');
    $check->execute([$orderId]);
    if (!$check->fetch()) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }

    $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?')->execute([$status, $orderId]);

    return ['ok' => true, 'message' => 'Order status updated.'];
}

/**
 * @return array{pending: int, confirmed: int, shipped: int, total: int}
 */
function admin_orders_summary_counts(PDO $pdo, ?string $orderKind = null): array
{
    checkout_ensure_schema($pdo);

    $where = '';
    if ($orderKind === 'instock') {
        $where = "WHERE (
            order_kind = 'instock'
            OR (
                order_kind IS NULL
                AND (order_number LIKE 'GO%' OR order_number IS NULL OR order_number = '')
            )
        )";
    } elseif ($orderKind === 'preorder') {
        $where = "WHERE (
            order_kind = 'preorder'
            OR (order_kind IS NULL AND order_number LIKE 'GP%')
        )";
    }

    $stmt = $pdo->query('
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = "Pending" THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = "Confirmed" THEN 1 ELSE 0 END) AS confirmed_count,
            SUM(CASE WHEN status = "Shipped" THEN 1 ELSE 0 END) AS shipped_count
        FROM orders
        ' . $where . '
    ');
    $row = $stmt->fetch() ?: [];

    return [
        'total' => (int) ($row['total_orders'] ?? 0),
        'pending' => (int) ($row['pending_count'] ?? 0),
        'confirmed' => (int) ($row['confirmed_count'] ?? 0),
        'shipped' => (int) ($row['shipped_count'] ?? 0),
    ];
}

function admin_order_img_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '../uploads/lace.jpg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return '../' . ltrim($path, '/');
}

function admin_order_format_date($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime((string) $value);

    return $ts !== false ? date('M j, Y', $ts) : (string) $value;
}

function admin_order_format_money(int $amount): string
{
    return 'MMK ' . number_format($amount);
}

function admin_order_status_class(string $status): string
{
    $map = [
        'Pending' => 'pending',
        'Confirmed' => 'confirmed',
        'Shipped' => 'shipped',
        'Delivered' => 'delivered',
        'Returned' => 'returned',
        'Refunded' => 'refunded',
    ];

    return $map[$status] ?? 'pending';
}
