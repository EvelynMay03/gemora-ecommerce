<?php
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';
require_once __DIR__ . '/include/product_images_lib.php';
$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? (int) ($_SESSION['user_id'] ?? 0) : 0;

$selectedBrands = [];
if (isset($_GET['brand'])) {
    $brandInput = $_GET['brand'];
    if (!is_array($brandInput)) {
        $brandInput = [$brandInput];
    }
    foreach ($brandInput as $brand) {
        $brand = trim((string) $brand);
        if ($brand !== '') {
            $selectedBrands[] = $brand;
        }
    }
}
$selectedBrands = array_values(array_unique($selectedBrands));

$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? max(0, (int) $_GET['min_price']) : null;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? max(0, (int) $_GET['max_price']) : null;
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    [$minPrice, $maxPrice] = [$maxPrice, $minPrice];
}
$selectedSize = trim((string) ($_GET['size'] ?? ''));
$selectedColor = trim((string) ($_GET['color'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$isPreorderPage = isset($_GET['preorder']) && (string) $_GET['preorder'] === '1';
$headerNavActive = $isPreorderPage ? 'preorder' : 'shop';
$allowedSorts = ['popular', 'new', 'price_asc', 'price_desc'];
$selectedSort = trim((string) ($_GET['sort'] ?? 'popular'));
if (!in_array($selectedSort, $allowedSorts, true)) {
    $selectedSort = 'popular';
}

function user_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'wishlist_toggle') {
        if (!$isLoggedIn || $userId <= 0) {
            user_json_response(['ok' => false, 'message' => 'Please log in first.'], 401);
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            user_json_response(['ok' => false, 'message' => 'Invalid product.'], 400);
        }

        try {
            $existsStmt = $pdo->prepare('SELECT wishlist_id FROM wishlist WHERE user_id = ? AND product_id = ? LIMIT 1');
            $existsStmt->execute([$userId, $productId]);
            $exists = (int) ($existsStmt->fetchColumn() ?: 0);

            if ($exists > 0) {
                $pdo->prepare('DELETE FROM wishlist WHERE wishlist_id = ? AND user_id = ?')->execute([$exists, $userId]);
                user_json_response(['ok' => true, 'saved' => false, 'message' => 'Removed from wishlist.']);
            }

            $pdo->prepare('INSERT INTO wishlist (product_id, user_id) VALUES (?, ?)')->execute([$productId, $userId]);
            user_json_response(['ok' => true, 'saved' => true, 'message' => 'Added to wishlist.']);
        } catch (Throwable $e) {
            error_log('Wishlist toggle failed: ' . $e->getMessage());
            user_json_response(['ok' => false, 'message' => 'Could not update wishlist.'], 500);
        }
    }

    if ($isAjax) {
        user_json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
}

function user_img_url(?string $path): string
{
    if ($path) {
        return gemora_url(ltrim((string) $path, '/'));
    }

    return gemora_url('uploads/lace.jpg');
}

$perPage = 8;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalProducts = 0;
$totalPages = 1;
$offset = 0;

function user_shop_page_url(array $overrides = []): string
{
    global $userBase, $isPreorderPage, $selectedBrands, $minPrice, $maxPrice, $selectedSize, $selectedColor, $selectedSort, $searchQuery, $page;

    $query = [];
    if ($isPreorderPage) {
        $query['preorder'] = '1';
    }
    if ($searchQuery !== '') {
        $query['q'] = $searchQuery;
    }
    if (!empty($selectedBrands)) {
        $query['brand'] = $selectedBrands;
    }
    if ($minPrice !== null) {
        $query['min_price'] = $minPrice;
    }
    if ($maxPrice !== null) {
        $query['max_price'] = $maxPrice;
    }
    if ($selectedSize !== '') {
        $query['size'] = $selectedSize;
    }
    if ($selectedColor !== '') {
        $query['color'] = $selectedColor;
    }
    if ($selectedSort !== 'popular') {
        $query['sort'] = $selectedSort;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } elseif ($key === 'page' && (int) $value <= 1) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $qs = http_build_query($query);

    return $userBase . 'index.php' . ($qs ? '?' . $qs : '');
}

function user_shop_reset_filters_url(): string
{
    global $userBase, $isPreorderPage, $selectedSort, $searchQuery;

    $query = [];
    if ($isPreorderPage) {
        $query['preorder'] = '1';
    }
    if ($searchQuery !== '') {
        $query['q'] = $searchQuery;
    }
    if ($selectedSort !== 'popular') {
        $query['sort'] = $selectedSort;
    }

    $qs = http_build_query($query);

    return $userBase . 'index.php' . ($qs ? '?' . $qs : '');
}

function user_shop_variant_scope_sql(string $alias = 'v'): string
{
    global $isPreorderPage;

    if ($isPreorderPage) {
        return ' AND ' . $alias . '.preorder_flag = 1 AND ' . $alias . '.stock_qty <= 0';
    }

    return ' AND ' . $alias . '.stock_qty > 0';
}

function user_shop_product_availability_sql(): string
{
    global $isPreorderPage;

    if ($isPreorderPage) {
        return 'EXISTS (
            SELECT 1 FROM variants vp
            WHERE vp.product_id = p.product_id
              AND vp.preorder_flag = 1
              AND vp.stock_qty <= 0
        )';
    }

    return 'EXISTS (
        SELECT 1 FROM variants vp
        WHERE vp.product_id = p.product_id
          AND vp.stock_qty > 0
    )';
}

