<?php


define('REQUESTS_LOG_LEVEL',5);			//уровень логирования для отображения запросов
define('RESPONSES_LOG_LEVEL',5);		//уровень логирования для отображения ответов
define('INFO_EVENTS_LOG_LEVEL',5);	    //уровень логирования для отображения полезных обрабатываемых событий
define('HANDLED_EVENTS_LOG_LEVEL',6);	//уровень логирования для отображения обрабатываемых событий
define('IGNORED_EVENTS_LOG_LEVEL',7);	//уровень логирования для отображения обрабатываемых но отброшенных событий
define('EVENTS_LOG_LEVEL',8);			//уровень логирования всех событий



class astConnector {
	/**
	 * @var AGI_AsteriskManager
	 */
	private $astman;		//AGI manager

	private $conParams;
	private $defaultEvtHandler;

	/**
	 * @var chanList
	 */
	private $chans;
	private $p;


	function __construct($params,$chans,$defHandler) {
		$this->conParams=$params;
		$this->p='astConnector('.$params['server'].'): ';
		$this->chans=$chans;
		$this->defaultEvtHandler=$defHandler;
		$this->chans->attachAMI($this);
	}

	public function get_info() {return $this->p;}

	public function get_chan_var($channel, $variable) 
	{//возвращает переменную из канала
		$response=$this->astman->GetVar($channel, $variable);
		//msg($this->p.'Got getvar responce:'.$response);

		//Обрабатываем ошибки
		if (isset($response['Response']) && ($response['Response'] == 'Error')) {
			if (isset($response['Message']) && ($response['Message'] == 'No such channel')) {
				//если нам вернули ответ, что нет такого канала, то оч интересно посмотреть, что у нас вообще есть за каналы
				$activeChans=$this->astman->Command('core show channels concise');
				msg('Chan '.$channel.' not found in '.print_r($activeChans['data'],true),RESPONSES_LOG_LEVEL,1);
			}
		}
		return isset($response['Value'])?$response['Value']:null;
		/*
		 * Начал получать такие вот ошибки на запрос
		 * 20.04.02 11:14:34: AstManager(evt:183(caps:1427,1426),rsp:1): Response:
		 * Response => Error
		 * ActionID => 1585808074.445
		 * Message => No such channel
		 */

	}
	
	public function set_chan_var($channel, $variable, $value)
	{
		$this->astman->SetVar($channel, $variable, $value);
		/*if ($this->get_chan_var($channel, $variable)!=$value)
		msg($this->p.'Err setting '.$variable.' into '.$channel.'!');
		else
		msg($this->p.'Sucessfully set '.$variable.' into '.$channel.'!');*/
	}

	/**
	 * обработчик события AMI по умолчанию
	 * ищет сорц, дестинейшн и статус,
	 * и если находит чтото - обновляет этими данными список каналов
	 * @param $evt
	 * @param $par
	 * @param null $server
	 * @param null $port
	 */

	public function evt_def($evt, $par, $server=NULL, $port=NULL)
	{
		//если раскомментировать то что ниже, то в консольке можно будет
		//посмотреть какая нам информация приходит с теми событиями
		//на которые повешен этот обработчик

		//нет смысла логировать тут, оно потом отлогируется в upd
		//msg('Got evt '.dumpEvent($par)HANDLED_EVENTS_LOG_LEVEL); 

		$this->chans->upd($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function evt_rename($evt,$par)
	{//обработчик события о переименовании канала
		$this->chans->ren($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function evt_hangup($evt,$par)
	{//обработчик события о смерти канала
		//(обновление статуса для передачи в БД самым дешевым способом)
		$par['ChannelStateDesc']='Hangup'; //подсовываем обновление канала фейковым статусом окончания разговора
		//обновляем канал
		$this->chans->upd($par);
		$this->chans->ren($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function connect() {
		msg($this->p.'Init AMI interface class ... ',1);
			$this->astman = new AGI_AsteriskManager(null,$this->conParams);
			$this->astman->log_handler='msg';
		msg($this->p.'Init AMI event handlers ... ',1);
			//https://wiki.asterisk.org/wiki/display/AST/Asterisk+11+AMI+Events
			$this->astman->add_event_handler('hangup',			array($this,'evt_hangup'));	//class CALL
			$this->astman->add_event_handler('newcallerid',		array($this,'evt_def'));	//class CALL
			$this->astman->add_event_handler('newchannel',		array($this,'evt_def'));	//class CALL
			//$this->astman->add_event_handler('newexten',		array($this,'evt_def'));	//class DIALPLAN
			$this->astman->add_event_handler('newstate',		array($this,'evt_def'));	//class CALL
			$this->astman->add_event_handler('rename',			array($this,'evt_rename'));	//class CALL
			$this->astman->add_event_handler('state',			array($this,'evt_def')); 	//??
			$this->astman->add_event_handler('*',				'AMI_default_event_handler');
		msg($this->p.'Connecting AMI interface ... ');
			if (!$this->astman->connect()) return false;
		msg($this->p.'Switching AMI events ON ... ',1);
			$this->astman->Events('call');
		return true;
	}

	public function checkConnection() {
		if (!$this->astman->socket->error()) return true;
		msg ($this->p.'AMI socket error!');
		return false;
	}

	public function waitResponse() {
		return $this->astman->wait_response();
	}

	public function disconnect() {
		$this->astman->disconnect();
		unset ($this->astman);
	}

	public function astStatus() {
		return is_object($this)?$this->astman->dump():'disconnected';
	}
}
