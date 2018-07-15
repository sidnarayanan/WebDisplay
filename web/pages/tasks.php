<?php
session_start();
?>
<html>

<head>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">

    <title>Tasks</title>
    <?php
	$darkmode = false;
	if (!empty($_GET["toggledark"])) {
	    if (strpos($_GET["toggledark"], "Dark") !== false) {
		$darkmode = true;
	    }
	}
	if ($darkmode) {
	    echo "<link href=\"https://stackpath.bootstrapcdn.com/bootswatch/3.3.7/darkly/bootstrap.min.css\" rel=\"stylesheet\"  crossorigin=\"anonymous\">";
	} else {
	    echo "<link href=\"https://stackpath.bootstrapcdn.com/bootswatch/3.3.7/simplex/bootstrap.min.css\" rel=\"stylesheet\"  crossorigin=\"anonymous\">";
	}
    ?>
    <link href="..//vendor/metisMenu/metisMenu.min.css" rel="stylesheet">
    <link href="..//dist/css/sb-admin-2.css" rel="stylesheet">
    <link href="..//vendor/morrisjs/morris.css" rel="stylesheet">
    <link href="..//vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <script src="..//vendor/jquery/jquery.min.js"></script>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>


    <?php
    $db = new PDO('sqlite:/home/snarayan/flask_server/condor/tasks.sqlite');
    $task = "";
    if (!empty($_GET["task"])) {
        $task = $_GET["task"];
    }
    $since = 0;
    $ndays = NULL;
    if (!empty($_GET["ndays"])) {
        $ndays = $_GET["ndays"];
        $since = time() - (86400 * (int)$ndays);
    }
    echo " <script type=\"text/javascript\"> var task = \"".$task."\"; </script>";
    if ($task != "") {
        $task = $_GET["task"];
        $tasks = explode(",", $task);
        $cmd = "SELECT task, timestamp, starttime, lat, lon ";
        $cmd .= "FROM jobs INNER JOIN nodes ON jobs.host_id = nodes.id WHERE (";
        foreach ($tasks as $t) {
            $cmd .= "task LIKE ? OR ";
        }
        $cmd .= "0 )";
        if ($since > 0) {
            $cmd .= " AND ( starttime > ? )";
            $tasks[] = $since;
        }
        $stmt = $db->prepare($cmd); 

        echo " <script type=\"text/javascript\"> ";
	if ($darkmode) {
	    echo "var bgcolor=\"#000\";";
	    echo "var fgcolor=\"#fff\";";
	} else {
	    echo "var fgcolor=\"#000\";";
	    echo "var bgcolor=\"#fff\";";
	}
        $mapRawData = array();
        $latLon = array();
        $s2h = 0.0002778;
        $jobSummary = array();

        $stmt->execute($tasks);
        while ($rec = $stmt->fetch()) {
            $start = floatval($rec['starttime']);
            $stop = floatval($rec['timestamp']); 
            if ($start > 0 && $stop > 0 && $stop > $start) {
                $slat = sprintf("%.2f", floatval($rec['lat']));
                $slon = sprintf("%.2f", floatval($rec['lon']));
                if ($slat != 0 && $slon != 0) { 
                    $period = ($stop - $start) * $s2h; 
                    if (array_key_exists($slat, $mapRawData)) { 
                        if (array_key_exists($slon, $mapRawData[$slat])) {
                            $mapRawData[$slat][$slon] += $period;
                        } else {
                            $mapRawData[$slat][$slon] = $period;
                            $latLon[] = array($slat, $slon);
                        }
                    } else { 
                        $mapRawData[$slat] = array();
                        $mapRawData[$slat][$slon] = $period;
                        $latLon[] = array($slat, $slon);
                    }
                }
                $thisTask = $rec['task'];
                if (!array_key_exists($thisTask, $jobSummary)) {
                    $jobSummary[$thisTask] = array(
                        'name' => $thisTask,
                        'minstart' => $start,
                        'maxstop' => $stop,
                        'cpu' => 0,
                    );
                }
                $jobSummary[$thisTask]['minstart'] = min($start, $jobSummary[$thisTask]['minstart']);
                $jobSummary[$thisTask]['maxstop'] = max($stop, $jobSummary[$thisTask]['maxstop']);
                $jobSummary[$thisTask]['cpu'] += ( ($stop - $start) * $s2h );
            }
        }
        echo "var mapLat = [";  foreach ($latLon as $x) { echo sprintf("%.2f,", $x[0]); } echo "];"; 
        echo "var mapLon = [";  foreach ($latLon as $x) { echo sprintf("%.2f,", $x[1]); } echo "];"; 
        echo "var mapRawSizes = [";  
            foreach ($latLon as $x) { echo sprintf("%.2f,", $mapRawData[$x[0]][$x[1]]); } 
            echo "];"; 

        function cmp($x, $y) { 
            if ($x == $y) 
                return 0;
            return ($x['cpu'] < $y['cpu']) ? 1 : -1;
        }
        uasort($jobSummary, 'cmp');
        $ncount = 0; 
        $topTasks = array();
        foreach ($jobSummary as $taskname => $job) {
            ++$ncount; 
            $topTasks[] = $taskname;
            if ($ncount == 6) {
                break;
            }
        }
        echo " var barTicks=["; 
            foreach ($topTasks as $taskname) { echo sprintf("\"%s\",",$taskname); } 
            echo "];";
        echo "var barCpuY=["; 
            foreach ($topTasks as $taskname) { echo sprintf("%f,",$jobSummary[$taskname]['cpu']); } 
            echo "];";
        echo "var barUserY=["; 
            foreach ($topTasks as $taskname) { 
                echo sprintf("%f,",$s2h*($jobSummary[$taskname]['maxstop']-$jobSummary[$taskname]['minstart'])); 
            } 
            echo "];";
        $maxInterval = 0;
        foreach ($topTasks as $taskname) {
            $job = $jobSummary[$taskname];
            $maxInterval = max($maxInterval, $job['maxstop'] - $job['minstart']);
        }
        $jobHistory = array();
        $NPOINTS = 30;
        $first = true;
        echo "var lineX = [";
        foreach ($topTasks as $taskname) {
            $jobHistory[$taskname] = array(
                'name' => $taskname,
                'ncores' => array(),
                'N' => 0,
                'sum' => 0,
                'var' => 0,
                'shift' => 0,
                'min' => 0,
                'max' => 0,
            );
            for ($i = 0; $i < $NPOINTS; ++$i) {
                $jobHistory[$taskname]['ncores'][] = 0.01;
                if ($first) {
                    //$now = 1.0 * $i / $NPOINTS * $maxInterval * $s2h;
                    $now = 100. * $i / $NPOINTS;
                    //$now = pow(10, 1.0 * ($i + 1) / $NPOINTS * log($maxInterval, 10)) * $s2h;
                    echo sprintf("%.3f,",$now);
                }
            }
            if ($first) {
                echo "];";
                $first = false;
            }
        }
        if ($first) {
            echo "];";
        }
        // do it this way to avoid building large PHP arrays
        $cmd = "SELECT task, timestamp, starttime ";
        $cmd .= "FROM jobs WHERE (";
        foreach ($topTasks as $t) {
            $cmd .= "task = ? OR ";
        }
        $cmd .= "0)";
        $args = $topTasks;
        if ($since > 0) {
            $cmd .= " AND ( starttime > ? )";
            $args[] = $since;
        }
        $stmt = $db->prepare($cmd); 
        $stmt->execute($args);
        while ($rec = $stmt->fetch()) {
            $taskname = $rec['task'];
            $job = $jobSummary[$taskname];
            for ($i = 0; $i < $NPOINTS; ++$i) {
                $now = 1.0 * $i / $NPOINTS * ($job['maxstop'] - $job['minstart']) + $job['minstart'] + 0.1;
                //$now = 1.0 * $i / $NPOINTS * $maxInterval + $job['minstart'];
                //$now = pow(10, 1.0 * ($i + 1) / $NPOINTS * log($maxInterval, 10)) + $job['minstart'];
                if ($rec['starttime'] <= $now && $rec['timestamp'] >= $now) {
                    $jobHistory[$taskname]['ncores'][$i] += 1;
                }
            }
            if ($rec['starttime'] > 0 && $rec['timestamp'] > 0) { 
                $length = ($rec['timestamp'] - $rec['starttime']) * $s2h;
                if ($length < 0.01) // makes no sense
                    continue;
                if ($jobHistory[$taskname]['N'] == 0) {
                    $jobHistory[$taskname]['shift'] = $length;
                    $jobHistory[$taskname]['N'] = 1;
                    $jobHistory[$taskname]['max'] = $length;
                    $jobHistory[$taskname]['min'] = $length;
                } else {
                    $length -= $jobHistory[$taskname]['shift'];
                    $oldmean = $jobHistory[$taskname]['sum'] / $jobHistory[$taskname]['N']; 
                    $jobHistory[$taskname]['sum'] += $length;
                    $jobHistory[$taskname]['N'] += 1;
                    $N = $jobHistory[$taskname]['N'];
                    $mean = $jobHistory[$taskname]['sum'] / $jobHistory[$taskname]['N']; 
                    $oldvar = $jobHistory[$taskname]['var'];
                    $jobHistory[$taskname]['var'] = ($N - 1) / $N * ( $oldvar + ($oldmean * $oldmean)) + ($length * $length / $N) - ($mean * $mean);
                    $jobHistory[$taskname]['min'] = min($jobHistory[$taskname]['min'], $length);
                    $jobHistory[$taskname]['max'] = max($jobHistory[$taskname]['max'], $length);
                }
            }
        }
        echo "var lineData = {}; ";
        echo "var boxData = {}; ";
        foreach ($topTasks as $taskname) {
            $history = $jobHistory[$taskname];
            echo sprintf("lineData[\"%s\"]=[",$taskname);
            for ($i = 0; $i < $NPOINTS; ++$i) {
                echo sprintf("%d,",$history['ncores'][$i]);
            }
            echo "];";
            echo sprintf("boxData[\"%s\"]=[",$taskname);
            $min = $history['min'] + $history['shift'];
            echo sprintf("%f,", $min);
            $mean = $history['sum'] / $history['N'] + $history['shift'];
            $stdev = sqrt($history['var']);
            echo sprintf("%f,", max($min,  $mean - $stdev));
            echo sprintf("%f,", $mean);
            echo sprintf("%f,", $mean + $stdev);
            echo sprintf("%f", $history['max'] + $history['shift']);
            echo "];";

        }
        
        echo " var records=[]; var totalData={};  </script>";
    } else {
        echo " <script type=\"text/javascript\"> var lineData={}; var lineX=[]; var mapLat=[]; var mapLon=[]; var mapRawSizes=[]; var barTicks=[]; var barCpuY=[]; var barUserY=[];  </script>";
    }
    $db = null;        
    ?>
    <script type="text/javascript">
    function labform(label, series)  {
        return '<div style="font-size:16pt;"> ' + label +' </div>';
    }
    if (task != "") {
        $(function() {
            var s2h = 0.0002778;
            var heightRatio = 0.45;

            // map first 
            var mapDiv = document.getElementById('plot-map');
            var mapSizes = [];
            var mapColors = [];
            var mapLabels = [];
            var mapScale = 30. / Math.log(Math.max(...mapRawSizes));
            for (var idx in mapLat) {
                var lat = mapLat[idx];
                var lon = mapLon[idx];
                var val = mapRawSizes[idx];
                mapColors.push(Math.log10(val));
                mapSizes.push(Math.log(val) * mapScale);
                mapLabels.push(val.toFixed(2) + ' H')
            }
            var mapTicks = [];
            var mapTickTexts = [];
            var NTICKS = 6;
            for (var  i = 0; i != NTICKS; ++i) {
                var x = Math.ceil(Math.max(...mapColors) / NTICKS) * i;
                mapTicks.push(x);
                var text = "10<sup>"+x.toString()+"</sup>";
                mapTickTexts.push(text);
            }
            var mapData = [{
                type: 'scattergeo',
                mode: 'markers',
                text: mapLabels,
                lon: mapLon,
                lat: mapLat,
                marker: {
                    size: mapSizes,
                    color: mapColors,
                    cmin: 0,
                    cmax: Math.max(...mapColors),
                    colorscale: 'Portland',
                    colorbar: {
                        thicknessmode: 'fraction',
                        thickness: 0.025,
                        lenmode: 'fraction',
                        len: 0.9,
                        title: 'CPU [H]',
                        tickmode: 'array',
                        tickvals: mapTicks,
                        ticktext: mapTickTexts,
                    },
                    line: {
                        color: 'black'
                    }
                }
            }];
            var mapLonRange = [Math.min(...mapLon)-5, Math.max(...mapLon)+5];
            var mapLatRange = [Math.min(...mapLat)-5, Math.max(...mapLat)+5];
            var mapRange = 1.4 * Math.max(heightRatio*(mapLonRange[1] - mapLonRange[0]),
                                          (mapLatRange[1] - mapLatRange[0]));
            var mapCenter = 0.5 * (mapLonRange[0] + mapLonRange[1]);
            mapLonRange[0] = mapCenter - mapRange;
            mapLonRange[1] = mapCenter + mapRange;
            mapCenter = 0.5 * (mapLatRange[0] + mapLatRange[1]);
            mapLatRange[0] = mapCenter - mapRange*heightRatio;
            mapLatRange[1] = mapCenter + mapRange*heightRatio;
            var mapLayout = {
                    margin: { 
                        l: 10, r: 10, b: 20, t: 40,
                    },
                    font: {
                        size: 16
                    },
                    title: 'Global CPU Usage (all tasks)',
                    titlefont: {
                        size: 24
                    },
                    geo: {
                        lataxis: { showgrid: true },
                        lonaxis: { showgrid: true },
                        scope: 'world',
                        resolution: '110', 
                        lonaxis: {
                            range: mapLonRange ,
                            fixedrange: true
                        },
                        lataxis: {
                            range: mapLatRange, 
                            fixedrange: true
                        },
                        projection: {
                            type: 'robinson'
                        },
                        showframe: false,
                        showrivers: true,
                        rivercolor: '#fff',
                        showocean: true,
                        oceancolor: '#fff',
                        showlakes: true,
                        lakecolor: '#fff',
                        showland: true,
                        landcolor: '#bbb',
                        showcountries: true,
                        countrycolor: '#000000',
                        countrywidth: 1,
                        showsubunits: true,
                        subunitcolor: '#000000',
                        subunitwidth: 1,
                    },
                    width: mapDiv.offsetWidth,
                    height: heightRatio * mapDiv.offsetWidth,
            };
            Plotly.newPlot(mapDiv, mapData, mapLayout);

            // bar chart stuff
            var barDiv = document.getElementById('plot-bar');
            var cpuDataSet = {
                name: "CPU Time", 
                x:barTicks, 
                y:barCpuY, 
                type:'bar', 
                marker: {
                    color: 'rgba(255,50,0,0.5)',
                    line: {color: 'rgba(255,50,0)', width:2},
                },
                showlegend:true
            };
            var totalDataSet = {
                name: "Real Time", 
                x:barTicks, 
                y:barUserY, 
                yaxis:'y2', 
                type:'bar', 
                marker: {
                    color: 'rgba(0,0,120,0.5)',
                    line: {color: 'rgba(0,0,120)', width:2},
                },
                showlegend:true
            };
            var barLayout = {
                    margin: { 
                        t: 40
                    },
                    xaxis: {
                        fixedrange: true
                    },
                    yaxis: {
                        fixedrange: true
                    },
                    font: {
                        size: 16
                    },
                    title: 'Task Breakdown (top 6)',
                    barmode: 'group',
                    titlefont: {
                        size: 24
                    },
                    yaxis: {title: 'CPU Time [Hours]', type: 'log', autorange: true, exponentformat: 'power'},
                    yaxis2: {title: 'Real Time [Hours]', overlaying:'y', side:'right', type: 'log', autorange: true, exponentformat: 'power'},
                    width: barDiv.offsetWidth,
                    height: heightRatio * barDiv.offsetWidth,
                    showlegend: true,
                    legend: { x: 0.8, y: 1, bgcolor: 'rgba(255,255,255,.5)'}, 
            };
            var inv1 = {x:[cpuDataSet.x[0]], y: [0], type: 'bar', hoverinfo: 'none', showlegend: false};
            var inv2 = {x:[totalDataSet.x[0]], y: [0], type: 'bar', hoverinfo: 'none', showlegend: false, yaxis: 'y2'};
            Plotly.newPlot(barDiv, [cpuDataSet, inv1, inv2,  totalDataSet], barLayout);

            // line plot stuff
            var lineDiv = document.getElementById('plot-line');
            var series = {};
            for (var idx in barTicks) {
                var key = barTicks[idx];
                var tmpData = [];
                var scale = 100.0 / Math.max(...lineData[key]);
                for (var idx in lineData[key]) {
                    tmpData.push(lineData[key][idx] * scale);
                }
                series[key] = {label: key, x: lineX, y: tmpData, name: key, type: 'scatter', line: {shape: 'spline'}, hoverinfo: 'y'};
            }
            var lineLayout = {
                    margin: { 
                        t: 40, r: 20
                    },
                    font: {
                        size: 16
                    },
                    title: 'Job Evolution (top 6)',
                    titlefont: {
                        size: 24
                    },
                    yaxis: {title: 'Relative resource utilization [%]', autorange: true},
                    xaxis: {title: 'Task progress [%]', autorange: true, showspikes: true},
                    showlegend: true,
                    legend: { x: 0.7, y: 1, bgcolor: 'rgba(255,255,255,.5)'}, 
                    width: lineDiv.offsetWidth,
                    height: heightRatio * lineDiv.offsetWidth,
            };
            var toplot = [];
            for (var keyIdx in barTicks) {
                var key = barTicks[keyIdx]; 
                toplot.push(series[key]);
            }
            Plotly.newPlot(lineDiv, toplot, lineLayout);

            // box plot stuff
            var boxDiv = document.getElementById('plot-box');
            var boxLayout = {
                    margin: { 
                        t: 40, r: 20
                    },
                    font: {
                        size: 16
                    },
                    yaxis: {
                        fixedrange: true,
                        mirror: 'allticks',
                    },
                    title: 'Job Spread (top 6)',
                    titlefont: {
                        size: 24
                    },
                    yaxis: {
                        title: 'Job CPU Time [Hours]', 
                        autorange: true, 
                        type: 'log', 
                        exponentformat: 'power', 
                        showspikes: true
                    },
                    showlegend: false,
                    width: boxDiv.offsetWidth,
                    height: heightRatio * boxDiv.offsetWidth,
            };
            var toplot = [];
            for (var key in boxData) {
                toplot.push({
                    name: key,
                    y: boxData[key],
                    type: 'box',
                    hoverinfo: 'x',
                })
            }
            Plotly.newPlot(boxDiv, toplot, boxLayout);

            window.onresize = function() {
                Plotly.relayout(lineDiv, {
                    width:  lineDiv.offsetWidth,
                    height: heightRatio * lineDiv.offsetWidth,
                });
                Plotly.relayout(barDiv, {
                    width:  barDiv.offsetWidth,
                    height: heightRatio * barDiv.offsetWidth,
                });
                Plotly.relayout(mapDiv, {
                    width:  mapDiv.offsetWidth,
                    height: heightRatio * mapDiv.offsetWidth,
                });
                Plotly.relayout(boxDiv, {
                    width:  boxDiv.offsetWidth,
                    height: heightRatio * boxDiv.offsetWidth,
                });
            }

        });
    }
    </script>
