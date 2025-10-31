<?php
session_start();
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: login.php');
    exit;
}

$db_path = __DIR__ . '/../func/db.php';
if (!file_exists($db_path)) die('Missing include: func/db.php');
include $db_path;

// Simple admin notification helper (DB-first, file fallback).
function add_admin_notification($admin_id, $admin_username, $message) {
    global $conn;
    $ts = date('Y-m-d H:i:s');
    // Try DB first (notifications: id, user_id, message, created_at, is_read)
    if (isset($conn) && $conn) {
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "iss", $admin_id, $message, $ts);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                return;
            }
        } catch (Exception $e) {
            // fall back to file
        }
    }
    // fallback file
    $file = __DIR__ . '/../data/notifications.log';
    $entry = json_encode(['admin_id'=>$admin_id,'admin_username'=>$admin_username,'action'=>$message,'product_id'=>null,'product_name'=>'','ts'=>$ts], JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Ensure optional columns exist (non-fatal)
try { $colRes = $conn->query("SHOW COLUMNS FROM product LIKE 'category'"); if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE product ADD COLUMN category VARCHAR(100) NULL AFTER Product_name"); } catch(Exception $e){}
try { $colRes = $conn->query("SHOW COLUMNS FROM product LIKE 'type'"); if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE product ADD COLUMN type VARCHAR(100) NULL AFTER category"); } catch(Exception $e){}
try { $colRes = $conn->query("SHOW COLUMNS FROM product LIKE 'prescription_needed'"); if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE product ADD COLUMN prescription_needed TINYINT(1) NOT NULL DEFAULT 0 AFTER `type`"); } catch(Exception $e){}

$message = '';
$error = '';

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $admin_id = (int)($_SESSION['user_id'] ?? 0);
    $admin_un = $_SESSION['username'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $pres = (($_POST['prescription'] ?? 'no') === 'yes') ? 1 : 0;
        $desc = trim($_POST['desc'] ?? '');
        $price = $_POST['price'] ?? 0;
        $stock = (int)($_POST['stock'] ?? 0);

        // Server-side validation
        if ($name === '') $error = 'Name is required.';
        if ($error === '' && mb_strlen($desc) > 300) $error = 'Description must be 300 characters or less.';
        if ($error === '' && $stock < 0) $error = 'Stock must be zero or positive.';
        if ($error === '' && $stock > 5000) $error = 'Stock cannot exceed 5000.';
        if ($error === '') {
            if (!is_numeric($price)) $error = 'Invalid price.';
        }

        // sanitize and format price to 2 decimals for display; store as provided (DB may truncate per schema)
        $price = (float)round((float)$price, 2);

        // image handling (optional)
        $image_name = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['image']['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (isset($allowed[$mime])) {
                $ext = $allowed[$mime];
                $base = pathinfo($_FILES['image']['name'], PATHINFO_FILENAME);
                $base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
                $image_name = time() . '_' . $base . '.' . $ext;
                $target_dir = __DIR__ . '/../assets/image/';
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $target = $target_dir . $image_name;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $image_name = null;
                }
            }
        }

        if ($error === '') {
            if ($action === 'create') {
                $stmt = mysqli_prepare($conn, "INSERT INTO product (Product_name, description, category, `type`, prescription_needed, image, price, stck_qty, order_date, exp_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE())");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "sssisdis", $name, $desc, $category, $type, $pres, $image_name, $price, $stock);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $message = 'Product added.';
                    add_admin_notification($admin_id, $admin_un, 'added product: ' . $name);
                } else {
                    $error = 'DB insert failed.';
                }
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                // Build update dynamically to avoid overwriting image when not uploaded
                $fields = 'Product_name=?, description=?, category=?, `type`=?, prescription_needed=?, price=?, stck_qty=?';
                $params = [];
                if ($image_name !== null) {
                    $fields .= ', image=?';
                    $stmt = mysqli_prepare($conn, "UPDATE product SET {$fields} WHERE Product_id=?");
                    if ($stmt) mysqli_stmt_bind_param($stmt, "sssisdsi", $name, $desc, $category, $type, $pres, $price, $stock, $image_name, $id);
                } else {
                    $stmt = mysqli_prepare($conn, "UPDATE product SET {$fields} WHERE Product_id=?");
                    if ($stmt) mysqli_stmt_bind_param($stmt, "sssisdis", $name, $desc, $category, $type, $pres, $price, $stock, $id);
                }
                if ($stmt) {
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $message = 'Product updated.';
                    add_admin_notification($admin_id, $admin_un, 'updated product: ' . $name);
                } else {
                    $error = 'DB update failed.';
                }
            }
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // optionally delete image file
            $stmt = mysqli_prepare($conn, "SELECT image, Product_name FROM product WHERE Product_id=? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $id);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $row = $res ? mysqli_fetch_assoc($res) : null;
                mysqli_stmt_close($stmt);
                if ($row && !empty($row['image'])) {
                    $fn = __DIR__ . '/../assets/image/' . $row['image'];
                    if (is_file($fn)) @unlink($fn);
                }
            }
            $stmt2 = mysqli_prepare($conn, "DELETE FROM product WHERE Product_id=?");
            if ($stmt2) {
                mysqli_stmt_bind_param($stmt2, "i", $id);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                $message = 'Product deleted.';
                add_admin_notification($admin_id, $admin_un, 'deleted product id: ' . $id);
            }
        }
    }

}

