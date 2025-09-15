<?php
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit("Not logged in");
}

$db = new PDO('sqlite:../db/app.db');

$route_id = $_POST['route_id'] ?? null;
$index    = $_POST['index'] ?? null;

if ($route_id === null || $index === null) {
    exit("Missing parameters");
}

// Load route
$stmt = $db->prepare("SELECT * FROM routes WHERE id=?");
$stmt->execute([$route_id]);
$route = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$route) {
    exit("Route not found");
}

// Remove issue
$issues = json_decode($route['issues'], true) ?: [];
if (isset($issues[$index])) {
    array_splice($issues, $index, 1);
    $stmt = $db->prepare("UPDATE routes SET issues=? WHERE id=?");
    $stmt->execute([json_encode($issues), $route_id]);
    echo "Issue deleted!";
} else {
    echo "Issue not found at index $index";
}
