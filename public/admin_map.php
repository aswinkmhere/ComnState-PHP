<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['isAdmin'])) {
    header("Location: login.php?msg='admin rights reqd to access the page'");
    exit;
}

// Database setup
$db = new PDO('sqlite:../db/app.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = $_SESSION['user'];

$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$user]);
$userId = $stmt->fetchColumn();


$stmt = $db->query("SELECT id, name, latitude, longitude FROM nodes");
$nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query('SELECT 
    l.id AS link_id,
    pf.name   AS from_place,
    pf.latitude AS from_lat,
    pf.longitude AS from_lng,
    pt.name   AS to_place,
    pt.latitude AS to_lat,
    pt.longitude AS to_lng,
    l.type,l.last_status_time,
    l.status
FROM links l
JOIN places pf ON l."from" = pf.id
LEFT JOIN places pt ON l."to" = pt.id');
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Schema Enforcement ---
// 1. Routes table (unchanged)
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

// 2. Markers table
$db->exec("CREATE TABLE IF NOT EXISTS markers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user TEXT,
    lat REAL,
    lng REAL,
    type TEXT,
    icon TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

// --- API Endpoints ---

// Fetch all markers
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_markers'])) {
    header('Content-Type: application/json');
    $stmt = $db->prepare("SELECT id, user, lat, lng, type, icon FROM markers");
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Save a marker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_marker'])) {
    header('Content-Type: application/json');
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    $user = $_POST['user'] ?? '';
    $type = $_POST['type'] ?? '';

    if (!$lat || !$lng || !$type) {
        echo json_encode(["success" => false, "error" => "Missing lat/lng/type"]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO markers (user, lat, lng, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user, $lat, $lng, $type]);
    $markerId = $db->lastInsertId();

    echo json_encode([
        "success" => true,
        "marker" => [
            "id" => $markerId,
            "lat" => $lat,
            "lng" => $lng,
            "type" => $type,
            "user" => $user
        ]
    ]);
    exit;
}

// Delete marker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_marker_id'])) {
    header('Content-Type: application/json');
    $markerId = (int)$_POST['delete_marker_id'];
    $stmt = $db->prepare("DELETE FROM markers WHERE id = ?");
    $success = $stmt->execute([$markerId]);

    if ($success && $stmt->rowCount() > 0) {
        echo json_encode(["success" => true, "id" => $markerId]);
    } else {
        echo json_encode(["success" => false, "error" => "Marker not found or permission denied"]);
    }
    exit;
}

// Endpoint to toggle a route's status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $id = (int)$_POST['toggle_id'];
    $status_date = $_POST['status_date'] ?? '0000-00-00';
    $status_time = $_POST['status_time'] ?? '00:00';
    $full_status_time = $status_date . ' ' . $status_time;
    

    $routeStmt = $db->prepare("SELECT status FROM links WHERE id = ?");
    $routeStmt->execute([$id]);
    $route = $routeStmt->fetch(PDO::FETCH_ASSOC);
    if ($route) {
        $newStatus = ($route['status'] === 1) ? 0 : 1;
        $db->prepare("UPDATE links SET status=?, last_status_time=? WHERE id=?")
           ->execute([$newStatus, $full_status_time, $id]);
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}


$stmt = $db->query("SELECT id, name, latitude, longitude FROM places");
$places = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin - Map</title>
  <link rel="stylesheet" href="leaflet/leaflet.css"/>
  <link rel="stylesheet" href="css/bootstrap.min.css"/>
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    #map { height: 600px; }
    #markerModal {
        display:none;
        position:fixed; top:0; left:0; right:0; bottom:0;
        background: rgba(0,0,0,0.5);
        justify-content:center; align-items:center;
    }
    #markerModal .modal-content {
        background:#fff; padding:20px; border-radius:8px; width:300px;
    }
  </style>
