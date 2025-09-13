<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit; }

$db = new PDO('sqlite:../db/app.db');
$user = $_SESSION['user'];

// Ensure schema
$db->exec("CREATE TABLE IF NOT EXISTS routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT,
    name TEXT,
    type TEXT,
    status TEXT,
    last_status_time TEXT,
    coordinates TEXT,
    issues TEXT
)");

$columns = $db->query("PRAGMA table_info(routes)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($columns, "name");
if (!in_array("last_status_time", $colNames)) {
    $db->exec("ALTER TABLE routes ADD COLUMN last_status_time TEXT");
}
if (!in_array("issues", $colNames)) {
    $db->exec("ALTER TABLE routes ADD COLUMN issues TEXT");
}

// Save / update route
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['points'])) {
    $points = $_POST['points'];
    $decoded = json_decode($points, true);
    if ($decoded === null) die("Invalid JSON format for points.");

    $issues = $_POST['issues'] ?? '[]';

    $stmt = $db->prepare("INSERT INTO routes (user_id, name, type, status, last_status_time, coordinates, issues)
        VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(user_id, name) DO UPDATE SET 
            type=excluded.type,
            status=excluded.status,
            last_status_time=excluded.last_status_time,
            coordinates=excluded.coordinates,
            issues=excluded.issues");

    $stmt->execute([
        $user,
        $_POST['name'],
        $_POST['type'],
        $_POST['status'],
        date("Y-m-d H:i"), // default save time
        json_encode($decoded),
        $issues
    ]);
}

// Toggle route status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)$_POST['toggle_id'];
    $time = $_POST['status_time'] ?? null;
    if ($time) {
        $route = $db->query("SELECT status FROM routes WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        if ($route) {
            $newStatus = ($route['status'] === 'functional') ? 'defunct' : 'functional';
            $db->prepare("UPDATE routes SET status=?, last_status_time=? WHERE id=?")
               ->execute([$newStatus, $time, $id]);
        }
    }
}

$routes = $db->query("SELECT * FROM routes WHERE user_id='$user'")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Dashboard - <?php echo htmlspecialchars($user); ?></title>
  <link rel="stylesheet" href="leaflet/leaflet.css"/>
  <style>
    #map {height:600px;width:100%;}
    #controls {margin:10px 0;}
    textarea {width:100%; height:60px;}
    #routeList {margin-top:20px;}
    #routeList li {margin:5px 0;}
  </style>
