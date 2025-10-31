<?php
session_start();
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header('Location: login.php');
    exit;
}

$db_path = __DIR__ . '/../func/db.php';
if (!file_exists($db_path)) die('Missing include: func/db.php');
include $db_path;

$message = '';

// Notifications helper (mirror admin.php implementation so this file can record actions)
function add_admin_notification_local($admin_id, $admin_username, $action, $entity_id = null, $entity_name = '') {
  global $conn;
  $ts = date('Y-m-d H:i:s');
  $messageText = is_string($entity_name) && $entity_name !== '' ? ($action . ' "' . $entity_name . '"') : $action;

  if (isset($conn) && $conn) {
    try {
      $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, action, product_id, product_name, actor_username, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
      if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ississs", $admin_id, $messageText, $action, $entity_id, $entity_name, $admin_username, $ts);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return;
      }
    } catch (Exception $e) {
      // fall through to file fallback
    }
  }

  $file = __DIR__ . '/../data/notifications.log';
  $entry = json_encode([ 'admin_id' => $admin_id, 'admin_username' => $admin_username, 'action' => $action, 'product_id' => $entity_id, 'product_name' => $entity_name, 'ts' => $ts ], JSON_UNESCAPED_SLASHES);
  @file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Identify the designated superadmin account (prefer username 'superadmin', fallback to admin_id 1)
$super_admin_id = null;
try {
  $resS = $conn->query("SELECT admin_id FROM admin WHERE LOWER(username) = 'superadmin' LIMIT 1");
  if ($resS && $resS->num_rows > 0) {
    $rS = $resS->fetch_assoc();
    $super_admin_id = (int)($rS['admin_id'] ?? 0);
  } else {
    // fallback to admin_id 1 if exists
    $resF = $conn->query("SELECT admin_id FROM admin ORDER BY admin_id ASC LIMIT 1");
    if ($resF && $resF->num_rows > 0) {
      $rF = $resF->fetch_assoc();
      $super_admin_id = (int)($rF['admin_id'] ?? 0);
    }
  }
} catch (Exception $e) {
  $super_admin_id = (int)($_SESSION['user_id'] ?? 0);
}
// convenience flag
$current_admin_id = (int)($_SESSION['user_id'] ?? 0);
$is_super_logged_in = ($current_admin_id === $super_admin_id);

// Delete user (customer)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
  // fetch username for notification
  $custName = '';
  $s0 = mysqli_prepare($conn, "SELECT username FROM customer WHERE Customer_id=? LIMIT 1");
  if ($s0) {
    mysqli_stmt_bind_param($s0, "i", $id);
    mysqli_stmt_execute($s0);
    $r0 = mysqli_stmt_get_result($s0);
    if ($r0) { $rr0 = mysqli_fetch_assoc($r0); $custName = $rr0['username'] ?? ''; }
    mysqli_stmt_close($s0);
  }

  $stmt = mysqli_prepare($conn, "DELETE FROM customer WHERE Customer_id=?");
  mysqli_stmt_bind_param($stmt, "i", $id);
  if (mysqli_stmt_execute($stmt)) {
    $message = 'Customer deleted.';
    // record admin notification
    add_admin_notification_local((int)($_SESSION['user_id'] ?? 0), $_SESSION['username'] ?? '', 'deleted customer', $id, $custName);
  }
  mysqli_stmt_close($stmt);
  header('Location: user_management.php');
  exit;
}

// Delete admin (prevent deleting self)
if (isset($_GET['delete_admin'])) {
  $id = (int)$_GET['delete_admin'];
  // only allow the designated superadmin to delete admin accounts
  if (!$is_super_logged_in) {
    $message = 'Only the superadmin may delete administrator accounts.';
  } elseif ($id === $current_admin_id) {
    $message = 'You cannot delete your own admin account.';
  } else {
    // fetch username for notification
    $admName = '';
    $s0 = mysqli_prepare($conn, "SELECT username FROM admin WHERE admin_id=? LIMIT 1");
    if ($s0) {
        mysqli_stmt_bind_param($s0, "i", $id);
        mysqli_stmt_execute($s0);
        $r0 = mysqli_stmt_get_result($s0);
        if ($r0) { $rr0 = mysqli_fetch_assoc($r0); $admName = $rr0['username'] ?? ''; }
        mysqli_stmt_close($s0);
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM admin WHERE admin_id=?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
      $message = 'Admin deleted.';
      add_admin_notification_local((int)($_SESSION['user_id'] ?? 0), $_SESSION['username'] ?? '', 'deleted admin', $id, $admName);
    }
    mysqli_stmt_close($stmt);
  }
  header('Location: user_management.php');
  exit;
}

