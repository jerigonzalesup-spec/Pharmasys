<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

include __DIR__ . '/../../func/db.php'; // ensure we use the central DB include

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
?>

<header>
  <div class="logo">
    <div class="logo-icon"></div>
    <span>PharmaSys</span>
  </div>

  <nav>
    <a href="#home">Home</a>
    <a href="#about">About</a>
    <a href="#contact">Contact</a>
    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
      <a href="pages/admin.php" class="btn btn-warning">Add product</a>
    <?php endif; ?>
  </nav>

  <!-- ✅ Search bar + Cart + Notification -->
  <div class="search-area d-flex align-items-center gap-3">
    <form class="search-container d-flex align-items-center" method="GET" action="search.php">
      <input type="text" name="query" placeholder="Search..." class="form-control" style="width: 220px;">
      <button type="submit" class="search-button btn btn-outline-primary ms-2">Search</button>
    </form>

    <!-- Cart + Notification beside search -->
    <div class="d-flex align-items-center gap-3 ms-3">
    <a href="pages/cart.php" class="text-dark position-relative">
        <i class="bi bi-cart" style="font-size: 1.4rem;"></i>
      </a>
    <a href="pages/notification.php" class="text-dark position-relative">
        <i class="bi bi-bell" style="font-size: 1.4rem;"></i>
        <?php if ($notif_count > 0): ?> <!-- ✅ simplified, no need for isset() -->
          <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
            <?= $notif_count ?>
          </span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Logged in: show profile + logout -->
  <a href="pages/profile.php" id="profile" class="d-flex align-items-center gap-2 ms-3">
   <img src="assets/image/default.jpg" alt="Profile"
     style="width: 30px; height: 30px; border-radius: 50%;">
      <span><?= htmlspecialchars($_SESSION['username']) ?></span>
    </a>
  <a href="func/logout.php" id="logout" class="btn btn-outline-secondary btn-sm ms-2 confirm-logout">Log Out</a>
  <?php else: ?>
    <!-- Not logged in -->
  <a href="pages/login.php" class="primary-button">Sign In</a>
  <?php endif; ?>

</header>
