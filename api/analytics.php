<?php
// Simple analytics JSON endpoint for Chart.js
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../func/db.php';

$action = $_GET['action'] ?? 'daily_sales';

try {
    if ($action === 'daily_sales') {
        // Last 14 days
        $sql = "SELECT DATE(order_date) AS d, IFNULL(SUM(total_amount),0) AS s FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(order_date) ORDER BY DATE(order_date)";
        $res = $conn->query($sql);
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status'=>'ok','data'=>$rows]);
        exit;
    }

    if ($action === 'weekly_sales') {
        // Last 12 weeks (week start date)
        $sql = "SELECT STR_TO_DATE(CONCAT(YEAR(order_date), ' ', WEEK(order_date,1), ' Monday'), '%X %V %W') AS week_start, IFNULL(SUM(total_amount),0) AS s FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 83 DAY) GROUP BY YEAR(order_date), WEEK(order_date,1) ORDER BY YEAR(order_date), WEEK(order_date,1)";
        $res = $conn->query($sql);
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status'=>'ok','data'=>$rows]);
        exit;
    }

    // Check for order_items table for product-level stats
    $hasOrderItems = false;
    $check = $conn->query("SHOW TABLES LIKE 'order_items'");
    if ($check && $check->num_rows > 0) $hasOrderItems = true;

    if ($action === 'top_products') {
        if (!$hasOrderItems) { echo json_encode(['status'=>'error','message'=>'order_items table not found']); exit; }
        $sql = "SELECT p.id, p.name, SUM(oi.quantity) AS qty FROM order_items oi JOIN product p ON p.id = oi.product_id JOIN orders o ON o.id = oi.order_id WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY p.id ORDER BY qty DESC LIMIT 10";
        $res = $conn->query($sql);
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status'=>'ok','data'=>$rows]);
        exit;
    }

    if ($action === 'revenue_by_category') {
        if (!$hasOrderItems) { echo json_encode(['status'=>'error','message'=>'order_items table not found']); exit; }
        $sql = "SELECT p.category AS category, IFNULL(SUM(oi.quantity * oi.unit_price),0) AS revenue FROM order_items oi JOIN product p ON p.id = oi.product_id JOIN orders o ON o.id = oi.order_id WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY p.category ORDER BY revenue DESC";
        $res = $conn->query($sql);
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode(['status'=>'ok','data'=>$rows]);
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'unknown action']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

?>
