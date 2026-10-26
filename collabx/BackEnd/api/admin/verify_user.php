<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Fetch all users for verification
if ($method === 'GET') {
    try {
        $users = [];
        $sql = "SELECT user_id, name, email, primary_role AS role, 
                IFNULL(verification_status, 'pending') AS verification_status, 
                nid_passport_no, created_at 
                FROM users 
                ORDER BY user_id DESC";

        if ($db instanceof mysqli) {
            $res = $db->query($sql);
            while ($row = $res->fetch_assoc()) {
                $users[] = $row;
            }
        } else if ($db instanceof PDO) {
            $users = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => 200, 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// 2. POST: Approve or Reject User Verification
else if ($method === 'POST') {
    $user_id = intval($_POST['user_id'] ?? 0);
    $action = $_POST['action'] ?? 'approve'; // 'approve' or 'reject'
    $status_val = ($action === 'approve') ? 'verified' : 'rejected';

    if ($user_id <= 0) {
        echo json_encode(['status' => 400, 'message' => 'User ID required']);
        exit;
    }

    try {
        if ($db instanceof mysqli) {
            $stmt = $db->prepare("UPDATE users SET verification_status = ? WHERE user_id = ?");
            $stmt->bind_param("si", $status_val, $user_id);
            $stmt->execute();
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare("UPDATE users SET verification_status = ? WHERE user_id = ?");
            $stmt->execute([$status_val, $user_id]);
        }

        $msg = ($action === 'approve') ? 'User verified successfully!' : 'User verification rejected.';
        echo json_encode(['status' => 200, 'message' => $msg]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => 'Update failed: ' . $e->getMessage()]);
    }
}
?>