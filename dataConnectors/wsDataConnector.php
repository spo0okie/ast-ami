<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 17:11
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/abstractDataConnector.php');

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

