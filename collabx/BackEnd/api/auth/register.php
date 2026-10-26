<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $primary_role = trim($_POST['primary_role'] ?? '');
    
    // Optional Profile Data
    $idea_title = trim($_POST['idea_title'] ?? '');
    $investment_capacity = !empty($_POST['investment_capacity']) ? floatval($_POST['investment_capacity']) : NULL;
    $nid_passport_no = trim($_POST['nid_passport_no'] ?? '');

    if (empty($name) || empty($email) || empty($password) || empty($primary_role)) {
        json_response(400, 'Khub dorkari information gulo puron korun.');
    }

    // Duplicate email check
    $check_stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
    $check_stmt->bind_param("s", $email);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        json_response(409, 'Ei email-e alada account already ache.');
    }

    $conn->begin_transaction();

    try {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO Users (name, email, password, phone, primary_role, idea_title, investment_capacity, nid_passport_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssds", $name, $email, $hashed_password, $phone, $primary_role, $idea_title, $investment_capacity, $nid_passport_no);
        $stmt->execute();

        $user_id = $stmt->insert_id;

        // Auto Wallet Creation
        $wallet_stmt = $conn->prepare("INSERT INTO Wallets (user_id, balance) VALUES (?, 0.00)");
        $wallet_stmt->bind_param("i", $user_id);
        $wallet_stmt->execute();

        $conn->commit();
        json_response(200, 'Registration Successful! Login korun.');
    } catch (Exception $e) {
        $conn->rollback();
        json_response(500, 'Registration Failed: ' . $e->getMessage());
    }
}
?>