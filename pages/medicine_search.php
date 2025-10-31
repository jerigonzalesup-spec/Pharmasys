<?php
session_start();
include __DIR__ . '/../func/db.php';
include __DIR__ . '/../func/header.php';

// Pagination and filters
$query = trim((string)($_GET['query'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
// Fixed per-page: show 6 medicines per page so layout fits header/footer without scrolling
$perPage = 6;
$offset = ($page - 1) * $perPage;

$filter_category = trim((string)($_GET['category'] ?? ''));
$filter_type = trim((string)($_GET['type'] ?? ''));
$filter_min_price = trim((string)($_GET['min_price'] ?? ''));
$filter_max_price = trim((string)($_GET['max_price'] ?? ''));
$filter_stock = trim((string)($_GET['stock'] ?? ''));// values: any|in|out
$filter_prescription = trim((string)($_GET['prescription'] ?? ''));// any|yes|no

$products = [];
// fetch available categories/types for the filters
$categories = [];
$types = [];
try {
  $cres = $conn->query("SELECT DISTINCT category FROM product WHERE category IS NOT NULL AND category<>'' ORDER BY category");
  if ($cres) { while ($r = $cres->fetch_row()) $categories[] = $r[0]; }
  $tres = $conn->query("SELECT DISTINCT `type` FROM product WHERE `type` IS NOT NULL AND `type`<>'' ORDER BY `type`");
  if ($tres) { while ($r = $tres->fetch_row()) $types[] = $r[0]; }
} catch (Exception $e) {}

// build dynamic prepared statement
try {
  $where = ' WHERE 1=1 ';
  $typesStr = '';
  $params = [];

  if ($query !== '') {
    $where .= " AND (Product_name LIKE ? OR description LIKE ? OR category LIKE ? OR `type` LIKE ?)";
    $like = '%' . $query . '%';
    $typesStr .= 'ssss';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
  }
  if ($filter_category !== '') {
    $where .= " AND category = ?";
    $typesStr .= 's'; $params[] = $filter_category;
  }
  if ($filter_type !== '') {
    $where .= " AND `type` = ?";
    $typesStr .= 's'; $params[] = $filter_type;
  }
  if (is_numeric($filter_min_price)) {
    $where .= " AND price >= ?"; $typesStr .= 'd'; $params[] = (float)$filter_min_price;
  }
  if (is_numeric($filter_max_price)) {
    $where .= " AND price <= ?"; $typesStr .= 'd'; $params[] = (float)$filter_max_price;
  }
  if ($filter_stock === 'in') { $where .= " AND stck_qty > 0"; }
  if ($filter_stock === 'out') { $where .= " AND stck_qty <= 0"; }
  if ($filter_prescription === 'yes') { $where .= " AND prescription_needed = 1"; }
  if ($filter_prescription === 'no') { $where .= " AND prescription_needed = 0"; }

  // count total
  $countSql = "SELECT COUNT(*) AS cnt FROM product" . $where;
  $countStmt = $conn->prepare($countSql);
  if ($countStmt) {
    if (!empty($typesStr)) {
      $bindParams = [];
      $bindParams[] = & $typesStr;
      for ($i=0;$i<count($params);$i++) $bindParams[] = & $params[$i];
      call_user_func_array(array($countStmt,'bind_param'), $bindParams);
    }
    $countStmt->execute();
    $cres = $countStmt->get_result();
    $total = 0; if ($cres && ($crow = $cres->fetch_assoc())) $total = (int)$crow['cnt'];
    $countStmt->close();
  } else { $total = 0; }

  $sql = "SELECT Product_id, Product_name, description, image, price, stck_qty, prescription_needed FROM product" . $where . " ORDER BY Product_name ASC LIMIT ? OFFSET ?";
  $stmt = $conn->prepare($sql);
  if ($stmt) {
    // bind params + perPage/offset
    $fullTypes = $typesStr . 'ii';
    $fullParams = $params;
    $fullParams[] = $perPage; $fullParams[] = $offset;
    $bindParams = [];
    $bindParams[] = & $fullTypes;
    for ($i=0;$i<count($fullParams);$i++) $bindParams[] = & $fullParams[$i];
    call_user_func_array(array($stmt,'bind_param'), $bindParams);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) { while ($r = $res->fetch_assoc()) $products[] = $r; $res->free(); }
    $stmt->close();
  }
} catch (Exception $e) {
  // ignore and show no results
  $total = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Search Medicines - PharmaSys</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>
  <main class="container my-4">
    <div class="row">
      <div class="col-md-3">
        <h4>Search Medicines</h4>
        <form method="GET" action="/Pharma_Sys/pages/medicine_search.php">
          <div class="mb-2">
            <input class="form-control form-control-sm" type="search" name="query" placeholder="Search products" value="<?= htmlspecialchars($query) ?>">
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Category</label>
            <select name="category" class="form-select form-select-sm">
              <option value="">All categories</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $filter_category === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Type</label>
            <select name="type" class="form-select form-select-sm">
              <option value="">All types</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $filter_type === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Price range</label>
            <div class="d-flex gap-2">
              <input type="number" step="0.01" name="min_price" class="form-control form-control-sm" placeholder="Min" value="<?= htmlspecialchars($filter_min_price) ?>">
              <input type="number" step="0.01" name="max_price" class="form-control form-control-sm" placeholder="Max" value="<?= htmlspecialchars($filter_max_price) ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Stock status</label>
            <select name="stock" class="form-select form-select-sm">
              <option value="">Any</option>
              <option value="in" <?= $filter_stock === 'in' ? 'selected' : '' ?>>In stock</option>
              <option value="out" <?= $filter_stock === 'out' ? 'selected' : '' ?>>Out of stock</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small mb-1">Prescription</label>
            <select name="prescription" class="form-select form-select-sm">
              <option value="">Any</option>
              <option value="yes" <?= $filter_prescription === 'yes' ? 'selected' : '' ?>>Yes</option>
              <option value="no" <?= $filter_prescription === 'no' ? 'selected' : '' ?>>No</option>
            </select>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary flex-fill">Search</button>
            <a class="btn btn-sm btn-outline-secondary" href="/Pharma_Sys/pages/medicine_search.php">Reset</a>
          </div>
        </form>
      </div>
  <div id="results-col" class="col-md-9">
        <?php if ($query !== ''): ?>
          <p class="text-muted small">Showing results for: <strong><?= htmlspecialchars($query) ?></strong></p>
        <?php endif; ?>

        <?php if (empty($products)): ?>
          <div class="alert alert-info">No products found.</div>
        <?php else: ?>
          <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
            <?php foreach ($products as $row): ?>
              <div class="col">
                <div class="card h-100 shadow-sm small-card position-relative product-card"
                     data-id="<?= (int)$row['Product_id'] ?>"
                     data-name="<?= htmlspecialchars($row['Product_name'], ENT_QUOTES) ?>"
                     data-desc="<?= htmlspecialchars($row['description'], ENT_QUOTES) ?>"
                     data-price="<?= htmlspecialchars(number_format((float)$row['price'], 2), ENT_QUOTES) ?>"
                     data-stock="<?= (int)$row['stck_qty'] ?>"
                     data-image="<?= rawurlencode($row['image'] ?? '') ?>"
                     data-pres="<?= isset($row['prescription_needed']) ? ((int)$row['prescription_needed']) : 0 ?>">
                  <?php if (!empty($row['prescription_needed'])): ?>
                    <span class="badge bg-danger position-absolute" style="top:8px; right:8px; z-index:6;">Rx required</span>
                  <?php endif; ?>
                  <img src="<?= !empty($row['image']) ? '/Pharma_Sys/assets/image/' . rawurlencode($row['image']) : '/Pharma_Sys/assets/image/default.png' ?>" class="card-img-top" alt="<?= htmlspecialchars($row['Product_name']) ?>" style="height:110px;object-fit:cover;">
                  <div class="card-body p-2">
                    <h6 class="card-title text-primary mb-1" style="font-size:0.95rem"><?= htmlspecialchars($row['Product_name']) ?></h6>
                    <p class="card-text text-truncate mb-1" style="font-size:0.85rem"><?= htmlspecialchars($row['description']) ?></p>
                    <div class="d-flex justify-content-between align-items-center">
                      <div class="text-success">₱<?= number_format((float)$row['price'], 2) ?></div>
                      <small class="text-secondary">Stock: <?= (int)$row['stck_qty'] ?></small>
                    </div>
                  </div>
                  <div class="card-footer bg-transparent border-0 p-2">
                    <?php if ($row['stck_qty'] > 0): ?>
                      <button type="button" class="btn btn-sm btn-primary w-100 view-product-btn"
                              data-id="<?= (int)$row['Product_id'] ?>"
                              data-name="<?= htmlspecialchars($row['Product_name'], ENT_QUOTES) ?>"
                              data-desc="<?= htmlspecialchars($row['description'], ENT_QUOTES) ?>"
                              data-price="<?= htmlspecialchars(number_format((float)$row['price'], 2), ENT_QUOTES) ?>"
                              data-stock="<?= (int)$row['stck_qty'] ?>"
                              data-image="<?= rawurlencode($row['image'] ?? '') ?>"
                              data-pres="<?= isset($row['prescription_needed']) ? ((int)$row['prescription_needed']) : 0 ?>">
                        View
                      </button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-secondary w-100" disabled>Out of Stock</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
            <?php
              // Render invisible placeholder cards so the grid keeps consistent height when fewer than $perPage items are shown
              $shown = count($products);
              $placeholders = max(0, ($perPage ?? 6) - $shown);
              for ($i = 0; $i < $placeholders; $i++): ?>
                <div class="col" aria-hidden="true">
                  <div class="card h-100 shadow-sm small-card placeholder-card">
                    <div class="card-img-top" style="height:110px; background:transparent"></div>
                    <div class="card-body p-2">
                      <h6 class="card-title text-primary mb-1" style="font-size:0.95rem">&nbsp;</h6>
                      <p class="card-text text-truncate mb-1" style="font-size:0.85rem">&nbsp;</p>
                      <div class="d-flex justify-content-between align-items-center">
                        <div class="text-success">&nbsp;</div>
                        <small class="text-secondary">&nbsp;</small>
                      </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 p-2">&nbsp;</div>
                  </div>
                </div>
            <?php endfor; ?>
          </div>

          <?php
            // pagination control
            $totalPages = max(1, (int)ceil(($total ?? 0) / $perPage));
            $prev = $page > 1 ? $page - 1 : null;
            $next = $page < $totalPages ? $page + 1 : null;
            // helper to build query strings while preserving filters
            function qbuild($overrides = []){
              $base = array_merge($_GET, $overrides);
              return '?' . http_build_query($base);
            }
          ?>
          <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination pagination-sm">
              <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="<?= $prev ? qbuild(['page'=>$prev]) : '#' ?>">Previous</a></li>
              <?php for ($p=1;$p<=$totalPages;$p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= qbuild(['page'=>$p]) ?>"><?= $p ?></a></li>
              <?php endfor; ?>
              <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="<?= $next ? qbuild(['page'=>$next]) : '#' ?>">Next</a></li>
            </ul>
          </nav>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/../func/footer.php'; ?>

  <style>
    /* Prevent page scrolling and avoid header/footer overlap */
    /* Force no page scrolling on this page (override header inline styles) */
  :root { --header-height: 56px; --footer-height: 48px; }
    html, body { height: 100%; overflow: hidden !important; }
    body.page-no-scroll { overflow: hidden !important; }
    /* Make the main container respect header/footer computed padding using CSS variables */
  main.container { box-sizing: border-box; padding-top: calc(var(--header-height) + 6px); padding-bottom: calc(var(--footer-height) + 6px); }
    /* Slightly reduce card spacing to fit more cards when page is non-scrollable */
    .small-card { font-size: 0.95rem; }
    /* Results column: do not allow internal scrolling — rely on pagination to navigate */
    #results-col { -webkit-overflow-scrolling: touch; overflow: hidden !important; height: auto !important; }
    .placeholder-card { visibility: hidden; }
  </style>
  <style>
    /* Hover effect for product cards */
    .product-card { transition: transform .16s ease, box-shadow .16s ease; }
    .product-card:hover { transform: translateY(-6px) scale(1.012); box-shadow: 0 12px 28px rgba(0,0,0,0.08); }
  </style>
  <script>
    (function(){
      // Fix for header/footer overlap: pin header, measure header/footer and apply padding
      function adjustLayout(){
        try{
          var hdr = document.querySelector('header');
          var ftr = document.querySelector('footer');
          if (hdr){ hdr.style.position = 'fixed'; hdr.style.top = '0'; hdr.style.left = '0'; hdr.style.right = '0'; hdr.style.zIndex = 1030; }
          var h = hdr ? (hdr.getBoundingClientRect().height || 0) : 70;
          var f = ftr ? (ftr.getBoundingClientRect().height || 0) : 70;
          // prevent overall page scrolling (CSS enforces), update CSS variables for padding
          try { document.documentElement.style.overflow = 'hidden'; } catch(e){}
          try { document.body.classList.add('page-no-scroll'); } catch(e){}
          try { document.documentElement.style.setProperty('--header-height', h + 'px'); } catch(e){}
          try { document.documentElement.style.setProperty('--footer-height', f + 'px'); } catch(e){}
          // Neutralize any inline body padding set by header scripts so our CSS variables control spacing
          try { document.body.style.paddingTop = '0px'; document.body.style.paddingBottom = '0px'; } catch(e){}

          // Make the results column scrollable internally and fit available height
          var results = document.getElementById('results-col');
          // We intentionally do not set results height or overflow here; pagination controls how many items are shown.
          if (results) {
            // remove any previously set inline overflow/height to ensure CSS rule controls layout
            try { results.style.overflowY = 'hidden'; results.style.height = 'auto'; } catch(e){}
          }

        }catch(e){ console.error('adjustLayout', e); }
      }
      // Run after DOM ready so header/footer exist
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', adjustLayout);
      else adjustLayout();
      window.addEventListener('resize', adjustLayout);
      if (typeof ResizeObserver !== 'undefined'){
        try{ var ro = new ResizeObserver(adjustLayout); var hdr = document.querySelector('header'); var ftr = document.querySelector('footer'); if (hdr) ro.observe(hdr); if (ftr) ro.observe(ftr); }catch(e){}
      }
    })();
  </script>
</script>

<!-- Product view modal (simple, used by View buttons and card clicks) -->
<div class="modal fade" id="viewProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="view-title">Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-5" id="view-image-wrap"></div>
          <div class="col-md-7">
            <h4 id="view-name"></h4>
            <p id="view-desc"></p>
            <p><strong>Price:</strong> <span id="view-price"></span></p>
            <p><strong>Stock:</strong> <span id="view-stock"></span></p>
            <p><strong>Prescription:</strong> <span id="view-pres"></span></p>
            <div class="mt-3" id="view-actions"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Wire view-product-btn clicks to populate and show the modal
function showProductInModal(data){
  document.getElementById('view-title').textContent = data.name || 'Product';
  document.getElementById('view-name').textContent = data.name || '';
  document.getElementById('view-desc').textContent = data.desc || '';
  document.getElementById('view-price').textContent = '₱' + (data.price || '0.00');
  document.getElementById('view-stock').textContent = data.stock || '0';
  document.getElementById('view-pres').textContent = (data.pres && data.pres.toString() === '1') ? 'Yes' : 'No';
  var wrap = document.getElementById('view-image-wrap'); wrap.innerHTML = '';
  if (data.image) { var img = document.createElement('img'); img.src = '/Pharma_Sys/assets/image/' + data.image; img.style.maxWidth = '100%'; img.alt = data.name || ''; wrap.appendChild(img); }
  var actions = document.getElementById('view-actions'); actions.innerHTML = '';
  // If in stock, show Add to Cart or login prompt
  if ((parseInt(data.stock) || 0) > 0) {
    var addBtn = document.createElement('a');
    if (<?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>) {
      addBtn.href = '/Pharma_Sys/pages/cart.php?id=' + encodeURIComponent(data.id);
      addBtn.className = 'btn btn-success';
      addBtn.textContent = 'Add to Cart';
    } else {
      addBtn.href = '/Pharma_Sys/pages/login.php';
      addBtn.className = 'btn btn-success';
      addBtn.textContent = 'Login to add';
    }
    actions.appendChild(addBtn);
  }
  var modal = new bootstrap.Modal(document.getElementById('viewProductModal'));
  modal.show();
}

// delegate view button clicks
document.addEventListener('click', function(e){
  var btn = e.target.closest && e.target.closest('.view-product-btn');
  if (btn) {
    var d = {
      id: btn.getAttribute('data-id'),
      name: btn.getAttribute('data-name'),
      desc: btn.getAttribute('data-desc'),
      price: btn.getAttribute('data-price'),
      stock: btn.getAttribute('data-stock'),
      image: btn.getAttribute('data-image'),
      pres: btn.getAttribute('data-pres')
    };
    showProductInModal(d);
    return;
  }
  // product-card click: ignore interactive elements
  var card = e.target.closest && e.target.closest('.product-card');
  if (card && !e.target.closest('button, a, form, input, select, textarea')) {
    var d = {
      id: card.getAttribute('data-id'),
      name: card.getAttribute('data-name'),
      desc: card.getAttribute('data-desc'),
      price: card.getAttribute('data-price'),
      stock: card.getAttribute('data-stock'),
      image: card.getAttribute('data-image'),
      pres: card.getAttribute('data-pres')
    };
    showProductInModal(d);
  }
});
</script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
