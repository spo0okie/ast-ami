#!/usr/bin/php -q
<?php
/* Файл сбора сообщений из AMI и слива в WS */

//прикладные функции работы с логом файлами и проч. 
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.inc.php');	
//библиотека работы с астериском
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');
//класс коннекторов к получателям данных
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'class.extConnector.php');	
//класс коннекторa к asterisk AMI
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'class.chans.php');	


//папка логов
$tmp='/tmp/';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $tmp='c:\\temp\\';
} 
$logdir=$piddir=$tmp;	//куда будем писать логи и хартбиты сервисов
$globLogFile=$logdir.DIRECTORY_SEPARATOR.basename(__FILE__).'.msg.log';
$globErrFile=$logdir.DIRECTORY_SEPARATOR.basename(__FILE__).'.err.log';
initLog();

$usage="Correct usage is:\n"
	.basename(__FILE__)." srvaddr:192.168.0.1 srvport:5038 srvuser:username srvpass:secret [<wsaddr:192.168.0.2> <wsport:8000> <wschan:channel1>] [<ocisrv:127.0.0.1> <ociinst:orcl> <ociuser:orauser> <ocipass:password1>]\n"
	."srvaddr:192.168.0.1  - AMI server address\n"
	."srvport:5038         - AMI interface port\n"
	."srvuser:username     - AMI user\n"
	."srvpass:secret       - AMI password\n"
	."- to translate to WebSockets channel use:"
	."wsaddr:192.168.0.2   - WebSockets server address\n"
	."wsport:8000          - WebSockets server port\n"
	."wschan:channel1      - WebSockets channel to post AMI messages\n"
	."- to translate to Oracle table use:"
	."ocisrv:127.0.0.1     - Oracle server address\n"
	."ociinst:orcl         - Oracle server instance\n"
	."ociuser:oruser       - Oracle server user\n"
	."ocipass:password1    - Oracle server password\n";
	
if (!strlen($srvaddr=get_param('srvaddr'))) criterr($usage);
if (!strlen($srvport=get_param('srvport'))) criterr($usage);
if (!strlen($srvuser=get_param('srvuser'))) criterr($usage);
if (!strlen($srvpass=get_param('srvpass'))) criterr($usage);


$globConnParams=array();

//Используем ли мы вебсокеты?
if (strlen($wsaddr=get_param('wsaddr'))) {
	//если указан сервер вебсокетов, то используем. Тогда еще нужны учетные данные
	if (!strlen($wsport =get_param('wsport')))  criterr($usage);
	if (!strlen($wschan =get_param('wschan')))  criterr($usage);	
	//библиотека работы с WebSocket
	require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.ws.php');
	//в список параметров подключения к внешним получалям данных добавляем вебсокеты
	$globConnParams[]=array('wsaddr'=>$wsaddr,'wsport'=>$wsport,'wschan'=>$wschan);
}

if (strlen($ocisrv=get_param('ocisrv'))) {
	//если указан сервер вебсокетов, то используем. Тогда еще нужны учетные данные
	if (!strlen($ociinst =get_param('ociinst')))  criterr($usage);
	if (!strlen($ociuser =get_param('ociuser')))  criterr($usage);	
	if (!strlen($ocipass =get_param('ocipass')))  criterr($usage);	
	//библиотека работы с WebSocket
	require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.ws.php');
	//в список параметров подключения к внешним получалям данных добавляем вебсокеты
	$globConnParams[]=array('ocisrv'=>$ocisrv,'ociinst'=>$ociinst,'ociuser'=>$ociuser,'ocipass'=>$ocipass);
}





/*	ФУНКЦИИ РАБОТЫ С КАНАЛАМИ АСТЕРИСКА */



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

/*	ОБРАБОТЧИКИ СОБЫТИЙ ПОЛУЧАЕМЫХ ОТ АСТЕРИСКА */

$chans = new chanList();

function evt_def($evt, $par, $server=NULL, $port=NULL)
{	/*	обработчик события AMI по умолчанию 
	* ищет сорц, дестинейшн и статус, 
	* и если находит чтото - обновляет этими данными список каналов	*/
	
	//если раскомментировать то что ниже, то в консольке можно будет
	//посмотреть какая нам информация приходит с теми событиями
	//на которые повешен этот обработчик
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	global $chans;
	$chans->upd($par);
	
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function evt_rename($evt,$par)
{//обработчик события о переименовании канала
	global $chans;
	$chans->ren($par);
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function evt_hangup($evt,$par)
{//обработчик события о смерти канала
	global $chans;
	$chans->ren($par);
	//chan_dump_all();
	AMI_defaultevent_handler($evt,$par);
}

function con_rotor()
{	//рисует вращающийся курсор, дабы было в консоли было видно что процесс жив
	global $phpagirotor;
	$sym=array('| ','/ ','- ','--',' -',' \\ ',' |',' /',' -','--','- ','\\ ');
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
//	msg('Got evt "'.$evt.'"');
//	print_r($par);
	con_rotor();					//update con
//	global $wschan;
	pidWriteSvc(basename(__FILE__));//heartbeat file
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


$connector = new globDataConnector($globConnParams);
$chans->connect($connector);
$ast = new astConnector(array('server'=>$srvaddr,'port'=>$srvport,'username'=>$srvuser,'secret'=>$srvpass));
$p=basename(__FILE__).'('.$connector->getType().'): '; //msg prefix

//собственно понеслась
//msg($p.'Script started');

while (true) {
	pidWriteSvc(basename(__FILE__));//heartbeat

	if ($ast->connect()) {

		msg($p.'Connecting data receivers ... ');
		if ($connector->connect()) {

			msg($p.'AMI event waiting loop ... ');
				pidWriteSvc(basename(__FILE__));//heartbeat

				while ($ast->checkConnection()&&$connector->checkConnection())	//пока с соединениями все ок
					$ast->waitResponse();	//обрабатываем события

			msg($p.'Loop exited. ');
			$connector->disconnect();

		} else msg ($p.'Err connecting data recivers');

	} else msg ($p.'Err connecting AMI.');

	$ast->disconnect();

	msg($p.'Reconnecting ... ');
	sleep(1);
}

exit; //а вдруг)
?>
