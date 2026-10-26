<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        json_response(401, 'Unauthorized access. Kripaya login korun.');
    }

    $user_id = $_SESSION['user_id'];
    $title = trim($_POST['title'] ?? '');
    $tagline = trim($_POST['tagline'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $required_budget = floatval($_POST['target_amount'] ?? 0);

    if (empty($title)) $title = "New Startup Idea";
    if (empty($category)) $category = "General";
    if ($required_budget <= 0) $required_budget = 50000;

    try {
        $stmt = $conn->prepare("INSERT INTO projects (owner_id, title, tagline, category, description, required_budget, approval_status, status) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'draft')");
        
        if (!$stmt) {
            json_response(500, 'Database Query Prepare Error: ' . $conn->error);
        }

        $stmt->bind_param("issssd", $user_id, $title, $tagline, $category, $description, $required_budget);

        if ($stmt->execute()) {
            json_response(200, 'Project safolvabe submit hoyeche! Admin approval-er jonno pending ache.', [
                'project_id' => $stmt->insert_id
            ]);
        } else {
            json_response(400, 'Database Query Execution Error: ' . $stmt->error);
        }
    } catch (Exception $e) {
        json_response(500, 'Server Execution Error: ' . $e->getMessage());
    }
} else {
    json_response(405, 'Method Not Allowed');
}
?>