# my-influxdb
Poor man's influxdb clone running on mysql

## What is this?
A full fledged InfluxDB is sometimes overkill. For DBAs it requires learning another toolset. This is a solution uses a MySQL/MariaDB backend to store the timeseries data. MyInfluxDB offers the dynamic data storage properties of a dedicated time series database, whilst maintaining the full power of a SQL database with DELETE, UPDATE and table joins which are not or limited available in InfluxDB v1.7. 

## Features
- The data is stored in plain tables, with the tags and fields as columns. 
- The time column ```ts``` is of type timestamp and has second resolution. 
- Tags are indexed varchar columns.
- Fields (data values) are non indexed float or varchar columns. 
- The primary key is time plus tag columns. 
- Create tables dynamically as data arrives.
- Alter columns and indices dynamically as data arrives.
- Keep disk storage within bounds: Unpopulated fields only take up one bit of data per field per row (with InnoDB compact row format).
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision, but different implementation as InfluxDB. A value in seconds can be set, and the timestamp is truncated to that many seconds. The last value written to a datapoint (row) is kept.
- Logging of write requests including ip address and result to a database table, and automatic deletion of stale log entries. 

## Not implemented
- Authentication (workaround: use https and place the write.php script in a directory /mysecretkey/write.php)
- Retention Policies (workaround: cron job)
- Continuous Queries (workaround: cron job)

## Install
- Copy ```config.inc.php.template``` to ```config.inc.php``` and modify it as required.
- Execute ```php loadfile.php --verbose test.txt``` and look for tables ```i_test``` and ```isys_log_write``` in your database.
- Execute ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose' --data-binary @test.txt``` 

## Hints/Gotchas
- The Influx Line Protocol parser does not support spaces in strings (not even in quoted strings).
- The Influx Line Protocol parser creates a numeric column when sending an initial quoted numeric value.
- Keep the number of tags low. The tag indexes use a lot of space. 
