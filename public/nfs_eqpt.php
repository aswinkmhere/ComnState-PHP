<?php
$pdo = new PDO('sqlite:../db/app.db');

// Fetch all nodes for the filter list
$nodes = $pdo->query("SELECT id, name FROM nodes ORDER BY name")->fetchAll();

// Fetch all equipment types for the filter list
$equipments = $pdo->query("SELECT id, nomenclature FROM eqpt_master ORDER BY nomenclature")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Dashboard</title>
    <link rel="stylesheet" href="css/bootstrap.min.css"/>
    <link rel="stylesheet" href="css/style.css"/>
    <style>
        body { font-family: sans-serif; display: flex; padding: 20px; background: linear-gradient(135deg, #0a081cff, #232047ff, #171728ff); }
        .filters h3 { margin-top: 0; }
        
        .chart-container { flex-grow: 1; padding-left: 20px; }
    </style>
</head>
<body>
    <a href="#" class="ribbon">Dagger Website</a>

    <a href="login.php" class="ribbon-login">
        Login
    </a>
    <a href="index.php" class="ribbon-cc">Comn State</a>
    <div class="filters">
        <h3>Filter by Node</h3>
        <div id="node-list">
            <?php foreach ($nodes as $node): ?>
                <a href="#" class="filter-link" data-type="node_id" data-id="<?= $node['id'] ?>"><?= htmlspecialchars($node['name']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="chart-container">
        <h2 id="chart-title">Select a filter to view data</h2>
        <canvas id="myChart"></canvas>
    </div>

    <div class="filters-eqpt">
        <h3>Filter by Equipment</h3>
        <div id="eqpt-list">
             <?php foreach ($equipments as $eqpt): ?>
                <a href="#" class="filter-link" data-type="eqpt_id" data-id="<?= $eqpt['id'] ?>"><?= htmlspecialchars($eqpt['nomenclature']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="js/chart.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const chartCanvas = document.getElementById('myChart');
        const chartTitle = document.getElementById('chart-title');
        const filterLinks = document.querySelectorAll('.filter-link');
        let myChart; // Variable to hold the chart instance

        // Function to fetch data and update the chart
        async function updateChart(type, id, titleText) {
            // Fetch data from our PHP API
            const response = await fetch(`API/get_chart_data.php?${type}=${id}`);
            const data = await response.json();

            chartTitle.textContent = titleText; // Update the chart title

            // If a chart instance already exists, destroy it
            if (myChart) {
                myChart.destroy();
            }

            // Create a new chart instance
            myChart = new Chart(chartCanvas, {
                type: 'bar',
                data: data, // Data comes directly from our API response
                options: {
                    responsive: true,
                    scales: {
                        x: { stacked: true }, // Stack bars on the X-axis
                        y: { stacked: true, beginAtZero: true } // Stack bars on the Y-axis
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Equipment Status'
                        }
                    }
                }
            });
        }

        // Add click event listeners to all filter links
        filterLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault(); // Prevent the link from navigating

                // Remove 'active' class from all links and add it to the clicked one
                filterLinks.forEach(l => l.classList.remove('active'));
                e.target.classList.add('active');
                
                const type = e.target.dataset.type; // 'node_id' or 'eqpt_id'
                const id = e.target.dataset.id;
                const titleText = e.target.textContent;

                updateChart(type, id, `Showing data for: ${titleText}`);
            });
        });
        
        // Optional: Load data for the first node by default
        if (document.querySelector('#node-list a')) {
             document.querySelector('#node-list a').click();
        }

    });
    </script>

</body>
</html>