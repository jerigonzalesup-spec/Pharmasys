<?php
session_start();
include __DIR__ . '/../func/db.php';  // Now uses MySQLi connection ($conn)

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

// Handle adding to cart (from index.php ?id=)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM product WHERE Product_id = ? AND stck_qty > 0");
    $stmt->bind_param("i", $product_id);  // "i" means the parameter is an integer
    $stmt->execute();
    $result = $stmt->get_result();  // Get the result set
    $product = $result->fetch_assoc();  // Fetch as associative array
    
    if ($product) {
        // If this product requires a prescription, require the user to upload one before adding.
        $requires_prescription = !empty($product['prescription_needed']);

        // initialize cart if missing
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        $stock = (int)$product['stck_qty'];

        // If prescription is required, handle upload flow
        if ($requires_prescription) {
            // Check if this request is a POST attempting to add with an uploaded prescription
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_presc_product_id']) && (int)$_POST['add_presc_product_id'] === $product_id) {
                // Validate file
                if (!isset($_FILES['prescription_image']) || $_FILES['prescription_image']['error'] !== UPLOAD_ERR_OK) {
                    $message = 'Please upload a prescription image to add this item to your cart.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['prescription_image']['tmp_name']);
                    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    if (!array_key_exists($mime, $allowed)) {
                        $message = 'Unsupported prescription image type. Allowed: JPG, PNG, GIF, WEBP.';
                    } else {
                        $ext = $allowed[$mime];
                        $base = time() . '_' . (int)$_SESSION['user_id'] . '_' . bin2hex(random_bytes(6));
                        $name = $base . '.' . $ext;
                        $target_dir = __DIR__ . '/../assets/prescriptions/';
                        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                        $target = $target_dir . $name;
                        // Read uploaded file into memory and store in session as base64 + mime
                        $data = file_get_contents($_FILES['prescription_image']['tmp_name']);
                        if ($data !== false) {
                            // Save uploaded file to disk (legacy behavior) and store filename in session
                            $img = isset($product['image']) && $product['image'] !== '' ? $product['image'] : null;
                            $target_dir = __DIR__ . '/../assets/prescriptions/';
                            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                            if (move_uploaded_file($_FILES['prescription_image']['tmp_name'], $target)) {
                                $_SESSION['cart'][$product_id] = [
                                    'name' => $product['Product_name'],
                                    'price' => $product['price'],
                                    'quantity' => 1,
                                    'stock' => $stock,
                                    'image' => $img,
                                    // legacy filename used by checkout and orders pages
                                    'prescription_image' => $name
                                ];
                                // Redirect to avoid double submit and show cart
                                header('Location: /Pharma_Sys/pages/cart.php');
                                exit;
                            } else {
                                $message = 'Failed to save uploaded prescription image. Please try again.';
                            }
                        } else {
                            $message = 'Failed to read uploaded prescription image. Please try again.';
                        }
                    }
                }
            } else {
                                // Render a full page (styled) asking the user to upload a prescription image
                                // so it matches the site's look-and-feel.
                                ?>
                                <!doctype html>
                                <html lang="en">
                                <head>
                                    <meta charset="utf-8">
                                    <meta name="viewport" content="width=device-width,initial-scale=1">
                                    <title>Prescription required - PharmaSys</title>
                                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                                    <link rel="stylesheet" href="/Pharma_Sys/assets/css/design.css">
                                </head>
                                <body style="overflow:hidden;">
                                <?php include __DIR__ . '/../func/header.php'; ?>
                                <main class="container d-flex align-items-center justify-content-center" style="height: calc(100vh - 140px);">
                                    <div class="row justify-content-center">
                                        <div class="col-md-8">
                                            <div class="card shadow-sm">
                                                <div class="card-body">
                                                    <h4 class="card-title">Prescription required</h4>
                                                    <p class="card-text">The product <strong><?= htmlspecialchars($product['Product_name']) ?></strong> requires a prescription before it can be added to your cart. Please upload a clear image of your prescription.</p>
                                                    <?php if (!empty($message)): ?><div class="alert alert-warning"><?= htmlspecialchars($message) ?></div><?php endif; ?>
                                                    <form method="post" enctype="multipart/form-data">
                                                            <input type="hidden" name="add_presc_product_id" value="<?= (int)$product_id ?>">
                                                            <div class="mb-3">
                                                                    <label class="form-label">Prescription image</label>
                                                                    <input type="file" name="prescription_image" accept="image/*" class="form-control" required>
                                                            </div>
                                                            <div class="d-flex gap-2">
                                                                    <button type="submit" class="btn btn-success">Upload & Add to Cart</button>
                                                                    <a href="/Pharma_Sys/pages/medicine_search.php" class="btn btn-outline-secondary">Cancel</a>
                                                            </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </main>
                                <?php include __DIR__ . '/../func/footer.php'; ?>
                                <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
                                </body>
                                </html>
                                <?php
                                exit;
            }
        } else {
            // Standard add-to-cart behavior for non-prescription items
            if (isset($_SESSION['cart'][$product_id])) {
                if ($_SESSION['cart'][$product_id]['quantity'] < $stock) {
                    $_SESSION['cart'][$product_id]['quantity'] += 1;
                    $message = 'Item added to cart!';
                } else {
                    $message = 'You’ve reached the maximum stock available.';
                }
            } else {
                // store image filename (if available) so cart can show the product image
                $img = isset($product['image']) && $product['image'] !== '' ? $product['image'] : null;
                $_SESSION['cart'][$product_id] = [
                    'name' => $product['Product_name'],
                    'price' => $product['price'],
                    'quantity' => 1,
                    'stock' => $stock, // optional, store stock for later checks
                    'image' => $img
                ];
                $message = 'Item added to cart!';
            }
        }
    } else {
        $message = 'Product not available.';
    }
}

