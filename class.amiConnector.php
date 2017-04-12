<?php
class astConnector {
	private $astman;
	private $conParams;
	private $defaultEvtHandler;
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
		return $response['Value'];
	}
	
	public function set_chan_var($channel, $variable, $value)
	{
		$this->astman->SetVar($channel, $variable, $value);
		/*if ($this->get_chan_var($channel, $variable)!=$value)
		msg($this->p.'Err setting '.$variable.' into '.$channel.'!');
		else
		msg($this->p.'Sucessfully set '.$variable.' into '.$channel.'!');*/
	}

	public function evt_def($evt, $par, $server=NULL, $port=NULL)
	{	/*	обработчик события AMI по умолчанию 
		* ищет сорц, дестинейшн и статус, 
		* и если находит чтото - обновляет этими данными список каналов	*/
		
		//если раскомментировать то что ниже, то в консольке можно будет
		//посмотреть какая нам информация приходит с теми событиями
		//на которые повешен этот обработчик
		global $globVerbose;
		if ($globVerbose>5) {
			msg('Got evt "'.$evt.'"');
			print_r($par);
		}
		global $chans;
		$this->chans->upd($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function evt_rename($evt,$par)
	{//обработчик события о переименовании канала
		global $chans;
		$this->chans->ren($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function evt_hangup($evt,$par)
	{//обработчик события о смерти канала
		global $chans;
		$this->chans->ren($par);
		if (function_exists($this->defaultEvtHandler)) {
			$handler=$this->defaultEvtHandler;
			$handler($evt,$par);
		}
	}

	public function connect() {
		msg($this->p.'Init AMI interface class ... ',1);
			$this->astman = new AGI_AsteriskManager(null,$this->conParams);
		msg($this->p.'Init AMI event handlers ... ',1);
			$this->astman->add_event_handler('state',		array($this,'evt_def'));
			$this->astman->add_event_handler('newexten',	array($this,'evt_def'));
			$this->astman->add_event_handler('newstate',	array($this,'evt_def'));
			$this->astman->add_event_handler('newcallerid',	array($this,'evt_def'));
			$this->astman->add_event_handler('newchannel',	array($this,'evt_def'));
			$this->astman->add_event_handler('hangup',		array($this,'evt_hangup'));
			$this->astman->add_event_handler('rename',		array($this,'evt_rename'));
		msg($this->p.'Connecting AMI inteface ... ');
			if (!$this->astman->connect()) return false;
		msg($this->p.'Switching AMI events ON ... ',1);
			$this->astman->Events('on');
		return true;
	}
	
	public function checkConnection() {
		if (!$this->astman->socket_error) return true;
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
}
