<?php
// HTML Warning/Notice বন্ধ রাখা যেন JSON Break না করে
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

try {
    $db_path = __DIR__ . '/../../config/db.php';
    if (!file_exists($db_path)) {
        echo json_encode(["status" => 500, "message" => "db.php file path not found!"]);
        exit;
    }
    require_once $db_path;

    // $pdo না পাওয়া গেলে $conn চেক করবে
    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

    if (!$db) {
        echo json_encode(["status" => 500, "message" => "Database connection variable not found in db.php."]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["status" => 405, "message" => "Method Not Allowed"]);
        exit;
    }

    $project_id = $_POST['project_id'] ?? null;
    $investor_id = $_POST['investor_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $equity_percentage = $_POST['equity_percentage'] ?? 0;

    if (!$project_id || !$investor_id || !$amount) {
        echo json_encode(["status" => 400, "message" => "Required fields missing."]);
        exit;
    }

    // Insert investment
    $stmt = $db->prepare("INSERT INTO investments (project_id, investor_id, amount, equity_percentage, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$project_id, $investor_id, $amount, $equity_percentage]);

    echo json_encode(["status" => 200, "message" => "Investment proposal submitted successfully!"]);

} catch (PDOException $e) {
    echo json_encode(["status" => 500, "message" => "Database Error: " . $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(["status" => 500, "message" => "Server Error: " . $e->getMessage()]);
}