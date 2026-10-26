<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/db.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized user. Please login first.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputData = json_decode(file_get_contents('php://input'), true);
    $project_id = isset($inputData['project_id']) ? intval($inputData['project_id']) : 0;
    $expected_salary = isset($inputData['expected_salary']) ? floatval($inputData['expected_salary']) : 0;
    $cover_note = isset($inputData['cover_note']) ? trim($inputData['cover_note']) : '';

    if ($project_id <= 0) {
        echo json_encode(['status' => 400, 'message' => 'Invalid Project ID']);
        exit();
    }

    // Duplicate Application Check
    $dupCheck = $db->prepare("SELECT request_id FROM joinrequests WHERE project_id = ? AND user_id = ?");
    $dupCheck->bind_param("ii", $project_id, $user_id);
    $dupCheck->execute();
    if ($dupCheck->get_result()->num_rows > 0) {
        echo json_encode(['status' => 400, 'message' => 'You have already applied to this project!']);
        exit();
    }

    // Default Skill ID
    $skill_id = 1;
    $skill_query = $db->query("SELECT skill_id FROM skills LIMIT 1");
    if ($skill_query && $row = $skill_query->fetch_assoc()) {
        $skill_id = $row['skill_id'];
    }

    // Insert into joinrequests table
    $stmt = $db->prepare("INSERT INTO joinrequests (project_id, user_id, skill_id, expected_salary, cover_note, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param("iiids", $project_id, $user_id, $skill_id, $expected_salary, $cover_note);

    if ($stmt->execute()) {
        echo json_encode(['status' => 200, 'message' => 'Application submitted successfully!']);
    } else {
        echo json_encode(['status' => 500, 'message' => 'DB Error: ' . $db->error]);
    }
    exit();
}
?>