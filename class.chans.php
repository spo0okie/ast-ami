<?php
//библиотека с классом обработчиком состояний каналов

//на контекст куда бросаются вызовы из call файлов называется org1_api_outcall
define ('API_CALLOUT_PREFIX','Вызов ');

/**
 * Вытаскивает технологию канала из его имени
 * @param $name string имя канала
 * @return bool|null|string
 */
function chanGetTech($name) {
	if (!strlen($name)) return NULL;	//пустая строка
	$slash=strpos($name,'/'); 	//разбираем канал в соответствии с синтаксисом
	if (!$slash) return NULL;			//несоотв синтаксиса
	return substr($name,0,$slash);
}

/**
 * Проверяет наличие технологии у канала отличной от "Логический канал"
 * @param $name string имя канала
 * @return bool
 */
function chanCkTech($name) {
	$tech = chanGetTech($name);
	//echo $tech."\n"; //debug
	return NULL!==$tech&&$tech!=='Local';//возвращаем что технология у канала есть и она не Local (что означает, что канал виртуальный)
}

/**
 * Вытаскивает источник вызова (А-номер) из имени канала
 * @param $name
 * @return bool|null|string
 */
function chanGetSrc($name)	{
	if (!strlen($name)) return NULL;		    //пустая строка
	$slash	=strpos($name,'/'); 	        //разбираем канал в соответствии с синтаксисом
	$dash	=strrpos($name,'-');
	$at		=strpos($name,'@'); 			//ищем / - @ в строке
	if (!$slash||!($at||$dash)) return NULL;	//несоотв синтаксиса
	$numend=($at&&$dash)?min($at,$dash):max($at,$dash);	//конец номера
	return substr($name,$slash+1,$numend-$slash-1); //ищем номер звонящего абонента в имени канала
}

/*
 * Класс события полученного от AMI
    [Event] => Newexten
    [Privilege] => dialplan,all
    [Channel] => SIP/telphin_yamal-000008b7
    [Context] => macro-RecordCall
    [Extension] => s
    [Priority] => 6
    [Application] => Monitor
    [AppData] => wav,/home/record/yamal/_current/20170210-221016-+79193393655-IN-+79193393655
    [Uniqueid] => 1486746616.2615
 */
class CAmiEventItem {

	private static $debug_evts=['Newexten'];

	/*
	 * массив параметр=>значение из которого и состоит евент
	 */
	private $par; 

	/*
	 * @param array $par массив элементов полученный от AMI
	 */
	public function __construct($par) {
		$this->par=$par;
		$this->par['logLevel']=INFO_EVENTS_LOG_LEVEL;
		if (isset($this->par['Event']))
			foreach (static::$debug_evts as $debug_evt)
				if (strcmp($debug_evt,$this->par['Event'])==0)
					$this->par['logLevel']=HANDLED_EVENTS_LOG_LEVEL;
	}

	/*
	 * Возвращает текстовый дамп содержимого евента
	 */
	public function dump(){
		$data='';
		foreach ($this->par as $key => $value)	$data.="$key => $value\n";
		return $data;
	}

	/**
	 * Возвращает уровень логирования этого сообщения DEBUG|INFO
	 */
	public function logLevel() {
		if (isset($this->par['logLevel'])) return $this->par['logLevel'];
		return INFO_EVENTS_LOG_LEVEL;
	}

	/**
	 * Возвращает наличие элемента $item в эвенте
	 * @param string $item имя параметра
	 * @return bool признак наличия элемента в эвенте
	 */
	public function exists($item) {return isset($this->par[$item])&&strlen($this->par[$item]);}

	/**
	 * возвращает true, если итем есть и числовой
	 * @param string $item имя параметра
	 * @return bool признак наличия числового элемента в эвенте
	 */
	public function numeric($item) {return $this->exists($item)&&is_numeric($this->par[$item]);}

	/**
	 * получить значение элемента или "по умолчанию", если элемента нет
	 * @param string $name имя элемента
	 * @param string $default значение по умолчанию
	 * @return null|string
	 */
	public function getPar($name,$default=NULL){return $this->exists($name)?$this->par[$name]:$default;}

	
	public function getSrc() {//ищем номер звонящего абонента в параметрах ивента
		return $this->getPar('CallerIDNum');
	}

	public function getDst() {//ищем номер вызываемого абонента в параметрах ивента
		if ($this->numeric('ConnectedLineNum'))	return $this->par['ConnectedLineNum'];
		if ($this->numeric('Exten'))				return $this->par['Exten'];
		return NULL;
	}

