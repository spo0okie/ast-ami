<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 04.04.2020
 * Time: 23:54
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'CAmiEvent.php');

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
	//private $bridges;	//соединения с другими каналами

	/**
	 * возвращает сорс из имени канала
	 * @param string $name имя канала
	 * @return string источник звонка
	 */
	static public function parseSrc($name)	{
		if (!strlen($name)) return NULL;							//пустая строка
		$slash=strpos($name,'/');							//разбираем канал в соответствии с синтаксисом
		$dash=strrpos($name,'-');
		$at=strpos($name,'@'); 								//ищем / - @ в строке
		if (!$slash||!($at||$dash)) return NULL;					//несоотв синтаксиса
		$numEnd=($at&&$dash)?min($at,$dash):max($at,$dash);			//конец номера
		return substr($name,$slash+1,$numEnd-$slash-1); //ищем номер звонящего абонента в имени канала
	}

	/**
	 * возвращает технологию канала
	 * @param string $name имя канала
	 * @return string технолгия канала
	 */
	static public function parseTech($name) {
		if (!strlen($name)) return NULL;	//пустая строка
		$slash=strpos($name,'/'); 		//разбираем канал в соответствии с синтаксисом
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
	 * @param CAmiChannel $evt
	 * @param astConnector $ami
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
	 * @return string
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

	/**
	 * префикс для лога
	 *
	 * @return string
	 */
	private function p(){
		return 'Ch('.$this->shortname.','.$this->info().'): ';
	}

	/**
	 * текстовый дамп данных из файла
	 * @return string
	 */
	public function dump(){
		return 'Channel ['.$this->name.']:	'.$this->info();
	}


	/**
	 * вертает номер звонящего абонента из имени канала, или CallerID
	 * @param $evt
	 * @return string|NULL
	 */
	private function selectSrc(&$evt)
	{
		//$fromName=CAmiChannel::parseSrc($this->name);	//src из имени канала
		$fromPar=$evt->getSrc();
		//if (strlen($fromName)&&is_numeric($fromName)) return $fromName;
		if (strlen($fromPar) && is_numeric($fromPar)) return $fromPar;
		return NULL;
	}


	/**
	 * @param null|CAmiEvent $evt
	 * @return null|string
	 */
	public function getMonitorVar(&$evt = null) {//возвращает имя файла записи звонка
		//если передали событие, попробуем вытащить данные из него
		if (!is_null($evt)) {
			if (!is_null($uuid=$evt->getMonitor())) return $uuid;
		}
		//если не удалось - запрашиваем
		if (is_null($uuid=$this->fetchVar('CallUUID'))) return null;
		//$parts=explode(',',$recordFile);
		//$tokens=explode('/',$parts[count($parts)-1]);
		return $uuid;
	}

	/**
	 * @param null|CAmiEvent $evt
	 * @return null|string
	 */
	public function getOrgVar(&$evt = null) {//возвращает организацию вызова
		//если передали событие, попробуем вытащить данные из него
		if (!is_null($evt)) {
			if (!is_null($uuid=$evt->getOrg())) return $uuid;
		}
		if (is_null($org=$this->fetchVar('CallOrg'))) return null;
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
	 * @param CAmiEvent $evt
	 * @return bool
	 */
	public function upd(&$evt)
	{//обновляем информацию о канале новыми данными
		//echo "Got chan: $cname";
		msg($this->p().'parsing event: '.$evt->dump(),$evt->logLevel());

		$oldState=$this->state;			//запоминаем старый статус

		/* обновляем информацию всегда, когда есть что обновить (больше обновлений) */
		if (!is_null($src=$this->selectSrc($evt)))	$this->src=$src; //ищем вызывающего
		if (!is_null($dst=$evt->getDst())) 				$this->dst=$dst; //ищем вызываемого
		if (!is_null($newState=$evt->getState()))		$this->state=$newState;//устанавливаем статус

		//пугает меня этот вызов не зафлудить бы АМИ этимим запросами
		if (is_null($this->monitor)) $this->monitor=$this->getMonitorVar($evt);
		if (!is_null($this->monitor) && is_null($this->org)) $this->org=$this->getOrgVar($evt);

		//проверяем что это не исходящий звонок начинающийся со звонка на аппарат звонящего
		//с демонстрацией callerID абонента куда будет совершен вызов, если снять трубку
		//(костыль для обнаружения вызовов через call файлы)
		$this->reversed=$this->reversed||$evt->isFakeIncoming();

		//возвращаем флаг необходимости отправки данных (канал укомплектован и инфо обновилась)
		return !is_null($this->src)&&
			!is_null($this->dst)&&
			!is_null($this->state)&&
			($oldState!==$this->state);
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

