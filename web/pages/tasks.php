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
            $cmd = "SELECT task, timestamp, starttime, job_id FROM jobs WHERE ";
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
        echo " <script type=\"text/javascript\"> var records = [";
        foreach ($data as $rec) {
            if ($rec['starttime'] > 0 && $rec['timestamp'] > 0) {
                echo sprintf("[\"%s\", %d, %d, \"%s\"],", $rec['task'], $rec['starttime'], $rec['timestamp'], $rec['job_id']);
            }
        }
        echo " ]; </script>";
    } else {
        echo " <script type=\"text/javascript\"> var records = []; </script>";
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

            // bar chart stuff
            var cpuData = {};
            var totalData = {};
            for (var idx in records) {
                var d = records[idx]; 
                if (!(d[0] in cpuData)) {
                    cpuData[d[0]] = 0;
                    totalData[d[0]] = [[], [], []];
                }
                if (d[2] > 0 && d[1] > 0) {
                    cpuData[d[0]] = cpuData[d[0]] + d[2] - d[1];
                    totalData[d[0]][0].push(d[1]);
                    totalData[d[0]][1].push(d[2]);
                    totalData[d[0]][2].push(d[3]);
                }
            }
            var cpuDataUnsorted = [];
            var totalDataUnsorted = [];
            var barTicksUnsorted = [];
            var idx = 0;
            for (var key in cpuData) {
                cpuDataUnsorted.push([idx, cpuData[key] * s2h]);
                totalDataUnsorted.push([idx, s2h * (Math.max(...totalData[key][1]) 
                                                    - Math.min(...totalData[key][0]))]);
                barTicksUnsorted.push([idx, key])
                idx += 1; 
            }
            cpuDataUnsorted.sort(function(x, y) { return y[1] - x[1]; });
            var cpuDataSet = {label: "CPU Time", data:[], yaxis:1};
            var totalDataSet = {label: "User Time", data:[], yaxis:2};
            var barTicks= [];
            var realIdx = 0;
            for (var idx in cpuDataUnsorted) {
                var v = cpuDataUnsorted[idx];
                cpuDataSet.data.push([realIdx,v[1]]);
                barTicks.push([realIdx,barTicksUnsorted[v[0]][1]]);
                totalDataSet.data.push([realIdx, totalDataUnsorted[v[0]][1]]);
                realIdx += 1; 
                if (realIdx == 6) { // max
                    break;
                }
            }
            var barOptions = {
                series: {
                bars: {
                    show: true
                }
                },
                bars: {
                    show: true,
                    barWidth: 0.45,
                    order: 1
                },
                legend: {
                    labelFormatter: labform
                },
                xaxis: {
                        ticks: barTicks,
                        axisLabelUseCanvas: true,
                        axisLabelFontSizePixels: 24,
                        axisLabelFontFamily: 'Verdana, Arial',
                        axisLabelPadding: 20,
                        axisLabel: "Tasks (top 5)",
                        font: {
                            size: 16,
                            color: "black"
                        },
                        rotateTicks: 30
                },
                yaxis: {
                    axisLabelUseCanvas: true,
                    axisLabelFontSizePixels: 24,
                    axisLabelFontFamily: 'Verdana, Arial',
                    axisLabelPadding: 10,
                    font: {
                        size: 16,
                        color: "black"
                    }
                },
                yaxes: [
                    { position: "left",
                      axisLabel: "CPU Time [Hours]"
                    },
                    { position: "right",
                      axisLabel: "User Time [Hours]"
                    },
                ],
                grid: {
                hoverable: true,
                        borderWidth: 2
                },
                tooltip: true,
                tooltipOpts: {
                content: "task=%x, time=%yh"
                }
            };
            $.plot($("#flot-cputime"), [cpuDataSet, totalDataSet], barOptions);

            // line plot stuff
            var maxInterval = 0;
            var startTimes = {}
            var series = {};
            for (var key in totalData) {
                series[key] = {label: key, data: []};
                startTimes[key] = 0;
            }
            for (var key in totalData) {
                // figure out ranges 
                startTimes[key] = Math.min(...totalData[key][0]);
                maxInterval = Math.max(maxInterval,
                                       (Math.max(...totalData[key][1])
                                        - startTimes[key]));
            }
            var NPOINTS = 25;
            for (var i = 0; i < NPOINTS; i += 1) {
                var now = 1.0 * i / NPOINTS * maxInterval;
                for (var key in totalData) {
                    var pt = startTimes[key] + now; 
                    var val = 0;
                    var running = new Set([]);
                    for (var idx in totalData[key][0]) {
                        var v0 = totalData[key][0][idx];
                        var v1 = totalData[key][1][idx];
                        if (v0 < pt && pt < v1) {
                            running.add(totalData[key][2][idx]);
                        }
                    }
                    series[key].data.push([now * s2h, running.size]);
                }
            }
            var lineOptions = {
                series: {
                    lines: {
                        show: true,
                        fill: 0.2,
                    },
                },
                grid: {
                    hoverable: true //IMPORTANT! this is needed for tooltip to work
                },
                legend: {
                    labelFormatter: labform
                },
                xaxis: {
                        axisLabelUseCanvas: true,
                        axisLabelFontSizePixels: 24,
                        axisLabelFontFamily: 'Verdana, Arial',
                        axisLabelPadding: 20,
                        axisLabel: "Hours since start",
                        font: {
                            size: 16,
                            color: "black"
                        }
                },
                yaxis: {
                    axisLabelUseCanvas: true,
                    axisLabelFontSizePixels: 24,
                    axisLabelFontFamily: 'Verdana, Arial',
                    axisLabelPadding: 10,
                    axisLabel: "Number of Cores",
                    font: {
                        size: 16,
                        color: "black"
                    }
                },
                tooltip: true,
                tooltipOpts: {
                    content: "%s - %y.0 running after %x.1 hours",
                    shifts: {
                        x: -60,
                        y: 25
                    }
                }
            };
            var toplot = [];
            for (var key in series) {
                toplot.push(series[key]);
            }
            $.plot($("#flot-njobs"), toplot, lineOptions);

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
                            <div class="flot-chart">
                                <div class="flot-chart-content" id="flot-cputime"></div>
                            </div>
                        </div>
                        <!-- /.panel-body -->
                    </div>
                    <!-- /.panel -->
                </div>
                <!-- /.col-lg-6 -->
                <div class="col-lg-6">
                    <div class="panel panel-default">
                        <div class="panel-body">
                            <div class="flot-chart">
                                <div class="flot-chart-content" id="flot-njobs"></div>
                            </div>
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