// Handle updates/removals
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update'])) {
        foreach ($_POST['quantity'] as $id => $qty) {
    $qty = (int)$qty;

    // Fetch current stock from DB
    $stmt = $conn->prepare("SELECT stck_qty FROM product WHERE Product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($stock);
    $stmt->fetch();
    $stmt->close();

    if ($qty > 0 && isset($_SESSION['cart'][$id])) {
        if ($qty <= $stock) {
            $_SESSION['cart'][$id]['quantity'] = $qty;
        } else {
            $_SESSION['cart'][$id]['quantity'] = $stock;
            $message = "Quantity adjusted to available stock ($stock).";
        }
    } else {
        unset($_SESSION['cart'][$id]);
    }

        }
        $message = 'Cart updated!';
    } elseif (isset($_POST['remove_id'])) {
        $remove_id = (int)$_POST['remove_id'];
        unset($_SESSION['cart'][$remove_id]);
        $message = 'Item removed!';
    }
}

// After all POST updates and removals are done
$cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];

// Ensure cart items reflect the latest product images stored in DB.
// We'll fetch images for all cart product IDs in a single query and update session/cart entries.
if (!empty($cart) && isset($conn)) {
    $ids = array_map('intval', array_keys($cart));
    if (!empty($ids)) {
        // build a safe IN list from integers
        $in = implode(',', $ids);
        $sql = "SELECT Product_id, image FROM product WHERE Product_id IN ($in)";
        $res = $conn->query($sql);
        $dbImages = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $dbImages[(int)$r['Product_id']] = $r['image'];
            }
        }
        foreach ($ids as $pid) {
            if (isset($dbImages[$pid]) && $dbImages[$pid] !== '') {
                // update session and local cart to ensure rendering uses DB image
                $_SESSION['cart'][$pid]['image'] = $dbImages[$pid];
                $cart[$pid]['image'] = $dbImages[$pid];
            }
        }
    }
}

