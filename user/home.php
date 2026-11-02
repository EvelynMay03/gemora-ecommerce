<?php
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/../include/session.php';
require_once __DIR__ . '/include/user_paths.php';

$headerNavActive = 'home';

$productCount = 0;
$featuredProducts = [];
try {
    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $featStmt = $pdo->query('
        SELECT
            p.product_id,
            p.title,
            p.price,
            COALESCE(NULLIF(p.brand, ""), b.name, "Gemora") AS brand_name,
            (
                SELECT COALESCE(
                    (SELECT i.image FROM images i WHERE i.image_id = v.image_id LIMIT 1),
                    (SELECT i2.image FROM images i2 WHERE i2.variant_id = v.variant_id ORDER BY i2.sort_order, i2.image_id LIMIT 1)
                )
                FROM variants v
                WHERE v.product_id = p.product_id
                ORDER BY v.variant_id
                LIMIT 1
            ) AS image_path
        FROM products p
        LEFT JOIN brands b ON b.brand_id = p.brand_id
        ORDER BY p.created_at DESC, p.product_id DESC
        LIMIT 4
    ');
    $featuredProducts = $featStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Home cover data failed: ' . $e->getMessage());
}

function cover_img_url(?string $path): string
{
    if ($path) {
        return gemora_url(ltrim((string) $path, '/'));
    }

    return gemora_url('uploads/lace.jpg');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Gemora — Imported-quality lingerie</title>
<link rel="stylesheet" href="<?php echo gemora_url('assets/style.css'); ?>">
<style>
  .cover-main {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .cover-hero {
    position: relative;
    overflow: hidden;
    border-radius: var(--radius);
    background: var(--accent);
    color: #fff;
    min-height: 420px;
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
    box-shadow: var(--shadow);
  }

  .cover-hero-copy {
    padding: 48px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 18px;
    position: relative;
    z-index: 2;
  }

  .cover-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.85);
  }

  .cover-eyebrow::before {
    content: "";
    width: 28px;
    height: 1px;
    background: rgba(255, 255, 255, 0.5);
  }

  .cover-hero h2 {
    margin: 0;
    font-size: clamp(32px, 5vw, 52px);
    line-height: 1.08;
    font-weight: 700;
    letter-spacing: -0.02em;
  }

  .cover-hero-lead {
    margin: 0;
    max-width: 520px;
    font-size: 16px;
    line-height: 1.65;
    color: rgba(255, 255, 255, 0.88);
  }

  .cover-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
  }

  .cover-btn-light {
    background: #fff;
    color: var(--accent);
    border: 0;
    padding: 12px 22px;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .cover-btn-light:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
  }

  .cover-btn-outline {
    background: transparent;
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.45);
    padding: 12px 22px;
    border-radius: var(--radius);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s ease, border-color 0.2s ease;
  }

  .cover-btn-outline:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.7);
  }

  .cover-hero-visual {
    position: relative;
    min-height: 320px;
  }

  .cover-hero-visual img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .cover-hero-visual::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, var(--accent) 0%, rgba(19, 63, 76, 0.15) 45%, transparent 100%);
    z-index: 1;
  }

  .cover-hero-badge {
    position: absolute;
    right: 24px;
    bottom: 24px;
    z-index: 2;
    background: rgba(255, 255, 255, 0.95);
    color: var(--accent);
    border-radius: var(--radius);
    padding: 14px 18px;
    box-shadow: var(--shadow);
    font-size: 13px;
    line-height: 1.4;
  }

  .cover-hero-badge strong {
    display: block;
    font-size: 22px;
    line-height: 1.1;
    margin-bottom: 2px;
  }

  .cover-intro {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    gap: 24px;
    align-items: center;
    background: #fff;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 32px;
    box-shadow: var(--shadow);
  }

  .cover-intro-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    margin: 0 0 8px;
  }

  .cover-intro h3 {
    margin: 0;
    font-size: 28px;
    line-height: 1.2;
    color: var(--text);
  }

  .cover-intro p {
    margin: 0;
    font-size: 15px;
    line-height: 1.7;
    color: var(--muted);
  }

  .cover-features {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }

  .cover-feature {
    background: #fff;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 22px 18px;
    box-shadow: var(--shadow);
  }

  .cover-feature-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--accent-2);
    color: var(--accent);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    margin-bottom: 12px;
  }

  .cover-feature h4 {
    margin: 0 0 6px;
    font-size: 15px;
    color: var(--text);
  }

  .cover-feature p {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: var(--muted);
  }

  .cover-showcase {
    background: #fff;
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow);
  }

  .cover-showcase-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }

  .cover-showcase-head h3 {
    margin: 0;
    font-size: 20px;
  }

  .cover-showcase-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }

  .cover-showcase-card {
    border: 1px solid var(--glass-border);
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--accent-2);
    transition: transform 0.2s ease, border-color 0.2s ease;
  }

  .cover-showcase-card:hover {
    transform: translateY(-2px);
    border-color: var(--muted);
  }

  .cover-showcase-card img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
  }

  .cover-showcase-meta {
    padding: 10px 12px 12px;
    background: #fff;
  }

  .cover-showcase-meta .brand {
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 2px;
  }

  .cover-showcase-meta .title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 4px;
    color: var(--text);
  }

  .cover-showcase-meta .price {
    font-size: 13px;
    font-weight: 700;
    color: var(--accent);
  }

  .cover-bottom-cta {
    text-align: center;
    padding: 36px 24px;
    border-radius: var(--radius);
    background: linear-gradient(135deg, var(--accent) 0%, #1a5568 100%);
    color: #fff;
    box-shadow: var(--shadow);
  }

  .cover-bottom-cta h3 {
    margin: 0 0 8px;
    font-size: 26px;
  }

  .cover-bottom-cta p {
    margin: 0 auto 18px;
    max-width: 520px;
    font-size: 15px;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.88);
  }

  @media (max-width: 900px) {
    .cover-hero {
      grid-template-columns: 1fr;
      min-height: unset;
    }

    .cover-hero-copy {
      padding: 32px 24px 20px;
    }

    .cover-hero-visual {
      min-height: 260px;
      order: -1;
    }

    .cover-hero-visual::before {
      background: linear-gradient(180deg, transparent 30%, var(--accent) 100%);
    }

    .cover-intro {
      grid-template-columns: 1fr;
      padding: 24px;
    }

    .cover-features,
    .cover-showcase-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 560px) {
    .cover-features,
    .cover-showcase-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
</head>
<body>
  <div class="page" id="app">
    <?php include __DIR__ . '/../include/header.php'; ?>

    <main class="cover-main" role="main">
      <section class="cover-hero" aria-labelledby="coverHeroTitle">
        <div class="cover-hero-copy">
          <p class="cover-eyebrow">Welcome to Gemora</p>
          <h2 id="coverHeroTitle">Confidence with Comfort</h2>
          <p class="cover-hero-lead">
            Discover imported-quality bras and lingerie curated for everyday ease — thoughtfully selected pieces,
            trusted brands, and a calm shopping experience made for Myanmar.
          </p>
          <div class="cover-hero-actions">
            <a class="cover-btn-light" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Shop the Collection</a>
            <a class="cover-btn-outline" href="<?php echo htmlspecialchars($userBase . 'index.php?preorder=1', ENT_QUOTES, 'UTF-8'); ?>">Browse Preorders</a>
          </div>
        </div>
        <div class="cover-hero-visual" aria-hidden="true">
          <img src="<?php echo cover_img_url($featuredProducts[0]['image_path'] ?? null); ?>" alt="">
          <?php if ($productCount > 0): ?>
          <div class="cover-hero-badge">
            <strong><?php echo number_format($productCount); ?>+</strong>
            styles to explore
          </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="cover-intro" aria-labelledby="coverIntroTitle">
        <div>
          <p class="cover-intro-label">Our Story</p>
          <h3 id="coverIntroTitle">Lingerie that feels like you</h3>
        </div>
        <p>
          Gemora brings together comfort, fit, and quiet confidence. From everyday essentials to pieces you reach for
          on special days, we focus on quality fabrics, reliable sizing, and brands our customers already love —
          with clear stock updates, preorder support, and secure checkout across Myanmar.
        </p>
      </section>

      <section class="cover-features" aria-label="Why shop with Gemora">
        <article class="cover-feature">
          <div class="cover-feature-icon" aria-hidden="true">✦</div>
          <h4>Imported Quality</h4>
          <p>Curated pieces from trusted labels, chosen for fit, fabric, and lasting comfort.</p>
        </article>
        <article class="cover-feature">
          <div class="cover-feature-icon" aria-hidden="true">◎</div>
          <h4>Thoughtful Fit</h4>
          <p>Browse by size and variant with clear stock notes so you can shop with confidence.</p>
        </article>
        <article class="cover-feature">
          <div class="cover-feature-icon" aria-hidden="true">↗</div>
          <h4>Delivered in Myanmar</h4>
          <p>Free shipping on larger orders with a checkout flow designed for local customers.</p>
        </article>
        <article class="cover-feature">
          <div class="cover-feature-icon" aria-hidden="true">◷</div>
          <h4>Preorder Available</h4>
          <p>Love something that is not in stock yet? Reserve preorder items separately and easily.</p>
        </article>
      </section>

      <?php if (!empty($featuredProducts)): ?>
      <section class="cover-showcase" aria-labelledby="coverShowcaseTitle">
        <div class="cover-showcase-head">
          <h3 id="coverShowcaseTitle">New Arrivals</h3>
          <a class="btn ghost small" href="<?php echo htmlspecialchars($userBase . 'index.php?sort=new', ENT_QUOTES, 'UTF-8'); ?>">View all</a>
        </div>
        <div class="cover-showcase-grid">
          <?php foreach ($featuredProducts as $product): ?>
          <a class="cover-showcase-card" href="<?php echo htmlspecialchars($userBase . 'product.php?id=' . (int) $product['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
            <img src="<?php echo cover_img_url($product['image_path'] ?? null); ?>"
              alt="<?php echo htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
            <div class="cover-showcase-meta">
              <div class="brand"><?php echo htmlspecialchars((string) $product['brand_name'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="title"><?php echo htmlspecialchars((string) $product['title'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="price">MMK <?php echo number_format((int) $product['price']); ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <section class="cover-bottom-cta" aria-labelledby="coverCtaTitle">
        <h3 id="coverCtaTitle">Ready to find your next favorite?</h3>
        <p>Explore the full collection — in-stock pieces and preorder items are kept separate so every order stays simple.</p>
        <a class="cover-btn-light" href="<?php echo htmlspecialchars($userBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">Start Shopping</a>
      </section>
    </main>
  </div>

  <?php include __DIR__ . '/../include/footer.php'; ?>
</body>
</html>
