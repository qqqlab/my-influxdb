<?php
if(file_exists(__DIR__.'/configcli.inc.php')) 
  require_once('configcli.inc.php');
else
  require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

if($argc<2) die("usage: loadfile.php [-v] [-p=<precision>] <filename>\n   -v verbose\n");

$influx = new MyInfluxDB();

$verbose = false;
for($i=1;$i<$argc-1;$i++) {
  $a = $argv[$i];
  if($a == '-v') $verbose = true;
  if(substr($a,0,3) == '-p=') $influx->setPrecision(substr($a,3));
}
//echo "precision=".$influx->getPrecision()."\n";

if(!$f = fopen($argv[$argc-1], "r")) die("error opening file $argv[1]\n");


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

