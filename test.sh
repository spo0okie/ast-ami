#!/bin/sh
srv=10.1.10.3
port=5038
./svc.watch_ami.php verbose debug:6 srvaddr:$srv srvport:$port srvuser:astami srvpass:AstAm1pa55word112 conout:yes oci1srvv:srv-db.nppx.local ociinst:orcl ociuser:ics ocipass:Trk_icsPwd
