<?php
session_start();
include __DIR__ . '/../func/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Ensure customer.profile_image column exists (safe ALTER)
try {
    $colRes = $conn->query("SHOW COLUMNS FROM customer LIKE 'profile_image'");
    if (!$colRes || $colRes->num_rows === 0) {
        // attempt to add column; ignore failure
        $conn->query("ALTER TABLE customer ADD COLUMN profile_image VARCHAR(255) NULL AFTER password");
    }
} catch (Exception $e) {
    // ignore errors here
}

$message = '';
$error = '';

// Load user
$stmt = $conn->prepare("SELECT Customer_id, username, registered_date, COALESCE(profile_image, '') AS profile_image FROM customer WHERE Customer_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows !== 1) {
  echo "User not found.";
  exit();
}
$user = $result->fetch_assoc();
$stmt->close();

// Determine active tab
$tab = $_GET['tab'] ?? $_POST['tab'] ?? 'profile';

// Handle POST actions (save_profile, remove_profile_image)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'remove_profile_image') {
        if (!empty($user['profile_image'])) {
            $file = __DIR__ . '/../assets/profile/' . $user['profile_image'];
            if (is_file($file)) @unlink($file);
            $stmt = $conn->prepare("UPDATE customer SET profile_image=NULL WHERE Customer_id=?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
            $user['profile_image'] = '';
            $message = 'Profile picture removed.';
        }
    }

  if (($_POST['action'] ?? '') === 'save_profile') {
    $new_username = trim($_POST['username'] ?? '');
    $new_password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['password_confirm'] ?? '');

    // Username update
    if ($new_username !== '' && $new_username !== $user['username']) {
      $stmt = $conn->prepare("UPDATE customer SET username=? WHERE Customer_id=?");
      $stmt->bind_param('si', $new_username, $user_id);
      $stmt->execute();
      $stmt->close();
      $_SESSION['username'] = $new_username;
      $user['username'] = $new_username;
      $message = 'Profile updated.';
    }

    // Password update (if provided)
    if ($new_password !== '') {
      $minLen = 8; $maxLen = 30;
      if (strlen($new_password) < $minLen || strlen($new_password) > $maxLen) {
        $error = "Password must be between {$minLen} and {$maxLen} characters.";
      } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
      } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE customer SET password=? WHERE Customer_id=?");
        $stmt->bind_param('si', $hash, $user_id);
        $stmt->execute();
        $stmt->close();
        $message = 'Profile updated.';
      }
    }

        // Profile image upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['profile_image']['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (array_key_exists($mime, $allowed)) {
                $ext = $allowed[$mime];
                $base = pathinfo($_FILES['profile_image']['name'], PATHINFO_FILENAME);
                $base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
                $image_name = time() . '_' . $base . '.' . $ext;
                $target_dir = __DIR__ . '/../assets/profile/';
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $target_file = $target_dir . $image_name;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                    // update db
                    $stmt = $conn->prepare("UPDATE customer SET profile_image=? WHERE Customer_id=?");
                    $stmt->bind_param('si', $image_name, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    $user['profile_image'] = $image_name;
                    $message = 'Profile updated.';
                }
            }
        }
    }
}

