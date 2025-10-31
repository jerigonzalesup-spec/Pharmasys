<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Determine which admin section to show (home, profile, products, users, admins)
// Default to 'home' so the admin landing is an overview placeholder (to be filled later).
$section = $_GET['section'] ?? $_POST['section'] ?? 'home';

// admin display name (fallback to username from session)
$admin_display = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// time-based greeting (Good morning / afternoon / evening)
$hour = (int)date('G'); // 0-23
if ($hour < 12) {
    $time_greeting = 'Good morning';
} elseif ($hour < 18) {
    $time_greeting = 'Good afternoon';
} else {
    $time_greeting = 'Good evening';
}
$db_path = __DIR__ . '/../func/db.php';
if (!file_exists($db_path)) {
    die('Missing required include: func/db.php');
}
$contents = @file_get_contents($db_path);
if ($contents === false) {
    die('Unable to read include: func/db.php');
}
$trimmed = ltrim($contents);
// Accept files that contain a PHP open tag after possible BOM/whitespace.
if (stripos($trimmed, '<?php') === false) {
    // safety fix for invalid php files
    die('Invalid include detected: func/db.php does not contain "<?php". Please ensure it is a valid PHP file.');
}
include $db_path;

// Notifications storage (file-based fallback) -------------------------------------------------
// We store one JSON object per line to avoid needing DB migrations. Each entry:
// { admin_id, admin_username, action, product_id, product_name, ts }
function add_admin_notification($admin_id, $admin_username, $action, $product_id = null, $product_name = '') {
    // Writes a notification to DB if available, otherwise to a line-delimited JSON logfile.
    global $conn;
    $ts = date('Y-m-d H:i:s');
    $messageText = is_string($product_name) && $product_name !== '' ? ($action . ' "' . $product_name . '"') : $action;

    if (isset($conn) && $conn) {
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, action, product_id, product_name, actor_username, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ississs", $admin_id, $messageText, $action, $product_id, $product_name, $admin_username, $ts);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                return;
            }
        } catch (Exception $e) {
            // fall through to file-based logging
        }
    }

    // fallback: append JSON line to data/notifications.log
    $file = __DIR__ . '/../data/notifications.log';
    $entry = json_encode([ 'admin_id' => $admin_id, 'admin_username' => $admin_username, 'action' => $action, 'product_id' => $product_id, 'product_name' => $product_name, 'ts' => $ts ], JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}


function read_admin_notifications($limit = 50) {
    $out = [];
    global $conn;
    if (isset($conn) && $conn) {
        try {
            $stmt = mysqli_prepare($conn, "SELECT n.id, n.user_id, n.message, n.action, n.product_id, n.product_name, COALESCE(n.actor_username, a.username) AS actor_username, a.profile_image AS actor_profile, n.created_at FROM notifications n LEFT JOIN admin a ON n.user_id = a.admin_id ORDER BY n.created_at DESC LIMIT ?");
            mysqli_stmt_bind_param($stmt, "i", $limit);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
            }
            mysqli_stmt_close($stmt);
            return $out;
        } catch (Exception $e) {
            // fall through to file-based read
        }
    }

    $file = __DIR__ . '/../data/notifications.log';
    if (is_file($file)) {
        $lines = array_reverse(array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))));
        foreach (array_slice($lines, 0, $limit) as $line) {
            $j = json_decode($line, true);
            if (!$j) continue;
            $actor_profile = null;
            // attempt to resolve profile image from admin table if DB available
            if (isset($conn) && $conn) {
                try {
                    if (!empty($j['admin_id'])) {
                        $aid = (int)$j['admin_id'];
                        $s = mysqli_prepare($conn, "SELECT profile_image FROM admin WHERE admin_id = ? LIMIT 1");
                        if ($s) {
                            mysqli_stmt_bind_param($s, "i", $aid);
                            mysqli_stmt_execute($s);
                            $r = mysqli_stmt_get_result($s);
                            if ($r) { $rr = mysqli_fetch_assoc($r); if (!empty($rr['profile_image'])) $actor_profile = $rr['profile_image']; }
                            mysqli_stmt_close($s);
                        }
                    }
                    if ($actor_profile === null && !empty($j['admin_username'])) {
                        $uname = $j['admin_username'];
                        $s2 = mysqli_prepare($conn, "SELECT profile_image FROM admin WHERE username = ? LIMIT 1");
                        if ($s2) {
                            mysqli_stmt_bind_param($s2, "s", $uname);
                            mysqli_stmt_execute($s2);
                            $r2 = mysqli_stmt_get_result($s2);
                            if ($r2) { $rr2 = mysqli_fetch_assoc($r2); if (!empty($rr2['profile_image'])) $actor_profile = $rr2['profile_image']; }
                            mysqli_stmt_close($s2);
                        }
                    }
                } catch (Exception $e) {
                    // ignore and leave actor_profile null
                }
            }

            $out[] = [
                'id' => null,
                'user_id' => $j['admin_id'] ?? null,
                'message' => $j['action'] ?? '',
                'action' => $j['action'] ?? '',
                'product_id' => $j['product_id'] ?? null,
                'product_name' => $j['product_name'] ?? '',
                'actor_username' => $j['admin_username'] ?? '',
                'actor_profile' => $actor_profile,
                'created_at' => $j['ts'] ?? null,
            ];
        }
    }
    return $out;
}

