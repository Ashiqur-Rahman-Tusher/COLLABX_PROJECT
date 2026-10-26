<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

try {
    $db_path = __DIR__ . '/../../config/db.php';
    if (!file_exists($db_path)) {
        echo json_encode(["status" => 500, "message" => "db.php file path not found!"]);
        exit;
    }
    require_once $db_path;

    $db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

    if (!$db) {
        echo json_encode(["status" => 500, "message" => "Database connection variable not found."]);
        exit;
    }

    $startup_id = $_GET['startup_id'] ?? null;

    if (!$startup_id) {
        echo json_encode(["status" => 400, "message" => "Startup/User ID is required."]);
        exit;
    }

    // Exact SQL matching all three database schemas
    $sql = "SELECT 
                i.investment_id AS proposal_id,
                i.amount,
                i.equity_percentage,
                i.status,
                p.title AS project_title,
                u.name AS investor_name,
                u.email AS investor_email
            FROM investments i
            JOIN projects p ON i.project_id = p.project_id
            JOIN users u ON i.investor_id = u.user_id
            WHERE p.owner_id = ?
            ORDER BY i.investment_id DESC";

    if ($db instanceof PDO) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$startup_id]);
        $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else if ($db instanceof mysqli) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $startup_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $proposals = [];
        while ($row = $result->fetch_assoc()) {
            $proposals[] = $row;
        }
    }

    echo json_encode([
        "status" => 200,
        "data" => $proposals
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => 500, "message" => "Error: " . $e->getMessage()]);
}
?>