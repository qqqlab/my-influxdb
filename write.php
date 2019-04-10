<?php
require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

$influx = new MyInfluxDB();

$quiet=true;
if(isset($_GET['precision'])) $influx->setPrecision($_GET['precision']);
if(isset($_GET['verbose'])) $quiet=false;

$influx->db_connect();

if(!$f = fopen('php://input', 'r')) die("error opening file\n");

while (($influxdata = fgets($f)) !== false) {
   $influxdata = trim($influxdata);
   if(!$influxdata) continue;
   if(substr($influxdata,0,1)=='#') continue;
   if(!$quiet) echo "$influxdata -> ";
   $influx->parse($influxdata);
   $rv=$influx->write();
   if(!$quiet) echo "$rv\n";
}
fclose($f);