// Migration helper removed; legacy file log import is not performed automatically here.


// Ensure `category` column exists in `product` table (non-fatal)
try {
    $colResP = $conn->query("SHOW COLUMNS FROM product LIKE 'category'");
    if (!$colResP || $colResP->num_rows === 0) {
        $conn->query("ALTER TABLE product ADD COLUMN category VARCHAR(100) NULL AFTER Product_name");
    }
} catch (Exception $e) {}

// Ensure `type` column exists in `product` table (non-fatal)
try {
    $colResT = $conn->query("SHOW COLUMNS FROM product LIKE 'type'");
    if (!$colResT || $colResT->num_rows === 0) {
        $conn->query("ALTER TABLE product ADD COLUMN type VARCHAR(100) NULL AFTER category");
    }
} catch (Exception $e) {}
// Ensure `prescription_needed` column exists in `product` table (non-fatal)
try {
    $colResP = $conn->query("SHOW COLUMNS FROM product LIKE 'prescription_needed'");
    if (!$colResP || $colResP->num_rows === 0) {
        $conn->query("ALTER TABLE product ADD COLUMN prescription_needed TINYINT(1) NOT NULL DEFAULT 0 AFTER `type`");
    }
} catch (Exception $e) {}

$message = '';
$error = '';

// Load current admin record (used for profile picture + profile form)
$admin = null;
if (isset($_SESSION['user_id'])) {
    $aid = (int)$_SESSION['user_id'];
    // ensure profile_image column exists, add if missing
    $colRes = $conn->query("SHOW COLUMNS FROM admin LIKE 'profile_image'");
    if (!$colRes || $colRes->num_rows === 0) {
        try {
            $conn->query("ALTER TABLE admin ADD COLUMN profile_image VARCHAR(255) NULL AFTER password");
        } catch (Exception $e) {
            // ignore alter errors
        }
    }

    $stmtA = mysqli_prepare($conn, "SELECT * FROM admin WHERE admin_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtA, "i", $aid);
    mysqli_stmt_execute($stmtA);
    $resA = mysqli_stmt_get_result($stmtA);
    if ($resA) $admin = mysqli_fetch_assoc($resA);
    mysqli_stmt_close($stmtA);
}

// Redirect product section to canonical products management page
if ($section === 'products') {
    // send user to the dedicated products management page
    header('Location: /Pharma_Sys/pages/products.php');
    exit;
}

// Redirect notifications section to dedicated notifications page
if ($section === 'notifications') {
    header('Location: /Pharma_Sys/pages/notification.php');
    exit;
}

// --- Profile handlers (only when section=profile) ---
if ($section === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'remove_profile_image') {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid && !empty($admin['profile_image'])) {
            $file = __DIR__ . '/../assets/profile/' . $admin['profile_image'];
            if (is_file($file)) @unlink($file);
            $stmt = mysqli_prepare($conn, "UPDATE admin SET profile_image=NULL WHERE admin_id=?");
            mysqli_stmt_bind_param($stmt, "i", $uid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $admin['profile_image'] = null;
            $message = 'Profile picture removed.';
        }
    }

    if (($_POST['action'] ?? '') === 'save_profile') {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid) {
            $new_username = trim($_POST['username'] ?? '');
            $new_password = trim($_POST['password'] ?? '');
            $confirm_password = trim($_POST['password_confirm'] ?? '');

            if ($new_username !== '' && $new_username !== ($admin['username'] ?? '')) {
                $stmt = mysqli_prepare($conn, "UPDATE admin SET username=? WHERE admin_id=?");
                mysqli_stmt_bind_param($stmt, "si", $new_username, $uid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['username'] = $new_username;
                $admin['username'] = $new_username;
                $admin_display = htmlspecialchars($_SESSION['username'] ?? 'Admin');
                $message = 'Profile updated.';
                // record notification for username change
                add_admin_notification($uid, $_SESSION['username'] ?? '', 'updated profile (username changed)', null, $new_username);
            }

            if ($new_password !== '') {
                $minLen = 8; $maxLen = 30;
                if (strlen($new_password) < $minLen || strlen($new_password) > $maxLen) {
                    $error = "Password must be between {$minLen} and {$maxLen} characters.";
                } elseif ($new_password !== $confirm_password) {
                    $error = 'Passwords do not match.';
                } else {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, "UPDATE admin SET password=? WHERE admin_id=?");
                    mysqli_stmt_bind_param($stmt, "si", $hash, $uid);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $message = 'Profile updated.';
                    // record notification for password change
                    add_admin_notification($uid, $_SESSION['username'] ?? '', 'changed password', null, $_SESSION['username'] ?? '');
                }
            }

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
                        try {
                            $stmt = mysqli_prepare($conn, "UPDATE admin SET profile_image=? WHERE admin_id=?");
                            mysqli_stmt_bind_param($stmt, "si", $image_name, $uid);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                            $admin['profile_image'] = $image_name;
                            $message = 'Profile updated.';
                                    // record notification for profile image update
                                    add_admin_notification($uid, $_SESSION['username'] ?? '', 'updated profile image', null, $_SESSION['username'] ?? '');
                        } catch (Exception $e) {}
                    }
                }
            }
        }
    }
}

