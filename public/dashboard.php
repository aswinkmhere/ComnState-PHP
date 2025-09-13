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
        date("Y-m-d H:i"),
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

// Save reported issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_issue'])) {
    $routeId = $_POST['route_id'] ?: null;
    $issueDate = $_POST['issue_date'];
    $issueTime = $_POST['issue_time'];
    $latlng = $_POST['latlng'];

    $fullTime = $issueDate . " " . $issueTime;

    if ($routeId) {
        $route = $db->query("SELECT issues FROM routes WHERE id=".(int)$routeId)->fetch(PDO::FETCH_ASSOC);
        $currentIssues = $route && $route['issues'] ? json_decode($route['issues'], true) : [];
    } else {
        $currentIssues = [];
    }

    $currentIssues[] = ["latlng" => $latlng, "time" => $fullTime];

    if ($routeId) {
        $stmt = $db->prepare("UPDATE routes SET issues=? WHERE id=?");
        $stmt->execute([json_encode($currentIssues), $routeId]);
    }
}

$routes = $db->query("SELECT * FROM routes WHERE user_id='$user'")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Dashboard - <?php echo htmlspecialchars($user); ?></title>
  <link rel="stylesheet" href="leaflet/leaflet.css"/>
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    #map {height:600px;width:100%;}
    #controls {margin:10px 0;}
    textarea {width:100%; height:60px;}
    #routeList {margin-top:20px;}
    #routeList li {margin:5px 0;}
    #issueModal {
      display:none;
      position:fixed;
      top:0;left:0;width:100%;height:100%;
      background:rgba(0,0,0,0.6);
      justify-content:center;align-items:center;
    }
    #issueModal .modal-content {
      background:#fff;padding:20px;border-radius:8px;width:300px;
    }
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
  </form>

  <div id="map"></div>

  <h3>Your Routes</h3>
  <ul id="routeList"></ul>

  <!-- Issue Modal -->
  <div id="issueModal">
    <div class="modal-content">
      <h3>Report Issue</h3>
      <form method="post">
        <input type="hidden" name="report_issue" value="1">
        <input type="hidden" name="route_id" id="modalRouteId">
        <label>Date:</label>
        <input type="date" name="issue_date" id="issueDate" required><br>
        <label>Time:</label>
        <input type="time" name="issue_time" id="issueTime" required><br>
        <label>Location:</label>
        <input type="text" name="latlng" id="issueLatLng" placeholder="lat,lng"><br><br>
        <button type="submit">Save Issue</button>
        <button type="button" onclick="closeIssueModal()">Cancel</button>
      </form>
    </div>
  </div>

  <script src="leaflet/leaflet.js"></script>
  <script>
    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -1 });
    var bounds = [[0,0],[908,1630]];
    L.imageOverlay("images/map.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    var drawnPoints = [];
    var tempLine = null;

    // Map click opens issue modal
    map.on('click', function(e) {
      let lat = e.latlng.lat.toFixed(4);
      let lng = e.latlng.lng.toFixed(4);
      openIssueModal(null, {lat: lat, lng: lng});
    });

    var routes = <?php echo json_encode($routes); ?>;
    routes.forEach(r => {
      try {
        let coords = JSON.parse(r.coordinates);
        let color = (r.status === "functional") ? "green" : "red";
        let weight = (r.type === "double") ? 6 : 3;
        L.polyline(coords, {color: color, weight: weight})
          .bindPopup("Route: " + r.name + "<br>Status: " + r.status + "<br>Last time: " + (r.last_status_time || ""))
          .addTo(map);

        if (r.issues) {
          let issuePoints = JSON.parse(r.issues);
          issuePoints.forEach(pt => {
            L.circleMarker(pt.latlng.split(',').map(Number), {color: "red"}).bindPopup("Issue<br>"+pt.time).addTo(map);
          });
        }

        let li = document.createElement("li");
        li.innerHTML = r.name + " (" + r.status + ") " +
          `<form method="post" style="display:inline;" onsubmit="return confirmToggle(this)">
            <input type="hidden" name="toggle_id" value="${r.id}">
            <button type="submit">Toggle Status</button>
          </form>
          <button type="button" onclick='openIssueModal(${r.id}, null)'>Report Issue</button>`;
        document.getElementById("routeList").appendChild(li);
      } catch(e) { console.error("Bad coordinates:", r.coordinates); }
    });


    
    // ensure modal is appended to document.body (avoid being inside any Leaflet pane/stacking context)
    document.addEventListener('DOMContentLoaded', function() {
      const modal = document.getElementById('issueModal');
      if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
      }
    });

    // helper to disable / enable Leaflet interactions
    function disableMapInteraction(map) {
      if (!map) return;
      try { map.dragging.disable(); } catch(e) {}
      try { map.doubleClickZoom.disable(); } catch(e) {}
      try { map.scrollWheelZoom.disable(); } catch(e) {}
      try { map.boxZoom.disable(); } catch(e) {}
      try { map.keyboard.disable(); } catch(e) {}
      // if you have map.off('click',someHandler) logic you may want to keep click but usually okay
    }
    function enableMapInteraction(map) {
      if (!map) return;
      try { map.dragging.enable(); } catch(e) {}
      try { map.doubleClickZoom.enable(); } catch(e) {}
      try { map.scrollWheelZoom.enable(); } catch(e) {}
      try { map.boxZoom.enable(); } catch(e) {}
      try { map.keyboard.enable(); } catch(e) {}
    }

    // update your open/close modal functions to ensure modal is on top and map is disabled
    function openIssueModal(routeId=null, latlng=null) {
      // ensure it's attached to body (extra safety)
      const modal = document.getElementById('issueModal');
      if (modal && modal.parentElement !== document.body) document.body.appendChild(modal);

      // fill default date/time as before
      document.getElementById('modalRouteId').value = routeId || "";
      document.getElementById('issueDate').value = new Date().toISOString().split('T')[0];
      let now = new Date();
      document.getElementById('issueTime').value =
        String(now.getHours()).padStart(2,'0') + ":" + String(now.getMinutes()).padStart(2,'0');

      if (latlng) {
        document.getElementById('issueLatLng').value = `${latlng.lat},${latlng.lng}`;
      }

      // show modal and ensure it overlays everything
      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');

      // disable map interactions so user doesn't drag/zoom the map while modal is open
      if (typeof map !== 'undefined') disableMapInteraction(map);
    }

    function closeIssueModal() {
      const modal = document.getElementById('issueModal');
      if (!modal) return;
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');

      // re-enable map interactions
      if (typeof map !== 'undefined') enableMapInteraction(map);
    }

    // If you also open modal from route-list button without latlng, call openIssueModal(routeId, null).
    // If you do a map click handler that calls openIssueModal(null, e.latlng) that will auto-fill coords.

    // If using keyboard / ESC key to close modal:
    document.addEventListener('keydown', function(e) {
      const modal = document.getElementById('issueModal');
      if (!modal) return;
      if (modal.style.display === 'flex' && e.key === 'Escape') {
        closeIssueModal();
      }
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

    function openIssueModal(routeId=null, latlng=null) {
      document.getElementById('modalRouteId').value = routeId || "";

      document.getElementById('issueDate').value = new Date().toISOString().split('T')[0];
      let now = new Date();
      let hh = String(now.getHours()).padStart(2,'0');
      let mm = String(now.getMinutes()).padStart(2,'0');
      document.getElementById('issueTime').value = `${hh}:${mm}`;

      document.getElementById('issueLatLng').value = latlng ? `${latlng.lat},${latlng.lng}` : "";
      document.getElementById('issueModal').style.display = "flex";
    }

    function closeIssueModal() {
      document.getElementById('issueModal').style.display = "none";
    }
  </script>
</body>
</html>