$homeProducts = [];
try {
    $where = [];
    $params = [];

    if (!empty($selectedBrands)) {
        $brandPlaceholders = implode(',', array_fill(0, count($selectedBrands), '?'));
        $where[] = 'COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") IN (' . $brandPlaceholders . ')';
        foreach ($selectedBrands as $brand) {
            $params[] = $brand;
        }
    }
    if ($minPrice !== null) {
        $where[] = 'p.price >= ?';
        $params[] = $minPrice;
    }
    if ($maxPrice !== null) {
        $where[] = 'p.price <= ?';
        $params[] = $maxPrice;
    }
    $where[] = user_shop_product_availability_sql();
    if ($selectedSize !== '' && $selectedColor !== '') {
        $variantFilterSql = 'EXISTS (
            SELECT 1 FROM variants vf
            WHERE vf.product_id = p.product_id
              AND vf.size = ?
              AND LOWER(TRIM(vf.color)) = LOWER(?)'
            . user_shop_variant_scope_sql('vf') . '
        )';
        $where[] = $variantFilterSql;
        $params[] = $selectedSize;
        $params[] = $selectedColor;
    } else {
        if ($selectedSize !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM variants vf WHERE vf.product_id = p.product_id AND vf.size = ?'
                . user_shop_variant_scope_sql('vf') . ')';
            $params[] = $selectedSize;
        }
        if ($selectedColor !== '') {
            $where[] = 'EXISTS (
                SELECT 1 FROM variants vf
                WHERE vf.product_id = p.product_id
                  AND LOWER(TRIM(vf.color)) = LOWER(?)'
                . user_shop_variant_scope_sql('vf') . '
            )';
            $params[] = $selectedColor;
        }
    }
    if ($searchQuery !== '') {
        $where[] = '(p.title LIKE ? OR COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") LIKE ?)';
        $params[] = '%' . $searchQuery . '%';
        $params[] = '%' . $searchQuery . '%';
    }

    $fromSql = '
        FROM products p
        LEFT JOIN brands b ON b.brand_id = p.brand_id
    ';
    $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
    $countStmt->execute($params);
    $totalProducts = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalProducts / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $productSql = '
        SELECT
            p.product_id,
            p.title,
            p.price,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name,
            COALESCE((SELECT SUM(v.stock_qty) FROM variants v WHERE v.product_id = p.product_id), 0) AS stock_qty,
            COALESCE((SELECT MAX(v.preorder_flag) FROM variants v WHERE v.product_id = p.product_id), 0) AS has_preorder,
            COALESCE((
                SELECT SUM(oi.qty)
                FROM order_items oi
                INNER JOIN variants v ON v.variant_id = oi.variant_id
                INNER JOIN orders o ON o.order_id = oi.order_id
                WHERE v.product_id = p.product_id
                  AND o.status NOT IN ("Returned", "Refunded")
            ), 0) AS sold_qty,
            (
                SELECT ' . user_sql_variant_image_expr('v') . '
                FROM variants v
                WHERE v.product_id = p.product_id'
            . user_shop_variant_scope_sql('v') . '
                ORDER BY
                  CASE
                    WHEN v.image_id IS NOT NULL THEN 0
                    WHEN EXISTS (SELECT 1 FROM images i_chk WHERE i_chk.variant_id = v.variant_id) THEN 1
                    ELSE 2
                  END,
                  v.variant_id
                LIMIT 1
            ) AS image_path,
            COALESCE((
                SELECT ROUND(AVG(r.rating), 1)
                FROM reviews r
                WHERE r.product_id = p.product_id
            ), 0) AS avg_rating,
            COALESCE((
                SELECT COUNT(*)
                FROM reviews r
                WHERE r.product_id = p.product_id
            ), 0) AS review_count
    ' . $fromSql . $whereSql;

    switch ($selectedSort) {
        case 'new':
            $productSql .= ' ORDER BY p.created_at DESC, p.product_id DESC';
            break;
        case 'price_asc':
            $productSql .= ' ORDER BY p.price ASC, p.title ASC, p.product_id ASC';
            break;
        case 'price_desc':
            $productSql .= ' ORDER BY p.price DESC, p.title ASC, p.product_id DESC';
            break;
        case 'popular':
        default:
            $productSql .= ' ORDER BY sold_qty DESC, p.created_at DESC, p.product_id DESC';
            break;
    }

    $productSql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($productSql);
    $stmt->execute($params);
    $homeProducts = $stmt->fetchAll();
} catch (Throwable $e) {
    $homeProducts = [];
    $totalProducts = 0;
    $totalPages = 1;
    $offset = 0;
}

