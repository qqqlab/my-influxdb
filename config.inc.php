<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'influxdata');
define('DB_USER', 'root');
define('DB_PASS', '<<password>>');

//prefix data tables - prevents myinfluxdb from modifying non-myinflux tables
define('MYIF_TABLE_PREFIX','i_');

//prefix for system tables - prevents myinfluxdb from modifying non-myinflux tables
define('MYIF_SYSTABLE_PREFIX','isys_');

//logging
define('MYIF_LOG_ROWS',10000); //number of records to keep in log - set to 0 to disable logging

