# my-influxdb
Poor man's influxdb clone running on mysql

## What is this?
A full fledged InfluxDB is sometimes overkill. This is a solution uses a MySQL/MariaDB backend to store the data. It offers the dynamic data storage properties of a dedicated time series database, whilst maintaining the full data analysis properies of a SQL database such as table joins. The data is stored in plain tables, with the tags and fields as columns. The time column ```ts``` is of type timestamp and has second resolution. Tags are indexed varchar columns and fields (data values) are non indexed float columns. Primary key is the time plus tag columns. The tables, columns and indexes are dynamically created and altered as data arrives.

## Features
- Create tables as data arrives
- Alter table structure as data arrives
- Keep storage requirements within bounds: Unpopulated fields only take up one bit of data per field per row (with InnoDB compact row format).
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision. but different implementation as InfluxDB. A value in seconds can be set, and the timestamp is truncated to that many seconds. The last value written to a datapoint (row) is kept.
- Logging of write requests including ip address and result to a database table, and automatic delete of old log entries. 

## Not implemented
- Authentication
- Retention policies
- Continuous Queries

## Install
- Copy ```config.inc.php.template``` to ```config.inc.php``` and modify it as required.
- Execute ```php loadfile.php -v test.txt``` and look for tables ```i_test``` and ```log_influx_write``` in your database.
- Execute ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose=1' --data-binary @test.txt``` 

## Hints
- Keep the number of tags low. The tag indexes use a lot of space. 
