# my-influxdb
Poor man's influxdb clone running on mysql

## What is this
A full fledged InfluxDB is sometimes overkill. I wanted a simple solution on Mysql/MariaDB with the dynamic data storage properties of a dedicated time series database. The data is stored in plain tables, with the tags and fields as columns. The time column ts is a timestamp with second resolution. tags are indexed varchar columns and fields (data values) are non indexed float columns. Primary key is the time plus tag columns. The table and indexes are dynamically created and updated as data is written to a table.

## Implemented
- Create tables as data arrives
- Modify tables as data arrives
- Keep storage requirements within bounds
- HTTP /write API for writing data with InfluxDB Line Protocol 
- Load data from file with InfluxDB Line Protocol 
- Precision. but different implementation as InfluxDB. A value in seconds can be set, and the timestamp is truncated to that many seconds. The last value written to a datapoint (row) is kept.

## Not implemented
- Authentication
- Retention policies
- Continuous Queries

## Install
- Copy to your webspace and modify config.inc.php
- Execute ```php loadfile.php test.txt``` and look for table test in your database


## Hints
-- Keep the number of tags low. The indexes use a lot of space. Unpopulated fields only take up one bit of data per field per row.
