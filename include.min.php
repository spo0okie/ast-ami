<?php
	//options
	//where is the logs stored
	date_default_timezone_set('Asia/Yekaterinburg');
	$logdir='/var/log';
	$piddir='/tmp';
	//logfiles names
	$msgscriptlog=$logdir.'/spoo.ast.msg.log';
	$msgscripterr=$logdir.'/spoo.ast.msg.err.log';
	//where from scrip is running
	$scriptdir=dirname(__FILE__);

	
	//need to run php script in bg
	$phpbin='/usr/bin/php';

	//defaults
	//no debug
	$globScriptID='';
	$globDebugLevel=0;
	//be silent and don't be dummy!
	$globVerbose=$dummyMode=false;
	//log default
	$globLogging=true;
	$globErrFile=$msgscripterr; //NULL to disable
	$globLogFile=$msgscriptlog; //NULL to disable

	include('private/config.php');

	define('defCallTime',90);		//default calltime



	ob_implicit_flush(false);
	//set_time_limit(5);
	//error_reporting(0);

	function initLog($debug=2,$logging=true,$agiVerb=false){
		/* logging properties */
		global $globLogging;
		global $dummyMode;
		global $globVerbose;
		global $globDebugLevel;
		global $globAGIVerbose;

		$globLogging=$logging;
		$globAGIVerbose=$agiVerb;
		$dummyMode=get_param('dummy');
		$globVerbose=get_param('verbose')||$dummyMode;
		$globDebugLevel=max(get_param('debug'),$debug);
	}

	function initAgi() {
		global $dummyMode;
		if ($dummyMode) return; //disable AGI in dummy mode
		global $agi;
		global $callerid;
		global $exten;
		global $channel;
		global $UID;
		global $CSID;
		global $p;
		$agi = new AGI();	
		initDB(); //нужно для поиска подменных CallerID в бд
		msg($p.'Got EXTEN:	'.($exten=cmd_getExten()),5);
		msg($p.'Got CHANNEL:'.($channel=cmd_getChan()),5);
		msg($p.'Got CID:	'.($callerid=cmd_getCID()),5);
		msg($p.'Got UID:	'.($UID=cmd_getUID()),5);
		msg($p.'Got CALL ID:'.($CSID=cmd_getID()),5);
	}
	
	function initDB() {global $p;	db_connect_safe($p.'can not continue without DB connetion!');}

	/*
	тулкит для парсинга коммандной строки, в которой передаем параметры не по очередности а по именам
	cmd.php foo:foo bar:baz test
	*/
	function argvName($par){if ($tokens=explode(':',$par)) return $tokens[0]; else return $par;}
	function argvVal($par) {
		if ($tokens=explode(':',$par))
			if (count($tokens)>1)
				return $tokens[1];
		return true;
	}

	/*
	тулкит для работы с ПИДами
	*/
	function pidGetFname($base)		//файл для хранения пида
	{	global $piddir; return $piddir.'/spoo.'.$base.'.pid';}

	function pidWrite($file)
	{/*	записать пид в файл*/
		$p='pidWrite: ';
		if (!strlen($file)) {
			err($p.'no filename given');
			return false;
		}
		$f = fopen($file,"w");
		$pid=getmypid();
		if ($res=fwrite($f,$pid))
			msg($p.'wrote '.$pid.' to '.$file,5);
		else
			err($p.' fail to write '.$pid.' to '.$file);
		fclose($f);
		return $res;
	}

	function pidGetAge($file)
	{/*	возраст файла пида*/
		$p='pidGetAge: ';
		if (!strlen($file)) {
			err($p.'no filename given');
			return false;
		}
		if (!($ftime=filemtime($file))) {
			err($p.'can\'t get file modification time for '.$file);
			return false;
		}
		//msg('now is '.date('r',time()).' file '.$file.' is '.date('r',$ftime));
		return time()-$ftime;
	}

	function pidGetAgeSvc($svc)		//вернуть возраст пид файла сервиса
	{	return pidGetAge(pidGetFname($svc));}

	function pidWriteSvc($svc)			//записать мой пид
	{	return pidWrite(pidGetFname($svc));}


	function pidGetFnameMy()		//имя моего пидфайла
	{	return pidGetFname(basename(__FILE__));}

	function pidGetAgeMy()			//возраст моего пидфайла
	{	return pidGetAge(pidGetFnameMy());}

	function pidWriteMy()			//записать мой пид
	{	global $dummyMode;
		//if (!$dummyMode)
		pidWrite(pidGetFnameMy());
	}


	function get_argv($name) {global $argv; for ($i=1;$i<count($argv);$i++) if ($name==argvName($argv[$i])) return argvVal($argv[$i]); return false;}

	function get_param($var){
		global $_GET;
		global $_POST;
		if (isset($_GET[$var])&&strlen($result=$_GET[$var])) return $result;
		if (isset($_POST[$var])&&strlen($result=$_POST[$var])) return $result;
		return get_argv($var);
	}


	function Halt($text) {die('HALT: '.$text."\n");}
	//returning date in readable form
	function logdate() {return date("y.m.d H:i:s: ");}

	//standart msg output in log files
	function msglog($text,$debug=0,$err=0) { //log to file if need
		global $globDebugLevel;
		global $globLogFile;
		global $globErrFile;
		if ($debug<=$globDebugLevel) {
			if ($globLogFile!==NULL) {
				$h = fopen($globLogFile,"a");
					if (!fwrite($h,$text."\n")) Halt('log: err writing "'.$globLogFile.'"');
				fclose($h);
			}
			if (($err)&&(!($globErrFile==NULL))) {
				$h = fopen($globErrFile,"a");
					if (!fwrite($h,$text."\n")) Halt('log: err writing "'.$globErrFile.'"');
				fclose($h);
			}
		}
	}

	function msgagi($text,$debug=0,$err=0) {
		global $globDebugLevel;
		global $globAGIVerbose;
		global $agi;
		if (($globAGIVerbose)&&($debug<=$globDebugLevel)&&isset($agi)) $agi->verbose($text);
	}


	function msg($text,$debug=0,$err=0) { //verbose and log to file if need
		global $globDebugLevel;
		global $globVerbose;
		global $globAGIVerbose;
		global $globLogging;
		$dateprefx=logdate();
		if (($globVerbose)&&($debug<=$globDebugLevel)) echo $dateprefx.$text."\n";
		if ($globLogging) msglog($dateprefx.$text,$debug,$err);
		if ($globAGIVerbose) msgagi($text,$debug,$err);
	}

	function err($text,$debug=0) {msg('ERROR: '.$text,$debug,1);} //2 above with err prefix
	function criterr($text) {msg('CRITICAL ERROR: '.$text,0,1); Halt($text);} //above with die

	function myDate_dayStart($date=null)
	{
		if (!$date) return mktime(0,0,0);
		else 		return mktime(0,0,0, date("m", $date1), date("d", $date1)+1, date("Y", $date1));
	}

	function getCurrentProcs($procname)
	{/* возвращает текущее количество процессов парсинга/сжатия звонков*/
		$p="getCurrentProcs($procname): ";
		$cmd='ps ax|grep '.$procname.'|grep -v grep|wc -l';
		msg($p.'running '.$cmd,5);
		$res=(int)exec($cmd,$out);
		msg($p.'result: '.$res,5);
		foreach ($out as $line) msg($p.'output: '.$res,5);
		return $res;
	}

	function send_get_req($url)
	{
		return file_get_contents($url);
	}


	function send_post_req($post_data,$serv_addr,$serv_page,$serv_port = 80)
	{	//отправляет POST запрос с данными $post_data на сервер/страницу из параметров
		//возвращает ответ
		global $p;
		$pp=$p.'send_post_req: ';
		// Генерируем строку с POST запросом
		if (is_array($post_data)) {
			$post_data_text = '';
			foreach ($post_data AS $key => $val)
				$post_data_text .= $key.'='.urlencode($val).'&';
			$post_data_text = substr($post_data_text, 0, -1);
		} else $post_data_text = $post_data;

		// Прописываем заголовки, для передачи на сервер
		// Последний заголовок должен быть обязательно пустым,
		// так как тело запросов отделяется от заголовков пустой строкой (символом перевода каретки "\r\n")
		$headers = array('POST /'.$serv_page.' HTTP/1.1',
						 'Host: '.$serv_addr,
						 'Content-type: application/x-www-form-urlencoded',
						 'Content-length: '.strlen($post_data_text),
						 'Accept: */*',
						 //'Transfer-Encoding: chunked',
						 'Connection: Close',
						 '');
		$headers_txt = '';
		foreach ($headers AS $val)
			$headers_txt .= $val.chr(13).chr(10);

		// Создание общего запроса (заголовки и тело запроса)
		/*$request_body = $headers_txt.
					dechex(strlen($post_data_text)).chr(13).chr(10).
					$post_data_text.chr(13).chr(10).
					'0'.chr(13).chr(10).
					chr(13).chr(10);
		*/
		$request_body = $headers_txt.$post_data_text.chr(13).chr(10).chr(13).chr(10);

		// Открытие сокета
		$sp = fsockopen($serv_addr, $serv_port, $errno, $errstr, 2);

		if ($sp){
			// Передача заголовков и POST запросов за один раз
			msg($pp.'REMOTE << '.$request_body,2);
			fwrite($sp, $request_body);
			msg($pp.'REMOTE << sent OK, reading answer',2);

			$server_answer = '';
			$server_header = '';

			$start = time();
			$done=false;
			$header_flag = 1;
				while(!feof($sp) && ((time() - $start) < 2) && !$done)
				{
					$content = fgets($sp, 4096);
					msg($pp.'REMOTE >> sent block :'.$content,5);
					if ($header_flag == 1)
					{
						if ($content === chr(13).chr(10))
							$header_flag = 0;
						else
							$server_header .= $content;
					}else{
						if ($content === chr(13).chr(10))
							$done=true;
						else
							$server_answer .= $content;
					}
				}

				fclose($sp);
			msg($pp.'REMOTE >> '.$server_header,5);
			msg($pp.'REMOTE >> '.$server_answer,2);

			return $server_answer;
		} else {
			err($pp.'Can not open socket '.$serv_addr.':'.$serv_port.'; Error:'.$errstr.' #'.$errno);
		}
	}


	function array_uniquepush(&$arr, $val) { //кладет элемент если его еще нет в массиве
		if (array_search($val,$arr,true)===false) $arr[]=$val;
	}
?>
