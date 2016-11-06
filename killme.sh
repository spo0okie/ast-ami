#!/bin/sh
kill `ps aux|grep service.php|grep -v grep|tr -s ' '|cut -d' ' -f2`
