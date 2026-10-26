<?php
header('Content-Type: application/json');
session_start();

// Database Connection Check
if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} else if (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

// Logged in Manpower/Talent User ID
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? 0;

if ($user_id <= 0) {
    echo json_encode(['status' => 401, 'message' => 'Unauthorized user']);
    exit;
}

try {
    $appliedCount = 0;
    $pendingCount = 0;

    if ($db instanceof mysqli) {
        // Total Applied Count
        $stmt1 = $db->prepare("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = ?");
        $stmt1->bind_param("i", $user_id);
        $stmt1->execute();
        $res1 = $stmt1->get_result()->fetch_assoc();
        if ($res1) $appliedCount = intval($res1['total']);
        $stmt1->close();

        // Pending Count
        $stmt2 = $db->prepare("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = ? AND (status = 'pending' OR status IS NULL OR status = '')");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        if ($res2) $pendingCount = intval($res2['total']);
        $stmt2->close();

    } else if ($db instanceof PDO) {
        // Total Applied Count
        $stmt1 = $db->prepare("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = ?");
        $stmt1->execute([$user_id]);
        $res1 = $stmt1->fetch(PDO::FETCH_ASSOC);
        if ($res1) $appliedCount = intval($res1['total']);

        // Pending Count
        $stmt2 = $db->prepare("SELECT COUNT(*) as total FROM joinrequests WHERE user_id = ? AND (status = 'pending' OR status IS NULL OR status = '')");
        $stmt2->execute([$user_id]);
        $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($res2) $pendingCount = intval($res2['total']);
    }

    echo json_encode([
        'status' => 200,
        'applied_count' => $appliedCount,
        'pending_count' => $pendingCount
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>