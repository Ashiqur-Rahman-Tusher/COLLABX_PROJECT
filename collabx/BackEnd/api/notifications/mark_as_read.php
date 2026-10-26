<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

require_once dirname(__DIR__, 2) . '/config/db.php';

// JSON Input support
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Get values from POST, JSON body, or GET parameters safely
$notification_id = intval($input['notification_id'] ?? $_REQUEST['notification_id'] ?? $_REQUEST['id'] ?? 0);
$user_id = intval($input['user_id'] ?? $_REQUEST['user_id'] ?? 0);

if ($user_id <= 0) {
    echo json_encode([
        "status" => 400,
        "message" => "Invalid User ID."
    ]);
    exit();
}

try {
    if ($notification_id > 0) {
        $query = "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $notification_id, $user_id);
    } else {
        $query = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
    }

    if ($stmt->execute()) {
        echo json_encode([
            "status" => 200,
            "message" => "Notification(s) marked as read."
        ]);
    } else {
        echo json_encode([
            "status" => 500,
            "message" => "Failed to update notification."
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => 500,
        "message" => "Database Error: " . $e->getMessage()
    ]);
}
?>