# my-influxdb
Poor man's influxdb clone running on mysql

## What is this?
A full fledged InfluxDB is sometimes overkill. I wanted a simple solution on Mysql/MariaDB with the dynamic data storage properties of a dedicated time series database. The data is stored in plain tables, with the tags and fields as columns. The time column ts is a timestamp with second resolution. tags are indexed varchar columns and fields (data values) are non indexed float columns. Primary key is the time plus tag columns. The table and indexes are dynamically created and updated as data is written to a table.

## Features
- Create tables as data arrives
- Modify tables as data arrives
- Keep storage requirements within bounds: Unpopulated fields only take up one bit of data per field per row (with InnoDB compact row format).
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision. but different implementation as InfluxDB. A value in seconds can be set, and the timestamp is truncated to that many seconds. The last value written to a datapoint (row) is kept.

## Not implemented
- Authentication
- Retention policies
- Continuous Queries

## Install
- Copy to your webspace, copy ```config.inc.php.template``` to ```config.inc.php``` and modify it as required.
- Execute ```php loadfile.php -v test.txt``` and look for tables ```i_test``` and ```log_influx_write``` in your database.
- Execute ```curl -i -XPOST 'http://localhost/my-influxdb/write.php?verbose=1' --data-binary @test.txt``` 

## Hints
- Keep the number of tags low. The indexes use a lot of space. 
