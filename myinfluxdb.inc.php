<?php

class MyInfluxDB {

//current dataset, set by parse() or external program
public  $tbl;               //tablename
public  $tag;               //array of tags and values
public  $fld;               //array of fields and values
private $ts;                //timestamp in unix format
public  $fld_mode;          //mode for field: Last First miN maX Avg
private $precision = 0;     //number of seconds for rounding time stamps

//current dataset sql escaped (keys inside backticks, values inside quotes or NULL)
private $tbl_e;             //tablename
private $tag_e;             //array of tags and values
private $fld_e;             //array of fields and values
private $ts_e;              //timestamp in unix format
private $fld_mode_e;        //mode for field: Last First miN maX Avg

//row options
public $mode = 'L';         //default mode for fields: Last First miN maX Avg
public $noupdate = false;   //disable updates
public $nocreate = false;   //disable creating tables
public $noaddtag = false;   //disable adding tags
public $noaddfield = false; //disable adding fields

public $db;                 //PDO database object
public $err;                //last error message


public function setTs($val) {
  if($this->precision) 
    $this->ts = $val - $val % $this->precision;
  else
    $this->ts = (int)$val;
}
 
public function getTs() {
  return $this->ts;
}

public function setPrecision($val) {
  $this->precision = (int)$val;
  $this->setTs($this->ts);
}

public function getPrecision() {
  return $this->precision;
}

public function clear() {
  $this->tbl = null;
  $this->tag = [];
  $this->fld = [];
  $this->ts = null;
  $this->fld_mode = [];
}

//==========================================================
// MYSQL SPECIFIC
//==========================================================
public function db_connect() {
  $charset = 'utf8mb4';
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=$charset";
  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false, //set to false otherwise "MySQL server has gone away" errors with pcntl_fork()
  ];
  $this->db = new PDO($dsn, DB_USER, DB_PASS, $options);
}

//prepare dataset for database functions
private function prepare_dataset() {
  //escape dataset - including backticks for names (keys) and quotes for values
  $this->tbl_e = '`' .  MYIF_TABLE_PREFIX . $this->esc($this->tbl) . '`';
  $this->tag_e = [];
  foreach($this->tag as $k=>$v) $this->tag_e['`' . $this->esc($k). '`'] = ($v===''?'NULL':"'".$this->esc($v)."'");
  $this->fld_e = [];
  $this->fld_mode_e = [];
  foreach($this->fld as $k=>$v) {
    $k_e = '`' . $this->esc($k). '`'; 
    $this->fld_e[$k_e] = ($v===''?'NULL':"'".$this->esc($v)."'");
    if(!isset($this->fld_mode[$k])) $this->fld_mode[$k] = $this->mode;
    $this->fld_mode_e[$k_e] =  $this->fld_mode[$k];
  }
  $this->ts_e = $this->esc($this->ts);
}

private function esc($v) {
  return substr($this->db->quote($v), 1, -1);
}

private function insert() {
  try{
    if($this->ts===null) {$this->err = 'ts not set'; return 'ERROR';}

    $sql = "INSERT INTO $this->tbl_e (ts";
    foreach($this->tag_e as $k=>$v) $sql .= ",$k";
    foreach($this->fld_e as $k=>$v) $sql .= ",$k";
    $sql .= ') VALUES (';
    $sql .= "from_unixtime($this->ts_e)";
    foreach($this->tag_e as $k=>$v) $sql .= ",$v";
    foreach($this->fld_e as $k=>$v) $sql .= ",$v";
    $sql .= ')';
    $this->db_query($sql);
  }catch (\PDOException $e) {
    switch($e->errorInfo[1]) {
      case 1146: //table does not exit
        return "CREATE";
      case 1054: //unknow column
        return "ALTER";
      case 1062: //duplicate key
        if($this->noupdate) {$this->err = 'update not allowed, noupdate flag is set'; return 'ERROR';}
        $this->err = $e->getMessage();
        return "ERROR";
      default:
        $this->err = $e->getMessage(); 
        return "ERROR";
    }
  }
  return "OK";
}

