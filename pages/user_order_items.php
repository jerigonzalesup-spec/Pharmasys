<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['user_id'])) { echo json_encode(['error'=>'auth']); exit; }
include __DIR__ . '/../func/db.php';
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { echo json_encode(['error'=>'invalid']); exit; }

// verify ownership
$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT user_id FROM orders WHERE order_id = ? LIMIT 1");
if (!$stmt) { echo json_encode(['error'=>'server']); exit; }
$stmt->bind_param('i', $order_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$row || (int)$row['user_id'] !== $uid) { echo json_encode(['error'=>'forbidden']); exit; }

$out = [];
try {
    // order_items uses column `price` when created at checkout
    $stmt2 = $conn->prepare("SELECT oi.item_id, oi.product_id, oi.quantity, oi.price AS price, COALESCE(p.Product_name, '') AS product_name, COALESCE(p.image,'') AS image FROM order_items oi LEFT JOIN product p ON oi.product_id = p.Product_id WHERE oi.order_id = ?");
    if ($stmt2) {
        $stmt2->bind_param('i', $order_id);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        if ($r2) while ($rec = $r2->fetch_assoc()) $out[] = $rec;
        $stmt2->close();
    }
} catch (Exception $e) {}

echo json_encode($out);