$filterBrands = [];
$filterSizes = [];
$filterColors = [];
try {
    $filterBrands = $pdo->query('
        SELECT DISTINCT COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name
        FROM products p
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        ORDER BY brand_name
    ')->fetchAll();
    $filterSizes = $pdo->query('
        SELECT DISTINCT size
        FROM variants
        WHERE size IS NOT NULL AND size <> ""'
        . ($isPreorderPage
            ? ' AND preorder_flag = 1 AND stock_qty <= 0'
            : ' AND stock_qty > 0') . '
        ORDER BY size
    ')->fetchAll();
    $filterColors = $pdo->query('
        SELECT DISTINCT color
        FROM variants
        WHERE color IS NOT NULL AND color <> ""'
        . ($isPreorderPage
            ? ' AND preorder_flag = 1 AND stock_qty <= 0'
            : ' AND stock_qty > 0') . '
        ORDER BY color
    ')->fetchAll();
} catch (Throwable $e) {
    $filterBrands = [];
    $filterSizes = [];
    $filterColors = [];
}

$homeProductVariants = [];
if (!empty($homeProducts)) {
    $productIds = array_values(array_filter(array_map(static function ($product) {
        return (int) ($product['product_id'] ?? 0);
    }, $homeProducts), static fn (int $id): bool => $id > 0));

    if (!empty($productIds)) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $variantSql = '
            SELECT
                v.product_id,
                v.variant_id,
                v.size,
                v.color,
                v.stock_qty,
                v.preorder_flag,
                ' . user_sql_variant_image_expr('v') . ' AS image_path
            FROM variants v
            WHERE v.product_id IN (' . $placeholders . ')'
            . user_shop_variant_scope_sql('v');
        $variantParams = $productIds;
        if ($selectedSize !== '' && $selectedColor !== '') {
            $variantSql .= '
              AND v.size = ?
              AND LOWER(TRIM(v.color)) = LOWER(?)';
            $variantParams[] = $selectedSize;
            $variantParams[] = $selectedColor;
        } elseif ($selectedSize !== '') {
            $variantSql .= '
              AND v.size = ?';
            $variantParams[] = $selectedSize;
        } elseif ($selectedColor !== '') {
            $variantSql .= '
              AND LOWER(TRIM(v.color)) = LOWER(?)';
            $variantParams[] = $selectedColor;
        }
        $variantSql .= '
            ORDER BY v.product_id, v.variant_id
        ';

        try {
            $variantStmt = $pdo->prepare($variantSql);
            $variantStmt->execute($variantParams);
            foreach ($variantStmt->fetchAll() as $row) {
                $pid = (int) ($row['product_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                $size = trim((string) ($row['size'] ?? ''));
                $color = trim((string) ($row['color'] ?? ''));
                $labelParts = [];
                if ($color !== '') {
                    $labelParts[] = $color;
                }
                if ($size !== '') {
                    $labelParts[] = $size;
                }
                $homeProductVariants[$pid][] = [
                    'variant_id' => (int) ($row['variant_id'] ?? 0),
                    'stock_qty' => (int) ($row['stock_qty'] ?? 0),
                    'preorder' => (int) ($row['preorder_flag'] ?? 0),
                    'size' => $size,
                    'color' => $color,
                    'image_path' => trim((string) ($row['image_path'] ?? '')),
                    'label' => !empty($labelParts) ? implode(' / ', $labelParts) : 'Default',
                ];
            }

            $productImagesById = [];
            foreach ($homeProducts as $productRow) {
                $productImagesById[(int) ($productRow['product_id'] ?? 0)] = trim((string) ($productRow['image_path'] ?? ''));
            }

            foreach ($homeProductVariants as $pid => &$productVariants) {
                $fallbackPath = $productImagesById[$pid] ?? '';
                if ($fallbackPath === '') {
                    foreach ($productVariants as $variantRow) {
                        if (($variantRow['image_path'] ?? '') !== '') {
                            $fallbackPath = $variantRow['image_path'];
                            break;
                        }
                    }
                }

                foreach ($productVariants as &$variantRow) {
                    $path = ($variantRow['image_path'] ?? '') !== '' ? $variantRow['image_path'] : $fallbackPath;
                    $variantRow['image_url'] = user_img_url($path !== '' ? $path : null);
                }
                unset($variantRow);
            }
            unset($productVariants);
        } catch (Throwable $e) {
            $homeProductVariants = [];
        }
    }
}

$wishlistProductIds = [];
if ($isLoggedIn && $userId > 0 && !empty($homeProducts)) {
    try {
        $wishlistRows = $pdo->prepare('SELECT product_id FROM wishlist WHERE user_id = ?');
        $wishlistRows->execute([$userId]);
        foreach ($wishlistRows->fetchAll() as $row) {
            $wishlistProductIds[(int) ($row['product_id'] ?? 0)] = true;
        }
    } catch (Throwable $e) {
        error_log('Wishlist fetch failed: ' . $e->getMessage());
    }
}

function user_stock_note(int $stockQty, bool $isPreorder): string
{
    if ($stockQty > 0 && $stockQty < 5) {
        return number_format($stockQty) . ' items left';
    }
    if ($isPreorder) {
        return 'Preorder available';
    }
    if ($stockQty <= 0) {
        return 'Out of stock';
    }

    return '';
}

function user_variant_label(string $color, string $size): string
{
    $parts = [];
    if ($color !== '') {
        $parts[] = $color;
    }
    if ($size !== '') {
        $parts[] = $size;
    }

    return !empty($parts) ? implode(' / ', $parts) : 'Default';
}

function user_render_stars(float $avg): string
{
    $rounded = (int) round($avg);
    $rounded = max(0, min(5, $rounded));

    return str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded);
}

/**
 * @param list<array<string, mixed>> $variants
 * @return list<array<string, mixed>>
 */
function user_build_card_color_groups(array $variants, ?string $productImagePath = null): array
{
    $groups = [];
    $productImageUrl = trim((string) ($productImagePath ?? '')) !== '' ? user_img_url($productImagePath) : '';

    foreach ($variants as $variant) {
        $color = trim((string) ($variant['color'] ?? ''));
        $colorKey = $color !== '' ? $color : 'Default';
        $size = trim((string) ($variant['size'] ?? ''));
        $imageUrl = (string) ($variant['image_url'] ?? '');

        if (!isset($groups[$colorKey])) {
            $groups[$colorKey] = [
                'color' => $colorKey,
                'image_url' => '',
                'sizes' => [],
            ];
        }

        $groups[$colorKey]['sizes'][] = [
            'variant_id' => (int) ($variant['variant_id'] ?? 0),
            'size' => $size,
            'stock_qty' => (int) ($variant['stock_qty'] ?? 0),
            'preorder' => (int) ($variant['preorder'] ?? 0),
            'image_url' => $imageUrl,
            'label' => (string) ($variant['label'] ?? user_variant_label($color, $size)),
        ];
    }

    foreach ($groups as &$group) {
        $colorImageUrl = '';
        foreach ($group['sizes'] as $sizeRow) {
            if (($sizeRow['image_url'] ?? '') !== '') {
                $colorImageUrl = $sizeRow['image_url'];
                break;
            }
        }

        if ($colorImageUrl === '' && $productImageUrl !== '') {
            $colorImageUrl = $productImageUrl;
        }

        $group['image_url'] = $colorImageUrl !== '' ? $colorImageUrl : user_img_url(null);
        foreach ($group['sizes'] as &$sizeRow) {
            if (($sizeRow['image_url'] ?? '') === '') {
                $sizeRow['image_url'] = $group['image_url'];
            }
        }
        unset($sizeRow);
    }
    unset($group);

    return array_values($groups);
}

/**
 * @param list<array<string, mixed>> $colorGroups
 * @return array{initialColor: string, initialSizes: list<array<string, mixed>>, initialVariant: ?array<string, mixed>}
 */
function user_pick_initial_card_state(array $colorGroups, string $selectedColor = '', string $selectedSize = ''): array
{
    if ($selectedColor !== '') {
        foreach ($colorGroups as $group) {
            if (strcasecmp((string) ($group['color'] ?? ''), $selectedColor) !== 0) {
                continue;
            }

            $sizes = $group['sizes'] ?? [];
            if ($selectedSize !== '') {
                $matchedSizes = array_values(array_filter($sizes, static function ($sizeOption) use ($selectedSize) {
                    return trim((string) ($sizeOption['size'] ?? '')) === $selectedSize;
                }));
                if (!empty($matchedSizes)) {
                    $sizes = $matchedSizes;
                }
            }

            foreach ($sizes as $sizeOption) {
                $stock = (int) ($sizeOption['stock_qty'] ?? 0);
                $preorder = (int) ($sizeOption['preorder'] ?? 0) === 1;
                if ($stock > 0 || $preorder) {
                    return [
                        'initialColor' => (string) ($group['color'] ?? ''),
                        'initialSizes' => $sizes,
                        'initialVariant' => $sizeOption,
                    ];
                }
            }

            return [
                'initialColor' => (string) ($group['color'] ?? ''),
                'initialSizes' => $sizes,
                'initialVariant' => $sizes[0] ?? null,
            ];
        }
    }

    $initialColor = $colorGroups[0]['color'] ?? '';
    $initialSizes = $colorGroups[0]['sizes'] ?? [];
    $initialVariant = $initialSizes[0] ?? null;

    foreach ($colorGroups as $group) {
        $sizes = $group['sizes'] ?? [];
        if ($selectedSize !== '') {
            $sizes = array_values(array_filter($sizes, static function ($sizeOption) use ($selectedSize) {
                return trim((string) ($sizeOption['size'] ?? '')) === $selectedSize;
            }));
            if (empty($sizes)) {
                continue;
            }
        }

        foreach ($sizes as $sizeOption) {
            $stock = (int) ($sizeOption['stock_qty'] ?? 0);
            $preorder = (int) ($sizeOption['preorder'] ?? 0) === 1;
            if ($stock > 0 || $preorder) {
                return [
                    'initialColor' => (string) ($group['color'] ?? ''),
                    'initialSizes' => $selectedSize !== '' ? $sizes : ($group['sizes'] ?? []),
                    'initialVariant' => $sizeOption,
                ];
            }
        }
    }

    return [
        'initialColor' => $initialColor,
        'initialSizes' => $initialSizes,
        'initialVariant' => $initialVariant,
    ];
}

$activeFilterLabels = [];
if (!empty($selectedBrands)) {
    $activeFilterLabels[] = 'Brand: ' . implode(', ', $selectedBrands);
}
if ($minPrice !== null || $maxPrice !== null) {
    if ($minPrice !== null && $maxPrice !== null) {
        $activeFilterLabels[] = 'Price: ' . number_format($minPrice) . '–' . number_format($maxPrice) . ' MMK';
    } elseif ($minPrice !== null) {
        $activeFilterLabels[] = 'Price: from ' . number_format($minPrice) . ' MMK';
    } else {
        $activeFilterLabels[] = 'Price: up to ' . number_format((int) $maxPrice) . ' MMK';
    }
}
if ($selectedSize !== '') {
    $activeFilterLabels[] = 'Size: ' . $selectedSize;
}
if ($selectedColor !== '') {
    $activeFilterLabels[] = 'Color: ' . $selectedColor;
}
$hasActiveFilters = !empty($activeFilterLabels);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?php echo $isPreorderPage ? 'Preorder Items — Gemora' : 'Gemora'; ?></title>
<?php require_once __DIR__ . '/include/user_paths.php'; ?>
<link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
<style>
  .product-swipe-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 1px solid var(--glass-border);
    background: rgba(255, 255, 255, 0.92);
    color: var(--accent);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
    padding: 0;
    line-height: 1;
  }
  .product-swipe-btn.prev { left: 8px; }
  .product-swipe-btn.next { right: 8px; }
  .product-swipe-btn[hidden] { display: none; }
  .card-variant-label {
    font-size: 12px;
    color: var(--muted);
    margin: 0;
  }
  .card-size-picker {
    margin-top: 8px;
  }
  .card-size-picker-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 6px;
    display: block;
  }
  .card-size-options {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .card-size-btn {
    border: 1px solid #d9e7ed;
    border-radius: 10px;
    background: #fff;
    color: #7ea2b2;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    padding: 8px 12px;
    min-width: 42px;
    cursor: pointer;
    transition: all 0.2s ease;
  }
  .card-size-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
  }
  .card-size-btn.is-active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .card-size-picker.is-error .card-size-options {
    border: 1px solid #c33;
    border-radius: 10px;
    padding: 6px;
  }
  .card-size-hint {
    font-size: 11px;
    color: #c33;
    margin-top: 6px;
  }
  .card-size-hint[hidden] {
    display: none;
  }
  .card-size-picker.single-size .card-size-btn {
    cursor: default;
  }
  .card-size-picker.single-size .card-size-btn:not(.is-active) {
    display: none;
  }
  .card-size-picker.single-size .card-size-label {
    display: none;
  }
  .card-size-picker.single-size .card-size-hint {
    display: none !important;
  }
  .card-size-picker.is-error .card-size-btn {
    border-color: #c33;
  }
  .wishlist-btn.is-active {
    color: #e91e63;
    background: rgba(233, 30, 99, 0.1);
    border-color: rgba(233, 30, 99, 0.25);
  }
  .wishlist-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #6b7d86;
    z-index: 4;
  }
  .wishlist-btn svg {
    width: 18px;
    height: 18px;
    display: block;
    stroke: currentColor;
    fill: none;
    stroke-width: 1.8;
  }
  .wishlist-btn.is-active svg {
    fill: currentColor;
  }
  .img-wrap .badge {
    z-index: 4;
  }
  .img-wrap img[data-variant-image] {
    z-index: 1;
  }
  .card-foot {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 8px;
    margin-top: auto;
  }
  .card-foot .actions {
    margin-top: 0;
    flex-shrink: 0;
  }
  .card-rating {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    min-width: 0;
    flex-shrink: 1;
    font-size: 12px;
    line-height: 1.2;
    color: var(--accent);
  }
  .card-rating-stars,
  .card-rating-empty {
    letter-spacing: -0.5px;
    white-space: nowrap;
    line-height: 1;
  }
  .card-rating-empty {
    color: #c5c5c5;
  }
  .card-rating-meta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
  }
  .card-rating-score {
    font-weight: 700;
    color: var(--text);
  }
  .shop-toast {
    position: fixed;
    left: 50%;
    bottom: 24px;
    transform: translateX(-50%) translateY(16px);
    min-width: 260px;
    max-width: min(90vw, 520px);
    background: #fff;
    color: var(--text);
    border: 1px solid var(--glass-border);
    border-radius: 10px;
    box-shadow: var(--shadow);
    padding: 12px 14px;
    font-size: 13px;
    font-weight: 500;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease, transform 0.2s ease;
    z-index: 9999;
  }
  .shop-toast.is-visible {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
  .shop-toast.is-error {
    border-color: #e4b5b5;
    background: #fff7f7;
    color: #7f2020;
  }
  .filters-form .chip input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }
  .filters-form .chip.is-active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .filters-form .chip {
    cursor: pointer;
  }

  .shop-active-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 14px;
  }

  .shop-active-filters span {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: var(--accent-2);
    border: 1px solid var(--glass-border);
    color: var(--accent);
    font-size: 12px;
    font-weight: 600;
  }

  .shop-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .shop-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .shop-nav-arrows {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .shop-nav-arrows a,
  .shop-nav-arrows button {
    width: 34px;
    height: 34px;
    border-radius: var(--radius);
    border: 1px solid var(--glass-border);
    background: #fff;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 18px;
    line-height: 1;
    padding: 0;
  }

  .shop-nav-arrows a:hover {
    background: var(--accent-2);
    border-color: var(--muted);
    color: var(--accent);
  }

  .shop-nav-arrows button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
  }

  .shop-page-label {
    font-size: 13px;
    color: var(--muted);
    min-width: 88px;
    text-align: center;
  }

  .pager {
    margin-top: 8px;
    padding: 16px 0 4px;
    border-top: 1px solid var(--glass-border);
  }

  .pager a {
    text-decoration: none;
  }

  .pager button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  @media (max-width: 900px) {
    .shop-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .shop-filter-toggle {
      display: inline-flex;
    }

    .filters-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(13, 13, 13, 0.3);
      z-index: 80;
    }

    .filters-backdrop.is-open {
      display: block;
    }

    .filters.is-mobile-open {
      display: block;
      position: fixed;
      top: 0;
      right: 0;
      width: min(420px, 100%);
      height: 100%;
      z-index: 81;
      overflow: auto;
      border-radius: 8px 0 0 8px;
      box-shadow: var(--shadow);
    }
  }

  .shop-filter-toggle {
    display: none;
    align-items: center;
    gap: 6px;
  }

  @media (max-width: 560px) {
    .shop-grid {
      grid-template-columns: 1fr;
    }

    .shop-page-label {
      display: none;
    }
  }
