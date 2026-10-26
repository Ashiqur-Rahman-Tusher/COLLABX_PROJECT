<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

session_start();
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : (isset($_GET['user_id']) ? intval($_GET['user_id']) : 0);
$req_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($user_id <= 0 && $req_project_id <= 0) {
    echo json_encode(['status' => 400, 'message' => 'Unauthorized or Project/User ID required']);
    exit;
}

try {
    $locked = 0.00;
    $released = 0.00;
    $pending = 0.00;
    $active_project_title = "No Active Escrow Project";
    $active_project_id = 0;
    $transactions = [];

    // Fetch project (either by project_id if provided, or user's latest project)
    if ($db instanceof mysqli) {
        if ($req_project_id > 0) {
            $stmt = $db->prepare("SELECT project_id, title, required_budget FROM projects WHERE project_id = ? LIMIT 1");
            $stmt->bind_param("i", $req_project_id);
        } else {
            $stmt = $db->prepare("SELECT project_id, title, required_budget FROM projects WHERE owner_id = ? ORDER BY project_id DESC LIMIT 1");
            $stmt->bind_param("i", $user_id);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        if ($p = $res->fetch_assoc()) {
            $active_project_title = $p['title'];
            $active_project_id = intval($p['project_id']);
            $total_budget = floatval($p['required_budget'] > 0 ? $p['required_budget'] : 100000);

            // Fetch milestones
            $m_stmt = $db->prepare("SELECT milestone_id, title, status, fund_release_amount FROM milestones WHERE project_id = ?");
            $m_stmt->bind_param("i", $active_project_id);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            
            $milestones = [];
            while ($row = $m_res->fetch_assoc()) {
                $milestones[] = $row;
            }

            $milestone_count = count($milestones);

            if ($milestone_count > 0) {
                foreach ($milestones as $m) {
                    $m_amt = floatval($m['fund_release_amount']) > 0 ? floatval($m['fund_release_amount']) : ($total_budget / $milestone_count);

                    if ($m['status'] === 'completed' || $m['status'] === 'released') {
                        $released += $m_amt;
                        $tx_status = 'RELEASED';
                    } else if ($m['status'] === 'release_requested') {
                        $pending += $m_amt;
                        $tx_status = 'PENDING APPROVAL';
                    } else {
                        $locked += $m_amt;
                        $tx_status = 'LOCKED IN VAULT';
                    }

                    $transactions[] = [
                        'project_id' => $active_project_id,
                        'title' => $m['title'] ?? 'Project Milestone',
                        'project' => $active_project_title,
                        'type' => 'ESCROW',
                        'amount' => $m_amt,
                        'status' => $tx_status
                    ];
                }
            } else {
                $locked = $total_budget;
                $transactions[] = [
                    'project_id' => $active_project_id,
                    'title' => 'Initial Investor Commitment',
                    'project' => $active_project_title,
                    'type' => 'CREDIT',
                    'amount' => $total_budget,
                    'status' => 'LOCKED IN VAULT'
                ];
            }
            $m_stmt->close();
        }
        $stmt->close();
    } else if ($db instanceof PDO) {
        if ($req_project_id > 0) {
            $stmt = $db->prepare("SELECT project_id, title, required_budget FROM projects WHERE project_id = ? LIMIT 1");
            $stmt->execute([$req_project_id]);
        } else {
            $stmt = $db->prepare("SELECT project_id, title, required_budget FROM projects WHERE owner_id = ? ORDER BY project_id DESC LIMIT 1");
            $stmt->execute([$user_id]);
        }

        if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $active_project_title = $p['title'];
            $active_project_id = intval($p['project_id']);
            $total_budget = floatval($p['required_budget'] > 0 ? $p['required_budget'] : 100000);

            $m_stmt = $db->prepare("SELECT milestone_id, title, status, fund_release_amount FROM milestones WHERE project_id = ?");
            $m_stmt->execute([$active_project_id]);
            $milestones = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($milestones) > 0) {
                foreach ($milestones as $m) {
                    $m_amt = floatval($m['fund_release_amount']) > 0 ? floatval($m['fund_release_amount']) : ($total_budget / count($milestones));

                    if ($m['status'] === 'completed' || $m['status'] === 'released') {
                        $released += $m_amt;
                        $tx_status = 'RELEASED';
                    } else if ($m['status'] === 'release_requested') {
                        $pending += $m_amt;
                        $tx_status = 'PENDING APPROVAL';
                    } else {
                        $locked += $m_amt;
                        $tx_status = 'LOCKED IN VAULT';
                    }

                    $transactions[] = [
                        'project_id' => $active_project_id,
                        'title' => $m['title'] ?? 'Project Milestone',
                        'project' => $active_project_title,
                        'type' => 'ESCROW',
                        'amount' => $m_amt,
                        'status' => $tx_status
                    ];
                }
            } else {
                $locked = $total_budget;
                $transactions[] = [
                    'project_id' => $active_project_id,
                    'title' => 'Initial Investor Commitment',
                    'project' => $active_project_title,
                    'type' => 'CREDIT',
                    'amount' => $total_budget,
                    'status' => 'LOCKED IN VAULT'
                ];
            }
        }
    }

    echo json_encode([
        'status' => 200,
        'escrow' => [
            'project_id' => $active_project_id,
            'project_title' => $active_project_title,
            'locked_amount' => $locked,
            'released_amount' => $released,
            'pending_amount' => $pending
        ],
        'transactions' => $transactions
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>