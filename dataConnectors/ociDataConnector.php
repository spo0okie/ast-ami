<?php
/**
 * Created by PhpStorm.
 * User: spookie
 * Date: 09.03.2020
 * Time: 17:08
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '/abstractDataConnector.php');

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
