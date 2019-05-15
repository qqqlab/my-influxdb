<?php
//=======================================================
// HTTP endpoint for receiving raw sensor data
//=======================================================
//CONFIG
define('PRECISION', 300);
define('TBL','sensor');
//=======================================================

require_once('config.inc.php');
require_once('myinfluxdb.inc.php');

//$json = '{"tbl":"test_sensor","gw":"aap","rssi":-12,"m":"c29pbDFtNTg2djI1OTAsMTkzMGM3MTA2NTJ1NzEwNjUydDMwODswMDAwMDAwMDA="}';

$json = file_get_contents('php://input');

$influx = new MyInfluxDB();
$influx->db_connect();
$result = write_sensor($json, $influx);
$influx->log($influx->protostring() . " " . $json,$result);
if(substr($result,0,2)!='OK') http_response_code(400);
die($result);

function write_sensor($json, $influx) {
  if(!$json) return "ERROR no post data";
  $jdata = json_decode($json,true);
  if(!$jdata) return "ERROR json_decode($json)";
  //if(!isset($jdata['tbl'])) return("ERROR tbl not specified");
  if(isset($jdata['m'])) {
    $m = base64_decode($jdata['m']);
    if(!$m) return "ERROR base64_decode($jdata[m])";
    $mdata = msg_decode($m); 
    if(!$mdata) return "ERROR msg_decode($m)";
    if(!isset($mdata['node'])) return "ERROR node not specified in m";
  }else{
    if(!isset($jdata['node'])) return "ERROR node not specified in json";
    $mdata['node'] = $jdata['node'];
    unset( $jdata['node']);
  }

  //precision
  $influx->setPrecision(PRECISION);

  //table
  //$influx->tbl = $jdata['tbl'];
  $influx->tbl = TBL;

  //tags
  $influx->tag = [ 'node' => $mdata['node'] ];

  //time
  if(isset($jdata['ts'])) {
    $influx->setTs($jdata['ts']);
  }else{
    $influx->setTs(time());
  }

  //fields
  unset($jdata['tbl']);
  unset($jdata['ts']);
  unset($jdata['m']);
  unset($mdata['node']);
  $influx->fld = array_merge($jdata,$mdata);

  //field modes
  foreach($mdata as $k=>$v) $influx->fld_mode[$k] = 'A';
  if(isset($influx->fld['rssi'])) $influx->fld_mode['rssi'] = 'A';

  //insert
  return $influx->write();
}


//decode alpha (key) numeric (value) encoded message
//first alpha-num pair is node
//a comma as key repeats previous key
//message optinally terminated by ';'
//example "soil3volt-25,9,1.9t30;000000000" -> node:soil3 volt:-25 volt2:9 volt3:1.9 t:30
function msg_decode($msg) {
  $last_isnum = false;
  $comma_cnt = 0;
  $a = [];
  $starti = 0;
  $key = '';
  $key_last = '';
  $key_cnt = 0;

  for($i=0;$i<strlen($msg);$i++) {
    $c = $msg[$i];
    if(ord($c)<32 || ord($c)>127) return []; //invalid char
    if($c==';') break; //end of message
    $cur_isnum = ($c>='0' && $c<='9') || $c=='.' || $c=='-';
    if($cur_isnum && !$last_isnum) {
      $key = substr($msg, $starti, $i-$starti);
      if($key==',') {
        $key_cnt++;
        $key = $key_last . ($key_cnt+1);
      }else{
        $key_last = $key;
        $key_cnt = 0;
      }
      $starti = $i;
    }else if(!$cur_isnum && $last_isnum) {
      $val = substr($msg, $starti, $i-$starti);
      if(!$a) {
        $a['node'] = $key . $val;
      }else{
        if($key) $a[$key] = $val;
      }
      $starti = $i;
    }
    $last_isnum = $cur_isnum;
  }
  $a[$key] = substr($msg, $starti, $i-$starti);
  return $a;
}

