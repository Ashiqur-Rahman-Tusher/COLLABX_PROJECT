<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$role = isset($_GET['role']) ? $_GET['role'] : '';

try {
    if ($role === 'idea_owner') {
        // Idea owner sees investment proposals for their projects
        $sql = "SELECT i.*, p.title as project_title, u.name as investor_name, u.email as investor_email 
                FROM investments i 
                JOIN projects p ON i.project_id = p.project_id 
                JOIN users u ON i.investor_id = u.user_id 
                WHERE p.owner_id = ? 
                ORDER BY i.investment_id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    } else {
        // Investor sees their own sent investments
        $sql = "SELECT i.*, p.title as project_title, u.name as owner_name 
                FROM investments i 
                JOIN projects p ON i.project_id = p.project_id 
                JOIN users u ON p.owner_id = u.user_id 
                WHERE i.investor_id = ? 
                ORDER BY i.investment_id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    
    $investments = [];
    while ($row = $result->fetch_assoc()) {
        $investments[] = $row;
    }

    json_response(200, 'Investments fetched', ['investments' => $investments]);

} catch (Exception $e) {
    json_response(500, 'Database error: ' . $e->getMessage());
}
?>