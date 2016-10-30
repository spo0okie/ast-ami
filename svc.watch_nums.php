#!/usr/bin/php -q
<?php
/* Файл сбора сообщений из AMI и слива в WS */
//require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'include.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.ws.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');

//logging properties
$logdir=$piddir='c:\\temp\\';
$globLogFile=$logdir.'/'.basename(__FILE__).'.msg.log';
$globErrFile=$logdir.'/'.basename(__FILE__).'.err.log';
initLog();

$usage=basename(__FILE__)." srvaddr:192.168.0.1 srvport:5038 srvuser:username srvpass:secret wsaddr:192.168.0.2 wsport:8000 wschan:channel1 [pid:Uniq_process_name] \n"
	."srvaddr:192.168.0.1  - AMI server address\n"
	."srvport:5038         - AMI interface port\n"
	."srvuser:username     - AMI user\n"
	."srvpass:secret       - AMI password\n"
	."wsaddr:192.168.0.2   - WebSockets server address\n"
	."wsport:8000          - WebSockets server port\n"
	."wschan:channel1      - WebSockets channel to post AMI messages\n";
	
if (!strlen($srvaddr=get_param('srvaddr'))) criterr($usage);
if (!strlen($srvport=get_param('srvport'))) criterr($usage);
if (!strlen($srvuser=get_param('srvuser'))) criterr($usage);
if (!strlen($srvpass=get_param('srvpass'))) criterr($usage);
if (!strlen($wsaddr =get_param('wsaddr')))  criterr($usage);
if (!strlen($wsport =get_param('wsport')))  criterr($usage);
if (!strlen($wschan =get_param('wschan')))  criterr($usage);

$p=basename(__FILE__).': '; //msg prefix

$channels=array();


/* ниже функции выдранный из самописанной библиотеки с прикладными 
 * функциями, дабы не тащить всю библиотеку весом 100кб, ради 10 функций
 * не особо подробно расписаны ибо в основном сервисные, программа будет
 * работать и без них
 */









function ws_send($caller, $callee, $evt)
{
	global $ws;
	if (!$ws->checkConnection()) {
		msg ('Lost WS! Reconnecting ... ');
		$ws->reconnect();
	}
	$ws->sendData('{"type":"event","caller":"'.$caller.'","callee":"'.$callee.'","event":"'.$evt.'"}');
}

function chan_getTech($name, $defEmpty=true) 
{// вертает технологию канала, если распарсить строку не получится то вернуть пусто если $defEmpty=true, иначе вернуть всю строку
	$tokens=explode('/',$name);
	if (count($tokens)>1) {	//все ОК
		return $tokens[0];
	} else 					//чет не то
		return $defEmpty?NULL:$name;
	
}

function chan_ckTech($name)
{//проверят интересен ли нам канал по этой технологии
	return !(chan_getTech($name)=='Local');	//отфильтруем для начала только локальные каналы, вроде по документации только они создаются синтетически
}

function src_from_name($name)
{//номер в имени канала
	if (!strlen($name)) return NULL;		//пустая строка
	$slash=strpos($name,'/'); 
	$dash=strrpos($name,'-'); 
	$at=strpos($name,'@'); //ищем / - @ в строке
	if (!$slash||!($at||$dash)) return NULL;		//несоотв синтаксиса
	$numend=($at&&$dash)?min($at,$dash):max($at,$dash);
	return substr($name,$slash+1,$numend-$slash-1);
}

function src_from_par($par)
{//номер в параметрах ивента
	if 	(isset($par['CallerIDNum'])&&strlen($num=$par['CallerIDNum'])) return $num;
	return NULL;
}


function chan_src($name,$par=NULL)
{	/*	вертает номер из имени канала, или CallerID*/

	if (strlen($fromname=src_from_name($name))&&is_numeric($fromname)) return $fromname;
	if (isset($par)&&strlen($frompar=src_from_par($par))&&is_numeric($frompar)) return $frompar;
	return NULL;
}

