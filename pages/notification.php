<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: ../pages/login.php');
  exit;
}

$db_path = __DIR__ . '/../func/db.php';
if (!file_exists($db_path)) die('Missing required include: func/db.php');
include $db_path;

// read_admin_notifications: DB-first with file fallback (copied from admin.php)
function read_admin_notifications_paged($offset = 0, $limit = 20, &$totalOut = 0) {
  $out = [];
  global $conn;
  $totalOut = 0;
  if (isset($conn) && $conn) {
    try {
      $cstmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notifications");
      if ($cstmt) {
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
        if ($cres && ($crow = mysqli_fetch_assoc($cres))) $totalOut = (int)$crow['total'];
        mysqli_stmt_close($cstmt);
      }

      $stmt = mysqli_prepare($conn, "SELECT n.id, n.user_id, n.message, n.action, n.product_id, n.product_name, COALESCE(n.actor_username, a.username) AS actor_username, a.profile_image AS actor_profile, n.created_at FROM notifications n LEFT JOIN admin a ON n.user_id = a.admin_id ORDER BY n.created_at DESC LIMIT ?, ?");
      if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $offset, $limit);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
          while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
        }
        mysqli_stmt_close($stmt);
      }
      // return results (may be empty) with $totalOut set
      return $out;
    } catch (Exception $e) {
      // fall through to file fallback
    }
  }

  // File fallback
  $file = __DIR__ . '/../data/notifications.log';
  $lines = [];
  if (is_file($file)) {
    $raw = array_filter(array_map('trim', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    $lines = array_reverse($raw);
  }
  $totalOut = $totalOut ?: count($lines);
  $slice = array_slice($lines, $offset, $limit);
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
  return $out;
}

// Page variables
$page = max(1, (int)($_GET['page'] ?? 1));
// Reduce notifications per page so pagination stays visible when scrolling is disabled
$perPage = 6;
$offset = ($page - 1) * $perPage;
$total = 0;
$notes = read_admin_notifications_paged($offset, $perPage, $total);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>All Notifications - Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    /* Disable all scrolling on this page: page and notifications list will not scroll */
    html, body { height: 100%; overflow: hidden !important; }
    .notifications-list { max-height: none; overflow: visible; }
    .recent-notifications .list-group-item { border-radius: 12px; margin-bottom: 10px; border: none; background: #fff; box-shadow: 0 6px 18px rgba(20,20,30,0.06); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../func/header.php'; ?>
  <div class="container my-4">
    <h3>All Notifications</h3>
    <p class="text-muted">A chronological list of admin actions and system notifications (newest first).</p>

    <div class="list-group recent-notifications notifications-list mb-3">
      <?php
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
      $total = max(0, (int)$total);
      $totalPages = max(1, (int)ceil($total / $perPage));
      $prev = $page > 1 ? $page - 1 : null;
      $next = $page < $totalPages ? $page + 1 : null;
    ?>
    <nav aria-label="Notifications pagination">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="<?= $prev ? ('notification.php?page=' . $prev) : '#' ?>">Previous</a></li>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="notification.php?page=<?= $p ?>"><?= $p ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="<?= $next ? ('notification.php?page=' . $next) : '#' ?>">Next</a></li>
      </ul>
    </nav>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>