// Pagination and product list
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(12, min(48, (int)($_GET['per_page'] ?? 12)));
$offset = ($page - 1) * $perPage;
$total = 0;
$products = [];

// Filters (server-side)
$selectedCategory = trim((string)($_GET['category'] ?? ''));
$selectedType = trim((string)($_GET['type'] ?? ''));
$whereClauses = [];
if ($selectedCategory !== '') {
    $whereClauses[] = "category = '" . $conn->real_escape_string($selectedCategory) . "'";
}
if ($selectedType !== '') {
    $whereClauses[] = "`type` = '" . $conn->real_escape_string($selectedType) . "'";
}
$whereSql = '';
if (!empty($whereClauses)) $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

try {
    // count with filters
    $countSql = "SELECT COUNT(*) AS total FROM product " . $whereSql;
    $cres = $conn->query($countSql);
    if ($cres && ($crow = $cres->fetch_assoc())) $total = (int)$crow['total'];

    // fetch page with filters
    $selectSql = "SELECT Product_id, Product_name, description, category, `type`, prescription_needed, image, price, stck_qty FROM product " . $whereSql . " ORDER BY Product_name ASC LIMIT " . (int)$offset . ", " . (int)$perPage;
    $res = $conn->query($selectSql);
    if ($res) while ($r = $res->fetch_assoc()) $products[] = $r;
} catch (Exception $e) {
    // ignore
}

// pull distinct categories and types for filter toolbar
$categories = [];
$types = [];
try {
    $cq = $conn->query("SELECT DISTINCT COALESCE(category,'') AS category FROM product WHERE category IS NOT NULL AND category<>'' ORDER BY category ASC LIMIT 200");
    if ($cq) while ($row = $cq->fetch_assoc()) $categories[] = $row['category'];
    $tq = $conn->query("SELECT DISTINCT COALESCE(`type`,'') AS type FROM product WHERE `type` IS NOT NULL AND `type`<>'' ORDER BY `type` ASC LIMIT 200");
    if ($tq) while ($row = $tq->fetch_assoc()) $types[] = $row['type'];
} catch (Exception $e) {}