	/**
	 * возвращает имя канала из параметров ивента
	 * @return null|string
	 */
	public function getChan() {
		return $this->getPar('Channel');
	}

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
		return ($this->getPar('CallerIDName')===(API_CALLOUT_PREFIX.$this->getPar('ConnectedLineNum')))
		||($this->getPar('CallerIDName')===(API_CALLOUT_PREFIX.$this->getPar('CallerIDNum')))
		||($this->getPar('ConnectedLineName')===(API_CALLOUT_PREFIX.$this->getPar('ConnectedLineNum')));
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
			'Hangup'=>'Hangup',			//Окончание разговора
		);
		
		if 	(isset($this->par['ChannelStateDesc'])&&strlen($state=$this->par['ChannelStateDesc'])) //если статус в ивенте указан
			return isset($states[$state])?$states[$state]:NULL;	//возвращаем его если он есть в фильтре

		return NULL; //на нет и суда нет
	}
}

/*
 * Класс канала в астериске
 */
class CAmiChannel {

	/*
	 * AMI object
	 */
	private $ami;

	/*
	 * Данные о канале
	 */
	private $uid;		//uid
	private $name;		//имя
	private $type;		//тип (технология)
	private $barename;	//имя без префикса технологии
	private $shortname; //короткое имя (для вывода в консоль)
	private $state;		//состояние
	private $src;		//источник (caller)
	private $dst;		//вызываемый (callee)
	private $reversed;	//в канале src и dst обратны относительно исходного звонка?
	private $wasRinging;//признак того, что канал был ранее в состоянии Ringing, что подразумевает не прямое, а обратное направление вызовае
	private $monitor;	//имя файла в который пишется запись
	private $org;		//имя файла в который пишется запись

	private $variables;	//канальные переменные
	private $bridges;	//соединения с другими каналами
	
	/**
	 * возвращает сорс из имени канала
	 * @param string $name имя канала
	 * @return string источник звонка
	 */
	static public function parseSrc($name)	{
		if (!strlen($name)) return NULL;			//пустая строка
		$slash=strpos($name,'/');					//разбираем канал в соответствии с синтаксисом
		$dash=strrpos($name,'-');
		$at=strpos($name,'@'); 						//ищем / - @ в строке
		if (!$slash||!($at||$dash)) return NULL;	//несоотв синтаксиса
		$numend=($at&&$dash)?min($at,$dash):max($at,$dash);	//конец номера
		return substr($name,$slash+1,$numend-$slash-1); //ищем номер звонящего абонента в имени канала
	}
	
	/**
	 * возвращает технологию канала
	 * @param string $name имя канала
	 * @return string технолгия канала 
	 */
	static public function parseTech($name) {
		if (!strlen($name)) return NULL;	//пустая строка
		$slash=strpos($name,'/'); 			//разбираем канал в соответствии с синтаксисом
		if (!$slash) return NULL;			//несоотв синтаксиса
		return substr($name,0,$slash);
	}
	
	/**
	 * возвращает имя канала без технологии
	 * @param string $name имя канала
	 * @return string технолгия канала
	 */
	static public function parseBareName($name) {
		if (!strlen($name)) return NULL;	//пустая строка
		$slash=strpos($name,'/'); 			//разбираем канал в соответствии с синтаксисом
		if (!$slash) return NULL;			//несоотв синтаксиса
		return substr($name,$slash+1);
	}
	
	/**
	 * заполняет все переменные, которые заполняются единожды из имени канала
	 */
	private function parseNames(){
		$this->type=CAmiChannel::parseTech($this->name);
		$this->barename=CAmiChannel::parseBareName($this->name);
		if (strlen($this->barename)>9) { //при длинном имени канала вырежем середину
			$short=substr($this->barename,0,5).'~'.substr($this->barename,-3);
		} else $short=$this->barename;
		$this->shortname=substr($this->type,0,1).'/'.$short;
	}
	
	/**
	 * Возвращает значение переменной если такая в канале есть (иначе null)
	 * @param string $name имя переменной
	 * @param string $default значение, которое вернуть в случае отсутствия переменной
	 * @return string значение или $default
	 */
	public function getVar($name,$default=null){
		return 
			(isset($this->variables[$name])&&!is_null($this->variables[$name]))?
			$this->variables[$name]:$default;
	}
	
	/**
	 * Выставляет значение канальной переменной если есть что выставлять
	 * @param string $name имя переменной
	 * @param string $value значение переменной
	 */
	public function setVar($name,$value){
		if(strlen($name)&&strlen($value))$this->variables[$name]=$value;
	}

	/**
	 * Пытается отдать значение переменной, если ее в хранилище нет,
	 * то загружает из AMI значение переменной,
	 * в случае удачи кэширует в хранилище и отдает
	 * @param string $name имя переменной
	 * @param null $default
	 * @return string
	 */
	public function fetchVar($name,$default=null){
		if (is_null($this->getVar($name))){
			msg($this->p().'fetching Var '.$name,3);
			$this->setVar($name, $this->ami->get_chan_var($this->name,$name));
		}
		return $this->getVar($name,$default);
	}
	
