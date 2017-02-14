#!/usr/bin/php -q
<?php
/*
	следит за состоянием сервисов и запускает/перепускает при необходимости
*/

$services=array('svc.watch_chans.php','svc.watch_calls.php');
//$services=array('svc.watch_calls.php');

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'include.php');
$globLogging=false;
$globVerbose=true;
$globDebugLevel=0;

define('TIME_TO_FREEZE',120);
define('TIME_TO_KILL',40);
define('TIME_TO_START',20);


function svcKill($svc)
{
	$p="svcKill($svc): ";
	$killingtime=time();
	while(getCurrentProcs($svc)&&((time()-$killingtime)<TIME_TO_KILL)) {
		$exec='killall '.$svc;
		msg($p.'running '.$exec);
		exec($exec);
		sleep(2);
	}
	return (time()-$killingtime)<TIME_TO_KILL;
}

function svcStart($svc)
{
	$p="svcStart($svc): ";
	$starttime=time();
	$exec=dirname(__FILE__) . DIRECTORY_SEPARATOR . $svc. ' >> /dev/null 2>&1 &';
	msg($p.'running '.$exec);
	exec($exec);
	while(!getCurrentProcs($svc)&&((time()-$starttime)<TIME_TO_START))	sleep(1);
	return (time()-$starttime)<TIME_TO_START;
}

function svcCheckOnline($svc)
{
	if (!($procs=getCurrentProcs($svc))||($age=pidGetAgeSvc($svc))===false||($age>TIME_TO_FREEZE)) {
		if (!$procs) 					msg('service '.$svc.' not running!');
		elseif ($age===false)	 		msg('service '.$svc.' got no PID file!');
		elseif ($age>TIME_TO_FREEZE) 	msg('service '.$svc.' looks like stuck for '.$age.' seconds!');
		if (!svcKill($svc)){
			//some panic here
			err('Can\'t kill '.$svc.'!');
		} else if (!svcStart($svc)){
			//some other panic here
			err('Can\'t start '.$svc.'!');
		} else msg($svc.' restarted sucessfully');
	}
}


function svcsKill()
{
	global $services;
	global $verb;
	
	foreach ($services as $svc) {
		svcKill($svc);
		if ($verb) msg($svc.'	: '.($t=getCurrentProcs($svc)).' processes; '.(!$t?'kill OK':'kill ERR'));
	}
}

function svcsStart()
{
	global $services;
	global $verb;
	
	foreach ($services as $svc) {
		svcCheckOnline($svc);
		if ($verb) msg($svc.'	: '.getCurrentProcs($svc).' processes;	last heartbeat was '.pidGetAgeSvc($svc).' seconds ago');
	}
}

if ($verb=get_argv('verbose')) msg('Verbose mode');

$mode=strtolower(isset($argv[1])?$argv[1]:'start');
switch ($mode) {
	case 'stop':
		svcsKill();
		break;
	case 'restart':
		svcsKill();
		svcsStart();
		break;
	case 'start':
	default:
		svcsStart();
		break;
}


?>