// load edit candidate if requested via GET
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $s = mysqli_prepare($conn, "SELECT * FROM product WHERE Product_id=? LIMIT 1");
    if ($s) {
        mysqli_stmt_bind_param($s, "i", $id);
        mysqli_stmt_execute($s);
        $res = mysqli_stmt_get_result($s);
        $edit = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($s);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Products Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/design.css" rel="stylesheet">
    <style>
    /* Make modals scrollable and not hidden behind header */
    .modal-fullscreen-sm-down .modal-dialog { max-width: 980px; margin: 1.75rem auto; }
    .modal .modal-body { max-height: calc(100vh - 220px); overflow-y: auto; }
    /* Ensure the view modal appears above the fixed header and has a compact, fixed size */
    :root { --site-header-height: 70px; }
    .modal { z-index: 1400 !important; }
    .modal-backdrop { z-index: 1350 !important; }
        /* Target the view modal specifically so Add modal can remain large.
             Use flex centering so the dialog appears vertically centered in viewport. */
            #viewProductModal .modal-dialog {
                max-width: 760px;
                margin: auto;
                display: flex;
                align-items: center; /* vertical center */
                justify-content: center;
                min-height: calc(100vh - var(--site-header-height) - 40px);
            }
            /* Ensure modal content is centered within dialog and respects header height */
            #viewProductModal .modal-content { margin: auto; max-height: calc(100vh - var(--site-header-height) - 80px); overflow: hidden; }
            #viewProductModal .modal-body { overflow-y: auto; max-height: calc(100vh - var(--site-header-height) - 160px); }
    </style>
    <style>
    /* Hover effect for clickable product cards */
    .product-card { transition: transform .16s ease, box-shadow .16s ease; }
    .product-card:hover { transform: translateY(-6px) scale(1.015); box-shadow: 0 12px 30px rgba(0,0,0,0.10); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../func/header.php'; ?>

<div id="productsMain" class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 style="font-size:1.1rem">Products</h3>
        <div>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addProductModal">Add</button>
        </div>
    </div>

    <!-- Filter toolbar -->
    <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
        <div>Filter:</div>
    <a href="products.php" class="btn btn-outline-success btn-sm <?= $selectedCategory === '' && $selectedType === '' ? 'active' : '' ?>">All</a>
        <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $cat): ?>
                <a href="products.php?category=<?= rawurlencode($cat) ?>" class="btn btn-outline-success btn-sm <?= ($selectedCategory === $cat) ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($types)): ?>
            <?php foreach ($types as $t): ?>
                <a href="products.php?type=<?= rawurlencode($t) ?>" class="btn btn-outline-success btn-sm <?= ($selectedType === $t) ? 'active' : '' ?>"><?= htmlspecialchars($t) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row g-3">
        <?php foreach ($products as $p): ?>
        <div class="col-6 col-sm-4 col-md-3">
            <div class="card h-100 product-card" 
                 data-id="<?= (int)$p['Product_id'] ?>" 
                 data-name="<?= htmlspecialchars($p['Product_name'], ENT_QUOTES) ?>" 
                 data-desc="<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>" 
                 data-price="<?= htmlspecialchars(number_format((float)$p['price'], 2), ENT_QUOTES) ?>" 
                 data-stock="<?= (int)$p['stck_qty'] ?>" 
                 data-image="<?= rawurlencode($p['image'] ?? '') ?>" 
                 data-pres="<?= isset($p['prescription_needed']) ? ((int)$p['prescription_needed']) : 0 ?>">
                <?php if (!empty($p['image']) && is_file(__DIR__ . '/../assets/image/' . $p['image'])): ?>
                    <img src="../assets/image/<?= rawurlencode($p['image']) ?>" class="card-img-top" style="height:100px;object-fit:cover;" alt="<?= htmlspecialchars($p['Product_name']) ?>">
                <?php else: ?>
                    <div style="height:100px;background:#f8f9fa;display:flex;align-items:center;justify-content:center;color:#6c757d;font-size:0.9rem">No image</div>
                <?php endif; ?>
                <div class="card-body d-flex flex-column" style="padding:.5rem">
                    <h6 class="card-title mb-1" style="font-size:0.95rem"><?= htmlspecialchars($p['Product_name']) ?></h6>
                    <p class="card-text text-truncate" style="flex:1 1 auto;font-size:0.85rem;"><?= htmlspecialchars($p['description']) ?></p>
                    <div class="d-flex gap-2 align-items-center mt-2">
                        <div class="fw-bold" style="font-size:0.95rem">₱<?= htmlspecialchars(number_format((float)$p['price'], 2)) ?></div>
                        <div class="text-muted small" style="font-size:0.8rem">Stock: <?= (int)$p['stck_qty'] ?></div>
                        <div class="ms-auto">
                            <button class="btn btn-sm btn-success view-product-btn" data-id="<?= (int)$p['Product_id'] ?>" data-name="<?= htmlspecialchars($p['Product_name'], ENT_QUOTES) ?>" data-desc="<?= htmlspecialchars($p['description'], ENT_QUOTES) ?>" data-price="<?= htmlspecialchars(number_format((float)$p['price'], 2), ENT_QUOTES) ?>" data-stock="<?= (int)$p['stck_qty'] ?>" data-image="<?= rawurlencode($p['image'] ?? '') ?>" data-pres="<?= isset($p['prescription_needed']) ? ((int)$p['prescription_needed']) : 0 ?>">View</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php
    $totalPages = max(1, (int)ceil($total / $perPage));
    $prev = $page > 1 ? $page - 1 : null;
    $next = $page < $totalPages ? $page + 1 : null;
    ?>
    <nav class="mt-4" aria-label="Products pagination">
        <ul class="pagination">
            <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $prev ?: 1 ?>">Previous</a></li>
            <?php for ($p=1;$p<=$totalPages;$p++): ?>
                <li class="page-item <?= $p=== $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $next ?: $totalPages ?>">Next</a></li>
        </ul>
    </nav>