	/**
	 * Создает объект канала на основании события о нем
	 * @param object $event
	 */
	public function __construct(&$evt,&$ami){
		//empty part
		$this->ami=$ami;
		$this->state=null;
		$this->src=null;
		$this->dst=null;
		$this->monitor=null;
		$this->wasRinging=false;
		$this->variables=[];
		
		$this->uid=$evt->getPar('Uniqueid');
		$this->name=$evt->getChan();
		$this->parseNames();
	}

	/**
	 * информация о канале одной строкой
	 */
	private function info()
	{
		switch ($this->state){
				case 'Ring':    $st='>'; break;
				case 'Ringing': $st='<'; break;
				case 'Up':      $st='^' ; break;
				case 'Hangup':  $st='x' ; break;
				default:        $st='?'; break;
		}
		$rev=$this->needReverse()?'Y':'N';
		$mon=strlen($this->monitor)?'Y':'N';
		$var=count($this->variables);
		return  (is_null($this->src)?'[]':$this->src).
				$st.
				(is_null($this->dst)?'[]':$this->dst).
				','.
				'rv:'.$rev.','.
				'mn:'.$mon.','.
				'vr:'.$var;
	}
	
	/*
	 * префикс для лога
	 */
	private function p(){
		return 'Ch('.$this->shortname.','.$this->info().'): ';
	}
	
	/**
	 * текстовый дамп данных из файла
	 */
	public function dump(){
		return 'Channel ['.$this->name.']:	'.$this->info();
	}
	
	
	/**
	 * вертает номер звонящего абонента из имени канала, или CallerID
	 */
	private function selectSrc(&$evt)
	{
		//$fromname=CAmiChannel::parseSrc($this->name);	//src из имени канала
		$frompar=$evt->getSrc();
		//if (strlen($fromname)&&is_numeric($fromname)) return $fromname;
		if (strlen($frompar) && is_numeric($frompar)) return $frompar;
		return NULL;
	}



	public function getMonitorVar() {//возвращает имя файла записи звонка
		//if (is_null($recordfile=$this->fetchVar('CallUUID'))) return null;
		if (is_null($uuid=$this->fetchVar('CallUUID'))) return null;
		//$parts=explode(',',$recordfile);
		//$tokens=explode('/',$parts[count($parts)-1]);
		return $uuid;
	}

	public function getOrgVar() {//возвращает имя файла записи звонка
		//if (is_null($recordfile=$this->fetchVar('CallUUID'))) return null;
		if (is_null($org=$this->fetchVar('CallOrg'))) return null;
		//$parts=explode(',',$recordfile);
		//$tokens=explode('/',$parts[count($parts)-1]);
		return $org;
	}

	public function getMonitor() {//возвращает имя файла записи звонка
		if (is_null($this->monitor)) if (is_null($this->monitor)) $this->monitor=$this->getMonitorVar();
		return $this->monitor;
	}

	public function getOrg() {//возвращает имя файла записи звонка
		if (is_null($this->org)) if (is_null($this->org)) $this->org=$this->getOrgVar();
		return $this->org;
	}

	/**
	 * @param CAmiEventItem $evt
	 * @return bool
	 */
	public function upd(&$evt)
	{//обновляем информацию о канале новыми данными
		//echo "Got chan: $cname";
		msg($this->p().'parsing event: '.$evt->dump(),$evt->logLevel());

		$oldstate=$this->state;			//запоминаем старый статус
			
		/* обновляем информацию всегда, когда есть что обновить (больше обновлений) */
		if (!is_null($src=$this->selectSrc($evt)))	$this->src=$src; //ищем вызывающего
		if (!is_null($dst=$evt->getDst())) 				$this->dst=$dst; //ищем вызываемого
		if (!is_null($newstate=$evt->getState()))		$this->state=$newstate;//устанавливаем статус
		
		//пугает меня этот вызов не зафлудить бы АМИ этимим запросами
		if (is_null($this->monitor)) $this->monitor=$this->getMonitorVar();
		if (!is_null($this->monitor) && is_null($this->org)) $this->org=$this->getOrgVar();

			//проверяем что это не исходящий звонок начинающийся со звонка на аппарат звонящего
		//с демонстрацией callerID абонента куда будет совершен вызов, если снять трубку
		//(костыль для обнаружения вызовов через call файлы)
		$this->reversed=$this->reversed||$evt->isFakeIncoming();
			
		//возвращаем флаг необходимости отправки данных (канал укомплектован и инфо обновилась)
		return !is_null($this->src)&&
			!is_null($this->dst)&&
			!is_null($this->state)&&
			($oldstate!==$this->state);  
	}

