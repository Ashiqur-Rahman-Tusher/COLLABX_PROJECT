<?php
// Prevent PHP Warnings/Errors from corrupting JSON output
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Uniform JSON Response Helper
function json_response($status, $message, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $data));
    exit;
}

// 2. Strict Authentication Guard for APIs
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        json_response(401, 'Unauthorized! Kripaya login korun.');
    }
}

// 3. Role Authorization Guard
function require_role($allowed_roles = []) {
    require_login();
    if (!in_array($_SESSION['role'], $allowed_roles) && empty($_SESSION['is_admin'])) {
        json_response(403, 'Access Denied! Apnar ei feature beboharer onumoti nei.');
    }
}

// 4. CSRF Token Generator & Validator
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// 5. Input Sanitization Helper (XSS Protection)
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
        return $data;
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
?>