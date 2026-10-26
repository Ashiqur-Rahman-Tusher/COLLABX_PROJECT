<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/config/helpers.php';
require_once dirname(__DIR__, 2) . '/config/notification_helper.php';

$action = isset($_REQUEST['action']) ? sanitizeInput($_REQUEST['action']) : '';

// 1. Fetch Negotiations (By User ID for Investor Dashboard OR By Project ID)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // Case A: Fetch negotiations for a specific User (Investor OR Project Owner)
    if ($user_id > 0 && $project_id <= 0) {
        try {
            // FIX: agreements টেবিল JOIN করে চেক করা হচ্ছে এগ্রিমেন্ট অলরেডি সাইনড/কমপ্লিট কি না
            $query = "SELECT n.*, 
                             p.title AS project_title, 
                             COALESCE(u.name, u.email) AS owner_name,
                             a.status AS agreement_status
                      FROM negotiations n 
                      LEFT JOIN projects p ON n.project_id = p.project_id 
                      LEFT JOIN users u ON p.owner_id = u.user_id 
                      LEFT JOIN agreements a ON n.project_id = a.project_id
                      WHERE (n.user_id = ? OR p.owner_id = ?) 
                        AND n.status NOT IN ('accepted', 'rejected', 'completed')
                        AND (a.status IS NULL OR a.status NOT IN ('signed', 'completed', 'active', 'fully_signed'))
                      ORDER BY n.updated_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $user_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            json_response(200, "Investor negotiations fetched successfully.", [
                "commitments" => $data,
                "negotiations" => $data
            ]);

        } catch (Exception $e) {
            json_response(500, "Database Error: " . $e->getMessage());
        }
    }
    // Case B: Fetch negotiations for a specific Project
    else if ($project_id > 0) {
        try {
            $query = "SELECT n.*, 
                             COALESCE(u.name, u.email) AS full_name, 
                             u.email 
                      FROM negotiations n 
                      LEFT JOIN users u ON n.user_id = u.user_id 
                      WHERE n.project_id = ? 
                      ORDER BY n.updated_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            json_response(200, "Negotiations fetched successfully.", ["negotiations" => $data]);

        } catch (Exception $e) {
            json_response(500, "Database Error: " . $e->getMessage());
        }
    } else {
        json_response(400, "Invalid parameters. Please provide project_id or user_id.");
    }
}

// 2. Submit / Counter Offer Proposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit_proposal') {
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $negotiation_id = isset($_POST['negotiation_id']) ? intval($_POST['negotiation_id']) : 0;
    $role = isset($_POST['role']) ? sanitizeInput($_POST['role']) : 'investor';
    $proposed_percentage = isset($_POST['proposed_percentage']) ? floatval($_POST['proposed_percentage']) : 0.00;
    $proposed_salary = isset($_POST['proposed_salary']) ? floatval($_POST['proposed_salary']) : 0.00;
    $proposed_by = isset($_POST['proposed_by']) ? sanitizeInput($_POST['proposed_by']) : 'party';

    if ($project_id <= 0) {
        json_response(400, "Missing required project_id.");
    }

    try {
        if ($negotiation_id > 0) {
            $check_stmt = $conn->prepare("SELECT negotiation_id FROM negotiations WHERE negotiation_id = ?");
            $check_stmt->bind_param("i", $negotiation_id);
        } else {
            $check_stmt = $conn->prepare("SELECT negotiation_id FROM negotiations WHERE project_id = ?");
            $check_stmt->bind_param("i", $project_id);
        }
        
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if ($check_res->num_rows > 0) {
            $existing_neg = $check_res->fetch_assoc();
            $target_id = $existing_neg['negotiation_id'];

            $stmt = $conn->prepare("UPDATE negotiations SET role = ?, proposed_percentage = ?, proposed_salary = ?, proposed_by = ?, status = 'pending', updated_at = NOW() WHERE negotiation_id = ?");
            $stmt->bind_param("sddsi", $role, $proposed_percentage, $proposed_salary, $proposed_by, $target_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO negotiations (project_id, user_id, role, proposed_percentage, proposed_salary, proposed_by, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())");
            $stmt->bind_param("iisdds", $project_id, $user_id, $role, $proposed_percentage, $proposed_salary, $proposed_by);
        }

        if ($stmt->execute()) {
            createNotification($user_id, "Negotiation terms updated for project ID: #" . $project_id);
            json_response(200, "Proposal terms submitted successfully.");
        } else {
            json_response(500, "Failed to submit proposal.");
        }

    } catch (Exception $e) {
        json_response(500, "Database Error: " . $e->getMessage());
    }
}

