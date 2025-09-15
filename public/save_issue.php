
<?php
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit("Not logged in");
}

$db = new PDO('sqlite:../db/app.db');

// Find the user
$stmt = $db->prepare("SELECT id FROM users WHERE username=?");
$stmt->execute([$_SESSION['user']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    exit("User not found");
}

$user_id = $user['id'];

// For now: pick the first route for this user
$stmt = $db->prepare("SELECT * FROM routes WHERE user_id=? LIMIT 1");
$stmt->execute([$user_id]);
$route = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$route) {
    exit("No route found for this user");
}

// New issue
$lat  = $_POST['lat'] ?? null;
$lng  = $_POST['lng'] ?? null;
$date = $_POST['date'] ?? date('Y-m-d');
$time = $_POST['time'] ?? date('H:i');

if (!$lat || !$lng) {
    exit("Invalid coordinates");
}

$issues = [];
if (!empty($route['issues'])) {
    $issues = json_decode($route['issues'], true) ?: [];
}

$issues[] = [
    'lat'  => floatval($lat),
    'lng'  => floatval($lng),
    'date' => $date,
    'time' => $time
];

// Save back
$stmt = $db->prepare("UPDATE routes SET issues=? WHERE id=?");
$stmt->execute([json_encode($issues), $route['id']]);

echo "Issue saved!";
