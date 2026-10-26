<?php
header('Content-Type: application/json');
session_start();

if (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
} else if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
}

$db = isset($conn) ? $conn : (isset($pdo) ? $pdo : null);

// investor_id ba user_id URL parameters athoba Session theke nibe
$investor_id = $_GET['investor_id'] ?? $_GET['user_id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
$investor_id = intval($investor_id);

try {
    $portfolio = [];
    if ($db instanceof mysqli && $investor_id > 0) {
        $sql = "SELECT i.investment_id, i.project_id, i.amount, i.equity_percentage, i.escrow_status, i.status, i.invested_at,
                       p.title as project_title, p.expected_roi 
                FROM investments i 
                JOIN projects p ON i.project_id = p.project_id 
                WHERE i.investor_id = $investor_id 
                ORDER BY i.investment_id DESC";
        
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $portfolio[] = $row;
            }
        }
    }

    echo json_encode([
        'status' => 200,
        'data' => $portfolio,
        'portfolio' => $portfolio
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
}
?>