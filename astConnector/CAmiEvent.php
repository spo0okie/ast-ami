<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 04.04.2020
 * Time: 23:44
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'CAmiChannel.php');

/*
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

/**
 * Класс события полученного от AMI
 * Class CAmiEvent
 */
class CAmiEvent {

	private static $debugEvts=['Newexten'];

	/*
	 * массив параметр=>значение из которого и состоит евент
	 */
	private $par=[];

	/*
	 * @param array $par массив элементов полученный от AMI
	 */
	public function __construct($par) {
		$this->par=$par;
		$this->par['logLevel']=INFO_EVENTS_LOG_LEVEL;
		if (isset($this->par['Event']))
			foreach (static::$debugEvts as $debugEvt)
				if (strcmp($debugEvt,$this->par['Event'])==0)
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

	/**
	 * ищем номер звонящего абонента в параметрах ивента
	 * @return null|string
	 */
	public function getSrc() {
		return $this->getPar('CallerIDNum');
	}

	/**
	 * ищем номер вызываемого абонента в параметрах ивента
	 * @return string|null
	 */
	public function getDst() {
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

	/**
	 * возвращает имя файла записи звонка
	 * @return null|string
	 */
	public function getMonitor() {
		if (strlen($accCode=$this->getPar('AccountCode'))) return $accCode;
		if ($this->getPar('Application')=='Monitor') {
			$parts=explode(',',$this->getPar('AppData'))[1];
			$tokens=explode('/',$parts);
			return $tokens[count($tokens)-1];
		}
		return NULL;
	}

	/**
	 * возвращает признак что звонок притворяется входящим, будучи на самом деле
	 * исходящим сделанным не с телефона а чере call файл. тогда сначала звонит аппарат
	 * вызывающего, и отображается CallerID вызываемого. Если это не обработать специально
	 * то такой вызов классифицируется как входящий. Поэтому все вызовы через call файлы
	 * помещаются в специальный контекст, который проверяется в этой функции
	 * - не вышло с контекстом, пробуем через caller ID
	 * @return bool
	 */
	public function isFakeIncoming()
	{	return ($this->getPar('CallerIDName')===(API_CALLOUT_PREFIX.$this->getPar('ConnectedLineNum')))
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
