<?php
// returns JSON array of items for a given order_id
header('Content-Type: application/json; charset=utf-8');
session_start();
// allow role variants like 'Admin' or 'ADMIN'
$roleVal = (string)($_SESSION['role'] ?? '');
$isAdmin = (bool)preg_match('/\badmin\b/i', $roleVal);
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
// allow access if admin, otherwise verify order owner matches session user
$currentUserId = (int)$_SESSION['user_id'];
include __DIR__ . '/../func/db.php';
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { http_response_code(400); echo json_encode(['error'=>'missing_order_id']); exit; }
$out = [];
try {
    // Verify owner if not admin
    if (! $isAdmin) {
        $ownStmt = $conn->prepare("SELECT user_id FROM orders WHERE order_id = ? LIMIT 1");
        if ($ownStmt) {
            $ownStmt->bind_param('i', $order_id);
            $ownStmt->execute();
            $resOwn = $ownStmt->get_result();
            $rowOwn = $resOwn ? $resOwn->fetch_assoc() : null;
            $ownStmt->close();
            if (!$rowOwn || (int)$rowOwn['user_id'] !== $currentUserId) {
                http_response_code(403);
                echo json_encode(['error' => 'forbidden']);
                exit;
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'db_prepare_failed']);
            exit;
        }
    }

    // Return product_name as 'product_name' (JS expects this key) and include order-level prescription presence (legacy filename)
    $stmt = $conn->prepare("SELECT oi.item_id, oi.product_id, oi.quantity, oi.price, COALESCE(p.Product_name,'') AS product_name, (COALESCE(o.prescription_image,'') <> '') AS has_prescription, COALESCE(o.prescription_image,'') AS prescription_image FROM order_items oi LEFT JOIN product p ON oi.product_id = p.Product_id LEFT JOIN orders o ON oi.order_id = o.order_id WHERE oi.order_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) while ($r = $res->fetch_assoc()) $out[] = $r;
        $stmt->close();
    } else {
        // prepared statement failed
        http_response_code(500);
        echo json_encode(['error' => 'db_prepare_failed']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'exception']);
    exit;
}
echo json_encode($out);
