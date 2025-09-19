<?php
// index.php
$db = new PDO('sqlite:../db/app.db');
$routes = $db->query("SELECT * FROM routes")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
  <title>Offline Route Map - Public</title>
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
  <h2>Route Status Dashboard</h2>
  <div class="tile">
    Defunct Routes: 
    <?php
      $defunctCount = $db->query("SELECT COUNT(*) FROM routes WHERE status='defunct'")->fetchColumn();
      echo $defunctCount;
    ?>
  </div>

  <div id="map"></div>

  <script src="leaflet/leaflet.js"></script>
  <script>
    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -1 });
    var bounds = [[0,0], [908,1630]];
    L.imageOverlay("images/map.jpg", bounds).addTo(map);
    map.fitBounds(bounds);

    // Routes from PHP
    var routes = <?php echo json_encode($routes); ?>;

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

  </script>
</body>
</html>
