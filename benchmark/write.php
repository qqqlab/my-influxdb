<?php
//===========================================================
// Benchmark write endpoint
//
// without logging
// runtime multi: 1000 rec in 1.2044811248779 sec = 830/sec
// runtime single: 1000 rec in 5.3741478919983 sec = 186/sec
// with logging
// runtime multi: 1000 rec in 2.3916320800781 sec = 418/sec
// runtime single: 1000 rec in 6.9235417842865 sec = 144/sec
//===========================================================
//CONFIG
require '../config.inc.php';
$url = MYIF_BASE_URL . '/write.php?verbose';
$cnt = 1000; //number of records to write (benchmark writes 2 * $cnt + 1 records)
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
printf("runtime double: %d rows in %7.3f sec, %7.3f ms/row, %7d rows/sec\n",$cnt,$dt,$dt*1000/$cnt,$cnt/$dt);

//wait 1 second to make sure next inserts are inserts and not updates
sleep(1);

//each record in single write
$time_start = microtime(true);
foreach($msg as $msg_single) http_post($msg_single);
$dt =  microtime(true) - $time_start;
printf("runtime single: %d rows in %7.3f sec, %7.3f ms/row, %7d rows/sec\n",$cnt,$dt,$dt*1000/$cnt,$cnt/$dt);


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
