<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    echo json_encode(['status' => 400, 'message' => 'Invalid User ID']);
    exit;
}

try {
    $projects = [];
    $total_projects = 0;
    $active_projects = 0;

    // Fetch User Projects
    if ($db instanceof mysqli) {
        $stmt = $db->prepare("
            SELECT 
                p.project_id, 
                p.title, 
                p.status, 
                p.required_budget AS budget_required, 
                (SELECT COUNT(*) FROM milestones WHERE project_id = p.project_id) AS total_milestones,
                (SELECT COUNT(*) FROM milestones WHERE project_id = p.project_id AND status = 'completed') AS completed_milestones
            FROM projects p 
            WHERE p.owner_id = ? 
            ORDER BY p.project_id DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $projects[] = $row;
            if ($row['status'] === 'approved' || $row['status'] === 'active') {
                $active_projects++;
            }
        }
        $stmt->close();
    } else if ($db instanceof PDO) {
        $stmt = $db->prepare("
            SELECT 
                p.project_id, 
                p.title, 
                p.status, 
                p.required_budget AS budget_required, 
                (SELECT COUNT(*) FROM milestones WHERE project_id = p.project_id) AS total_milestones,
                (SELECT COUNT(*) FROM milestones WHERE project_id = p.project_id AND status = 'completed') AS completed_milestones
            FROM projects p 
            WHERE p.owner_id = ? 
            ORDER BY p.project_id DESC
        ");
        $stmt->execute([$user_id]);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as $p) {
            if ($p['status'] === 'approved' || $p['status'] === 'active') {
                $active_projects++;
            }
        }
    }

    $total_projects = count($projects);

    echo json_encode([
        'status' => 200,
        'summary' => [
            'total_projects' => $total_projects,
            'active_projects' => $active_projects,
            'total_escrow_funding' => 0
        ],
        'projects' => $projects
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 500,
        'message' => 'Database Error: ' . $e->getMessage()
    ]);
}
?>