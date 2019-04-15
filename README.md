# my-influxdb
Poor man's InfluxDB clone with MySQL backend

## What is this?
A full fledged time series database like InfluxDB is sometimes overkill, and for DBAs it requires learning another toolset. 

This project uses MySQL/MariaDB to store time series data. 

Get the best of both worlds: the dynamic data storage properties of a dedicated time series database, and the full power of a SQL database. (Update, delete queries and table joins are not or only limited available in InfluxDB v1.7.) 

## Getting Started
- Set your database credentials in ```config.inc.php```
- ```php loadfile.php --verbose test.txt``` and look for newly created tables ```i_test``` and ```isys_log``` in your database.
- ```php loadfile.php``` without arguments to get a list of options.
- ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose' --data-binary @test.txt```
- ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose' --data-binary 'test,node=newnode rssi=-456'```

## Features
- Tables, columns and indices are dynamically created as data arrives.
- The data is stored in plain tables, with the tags and fields as columns. 
- Tags are indexed varchar columns.
- Fields (data values) are non indexed float or varchar columns. 
- The primary key is time column plus tag columns.
- Keep disk storage within bounds: Unpopulated fields only take up one bit of data per field per row (with InnoDB compact row format).
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision, but different implementation as InfluxDB. A value in seconds can be set, and the timestamp is truncated to that many seconds. The last value written to a datapoint (row) is kept.
- Optional logging of write requests, ip address, and result to a database table, and automatic deletion of stale log entries. 

## Hints/Gotchas
- A numeric column when sending an initial quoted numeric value. (workaround: manually change the column to text.)
- Keep the number of tags low, the tag indexes quickly use a lot of disk space. 

## Requirements
- Webserver with PHP >= 5.6 (tested on 5.6 & 7.0)
- MySQL >= 5.5 (for 5.5 set option innodb_file_per_table, >=5.6 also offers microsecond timestamps)

## Benchmarks
Benchmark write endpoint (single threaded http client -> dockerized vserver with 2G memory)
runtime multi: 1000 rec in 4.23 sec = 236 rec/sec
runtime single: 1000 rec in 11.67 sec = 85 rec/sec

## Not Implemented
- Authentication (workaround: use https and place the write.php script in a 'secret' directory /mysecretkey/write.php)
- Retention Policies (workaround: cron job)
- Continuous Queries (workaround: cron job)