// 3. Update Status (Accept / Reject / Counter Offer Accept)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'update_status' || $action === 'accept_proposal' || $action === 'reject_proposal')) {
    $negotiation_id = isset($_POST['negotiation_id']) ? intval($_POST['negotiation_id']) : 0;
    
    // JS থেকে ভিন্ন ভিন্ন action নাম আসলেও হ্যান্ডেল করবে
    if ($action === 'accept_proposal') {
        $status = 'accepted';
    } else if ($action === 'reject_proposal') {
        $status = 'rejected';
    } else {
        $status = isset($_POST['status']) ? sanitizeInput($_POST['status']) : '';
    }

    if ($negotiation_id <= 0 || !in_array($status, ['accepted', 'rejected'])) {
        json_response(400, "Invalid request details.");
    }

    try {
        $stmt = $conn->prepare("UPDATE negotiations SET status = ?, updated_at = NOW() WHERE negotiation_id = ?");
        $stmt->bind_param("si", $status, $negotiation_id);

        if ($stmt->execute()) {
            if ($status === 'accepted') {
                $neg_stmt = $conn->prepare("SELECT n.*, p.title as project_title, p.owner_id 
                                            FROM negotiations n 
                                            JOIN projects p ON n.project_id = p.project_id 
                                            WHERE n.negotiation_id = ?");
                $neg_stmt->bind_param("i", $negotiation_id);
                $neg_stmt->execute();
                $neg_data = $neg_stmt->get_result()->fetch_assoc();

                if ($neg_data) {
                    $project_id = intval($neg_data['project_id']);
                    $investor_id = intval($neg_data['user_id']);
                    $owner_id = intval($neg_data['owner_id']);

                    $agr_stmt = $conn->prepare("SELECT agreement_id FROM agreements WHERE project_id = ?");
                    $agr_stmt->bind_param("i", $project_id);
                    $agr_stmt->execute();
                    $agr_res = $agr_stmt->get_result();

                    if ($agr_res->num_rows > 0) {
                        $agr_row = $agr_res->fetch_assoc();
                        $agreement_id = $agr_row['agreement_id'];
                    } else {
                        $contract_ref = "AGR-" . strtoupper(uniqid());
                        $ins_agr = $conn->prepare("INSERT INTO agreements (contract_ref_id, project_id, status, created_at) VALUES (?, ?, 'draft', NOW())");
                        $ins_agr->bind_param("si", $contract_ref, $project_id);
                        $ins_agr->execute();
                        $agreement_id = $conn->insert_id;
                    }

                    createNotification($investor_id, "Negotiation accepted for '" . $neg_data['project_title'] . "'! Please sign the agreement.");
                    createNotification($owner_id, "Negotiation accepted for '" . $neg_data['project_title'] . "'!");

                    json_response(200, "Negotiation accepted! Moving to Digital Agreement.", [
                        "negotiation_id" => $negotiation_id,
                        "agreement_id" => $agreement_id,
                        "redirect" => "manage-agreement.html?agreement_id=" . $agreement_id
                    ]);
                    exit;
                }
            }

            json_response(200, "Negotiation status updated to " . $status);
        } else {
            json_response(500, "Failed to update status.");
        }

    } catch (Exception $e) {
        json_response(500, "Database Error: " . $e->getMessage());
    }
}
?>