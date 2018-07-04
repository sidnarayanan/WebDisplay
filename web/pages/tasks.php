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
    <link href="..//vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="..//vendor/metisMenu/metisMenu.min.css" rel="stylesheet">
    <link href="..//dist/css/sb-admin-2.css" rel="stylesheet">
    <link href="..//vendor/morrisjs/morris.css" rel="stylesheet">
    <link href="..//vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <script src="..//vendor/jquery/jquery.min.js"></script>
    <script src="..//vendor/flot/jquery.flot.js"></script>
    <script src="..//vendor/flot/jquery.flot.pie.js"></script>
    <script src="..//vendor/flot/jquery.flot.resize.js"></script>
    <script src="..//vendor/flot/jquery.flot.time.js"></script>
    <script src="..//vendor/flot-axislabels/jquery.flot.axislabels.js"></script>
    <script src="..//vendor/flot-orderBars/jquery.flot.orderBars.js"></script>
    <script src="..//vendor/flot-tickrotor/jquery.flot.tickrotor.js"></script>
    <script src="..//vendor/flot-tooltip/jquery.flot.tooltip.min.js"></script>
    <script src="https://cdn.plot.ly/plotly-latest.min.js"></script>


    <?php
    $db = new PDO('sqlite:/home/snarayan/flask_server/condor/tasks.sqlite');
    $task = "";
    if (!empty($_GET["task"])) {
        $task = $_GET["task"];
    }
    echo " <script type=\"text/javascript\"> var task = \"".$task."\"; </script>";
    if ($task != "") {
        if ($task != "") {
            $task = $_GET["task"];
            $tasks = explode(",", $task);
            $cmd = "SELECT task, timestamp, starttime, job_id, lat, lon ";
            $cmd .= "FROM jobs INNER JOIN nodes ON jobs.host_id = nodes.id WHERE ";
            foreach ($tasks as $t) {
                $cmd .= "task LIKE ? OR ";
            }
            $cmd .= "0";
            $stmt = $db->prepare($cmd); 
            $stmt->execute($tasks);
        } else { 
            $stmt = $db->prepare("SELECT task, timestamp, starttime, job_id FROM jobs"); 
            $stmt->execute();
        }
        $data = $stmt->fetchall(); 
        echo " <script type=\"text/javascript\"> ";
        $mapRawData = array();
        $latLon = array();
        $s2h = 0.0002778;
        $jobSummary = array();
        foreach ($data as $rec) {
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
            if ($ncount == 5) {
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
        $NPOINTS = 100;
        $first = true;
        echo "var lineX = [";
        foreach ($topTasks as $taskname) {
            $jobHistory[$taskname] = array(
                'name' => $taskname,
                'ncores' => array()
            );
            for ($i = 0; $i < $NPOINTS; ++$i) {
                $jobHistory[$taskname]['ncores'][] = 0;
                $now = 1.0 * $i / $NPOINTS * $maxInterval;
                if ($first) {
                    echo sprintf("%.3f,",$now * $s2h);
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
        foreach ($data as $rec) {
            if (in_array($rec['task'], $topTasks)) {
                $taskname = $rec['task'];
                $job = $jobSummary[$taskname];
                for ($i = 0; $i < $NPOINTS; ++$i) {
                    $now = 1.0 * $i / $NPOINTS * $maxInterval + $job['minstart'];
                    if ($rec['starttime'] < $now && $rec['timestamp'] > $now) {
                        $jobHistory[$taskname]['ncores'][$i] += 1;
                    }
                }
            }
        }
        echo "var lineData = {}; ";
        foreach ($topTasks as $taskname) {
            $history = $jobHistory[$taskname];
            echo sprintf("lineData[\"%s\"]=[",$taskname);
            for ($i = 0; $i < $NPOINTS; ++$i) {
                echo sprintf("%d,",$history['ncores'][$i]);
            }
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

            // map first 
            var mapSizes = [];
            var mapColors = [];
            var mapLabels = [];
            var mapScale = 30. / Math.log(Math.max(...mapRawSizes));
            for (var idx in mapLat) {
                var lat = mapLat[idx];
                var lon = mapLon[idx];
                var val = mapRawSizes[idx];
                mapColors.push(val);
                mapSizes.push(Math.log(val) * mapScale);
                mapLabels.push(val.toFixed(2) + ' H')
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
                    colorscale: 'Jet',
                    //*
                    colorbar: {
                        thicknessmode: 'fraction',
                        thickness: 0.025,
                        lenmode: 'fraction',
                        len: 0.9,
                        title: 'CPU',
                        ticksuffix: 'H',
                        showticksuffix: 'last'
                    },
                     //*/
                    line: {
                        color: 'black'
                    }
                }
            }];
            var mapLonRange = [Math.min(...mapLon)-5, Math.max(...mapLon)+5];
            var mapLatRange = [Math.min(...mapLat)-5, Math.max(...mapLat)+5];
            var mapRange = 0.5 * Math.max(mapLonRange[1] - mapLonRange[0],
                                          mapLatRange[1] - mapLatRange[0]);
            var mapCenter = 0.5 * (mapLonRange[0] + mapLonRange[1]);
            mapLonRange[0] = mapCenter - mapRange*2;
            mapLonRange[1] = mapCenter + mapRange*2;
            mapCenter = 0.5 * (mapLatRange[0] + mapLatRange[1]);
            mapLatRange[0] = mapCenter - mapRange;
            mapLatRange[1] = mapCenter + mapRange;
            var mapLayout = {
                    margin: { 
                        l: 10, r: 10, b: 10, t: 40,
                    },
                    font: {
                        size: 16
                    },
                    title: 'Global CPU Usage (all tasks)',
                    titlefont: {
                        size: 24
                    },
                    geo: {
                        scope: 'world',
                        resolution: 50, 
                        lonaxis: {
                            'range': mapLonRange 
                        },
                        lataxis: {
                            'range': mapLatRange 
                        },
                        showrivers: true,
                        rivercolor: 'rgba(28,200,225,.2)',
                        showocean: true,
                        oceancolor: 'rgba(28,200,225,.2)',
                        showlakes: true,
                        lakecolor: 'rgba(28,200,225,.2)',
                        showland: true,
                        landcolor: 'rgba(86,140,115,.2)',
                        showcountries: true,
                        countrycolor: '#d3d3d3',
                        countrywidth: 1.5,
                        showsubunits: true,
                        subunitcolor: '#d3d3d3',
                        subunitwidth: 1.5,
                    }
            };
            Plotly.newPlot('plot-map', mapData, mapLayout);

            // bar chart stuff
            var cpuDataSet = {name: "CPU Time", x:barTicks, y:barCpuY, type:'bar', showlegend:false};
            var totalDataSet = {name: "User Time", x:barTicks, y:barUserY, yaxis:'y2', type:'bar', showlegend:false};
            var barLayout = {
                    margin: { 
                        t: 40
                    },
                    font: {
                        size: 16
                    },
                    title: 'Task Breakdown (top 5)',
                    barmode: 'group',
                    titlefont: {
                        size: 24
                    },
                    yaxis: {title: 'CPU Time [Hours]', type: 'log', autorange: true},
                    yaxis2: {title: 'Real Time [Hours]', overlaying:'y', side:'right', type: 'log', autorange: true},
            };
            var inv1 = {x:[cpuDataSet.x[0]], y: [0], type: 'bar', hoverinfo: 'none', showlegend: false};
            var inv2 = {x:[totalDataSet.x[0]], y: [0], type: 'bar', hoverinfo: 'none', showlegend: false, yaxis: 'y2'};
            Plotly.newPlot('plot-bar', [cpuDataSet, inv1, inv2,  totalDataSet], barLayout);

            // line plot stuff
            var series = {};
            for (var idx in barTicks) {
                var key = barTicks[idx];
                series[key] = {label: key, x: lineX, y: lineData[key], name: key, type: 'scatter', fill: 'tozeroy'};
            }
            var lineLayout = {
                    margin: { 
                         t: 40,
                    },
                    font: {
                        size: 16
                    },
                    title: 'Historical CPU Usage (top 5)',
                    titlefont: {
                        size: 24
                    },
                    yaxis: {title: 'Number of Cores', type: 'log', autorange: true},
                    xaxis: {title: 'Hours since start', type: 'log', autorange: true},
                    showlegend: true,
                    legend: { x: 0.7, y: 0, bgcolor: 'rgba(255,255,255,.2)'}, 
            };
            var toplot = [];
            for (var keyIdx in barTicks) {
                var key = barTicks[keyIdx]; 
                toplot.push(series[key]);
            }
            Plotly.newPlot('plot-line', toplot, lineLayout);

        });
    }
    </script>
</head>

<body>

            <div class="row">
                <div class="col-lg-12">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                          <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">  
                            <input type="submit" value="Filter task" class="btn btn-primary">  
                            <input placeholder="_ and % for single- and multi-char wildcard" type="text" name="task" value="<?php echo $task; ?>" class="form-control">
                            <?php
                              foreach($_GET as $name => $value) {
                                if ($name!=="task" && $name!=="subit") {
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
                <!-- /.col-lg-6 -->
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-body">
                                <div id="plot-map"></div>
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                <!-- /.col-lg-6 -->
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-body">
                                <div id="plot-line"></div>
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-body">
                                <div id="plot-bar"></div>
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
            </div>
            <!-- /.row -->


</body>

</html>