function chan_state($par)
{	$states=array(
		//'Down'=>NULL,				//Channel is down and available
		//'Rsrvd'=>NULL,			//Channel is down, but reserved
		//'OffHook'=>NULL,			//Channel is off hook
		//'Dialing'=>NULL,			//The channel is in the midst of a dialing operation
		'Ring'=>'Ring',				//The channel is ringing
		'Ringing'=>'Ringing',		//The remote endpoint is ringing. Note that for many channel technologies, this is the same as Ring.
		'Up'=>'Up',					//A communication path is established between the endpoint and Asterisk
		//'Busy'=>NULL,				//A busy indication has occurred on the channel
		//'Dialing Offhook'=>NULL,	//Digits (or equivalent) have been dialed while offhook
		//'Pre-ring'=>NULL,			//The channel technology has detected an incoming call and is waiting for a ringing indication
		//'Unknown'=>NULL			//The channel is an unknown state 
	);
		
	if 	(isset($par['ChannelStateDesc'])&&strlen($state=$par['ChannelStateDesc'])) {
		return isset($states[$state])?$states[$state]:NULL;	//by default uknown state is NULL
	} return NULL;
}

function chan_dst($evt,$par)
{//номер в параметрах ивента
	if (isset($par['ConnectedLineNum'])&&strlen($remote=$par['ConnectedLineNum'])&&is_numeric($remote)) return $remote;
	//msg ('no conline');
	if (isset($par['Exten'])&&strlen($remote=$par['Exten'])&&is_numeric($remote)) return $remote;
	//msg ('no exten');
	//var_dump($par);
	return NULL;
}

function chan_name($par)
{
	if (isset($par['Channel']) && strlen($chan=$par['Channel']) && chan_ckTech($chan)) return $chan;
	return NULL;
}

function chan_dump_all()
{
	global $chanlist;
	echo "chan list {\n";
	foreach ($chanlist as $name=>$chan) chan_dump($name);
	echo "}\n";
}

function chan_dump($name)
{
	global $chanlist;
	if (isset($chanlist[$name])) {
		switch ($chanlist[$name]['state']){
			case 'Ring':	$st=' --> '; break;
			case 'Ringing':	$st=' <-- '; break;
			case 'Up':		$st='<-!->'; break;
			default:		$st=' ??? '; break;
		}
		echo $name.':	'.$chanlist[$name]['src'].$st.$chanlist[$name]['dst']." \n";
	}
}

function chan_ws($name)
{
	global $chanlist;
	if (isset($chanlist[$name])) {
		switch ($chanlist[$name]['state']){
			case 'Ring':	ws_send($chanlist[$name]['src'],$chanlist[$name]['dst'],'ring'); break;
			case 'Ringing':	ws_send($chanlist[$name]['dst'],$chanlist[$name]['src'],'ring'); break;
			case 'Up':		ws_send($chanlist[$name]['src'],$chanlist[$name]['dst'],'connect'); break;
		}
	}
}

function chan_upd($evt,$par)
{
	global $chanlist;
	if (strlen($chan=chan_name($par))) //фильтруем события
	{
		if (!isset($chanlist[$chan])) $chanlist[$chan]=array('src'=>NULL,'dst'=>NULL,'state'=>NULL);		//создаем канал если его еще нет в списке
		$src	=chan_src($chan,$par);
		$dst	=chan_dst($chan,$par);
		$oldstate=$chanlist[$chan]['state'];
		
		//вариант только однократного обновления данных о номерах в канале
		if (!isset($chanlist[$chan]['src'])&&isset($src)) $chanlist[$chan]['src']=$src;
		if (!isset($chanlist[$chan]['dst'])&&isset($dst)) $chanlist[$chan]['dst']=$dst;
		
		//вариант многократного обновления
		//if (isset($src)) $chanlist[$chan]['src']=$src;
		//if (isset($dst)) $chanlist[$chan]['dst']=$dst;
		$chanlist[$chan]['state']=chan_state($par);
		if (isset($chanlist[$chan]['src'])&&isset($chanlist[$chan]['dst'])&&isset($chanlist[$chan]['state'])&&($oldstate!==$chanlist[$chan]['state']))	{
			chan_dump($chan);
			chan_ws($chan);
		}
	}
}