</head>
<body>
   <a href="#" class="ribbon">Dagger Website</a>
   <a href="index.php" class="ribbon-cc">Comn State</a>
   <a href="logout.php" class="ribbon-login">Logout</a>

   <section>
    <div class="col-md-12 f-left">
      <h2>Dashboard : <?php echo htmlspecialchars($user); ?></h2>
    </div>

    <div class="col-md-12 f-left">
      <!-- LEFT -->
      <div class="col-md-3 f-left">
        
        
        <div class="col-md-12 f-left">
          <div class="d-card">
            <h3>Map Control</h3>
            <div class="btn-container">
              <button class="btn btn-warning" type="button" onclick="window.location.href='dashboard.php'">User Dashboard</button>
              <button class="btn btn-info" type="button" onclick="setMode('marker')">Add Marker</button>
            </div>
          </div>
        </div>

        <div class="col-md-12 f-left">
          <div class="d-card">
            <div class="container">
              <h5>Choose items</h5>

              <div class="mb-2">
                <button id="selectAllBtn" class="btn btn-sm btn-outline-primary">Select All</button>
                <button id="clearBtn" class="btn btn-sm btn-outline-secondary">Clear</button>
              </div>

              <div class="list-group">
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 1"> MW
                </label>
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 2"> Satl
                </label>
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 3"> BBR
                </label>
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 4"> URRF
                </label>
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 5"> RRF
                </label>
                <label class="list-group-item">
                  <input class="form-check-input me-2" type="checkbox" value="Item 5"> DMR
                </label>
              </div>

              <div class="mt-3">
                <button id="showBtn" class="btn btn-primary">Show Selected</button>
                <div id="selectedOutput" class="mt-2"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div class="col-md-9 f-left map-box">
        <div id="map"></div>
      </div>
    </div>
   </section>

   <!-- Marker Modal -->
   <div id="markerModal">
     <div class="modal-content">
       <h3>Add Marker</h3>
       <form id="markerForm">
         <input type="hidden" name="ajax_marker" value="1">
         <label>Type:</label>
         <select class="form-control" name="type" id="markerType" required>
           <option value="">Select...</option>
           <option value="MW">MW</option>
           <option value="Satellite">Satellite</option>
           <option value="BBR">BBR</option>
           <option value="URRF">URRF</option>
           <option value="RRF">RRF</option>
           <option value="DMR">DMR</option>
         </select>
         <div class="space"></div>
         <label>User:</label>
         <select class="form-control" name="user" id="markerUser" required>
           <option value="">Select...</option>
           <option value="user1">User1</option>
           <option value="user2">User2</option>
           <option value="user3">User3</option>
         </select>
         <br><hr>
         <label>Latitude:</label>
         <input type="text" name="lat" id="markerLat" required>
         <label>Longitude:</label>
         <input type="text" name="lng" id="markerLng" required>
         <div class="button-group">
           <button class="btn btn-success" type="submit">Save</button>
           <button class="btn btn-secondary" type="button" onclick="closeMarkerModal()">Cancel</button>
         </div>
       </form>
     </div>
   </div>

    <div id="toggleModal">
      <div class="modal-content">
        <h3>Change Link Status</h3>
        <form id="toggleForm" method="post">
          <input type="hidden" name="toggle_id" id="toggleId">
          
          <label for="toggleDate">Date of Change:</label>
          <input type="date" name="status_date" id="toggleDate" required>
          
          <label for="toggleTime">Time of Change:</label>
          <input type="time" name="status_time" id="toggleTime" required>
          
          <div class="button-group">
            <button class="btn btn-success" type="submit">Confirm</button>
            <button class="btn btn-alert" type="button" onclick="closeToggleModal()">Cancel</button>
          </div>
        </form>
      </div>
    </div>

   <script src="leaflet/leaflet.js"></script>
   <script>
    // Map init
    const map = L.map('map', { crs: L.CRS.Simple, minZoom: -2.5 });
    const bounds = [[0,0], [3904,8192]];
    L.imageOverlay("images/baramulla.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    const markers = {};

    let nodeMarkers = {};  // add this for nodes
    const nodes = <?php echo json_encode($nodes); ?>;

     let placeMarkers = {}; 
    const places = <?php echo json_encode($places); ?>;

    const selectAllBtn = document.getElementById('selectAllBtn');
    const clearBtn = document.getElementById('clearBtn');
    const showBtn = document.getElementById('showBtn');
    const output = document.getElementById('selectedOutput');

    var links = <?php echo json_encode($links); ?>;

    links.forEach(r => {
         try {
            // Build coordinates directly from DB values
            let coords = [
                [r.from_lat, r.from_lng],
                [r.to_lat, r.to_lng]
            ];

            let color = (r.status === 1) ? "#01bef8ff" : "red";
            let status = (r.status === 1) ? "Functional" : "Defunct";
            let lastTime = (
                String(r.last_status_time).trim() === '' )
               ? 'NA'
               : r.last_status_time;

            L.polyline(coords, { color: color })
              .bindPopup(`
                  ${r.type} link : ${r.from_place} - ${r.to_place}
                  <br>${status} : ${r.last_status_time}
                  <br><button class="btn btn-danger btn-tooltip" onclick="confirmToggle(null, ${r.link_id})">Toggle</button>
              `)
              .addTo(map);

        } catch(e) {
            console.error("Bad link data:", r, e);
        }
    });

    selectAllBtn.addEventListener('click', () => {
      document.querySelectorAll('.list-group input[type=checkbox]').forEach(cb => cb.checked = true);
    });
    clearBtn.addEventListener('click', () => {
      document.querySelectorAll('.list-group input[type=checkbox]').forEach(cb => cb.checked = false);
    });
    showBtn.addEventListener('click', () => {
      const vals = Array.from(document.querySelectorAll('.list-group input[type=checkbox]:checked'))
                        .map(i => i.value);
      output.innerHTML = vals.length ? `<div class="alert alert-success p-2">Selected: ${vals.join(', ')}</div>` :
                                       `<div class="text-muted">No items selected</div>`;
    });

    function openMarkerModal(latlng) {
      document.getElementById("markerForm").reset();
      document.getElementById("markerLat").value = latlng.lat.toFixed(2);
      document.getElementById("markerLng").value = latlng.lng.toFixed(2);
      document.getElementById("markerModal").style.display = "flex";
    }
    function closeMarkerModal() {
      document.getElementById("markerModal").style.display = "none";
    }

    function confirmToggle(e, routeId) {
      if(e!=null)
        e.preventDefault();                // stop default button behaviour
      window._toggleFormRef = document.getElementById('toggleForm'); // store the actual form <element>

      // fill modal inputs (you also keep toggleId for convenience)
      document.getElementById('toggleId').value = routeId;
      const now = new Date();
      document.getElementById('toggleDate').value = now.toISOString().split('T')[0];
      document.getElementById('toggleTime').value = String(now.getHours()).padStart(2,'0') + ":" + String(now.getMinutes()).padStart(2,'0');

      document.getElementById('toggleModal').style.display = "flex";
      return false;
    }

    function closeToggleModal() {
      document.getElementById('toggleModal').style.display = "none";
    }

    function getIconUrl(marker) {
      if (marker.icon) return marker.icon;
      if (marker.type === "MW") return "images/map-icons/MW.png";
      if (marker.type === "Satellite") return "images/map-icons/satl.png";
      if (marker.type === "BBR") return "images/map-icons/BBR.png";
      if (marker.type === "URRF") return "images/map-icons/URRF.png";
      if (marker.type === "RRF") return "images/map-icons/RRF.png";
      if (marker.type === "DMR") return "images/map-icons/radioTower.png";
      return "images/map-icons/satlTower.png";
    }

    function addMarkerToMap(marker) {
      if (markers[marker.id]) map.removeLayer(markers[marker.id]);

      map.createPane('topMarkers');
      map.getPane('topMarkers').style.zIndex = 4650; // higher than default markerPane (600)
      const customIcon = L.icon({
        iconUrl: getIconUrl(marker),
        shadowUrl: "images/map-icons/marker-shadow.png",
        iconSize: [25,41], iconAnchor: [12,41], popupAnchor: [1,-34], shadowSize: [41,41],
        pane: 'topMarkers'      });
      const m = L.marker([marker.lat, marker.lng], { icon: customIcon, zIndexOffset: 1000   }).addTo(map);
      m.bindPopup(`<b>${marker.type}</b><br>${marker.user}<br><button class="btn btn-danger" onclick="deleteMarker(${marker.id})">Delete</button>`);
      markers[marker.id] = m;
    }

    function loadAllMarkers() {
      fetch("<?=$_SERVER['PHP_SELF']?>?get_markers=1")
        .then(r => r.json())
        .then(data => data.forEach(addMarkerToMap));
    }

    async function deleteMarker(id) {
      if (!confirm("Delete marker?")) return;
      const fd = new FormData(); fd.append("delete_marker_id", id);
      const res = await fetch("<?=$_SERVER['PHP_SELF']?>", { method:"POST", body:fd });
      const data = await res.json();
      if (data.success) {
        map.removeLayer(markers[id]);
        delete markers[id];
      }
    }

    
    
    function addPlaceMarkerToMap(node, iconUrl, shadowUrl) {
        if (!node || node.latitude === null || node.longitude === null || !node.id || typeof node.latitude === 'undefined' ) {
            //console.error("Invalid node object for marker:", node);
            return;
        }

        // Remove old marker if it already exists
        if (placeMarkers[node.id]) {
            map.removeLayer(placeMarkers[node.id]);
        }

        const markerIcon = L.icon({
            iconUrl: 'images/map-icons/location-pin.png',
            shadowUrl: shadowUrl || '',
            iconSize: [20, 28],    // adjust based on balloon image
            iconAnchor: [16, 48],  // bottom center of the balloon points to location
            popupAnchor: [0, -48]  // popup above the balloon
        });
        

        //const marker = L.marker([node.latitude, node.longitude], { icon: markerIcon }).addTo(map);

        const marker = L.marker([node.latitude, node.longitude], { icon: markerIcon })
            .bindTooltip(node.name, { permanent: true, direction: 'right', offset: [-10, -10] })
            .addTo(map);

        marker.bindPopup(`
            <b>Place:</b> ${node.name}
        `);

        placeMarkers[node.id] = marker;
    }

    function loadAllPlaces() {
        places.forEach(place => {
            addPlaceMarkerToMap(
                place,
                "../images/map-icons/location-pin.png"  // your custom balloon icon
            );
        });
    }

    function addNodeMarkerToMap(node, iconUrl, shadowUrl) {
        if (!node || node.latitude === null || node.longitude === null || !node.id || typeof node.latitude === 'undefined' ) {
            //console.error("Invalid node object for marker:", node);
            return;
        }

        // Remove old marker if it already exists
        if (nodeMarkers[node.id]) {
            map.removeLayer(nodeMarkers[node.id]);
        }

        const markerIcon = L.icon({
            iconUrl: 'images/map-icons/marker-icon.png',
            shadowUrl: shadowUrl || '',
            iconSize: [25, 36],    // adjust based on balloon image
            iconAnchor: [16, 48],  // bottom center of the balloon points to location
            popupAnchor: [0, -48]  // popup above the balloon
        });
        

        //const marker = L.marker([node.latitude, node.longitude], { icon: markerIcon }).addTo(map);

        const marker = L.marker([node.latitude, node.longitude], { icon: markerIcon })
            .bindTooltip(node.name, { permanent: true, direction: 'right', offset: [-5, -5] })
            .addTo(map);

        marker.bindPopup(`
            <b>Node:</b> ${node.name}
        `);

        nodeMarkers[node.id] = marker;
    }

    function loadAllNodes() {
        nodes.forEach(node => {
            addNodeMarkerToMap(
                node,
                "../images/map-icons/marker-icon.png"  // your custom balloon icon
            );
        });
    }

    document.getElementById("markerForm").addEventListener("submit", async function(e){
      e.preventDefault();
      const fd = new FormData(this);
      const res = await fetch("<?=$_SERVER['PHP_SELF']?>", { method:"POST", body:fd });
      const data = await res.json();
      if (data.success) {
        addMarkerToMap(data.marker);
        closeMarkerModal();
      }
    });

    map.on("click", function(e) {
      openMarkerModal(e.latlng);

      const img = document.querySelector('.leaflet-image-layer'); 

      const rect = img.getBoundingClientRect();
      const x = e.originalEvent.clientX - rect.left;
      const y = e.originalEvent.clientY - rect.top;
      console.log('LATLON:', e.latlng.lat, e.latlng.lng, 'IMAGE PIXELS:', x, y);
    });


    document.addEventListener('DOMContentLoaded', (event) => {
        
        loadAllNodes();
        loadAllPlaces();
        loadAllMarkers();
    });
   </script>
   <script src="js/bootstrap.min.js"></script>
</body>
</html>