</head>

<body>
	<div class="container-fluid">

            <div class="row">
                <div class="col-lg-12">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                          <form class="form-inline" method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
                            <input placeholder="_ and % for single- and multi-char wildcard" type="text" name="task" value="<?php echo $task; ?>" class="form-control" size="60">
                            <input placeholder="Last N" type="text" name="ndays" value="<?php echo $ndays; ?>" class="form-control" size="3"> days
                            <input type="submit" value="Filter" class="btn btn-primary">  
			    <?php if($darkmode): ?>
				<input type="submit" name="toggledark", value="Light mode" class="btn btn-secondary">
			    <?php else: ?>
				<input type="submit" name="toggledark", value="Dark mode" class="btn btn-secondary">
			    <?php endif; ?>
                            <?php
                              foreach($_GET as $name => $value) {
                                if ($name!=="task" && $name!=="submit" && $name!=="ndays" && $name!=="toggledark") {
                                  $value = html_entity_decode($value);
                                  echo '<input type="hidden" name="'. $name .'" value="'. $value .'">';
                                }
                              }
                            ?>
                          </form>
                        </div>
                        <!-- /.panel-heading -->
                    </div>
                    <!-- /.panel -->
                </div>
                <div class="col-lg-6">  <div class="panel panel-default">  <div class="panel-body">  <div id="plot-map"></div>  </div>  </div>  </div>
                <div class="col-lg-6">  <div class="panel panel-default">  <div class="panel-body">  <div id="plot-line"></div>  </div>  </div>  </div>
                <div class="col-lg-6">  <div class="panel panel-default">  <div class="panel-body">  <div id="plot-bar"></div>  </div>  </div>  </div>
                <div class="col-lg-6">  <div class="panel panel-default">  <div class="panel-body">  <div id="plot-box"></div>  </div>  </div>  </div>
            </div>
            <!-- /.row -->

    </div>

</body>

</html>
