<?php
session_start();
include __DIR__ . '/../func/db.php';  // MySQLi connection ($conn)

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

// Redirect if cart is empty
if (empty($_SESSION['cart'])) {
    header('Location: ../pages/cart.php');
    exit;
}

$cart = $_SESSION['cart'];
$total_price = 0;
foreach ($cart as $item) {
    $total_price += $item['price'] * $item['quantity'];
}

// Determine if any cart item requires a prescription AND does not already have one uploaded at add-to-cart time.
$prescription_required = false;
$missing_prescription_items = [];
try {
    $pids = array_map('intval', array_keys($cart));
    if (!empty($pids)) {
        $in = implode(',', $pids);
        $q = $conn->query("SELECT Product_id, prescription_needed FROM product WHERE Product_id IN (" . $in . ")");
        if ($q && $q->num_rows) {
            while ($r = $q->fetch_assoc()) {
                $pid = (int)$r['Product_id'];
                if (!empty($r['prescription_needed'])) {
                    // require a prescription only if the cart item does not already have one attached
                    if (empty($cart[$pid]['prescription_image'])) {
                        $missing_prescription_items[] = $pid;
                    }
                }
            }
        }
    }
} catch (Exception $e) {}

if (!empty($missing_prescription_items)) $prescription_required = true;

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $address = trim($_POST['address']);
    $payment_method = trim($_POST['payment_method']); // e.g. "Cash on Delivery" or "Card"
    // handle prescription upload if required
    $prescription_file = null;
    if ($prescription_required) {
        if (!isset($_FILES['prescription_image']) || $_FILES['prescription_image']['error'] !== UPLOAD_ERR_OK) {
            $message = "⚠️ Prescription image is required for some items in your cart.";
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['prescription_image']['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (!array_key_exists($mime, $allowed)) {
                $message = 'Unsupported prescription image type.';
            } else {
                $ext = $allowed[$mime];
                $base = time() . '_' . (int)$_SESSION['user_id'] . '_' . bin2hex(random_bytes(6));
                $name = $base . '.' . $ext;
                $target_dir = __DIR__ . '/../assets/prescriptions/';
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $target = $target_dir . $name;
                if (move_uploaded_file($_FILES['prescription_image']['tmp_name'], $target)) {
                    $prescription_file = $name;
                } else {
                    $message = 'Failed to save prescription image.';
                }
            }
        }
    }
    if (!empty($address) && !empty($payment_method)) {
        // Insert into orders table
        $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, address, payment_method, order_date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("idss", $_SESSION['user_id'], $total_price, $address, $payment_method);

        if ($stmt->execute()) {
            $order_id = $stmt->insert_id;

            // Insert each cart item into order_items table
            $item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($cart as $product_id => $item) {
                $item_stmt->bind_param("iiid", $order_id, $product_id, $item['quantity'], $item['price']);
                $item_stmt->execute();

                // OPTIONAL: Decrease stock quantity
                $stock_stmt = $conn->prepare("UPDATE product SET stck_qty = stck_qty - ? WHERE Product_id = ?");
                $stock_stmt->bind_param("ii", $item['quantity'], $product_id);
                $stock_stmt->execute();
                $stock_stmt->close();
            }
            $item_stmt->close();

            // Build a compact items description for notifications before we clear the cart
            $items_desc = [];
            foreach ($cart as $pid => $it) {
                $iname = trim((string)($it['name'] ?? 'product'));
                $qty = (int)($it['quantity'] ?? 0);
                $items_desc[] = $qty . 'x ' . $iname;
            }
            $items_summary = implode(', ', $items_desc);

            // If a prescription file was uploaded, attempt to store filename on the order
            try {
                $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'prescription_image'");
                if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE orders ADD COLUMN prescription_image VARCHAR(255) NULL AFTER order_date");
            } catch (Exception $e) {}
            // If a prescription file was uploaded at checkout (or attached in cart as filename), persist
            // the filename to the legacy `prescription_image` column so the UI can link to the saved file.
            if (!empty($prescription_file)) {
                try {
                    $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'prescription_image'");
                    if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE orders ADD COLUMN prescription_image VARCHAR(255) NULL AFTER order_date");
                } catch (Exception $e) {}
                $u = $conn->prepare("UPDATE orders SET prescription_image = ? WHERE order_id = ?");
                if ($u) {
                    $u->bind_param('si', $prescription_file, $order_id);
                    $u->execute();
                    $u->close();
                }
            } else {
                // check for any cart item that used the legacy 'prescription_image' filename and persist the first one
                $found = null;
                foreach ($cart as $it) {
                    if (!empty($it['prescription_image'])) { $found = $it['prescription_image']; break; }
                }
                if ($found) {
                    try {
                        $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'prescription_image'");
                        if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE orders ADD COLUMN prescription_image VARCHAR(255) NULL AFTER order_date");
                    } catch (Exception $e) {}
                    $u = $conn->prepare("UPDATE orders SET prescription_image = ? WHERE order_id = ?");
                    if ($u) { $u->bind_param('si', $found, $order_id); $u->execute(); $u->close(); }
                }
            }

            // Clear cart after we captured the items
            unset($_SESSION['cart']);
            $message = "✅ Order placed successfully! Your order ID is #$order_id.";

            //notification
            $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notif_message = "Your order #$order_id has been placed successfully! Items: " . $items_summary;
            $notif_stmt->bind_param("is", $_SESSION['user_id'], $notif_message);
            $notif_stmt->execute();
            $notif_stmt->close();

            // Notify admins: include customer username/ID and item summary
            $admins_result = $conn->query("SELECT Customer_id FROM customer WHERE role = 'admin'");
            if ($admins_result && $admins_result->num_rows > 0) {
                while ($admin_row = $admins_result->fetch_assoc()) {
                    $admin_id = $admin_row['Customer_id'];
                    $uname = isset($_SESSION['username']) ? $_SESSION['username'] : ('ID ' . (int)$_SESSION['user_id']);
                    // For admin notifications show the order id after the generic phrase
                    $admin_message = "An order has been placed #" . $order_id;
                    $admin_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    if ($admin_notif) {
                        $admin_notif->bind_param("is", $admin_id, $admin_message);
                        $admin_notif->execute();
                        $admin_notif->close();
                    }
                }
            }

            header("Location: ../pages/index.php");
            exit;
        } else {
            $message = "❌ Failed to place order. Please try again.";
        }
    } else {
        $message = "⚠️ Please fill out all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Checkout - PharmaSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
</head>
<body>
    <?php include __DIR__ . '/../func/header.php'; ?>

    <div class="container my-4">
        <h1 class="text-primary mb-3">Checkout</h1>

        <?php if (!empty($message)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!isset($order_id)): ?>
        <!-- Order summary -->
        <div class="card mb-4">
            <div class="card-header">Order Summary</div>
            <div class="card-body">
                <ul class="list-group mb-3">
                    <?php foreach ($cart as $item): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <div>
                                <strong><?= htmlspecialchars($item['name']) ?></strong> x <?= $item['quantity'] ?>
                            </div>
                            <span>₱<?= number_format($item['price'] * $item['quantity'], 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <strong>Total</strong>
                        <span>₱<?= number_format($total_price, 2) ?></span>
                    </li>
                </ul>

                <!-- Checkout Form -->
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="address" class="form-label">Shipping Address</label>
                        <textarea name="address" id="address" class="form-control" required style="resize: none; height: 30px;"></textarea>

                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-select" required>
                            <option value="">Select...</option>
                            <option value="Cash on Delivery">Cash on Delivery</option>
                            <option value="Card">Credit/Debit Card</option>
                        </select>
                    </div>
                    <?php if (!empty($missing_prescription_items)): ?>
                        <div class="alert alert-warning">
                            <strong>Prescription required</strong>
                            <div class="small">The following items need a prescription before checkout:</div>
                            <ul>
                                <?php foreach ($missing_prescription_items as $mpid): ?>
                                    <li><?= htmlspecialchars($cart[$mpid]['name'] ?? ('Product ' . (int)$mpid)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload prescription image</label>
                            <input type="file" name="prescription_image" accept="image/*" class="form-control" required>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-success w-100">Place Order</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <a href="../pages/cart.php" class="btn btn-secondary">← Back to Cart</a>
    </div>
</body>
</html>