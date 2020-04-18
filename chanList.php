<?php
//библиотека с классом обработчиком состояний каналов

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'astConnector' . DIRECTORY_SEPARATOR . 'CAmiEvent.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'astConnector' . DIRECTORY_SEPARATOR . 'CAmiChannel.php');

//на контекст куда бросаются вызовы из call файлов называется org1_api_outcall
define ('API_CALLOUT_PREFIX','Вызов ');


class chanList {
	private $list=array();
	private $ami=NULL;
	private $connector=NULL;

	/**
	 * префикс для сообщений в лог
	 * @return string
	 */
	private function p() 
	{
		return 'chanList('.count($this->list).'): ';
	}

	/**
	 * chanList constructor.
	 * @param abstractDataConnector $dst
	 */
	function __construct($dst){
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
		$evt=new CAmiEvent($par);
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
		$evt=new CAmiEvent($par);
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
		$evt=new CAmiEvent($par);
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
