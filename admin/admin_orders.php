<?php
$adminOrdersConfig = array_merge([
    'script' => 'admin_orders.php',
    'nav' => 'orders',
    'kind' => 'instock',
    'title' => 'Orders',
    'subtitle' => 'In-stock orders only (latest 10) · Manage status and details',
    'empty_label' => 'No in-stock orders found.',
    'count_label' => 'in-stock',
    'page_title' => 'Orders — Gemora Admin',
    'load_error' => 'Could not load orders.',
], $adminOrdersConfig ?? []);

$adminNavActive = $adminOrdersConfig['nav'];
$adminOrdersScript = $adminOrdersConfig['script'];
$adminOrdersKind = $adminOrdersConfig['kind'];
require_once __DIR__ . '/include/admin_auth.php';
require_once __DIR__ . '/include/admin_paths.php';
require_once __DIR__ . '/../database/pdo.php';
require_once __DIR__ . '/include/admin_orders_lib.php';

$message = '';
$messageType = 'success';
$statuses = admin_order_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));

    try {
        $result = admin_orders_update_status($pdo, $orderId, $newStatus);
        if ($result['ok'] && $orderId > 0) {
            $redirect = $adminOrdersScript . '?order=' . $orderId . '&msg=updated';
            header('Location: ' . $redirect);
            exit;
        }
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
    } catch (PDOException $e) {
        error_log('Admin order status update failed: ' . $e->getMessage());
        $message = 'Could not update order status.';
        $messageType = 'error';
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'updated' && $message === '') {
    $message = 'Order status updated.';
    $messageType = 'success';
}

$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterQ = trim((string) ($_GET['q'] ?? ''));
$perPage = 10;
$offset = 0;

$viewOrderId = (int) ($_GET['order'] ?? 0);
$orderDetail = null;

try {
    $summary = admin_orders_summary_counts($pdo, $adminOrdersKind);
    $listResult = admin_orders_fetch_list($pdo, [
        'status' => $filterStatus,
        'q' => $filterQ,
        'order_kind' => $adminOrdersKind,
        'limit' => $perPage,
        'offset' => $offset,
    ]);
    $orders = $listResult['rows'];
    $totalOrders = $listResult['total'];

    if ($viewOrderId > 0) {
        $orderDetail = admin_orders_fetch_detail($pdo, $viewOrderId);
        if (!$orderDetail) {
            $message = $message === '' ? 'Order not found.' : $message;
            $messageType = 'error';
            $viewOrderId = 0;
        }
    }
} catch (PDOException $e) {
    error_log('Admin orders fetch failed: ' . $e->getMessage());
    $summary = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'shipped' => 0];
    $orders = [];
    $totalOrders = 0;
    if ($message === '') {
        $message = $adminOrdersConfig['load_error'];
        $messageType = 'error';
    }
}

