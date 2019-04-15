<?php
//===========================================================
// Benchmark write endpoint
// runtime multi: 1000 rec in 4.2364399433136 sec = 236/sec
// runtime single: 1000 rec in 11.671812057495 sec = 85/sec
//===========================================================
//CONFIG
$url = 'http://localhost/my-influxdb/write.php?verbose';
$cnt = 100; //number of records to write (benchmark writes 2 * $cnt + 1 records)
$verbose = false; //show results of each request
//===========================================================

$msg = [];
for($i=0;$i<$cnt;$i++) { 
  $msg[] =  'test,cnt=' . sprintf("%06d",$i+1) . ' val1='.rand().',val2='.rand().',val3='.rand().',val4='.rand();
}
$msg_multi = implode("\n",$msg);

//write single record to create table (if it does not exist)
http_post($msg[0]);

//wait 1 second to make sure next inserts are inserts and not updates
sleep(1);

//all records in one write
$time_start = microtime(true);
http_post($msg_multi);
$dt =  microtime(true) - $time_start;
$n = floor($cnt/$dt);
echo "runtime multi: $cnt rec in $dt sec = $n/sec\n";

//wait 1 second to make sure next inserts are inserts and not updates
sleep(1);

//each record in single write
$time_start = microtime(true);
foreach($msg as $msg_single) http_post($msg_single);
$dt =  microtime(true) - $time_start;
$n = floor($cnt/$dt);
echo "runtime single: $cnt rec in $dt sec = $n/sec\n";


function http_post($msg) {
  global $url,$verbose;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $msg);

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);  //get result
  //curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
  //curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-type: text/plain"));
  $result = curl_exec($ch);

  if(curl_errno($ch) !== 0) die('cURL error when connecting to ' . $url . ': ' . curl_error($ch));

  //print_r(curl_getinfo($ch));
  curl_close($ch);
  if($verbose) echo($result);
}



/* non curl version
$options = [ 'http' => [
  'method'  => 'POST',
  'header'  => 'Content-type: text/plain',
  'content' => $msg,
]];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);
var_dump($result);
*/
