<?php
// AFFINE CALIBRATION: Lat/Lon -> Pixel (x,y)
// Usage: provide an array of control points (lat, lon, x, y).
// At least 3 control points recommended. Returns 6 parameters A..F.
// Then apply: x = A*lon + B*lat + C;  y = D*lon + E*lat + F;

// ----------------- Linear algebra helpers -----------------
function mat_transpose(array $m) {
    $r = [];
    $rows = count($m); if ($rows == 0) return $r;
    $cols = count($m[0]);
    for ($j=0;$j<$cols;$j++){
        $row = [];
        for ($i=0;$i<$rows;$i++) $row[] = $m[$i][$j];
        $r[] = $row;
    }
    return $r;
}
function mat_mul(array $A, array $B) {
    $r = [];
    $aR = count($A); $aC = count($A[0]);
    $bR = count($B); $bC = count($B[0]);
    if ($aC !== $bR) throw new Exception("mat_mul dim mismatch");
    for ($i=0;$i<$aR;$i++){
        $r[$i] = array_fill(0,$bC,0.0);
        for ($k=0;$k<$aC;$k++){
            for ($j=0;$j<$bC;$j++){
                $r[$i][$j] += $A[$i][$k] * $B[$k][$j];
            }
        }
    }
    return $r;
}
// Solve square linear system A * x = b using Gauss-Jordan
function solve_linear_system(array $A, array $b) {
    $n = count($A);
    // build augmented
    $aug = [];
    for ($i=0;$i<$n;$i++){
        if (count($A[$i]) !== $n) throw new Exception("A must be square");
        $aug[$i] = $A[$i];
        $aug[$i][] = $b[$i];
    }
    $eps = 1e-12;
    for ($col=0;$col<$n;$col++){
        // find pivot
        $pivot = $col;
        for ($r=$col;$r<$n;$r++){
            if (abs($aug[$r][$col]) > abs($aug[$pivot][$col])) $pivot = $r;
        }
        if (abs($aug[$pivot][$col]) < $eps) throw new Exception("Matrix singular or nearly singular");
        // swap
        if ($pivot !== $col) { $tmp=$aug[$col]; $aug[$col]=$aug[$pivot]; $aug[$pivot]=$tmp; }
        // normalize pivot row
        $div = $aug[$col][$col];
        for ($j=$col;$j<=$n;$j++) $aug[$col][$j] /= $div;
        // eliminate other rows
        for ($r=0;$r<$n;$r++){
            if ($r===$col) continue;
            $factor = $aug[$r][$col];
            if (abs($factor) < 1e-16) continue;
            for ($j=$col;$j<=$n;$j++) $aug[$r][$j] -= $factor * $aug[$col][$j];
        }
    }
    $x = array_fill(0,$n,0.0);
    for ($i=0;$i<$n;$i++) $x[$i] = $aug[$i][$n];
    return $x;
}

// ----------------- calibration function -----------------
function calibrate_affine(array $controls) {
    // controls: each item ['lat'=>, 'lon'=>, 'x'=>, 'y'=>]
    $n = count($controls);
    if ($n < 3) throw new Exception("Need at least 3 control points for robust affine calibration");
    // Build M (2n x 6) and b (2n x 1)
    $M = []; $b = [];
    foreach ($controls as $c) {
        $lon = floatval($c['lon']); $lat = floatval($c['lat']);
        $x   = floatval($c['x']);   $y   = floatval($c['y']);
        $M[] = [$lon, $lat, 1.0, 0.0, 0.0, 0.0]; $b[] = $x;
        $M[] = [0.0, 0.0, 0.0, $lon, $lat, 1.0]; $b[] = $y;
    }
    // Normal equations: (M^T M) p = M^T b
    $Mt = mat_transpose($M);                       // 6 x 2n
    $MtM = mat_mul($Mt, $M);                       // 6 x 6
    // turn b into column matrix (2n x 1) and compute Mt * b
    $bcol = []; foreach ($b as $bv) $bcol[] = [$bv];
    $Mtb_col = mat_mul($Mt, $bcol);                // 6 x 1
    // convert Mtb_col to simple vector
    $Mtb = array_map(function($r){ return $r[0]; }, $Mtb_col);
    // Solve 6x6
    $params = solve_linear_system($MtM, $Mtb);     // returns array of 6 elements
    // Return associative
    return [
        'A' => $params[0], 'B' => $params[1], 'C' => $params[2],
        'D' => $params[3], 'E' => $params[4], 'F' => $params[5],
    ];
}

function apply_affine(array $params, $lat, $lon) {
    // returns [x,y]
    $A = $params['A']; $B = $params['B']; $C = $params['C'];
    $D = $params['D']; $E = $params['E']; $F = $params['F'];
    $x = $A*$lon + $B*$lat + $C;
    $y = $D*$lon + $E*$lat + $F;
    return [ $x, $y ];
}

// ----------------- reprojection diagnostics -----------------
function reproj_errors(array $params, array $controls) {
    $errs = [];
    foreach ($controls as $c) {
        list($pred_x, $pred_y) = apply_affine($params, $c['lat'], $c['lon']);
        $dx = $pred_x - $c['x'];
        $dy = $pred_y - $c['y'];
        $errs[] = [
            'lat'=>$c['lat'],'lon'=>$c['lon'],
            'x'=>$c['x'],'y'=>$c['y'],
            'pred_x'=>$pred_x,'pred_y'=>$pred_y,
            'err_x'=>$dx,'err_y'=>$dy,
            'err_dist'=>sqrt($dx*$dx + $dy*$dy)
        ];
    }
    return $errs;
}


// ----------------- Example usage -----------------
$controls = [
    // Provide YOUR actual control points here:
    // Example using corners + a clicked interior point (replace x,y with values from your Leaflet console)
    ['lat'=>33.8550, 'lon'=>73.8207, 'x'=>0.0,    'y'=>0.0],      // bottom-left pixel reported by Leaflet
    ['lat'=>34.4023, 'lon'=>75.0611, 'x'=>8192.0, 'y'=>3904.0],  // top-right pixel reported by Leaflet
    ['lat'=>34.19830556, 'lon'=>74.34985556, 'x'=>3541.46,  'y'=>2428.71]  // interior point you clicked
];

try {
    $params = calibrate_affine($controls);
    echo "Affine parameters:\n";
    print_r($params);

    // reprojection errors
    $errs = reproj_errors($params, $controls);
    echo "Control points reprojection errors (pixels):\n";
    foreach ($errs as $e) {
        printf("lat=%.6f lon=%.6f  orig=(%.2f,%.2f) pred=(%.2f,%.2f) err=%.3f px\n",
               $e['lat'],$e['lon'],$e['x'],$e['y'],$e['pred_x'],$e['pred_y'],$e['err_dist']);
    }

    // map a new point:
    $query_lat = 34.4121; $query_lon = 74.8749;
    list($px,$py) = apply_affine($params, $query_lat, $query_lon);
    echo "\nExample mapped pixel for ($query_lat, $query_lon): X=" . round($px,2) . " Y=" . round($py,2) . "\n";

} catch (Exception $ex) {
    echo "Error: " . $ex->getMessage() . "\n";
}