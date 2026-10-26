<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

try {
    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);
    if (!$db) {
        json_response(500, "Database connection error.");
    }

    $sql = "SELECT 
                t.transaction_id,
                t.wallet_id,
                t.type,
                t.amount,
                t.reference,
                t.created_at,
                w.user_id,
                COALESCE(u.name, CONCAT('User #', w.user_id)) AS user_name,
                u.email
            FROM transactions t
            LEFT JOIN wallets w ON t.wallet_id = w.wallet_id
            LEFT JOIN users u ON w.user_id = u.user_id
            ORDER BY t.transaction_id DESC";

    $transactions = [];

    if ($db instanceof PDO) {
        $stmt = $db->query($sql);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($db instanceof mysqli) {
        $result = mysqli_query($db, $sql);
        while ($row = mysqli_fetch_assoc($result)) {
            $transactions[] = $row;
        }
    }

    json_response(200, "Transactions fetched successfully.", array_values($transactions));

} catch (Exception $e) {
    json_response(500, "Database error: " . $e->getMessage());
}
?>