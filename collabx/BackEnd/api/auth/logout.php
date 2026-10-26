<?php
require_once __DIR__ . '/../../config/helpers.php';

session_unset();
session_destroy();

json_response(200, 'Logged out successfully');
?>