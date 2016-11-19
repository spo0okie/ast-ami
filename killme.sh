#!/bin/sh
process=`ps aux|grep service.php|grep -v grep|tr -s ' '|cut -d' ' -f2`
echo killing $process
kill $process
