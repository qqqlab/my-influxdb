<?php

class MyInfluxDB {
public  $db;  //PDO database object

//set by parse()
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

//parse an influx line protocol string "table,tag1=val1,tag2=val2 field1=val3,field4=val4 timestamp" 
//returns 0 on success
public function parse($s) {
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

public function clear() {
  $this->tbl = null;
  $this->tag = [];
  $this->fld = [];
  $this->ts = null;
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
  ];
  $this->db = new PDO($dsn, DB_USER, DB_PASS, $options);
}

private function esc($v) {
  return substr($this->db->quote($v), 1, -1);
}

public function getDbTbl() {
  return MYIF_TABLE_PREFIX . $this->esc($this->tbl);
}

//get list of tags and fields from database
private function getDbTblInfo() {
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

private function insert() {
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

private function update() {
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

private function create() {
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

private function alter() {
  //load db meta data
  $meta = $this->getDbTblInfo();
  $err = '';

  //create fields (do not create field if column already exists as tag)
  foreach($this->fld as $k=>$v) if(!in_array($k,$meta['fld']) && !in_array($k,$meta['tag'])) {
    if($this->noaddfield) 
      $err = 'add field not allowed, noaddfield flag is set';
    else
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` ' . (is_numeric($v) ? 'FLOAT' : 'VARCHAR(255)') . ' NULL DEFAULT NULL' );
  }

  //create tags (do not create tag if column already exists as field)
  $created_tags = [];
  foreach($this->tag as $k=>$v) if(!in_array($k,$meta['tag']) && !in_array($k,$meta['fld'])) {
    if($this->noaddtag) {
      $err .= ($err?', ':'') . 'add tag not allowed, noaddtag flag is set';
    }else{
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` VARCHAR(255) NOT NULL AFTER `' . $this->esc(end($meta['tag'])) . '`' );
      $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD INDEX `' . $this->esc($k) . '` (`' . $this->esc($k) . '`)');
      $created_tags[] = $k;
    }
  }
  if($created_tags) {
    //set primary key to include new tag(s)
    $sql = 'ALTER TABLE `' . $this->getDbTbl() . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`ts`';
    foreach($meta['tag'] as $tag)  $sql .= ',`' . $this->esc($tag) . '`'; //current tags
    foreach($created_tags as $tag) $sql .= ',`' . $this->esc($tag) . '`'; //new tags
    $sql .= ') USING BTREE';
    $this->db_query($sql);
  }
  if($err) {$this->err = $err; return "ERROR";}
  return "OK";
}

private function db_query($sql) {
  //echo "$sql\n";
  return $this->db->query($sql);
}


//==========================================================
// WRITE
//==========================================================

//write to db - create table / alter table / insert record / update record as required
private function write_no_log() {
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

public function write($influx) {
  $rv = $this->parse($influx);
  if($rv != 0) {
    $result = "ERROR parse $rv";
  }else{
    $result = $this->write_no_log();
  }
  $this->log_write($influx,$result);
  return $result;
}

private function log_write($influx,$result) {
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

public function write_line($line,$verbose) {
  $line = trim($line);
  if(!$line) return;
  if(substr($line,0,1)=='#') return;
  if($verbose) echo "$line -> ";
  try{
    $rv = $this->write($line);
    if($verbose) echo "$rv\n";
  }catch(Exception $e){
    echo $e->getMessage().'\n';
  }
}

public function write_file($filename,$options) {
  $verbose = false;
  if(isset($options['precision'])) $this->setPrecision($options['precision']);
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


}//class

