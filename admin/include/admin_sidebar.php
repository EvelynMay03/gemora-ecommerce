<?php
require_once __DIR__ . '/admin_paths.php';
$adminNavActive = $adminNavActive ?? 'dashboard';
?>
<aside class="filters admin-sidebar" aria-label="Admin menu">
  <h3>Admin Menu</h3>

  <nav class="admin-nav" aria-label="Admin navigation">
    <ul class="admin-nav-list">
      <li><a href="<?php echo $adminBase; ?>admin.php" class="admin-nav-link<?php echo $adminNavActive === 'dashboard' ? ' active' : ''; ?>">Dashboard</a></li>
      <li><a href="<?php echo $adminBase; ?>admin.php#reports" class="admin-nav-link">Reports</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_inventory.php" class="admin-nav-link<?php echo $adminNavActive === 'inventory' ? ' active' : ''; ?>">Inventory</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_orders.php" class="admin-nav-link<?php echo $adminNavActive === 'orders' ? ' active' : ''; ?>">Orders</a></li>
      <li><a href="<?php echo $adminBase; ?>admin_preorders.php" class="admin-nav-link<?php echo $adminNavActive === 'preorders' ? ' active' : ''; ?>">Preorders</a></li>
    </ul>
  </nav>

  <div class="admin-sidebar-actions">
    <a href="<?php echo $adminBase; ?>admin_inventory.php" class="btn primary" style="width:100%;text-align:center;display:block;">Manage Stock</a>
    <a href="../user/home.php" class="btn ghost" style="width:100%;text-align:center;display:block;margin-top:8px;">Back to Store</a>
  </div>
</aside>
