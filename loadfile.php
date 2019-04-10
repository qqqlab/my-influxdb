<?php
require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

if($argc<2) die("usage: loadfile.php [-q] [-p=<precision>] <filename>\n");

$influx = new MyInfluxDB();

$quiet=false;
for($i=1;$i<$argc-1;$i++) {
  $a = $argv[$i];
  if($a == '-q') $quiet=true;
  if(substr($a,0,3) == '-p=') $influx->setPrecision(substr($a,3));
}
//echo "precision=".$influx->getPrecision()."\n";

if(!$f = fopen($argv[$argc-1], "r")) die("error opening file $argv[1]\n");

$influx->db_connect();

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
