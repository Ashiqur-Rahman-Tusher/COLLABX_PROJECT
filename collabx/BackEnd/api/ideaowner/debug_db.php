<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/db.php';

$db = isset($pdo) ? $pdo : (isset($conn) ? $conn : null);

if (!$db) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$debug = [];

// 1. joinrequests টেবিল চেক
try {
    $sql = "SELECT * FROM joinrequests LIMIT 5";
    if ($db instanceof mysqli) {
        $res = $db->query($sql);
        $debug['joinrequests_raw'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } else {
        $stmt = $db->query($sql);
        $debug['joinrequests_raw'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Exception $e) {
    $debug['joinrequests_error'] = $e->getMessage();
}

// 2. teammembers টেবিল চেক
try {
    $sql = "SELECT * FROM teammembers LIMIT 5";
    if ($db instanceof mysqli) {
        $res = $db->query($sql);
        $debug['teammembers_raw'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } else {
        $stmt = $db->query($sql);
        $debug['teammembers_raw'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Exception $e) {
    $debug['teammembers_error'] = $e->getMessage();
}

echo json_encode($debug, JSON_PRETTY_PRINT);
?>