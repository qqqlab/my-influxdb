<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'influxdata');
define('DB_USER', 'root');
define('DB_PASS', '<<password>>');

//prefix for data tables - important! this keeps myinflux from modifying non-myinflux tables
define('MYIF_TABLE_PREFIX','i_');

//prefix for myinflux system tables
define('MYIF_SYSTABLE_PREFIX','isys_');

//number of rows to keep in isys_log - set to 0 to disable logging
//note: the log has a 1/1000 change of getting purged upon each write. It will also grow to approx 1000 rows more than the value set here.
define('MYIF_LOG_ROWS',10000);

