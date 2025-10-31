<?php
session_start();
include __DIR__ . '/../func/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username !== '' && $password !== '') {

        // --- Check ADMIN first ---
        $stmt = $conn->prepare("SELECT admin_id, username, password, role FROM admin WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $admin_result = $stmt->get_result();

        if ($admin_row = $admin_result->fetch_assoc()) {
            // Support both hashed and legacy plain-text admin passwords.
            // Prefer password_verify for hashed passwords, but fall back to direct comparison
            // to avoid locking out existing legacy accounts.
            $stored = $admin_row['password'];
            $admin_ok = false;
            if ($stored !== null && $stored !== '') {
                // if it's a valid hash, password_verify will handle it; otherwise fallback
                if (password_verify($password, $stored)) {
                    $admin_ok = true;
                } elseif ($password === $stored) {
                    $admin_ok = true;
                }
            }
            if ($admin_ok) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $admin_row['admin_id'];
                $_SESSION['username'] = $admin_row['username'];
                $_SESSION['role'] = 'admin'; // hardcoded for reliability
                header("Location: admin.php");
                exit();
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            // --- Otherwise, check CUSTOMER ---
            $stmt = $conn->prepare("SELECT Customer_id, username, password, role FROM customer WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $row['Customer_id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['role'] = $row['role'];

                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                $error = "Invalid username or password.";
            }
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>
    <?php include __DIR__ . '/../func/header.php'; ?>

    <main>
        <div class="container mt-5" style="max-width: 400px;">
            <h2 class="mb-4 text-center" style="color: blue;">Log In</h2>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form action="login.php" method="POST">
                <div class="mb-3">
                    <label for="login-username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="login-username" name="username" placeholder="Enter username" required>
                </div>
                <div class="mb-3">
                    <label for="login-password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="login-password" name="password" placeholder="Enter password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign In</button>
            </form>
            <div class="text-center mt-3">
                <small>Don't have an account? <a href="register.php">Sign up</a></small>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../func/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>