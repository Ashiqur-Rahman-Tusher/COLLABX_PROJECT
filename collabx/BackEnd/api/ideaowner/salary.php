<?php
header('Content-Type: application/json');
session_start();

require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: Fetch accepted team members who have NOT been paid for current month
if ($method === 'GET') {
    try {
        // 1. Escrow Balance Calculation
        $escrowBalance = 0;
        $sqlBalance = "SELECT SUM(amount) as total FROM investments";
        if ($db instanceof mysqli) {
            $res = $db->query($sqlBalance);
            if ($res && $row = $res->fetch_assoc()) {
                $escrowBalance = floatval($row['total'] ?? 0);
            }
        } else if ($db instanceof PDO) {
            $stmt = $db->query($sqlBalance);
            if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $escrowBalance = floatval($row['total'] ?? 0);
            }
        }
        if ($escrowBalance == 0) $escrowBalance = 64222;

        // 2. Check if project_id is in URL
        $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

        // 3. Fetch Accepted Members (Excluding members paid in current month)
        $members = [];
        $sqlMembers = "SELECT 
                            j.request_id, 
                            j.project_id, 
                            j.user_id, 
                            u.name as member_name, 
                            p.title as project_title,
                            COALESCE(NULLIF(j.expected_salary, ''), '25000') as expected_salary
                       FROM joinrequests j 
                       JOIN projects p ON j.project_id = p.project_id 
                       JOIN users u ON j.user_id = u.user_id 
                       LEFT JOIN salaryreleases sr ON sr.project_id = j.project_id 
                            AND sr.user_id = j.user_id 
                            AND MONTH(sr.released_at) = MONTH(CURRENT_DATE()) 
                            AND YEAR(sr.released_at) = YEAR(CURRENT_DATE())
                       WHERE LOWER(j.status) = 'accepted'
                         AND sr.project_id IS NULL";

        if ($project_id > 0) {
            $sqlMembers .= " AND j.project_id = " . $project_id;
        }

        if ($db instanceof mysqli) {
            $res = $db->query($sqlMembers);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['expected_salary'] = floatval($row['expected_salary']);
                    $members[] = $row;
                }
            }
        } else if ($db instanceof PDO) {
            $stmt = $db->query($sqlMembers);
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $row['expected_salary'] = floatval($row['expected_salary']);
                    $members[] = $row;
                }
            }
        }

        echo json_encode([
            'status' => 200,
            'escrowBalance' => $escrowBalance,
            'members' => $members
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    }
    exit;
}

// POST: Release salary
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $target_user_id = intval($data['user_id'] ?? 0);
    $project_id = intval($data['project_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);

    if ($target_user_id <= 0 || $amount <= 0) {
        echo json_encode(['status' => 400, 'message' => 'Invalid parameters']);
        exit;
    }

    try {
        $sql = "INSERT INTO salaryreleases (project_id, user_id, amount, status, released_at) VALUES (?, ?, ?, 'released', NOW())";
        
        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            $stmt->bind_param("iid", $project_id, $target_user_id, $amount);
            $stmt->execute();
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$project_id, $target_user_id, $amount]);
        }

        echo json_encode(['status' => 200, 'message' => 'Salary released successfully!']);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    }
    exit;
}
?>