// Create admin via overlay form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_admin') {
  $username = trim($_POST['username'] ?? '');
  $password = trim($_POST['password'] ?? '');
  $password_confirm = trim($_POST['password_confirm'] ?? '');
  $minLen = 8; $maxLen = 30;
  if ($username === '' || $password === '') {
    $message = 'Please fill in all fields.';
  } elseif (strlen($password) < $minLen || strlen($password) > $maxLen) {
    $message = "Password must be between {$minLen} and {$maxLen} characters.";
  } elseif ($password !== $password_confirm) {
    $message = 'Passwords do not match.';
  } else {
    // ensure profile_image column exists
    try {
      $colRes = $conn->query("SHOW COLUMNS FROM admin LIKE 'profile_image'");
      if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE admin ADD COLUMN profile_image VARCHAR(255) NULL AFTER password");
      }
    } catch (Exception $e) {}

    // check existing username
    $chk = @$conn->prepare("SELECT admin_id FROM admin WHERE username = ? LIMIT 1");
    if ($chk) {
      $chk->bind_param('s', $username);
      $chk->execute();
      $res = $chk->get_result();
      if ($res && $res->num_rows > 0) {
        $message = 'Username already exists.';
      }
      $chk->close();
    }

    if ($message === '') {
      $profile_image = null;
      if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['profile_image']['tmp_name']);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        if (array_key_exists($mime, $allowed)) {
          $ext = $allowed[$mime];
          $base = pathinfo($_FILES['profile_image']['name'], PATHINFO_FILENAME);
          $base = preg_replace('/[^A-Za-z0-9_-]/', '_', $base);
          $profile_image = time() . '_' . $base . '.' . $ext;
          $target_dir = __DIR__ . '/../assets/profile/';
          if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
          $target_file = $target_dir . $profile_image;
          if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) $profile_image = null;
        }
      }

      $hash = password_hash($password, PASSWORD_BCRYPT);
      $stmt = mysqli_prepare($conn, "INSERT INTO admin (username, password, profile_image) VALUES (?, ?, ?)");
      mysqli_stmt_bind_param($stmt, "sss", $username, $hash, $profile_image);
      if (mysqli_stmt_execute($stmt)) {
        $message = 'Admin account created.';
        $new_id = mysqli_insert_id($conn);
        // notify
        add_admin_notification_local((int)($_SESSION['user_id'] ?? 0), $_SESSION['username'] ?? '', 'created admin', $new_id, $username);
      } else {
        $message = 'Failed to create admin account.';
      }
      mysqli_stmt_close($stmt);
    }
  }
}

// Read customers
$customers = [];
$res = $conn->query("SELECT * FROM customer");
if ($res) {
    while ($row = $res->fetch_assoc()) $customers[] = $row;
    $res->free();
}

