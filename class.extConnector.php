<?php
	/*абстрактный класс коннектора к внешним данным
	 * должен уметь подключаться к внешнему источнику и
	 * толкать в него данные
	 */
	abstract class abstractDataConnector {
		
		/*инициировать коннектор с передачей массива с учетными данными*/
		abstract public function __construct($conParams=null);
		
		/*подключиться к внешнему серверу с переданными учетными данными*/
		abstract public function connect();
		
		/*проверяет соединение, возвращает true если соединение потеряно*/
		abstract public function checkConnection();

		/*послать данные на внешний сервис*/
		abstract public function sendData($data);
		
		/*разовать соединение согласно протокола взаимодействия*/
		abstract public function disconnect();		

		/*возвращает тип коннектора*/
		abstract public function getType();		
	}
	
	class ociDataConnector extends abstractDataConnector  {
		private $p='ociDataConnector: '; //log prefix
		private $server;
		private $instance;
		private $user;
		private $password;
		private $oci;
		private $lastMsgTime=null;

		public function __construct($conParams=null) {
			if (
				!isset($conParams['ocisrv'])||
				!isset($conParams['ociinst'])||
				!isset($conParams['ociuser'])||
				!isset($conParams['ocipass'])
			) {
				msg($this->p.'Initialization error: Incorrect connection parameters given!');
				return NULL;					
			}
			$this->server=	$conParams['ocisrv'];
			$this->instance=$conParams['ociinst'];
			$this->user=	$conParams['ociuser'];
			$this->password=$conParams['ocipass'];
			$this->p='ociDataConnector('.$this->server.'/'.$this->instance.'): ';
			msg($this->p.'Initialized');
		}

		public function connect() {
			msg($this->p."Connecting ... ");
			$this->oci = oci_connect($this->user,$this->password,$this->server.'/'.$this->instance);
			return $this->checkConnection();
		}

		public function disconnect() {
			msg($this->p.'Disconnecting ... ');
			oci_close($this->oci);
			unset ($ws);
		}

		public function checkConnection() {
			if (!$this->oci) {
				msg($this->p.'Oracle instance not initialized!');
				return false;
			} else {
				$stid = oci_parse($this->oci, 'SELECT * FROM dual');
				oci_execute($stid);
				if (
					($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS))
					&& (isset($row['DUMMY']))
					&& ($row['DUMMY']==='X')
				) {
					//msg($this->p.'Oracle connection ok');
					return true;
				} else {
					msg($this->p.'Oracle connection lost!');
					return false;
				}
			}
		}
		
		public function sendData($data) {
			global $orgphone;
			$datastr=$data['src'].' '.$data['state'].' '.$data['dst'].' rec: '.$data['monitor'];
			if (strlen($data['src'])<5) {
				msg($this->p.'Channel update ignored (Too short CallerID):' . $datastr ,3);
				return true;
			}
			if (strlen($data['dst'])>4) {
				msg($this->p.'Channel update ignored (Too long Callee):' . $datastr ,3);
				return true;
			}
			if (isset($orgphone)&&strlen($orgphone)) {
				$oci_command = "begin ".
					"ics.services.calls_queue(".
					"'".$data['src']."',".
					"'$orgphone',".
					"'".$data['dst']."',".
					"to_date('". date('d.m.Y H:i:s')."','dd.mm.yyyy hh24:mi:ss'),".
					"'".$data['state']."',".
					"'".$data['monitor']."');".
					" end;";
			} else {
				$oci_command = "begin ics.services.calls_queue('".$data['src']."','".$data['dst']."','',to_date('". date('d.m.Y H:i:s')."','dd.mm.yyyy hh24:mi:ss'),'".$data['state']."','".$data['monitor']."'); end;";
			}
			msg($this->p.'Sending data:' . $oci_command);
			$stid = oci_parse($this->oci, $oci_command);
			if (!oci_execute($stid)) msg($this->p.'Error pushing data to Oracle!');
			//var_dump($data);
		}

		public function getType() {return 'oci';}
	}


class webDataConnector extends abstractDataConnector  {
	private $p='webDataConnector: '; //log prefix
	private $url;
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


class consoleDataConnector extends abstractDataConnector  {
		private $p='consoleDataConnector: '; //log prefix

