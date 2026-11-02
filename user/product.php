<?php
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? (int) ($_SESSION['user_id'] ?? 0) : 0;
$headerNavActive = 'shop';

$productId = max(0, (int) ($_GET['id'] ?? 0));

function product_img_url(?string $path): string
{
    if ($path) {
        return gemora_url(ltrim((string) $path, '/'));
    }

    return gemora_url('uploads/lace.jpg');
}

function product_stock_note(int $stockQty, bool $isPreorder): string
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

function product_render_stars(float $avg): string
{
    $rounded = (int) round($avg);
    $rounded = max(0, min(5, $rounded));
    $stars = str_repeat('★', $rounded) . str_repeat('☆', 5 - $rounded);

    return $stars;
}

function product_render_stars_exact(int $rating): string
{
    $rating = max(0, min(5, $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}

$allowedTabs = ['description', 'reviews', 'shipping'];
$activeTab = in_array($_GET['tab'] ?? '', $allowedTabs, true) ? (string) $_GET['tab'] : 'description';
$reviewFlash = null;
if (isset($_SESSION['review_flash']) && is_array($_SESSION['review_flash'])) {
    $reviewFlash = $_SESSION['review_flash'];
    unset($_SESSION['review_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && trim((string) ($_POST['action'] ?? '')) === 'submit_review') {
    $postProductId = max(0, (int) ($_POST['product_id'] ?? 0));
    $redirectUrl = $userBase . 'product.php?id=' . $postProductId . '&tab=reviews';

    if (!$isLoggedIn || $userId <= 0) {
        header('Location: ' . $gemoraBase . 'login.php?redirect=' . rawurlencode($redirectUrl));
        exit;
    }

    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));

    if ($postProductId <= 0) {
        $_SESSION['review_flash'] = ['type' => 'error', 'message' => 'Invalid product.'];
        header('Location: ' . $userBase . 'index.php');
        exit;
    }

    if ($rating < 1 || $rating > 5) {
        $_SESSION['review_flash'] = ['type' => 'error', 'message' => 'Please select a rating from 1 to 5 stars.'];
        header('Location: ' . $redirectUrl);
        exit;
    }

    try {
        $productCheck = $pdo->prepare('SELECT product_id FROM products WHERE product_id = ? LIMIT 1');
        $productCheck->execute([$postProductId]);
        if (!$productCheck->fetchColumn()) {
            $_SESSION['review_flash'] = ['type' => 'error', 'message' => 'Product not found.'];
            header('Location: ' . $userBase . 'index.php');
            exit;
        }
        $existingStmt = $pdo->prepare('SELECT review_id FROM reviews WHERE user_id = ? AND product_id = ? LIMIT 1');
        $existingStmt->execute([$userId, $postProductId]);
        $existingReviewId = (int) ($existingStmt->fetchColumn() ?: 0);
        if ($existingReviewId > 0) {
            $updateStmt = $pdo->prepare('
                UPDATE reviews
                SET rating = ?, comment = ?, created_at = CURDATE()
                WHERE review_id = ? AND user_id = ?
            ');
            $updateStmt->execute([
                $rating,
                $comment === '' ? null : $comment,
                $existingReviewId,
                $userId,
            ]);
            $_SESSION['review_flash'] = ['type' => 'success', 'message' => 'Your review was updated.'];
        } else {
            $insertStmt = $pdo->prepare('
                INSERT INTO reviews (product_id, user_id, rating, comment)
                VALUES (?, ?, ?, ?)
            ');
            $insertStmt->execute([
                $postProductId,
                $userId,
                $rating,
                $comment === '' ? null : $comment,
            ]);
            $_SESSION['review_flash'] = ['type' => 'success', 'message' => 'Thank you for your review!'];
        }
    } catch (Throwable $e) {
        error_log('Review submit failed: ' . $e->getMessage());
        $_SESSION['review_flash'] = ['type' => 'error', 'message' => 'Could not save your review. Please try again.'];
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$product = null;
$variants = [];
$imagesByVariant = [];
$reviews = [];
$userReview = null;
$relatedProducts = [];
$isWishlisted = false;

if ($productId > 0) {
    try {
        $stmt = $pdo->prepare('
            SELECT
                p.product_id,
                p.title,
                p.description,
                p.price,
                p.brand_id,
                p.sub_category_id,
                COALESCE(NULLIF(p.brand, ""), b.name, "Gemora") AS brand_name,
                sc.name AS sub_category_name,
                c.category_name
            FROM products p
            LEFT JOIN brands b ON b.brand_id = p.brand_id
            LEFT JOIN sub_categories sc ON sc.sub_category_id = p.sub_category_id
            LEFT JOIN categories c ON c.category_id = sc.category_id
            WHERE p.product_id = ?
            LIMIT 1
        ');
        $stmt->execute([$productId]);
        $product = $stmt->fetch() ?: null;

        if ($product) {
            $vStmt = $pdo->prepare('
                SELECT variant_id, size, color, stock_qty, preorder_flag
                FROM variants
                WHERE product_id = ?
                ORDER BY color, size, variant_id
            ');
            $vStmt->execute([$productId]);
            $variants = $vStmt->fetchAll();

            if (!empty($variants)) {
                $variantIds = array_map(static function ($row) {
                    return (int) ($row['variant_id'] ?? 0);
                }, $variants);
                $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
                $imgStmt = $pdo->prepare("
                    SELECT variant_id, image, sort_order
                    FROM images
                    WHERE variant_id IN ($placeholders)
                    ORDER BY variant_id, sort_order, image_id
                ");
                $imgStmt->execute($variantIds);
                foreach ($imgStmt->fetchAll() as $imgRow) {
                    $vid = (int) ($imgRow['variant_id'] ?? 0);
                    $imagesByVariant[$vid][] = (string) ($imgRow['image'] ?? '');
                }
            }

            $rStmt = $pdo->prepare('
                SELECT r.review_id, r.user_id, r.rating, r.comment, r.created_at, u.name AS user_name
                FROM reviews r
                INNER JOIN users u ON u.user_id = r.user_id
                WHERE r.product_id = ?
                ORDER BY r.created_at DESC, r.review_id DESC
            ');
            $rStmt->execute([$productId]);
            $reviews = $rStmt->fetchAll();

            if ($isLoggedIn && $userId > 0) {
                $urStmt = $pdo->prepare('
                    SELECT review_id, rating, comment, created_at
                    FROM reviews
                    WHERE user_id = ? AND product_id = ?
                    LIMIT 1
                ');
                $urStmt->execute([$userId, $productId]);
                $userReview = $urStmt->fetch() ?: null;
            }

            $subCategoryId = (int) ($product['sub_category_id'] ?? 0);
            $relStmt = $pdo->prepare('
                SELECT
                    p.product_id,
                    p.title,
                    p.price,
                    COALESCE(NULLIF(p.brand, ""), b.name, "Gemora") AS brand_name,
                    (
                        SELECT i.image
                        FROM variants v
                        INNER JOIN images i ON i.variant_id = v.variant_id
                        WHERE v.product_id = p.product_id
                        ORDER BY v.variant_id, i.sort_order, i.image_id
                        LIMIT 1
                    ) AS image_path
                FROM products p
                LEFT JOIN brands b ON b.brand_id = p.brand_id
                WHERE p.product_id != ?
                  AND p.sub_category_id = ?
                ORDER BY p.created_at DESC, p.product_id DESC
                LIMIT 4
            ');
            $relStmt->execute([$productId, $subCategoryId]);
            $relatedProducts = $relStmt->fetchAll();

            if ($isLoggedIn && $userId > 0) {
                $wStmt = $pdo->prepare('SELECT 1 FROM wishlist WHERE user_id = ? AND product_id = ? LIMIT 1');
                $wStmt->execute([$userId, $productId]);
                $isWishlisted = (bool) $wStmt->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        error_log('Product detail fetch failed: ' . $e->getMessage());
        $product = null;
    }
}

if (!$product) {
    http_response_code(404);
}

$variantOptions = [];
$colors = [];
foreach ($variants as $row) {
    $variantId = (int) ($row['variant_id'] ?? 0);
    $color = trim((string) ($row['color'] ?? ''));
    $size = trim((string) ($row['size'] ?? ''));
    $stockQty = (int) ($row['stock_qty'] ?? 0);
    $preorder = (int) ($row['preorder_flag'] ?? 0) === 1;
    $colorKey = $color !== '' ? $color : 'Default';
    $images = $imagesByVariant[$variantId] ?? [];

    if (!isset($colors[$colorKey])) {
        $colors[$colorKey] = [
            'color' => $colorKey,
            'sizes' => [],
        ];
    }

    $colors[$colorKey]['sizes'][] = [
        'variant_id' => $variantId,
        'size' => $size,
        'stock_qty' => $stockQty,
        'preorder' => $preorder,
        'images' => array_values(array_filter($images)),
    ];

    $variantOptions[] = [
        'variant_id' => $variantId,
        'color' => $colorKey,
        'size' => $size,
        'stock_qty' => $stockQty,
        'preorder' => $preorder,
        'images' => array_map('product_img_url', array_values(array_filter($images))),
    ];
}

$colorList = array_values($colors);
foreach ($colorList as &$colorGroup) {
    $colorImageUrls = [];
    foreach ($colorGroup['sizes'] as &$sizeOption) {
        $sizeImageUrls = array_map('product_img_url', array_values(array_filter($sizeOption['images'] ?? [])));
        $sizeOption['image_urls'] = !empty($sizeImageUrls) ? $sizeImageUrls : [];
        foreach ($sizeImageUrls as $imageUrl) {
            if (!in_array($imageUrl, $colorImageUrls, true)) {
                $colorImageUrls[] = $imageUrl;
            }
        }
    }
    unset($sizeOption);
    $colorGroup['image_urls'] = !empty($colorImageUrls) ? $colorImageUrls : [product_img_url(null)];
    $colorGroup['swatch_url'] = !empty($colorImageUrls) ? $colorImageUrls[0] : null;
}
unset($colorGroup);

$initialColor = $colorList[0]['color'] ?? '';
$initialSizes = $colorList[0]['sizes'] ?? [];
$initialVariant = $initialSizes[0] ?? null;

foreach ($colorList as $colorGroup) {
    foreach ($colorGroup['sizes'] as $sizeOption) {
        if ((int) ($sizeOption['stock_qty'] ?? 0) > 0 || !empty($sizeOption['preorder'])) {
            $initialColor = $colorGroup['color'];
            $initialSizes = $colorGroup['sizes'];
            $initialVariant = $sizeOption;
            break 2;
        }
    }
}

$initialImages = [product_img_url(null)];
foreach ($colorList as $colorGroup) {
    if ($colorGroup['color'] === $initialColor) {
        $initialImages = $colorGroup['image_urls'];
        break;
    }
}

$reviewCount = count($reviews);
$avgRating = 0.0;
if ($reviewCount > 0) {
    $totalRating = 0;
    foreach ($reviews as $review) {
        $totalRating += (int) ($review['rating'] ?? 0);
    }
    $avgRating = $totalRating / $reviewCount;
}

$initialStock = (int) ($initialVariant['stock_qty'] ?? 0);
$initialPreorder = !empty($initialVariant['preorder']);
$initialIsPreorder = $initialStock <= 0 && $initialPreorder;
$initialStockNote = $initialVariant ? product_stock_note($initialStock, $initialIsPreorder) : 'Out of stock';
$initialVariantId = (int) ($initialVariant['variant_id'] ?? 0);
$canAddToCart = $initialVariantId > 0 && ($initialStock > 0 || $initialIsPreorder);
$hasMultipleSizes = count($initialSizes) > 1;
$formRating = (int) ($userReview['rating'] ?? 0);
$formComment = (string) ($userReview['comment'] ?? '');

$pageTitle = $product
    ? htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8') . ' — Gemora'
    : 'Product Not Found — Gemora';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="page">
        <?php include __DIR__ . '/../include/header.php'; ?>

        <?php if (!$product): ?>
        <main class="product-not-found">
            <h1>Product not found</h1>
            <p>The item you are looking for may have been removed or the link is incorrect.</p>
            <a class="btn primary" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Back to shop</a>
        </main>
        <?php else: ?>
        <main class="product-detail">
            <div class="product-gallery">
                <div class="main-image">
                    <img
                        id="mainProductImage"
                        src="<?php echo htmlspecialchars($initialImages[0], ENT_QUOTES, 'UTF-8'); ?>"
                        alt="<?php echo htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="thumbnail-gallery" id="thumbnailGallery" <?php echo count($initialImages) <= 1 ? 'hidden' : ''; ?>>
                    <?php foreach ($initialImages as $index => $imageUrl): ?>
                    <div class="thumbnail<?php echo $index === 0 ? ' active' : ''; ?>" data-thumb-index="<?php echo $index; ?>">
                        <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="product-info">
                <nav class="breadcrumb">
                    <a href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Home</a> /
                    <?php if (!empty($product['category_name'])): ?>
                    <a href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $product['category_name'], ENT_QUOTES, 'UTF-8'); ?></a> /
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                </nav>

                <h1><?php echo htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="price">MMK <?php echo number_format((int) ($product['price'] ?? 0)); ?></div>

                <?php if ($reviewCount > 0): ?>
                <div class="rating">
                    <div class="stars" aria-label="<?php echo htmlspecialchars(number_format($avgRating, 1), ENT_QUOTES, 'UTF-8'); ?> out of 5"><?php echo product_render_stars($avgRating); ?></div>
                    <span class="review-count"><?php echo number_format($reviewCount); ?> <?php echo $reviewCount === 1 ? 'Review' : 'Reviews'; ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($product['description'])): ?>
                <div class="description">
                    <?php echo nl2br(htmlspecialchars((string) $product['description'], ENT_QUOTES, 'UTF-8')); ?>
                </div>
                <?php endif; ?>

                <div class="product-options">
                    <?php if (!empty($colorList)): ?>
                    <div class="option">
                        <label>Color</label>
                        <div class="color-options" id="colorOptions">
                            <?php foreach ($colorList as $colorGroup): ?>
                            <button
                                type="button"
                                class="color-option<?php echo $colorGroup['color'] === $initialColor ? ' active' : ''; ?><?php echo empty($colorGroup['swatch_url']) ? ' color-option-fallback' : ''; ?>"
                                data-color="<?php echo htmlspecialchars($colorGroup['color'], ENT_QUOTES, 'UTF-8'); ?>"
                                title="<?php echo htmlspecialchars($colorGroup['color'], ENT_QUOTES, 'UTF-8'); ?>"
                                aria-label="<?php echo htmlspecialchars($colorGroup['color'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if (!empty($colorGroup['swatch_url'])): ?>
                                <img
                                    src="<?php echo htmlspecialchars($colorGroup['swatch_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="<?php echo htmlspecialchars($colorGroup['color'], ENT_QUOTES, 'UTF-8'); ?>"
                                    loading="lazy">
                                <?php else: ?>
                                <span class="color-option-label"><?php echo htmlspecialchars(strtoupper(substr($colorGroup['color'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="color-selected-name" id="colorSelectedName"><?php echo htmlspecialchars($initialColor, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($initialSizes)): ?>
                    <div class="option">
                        <div class="card-size-picker<?php echo $hasMultipleSizes ? '' : ' single-size'; ?>" id="sizePicker" data-size-picker <?php echo $hasMultipleSizes ? 'data-size-required="1"' : ''; ?>>
                            <span class="card-size-picker-label">Size</span>
                            <div class="card-size-options" id="sizeOptions">
                                <?php foreach ($initialSizes as $sizeOption): ?>
                                <?php
                                $sizeLabel = trim((string) ($sizeOption['size'] ?? ''));
                                if ($sizeLabel === '') {
                                    $sizeLabel = 'One size';
                                }
                                $sizeVariantId = (int) ($sizeOption['variant_id'] ?? 0);
                                $sizeStock = (int) ($sizeOption['stock_qty'] ?? 0);
                                $sizePreorder = !empty($sizeOption['preorder']);
                                $sizeOut = $sizeStock <= 0 && !$sizePreorder;
                                $sizeActive = !$hasMultipleSizes || $sizeVariantId === $initialVariantId;
                                ?>
                                <button
                                    type="button"
                                    class="card-size-btn<?php echo $sizeActive && !$sizeOut ? ' is-active' : ''; ?><?php echo $sizeOut ? ' is-disabled' : ''; ?>"
                                    data-size-option
                                    data-size-value="<?php echo htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-variant-id="<?php echo $sizeVariantId; ?>"
                                    data-stock-qty="<?php echo $sizeStock; ?>"
                                    data-preorder="<?php echo $sizePreorder ? '1' : '0'; ?>"
                                    <?php echo $sizeOut ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" id="sizeSelectedVariant" data-size-selected-variant value="<?php echo !$hasMultipleSizes ? $initialVariantId : ($initialVariantId > 0 ? (string) $initialVariantId : ''); ?>">
                            <div class="card-size-hint" id="sizeHint" hidden>Please choose a size</div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="option">
                        <label for="quantity">Quantity</label>
                        <div class="quantity-selector">
                            <button type="button" class="quantity-btn" id="qtyMinus" aria-label="Decrease quantity">-</button>
                            <input type="number" id="quantity" value="1" min="1" max="10">
                            <button type="button" class="quantity-btn" id="qtyPlus" aria-label="Increase quantity">+</button>
                        </div>
                    </div>
                </div>

                <div class="stock-note" id="stockNote" <?php echo $initialStockNote === '' ? 'hidden' : ''; ?>><?php echo htmlspecialchars($initialStockNote, ENT_QUOTES, 'UTF-8'); ?></div>

                <div class="actions">
                    <button
                        type="button"
                        class="btn primary add-to-cart"
                        id="addToCartBtn"
                        data-variant-id="<?php echo $initialVariantId; ?>"
                        <?php echo $canAddToCart ? '' : 'disabled'; ?>>
                        <?php echo $initialIsPreorder ? 'Preorder' : 'Add to Cart'; ?>
                    </button>
                    <button
                        type="button"
                        class="btn secondary wishlist-toggle<?php echo $isWishlisted ? ' is-active' : ''; ?>"
                        id="wishlistBtn"
                        data-product-id="<?php echo $productId; ?>"
                        aria-pressed="<?php echo $isWishlisted ? 'true' : 'false'; ?>">
                        <?php echo $isWishlisted ? 'In Wishlist' : 'Add to Wishlist'; ?>
                    </button>
                </div>

                <div class="product-meta">
                    <div class="meta-item">
                        <span class="meta-label">SKU:</span>
                        <span>GEM-<?php echo str_pad((string) $productId, 4, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Brand:</span>
                        <span><?php echo htmlspecialchars((string) $product['brand_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if (!empty($product['sub_category_name'])): ?>
                    <div class="meta-item">
                        <span class="meta-label">Category:</span>
                        <span><?php echo htmlspecialchars((string) ($product['category_name'] ?? '') . ($product['category_name'] ? ' — ' : '') . $product['sub_category_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <section class="product-tabs">
            <div class="tabs">
                <button type="button" class="tab<?php echo $activeTab === 'description' ? ' active' : ''; ?>" data-tab="description">Description</button>
                <button type="button" class="tab<?php echo $activeTab === 'reviews' ? ' active' : ''; ?>" data-tab="reviews">Reviews (<?php echo number_format($reviewCount); ?>)</button>
                <button type="button" class="tab<?php echo $activeTab === 'shipping' ? ' active' : ''; ?>" data-tab="shipping">Shipping &amp; Returns</button>
            </div>

            <div class="tab-content">
                <div class="tab-pane<?php echo $activeTab === 'description' ? ' active' : ''; ?>" data-pane="description">
                    <h3>Product Description</h3>
                    <?php if (!empty($product['description'])): ?>
                    <p><?php echo nl2br(htmlspecialchars((string) $product['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                    <?php else: ?>
                    <p>No additional description available for this product.</p>
                    <?php endif; ?>
                </div>

                <div class="tab-pane<?php echo $activeTab === 'reviews' ? ' active' : ''; ?>" data-pane="reviews">
                    <div class="reviews-section">
                        <div class="reviews-summary">
                            <h3>Customer Reviews</h3>
                            <?php if ($reviewCount > 0): ?>
                            <div class="reviews-average">
                                <span class="reviews-average-score"><?php echo htmlspecialchars(number_format($avgRating, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="review-stars" aria-hidden="true"><?php echo product_render_stars($avgRating); ?></span>
                                <span class="reviews-average-count">Based on <?php echo number_format($reviewCount); ?> <?php echo $reviewCount === 1 ? 'review' : 'reviews'; ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($reviewFlash): ?>
                        <div class="review-feedback <?php echo ($reviewFlash['type'] ?? '') === 'error' ? 'is-error' : 'is-success'; ?>" role="alert">
                            <?php echo htmlspecialchars((string) ($reviewFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php endif; ?>

                        <div class="review-form-card">
                            <h4><?php echo $userReview ? 'Update your review' : 'Write a review'; ?></h4>
                            <?php if ($isLoggedIn): ?>
                            <form class="review-form" method="post" action="<?php echo htmlspecialchars($userBase . 'product.php?id=' . $productId, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="submit_review">
                                <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
                                <input type="hidden" name="rating" id="reviewRating" value="<?php echo $formRating > 0 ? $formRating : ''; ?>" required>

                                <label class="review-form-label">Your rating</label>
                                <div class="review-star-input" id="reviewStarInput" role="radiogroup" aria-label="Rating">
                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <button
                                        type="button"
                                        class="review-star-btn<?php echo $formRating >= $star ? ' is-active' : ''; ?>"
                                        data-star-value="<?php echo $star; ?>"
                                        aria-label="<?php echo $star; ?> star<?php echo $star === 1 ? '' : 's'; ?>"
                                        aria-pressed="<?php echo $formRating >= $star ? 'true' : 'false'; ?>">
                                        ★
                                    </button>
                                    <?php endfor; ?>
                                </div>

                                <label class="review-form-label" for="reviewComment">Your review</label>
                                <textarea
                                    id="reviewComment"
                                    name="comment"
                                    class="review-textarea"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="Share your experience with this product..."><?php echo htmlspecialchars($formComment, ENT_QUOTES, 'UTF-8'); ?></textarea>

                                <button type="submit" class="btn primary review-submit-btn">
                                    <?php echo $userReview ? 'Update review' : 'Submit review'; ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <p class="review-login-note">
                                <a href="<?php echo htmlspecialchars($gemoraBase . 'login.php?redirect=' . rawurlencode($userBase . 'product.php?id=' . $productId . '&tab=reviews'), ENT_QUOTES, 'UTF-8'); ?>">Log in</a>
                                to write a review.
                            </p>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($reviews)): ?>
                        <p class="empty-reviews">No reviews yet. Be the first to share your experience.</p>
                        <?php else: ?>
                        <div class="review-list">
                            <?php foreach ($reviews as $review): ?>
                            <?php $isOwnReview = $isLoggedIn && (int) ($review['user_id'] ?? 0) === $userId; ?>
                            <article class="review-item<?php echo $isOwnReview ? ' is-own' : ''; ?>">
                                <div class="review-head">
                                    <div class="review-author">
                                        <strong><?php echo htmlspecialchars((string) ($review['user_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if ($isOwnReview): ?>
                                        <span class="review-you-badge">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="review-stars" aria-label="<?php echo (int) ($review['rating'] ?? 0); ?> out of 5 stars"><?php echo product_render_stars_exact((int) ($review['rating'] ?? 0)); ?></span>
                                    <?php if (!empty($review['created_at'])): ?>
                                    <time datetime="<?php echo htmlspecialchars((string) $review['created_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $review['created_at'], ENT_QUOTES, 'UTF-8'); ?></time>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($review['comment'])): ?>
                                <p><?php echo nl2br(htmlspecialchars((string) $review['comment'], ENT_QUOTES, 'UTF-8')); ?></p>
                                <?php else: ?>
                                <p class="review-no-comment">No written comment.</p>
                                <?php endif; ?>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tab-pane<?php echo $activeTab === 'shipping' ? ' active' : ''; ?>" data-pane="shipping">
                    <h3>Shipping &amp; Returns</h3>
                    <p>Orders are processed within 1–2 business days. Standard delivery across Myanmar typically takes 3–7 business days depending on your region.</p>
                    <p>Eligible unworn items with tags attached may be returned within 14 days of delivery. Preorder items follow the estimated arrival window shown at checkout.</p>
                </div>
            </div>
        </section>

        <?php if (!empty($relatedProducts)): ?>
        <section class="related-products">
            <h2>You May Also Like</h2>
            <div class="product-grid">
                <?php foreach ($relatedProducts as $related): ?>
                <a class="related-product-card" href="<?php echo htmlspecialchars($userBase . 'product.php?id=' . (int) $related['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                    <img src="<?php echo htmlspecialchars(product_img_url($related['image_path'] ?? null), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $related['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                    <h3><?php echo htmlspecialchars((string) $related['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <div class="price">
                        <span class="current">MMK <?php echo number_format((int) ($related['price'] ?? 0)); ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="product-toast" id="productToast" role="status" aria-live="polite"></div>

    <style>
        .product-detail {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--desktop-gap);
            margin: 24px 0 48px;
        }

        .product-not-found {
            text-align: center;
            padding: 80px 24px;
        }

        .product-not-found h1 {
            margin: 0 0 12px;
        }

        .product-not-found p {
            color: var(--muted);
            margin: 0 0 24px;
        }

        .product-gallery {
            position: sticky;
            top: 20px;
        }

        .main-image {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            aspect-ratio: 1;
        }

        .main-image img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }

        .thumbnail-gallery {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .thumbnail-gallery[hidden] {
            display: none;
        }

        .thumbnail {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
        }

        .thumbnail:hover,
        .thumbnail.active {
            border-color: var(--accent);
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-info {
            padding: 20px 0;
        }

        .breadcrumb {
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 16px;
        }

        .breadcrumb a {
            color: var(--muted);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        .product-info h1 {
            font-size: 28px;
            margin: 0 0 12px;
            font-weight: 700;
        }

        .product-info .price {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 16px;
        }

        .rating {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .stars,
        .review-stars {
            color: #f59e0b;
            font-size: 16px;
        }

        .review-count {
            color: var(--muted);
            font-size: 14px;
        }

        .description {
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .product-options {
            background: var(--bg);
            padding: 20px;
            border-radius: var(--radius);
            margin-bottom: 16px;
        }

        .option {
            margin-bottom: 16px;
        }

        .option:last-child {
            margin-bottom: 0;
        }

        .option label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
        }

        .color-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .color-option {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid rgba(0, 0, 0, 0.12);
            cursor: pointer;
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            padding: 0;
            overflow: hidden;
            background: var(--card);
            flex-shrink: 0;
        }

        .color-option img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .color-option-fallback {
            background: var(--bg);
        }

        .color-option-label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
        }

        .color-selected-name {
            margin-top: 8px;
            font-size: 13px;
            color: var(--muted);
        }

        .color-option:hover {
            transform: translateY(-2px);
        }

        .color-option.active {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9), 0 0 0 4px var(--accent);
        }

        .color-option.active::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #fff;
            font-size: 12px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.65);
            z-index: 1;
            pointer-events: none;
        }

        .card-size-picker {
            margin-top: 0;
        }

        .card-size-picker-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
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

        .card-size-btn:hover:not(:disabled) {
            border-color: var(--accent);
            color: var(--accent);
        }

        .card-size-btn.is-active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .card-size-btn:disabled,
        .card-size-btn.is-disabled {
            opacity: 0.45;
            cursor: not-allowed;
            text-decoration: line-through;
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

        .card-size-picker.single-size .card-size-hint {
            display: none !important;
        }

        .card-size-picker.is-error .card-size-btn:not(:disabled) {
            border-color: #c33;
        }

        .quantity-selector {
            display: flex;
            align-items: center;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            overflow: hidden;
            width: 120px;
        }

        .quantity-btn {
            background: var(--card);
            border: none;
            padding: 10px 16px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .quantity-btn:hover {
            background: var(--bg);
        }

        .quantity-selector input[type="number"] {
            width: 40px;
            text-align: center;
            border: none;
            border-left: 1px solid var(--glass-border);
            border-right: 1px solid var(--glass-border);
            padding: 10px 0;
            -moz-appearance: textfield;
        }

        .quantity-selector input[type="number"]::-webkit-outer-spin-button,
        .quantity-selector input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .stock-note {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 16px;
        }

        .stock-note[hidden] {
            display: none;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn.primary {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 4px 12px rgba(19, 63, 76, 0.2);
        }

        .btn.primary:hover:not(:disabled) {
            background: #4a5a60;
            transform: translateY(-1px);
        }

        .btn.primary:active:not(:disabled) {
            background: #0f2f38;
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(19, 63, 76, 0.18);
        }

        .btn.primary:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .btn.secondary {
            background: var(--card);
            color: var(--text);
            border: 1px solid var(--glass-border);
        }

        .btn.secondary:hover {
            background: var(--bg);
            transform: translateY(-1px);
        }

        .btn.secondary:active {
            background: var(--accent-2);
            border-color: var(--muted);
            transform: translateY(0);
        }

        .wishlist-toggle.is-active {
            color: #e91e63;
            border-color: rgba(233, 30, 99, 0.25);
            background: rgba(233, 30, 99, 0.08);
        }

        .product-meta {
            padding: 16px 0;
            border-top: 1px solid var(--glass-border);
        }

        .meta-item {
            display: flex;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .meta-item:last-child {
            margin-bottom: 0;
        }

        .meta-label {
            font-weight: 600;
            min-width: 80px;
            color: var(--muted);
        }

        .product-tabs {
            margin-bottom: 48px;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 24px;
            gap: 4px;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab:hover {
            color: var(--text);
        }

        .tab.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .tab-content {
            padding: 0 16px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        .review-list {
            display: grid;
            gap: 16px;
        }

        .reviews-section {
            display: grid;
            gap: 24px;
        }

        .reviews-summary h3 {
            margin: 0 0 8px;
        }

        .reviews-average {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 14px;
        }

        .reviews-average-score {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            line-height: 1;
        }

        .reviews-average-count {
            color: var(--muted);
        }

        .review-feedback {
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 14px;
        }

        .review-feedback.is-success {
            background: #e8f4ea;
            border: 1px solid #b4d8b8;
            color: #245d2b;
        }

        .review-feedback.is-error {
            background: #fff7f7;
            border: 1px solid #e4b5b5;
            color: #7f2020;
        }

        .review-form-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 20px;
        }

        .review-form-card h4 {
            margin: 0 0 16px;
            font-size: 16px;
        }

        .review-form {
            display: grid;
            gap: 12px;
        }

        .review-form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .review-star-input {
            display: flex;
            gap: 6px;
        }

        .review-star-btn {
            border: none;
            background: none;
            font-size: 28px;
            line-height: 1;
            color: #d1d5db;
            cursor: pointer;
            padding: 0;
            transition: color 0.15s ease, transform 0.15s ease;
        }

        .review-star-btn:hover,
        .review-star-btn.is-active {
            color: #f59e0b;
        }

        .review-star-btn:hover {
            transform: scale(1.08);
        }

        .review-textarea {
            width: 100%;
            min-height: 110px;
            padding: 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            font: inherit;
            resize: vertical;
            background: var(--bg);
            color: var(--text);
        }

        .review-textarea:focus {
            outline: none;
            border-color: var(--accent);
        }

        .review-submit-btn {
            justify-self: start;
        }

        .review-login-note {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .review-login-note a {
            color: var(--accent);
            font-weight: 600;
        }

        .review-item {
            padding: 16px;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            background: var(--card);
        }

        .review-head {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .review-author {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .review-you-badge {
            font-size: 11px;
            font-weight: 700;
            color: var(--accent);
            background: rgba(45, 106, 79, 0.1);
            padding: 2px 8px;
            border-radius: 999px;
        }

        .review-item.is-own {
            border-color: rgba(45, 106, 79, 0.25);
        }

        .review-no-comment {
            color: var(--muted);
            font-style: italic;
        }

        .review-head time {
            color: var(--muted);
            font-size: 13px;
            margin-left: auto;
        }

        .empty-reviews {
            color: var(--muted);
        }

        .related-products {
            margin-bottom: 48px;
        }

        .related-products h2 {
            text-align: center;
            margin-bottom: 24px;
            font-size: 24px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .related-product-card {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            padding: 16px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .related-product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .related-product-card img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 12px;
        }

        .related-product-card h3 {
            font-size: 16px;
            margin: 0 0 8px;
        }

        .related-product-card .price .current {
            font-size: 18px;
            font-weight: 700;
            color: var(--accent);
        }

        .product-toast {
            position: fixed;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%) translateY(20px);
            background: #fff;
            border: 1px solid var(--glass-border);
            border-radius: 999px;
            padding: 10px 18px;
            box-shadow: var(--shadow);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 100;
            font-size: 14px;
        }

        .product-toast.is-visible {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .product-toast.is-error {
            border-color: #e4b5b5;
            background: #fff7f7;
            color: #7f2020;
        }

        @media (max-width: 1024px) {
            .product-detail {
                grid-template-columns: 1fr;
            }

            .product-gallery {
                position: static;
            }

            .product-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .product-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .product-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                overflow-x: auto;
                padding-bottom: 8px;
            }

            .tab {
                white-space: nowrap;
                padding: 12px 16px;
            }
        }
    </style>

    <?php if ($product): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      var variantData = <?php echo json_encode([
          'colors' => $colorList,
          'variants' => $variantOptions,
          'fallbackImage' => product_img_url(null),
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
      var loginUrl = <?php echo json_encode($gemoraBase . 'login.php?redirect=' . rawurlencode($userBase . 'product.php?id=' . $productId)); ?>;
      var cartUrl = <?php echo json_encode($userBase . 'cart.php'); ?>;
      var wishlistUrl = <?php echo json_encode($userBase . 'index.php'); ?>;

      var mainImage = document.getElementById('mainProductImage');
      var thumbnailGallery = document.getElementById('thumbnailGallery');
      var sizePicker = document.getElementById('sizePicker');
      var sizeOptions = document.getElementById('sizeOptions');
      var sizeSelectedInput = document.getElementById('sizeSelectedVariant');
      var sizeHint = document.getElementById('sizeHint');
      var colorSelectedName = document.getElementById('colorSelectedName');
      var stockNote = document.getElementById('stockNote');
      var addToCartBtn = document.getElementById('addToCartBtn');
      var wishlistBtn = document.getElementById('wishlistBtn');
      var quantityInput = document.getElementById('quantity');
      var toast = document.getElementById('productToast');
      var toastTimer = null;

      function showToast(message, isError) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.toggle('is-error', !!isError);
        toast.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function() {
          toast.classList.remove('is-visible');
        }, 2600);
      }

      function updateHeaderCartBadge(count) {
        var badge = document.querySelector('.cart-icon-btn .icon-count-badge');
        if (!badge) return;
        var qty = Number(count) || 0;
        badge.textContent = String(qty);
        badge.classList.toggle('is-hidden', qty <= 0);
      }

      function colorGroupByName(color) {
        return variantData.colors.find(function(item) {
          return item.color === color;
        }) || null;
      }

      function sizesForColor(color) {
        var group = colorGroupByName(color);
        return group ? group.sizes : [];
      }

      function imagesForColor(color) {
        var group = colorGroupByName(color);
        if (group && group.image_urls && group.image_urls.length) {
          return group.image_urls;
        }
        return [variantData.fallbackImage];
      }

      function imagesForVariant(variantId) {
        var variant = variantById(variantId);
        if (variant && variant.images && variant.images.length) {
          return variant.images;
        }
        if (variant && variant.color) {
          return imagesForColor(variant.color);
        }
        return [variantData.fallbackImage];
      }

      function variantById(variantId) {
        return variantData.variants.find(function(item) {
          return Number(item.variant_id) === Number(variantId);
        }) || null;
      }

      function stockNoteText(stockQty, preorder) {
        if (stockQty > 0 && stockQty < 5) {
          return stockQty.toLocaleString('en-US') + ' items left';
        }
        if (stockQty <= 0 && preorder) {
          return 'Preorder available';
        }
        if (stockQty <= 0) {
          return 'Out of stock';
        }
        return '';
      }

      function renderGallery(images) {
        var galleryImages = images && images.length ? images : [variantData.fallbackImage];
        if (mainImage) {
          mainImage.src = galleryImages[0];
        }
        if (!thumbnailGallery) return;
        if (galleryImages.length <= 1) {
          thumbnailGallery.innerHTML = '';
          thumbnailGallery.setAttribute('hidden', '');
          return;
        }
        thumbnailGallery.removeAttribute('hidden');
        thumbnailGallery.innerHTML = galleryImages.map(function(src, index) {
          return '<div class="thumbnail' + (index === 0 ? ' active' : '') + '" data-thumb-index="' + index + '">' +
            '<img src="' + src.replace(/"/g, '&quot;') + '" alt="">' +
          '</div>';
        }).join('');
      }

      function applyVariant(variantId) {
        var variant = variantById(variantId);
        if (!variant || !addToCartBtn) return;

        var stockQty = Number(variant.stock_qty || 0);
        var preorder = !!variant.preorder;
        var isPreorder = stockQty <= 0 && preorder;
        var canBuy = stockQty > 0 || isPreorder;
        var note = stockNoteText(stockQty, preorder);

        addToCartBtn.dataset.variantId = String(variantId);
        addToCartBtn.textContent = isPreorder ? 'Preorder' : 'Add to Cart';
        addToCartBtn.disabled = !canBuy;

        if (sizeSelectedInput) {
          sizeSelectedInput.value = String(variantId);
        }

        if (stockNote) {
          stockNote.textContent = note;
          if (note) stockNote.removeAttribute('hidden');
          else stockNote.setAttribute('hidden', '');
        }

        renderGallery(imagesForVariant(variantId));
      }

      function setActiveSizeButton(variantId) {
        if (!sizeOptions) return;
        sizeOptions.querySelectorAll('[data-size-option]').forEach(function(btn) {
          var matches = btn.getAttribute('data-variant-id') === String(variantId);
          btn.classList.toggle('is-active', matches);
        });
      }

      function rebuildSizeOptions(color) {
        if (!sizeOptions) return null;

        var sizes = sizesForColor(color);
        sizeOptions.innerHTML = '';

        var firstAvailable = null;
        sizes.forEach(function(sizeOption) {
          var label = sizeOption.size || 'One size';
          var stockQty = Number(sizeOption.stock_qty || 0);
          var preorder = !!sizeOption.preorder;
          var out = stockQty <= 0 && !preorder;
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'card-size-btn' + (out ? ' is-disabled' : '');
          btn.setAttribute('data-size-option', '');
          btn.setAttribute('data-size-value', label);
          btn.setAttribute('data-variant-id', String(sizeOption.variant_id));
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
          if (multiple) {
            sizePicker.setAttribute('data-size-required', '1');
          } else {
            sizePicker.removeAttribute('data-size-required');
          }
        }

        if (sizeSelectedInput) {
          sizeSelectedInput.value = '';
        }
        if (sizeHint) sizeHint.setAttribute('hidden', '');
        if (sizePicker) sizePicker.classList.remove('is-error');

        if (firstAvailable) {
          setActiveSizeButton(firstAvailable.variant_id);
          applyVariant(firstAvailable.variant_id);
          return firstAvailable.variant_id;
        }

        if (sizes[0]) {
          setActiveSizeButton(sizes[0].variant_id);
          applyVariant(sizes[0].variant_id);
          return sizes[0].variant_id;
        }

        if (addToCartBtn) {
          addToCartBtn.disabled = true;
          addToCartBtn.dataset.variantId = '';
        }
        if (stockNote) {
          stockNote.textContent = 'Out of stock';
          stockNote.removeAttribute('hidden');
        }

        return null;
      }

      document.querySelectorAll('.tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
          var target = tab.getAttribute('data-tab');
          document.querySelectorAll('.tab').forEach(function(other) {
            other.classList.toggle('active', other === tab);
          });
          document.querySelectorAll('.tab-pane').forEach(function(pane) {
            pane.classList.toggle('active', pane.getAttribute('data-pane') === target);
          });
        });
      });

      var reviewRatingInput = document.getElementById('reviewRating');
      var reviewStarInput = document.getElementById('reviewStarInput');
      var reviewForm = document.querySelector('.review-form');

      function setReviewRating(value) {
        var rating = Number(value) || 0;
        if (!reviewRatingInput || !reviewStarInput) return;
        reviewRatingInput.value = rating > 0 ? String(rating) : '';
        reviewStarInput.querySelectorAll('.review-star-btn').forEach(function(btn) {
          var starValue = Number(btn.getAttribute('data-star-value') || 0);
          var active = rating >= starValue;
          btn.classList.toggle('is-active', active);
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      }

      reviewStarInput?.addEventListener('click', function(event) {
        var btn = event.target.closest('.review-star-btn');
        if (!btn) return;
        setReviewRating(btn.getAttribute('data-star-value'));
      });

      reviewForm?.addEventListener('submit', function(event) {
        if (!reviewRatingInput || !reviewRatingInput.value) {
          event.preventDefault();
          showToast('Please select a star rating before submitting.', true);
        }
      });

      document.getElementById('colorOptions')?.addEventListener('click', function(event) {
        var btn = event.target.closest('.color-option');
        if (!btn) return;
        var color = btn.getAttribute('data-color');
        document.querySelectorAll('.color-option').forEach(function(other) {
          other.classList.toggle('active', other === btn);
        });
        if (colorSelectedName) {
          colorSelectedName.textContent = color || '';
        }
        renderGallery(imagesForColor(color));
        rebuildSizeOptions(color);
      });

      sizeOptions?.addEventListener('click', function(event) {
        var btn = event.target.closest('[data-size-option]');
        if (!btn || btn.disabled) return;
        var variantId = btn.getAttribute('data-variant-id');
        if (!variantId) return;

        sizeOptions.querySelectorAll('[data-size-option]').forEach(function(other) {
          other.classList.remove('is-active');
        });
        btn.classList.add('is-active');
        if (sizePicker) sizePicker.classList.remove('is-error');
        if (sizeHint) sizeHint.setAttribute('hidden', '');
        applyVariant(variantId);
      });

      thumbnailGallery?.addEventListener('click', function(event) {
        var thumb = event.target.closest('.thumbnail');
        if (!thumb || !mainImage) return;
        var img = thumb.querySelector('img');
        if (!img) return;
        mainImage.src = img.src;
        thumbnailGallery.querySelectorAll('.thumbnail').forEach(function(other) {
          other.classList.toggle('active', other === thumb);
        });
      });

      document.getElementById('qtyMinus')?.addEventListener('click', function() {
        var value = parseInt(quantityInput.value, 10) || 1;
        if (value > 1) quantityInput.value = String(value - 1);
      });

      document.getElementById('qtyPlus')?.addEventListener('click', function() {
        var value = parseInt(quantityInput.value, 10) || 1;
        if (value < 10) quantityInput.value = String(value + 1);
      });

      addToCartBtn?.addEventListener('click', function() {
        if (!isLoggedIn) {
          window.location.href = loginUrl;
          return;
        }

        var sizeRequired = sizePicker && sizePicker.getAttribute('data-size-required') === '1';
        if (sizeRequired && sizeSelectedInput && !sizeSelectedInput.value) {
          if (sizePicker) sizePicker.classList.add('is-error');
          if (sizeHint) sizeHint.removeAttribute('hidden');
          showToast('Please select a size before adding to cart.', true);
          return;
        }

        var variantId = addToCartBtn.getAttribute('data-variant-id');
        var qty = Math.max(1, parseInt(quantityInput.value, 10) || 1);
        if (!variantId || variantId === '0' || addToCartBtn.disabled) return;

        addToCartBtn.disabled = true;
        var body = new URLSearchParams({ action: 'add', variant_id: variantId, qty: String(qty) });
        fetch(cartUrl, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: body,
          credentials: 'same-origin'
        })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (!data.ok) {
              showToast(data.message || 'Could not add to cart.', true);
              addToCartBtn.disabled = false;
              return;
            }
            var label = addToCartBtn.textContent.trim();
            addToCartBtn.textContent = label.indexOf('Preorder') !== -1 ? 'Added!' : 'Added to cart!';
            showToast(data.message || addToCartBtn.textContent, false);
            if (typeof data.item_count !== 'undefined') {
              updateHeaderCartBadge(data.item_count);
            }
            setTimeout(function() {
              addToCartBtn.textContent = label;
              addToCartBtn.disabled = false;
            }, 1500);
          })
          .catch(function() {
            showToast('Could not add to cart. Please try again.', true);
            addToCartBtn.disabled = false;
          });
      });

      wishlistBtn?.addEventListener('click', function() {
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
              showToast(data.message || 'Could not update wishlist.', true);
              wishlistBtn.disabled = false;
              return;
            }
            var saved = !!data.saved;
            wishlistBtn.classList.toggle('is-active', saved);
            wishlistBtn.setAttribute('aria-pressed', saved ? 'true' : 'false');
            wishlistBtn.textContent = saved ? 'In Wishlist' : 'Add to Wishlist';
            showToast(data.message || (saved ? 'Added to wishlist.' : 'Removed from wishlist.'), false);
            wishlistBtn.disabled = false;
          })
          .catch(function() {
            showToast('Could not update wishlist. Please try again.', true);
            wishlistBtn.disabled = false;
          });
      });
    });
    </script>
    <?php endif; ?>
</body>
</html>
