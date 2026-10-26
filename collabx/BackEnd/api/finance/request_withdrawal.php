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

$user_id = $_POST['user_id'] ?? 0;
$amount = $_POST['amount'] ?? 0;
$payout_method = $_POST['payout_method'] ?? 'Bkash'; // e.g. Bkash, Nagad, Bank
$account_details = $_POST['account_details'] ?? '';

if (!$user_id || $amount <= 0) {
    json_response(400, "User ID and valid withdrawal amount are required.");
}

try {
    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);
    if (!$db) {
        json_response(500, "Database connection error.");
    }

    // 1. Check wallet balance
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

    if (!$wallet || floatval($wallet['balance']) < $amount) {
        json_response(400, "Insufficient wallet balance.");
    }

    $wallet_id = $wallet['wallet_id'];

    // 2. Deduct wallet balance
    $deduct_sql = "UPDATE wallets SET balance = balance - ? WHERE wallet_id = ?";

    // 3. Log transaction as 'withdraw'
    $trx_sql = "INSERT INTO transactions (wallet_id, type, amount, reference) VALUES (?, 'withdraw', ?, ?)";
    $ref_note = "Withdrawal request via " . $payout_method . " (" . $account_details . ")";

    if ($db instanceof PDO) {
        $stmt1 = $db->prepare($deduct_sql);
        $stmt1->execute([$amount, $wallet_id]);

        $stmt2 = $db->prepare($trx_sql);
        $stmt2->execute([$wallet_id, $amount, $ref_note]);
    } else if ($db instanceof mysqli) {
        $stmt1 = $db->prepare($deduct_sql);
        $stmt1->bind_param("di", $amount, $wallet_id);
        $stmt1->execute();

        $stmt2 = $db->prepare($trx_sql);
        $stmt2->bind_param("ids", $wallet_id, $amount, $ref_note);
        $stmt2->execute();
    }

    json_response(200, "Withdrawal request submitted successfully.");

} catch (Exception $e) {
    json_response(500, "Database error: " . $e->getMessage());
}
?>