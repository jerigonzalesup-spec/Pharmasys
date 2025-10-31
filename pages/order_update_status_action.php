<?php
session_start();
// simple server-side action to update order status via POST and redirect back
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    // not authorized - redirect to login
    header('Location: /Pharma_Sys/pages/login.php');
    exit;
}
include __DIR__ . '/../func/db.php';

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$allowed = ['processing','shipped','delivered','cancelled'];

$redirect = $_SERVER['HTTP_REFERER'] ?? '/Pharma_Sys/pages/orders.php';

if (!$order_id || !in_array($status, $allowed, true)) {
    $_SESSION['flash_error'] = 'Invalid order or status.';
    header('Location: ' . $redirect);
    exit;
}

// ensure status column exists (safe)
try {
    $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER order_date");
    }
} catch (Exception $e) {}

$stmt = $conn->prepare("UPDATE orders SET status=? WHERE order_id=?");
if ($stmt) {
    $stmt->bind_param('si', $status, $order_id);
    $stmt->execute();
    $stmt->close();
}

// notify customer
$uid = 0;
$s2 = $conn->prepare("SELECT user_id FROM orders WHERE order_id=? LIMIT 1");
if ($s2) {
    $s2->bind_param('i', $order_id);
    $s2->execute();
    $r = $s2->get_result();
    if ($r && ($row = $r->fetch_assoc())) $uid = (int)$row['user_id'];
    $s2->close();
}
if ($uid) {
    $msg = "Your order #$order_id status has been updated to " . ucfirst($status) . ".";
    $ins = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    if ($ins) { $ins->bind_param('is', $uid, $msg); $ins->execute(); $ins->close(); }
}

$_SESSION['flash_success'] = 'Order status updated.';
header('Location: ' . $redirect);
exit;