</style>
</head>
<body>
  <div class="page" id="app">
    <?php include __DIR__ . '/../include/header.php'; ?>

    <!-- Main content -->
    <main class="content" role="main">
      <!-- Sidebar filters (desktop) -->
      <div class="filters-backdrop" id="shopFiltersBackdrop" hidden></div>
      <aside class="filters" id="shopFilters" aria-label="Filters">
        <h3>Filters</h3>
        <form method="get" action="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="filters-form">
          <?php if ($isPreorderPage): ?>
            <input type="hidden" name="preorder" value="1">
          <?php endif; ?>
          <?php if ($selectedSort !== 'popular'): ?>
            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($selectedSort, ENT_QUOTES, 'UTF-8'); ?>">
          <?php endif; ?>
          <?php if ($searchQuery !== ''): ?>
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
          <?php endif; ?>
          <div class="filter-section">
            <label>Brand</label>
            <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;max-height:170px;overflow:auto">
              <?php foreach ($filterBrands as $brandRow): ?>
                <?php $brandName = (string) ($brandRow['brand_name'] ?? 'Unknown'); ?>
                <label>
                  <input type="checkbox" name="brand[]" value="<?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($brandName, $selectedBrands, true) ? 'checked' : ''; ?>>
                  <?php echo htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8'); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="filter-section">
            <label>Price (MMK)</label>
            <div class="price-range">
              <input type="number" name="min_price" placeholder="min" min="0" value="<?php echo $minPrice !== null ? (int) $minPrice : ''; ?>">
              <input type="number" name="max_price" placeholder="max" min="0" value="<?php echo $maxPrice !== null ? (int) $maxPrice : ''; ?>">
            </div>
          </div>

          <div class="filter-section">
            <label>Size</label>
            <div class="sizes" role="group" aria-label="Sizes">
              <label class="chip <?php echo $selectedSize === '' ? 'is-active' : ''; ?>">
                <input type="radio" name="size" value="" <?php echo $selectedSize === '' ? 'checked' : ''; ?>>
                Any
              </label>
              <?php foreach ($filterSizes as $sizeRow): ?>
                <?php $sizeVal = trim((string) ($sizeRow['size'] ?? '')); ?>
                <?php if ($sizeVal === '') continue; ?>
                <label class="chip <?php echo $selectedSize === $sizeVal ? 'is-active' : ''; ?>">
                  <input type="radio" name="size" value="<?php echo htmlspecialchars($sizeVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedSize === $sizeVal ? 'checked' : ''; ?>>
                  <?php echo htmlspecialchars($sizeVal, ENT_QUOTES, 'UTF-8'); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="filter-section">
            <label>Color</label>
            <div class="sizes">
              <label class="chip <?php echo $selectedColor === '' ? 'is-active' : ''; ?>">
                <input type="radio" name="color" value="" <?php echo $selectedColor === '' ? 'checked' : ''; ?>>
                Any
              </label>
              <?php foreach ($filterColors as $colorRow): ?>
                <?php $colorVal = trim((string) ($colorRow['color'] ?? '')); ?>
                <?php if ($colorVal === '') continue; ?>
                <label class="chip <?php echo strcasecmp($selectedColor, $colorVal) === 0 ? 'is-active' : ''; ?>">
                  <input type="radio" name="color" value="<?php echo htmlspecialchars($colorVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo strcasecmp($selectedColor, $colorVal) === 0 ? 'checked' : ''; ?>>
                  <?php echo htmlspecialchars($colorVal, ENT_QUOTES, 'UTF-8'); ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="margin-top:12px;display:flex;gap:8px">
            <button type="submit" class="btn primary">Apply</button>
            <a href="<?php echo htmlspecialchars(user_shop_reset_filters_url(), ENT_QUOTES, 'UTF-8'); ?>" class="btn ghost">Reset</a>
          </div>
        </form>
      </aside>

      <!-- Product list area -->
      <section>
        <div class="product-header">
          <div class="left">
            <div style="display:flex;flex-direction:column">
              <div style="font-weight:600">
                <?php if ($searchQuery !== ''): ?>
                  Results for &ldquo;<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>&rdquo;
                <?php else: ?>
                  <?php echo $isPreorderPage ? 'Preorder Items' : 'Bras & Lingerie'; ?>
                <?php endif; ?>
              </div>
              <div class="product-count">
                <?php if ($totalProducts > 0): ?>
                  Showing <?php echo number_format(min($totalProducts, $offset + 1)); ?>–<?php echo number_format(min($totalProducts, $offset + count($homeProducts))); ?>
                  of <?php echo number_format($totalProducts); ?> <?php echo $isPreorderPage ? 'preorder items' : 'products'; ?>
                  <?php if ($totalPages > 1): ?>
                    · Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
                  <?php endif; ?>
                  · Myanmar
                <?php else: ?>
                  0 <?php echo $isPreorderPage ? 'preorder items' : 'products'; ?> • Myanmar
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="shop-header-actions">
            <button type="button" class="btn ghost shop-filter-toggle" id="shopFilterToggle" aria-expanded="false" aria-controls="shopFilters">
              Filters
            </button>
            <?php if ($totalProducts > 0): ?>
            <div class="shop-nav-arrows" aria-label="Product pages">
              <?php if ($page > 1): ?>
                <a href="<?php echo htmlspecialchars(user_shop_page_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">‹</a>
              <?php else: ?>
                <button type="button" disabled aria-label="Previous page">‹</button>
              <?php endif; ?>
              <span class="shop-page-label">Page <?php echo (int) $page; ?>/<?php echo (int) $totalPages; ?></span>
              <?php if ($page < $totalPages): ?>
                <a href="<?php echo htmlspecialchars(user_shop_page_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">›</a>
              <?php else: ?>
                <button type="button" disabled aria-label="Next page">›</button>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="get" action="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="shop-sort-form" style="display:flex;gap:12px;align-items:center;margin:0;">
              <?php if ($isPreorderPage): ?>
                <input type="hidden" name="preorder" value="1">
              <?php endif; ?>
              <?php foreach ($selectedBrands as $brand): ?>
                <input type="hidden" name="brand[]" value="<?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endforeach; ?>
              <?php if ($minPrice !== null): ?>
                <input type="hidden" name="min_price" value="<?php echo (int) $minPrice; ?>">
              <?php endif; ?>
              <?php if ($maxPrice !== null): ?>
                <input type="hidden" name="max_price" value="<?php echo (int) $maxPrice; ?>">
              <?php endif; ?>
              <?php if ($selectedSize !== ''): ?>
                <input type="hidden" name="size" value="<?php echo htmlspecialchars($selectedSize, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endif; ?>
              <?php if ($selectedColor !== ''): ?>
                <input type="hidden" name="color" value="<?php echo htmlspecialchars($selectedColor, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endif; ?>
              <?php if ($searchQuery !== ''): ?>
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endif; ?>
              <label class="muted" style="font-size:13px" for="shopSortSelect">Sort by</label>
              <select id="shopSortSelect" name="sort" onchange="this.form.submit()">
                <option value="popular" <?php echo $selectedSort === 'popular' ? 'selected' : ''; ?>>Popular</option>
                <option value="new" <?php echo $selectedSort === 'new' ? 'selected' : ''; ?>>New Arrivals</option>
                <option value="price_asc" <?php echo $selectedSort === 'price_asc' ? 'selected' : ''; ?>>Price Low → High</option>
                <option value="price_desc" <?php echo $selectedSort === 'price_desc' ? 'selected' : ''; ?>>Price High → Low</option>
              </select>
            </form>
          </div>
        </div>

        <?php if ($hasActiveFilters): ?>
        <div class="shop-active-filters" aria-label="Active filters">
          <?php foreach ($activeFilterLabels as $filterLabel): ?>
          <span><?php echo htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endforeach; ?>
          <a href="<?php echo htmlspecialchars(user_shop_reset_filters_url(), ENT_QUOTES, 'UTF-8'); ?>" class="btn ghost small">Clear filters</a>
        </div>
        <?php endif; ?>

        <div class="grid shop-grid" aria-live="polite">
          <?php if (empty($homeProducts)): ?>
            <article class="card">
              <div class="meta">
                <div class="title">
                  <?php if ($searchQuery !== ''): ?>
                    No results for &ldquo;<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>&rdquo;
                  <?php elseif ($hasActiveFilters): ?>
                    No products match your filters
                  <?php else: ?>
                    <?php echo $isPreorderPage ? 'No preorder items yet' : 'No products yet'; ?>
                  <?php endif; ?>
                </div>
                <div class="note">
                  <?php if ($searchQuery !== '' || $hasActiveFilters): ?>
                    Try a different search term or <a href="<?php echo htmlspecialchars(user_shop_reset_filters_url(), ENT_QUOTES, 'UTF-8'); ?>">clear your filters</a>.
                  <?php elseif ($isPreorderPage): ?>
                    Check back later for items available to preorder.
                  <?php else: ?>
                    Please add products from admin inventory.
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php else: ?>
            <?php foreach ($homeProducts as $product): ?>
              <?php
              $cardProductId = (int) ($product['product_id'] ?? 0);
              $cardVariants = $homeProductVariants[$cardProductId] ?? [];
              if (empty($cardVariants)) {
                  $stockQty = (int) ($product['stock_qty'] ?? 0);
                  $hasPreorder = (int) ($product['has_preorder'] ?? 0) === 1;
                  $cardVariants = [[
                      'variant_id' => 0,
                      'stock_qty' => $stockQty,
                      'preorder' => $hasPreorder ? 1 : 0,
                      'size' => '',
                      'color' => 'Default',
                      'image_url' => user_img_url($product['image_path'] ?? null),
                      'label' => 'Default',
                  ]];
              }

              $colorGroups = user_build_card_color_groups($cardVariants, $product['image_path'] ?? null);
              if (empty($colorGroups)) {
                  continue;
              }
              $cardState = user_pick_initial_card_state($colorGroups, $selectedColor, $selectedSize);
              $initialColor = $cardState['initialColor'];
              $initialSizes = $cardState['initialSizes'];
              $initialVariant = $cardState['initialVariant'] ?? ($initialSizes[0] ?? null);
              $canSwipeColors = count($colorGroups) > 1;
              $initialImageUrl = '';
              foreach ($colorGroups as $colorGroup) {
                  if (($colorGroup['color'] ?? '') === $initialColor) {
                      $initialImageUrl = (string) ($colorGroup['image_url'] ?? '');
                      break;
                  }
              }
              if ($initialImageUrl === '' && !empty($colorGroups)) {
                  $initialImageUrl = (string) ($colorGroups[0]['image_url'] ?? user_img_url($product['image_path'] ?? null));
              }
              if ($initialImageUrl === '' && $initialVariant) {
                  $initialImageUrl = (string) ($initialVariant['image_url'] ?? user_img_url($product['image_path'] ?? null));
              }

              $variantStock = (int) ($initialVariant['stock_qty'] ?? 0);
              $variantPreorder = (int) ($initialVariant['preorder'] ?? 0) === 1;
              $isPreorder = $variantStock <= 0 && $variantPreorder;
              $stockNote = user_stock_note($variantStock, $isPreorder);
              $isWishlisted = !empty($wishlistProductIds[$cardProductId]);
              $hasMultipleSizes = count($initialSizes) > 1;
              $reviewCount = (int) ($product['review_count'] ?? 0);
              $avgRating = (float) ($product['avg_rating'] ?? 0);
              $ratingTitle = $reviewCount > 0
                  ? 'Average rating from ' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's')
                  : 'No reviews yet';
              ?>
              <article class="card" data-size-picked="<?php echo $hasMultipleSizes ? '0' : '1'; ?>" data-active-color="<?php echo htmlspecialchars($initialColor, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="img-wrap" data-color-swiper data-color-groups="<?php echo htmlspecialchars(json_encode($colorGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>">
                  <div class="badge" data-variant-badge <?php echo $isPreorder ? '' : 'hidden'; ?>>Preorder</div>
                  <button
                    class="wishlist-btn<?php echo $isWishlisted ? ' is-active' : ''; ?>"
                    data-add-wishlist
                    data-product-id="<?php echo $cardProductId; ?>"
                    aria-pressed="<?php echo $isWishlisted ? 'true' : 'false'; ?>"
                    title="<?php echo $isWishlisted ? 'Remove from wishlist' : 'Add to wishlist'; ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                  </button>
                  <img src="<?php echo htmlspecialchars($initialImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    data-variant-image
                    alt="<?php echo htmlspecialchars((string) ($product['title'] ?? 'Product'), ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                  <button type="button" class="product-swipe-btn prev" data-color-prev <?php echo $canSwipeColors ? '' : 'hidden'; ?> aria-label="Previous color">‹</button>
                  <button type="button" class="product-swipe-btn next" data-color-next <?php echo $canSwipeColors ? '' : 'hidden'; ?> aria-label="Next color">›</button>
                </div>
                <div class="meta">
                  <div class="brand-name"><?php echo htmlspecialchars((string) ($product['brand_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="title"><?php echo htmlspecialchars((string) ($product['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if ($canSwipeColors || $hasMultipleSizes): ?>
                    <p class="card-variant-label">Variant <span data-variant-label><?php echo htmlspecialchars((string) ($initialVariant['label'] ?? $initialColor), ENT_QUOTES, 'UTF-8'); ?></span></p>
                  <?php endif; ?>
                  <?php if (!empty($initialSizes)): ?>
                    <div class="card-size-picker<?php echo $hasMultipleSizes ? '' : ' single-size'; ?>" data-size-picker <?php echo $hasMultipleSizes ? 'data-size-required="1"' : ''; ?>>
                      <span class="card-size-picker-label">Size</span>
                      <div class="card-size-options" data-size-options>
                        <?php foreach ($initialSizes as $sizeOption): ?>
                          <?php
                          $sizeLabel = trim((string) ($sizeOption['size'] ?? ''));
                          if ($sizeLabel === '') {
                              $sizeLabel = 'One size';
                          }
                          $sizeVariantId = (int) ($sizeOption['variant_id'] ?? 0);
                          $sizeStock = (int) ($sizeOption['stock_qty'] ?? 0);
                          $sizePreorder = (int) ($sizeOption['preorder'] ?? 0) === 1;
                          $sizeOut = $sizeStock <= 0 && !$sizePreorder;
                          $sizeActive = !$hasMultipleSizes || $sizeVariantId === (int) ($initialVariant['variant_id'] ?? 0);
                          ?>
                          <button
                            type="button"
                            class="card-size-btn<?php echo $sizeActive && !$sizeOut ? ' is-active' : ''; ?>"
                            data-size-option
                            data-size-value="<?php echo htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            data-variant-id="<?php echo $sizeVariantId; ?>"
                            data-image-url="<?php echo htmlspecialchars((string) ($sizeOption['image_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            data-variant-label="<?php echo htmlspecialchars((string) ($sizeOption['label'] ?? $sizeLabel), ENT_QUOTES, 'UTF-8'); ?>"
                            data-stock-qty="<?php echo $sizeStock; ?>"
                            data-preorder="<?php echo $sizePreorder ? '1' : '0'; ?>"
                            <?php echo $sizeOut ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8'); ?>
                          </button>
                        <?php endforeach; ?>
                      </div>
                      <input type="hidden" data-size-selected-variant value="<?php echo !$hasMultipleSizes ? (int) ($initialVariant['variant_id'] ?? 0) : ((int) ($initialVariant['variant_id'] ?? 0) > 0 ? (int) $initialVariant['variant_id'] : ''); ?>">
                      <div class="card-size-hint" data-size-hint hidden>Please choose a size</div>
                    </div>
                  <?php endif; ?>
                  <div class="price">MMK <?php echo number_format((int) ($product['price'] ?? 0)); ?></div>
                  <div class="note" data-variant-note <?php echo $stockNote === '' ? 'hidden' : ''; ?>><?php echo htmlspecialchars($stockNote, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="card-foot">
                  <div class="card-rating" title="<?php echo htmlspecialchars($ratingTitle, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if ($reviewCount > 0): ?>
                      <span class="card-rating-stars" aria-label="<?php echo htmlspecialchars(number_format($avgRating, 1), ENT_QUOTES, 'UTF-8'); ?> out of 5 stars"><?php echo user_render_stars($avgRating); ?></span>
                      <span class="card-rating-meta">
                        <span class="card-rating-score"><?php echo htmlspecialchars(number_format($avgRating, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>(<?php echo number_format($reviewCount); ?>)</span>
                      </span>
                    <?php else: ?>
                      <span class="card-rating-empty" aria-label="No reviews">☆☆☆☆☆</span>
                      <span class="card-rating-meta">No reviews</span>
                    <?php endif; ?>
                  </div>
                  <div class="actions">
                    <a class="btn ghost small" href="<?php echo htmlspecialchars($userBase . 'product.php?id=' . $cardProductId, ENT_QUOTES, 'UTF-8'); ?>">Details</a>
                    <button type="button" class="btn primary small" data-variant-cta data-add-to-cart
                      data-variant-id="<?php echo (int) ($initialVariant['variant_id'] ?? 0); ?>"
                      <?php echo (int) ($initialVariant['variant_id'] ?? 0) <= 0 ? 'disabled' : ''; ?>>
                      <?php echo $isPreorder ? 'Preorder' : 'Add to cart'; ?>
                    </button>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if ($totalProducts > 0): ?>
        <div class="pager" aria-label="Pagination">
          <?php if ($page > 1): ?>
            <a class="btn ghost small" href="<?php echo htmlspecialchars(user_shop_page_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8'); ?>">« Prev</a>
          <?php else: ?>
            <button type="button" class="btn ghost small" disabled>« Prev</button>
          <?php endif; ?>
          <div class="muted">
            Showing <?php echo number_format(min($totalProducts, $offset + 1)); ?>–<?php echo number_format(min($totalProducts, $offset + count($homeProducts))); ?>
            of <?php echo number_format($totalProducts); ?>
            · Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
          </div>
          <?php if ($page < $totalPages): ?>
            <a class="btn ghost small" href="<?php echo htmlspecialchars(user_shop_page_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next »</a>
          <?php else: ?>
            <button type="button" class="btn ghost small" disabled>Next »</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <?php include __DIR__ . '/../include/footer.php'; ?>
  <div id="shopToast" class="shop-toast" role="status" aria-live="polite"></div>
<script>
  var toastTimer = null;
  function showShopToast(message, type) {
    var toast = document.getElementById('shopToast');
    if (!toast) return;
    toast.textContent = message || '';
    toast.classList.remove('is-error');
    if (type === 'error') toast.classList.add('is-error');
    toast.classList.add('is-visible');
    if (toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function() {
      toast.classList.remove('is-visible');
    }, 1800);
  }

  function updateHeaderCartBadge(itemCount) {
    var count = Number(itemCount || 0);
    if (!Number.isFinite(count) || count < 0) count = 0;
    document.querySelectorAll('.icon-count-badge').forEach(function(badge) {
      badge.textContent = String(count);
      if (count > 0) badge.classList.remove('is-hidden');
      else badge.classList.add('is-hidden');
    });
  }

  document.querySelectorAll('.filters-form .chip input[type="radio"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
      var group = radio.closest('.sizes');
      if (!group) return;
      group.querySelectorAll('.chip').forEach(function(chip) {
        chip.classList.remove('is-active');
      });
      var parentChip = radio.closest('.chip');
      if (parentChip) parentChip.classList.add('is-active');
    });
  });

  var shopFilters = document.getElementById('shopFilters');
  var shopFiltersBackdrop = document.getElementById('shopFiltersBackdrop');
  var shopFilterToggle = document.getElementById('shopFilterToggle');

  function setMobileFiltersOpen(isOpen) {
    if (!shopFilters || !shopFiltersBackdrop || !shopFilterToggle) return;
    shopFilters.classList.toggle('is-mobile-open', isOpen);
    shopFiltersBackdrop.classList.toggle('is-open', isOpen);
    shopFiltersBackdrop.hidden = !isOpen;
    shopFilterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    document.body.style.overflow = isOpen ? 'hidden' : '';
  }

  if (shopFilterToggle) {
    shopFilterToggle.addEventListener('click', function() {
      var isOpen = shopFilters && shopFilters.classList.contains('is-mobile-open');
      setMobileFiltersOpen(!isOpen);
    });
  }

  if (shopFiltersBackdrop) {
    shopFiltersBackdrop.addEventListener('click', function() {
      setMobileFiltersOpen(false);
    });
  }

  window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
      setMobileFiltersOpen(false);
    }
  });

  document.querySelectorAll('[data-color-swiper]').forEach(function(swiper) {
    var groupsRaw = swiper.getAttribute('data-color-groups');
    if (!groupsRaw) return;

    var colorGroups;
    try {
      colorGroups = JSON.parse(groupsRaw);
    } catch (err) {
      return;
    }
    if (!Array.isArray(colorGroups) || colorGroups.length === 0) return;

    var card = swiper.closest('.card');
    if (!card) return;

    var img = swiper.querySelector('[data-variant-image]');
    var badge = swiper.querySelector('[data-variant-badge]');
    var noteEl = card.querySelector('[data-variant-note]');
    var labelEl = card.querySelector('[data-variant-label]');
    var ctaBtn = card.querySelector('[data-variant-cta]');
    var sizePicker = card.querySelector('[data-size-picker]');
    var sizeOptions = card.querySelector('[data-size-options]');
    var sizeSelectedInput = card.querySelector('[data-size-selected-variant]');
    var prevBtn = swiper.querySelector('[data-color-prev]');
    var nextBtn = swiper.querySelector('[data-color-next]');
    var colorIndex = 0;

    function formatNumber(n) {
      var value = Number(n || 0);
      if (!Number.isFinite(value)) value = 0;
      return value.toLocaleString('en-US');
    }

    function stockNoteForVariant(variant) {
      var stock = Number(variant.stock_qty || 0);
      var preorder = Number(variant.preorder || 0) === 1;
      if (stock > 0 && stock < 5) {
        return formatNumber(stock) + ' items left';
      }
      if (stock <= 0 && preorder) {
        return 'Preorder available';
      }
      if (stock <= 0) {
        return 'Out of stock';
      }
      return '';
    }

    function currentColorGroup() {
      return colorGroups[colorIndex] || colorGroups[0];
    }

    function findColorIndex(colorName) {
      var idx = colorGroups.findIndex(function(group) {
        return group.color === colorName;
      });
      return idx >= 0 ? idx : 0;
    }

    function applyVariantState(variant, imageUrl) {
      if (!variant) return;

      var stock = Number(variant.stock_qty || 0);
      var preorder = Number(variant.preorder || 0) === 1;
      var isPreorder = stock <= 0 && preorder;
      var note = stockNoteForVariant(variant);
      var sizeRequired = sizePicker && sizePicker.getAttribute('data-size-required') === '1';
      var sizePicked = card.getAttribute('data-size-picked') === '1';
      if (sizeRequired && !sizePicked && note === 'Out of stock') {
        note = '';
      }

      if (img) {
        img.src = imageUrl || variant.image_url || currentColorGroup().image_url || img.src;
      }
      if (badge) {
        if (isPreorder) badge.removeAttribute('hidden');
        else badge.setAttribute('hidden', '');
      }
      if (noteEl) {
        if (note) {
          noteEl.textContent = note;
          noteEl.removeAttribute('hidden');
        } else {
          noteEl.textContent = '';
          noteEl.setAttribute('hidden', '');
        }
      }
      if (labelEl) {
        labelEl.textContent = variant.label || currentColorGroup().color || 'Default';
      }
      if (ctaBtn) {
        ctaBtn.textContent = isPreorder ? 'Preorder' : 'Add to cart';
        ctaBtn.dataset.variantId = String(variant.variant_id || '');
        ctaBtn.disabled = !variant.variant_id || (!isPreorder && stock <= 0);
      }
      if (sizeSelectedInput) {
        sizeSelectedInput.value = variant.variant_id ? String(variant.variant_id) : '';
      }
    }

    function rebuildSizeOptions(colorName) {
      if (!sizeOptions) return null;

      var group = colorGroups.find(function(item) {
        return item.color === colorName;
      }) || currentColorGroup();
      var sizes = group.sizes || [];
      sizeOptions.innerHTML = '';

      var firstAvailable = null;
      sizes.forEach(function(sizeOption) {
        var label = sizeOption.size || 'One size';
        var stockQty = Number(sizeOption.stock_qty || 0);
        var preorder = Number(sizeOption.preorder || 0) === 1;
        var out = stockQty <= 0 && !preorder;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'card-size-btn';
        btn.setAttribute('data-size-option', '');
        btn.setAttribute('data-size-value', label);
        btn.setAttribute('data-variant-id', String(sizeOption.variant_id || ''));
        btn.setAttribute('data-image-url', sizeOption.image_url || group.image_url || '');
        btn.setAttribute('data-variant-label', sizeOption.label || label);
        btn.setAttribute('data-stock-qty', String(stockQty));
        btn.setAttribute('data-preorder', preorder ? '1' : '0');
        btn.textContent = label;
        if (out) btn.disabled = true;
        sizeOptions.appendChild(btn);
        if (!out && !firstAvailable) {
          firstAvailable = sizeOption;
        }
      });

      if (sizePicker) {
        var multiple = sizes.length > 1;
        sizePicker.classList.toggle('single-size', !multiple);
        if (multiple) sizePicker.setAttribute('data-size-required', '1');
        else sizePicker.removeAttribute('data-size-required');
      }

      card.setAttribute('data-size-picked', sizes.length > 1 ? '0' : '1');
      if (sizeSelectedInput) sizeSelectedInput.value = sizes.length > 1 ? '' : String((sizes[0] && sizes[0].variant_id) || '');
      var hint = card.querySelector('[data-size-hint]');
      if (hint) hint.setAttribute('hidden', '');
      if (sizePicker) sizePicker.classList.remove('is-error');

      if (firstAvailable) {
        var activeBtn = sizeOptions.querySelector('[data-variant-id="' + firstAvailable.variant_id + '"]');
        if (activeBtn) activeBtn.classList.add('is-active');
        applyVariantState(firstAvailable, firstAvailable.image_url || group.image_url);
        return firstAvailable;
      }

      if (sizes[0]) {
        applyVariantState(sizes[0], sizes[0].image_url || group.image_url);
        return sizes[0];
      }

      if (img && group.image_url) img.src = group.image_url;
      return null;
    }

    function renderColorGroup() {
      var group = currentColorGroup();
      card.setAttribute('data-active-color', group.color || '');
      if (img && group.image_url) img.src = group.image_url;
      rebuildSizeOptions(group.color);
    }

    function stepColor(direction) {
      if (colorGroups.length <= 1) return;
      colorIndex = (colorIndex + direction + colorGroups.length) % colorGroups.length;
      renderColorGroup();
    }

    colorIndex = findColorIndex(card.getAttribute('data-active-color') || colorGroups[0].color);
    renderColorGroup();

    if (prevBtn) prevBtn.addEventListener('click', function() { stepColor(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function() { stepColor(1); });

    var touchStartX = null;
    swiper.addEventListener('touchstart', function(event) {
      if (!event.touches || event.touches.length === 0) return;
      touchStartX = event.touches[0].clientX;
    }, { passive: true });

    swiper.addEventListener('touchend', function(event) {
      if (touchStartX === null || !event.changedTouches || event.changedTouches.length === 0) {
        touchStartX = null;
        return;
      }
      var deltaX = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(deltaX) < 30 || colorGroups.length <= 1) return;
      stepColor(deltaX < 0 ? 1 : -1);
    }, { passive: true });
  });

  var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
  var loginUrl = <?php echo json_encode($gemoraBase . 'login.php?redirect=' . $userBase . 'index.php'); ?>;
  var cartUrl = <?php echo json_encode($userBase . 'cart.php'); ?>;
  var wishlistUrl = <?php echo json_encode($userBase . 'index.php'); ?>;

  document.addEventListener('click', function(event) {
    var wishlistBtn = event.target.closest('[data-add-wishlist]');
    if (!wishlistBtn) return;

    if (!isLoggedIn) {
      window.location.href = loginUrl;
      return;
    }

    var productId = wishlistBtn.getAttribute('data-product-id');
    if (!productId || productId === '0') return;

    wishlistBtn.disabled = true;
    var body = new URLSearchParams({ action: 'wishlist_toggle', product_id: productId });
    fetch(wishlistUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data.ok) {
          showShopToast(data.message || 'Could not update wishlist.', 'error');
          wishlistBtn.disabled = false;
          return;
        }
        var saved = !!data.saved;
        wishlistBtn.classList.toggle('is-active', saved);
        wishlistBtn.setAttribute('aria-pressed', saved ? 'true' : 'false');
        wishlistBtn.setAttribute('title', saved ? 'Remove from wishlist' : 'Add to wishlist');
        showShopToast(data.message || (saved ? 'Added to wishlist.' : 'Removed from wishlist.'), 'success');
        wishlistBtn.disabled = false;
      })
      .catch(function() {
        showShopToast('Could not update wishlist. Please try again.', 'error');
        wishlistBtn.disabled = false;
      });
  });

  document.addEventListener('click', function(event) {
    var btn = event.target.closest('[data-add-to-cart]');
    if (!btn || btn.disabled) return;

    if (!isLoggedIn) {
      window.location.href = loginUrl;
      return;
    }

    var card = btn.closest('.card');
    var sizePicker = card ? card.querySelector('[data-size-picker]') : null;
    var sizeSelectedInput = card ? card.querySelector('[data-size-selected-variant]') : null;
    var sizeHint = card ? card.querySelector('[data-size-hint]') : null;
    var sizeRequired = sizePicker && sizePicker.getAttribute('data-size-required') === '1';
    if (sizeRequired && (!sizeSelectedInput || !sizeSelectedInput.value)) {
      sizePicker.classList.add('is-error');
      if (sizeHint) sizeHint.removeAttribute('hidden');
      showShopToast('Please select a size before adding to cart.', 'error');
      return;
    }
    if (sizeSelectedInput) {
      btn.dataset.variantId = sizeSelectedInput.value || btn.dataset.variantId || '';
    }

    var variantId = btn.getAttribute('data-variant-id');
    if (!variantId || variantId === '0') return;

    btn.disabled = true;
    var body = new URLSearchParams({ action: 'add', variant_id: variantId, qty: '1' });
    fetch(cartUrl, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
      credentials: 'same-origin'
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.ok) {
          var label = btn.textContent.trim();
          btn.textContent = label.indexOf('Preorder') !== -1 ? 'Added!' : 'Added to cart!';
          showShopToast(btn.textContent, 'success');
          if (typeof data.item_count !== 'undefined') {
            updateHeaderCartBadge(data.item_count);
          }
          setTimeout(function() {
            btn.textContent = label;
            btn.disabled = false;
          }, 1500);
        } else {
          showShopToast(data.message || 'Could not add to cart.', 'error');
          btn.disabled = false;
        }
      })
      .catch(function() {
        showShopToast('Could not add to cart. Please try again.', 'error');
        btn.disabled = false;
      });
  });

  document.addEventListener('click', function(event) {
    var optionBtn = event.target.closest('[data-size-option]');
    if (!optionBtn || optionBtn.disabled) return;

    var card = optionBtn.closest('.card');
    if (!card) return;
    var picker = card.querySelector('[data-size-picker]');
    var input = card.querySelector('[data-size-selected-variant]');
    var hint = card.querySelector('[data-size-hint]');
    var btn = card.querySelector('[data-add-to-cart]');
    var img = card.querySelector('[data-variant-image]');
    var labelEl = card.querySelector('[data-variant-label]');
    if (!picker || !input) return;

    picker.querySelectorAll('[data-size-option]').forEach(function(otherBtn) {
      otherBtn.classList.remove('is-active');
    });
    optionBtn.classList.add('is-active');
    input.value = optionBtn.getAttribute('data-variant-id') || '';
    card.setAttribute('data-size-picked', '1');

    picker.classList.remove('is-error');
    if (hint) hint.setAttribute('hidden', '');

    var imageUrl = optionBtn.getAttribute('data-image-url') || '';
    if (img && imageUrl) img.src = imageUrl;
    if (labelEl) {
      labelEl.textContent = optionBtn.getAttribute('data-variant-label') || optionBtn.getAttribute('data-size-value') || 'Default';
    }

    var stock = Number(optionBtn.getAttribute('data-stock-qty') || 0);
    var preorder = Number(optionBtn.getAttribute('data-preorder') || 0) === 1;
    var isPreorder = stock <= 0 && preorder;
    var noteEl = card.querySelector('[data-variant-note]');
    var badge = card.querySelector('[data-variant-badge]');
    if (btn && input.value) {
      btn.dataset.variantId = input.value;
      btn.textContent = isPreorder ? 'Preorder' : 'Add to cart';
      btn.disabled = !input.value || (stock <= 0 && !preorder);
    }
    if (noteEl) {
      var note = '';
      if (stock > 0 && stock < 5) {
        note = stock.toLocaleString('en-US') + ' items left';
      } else if (isPreorder) {
        note = 'Preorder available';
      } else if (stock <= 0) {
        note = 'Out of stock';
      }
      if (note) {
        noteEl.textContent = note;
        noteEl.removeAttribute('hidden');
      } else {
        noteEl.textContent = '';
        noteEl.setAttribute('hidden', '');
      }
    }
    if (badge) {
      if (isPreorder) badge.removeAttribute('hidden');
      else badge.setAttribute('hidden', '');
    }
  });
</script>
</body>
</html>
