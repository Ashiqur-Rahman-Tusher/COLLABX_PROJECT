<?php
require_once __DIR__ . '/db.php';

if (!function_exists('createNotification')) {
    function createNotification($user_id, $message) {
        global $conn;
        
        if (empty($user_id) || empty($message)) {
            return false;
        }

        try {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $stmt->bind_param("is", $user_id, $message);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Notification Creation Error: " . $e->getMessage());
            return false;
        }
    }
}
?>