	/*
	 * суть: в зависимости от статуса Ring или Ringing меняется смысл кто кому звонит
	 * поэтому если вдруг у нас ringing, то мы меняем его на ring и меняем местами абонентов
	 * таким образом всегда понятно что src -> dst, что проще
	 */
	private function needReverse()
	{
		if ($this->state==='Ringing') $this->wasRinging=true;
		return ($this->wasRinging===true) xor ($this->reversed===true);
	}
	
	public function getState(){
		if ($this->state==='Ringing') return 'Ring';
		return $this->state;
	}
	
	public function getSrc(){
		return $this->needReverse()?$this->dst:$this->src;
	}

	public function getDst(){
		return $this->needReverse()?$this->src:$this->dst;
	}
	
	public function getData(){
		return [
			'src'	=>$this->getSrc(),
			'dst'	=>$this->getDst(),
			'state'	=>$this->getState(),
			'monitor'=>$this->monitor,
		];
	}

	public function getType(){
		return $this->type;
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
		
		if (!is_null($dst)) {
			//подключаем внешний коннектор куда кидать обновления
			$this->connector = $dst;
			msg($this->p().'External connector attached. ('.$this->connector->getType().')');
		} else echo "DST is $dst \n";
		msg($this->p().'Initialized.');
	}

	public function attachAMI($src)
	{//подключает АМИ интерфейс для прямого запроса дополнительных данных при обработке событий поступающих от АМИ
		if ($src) {
			$this->ami=$src;
			msg($this->p().'AMI connector attached. ('.$this->ami->get_info().')');
		}
	}

	/**
	 * Отправляет данные об ивентах вызова внешним коннекторам
	 * @param $data
	 */
	private function sendData($data)
	{//подключаем внешний коннектор куда кидать обновления
		if (isset($this->connector)) {
			//msg($this->p().'Sending data to connector.');
			$this->connector->sendData($data);
		}
		else
			msg($this->p().'External connector not attached! Cant send call updates!');
	}

	/**
	 * Отправляет данные об ивентах каналов внешним коннекторам
	 * @param array $data
	 * @param CAmiChannel $chan
	 */
	private function sendChanData($data,$chan)
	{//подключаем внешний коннектор куда кидать обновления
		if (isset($this->connector)) {
			//msg($this->p().'Sending data to connector.');
			if (!empty($chan)) {
				if (!isset($data['variables']))$data['variables']=[];
				$data['variables']['monitor']=$chan->getMonitor();
				$data['variables']['org']=$chan->getOrg();
			}

			$this->connector->sendChanData($data);
		}
		else
			msg($this->p().'External connector not attached! Cant send chan updates!');
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

	public function upd($par)
	{//обновляем информацию о канале новыми данными
		$evt=new CAmiEventItem($par);
		if (strlen($cname=$evt->getChan())) //имя канала вернется если в пераметрах события оно есть (ну для канальных событий есть всегда)
		{
			//создаем канал если его еще нет в списке
			if (!isset($this->list[$cname])) $this->list[$cname]=new CAmiChannel($evt, $this->ami);

			//если обновление укомплетовало канал новыми данными
			if ($this->list[$cname]->upd($evt))  {
				if ($this->list[$cname]->getType()!=='Local') $this->sendData($this->list[$cname]->getData());
		 	}

			$this->sendChanData($par,$this->list[$cname]);
		} else {
			msg($this->p().'Event ignored: channel not found ['.$cname.']:'.$evt->dump());
		}
		unset ($evt);
		$this->dumpAll();
	}
	
	public function ren($par)
	{
		$evt=new CAmiEventItem($par);
		if (strlen($cname=$evt->getChan())) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
		{
			$this->sendChanData($par,isset($this->list[$cname])?$this->list[$cname]:null);

			if($evt->exists('Newname')) {//в нем есть новый канал?
				$newchan=$evt->getPar('Newname');//создаем объект канала из нового канала
				if (isset($this->list[$cname]))
					$this->list[$newchan]=$this->list[$cname]; //то создаем канал с новым именем из старого
			}
			unset ($this->list[$cname]);
		}
		unset ($evt);
		$this->dumpAll();
	}

	public function del($par)
	{
		$evt=new CAmiEventItem($par);
		if (strlen($cname=$evt->getChan())) //имя канала вернется если в пераметрах события оно есть и если канал при этом не виртуальный
		{
			unset ($this->list[$cname]);
		}
		unset ($evt);
		$this->dumpAll();
	}

	private function dumpAll()
	{//дампит в консоль список известных на текущий момент соединений с их статусами
		$list=[];
		foreach ($this->list as $name=>$chan) $list[]=$this->list[$name]->dump()."\n";
		msg($this->p()."Current chans: \n".implode('',$list),HANDLED_EVENTS_LOG_LEVEL);
	}


	private function dump($name)
	{//дампит в консоль один канал
		if (isset($this->list[$name])) 
			msg($this->chanInfo($name),HANDLED_EVENTS_LOG_LEVEL);
	}
}
