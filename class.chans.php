<?php
//библиотека с классом обработчиком состояний каналов

//на контекст куда бросаются вызовы из call файлов называется org1_api_outcall
define ('API_CALLOUT_PREFIX','Вызов ');

function chanGetTech($name) {
	if (!strlen($name)) return NULL;	//пустая строка
		$slash=strpos($name,'/'); 		//разбираем канал в соответствии с синтаксисом
	if (!$slash) return NULL;			//несоотв синтаксиса
	return substr($name,0,$slash);
}

function chanCkTech($name) {
	$tech = chanGetTech($name);
//	echo $tech."\n";
	return NULL!==$tech&&$tech!=='Local';//возвращаем что технология у канала есть и она не Local (что означает, что канал виртуальный)
}

function chanGetSrc($name)	{
		if (!strlen($name)) return NULL;		//пустая строка
		$slash=strpos($name,'/'); 	//разбираем канал в соответствии с синтаксисом
		$dash=strrpos($name,'-'); 
		$at=strpos($name,'@'); 		//ищем / - @ в строке
		if (!$slash||!($at||$dash)) return NULL;	//несоотв синтаксиса
		$numend=($at&&$dash)?min($at,$dash):max($at,$dash);	//конец номера
		return substr($name,$slash+1,$numend-$slash-1); //ищем номер звонящего абонента в имени канала
}

class eventItem {
	public $par; //массив параметр=>значение из которого и состоит евент

