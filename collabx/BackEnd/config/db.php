<?php
// Correct DB Name: startup_collab
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "startup_collab";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die(json_encode([
        'status' => 500, 
        'message' => 'Database Connection Failed: ' . $conn->connect_error
    ]));
}

$conn->set_charset("utf8mb4");
?>