<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
session_start();

require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['status' => 500, 'message' => 'Database connection failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Catch both JSON payload and FormData/$_POST
    $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];

    $email = trim($jsonInput['email'] ?? $_POST['email'] ?? '');
    $password = trim($jsonInput['password'] ?? $_POST['password'] ?? '');
    $role = trim($jsonInput['role'] ?? $jsonInput['primary_role'] ?? $_POST['role'] ?? $_POST['primary_role'] ?? '');

    if (empty($email) || empty($password) || empty($role)) {
        echo json_encode([
            'status' => 400, 
            'message' => 'All fields (Email, Password, Role) are required'
        ]);
        exit;
    }

    try {
        $user = null;

        // DB Query according to your EXACT schema (user_id & primary_role)
        if ($db instanceof mysqli) {
            $stmt = $db->prepare("SELECT user_id, name, email, password, primary_role, is_admin FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else if ($db instanceof PDO) {
            $stmt = $db->prepare("SELECT user_id, name, email, password, primary_role, is_admin FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$user) {
            echo json_encode(['status' => 404, 'message' => 'User not found with this email!']);
            exit;
        }

        // Fetch DB Primary Role
        $dbRole = strtolower($user['primary_role']);
        $submittedRole = strtolower($role);

        // Role verify (Allow admin override or exact role match)
        if ($user['is_admin'] != 1 && $dbRole !== $submittedRole) {
            echo json_encode([
                'status' => 403, 
                'message' => 'Role mismatch! Your account role is: ' . $user['primary_role']
            ]);
            exit;
        }

        // Password verify (hashed or plain)
        $isValidPassword = password_verify($password, $user['password']) || ($password === $user['password']);

        if (!$isValidPassword) {
            echo json_encode(['status' => 401, 'message' => 'Invalid password!']);
            exit;
        }

        // Update Last Login Time
        if ($db instanceof mysqli) {
            $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
            $updateStmt->bind_param("i", $user['user_id']);
            $updateStmt->execute();
            $updateStmt->close();
        } else if ($db instanceof PDO) {
            $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
            $updateStmt->execute([$user['user_id']]);
        }

        // Login Success -> Set Session Variables
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role'] = $dbRole;
        $_SESSION['is_admin'] = $user['is_admin'];

        // Dynamic Dashboard Redirect Setup
        $redirectUrl = 'investor-dashboard.html';
        if ($user['is_admin'] == 1 || $submittedRole === 'admin') {
            $redirectUrl = 'admin-dashboard.html';
        } else if ($dbRole === 'manpower' || $dbRole === 'worker') {
            $redirectUrl = 'manpower-dashboard.html';
        } else if ($dbRole === 'idea_owner' || $dbRole === 'owner') {
            $redirectUrl = 'ideaowner-dashboard.html'; // <--- এখানে owner-dashboard এর জায়গায় ideaowner-dashboard হবে
        }

        echo json_encode([
            'status' => 200,
            'message' => 'Login successful',
            'redirect' => $redirectUrl,
            'is_admin' => $user['is_admin'],
            'role' => $dbRole,
            'user' => [
                'id' => $user['user_id'],
                'name' => $user['name'],
                'role' => $user['primary_role']
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 500, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}
?>