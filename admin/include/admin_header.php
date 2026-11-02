<?php
require_once __DIR__ . '/admin_paths.php';
$basePath = '../';
$adminNavActive = $adminNavActive ?? 'dashboard';
?>
<style>
  .site-header .header-controls .admin-store-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    color: #133f4c !important;
    background: #ffffff !important;
    border: 1px solid #ffffff !important;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.18);
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.2s ease, box-shadow 0.2s ease;
  }
  .site-header .header-controls .admin-store-link:hover {
    background: #e8f0f2 !important;
    color: #133f4c !important;
    border-color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.22);
  }
</style>
<header class="site-header" role="banner">
  <div class="brand">
    <a href="<?php echo $adminBase; ?>admin.php" class="brand-link">
      <div class="logo" aria-hidden="true">
        <img src="<?php echo $basePath; ?>uploads/bikini.png" alt="Gemora" loading="lazy">
      </div>
      <div>
        <h1>Gemora</h1>
        <p>Admin Dashboard</p>
      </div>
    </a>
  </div>

  <nav class="main-nav" role="navigation" aria-label="Admin navigation">
    <ul class="nav-list">
      <li><a href="<?php echo $adminBase; ?>admin.php" class="nav-link<?php echo $adminNavActive === 'dashboard' ? ' active' : ''; ?>">Dashboard</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_inventory.php" class="nav-link<?php echo $adminNavActive === 'inventory' ? ' active' : ''; ?>">Inventory</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_orders.php" class="nav-link<?php echo $adminNavActive === 'orders' ? ' active' : ''; ?>">Orders</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_preorders.php" class="nav-link<?php echo $adminNavActive === 'preorders' ? ' active' : ''; ?>">Preorders</a></li>
    </ul>
  </nav>

  <div style="flex:1"></div>

  <div class="header-controls">
    <a href="<?php echo $basePath; ?>user/home.php" class="admin-store-link">View Store</a>
  </div>
</header>
