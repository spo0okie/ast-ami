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
				if (isset($dest['wsaddr'])) 
					$this->connectors[] = new wsDataConnector($dest);	
			}
			
			msg($this->p.'Initialized '.count($this->connectors).' subconnectors.');
		}
		
		public function connect() {
			msg($this->p.'Connecting data receivers ... ',2);
			foreach ($this->connectors as $conn) if (!$conn->connect) return false;
			return true;
		}

		public function disconnect() {
			msg($this->p.'Disconnecting data receivers ... ',2);
			foreach ($this->connectors as $conn) $conn->disconnect;
		}

		public function checkConnection() {
			//прекращаем проверку, если найден разрыв хоть в одном источнике данных, и переинциализируем все на всякий случай
			foreach ($this->connectors as $conn) if ($conn->checkConnection()) return false; 
			//иначе все хорошо
			return true;
		}

		
		public function sendData($data) {
			foreach ($this->connectors as $conn) $conn->sendData($data);
		}
		
		public function getType() {
			/*вместо своего типа вернет через запятую типы субконнекторов*/
			$types=array();
			foreach ($this->connectors as $conn) $types[]=$conn->getType();
			return implode(',',$types);
		}
	}
	
?>