// If tab is orders or prescriptions, try to fetch rows (if table exists)
$orders = [];
$prescriptions = [];
if ($tab === 'orders') {
  try {
    // The orders table uses `user_id` to reference the customer. Use a prepared statement
    // to safely fetch the recent orders for the currently logged-in user.
    $stmtO = $conn->prepare("SELECT order_id, user_id AS customer_id, total_amount, COALESCE(status,'pending') AS status, payment_method, address, order_date FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 100");
    if ($stmtO) {
      $stmtO->bind_param('i', $user_id);
      $stmtO->execute();
      $resO = $stmtO->get_result();
      if ($resO) {
        while ($r = $resO->fetch_assoc()) $orders[] = $r;
        $resO->free();
      }
      $stmtO->close();
    }
  } catch (Exception $e) {}
}
// prescriptions tab removed
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Profile - PharmaSys</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>

  <?php include __DIR__ . '/../func/header.php'; ?>

  <div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2>Profile</h2>
    </div>

  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-3">
      <li class="nav-item"><a class="nav-link <?= $tab === 'profile' ? 'active' : '' ?>" href="?tab=profile">Profile</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab === 'orders' ? 'active' : '' ?>" href="?tab=orders">Orders</a></li>
    </ul>

    <?php if ($tab === 'profile'): ?>
      <div class="row">
        <div class="col-md-4">
          <div class="card">
            <div class="card-body text-center">
              <?php if (!empty($user['profile_image']) && is_file(__DIR__ . '/../assets/profile/' . $user['profile_image'])): ?>
                <img src="../assets/profile/<?= rawurlencode($user['profile_image']) ?>" alt="Profile" class="img-fluid mb-2" style="width:150px;height:150px;object-fit:cover;border-radius:50%;">
              <?php else: ?>
                <img src="../assets/image/default.jpg" alt="Profile" class="img-fluid mb-2" style="width:150px;height:150px;object-fit:cover;border-radius:50%;">
              <?php endif; ?>
              <h4><?= htmlspecialchars($user['username']) ?></h4>
              <p class="text-muted">Registered: <?= htmlspecialchars(date('F j, Y', strtotime($user['registered_date']))) ?></p>
            </div>
          </div>
        </div>

        <div class="col-md-8">
          <div class="card">
            <div class="card-body">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_profile">
                <div class="mb-3">
                  <label class="form-label">Username</label>
                  <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>">
                </div>
                <div class="mb-3">
                  <label class="form-label">Change password</label>
                    <div class="d-flex align-items-center">
                      <input type="password" name="password" id="profile-password" class="form-control" placeholder="Leave blank to keep current" minlength="8" maxlength="30">
                      <span id="profile-pw-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                      <small id="profile-pw-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                    </div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Confirm new password</label>
                    <div class="d-flex align-items-center">
                      <input type="password" name="password_confirm" id="profile-password-confirm" class="form-control" placeholder="Repeat new password" minlength="8" maxlength="30">
                      <span id="profile-pwconf-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                      <small id="profile-pwconf-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                    </div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Profile picture</label>
                  <input type="file" name="profile_image" class="form-control">
                </div>
                  <script>
                    (function(){
                      var pw = document.getElementById('profile-password');
                      var pwc = document.getElementById('profile-password-confirm');
                      var ind = document.getElementById('profile-pw-ind');
                      var indc = document.getElementById('profile-pwconf-ind');
                      var msg = document.getElementById('profile-pw-msg');
                      var msgc = document.getElementById('profile-pwconf-msg');
                      var saveBtn = document.getElementById('profile-save-btn');
                      if (!pw || !pwc) return;
                      var min = 8, max = 30;
                      function setInvalid(reason) {
                        if (ind) ind.style.background = 'red';
                        if (indc) indc.style.background = 'red';
                        if (msg) msg.textContent = reason;
                        if (msgc) msgc.textContent = '';
                        if (saveBtn) saveBtn.disabled = true;
                      }
                      function setWarning(reason) {
                        if (ind) ind.style.background = '#ffa500';
                        if (indc) indc.style.background = 'red';
                        if (msg) msg.textContent = reason;
                        if (msgc) msgc.textContent = 'Does not match';
                        if (saveBtn) saveBtn.disabled = true;
                      }
                      function setOk() {
                        if (ind) ind.style.background = 'green';
                        if (indc) indc.style.background = 'green';
                        if (msg) msg.textContent = 'Good';
                        if (msgc) msgc.textContent = 'Matches';
                        if (saveBtn) saveBtn.disabled = false;
                      }
                      function clearState(){
                        if (ind) ind.style.background = '#ccc';
                        if (indc) indc.style.background = '#ccc';
                        if (msg) msg.textContent = '';
                        if (msgc) msgc.textContent = '';
                        if (saveBtn) saveBtn.disabled = false;
                      }
                      function update(){
                        var v = pw.value || '';
                        var vc = pwc.value || '';
                        if (!v.length && !vc.length) { clearState(); return; }
                        if (v.length < min || v.length > max) { setInvalid('Password must be between '+min+' and '+max+' characters.'); return; }
                        if (vc.length && v !== vc) { setWarning('Passwords do not match.'); return; }
                        // ok
                        setOk();
                      }
                      pw.addEventListener('input', update);
                      pwc.addEventListener('input', update);
                      update();
                    })();
                  </script>
                <?php if (!empty($user['profile_image'])): ?>
                  <div class="mb-3">
                    <button type="button" id="removeProfileImageBtn" class="btn btn-danger">Remove profile picture</button>
                    <script>
                      (function(){
                        var btn = document.getElementById('removeProfileImageBtn');
                        if (!btn) return;
                        btn.addEventListener('click', function(){
                          if (!confirm('Remove profile picture?')) return;
                          var f = document.createElement('form');
                          f.method = 'POST'; f.style.display='none';
                          var a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='remove_profile_image'; f.appendChild(a);
                          document.body.appendChild(f); f.submit();
                        });
                      })();
                    </script>
                  </div>
                <?php endif; ?>
                <button type="submit" id="profile-save-btn" class="btn btn-primary">Save changes</button>
              </form>
            </div>
          </div>
        </div>
      </div>

  <?php elseif ($tab === 'orders'): ?>
      <h4>Orders</h4>
      <?php if (empty($orders)): ?>
        <div class="alert alert-info">You have no orders yet.</div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($orders as $o):
            $oid = (int)$o['order_id'];
            $status = strtolower((string)($o['status'] ?? 'pending'));
            $badge = 'secondary';
            if ($status === 'processing') $badge = 'warning';
            if ($status === 'shipped') $badge = 'info';
            if ($status === 'delivered') $badge = 'success';
            if ($status === 'received') $badge = 'success';
            if ($status === 'cancelled' || $status === 'canceled') $badge = 'danger';
          ?>
            <div class="col-12">
              <div class="card">
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                  <div style="min-width:220px;">
                    <div class="fw-semibold">Order #<?= $oid ?> <small class="text-muted">placed <?= htmlspecialchars(date('M j, Y H:i', strtotime($o['order_date']))) ?></small></div>
                    <div class="small text-muted">Payment: <?= htmlspecialchars($o['payment_method'] ?? '') ?></div>
                    <div class="small text-truncate" style="max-width:420px;">Shipping: <?= htmlspecialchars($o['address'] ?? '') ?></div>
                  </div>
                  <div class="d-flex align-items-center gap-3">
                    <div class="text-end">
                      <div class="fw-bold">₱<?= number_format((float)($o['total_amount'] ?? 0), 2) ?></div>
                      <div class="small text-muted">Items: <?php /* optional: could sum quantities */ echo '-'; ?></div>
                    </div>
                    <div>
                      <span class="badge bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                    </div>
                    <div class="d-flex flex-column gap-2">
                      <button class="btn btn-outline-primary btn-sm" data-order-id="<?= $oid ?>" onclick="openOrderModal(this)">View details</button>
                      <?php if ($status === 'pending'): ?>
                        <form method="post" action="/Pharma_Sys/pages/user_order_action.php" onsubmit="return confirm('Cancel order #<?= $oid ?>?');" class="m-0">
                          <input type="hidden" name="order_id" value="<?= $oid ?>">
                          <input type="hidden" name="action" value="cancel">
                          <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($status === 'delivered'): ?>
                        <form method="post" action="/Pharma_Sys/pages/user_order_action.php" onsubmit="return confirm('Mark order #<?= $oid ?> as received?');" class="m-0">
                          <input type="hidden" name="order_id" value="<?= $oid ?>">
                          <input type="hidden" name="action" value="received">
                          <button type="submit" class="btn btn-success btn-sm">Received</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Order details modal -->
        <div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Order <span id="orderModalTitle">#</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div id="orderModalBody">Loading…</div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              </div>
            </div>
          </div>
        </div>

        <script>
          function openOrderModal(btn){
            var id = btn.getAttribute('data-order-id');
            if (!id) return;
            var modalEl = document.getElementById('orderModal');
            var title = document.getElementById('orderModalTitle');
            var body = document.getElementById('orderModalBody');
            title.textContent = '#' + id;
            body.innerHTML = '<div class="text-center py-3">Loading…</div>';
            var modal = new bootstrap.Modal(modalEl, {});
            modal.show();
            fetch('/Pharma_Sys/pages/user_order_items.php?order_id=' + encodeURIComponent(id))
              .then(r => r.json())
              .then(function(j){
                if (j && j.error){ body.innerHTML = '<div class="alert alert-danger">'+ (j.error === 'forbidden' ? 'You do not have access to this order.' : 'Error loading order') +'</div>'; return; }
                if (!Array.isArray(j) || j.length === 0) { body.innerHTML = '<div class="small text-muted">No items found for this order.</div>'; return; }
                var html = '<div class="list-group">';
                j.forEach(function(it){
                  var name = it.product_name || ('Product #' + (it.product_id||''));
                  var qty = parseInt(it.quantity || 0);
                  var price = parseFloat(it.price || 0).toFixed(2);
                  html += '<div class="list-group-item d-flex align-items-center justify-content-between">'
                    + '<div><div class="fw-semibold">'+escapeHtml(name)+'</div><div class="small text-muted">Qty: '+qty+'</div></div>'
                    + '<div class="fw-semibold">₱'+price+'</div>'
                    + '</div>';
                });
                html += '</div>';
                body.innerHTML = html;
              }).catch(function(e){ console.error(e); body.innerHTML = '<div class="alert alert-danger">Error loading order items</div>'; });
          }
          function escapeHtml(s){ return String(s||'').replace(/[&"'<>]/g, function(c){ return {'&':'&amp;','"':'&quot;','\'':'&#39;','<':'&lt;','>':'&gt;'}[c]; }); }
        </script>
      <?php endif; ?>

    <?php endif; ?>
  </div>

  <?php include __DIR__ . '/../func/footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>