// Read all products
$products = [];
$res = $conn->query("SELECT Product_id, Product_name, description, category, type, prescription_needed, image, price, stck_qty FROM product");
if ($res) {
    while ($row = $res->fetch_assoc()) $products[] = $row;
    $res->free();
}

// Read for edit
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM product WHERE Product_id=?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
.alert-fixed { position: fixed; top: 80px; left: 50%; transform: translateX(-50%); z-index:1050; max-width:400px; border-radius:8px; animation: fadeOut 0.5s ease-in-out 3s forwards; }
@keyframes fadeOut { to { opacity:0; visibility:hidden; } }
@keyframes fadeIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.notification-item { animation: fadeIn 300ms ease-out; }
.notification-toast { max-width:360px; }
.recent-notifications { max-height: calc(100vh - 220px); overflow: hidden; padding: 8px; }
.recent-notifications .list-group-item { border-radius: 12px; margin-bottom: 10px; border: none; background: #fff; box-shadow: 0 6px 18px rgba(20,20,30,0.06); }
.recent-notifications .list-group-item .small.text-muted { color: #6c757d; }
.recent-notifications::-webkit-scrollbar { height:8px; width:8px; }
.recent-notifications::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.12); border-radius:8px; }
/* Make admin sidebar buttons larger, rounded and more modern */
.admin-sidebar { padding: 6px; /* keep sidebar from scrolling; limit height to viewport minus header area */ max-height: calc(100vh - 140px); overflow: hidden; box-sizing: border-box; overflow-x: hidden; width:100%; }
.admin-sidebar .list-group { margin: 0; padding: 0; width: 100%; }
.admin-sidebar .list-group-item {
    padding: 0.9rem 1rem;
    font-size: 1.05rem;
    border-radius: 10px;
    margin: 0.45rem 0;
    background: #fff;
    box-shadow: 0 6px 18px rgba(20,20,30,0.06);
    display: flex;
    align-items: center;
    gap: .6rem;
    border: none;
}
.admin-sidebar .list-group-item { white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
                
                     /* Offcanvas / sidebar horizontal overflow fixes: ensure elements use border-box
                         and never cause horizontal scroll due to padding/shadows. Apply to both
                         Bootstrap offcanvas and our admin-sidebar inside it. */
                     .offcanvas, .offcanvas .offcanvas-body { box-sizing: border-box; overflow-x: hidden !important; -webkit-overflow-scrolling: touch; }
                     .offcanvas { overflow-y: auto; }
                     .offcanvas .admin-sidebar { max-width: 100%; padding-left: 0.5rem; padding-right: 0.5rem; }
                     .offcanvas .admin-sidebar .list-group { width: 100%; }
                     .offcanvas .admin-sidebar .list-group-item { width: 100%; max-width: 100%; margin-right: 0; box-sizing: border-box; }
                     /* hide any accidental horizontal scrollbar styling for webkit */
                     .offcanvas::-webkit-scrollbar { height: 0; }
.admin-sidebar .list-group-item i { font-size: 1.2rem; color: #198754; }
.admin-sidebar .list-group-item.active, .admin-sidebar .list-group-item:hover { background: linear-gradient(90deg, rgba(25,135,84,0.06), rgba(25,135,84,0.02)); color: #0f5132; }
    </style>
</head>
<body>
        <?php include __DIR__ . '/../func/header.php'; ?>

        <?php $sidebarVisible = !in_array($section, ['home','notifications','orders','products'], true); ?>
        <div class="container my-3">
            <div class="row">
                <?php if ($sidebarVisible): ?>
                <div class="col-md-3">
                    <!-- Admin sidebar: moved links from header into a left-side navigation for admin pages -->
                                <nav class="list-group admin-sidebar mb-3">
                                    <a href="admin.php?section=home" class="list-group-item list-group-item-action py-3 fs-6 <?= ($section === 'home') ? 'active' : '' ?>"><i class="bi bi-house-fill me-2"></i>Home</a>
                                    <a href="/Pharma_Sys/pages/dashboard.php" class="list-group-item list-group-item-action py-3 fs-6"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                                    <a href="admin.php?section=notifications" class="list-group-item list-group-item-action py-3 fs-6 <?= ($section === 'notifications') ? 'active' : '' ?>"><i class="bi bi-bell-fill me-2"></i>Notifications</a>
                                    <a href="/Pharma_Sys/pages/orders.php" class="list-group-item list-group-item-action py-3 fs-6"><i class="bi bi-bag-fill me-2"></i>Orders</a>
                                    <a href="/Pharma_Sys/pages/products.php" class="list-group-item list-group-item-action py-3 fs-6"><i class="bi bi-box-seam me-2"></i>Products</a>
                                                <a href="/Pharma_Sys/pages/user_management.php" class="list-group-item list-group-item-action py-3 fs-6"><i class="bi bi-people-fill me-2"></i>User Management</a>
                                                <a href="/Pharma_Sys/pages/admin_profile.php" class="list-group-item list-group-item-action py-3 fs-6"><i class="bi bi-person-circle me-2"></i>Profile</a>
                                </nav>
                </div>
                <?php endif; ?>
                <div class="<?= $sidebarVisible ? 'col-md-9' : 'col-12' ?>">
            <?php if (!in_array($section, ['notifications','orders'], true)): ?>
            <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="admin-greeting d-flex align-items-center gap-3">
                <?php
                    // Determine greeting image: prefer uploaded admin image only if the file exists.
                    $imgPath = $admin['profile_image'] ?? '';
                    $greeting_img = '../assets/image/default.jpg';
                    if ($imgPath) {
                        $maybe = __DIR__ . '/../assets/profile/' . $imgPath;
                        if (is_file($maybe)) {
                            // rawurlencode the filename for safety in URLs
                            $greeting_img = '../assets/profile/' . rawurlencode($imgPath);
                        }
                    }
                ?>
                <img src="<?= htmlspecialchars($greeting_img) ?>" alt="Profile" style="width:64px;height:64px;border-radius:50%;object-fit:cover;">
                <h2 id="admin-greeting-text" data-admin="<?= htmlspecialchars($admin_display, ENT_QUOTES) ?>" class="mb-0"><?= htmlspecialchars($time_greeting) ?>, <?= $admin_display ?></h2>
            </div>
            </div>
            <?php endif; ?>

        <!-- Navigation tabs removed per request: admin landing will be a single overview page. -->

        <div class="section-content">
            <?php if ($section === 'home'): ?>
                <div class="row">
                    <div class="col-md-8">
                            <h3>Overview</h3>
                            <?php
                            // Mini dashboard stats for admin home
                            $miniUsers = 0; $miniOrders = 0; $miniDailySales = 0.0;
                            try {
                                $r = $conn->query("SELECT COUNT(*) AS c FROM customer"); if ($r && ($row = $r->fetch_assoc())) $miniUsers = (int)$row['c'];
                            } catch (Exception $e) {}
                            try {
                                $r = $conn->query("SELECT COUNT(*) AS c FROM orders"); if ($r && ($row = $r->fetch_assoc())) $miniOrders = (int)$row['c'];
                            } catch (Exception $e) {}
                            try {
                                $r = $conn->query("SELECT IFNULL(SUM(total_amount),0) AS s FROM orders WHERE DATE(order_date) = CURDATE()"); if ($r && ($row = $r->fetch_assoc())) $miniDailySales = (float)$row['s'];
                            } catch (Exception $e) {}
                            ?>
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <div class="card p-2">
                                        <div class="small text-muted">Users</div>
                                        <div class="h5 mb-0"><?= number_format($miniUsers) ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="card p-2">
                                        <div class="small text-muted">Orders</div>
                                        <div class="h5 mb-0"><?= number_format($miniOrders) ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="card p-2">
                                        <div class="small text-muted">Daily Sales</div>
                                        <div class="h5 mb-0">₱<?= htmlspecialchars(number_format((float)$miniDailySales,2)) ?></div>
                                    </div>
                                </div>
                            </div>
                            <!-- Mini sales chart -->
                            <div class="card p-3 mt-3">
                                <div class="fw-semibold mb-2">Sales (Last 14 days)</div>
                                <canvas id="admin-mini-sales-chart" height="120"></canvas>
                            </div>
                        </div>
                    <div class="col-md-4">
                        <h5>Recent Notifications</h5>
                        <div class="list-group recent-notifications">
                            <?php
                        $notes = read_admin_notifications(8);
                                $lastNotifId = 0;
                                if (empty($notes)) {
                                    echo '<div class="list-group-item">No notifications yet.</div>';
                                } else {
                                    foreach ($notes as $n) {
                                        if (!empty($n['id'])) $lastNotifId = max($lastNotifId, (int)$n['id']);
                                        // support both file-based entries and DB rows
                                        if (!empty($n['created_at'])) {
                                            $tsRaw = $n['created_at'];
                                        } elseif (!empty($n['ts'])) {
                                            $tsRaw = $n['ts'];
                                        } else {
                                            $tsRaw = '';
                                        }
                                        $ts = '';
                                        try { if ($tsRaw) $ts = (new DateTime($tsRaw))->format('M j, Y H:i'); } catch(Exception $e) { $ts = htmlspecialchars($tsRaw); }

                                        $adminN = htmlspecialchars($n['actor_username'] ?? $n['admin_username'] ?? '');
                                        // Prefer full message when available (newer notifications). Fall back to action/product fields for legacy entries.
                                        $msg = htmlspecialchars($n['message'] ?? ($n['action'] ?? ''));
                                        $pname = htmlspecialchars($n['product_name'] ?? '');
                                        $pid = '';
                                        if (isset($n['product_id']) && $n['product_id'] !== null && $n['product_id'] !== '') $pid = '#'.(int)$n['product_id'];

                                        // resolve profile image for this actor (if present)
                                        $profile_url = '../assets/image/default.jpg';
                                        if (!empty($n['actor_profile'])) {
                                            $maybe = __DIR__ . '/../assets/profile/' . $n['actor_profile'];
                                            if (is_file($maybe)) $profile_url = '../assets/profile/' . rawurlencode($n['actor_profile']);
                                        }

                                        echo '<div class="list-group-item d-flex align-items-start gap-2 notification-item">';
                                        echo '<div style="flex:0 0 auto">';
                                        echo '<img src="' . htmlspecialchars($profile_url) . '" style="width:36px;height:36px;border-radius:50%;object-fit:cover;margin-right:8px;">';
                                        echo '</div>';
                                        echo '<div style="flex:1 1 auto">';
                                        echo '<div class="small text-muted">' . htmlspecialchars($ts) . '</div>';
                                        if ($msg !== '') {
                                            echo '<div><strong>' . $adminN . '</strong> ' . $msg . '</div>';
                                        } else {
                                            echo '<div><strong>' . $adminN . '</strong> ' . htmlspecialchars($n['action'] ?? '') . ' ' . ($pname ? '<em>' . $pname . '</em>' : '') . ' ' . $pid . '</div>';
                                        }
                                        echo '</div>';
                                        echo '</div>';
                                    }
                                }
                            ?>
                        </div>
                    </div>
                </div>
                <script>
                    (function(){
                        // polling for new notifications and show toast
                        var lastId = <?= isset($lastNotifId) ? (int)$lastNotifId : 0 ?>;
                        var pollInterval = 5000; // 5s
                        var listGroup = document.currentScript.previousElementSibling || null;
                        function renderNotificationItem(n) {
                            var created = n.created_at || n.ts || '';
                            var ts = '';
                            try { if (created) ts = new Date(created).toLocaleString(); } catch(e) { ts = created; }
                            var actor = n.actor_username || n.admin_username || '';
                            var profile = n.actor_profile || '';
                            var imgHtml = '';
                            if (profile) {
                                var url = '../assets/profile/' + encodeURIComponent(profile);
                                imgHtml = '<img src="' + url + '" style="width:36px;height:36px;border-radius:50%;object-fit:cover;margin-right:8px;">';
                            }
                            var title = '<div class="d-flex align-items-center">' + imgHtml + '<div><strong>' + escapeHtml(actor) + '</strong><div class="small text-muted">' + escapeHtml(ts) + '</div></div></div>';
                            var body = '<div class="mt-2">' + escapeHtml(n.message || n.action || '') + '</div>';
                            var item = document.createElement('div');
                            item.className = 'list-group-item notification-item';
                            item.style.animation = 'fadeIn 400ms ease-out';
                            item.innerHTML = title + body;
                            return item;
                        }

                        function escapeHtml(s){ return String(s).replace(/[&"'<>]/g, function(c){return {'&':'&amp;','"':'&quot;','\'':'&#39;','<':'&lt;','>':'&gt;'}[c];}); }

                        function showToast(n){
                            var toast = document.createElement('div');
                            toast.className = 'notification-toast shadow';
                            var imgHtml = '';
                            if (n.actor_profile) {
                                imgHtml = '<img src="../assets/profile/' + encodeURIComponent(n.actor_profile) + '" style="width:40px;height:40px;border-radius:50%;object-fit:cover;margin-right:8px;">';
                            }
                            var title = '<div class="d-flex align-items-center">' + imgHtml + '<div><strong>' + escapeHtml(n.actor_username || n.admin_username || '') + '</strong><div class="small text-muted">' + (new Date(n.created_at || n.ts || '').toLocaleString() || '') + '</div></div></div>';
                            var body = '<div>' + escapeHtml(n.message || n.action || '') + '</div>';
                            toast.innerHTML = '<div class="p-2 bg-white rounded">' + title + body + '</div>';
                            toast.style.opacity = '0';
                            document.body.appendChild(toast);
                            // position
                            toast.style.position = 'fixed';
                            toast.style.right = '20px';
                            toast.style.bottom = '20px';
                            toast.style.zIndex = 2000;
                            toast.style.transition = 'opacity 300ms ease, transform 300ms ease';
                            setTimeout(function(){ toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
                            setTimeout(function(){ toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)'; setTimeout(function(){ toast.remove(); }, 400); }, 5000);
                        }

                        function poll(){
                            fetch('notifications_poll.php?since_id=' + lastId)
                            .then(function(r){ if (!r.ok) throw r; return r.json(); })
                            .then(function(list){
                                if (!Array.isArray(list) || list.length === 0) return;
                                // prepend in reverse order so earliest appears first
                                list.reverse();
                                list.forEach(function(n){
                                    try {
                                        // ensure actor_profile_url available (from poll endpoint)
                                        if (!n.actor_profile_url && n.actor_profile) {
                                            // fallback: build relative path
                                            n.actor_profile_url = '../assets/profile/' + encodeURIComponent(n.actor_profile);
                                        }
                                        var item = renderNotificationItem(n);
                                        var container = document.querySelector('.list-group');
                                        if (container) container.insertBefore(item, container.firstChild);
                                        showToast(n);
                                        if (n.id && n.id > lastId) lastId = n.id;
                                    } catch(e){}
                                });
                            }).catch(function(){ /* ignore */ });
                        }
                        // initial poll after small delay
                        setInterval(poll, pollInterval);
                    })();
                </script>
            <?php elseif ($section === 'profile'): ?>
                
                <?php elseif ($section === 'notifications'): ?>
                <h3>All Notifications</h3>
                <p class="text-muted">A chronological list of admin actions and system notifications (newest first).</p>
                <style>
                    /* For the notifications page: prevent the overall page from scrolling
                       and do NOT allow the notifications panel itself to scroll. Pagination
                       will be used to navigate pages so both the page and the panel stay
                       fixed in height without internal scrollbars. */
                    html, body { height: 100%; }
                    body.notifications-no-scroll { overflow: hidden; }
                    /* Keep the list fixed to viewport space and do not show internal scrollbars */
                    .notifications-list { max-height: calc(100vh - 220px); overflow: hidden; }
                    /* Ensure the list uses the modern rounded bubble style */
                    .recent-notifications .list-group-item { border-radius: 12px; margin-bottom: 10px; border: none; background: #fff; box-shadow: 0 6px 18px rgba(20,20,30,0.06); }
                </style>
                <div class="list-group recent-notifications notifications-list mb-3">
                    <?php
                        $page = max(1, (int)($_GET['page'] ?? 1));
                        // Reduced per-page so each page fits the viewport without needing scrollbars
                        $perPage = 8;
                        $offset = ($page - 1) * $perPage;
                        $notes = [];
                        $total = 0;
                        // Try DB first
                        if (isset($conn) && $conn) {
                            try {
                                $cstmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notifications");
                                if ($cstmt) {
                                    mysqli_stmt_execute($cstmt);
                                    $cres = mysqli_stmt_get_result($cstmt);
                                    if ($cres && ($crow = mysqli_fetch_assoc($cres))) $total = (int)$crow['total'];
                                    mysqli_stmt_close($cstmt);
                                }

                                $stmt = mysqli_prepare($conn, "SELECT n.id, n.user_id, n.message, n.action, n.product_id, n.product_name, COALESCE(n.actor_username, a.username) AS actor_username, a.profile_image AS actor_profile, n.created_at FROM notifications n LEFT JOIN admin a ON n.user_id = a.admin_id ORDER BY n.created_at DESC LIMIT ?, ?");
                                if ($stmt) {
                                    mysqli_stmt_bind_param($stmt, "ii", $offset, $perPage);
                                    mysqli_stmt_execute($stmt);
                                    $res = mysqli_stmt_get_result($stmt);
                                    if ($res) {
                                        while ($r = mysqli_fetch_assoc($res)) $notes[] = $r;
                                    }
                                    mysqli_stmt_close($stmt);
                                }
                            } catch (Exception $e) {
                                // fall through to file fallback
                            }
                        }

                        // File fallback if DB not available or returned nothing
                        if (empty($notes)) {
                            $file = __DIR__ . '/../data/notifications.log';
                            $lines = [];
                            if (is_file($file)) {
                                $raw = array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
                                $lines = array_reverse($raw);
                            }
                            $total = $total ?: count($lines);
                            $slice = array_slice($lines, $offset, $perPage);
                            foreach ($slice as $line) {
                                $j = json_decode($line, true);
                                if (!$j) continue;
                                $actor_profile = null;
                                if (isset($conn) && $conn) {
                                    try {
                                        if (!empty($j['admin_id'])) {
                                            $aid = (int)$j['admin_id'];
                                            $s = mysqli_prepare($conn, "SELECT profile_image FROM admin WHERE admin_id = ? LIMIT 1");
                                            if ($s) {
                                                mysqli_stmt_bind_param($s, "i", $aid);
                                                mysqli_stmt_execute($s);
                                                $r = mysqli_stmt_get_result($s);
                                                if ($r && ($rr = mysqli_fetch_assoc($r)) && !empty($rr['profile_image'])) $actor_profile = $rr['profile_image'];
                                                mysqli_stmt_close($s);
                                            }
                                        }
                                    } catch (Exception $e) {}
                                }
                                $notes[] = [
                                    'id' => null,
                                    'user_id' => $j['admin_id'] ?? null,
                                    'message' => $j['action'] ?? '',
                                    'action' => $j['action'] ?? '',
                                    'product_id' => $j['product_id'] ?? null,
                                    'product_name' => $j['product_name'] ?? '',
                                    'actor_username' => $j['admin_username'] ?? '',
                                    'actor_profile' => $actor_profile,
                                    'created_at' => $j['ts'] ?? null,
                                ];
                            }
                        }

                        if (empty($notes)) {
                            echo '<div class="list-group-item">No notifications yet.</div>';
                        } else {
                            foreach ($notes as $n) {
                                $tsRaw = $n['created_at'] ?? ($n['ts'] ?? '');
                                $ts = '';
                                try { if ($tsRaw) $ts = (new DateTime($tsRaw))->format('M j, Y H:i'); } catch(Exception $e) { $ts = htmlspecialchars($tsRaw); }
                                $adminN = htmlspecialchars($n['actor_username'] ?? $n['admin_username'] ?? 'System');
                                $action = htmlspecialchars($n['action'] ?? ($n['message'] ?? ''));
                                $pname = htmlspecialchars($n['product_name'] ?? '');
                                $pid = '';
                                if (isset($n['product_id']) && $n['product_id'] !== null && $n['product_id'] !== '') $pid = '#'.(int)$n['product_id'];
                                $profile_url = '../assets/image/default.jpg';
                                if (!empty($n['actor_profile'])) {
                                    $maybe = __DIR__ . '/../assets/profile/' . $n['actor_profile'];
                                    if (is_file($maybe)) $profile_url = '../assets/profile/' . rawurlencode($n['actor_profile']);
                                }

                                echo '<div class="list-group-item d-flex align-items-start gap-3">';
                                echo '<div style="flex:0 0 auto"><img src="' . htmlspecialchars($profile_url) . '" style="width:44px;height:44px;border-radius:50%;object-fit:cover;margin-right:10px;"></div>';
                                echo '<div style="flex:1 1 auto">';
                                echo '<div class="small text-muted">' . htmlspecialchars($ts) . '</div>';
                                echo '<div><strong>' . $adminN . '</strong> ' . $action;
                                if ($pname) echo ' <em>' . $pname . '</em>';
                                if ($pid) echo ' ' . $pid;
                                echo '</div>';
                                echo '</div>';
                                echo '</div>';
                            }
                        }
                    ?>
                </div>
                <?php
                    // Pagination controls
                    $total = max(0, (int)$total);
                    $totalPages = max(1, (int)ceil($total / $perPage));
                    $prev = $page > 1 ? $page - 1 : null;
                    $next = $page < $totalPages ? $page + 1 : null;
                ?>
                                <nav aria-label="Notifications pagination">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="<?= $prev ? ('admin.php?section=notifications&page=' . $prev) : '#' ?>">Previous</a></li>
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="admin.php?section=notifications&page=<?= $p ?>"><?= $p ?></a></li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="<?= $next ? ('admin.php?section=notifications&page=' . $next) : '#' ?>">Next</a></li>
                                    </ul>
                                </nav>
                                <script>
                                        // Prevent body/page scroll while allowing the notifications panel to scroll
                                        try { document.body.classList.add('notifications-no-scroll'); } catch(e){}
                                </script>

            <?php elseif ($section === 'profile'): ?>
                <h3>Profile</h3>
                <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if (empty($edit)): ?>
                <form method="POST" enctype="multipart/form-data" class="mb-4 p-3 border">
                    <input type="hidden" name="section" value="profile">
                    <input type="hidden" name="action" value="save_profile">
                    <div class="mb-3">
                        <label class="form-label">Change username</label>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($admin['username'] ?? '') ?>">
                    </div>
                                        <div class="mb-3">
                                                <label class="form-label">Change password</label>
                                                <div class="d-flex align-items-center">
                                                    <input type="password" name="password" id="admin-password" class="form-control" placeholder="Leave blank to keep current">
                                                    <span id="admin-pw-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                                                    <small id="admin-pw-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                                                </div>
                                        </div>
                                        <div class="mb-3">
                                                <label class="form-label">Confirm new password</label>
                                                <div class="d-flex align-items-center">
                                                    <input type="password" name="password_confirm" id="admin-password-confirm" class="form-control" placeholder="Repeat new password">
                                                    <span id="admin-pwconf-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                                                    <small id="admin-pwconf-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                                                </div>
                                        </div>
                                        <script>
                                            (function(){
                                                var pw = document.getElementById('admin-password');
                                                var pwc = document.getElementById('admin-password-confirm');
                                                var ind = document.getElementById('admin-pw-ind');
                                                var indc = document.getElementById('admin-pwconf-ind');
                                                var msg = document.getElementById('admin-pw-msg');
                                                var msgc = document.getElementById('admin-pwconf-msg');
                                                if (!pw || !pwc) return;
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
                                            })();
                                        </script>
                    <div class="mb-3">
                        <label class="form-label">Upload profile picture</label>
                        <input type="file" name="profile_image" class="form-control">
                    </div>
                    <?php
                        $admImg = $admin['profile_image'] ?? '';
                        $admImgExists = false;
                        if ($admImg) {
                            $admDisk = __DIR__ . '/../assets/profile/' . $admImg;
                            if (is_file($admDisk)) $admImgExists = true;
                        }
                    ?>
                    <?php if ($admImgExists): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <img src="../assets/profile/<?= htmlspecialchars($admImg) ?>" style="max-width:100px; border-radius:50%;">
                            <button type="button" class="btn btn-sm btn-danger" id="removeProfileImageBtn">Remove profile picture</button>
                        </div>
                        <script>
                            (function(){
                                var btn = document.getElementById('removeProfileImageBtn');
                                if (!btn) return;
                                btn.addEventListener('click', function(){
                                    if (!confirm('Remove profile picture?')) return;
                                    var f = document.createElement('form');
                                    f.method = 'POST';
                                    f.style.display = 'none';
                                    var s = document.createElement('input'); s.type='hidden'; s.name='section'; s.value='profile'; f.appendChild(s);
                                    var a = document.createElement('input'); a.type='hidden'; a.name='action'; a.value='remove_profile_image'; f.appendChild(a);
                                    document.body.appendChild(f);
                                    f.submit();
                                });
                            })();
                        </script>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </form>
                <?php endif; ?>
                <h3>Products</h3>
                <?php if ($message): ?>
                    <div class="alert alert-success alert-fixed shadow"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="mb-4 p-3 border">
                    <input type="hidden" name="section" value="products">
                    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['Product_id'] ?>"><?php endif; ?>

                    <div class="mb-2"><label>Name:</label><input type="text" name="name" value="<?= htmlspecialchars($edit['Product_name'] ?? '') ?>" class="form-control" required></div>
                    <div class="mb-2">
                        <label>Category:</label>
                        <select name="category" class="form-select">
                            <option value="">-- Select category --</option>
                            <?php
                                $cats = ['Pain Relief','Antibiotics','Antivirals','Antifungals','Antipyretics','Antihistamines','Cough & Cold Remedies','Vitamins & Supplements','Anesthetics','Laxatives','Vaccines'];
                                $curCat = $edit['category'] ?? '';
                                foreach ($cats as $c) {
                                    $sel = ($c === $curCat) ? 'selected' : '';
                                    echo '<option ' . $sel . '>' . htmlspecialchars($c) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>Type:</label>
                        <select name="type" class="form-select">
                            <option value="">-- Select type --</option>
                            <?php
                                $types = ['Tablet','Capsule','Syrup','Injection','Cream','Ointment','Drops','Inhaler','Suppository','Gel','Powder'];
                                $curType = $edit['type'] ?? '';
                                foreach ($types as $t) {
                                    $sel = ($t === $curType) ? 'selected' : '';
                                    echo '<option ' . $sel . '>' . htmlspecialchars($t) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>Prescription Needed:</label>
                        <?php $curPres = ($edit['prescription_needed'] ?? 0) ? 'yes' : 'no'; ?>
                        <select name="prescription" class="form-select">
                            <option value="no" <?= $curPres === 'no' ? 'selected' : '' ?>>No</option>
                            <option value="yes" <?= $curPres === 'yes' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="mb-2"><label>Description:</label><input type="text" name="desc" value="<?= htmlspecialchars($edit['description'] ?? '') ?>" class="form-control" required></div>
                    <div class="mb-2"><label>Price:</label><input type="number" step="0.01" name="price" value="<?= htmlspecialchars($edit['price'] ?? '') ?>" class="form-control" required></div>
                    <div class="mb-2"><label>Stock:</label><input type="number" name="stock" value="<?= htmlspecialchars($edit['stck_qty'] ?? '') ?>" class="form-control" required></div>
                    <div class="mb-2"><label>Image:</label><input type="file" name="image" class="form-control" <?= $edit ? '' : 'required' ?>><?php if ($edit && $edit['image']): ?><div style="margin-top:6px;"><img src="../assets/image/<?= htmlspecialchars($edit['image']) ?>" style="max-width:100px"></div><?php endif; ?></div>

                    <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add' ?></button>
                    <?php if ($edit): ?><a href="/Pharma_Sys/pages/products.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
                </form>

                <?php foreach ($products as $p): ?>
                    <div class="border p-2 mb-2">
                        <strong><?= htmlspecialchars($p['Product_name']) ?></strong>
                        <?php if (!empty($p['category']) || !empty($p['type'])): ?> -
                            <?php if (!empty($p['category'])): ?><em><?= htmlspecialchars($p['category']) ?></em><?php endif; ?>
                            <?php if (!empty($p['type'])): ?> / <small><?= htmlspecialchars($p['type']) ?></small><?php endif; ?>
                        <?php endif; ?>
                        - <?= htmlspecialchars($p['description']) ?> - Price: ₱<?= htmlspecialchars(number_format((float)$p['price'],2)) ?> - Stock: <?= htmlspecialchars($p['stck_qty']) ?><?php if (isset($p['prescription_needed'])): ?> - <small>Prescription: <?= $p['prescription_needed'] ? 'Yes' : 'No' ?></small><?php endif; ?>
                        <a href="/Pharma_Sys/pages/products.php?edit=<?= (int)$p['Product_id'] ?>" class="btn btn-sm btn-info">Edit</a>
                        <a href="/Pharma_Sys/pages/products.php?delete=<?= (int)$p['Product_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</a>
                    </div>
                <?php endforeach; ?>

            <?php /* 'admins' section removed: admin account management is handled in user_management.php */ ?>

            <?php endif; ?>
                </div> <!-- end section-content -->
                </div> <!-- end col-md-9 -->
            </div> <!-- end row -->
        </div> <!-- end container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
                try { var ro = new ResizeObserver(adjustBodyPadding); ro.observe(hdr);} catch(e){ }
            }
        })();
    </script>
    <script>
        // Mini admin chart (daily sales)
        (async function(){
            try{
                const res = await fetch('../api/analytics.php?action=daily_sales');
                const j = await res.json();
                if (j.status !== 'ok') return;
                const labels = j.data.map(x=>x.d);
                const data = j.data.map(x=>parseFloat(x.s));
                const ctx = document.getElementById('admin-mini-sales-chart');
                if (!ctx) return;
                new Chart(ctx, { type:'line', data:{ labels, datasets:[{ label:'Sales', data, borderColor:'#198754', backgroundColor:'rgba(25,135,84,0.08)', fill:true, tension:0.3 }] }, options:{ responsive:true, plugins:{legend:{display:false}}, scales:{ x:{ ticks:{ maxRotation:0, autoSkip:true } } } } });
            }catch(e){ console.error('mini chart',e); }
        })();
    </script>
    <script>
        // Update admin greeting on client side so it reflects the user's local time
        (function(){
            try {
                var el = document.getElementById('admin-greeting-text');
                if (!el) return;
                var name = el.dataset.admin || '';
                function updateGreeting(){
                    var d = new Date();
                    var h = d.getHours();
                    var greeting = (h < 12) ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
                    el.textContent = greeting + ', ' + name;
                }
                // initial update and keep in sync if user keeps page open across hours
                updateGreeting();
                // update every 5 minutes in case of long sessions
                setInterval(updateGreeting, 5 * 60 * 1000);
            } catch(e) { /* silent fallback to server greeting */ }
        })();
    </script>
</body>
</html>