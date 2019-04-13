<?php

$s= 'so\,il,n\"ode=so\\il2,tag2=bla msg="as\"df,a"sdf=asdf",rssi=-95,msgcnt=553,millis=1579160 124124124';

$s='tbl aaa=bbb,ccc=,ddd=9 123';

function parse($s) {
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
  $tbl = $p[0];
  $tag = [];
  $fld = [];
  $i = 1;
  while($p[$i]==','){
    if($i+4 > $cnt) return 2;
    if($p[$i+2] != '=') return 3;
    $tag[$p[$i+1]] = $p[$i+3];
    $i+=4;
  }
  if($i+4 > $cnt) return 5;
  if($p[$i]!=' ') return 6;
  if($p[$i+2] != '=') return 7;
  $fld[$p[$i+1]] = $p[$i+3];
  $i+=4;
  while($p[$i]==','){
    if($i+4 > $cnt) return 8;
    if($p[$i+2] != '=') return 9;
    $fld[$p[$i+1]] = $p[$i+3];
    $i+=4;
  }
  if($i+1 < $cnt) {
   if($p[$i] != ' ') return 10;
   $ts = $p[$i+1];
  }

  $all = [$tbl,$tag,$fld,$ts];
  echo "$s\n";
  print_r($p);
  print_r($all);
  return 0;
}

$rv = parse($s);
echo "rv=$rv\n";




//print_r(str_getcsv('soil,node=soil2 msg="asdf,asdf=asdf",rssi=-95,msgcnt=553,millis=1579160 124124124'));

