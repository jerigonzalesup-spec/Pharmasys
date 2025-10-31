<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
// simple endpoint that returns notifications newer than since_id
$since = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
$path = __DIR__ . '/../func/db.php';
if (!file_exists($path)) { echo json_encode([]); exit; }
include $path;
if (!isset($conn) || !$conn) { echo json_encode([]); exit; }
try {
    $stmt = mysqli_prepare($conn, "SELECT n.id, n.user_id, n.message, n.action, n.product_id, n.product_name, COALESCE(n.actor_username, a.username) AS actor_username, a.profile_image AS actor_profile, n.created_at FROM notifications n LEFT JOIN admin a ON n.user_id = a.admin_id WHERE n.id > ? ORDER BY n.created_at ASC LIMIT 50");
    mysqli_stmt_bind_param($stmt, "i", $since);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            // compute profile image URL (prefer profile folder, fall back to generic image)
            $profile = $row['actor_profile'] ?? '';
            $profile_url = '';
            if ($profile) {
                $disk = __DIR__ . '/../assets/profile/' . $profile;
                if (is_file($disk)) {
                    $profile_url = '../assets/profile/' . rawurlencode($profile);
                }
            }
            if (!$profile_url) $profile_url = '../assets/image/default.jpg';
            $row['actor_profile_url'] = $profile_url;
            $out[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    echo json_encode($out);
} catch (Exception $e) {
    echo json_encode([]);
}

?>