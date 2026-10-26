<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET");

// Database connection include korun (Apnar db file path onushare modify korun)
require_once "../../config/db.php"; 

$data = json_decode(file_get_contents("php://input"), true);

$user_id = isset($data['user_id']) ? intval($data['user_id']) : (isset($_GET['user_id']) ? intval($_GET['user_id']) : 0);
$amount = isset($data['amount']) ? floatval($data['amount']) : (isset($_GET['amount']) ? floatval($_GET['amount']) : 0);
$payment_method = isset($data['payment_method']) ? $data['payment_method'] : (isset($_GET['payment_method']) ? $_GET['payment_method'] : 'Online Deposit');

if ($user_id <= 0 || $amount <= 0) {
    echo json_encode(["status" => 400, "message" => "Invalid user ID or deposit amount"]);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Get Wallet
    $stmt = $conn->prepare("SELECT wallet_id, balance FROM wallets WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        // Wallet na thakle toiri korbe
        $createStmt = $conn->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, ?)");
        $createStmt->bind_param("id", $user_id, $amount);
        $createStmt->execute();
        $wallet_id = $conn->insert_id;
    } else {
        $wallet = $result->fetch_assoc();
        $wallet_id = $wallet['wallet_id'];
        
        // Balance update korbe
        $updateStmt = $conn->prepare("UPDATE wallets SET balance = balance + ? WHERE wallet_id = ?");
        $updateStmt->bind_param("di", $amount, $wallet_id);
        $updateStmt->execute();
    }

    // 2. Insert into Transactions Table
    $ref = "Deposit via " . strtoupper($payment_method);
    $type = "deposit";
    $trxStmt = $conn->prepare("INSERT INTO transactions (wallet_id, type, amount, reference, created_at) VALUES (?, ?, ?, ?, NOW())");
    $trxStmt->bind_param("isds", $wallet_id, $type, $amount, $ref);
    $trxStmt->execute();

    $conn->commit();

    echo json_encode([
        "status" => 200,
        "message" => "Deposit successful!",
        "amount" => $amount
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => 500, "message" => "Deposit failed: " . $e->getMessage()]);
}
?>