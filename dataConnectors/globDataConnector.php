<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 17:00
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/abstractDataConnector.php');

class globDataConnector extends abstractDataConnector  {
	private $p='globDataConnector: ';
	private $connectors=[];
	private $chan_connectors=[];

	public function __construct($conParams=null) {
		msg($this->p.'Initializing ... ',2);

		$this->connectors=array();
		foreach ($conParams as $dest) {
			if (isset($dest['conout'])) {
				require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/consoleDataConnector.php');
				$this->connectors[] = new consoleDataConnector($dest);
			}
			if (isset($dest['wsaddr'])) {
				require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/wsDataConnector.php');
				$this->connectors[] = new wsDataConnector($dest);
			}
			if (isset($dest['ocisrv'])) {
				require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/ociDataConnector.php');
				$this->connectors[] = new ociDataConnector($dest);
			}
			if (isset($dest['weburl'])) {
				require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/webDataConnector.php');
				$this->connectors[] = new webDataConnector($dest);
			}
			if (isset($dest['weburl_chan'])) {
				require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/webChanDataConnector.php');
				$this->chan_connectors[] = new webChanDataConnector($dest);
			}
		}

		msg($this->p.'Initialized '.count($this->connectors).' subconnectors.');
	}

	public function connect() {
		msg($this->p.'Connecting data receivers ... ',2);
		foreach ($this->connectors as $conn) if (!$conn->connect()) return false;
		foreach ($this->chan_connectors as $conn) if (!$conn->connect()) return false;
		msg($this->p.'Connecting data receivers - All OK',2);
		return true;
	}

	public function disconnect() {
		msg($this->p.'Disconnecting data receivers ... ',2);
		if (is_array($this->connectors))
		foreach ($this->connectors as $conn) $conn->disconnect();
		foreach ($this->chan_connectors as $conn) if (!$conn->disconnect()) return false;
		msg($this->p.'Disconnecting data receivers - OK',2);
	}

	public function checkConnection() {
		//прекращаем проверку, если найден разрыв хоть в одном источнике данных, и переинциализируем все на всякий случай
		foreach ($this->connectors as $conn) if (!$conn->checkConnection()) return false;
		foreach ($this->chan_connectors as $conn) if (!$conn->checkConnection()) return false;
		//иначе все хорошо
		return true;
	}

	/**
	 * отправляет данные всем подключенным коннекторам
	 * @param $data
	 */
	public function sendData($data) {
		//msg($this->p.'Sending data to '.count($this->connectors).' subconnectors ... ',2);
		foreach ($this->connectors as $conn) {
			//msg($this->p.'Push!',2);
			$conn->sendData($data);
		}
	}

	/**
	 * Отправляет данные всем подключенным коннекторам канальных событий
	 * @param $data
	 */
	public function sendChanData($data) {
		//msg($this->p.'Sending data to '.count($this->connectors).' subconnectors ... ',2);
		foreach ($this->chan_connectors as $conn) {
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
