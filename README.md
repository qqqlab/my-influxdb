# my-influxdb
Poor man's InfluxDB clone with MySQL backend

## What is this?
A full fledged time series database like InfluxDB is sometimes overkill, and for DBAs it requires learning another toolset. This solution uses a MySQL/MariaDB backend to store the time series data. MyInfluxDB offers the dynamic data storage properties of a dedicated time series database, whilst maintaining the full power of a SQL database with update, delete queries and table joins which are not or limited available in InfluxDB v1.7. 

## Getting Started
- Copy ```config.inc.php.template``` to ```config.inc.php``` and modify it as required.
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

## Not Implemented
- Authentication (workaround: use https and place the write.php script in a 'secret' directory /mysecretkey/write.php)
- Retention Policies (workaround: cron job)
- Continuous Queries (workaround: cron job)
