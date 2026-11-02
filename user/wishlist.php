<?php
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/user_paths.php';

if (!isset($_SESSION['user_id'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? ($userBase . 'wishlist.php');
    header('Location: ' . $gemoraBase . 'login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$userId = (int) $_SESSION['user_id'];

function wishlist_img_url(?string $path): string
{
    if ($path) {
        return gemora_url(ltrim((string) $path, '/'));
    }
    return gemora_url('uploads/lace.jpg');
}

function wishlist_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'remove') {
            $wishlistId = (int) ($_POST['wishlist_id'] ?? 0);
            if ($wishlistId <= 0) {
                wishlist_json(['ok' => false, 'message' => 'Invalid item.'], 400);
            }
            $stmt = $pdo->prepare('DELETE FROM wishlist WHERE wishlist_id = ? AND user_id = ?');
            $stmt->execute([$wishlistId, $userId]);
            wishlist_json(['ok' => true, 'message' => 'Removed from wishlist.']);
        }

        if ($action === 'clear') {
            $stmt = $pdo->prepare('DELETE FROM wishlist WHERE user_id = ?');
            $stmt->execute([$userId]);
            wishlist_json(['ok' => true, 'message' => 'Wishlist cleared.']);
        }
    } catch (Throwable $e) {
        error_log('Wishlist action failed: ' . $e->getMessage());
        wishlist_json(['ok' => false, 'message' => 'Could not update wishlist.'], 500);
    }

    if ($isAjax) {
        wishlist_json(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
}

$wishlistItems = [];
try {
    $stmt = $pdo->prepare('
        SELECT
            w.wishlist_id,
            p.product_id,
            p.title,
            p.price,
            COALESCE(NULLIF(p.brand, ""), b.name, "Unknown") AS brand_name,
            v.variant_id,
            v.size,
            v.color,
            v.stock_qty,
            v.preorder_flag,
            (
                SELECT i.image
                FROM images i
                WHERE i.variant_id = v.variant_id
                ORDER BY i.sort_order, i.image_id
                LIMIT 1
            ) AS image_path
        FROM wishlist w
        INNER JOIN products p ON p.product_id = w.product_id
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        LEFT JOIN variants v ON v.variant_id = (
            SELECT v2.variant_id
            FROM variants v2
            WHERE v2.product_id = p.product_id
            ORDER BY v2.variant_id
            LIMIT 1
        )
        WHERE w.user_id = ?
        ORDER BY w.wishlist_id DESC
    ');
    $stmt->execute([$userId]);
    $wishlistItems = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('Wishlist fetch failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Wishlist - Gemora</title>
    <link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
</head>
<body>
    <div class="page" id="app">
        <?php include __DIR__ . '/../include/header.php'; ?>

        <main class="wishlist-page">
            <div class="wishlist-header">
                <div>
                    <h1>My Wishlist</h1>
                    <p class="wishlist-count"><?php echo count($wishlistItems); ?> <?php echo count($wishlistItems) === 1 ? 'item' : 'items'; ?> saved</p>
                </div>
                <div class="wishlist-actions">
                    <button class="btn ghost" id="clearWishlistBtn" <?php echo empty($wishlistItems) ? 'disabled' : ''; ?>>Clear All</button>
                </div>
            </div>

            <!-- Wishlist Items Grid -->
            <div class="wishlist-grid" <?php echo empty($wishlistItems) ? 'style="display:none;"' : ''; ?>>
                <?php foreach ($wishlistItems as $item): ?>
                    <?php
                    $stock = (int) ($item['stock_qty'] ?? 0);
                    $isPreorder = $stock <= 0 && (int) ($item['preorder_flag'] ?? 0) === 1;
                    $note = '';
                    if ($stock > 0 && $stock < 5) {
                        $note = number_format($stock) . ' items left';
                    } elseif ($isPreorder) {
                        $note = 'Preorder available';
                    } elseif ($stock <= 0) {
                        $note = 'Out of stock';
                    }
                    ?>
                <article class="wishlist-card" data-wishlist-id="<?php echo (int) $item['wishlist_id']; ?>">
                    <div class="img-wrap">
                        <?php if ($isPreorder): ?>
                            <div class="badge">Preorder</div>
                        <?php endif; ?>
                        <button class="wishlist-btn active" aria-pressed="true" title="Remove from wishlist" data-remove-wishlist>
                            <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                        </button>
                        <img src="<?php echo htmlspecialchars(wishlist_img_url($item['image_path'] ?? null), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                    </div>
                    <div class="meta">
                        <div class="brand-name"><?php echo htmlspecialchars((string) $item['brand_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="title"><?php echo htmlspecialchars((string) $item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="price">MMK <?php echo number_format((int) $item['price']); ?></div>
                        <?php if ($note !== ''): ?>
                            <div class="note"><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="actions">
                        <a class="btn ghost small" href="<?php echo htmlspecialchars($userBase . 'product.php?id=' . (int) $item['product_id'], ENT_QUOTES, 'UTF-8'); ?>">View Details</a>
                        <button class="btn primary small add-to-cart-btn"
                            data-variant-id="<?php echo (int) ($item['variant_id'] ?? 0); ?>"
                            <?php echo (int) ($item['variant_id'] ?? 0) <= 0 ? 'disabled' : ''; ?>>
                            <?php echo $isPreorder ? 'Preorder' : 'Add to cart'; ?>
                        </button>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <!-- Empty State (hidden by default) -->
            <div class="empty-wishlist" <?php echo empty($wishlistItems) ? '' : 'style="display: none;"'; ?>>
                <svg viewBox="0 0 24 24" width="80" height="80" fill="none">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.5"/>
                </svg>
                <h2>Your wishlist is empty</h2>
                <p>Start adding products you love to your wishlist!</p>
                <button class="btn primary" onclick="window.location.href='index.php'">Browse Products</button>
            </div>
        </main>

        <?php include __DIR__ . '/../include/footer.php'; ?>
    </div>

    <div class="wishlist-confirm-backdrop" id="clearWishlistModal" hidden role="dialog" aria-modal="true" aria-labelledby="clearWishlistTitle" aria-describedby="clearWishlistMessage">
        <div class="wishlist-confirm-dialog">
            <button type="button" class="wishlist-confirm-close" id="clearWishlistClose" aria-label="Close">&times;</button>
            <div class="wishlist-confirm-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
            </div>
            <h3 id="clearWishlistTitle">Clear wishlist?</h3>
            <p class="wishlist-confirm-message" id="clearWishlistMessage">Are you sure you want to remove all saved items from your wishlist? This action cannot be undone.</p>
            <div class="wishlist-confirm-actions">
                <button type="button" class="btn ghost" id="clearWishlistCancel">Cancel</button>
                <button type="button" class="btn primary wishlist-confirm-danger" id="clearWishlistConfirm">Clear All</button>
            </div>
        </div>
    </div>

    <style>
        /* Wishlist Page Styles */
        .wishlist-page {
            margin: 24px 0;
        }

        .wishlist-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--glass-border);
        }

        .wishlist-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: var(--text);
        }

        .wishlist-count {
            color: var(--muted);
            font-size: 14px;
            margin: 0;
        }

        .wishlist-actions {
            display: flex;
            gap: 12px;
        }

        /* Wishlist Grid */
        .wishlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }

        .wishlist-card {
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            position: relative;
        }

        .wishlist-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--muted);
        }

        .wishlist-card .img-wrap {
            position: relative;
            border-radius: 6px;
            overflow: hidden;
            padding-top: 120%;
            background: var(--bg);
        }

        .wishlist-card .img-wrap img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .wishlist-card .badge {
            position: absolute;
            left: 10px;
            top: 10px;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            color: white;
            background: var(--accent);
            z-index: 2;
        }

        .wishlist-card .wishlist-btn {
            position: absolute;
            right: 10px;
            top: 10px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 50%;
            padding: 8px;
            border: 1px solid var(--glass-border);
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 2;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wishlist-card .wishlist-btn:hover {
            background: white;
            transform: scale(1.1);
        }

        .wishlist-card .wishlist-btn.active {
            color: #e91e63;
            background: rgba(233, 30, 99, 0.1);
        }

        .wishlist-card .wishlist-btn svg {
            width: 20px;
            height: 20px;
        }

        .wishlist-card .meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
        }

        .wishlist-card .brand-name {
            font-size: 12px;
            color: var(--muted);
        }

        .wishlist-card .title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text);
            line-height: 1.4;
        }

        .wishlist-card .price {
            font-weight: 700;
            color: var(--accent);
            font-size: 16px;
            margin-top: 4px;
        }

        .wishlist-card .note {
            font-size: 12px;
            color: var(--muted);
        }

        .wishlist-card .actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
        }

        .wishlist-card .btn {
            flex: 1;
        }

        /* Empty State */
        .empty-wishlist {
            text-align: center;
            padding: 80px 20px;
            background: var(--card);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
        }

        .empty-wishlist svg {
            color: var(--muted);
            margin: 0 auto 24px;
            opacity: 0.5;
        }

        .empty-wishlist h2 {
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 12px 0;
            color: var(--text);
        }

        .empty-wishlist p {
            color: var(--muted);
            margin: 0 0 24px 0;
            font-size: 14px;
        }

        /* Mobile Responsive */
        @media (max-width: 900px) {
            .wishlist-header {
                flex-direction: column;
                gap: 16px;
            }

            .wishlist-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 16px;
            }

            .wishlist-card .actions {
                flex-direction: column;
            }

            .wishlist-card .btn {
                width: 100%;
            }
        }

        @media (max-width: 600px) {
            .wishlist-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .wishlist-header h1 {
                font-size: 24px;
            }
        }

        .wishlist-confirm-backdrop {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(19, 63, 76, 0.42);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .wishlist-confirm-backdrop.is-open {
            opacity: 1;
            visibility: visible;
        }

        .wishlist-confirm-backdrop[hidden] {
            display: none !important;
        }

        .wishlist-confirm-backdrop.is-open[hidden] {
            display: flex !important;
        }

        .wishlist-confirm-dialog {
            position: relative;
            width: min(100%, 420px);
            background: #fff;
            border: 1px solid var(--glass-border);
            border-radius: var(--radius);
            box-shadow: 0 20px 50px rgba(19, 63, 76, 0.2);
            padding: 28px 24px 24px;
            text-align: center;
            transform: translateY(12px) scale(0.98);
            transition: transform 0.2s ease;
        }

        .wishlist-confirm-backdrop.is-open .wishlist-confirm-dialog {
            transform: translateY(0) scale(1);
        }

        .wishlist-confirm-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: var(--muted);
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .wishlist-confirm-close:hover {
            background: var(--accent-2);
            color: var(--text);
        }

        .wishlist-confirm-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(233, 30, 99, 0.1);
            color: #e91e63;
        }

        .wishlist-confirm-dialog h3 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
        }

        .wishlist-confirm-message {
            margin: 0 0 20px;
            font-size: 14px;
            line-height: 1.55;
            color: var(--muted);
        }

        .wishlist-confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .wishlist-confirm-actions .btn {
            min-width: 120px;
            justify-content: center;
        }

        .wishlist-confirm-danger {
            background: var(--accent);
            border-color: var(--accent);
        }

        .wishlist-confirm-danger:hover {
            background: #0d4351;
            border-color: #0d4351;
        }

        @media (max-width: 480px) {
            .wishlist-confirm-actions {
                flex-direction: column-reverse;
            }

            .wishlist-confirm-actions .btn {
                width: 100%;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const wishlistButtons = document.querySelectorAll('[data-remove-wishlist]');
            const clearBtn = document.getElementById('clearWishlistBtn');
            const addToCartButtons = document.querySelectorAll('.add-to-cart-btn');
            const wishlistCount = document.querySelector('.wishlist-count');
            const wishlistGrid = document.querySelector('.wishlist-grid');
            const emptyState = document.querySelector('.empty-wishlist');
            const cartUrl = <?php echo json_encode($userBase . 'cart.php'); ?>;
            const wishlistUrl = <?php echo json_encode($userBase . 'wishlist.php'); ?>;

            function updateHeaderCartBadge(itemCount) {
                let count = Number(itemCount || 0);
                if (!Number.isFinite(count) || count < 0) count = 0;
                document.querySelectorAll('.icon-count-badge').forEach(badge => {
                    badge.textContent = String(count);
                    if (count > 0) badge.classList.remove('is-hidden');
                    else badge.classList.add('is-hidden');
                });
            }

            // Remove item from wishlist
            wishlistButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const card = this.closest('.wishlist-card');
                    const wishlistId = card ? card.getAttribute('data-wishlist-id') : '';
                    if (!wishlistId) return;

                    const body = new URLSearchParams({ action: 'remove', wishlist_id: wishlistId });
                    fetch(wishlistUrl, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body,
                        credentials: 'same-origin'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.ok) {
                            showNotification(data.message || 'Could not remove item.');
                            return;
                        }
                    
                        // Animate removal
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.9)';
                        card.style.transition = 'all 0.3s ease';
                    
                        setTimeout(() => {
                            card.remove();
                            updateWishlistCount();
                            checkEmptyState();
                        }, 300);
                    })
                    .catch(() => showNotification('Could not remove item.'));
                });
            });

            // Clear all wishlist items
            const clearModal = document.getElementById('clearWishlistModal');
            const clearClose = document.getElementById('clearWishlistClose');
            const clearCancel = document.getElementById('clearWishlistCancel');
            const clearConfirm = document.getElementById('clearWishlistConfirm');

            function openClearModal() {
                if (!clearModal) return;
                clearModal.hidden = false;
                requestAnimationFrame(function() {
                    clearModal.classList.add('is-open');
                });
                document.body.style.overflow = 'hidden';
            }

            function closeClearModal() {
                if (!clearModal) return;
                clearModal.classList.remove('is-open');
                document.body.style.overflow = '';
                window.setTimeout(function() {
                    if (!clearModal.classList.contains('is-open')) {
                        clearModal.hidden = true;
                    }
                }, 200);
            }

            function performClearWishlist() {
                const body = new URLSearchParams({ action: 'clear' });
                fetch(wishlistUrl, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body,
                    credentials: 'same-origin'
                })
                .then(res => res.json())
                .then(data => {
                    if (!data.ok) {
                        showNotification(data.message || 'Could not clear wishlist.');
                        return;
                    }
                    const cards = document.querySelectorAll('.wishlist-card');
                    cards.forEach((card, index) => {
                        setTimeout(() => {
                            card.style.opacity = '0';
                            card.style.transform = 'scale(0.9)';
                            card.style.transition = 'all 0.3s ease';

                            setTimeout(() => {
                                card.remove();
                                if (index === cards.length - 1) {
                                    updateWishlistCount();
                                    checkEmptyState();
                                    if (clearBtn) clearBtn.disabled = true;
                                }
                            }, 300);
                        }, index * 50);
                    });
                    showNotification('Wishlist cleared.');
                })
                .catch(() => showNotification('Could not clear wishlist.'));
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    if (clearBtn.disabled) return;
                    openClearModal();
                });
            }

            if (clearClose) clearClose.addEventListener('click', closeClearModal);
            if (clearCancel) clearCancel.addEventListener('click', closeClearModal);

            if (clearModal) {
                clearModal.addEventListener('click', function(event) {
                    if (event.target === clearModal) closeClearModal();
                });
            }

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && clearModal && clearModal.classList.contains('is-open')) {
                    closeClearModal();
                }
            });

            if (clearConfirm) {
                clearConfirm.addEventListener('click', function() {
                    closeClearModal();
                    performClearWishlist();
                });
            }

            // Add to cart functionality
            addToCartButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const variantId = this.getAttribute('data-variant-id');
                    if (!variantId || variantId === '0') {
                        showNotification('No variant available for cart.');
                        return;
                    }
                    const originalText = this.textContent;
                    const body = new URLSearchParams({ action: 'add', variant_id: variantId, qty: '1' });

                    this.disabled = true;
                    fetch(cartUrl, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: body,
                        credentials: 'same-origin'
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.ok) {
                            showNotification(data.message || 'Could not add to cart.');
                            this.disabled = false;
                            return;
                        }
                        this.textContent = 'Added!';
                        this.style.backgroundColor = '#10b981';
                        showNotification('Added to cart!');
                        if (typeof data.item_count !== 'undefined') {
                            updateHeaderCartBadge(data.item_count);
                        }
                        setTimeout(() => {
                            this.textContent = originalText;
                            this.style.backgroundColor = '';
                            this.disabled = false;
                        }, 1500);
                    })
                    .catch(() => {
                        showNotification('Could not add to cart.');
                        this.disabled = false;
                    });
                });
            });

            // Update wishlist count
            function updateWishlistCount() {
                const itemCount = document.querySelectorAll('.wishlist-card').length;
                if (wishlistCount) {
                    wishlistCount.textContent = `${itemCount} ${itemCount === 1 ? 'item' : 'items'} saved`;
                }
            }

            // Check if wishlist is empty
            function checkEmptyState() {
                const itemCount = document.querySelectorAll('.wishlist-card').length;
                if (itemCount === 0) {
                    wishlistGrid.style.display = 'none';
                    emptyState.style.display = 'block';
                    if (clearBtn) clearBtn.disabled = true;
                } else {
                    wishlistGrid.style.display = 'grid';
                    emptyState.style.display = 'none';
                    if (clearBtn) clearBtn.disabled = false;
                }
            }

            // Show notification
            function showNotification(message) {
                // Create notification element
                const notification = document.createElement('div');
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: var(--accent);
                    color: white;
                    padding: 16px 24px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    z-index: 1000;
                    animation: slideIn 0.3s ease;
                `;
                notification.textContent = message;
                
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }, 3000);
            }

            // Add CSS animations
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
                @keyframes slideOut {
                    from {
                        transform: translateX(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>

