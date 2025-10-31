<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
session_start();

// debug log (temporary) - helps diagnose why requests may fail (auth/session/cookies)
$dbgPath = __DIR__ . '/../data/order_update_debug.log';
try {
    $dbg = [
        'ts' => date('c'),
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'session_id' => session_id(),
        'session' => $_SESSION,
        'post' => $_POST,
        'raw' => file_get_contents('php://input')
    ];
    @file_put_contents($dbgPath, json_encode($dbg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
} catch (Exception $e) {}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') { echo json_encode(['success'=>false,'error'=>'auth','error_desc'=>'not_logged_in_or_not_admin']); exit; }
include __DIR__ . '/../func/db.php';
$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';
$allowed = ['processing','shipped','delivered'];
if (!$order_id || !in_array($status, $allowed, true)) { echo json_encode(['success'=>false,'error'=>'invalid','error_desc'=>'missing_order_or_bad_status']); exit; }
try {
    // ensure column exists
    try { $colRes = $conn->query("SHOW COLUMNS FROM orders LIKE 'status'"); if (!$colRes || $colRes->num_rows === 0) $conn->query("ALTER TABLE orders ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER order_date"); } catch(Exception $e){}

    $stmt = $conn->prepare("UPDATE orders SET status=? WHERE order_id=?");
    if (!$stmt) throw new Exception('prepare');
    $stmt->bind_param('si', $status, $order_id);
    $stmt->execute();
    $stmt->close();

    // fetch order user
    $uid = 0;
    $s2 = $conn->prepare("SELECT user_id FROM orders WHERE order_id=? LIMIT 1");
    if ($s2) {
        $s2->bind_param('i', $order_id);
        $s2->execute();
        $r = $s2->get_result();
        if ($r && ($row = $r->fetch_assoc())) $uid = (int)$row['user_id'];
        $s2->close();
    }

    // notify customer
    if ($uid) {
        $msg = "Your order #$order_id status has been updated to " . ucfirst($status) . ".";
        $ins = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        if ($ins) { $ins->bind_param('is', $uid, $msg); $ins->execute(); $ins->close(); }
    }

    // Determine badge class
    $class = 'secondary';
    if ($status === 'processing') $class = 'warning';
    if ($status === 'shipped') $class = 'info';
    if ($status === 'delivered') $class = 'success';
    if ($status === 'received') $class = 'success';
    if ($status === 'cancelled' || $status === 'canceled') $class = 'danger';

    echo json_encode(['success'=>true,'status'=> $status, 'status_display'=> ucfirst($status), 'status_class'=> $class]);
} catch (Exception $e) {
    // Log exception
    @file_put_contents($dbgPath, json_encode(['ts'=>date('c'),'error'=> (string)$e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode(['success'=>false,'error'=>'server','error_desc'=>substr((string)$e->getMessage(),0,200)]);
}
