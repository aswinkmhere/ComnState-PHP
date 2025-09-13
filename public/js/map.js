function drawRoutes(routes) {
    const canvas = document.getElementById("mapCanvas");
    const ctx = canvas.getContext("2d");

    const Lon_min = 76.0, Lon_max = 78.0, Lat_min = 8.0, Lat_max = 10.0;
    const W = canvas.width, H = canvas.height;

    function toXY(lat, lon) {
        const x = (lon - Lon_min) / (Lon_max - Lon_min) * W;
        const y = (lat - Lat_min) / (Lat_max - Lat_min) * H;
        return [x, y];
    }

    routes.forEach(route => {
        const coords = JSON.parse(route.coordinates);
        ctx.beginPath();
        coords.forEach((point, i) => {
            const [x, y] = toXY(point.lat, point.lon);
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.strokeStyle = route.status === "functional" ? "green" : "red";
        ctx.lineWidth = route.type === "double" ? 4 : 2;
        ctx.stroke();
    });
}

// showDetails() for dashboard tile
function showDetails() {
    const defuncRoutes = routes.filter(r => r.status === "defunc");
    if (defuncRoutes.length === 0) {
        alert("No defunc routes.");
        return;
    }
    let msg = "Defunc Routes:\n\n";
    defuncRoutes.forEach(r => {
        msg += `- ${r.name} (${r.type})\n`;
    });
    alert(msg);
}
