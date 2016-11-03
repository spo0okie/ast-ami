<?php
//библиотека с классом обработчиком состояний каналов

/*класс парсер канала*/
class channelItem {
	
	private $name=null;	//полное имя канала
	private $tech=null; //технология канала (протокол)
	private $cid=null;	//источник вызова (callerid)
	private $realTech=false; //признак реального канала (не виртуального внутри самого астериска)
	
	
	public function __concstruct ($name) {
		if (!strlen($name)) return NULL;		//пустая строка

		$this->name = $name;
		$slash=strpos($name,'/'); 	//разбираем канал в соответствии с синтаксисом
		$dash=strrpos($name,'-'); 
		$at=strpos($name,'@'); 		//ищем / - @ в строке
		
		if (!$slash||!($at||$dash)) {
			echo "$name is incorrect channel!";
			return NULL;		//несоотв синтаксиса
		}
		
		$numend=($at&&$dash)?min($at,$dash):max($at,$dash);	//конец номера
		$this->cid=substr($name,$slash+1,$numend-$slash-1); //ищем номер звонящего абонента в имени канала
		$this->tech=left($name,$slash-1);
		
	}
	public function getName()	{return $this->name;}
	public function getSrc()	{return $this->cid;}
	public function getTech()	{return $this->tech;}
	public function ckTech()
	{//проверят интересен ли нам канал по этой технологии
		return !($this->getTech()==='Local');	//отфильтруем только локальные каналы, вроде по документации только они создаются синтетически
	}
	
	
}


class eventItem {
	private $par; //массив параметр=>значение из которого и состоит евент

	public function __construct($par){
		$this->par=$par;
		//echo "New event:\n";
		//print_r ($this->par);
	}

	public function exists($item) { //возвращает если итем есть и не пустой
		return isset($this->par[$item])&&strlen($this->par[$item]);
	}

	public function numeric($item) { //возвращает если итем есть и числовой
		return $this->exists($item)&&is_numeric($this->par[$item]);
	}

	public function getPar($name){
		if ($this->exists($name)) return $this->par[$name];
		return NULL;
	}

	public function getSrc() {//ищем номер звонящего абонента в параметрах ивента
		return $this->getPar('CallerIDNum');
	}

	public function getDst() {//ищем номер вызываемого абонента в параметрах ивента
		if ($this->numeric('ConnectedLineNum'))	return $this->par['ConnectedLineNum'];
		if ($this->numeric('Exten'))			return $this->par['Exten'];
		return NULL;
	}

	public function getChan() {//возвращает имя канала из параметров ивента с учетом фильтра технологий соединения
		if ($this->exists('Channel')) {
			$chan = new channelItem($this->par['Channel']);
			return $chan;
			if ($chan->ckTech()) return $chan;
			else unset ($chan);
		} else {
			//echo "No channel in \n";
			//print_r($this->par);
		}
		return NULL;
	}

	public function getState()
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
		
		if 	(isset($this->par['ChannelStateDesc'])&&strlen($state=$this->par['ChannelStateDesc'])) //если статус в ивенте указан
			return isset($states[$state])?$states[$state]:NULL;	//возвращаем его если он есть в фильтре

		return NULL; //на нет и суда нет
	}
}


class chanList {
	private $list=array();
	
	function __constructor(){
		msg($p.'Initializing chanlist');
		$list=array();
	}

	private function getSrc($chan,$evt=NULL)
	{//вертает номер звонящего абонента из имени канала, или CallerID
		$fromname=$chan->getSrc();
		$frompar='';
		if (isset($evt)) $frompar=$evt->getSrc();
		if (strlen($fromname)&&is_numeric($fromname)) return $fromname;
		if (strlen($frompar) && is_numeric($frompar)) return $frompar;
		return NULL;
	}


	public function upd($par)
	{//обновляем информацию о канале новыми данными
		$evt=new eventItem($par);
		if (NULL!==($chan=$evt->getChan())) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
		{
			$cname=$chan->getName();
			//echo "Got chan: $cname";
			if (!isset($this->list[$cname])) $this->list[$cname]=array('src'=>NULL,'dst'=>NULL,'state'=>NULL);        //создаем канал если его еще нет в списке
			$src	=chanList::getSrc($chan,$evt);	//ищем вызывающего
			$dst	=$evt->getDst();				//ищем вызываемого
			$oldstate=$this->list[$cname]['state'];    //запоминаем старый статус

			//вариант однократного обновления данных о номерах в канале
			//ищем абонентов до тех пор пока не найдем, следующие изменения абонентов игнорируем
			if (!isset($this->list[$cname]['src'])&&isset($src)) $this->list[$cname]['src']=$src;
			if (!isset($this->list[$cname]['dst'])&&isset($dst)) $this->list[$cname]['dst']=$dst;

			//вариант многократного обновления
			//if (isset($src)) $this->list[$cname]['src']=$src;
			//if (isset($dst)) $this->list[$cname]['dst']=$dst;

			$this->list[$cname]['state']=$evt->getState();//устанавливаем статус

			//если у нас накопился законченный набор информации
			//и статус отличается от старого
			if (isset($this->list[$cname]['src'])&&isset($this->list[$cname]['dst'])&&isset($this->list[$cname]['state'])&&($oldstate!==$this->list[$cname]['state']))  {
				$this->dump($cname);   //сообщаем об этом радостном событии в консольку
				//chan_ws($chan);     //и на сервер вебсокетов
		 	}
		}
		unset ($evt);
//		$this->dumpAll();
	}
	
	public function ren($par)
	{
		$evt=new eventItem($par);		//создаем событие
		if (NULL!==($chan=$evt->getChan())) //в событии есть канал?
		{
			if($evt->exists('Newname')) {//в нем есть новый канал?
				$newchan=new channelItem($evt->getPar('Newname'));//создаем объект канала из нового канала
				if ($newchan->ckTech()) //если канал настоящий 
					$this->list[$evt->getPar('Newname')]=$this->list[$chan->getName()]; //то создаем канал с новым именем из старого
				unset ($newchan);
			}
			unset ($this->list[$chan->getName()]);
			unset ($chan);
		}
		unset ($evt);
//		$this->dumpAll();
	}

	public function del($par)
	{
		$evt=new eventItem($par);		//создаем событие
		if (NULL!==($chan=$evt->getChan())) //в событии есть канал?
		{
			unset ($this->list[$chan->getName()]);
			unset ($chan);
		}
		unset ($evt);
//		$this->dumpAll();
	}

	private function dumpAll()
	{//дампит в консоль список известных на текущий момент соединений с их статусами
		echo "chan list {\n";
		foreach ($this->list as $name=>$chan) $this->dump($name);
		echo "}\n";
	}

	private function dump($name)
	{//дампит в консоль один канал
		if (isset($this->list[$name])) {
			switch ($this->list[$name]['state']){
				case 'Ring':    $st=' --> '; break;
				case 'Ringing': $st=' <-- '; break;
				case 'Up':      $st='<-!->'; break;
				default:        $st=' ??? '; break;
			}
			echo $name.':   '.$this->list[$name]['src'].$st.$this->list[$name]['dst']." \n";
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