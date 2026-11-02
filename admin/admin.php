<?php
$adminNavActive = 'dashboard';
require_once __DIR__ . '/include/admin_auth.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/admin_dashboard_lib.php';

$message = '';
$messageType = 'success';
$preorderStatusFilter = trim((string) ($_GET['preorder_status'] ?? ''));
$salesDateFromInput = trim((string) ($_GET['sales_from'] ?? ''));
$salesDateToInput = trim((string) ($_GET['sales_to'] ?? ''));
$salesDateRange = admin_dashboard_parse_date_range(
    $salesDateFromInput !== '' ? $salesDateFromInput : null,
    $salesDateToInput !== '' ? $salesDateToInput : null
);
$orderStatuses = admin_order_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_preorder'])) {
    $orderId = (int) ($_POST['order_id'] ?? 0);

    try {
        $result = admin_orders_update_status($pdo, $orderId, 'Confirmed');
        if ($result['ok'] && $orderId > 0) {
            $redirect = 'admin.php?msg=preorder_confirmed';
            if ($preorderStatusFilter !== '' && in_array($preorderStatusFilter, $orderStatuses, true)) {
                $redirect .= '&preorder_status=' . urlencode($preorderStatusFilter);
            }
            if ($salesDateRange !== null) {
                $redirect .= '&sales_from=' . urlencode($salesDateRange['from']);
                $redirect .= '&sales_to=' . urlencode($salesDateRange['to']);
            }
            header('Location: ' . $redirect . '#preorders');
            exit;
        }
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
    } catch (PDOException $e) {
        error_log('Admin preorder confirm failed: ' . $e->getMessage());
        $message = 'Could not confirm preorder order.';
        $messageType = 'error';
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'preorder_confirmed' && $message === '') {
    $message = 'Preorder order confirmed.';
    $messageType = 'success';
}

$dashboardKpis = [
    'total_sales' => 0,
    'revenue' => 0,
    'low_stock' => 0,
    'preorders' => 0,
    'preorders_today' => 0,
    'sales_change' => null,
    'revenue_change' => null,
    'date_range_label' => null,
    'revenue_period_label' => '',
];
$topProducts = [];
$lowStockAlerts = [];
$preorderCategories = [];
$topPreorderItems = [];
$allPreorderLineItems = [];
$salesSeries = [
    'week' => ['labels' => [], 'values' => []],
    'month' => ['labels' => [], 'values' => []],
    'year' => ['labels' => [], 'values' => []],
    'range' => ['labels' => [], 'values' => []],
];

try {
    $dashboardKpis = admin_dashboard_kpis($pdo, $salesDateRange);
    $topProducts = admin_dashboard_top_products($pdo, 5, $salesDateRange);
    $lowStockAlerts = admin_dashboard_low_stock_alerts($pdo, 25);
    $preorderCategories = admin_dashboard_preorder_by_category($pdo);
    $topPreorderItems = admin_dashboard_top_preorder_items($pdo);
    $allPreorderLineItems = admin_dashboard_all_preorder_line_items($pdo, $preorderStatusFilter);
    if ($salesDateRange !== null) {
        $salesSeries['range'] = admin_dashboard_sales_series_for_range($pdo, $salesDateRange);
    } else {
        foreach (['week', 'month', 'year'] as $period) {
            try {
                $salesSeries[$period] = admin_dashboard_sales_series($pdo, $period);
            } catch (PDOException $e) {
                error_log('Admin dashboard sales series failed (' . $period . '): ' . $e->getMessage());
            }
        }
    }
} catch (PDOException $e) {
    error_log('Admin dashboard fetch failed: ' . $e->getMessage());
}

