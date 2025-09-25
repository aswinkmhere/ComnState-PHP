<?php
// index.php
session_start();
$db = new PDO('sqlite:../db/app.db');
$routes = $db->query("SELECT * FROM routes")->fetchAll(PDO::FETCH_ASSOC);


$stmt = $db->query("SELECT id, name, latitude, longitude FROM nodes");
$nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $db->query("SELECT id, name, latitude, longitude FROM places");
$places = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
?>

<!DOCTYPE html>
<html>
<head>
  <title>NFS Routes</title>
  <link rel="stylesheet" href="leaflet/leaflet.css"/>
  <link rel="stylesheet" href="css/bootstrap.min.css"/>
  <link rel="stylesheet" href="css/style.css"/>
  <style>
    #map { height: 600px; width: 100%; }
    .tile {
      background: #eee; padding: 10px; margin: 10px; display: inline-block;
      font-weight: bold;
    }
  </style>
</head>
<body>
  <a href="#" class="ribbon">Dagger Website</a>
  <a href="index.php" class="ribbon-cc">
    Comn State
  </a>
  <a href="<?php if (isset($_SESSION['user'])) { echo "dashboard.php"; } else { echo "login.php"; }?>" class="ribbon-login">
    <?php if (isset($_SESSION['user'])) { echo "Dashboard"; } else { echo "Login"; }?>
  </a>
    <?php
      $totalRouteCount = $db->query("SELECT COUNT(*) FROM routes ")->fetchColumn();
      $functRouteCount = $db->query("SELECT COUNT(*) FROM routes WHERE status='functional'")->fetchColumn();
      
      $defunctRouteCount = $db->query("SELECT COUNT(*) FROM routes WHERE status='defunct'")->fetchColumn();
      $defunctRouteNames = $db->query("SELECT name FROM routes WHERE status='defunct'")
                      ->fetchAll(PDO::FETCH_COLUMN);
      $functRouteNamesStr = implode(", ", $defunctRouteNames);
      //echo $defunctCount;
    ?>
  

  <div class="col-md-12 f-left">
      <h2>NFS Routes: Unit</h2>
  </div>


  <div class="col-md-12 f-left">

    <!-- LEFT PORTION -->
    <div class="col-md-3 f-left">

      <div class="col-md-12 f-left">
        <div class="d-card t-total">
          <h3>Total : <?php echo $totalRouteCount; ?></h3>
          
        </div>
      </div>
      
      <div class="col-md-12 f-left">
        <div class="d-card t-func">
          <h3>Functional : <?php echo $functRouteCount; ?></h3>
          
          
        </div>

        <div class="d-card t-defunc">
          <h3 class="tiles-interactive" id="defunc-tiles" details="<?php echo $functRouteNamesStr; ?>">Defunct : <?php echo $defunctRouteCount; ?></h3>
          <div class="tiles-tooltip"></div>

          <ul id="routeList"></ul>

          

          

          
        </div>
      </div><!--  end of Col-md-6 -->

    </div>
    <!--end LEFT PORTION -->

    <!-- RIGHT PORTION -->
    <div class="col-md-9 f-left">
      <div id="map"></div>
  
    </div>

    
  <script src="js/jquery-3.7.1.min.js"></script>
  <script src="js/bootstrap.min.js"></script>
  <script src="leaflet/leaflet.js"></script>
  <script>
    const map = L.map('map', { crs: L.CRS.Simple, minZoom: -2.5 });
    const bounds = [[0,0], [3904,8192]];
    L.imageOverlay("images/baramulla.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    // Routes from PHP
    var routes = <?php echo json_encode($routes); ?>;
    const issueMarkers = {}; // Store L.Marker objects keyed by issue.id for easy removal
    let nodeMarkers = {};  // add this for nodes
    const nodes = <?php echo json_encode($nodes); ?>;

    let placeMarkers = {};  // add this for nodes
    const places = <?php echo json_encode($places); ?>;


    routes.forEach(r => {
        try {
            let coords = [];
            try {
            coords = JSON.parse(r.coordinates);
            } catch(e) {
            console.error("Bad coordinates:", r.coordinates);
            }
            let color = (r.status === "functional") ? "#f801b2ff" : "red";
            let weight = (r.type === "double") ? 6 : 3;

            L.polyline(coords, {color: color, weight: weight})
            .bindPopup("User: " + r.user_id + "<br>Status: " + r.status + "<br>Last Updated: " + r.last_status_time)
            .addTo(map);
        } catch(e) {
            console.error("Bad points data:", r.points);
        }
    });

    function addIssueMarkerToMap(issue, iconUrl="", shadowUrl="") {
        if (!issue || typeof issue.lat === 'undefined' || typeof issue.lng === 'undefined' || !issue.id) {
            console.error("Invalid issue object for marker:", issue);
            return;
        }

        // Remove old marker if it already exists
        if (issueMarkers[issue.id]) {
            map.removeLayer(issueMarkers[issue.id]);
        }

        // Default to Leaflet’s built-in icons if none provided
        const markerIcon = L.icon({
            iconUrl: iconUrl || '../images/map-icons/marker-icon.png',
            shadowUrl: shadowUrl || '../images/map-icons/marker-shadow.png',
            iconSize: [25, 41],    // icon size
            iconAnchor: [12, 41],  // point of icon corresponding to marker location
            popupAnchor: [1, -34], // popup position relative to icon
            shadowSize: [41, 41]
        });

        const marker = L.marker([issue.lat, issue.lng], { icon: markerIcon }).addTo(map);

        marker.bindPopup(`
            <b> ${issue.description}</b><br>
            <b>AoR :</b> ${issue.user}<br>
            <button class="issue-delete-btn" onclick="deleteIssue(${issue.id})">Delete</button>
        `);

        issueMarkers[issue.id] = marker;
    }



    function loadAllIssues() {
        fetch("comn_state.php?get_issues=1")
            .then(response => response.json())
            .then(issues => {
                issues.forEach(issue => {
                    addIssueMarkerToMap(
                        issue,
                        "../images/map-icons/marker-icon.png",
                        "../images/map-icons/marker-shadow.png"
                    );
                });
            })
            .catch(error => console.error("Failed to load issues:", error));
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
            <b>Node:</b> ${node.name}<br>
            <b>ID:</b> ${node.id}
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
            <b>Place:</b> ${node.name}<br>
            <b>ID:</b> ${node.id}
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

    document.addEventListener("DOMContentLoaded", () => {
      loadAllIssues();
      loadAllNodes();
      loadAllPlaces();
      const tooltip = document.querySelector(".tiles-tooltip");

      document.querySelectorAll(".tiles-interactive").forEach(el => {
        el.addEventListener("mouseenter", e => {
          //tooltip.innerText = el.getAttribute("details");
          details = el.getAttribute("details");
          if (details) {
            const items = details.split(",").map(s => s.trim()); // split by comma
            tooltip.innerHTML = "<ul style='margin:0; padding-left:20px; list-style-type:disc;'>"
              + items.map(item => `<li>${item}</li>`).join("")
              + "</ul>";
          } else {
            tooltip.innerText = "";
          }
          tooltip.style.display = "block";
          tooltip.style.opacity = 0.8;

        });

        el.addEventListener("mousemove", e => {
          tooltip.style.left = e.pageX + 0 + "px";
          tooltip.style.top = (e.pageY - 210) + "px";
        });

        el.addEventListener("mouseleave", () => {
          tooltip.style.opacity = 0;
        });
      });
    });
  
      
  </script>
</body>
</html>
