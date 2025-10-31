<?php
// Serve prescription image from database for a given order_id
session_start();
include __DIR__ . '/../func/db.php';

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { http_response_code(404); exit; }

// Permissions: allow admins or the owner of the order to view
$roleVal = (string)($_SESSION['role'] ?? '');
$isAdmin = (bool)preg_match('/\badmin\b/i', $roleVal);
if (!isset($_SESSION['user_id'])) { http_response_code(403); exit; }

try {
    $stmt = $conn->prepare("SELECT prescription_blob, prescription_mime, user_id FROM orders WHERE order_id = ? LIMIT 1");
    if (!$stmt) { http_response_code(404); exit; }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (! $row) { http_response_code(404); exit; }

    $owner = (int)$row['user_id'];
    if (! $isAdmin && $owner !== (int)($_SESSION['user_id'] ?? 0)) { http_response_code(403); exit; }

    if (empty($row['prescription_blob'])) { http_response_code(404); exit; }
    $mime = !empty($row['prescription_mime']) ? $row['prescription_mime'] : 'application/octet-stream';
    header('Content-Type: ' . $mime);
    // output the blob directly
    echo $row['prescription_blob'];
    exit;
} catch (Exception $e) {
    http_response_code(500);
    exit;
}

?>
