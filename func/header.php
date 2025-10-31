<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

include __DIR__ . '/db.php';

$notif_count = 0;

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $query = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
    $query->bind_param("i", $user_id);
    $query->execute();
    $result = $query->get_result();
    if ($row = $result->fetch_assoc()) {
        $notif_count = $row['total'];
    }
    $query->close();
}
// Are we in admin mode? used to render a simplified admin header
// Accept common variants like 'admin', 'Administrator', 'ADMIN' etc.
$roleVal = (string)($_SESSION['role'] ?? '');
$isAdmin = (bool)preg_match('/\badmin\b/i', $roleVal);
?>
<!-- Bootstrap Icons (ensure icons render on pages that don't include the icons stylesheet) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

<header<?php if ($isAdmin) echo ' class="admin-mode"'; ?>>
  <a href="/Pharma_Sys/pages/index.php" class="logo" style="text-decoration:none;">
    <div class="logo-icon"></div>
    <span style="color:inherit;">PharmaSys</span>
  </a>
  <?php if ($isAdmin): ?>
    <!-- Sidebar toggle for admins (moved left of nav) -->
    <button class="btn btn-sm btn-link text-decoration-none p-0 me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminOffcanvas" aria-controls="adminOffcanvas" title="Open admin menu" id="admin-sidebar-toggle">
      <i class="bi bi-list" aria-hidden="true" style="font-size:1.25rem"></i>
      <span class="visually-hidden">Admin menu</span>
    </button>
  <?php endif; ?>

  <nav>
    <?php if ($isAdmin): ?>
      <a href="/Pharma_Sys/pages/admin.php">Home</a>
      <a href="/Pharma_Sys/pages/dashboard.php" class="ms-3">Dashboard</a>
      <a href="/Pharma_Sys/pages/admin_profile.php" class="ms-3">Profile</a>
    <?php else: ?>
      <a href="/Pharma_Sys/pages/index.php">Home</a>
    <?php endif; ?>
  </nav>

  <?php if ($isAdmin): ?>
    <!-- Offcanvas sidebar (available site-wide for admins) -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="adminOffcanvas" aria-labelledby="adminOffcanvasLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="adminOffcanvasLabel">Admin Menu</h5>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body p-0">
          <div class="list-group list-group-flush">
          <a href="/Pharma_Sys/pages/admin.php?section=home" class="list-group-item list-group-item-action"><i class="bi bi-house-fill me-2"></i>Home</a>
          <a href="/Pharma_Sys/pages/dashboard.php" class="list-group-item list-group-item-action"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
          <a href="/Pharma_Sys/pages/notification.php" class="list-group-item list-group-item-action"><i class="bi bi-bell-fill me-2"></i>Notifications</a>
          <a href="/Pharma_Sys/pages/orders.php" class="list-group-item list-group-item-action"><i class="bi bi-bag-fill me-2"></i>Orders</a>
          <a href="/Pharma_Sys/pages/products.php" class="list-group-item list-group-item-action"><i class="bi bi-box-seam me-2"></i>Products</a>
          <a href="/Pharma_Sys/pages/user_management.php" class="list-group-item list-group-item-action"><i class="bi bi-people-fill me-2"></i>User Management</a>
          <!-- Admins link removed: management of admin accounts is available in User Management -->
          <a href="/Pharma_Sys/pages/admin_profile.php" class="list-group-item list-group-item-action"><i class="bi bi-person-circle me-2"></i>Profile</a>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Search bar -->
  <?php if (! $isAdmin): ?>
    <div class="search-area d-flex align-items-center gap-3">
      <form class="search-container d-flex align-items-center" method="GET" action="/Pharma_Sys/pages/medicine_search.php" role="search">
        <a href="/Pharma_Sys/pages/medicine_search.php" class="text-muted me-2 search-hint" style="text-decoration:none; white-space:nowrap;">Search for medicine...</a>
        <input type="text" name="query" placeholder="Search..." class="form-control" style="width: 220px;">
        <button type="submit" class="search-button btn btn-outline-primary ms-2">Search</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Cart + Notification (always visible for non-admin users) -->
  <?php if (! $isAdmin): ?>
    <div class="header-icons d-flex align-items-center gap-3 ms-3">
      <a href="/Pharma_Sys/pages/cart.php" class="text-dark position-relative">
        <i class="bi bi-cart" style="font-size: 1.4rem;"></i>
      </a>
      <a href="/Pharma_Sys/pages/notifications.php" class="text-dark position-relative">
        <i class="bi bi-bell" style="font-size: 1.4rem;"></i>
        <?php if ($notif_count > 0): ?>
          <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
            <?= $notif_count ?>
          </span>
        <?php endif; ?>
      </a>
    </div>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
    <!-- Inline admin CSS to ensure green theme overrides any existing blue rules. Placed after main CSS so it wins. -->
    <style>
      header.admin-mode .logo { color: #198754 !important; }
      header.admin-mode .logo-icon { background-color: #198754 !important; }
      header.admin-mode .logo-icon.small { background-color: #198754 !important; }
      header.admin-mode nav a { color: #333 !important; }
      header.admin-mode nav a:hover { color: #198754 !important; }
      header.admin-mode .primary-button, header.admin-mode .search-button, header.admin-mode #logout { background-color: #198754 !important; border-color: #198754 !important; color: #fff !important; }
      header.admin-mode #logout:hover { background-color: #146c43 !important; }
      header.admin-mode #profile:hover { color: #198754 !important; }
      /* Admin sidebar toggle (left) - make it match green theme and more tappable */
      header.admin-mode #admin-sidebar-toggle { border-radius: 8px; padding: 6px; border: 1px solid rgba(25,135,84,0.12); background: transparent; }
      header.admin-mode #admin-sidebar-toggle i { color: #198754 !important; font-size: 1.25rem; }

      /* Offcanvas/admin menu item styling: larger, rounded, modern buttons */
      header.admin-mode .offcanvas .list-group-item {
        padding: 0.9rem 1rem;
        margin: 0.45rem 0.6rem;
        border-radius: 10px;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: .6rem;
        background: #fff;
        box-shadow: 0 6px 18px rgba(20,20,30,0.06);
      }
      header.admin-mode .offcanvas .list-group-item i { font-size: 1.15rem; color: #198754; }
      header.admin-mode .offcanvas .list-group-item.active,
      header.admin-mode .offcanvas .list-group-item:active,
      header.admin-mode .offcanvas .list-group-item:hover { background: linear-gradient(90deg, rgba(25,135,84,0.06), rgba(25,135,84,0.02)); color: #0f5132; }
    </style>
    <style>
      /* Global admin theme: apply green primary to buttons and pagination across admin pages */
      .site-admin-mode .btn-primary { background-color: #198754 !important; border-color: #198754 !important; color: #fff !important; }
      .site-admin-mode .btn-outline-primary { color: #198754 !important; border-color: #198754 !important; background: transparent !important; }
      .site-admin-mode .btn-outline-primary.active, .site-admin-mode .btn-outline-primary:active { background-color: rgba(25,135,84,0.08) !important; }
      .site-admin-mode .pagination .page-item.active .page-link,
      .site-admin-mode .pagination .page-link.active { background-color: #198754 !important; border-color: #198754 !important; color: #fff !important; }
      .site-admin-mode .page-link { color: #198754 !important; }
    </style>
    <script>
      // Add a global class to the body so admin theme rules can target the whole page
      try { document.addEventListener('DOMContentLoaded', function(){ document.body.classList.add('site-admin-mode'); }); } catch(e){}
    </script>
  <?php endif; ?>

  <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Logged in: show profile + logout -->
    <?php
  // determine profile image URL
  // Prefer the project's JPEG default; fall back to an existing PNG to
  // avoid displaying a broken image if the JPG isn't present.
  $default_web_jpg = '/Pharma_Sys/assets/image/default.jpg';
  $default_web_png = '/Pharma_Sys/assets/image/default.jpg';
  $default_disk_jpg = __DIR__ . '/../assets/image/default.jpg';
  $default_disk_png = __DIR__ . '/../assets/image/default.jpg';

  if (is_file($default_disk_jpg)) {
    $default_img = $default_web_jpg;
  } elseif (is_file($default_disk_png)) {
    $default_img = $default_web_png;
  } else {
    // last-resort: keep the old jpeg name (in case other parts still use it)
    $default_img = '/Pharma_Sys/assets/image/default.jpeg';
  }

  $profile_url = $default_img;
  $role = isset($_SESSION['role']) ? strtolower((string)$_SESSION['role']) : '';
  $uid = (int)($_SESSION['user_id'] ?? 0);

  // Only look up the profile image from the table that matches the
  // current session role. This prevents falling back to a different
  // user table and accidentally showing another account's photo.
  if ($uid && $role === 'admin') {
    // Check that the column exists before selecting it
    $colOk = false;
    try {
      $colRes = $conn->query("SHOW COLUMNS FROM admin LIKE 'profile_image'");
      if ($colRes && $colRes->num_rows > 0) $colOk = true;
    } catch (Exception $e) {
      $colOk = false;
    }

    if ($colOk) {
      $stmtImg = @$conn->prepare("SELECT profile_image FROM admin WHERE admin_id = ? LIMIT 1");
      if ($stmtImg) {
        $stmtImg->bind_param("i", $uid);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result();
        if ($rowImg = $resImg->fetch_assoc()) {
          $fn = trim((string)($rowImg['profile_image'] ?? ''));
          if ($fn !== '') {
            $disk = __DIR__ . '/../assets/profile/' . $fn;
            $web = '/Pharma_Sys/assets/profile/' . rawurlencode($fn);
            if (is_file($disk)) $profile_url = $web;
          }
        }
        $stmtImg->close();
      }
    }
  } elseif ($uid && ($role === 'customer' || $role === 'user')) {
    // Customer (or general user) role
    $colOkC = false;
    try {
      $colResC = $conn->query("SHOW COLUMNS FROM customer LIKE 'profile_image'");
      if ($colResC && $colResC->num_rows > 0) $colOkC = true;
    } catch (Exception $e) {
      $colOkC = false;
    }

    if ($colOkC) {
      $stmtC = @$conn->prepare("SELECT profile_image FROM customer WHERE Customer_id = ? LIMIT 1");
      if ($stmtC) {
        $stmtC->bind_param("i", $uid);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        if ($rowC = $resC->fetch_assoc()) {
          $fn = trim((string)($rowC['profile_image'] ?? ''));
          if ($fn !== '') {
            $disk = __DIR__ . '/../assets/profile/' . $fn;
            $web = '/Pharma_Sys/assets/profile/' . rawurlencode($fn);
            if (is_file($disk)) $profile_url = $web;
          }
        }
        $stmtC->close();
      }
    }
  }
    ?>
    <a href="<?= $isAdmin ? '/Pharma_Sys/pages/admin_profile.php' : '/Pharma_Sys/pages/profile.php' ?>" id="profile" class="d-flex align-items-center gap-2 ms-3">
      <img src="<?= htmlspecialchars($profile_url) ?>" alt="Profile" style="width: 30px; height: 30px; border-radius: 50%; object-fit:cover;">
      <span><?= htmlspecialchars($_SESSION['username']) ?></span>
    </a>
    <a href="/Pharma_Sys/func/logout.php" id="logout" class="btn btn-outline-secondary btn-sm ms-2">Log Out</a>
  <?php else: ?>
    <!-- Not logged in -->
    <a href="/Pharma_Sys/pages/login.php" class="primary-button">Sign In</a>
  <?php endif; ?>

</header>
<style>
  /* Ensure the header search input and button display as a single pill and override Bootstrap defaults if needed */
  header .search-container { display:flex; align-items:center; }
  header .search-container input {
    border-radius: 0.5rem 0 0 0.5rem;
    height: 38px;
    padding-left: 12px;
    border-right: none;
  }
  header .search-container .search-button {
    border-radius: 0 0.5rem 0.5rem 0;
    height: 38px;
    padding: 6px 12px;
    background: #1877f2 !important;
    color: #fff !important;
    border: 1px solid #1877f2 !important;
  }
  /* ensure outline variant doesn't override colors */
  header .search-container .search-button.btn-outline-primary {
    background: #1877f2 !important;
    color: #fff !important;
    border-color: #1877f2 !important;
  }
</style>
<script>
  // Ensure header is fixed and page content is padded so it doesn't sit behind the header.
  (function(){
    function adjustHeaderPadding(){
      var hdr = document.querySelector('header');
      if (!hdr) return;
      try { hdr.style.position = 'fixed'; hdr.style.top = '0'; hdr.style.left = '0'; hdr.style.right = '0'; hdr.style.zIndex = '1200'; } catch(e){}
      var rect = hdr.getBoundingClientRect();
      var h = rect.height || 0;
      try { document.documentElement.style.overflowY = 'auto'; document.body.style.overflowY = 'auto'; } catch(e){}
      document.body.style.paddingTop = h + 'px';
    }
    function bindLogout(){
      var els = document.querySelectorAll('a.confirm-logout, a#logout');
      els.forEach(function(a){
        a.addEventListener('click', function(e){
          var href = a.getAttribute('href') || '';
          if (/logout\.php$/i.test(href)){
            if (!confirm('Are you sure you want to log out?')) {
              e.preventDefault();
              e.stopImmediatePropagation();
              return false;
            }
          }
        }, { once: false });
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function(){ adjustHeaderPadding(); bindLogout(); });
    } else { adjustHeaderPadding(); bindLogout(); }

    // Recompute on resize and when header size changes
    window.addEventListener('resize', adjustHeaderPadding);
    var hdr = document.querySelector('header');
    if (hdr && typeof ResizeObserver !== 'undefined'){
      try { var ro = new ResizeObserver(adjustHeaderPadding); ro.observe(hdr); } catch(e){}
    }
  })();
</script>
<?php
// Prepare category/type phrases for search placeholder typing (non-admin only)
$typing_phrases = ["Medicine","Prescriptions","PharmaSys"];
if (!empty($conn)) {
  try {
    $seen = [];
    // Pull both category and type, then split common delimiters so combined values
    // like "Pain relief, Antipyretics" become two separate phrases.
    $q = $conn->query("SELECT DISTINCT COALESCE(category,'') AS category, COALESCE(`type`,'') AS type FROM product WHERE (category IS NOT NULL AND category <> '') OR (`type` IS NOT NULL AND `type` <> '') LIMIT 200");
    if ($q && $q->num_rows > 0) {
      while ($r = $q->fetch_assoc()) {
        foreach (['category','type'] as $col) {
          $raw = trim((string)($r[$col] ?? ''));
          if ($raw === '') continue;
          // split on commas, semicolons, pipes and slashes
          $parts = preg_split('/[,;|\\/]+/', $raw);
          if (!is_array($parts)) $parts = [$raw];
          foreach ($parts as $p) {
            $t = trim((string)$p);
            if ($t === '') continue;
            // normalize internal whitespace
            $t = preg_replace('/\s+/', ' ', $t);
            $key = mb_strtolower($t);
            if (!isset($seen[$key])) $seen[$key] = $t;
          }
        }
      }
      if (!empty($seen)) {
        $typing_phrases = array_values($seen);
        // sort case-insensitive for nicer ordering
        usort($typing_phrases, function($a,$b){ return strcasecmp($a,$b); });
      }
    }
  } catch (Exception $e) {}
}
?>
<script>
  (function(){
    var phrases = <?= json_encode(array_values(array_slice($typing_phrases,0,20)), JSON_UNESCAPED_UNICODE) ?>;
    if (!phrases || !phrases.length) return;
    var input = document.querySelector('form.search-container input[name="query"]');
    if (!input) return;
    var typeSpeed = 80; var pauseAfter = 1200; var pauseBetween = 400;
    var pIndex = 0, charIndex = 0, forward = true, taglineTimer = null;

    function typeStep(){
      try {
        var current = phrases[pIndex] || '';
        if (forward) {
          charIndex++;
          input.placeholder = current.slice(0, charIndex);
          if (charIndex >= current.length) {
            forward = false;
            taglineTimer = setTimeout(typeStep, pauseAfter);
            return;
          }
        } else {
          charIndex--;
          input.placeholder = current.slice(0, charIndex);
          if (charIndex <= 0) {
            forward = true;
            pIndex = (pIndex + 1) % phrases.length;
            taglineTimer = setTimeout(typeStep, pauseBetween);
            return;
          }
        }
      } catch(e){}
      taglineTimer = setTimeout(typeStep, typeSpeed);
    }

    var focused = false;
    input.addEventListener('focus', function(){ focused = true; if (taglineTimer) { clearTimeout(taglineTimer); taglineTimer = null; } input.placeholder = ''; });
    input.addEventListener('blur', function(){ focused = false; if (!taglineTimer) { charIndex = 0; forward = true; pIndex = 0; typeStep(); } });

    // start animation only when input is empty and not focused
    if (input && !input.value) setTimeout(typeStep, 700);
  })();
</script>