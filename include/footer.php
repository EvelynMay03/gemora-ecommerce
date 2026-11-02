<?php $gemoraBase = $gemoraBase ?? ''; $shopBase = (!empty($inUserArea) && isset($userBase)) ? $userBase : $gemoraBase . 'user/'; ?>
<!-- Footer -->
<footer class="site-footer" role="contentinfo">
  <div class="footer-content">
    <!-- Brand Section -->
    <div class="footer-section">
      <div class="footer-brand">
        <div class="footer-logo">
          <img src="<?php echo htmlspecialchars($gemoraBase . 'uploads/bikini.png', ENT_QUOTES, 'UTF-8'); ?>" alt="Gemora" loading="lazy">
        </div>
        <h3>Gemora</h3>
        <p>Imported-quality lingerie — Confidence with Comfort</p>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="footer-section">
      <h4>Shop</h4>
      <ul class="footer-links">
        <li><a href="<?php echo htmlspecialchars($shopBase . 'home.php', ENT_QUOTES, 'UTF-8'); ?>">Home</a></li>
        <li><a href="<?php echo htmlspecialchars($shopBase . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">All Products</a></li>
        <li><a href="<?php echo htmlspecialchars($shopBase . 'index.php?sort=new', ENT_QUOTES, 'UTF-8'); ?>">New Arrivals</a></li>
        <li><a href="<?php echo htmlspecialchars($shopBase . 'index.php?preorder=1', ENT_QUOTES, 'UTF-8'); ?>">Preorder Items</a></li>
      </ul>
    </div>

    <!-- Customer Service -->
    <div class="footer-section">
      <h4>Customer Service</h4>
      <ul class="footer-links">
        <li><a href="#">Contact Us</a></li>
        <li><a href="#">Shipping & Returns</a></li>
        <li><a href="#">Size Guide</a></li>
        <li><a href="#">FAQ</a></li>
      </ul>
    </div>

    <!-- Company Info -->
    <div class="footer-section">
      <h4>About</h4>
      <ul class="footer-links">
        <li><a href="<?php echo htmlspecialchars($shopBase . 'our-story.php', ENT_QUOTES, 'UTF-8'); ?>">Our Story</a></li>
        <li><a href="<?php echo htmlspecialchars($shopBase . 'privacy.php', ENT_QUOTES, 'UTF-8'); ?>">Privacy Policy</a></li>
        <li><a href="<?php echo htmlspecialchars($shopBase . 'terms.php', ENT_QUOTES, 'UTF-8'); ?>">Terms &amp; Conditions</a></li>
        <li><a href="#">Careers</a></li>
      </ul>
    </div>

    <!-- Contact & Social -->
    <div class="footer-section">
      <h4>Connect</h4>
      <div class="footer-contact">
        <p>Email: <a href="mailto:info@gemora.com">info@gemora.com</a></p>
        <p>Phone: <a href="tel:+959123456789">+95 9 123 456 789</a></p>
      </div>
      <div class="footer-social">
        <a href="#" class="social-link" aria-label="Facebook">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" stroke="currentColor" stroke-width="1.2"/>
          </svg>
        </a>
        <a href="#" class="social-link" aria-label="Instagram">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
            <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="currentColor" stroke-width="1.2"/>
            <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.2"/>
            <circle cx="17.5" cy="6.5" r="1.5" fill="currentColor"/>
          </svg>
        </a>
        <a href="#" class="social-link" aria-label="Twitter">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none">
            <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z" stroke="currentColor" stroke-width="1.2"/>
          </svg>
        </a>
      </div>
    </div>
  </div>

  <!-- Footer Bottom -->
  <div class="footer-bottom">
    <p>&copy; <?php echo date('Y'); ?> Gemora. All rights reserved.</p>
    <p class="footer-location">Myanmar</p>
  </div>
</footer>

