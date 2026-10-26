<?php
// Prevent any PHP HTML notice/warning breaking JSON response
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

// Get Logged-in User ID from Session
$user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['user']['user_id'] ?? $_SESSION['user']['id'] ?? 0;

try {
    // Base SQL Query: Fetch approved projects
    $sql = "SELECT p.*, COALESCE(u.name, 'Startup Owner') as owner_name 
            FROM projects p 
            LEFT JOIN users u ON p.owner_id = u.user_id 
            WHERE p.approval_status = 'approved'";

    // If Talent/Manpower User is logged in, EXCLUDE applied projects
    if ($user_id > 0) {
        $sql .= " AND p.project_id NOT IN (
            SELECT project_id FROM joinrequests WHERE user_id = $user_id
        )";
    }

    $sql .= " ORDER BY p.project_id DESC";

    $result = $conn->query($sql);

    $projects = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }

    json_response(200, 'Projects fetched successfully', [
        'projects' => $projects
    ]);

} catch (Exception $e) {
    json_response(500, 'Database error: ' . $e->getMessage());
}
?>