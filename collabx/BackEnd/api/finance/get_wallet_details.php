<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

$user_id = $_GET['user_id'] ?? 0;

if (!$user_id) {
    json_response(400, "User ID is required.");
}

try {
    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);
    if (!$db) {
        json_response(500, "Database connection error.");
    }

    // 1. Get Wallet Balance
    $w_sql = "SELECT wallet_id, balance FROM wallets WHERE user_id = ?";
    $wallet = null;

    if ($db instanceof PDO) {
        $stmt = $db->prepare($w_sql);
        $stmt->execute([$user_id]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    } else if ($db instanceof mysqli) {
        $stmt = $db->prepare($w_sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $wallet = $res->fetch_assoc();
    }

    $wallet_id = $wallet['wallet_id'] ?? 0;
    $balance = $wallet['balance'] ?? "0.00";

    // 2. Get Transaction History
    $trx_list = [];
    if ($wallet_id) {
        $t_sql = "SELECT transaction_id, type, amount, reference, created_at 
                  FROM transactions 
                  WHERE wallet_id = ? 
                  ORDER BY transaction_id DESC";

        if ($db instanceof PDO) {
            $t_stmt = $db->prepare($t_sql);
            $t_stmt->execute([$wallet_id]);
            $trx_list = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else if ($db instanceof mysqli) {
            $t_stmt = $db->prepare($t_sql);
            $t_stmt->bind_param("i", $wallet_id);
            $t_stmt->execute();
            $t_res = $t_stmt->get_result();
            while ($row = $t_res->fetch_assoc()) {
                $trx_list[] = $row;
            }
        }
    }

    json_response(200, "Wallet details fetched successfully.", [
        "wallet_id" => $wallet_id,
        "balance" => $balance,
        "transactions" => array_values($trx_list)
    ]);

} catch (Exception $e) {
    json_response(500, "Database error: " . $e->getMessage());
}
?>