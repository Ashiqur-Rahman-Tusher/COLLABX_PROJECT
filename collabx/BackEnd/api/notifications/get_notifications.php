<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

require_once dirname(__DIR__, 2) . '/config/db.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    echo json_encode([
        "status" => 400,
        "message" => "Invalid user_id provided."
    ]);
    exit();
}

try {
    // Query Updated: Added title, type, and link columns
    $query = "SELECT notification_id, title, message, type, link, is_read, created_at 
              FROM notifications 
              WHERE user_id = ? 
              ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $notifications = [];
    $unread_count = 0;

    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) {
            $unread_count++;
        }
    }

    echo json_encode([
        "status" => 200,
        "unread_count" => $unread_count,
        "notifications" => $notifications
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => 500,
        "message" => "Database Error: " . $e->getMessage()
    ]);
}
?>