<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Only logged in admin can perform approval actions
    if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
        json_response(403, 'Access denied. Shudhu Admin ei action nite parben.');
    }

    // Support both FormData and JSON input
    $inputRaw = file_get_contents('php://input');
    $jsonInput = json_decode($inputRaw, true) ?? [];

    $project_id = intval($_POST['project_id'] ?? $jsonInput['project_id'] ?? 0);
    $action = trim($_POST['action'] ?? $jsonInput['action'] ?? ''); 

    // Handle string variations ('approved' -> 'approve', 'rejected' -> 'reject')
    if ($action === 'approved') $action = 'approve';
    if ($action === 'rejected') $action = 'reject';

    if ($project_id <= 0 || !in_array($action, ['approve', 'reject'])) {
        json_response(400, 'Invalid request data.');
    }

    $new_approval_status = ($action === 'approve') ? 'approved' : 'rejected';
    $new_status = ($action === 'approve') ? 'open' : 'pending';

    // 1. Update project approval status in DB
    $stmt = $conn->prepare("UPDATE projects SET approval_status = ?, status = ? WHERE project_id = ?");
    $stmt->bind_param("ssi", $new_approval_status, $new_status, $project_id);

    if ($stmt->execute()) {
        // 2. Fetch project details & owner ID for Notification
        $p_stmt = $conn->prepare("SELECT title, owner_id FROM projects WHERE project_id = ?");
        $p_stmt->bind_param("i", $project_id);
        $p_stmt->execute();
        $p_res = $p_stmt->get_result()->fetch_assoc();

        if ($p_res && !empty($p_res['owner_id'])) {
            $owner_id = $p_res['owner_id'];
            $title = $p_res['title'];
            $msg = ($action === 'approve') 
                ? "Congratulations! Your project '{$title}' has been approved by Admin and is now live." 
                : "Your project '{$title}' proposal has been rejected by Admin.";

            // 3. Insert notification into DB for the Idea Owner
            $n_stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
            $n_stmt->bind_param("is", $owner_id, $msg);
            $n_stmt->execute();
        }

        json_response(200, "Project status successfully updated to {$new_approval_status}.");
    } else {
        json_response(500, 'Failed to update project status.');
    }
} else {
    json_response(405, 'Method Not Allowed');
}
?>