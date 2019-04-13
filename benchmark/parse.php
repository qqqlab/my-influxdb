<?php
/* benchmark two parsers
parse1 first splits the string into an array, then sorts this array into table,tags,fields and timestamp
parse2 grabs a word at the time and assigns it to table,tags,fields and timestamp

parse1 appears to be slightly faster:

runtime parse1: 300000 rec in 12.622388124466 sec = 23767/sec
runtime parse2: 300000 rec in 15.383553981781 sec = 19501/sec
*/



class a{

private function setTs($v) {$this->ts=$v;}

//influx line protocol string "table,tag1=val1,tag2=val2 field1=val3,field4=val4 timestamp"
//returns 0 on success
public function parse2($s) {
  $this->tag = [];
  $this->fld = [];
  $s = trim($s);
  $len = strlen($s);
  $i=0;
  $delim = null;
  if($i<$len) {
    $this->tbl = $this->parse_word($s,$len,$i,$delim);
    switch($delim) {
      case ',': 
        if(!$this->parse_group($s, $len, $i, $this->tag, $delim)) return 2;
        break;
      case ' ': 
        break;
      default:
        return 1;
    }
  }
  if($i<$len) {
    if($delim != ' ') return 3;
    if(!$this->parse_group($s, $len, $i, $this->fld, $delim)) return 4;
  }
  if($i<$len) {
    if($delim != ' ') return 5;
    $this->setTs($this->parse_word($s, $len, $i, $delim));
  }else{
    $this->setTs(time());
  }
  return 0;  
}

//parse a group of tags or fields "key1=val1,key2=val2,..."
//returns true & group and delimiter after group on success
function parse_group($s, $len, &$i, &$g, &$delim) {
  while($i<$len) {
    $key = $this->parse_word($s, $len, $i, $delim);
    if($key == '' || $delim != '=') return false;
    $g[$key] = $this->parse_word($s, $len, $i, $delim);
    switch($delim) {
      case '=': return false;
      case ',': break;
      default: return true; //delimiter space or none 
    }
  }
  return true;
}

//parse a single word from string $s starting from position $i
//returns word and delimiter after word
public function parse_word($s, $len, &$i, &$delim){
  //split influx string into array with: value,delim,value,delim,...
  $w = ''; //word
  $q = false; //in qouted
  if(@$s[$i]=='"') { $q=true; $i++;} //skip quote
  while($i<$len) {
    $c = $s[$i];
    $c1 = @$s[$i+1]; //next char or '' if no next char
    if($q && $c=='"' && ($c1==','||$c1=='='||$c1==' ')) {
      $q = false;
    }else if(!$q && ($c==','||$c=='='||$c==' ')) {
      $delim = $c;
      $i++;
      return $w;
      if($c1=='"') { $q=true; $i++;} //skip quote
    }else{
      if($c=='\\') {
        $w .= $c1;
        $i++;
      }else{
        $w .= $c;
      }
    }
    $i++;
  }
  $delim = '';
  return $w;
}



//parse an influx line protocol string "table,tag1=val1,tag2=val2 field1=val3,field4=val4 timestamp" 
//returns 0 on success
public function parse1($s) {
  $s=trim($s);
  //split influx string into array with: value,delim,value,delim,...
  $p = []; //parts
  $w = ''; //word
  $q = false; //in qouted
  $len = strlen($s);
  $i = 0;
  while($i<$len) {
    $c = $s[$i];
    $c1 = @$s[$i+1]; //next char or '' if no next char
    if($q && $c=='"' && ($c1==','||$c1=='='||$c1==' ')) {
      $q = false;
    }else if(!$q && ($c==','||$c=='='||$c==' ')) {
      $p[] = $w;
      $p[] = $c;
      $w = '';
      if($c1=='"') { $q=true; $i++;} //skip quote
    }else{
      if($c=='\\') {
        $w .= $c1;
        $i++;
      }else{
        $w .= $c;
      }
    }
    $i++;
  }
  $p[] = $w;
  //sort $p into tbl,tags,fields, and ts
  $cnt = count($p);
  if($cnt < 5) return 1; //minimum 5 parts: "tbl fld=val"
  $this->tbl = $p[0];
  $this->tag = [];
  $this->fld = [];
  $this->ts = null;
  $i = 1;
  while($p[$i]==','){
    if($i+4 > $cnt) return 2;
    if($p[$i+2] != '=') return 3;
    $this->tag[$p[$i+1]] = $p[$i+3];
    $i+=4;
  }
  if($i+4 > $cnt) return 5;
  if($p[$i]!=' ') return 6;
  if($p[$i+2] != '=') return 7;
  $this->fld[$p[$i+1]] = $p[$i+3];
  $i+=4;
  while($i < $cnt && $p[$i]==','){
    if($i+4 > $cnt) return 8;
    if($p[$i+2] != '=') return 9;
    $this->fld[$p[$i+1]] = $p[$i+3];
    $i+=4;
  }
  if($i+1 < $cnt) {
    if($p[$i] != ' ') return 10;
    $this->setTs($p[$i+1]);
  }else{
    $this->setTs(time());
  }
  return 0;
}



}

$s='ta\\\\bl\\,e,tag1="va\\\\,= \\"\\\\l1",tag2=,tag3=val3 field1=val3,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4,field4=val4 timestamp';

$cnt = 300000;


$time_start = microtime(true);
$a=new a;
for($i=0;$i<$cnt;$i++) {
  $rv = $a->parse1($s);
}
//print_r($a);
//echo "RV=$rv\n";
$dt =  microtime(true) - $time_start;
$n = floor($cnt/$dt);
echo "runtime parse1: $cnt rec in $dt sec = $n/sec\n";

$time_start = microtime(true);
$a=new a;
for($i=0;$i<$cnt;$i++) {
  $rv = $a->parse2($s);
}
//print_r($a);
//echo "RV=$rv\n";
$dt =  microtime(true) - $time_start;
$n = floor($cnt/$dt);
echo "runtime parse2: $cnt rec in $dt sec = $n/sec\n";


