#!/usr/bin/php -q
<?php
/* Файл сбора сообщений из AMI и слива в WS */

//прикладные функции работы с логом файлами и проч. 
//можно убрать и вырезать все обращения к пропавшим функциям 
//и функционал не изменится, изменится только интерфейс
//а вообще библиотека итак изрядно почищена от ненужного
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.inc.php');	
//библиотека работы с WebSocket
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.ws.php');
//библиотека работы с астериском
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');

//папка логов
$logdir=$piddir='c:\\temp\\';	//куда будем писать логи и хартбиты сервисов
$globLogFile=$logdir.'/'.basename(__FILE__).'.msg.log';
$globErrFile=$logdir.'/'.basename(__FILE__).'.err.log';
initLog();

$usage=basename(__FILE__)." srvaddr:192.168.0.1 srvport:5038 srvuser:username srvpass:secret wsaddr:192.168.0.2 wsport:8000 wschan:channel1\n"
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

$p=basename(__FILE__).'('.$wschan.'): '; //msg prefix


/*	ФУНКЦИИ РАБОТЫ С КАНАЛАМИ АСТЕРИСКА */

function chan_getTech($name, $defEmpty=true) 
{// вертает технологию канала, 
 // если распарсить строку не получится то вернуть 
 //(пусто если $defEmpty=true, иначе вернуть всю строку)
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
{//ищем номер звонящего абонента в имени канала
	if (!strlen($name)) return NULL;		//пустая строка
	$slash=strpos($name,'/'); 
	$dash=strrpos($name,'-'); 
	$at=strpos($name,'@'); //ищем / - @ в строке
	if (!$slash||!($at||$dash)) return NULL;		//несоотв синтаксиса
	$numend=($at&&$dash)?min($at,$dash):max($at,$dash);
	return substr($name,$slash+1,$numend-$slash-1);
}

function src_from_par($par)
{//ищем номер звонящего абонента в параметрах ивента
	if 	(isset($par['CallerIDNum'])&&strlen($num=$par['CallerIDNum'])) return $num;
	return NULL;
}

function chan_src($name,$par=NULL)
{//вертает номер звонящего абонента из имени канала, или CallerID
	if (strlen($fromname=src_from_name($name))&&is_numeric($fromname)) return $fromname;
	if (isset($par)&&strlen($frompar=src_from_par($par))&&is_numeric($frompar)) return $frompar;
	return NULL;
}

function chan_dst($evt,$par)
{//ищем номер вызываемого абонента в параметрах ивента
	if (isset($par['ConnectedLineNum'])&&strlen($remote=$par['ConnectedLineNum'])&&is_numeric($remote)) return $remote;
	if (isset($par['Exten'])&&strlen($remote=$par['Exten'])&&is_numeric($remote)) return $remote;
	return NULL;
}

function chan_state($par)
{//возвращает статус канала из параметров ивента, 
 //но только если этотстатус нас интересует
 //можно раскоментить и другие статусы, но нужно потом их обрабатывать
		$states=array(
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
		
	if 	(isset($par['ChannelStateDesc'])&&strlen($state=$par['ChannelStateDesc'])) //если статус в ивенте указан
		return isset($states[$state])?$states[$state]:NULL;	//возвращаем его если он есть в фильтре
	
	return NULL; //на нет и суда нет
}


function chan_name($par)
{//возвращает имя канала из параметров ивента с учетом фильтра технологий соединения
	if (isset($par['Channel']) && strlen($chan=$par['Channel']) && chan_ckTech($chan)) return $chan;
	return NULL;
}

function chan_dump_all()
{//дампит в консоль список известных на текущий момент соединений с их статусами
	global $chanlist;
	echo "chan list {\n";
	foreach ($chanlist as $name=>$chan) chan_dump($name);
	echo "}\n";
}

function chan_dump($name)
{//дампит в консоль один канал
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
{//пишем в вебсокеты информацию о канале
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
{//обновляем информацию о канале новыми данными
	global $chanlist;
	if (strlen($chan=chan_name($par))) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
	{
		if (!isset($chanlist[$chan])) $chanlist[$chan]=array('src'=>NULL,'dst'=>NULL,'state'=>NULL);		//создаем канал если его еще нет в списке
		$src	=chan_src($chan,$par);	//ищем вызывающего
		$dst	=chan_dst($chan,$par);	//ищем вызываемого
		$oldstate=$chanlist[$chan]['state'];	//запоминаем старый статус
		
		//вариант однократного обновления данных о номерах в канале
		//ищем абонентов до тех пор пока не найдем, следующие изменения абонентов игнорируем
		if (!isset($chanlist[$chan]['src'])&&isset($src)) $chanlist[$chan]['src']=$src;
		if (!isset($chanlist[$chan]['dst'])&&isset($dst)) $chanlist[$chan]['dst']=$dst;
		
		//вариант многократного обновления
		//if (isset($src)) $chanlist[$chan]['src']=$src;
		//if (isset($dst)) $chanlist[$chan]['dst']=$dst;
		
		$chanlist[$chan]['state']=chan_state($par);//устанавливаем статус
		
		//если у нас накопился законченный набор информации 
		//и статус отличается от старого
		if (isset($chanlist[$chan]['src'])&&isset($chanlist[$chan]['dst'])&&isset($chanlist[$chan]['state'])&&($oldstate!==$chanlist[$chan]['state']))	{
			chan_dump($chan);	//сообщаем об этом радостном событии в консольку
			chan_ws($chan);		//и на сервер вебсокетов
		}
	}
}

/*	ОБРАБОТЧИКИ СОБЫТИЙ ПОЛУЧАЕМЫХ ОТ АСТЕРИСКА */

function evt_def($evt, $par, $server=NULL, $port=NULL)
{	/*	обработчик события AMI по умолчанию 
	* ищет сорц, дестинейшн и статус, 
	* и если находит чтото - обновляет этими данными список каналов	*/
	
	//если раскомментировать то что ниже, то в консольке можно будет
	//посмотреть какая нам информация приходит с теми событиями
	//на которые повешен этот обработчик
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	chan_upd($evt,$par);
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function evt_rename($evt,$par)
{//обработчик события о переименовании канала
	global $chanlist;
	//если канал переименовался не в виртуальный - 
	//создаем его экземпляр с новым именем
	if (strlen($chan=chan_name($par)) && isset($par['Newname']) && chan_ckTech($par['Newname']))$chanlist[$par['Newname']]=$chanlist[$chan]; 
	//старый удаляем
	evt_hangup($evt,$par);
}

function evt_hangup($evt,$par)
{//обработчик события о смерти канала
	global $chanlist;
	if (strlen($chan=chan_name($par)))	unset($chanlist[$chan]);
	//chan_dump_all(); //если следим за списком каналов, то после чьейто смерти он тоже меняется
	AMI_defaultevent_handler($evt,$par);
}

function con_rotor()
{	//рисует вращающийся курсор, дабы было в консоли было видно что процесс жив
	global $phpagirotor;
	$sym=array('| ','/ ','- ','--',' -',' \\',' |',' /',' -','--','- ','\\ ');
	if (!isset($phpagirotor)) $phpagirotor=0;
	$phpagirotor %= count($sym);
	echo $sym[($phpagirotor++)]."\r";
}

function AMI_defaultevent_handler($evt, $par, $server=NULL, $port=NULL)
{//обработчик всех прочих событий от астериска
 //на нем висит обновление статусов процесса
 //перезапись сердцебиения и перерисовка курсора в консольке
 //имя файла формируется по имени канала WebSocket
 //это не очень удобно, можно придумать любой другой способ именования
 
	//если раскомментировать 2 строки ниже, всю консоль зафлудит 
	//всякими сообщениями от астериска, не только теми которые по звонкам
	//а вообще все что он шлет (а он шлет много)...
	//но для понимания картины событий можно и глянуть время от времени
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	con_rotor();					//update con
	global $wschan;
	pidWriteSvc(basename(__FILE__).'.'.$wschan);//heartbeat file
	//файл сердцебиения сервиса. 
	//в нем лежит PID процесса
	//нужен для отслеживания жизни процесса
	//если время обновления файла будет больше какого-то времени
	//то имеет смысл убить процесс (PID на этот случай в файле)
	//и создать новый экземпляр
}	
	
function ws_send($caller, $callee, $evt)
{//отправляем сообщение в вебсокеты
	global $ws;
	if (!$ws->checkConnection()) {
		msg ('Lost WS! Reconnecting ... ');
		$ws->reconnect();
		$ws->sendData('{"type":"subscribe","channel":"'.$wschan.'"}');
	}
	$ws->sendData('{"type":"event","caller":"'.$caller.'","callee":"'.$callee.'","event":"'.$evt.'"}');
}


//собственно понеслась
msg($p.'Script started');
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
			msg($p.'Subscribing channel in WS ... ');
			$ws->sendData('{"type":"subscribe","channel":"'.$wschan.'"}');

			msg($p.'Switching AMI events ON ... ',1);
				$astman->Events('on');

			msg($p.'AMI event waiting loop ... ');
				pidWriteSvc(basename(__FILE__));//heartbeat
				while (!$astman->socket_error&&$ws->checkConnection())	//пока с соединениями все ок
					$astman->wait_response();	//обрабатываем события

			if ($astman->socket_error) msg($p.'AMI Socket error!');
			if ($ws->checkConnection()) msg($p.'WS Socket error!');
			msg($p.'Loop exited. ');
			$astman->disconnect();
			$ws->disconnect();
		} else msg ($p.'Err connecting WS.');
	} else msg ($p.'Err connecting AMI.');
	unset($astman);
	unset($ws);
	msg($p.'Reconnecting ... ');
	sleep(1);
}

exit; //а вдруг)
?>
