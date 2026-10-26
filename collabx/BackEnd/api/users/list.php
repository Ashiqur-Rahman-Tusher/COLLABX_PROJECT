<?php
header('Content-Type: application/json');

// Database Connection Handling
require_once '../../config/db.php';

// Detect Connection Type ($pdo or $conn)
$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode([
        'status' => 500,
        'message' => 'Database connection failed (No valid connection object found)'
    ]);
    exit;
}

$role = isset($_GET['role']) ? trim($_GET['role']) : '';
$users = [];

try {
    // 1. If connection is MySQLi
    if ($db instanceof mysqli) {
        if (!empty($role)) {
            $stmt = $db->prepare("SELECT user_id, name, email, primary_role AS role, investment_capacity, reputation_score, created_at FROM users WHERE primary_role = ? AND is_active = 1 ORDER BY user_id DESC LIMIT 10");
            $stmt->bind_param("s", $role);
        } else {
            $stmt = $db->prepare("SELECT user_id, name, email, primary_role AS role, investment_capacity, reputation_score, created_at FROM users WHERE is_active = 1 ORDER BY user_id DESC LIMIT 10");
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();
    } 
    // 2. If connection is PDO
    else if ($db instanceof PDO) {
        if (!empty($role)) {
            $stmt = $db->prepare("SELECT user_id, name, email, primary_role AS role, investment_capacity, reputation_score, created_at FROM users WHERE primary_role = ? AND is_active = 1 ORDER BY user_id DESC LIMIT 10");
            $stmt->execute([$role]);
        } else {
            $stmt = $db->prepare("SELECT user_id, name, email, primary_role AS role, investment_capacity, reputation_score, created_at FROM users WHERE is_active = 1 ORDER BY user_id DESC LIMIT 10");
            $stmt->execute();
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 200,
        'users' => $users
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 500,
        'message' => 'Query error: ' . $e->getMessage()
    ]);
}
?>