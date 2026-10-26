<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$inputRaw = file_get_contents('php://input');
$jsonInput = json_decode($inputRaw, true) ?? [];

if ($method === 'GET') {
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

    if ($project_id <= 0) {
        echo json_encode(['status' => 400, 'message' => 'Project ID required']);
        exit;
    }

    try {
        $milestones = [];
        if ($db instanceof mysqli) {
            $stmt = $db->prepare("SELECT milestone_id, project_id, title, description, status, due_date, COALESCE(fund_release_amount, 0) AS amount FROM milestones WHERE project_id = ? ORDER BY milestone_id ASC");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $milestones[] = $row;
            }
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare("SELECT milestone_id, project_id, title, description, status, due_date, COALESCE(fund_release_amount, 0) AS amount FROM milestones WHERE project_id = ? ORDER BY milestone_id ASC");
            $stmt->execute([$project_id]);
            $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['status' => 200, 'milestones' => $milestones]);
    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
else if ($method === 'POST') {
    $action = $_POST['action'] ?? $jsonInput['action'] ?? null;

    if (!$action && (isset($_POST['milestone_id']) || isset($jsonInput['milestone_id']))) {
        $action = 'update_status';
    }
    if (!$action) $action = 'create';

    if ($action === 'create') {
        $project_id = intval($_POST['project_id'] ?? $jsonInput['project_id'] ?? 0);
        $title = trim($_POST['title'] ?? $jsonInput['title'] ?? '');
        $description = trim($_POST['description'] ?? $jsonInput['description'] ?? '');
        $due_date = $_POST['due_date'] ?? $jsonInput['due_date'] ?? date('Y-m-d', strtotime('+30 days'));

        if ($project_id <= 0 || empty($title)) {
            echo json_encode(['status' => 400, 'message' => 'Title and Project ID required']);
            exit;
        }

        try {
            if ($db instanceof mysqli) {
                $stmt = $db->prepare("INSERT INTO milestones (project_id, title, description, status, due_date) VALUES (?, ?, ?, 'pending', ?)");
                $stmt->bind_param("isss", $project_id, $title, $description, $due_date);
                $stmt->execute();
                $stmt->close();
            } else if ($db instanceof PDO) {
                $stmt = $db->prepare("INSERT INTO milestones (project_id, title, description, status, due_date) VALUES (?, ?, ?, 'pending', ?)");
                $stmt->execute([$project_id, $title, $description, $due_date]);
            }

            echo json_encode(['status' => 200, 'message' => 'Milestone created successfully!']);
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Error creating milestone: ' . $e->getMessage()]);
        }
    } 
    else if ($action === 'update_status') {
        $milestone_id = intval($_POST['milestone_id'] ?? $jsonInput['milestone_id'] ?? 0);
        $status = trim($_POST['status'] ?? $jsonInput['status'] ?? 'completed');

        if ($milestone_id <= 0) {
            echo json_encode(['status' => 400, 'message' => 'Milestone ID required']);
            exit;
        }

        try {
            // 1. Fetch Milestone & Project Details Safely
            $m_data = null;
            if ($db instanceof mysqli) {
                $m_stmt = $db->prepare("SELECT m.milestone_id, m.project_id, m.title AS milestone_title, p.title AS project_title, p.owner_id 
                                        FROM milestones m 
                                        JOIN projects p ON m.project_id = p.project_id 
                                        WHERE m.milestone_id = ?");
                $m_stmt->bind_param("i", $milestone_id);
                $m_stmt->execute();
                $m_data = $m_stmt->get_result()->fetch_assoc();
            } else if ($db instanceof PDO) {
                $m_stmt = $db->prepare("SELECT m.milestone_id, m.project_id, m.title AS milestone_title, p.title AS project_title, p.owner_id 
                                        FROM milestones m 
                                        JOIN projects p ON m.project_id = p.project_id 
                                        WHERE m.milestone_id = ?");
                $m_stmt->execute([$milestone_id]);
                $m_data = $m_stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$m_data) {
                echo json_encode(['status' => 404, 'message' => 'Milestone not found']);
                exit;
            }

            // 2. Update Milestone Status to 'completed'
            if ($db instanceof mysqli) {
                $stmt = $db->prepare("UPDATE milestones SET status = ? WHERE milestone_id = ?");
                $stmt->bind_param("si", $status, $milestone_id);
                $stmt->execute();
                $stmt->close();
            } else if ($db instanceof PDO) {
                $stmt = $db->prepare("UPDATE milestones SET status = ? WHERE milestone_id = ?");
                $stmt->execute([$status, $milestone_id]);
            }

            // 3. Collect User IDs to Notify (Owner & Investors)
            $usersToNotify = [];

            // Add Idea Owner
            if (!empty($m_data['owner_id'])) {
                $usersToNotify[] = [
                    'user_id' => $m_data['owner_id'],
                    'message' => "Escrow funds for milestone '{$m_data['milestone_title']}' in your project '{$m_data['project_title']}' have been released by Admin!"
                ];
            }

            // Add All Investors of this Project
            $project_id = $m_data['project_id'];
            if ($db instanceof mysqli) {
                $inv_stmt = $db->prepare("SELECT DISTINCT investor_id FROM investments WHERE project_id = ?");
                $inv_stmt->bind_param("i", $project_id);
                $inv_stmt->execute();
                $inv_res = $inv_stmt->get_result();
                while ($inv = $inv_res->fetch_assoc()) {
                    if (!empty($inv['investor_id']) && $inv['investor_id'] != $m_data['owner_id']) {
                        $usersToNotify[] = [
                            'user_id' => $inv['investor_id'],
                            'message' => "Milestone '{$m_data['milestone_title']}' for project '{$m_data['project_title']}' you invested in is completed and funds released."
                        ];
                    }
                }
            } else if ($db instanceof PDO) {
                $inv_stmt = $db->prepare("SELECT DISTINCT investor_id FROM investments WHERE project_id = ?");
                $inv_stmt->execute([$project_id]);
                $investors = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($investors as $inv) {
                    if (!empty($inv['investor_id']) && $inv['investor_id'] != $m_data['owner_id']) {
                        $usersToNotify[] = [
                            'user_id' => $inv['investor_id'],
                            'message' => "Milestone '{$m_data['milestone_title']}' for project '{$m_data['project_title']}' you invested in is completed and funds released."
                        ];
                    }
                }
            }

            // 4. Send Notifications
            foreach ($usersToNotify as $notif) {
                if ($db instanceof mysqli) {
                    $n_stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                    $n_stmt->bind_param("is", $notif['user_id'], $notif['message']);
                    $n_stmt->execute();
                } else if ($db instanceof PDO) {
                    $n_stmt = $db->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                    $n_stmt->execute([$notif['user_id'], $notif['message']]);
                }
            }

            echo json_encode(['status' => 200, 'message' => 'Milestone status updated and notifications sent successfully!']);
        } catch (Exception $e) {
            echo json_encode(['status' => 500, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
    }
}
?>