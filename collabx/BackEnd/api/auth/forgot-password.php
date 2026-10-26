<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        json_response(400, 'Email address din.');
    }

    $stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ? AND is_active = 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $user = $res->fetch_assoc();
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $ip = $_SERVER['REMOTE_ADDR'];

        $ins_stmt = $conn->prepare("INSERT INTO PasswordResets (user_id, token_hash, expires_at, request_ip) VALUES (?, ?, ?, ?)");
        $ins_stmt->bind_param("isss", $user['user_id'], $token_hash, $expires_at, $ip);
        $ins_stmt->execute();

        // Outputting token directly for development/testing
        json_response(200, 'Password reset link paidyachi.', [
            'reset_token' => $token,
            'reset_link' => "reset-password.html?token=" . $token
        ]);
    } else {
        json_response(404, 'Ei email-e kono active user paoya jayni.');
    }
}
?>