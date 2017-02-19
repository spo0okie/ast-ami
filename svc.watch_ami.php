#!/usr/bin/php -q
<?php
/* Файл сбора сообщений из AMI и слива во внешние хранилища данных
 * на текущий момент поддерживаются:
 * - вывод в консоль 
 * - запись в БД Oracle
 * - запись в канал WebSockets (не проверялось давно, и с тех пор много кода поменялось) */

//прикладные функции работы с логом файлами и проч. 
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'funcs.inc.php');	
//библиотека работы с астериском
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');
//класс коннекторов к получателям данных
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'class.extConnector.php');	
//класс коннекторa к asterisk AMI
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'class.amiConnector.php');	
//класс управления списком каналов
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
	."srvpass:secret       - AMI password\n\n"
	
	."- to translate to Console  use:"
	."conout:yes           - Use console output\n\n"

	."- to translate to WebSockets channel use:"
	."wsaddr:192.168.0.2   - WebSockets server address\n"
	."wsport:8000          - WebSockets server port\n"
	."wschan:channel1      - WebSockets channel to post AMI messages\n\n"
	
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
if (strlen($conout=get_param('conout'))) {
	//если указан сервер вебсокетов, то используем. Тогда еще нужны учетные данные
	$globConnParams[]=array('conout'=>$conout);
}

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



function AMI_defaultevent_handler($evt, $par, $server=NULL, $port=NULL)
{//обработчик всех прочих событий от астериска
 //на нем висит перезапись файла сердцебиения и перерисовка курсора в консольке
 //имя файла формируется по имени этого файла
 //это не очень удобно, можно придумать любой другой способ именования
 
	//если раскомментировать 2 строки ниже, всю консоль зафлудит 
	//всякими сообщениями от астериска, не только теми которые по звонкам
	//а вообще все что он шлет (а он шлет много)...
	//но для понимания картины событий можно и глянуть время от времени
	//msg('Got evt "'.$evt.'"');
	//print_r($par);
	con_rotor();					//update con
	pidWriteSvc(basename(__FILE__));//heartbeat file
	//файл сердцебиения сервиса. 
	//в нем лежит PID процесса
	//нужен для отслеживания жизни процесса
	//если время обновления файла будет больше какого-то времени
	//то имеет смысл убить процесс (PID на этот случай в файле)
	//и создать новый экземпляр
}	

//Коннектор к внешним БД
$DBconnector = new globDataConnector($globConnParams);

//Класс списка каналов подключатся к внешним источникам, чтобы передавать туда информацию о событиях
$chans = new chanList($DBconnector);

//Коннектор к AMI подключается к списку каналов чтобы передавать в него информацию о событиях поступающих от Asterisk
$AMIconnector = new astConnector(array('server'=>$srvaddr,'port'=>$srvport,'username'=>$srvuser,'secret'=>$srvpass),$chans,'AMI_defaultevent_handler');

$p=basename(__FILE__).'('.$DBconnector->getType().'): '; //msg prefix

//собственно понеслась
//msg($p.'Script started');

while (true) {
	pidWriteSvc(basename(__FILE__));//heartbeat

	if ($AMIconnector->connect()) {

		msg($p.'Connecting data receivers ... ');
		if ($DBconnector->connect()) {

			msg($p.'AMI event waiting loop ... ');
				pidWriteSvc(basename(__FILE__));//heartbeat

				while ($AMIconnector->checkConnection()&&$DBconnector->checkConnection()) {	//пока с соединениями все ок
					$AMIconnector->waitResponse();	//обрабатываем события
					usleep(50000);	//если вдруг всосали и обработали всю очередь то отдохнуть 0.05с.
				}

			msg($p.'Loop exited. ');
			$DBconnector->disconnect();

		} else msg ($p.'Err connecting data recivers');

	} else msg ($p.'Err connecting AMI.');

	$AMIconnector->disconnect();

	msg($p.'Reconnecting ... ');
	sleep(1);
}

exit; //а вдруг)
?>
