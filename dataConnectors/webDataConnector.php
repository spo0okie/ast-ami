<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 17:10
 */

class webDataConnector extends abstractDataConnector  {
	private $p='webDataConnector: '; //log prefix
	protected $url;
	//private $lastMsgTime=null;

	public function __construct($conParams=null) {
		if (
		!isset($conParams['weburl'])
		) {
			msg($this->p.'Initialization error: Incorrect connection parameters given!');
			return NULL;
		}
		$this->url=	$conParams['weburl'];
		$this->p='webDataConnector('.$this->url.'): ';
		msg($this->p.'Initialized');
	}

	public function connect() {
		msg($this->p."Connecting ... ");
		return $this->checkConnection();
	}

	public function disconnect() {
		msg($this->p.'Disconnecting ... ');
	}

	public function checkConnection() {
		return true;
		$req='http://'.$this->url.'/test';
		$response=file_get_contents($req);
		msg($this->p.'Checking '.$req.', Got: '.$response,5);
		if (!strlen($response)) {
			msg($this->p.'Web API lost connection (empty response): '.$response);
			return false;
		} //должен быть ответ
		if (strlen($response) < 3) {
			msg($this->p.'Web API lost connection (short response): '.$response);
			return false;
		} //ответ должен содержать ОК:
		return (strcmp(substr($response,0,3),'OK:')==0); //если ответ начинается на ОК, то и славно
	}

	public function sendData($data) {
		$datastr=$data['src'].' '.$data['state'].' '.$data['dst'].' rec: '.$data['monitor'];

		//сюда складываем параметры для отправки в АПИ
		$params=[
			'src_phone'=>$data['src'],      //заполняем исходящий номер
			'call_id'=>$data['monitor'],    //запоминаем имя файла как идентификатор вызова
		];

		//игнорируем незаписываемые вызовы
		if (!strlen($data['monitor'])) {
			msg($this->p.'Channel update ignored (Call not recorded):' . $datastr ,3);
			return true;
		}

		//разбираем имя файла на токены
		$mon_tokens=explode('-',$data['monitor']);

		//игнорируем ошбки в имени файла
		if (count($mon_tokens)<2) {
			msg($this->p.'Channel update ignored (Call record file incorrect):' . $datastr ,3);
			return true;
		}

		//заполняем городской номер
		$params['dst_phone']=$mon_tokens[count($mon_tokens)-1];

		//игнорируем исходящие вызовы
		if ($mon_tokens[count($mon_tokens)-2] !== 'IN') {
			msg($this->p.'Channel update ignored (Outgoing call):' . $datastr ,3);
			return true;
		};

		//игнорируем вызовы с внутреннего
		if (strlen($data['src'])<5) {
			msg($this->p.'Channel update ignored (Too short CallerID):' . $datastr ,3);
			return true;
		}

		//если вызываемый номер длинный - то звонок на городской
		if (strlen($data['dst'])>4) {
			if ($data['state']=='Ring')
				$params['event_name']='start.call'; //начало вызова
			if ($data['state']=='Up')
				$params['event_name']='answer.call'; //гипотетическое событие ответа на городской номер до ответа живого человека
			if ($data['state']=='Hangup')
				$params['event_name']='end.call';   //конец звонка
		} else {
			$params['real_local_number']=$data['dst'];
			if ($data['state']=='Ring')
				$params['event_name']='local.in.call';
			if ($data['state']=='Up')
				$params['event_name']='start.talk';
			if ($data['state']=='Hangup')
				$params['event_name']='end.call';   //конец звонка
		}

		$event=[
			'type'=>'call_event',

			'params'=>$params
		];

		$data=json_encode($event,JSON_FORCE_OBJECT);

		msg($this->p.'Sending data:' . $data);

		$options = [
			'http' => [
				'header'  => "Content-type: application/json\r\n",
				'method'  => 'POST',
				'content' => $data,
			]
		];

		$context  = stream_context_create($options);
		$result = file_get_contents('http://'.$this->url.'/push', false, $context);
		msg($this->p.'Data sent:' . $result);
	}

	public function getType() {return 'web';}
}
