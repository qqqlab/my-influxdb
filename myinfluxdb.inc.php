<?php

class MyInfluxDB {
public  $db;  //PDO database object
public  $tbl; //tablename
public  $tag; //array of tags and values
public  $fld; //array of fields and values
private $ts;  //timestamp in unix format
private $precision = 0; //number of seconds for rounding time stamps
public $noupdate = false; //disable updates
public $nocreate = false; //disable creating tables
public $noaddtag = false; //disable adding tags
public $noaddfield = false; //disable adding fields
public $err; //last error message

public function setTs($val) {
  if($this->precision) 
    $this->ts = intdiv($val, $this->precision) * $this->precision;
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


//public function __construct($db) {
//  $this->db = $db;
//}

//parse an influx line protocol string and return parsed object
//TODO: handle embedded spaces and special chars in strings
public function parse($influx) {
  $influx = trim($influx);
  $parts = preg_split('/\s+/',$influx);
  if(count($parts)<2 || count($parts)>3) return("ERROR parse1: invalid line '$influx'");
  $tbl_tag = preg_split('/,/',$parts[0]);
  $this->tbl = $tbl_tag[0];
  $this->tag = [];
  for($i=1; $i<count($tbl_tag); $i++) {
    $keyval = preg_split('/=/',$tbl_tag[$i]);
    if(count($keyval)!=2) return("ERROR parse2: invalid line '$influx'");
    $this->tag[$keyval[0]] = $keyval[1];
  }
  $fld_parts = preg_split('/,/',$parts[1]);
  $this->fld = [];
  for($i=0; $i<count($fld_parts); $i++) {
    $keyval = preg_split('/=/',$fld_parts[$i]);
    if(count($keyval)!=2) return("ERROR parse3: invalid line '$influx'");
    $keyval[1] = preg_replace('/(^"|"$)/', '', $keyval[1]); //remove double quotes
    $this->fld[$keyval[0]] = $keyval[1];
  }
  if(count($parts)==3){
    $this->setTs($parts[2]);
  }else{
    $this->setTs(time());
  }
  return "OK";
}


function clear() {
  $this->tbl = null;
  $this->tag = [];
  $this->fld = [];
  $this->ts = null;
}

//==========================================================
// MYSQL SPECIFIC
//==========================================================
function db_connect() {
  $charset = 'utf8mb4';
  $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=$charset";
  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];
  $this->db = new PDO($dsn, DB_USER, DB_PASS, $options);
}

function esc($v) {
  return substr($this->db->quote($v), 1, -1);
}

function getDbTbl() {
  return MYIF_TABLE_PREFIX . $this->esc($this->tbl);
}

//get list of tags and fields from database
function getDbTblInfo() {
  $rv = [];
  $result = $this->db->query('SHOW COLUMNS FROM `' . $this->getDbTbl() .'`');
  while($row = $result->fetch()){
    $istag = ($row['Key'] == 'PRI');
    $name = $row['Field'];
    if($name=='ts') continue;
    if($istag) $rv['tag'][]=$name; else $rv['fld'][]=$name;
  }
  return $rv;
}

function insert() {
  try{
    if($this->ts===null) return 'ts not set';

    $sql = 'INSERT INTO `' . $this->getDbTbl() . '`(ts';
    foreach($this->tag as $k=>$v) $sql .= ',`'.$this->esc($k).'`';
    foreach($this->fld as $k=>$v) $sql .= ',`'.$this->esc($k).'`';
    $sql .= ') VALUES (';
    $sql .= 'from_unixtime('.$this->esc($this->ts).')';
    foreach($this->tag as $k=>$v) $sql .= ',\'' . $this->esc($v) . '\'';
    foreach($this->fld as $k=>$v) $sql .= ',\'' . $this->esc($v) . '\'';
    $sql .= ')';
    $this->db_query($sql);
  }catch (\PDOException $e) {
    switch($e->errorInfo[1]) {
    case 1054: //unknow column
      return "ALTER";
    case 1062: //duplicate key
      return "UPDATE";
    case 1146: //table does not exit
      return "CREATE";
    default:
      $this->err = $e->getMessage(); 
      return "ERROR";
    }
  }
  return "OK";
}

function update() {
  try{
    if($this->ts===null) {$this->err = 'ts not set'; return 'ERROR';}
    if($this->noupdate) {$this->err = 'update not allowed, noupdate flag is set'; return 'ERROR';}

    $sql = 'UPDATE `' . $this->getDbTbl() . '` SET ';
    $first=true;
    foreach($this->fld as $k=>$v) {
      $sql .= ($first?'':',') . '`' . $this->esc($k) . '`=\'' . $this->esc($v) . '\'';
      $first=false;
    }
    $sql .= ' WHERE ts = ';
    $sql .= 'from_unixtime('.$this->esc($this->ts).')';
    foreach($this->tag as $k=>$v) $sql .= ' AND `' . $this->esc($k) . '`=\'' . $this->esc($v) . '\'';
    $sql.=' LIMIT 1';

    $this->db_query($sql);
  }catch (\PDOException $e) {
    $this->err = $e->getMessage();
    return "ERROR";
  }
  return "OK";
}

function create() {
  try{
    if($this->nocreate) {$this->err = 'create not allowed, nocreate flag is set'; return 'ERROR';}

    $sql = 'CREATE TABLE `' . $this->getDbTbl() . '`(ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
    foreach($this->tag as $k=>$v) $sql.=',`' . $this->esc($k) . '` VARCHAR(255) NOT NULL';
    foreach($this->fld as $k=>$v) $sql.=',`' . $this->esc($k) . '` ' . (is_numeric($v) ? 'FLOAT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL';
    $sql.=",PRIMARY KEY (ts";
    foreach($this->tag as $k=>$v) $sql.=",`".$this->esc($k)."`";
    $sql.=")";
    foreach($this->tag as $k=>$v) $sql.=",KEY `".$this->esc($k)."`(`".$this->esc($k)."`)";
    $sql.=")";
    $this->db_query($sql);
  }catch (\PDOException $e) {
    $this->err = $e->getMessage();
    return "ERROR";
  }
  return "OK";
}

function alter() {
  //load db meta data
  $meta = $this->getDbTblInfo();
  $err = '';

  //create fields
  foreach($this->fld as $k=>$v) if(!in_array($k,$meta['fld'])) {
    if($this->noaddfield) 
      $err = 'add field not allowed, noaddfield flag is set';
    else
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` ' . (is_numeric($v) ? 'FLOAT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL' );
  }

  //create tags
  $created_tag = false;
  foreach($this->tag as $k=>$v) if(!in_array($k,$meta['tag'])) {
    if($this->noaddtag) {
      $err .= ($err?', ':'') . 'add tag not allowed, noaddtag flag is set';
    }else{
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` VARCHAR(255) NOT NULL AFTER `' . $this->esc(end($meta['tag'])) . '`' );
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD INDEX `' . $this->esc($k) . '` (`' . $this->esc($k) . '`)');
      $created_tag = true;
    }
  }
  if($created_tag) {
    $meta = $this->getDbTblInfo(); //reload db meta data from altered database
    $sql = 'ALTER TABLE `' . $this->getDbTbl() . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`ts`';
    foreach($meta['tag'] as $tag) $sql .= ',`' . $this->esc($tag) . '`';
    $sql .= ') USING BTREE';
    $this->db_query($sql);
  }
  if($err) {$this->err = $err; return "ERROR";}
  return "OK";
}

function db_query($sql) {
  //echo "$sql\n";
  return $this->db->query($sql);
}


//==========================================================
// WRITE
//==========================================================

//write to db - create table / alter table / insert record / update record as required
function write_no_log() {
  $op = 'insert';
  $rv = $this->insert();
  switch($rv) {
    case "OK":
      return "OK $op";
    case "CREATE":
      $op = 'create';
      $rv = $this->create();
      if($rv == 'OK') {
        $op = 'create insert';
        $rv = $this->insert();
        if($rv=="OK") return("OK $op"); 
      }
      break;
    case "ALTER":
      $op = "alter";
      $rv = $this->alter();
      if($rv == 'OK') {
        $op = 'alter insert';
        $rv = $this->insert();
        switch($this->insert()) {
          case "OK": 
            return "OK $op";
          case "UPDATE":
            $op = 'alter update';
            $rv = $this->update();
            if($rv=="OK") return("OK $op"); 
        }
      }
      break;
    case "UPDATE":
      $op = 'update';
      $rv = $this->update();
      if($rv=="OK") return("OK $op");
      break;
  }
  return "ERROR $op: $this->err";
}

function write($influx) {
  $this->parse($influx);
  $result = $this->write_no_log();
  $this->log_write($influx,$result);
  return $result;
}

function log_write($influx,$result) {
  if(MYIF_LOG_DAYS>0) {
    $logtable = MYIF_SYSTABLE_PREFIX . 'log_write';
    try{
      $this->db
      ->prepare('INSERT INTO `' . $logtable . '` (log_ts,ip,influx,result) VALUES (now(), :ip, :influx, :result)')
      ->execute( ['ip'=>@$_SERVER['REMOTE_ADDR'], 'influx'=>$influx, 'result'=>$result] );
    }catch (\PDOException $e) {
      if($e->errorInfo[1]==1146) {
        //table does not exit
        //TODO: write first log entry
        $this->db->query('CREATE TABLE `' . $logtable . '` ( `log_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `ip` varchar(255) DEFAULT NULL, `influx` text, `result` varchar(255) DEFAULT NULL)');
      }else{
        throw $e;
      }
    }
    if(rand(0,999)==0){
      // 0.1% chance of getting executed
      if( pcntl_fork() <= 0 ) { 
        // execute in child process, or in parent process if could not fork
        $this->db->query('DELETE FROM `' . $logtable . '` WHERE log_ts < DATE_SUB(NOW(), INTERVAL ' . MYIF_LOG_DAYS . ' DAY)');
      }
    }
  }
}

function write_file($filename,$options) {
  $verbose = false;
  if(isset($options['precision'])) $this->setPrecision($options['precision']);
  if(array_key_exists('verbose',$options)) $verbose = true;
  if(array_key_exists('noupdate',$options)) $this->noupdate = true;
  if(array_key_exists('nocreate',$options)) $this->nocreate = true;
  if(array_key_exists('noaddtag',$options)) $this->noaddtag = true;
  if(array_key_exists('noaddfield',$options)) $this->noaddfield = true;

  if(!$f = @fopen($filename,'r')) return ("ERROR opening file $filename\n");

  while (($line = fgets($f)) !== false) {
    $line = trim($line);
    if(!$line) continue;
    if(substr($line,0,1)=='#') continue;
    if($verbose) echo "$line -> ";
    try{
      $rv = $this->write($line);
      if($verbose) echo "$rv\n";
    }catch(Exception $e){
      echo $e->getMessage().'\n';
    }
  }
  fclose($f);
  return '';
}


}//class