</div>

<style>
/* Disable scrolling entirely on this page: neither body nor internal product list will scroll. */
html, body { height: 100%; overflow: hidden !important; }
/* Keep Bootstrap grid behavior and allow items to wrap naturally within the container. */
#productsMain { overflow: visible; }
#productsMain .row.g-3 { overflow: visible; max-height: none; }
</style>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title">Add Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Category</label><input name="category" class="form-control"></div>
          <div class="mb-2"><label class="form-label">Type</label><input name="type" class="form-control"></div>
          <div class="mb-2"><label class="form-label">Prescription Needed</label>
            <select name="prescription" class="form-select"><option value="no">No</option><option value="yes">Yes</option></select>
          </div>
          <div class="mb-2"><label class="form-label">Description <small class="text-muted">(<span id="add-desc-count">0</span>/300)</small></label>
            <textarea name="desc" id="add-desc" class="form-control" maxlength="300" rows="3"></textarea></div>
          <div class="mb-2"><label class="form-label">Price</label><input name="price" type="number" step="0.01" min="0" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Stock</label><input name="stock" type="number" min="0" max="5000" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Image</label><input name="image" type="file" accept="image/*" class="form-control"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View/Edit Modal (shared) -->
<div class="modal fade" id="viewProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-centered">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Char counter for add/edit description
function wireDescCounter(id, counterId){
  var ta = document.getElementById(id); if (!ta) return; var c = document.getElementById(counterId); if (!c) return;
  function update(){ c.textContent = ta.value.length; }
  ta.addEventListener('input', update); update();
}
wireDescCounter('add-desc','add-desc-count');

