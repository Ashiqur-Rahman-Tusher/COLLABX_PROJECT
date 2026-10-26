<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/helpers.php';
require_once dirname(__DIR__, 2) . '/config/notification_helper.php';

$action = isset($_REQUEST['action']) ? sanitizeInput($_REQUEST['action']) : '';

// 1. Generate or Force Sync Agreement Parties
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate') {
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    
    if ($project_id <= 0) {
        json_response(400, "Invalid Project ID.");
    }

    try {
        $check_stmt = $conn->prepare("SELECT agreement_id, contract_ref_id FROM agreements WHERE project_id = ?");
        $check_stmt->bind_param("i", $project_id);
        $check_stmt->execute();
        $res = $check_stmt->get_result();

        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $agreement_id = $row['agreement_id'];
            $contract_ref = $row['contract_ref_id'];
        } else {
            $contract_ref = "CX-AGR-" . date("Ymd") . "-" . rand(1000, 9999);
            $stmt = $conn->prepare("INSERT INTO agreements (contract_ref_id, project_id, status, created_at) VALUES (?, ?, 'pending_signatures', NOW())");
            $stmt->bind_param("si", $contract_ref, $project_id);
            $stmt->execute();
            $agreement_id = $stmt->insert_id;
        }

        // Sync project negotiations to agreementparties
        $neg_stmt = $conn->prepare("SELECT user_id, role, proposed_percentage, proposed_salary FROM negotiations WHERE project_id = ?");
        $neg_stmt->bind_param("i", $project_id);
        $neg_stmt->execute();
        $neg_res = $neg_stmt->get_result();

        while ($neg = $neg_res->fetch_assoc()) {
            $p_check = $conn->prepare("SELECT party_id FROM agreementparties WHERE agreement_id = ? AND user_id = ?");
            $p_check->bind_param("ii", $agreement_id, $neg['user_id']);
            $p_check->execute();
            
            if ($p_check->get_result()->num_rows == 0) {
                $party_stmt = $conn->prepare("INSERT INTO agreementparties (agreement_id, user_id, role, profit_share_percent, monthly_salary, is_signed) VALUES (?, ?, ?, ?, ?, 0)");
                $party_stmt->bind_param("iisdd", $agreement_id, $neg['user_id'], $neg['role'], $neg['proposed_percentage'], $neg['proposed_salary']);
                $party_stmt->execute();

                createNotification($neg['user_id'], "Agreement contract synced: " . $contract_ref);
            }
        }

        json_response(200, "Agreement synchronized successfully.", ["agreement_id" => $agreement_id, "contract_ref_id" => $contract_ref]);

    } catch (Exception $e) {
        json_response(500, "Database Error: " . $e->getMessage());
    }
}

// 2. Fetch Agreement Details & Dynamic Parties
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'details') {
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

    if ($project_id <= 0) {
        json_response(400, "Invalid Project ID.");
    }

    try {
        $stmt = $conn->prepare("SELECT a.*, p.title AS project_title 
                                FROM agreements a 
                                JOIN projects p ON a.project_id = p.project_id 
                                WHERE a.project_id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $agreement = $stmt->get_result()->fetch_assoc();

        if (!$agreement) {
            json_response(404, "No agreement found for this project.");
        }

        $party_stmt = $conn->prepare("SELECT ap.*, COALESCE(u.name, u.email) AS user_name, u.email 
                                      FROM agreementparties ap 
                                      JOIN users u ON ap.user_id = u.user_id 
                                      WHERE ap.agreement_id = ?");
        $party_stmt->bind_param("i", $agreement['agreement_id']);
        $party_stmt->execute();
        $parties_res = $party_stmt->get_result();

        $parties = [];
        while ($p = $parties_res->fetch_assoc()) {
            $parties[] = $p;
        }

        json_response(200, "Agreement details fetched successfully.", [
            "agreement" => $agreement,
            "parties" => $parties
        ]);

    } catch (Exception $e) {
        json_response(500, "Database Error: " . $e->getMessage());
    }
}

// 3. Digital Sign Agreement Action (With Escrow Sync)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sign') {
    $agreement_id = isset($_POST['agreement_id']) ? intval($_POST['agreement_id']) : 0;
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    if ($agreement_id <= 0 || $user_id <= 0) {
        json_response(400, "Missing required fields.");
    }

    try {
        $stmt = $conn->prepare("UPDATE agreementparties SET is_signed = 1, signed_at = NOW() WHERE agreement_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $agreement_id, $user_id);

        if ($stmt->execute()) {
            // Check if all parties have signed
            $check_all = $conn->prepare("SELECT COUNT(*) as unsigned_count FROM agreementparties WHERE agreement_id = ? AND is_signed = 0");
            $check_all->bind_param("i", $agreement_id);
            $check_all->execute();
            $unsigned = $check_all->get_result()->fetch_assoc()['unsigned_count'];

            if ($unsigned == 0) {
                // 1. Mark Agreement as Active
                $active_stmt = $conn->prepare("UPDATE agreements SET status = 'active', finalized_at = NOW() WHERE agreement_id = ?");
                $active_stmt->bind_param("i", $agreement_id);
                $active_stmt->execute();

                // 2. Fetch Project ID for this Agreement
                $agr_info = $conn->query("SELECT project_id FROM agreements WHERE agreement_id = $agreement_id")->fetch_assoc();
                if ($agr_info) {
                    $pid = intval($agr_info['project_id']);

                    // 3. Update Investment & Escrow Vault to Active
                    $conn->query("UPDATE investments SET status = 'active', escrow_status = 'held' WHERE project_id = $pid");
                }

                // 4. Notify all parties
                $parties_res = $conn->query("SELECT user_id FROM agreementparties WHERE agreement_id = $agreement_id");
                while ($p_row = $parties_res->fetch_assoc()) {
                    createNotification($p_row['user_id'], "🎉 Agreement contract fully signed & active! Funds officially locked in Escrow Vault.");
                }
            }

            json_response(200, "Agreement signed successfully!");
        } else {
            json_response(500, "Failed to sign agreement.");
        }

    } catch (Exception $e) {
        json_response(500, "Database Error: " . $e->getMessage());
    }
}
?>