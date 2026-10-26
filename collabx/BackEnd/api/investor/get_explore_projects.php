<?php
header('Content-Type: application/json');
session_start();

if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

// URL Parameter or Session user_id
$user_id = $_GET['user_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? 0;
$user_id = intval($user_id);

try {
    // Database-er sob projects fetch korbe
    $sql = "SELECT p.project_id, p.title, p.category, p.required_budget, p.collected_budget, p.expected_roi,
                   COALESCE(u.name, 'Startup Owner') as owner_name 
            FROM projects p 
            LEFT JOIN users u ON p.owner_id = u.user_id 
            WHERE 1=1";

    // Je project-e investor ALREADY invest koreche, seiti exclude/hide hobe
    if ($user_id > 0) {
        $sql .= " AND p.project_id NOT IN (
            SELECT DISTINCT project_id FROM investments WHERE investor_id = $user_id
        )";
    }

    $sql .= " ORDER BY p.project_id DESC";

    $projects = [];
    if ($db instanceof mysqli) {
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $projects[] = $row;
            }
        }
    }

    echo json_encode([
        'status' => 200,
        'projects' => $projects
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>