<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

try {
    // 1. Total Users Count
    $total_users = 0;
    if ($db instanceof mysqli) {
        $res = $db->query("SELECT COUNT(*) AS total FROM users");
        if ($row = $res->fetch_assoc()) $total_users = intval($row['total']);
    } else if ($db instanceof PDO) {
        $total_users = intval($db->query("SELECT COUNT(*) FROM users")->fetchColumn());
    }

    // 2. Global Escrow Funds (Approved projects required_budget)
    $global_escrow = 0;
    if ($db instanceof mysqli) {
        $res = $db->query("SELECT SUM(required_budget) AS total FROM projects WHERE approval_status = 'approved'");
        if ($row = $res->fetch_assoc()) $global_escrow = floatval($row['total'] ?? 0);
    } else if ($db instanceof PDO) {
        $global_escrow = floatval($db->query("SELECT SUM(required_budget) FROM projects WHERE approval_status = 'approved'")->fetchColumn() ?? 0);
    }

    // 3. Pending Projects for Moderation & Approval
    $pending_projects = [];
    $p_sql = "SELECT p.project_id, p.title, p.category, p.required_budget, COALESCE(u.name, u.email, 'Unknown') AS owner_name 
              FROM projects p 
              LEFT JOIN users u ON p.owner_id = u.user_id 
              WHERE p.approval_status = 'pending' 
              ORDER BY p.project_id DESC";

    if ($db instanceof mysqli) {
        $res = $db->query($p_sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $pending_projects[] = $row;
            }
        }
    } else if ($db instanceof PDO) {
        $stmt = $db->query($p_sql);
        $pending_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Milestone Payout Requests
    $milestones_requests = [];
    $total_disbursement = 0;

    $m_sql = "SELECT m.milestone_id, m.title AS milestone_title, m.status, 
                     COALESCE(m.fund_release_amount, 0) AS amount, 
                     p.title AS project_title, COALESCE(p.required_budget, 0) AS required_budget,
                     COALESCE(u.name, u.email, 'Project Owner') AS owner_name 
              FROM milestones m 
              JOIN projects p ON m.project_id = p.project_id 
              LEFT JOIN users u ON p.owner_id = u.user_id 
              WHERE m.status IN ('pending', 'in_progress') 
              ORDER BY m.milestone_id DESC";

    if ($db instanceof mysqli) {
        $res = $db->query($m_sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $amt = floatval($row['amount']);
                // Fallback: If amount is 0, estimate from budget
                if ($amt <= 0 && floatval($row['required_budget']) > 0) {
                    $amt = round(floatval($row['required_budget']) / 2, 2); 
                }
                $row['amount'] = $amt;
                $total_disbursement += $amt;
                $milestones_requests[] = $row;
            }
        }
    } else if ($db instanceof PDO) {
        $stmt = $db->query($m_sql);
        $milestones_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($milestones_requests as &$row) {
            $amt = floatval($row['amount']);
            if ($amt <= 0 && floatval($row['required_budget']) > 0) {
                $amt = round(floatval($row['required_budget']) / 2, 2);
            }
            $row['amount'] = $amt;
            $total_disbursement += $amt;
        }
    }

    echo json_encode([
        'status' => 200,
        'stats' => [
            'total_users' => $total_users,
            'pending_projects_count' => count($pending_projects),
            'global_escrow' => $global_escrow,
            'total_disbursement' => $total_disbursement
        ],
        'pending_projects' => $pending_projects,
        'payout_requests' => $milestones_requests
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => 'Error: ' . $e->getMessage()]);
}
?>