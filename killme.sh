#!/bin/sh
process=`ps aux|grep svc.watch_ami.php|grep -v grep|tr -s ' '|cut -d' ' -f2`
echo killing $process
kill $process
