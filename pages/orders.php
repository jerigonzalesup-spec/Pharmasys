<?php
session_start();
// allow role variants like 'Admin', 'administrator', 'ADMIN' etc.
$roleVal = (string)($_SESSION['role'] ?? '');
$isAdmin = (bool)preg_match('/\badmin\b/i', $roleVal);
if (!isset($_SESSION['user_id']) || ! $isAdmin) {
  header('Location: /Pharma_Sys/pages/login.php');
  exit;
}
include __DIR__ . '/../func/db.php';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 4; // show fewer orders per page so pagination is usable in the admin UI
$offset = ($page - 1) * $perPage;
$total = 0;
$orders = [];
// Status filter (optional): pending, processing, shipped, delivered
$allowedFilters = ['pending','processing','shipped','delivered'];
$filter = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : '';
if ($filter === 'all') $filter = '';
if ($filter !== '' && !in_array($filter, $allowedFilters, true)) $filter = '';

try {
  // Ensure orders.status column exists (safe, non-fatal)
  try {
    $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if (!$colRes || $colRes->num_rows === 0) {
      $conn->query("ALTER TABLE orders ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER order_date");
    }
  } catch (Exception $e) {}

  if ($filter) {
    $cstmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE COALESCE(status,'pending') = ?");
    if ($cstmt) {
      $cstmt->bind_param('s', $filter);
      $cstmt->execute();
      $cres = $cstmt->get_result();
      if ($cres && ($crow = $cres->fetch_assoc())) $total = (int)$crow['total'];
      $cstmt->close();
    }

  $stmt = $conn->prepare("SELECT o.order_id, o.user_id, o.total_amount, o.address, o.payment_method, o.order_date, COALESCE(o.status,'pending') AS status, COALESCE(o.prescription_image,'') AS prescription_image, c.username FROM orders o LEFT JOIN customer c ON o.user_id = c.Customer_id WHERE COALESCE(o.status,'pending') = ? ORDER BY o.order_date DESC LIMIT ?, ?");
    if ($stmt) {
      $stmt->bind_param('sii', $filter, $offset, $perPage);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) while ($r = $res->fetch_assoc()) $orders[] = $r;
      $stmt->close();
    }
  } else {
    $cstmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders");
    if ($cstmt) {
      $cstmt->execute();
      $cres = $cstmt->get_result();
      if ($cres && ($crow = $cres->fetch_assoc())) $total = (int)$crow['total'];
      $cstmt->close();
    }

  $stmt = $conn->prepare("SELECT o.order_id, o.user_id, o.total_amount, o.address, o.payment_method, o.order_date, COALESCE(o.status,'pending') AS status, COALESCE(o.prescription_image,'') AS prescription_image, c.username FROM orders o LEFT JOIN customer c ON o.user_id = c.Customer_id ORDER BY o.order_date DESC LIMIT ?, ?");
    if ($stmt) {
      $stmt->bind_param('ii', $offset, $perPage);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res) while ($r = $res->fetch_assoc()) $orders[] = $r;
      $stmt->close();
    }
  }
} catch (Exception $e) {
    // ignore
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Orders - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    .order-row { border-radius:10px; background:#fff; box-shadow:0 6px 18px rgba(0,0,0,0.04); padding:12px; margin-bottom:10px; }
    .order-items { margin-top:8px; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../func/header.php'; ?>
  <div class="container my-4">
    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="me-3">Orders</h3>
      <div class="btn-group ms-2 me-auto" role="group" aria-label="Order status filter">
        <?php
          $filters = ['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
          foreach ($filters as $k => $label) {
            $isActive = ($k === $filter) || ($k === '' && $filter === '');
            $url = '/Pharma_Sys/pages/orders.php' . ($k !== '' ? ('?status=' . urlencode($k)) : '');
            echo '<a href="' . htmlspecialchars($url) . '" class="btn btn-sm ' . ($isActive ? 'btn-primary' : 'btn-outline-secondary') . '">' . htmlspecialchars($label) . '</a>';
          }
        ?>
      </div>
      <a href="/Pharma_Sys/pages/admin.php" class="btn btn-outline-secondary">Back to Admin</a>
    </div>

    <?php if (empty($orders)): ?>
      <div class="alert alert-info">No orders found.</div>
    <?php else: ?>
      <?php foreach ($orders as $o): ?>
        <div class="order-row">
          <div class="d-flex align-items-start">
            <div style="flex:1">
              <div class="fw-semibold">Order #<?= (int)$o['order_id'] ?> <small class="text-muted">by <?= htmlspecialchars($o['username'] ?? ('User ' . (int)$o['user_id'])) ?></small></div>
              <div class="mt-1">
                <?php
                  $st = strtolower(trim((string)($o['status'] ?? 'pending')));
                  $badgeClass = 'secondary';
                  if ($st === 'processing') $badgeClass = 'warning';
                  if ($st === 'shipped') $badgeClass = 'info';
                  if ($st === 'delivered') $badgeClass = 'success';
                  if ($st === 'received') $badgeClass = 'success';
                  if ($st === 'cancelled' || $st === 'canceled') $badgeClass = 'danger';
                ?>
                <span class="badge bg-<?= htmlspecialchars($badgeClass) ?>" id="status-badge-<?= (int)$o['order_id'] ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span>

                <div class="btn-group ms-2">
                  <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Change status</button>
                  <ul class="dropdown-menu">
                    <li>
                      <form action="/Pharma_Sys/pages/order_update_status_action.php" method="post" class="m-0">
                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                        <input type="hidden" name="status" value="processing">
                        <button type="submit" class="dropdown-item">Processing</button>
                      </form>
                    </li>
                    <li>
                      <form action="/Pharma_Sys/pages/order_update_status_action.php" method="post" class="m-0">
                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                        <input type="hidden" name="status" value="shipped">
                        <button type="submit" class="dropdown-item">Shipped</button>
                      </form>
                    </li>
                    <li>
                      <form action="/Pharma_Sys/pages/order_update_status_action.php" method="post" class="m-0">
                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                        <input type="hidden" name="status" value="delivered">
                        <button type="submit" class="dropdown-item">Delivered</button>
                      </form>
                    </li>
                  </ul>
                </div>
                <?php if (!empty($o['prescription_image'])): ?>
                  <a href="/Pharma_Sys/assets/prescriptions/<?= rawurlencode($o['prescription_image']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-2" title="View prescription">
                    <i class="bi bi-file-earmark-image"></i>&nbsp;View prescription
                  </a>
                <?php endif; ?>
              </div>
              <div class="small text-muted">Placed: <?= htmlspecialchars((new DateTime($o['order_date']))->format('M j, Y H:i')) ?></div>
              <div class="mt-2">Total: ₱<?= htmlspecialchars(number_format((float)$o['total_amount'],2)) ?> &nbsp; &middot; &nbsp; Payment: <?= htmlspecialchars($o['payment_method']) ?></div>
              <div class="mt-2 text-truncate"><strong>Shipping:</strong> <?= htmlspecialchars($o['address']) ?></div>

              <!-- Duplicate prescription link removed: header button remains for viewing prescriptions -->

              <div class="order-items">
                <?php
                  // Server-side render items for reliability (avoids AJAX/session issues)
                  $items_html = '';
                  try {
                    $itStmt = $conn->prepare("SELECT oi.item_id, oi.product_id, oi.quantity, oi.price, COALESCE(p.Product_name,'') AS product_name FROM order_items oi LEFT JOIN product p ON oi.product_id = p.Product_id WHERE oi.order_id = ?");
                    if ($itStmt) {
                      $itStmt->bind_param('i', $o['order_id']);
                      $itStmt->execute();
                      $itRes = $itStmt->get_result();
                      $rows = [];
                      if ($itRes) {
                        while ($ir = $itRes->fetch_assoc()) $rows[] = $ir;
                      }
                      $itStmt->close();
                      if (empty($rows)) {
                        $items_html = '<div class="small text-muted">No items</div>';
                      } else {
                        // show prescription thumbnail if order has blob
                        if (!empty($o['prescription_image'])) {
                          $items_html .= '<div class="mb-2"><small class="text-muted">Prescription:</small> <a href="/Pharma_Sys/assets/prescriptions/' . rawurlencode($o['prescription_image']) . '" target="_blank" class="ms-2"><img src="/Pharma_Sys/assets/prescriptions/' . rawurlencode($o['prescription_image']) . '" alt="Prescription" style="height:48px;object-fit:cover;border-radius:6px;border:1px solid #eee;"></a></div>';
                        }
                        $items_html .= '<ul class="list-group">';
                        foreach ($rows as $it) {
                          $iname = htmlspecialchars($it['product_name'] ?: 'Item');
                          $qty = (int)$it['quantity'];
                          $price = number_format((float)$it['price'],2);
                          $items_html .= "<li class=\"list-group-item d-flex justify-content-between align-items-center\"><div><strong>{$iname}</strong> <small class=\"text-muted\">x{$qty}</small></div><div>₱{$price}</div></li>";
                        }
                        $items_html .= '</ul>';
                      }
                    } else {
                      $items_html = '<div class="small text-muted">Unable to load items</div>';
                    }
                  } catch (Exception $e) {
                    $items_html = '<div class="small text-danger">Error loading items</div>';
                  }
                  echo $items_html;
                ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php
        $totalPages = max(1, (int)ceil($total / $perPage));
        $prev = $page > 1 ? $page - 1 : null;
        $next = $page < $totalPages ? $page + 1 : null;
      ?>
      <nav aria-label="Orders pagination">
        <ul class="pagination">
          <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $prev ?: 1 ?>">Previous</a></li>
          <?php for ($p=1;$p<=$totalPages;$p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
          <?php endfor; ?>
          <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $next ?: $totalPages ?>">Next</a></li>
        </ul>
      </nav>

    <?php endif; ?>
  </div>

<script>
function fetchItems(btn){
  var id = btn.getAttribute('data-order-id');
  var target = document.getElementById('items-' + id);
  if (!id || !target) return;
  // already loaded
  if (target.innerHTML.trim() !== '') return;
  fetch('/Pharma_Sys/pages/orders_items.php?order_id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e){ console.error('orders_items non-json', t); return null; } }); })
      .then(j => {
        if (!j) { target.innerHTML = '<div class="small text-muted">No items (invalid response)</div>'; return; }
        if (j.error) { target.innerHTML = '<div class="small text-danger">Error: ' + escapeHtml(j.error + (j.message ? (': '+j.message) : '')) + '</div>'; return; }
        if (!Array.isArray(j) || j.length === 0) { target.innerHTML = '<div class="small text-muted">No items</div>'; return; }
        var html = '';
        // if the order has a prescription stored in DB, show it once at the top
        var hasPres = j[0] && (j[0].has_prescription == 1 || j[0].has_prescription === true);
        if (hasPres) {
          html += '<div class="mb-2"><small class="text-muted">Prescription:</small> <a href="/Pharma_Sys/pages/prescription_view.php?order_id=' + encodeURIComponent(id) + '" target="_blank" class="ms-2"><img src="/Pharma_Sys/pages/prescription_view.php?order_id=' + encodeURIComponent(id) + '" alt="Prescription" style="height:48px;object-fit:cover;border-radius:6px;border:1px solid #eee;"></a></div>';
        }
        html += '<ul class="list-group">';
        j.forEach(function(it){
          var name = it.product_name || it.Product_name || 'Item';
          html += '<li class="list-group-item d-flex justify-content-between align-items-center"><div><strong>'+escapeHtml(name)+'</strong> <small class="text-muted">x'+parseInt(it.quantity)+'</small></div><div>₱'+parseFloat(it.price).toFixed(2)+'</div></li>';
        });
        html += '</ul>';
        target.innerHTML = html;
      }).catch(function(){ target.innerHTML = '<div class="small text-muted">Error loading items</div>'; });
}
function escapeHtml(s){ return String(s||'').replace(/[&"'<>]/g, function(c){return {'&':'&amp;','"':'&quot;','\'':'&#39;','<':'&lt;','>':'&gt;'}[c];}); }
// status update handler
(function(){
  function bindStatusButtons(){
    var els = document.querySelectorAll('.update-status');
    if (!els || els.length === 0) { console.debug('No status buttons found'); }
    els.forEach(function(el){
      // avoid double-binding
      if (el._statusBound) return; el._statusBound = true;
      el.addEventListener('click', function(e){
        e.preventDefault();
        var target = e.currentTarget || el;
        console.debug('status click', target);
        var orderId = target.getAttribute('data-order-id');
        var status = target.getAttribute('data-status');
        if (!orderId || !status) { console.warn('Missing orderId/status', orderId, status); return; }
        if (!confirm('Set order #' + orderId + ' status to ' + status + '?')) return;
        fetch('/Pharma_Sys/pages/order_update_status.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: 'order_id=' + encodeURIComponent(orderId) + '&status=' + encodeURIComponent(status)
        }).then(function(r){
          return r.text().then(function(t){
            try { return JSON.parse(t); } catch(e){ console.error('Non-JSON response:', t); return { success:false, error: 'invalid_response' }; }
          });
        }).then(function(j){
          console.log('order_update_status response', j);
          if (j && j.success) {
            var badge = document.getElementById('status-badge-' + orderId);
            if (badge) { badge.textContent = j.status_display; badge.className = 'badge bg-' + (j.status_class || 'secondary'); }
            alert('Status updated');
          } else {
            var msg = 'Failed to update status' + (j && j.error ? (': ' + j.error) : '');
            alert(msg);
          }
        }).catch(function(err){ console.error('Fetch error', err); alert('Error updating status'); });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindStatusButtons);
  } else {
    // DOM already ready
    bindStatusButtons();
  }
})();

// Delegated click listener as a fallback if direct listeners don't fire
(function(){
  function handleStatusClickElement(el){
    if (!el) return;
    if (el._statusHandled) return;
    el._statusHandled = true;
    setTimeout(function(){ el._statusHandled = false; }, 1000);
    try {
      var orderId = el.getAttribute('data-order-id');
      var status = el.getAttribute('data-status');
      console.debug('delegated status click', orderId, status, el);
      if (!orderId || !status) { console.warn('Missing orderId/status', orderId, status); return; }
      if (!confirm('Set order #' + orderId + ' status to ' + status + '?')) return;
      fetch('/Pharma_Sys/pages/order_update_status.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: 'order_id=' + encodeURIComponent(orderId) + '&status=' + encodeURIComponent(status)
      }).then(function(r){
        return r.text().then(function(t){
          try { return JSON.parse(t); } catch(e){ console.error('Non-JSON response:', t); return { success:false, error: 'invalid_response' }; }
        });
      }).then(function(j){
        console.log('order_update_status (delegated) response', j);
        if (j && j.success) {
          var badge = document.getElementById('status-badge-' + orderId);
          if (badge) { badge.textContent = j.status_display; badge.className = 'badge bg-' + (j.status_class || 'secondary'); }
          alert('Status updated');
        } else {
          var msg = 'Failed to update status' + (j && j.error ? (': ' + j.error) : '');
          alert(msg);
        }
      }).catch(function(err){ console.error('Fetch error', err); alert('Error updating status'); });
    } catch (err) { console.error('delegated handler error', err); }
  }

  document.addEventListener('click', function(e){
    var el = e.target.closest && e.target.closest('.update-status');
    if (el) {
      e.preventDefault();
      handleStatusClickElement(el);
    }
  }, false);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
