<?php
//библиотека с классом обработчиком состояний каналов

/*класс парсер канала*/
class channelItem {
	
	private $name=null;	//полное имя канала
	private $tech=null; //технология канала (протокол)
	private $cid=null;	//источник вызова (callerid)
	private $realTech=false; //признак реального канала (не виртуального внутри самого астериска)
	
	
	function __concstruct ($name) {
		if (!strlen($name)) return NULL;		//пустая строка

		$this->name = $name;
		$slash=strpos($name,'/'); 	//разбираем канал в соответствии с синтаксисом
		$dash=strrpos($name,'-'); 
		$at=strpos($name,'@'); 		//ищем / - @ в строке
		
		if (!$slash||!($at||$dash)) return NULL;		//несоотв синтаксиса
		
		$numend=($at&&$dash)?min($at,$dash):max($at,$dash);	//конец номера
		$this->cid=substr($name,$slash+1,$numend-$slash-1); //ищем номер звонящего абонента в имени канала
		$this->tech=left($name,$slash-1);
		
	}

	function getSrc()	{return $this->cid;}
	function getTech()	{return $this->tech;}
	function ckTech()
	{//проверят интересен ли нам канал по этой технологии
		return !($this->tech==='Local');	//отфильтруем только локальные каналы, вроде по документации только они создаются синтетически
	}
	
	function getState($par)
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
	
}


class eventItem {
	private $par; //массив параметр=>значение из которого и состоит евент

	private function exists($item) { //возвращает если итем есть и не пустой
		return isset($this->par[$item])&&strlen($this->par[$item]);
	}

	private function numeric($item) { //возвращает если итем есть и не пустой
		return $this->exists($item)&&is_numeric($this->par[$item]);
	}

	public function __constructor($par){$this->par=$par;}


	public function getSrc() {//ищем номер звонящего абонента в параметрах ивента
		if ($this->exists('CallerIDNum')) return $this->par['CallerIDNum'];
		return NULL;
	}

	public function getDst() {//ищем номер вызываемого абонента в параметрах ивента
		if ($this->numeric('ConnectedLineNum'))	return $this->par['ConnectedLineNum'];
		if ($this->numeric('Exten'))			return $this->par['Exten'];
		return NULL;
	}

	public function getChan() {//возвращает имя канала из параметров ивента с учетом фильтра технологий соединения
		if ($this->exists('Channel')) {
			$chan = new channelItem($this->par['Channel']);
			if ($chan->ckTech()) return $chan;
			else unset ($chan);
		}
		return NULL;
	}
}


class chanList {
	private $list=array();
	
	function __chanList(){
		msg($p.'Initializing chanlist');
		$list=array();
	}

	function getSrc($chan,$evt=NULL)
	{//вертает номер звонящего абонента из имени канала, или CallerID
		$fromname=$chan->getSrc();
		$frompar='';
		if (isset($evt)) $frompar=$evt->getSrc();
		if (strlen($fromname)&&is_numeric($fromname)) return $fromname;
		if (strlen($frompar) && is_numeric($frompar)) return $frompar;
		return NULL;
	}



	public function upd($evt,$par)
	{//обновляем информацию о канале новыми данными
		if (strlen($chan=chan_name($par))) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
    {
        if (!isset($this->list[$chan])) $this->list[$chan]=array('src'=>NULL,'dst'=>NULL,'state'=>NULL);        //создаем канал если его еще нет в списке
        $src    =chan_src($chan,$par);  //ищем вызывающего
        $dst    =chan_dst($chan,$par);  //ищем вызываемого
        $oldstate=$this->list[$chan]['state'];    //запоминаем старый статус

        //вариант однократного обновления данных о номерах в канале
        //ищем абонентов до тех пор пока не найдем, следующие изменения абонентов игнорируем
        if (!isset($this->list[$chan]['src'])&&isset($src)) $this->list[$chan]['src']=$src;
        if (!isset($this->list[$chan]['dst'])&&isset($dst)) $this->list[$chan]['dst']=$dst;
    
        //вариант многократного обновления
        //if (isset($src)) $this->list[$chan]['src']=$src;
        //if (isset($dst)) $this->list[$chan]['dst']=$dst;

        $this->list[$chan]['state']=chan_state($par);//устанавливаем статус

        //если у нас накопился законченный набор информации
        //и статус отличается от старого
        if (isset($this->list[$chan]['src'])&&isset($this->list[$chan]['dst'])&&isset($this->list[$chan]['state'])&&($oldstate!==$this->list[$chan]['state']))  {
            chan_dump($chan);   //сообщаем об этом радостном событии в консольку
            chan_ws($chan);     //и на сервер вебсокетов
        }
    }
}
}

class astConnector {
	private $astman;
	private $conParams;
	private $p;

	function __construct($params) {
		$this->conParams=$params;
		$this->p='astConnector('.$params['server'].'): ';
	}

	function connect() {
		msg($this->p.'Init AMI interface class ... ',1);
			$this->astman = new AGI_AsteriskManager(null,$this->conParams);
		msg($this->p.'Init AMI event handlers ... ',1);
			$this->astman->add_event_handler('state',		'evt_def');
			$this->astman->add_event_handler('newstate',	'evt_def');
			$this->astman->add_event_handler('newcallerid',	'evt_def');
			$this->astman->add_event_handler('newchannel',	'evt_def');
			$this->astman->add_event_handler('hangup',		'evt_hangup');
			$this->astman->add_event_handler('rename',		'evt_rename');
		msg($this->p.'Connecting AMI inteface ... ');
			if (!$this->astman->connect()) return false;
		msg($this->p.'Switching AMI events ON ... ',1);
			$this->astman->Events('on');
		return true;
	}
	
	function checkConnection() {
		if (!$this->astman->socket_error) return true;
		msg ($this->p.'AMI socket error!');
		return false;
	}
	
	function waitResponse() {
		return $this->astman->wait_response();
	}
	
	function disconnect() {
		$this->astman->disconnect();
		unset ($this->astman);
	}
}
