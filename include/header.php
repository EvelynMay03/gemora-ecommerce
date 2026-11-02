<?php
require_once __DIR__ . '/session.php';
$gemoraBase = $gemoraBase ?? '';
$shopBase = (!empty($inUserArea) && isset($userBase)) ? $userBase : $gemoraBase . 'user/';
$headerNavActive = $headerNavActive ?? '';
$headerSearchQuery = trim((string) ($_GET['q'] ?? ''));
$headerCartQty = 0;
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../database/pdo.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $cartCountStmt = $pdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM cart_items WHERE user_id = ?');
            $cartCountStmt->execute([(int) $_SESSION['user_id']]);
            $headerCartQty = (int) $cartCountStmt->fetchColumn();
        }
    } catch (Throwable $e) {
        $headerCartQty = 0;
    }
}
?>
<style>
  .cart-icon-btn{
    position:relative !important;
    overflow:visible !important;
  }
  .cart-icon-btn .icon-count-badge{
    position:absolute !important;
    top:-7px !important;
    right:-7px !important;
    min-width:18px !important;
    height:18px !important;
    border-radius:999px !important;
    background:var(--accent) !important;
    color:#fff !important;
    font-size:11px !important;
    font-weight:700 !important;
    line-height:1 !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    padding:0 4px !important;
    border:2px solid #fff !important;
    box-sizing:border-box !important;
    z-index:5 !important;
  }
  .cart-icon-btn .icon-count-badge.is-hidden{
    display:none !important;
  }
</style>
<!-- Header Navigation Bar -->
<header class="site-header" role="banner">
  <div class="brand">
    <a href="<?php echo htmlspecialchars($shopBase . 'home.php', ENT_QUOTES, 'UTF-8'); ?>" class="brand-link">
      <div class="logo" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($gemoraBase . 'uploads/bikini.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Gemora" loading="lazy">
      </div>
      <div>
        <h1>Gemora</h1>
        <p>Imported-quality lingerie — Confidence with Comfort</p>
      </div>
    </a>
  </div>

  <nav class="main-nav" role="navigation" aria-label="Main navigation">
    <ul class="nav-list">
      <li><a href="<?php echo htmlspecialchars($shopBase . 'home.php', ENT_QUOTES, 'UTF-8'); ?>" class="nav-link<?php echo $headerNavActive === 'home' ? ' active' : ''; ?>">Home</a></li>
      <li><a href="<?php echo htmlspecialchars($shopBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="nav-link<?php echo $headerNavActive === 'shop' ? ' active' : ''; ?>">Shop</a></li>
      <li><a href="<?php echo htmlspecialchars($shopBase . 'index.php?preorder=1', ENT_QUOTES, 'UTF-8'); ?>" class="nav-link<?php echo $headerNavActive === 'preorder' ? ' active' : ''; ?>">Preorder items</a></li>
    </ul>
  </nav>

  <div style="flex:1;margin:0 12px;">
    <form class="search" role="search" aria-label="Search products" method="get" action="<?php echo htmlspecialchars($shopBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" style="opacity:.6" aria-hidden="true">
        <path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <input id="searchInput" name="q" type="search" value="<?php echo htmlspecialchars($headerSearchQuery, ENT_QUOTES, 'UTF-8'); ?>" placeholder="What are you looking for? e.g. Sabina bra" autocomplete="off" />
      <button type="submit" class="icon-btn" title="Search" aria-label="Search">
        <svg viewBox="0 0 24 24" width="18" height="18">
          <path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.6"/>
        </svg>
      </button>
    </form>
  </div>

  <div class="header-controls">
    <a href="<?php echo htmlspecialchars($shopBase . 'wishlist.php', ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn" title="Wishlist" aria-label="Wishlist">
      <svg viewBox="0 0 24 24" fill="none">
        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78z" stroke="currentColor" stroke-width="1.2"/>
      </svg>
    </a>
    <a href="<?php echo htmlspecialchars($shopBase . 'cart.php', ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn cart-icon-btn" aria-label="Cart" title="Shopping Cart">
      <svg viewBox="0 0 24 24" fill="none">
        <path d="M6 6h15l-1.5 9h-12zM6 6L4 2H2" stroke="currentColor" stroke-width="1.2"/>
      </svg>
      <span class="icon-count-badge<?php echo $headerCartQty > 0 ? '' : ' is-hidden'; ?>"><?php echo (int) $headerCartQty; ?></span>
    </a>
    <a href="<?php echo htmlspecialchars($gemoraBase . 'profile.php', ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn" aria-label="Account" title="My Profile">
      <svg viewBox="0 0 24 24" fill="none">
        <circle cx="12" cy="8" r="3" stroke="currentColor" stroke-width="1.2"/>
        <path d="M5 20c1.5-3 4.5-5 7-5s5.5 2 7 5" stroke="currentColor" stroke-width="1.2"/>
      </svg>
    </a>
  </div>
</header>