private function insupd() {
  try{
    if($this->ts===null) {$this->err = 'ts not set'; return 'ERROR';}
    if($this->noupdate) return insert();

    $sql = "INSERT INTO $this->tbl_e (ts";
    foreach($this->tag_e as $k=>$v) $sql .= ",$k";
    foreach($this->fld_e as $k=>$v) $sql .= ",$k";
    $sql .= ') VALUES (';
    $sql .= 'from_unixtime('.$this->esc($this->ts).')';
    foreach($this->tag_e as $k=>$v) $sql .= ",$v";
    foreach($this->fld_e as $k=>$v) $sql .= ",$v";
    $sql .= ')';
    $sql .= ' ON DUPLICATE KEY UPDATE ';
    if(in_array('A',$this->fld_mode)) $sql .= 'ts_cnt=ts_cnt+1,';    
    $first=true;
    foreach($this->fld_e as $k=>$v) {
      $sql .= ($first?'':',');
      $first=false;
      switch($this->fld_mode_e[$k]) {
        case 'L': //Last
          $sql .= "$k=$v";
          break; 
        case 'F': //First
          $sql .= "$k=$k";
          break;
        case 'N': //miN
          $sql .= "$k=least(coalesce($k,$v),$v)";
          break;
        case 'X': //maX
          $sql .= "$k=greatest(coalesce($k,$v),$v)";
          break;
        case 'A': //Average
          $sql .= "$k=coalesce(($k*(ts_cnt-1)+$v)/ts_cnt,$k,$v)";
          break;
        default: 
           $this->err = "Unknown mode ".$this->fld_mode_e[$k]." for field $k";
           return "ERROR";
      }
    }
    $this->db_query($sql);
  }catch (\PDOException $e) {
    switch($e->errorInfo[1]) {
      case 1054: //unknow column
        return "ALTER";
      case 1146: //table does not exit
        return "CREATE";
      default:
        $this->err = $e->errorInfo[1] . ' ' . $e->getMessage();
        return "ERROR";
    }
  }
  return "OK";
}

