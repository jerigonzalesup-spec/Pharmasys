<?php
session_start();
include __DIR__ . '/../func/db.php';

// Must be admin
if (!isset($_SESSION['user_id']) || strtolower((string)($_SESSION['role'] ?? '')) !== 'admin') {
    header("Location: ../pages/login.php");
    exit();
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);

// Ensure admin.profile_image column exists (safe ALTER)
try {
    $colRes = $conn->query("SHOW COLUMNS FROM admin LIKE 'profile_image'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE admin ADD COLUMN profile_image VARCHAR(255) NULL AFTER password");
    }
} catch (Exception $e) {}

$message = '';
$error = '';

// Load admin
$stmt = $conn->prepare("SELECT admin_id, username, COALESCE(profile_image,'') AS profile_image FROM admin WHERE admin_id = ? LIMIT 1");
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows !== 1) {
    echo "Admin not found.";
    exit();
}
$admin = $result->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'remove_profile_image') {
        if (!empty($admin['profile_image'])) {
            $file = __DIR__ . '/../assets/profile/' . $admin['profile_image'];
            if (is_file($file)) @unlink($file);
            $stmt = $conn->prepare("UPDATE admin SET profile_image=NULL WHERE admin_id=?");
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $stmt->close();
            $admin['profile_image'] = '';
            $message = 'Profile picture removed.';
        }
    }

    if (($_POST['action'] ?? '') === 'save_profile') {
        $new_username = trim($_POST['username'] ?? '');
        $new_password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['password_confirm'] ?? '');

        // Username update
        if ($new_username !== '' && $new_username !== $admin['username']) {
            $stmt = $conn->prepare("UPDATE admin SET username=? WHERE admin_id=?");
            $stmt->bind_param('si', $new_username, $admin_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['username'] = $new_username;
            $admin['username'] = $new_username;
            $message = 'Profile updated.';
        }

        // Password update
        if ($new_password !== '') {
            $minLen = 8; $maxLen = 30;
            if (strlen($new_password) < $minLen || strlen($new_password) > $maxLen) {
                $error = "Password must be between {$minLen} and {$maxLen} characters.";
            } elseif ($new_password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admin SET password=? WHERE admin_id=?");
                $stmt->bind_param('si', $hash, $admin_id);
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
                    $stmt = $conn->prepare("UPDATE admin SET profile_image=? WHERE admin_id=?");
                    $stmt->bind_param('si', $image_name, $admin_id);
                    $stmt->execute();
                    $stmt->close();
                    $admin['profile_image'] = $image_name;
                    $message = 'Profile updated.';
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Profile - PharmaSys</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>

<?php include __DIR__ . '/../func/header.php'; ?>

<div class="container my-4">
  <div class="mb-3">
    <h2>Admin Profile</h2>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="row">
    <div class="col-md-4">
      <div class="card">
        <div class="card-body text-center">
          <?php if (!empty($admin['profile_image']) && is_file(__DIR__ . '/../assets/profile/' . $admin['profile_image'])): ?>
            <img src="../assets/profile/<?= rawurlencode($admin['profile_image']) ?>" alt="Profile" class="img-fluid mb-2" style="width:150px;height:150px;object-fit:cover;border-radius:50%;">
          <?php else: ?>
            <img src="../assets/image/default.jpg" alt="Profile" class="img-fluid mb-2" style="width:150px;height:150px;object-fit:cover;border-radius:50%;">
          <?php endif; ?>
          <h4><?= htmlspecialchars($admin['username']) ?></h4>
          <p class="text-muted">Administrator account</p>
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
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($admin['username']) ?>">
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

            <?php if (!empty($admin['profile_image'])): ?>
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
</div>

<?php include __DIR__ . '/../func/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
