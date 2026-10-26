<?php
header('Content-Type: application/json');
session_start();

// Database Connection Check
if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$db = isset($conn) ?$conn : (isset($pdo) ?$pdo : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

// Read JSON input or POST input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data) {
    $data =$_POST;
}

$requestId = isset($data['request_id']) ? intval($data['request_id']) : (isset($data['id']) ? intval($data['id']) : 0);
$status = isset($data['status']) ? trim($data['status']) : (isset($data['action']) ? trim($data['action']) : '');$type = isset($data['type']) ? trim($data['type']) : 'talent';

// Normalize status keywords
if ($status === 'accept' || $status === 'approved') {$status = 'accepted';
} elseif ($status === 'decline' || $status === 'reject') {$status = 'rejected';
}

if ($requestId <= 0 || empty($status)) {
    echo json_encode(['status' => 400, 'message' => 'Invalid request parameters']);
    exit;
}

try {
    if ($type === 'investor') {
        // Update Investments Status
        $sql = "UPDATE investments SET status = ? WHERE investment_id = ?";
        if ($db instanceof mysqli) {
            $stmt =$db->prepare($sql);$stmt->bind_param("si", $status,$requestId);
            $stmt->execute();$stmt->close();
        } else if ($db instanceof PDO) {
            $stmt =$db->prepare($sql);$stmt->execute([$status,$requestId]);
        }
    } else {
        // 1. Update Join Requests Status (Talent)
        $sql = "UPDATE joinrequests SET status = ? WHERE request_id = ?";
        if ($db instanceof mysqli) {
            $stmt =$db->prepare($sql);$stmt->bind_param("si", $status,$requestId);
            $stmt->execute();$stmt->close();
        } else if ($db instanceof PDO) {
            $stmt =$db->prepare($sql);$stmt->execute([$status,$requestId]);
        }

        // 2. If Request is ACCEPTED, automatically insert into `teammembers` table
        if ($status === 'accepted' || $status === 'approved') {$reqData = null;
            if ($db instanceof mysqli) {
                $res =$db->query("SELECT project_id, user_id FROM joinrequests WHERE request_id = $requestId");
                if ($res) $reqData =$res->fetch_assoc();
            } else if ($db instanceof PDO) {
                $stmt =$db->prepare("SELECT project_id, user_id FROM joinrequests WHERE request_id = ?");
                $stmt->execute([$requestId]);
                $reqData =$stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($reqData) {
                $projectId = intval($reqData['project_id']);
                $applicantUserId = intval($reqData['user_id']);

                // Check if already in teammembers table
                $alreadyMember = false;
                $checkSql = "SELECT team_member_id FROM teammembers WHERE project_id = ? AND user_id = ?";
                if ($db instanceof mysqli) {$cStmt = $db->prepare($checkSql);
                    $cStmt->bind_param("ii", $projectId, $applicantUserId);$cStmt->execute();
                    if ($cStmt->get_result()->fetch_assoc())$alreadyMember = true;
                    $cStmt->close();
                } else if ($db instanceof PDO) {
                    $cStmt =$db->prepare($checkSql);$cStmt->execute([$projectId,$applicantUserId]);
                    if ($cStmt->fetch())$alreadyMember = true;
                }

                // Insert into teammembers if not exists
                if (!$alreadyMember) {$insSql = "INSERT INTO teammembers (project_id, user_id, role, joined_at) VALUES (?, ?, 'Team Member', NOW())";
                    if ($db instanceof mysqli) {
                        $iStmt =$db->prepare($insSql);$iStmt->bind_param("ii", $projectId,$applicantUserId);
                        $iStmt->execute();$iStmt->close();
                    } else if ($db instanceof PDO) {
                        $iStmt =$db->prepare($insSql);$iStmt->execute([$projectId,$applicantUserId]);
                    }
                }
            }
        }
    }

    echo json_encode([
        'status' => 200,
        'message' => 'Request status updated successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>