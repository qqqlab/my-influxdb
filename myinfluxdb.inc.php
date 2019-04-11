<?php

class MyInfluxDB {
public  $db;  //PDO database object
public  $tbl; //tablename
public  $tag; //array of tags and values
public  $fld; //array of fields and values
private $ts;  //timestamp in unix format
private $precision = 0; //number of seconds for rounding time stamps

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
public function parse($influx) {
  $influx = trim($influx);
  $parts = preg_split('/\s+/',$influx);
  if(count($parts)<2 || count($parts)>3) die("ERROR2 invalid line '$influx'");
  $tbl_tag = preg_split('/,/',$parts[0]);
  $this->tbl = $tbl_tag[0];
  $this->tag = [];
  for($i=1; $i<count($tbl_tag); $i++) {
    $keyval = preg_split('/=/',$tbl_tag[$i]);
    if(count($keyval)!=2) die("ERROR3 invalid line '$influx'");
    $this->tag[$keyval[0]] = $keyval[1];
  }
  $fld_parts = preg_split('/,/',$parts[1]);
  $this->fld = [];
  for($i=0; $i<count($fld_parts); $i++) {
    $keyval = preg_split('/=/',$fld_parts[$i]);
    if(count($keyval)!=2) die("ERROR4 invalid line '$influx'");
    $this->fld[$keyval[0]] = $keyval[1];
  }
  if(count($parts)==3){
    $this->setTs($parts[2]);
  }else{
    $this->setTs(time());
  }
  //print_r($this);
}


function clear() {
  $this->tbl = null;
  $this->tag = [];
  $this->fld = [];
  $this->ts = null;
}

//==========================================================
// DATABASE
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
  $tag = [];
  $fld = [];
  $stm = $this->db->query('SELECT * FROM `' . $this->getDbTbl() .'` LIMIT 0');
  for($i=0;$i<$stm->columnCount();$i++) {
    $inf = $stm->getColumnMeta($i);
    $type = $inf['native_type'];
    $name = $inf['name'];
    if($name=='ts') continue;
    if($type=='VAR_STRING') {
      $tag[]=$name;
    }else{
      $fld[]=$name;
    }
    //print_r($stm->getColumnMeta($i));
  }
  return ['tag'=>$tag, 'fld'=>$fld];
}

//create insert sql from parsed influx
function insert_sql() {
  if($this->ts===null) return '';

  $sql = 'INSERT INTO `' . $this->getDbTbl() . '`(ts';
  foreach($this->tag as $k=>$v) $sql .= ',`'.$this->esc($k).'`';
  foreach($this->fld as $k=>$v) $sql .= ',`'.$this->esc($k).'`';
  $sql .= ') VALUES (';
  $sql .= 'from_unixtime('.$this->esc($this->ts).')';
  foreach($this->tag as $k=>$v) $sql .= ",'".$this->esc($v)."'";
  foreach($this->fld as $k=>$v) $sql .= ','.$this->esc($v);
  $sql.=')';
  return $sql;
}

//update record
function update_sql() {
  if($this->ts===null) return '';

  $sql = 'UPDATE `' . $this->getDbTbl() . '` SET ';
  $first=true;
  foreach($this->fld as $k=>$v) {
    $sql .= ($first?'':',') . '`' . $this->esc($k) . '`=' . $this->esc($v);
    $first=false;
  }
  $sql .= ' WHERE ts = ';
  $sql .= 'from_unixtime('.$this->esc($this->ts).')';
  foreach($this->tag as $k=>$v) $sql .= " AND `" . $this->esc($k) . "`='" . $this->esc($v) . "'";
  $sql.=' LIMIT 1';
  return $sql;
}


function create_sql() {
  $sql = 'CREATE TABLE `' . $this->getDbTbl() . '`(ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
  foreach($this->tag as $k=>$v) $sql.=",`".$this->esc($k)."` VARCHAR(255) NOT NULL";
  foreach($this->fld as $k=>$v) $sql.=",`".$this->esc($k)."` FLOAT DEFAULT NULL";
  $sql.=",PRIMARY KEY (ts"; 
  foreach($this->tag as $k=>$v) $sql.=",`".$this->esc($k)."`";
  $sql.=")";
  foreach($this->tag as $k=>$v) $sql.=",KEY `".$this->esc($k)."`(`".$this->esc($k)."`)";
  $sql.=")";
  return $sql;
}

