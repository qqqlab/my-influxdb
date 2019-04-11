<?php
require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

$influx = new MyInfluxDB();

$verbose = false;
if(isset($_GET['precision'])) $influx->setPrecision($_GET['precision']);
if(isset($_GET['verbose']) && $_GET['verbose']) $verbose = true;

if(!$f = fopen('php://input', 'r')) die("error opening file\n");

//------------------------
$influx->db_connect();

while (($influxdata = fgets($f)) !== false) {
   $influxdata = trim($influxdata);
   if(!$influxdata) continue;
   if(substr($influxdata,0,1)=='#') continue;
   if($verbose) echo "$influxdata -> ";
   try{
     $rv=$influx->write($influxdata);
     if($verbose) echo "$rv\n";
   }catch(Exception $e){
     print_r($e);
   }
}
fclose($f);
