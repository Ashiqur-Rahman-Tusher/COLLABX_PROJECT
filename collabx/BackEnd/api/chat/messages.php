<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection error']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized. Please login.']);
    exit;
}

$action = $_GET['action'] ?? '';

// Table Auto-Creation if missing
try {
    $createSql = "CREATE TABLE IF NOT EXISTS direct_messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    if ($db instanceof mysqli) {
        $db->query($createSql);
    } else if ($db instanceof PDO) {
        $db->exec($createSql);
    }
} catch (Exception $e) { }

// 1. Get Contact List
if ($action === 'contacts') {
    try {
        $contacts = [];
        $sql = "SELECT DISTINCT u.user_id, u.name, u.primary_role AS role 
                FROM users u 
                WHERE u.user_id != ? 
                ORDER BY u.name ASC LIMIT 20";

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $contacts[] = $row;
            }
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$user_id]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => 200, 'contacts' => $contacts]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    }
}

// 2. Fetch Messages between current user and target receiver
else if ($action === 'fetch') {
    $receiver_id = intval($_GET['receiver_id'] ?? 0);
    try {
        $messages = [];
        $sql = "SELECT message_id, sender_id, receiver_id, message, created_at 
                FROM direct_messages 
                WHERE (sender_id = ? AND receiver_id = ?) 
                   OR (sender_id = ? AND receiver_id = ?) 
                ORDER BY created_at ASC";

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param("iiii", $user_id, $receiver_id, $receiver_id, $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $messages[] = $row;
            }
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$user_id, $receiver_id, $receiver_id, $user_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'status' => 200, 
            'current_user_id' => $user_id, 
            'messages' => $messages
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    }
}

// 3. Send Message
else if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $msg = trim($_POST['message'] ?? '');

    if ($receiver_id <= 0 || empty($msg)) {
        echo json_encode(['status' => 400, 'message' => 'Invalid parameters']);
        exit;
    }

    try {
        if ($db instanceof mysqli) {
            $stmt = $db->prepare("INSERT INTO direct_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $user_id, $receiver_id, $msg);
            $stmt->execute();
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare("INSERT INTO direct_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $receiver_id, $msg]);
        }

        echo json_encode(['status' => 200, 'message' => 'Message sent successfully']);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    }
}
?>