function alter() {
  //load db meta data
  $meta = $this->getDbTblInfo();

  //create fields
  foreach($this->fld as $k=>$v) if(!in_array($k,$meta['fld'])) {
    $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` FLOAT NULL DEFAULT NULL' );
  }

  //create tags
  $created_tag = false;
  foreach($this->tag as $k=>$v) if(!in_array($k,$meta['tag'])) {
    $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD `' . $this->esc($k) . '` VARCHAR(255) NOT NULL AFTER `' . $this->esc(end($meta['tag'])) . '`' );
    $this->db_query('ALTER TABLE `' . $this->getDbTbl() . '` ADD INDEX `' . $this->esc($k) . '` (`' . $this->esc($k) . '`)');
    $created_tag = true;
  }
  if($created_tag) {
    $meta = $this->getDbTblInfo(); //reload db meta data from altered database
    $sql = 'ALTER TABLE `' . $this->getDbTbl() . '` DROP PRIMARY KEY, ADD PRIMARY KEY (`ts`';
    foreach($meta['tag'] as $tag) $sql .= ',`' . $this->esc($tag) . '`';
    $sql .= ') USING BTREE';
    $this->db_query($sql);
  }
  return "OK";
}

function db_query($sql) {
  //echo "$sql\n";
  return $this->db->query($sql);
}

//==========================================================
// CRUD
//==========================================================

function insert() {
  try{
    $this->db_query($this->insert_sql());
  }catch (\PDOException $e) {
    switch($e->errorInfo[1]) {
    case 1054: //unknow column
      return "ALTER";
    case 1062: //duplicate key
      return "UPDATE";
    case 1146: //table does not exit
      return "CREATE";
    default:
      print_r($e);
      return "ERROR";
    }
  }
  return "OK";
}

function update() {
  try{
    $this->db_query($this->update_sql());
  }catch (\PDOException $e) {
    print_r($e);  
    return "ERROR";
  }
  return "OK";
}

function create() {
  try{
    $this->db_query($this->create_sql());
  }catch (\PDOException $e) {
    print_r($e);
    return "ERROR";
  }
  return "OK";
}

//write to db - create table / alter table / insert record / update record as required
function write_no_log() {
  switch($this->insert()) {
    case "OK":
      return "OK insert";
      break;
    case "CREATE":
      switch($this->create()) {
        case "OK":
          if($this->insert()!="OK") return("ERROR insert2");
          return "OK create insert";
          break;
        default:
          return("ERROR create");
      }
      break;
    case "ALTER":
      switch($this->alter()) {
        case "OK":
          switch($this->insert()) {
            case "OK": 
              return "OK alter insert";
            case "UPDATE":
              if($this->update()!="OK") return("ERROR update");
              return "OK alter update";
            default: 
              return("ERROR alter update");
          }
          break;
        default:
          return("ERROR alter");
      }
      break;
    case "UPDATE":
      if($this->update()!="OK") return("ERROR update");
      return "OK update";
      break;
    default:
      return("ERROR insert");
  }
  return "ERROR unknown";
}

function write($influx) {
  $this->parse($influx);
  $result = $this->write_no_log();
  if(MYIF_LOG_DAYS>0) {
    try{
      $this->db
      ->prepare('INSERT INTO `' . MYIF_LOG_TABLE . '` (log_ts,ip,influx,result) VALUES (now(), :ip, :influx, :result)')
      ->execute( ['ip'=>@$_SERVER['REMOTE_ADDR'], 'influx'=>$influx, 'result'=>$result] );
    }catch (\PDOException $e) {
      if($e->errorInfo[1]==1146) {
        //table does not exit
        $this->db->query('CREATE TABLE `' . MYIF_LOG_TABLE . '` ( `log_ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, `ip` varchar(255) DEFAULT NULL, `influx` text, `result` varchar(255) DEFAULT NULL)');
      }else{
        throw $e;
      }
    }
    if(rand(0,999)==0){ 
      // 0.1% chance of getting executed
      $this->db->query('DELETE FROM `' . MYIF_LOG_TABLE . '` WHERE log_ts < DATE_SUB(NOW(), INTERVAL ' . MYIF_LOG_DAYS . ' DAY)');
    }
  }
  return $result;
}

}//class

