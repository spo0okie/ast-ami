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
		private $ws;

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
			if (strlen($data['src'])<5) return true;
			if (strlen($data['dst'])>4) return true;
			$oci_command = "begin ics.services.calls_queue('".$data['src']."','".$data['dst']."','',to_date('". date('d.m.Y H:i:s')."','dd.mm.yyyy hh24:mi:ss'),'".$data['state']."'); end;";
			msg($this->p.'Sending data:' . $oci_command);
			$stid = oci_parse($this->oci, $oci_command);
			if (!oci_execute($stid)) msg($this->p.'Error pushing data to Oracle!');
			//var_dump($data);
		}

		public function getType() {return 'oci';}
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
			var_dump($data);
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
