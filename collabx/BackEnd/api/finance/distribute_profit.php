<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, "Method not allowed.");
}

$project_id = $_POST['project_id'] ?? 0;
$total_revenue = $_POST['total_revenue'] ?? 0;
$revenue_id = $_POST['revenue_id'] ?? 0;

if (!$project_id || $total_revenue <= 0) {
    json_response(400, "Valid Project ID and Revenue amount are required.");
}

try {
    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);
    if (!$db) {
        json_response(500, "Database connection error.");
    }

    // 1. If revenue_id is not passed, insert minimal entry in revenue table
    if (!$revenue_id) {
        $rev_sql = "INSERT INTO revenue (project_id, amount) VALUES (?, ?)";
        if ($db instanceof PDO) {
            $r_stmt = $db->prepare($rev_sql);
            $r_stmt->execute([$project_id, $total_revenue]);
            $revenue_id = $db->lastInsertId();
        } else if ($db instanceof mysqli) {
            $r_stmt = $db->prepare($rev_sql);
            $r_stmt->bind_param("id", $project_id, $total_revenue);
            $r_stmt->execute();
            $revenue_id = $db->insert_id;
        }
    }

    // 2. Fetch active investments for this project
    $sql = "SELECT investment_id, investor_id, equity_percentage 
            FROM investments 
            WHERE project_id = ?";

    $investments = [];
    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$project_id]);
        $investments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $investments[] = $row;
        }
    }

    if (empty($investments)) {
        json_response(404, "No investments found for this project.");
    }

    $distributed_count = 0;

    foreach ($investments as $inv) {
        $investor_id = $inv['investor_id'];
        $equity_pct = floatval($inv['equity_percentage']);
        
        $payout_amount = ($total_revenue * $equity_pct) / 100;

        if ($payout_amount > 0) {
            // A. Get/Create Investor's Wallet
            $wallet_id = 0;
            $w_sql = "SELECT wallet_id FROM wallets WHERE user_id = ?";
            if ($db instanceof PDO) {
                $w_stmt = $db->prepare($w_sql);
                $w_stmt->execute([$investor_id]);
                $w_row = $w_stmt->fetch(PDO::FETCH_ASSOC);
                $wallet_id = $w_row['wallet_id'] ?? 0;
            } else if ($db instanceof mysqli) {
                $w_stmt = $db->prepare($w_sql);
                $w_stmt->bind_param("i", $investor_id);
                $w_stmt->execute();
                $w_res = $w_stmt->get_result();
                $w_row = $w_res->fetch_assoc();
                $wallet_id = $w_row['wallet_id'] ?? 0;
            }

            if (!$wallet_id) {
                $cw_sql = "INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)";
                if ($db instanceof PDO) {
                    $cw_stmt = $db->prepare($cw_sql);
                    $cw_stmt->execute([$investor_id]);
                    $wallet_id = $db->lastInsertId();
                } else if ($db instanceof mysqli) {
                    $cw_stmt = $db->prepare($cw_sql);
                    $cw_stmt->bind_param("i", $investor_id);
                    $cw_stmt->execute();
                    $wallet_id = $db->insert_id;
                }
            }

            // B. Insert profitshare record
            $ps_sql = "INSERT INTO profitshares (revenue_id, user_id, share_percentage, share_amount, paid_status) 
                       VALUES (?, ?, ?, ?, 'paid')";
            
            // C. Update Wallet Balance
            $wallet_update_sql = "UPDATE wallets SET balance = balance + ? WHERE wallet_id = ?";

            // D. Insert Transaction Entry
            $trx_sql = "INSERT INTO transactions (wallet_id, type, amount, reference) VALUES (?, 'profit', ?, ?)";
            $trx_ref = "Profit share payout for Project #" . $project_id;

            if ($db instanceof PDO) {
                $s1 = $db->prepare($ps_sql);
                $s1->execute([$revenue_id, $investor_id, $equity_pct, $payout_amount]);

                $s2 = $db->prepare($wallet_update_sql);
                $s2->execute([$payout_amount, $wallet_id]);

                $s3 = $db->prepare($trx_sql);
                $s3->execute([$wallet_id, $payout_amount, $trx_ref]);
            } else if ($db instanceof mysqli) {
                $s1 = $db->prepare($ps_sql);
                $s1->bind_param("iidd", $revenue_id, $investor_id, $equity_pct, $payout_amount);
                $s1->execute();

                $s2 = $db->prepare($wallet_update_sql);
                $s2->bind_param("di", $payout_amount, $wallet_id);
                $s2->execute();

                $s3 = $db->prepare($trx_sql);
                $s3->bind_param("ids", $wallet_id, $payout_amount, $trx_ref);
                $s3->execute();
            }

            $distributed_count++;
        }
    }

    json_response(200, "Profit distributed successfully to {$distributed_count} investor(s).");

} catch (Exception $e) {
    json_response(500, "Database error: " . $e->getMessage());
}
?>