$total_items = 0;
$total_price = 0;

if (!empty($cart)) {
    foreach ($cart as $item) {
        $total_items += $item['quantity'];
        $total_price += $item['price'] * $item['quantity'];
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - PharmaSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/design.css">
    <style>
        .cart-table { margin-top: 20px; }
        .cart-table img { width: 50px; height: 50px; object-fit: cover; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../func/header.php'; ?>

    <div class="container my-4">
        <h1 class="text-primary mb-3">Shopping Cart</h1>
        
        <?php if (isset($message)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

                <?php if (empty($cart)): ?>
                        <div class="text-center py-4">
                                <p>Your cart is empty. <a href="../pages/index.php">Shop now</a>.</p>
                        </div>
                <?php else: ?>
                        <form method="POST">
                            <div class="row g-3">
                                <?php foreach ($cart as $id => $item): ?>
                                    <div class="col-12">
                                        <div class="card shadow-sm">
                                            <div class="card-body" style="max-height:80vh; overflow:auto;">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3" style="width:96px;height:96px;flex:0 0 96px;">
                                                        <?php
                                                            // Use default.jpg which exists in assets (fallback). Some pages used default.jpg
                                                            // so keep the same fallback to avoid a broken default.png reference.
                                                            $imgUrl = '/Pharma_Sys/assets/image/default.jpg';
                                                            if (!empty($item['image'])) {
                                                                $disk = __DIR__ . '/../assets/image/' . $item['image'];
                                                                if (is_file($disk)) {
                                                                    $imgUrl = '/Pharma_Sys/assets/image/' . rawurlencode($item['image']);
                                                                }
                                                            }
                                                        ?>
                                                        <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width:96px;height:96px;object-fit:cover;border-radius:8px;">
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h5 class="mb-1 text-primary"><?= htmlspecialchars($item['name']) ?></h5>
                                                        <div class="text-muted small">Price: ₱<?= number_format($item['price'],2) ?> • Subtotal: ₱<?= number_format($item['price'] * $item['quantity'],2) ?></div>
                                                        <div class="mt-2 d-flex align-items-center gap-2">
                                                            <label class="m-0 small">Qty</label>
                                                            <input type="number" name="quantity[<?= $id ?>]" value="<?= $item['quantity'] ?>" min="1" class="form-control form-control-sm" style="width:80px;">
                                                            <button type="submit" name="remove_id" value="<?= $id ?>" class="btn btn-sm btn-danger ms-3" onclick="return confirm('Remove?')">Remove</button>
                                                        </div>
                                                        <?php if (!empty($item['prescription_image'])): ?>
                                                            <div class="mt-2">
                                                                <small class="text-muted">Prescription attached</small>
                                                                <div class="mt-1">
                                                                    <a href="/Pharma_Sys/assets/prescriptions/<?= rawurlencode($item['prescription_image']) ?>" target="_blank">
                                                                        <img src="/Pharma_Sys/assets/prescriptions/<?= rawurlencode($item['prescription_image']) ?>" alt="Prescription" style="height:48px;object-fit:cover;border-radius:6px;border:1px solid #eee">
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-end ms-3" style="min-width:160px;">
                                                        <div class="mb-2">Total</div>
                                                        <div class="h5">₱<?= number_format($item['price'] * $item['quantity'],2) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <div>
                                    <button type="submit" name="update" class="btn btn-secondary">Update Cart</button>
                                    <a href="../pages/medicine_search.php" class="btn btn-primary ms-2">Continue Shopping</a>
                                </div>
                                <div class="text-end">
                                    <h5 class="mb-1">Total: <?= $total_items ?> items</h5>
                                    <h4 class="text-primary">₱<?= number_format($total_price, 2) ?></h4>
                                    <a href="../pages/checkout.php" class="btn btn-success mt-2">Checkout</a>
                                </div>
                            </div>
                        </form>
                <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>