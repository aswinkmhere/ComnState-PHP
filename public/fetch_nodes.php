<?php

// --- Database Connection ---
try {
    // Path to your SQLite database file
    $db = new PDO('sqlite:../db/app.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    
} catch (PDOException $e) {
    // If connection fails, stop and return an error message
    header('Content-Type: application/json');
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// --- Fetch Nodes ---
try {
    $stmt = $db->query("SELECT id, name, latitude, longitude FROM nodes2");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Output as JSON ---
    // Set the content type header to signal that we're sending JSON
    header('Content-Type: application/json');
    echo json_encode($nodes);

} catch (PDOException $e) {
    // If query fails, return an error
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch data: ' . $e->getMessage()]);
    exit();
}

?>