// Read admins for Admins tab
$admins = [];
$resA = $conn->query("SELECT admin_id, username, profile_image FROM admin");
if ($resA) {
  while ($r = $resA->fetch_assoc()) $admins[] = $r;
  $resA->free();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>User Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design.css">
  <style>
    /* User/Admin card styling - unified sizes so both look identical */
    .um-card, .um-admin-card { border-radius: 14px; overflow: hidden; display:flex; flex-direction:column; align-items:center; padding:12px; min-height:240px; }
    .um-card .profile-wrap, .um-admin-card .profile-wrap { width:120px; height:120px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; margin-bottom:12px; }
    .um-card .profile-wrap img, .um-admin-card .profile-wrap img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
    @media (max-width:576px){ .um-card, .um-admin-card { min-height:220px; } .um-card .profile-wrap, .um-admin-card .profile-wrap { width:100px; height:100px; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../func/header.php'; ?>
  <div class="container my-3">
    <h2>User / Admin Management</h2>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-3" id="umTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Users</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="admins-tab" data-bs-toggle="tab" data-bs-target="#admins" type="button" role="tab">Admins</button>
      </li>
    </ul>

    <div class="tab-content" id="umTabsContent">
      <div class="tab-pane fade show active" id="users" role="tabpanel" aria-labelledby="users-tab">
        <?php if (empty($customers)): ?>
          <p>No customers found.</p>
        <?php else: ?>
          <div class="row">
            <?php foreach ($customers as $c):
                $id = $c['Customer_id'] ?? $c['customer_id'] ?? $c[array_key_first($c)];
                $fn = trim((string)($c['profile_image'] ?? ''));
                $img_url = '/Pharma_Sys/assets/image/default.jpg';
                if ($fn !== '') {
                    $disk = __DIR__ . '/../assets/profile/' . $fn;
                    $web = '/Pharma_Sys/assets/profile/' . rawurlencode($fn);
                    if (is_file($disk)) $img_url = $web;
                }
                $displayName = htmlspecialchars($c['username'] ?? $c['name'] ?? $c['email'] ?? 'User');
            ?>
            <div class="col-6 col-md-4 col-lg-3 mb-3">
              <div class="card text-center p-2 h-100 um-card">
                <div class="profile-wrap">
                  <img src="<?= htmlspecialchars($img_url) ?>" alt="Profile">
                </div>
                <div class="card-body p-2" style="flex:1; display:flex; flex-direction:column; justify-content:space-between; align-items:center;">
                  <div>
                    <h6 class="card-title mb-2"><?= $displayName ?></h6>
                  </div>
                  <div class="d-flex justify-content-center gap-2">
                    <a href="user_management.php?manage=<?= (int)$id ?>" class="btn btn-sm btn-primary">Manage</a>
                    <a href="user_management.php?delete=<?= (int)$id ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete user?')">Delete</a>
                  </div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="tab-pane fade" id="admins" role="tabpanel" aria-labelledby="admins-tab">
        <div class="row">
          <div class="col-md-8">
            <?php if (empty($admins)): ?>
              <p>No admins found.</p>
            <?php else: ?>
              <div class="row">
                <?php foreach ($admins as $a):
                    $aid = (int)$a['admin_id'];
                    $fn = trim((string)($a['profile_image'] ?? ''));
                    $img_url = '/Pharma_Sys/assets/image/default.jpg';
                    if ($fn !== '') {
                        $disk = __DIR__ . '/../assets/profile/' . $fn;
                        $web = '/Pharma_Sys/assets/profile/' . rawurlencode($fn);
                        if (is_file($disk)) $img_url = $web;
                    }
                ?>
                <div class="col-6 col-md-4 col-lg-3 mb-3">
                  <div class="card text-center p-2 um-admin-card">
                        <div class="profile-wrap">
                          <img src="<?= htmlspecialchars($img_url) ?>" alt="Admin">
                        </div>
                        <div class="p-2">
                          <strong><?= htmlspecialchars($a['username']) ?></strong>
                        </div>
                        <div class="p-2">
                          <?php if ($is_super_logged_in && $aid !== $current_admin_id): ?>
                            <a href="user_management.php?delete_admin=<?= $aid ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete admin?')">Delete</a>
                          <?php elseif ($aid === $current_admin_id): ?>
                            <span class="text-muted small">(You)</span>
                          <?php else: ?>
                            <!-- only superadmin may delete other admins -->
                          <?php endif; ?>
                        </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <div class="d-flex flex-column align-items-stretch gap-2">
              <button class="btn btn-success mb-2" data-bs-toggle="modal" data-bs-target="#addAdminModal">Add Admin</button>
              <div class="card p-2">
                <h6>Admins</h6>
                <p>Manage administrator accounts. Use Add Admin to create a new admin (overlay).</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Admin Modal (overlay) -->
    <div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Create Admin Account</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_admin">
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="d-flex align-items-center">
                  <input type="password" name="password" id="addadmin-password" class="form-control" required>
                  <span id="addadmin-pw-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                  <small id="addadmin-pw-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <div class="d-flex align-items-center">
                  <input type="password" name="password_confirm" id="addadmin-password-confirm" class="form-control" required>
                  <span id="addadmin-pwconf-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                  <small id="addadmin-pwconf-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Profile image (optional)</label>
                <input type="file" name="profile_image" class="form-control">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Create Admin</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function(){
      var pw = document.getElementById('addadmin-password');
      var pwc = document.getElementById('addadmin-password-confirm');
      var ind = document.getElementById('addadmin-pw-ind');
      var indc = document.getElementById('addadmin-pwconf-ind');
      var msg = document.getElementById('addadmin-pw-msg');
      var msgc = document.getElementById('addadmin-pwconf-msg');
      if (pw && pwc) {
        var min = 8, max = 30;
        function update(){
          var v = pw.value || '';
          var vc = pwc.value || '';
          var okLen = v.length >= min && v.length <= max;
          if (!v.length){ ind.style.background='#ccc'; indc.style.background='#ccc'; msg.textContent=''; msgc.textContent=''; return; }
          if (!okLen){ ind.style.background='red'; msg.textContent='Password length: '+min+'-'+max; } else { msg.textContent=''; }
          if (v.length && vc.length){
            if (v === vc && okLen){ ind.style.background='green'; indc.style.background='green'; msg.textContent='Good'; msgc.textContent='Matches'; }
            else { ind.style.background = okLen ? '#ffa500' : 'red'; indc.style.background='red'; msgc.textContent='Does not match'; }
          } else { if (okLen) ind.style.background='green'; else ind.style.background='red'; indc.style.background='#ccc'; msgc.textContent=''; }
        }
        pw.addEventListener('input', update);
        pwc.addEventListener('input', update);
        update();
      }
    })();
  </script>
  <script>
    (function(){
      function adjustBodyPadding(){
        var hdr = document.querySelector('header');
        if (!hdr) return;
        try { hdr.style.position = 'fixed'; hdr.style.top = '0px'; hdr.style.left = '0px'; } catch(e){}
        var rect = hdr.getBoundingClientRect();
        var h = rect.height || 0;
        try { document.documentElement.style.overflowY = 'auto'; document.body.style.overflowY = 'auto'; } catch(e){}
        document.body.style.paddingTop = h + 'px';
      }
      adjustBodyPadding();
      window.addEventListener('resize', adjustBodyPadding);
      var hdr = document.querySelector('header');
      if (hdr && typeof ResizeObserver !== 'undefined'){
        try { var ro = new ResizeObserver(adjustBodyPadding); ro.observe(hdr);} catch(e){}
      }
    })();
  </script>
</body>
</html>
