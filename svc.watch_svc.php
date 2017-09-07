#!/usr/bin/php -q
<?php
define('TIME_TO_FREEZE',120);
define('TIME_TO_KILL',40);
define('TIME_TO_START',20);

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.inc.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'priv.conf.php');
//список сервисов для запуска

//папка логов
$tmp='/var/log/asterisk/';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	$tmp='c:\\temp\\';
}
//

$logdir=$piddir=$tmp;	//куда будем писать логи и хартбиты сервисов

	

/*
 * прибивает конкретный сервис (тупо по имени файла)
 */	
function svcKill($svc)
{
	$p="svcKill($svc): ";
	$pid=pidReadSvc($svc);
	$output='';
	$killingtime=time();
	while((pidCheck($pid))&&((time()-$killingtime)<TIME_TO_KILL/2)) {
		$exec='kill '.$pid;
		msg("$p waiting $pid ...");
		exec($exec,$output);
		sleep(2);
	}
	
	$killingtime=time();
	while((pidCheck($pid))&&((time()-$killingtime)<TIME_TO_KILL/2)) {
		$exec='kill -9 '.$pid;
		msg("$p waiting $pid ...");
		exec($exec,$output);
		sleep(2);
	}
	return pidCheck($pid);
}

/*
 * запускает сервис (имя файла, параметры)
 */
function svcStart($svc,$params)
{
	$p="svcStart($svc): ";
	$starttime=time();
	$exec=dirname(__FILE__) . DIRECTORY_SEPARATOR . $svc. ' ' . $params . ' >> /dev/null 2>&1 &';
	msg($p.'running '.$exec);
	exec($exec);
	while(!getCurrentProcs($svc)&&((time()-$starttime)<TIME_TO_START))	sleep(1);
	return (time()-$starttime)<TIME_TO_START;
}

function svcCheckOnline($svc,$params)
{
	if (!($procs=pidCheckSvc($svc))||($age=pidGetAgeSvc($svc))===false||($age>TIME_TO_FREEZE)) {
		if (!$procs) 					msg('service '.$svc.' not running!');
		elseif ($age===false)	 		msg('service '.$svc.' got no PID file!');
		elseif ($age>TIME_TO_FREEZE) 	msg('service '.$svc.' looks like stuck for '.$age.' seconds!');
		if (!svcKill($svc)){
			//some panic here
			err('Can\'t kill '.$svc.'!');
		} 
		if (!svcStart($svc,$params)){
			//some other panic here
			err('Can\'t start '.$svc.'!');
		} else msg($svc.' restarted sucessfully');
	}
}


function svcsKill($services)
{
	global $verb;

	foreach ($services as $svc=>$param) {
		svcKill($svc);
		msg($svc.'	: '.($t=getCurrentProcs($svc)).' processes; '.(!$t?'kill OK':'kill ERR'));
	}
}

function svcsStart($services)
{
	global $verb;

	foreach ($services as $svc=>$param) {
		svcCheckOnline($svc,$param);
		msg($svc.'	: '.getCurrentProcs($svc).' processes;	last heartbeat was '.pidGetAgeSvc($svc).' seconds ago');
	}
}

initLog();
if ($globVerbose) msg('Verbose mode');

if (!count($services_list)) Halt('No services defined in priv.conf.php');

$mode=strtolower(isset($argv[1])?$argv[1]:'start');
switch ($mode) {
	case 'stop':
		svcsKill($services_list);
		break;
	case 'restart':
		svcsKill($services_list);
		svcsStart($services_list);
		break;
	case 'start':
	default:
		svcsStart($services_list);
		break;
}
	
	
		
?>