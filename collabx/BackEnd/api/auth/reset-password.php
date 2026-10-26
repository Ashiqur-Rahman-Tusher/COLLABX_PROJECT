<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $new_password = trim($_POST['password'] ?? '');

    if (empty($token) || empty($new_password)) {
        json_response(400, 'Token ebong Notun Password proyojon.');
    }

    $token_hash = hash('sha256', $token);

    $stmt = $conn->prepare("SELECT reset_id, user_id FROM PasswordResets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()");
    $stmt->bind_param("s", $token_hash);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 1) {
        $reset = $res->fetch_assoc();
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $conn->begin_transaction();
        try {
            // Update Password
            $upd_user = $conn->prepare("UPDATE Users SET password = ? WHERE user_id = ?");
            $upd_user->bind_param("si", $hashed_password, $reset['user_id']);
            $upd_user->execute();

            // Mark Token as Used
            $upd_reset = $conn->prepare("UPDATE PasswordResets SET used_at = NOW() WHERE reset_id = ?");
            $upd_reset->bind_param("i", $reset['reset_id']);
            $upd_reset->execute();

            $conn->commit();
            json_response(200, 'Password safolvabe reset hoyeche! Login korun.');
        } catch (Exception $e) {
            $conn->rollback();
            json_response(500, 'Password reset korte somossha hoyeche.');
        }
    } else {
        json_response(400, 'Token-ti expired ba oboidho.');
    }
}
?>