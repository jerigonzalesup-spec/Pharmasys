<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /Pharma_Sys/pages/login.php');
    exit;
}
include __DIR__ . '/../func/db.php';

$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$allowed = ['cancel','received'];
$redirect = $_SERVER['HTTP_REFERER'] ?? '/Pharma_Sys/pages/profile.php?tab=orders';

if (!$order_id || !in_array($action, $allowed, true)) {
    $_SESSION['flash_error'] = 'Invalid request.';
    header('Location: ' . $redirect);
    exit;
}

// verify ownership
$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT order_id, user_id, COALESCE(status,'pending') AS status FROM orders WHERE order_id = ? LIMIT 1");
if (!$stmt) { $_SESSION['flash_error'] = 'Server error.'; header('Location: ' . $redirect); exit; }
$stmt->bind_param('i', $order_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$row || (int)$row['user_id'] !== $uid) {
    $_SESSION['flash_error'] = 'You cannot modify this order.';
    header('Location: ' . $redirect);
    exit;
}

$current = strtolower((string)($row['status'] ?? 'pending'));

if ($action === 'cancel') {
    // only allow cancel when pending
    if ($current !== 'pending') {
        $_SESSION['flash_error'] = 'Order cannot be cancelled at this stage.';
        header('Location: ' . $redirect);
        exit;
    }
    $upd = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE order_id = ?");
    if ($upd) { $upd->bind_param('i', $order_id); $upd->execute(); $upd->close(); }
    // notify admins
    $msg = "Order #$order_id has been cancelled by the customer.";
    $r = $conn->query("SELECT Customer_id FROM customer WHERE role = 'admin'");
    if ($r && $r->num_rows) {
        while ($a = $r->fetch_assoc()) {
            $aid = (int)$a['Customer_id'];
            $ins = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            if ($ins) { $ins->bind_param('is', $aid, $msg); $ins->execute(); $ins->close(); }
        }
    }
    $_SESSION['flash_success'] = 'Order cancelled.';
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'received') {
    // only allow received when delivered
    if ($current !== 'delivered') {
        $_SESSION['flash_error'] = 'You can only mark an order as received when it is delivered.';
        header('Location: ' . $redirect);
        exit;
    }
    $upd = $conn->prepare("UPDATE orders SET status = 'received' WHERE order_id = ?");
    if ($upd) { $upd->bind_param('i', $order_id); $upd->execute(); $upd->close(); }
    // notify admins
    $msg = "Order #$order_id has been marked as received by the customer.";
    $r = $conn->query("SELECT Customer_id FROM customer WHERE role = 'admin'");
    if ($r && $r->num_rows) {
        while ($a = $r->fetch_assoc()) {
            $aid = (int)$a['Customer_id'];
            $ins = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            if ($ins) { $ins->bind_param('is', $aid, $msg); $ins->execute(); $ins->close(); }
        }
    }
    $_SESSION['flash_success'] = 'Order marked as received.';
    header('Location: ' . $redirect);
    exit;
}

// fallback
$_SESSION['flash_error'] = 'Unknown action.';
header('Location: ' . $redirect);
exit;
