<?php
if(file_exists(__DIR__.'/conf/config.inc.php'))
  require_once('conf/config.inc.php');
else
  require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

$influx = new MyInfluxDB();
$influx->db_connect();
echo $influx->write_file('php://input',$_GET);

