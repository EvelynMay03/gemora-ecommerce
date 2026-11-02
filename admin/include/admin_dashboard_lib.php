<?php

require_once __DIR__ . '/admin_orders_lib.php';

/**
 * Orders that count toward sales and revenue figures.
 */
function admin_dashboard_countable_statuses(): string
{
    return "'Pending', 'Confirmed', 'Shipped', 'Delivered'";
}

/**
 * Orders that count as realized revenue.
 */
function admin_dashboard_revenue_statuses(): string
{
    return "'Confirmed', 'Shipped', 'Delivered'";
}

/**
 * @return array{from: string, to: string}|null
 */
function admin_dashboard_parse_date_range(?string $from, ?string $to): ?array
{
    $from = trim((string) $from);
    $to = trim((string) $to);

    if ($from === '' && $to === '') {
        return null;
    }
    if ($from === '') {
        $from = $to;
    }
    if ($to === '') {
        $to = $from;
    }

    $fromDate = DateTimeImmutable::createFromFormat('Y-m-d', $from);
    $toDate = DateTimeImmutable::createFromFormat('Y-m-d', $to);
    if (
        !$fromDate || !$toDate
        || $fromDate->format('Y-m-d') !== $from
        || $toDate->format('Y-m-d') !== $to
    ) {
        return null;
    }

    if ($fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    return [
        'from' => $fromDate->format('Y-m-d'),
        'to' => $toDate->format('Y-m-d'),
    ];
}

/**
 * @param array{from: string, to: string} $range
 */
function admin_dashboard_format_date_range_label(array $range): string
{
    $from = DateTimeImmutable::createFromFormat('Y-m-d', $range['from']);
    $to = DateTimeImmutable::createFromFormat('Y-m-d', $range['to']);
    if (!$from || !$to) {
        return '';
    }

    if ($range['from'] === $range['to']) {
        return $from->format('M j, Y');
    }

    if ($from->format('Y') === $to->format('Y')) {
        return $from->format('M j') . ' – ' . $to->format('M j, Y');
    }

    return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
}

/**
 * @return array{from: string, to: string}
 */
function admin_dashboard_current_month_to_date_range(): array
{
    $today = new DateTimeImmutable('today');

    return [
        'from' => $today->format('Y-m-01'),
        'to' => $today->format('Y-m-d'),
    ];
}

/**
 * @param array{from: string, to: string} $range
 * @return array{from: string, to: string}
 */
function admin_dashboard_previous_month_same_period_range(array $range): array
{
    $from = DateTimeImmutable::createFromFormat('Y-m-d', $range['from']);
    $to = DateTimeImmutable::createFromFormat('Y-m-d', $range['to']);
    if (!$from || !$to) {
        return $range;
    }

    return [
        'from' => $from->modify('-1 month')->format('Y-m-d'),
        'to' => $to->modify('-1 month')->format('Y-m-d'),
    ];
}

function admin_dashboard_revenue_for_range(PDO $pdo, array $range): int
{
    $revenueStatuses = admin_dashboard_revenue_statuses();
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE status IN ({$revenueStatuses})
          AND DATE(created_at) >= ?
          AND DATE(created_at) <= ?
    ");
    $stmt->execute([$range['from'], $range['to']]);

    return (int) $stmt->fetchColumn();
}

function admin_dashboard_format_money(int $amount): string
{
    return admin_order_format_money($amount);
}

/**
 * @return array{text: string, class: string}|null
 */
function admin_dashboard_change_note(int $current, int $previous, string $suffix = 'from last month'): ?array
{
    if ($previous === 0) {
        if ($current === 0) {
            return null;
        }

        return ['text' => 'New this month', 'class' => 'positive'];
    }

    $pct = (int) round((($current - $previous) / $previous) * 100);
    $sign = $pct > 0 ? '+' : '';

    return [
        'text' => $sign . $pct . '% ' . $suffix,
        'class' => $pct >= 0 ? 'positive' : 'negative',
    ];
}

/**
 * @return array{
 *   total_sales: int,
 *   revenue: int,
 *   low_stock: int,
 *   preorders: int,
 *   preorders_today: int,
 *   sales_change: array{text: string, class: string}|null,
 *   revenue_change: array{text: string, class: string}|null,
 *   date_range_label: string|null,
 *   revenue_period_label: string
 * }
 * @param array{from: string, to: string}|null $dateRange
 */
function admin_dashboard_kpis(PDO $pdo, ?array $dateRange = null): array
{
    checkout_ensure_schema($pdo);

    $countable = admin_dashboard_countable_statuses();
    $revenueRange = admin_dashboard_current_month_to_date_range();
    $revenue = admin_dashboard_revenue_for_range($pdo, $revenueRange);
    $revenuePeriodLabel = admin_dashboard_format_date_range_label($revenueRange);
    $previousRevenueRange = admin_dashboard_previous_month_same_period_range($revenueRange);
    $previousMtdRevenue = admin_dashboard_revenue_for_range($pdo, $previousRevenueRange);
    $revenueChange = admin_dashboard_change_note($revenue, $previousMtdRevenue, 'vs same period last month');

    if ($dateRange !== null) {
        $salesStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total), 0)
            FROM orders
            WHERE status IN ({$countable})
              AND DATE(created_at) >= ?
              AND DATE(created_at) <= ?
        ");
        $salesStmt->execute([$dateRange['from'], $dateRange['to']]);
        $totalSales = (int) $salesStmt->fetchColumn();

        $lowStockStmt = $pdo->query('
            SELECT COUNT(*) FROM (
                SELECT p.product_id
                FROM products p
                WHERE EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.product_id)
                  AND COALESCE((SELECT SUM(v.stock_qty) FROM variants v WHERE v.product_id = p.product_id), 0) <= 5
            ) t
        ');
        $lowStock = (int) $lowStockStmt->fetchColumn();

        $preorderStmt = $pdo->query('
            SELECT COUNT(DISTINCT o.order_id)
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.order_id
            INNER JOIN variants v ON v.variant_id = oi.variant_id
            WHERE v.preorder_flag = 1
              AND o.status NOT IN ("Returned", "Refunded")
        ');
        $preorders = (int) $preorderStmt->fetchColumn();

        $preordersTodayStmt = $pdo->query('
            SELECT COUNT(DISTINCT o.order_id)
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.order_id
            INNER JOIN variants v ON v.variant_id = oi.variant_id
            WHERE v.preorder_flag = 1
              AND o.status NOT IN ("Returned", "Refunded")
              AND o.created_at = CURDATE()
        ');
        $preordersToday = (int) $preordersTodayStmt->fetchColumn();

        return [
            'total_sales' => $totalSales,
            'revenue' => $revenue,
            'low_stock' => $lowStock,
            'preorders' => $preorders,
            'preorders_today' => $preordersToday,
            'sales_change' => null,
            'revenue_change' => $revenueChange,
            'date_range_label' => admin_dashboard_format_date_range_label($dateRange),
            'revenue_period_label' => $revenuePeriodLabel,
        ];
    }

    $salesStmt = $pdo->query("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE status IN ({$countable})
    ");
    $totalSales = (int) $salesStmt->fetchColumn();

    $lowStockStmt = $pdo->query('
        SELECT COUNT(*) FROM (
            SELECT p.product_id
            FROM products p
            WHERE EXISTS (SELECT 1 FROM variants v WHERE v.product_id = p.product_id)
              AND COALESCE((SELECT SUM(v.stock_qty) FROM variants v WHERE v.product_id = p.product_id), 0) <= 5
        ) t
    ');
    $lowStock = (int) $lowStockStmt->fetchColumn();

    $preorderStmt = $pdo->query('
        SELECT COUNT(DISTINCT o.order_id)
        FROM orders o
        INNER JOIN order_items oi ON oi.order_id = o.order_id
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        WHERE v.preorder_flag = 1
          AND o.status NOT IN ("Returned", "Refunded")
    ');
    $preorders = (int) $preorderStmt->fetchColumn();

    $preordersTodayStmt = $pdo->query('
        SELECT COUNT(DISTINCT o.order_id)
        FROM orders o
        INNER JOIN order_items oi ON oi.order_id = o.order_id
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        WHERE v.preorder_flag = 1
          AND o.status NOT IN ("Returned", "Refunded")
          AND o.created_at = CURDATE()
    ');
    $preordersToday = (int) $preordersTodayStmt->fetchColumn();

    $currentMonthSales = (int) $pdo->query("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE status IN ({$countable})
          AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND created_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
    ")->fetchColumn();

    $previousMonthSales = (int) $pdo->query("
        SELECT COALESCE(SUM(total), 0)
        FROM orders
        WHERE status IN ({$countable})
          AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
          AND created_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')
    ")->fetchColumn();

    return [
        'total_sales' => $totalSales,
        'revenue' => $revenue,
        'low_stock' => $lowStock,
        'preorders' => $preorders,
        'preorders_today' => $preordersToday,
        'sales_change' => admin_dashboard_change_note($currentMonthSales, $previousMonthSales),
        'revenue_change' => $revenueChange,
        'date_range_label' => null,
        'revenue_period_label' => $revenuePeriodLabel,
    ];
}

/**
 * @param array{from: string, to: string} $dateRange
 * @return array{labels: list<string>, values: list<int>}
 */
function admin_dashboard_sales_series_for_range(PDO $pdo, array $dateRange): array
{
    checkout_ensure_schema($pdo);

    $countable = admin_dashboard_countable_statuses();
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total), 0) AS total_amount
        FROM orders
        WHERE status IN ({$countable})
          AND DATE(created_at) >= ?
          AND DATE(created_at) <= ?
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$dateRange['from'], $dateRange['to']]);

    $byDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $byDate[(string) $row['sale_date']] = (int) $row['total_amount'];
    }

    $start = new DateTimeImmutable($dateRange['from']);
    $end = new DateTimeImmutable($dateRange['to']);
    $dayCount = (int) $start->diff($end)->days + 1;
    $labels = [];
    $values = [];

    for ($current = $start; $current <= $end; $current = $current->modify('+1 day')) {
        $key = $current->format('Y-m-d');
        $values[] = $byDate[$key] ?? 0;
        if ($dayCount <= 14) {
            $labels[] = $current->format('D M j');
        } elseif ($dayCount <= 90) {
            $labels[] = $current->format('M j');
        } else {
            $labels[] = $current->format('M j, Y');
        }
    }

    return ['labels' => $labels, 'values' => $values];
}

/**
 * @return array{labels: list<string>, values: list<int>}
 */
function admin_dashboard_sales_series(PDO $pdo, string $period): array
{
    checkout_ensure_schema($pdo);

    $countable = admin_dashboard_countable_statuses();
    $period = in_array($period, ['week', 'month', 'year'], true) ? $period : 'week';

    if ($period === 'week') {
        $stmt = $pdo->query("
            SELECT
                d.bucket_date,
                COALESCE(SUM(o.total), 0) AS total_amount
            FROM (
                SELECT CURDATE() - INTERVAL seq DAY AS bucket_date
                FROM (
                    SELECT 0 AS seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                    UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
                ) days
            ) d
            LEFT JOIN orders o
                ON o.created_at = d.bucket_date
               AND o.status IN ({$countable})
            GROUP BY d.bucket_date
            ORDER BY d.bucket_date
        ");
        $rows = $stmt->fetchAll();
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = date('D', strtotime((string) $row['bucket_date']));
            $values[] = (int) $row['total_amount'];
        }

        return ['labels' => $labels, 'values' => $values];
    }

    if ($period === 'month') {
        $stmt = $pdo->query("
            SELECT
                d.bucket_date,
                COALESCE(SUM(o.total), 0) AS total_amount
            FROM (
                SELECT CURDATE() - INTERVAL seqs.seq DAY AS bucket_date
                FROM (
                    SELECT ones.n + tens.n * 10 AS seq
                    FROM (
                        SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
                    ) ones
                    CROSS JOIN (
                        SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                    ) tens
                ) seqs
                WHERE seqs.seq < 30
            ) d
            LEFT JOIN orders o
                ON o.created_at = d.bucket_date
               AND o.status IN ({$countable})
            GROUP BY d.bucket_date
            ORDER BY d.bucket_date
        ");
        $rows = $stmt->fetchAll();
        $labels = [];
        $values = [];
        foreach ($rows as $row) {
            $labels[] = date('M j', strtotime((string) $row['bucket_date']));
            $values[] = (int) $row['total_amount'];
        }

        return ['labels' => $labels, 'values' => $values];
    }

    $stmt = $pdo->query("
        SELECT
            DATE_FORMAT(d.bucket_month, '%Y-%m-01') AS bucket_month,
            COALESCE(SUM(o.total), 0) AS total_amount
        FROM (
            SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL seq MONTH), '%Y-%m-01') AS bucket_month
            FROM (
                SELECT 0 AS seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
            ) months
        ) d
        LEFT JOIN orders o
            ON o.created_at >= d.bucket_month
           AND o.created_at < DATE_ADD(d.bucket_month, INTERVAL 1 MONTH)
           AND o.status IN ({$countable})
        GROUP BY d.bucket_month
        ORDER BY d.bucket_month
    ");
    $rows = $stmt->fetchAll();
    $labels = [];
    $values = [];
    foreach ($rows as $row) {
        $labels[] = date('M Y', strtotime((string) $row['bucket_month']));
        $values[] = (int) $row['total_amount'];
    }

    return ['labels' => array_reverse($labels), 'values' => array_reverse($values)];
}

function admin_dashboard_format_compact_money(int $amount): string
{
    if ($amount >= 1000000) {
        return 'MMK ' . round($amount / 1000000, 1) . 'M';
    }
    if ($amount >= 1000) {
        return 'MMK ' . round($amount / 1000, 1) . 'K';
    }

    return 'MMK ' . number_format($amount);
}

/**
 * @param array{labels: list<string>, values: list<int>} $series
 */
function admin_dashboard_render_sales_svg(array $series, int $width = 680, int $height = 240): string
{
    $labels = array_values($series['labels'] ?? []);
    $values = array_map('intval', array_values($series['values'] ?? []));
    $count = count($values);

    if ($count === 0) {
        return '<div class="admin-chart-empty">No sales data for this period yet.</div>';
    }

    $paddingLeft = 72;
    $paddingRight = 16;
    $paddingTop = 16;
    $paddingBottom = 36;
    $plotWidth = max(1, $width - $paddingLeft - $paddingRight);
    $plotHeight = max(1, $height - $paddingTop - $paddingBottom);
    $maxValue = max($values);
    $scaleMax = $maxValue > 0 ? $maxValue : 1;

    $points = [];
    $circles = [];
    foreach ($values as $index => $value) {
        $x = $paddingLeft + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : ($plotWidth / 2));
        $y = $paddingTop + $plotHeight - (($value / $scaleMax) * $plotHeight);
        $points[] = round($x, 1) . ',' . round($y, 1);
        $circles[] = sprintf(
            '<circle cx="%.1f" cy="%.1f" r="4" fill="#133f4c" stroke="#fff" stroke-width="2"><title>%s: %s</title></circle>',
            $x,
            $y,
            htmlspecialchars($labels[$index] ?? ('Point ' . ($index + 1)), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(admin_dashboard_format_money($value), ENT_QUOTES, 'UTF-8')
        );
    }

    $linePath = 'M ' . implode(' L ', $points);
    $areaPath = $linePath
        . sprintf(' L %.1f,%.1f L %.1f,%.1f Z', $paddingLeft + $plotWidth, $paddingTop + $plotHeight, $paddingLeft, $paddingTop + $plotHeight);

    $gridLines = '';
    for ($step = 0; $step <= 4; $step++) {
        $y = $paddingTop + ($step / 4) * $plotHeight;
        $value = (int) round($scaleMax - (($step / 4) * $scaleMax));
        $gridLines .= sprintf(
            '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e7ebf0" stroke-width="1"></line>
             <text x="%d" y="%.1f" fill="#667085" font-size="11" text-anchor="end" dominant-baseline="middle">%s</text>',
            $paddingLeft,
            $y,
            $paddingLeft + (int) $plotWidth,
            $y,
            $paddingLeft - 8,
            $y,
            htmlspecialchars(admin_dashboard_format_compact_money($value), ENT_QUOTES, 'UTF-8')
        );
    }

    $xLabels = '';
    $labelEvery = $count > 12 ? (int) ceil($count / 6) : 1;
    foreach ($labels as $index => $label) {
        if ($index % $labelEvery !== 0 && $index !== $count - 1) {
            continue;
        }
        $x = $paddingLeft + ($count > 1 ? ($index / ($count - 1)) * $plotWidth : ($plotWidth / 2));
        $xLabels .= sprintf(
            '<text x="%.1f" y="%d" fill="#667085" font-size="11" text-anchor="middle">%s</text>',
            $x,
            $height - 10,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }

    return sprintf(
        '<svg class="admin-sales-svg" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Sales chart">
            %s
            <path d="%s" fill="rgba(19, 63, 76, 0.12)" stroke="none"></path>
            <path d="%s" fill="none" stroke="#133f4c" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"></path>
            %s
            %s
        </svg>',
        $width,
        $height,
        $gridLines,
        htmlspecialchars($areaPath, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($linePath, ENT_QUOTES, 'UTF-8'),
        implode('', $circles),
        $xLabels
    );
}

/**
 * @param list<array{category: string, qty: int}> $categories
 */
function admin_dashboard_render_preorder_donut_svg(array $categories, int $size = 240): string
{
    if ($categories === []) {
        return '<div class="admin-chart-empty">No preorder category data yet.</div>';
    }

    $total = 0;
    foreach ($categories as $category) {
        $total += (int) ($category['qty'] ?? 0);
    }
    if ($total <= 0) {
        return '<div class="admin-chart-empty">No preorder category data yet.</div>';
    }

    $palette = ['#133f4c', '#819FA7', '#5b6e74', '#a8bcc2', '#d9e2e5', '#2d6a4f', '#9b2226'];
    $radius = 78;
    $stroke = 34;
    $center = $size / 2;
    $startAngle = -90;
    $segments = '';
    $legend = '';
    $currentAngle = $startAngle;

    foreach ($categories as $index => $category) {
        $qty = (int) ($category['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $share = ($qty / $total) * 360;
        $endAngle = $currentAngle + $share;
        $segments .= admin_dashboard_svg_arc_segment($center, $center, $radius, $currentAngle, $endAngle, $palette[$index % count($palette)], $stroke);
        $currentAngle = $endAngle;

        $legend .= sprintf(
            '<div class="admin-donut-legend-item"><span class="admin-donut-swatch" style="background:%s"></span><span>%s</span><strong>%d</strong></div>',
            htmlspecialchars($palette[$index % count($palette)], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($category['category'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'),
            $qty
        );
    }

    return sprintf(
        '<div class="admin-donut-chart">
            <svg class="admin-donut-svg" viewBox="0 0 %1$d %1$d" role="img" aria-label="Preorder demand chart">
                %2$s
                <text x="%3$.1f" y="%4$.1f" text-anchor="middle" fill="#133f4c" font-size="24" font-weight="700">%5$d</text>
                <text x="%3$.1f" y="%6$.1f" text-anchor="middle" fill="#667085" font-size="12">items</text>
            </svg>
            <div class="admin-donut-legend">%7$s</div>
        </div>',
        $size,
        $segments,
        $center,
        $center - 4,
        $total,
        $center + 16,
        $legend
    );
}

function admin_dashboard_svg_arc_segment(float $cx, float $cy, float $radius, float $startAngle, float $endAngle, string $color, float $stroke): string
{
    if ($endAngle - $startAngle >= 359.9) {
        return sprintf(
            '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="%.1f"></circle>',
            $cx,
            $cy,
            $radius,
            htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
            $stroke
        );
    }

    $start = admin_dashboard_polar_to_cartesian($cx, $cy, $radius, $endAngle);
    $end = admin_dashboard_polar_to_cartesian($cx, $cy, $radius, $startAngle);
    $largeArc = ($endAngle - $startAngle) > 180 ? 1 : 0;

    return sprintf(
        '<path d="M %.2f %.2f A %.2f %.2f 0 %d 0 %.2f %.2f" fill="none" stroke="%s" stroke-width="%.1f" stroke-linecap="butt"></path>',
        $start['x'],
        $start['y'],
        $radius,
        $radius,
        $largeArc,
        $end['x'],
        $end['y'],
        htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
        $stroke
    );
}

/**
 * @return array{x: float, y: float}
 */
function admin_dashboard_polar_to_cartesian(float $cx, float $cy, float $radius, float $angleDeg): array
{
    $angle = deg2rad($angleDeg);

    return [
        'x' => $cx + ($radius * cos($angle)),
        'y' => $cy + ($radius * sin($angle)),
    ];
}

/**
 * @return list<array{title: string, sold: int, revenue: int, price: int, image_path: string}>
 * @param array{from: string, to: string}|null $dateRange
 */
function admin_dashboard_top_products(PDO $pdo, int $limit = 5, ?array $dateRange = null): array
{
    checkout_ensure_schema($pdo);

    $limit = max(1, min(20, $limit));
    $countable = admin_dashboard_countable_statuses();

    $dateSql = '';
    $params = [];
    if ($dateRange !== null) {
        $dateSql = ' AND DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?';
        $params = [$dateRange['from'], $dateRange['to']];
    }

    $stmt = $pdo->prepare("
        SELECT
            p.product_id,
            p.title,
            p.price,
            COALESCE(SUM(oi.qty), 0) AS sold_qty,
            COALESCE(SUM(oi.qty * oi.unit_price), 0) AS revenue_amount,
            (
                SELECT i.image
                FROM variants v
                LEFT JOIN images i ON i.image_id = v.image_id
                WHERE v.product_id = p.product_id AND i.image IS NOT NULL
                ORDER BY v.variant_id, i.sort_order
                LIMIT 1
            ) AS image_path
        FROM order_items oi
        INNER JOIN orders o ON o.order_id = oi.order_id
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE o.status IN ({$countable}){$dateSql}
        GROUP BY p.product_id, p.title, p.price
        ORDER BY sold_qty DESC, revenue_amount DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'title' => (string) $row['title'],
            'sold' => (int) $row['sold_qty'],
            'revenue' => (int) $row['revenue_amount'],
            'price' => (int) $row['price'],
            'image_path' => (string) ($row['image_path'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @param array{from: string, to: string}|null $dateRange
 */
function admin_dashboard_sales_filter_query(?array $dateRange, string $preorderStatus = ''): string
{
    $parts = [];
    if ($dateRange !== null) {
        $parts[] = 'sales_from=' . urlencode($dateRange['from']);
        $parts[] = 'sales_to=' . urlencode($dateRange['to']);
    }
    if ($preorderStatus !== '') {
        $parts[] = 'preorder_status=' . urlencode($preorderStatus);
    }

    return $parts ? '?' . implode('&', $parts) : '';
}

/**
 * Resolve inventory alert status from live variant stock_qty.
 * 0 → no_stock; 1–4 → low_stock (threshold is stock_qty < 5).
 */
function admin_dashboard_inventory_alert_status(int $stockQty): ?string
{
    if ($stockQty <= 0) {
        return 'no_stock';
    }
    if ($stockQty < 5) {
        return 'low_stock';
    }

    return null;
}

/**
 * @return list<array{title: string, variant: string, stock_qty: int, product_id: int, status: string}>
 */
function admin_dashboard_low_stock_alerts(PDO $pdo, int $limit = 25): array
{
    $limit = max(1, min(50, $limit));

    // Low stock (1–4) first, then no stock (0), so both statuses appear in the dashboard.
    $stmt = $pdo->query("
        SELECT
            p.product_id,
            p.title,
            v.size,
            v.color,
            v.stock_qty
        FROM variants v
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE v.stock_qty < 5
        ORDER BY
            CASE WHEN v.stock_qty <= 0 THEN 1 ELSE 0 END ASC,
            v.stock_qty ASC,
            p.title ASC,
            v.variant_id ASC
        LIMIT {$limit}
    ");

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $stockQty = (int) $row['stock_qty'];
        $status = admin_dashboard_inventory_alert_status($stockQty);
        if ($status === null) {
            continue;
        }

        $parts = array_filter([
            trim((string) ($row['color'] ?? '')),
            trim((string) ($row['size'] ?? '')) !== '' ? 'Size ' . trim((string) $row['size']) : '',
        ]);
        $rows[] = [
            'title' => (string) $row['title'],
            'variant' => $parts ? implode(', ', $parts) : 'Default',
            'stock_qty' => $stockQty,
            'product_id' => (int) $row['product_id'],
            'status' => $status,
        ];
    }

    return $rows;
}

/**
 * @return list<array{category: string, qty: int}>
 */
function admin_dashboard_preorder_by_category(PDO $pdo): array
{
    checkout_ensure_schema($pdo);

    $stmt = $pdo->query('
        SELECT
            c.category_name,
            COALESCE(SUM(oi.qty), 0) AS qty
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        INNER JOIN orders o ON o.order_id = oi.order_id
        INNER JOIN sub_categories sc ON sc.sub_category_id = p.sub_category_id
        INNER JOIN categories c ON c.category_id = sc.category_id
        WHERE v.preorder_flag = 1
          AND o.status NOT IN ("Returned", "Refunded")
        GROUP BY c.category_id, c.category_name
        ORDER BY qty DESC, c.category_name ASC
    ');

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'category' => (string) $row['category_name'],
            'qty' => (int) $row['qty'],
        ];
    }

    return $rows;
}

/**
 * @return list<array{title: string, qty: int, share: int}>
 */
function admin_dashboard_top_preorder_items(PDO $pdo, int $limit = 0): array
{
    checkout_ensure_schema($pdo);

    $limitSql = '';
    if ($limit > 0) {
        $limit = max(1, min(100, $limit));
        $limitSql = "LIMIT {$limit}";
    }

    $stmt = $pdo->query("
        SELECT
            p.title,
            COALESCE(SUM(oi.qty), 0) AS qty
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id
        INNER JOIN products p ON p.product_id = v.product_id
        INNER JOIN orders o ON o.order_id = oi.order_id
        WHERE v.preorder_flag = 1
          AND o.status NOT IN ('Returned', 'Refunded')
          AND (
            o.order_kind = 'preorder'
            OR (o.order_kind IS NULL AND o.order_number LIKE 'GP%')
          )
        GROUP BY p.product_id, p.title
        ORDER BY qty DESC, p.title ASC
        {$limitSql}
    ");

    $rows = [];
    $items = $stmt->fetchAll();
    $totalQty = 0;
    foreach ($items as $item) {
        $totalQty += (int) $item['qty'];
    }

    foreach ($items as $item) {
        $qty = (int) $item['qty'];
        $rows[] = [
            'title' => (string) $item['title'],
            'qty' => $qty,
            'share' => $totalQty > 0 ? (int) round(($qty / $totalQty) * 100) : 0,
        ];
    }

    return $rows;
}

/**
 * @return list<array{
 *   order_id: int,
 *   customer_name: string,
 *   status: string,
 *   total: int,
 *   created_at: string,
 *   preorder_items: string,
 *   preorder_qty: int,
 *   items: list<array{title: string, color: string, size: string, qty: int, image_path: string}>
 * }>
 */
function admin_dashboard_preorder_orders(PDO $pdo, int $limit = 0): array
{
    checkout_ensure_schema($pdo);

    $limitSql = '';
    if ($limit > 0) {
        $limit = max(1, min(100, $limit));
        $limitSql = "LIMIT {$limit}";
    }

    $stmt = $pdo->query("
        SELECT
            o.order_id,
            o.order_number,
            o.status,
            o.total,
            o.created_at,
            u.name AS customer_name,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    p.title,
                    IF(v.color IS NOT NULL AND v.color <> '', CONCAT(' · ', v.color), ''),
                    IF(v.size IS NOT NULL AND v.size <> '', CONCAT(' · ', v.size), ''),
                    ' × ', oi.qty
                )
                ORDER BY p.title, v.color, v.size
                SEPARATOR '; '
            ) AS preorder_items,
            COALESCE(SUM(oi.qty), 0) AS preorder_qty
        FROM orders o
        INNER JOIN users u ON u.user_id = o.user_id
        INNER JOIN order_items oi ON oi.order_id = o.order_id
        INNER JOIN variants v ON v.variant_id = oi.variant_id AND v.preorder_flag = 1
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE o.status NOT IN ('Returned', 'Refunded')
          AND (
            o.order_kind = 'preorder'
            OR (o.order_kind IS NULL AND o.order_number LIKE 'GP%')
          )
        GROUP BY o.order_id, o.order_number, o.status, o.total, o.created_at, u.name
        ORDER BY o.order_id DESC
        {$limitSql}
    ");

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'order_id' => (int) $row['order_id'],
            'order_number' => (string) ($row['order_number'] ?? ''),
            'customer_name' => (string) $row['customer_name'],
            'status' => (string) $row['status'],
            'total' => (int) $row['total'],
            'created_at' => (string) $row['created_at'],
            'preorder_items' => (string) ($row['preorder_items'] ?? ''),
            'preorder_qty' => (int) ($row['preorder_qty'] ?? 0),
            'items' => [],
        ];
    }

    if ($rows === []) {
        return $rows;
    }

    $orderIds = array_column($rows, 'order_id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT
            oi.order_id,
            p.title,
            v.color,
            v.size,
            oi.qty,
            (
                SELECT i.image
                FROM images i
                WHERE i.variant_id = v.variant_id
                ORDER BY i.sort_order, i.image_id
                LIMIT 1
            ) AS image_path
        FROM order_items oi
        INNER JOIN variants v ON v.variant_id = oi.variant_id AND v.preorder_flag = 1
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE oi.order_id IN ({$placeholders})
        ORDER BY oi.order_id, oi.order_item_id
    ");
    $itemStmt->execute($orderIds);

    $itemsByOrder = [];
    foreach ($itemStmt->fetchAll() as $itemRow) {
        $orderId = (int) ($itemRow['order_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        $color = trim((string) ($itemRow['color'] ?? ''));
        $size = trim((string) ($itemRow['size'] ?? ''));
        $labelParts = array_filter([
            (string) ($itemRow['title'] ?? ''),
            $color !== '' ? $color : '',
            $size !== '' ? $size : '',
        ]);
        $itemsByOrder[$orderId][] = [
            'title' => (string) ($itemRow['title'] ?? ''),
            'color' => $color,
            'size' => $size,
            'qty' => (int) ($itemRow['qty'] ?? 0),
            'image_path' => (string) ($itemRow['image_path'] ?? ''),
            'label' => implode(' · ', $labelParts) . ' × ' . (int) ($itemRow['qty'] ?? 0),
        ];
    }

    foreach ($rows as $index => $row) {
        $rows[$index]['items'] = $itemsByOrder[$row['order_id']] ?? [];
    }

    return $rows;
}

/**
 * @return list<array{
 *   order_id: int,
 *   order_number: string,
 *   customer_name: string,
 *   status: string,
 *   created_at: string,
 *   title: string,
 *   color: string,
 *   size: string,
 *   qty: int,
 *   image_path: string
 * }>
 */
function admin_dashboard_all_preorder_line_items(PDO $pdo, string $status = ''): array
{
    checkout_ensure_schema($pdo);

    $where = [
        "o.status NOT IN ('Returned', 'Refunded')",
        "(
            o.order_kind = 'preorder'
            OR (o.order_kind IS NULL AND o.order_number LIKE 'GP%')
        )",
    ];
    $params = [];

    $status = trim($status);
    if ($status !== '' && in_array($status, admin_order_statuses(), true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.status,
            o.created_at,
            u.name AS customer_name,
            p.title,
            v.color,
            v.size,
            oi.qty,
            (
                SELECT i.image
                FROM images i
                WHERE i.variant_id = v.variant_id
                ORDER BY i.sort_order, i.image_id
                LIMIT 1
            ) AS image_path
        FROM order_items oi
        INNER JOIN orders o ON o.order_id = oi.order_id
        INNER JOIN users u ON u.user_id = o.user_id
        INNER JOIN variants v ON v.variant_id = oi.variant_id AND v.preorder_flag = 1
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE {$whereSql}
        ORDER BY o.order_id DESC, oi.order_item_id ASC
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'color' => trim((string) ($row['color'] ?? '')),
            'size' => trim((string) ($row['size'] ?? '')),
            'qty' => (int) ($row['qty'] ?? 0),
            'image_path' => (string) ($row['image_path'] ?? ''),
        ];
    }

    return $rows;
}

function admin_dashboard_preorder_item_label(array $item): string
{
    $parts = array_filter([
        trim((string) ($item['title'] ?? '')),
        trim((string) ($item['color'] ?? '')),
        trim((string) ($item['size'] ?? '')),
    ]);

    $label = $parts ? implode(' · ', $parts) : 'Item';

    return $label . ' × ' . (int) ($item['qty'] ?? 0);
}
