<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// Database setup
$db = new PDO('sqlite:../db/app.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = $_SESSION['user'];

// --- Schema Enforcement ---
// 1. Create routes table
$db->exec("CREATE TABLE IF NOT EXISTS routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT,
    name TEXT,
    type TEXT,
    status TEXT,
    last_status_time TEXT,
    coordinates TEXT,
    UNIQUE(user_id, name)
)");

// 2. Create the global issues table
$db->exec("CREATE TABLE IF NOT EXISTS issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    route_id INTEGER,
    user TEXT,
    lat REAL,
    lng REAL,
    description TEXT,
    time_reported TEXT,
    FOREIGN KEY(route_id) REFERENCES routes(id)
)");

// --- API Endpoints ---

// Endpoint to fetch all reported issues for the map
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_issues'])) {
    header('Content-Type: application/json');
    $stmt = $db->query("SELECT id, route_id, user, lat, lng, description, time_reported FROM issues");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Endpoint to save/update a route
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['points'])) {
    $points = $_POST['points'];
    $decoded = json_decode($points, true);
    if ($decoded === null) {
        die("Invalid JSON format for points.");
    }

    $stmt = $db->prepare("INSERT INTO routes (user_id, name, type, status, last_status_time, coordinates)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(user_id, name) DO UPDATE SET 
            type=excluded.type,
            status=excluded.status,
            last_status_time=excluded.last_status_time,
            coordinates=excluded.coordinates");

    $stmt->execute([
        $user,
        $_POST['name'],
        $_POST['type'],
        $_POST['status'],
        date("Y-m-d H:i"),
        json_encode($decoded)
    ]);

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Endpoint to save a reported issue (via AJAX from modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_issue'])) {
    header('Content-Type: application/json');
    $routeId = !empty($_POST['route_id']) ? (int)$_POST['route_id'] : null;
    $latlngRaw = $_POST['latlng'] ?? '';
    $desc = trim($_POST['description'] ?? '');
    $issueDate = $_POST['issue_date'] ?? date("Y-m-d");
    $issueTime = $_POST['issue_time'] ?? date("H:i");
    $fullTime = $issueDate . " " . $issueTime;

    $latlng = array_map('trim', explode(",", $latlngRaw));
    if (count($latlng) < 2 || empty($desc)) {
        echo json_encode(["success" => false, "error" => "Invalid location or empty description."]);
        exit;
    }
    $lat = floatval($latlng[0]);
    $lng = floatval($latlng[1]);

    $stmt = $db->prepare("INSERT INTO issues (route_id, user, lat, lng, description, time_reported)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$routeId, $user, $lat, $lng, $desc, $fullTime]);
    $issueId = $db->lastInsertId();

    echo json_encode([
        "success" => true,
        "issue" => [
            "id" => $issueId,
            "route_id" => $routeId,
            "lat" => $lat, "lng" => $lng,
            "description" => $desc,
            "time_reported" => $fullTime,
            "user" => $user
        ]
    ]);
    exit;
}

// Endpoint to delete an issue (via AJAX from marker popup)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_issue_id'])) {
    header('Content-Type: application/json');
    $issueId = (int)$_POST['delete_issue_id'];
    
    // Optional: Add a check here to ensure only the user who reported the issue (or an admin) can delete it
    $stmt = $db->prepare("DELETE FROM issues WHERE id = ? AND user = ?"); // Added user check
    $success = $stmt->execute([$issueId, $user]);

    if ($success && $stmt->rowCount() > 0) {
        echo json_encode(["success" => true, "id" => $issueId]);
    } else {
        echo json_encode(["success" => false, "error" => "Issue not found or you don't have permission to delete it."]);
    }
    exit;
}


// Endpoint to toggle a route's status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)$_POST['toggle_id'];
    $status_date = $_POST['status_date'] ?? '0000-00-00';
    $status_time = $_POST['status_time'] ?? '00:00';
    $full_status_time = $status_date . ' ' . $status_time;

    $routeStmt = $db->prepare("SELECT status FROM routes WHERE id = ?");
    $routeStmt->execute([$id]);
    $route = $routeStmt->fetch(PDO::FETCH_ASSOC);
    if ($route) {
        $newStatus = ($route['status'] === 'functional') ? 'defunct' : 'functional';
        $db->prepare("UPDATE routes SET status=?, last_status_time=? WHERE id=?")
           ->execute([$newStatus, $full_status_time, $id]);
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// --- Page Load Data Fetch ---
$stmt = $db->prepare("SELECT * FROM routes WHERE user_id = ?");
$stmt->execute([$user]);
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <title>Dashboard - <?php echo htmlspecialchars($user); ?></title>
  <link rel="stylesheet" href="leaflet/leaflet.css"/>
  <style>
    body { font-family: sans-serif; }
    #map { height: 600px; width: 100%; border-radius: 8px; border: 1px solid #ccc; }
    #routeList { margin-top: 20px; padding-left: 20px; }
    #routeList li { margin: 8px 0; }
    #issueModal, #toggleModal {
      display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.6); justify-content: center; align-items: center; z-index: 10000;
    }
    .modal-content {
      background: #fff; padding: 25px; border-radius: 8px; width: 350px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    .modal-content h3 { margin-top: 0; }
    .modal-content label { display: block; margin-top: 10px; margin-bottom: 5px; font-weight: bold; }
    .modal-content input, .modal-content textarea, .modal-content button { width: 100%; padding: 8px; margin-bottom: 10px; box-sizing: border-box; }
    .modal-content textarea { resize: vertical; min-height: 80px; }
    .modal-content .button-group { display: flex; gap: 10px; }
    .issue-delete-btn {
        background-color: #dc3545; /* Red color */
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 4px;
        cursor: pointer;
        margin-top: 5px;
        width: auto; /* Override modal-content button width */
    }
    .issue-delete-btn:hover {
        background-color: #c82333;
    }
  </style>
</head>
<body>
  <h2>Welcome <?php echo htmlspecialchars($user); ?></h2>
  <a href="logout.php">Logout</a>

  <hr>

  <h3>Create New Route</h3>
  <form id="routeForm" method="post">
    <input type="text" name="name" placeholder="Route Name" required><br>
    <textarea name="points" id="points" placeholder='Click on map in "Draw Route" mode to add points' readonly></textarea><br>
    <select name="type">
      <option value="single">Single</option>
      <option value="double">Double</option>
    </select>
    <select name="status">
      <option value="functional">Functional</option>
      <option value="defunct">Defunct</option>
    </select>
    <button type="submit">Save Route</button>
  </form>

  <hr>

  <h3>Map Control</h3>
  <div style="margin:10px 0;">
    <button type="button" onclick="setMode('draw')">Draw Route</button>
    <button type="button" onclick="setMode('issue')">Report Issue</button>
    <button type="button" onclick="undoLastPoint()">Undo Last Point</button>
    <span id="modeIndicator" style="margin-left: 20px; font-style: italic;">Current Mode: Draw</span>
  </div>

  <div id="map"></div>

  <h3>Your Routes</h3>
  <ul id="routeList"></ul>

  <div id="issueModal">
    <div class="modal-content">
      <h3>Report an Issue</h3>
      <form id="issueForm">
        <input type="hidden" name="ajax_issue" value="1">
        <input type="hidden" name="route_id" id="modalRouteId">
        
        <label for="issueDescription">Description:</label>
        <textarea name="description" id="issueDescription" placeholder="Describe the issue..." required></textarea>
        
        <label for="issueDate">Date:</label>
        <input type="date" name="issue_date" id="issueDate" required>
        
        <label for="issueTime">Time:</label>
        <input type="time" name="issue_time" id="issueTime" required>
        
        <label for="issueLatLng">Location (Lat, Lng):</label>
        <input type="text" name="latlng" id="issueLatLng" required>
        
        <div class="button-group">
          <button type="submit">Save Issue</button>
          <button type="button" onclick="closeIssueModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toggleModal">
    <div class="modal-content">
      <h3>Change Route Status</h3>
      <form id="toggleForm" method="post">
        <input type="hidden" name="toggle_id" id="toggleId">
        
        <label for="toggleDate">Date of Change:</label>
        <input type="date" name="status_date" id="toggleDate" required>
        
        <label for="toggleTime">Time of Change:</label>
        <input type="time" name="status_time" id="toggleTime" required>
        
        <div class="button-group">
          <button type="submit">Confirm</button>
          <button type="button" onclick="closeToggleModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script src="leaflet/leaflet.js"></script>
  <script>
    // --- Map Initialization ---
    const map = L.map('map', { crs: L.CRS.Simple, minZoom: -1 });
    const bounds = [[0,0], [908,1630]];
    L.imageOverlay("images/map.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    // --- State Variables ---
    let currentMode = "draw";
    let drawnLine = null;
    let drawnPoints = [];
    const userRoutes = <?php echo json_encode($routes); ?>;
    const issueMarkers = {}; // Store L.Marker objects keyed by issue.id for easy removal

    // --- Core Functions ---
    function setMode(mode) {
      currentMode = mode;
      document.getElementById('modeIndicator').innerText = `Current Mode: ${mode.charAt(0).toUpperCase() + mode.slice(1)}`;
    }

    // --- Issue Handling ---
    function addIssueMarkerToMap(issue) {
        if (!issue || typeof issue.lat === 'undefined' || typeof issue.lng === 'undefined' || !issue.id) {
            console.error("Invalid issue object for marker:", issue);
            return;
        }

        // Check if marker already exists for this issue ID
        if (issueMarkers[issue.id]) {
            map.removeLayer(issueMarkers[issue.id]); // Remove old marker if it exists
        }

        const marker = L.circleMarker([issue.lat, issue.lng], { radius: 8, color: 'orange', fillColor: '#ffc107', fillOpacity: 0.8 }).addTo(map);
        marker.bindPopup(`
            <b>Issue:</b> ${issue.description}<br>
            <b>Reported by:</b> ${issue.user}<br>
            <b>Time:</b> ${issue.time_reported}<br>
            <button class="issue-delete-btn" onclick="deleteIssue(${issue.id})">Delete</button>
        `);
        issueMarkers[issue.id] = marker; // Store the marker
    }

    function loadAllIssues() {
        fetch("dashboard.php?get_issues=1")
            .then(response => response.json())
            .then(issues => {
                issues.forEach(addIssueMarkerToMap);
            })
            .catch(error => console.error("Failed to load issues:", error));
    }

    async function deleteIssue(issueId) {
        if (!confirm("Are you sure you want to delete this issue?")) {
            return;
        }

        try {
            const formData = new FormData();
            formData.append('delete_issue_id', issueId);
            formData.append('user', '<?php echo $user; ?>'); // Pass current user for server-side permission check

            const response = await fetch("dashboard.php", {
                method: "POST",
                body: formData
            });
            const data = await response.json();

            if (data.success) {
                if (issueMarkers[data.id]) {
                    map.removeLayer(issueMarkers[data.id]); // Remove marker from map
                    delete issueMarkers[data.id]; // Remove from our tracking object
                }
                alert("Issue deleted successfully!");
            } else {
                alert("Failed to delete issue: " + (data.error || "Unknown error."));
            }
        } catch (error) {
            alert("An error occurred while trying to delete the issue.");
            console.error("Delete issue error:", error);
        }
    }


    // --- Route Drawing ---
    userRoutes.forEach((route) => {
      try {
        const coords = JSON.parse(route.coordinates);
        const color = (route.status === "functional") ? "green" : "red";
        const weight = (route.type === "double") ? 6 : 3;
        
        const polyline = L.polyline(coords, { color: color, weight: weight })
          .bindPopup(`<b>Route:</b> ${route.name}<br><b>Status:</b> ${route.status}<br><b>Last Status Change:</b> ${route.last_status_time || "N/A"}`)
          .addTo(map);

        // Add click listener to existing routes for reporting issues on them
        polyline.on('click', (e) => {
            if (currentMode === 'issue') {
                L.DomEvent.stopPropagation(e); // Prevent map click from firing
                openIssueModal(route.id, e.latlng);
            }
        });

        // Add route to the list below the map
        const li = document.createElement("li");
        li.innerHTML = `${route.name} (${route.status}) 
          <form method="post" style="display:inline;" onsubmit="return confirmToggle(this, ${route.id})">
            <button type="submit">Toggle Status</button>
          </form>
          <button type="button" onclick='openIssueModal(${route.id}, null)'>Report Issue on Route</button>`;
        document.getElementById("routeList").appendChild(li);

      } catch(e) { console.error("Could not parse route data:", e, route); }
    });

    map.on('click', function(e) {
      const lat = e.latlng.lat.toFixed(4);
      const lng = e.latlng.lng.toFixed(4);

      if (currentMode === "draw") {
        const newPoint = [parseFloat(lat), parseFloat(lng)];
        drawnPoints.push(newPoint);
        document.getElementById("points").value = JSON.stringify(drawnPoints);

        if (!drawnLine) {
          drawnLine = L.polyline([newPoint], { color: "blue", dashArray: '5, 5' }).addTo(map);
        } else {
          drawnLine.addLatLng(newPoint);
        }
      } else if (currentMode === "issue") {
        openIssueModal(null, e.latlng); // null routeId for general map issue
      }
    });

    function undoLastPoint() {
      if (currentMode !== 'draw' || drawnPoints.length === 0) return;
      drawnPoints.pop();
      document.getElementById("points").value = JSON.stringify(drawnPoints);
      if (drawnLine) {
        map.removeLayer(drawnLine);
        drawnLine = null; // reset
        if (drawnPoints.length > 0) {
          drawnLine = L.polyline(drawnPoints, { color: "blue", dashArray: '5, 5' }).addTo(map);
        }
      }
    }
    
    document.addEventListener("keydown", function(e) {
      if (e.key === "Backspace" && currentMode === "draw") {
        e.preventDefault();
        undoLastPoint();
      }
    });

    // --- Modal Handling ---
    function openIssueModal(routeId = null, latlng = null) {
      document.getElementById('issueForm').reset();
      document.getElementById('modalRouteId').value = routeId || "";
      
      const now = new Date();
      document.getElementById('issueDate').value = now.toISOString().split('T')[0];
      document.getElementById('issueTime').value = String(now.getHours()).padStart(2,'0') + ":" + String(now.getMinutes()).padStart(2,'0');
      
      if (latlng) {
        document.getElementById('issueLatLng').value = `${latlng.lat.toFixed(4)},${latlng.lng.toFixed(4)}`;
      } else {
        document.getElementById('issueLatLng').value = ''; // Clear if no latlng provided
      }
      
      document.getElementById('issueModal').style.display = "flex";
    }

    function closeIssueModal() {
      document.getElementById('issueModal').style.display = "none";
    }

    function confirmToggle(form, routeId) {
      event.preventDefault(); // Prevent direct form submission
      document.getElementById('toggleId').value = routeId;
      const now = new Date();
      document.getElementById('toggleDate').value = now.toISOString().split('T')[0];
      document.getElementById('toggleTime').value = String(now.getHours()).padStart(2,'0') + ":" + String(now.getMinutes()).padStart(2,'0');
      document.getElementById('toggleModal').style.display = "flex";
      // Store reference to the original form to submit it later
      window._toggleFormRef = form;
      return false;
    }

    function closeToggleModal() {
      document.getElementById('toggleModal').style.display = "none";
    }

    // --- Event Listeners ---
    document.getElementById("issueForm").addEventListener("submit", async function(e){
      e.preventDefault();
      const formData = new FormData(this);
      
      try {
        const response = await fetch("dashboard.php", { method: "POST", body: formData });
        const data = await response.json();

        if(data.success) {
          addIssueMarkerToMap(data.issue); // Add new issue marker to map instantly
          closeIssueModal();
        } else {
          alert("Failed to save issue: " + (data.error || "Unknown server error."));
        }
      } catch (err) {
        alert("An error occurred. Could not save issue.");
        console.error("Save issue error:", err);
      }
    });

    document.getElementById("toggleForm").addEventListener("submit", function(e){
      e.preventDefault();
      // Combine date and time and append to the original form before submitting
      const date = document.getElementById("toggleDate").value;
      const time = document.getElementById("toggleTime").value;
      if (!date || !time) {
          alert("Please select a valid date and time.");
          return;
      }
      
      const originalForm = window._toggleFormRef;
      // Add hidden inputs for date and time to the original form
      let hiddenDate = document.createElement("input");
      hiddenDate.type = "hidden"; hiddenDate.name = "status_date"; hiddenDate.value = date;
      originalForm.appendChild(hiddenDate);

      let hiddenTime = document.createElement("input");
      hiddenTime.type = "hidden"; hiddenTime.name = "status_time"; hiddenTime.value = time;
      originalForm.appendChild(hiddenTime);
      
      originalForm.submit();
    });

    // --- Initial Load ---
    document.addEventListener('DOMContentLoaded', (event) => {
        loadAllIssues();
    });

  </script>
</body>
</html>