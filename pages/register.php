<?php
session_start();
include __DIR__ . '/../func/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');

    // Password rules
    $minLen = 8; $maxLen = 30;
    if ($username !== '' && $password !== '') {
        if (strlen($password) < $minLen || strlen($password) > $maxLen) {
            $error = "Password must be between {$minLen} and {$maxLen} characters.";
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Check existing username
            $stmt = $conn->prepare("SELECT Customer_id FROM customer WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows > 0) {
                $error = 'Username already exists. Please choose a different username.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $insert = $conn->prepare("INSERT INTO customer (username, password, registered_date, role) VALUES (?, ?, NOW(), 'customer')");
                $insert->bind_param('ss', $username, $hashedPassword);
                if ($insert->execute()) {
                    // Registration success. Redirect to login.
                    header("Location: login.php");
                    exit();
                } else {
                    error_log('Registration error: ' . $conn->error);
                    $error = 'Registration failed. Please try again later.';
                }
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
    <title>Sign Up</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>
    <?php include __DIR__ . '/../func/header.php'; ?>

    <main>
        <div class="container mt-5" style="max-width: 400px;">
            <h2 class="mb-4 text-center" style="color: blue;">Sign Up</h2>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form action="register.php" method="POST">
                <div class="mb-3">
                    <label for="reg-username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="reg-username" name="username" placeholder="Enter username" required>
                </div>
                <div class="mb-3">
                    <label for="reg-password" class="form-label">Password</label>
                                        <div class="d-flex align-items-center">
                                            <input type="password" class="form-control" id="reg-password" name="password" placeholder="Enter password" required>
                                            <span id="reg-pw-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                                            <small id="reg-pw-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                                        </div>
                </div>
                <div class="mb-3">
                    <label for="reg-password-confirm" class="form-label">Confirm Password</label>
                                        <div class="d-flex align-items-center">
                                            <input type="password" class="form-control" id="reg-password-confirm" name="password_confirm" placeholder="Repeat password" required>
                                            <span id="reg-pwconf-ind" class="ms-2" style="width:12px;height:12px;border-radius:50%;display:inline-block;background:#ccc;"></span>
                                            <small id="reg-pwconf-msg" class="ms-2 text-muted" style="font-size:0.9rem"></small>
                                        </div>
                </div>
                                <script>
                                    (function(){
                                        var pw = document.getElementById('reg-password');
                                        var pwc = document.getElementById('reg-password-confirm');
                                        var ind = document.getElementById('reg-pw-ind');
                                        var indc = document.getElementById('reg-pwconf-ind');
                                        var msg = document.getElementById('reg-pw-msg');
                                        var msgc = document.getElementById('reg-pwconf-msg');
                                        if (!pw || !pwc) return;
                                        var min = 8, max = 30;
                                        function update(){
                                            var v = pw.value || '';
                                            var vc = pwc.value || '';
                                            var okLen = v.length >= min && v.length <= max;
                                            if (!okLen){
                                                ind.style.background = 'red';
                                                msg.textContent = 'Password length: '+min+'-'+max;
                                            } else {
                                                msg.textContent = '';
                                            }
                                            // confirmation
                                            if (v.length && vc.length){
                                                if (v === vc && okLen){
                                                    ind.style.background = 'green'; indc.style.background='green'; msg.textContent='Good'; msgc.textContent='Matches';
                                                } else {
                                                    ind.style.background = okLen ? '#ffa500' : 'red';
                                                    indc.style.background = 'red';
                                                    msgc.textContent = 'Does not match';
                                                }
                                            } else {
                                                // only length status
                                                if (okLen) { ind.style.background='green'; } else { ind.style.background='red'; }
                                                indc.style.background='#ccc'; msgc.textContent='';
                                            }
                                        }
                                        pw.addEventListener('input', update);
                                        pwc.addEventListener('input', update);
                                        // initial
                                        update();
                                    })();
                                </script>
                <button type="submit" class="btn btn-primary w-100">Sign Up</button>
            </form>
            <div class="text-center mt-3">
                <small>Already have an account? <a href="login.php">Log in</a></small>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../func/footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>