// View modal wiring
document.querySelectorAll('.view-product-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var id = btn.getAttribute('data-id');
    var name = btn.getAttribute('data-name');
    var desc = btn.getAttribute('data-desc');
    var price = btn.getAttribute('data-price');
    var stock = btn.getAttribute('data-stock');
    var image = btn.getAttribute('data-image');
    var pres = btn.getAttribute('data-pres') === '1' ? 'Yes' : 'No';
    document.getElementById('view-title').textContent = name;
    document.getElementById('view-name').textContent = name;
    document.getElementById('view-desc').textContent = desc;
    document.getElementById('view-price').textContent = '₱' + price;
    document.getElementById('view-stock').textContent = stock;
    document.getElementById('view-pres').textContent = pres;
    var w = document.getElementById('view-image-wrap'); w.innerHTML = '';
    if (image) {
      var img = document.createElement('img'); img.src = '../assets/image/' + image; img.style.maxWidth = '100%'; img.style.height = 'auto'; img.alt = name; w.appendChild(img);
    }
    var actions = document.getElementById('view-actions');
    actions.innerHTML = '';
    // Manage/Edit button
    var editBtn = document.createElement('a'); editBtn.href = '?edit=' + encodeURIComponent(id); editBtn.className = 'btn btn-sm btn-info me-2'; editBtn.textContent = 'Manage';
    actions.appendChild(editBtn);
    // Delete form
    var delForm = document.createElement('form'); delForm.method = 'POST'; delForm.style.display = 'inline';
    var inputAction = document.createElement('input'); inputAction.type='hidden'; inputAction.name='action'; inputAction.value='delete'; delForm.appendChild(inputAction);
    var inputId = document.createElement('input'); inputId.type='hidden'; inputId.name='id'; inputId.value=id; delForm.appendChild(inputId);
    var delBtn = document.createElement('button'); delBtn.type='submit'; delBtn.className='btn btn-sm btn-danger'; delBtn.textContent='Delete'; delBtn.onclick = function(){ if(!confirm('Delete this product?')) return false; };
    delForm.appendChild(delBtn);
    actions.appendChild(delForm);

    var modal = new bootstrap.Modal(document.getElementById('viewProductModal'));
    modal.show();
  });
});
// Make the whole product card clickable (opens the same view modal) but ignore clicks on
// controls (buttons/links/forms) so Manage/Delete remain usable.
document.querySelectorAll('.product-card').forEach(function(card){
    card.style.cursor = 'pointer';
    card.addEventListener('click', function(e){
        // ignore clicks that originate from interactive controls inside the card
        if (e.target.closest('button, a, form, input, select, textarea')) return;
        // find the internal view button and delegate to it (reuses existing wiring)
        var btn = card.querySelector('.view-product-btn');
        if (btn) {
            btn.click();
        } else {
            // fallback: build dataset and show modal directly
            try {
                var id = card.getAttribute('data-id');
                var name = card.getAttribute('data-name');
                var desc = card.getAttribute('data-desc');
                var price = card.getAttribute('data-price');
                var stock = card.getAttribute('data-stock');
                var image = card.getAttribute('data-image');
                var pres = card.getAttribute('data-pres') === '1' ? 'Yes' : 'No';
                document.getElementById('view-title').textContent = name;
                document.getElementById('view-name').textContent = name;
                document.getElementById('view-desc').textContent = desc;
                document.getElementById('view-price').textContent = '₱' + price;
                document.getElementById('view-stock').textContent = stock;
                document.getElementById('view-pres').textContent = pres;
                var w = document.getElementById('view-image-wrap'); w.innerHTML = '';
                if (image) {
                    var img = document.createElement('img'); img.src = '../assets/image/' + image; img.style.maxWidth = '100%'; img.style.height = 'auto'; img.alt = name; w.appendChild(img);
                }
                var actions = document.getElementById('view-actions'); actions.innerHTML = '';
                var editBtn = document.createElement('a'); editBtn.href = '?edit=' + encodeURIComponent(id); editBtn.className = 'btn btn-sm btn-info me-2'; editBtn.textContent = 'Manage';
                actions.appendChild(editBtn);
                var delForm = document.createElement('form'); delForm.method = 'POST'; delForm.style.display = 'inline';
                var inputAction = document.createElement('input'); inputAction.type='hidden'; inputAction.name='action'; inputAction.value='delete'; delForm.appendChild(inputAction);
                var inputId = document.createElement('input'); inputId.type='hidden'; inputId.name='id'; inputId.value=id; delForm.appendChild(inputId);
                var delBtn = document.createElement('button'); delBtn.type='submit'; delBtn.className='btn btn-sm btn-danger'; delBtn.textContent='Delete'; delBtn.onclick = function(){ if(!confirm('Delete this product?')) return false; };
                delForm.appendChild(delBtn);
                actions.appendChild(delForm);
                var modal = new bootstrap.Modal(document.getElementById('viewProductModal'));
                modal.show();
            } catch (err) { console.error('product-card click fallback error', err); }
        }
    });
});
</script>
    <script>
    // Ensure modals avoid being hidden behind a fixed header: measure header height and set CSS var.
    (function(){
        function setHeaderHeight(){
            try{
                var hdr = document.querySelector('header');
                var h = hdr ? Math.ceil(hdr.getBoundingClientRect().height) : 70;
                        document.documentElement.style.setProperty('--site-header-height', h + 'px');
                        // Ensure the page remains non-scrollable as requested (disable page scroll)
                        try {
                            document.body.style.overflow = 'hidden';
                        } catch(e) {}
            }catch(e){}
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setHeaderHeight); else setHeaderHeight();
        // watch for header size changes
        var hdrEl = document.querySelector('header');
        if (hdrEl && typeof ResizeObserver !== 'undefined'){
            try{ new ResizeObserver(setHeaderHeight).observe(hdrEl); }catch(e){}
        }
        // also update on window resize/orientation change
        window.addEventListener('resize', function(){ setHeaderHeight(); });
    })();
    </script>
</body>
</html>