		public function __construct($conParams=null) {
			msg($this->p.'Initialized');
		}

		public function connect() {
			msg($this->p.'Connecting ... ');
			return $this->checkConnection();
		}

		public function disconnect() {
			msg($this->p.'Disconnecting ... ');
		}

		public function checkConnection() {return true;}
		
		public function sendData($data) {
			msg($this->p.'Sending data:');
			var_dump($data);
		}

		public function getType() {return 'con';}
	}



	
	class wsDataConnector extends abstractDataConnector  {
		private $p='wsDataConnector: '; //log prefix
		private $wsaddr;
		private $wsport;
		private $wschan;
		private $ws;
		
		public function __construct($conParams=null) {
			if (
				!isset($conParams['wsaddr'])||
				!isset($conParams['wsport'])||
				!isset($conParams['wschan'])
				) {
				msg($this->p.'Initialization error: Incorrect connection parameters given!');
				return NULL;					
			}
			$this->wsaddr=$conParams['wsaddr'];
			$this->wsport=$conParams['wsport'];
			$this->wschan=$conParams['wschan'];
			
			$this->p='wsDataConnector('.$wsaddr.'): ';
			msg($this->p.'Initialized');
		}

		public function connect() {
			msg($this->p.'Connecting ... ');
			$this->ws = new WebsocketClient;
			if ($this->ws->connect($wsaddr, $wsport, '/', 'server')) return false;
			msg($this->p."Subscribing $wschan ... ");
			$this->ws->sendData('{"type":"subscribe","channel":"'.$wschan.'"}');
		}

		public function disconnect() {
			msg($this->p.'Disconnecting ... ');
			$ws->disconnect;
			unset ($ws);
		}

		public function checkConnection() {
			if ($this->ws->checkConnection()) {
				msg($this->p.'WS Socket error!');
				return true;
			} else return false;
		}

		public function sendData($data) {
		//отправляем сообщение в вебсокеты
			if (!$this->checkConnection()) {
				msg ('Lost WS! Reconnecting ... ');
				$this->reconnect();
			}
			$this->sendData('{"type":"event","caller":"'.$data['src'].'","callee":"'.$data['dst'].'","event":"'.$data['state'].'"}');
		}

		public function getType() {return 'ws';}
	}



	class globDataConnector extends abstractDataConnector  {
		private $p='globDataConnector: ';
		private $connectors;
		
		public function __construct($conParams=null) {
			msg($this->p.'Initializing ... ',2);
			
			$this->connectors=array();
			foreach ($conParams as $dest) {
				if (isset($dest['conout'])) 
					$this->connectors[] = new consoleDataConnector($dest);
				if (isset($dest['wsaddr'])) 
					$this->connectors[] = new wsDataConnector($dest);
				if (isset($dest['ocisrv']))
					$this->connectors[] = new ociDataConnector($dest);
				if (isset($dest['weburl']))
					$this->connectors[] = new webDataConnector($dest);
			}
			
			msg($this->p.'Initialized '.count($this->connectors).' subconnectors.');
		}
		
		public function connect() {
			msg($this->p.'Connecting data receivers ... ',2);
			foreach ($this->connectors as $conn) if (!$conn->connect()) return false;
			msg($this->p.'Connecting data receivers - All OK',2);
			return true;
		}

		public function disconnect() {
			msg($this->p.'Disconnecting data receivers ... ',2);
			foreach ($this->connectors as $conn) $conn->disconnect();
			msg($this->p.'Disconnecting data receivers - OK',2);
		}

		public function checkConnection() {
			//прекращаем проверку, если найден разрыв хоть в одном источнике данных, и переинциализируем все на всякий случай
			foreach ($this->connectors as $conn) if (!$conn->checkConnection()) return false; 
			//иначе все хорошо
			return true;
		}

		
		public function sendData($data) {
			//msg($this->p.'Sending data to '.count($this->connectors).' subconnectors ... ',2);
			foreach ($this->connectors as $conn) {
				//msg($this->p.'Push!',2);
				$conn->sendData($data);
			}
		}
		
		public function getType() {
			/*вместо своего типа вернет через запятую типы субконнекторов*/
			$types=array();
			foreach ($this->connectors as $conn) $types[]=$conn->getType();
			return implode(',',$types);
		}
	}
	
?>
