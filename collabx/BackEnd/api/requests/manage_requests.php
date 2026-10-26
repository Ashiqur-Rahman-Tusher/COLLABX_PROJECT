<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/db.php';

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

try {
    // 1. Fetch Project Title
    $project_title = "Project";
    if ($project_id > 0) {
        $resP = $db->query("SELECT title FROM projects WHERE project_id = $project_id");
        if ($resP && $rowP = $resP->fetch_assoc()) {
            $project_title = $rowP['title'];
        }
    }

    // 2. Fetch Talent Requests using LEFT JOIN
    $talentRequests = [];
    $sqlTalent = "SELECT 
                    j.request_id, 
                    j.project_id, 
                    j.user_id, 
                    j.status, 
                    j.expected_salary,
                    j.cover_note,
                    COALESCE(u.name, 'Applicant') as applicant_name, 
                    COALESCE(u.email, 'N/A') as applicant_email,
                    u.portfolio_url as portfolio_link
                  FROM joinrequests j
                  LEFT JOIN users u ON j.user_id = u.user_id";

    if ($project_id > 0) {
        $sqlTalent .= " WHERE j.project_id = $project_id";
    }

    $sqlTalent .= " ORDER BY j.request_id DESC";

    $res = $db->query($sqlTalent);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['role_applied'] = !empty($row['cover_note']) ? $row['cover_note'] : 'Team Member';
            $row['match_score'] = '95%';
            if (empty($row['expected_salary'])) $row['expected_salary'] = 25000;
            $talentRequests[] = $row;
        }
    }

    echo json_encode([
        'status' => 200,
        'project_title' => $project_title,
        'talent_requests' => $talentRequests,
        'requests' => $talentRequests,
        'data' => $talentRequests,
        'investor_commitments' => []
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>