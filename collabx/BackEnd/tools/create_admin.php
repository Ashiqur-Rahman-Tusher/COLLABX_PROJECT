<?php
require_once __DIR__ . '/../config/db.php';

$admin_email = "admin@collabbx.com";
$admin_pass = "Admin@123456";
$admin_name = "Super Admin";

$stmt = $conn->prepare("SELECT user_id FROM Users WHERE email = ?");
$stmt->bind_param("s", $admin_email);
$stmt->execute();

if ($stmt->get_result()->num_rows > 0) {
    echo "Admin user already exists!\n";
    exit;
}

$hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
$insert = $conn->prepare("INSERT INTO Users (name, email, password, primary_role, is_admin, verification_status) VALUES (?, ?, ?, 'idea_owner', 1, 'verified')");
$insert->bind_param("sss", $admin_name, $admin_email, $hashed);

if ($insert->execute()) {
    $user_id = $insert->insert_id;
    $wallet = $conn->prepare("INSERT INTO Wallets (user_id, balance) VALUES (?, 0.00)");
    $wallet->bind_param("i", $user_id);
    $wallet->execute();

    echo "Super Admin Created Successfully!\nEmail: $admin_email\nPassword: $admin_pass\n";
} else {
    echo "Error creating admin: " . $conn->error;
}
?>