$topProductMaxSold = 0;
foreach ($topProducts as $product) {
    $topProductMaxSold = max($topProductMaxSold, (int) $product['sold']);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Admin Dashboard — Gemora</title>
<link rel="stylesheet" href="../assets/style.css">
<?php include __DIR__ . '/include/admin_sidebar_styles.php'; ?>
<style>
  .admin-nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .admin-nav-link {
    display: block;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    border: 1px solid transparent;
    transition: all 0.2s ease;
  }
  .admin-nav-link:hover {
    background: var(--accent-2);
    border-color: var(--glass-border);
  }
  .admin-nav-link.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
  }
  .admin-sidebar-actions {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--glass-border);
  }
  .admin-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 16px;
  }
  .admin-kpi {
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }
  .admin-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    background: var(--accent-2);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 18px;
  }
  .admin-kpi-label {
    font-size: 13px;
    color: var(--muted);
    margin: 0 0 4px;
  }
  .admin-kpi-value {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 4px;
    color: var(--text);
  }
  .admin-kpi-note {
    font-size: 12px;
    margin: 0;
  }
  .admin-kpi-note.positive { color: #2d6a4f; }
  .admin-kpi-note.negative { color: #9b2226; }
  main.content {
    min-width: 0;
    grid-template-columns: 320px minmax(0, 1fr);
  }
  main.content > section {
    min-width: 0;
    width: 100%;
  }
  .admin-panel-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 14px;
    margin-bottom: 16px;
    align-items: stretch;
  }
  .admin-panel-grid > .card {
    min-width: 0;
  }
  .admin-chart-wrap {
    position: relative;
    width: 100%;
    min-height: 260px;
    min-width: 0;
  }
  .admin-sales-svg,
  .admin-donut-svg {
    display: block;
    width: 100%;
    height: auto;
    max-height: 260px;
  }
  .admin-sales-panel {
    display: none;
  }
  .admin-sales-panel.is-active {
    display: block;
  }
  .admin-chip-row .chip {
    cursor: pointer;
    border: 1px solid var(--glass-border);
    background: #fff;
    color: var(--text);
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
  }
  .admin-chip-row .chip.active {
    background: var(--accent);
    border-color: var(--accent);
    color: #fff;
  }
  .admin-donut-chart {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    width: 100%;
  }
  .admin-donut-legend {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    max-width: 220px;
  }
  .admin-donut-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text);
  }
  .admin-donut-legend-item strong {
    margin-left: auto;
  }
  .admin-donut-swatch {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    flex-shrink: 0;
  }
  #reports.card,
  #preorders.card {
    display: block;
    padding: 18px;
    overflow: visible;
  }
  #preorders .admin-preorder-chart-wrap {
    min-height: 260px;
    position: relative;
  }
  .admin-chart-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 260px;
    color: var(--muted);
    font-size: 14px;
    text-align: center;
    padding: 12px;
  }
  .admin-top-products-card {
    padding: 18px;
  }
  .admin-top-products-card .admin-panel-head h3 {
    font-size: 17px;
  }
  .admin-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    flex-wrap: wrap;
  }
  .admin-panel-head h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
  }
  .admin-chip-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }
  .admin-sales-date-filter {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .admin-sales-date-filter label {
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
  }
  .admin-sales-date-filter input[type="date"] {
    border: 1px solid var(--glass-border);
    border-radius: 6px;
    padding: 7px 10px;
    font-size: 13px;
    background: var(--surface, #fff);
    color: var(--text);
    min-width: 140px;
  }
  .admin-sales-date-filter input[type="date"]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.04);
  }
  .admin-sales-date-sep {
    font-size: 13px;
    color: var(--muted);
  }
  .admin-range-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
    background: var(--accent-2);
    border: 1px solid var(--glass-border);
  }
  .admin-kpi-note.muted {
    color: var(--muted);
  }
  .admin-top-product {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--glass-border);
  }
  .admin-top-product:last-child { border-bottom: 0; padding-bottom: 0; }
  .admin-top-product-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: var(--accent-2);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 20px;
    overflow: hidden;
  }
  .admin-top-product-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .admin-empty-note {
    margin: 0;
    padding: 12px 0;
    color: var(--muted);
    font-size: 14px;
  }
  .admin-top-product-meta { flex: 1; min-width: 0; }
  .admin-top-product-title {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
    font-weight: 600;
    font-size: 14px;
  }
  .admin-progress {
    width: 100%;
    height: 6px;
    background: var(--accent-2);
    border-radius: 999px;
    overflow: hidden;
    margin-top: 6px;
  }
  .admin-progress > span {
    display: block;
    height: 100%;
    background: var(--accent);
    border-radius: 999px;
  }
  .admin-table-wrap {
    overflow-x: auto;
  }
  .admin-inventory-alerts-scroll {
    max-height: 360px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
  }
  .admin-inventory-alerts-scroll .admin-table {
    margin: 0;
  }
  .admin-inventory-alerts-scroll .admin-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--accent-2);
  }
  .admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  .admin-table th,
  .admin-table td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid var(--glass-border);
  }
  .admin-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted);
    font-weight: 600;
  }
  .admin-table tbody tr:hover {
    background: var(--accent-2);
  }
  .admin-status {
    display: inline-flex;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
  }
  .admin-status.low {
    background: #fde8e8;
    color: #9b2226;
  }
  .admin-status.no-stock {
    background: #e8e8e8;
    color: #4a4a4a;
    border: 1px solid #d0d0d0;
  }
  .admin-status.pending { background: #fff3cd; color: #856404; }
  .admin-status.confirmed { background: #d1ecf1; color: #0c5460; }
  .admin-status.shipped { background: #cce5ff; color: #004085; }
  .admin-status.delivered { background: #d4edda; color: #155724; }
  .admin-status.returned { background: #f8d7da; color: #721c24; }
  .admin-status.refunded { background: #e2e3e5; color: #383d41; }
  #preorders .admin-status.delivered { background: #d4edda; color: #155724; }
  #preorders .admin-status.confirmed { background: #d1ecf1; color: #0c5460; }
  #preorders .admin-status.pending { background: #fff3cd; color: #856404; }
  #preorders .admin-preorder-scroll {
    overflow: visible;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    background: #fff;
  }
  #preorders .admin-preorder-scroll .admin-table {
    margin: 0;
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
  }
  #preorders .admin-preorder-scroll .admin-table thead th {
    background: var(--accent-2);
  }
  #preorders .admin-preorder-scroll .admin-table tbody tr {
    display: table-row;
  }
  #preorders .admin-preorder-summary-scroll {
    max-height: 220px;
    overflow-y: auto;
    padding-right: 4px;
  }
  .admin-preorder-orders {
    margin-top: 18px;
    padding-top: 18px;
    border-top: 1px solid var(--glass-border);
    overflow: visible;
    min-height: 0;
  }
  .admin-preorder-orders h4 {
    margin: 0 0 12px;
    font-size: 15px;
  }
  .admin-preorder-head-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .admin-preorder-status-filter select {
    padding: 6px 10px;
    border: 1px solid var(--glass-border);
    border-radius: 6px;
    font-size: 13px;
    background: #fff;
    color: var(--text);
    min-width: 140px;
  }
  .admin-preorder-items-cell {
    font-size: 13px;
    color: var(--text);
    max-width: 320px;
  }
  .admin-preorder-item-line {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .admin-preorder-item-line + .admin-preorder-item-line {
    margin-top: 8px;
  }
  .admin-preorder-item-line img {
    width: 40px;
    height: 48px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--glass-border);
    background: var(--accent-2);
    flex-shrink: 0;
  }
  .admin-preorder-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .admin-preorder-confirm-form {
    margin: 0;
  }
  .admin-confirm-check {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    margin: 0;
  }
  .admin-confirm-check input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: var(--accent);
    cursor: pointer;
    margin: 0;
  }
  .admin-confirm-check.is-readonly {
    cursor: default;
  }
  .admin-confirm-check.is-readonly input[type="checkbox"] {
    cursor: default;
  }
  .admin-feedback {
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 14px;
    font-size: 14px;
  }
  .admin-feedback.success {
    background: rgba(129, 159, 167, 0.2);
    color: var(--accent);
    border: 1px solid var(--muted);
  }
  .admin-feedback.error {
    background: rgba(155, 34, 38, 0.08);
    color: #9b2226;
    border: 1px solid rgba(155, 34, 38, 0.25);
  }
  .admin-preorder-row + .admin-preorder-row {
    margin-top: 12px;
  }
  .admin-preorder-label {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 14px;
    margin-bottom: 6px;
  }
  @media (max-width: 1100px) {
    .admin-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 900px) {
    .admin-kpi-grid { grid-template-columns: 1fr; }
    .admin-panel-grid { grid-template-columns: minmax(0, 1fr); }
  }
