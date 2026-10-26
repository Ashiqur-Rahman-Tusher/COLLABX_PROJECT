<?php
header('Content-Type: application/json');
session_start();

if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

// Logged in Manpower/Talent User ID
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? 0;

if ($user_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized']);
    exit;
}

try {
    // 1. Total Applied Projects Count
    $appliedCount = 0;
    $resApplied = $db->query("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = $user_id");
    if ($resApplied && $row = $resApplied->fetch_assoc()) {
        $appliedCount = intval($row['total']);
    }

    // 2. Pending Approval Count
    $pendingCount = 0;
    $resPending = $db->query("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = $user_id AND (status = 'pending' OR status IS NULL)");
    if ($resPending && $row = $resPending->fetch_assoc()) {
        $pendingCount = intval($row['total']);
    }

    // 3. Fetch Applied Projects with current Status
    $myApplications = [];
    $sqlApps = "SELECT 
                    j.request_id,
                    j.project_id,
                    j.status,
                    p.title as project_title,
                    p.category,
                    p.budget
                FROM joinrequests j
                LEFT JOIN projects p ON j.project_id = p.project_id
                WHERE j.user_id = $user_id
                ORDER BY j.request_id DESC";
                
    $resApps = $db->query($sqlApps);
    if ($resApps) {
        while ($row = $resApps->fetch_assoc()) {
            $myApplications[] = $row;
        }
    }

    echo json_encode([
        'status' => 200,
        'applied_count' => $appliedCount,
        'pending_count' => $pendingCount,
        'applications' => $myApplications
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>