private function create() {
  try{
    if($this->nocreate) {$this->err = 'create not allowed, nocreate flag is set'; return 'ERROR';}

    $sql = "CREATE TABLE $this->tbl_e (ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";
    if(in_array('A',$this->fld_mode)) $sql .= ',ts_cnt SMALLINT UNSIGNED NOT NULL DEFAULT 1';
    foreach($this->tag_e as $k=>$v) $sql.=",$k VARCHAR(255) NOT NULL";
    foreach($this->fld_e as $k=>$v) $sql.=",$k " . (is_numeric($v) ? 'FLOAT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL';
    $sql.=",PRIMARY KEY (ts";
    foreach($this->tag_e as $k=>$v) $sql.=",$k";
    $sql.=")";
    foreach($this->tag_e as $k=>$v) $sql.=",KEY $k($k)";
    $sql.=")";
    $this->db_query($sql);
  }catch (\PDOException $e) {
    $this->err = $e->getMessage();
    return "ERROR";
  }
  return "OK";
}

private function alter() {
  $err = ''; 
 
  //get list of tags and fields from database
  $meta = [];
  $result = $this->db->query("SHOW COLUMNS FROM $this->tbl_e");
  while($row = $result->fetch()){
    $istag = ($row['Key'] == 'PRI');
    $name = $row['Field'];
    if($name=='ts') continue;
    if($istag) $meta['tag'][]=$name; else $meta['fld'][]=$name;
  }

  //create ts_cnt
  if(in_array('A',$this->fld_mode) && !in_array('ts_cnt',$meta['fld']) && !in_array('ts_cnt',$meta['tag'])) $this->db_query("ALTER TABLE $this->tbl_e ADD ts_cnt SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER ts");

  //create fields (do not create field if column already exists as tag)
  foreach($this->fld as $k=>$v) if(!in_array($k,$meta['fld']) && !in_array($k,$meta['tag'])) {
    if($this->noaddfield) 
      $err = 'add field not allowed, noaddfield flag is set';
    else
      $this->db_query("ALTER TABLE $this->tbl_e ADD `" . $this->esc($k) . '` ' . (is_numeric($v) ? 'FLOAT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL' );
  }

  //create tags (do not create tag if column already exists as field)
  $created_tags = [];
  foreach($this->tag as $k=>$v) if(!in_array($k,$meta['tag']) && !in_array($k,$meta['fld'])) {
    if($this->noaddtag) {
      $err .= ($err?', ':'') . 'add tag not allowed, noaddtag flag is set';
    }else{
      $this->db_query("ALTER TABLE $this->tbl_e ADD `" . $this->esc($k) . '` VARCHAR(255) NOT NULL AFTER `' . $this->esc(end($meta['tag'])) . '`' );
      $this->db_query("ALTER TABLE $this->tbl_e ADD INDEX `" . $this->esc($k) . '` (`' . $this->esc($k) . '`)');
      $created_tags[] = $k;
    }
  }
  if($created_tags) {
    //set primary key to include new tag(s)
    $sql = "ALTER TABLE $this->tbl_e DROP PRIMARY KEY, ADD PRIMARY KEY (ts";
    foreach($meta['tag'] as $tag)  $sql .= ',`' . $this->esc($tag) . '`'; //current tags
    foreach($created_tags as $tag) $sql .= ',`' . $this->esc($tag) . '`'; //new tags
    $sql .= ') USING BTREE';
    $this->db_query($sql);
  }
  if($err) {$this->err = $err; return "ERROR";}
  return "OK";
}

private function db_query($sql) {
  echo "$sql\n";
  return $this->db->query($sql);
}


//==========================================================
// WRITE
//==========================================================

//write to db - create table / alter table / insert record / update record as required
public function write() {
  $this->prepare_dataset();
  $op = 'insupd';
  switch($this->insupd()) {
    case 'OK':
      return "OK $op";
    case 'CREATE':
      $op = 'create';
      if($this->create() == 'OK') {
        $op = "create insupd";
        if($this->insupd() == 'OK') return "OK $op"; 
      }
      break;
    case 'ALTER':
      $op = "alter";
      if($this->alter() == 'OK') {
        $op = "alter insupd";
        if($this->insupd() == 'OK') return "OK $op" ;
      }
      break;
  }
  return "ERROR $op: $this->err";
}


//==========================================================
// LOGGING
//==========================================================

public function log($msg,$result) {
  if(MYIF_LOG_ROWS>0) {
    if(!$this->log_insert($msg,$result)){
      $this->log_create();
      $this->log_insert($msg,$result);
    }
    if(rand(0,999)==0){
      // 0.1% chance of getting executed
      if(! is_callable('pcntl_fork')) {
        $this->log_truncate();
      }else{
        //start a new process to do the truncate (async queries not allowed with pdo, can do with mysqli?)
        switch(pcntl_fork()) {
          case -1: //could not fork
            $this->log_truncate();
            break;
          case 0; //child
            $this->db_connect(); //reconnect both child and parent - otherwise "MySQL server has gone away" error
            $this->log_truncate();
            exit();
            break;
          default: //parent
            $this->db_connect(); //reconnect both child and parent - otherwise "MySQL server has gone away" error
        }
      } 
    }
  }
}

private function log_insert($msg, $result) {
  try{
    $this->db
    ->prepare('INSERT INTO `' . MYIF_SYSTABLE_PREFIX . 'log` (log_ts,ip,msg,result) VALUES (now(), :ip, :msg, :result)')
    ->execute( ['ip'=>@$_SERVER['REMOTE_ADDR'], 'msg'=>$msg, 'result'=>$result] );
  }catch (\PDOException $e) {
    if($e->errorInfo[1]==1146) { //table does not exit
      return false;
    }else{
      throw $e;
    }
  }
  return true;
}

private function log_create() {
  $this->db->query('CREATE TABLE `' . MYIF_SYSTABLE_PREFIX . 'log` ( `log_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `ip` varchar(255) DEFAULT NULL, `msg` text, `result` varchar(255) DEFAULT NULL)');
}

private function log_truncate() {
   $delrows = $this->db->query('SELECT count(*) FROM `' . MYIF_SYSTABLE_PREFIX . 'log`')->fetchColumn() - MYIF_LOG_ROWS;
   if($delrows > 0) $this->db->query('DELETE FROM `' . MYIF_SYSTABLE_PREFIX . 'log` ORDER BY log_ts LIMIT ' . $delrows);
   $this->log_insert("log: deleted $delrows rows",'LOG');
}


//==========================================================
// WRITE INFLUX PROTOCOL DATA
//==========================================================

//parse an influx line protocol string "table,tag1=val1,tag2=val2 field1=val3,field4=val4 timestamp"
//returns 0 on success
public function parse($s) {
  $this->clear();

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
  $this->parse_fld($p[$i+1], $p[$i+3]);
  $i+=4;
  while($i < $cnt && $p[$i]==','){
    if($i+4 > $cnt) return 8;
    if($p[$i+2] != '=') return 9;
    $this->parse_fld($p[$i+1], $p[$i+3]);
    $i+=4;
  }
  if($i+1 < $cnt) {
    if($p[$i] != ' ') return 10;
    $this->setTs($p[$i+1]);
  }else{
    $this->setTs(time());
  }

  //split "tablename:precision"
  $parts = explode(':',$this->tbl,2);
  if(count($parts)==2) {
    $this->tbl = $parts[0];
    if(((int)$parts[1]) > 0) $this->precision = (int)$parts[1];
  } 
  return 0;
}

//set fld_mode
private function parse_fld($k,$v) {
  $parts = explode(':',$k,2);
  if(count($parts)==2) {
    $this->fld[$parts[0]] = $v;
    $this->fld_mode[$parts[0]] = $parts[1];
  }else{
    $this->fld[$k] = $v;
    $this->fld_mode[$k] = $this->mode;
  }
}

//write single influx line to database
public function write_line($line,$verbose) {
  $line = trim($line);
  if(!$line) return;
  if(substr($line,0,1)=='#') return;
  if($verbose) echo "$line -> ";
  try{
    $parse_result = $this->parse($line);
    if($parse_result != 0) {
      $result = "ERROR parse $parse_result";
    }else{
      $result = $this->write();
    }
    $this->log($line,$result);
    if($verbose) echo "$result\n";
  }catch(Exception $e){
    echo $e->getMessage().'\n';
  }
}

//write file with influx records to database
public function write_file($filename,$options) {
  $verbose = false;
  if(isset($options['precision'])) $this->setPrecision($options['precision']);
  if(isset($options['mode'])) $this->mode = $options['mode'];
  if(array_key_exists('verbose',$options)) $verbose = true;
  if(array_key_exists('noupdate',$options)) $this->noupdate = true;
  if(array_key_exists('nocreate',$options)) $this->nocreate = true;
  if(array_key_exists('noaddtag',$options)) $this->noaddtag = true;
  if(array_key_exists('noaddfield',$options)) $this->noaddfield = true;

  if(isset($options['data'])) $this->write_line($options['data'],$verbose);

  if(!$f = @fopen($filename,'r')) return ("ERROR opening file $filename\n");

  while (($line = fgets($f)) !== false) $this->write_line($line,$verbose);
  fclose($f);
  return '';
}

//==========================================================
// PRINT DATASET AS INFLUX PROTOCOL DATA
//==========================================================
//return influx line protocol string "table,tag1=val1,tag2=val2 field1=val3,field4=val4 timestamp"
public function protostring() {
  $s = $this->tbl;
  foreach($this->tag as $k=>$v) $s .= ",$k=$v";
  $s .= " ";
  $first = true;
  foreach($this->fld as $k=>$v) {
    $s .= ($first?"":",") . "$k=$v";
    $first = false;
  }
  $s .= " ";
  $s .= $this->ts; 
  return $s;
}

}//class

