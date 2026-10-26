<?php
require_once '../../config/notification_helper.php';


$status = createNotification(3, 'Test System Notification: Auto trigger is working properly!');

if ($status) {
    echo "Success! New notification created for User ID 3.";
} else {
    echo "Failed to create notification.";
}
?>