function admin_orders_page_url(array $params = []): string
{
    global $filterStatus, $filterQ, $viewOrderId, $adminOrdersScript;

    $query = [];
    if ($filterStatus !== '') {
        $query['status'] = $filterStatus;
    }
    if ($filterQ !== '') {
        $query['q'] = $filterQ;
    }
    if ($viewOrderId > 0) {
        $query['order'] = $viewOrderId;
    }

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return $adminOrdersScript . ($query ? '?' . http_build_query($query) : '');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title><?php echo htmlspecialchars($adminOrdersConfig['page_title'], ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="../assets/style.css">
<?php include __DIR__ . '/include/admin_sidebar_styles.php'; ?>
<style>
  main.content {
    min-width: 0;
    grid-template-columns: minmax(240px, 320px) minmax(0, 1fr);
    align-items: start;
  }
  main.content > section { min-width: 0; width: 100%; }

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

  .ord-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
  }
  .ord-kpi {
    padding: 14px;
    border-radius: 8px;
    background: #fff;
    border: 1px solid var(--glass-border);
  }
  .ord-kpi-label {
    font-size: 12px;
    color: var(--muted);
    margin: 0 0 6px;
  }
  .ord-kpi-value {
    font-size: 22px;
    font-weight: 700;
    margin: 0;
    color: var(--text);
  }

  .ord-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
  }
  .ord-toolbar input,
  .ord-toolbar select {
    padding: 10px 12px;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    font-size: 14px;
    background: #fff;
    color: var(--text);
  }
  .ord-toolbar input { min-width: 220px; flex: 1; }
  .ord-toolbar select { min-width: 160px; }

  .ord-table-wrap {
    max-height: 420px;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    background: #fff;
  }
  .ord-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--accent-2);
  }
  .ord-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  .ord-table th,
  .ord-table td {
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid var(--glass-border);
    vertical-align: middle;
  }
  .ord-table th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--muted);
  }
  .ord-table tr:last-child td { border-bottom: 0; }
  .ord-table tr:hover td { background: rgba(243, 245, 249, 0.6); }
  .ord-table .ord-customer {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .ord-table .ord-customer small {
    color: var(--muted);
    font-size: 12px;
  }

  .ord-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
  }
  .ord-status.pending { background: #fff3cd; color: #856404; }
  .ord-status.confirmed { background: #d1ecf1; color: #0c5460; }
  .ord-status.shipped { background: #cce5ff; color: #004085; }
  .ord-status.delivered { background: #d4edda; color: #155724; }
  .ord-status.returned { background: #f8d7da; color: #721c24; }
  .ord-status.refunded { background: #e2e3e5; color: #383d41; }

  .ord-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 14px;
    flex-wrap: wrap;
    font-size: 13px;
    color: var(--muted);
  }

  .ord-detail-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 16px;
    margin-bottom: 16px;
  }
  .ord-detail-block h3 {
    margin: 0 0 10px;
    font-size: 15px;
  }
  .ord-meta-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    font-size: 14px;
  }
  .ord-meta-list li {
    display: flex;
    gap: 8px;
    justify-content: space-between;
    border-bottom: 1px dashed var(--glass-border);
    padding-bottom: 6px;
  }
  .ord-meta-list span:first-child { color: var(--muted); }
  .ord-shipping {
    white-space: pre-wrap;
    font-size: 13px;
    line-height: 1.5;
    color: var(--text);
    background: var(--accent-2);
    border-radius: 8px;
    padding: 12px;
  }

  .ord-items {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .ord-item {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 10px;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    background: #fff;
  }
  .ord-item img {
    width: 56px;
    height: 68px;
    object-fit: cover;
    border-radius: 6px;
    background: var(--accent-2);
  }
  .ord-item-info { flex: 1; min-width: 0; }
  .ord-item-info h4 {
    margin: 0 0 4px;
    font-size: 14px;
  }
  .ord-item-info p {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
  }
  .ord-item-price {
    font-weight: 700;
    font-size: 14px;
    white-space: nowrap;
  }

  .ord-status-form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 12px;
  }
  .ord-status-form select {
    padding: 10px 12px;
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    font-size: 14px;
    min-width: 180px;
  }
  .ord-payment-shot {
    margin-top: 10px;
  }
  .ord-payment-shot img {
    max-width: 280px;
    border-radius: 8px;
    border: 1px solid var(--glass-border);
    display: block;
    cursor: zoom-in;
  }
  .ord-payment-shot-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
  }
  .ord-payment-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.82);
  }
  .ord-payment-lightbox.open {
    display: flex;
  }
  .ord-payment-lightbox-panel {
    position: relative;
    max-width: min(96vw, 1100px);
    max-height: 92vh;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .ord-payment-lightbox img {
    display: block;
    max-width: 100%;
    max-height: calc(92vh - 56px);
    width: auto;
    height: auto;
    margin: 0 auto;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.35);
  }
  .ord-payment-lightbox-close {
    align-self: flex-end;
    border: 0;
    border-radius: 999px;
    width: 36px;
    height: 36px;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    color: #fff;
    background: rgba(255, 255, 255, 0.14);
  }
  .ord-payment-lightbox-close:hover {
    background: rgba(255, 255, 255, 0.24);
  }

  .ord-empty {
    padding: 28px;
    text-align: center;
    color: var(--muted);
    font-size: 14px;
  }

  @media (max-width: 900px) {
    .ord-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ord-detail-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 640px) {
    .ord-kpi-grid { grid-template-columns: 1fr; }
    .ord-table th:nth-child(4),
    .ord-table td:nth-child(4) { display: none; }
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
              <div style="font-weight:600"><?php echo htmlspecialchars($adminOrdersConfig['title'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="product-count"><?php echo htmlspecialchars($adminOrdersConfig['subtitle'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
          </div>
        </div>

        <?php if ($message !== ''): ?>
          <div class="admin-feedback <?php echo $messageType === 'error' ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
          </div>
        <?php endif; ?>

        <div class="ord-kpi-grid">
          <div class="ord-kpi">
            <p class="ord-kpi-label">Total orders</p>
            <p class="ord-kpi-value"><?php echo number_format($summary['total']); ?></p>
          </div>
          <div class="ord-kpi">
            <p class="ord-kpi-label">Pending</p>
            <p class="ord-kpi-value"><?php echo number_format($summary['pending']); ?></p>
          </div>
          <div class="ord-kpi">
            <p class="ord-kpi-label">Confirmed</p>
            <p class="ord-kpi-value"><?php echo number_format($summary['confirmed']); ?></p>
          </div>
          <div class="ord-kpi">
            <p class="ord-kpi-label">Shipped</p>
            <p class="ord-kpi-value"><?php echo number_format($summary['shipped']); ?></p>
          </div>
        </div>

        <?php if ($orderDetail): ?>
          <article class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
              <div>
                <h2 style="margin:0 0 6px;font-size:18px;">Order <?php echo htmlspecialchars(checkout_display_order_number($orderDetail), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p style="margin:0;color:var(--muted);font-size:13px;">
                  Placed <?php echo htmlspecialchars(admin_order_format_date($orderDetail['created_at'])); ?>
                </p>
              </div>
              <span class="ord-status <?php echo admin_order_status_class((string) $orderDetail['status']); ?>">
                <?php echo htmlspecialchars((string) $orderDetail['status']); ?>
              </span>
            </div>

            <div class="ord-detail-grid">
              <div class="ord-detail-block">
                <h3>Customer</h3>
                <ul class="ord-meta-list">
                  <li><span>Name</span><strong><?php echo htmlspecialchars((string) $orderDetail['customer_name']); ?></strong></li>
                  <li><span>Email</span><strong><?php echo htmlspecialchars((string) $orderDetail['customer_email']); ?></strong></li>
                  <li><span>Phone</span><strong><?php echo htmlspecialchars((string) ($orderDetail['customer_phone'] ?? '—')); ?></strong></li>
                  <li><span>Payment</span><strong><?php echo htmlspecialchars(checkout_payment_label((string) ($orderDetail['payment_method'] ?? ''))); ?></strong></li>
                  <li><span>Shipping fee</span><strong><?php echo htmlspecialchars(admin_order_format_money((int) ($orderDetail['shipping'] ?? 0))); ?></strong></li>
                  <li><span>Total</span><strong><?php echo htmlspecialchars(admin_order_format_money((int) ($orderDetail['total'] ?? 0))); ?></strong></li>
                </ul>

                <form method="post" action="<?php echo htmlspecialchars(admin_orders_page_url(['order' => (int) $orderDetail['order_id']])); ?>" class="ord-status-form">
                  <input type="hidden" name="order_id" value="<?php echo (int) $orderDetail['order_id']; ?>">
                  <label for="status" style="font-size:13px;color:var(--muted);">Update status</label>
                  <select id="status" name="status" required>
                    <?php foreach ($statuses as $statusOption): ?>
                      <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo (string) $orderDetail['status'] === $statusOption ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($statusOption); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="update_status" value="1" class="btn primary small">Save status</button>
                  <a href="<?php echo htmlspecialchars(admin_orders_page_url(['order' => null])); ?>" class="btn ghost small">Back to list</a>
                </form>
              </div>

              <div class="ord-detail-block">
                <h3>Shipping address</h3>
                <?php if (!empty($orderDetail['shipping_address'])): ?>
                  <div class="ord-shipping"><?php echo htmlspecialchars((string) $orderDetail['shipping_address']); ?></div>
                <?php else: ?>
                  <p style="margin:0;color:var(--muted);font-size:14px;">No shipping address recorded.</p>
                <?php endif; ?>

                <?php if (!empty($orderDetail['payment_screenshot'])): ?>
                  <?php $paymentScreenshotUrl = admin_order_img_url((string) $orderDetail['payment_screenshot']); ?>
                  <div class="ord-payment-shot">
                    <h3 style="margin:14px 0 8px;font-size:15px;">Payment screenshot</h3>
                    <img
                      src="<?php echo htmlspecialchars($paymentScreenshotUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      alt="Payment screenshot"
                      data-payment-full="<?php echo htmlspecialchars($paymentScreenshotUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      class="ord-payment-preview"
                    >
                    <div class="ord-payment-shot-actions">
                      <button type="button" class="btn ghost small ord-payment-view-full" data-payment-full="<?php echo htmlspecialchars($paymentScreenshotUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        View full size
                      </button>
                      <a class="btn ghost small" href="<?php echo htmlspecialchars($paymentScreenshotUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        Open in new tab
                      </a>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <h3 style="margin:0 0 10px;font-size:15px;">Items (<?php echo count($orderDetail['items']); ?>)</h3>
            <div class="ord-items">
              <?php foreach ($orderDetail['items'] as $item): ?>
                <?php
                  $size = trim((string) ($item['size'] ?? ''));
                  $color = trim((string) ($item['color'] ?? ''));
                  $variantParts = array_filter([$size !== '' ? 'Size ' . $size : '', $color !== '' ? 'Color ' . $color : '']);
                  $lineTotal = (int) $item['unit_price'] * (int) $item['qty'];
                ?>
                <div class="ord-item">
                  <img src="<?php echo htmlspecialchars(admin_order_img_url((string) ($item['image_path'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" alt="">
                  <div class="ord-item-info">
                    <h4><?php echo htmlspecialchars((string) $item['title']); ?></h4>
                    <p><?php echo htmlspecialchars((string) ($item['brand_name'] ?? '')); ?></p>
                    <p><?php echo htmlspecialchars(implode(' · ', $variantParts) ?: 'Default variant'); ?> · Qty <?php echo (int) $item['qty']; ?></p>
                  </div>
                  <div class="ord-item-price"><?php echo htmlspecialchars(admin_order_format_money($lineTotal)); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endif; ?>

        <article class="card">
          <form class="ord-toolbar" method="get" action="<?php echo htmlspecialchars($adminOrdersScript, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="search" name="q" placeholder="Search order #, name, email, phone…"
              value="<?php echo htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8'); ?>">
            <select name="status" aria-label="Filter by status">
              <option value="">All statuses</option>
              <?php foreach ($statuses as $statusOption): ?>
                <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>"
                  <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($statusOption); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn primary small">Filter</button>
            <?php if ($filterStatus !== '' || $filterQ !== ''): ?>
              <a href="<?php echo htmlspecialchars($adminOrdersScript, ENT_QUOTES, 'UTF-8'); ?>" class="btn ghost small">Clear</a>
            <?php endif; ?>
          </form>

          <?php if (empty($orders)): ?>
            <div class="ord-empty"><?php echo htmlspecialchars($adminOrdersConfig['empty_label'], ENT_QUOTES, 'UTF-8'); ?></div>
          <?php else: ?>
            <div class="ord-table-wrap">
              <table class="ord-table">
                <thead>
                  <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Payment</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($orders as $orderRow): ?>
                    <tr>
                      <td><strong><?php echo htmlspecialchars(checkout_display_order_number($orderRow), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                      <td>
                        <div class="ord-customer">
                          <span><?php echo htmlspecialchars((string) $orderRow['customer_name']); ?></span>
                          <small><?php echo htmlspecialchars((string) $orderRow['customer_email']); ?></small>
                        </div>
                      </td>
                      <td><?php echo htmlspecialchars(admin_order_format_date($orderRow['created_at'])); ?></td>
                      <td><?php echo (int) ($orderRow['item_qty'] ?? 0); ?></td>
                      <td><?php echo htmlspecialchars(checkout_payment_label((string) ($orderRow['payment_method'] ?? ''))); ?></td>
                      <td><?php echo htmlspecialchars(admin_order_format_money((int) ($orderRow['total'] ?? 0))); ?></td>
                      <td>
                        <span class="ord-status <?php echo admin_order_status_class((string) $orderRow['status']); ?>">
                          <?php echo htmlspecialchars((string) $orderRow['status']); ?>
                        </span>
                      </td>
                      <td>
                        <a class="btn ghost small" href="<?php echo htmlspecialchars(admin_orders_page_url(['order' => (int) $orderRow['order_id']])); ?>">View</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="ord-pagination">
              <span>Showing <?php echo number_format(count($orders)); ?> latest <?php echo htmlspecialchars($adminOrdersConfig['count_label'], ENT_QUOTES, 'UTF-8'); ?> order<?php echo count($orders) === 1 ? '' : 's'; ?></span>
            </div>
          <?php endif; ?>
        </article>
      </section>
    </main>
  </div>

  <div class="ord-payment-lightbox" id="ordPaymentLightbox" hidden>
    <div class="ord-payment-lightbox-panel" role="dialog" aria-modal="true" aria-label="Payment screenshot full size">
      <button type="button" class="ord-payment-lightbox-close" id="ordPaymentLightboxClose" aria-label="Close">&times;</button>
      <img src="" alt="Payment screenshot full size" id="ordPaymentLightboxImg">
    </div>
  </div>

  <?php include 'include/admin_footer.php'; ?>
  <script>
  (function () {
    var lightbox = document.getElementById('ordPaymentLightbox');
    var lightboxImg = document.getElementById('ordPaymentLightboxImg');
    var closeBtn = document.getElementById('ordPaymentLightboxClose');
    if (!lightbox || !lightboxImg || !closeBtn) return;

    function openPaymentLightbox(url) {
      if (!url) return;
      lightboxImg.src = url;
      lightbox.classList.add('open');
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
    }

    function closePaymentLightbox() {
      lightbox.classList.remove('open');
      lightbox.hidden = true;
      lightboxImg.removeAttribute('src');
      document.body.style.overflow = '';
    }

    document.querySelectorAll('.ord-payment-view-full, .ord-payment-preview').forEach(function (el) {
      el.addEventListener('click', function () {
        openPaymentLightbox(el.getAttribute('data-payment-full'));
      });
    });

    closeBtn.addEventListener('click', closePaymentLightbox);
    lightbox.addEventListener('click', function (e) {
      if (e.target === lightbox) closePaymentLightbox();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && lightbox.classList.contains('open')) closePaymentLightbox();
    });
  })();
  </script>
</body>
</html>
