<?php
header('Content-Type: application/json');
session_start();

// Database connection
require_once '../../config/db.php'; 

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized user. Please login first.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// GET Request: Project details and applied status
if ($method === 'GET') {
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

    // Fixed: strictly project_id
    $stmt = $db->prepare("SELECT title, category FROM projects WHERE project_id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $project = $stmt->get_result()->fetch_assoc();

    $check_stmt = $db->prepare("SELECT request_id FROM joinrequests WHERE project_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $project_id, $user_id);
    $check_stmt->execute();
    $already_applied = $check_stmt->get_result()->num_rows > 0;

    echo json_encode([
        'status' => 200,
        'project' => $project,
        'already_applied' => $already_applied
    ]);
    exit();
}

// POST Request: Submit Application
if ($method === 'POST') {
    $inputData = json_decode(file_get_contents('php://input'), true);
    $project_id = isset($inputData['project_id']) ? intval($inputData['project_id']) : 0;

    if ($project_id <= 0) {
        echo json_encode(['status' => 400, 'message' => 'Invalid Project ID']);
        exit();
    }

    // Check duplicate
    $dupCheck = $db->prepare("SELECT request_id FROM joinrequests WHERE project_id = ? AND user_id = ?");
    $dupCheck->bind_param("ii", $project_id, $user_id);
    $dupCheck->execute();
    if ($dupCheck->get_result()->num_rows > 0) {
        echo json_encode(['status' => 400, 'message' => 'You have already applied to this project!']);
        exit();
    }

    // Get a valid skill_id
    $skill_id = 1; 
    $skill_query = $db->query("SELECT skill_id FROM skills LIMIT 1");
    if ($skill_query && $row = $skill_query->fetch_assoc()) {
        $skill_id = $row['skill_id'];
    }

    // Insert into joinrequests
    $stmt = $db->prepare("INSERT INTO joinrequests (project_id, user_id, skill_id, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bind_param("iii", $project_id, $user_id, $skill_id);

    if ($stmt->execute()) {
        echo json_encode(['status' => 200, 'message' => 'Application submitted successfully!']);
    } else {
        echo json_encode(['status' => 500, 'message' => 'DB Error: ' . $db->error]);
    }
    exit();
}
?>