<?php
// index.php
session_start();
$db = new PDO('sqlite:../db/app.db');
$routes = $db->query("SELECT * FROM routes")->fetchAll(PDO::FETCH_ASSOC);


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
    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -1 });
    var bounds = [[0,0], [908,1630]];
    L.imageOverlay("images/map.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    // Routes from PHP
    var routes = <?php echo json_encode($routes); ?>;
    const issueMarkers = {}; // Store L.Marker objects keyed by issue.id for easy removal

    routes.forEach(r => {
        try {
            let coords = [];
            try {
            coords = JSON.parse(r.coordinates);
            } catch(e) {
            console.error("Bad coordinates:", r.coordinates);
            }
            let color = (r.status === "functional") ? "green" : "red";
            let weight = (r.type === "double") ? 6 : 3;

            L.polyline(coords, {color: color, weight: weight})
            .bindPopup("User: " + r.user_id + "<br>Status: " + r.status)
            .addTo(map);
        } catch(e) {
            console.error("Bad points data:", r.points);
        }
    });

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
        fetch("comn_state.php?get_issues=1")
            .then(response => response.json())
            .then(issues => {
                issues.forEach(addIssueMarkerToMap);
            })
            .catch(error => console.error("Failed to load issues:", error));
    }

    document.addEventListener("DOMContentLoaded", () => {
      loadAllIssues();

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

          // const rect = el.getBoundingClientRect();
          // tooltip.style.left = rect.left + window.scrollX + "px"; // slightly right
          // tooltip.style.top = "0px"; // slightly below
        });

        el.addEventListener("mousemove", e => {
          // optional: update position while moving
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
