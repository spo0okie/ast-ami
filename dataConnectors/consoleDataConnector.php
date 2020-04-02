<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 17:06
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/abstractDataConnector.php');

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