</style>
</head>
<body>
  <div class="page" id="app">
    <?php include 'include/admin_header.php'; ?>

    <main class="content" role="main">
      <?php include 'include/admin_sidebar.php'; ?>

      <section>
        <div class="product-header">
          <div class="left">
            <div style="display:flex;flex-direction:column">
              <div style="font-weight:600">Dashboard Overview</div>
              <div class="product-count">Store performance • Myanmar</div>
            </div>
          </div>
          <form class="admin-sales-date-filter" method="get" action="admin.php">
            <?php if ($preorderStatusFilter !== '' && in_array($preorderStatusFilter, $orderStatuses, true)): ?>
              <input type="hidden" name="preorder_status" value="<?php echo htmlspecialchars($preorderStatusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <label for="sales_from">Sales from</label>
            <input
              type="date"
              id="sales_from"
              name="sales_from"
              value="<?php echo htmlspecialchars($salesDateRange['from'] ?? $salesDateFromInput, ENT_QUOTES, 'UTF-8'); ?>"
            >
            <span class="admin-sales-date-sep">to</span>
            <input
              type="date"
              id="sales_to"
              name="sales_to"
              value="<?php echo htmlspecialchars($salesDateRange['to'] ?? $salesDateToInput, ENT_QUOTES, 'UTF-8'); ?>"
            >
            <button class="btn ghost small" type="submit">Apply</button>
            <?php if ($salesDateRange !== null): ?>
              <a
                class="btn ghost small"
                href="admin.php<?php echo htmlspecialchars(admin_dashboard_sales_filter_query(null, $preorderStatusFilter), ENT_QUOTES, 'UTF-8'); ?>"
              >Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <?php if ($message !== ''): ?>
          <div class="admin-feedback <?php echo $messageType === 'error' ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
          </div>
        <?php endif; ?>

        <div class="admin-kpi-grid">
          <article class="card admin-kpi">
            <div class="admin-kpi-icon">🛒</div>
            <div>
              <p class="admin-kpi-label">Total Sales</p>
              <p class="admin-kpi-value"><?php echo htmlspecialchars(admin_dashboard_format_money((int) $dashboardKpis['total_sales'])); ?></p>
              <?php if (!empty($dashboardKpis['date_range_label'])): ?>
                <p class="admin-kpi-note muted"><?php echo htmlspecialchars((string) $dashboardKpis['date_range_label']); ?></p>
              <?php elseif (!empty($dashboardKpis['sales_change'])): ?>
                <p class="admin-kpi-note <?php echo htmlspecialchars((string) $dashboardKpis['sales_change']['class']); ?>">
                  <?php echo htmlspecialchars((string) $dashboardKpis['sales_change']['text']); ?>
                </p>
              <?php endif; ?>
            </div>
          </article>
          <article class="card admin-kpi">
            <div class="admin-kpi-icon">📈</div>
            <div>
              <p class="admin-kpi-label">Monthly Revenue</p>
              <p class="admin-kpi-value"><?php echo htmlspecialchars(admin_dashboard_format_money((int) $dashboardKpis['revenue'])); ?></p>
              <?php if (!empty($dashboardKpis['revenue_period_label'])): ?>
                <p class="admin-kpi-note muted"><?php echo htmlspecialchars((string) $dashboardKpis['revenue_period_label']); ?></p>
              <?php endif; ?>
              <?php if (!empty($dashboardKpis['revenue_change'])): ?>
                <p class="admin-kpi-note <?php echo htmlspecialchars((string) $dashboardKpis['revenue_change']['class']); ?>">
                  <?php echo htmlspecialchars((string) $dashboardKpis['revenue_change']['text']); ?>
                </p>
              <?php endif; ?>
            </div>
          </article>
          <article class="card admin-kpi">
            <div class="admin-kpi-icon">⚠️</div>
            <div>
              <p class="admin-kpi-label">Low Stock Items</p>
              <p class="admin-kpi-value"><?php echo number_format((int) $dashboardKpis['low_stock']); ?></p>
              <?php if ((int) $dashboardKpis['low_stock'] > 0): ?>
                <p class="admin-kpi-note negative">Needs attention</p>
              <?php endif; ?>
            </div>
          </article>
          <article class="card admin-kpi">
            <div class="admin-kpi-icon">📋</div>
            <div>
              <p class="admin-kpi-label">Preorders</p>
              <p class="admin-kpi-value"><?php echo number_format((int) $dashboardKpis['preorders']); ?></p>
              <?php if ((int) $dashboardKpis['preorders_today'] > 0): ?>
                <p class="admin-kpi-note positive">+<?php echo number_format((int) $dashboardKpis['preorders_today']); ?> new order<?php echo (int) $dashboardKpis['preorders_today'] === 1 ? '' : 's'; ?> today</p>
              <?php endif; ?>
            </div>
          </article>
        </div>

        <div class="admin-panel-grid">
          <article class="card" id="reports">
            <div class="admin-panel-head">
              <h3>Sales Overview</h3>
              <?php if ($salesDateRange !== null): ?>
                <div class="admin-chip-row">
                  <span class="admin-range-badge"><?php echo htmlspecialchars(admin_dashboard_format_date_range_label($salesDateRange)); ?></span>
                </div>
              <?php else: ?>
                <div class="admin-chip-row">
                  <button class="chip" type="button">Week</button>
                  <button class="chip active" type="button">Month</button>
                  <button class="chip" type="button">Year</button>
                </div>
              <?php endif; ?>
            </div>
            <div class="admin-chart-wrap" id="salesChartWrap">
              <?php if ($salesDateRange !== null): ?>
                <div class="admin-sales-panel is-active" data-sales-panel="range">
                  <?php echo admin_dashboard_render_sales_svg($salesSeries['range']); ?>
                </div>
              <?php else: ?>
                <div class="admin-sales-panel" data-sales-panel="week">
                  <?php echo admin_dashboard_render_sales_svg($salesSeries['week']); ?>
                </div>
                <div class="admin-sales-panel is-active" data-sales-panel="month">
                  <?php echo admin_dashboard_render_sales_svg($salesSeries['month']); ?>
                </div>
                <div class="admin-sales-panel" data-sales-panel="year">
                  <?php echo admin_dashboard_render_sales_svg($salesSeries['year']); ?>
                </div>
              <?php endif; ?>
            </div>
          </article>

          <article class="card admin-top-products-card">
            <div class="admin-panel-head">
              <h3>Top Selling Products</h3>
            </div>
            <?php if ($topProducts): ?>
              <?php foreach ($topProducts as $product): ?>
                <?php
                  $sold = (int) $product['sold'];
                  $barWidth = $topProductMaxSold > 0 ? (int) round(($sold / $topProductMaxSold) * 100) : 0;
                  $imageUrl = admin_order_img_url((string) $product['image_path']);
                ?>
                <div class="admin-top-product">
                  <div class="admin-top-product-icon">
                    <img src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                  </div>
                  <div class="admin-top-product-meta">
                    <div class="admin-top-product-title">
                      <span><?php echo htmlspecialchars((string) $product['title']); ?></span>
                      <span class="muted"><?php echo htmlspecialchars(admin_dashboard_format_money((int) $product['price'])); ?></span>
                    </div>
                    <div class="admin-progress"><span style="width:<?php echo $barWidth; ?>%"></span></div>
                    <p class="note"><?php echo number_format($sold); ?> sold</p>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="admin-empty-note">No sales data yet.</p>
            <?php endif; ?>
          </article>
        </div>

        <article class="card" style="margin-bottom:16px;">
          <div class="admin-panel-head">
            <h3>Inventory Alerts</h3>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <?php
                $alertNoStockCount = 0;
                $alertLowStockCount = 0;
                foreach ($lowStockAlerts as $alertRow) {
                    $qty = (int) ($alertRow['stock_qty'] ?? 0);
                    if ($qty <= 0) {
                        $alertNoStockCount++;
                    } else {
                        $alertLowStockCount++;
                    }
                }
              ?>
              <?php if ($alertLowStockCount > 0): ?>
                <span class="admin-status low"><?php echo number_format($alertLowStockCount); ?> Low Stock</span>
              <?php endif; ?>
              <?php if ($alertNoStockCount > 0): ?>
                <span class="admin-status no-stock"><?php echo number_format($alertNoStockCount); ?> No Stock</span>
              <?php endif; ?>
              <a href="admin_inventory.php" class="btn ghost small">View All</a>
            </div>
          </div>
          <div class="admin-table-wrap admin-inventory-alerts-scroll">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Variant</th>
                  <th>Stock</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($lowStockAlerts): ?>
                  <?php foreach ($lowStockAlerts as $alert): ?>
                    <?php
                      $stockQty = (int) ($alert['stock_qty'] ?? 0);
                      $alertStatus = admin_dashboard_inventory_alert_status($stockQty);
                      $isNoStock = $alertStatus === 'no_stock';
                    ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string) $alert['title']); ?></td>
                      <td><?php echo htmlspecialchars((string) $alert['variant']); ?></td>
                      <td><?php echo number_format($stockQty); ?> left</td>
                      <td>
                        <span class="admin-status <?php echo $isNoStock ? 'no-stock' : 'low'; ?>">
                          <?php echo $isNoStock ? 'No Stock' : 'Low Stock'; ?>
                        </span>
                      </td>
                      <td><a href="admin_inventory.php?edit=<?php echo (int) ($alert['product_id'] ?? 0); ?>">Restock</a></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" style="color:var(--muted);">No variants with stock below 5. All items are sufficiently stocked.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>

        <article class="card" id="preorders">
          <div class="admin-panel-head">
            <h3>Preorder Demand Analysis</h3>
          </div>
          <div class="admin-panel-grid" style="margin-bottom:0;">
            <div class="admin-chart-wrap admin-preorder-chart-wrap" id="preorderChartWrap">
              <?php echo admin_dashboard_render_preorder_donut_svg($preorderCategories); ?>
            </div>
            <div>
              <h4 style="margin:0 0 12px;font-size:15px;">All Preordered Items (<?php echo count($topPreorderItems); ?>)</h4>
              <div class="admin-preorder-summary-scroll">
              <?php if ($topPreorderItems): ?>
                <?php foreach ($topPreorderItems as $item): ?>
                  <div class="admin-preorder-row">
                    <div class="admin-preorder-label">
                      <span><?php echo htmlspecialchars((string) $item['title']); ?></span>
                      <strong><?php echo number_format((int) $item['qty']); ?> · <?php echo (int) $item['share']; ?>%</strong>
                    </div>
                    <div class="admin-progress"><span style="width:<?php echo (int) $item['share']; ?>%"></span></div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <p class="admin-empty-note">No preorder demand recorded yet.</p>
              <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="admin-preorder-orders">
            <div class="admin-panel-head" style="margin-bottom:12px;">
              <h4 style="margin:0;font-size:15px;">All Preorder Items (<?php echo count($allPreorderLineItems); ?>)</h4>
              <div class="admin-preorder-head-actions">
                <label class="admin-preorder-status-filter">
                  <span class="sr-only">Filter by order status</span>
                  <select name="preorder_status" aria-label="Filter by order status"
                    onchange="var params = new URLSearchParams(window.location.search); if (this.value) { params.set('preorder_status', this.value); } else { params.delete('preorder_status'); } window.location.href = 'admin.php?' + params.toString() + '#preorders';">
                    <option value="">All statuses</option>
                    <?php foreach ($orderStatuses as $statusOption): ?>
                      <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $preorderStatusFilter === $statusOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($statusOption); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <a href="admin_preorders.php<?php echo $preorderStatusFilter !== '' ? '?status=' . urlencode($preorderStatusFilter) : ''; ?>" class="btn ghost small">View all preorders</a>
              </div>
            </div>
            <div class="admin-preorder-scroll">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($allPreorderLineItems): ?>
                    <?php foreach ($allPreorderLineItems as $lineItem): ?>
                      <tr>
                        <td><strong><?php echo htmlspecialchars(checkout_display_order_number($lineItem), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td><?php echo htmlspecialchars((string) $lineItem['customer_name']); ?></td>
                        <td><?php echo htmlspecialchars(admin_order_format_date($lineItem['created_at'])); ?></td>
                        <td class="admin-preorder-items-cell">
                          <div class="admin-preorder-item-line">
                            <img src="<?php echo htmlspecialchars(admin_order_img_url((string) ($lineItem['image_path'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" alt="">
                            <span><?php echo htmlspecialchars(admin_dashboard_preorder_item_label($lineItem)); ?></span>
                          </div>
                        </td>
                        <td><?php echo number_format((int) $lineItem['qty']); ?></td>
                        <td>
                          <?php if ((string) $lineItem['status'] === 'Pending'): ?>
                            <form method="post" action="admin.php<?php echo htmlspecialchars(admin_dashboard_sales_filter_query($salesDateRange, $preorderStatusFilter), ENT_QUOTES, 'UTF-8'); ?>#preorders" class="admin-preorder-confirm-form">
                              <input type="hidden" name="order_id" value="<?php echo (int) $lineItem['order_id']; ?>">
                              <label class="admin-confirm-check" title="Confirm preorder order">
                                <input type="checkbox" name="confirm_preorder" value="1" onchange="this.form.submit()">
                                <span class="admin-status pending">Pending</span>
                              </label>
                            </form>
                          <?php else: ?>
                            <span class="admin-status <?php echo admin_order_status_class((string) $lineItem['status']); ?>">
                              <?php echo htmlspecialchars((string) $lineItem['status']); ?>
                            </span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <a href="admin_preorders.php?order=<?php echo (int) $lineItem['order_id']; ?>">View</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="7" style="color:var(--muted);">
                        <?php echo $preorderStatusFilter !== '' ? 'No preorder items with this status.' : 'No preorder items yet.'; ?>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </article>
      </section>
    </main>
  </div>

  <?php include 'include/admin_footer.php'; ?>

<script>
document.querySelectorAll('#reports .chip').forEach(function(chip) {
  chip.addEventListener('click', function() {
    var period = chip.textContent.trim().toLowerCase();
    document.querySelectorAll('#reports .chip').forEach(function(btn) {
      btn.classList.remove('active');
    });
    chip.classList.add('active');
    document.querySelectorAll('[data-sales-panel]').forEach(function(panel) {
      panel.classList.toggle('is-active', panel.getAttribute('data-sales-panel') === period);
    });
  });
});
</script>
</body>
</html>
