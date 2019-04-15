<?php
require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

if($argc<2) die("usage: loadfile.php [--option=value] ... <filename>
   --verbose              Verbose output
   --precision=<seconds>  Set precision
   --noupdate             Disable update, only inserts are allowed
   --nocreate             Disable creating tables
   --noaddtag             Disable adding tag columns
   --noaddfield           Disable adding field columns
");

$filename = $argv[$argc-1];
$options = [];
for($i=1;$i<$argc-1;$i++) {
  $a = explode('=',$argv[$i],2);
  if(count($a)<2) $a[1] = null;
  if(substr($a[0],0,2) == '--') $options[substr($a[0],2)] = $a[1];
}

$influx = new MyInfluxDB();
$influx->db_connect();
echo $influx->write_file($filename,$options);

