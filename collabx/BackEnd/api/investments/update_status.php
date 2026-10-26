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

$investment_id = intval($_REQUEST['investment_id'] ?? $_REQUEST['id'] ?? 0);
$action = $_REQUEST['status'] ?? $_REQUEST['action'] ?? 'accepted';

if ($investment_id <= 0) {
    echo json_encode(['status' => 400, 'message' => 'Invalid Investment ID']);
    exit;
}

try {
    if ($db instanceof mysqli) {
        $invRes = $db->query("SELECT i.*, p.title as project_title, p.owner_id 
                              FROM investments i 
                              JOIN projects p ON i.project_id = p.project_id 
                              WHERE i.investment_id = $investment_id");
        $inv = $invRes ? $invRes->fetch_assoc() : null;

        if (!$inv) {
            echo json_encode(['status' => 404, 'message' => 'Investment proposal not found']);
            exit;
        }

        $project_id = intval($inv['project_id']);
        $investor_id = intval($inv['investor_id']);
        $owner_id = intval($inv['owner_id']);
        $amount = floatval($inv['amount']);
        $title = $inv['project_title'];

        if ($action === 'accepted' || $action === 'accept') {
            // Update status
            $db->query("UPDATE investments SET status = 'agreement_pending', escrow_status = 'held' WHERE investment_id = $investment_id");

            // Check or create agreement record
            $checkAgr = $db->query("SELECT agreement_id FROM agreements WHERE investment_id = $investment_id");
            if ($checkAgr && $checkAgr->num_rows == 0) {
                $terms = "Legal Investment Agreement for project: $title. Agreed Amount: BDT " . number_format($amount) . ". Funds will remain in Escrow Vault until contract signature.";
                $db->query("INSERT INTO agreements (investment_id, project_id, investor_id, owner_id, terms, status, created_at) 
                            VALUES ($investment_id, $project_id, $investor_id, $owner_id, '$terms', 'pending_signatures', NOW())");
                $agreement_id = $db->insert_id;
            } else {
                $agrData = $checkAgr->fetch_assoc();
                $agreement_id = $agrData['agreement_id'];
            }

            // Send Notification
            $notifMsg = "Your investment proposal for '$title' was accepted! Please review and sign the legal agreement.";
            $db->query("INSERT INTO notifications (user_id, message, is_read) VALUES ($investor_id, '$notifMsg', 0)");

            echo json_encode([
                'status' => 200,
                'message' => 'Proposal Accepted! Redirecting to Digital Agreement...',
                'agreement_id' => $agreement_id,
                'redirect' => "manage-agreement.html?agreement_id=" . $agreement_id
            ]);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
    exit;
}
?>