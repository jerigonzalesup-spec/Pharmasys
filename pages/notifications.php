<?php
session_start();
include __DIR__ . '/../func/db.php';

// Require logged-in user
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$total = 0;
$notes = [];

try {
    // Count
    $cstmt = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ?");
    if ($cstmt) {
        $cstmt->bind_param('i', $uid);
        $cstmt->execute();
        $cres = $cstmt->get_result();
        if ($cres && ($crow = $cres->fetch_assoc())) $total = (int)$crow['total'];
        $cstmt->close();
    }

    $stmt = $conn->prepare("SELECT id, user_id, message, action, product_id, product_name, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?");
    if ($stmt) {
        $stmt->bind_param('iii', $uid, $offset, $perPage);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $notes[] = $r;
        $stmt->close();
    }
} catch (Exception $e) {
    // ignore and fall back to empty
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Your Notifications</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/design.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    html, body { height: 100%; }
    .notification-item { border-radius: 12px; margin-bottom: 10px; padding: 12px; background: #fff; box-shadow: 0 6px 18px rgba(20,20,30,0.04); }
  </style>
</head>
<body>
  <?php include __DIR__ . '/../func/header.php'; ?>
  <div class="container my-4">
    <h3>Your Notifications</h3>
    <p class="text-muted">Notifications relevant to your account, newest first.</p>

    <div class="list-group mb-3">
      <?php if (empty($notes)): ?>
        <div class="list-group-item">No notifications yet.</div>
      <?php else: ?>
        <?php foreach ($notes as $n): ?>
          <?php
            $tsRaw = $n['created_at'] ?? '';
            $ts = '';
            try { if ($tsRaw) $ts = (new DateTime($tsRaw))->format('M j, Y H:i'); } catch(Exception $e) { $ts = htmlspecialchars($tsRaw); }
            $action = htmlspecialchars($n['action'] ?? $n['message'] ?? '');
            $pname = htmlspecialchars($n['product_name'] ?? '');
            $pid = isset($n['product_id']) && $n['product_id'] !== null ? '#'.(int)$n['product_id'] : '';
          ?>
          <div class="list-group-item notification-item">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="small text-muted"><?= $ts ?></div>
                <div><strong><?= htmlspecialchars($_SESSION['username'] ?? 'You') ?></strong> <?= $action ?> <?php if ($pname) echo '<em>'.$pname.'</em>'; ?> <?php if ($pid) echo $pid; ?></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php
      $total = max(0, (int)$total);
      $totalPages = max(1, (int)ceil($total / $perPage));
      $prev = $page > 1 ? $page - 1 : null;
      $next = $page < $totalPages ? $page + 1 : null;
    ?>
    <nav aria-label="Notifications pagination">
      <ul class="pagination justify-content-center">
        <li class="page-item <?= $prev ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $prev ?: 1 ?>">Previous</a></li>
        <?php for ($p=1;$p<=$totalPages;$p++): ?>
          <li class="page-item <?= $p=== $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $next ? '' : 'disabled' ?>"><a class="page-link" href="?page=<?= $next ?: $totalPages ?>">Next</a></li>
      </ul>
    </nav>

  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