function evt_def($evt, $par, $server=NULL, $port=NULL)
{	/*	обработчик события по умолчанию (ищет сорц и статус, и если находит чтото - обновляет свой список каналов	*/
	global $p;
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	chan_upd($evt,$par);
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function evt_rename($evt,$par)
{
	global $chanlist;
	if (strlen($chan=chan_name($par)) && isset($par['Newname']) && chan_ckTech($par['Newname']))$chanlist[$par['Newname']]=$chanlist[$chan];
	evt_hangup($evt,$par);
}

function evt_hangup($evt,$par)
{
	global $chanlist;
	if (strlen($chan=chan_name($par)))	unset($chanlist[$chan]);
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function con_rotor()
{	// рисует вращающийся курсор, дабы было ясно что не зависло
	global $phpagirotor;
	$sym=array('| ','/ ','- ','--',' -',' \\',' |',' /',' -','--','- ','\\ ');
	if (!isset($phpagirotor)) $phpagirotor=0;
	$phpagirotor %= count($sym);
	echo $sym[($phpagirotor++)]."\r";
}

function AMI_defaultevent_handler($evt, $par, $server=NULL, $port=NULL)
{	
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	con_rotor();					//update con
	pidWriteSvc(basename(__FILE__));//heartbeat file
}	
	
if ($dummyMode) echo "DUMMY MODE!";

msg($p.'Script started');
msg($p.'Checking numeric func ... ');


/*
 Всетаки придется завести хранилище каналов, поскольку иногда понятно 
 кто кому будет звонить только на событии newchannel,
 а к событию Ринг или Ап, уже нужные данные частично потерты.
 итого нам нужно хранить о канале следующие данные:
 src	- один номер
 dst	- второй номер (удаленная сторона, не обязательно именно callee)
 state	- статус канала
 */
msg($p.'Initializing chanlist');
$chanlist=array();


while (true) {
	pidWriteSvc(basename(__FILE__));//heartbeat
 
	
	msg($p.'Init AMI interface class ... ',1);
		$astman = new AGI_AsteriskManager(null,array('server'=>$srvaddr,'port'=>$srvport,'username'=>$srvuser,'secret'=>$srvpass));

	msg($p.'Init AMI event handlers ... ',1);
		$astman->add_event_handler('state',			'evt_def');
		$astman->add_event_handler('newstate',		'evt_def');
		$astman->add_event_handler('newcallerid',	'evt_def');
		$astman->add_event_handler('newchannel',	'evt_def');
		$astman->add_event_handler('hangup',		'evt_hangup');
		$astman->add_event_handler('rename',		'evt_rename');

	msg($p.'Connecting AMI inteface ... ');
	if ($astman->connect()) {
		
		msg($p.'Connecting WS ... ');
		$ws = new WebsocketClient;
		if ($ws->connect($wsaddr, $wsport, '/', 'server')) {
			$ws->sendData('hello Server!');
			$ws->sendData('{"type":"subscribe","channel":"'.$wschan.'"}');

			msg($p.'Switching AMI events ON ... ',1);
				$astman->Events('on');

			msg($p.'AMI event waiting loop ... ');
				pidWriteSvc(basename(__FILE__));//heartbeat
				while (!$astman->socket_error&&$ws->checkConnection())
					$astman->wait_response();

			if ($astman->socket_error) msg($p.'AMI Socket error!');
			if ($ws->checkConnection()) msg($p.'WS Socket error!');
			msg($p.'Loop exited. ');
				$astman->disconnect();
		} else msg ($p.'Err connecting WS.');
	} else msg ($p.'Err connecting AMI.');
	unset($astman);
	unset($ws);
	msg($p.'AMI reconnecting ... ');
	sleep(1);
}

exit;
?>
