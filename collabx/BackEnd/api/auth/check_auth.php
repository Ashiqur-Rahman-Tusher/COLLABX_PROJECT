<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

require_login();

$user_id = $_SESSION['user_id'];

// Get live wallet balance & verification status
$stmt = $conn->prepare("
    SELECT u.user_id, u.name, u.email, u.primary_role, u.is_admin, u.verification_status, w.balance 
    FROM Users u 
    LEFT JOIN Wallets w ON u.user_id = w.user_id 
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

json_response(200, 'Authenticated', [
    'user' => $user_data,
    'csrf_token' => generate_csrf_token()
]);
?>