</head>
<body>
  <h2>Welcome <?php echo htmlspecialchars($user); ?></h2>
  <a href="logout.php">Logout</a>

  <form method="post">
    <input type="text" name="name" placeholder="Route Name" required><br>
    <textarea name="points" id="points" placeholder='Click on map to add points'></textarea><br>
    <select name="type">
      <option value="single">Single Line</option>
      <option value="double">Double Line</option>
    </select>
    <select name="status">
      <option value="functional">Functional</option>
      <option value="defunct">Defunct</option>
    </select>
    <input type="hidden" name="issues" id="issues">
    <button type="submit">Save Route</button>
    <button type="button" onclick="undoLastPoint()">Undo Last Point</button>
    <button type="button" onclick="undoLastIssue()">Undo Last Issue</button>
  </form>

  <div id="map"></div>

  <h3>Your Routes</h3>
  <ul id="routeList"></ul>

  <script src="leaflet/leaflet.js"></script>
  <script>
    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -1 });
    var bounds = [[0,0],[908,1630]];
    L.imageOverlay("images/map.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    var drawnPoints = [];
    var tempLine = null;
    var issueMarkers = [];

    // Keyboard: Backspace = undo route point
    document.addEventListener('keydown', function(e) {
        let active = document.activeElement;
        if (e.key === "Backspace" && active.tagName !== "INPUT" && active.tagName !== "TEXTAREA") {
            e.preventDefault();
            undoLastPoint();
        }
    });

    // Add points or issues
    map.on('click', function(e) {
        let status = document.querySelector("select[name='status']").value;

        if (status === "defunct") {
            let issueTime = prompt("Enter time for this issue point (YYYY-MM-DD HH:MM):");
            if (!issueTime) return;

            let idx = issueMarkers.length;
            let marker = L.circleMarker(e.latlng, {color: "red", radius: 6}).addTo(map);
            marker.bindPopup(`Issue<br>${issueTime}<br><button onclick="deleteIssue(${idx})">Delete</button>`);
            issueMarkers.push(marker);

            let currentIssues = JSON.parse(document.getElementById('issues').value || "[]");
            currentIssues.push({lat: e.latlng.lat, lng: e.latlng.lng, time: issueTime});
            document.getElementById('issues').value = JSON.stringify(currentIssues);
        } else {
            let latlng = [e.latlng.lat, e.latlng.lng];
            drawnPoints.push(latlng);
            if (tempLine) map.removeLayer(tempLine);
            tempLine = L.polyline(drawnPoints, {color: 'blue', weight: 2}).addTo(map);
            document.getElementById('points').value = JSON.stringify(drawnPoints);
        }
    });

    function undoLastPoint() {
      if (drawnPoints.length > 0) {
        drawnPoints.pop();
        if (tempLine) map.removeLayer(tempLine);
        if (drawnPoints.length > 0) {
          tempLine = L.polyline(drawnPoints, {color: 'blue', weight: 2}).addTo(map);
        }
        document.getElementById('points').value = JSON.stringify(drawnPoints);
      }
    }

    function undoLastIssue() {
        let currentIssues = JSON.parse(document.getElementById('issues').value || "[]");
        if (issueMarkers.length > 0) {
            let lastMarker = issueMarkers.pop();
            map.removeLayer(lastMarker);
            currentIssues.pop();
            document.getElementById('issues').value = JSON.stringify(currentIssues);
        }
    }

    function deleteIssue(index) {
        let currentIssues = JSON.parse(document.getElementById('issues').value || "[]");
        if (issueMarkers[index]) {
            map.removeLayer(issueMarkers[index]);
            issueMarkers[index] = null;
            currentIssues.splice(index,1);
            document.getElementById('issues').value = JSON.stringify(currentIssues);
        }
    }

    // Draw existing routes
    var routes = <?php echo json_encode($routes); ?>;
    routes.forEach(r => {
      try {
        let coords = JSON.parse(r.coordinates);
        let color = (r.status === "functional") ? "green" : "red";
        let weight = (r.type === "double") ? 6 : 3;
        L.polyline(coords, {color: color, weight: weight})
          .bindPopup("Route: " + r.name + "<br>Status: " + r.status + "<br>Last time: " + (r.last_status_time || ""))
          .addTo(map);

        // Show issues
        if (r.issues) {
          let issuePoints = JSON.parse(r.issues);
          issuePoints.forEach((pt, idx) => {
            L.circleMarker([pt.lat, pt.lng], {color: "red"}).bindPopup("Issue<br>"+pt.time).addTo(map);
          });
        }

        // Add to list
        let li = document.createElement("li");
        li.innerHTML = r.name + " (" + r.status + ") " +
        `<button type="button" onclick='editRoute(${JSON.stringify(r)})'>Edit</button>` +
        `<form method="post" style="display:inline;" onsubmit="return confirmToggle(this)">
            <input type="hidden" name="toggle_id" value="${r.id}">
            <button type="submit">Toggle Status</button>
        </form>` +
        (r.status === "defunct" ? `<button type="button" onclick='markIssues(${JSON.stringify(r)})'>Mark Issues</button>` : "");
        document.getElementById("routeList").appendChild(li);

      } catch(e) { console.error("Bad coordinates:", r.coordinates); }
    });

    function confirmToggle(form) {
        let time = prompt("Enter time for this status change (YYYY-MM-DD HH:MM):");
        if (!time) return false;
        let input = document.createElement("input");
        input.type = "hidden";
        input.name = "status_time";
        input.value = time;
        form.appendChild(input);
        return true;
    }

    function editRoute(route) {
      drawnPoints = JSON.parse(route.coordinates);
      if (tempLine) map.removeLayer(tempLine);
      tempLine = L.polyline(drawnPoints, {color: 'blue', weight: 2}).addTo(map);
      document.getElementById('points').value = JSON.stringify(drawnPoints);
      document.querySelector("input[name='name']").value = route.name;
      document.querySelector("select[name='type']").value = route.type;
      document.querySelector("select[name='status']").value = route.status;

      // reset issues
      issueMarkers.forEach(m => { if (m) map.removeLayer(m); });
      issueMarkers = [];
      document.getElementById('issues').value = route.issues || "[]";
      if (route.issues) {
        JSON.parse(route.issues).forEach(pt => {
          let marker = L.circleMarker([pt.lat, pt.lng], {color: "red"}).addTo(map);
          issueMarkers.push(marker);
        });
      }
    }

    function markIssues(route) {
        issueMarkers.forEach(m => { if (m) map.removeLayer(m); });
        issueMarkers = [];
        let issues = [];
        if (route.issues) {
            issues = JSON.parse(route.issues);
            issues.forEach(pt => {
                let marker = L.circleMarker([pt.lat, pt.lng], {color: "red"}).addTo(map);
                issueMarkers.push(marker);
            });
        }
        document.querySelector("input[name='name']").value = route.name;
        document.querySelector("select[name='status']").value = "defunct";
        document.querySelector("select[name='type']").value = route.type;
        document.getElementById('points').value = route.coordinates;
        document.getElementById('issues').value = JSON.stringify(issues);
        alert("Click on the map to add issue spots for " + route.name);
    }
  </script>
</body>
</html>