	public function __construct($par) {
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

	public function getPar($name,$default=NULL){
		if ($this->exists($name)) return $this->par[$name];
		return $default;
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
		if (($this->exists('Channel')) && (chanCKTech($this->getPar('Channel'))))
			return $this->getPar('Channel');
		return NULL;
	}
/*Array
(
    [Event] => Newexten
    [Privilege] => dialplan,all
    [Channel] => SIP/telphin_yamal-000008b7
    [Context] => macro-RecordCall
    [Extension] => s
    [Priority] => 6
    [Application] => Monitor
    [AppData] => wav,/home/record/yamal/_current/20170210-221016-+79193393655-IN-+79193393655
    [Uniqueid] => 1486746616.2615
)*/

	public function getMonitor() {//возвращает имя файла записи звонка
		if ($this->getPar('Application')=='Monitor') {
			$parts=explode(',',$this->getPar('AppData'))[1];
			$tokens=explode('/',$parts);
			return $tokens[count($tokens)-1];
		}
		return NULL;
	}

	public function isFakeIncoming() 
	{//возвращает признак что звонок притворяется входящим, будучи на самом деле
	 //исходящим сделанным не с телефона а чере call файл. тогда сначала звонит аппарат
	 //вызывающего, и отображается CallerID вызываемого. Если это не обработать специально
	 //то такой вызов классифицируется как входящий. Поэтому все вызовы через call файлы
	 //помещаются в специальный контекст, который проверяется в этой функции 
	 // - не вышло с контекстом, пробуем через caller ID
		return ($this->getPar('CallerIDName')===(API_CALLOUT_PREFIX.$this->getPar('ConnectedLineNum')));
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
	private $ami=NULL;
	private $connector=NULL;
	
	
	private function p() 
	{// префикс для сообщений в лог
		return 'chanList('.count($this->list).'): ';
	}
	
	function __construct($dst){
		$list=array();
		
		if ($dst!==NULL) {
			//подключаем внешний коннектор куда кидать обновления
			$this->connector = $dst;
			msg($this->p().'External connector attached. ('.$this->connector->getType().')');
		} else echo "DST is $dst \n";
		msg($this->p().'Initialized.');
	}

	private function getSrc($chan,$evt=NULL)
	{//вертает номер звонящего абонента из имени канала, или CallerID
		$fromname=chanGetSrc($chan);
		$frompar='';
		if (isset($evt)) $frompar=$evt->getSrc();
		if (strlen($fromname)&&is_numeric($fromname)) return $fromname;
		if (strlen($frompar) && is_numeric($frompar)) return $frompar;
		return NULL;
	}

	public function attachAMI($src)
	{//подключает АМИ интерфейс для прямого запроса дополнительных данных при обработке событий поступающих от АМИ
		if ($src) {
			$this->ami=$src;
			msg($this->p().'AMI connector attached. ('.$this->ami->get_info().')');
		}
	}

	private function sendData($data)
	{//подключаем внешний коннектор куда кидать обновления
		if (isset($this->connector)) {
			//msg($this->p().'Sending data to connector.');
			$this->connector->sendData($data);
		}
		else
			msg($this->p().'External connector not attached! Cant send updates!');
	}

	private function ringDirCheckData($chan) 
	{/*суть: в зависимости от статуса Ring или Ringing меняется смысл кто кому звонит
	  * поэтому если вдруг у нас ringing, то мы меняем его на ring и меняем местами абонентов
	  * таким образом всегда понятно что src -> dst, что проще*/
		$data=$this->list[$chan];
		if (($data['state']==='Ringing') xor ($data['reversed']===true)) {
			$tmp=$data['dst'];
			$data['dst']=$data['src'];
			$data['src']=$tmp;
			$data['state']='Ring';
		}
		return $data;
	}

	public function setMonitorHook($evt) {//устанавливает имя файла записи звонка в переменную, которая расползется по всем дочерним каналам
		msg ($this->p().'Pushing monitor file to chan variable...',2);
		$this->ami->set_chan_var($evt->getChan(),'__Recordfile',$evt->getMonitor());
	}

	public function getMonitorHook($evt) {//возвращает имя файла записи звонка
		$this->ami->get_chan_var($evt->getChan(),'Recordfile');
		$parts=explode(',',$this->ami->get_chan_var($evt->getChan(),'Recordfile'));
		$tokens=explode('/',$parts[count($parts)-1]);
		return $tokens[count($tokens)-1];
	}

	public function upd($par)
	{//обновляем информацию о канале новыми данными
		$evt=new eventItem($par);
		if (NULL!==($cname=$evt->getChan())) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
		{
			//echo "Got chan: $cname";
			msg($this->p().'upd got event: '.dumpEvent($evt->par),HANDLED_EVENTS_LOG_LEVEL);
			if (!isset($this->list[$cname])) $this->list[$cname]=['src'=>NULL,'dst'=>NULL,'state'=>NULL,'monitor'=>NULL,'reversed'=>false];        //создаем канал если его еще нет в списке
			$src	=chanList::getSrc($cname,$evt);	//ищем вызывающего
			$dst	=$evt->getDst();				//ищем вызываемого
/*			if ($mon=$evt->getMonitor()) {
				$this->list[$cname]['monitor']=$mon;
				echo "Got record file: $mon\n";
				$this->setMonitorHook($evt);
			}*/
			$oldstate=$this->list[$cname]['state'];	//запоминаем старый статус

			//вариант однократного обновления данных о номерах в канале
			//ищем абонентов до тех пор пока не найдем, следующие изменения абонентов игнорируем
			/*информация о канале формируется единожды. т.е. как только мы узнали src и dst
			 * больше их не меняем. обновляем данные только о неполных с точки зрения информации
			 * каналах */
			//if (!isset($this->list[$cname]['src'])&&isset($src)) $this->list[$cname]['src']=$src;
			//if (!isset($this->list[$cname]['dst'])&&isset($dst)) $this->list[$cname]['dst']=$dst;

			//вариант многократного обновления
			/* обновляем информацию всегда, когда есть что обновить (больше обновлений) */
			if (isset($src)) $this->list[$cname]['src']=$src;
			if (isset($dst)) $this->list[$cname]['dst']=$dst;

			$this->list[$cname]['state']=$evt->getState();//устанавливаем статус
			if (!isset($this->list[$cname]['monitor'])) $this->list[$cname]['monitor']=$this->getMonitorHook($evt);
			
			//проверяем что это не исходящий звонок начинающийся со звонка на аппарат звонящего
			//с демонстрацией callerID абонента куда будет совершен вызов, если снять трубку
			//(костыль для обнаружения вызовов через call файлы)
			$this->list[$cname]['reversed']=$this->list[$cname]['reversed']||$evt->isFakeIncoming();
			
			//если у нас накопился законченный набор информации
			//и статус отличается от старого
			if (isset($this->list[$cname]['src'])&&isset($this->list[$cname]['dst'])&&isset($this->list[$cname]['state'])&&($oldstate!==$this->list[$cname]['state']))  {
				$this->dump($cname);   //сообщаем об этом радостном событии в консольку
				if (!strlen($this->list[$cname]['monitor'])) $this->list[$cname]['monitor']=$this->getMonitorHook($evt);
				$this->sendData($this->ringDirCheckData($cname));
		 	}
		} else {
			msg($this->p().'Event ignored: incorrect channel/tec:'.dumpEvent($par));
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
				$newchan=$evt->getPar('Newname');//создаем объект канала из нового канала
				if (chanCkTech($newchan)) //если канал настоящий 
					$this->list[$newchan]=$this->list[$chan]; //то создаем канал с новым именем из старого
			}
			unset ($this->list[$chan]);
		}
		unset ($evt);
//		$this->dumpAll();
	}

	public function del($par)
	{
		$evt=new eventItem($par);		//создаем событие
		if (NULL!==($chan=$evt->getChan())) //в событии есть канал?
		{
			unset ($this->list[$chan]);
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
				case 'Ring':    $st=' -(Ring)-> '; break;
				case 'Ringing': $st=' <-(Ringing)- '; break;
				case 'Up':      $st=' <-(Up)->' ; break;
				default:        $st=' (Unknown) '; break;
			}
			echo $name.':   '.$this->list[$name]['src'].$st.$this->list[$name]['dst']."	".($this->list[$name]['reversed']?'reversed':'straight').' Rec:'.$this->list[$name]['monitor']."\n";
		}
	}
}
