<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = new PDO('sqlite:../../db/app.db');

header('Content-Type: application/json'); // Tell the browser to expect JSON

$response = [
    'labels' => [],
    'datasets' => [
        [
            'label' => 'Serviceable',
            'data' => [],
            'backgroundColor' => 'rgba(75, 192, 192, 0.7)',
        ],
        [
            'label' => 'Unserviceable',
            'data' => [],
            'backgroundColor' => 'rgba(255, 99, 132, 0.7)',
        ]
    ]
];

// --- Logic to handle a request for a specific node ---
if (isset($_GET['node_id'])) {
    $nodeId = filter_var($_GET['node_id'], FILTER_VALIDATE_INT);
    if ($nodeId) {
        $stmt = $db->prepare("SELECT eqpt, count_serv, count_unserv FROM node_eqpt WHERE node_id = ? ORDER BY count_serv+count_unserv desc");
        $stmt->execute([$nodeId]);
        $data = $stmt->fetchAll();

        foreach ($data as $row) {
            $response['labels'][] = $row['eqpt'];
            $response['datasets'][0]['data'][] = $row['count_serv'];
            $response['datasets'][1]['data'][] = $row['count_unserv'];
        }
    }
}
// --- Logic to handle a request for a specific equipment type ---
elseif (isset($_GET['eqpt_id'])) {
    $eqptId = filter_var($_GET['eqpt_id'], FILTER_VALIDATE_INT);
    if ($eqptId) {
        $stmt = $db->prepare("SELECT node, count_serv, count_unserv FROM node_eqpt WHERE eqpt_id = ? ORDER BY count_serv+count_unserv desc");
        $stmt->execute([$eqptId]);
        $data = $stmt->fetchAll();

        foreach ($data as $row) {
            $response['labels'][] = $row['node'];
            $response['datasets'][0]['data'][] = $row['count_serv'];
            $response['datasets'][1]['data'][] = $row['count_unserv'];
        }
    }
}

// Return the final response as a JSON string
echo json_encode($response);
?>