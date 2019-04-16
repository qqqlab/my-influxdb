# my-influxdb
Poor man's InfluxDB clone with MySQL backend

## What is this?
A full fledged time series database like InfluxDB is sometimes overkill, and for DBAs it requires learning another toolset. 

This project uses MySQL/MariaDB to store time series data. 

Get the best of both worlds: dynamic data storage properties of a dedicated time series database, and the full power of a SQL database. (For example, InfluxDB 1.7 has limited support for update queries and delete queries, and table joins are completely missing.) 

## Getting Started 1-2-3
1. Create an empty MySQL database.
2. Set your database credentials in ```config.inc.php```
3. Setup completed, now try:

- ```php loadfile.php --verbose test.txt``` and look for newly created tables ```i_test``` and ```isys_log``` in your database.
- ```php loadfile.php``` without arguments to get a list of options. Endpoint write.php uses the same options.
- ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose' --data-binary @test.txt```
- ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose' --data-binary 'test,node=newnode rssi=-456'```

## Features
- Tables, columns and indices are dynamically created as data arrives.
- Influx Measurements are stored in plain tables, with timestamp, tags, and fields as columns. 
- Influx Tags are indexed varchar columns.
- Influx Fields (data values) are non indexed float or varchar columns. 
- The primary key is time column plus tag columns.
- Keep disk storage space under control: unpopulated fields only take up one bit per field per row (with InnoDB compact row format).
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision, but different implementation than InfluxDB. Precision is a value in seconds, and the timestamp is rounded down to that many seconds. Only the last value written to a datapoint (row) is kept in the database. For example: precision 3600 will only keep one value per hour, samples with timestamps 10:11:12 and 10:54:23 are both written to datapoint 10:00:00.
- Downsampling arriving data with Average, Min, Max, First, and Last operators.
- Optional logging of all write requests, ip address, and result to a database table, and automatic deletion of stale log entries. 

## Requirements
- Webserver with PHP >= 5.6 (tested with 5.6 & 7.0)
- MySQL >= 5.5 (for 5.5 set option innodb_file_per_table, >=5.6 also offers microsecond timestamps)

## Hints/Gotchas 
- Use the precision option to downsample data as it arrives.
- Extension of Line Protocol: A field name can be appended with colon (:) plus aggegration operator flag: [A]vg, mi[N], ma[X], [F]irst, [L]ast. For example: ```field1:A=5,field2:L="last"``` will store the average for field1 and the last value for field1 over the precision period. 
- Keep the number of tags low, the tag indexes use a lot of disk space.
- Sending an initial quoted numeric value creates a numeric (not text) column. (workaround: manually change the column to text.)
- Unlike InfluxDB all double quotes are removed from Line Protocol data. So ```test,tag="foo" fld="bar"``` stores ```foo``` and ```bar```, InfluxDB would store ```"foo"``` and ```bar```.
- Table names are case sensitive, tags and fields are case insensitive.

## Requirements
- Webserver with PHP >= 5.6 (tested with 5.6 & 7.0)
- MySQL >= 5.5 (for 5.5 set option innodb_file_per_table, >=5.6 also offers microsecond timestamps)

## Benchmarks
Benchmark write endpoint (single threaded http client -> dockerized vserver with 2G memory)  

without logging  
runtime multi: 1000 rows in 1.20 sec = 830 rows/sec  
runtime single: 1000 rows in 5.37 sec = 186 rows/sec

with logging  
runtime multi: 1000 rows in 2.39 sec = 418 rows/sec  
runtime single: 1000 rows in 6.92 sec = 144 rows/sec

## Not Implemented InfluxDB Features
- Authentication (workaround: use https and move the write.php script to a 'secret' location: /mysecretkey/write.php)
- Retention Policies (workaround: cron job or MySQL events)
- Continuous Queries (workaround: cron job or MySQL events)
