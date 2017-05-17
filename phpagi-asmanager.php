<?php
 /**
  * phpagi-asmanager.php : PHP Asterisk Manager functions
  * Website: http://phpagi.sourceforge.net
  *
  * $Id: phpagi-asmanager.php,v 1.10 2005/05/25 18:43:48 pinhole Exp $
  *
  * Copyright (c) 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
  * All Rights Reserved.
  *
  * This software is released under the terms of the GNU Lesser General Public License v2.1
  *  A copy of which is available from http://www.gnu.org/copyleft/lesser.html
  * 
  * We would be happy to list your phpagi based application on the phpagi
  * website.  Drop me an Email if you'd like us to list your program.
  *
  * @package phpAGI
  * @version 2.0
  */


 /**
  * Written for PHP 4.3.4, should work with older PHP 4.x versions.  
  * Please submit bug reports, patches, etc to http://sourceforge.net/projects/phpagi/
  * Gracias. :)
  *
  */

  /**
  * Текущий режим работы такой: все данные из протокола AMI скачиваются и складываются в
  * 2 кучки: евентов и ответов на запросы.
  * сделано это для асинхронного вхаимодействия, поскольку иначе может случиться так,
  * что обработчик какого-то события сделает запрос в АМИ, и вместо ответа получит
  * новые евент, на который запустится обработчик, и т.д. А так если он вместо ответа 
  * получит новые евент, он его просто сложит в кучку и дождется своего ответа.  
  */


	define('REQUESTS_LOG_LEVEL',4);		//уровень логирования для отображения запросов
	define('RESPONCES_LOG_LEVEL',4);		//уровень логирования для отображения ответов
	define('HANDLED_EVENTS_LOG_LEVEL',5);	//уровень логирования для отображения обрабатываемых событий
	define('IGNORED_EVENTS_LOG_LEVEL',6);	//уровень логирования для отображения обрабатываемых но отброшенных событий
	define('EVENTS_LOG_LEVEL',7);			//уровень логирования всех событий
	
	function getParName($buffer){
		$a = strpos($buffer, ':');
		return $a?substr($buffer, 0, $a):false;
	}
	
	function dumpEvent($params){
		$data='';
		foreach ($params as $key => $value)
			$data.=$key.' => '.$value."\n";
		return $data;
	}

  	if(!class_exists('AGI'))
	{
	    require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phpagi.php');
	}
  /**
  * Asterisk Manager class
  *
  * @link http://www.voip-info.org/wiki-Asterisk+config+manager.conf
  * @link http://www.voip-info.org/wiki-Asterisk+manager+API
  * @example examples/sip_show_peer.php Get information about a sip peer
  * @package phpAGI
  */
  class AGI_AsteriskManager
  {
   /**
    * Config variables
    *
    * @var array
    * @access public
    */
    public $config;

   /**
    * Socket
    *
    * @access public
    */
    public $socket = NULL;

    /**
     * флаг сбоя сокета
     * 
     * @access public
     */
    public $socket_error = false;
    
    /**
     * буфер сокета. читаем данные в буфер и потом его уже разбираем
     * @var string
     */
    private $socket_buffer = '';
    
    /**
     * последняя прочитанная из буффера строка (нужно для коррекции вывода ami) 
     * несколько евентов астериск почемуто может послать не разделяя пустой линией,
     * мы постараемся ее вставлять
     * @var string  
     */
    private $socket_buffer_last = '';
    
    /**
     * куча ответов. Поскольку канал общения один, и ответ на запрос приходит не сразу, то ожидая
     * свой ответ, можно получить чужой, тогда его надо положить в кучу 
     * при ожидании ответа надо не только ждать его прям с текущего потока событий, а и из кучи
     * @var array
     */
    private $responses_heap = array();
    
    /**
     * очередь ивентов. поскольку обработчик какого либо ивента может создать остановку текущего
     * ожидания ответа. то в случае ожидания ответа ивенты не будем обрабатывать, а сложим в очередь.
     * обрабатывать ивенты будут только в случае ожидания чего угодно.
     * @var array
     */
    private $events_queue = array();
    
    /**
     * Actions requested
     * @var integer
     */
    private $actions_count = 0;

   /**
    * Server we are connected to
    *
    * @access public
    * @var string
    */
    public $server;

   /**
    * Port on the server we are connected to
    *
    * @access public
    * @var integer
    */
    public $port;

   /**
    * Parent AGI
    *
    * @access private
    * @var AGI
    */
    private $pagi;

   /**
    * Event Handlers
    *
    * @access private
    * @var array
    */
    private $event_handlers;

    /**
     * Whether we're successfully logged in
     *
     * @access private
     * @var boolean
     */
    private $_logged_in = FALSE;


	private $socket_last_read=0;
    
   /**
    * Constructor
    *
    * @param string $config is the name of the config file to parse or a parent agi from which to read the config
    * @param array $optconfig is an array of configuration vars and vals, stuffed into $this->config['asmanager']
    */
    function AGI_AsteriskManager($config=NULL, $optconfig=array())
    {
      // load config
      if(!is_null($config) && file_exists($config))
        $this->config = parse_ini_file($config, true);
      elseif(file_exists(DEFAULT_PHPAGI_CONFIG))
        $this->config = parse_ini_file(DEFAULT_PHPAGI_CONFIG, true);

      // If optconfig is specified, stuff vals and vars into 'asmanager' config array.
      foreach($optconfig as $var=>$val)
        $this->config['asmanager'][$var] = $val;

      // add default values to config for uninitialized values
      if(!isset($this->config['asmanager']['server'])) $this->config['asmanager']['server'] = 'localhost';
      if(!isset($this->config['asmanager']['port'])) $this->config['asmanager']['port'] = 5038;
      if(!isset($this->config['asmanager']['username'])) $this->config['asmanager']['username'] = 'phpagi';
      if(!isset($this->config['asmanager']['secret'])) $this->config['asmanager']['secret'] = 'phpagi';
    }
    
    /*
     * Returns cuurent count of events and responses in heaps
     * @return strin current status
     */
    function getStatus(){
    	return 'evt:'.count($this->events_queue).',rsp:'.count($this->responses_heap);
    }

    /**
     * Generates an unique in current instance ActionID same as UniquieID paramete ins asterisk
     * @return string unique action id 
     */
    function generateAID()
    {
    	$cnt=$this->actions_count++;
    	$tm=time();
    	return "$tm.$cnt";
    }
    
   /**
    * Send a request
    *
    * @param string $action
    * @param array $parameters
    * @return array of parameters
    */
	function send_request($action, $parameters=array(), $response=true)
    {
		//$this->wait_response();
        if (!isset($parameters['ActionID'])) {
        	$parameters['ActionID']=$this->generateAID();
        }    	
        $req = "Action: $action\r\n";
		foreach($parameters as $var=>$val)
        $req .= "$var: $val\r\n";
		$req .= "\r\n";
		$this->log("Request:\n".dumpEvent($parameters),REQUESTS_LOG_LEVEL);
		socket_write($this->socket, $req);
		if (!$response) return NULL;
		$answ=$this->wait_response($parameters['ActionID']);
		//echo "GOT ANSWER:".print_r($answ);
		return $answ;
    }


    
	/**
	 * Читает из сокета в буффер и из буффера отдает по одной строке
	 * Аналог gets  
	*/
	function read_socket()
	{
        //$buffer = fgets($this->socket, 4096);
        $r=array($this->socket);
        $w=NULL;
        $e=NULL;
        //проверяем, что в сокете есть что почитать
        $avail=socket_select($r,$w,$e,0);
        if ($avail===false) {//ошибка
				$this->socket_error=true;
				//echo "SOCKET ERR!\n";
		} elseif ($avail>0) {//чтото есть
			//читаем
			$read=socket_recv($this->socket,$buffer,65536,0); //got Use of undefined constant MSG_DONTWAIT in some cases
			if ($read===false) {//ошибка
	/*			if (socket_last_error($this->socket)===0)
					socket_clear_error($this->socket);
				else*/
					$this->socket_error=true; //turning on PANIC!!!!! mode %-)
			} elseif (strlen($buffer)) {
				//echo "RCVD:--< $buffer >--\n";
				$this->socket_buffer.=$buffer; //добавляем прочитанное во внутреннее хранилище
			}
		}
		//echo $buffer;
		$line=false;
		if (strlen($this->socket_buffer)){
			$crlf = strpos($this->socket_buffer, "\r\n");
			if($crlf!==FALSE) {
				$line=substr($this->socket_buffer,0,$crlf);
				if ((getParName($line)=='Event')&&($this->socket_buffer_last!==''))
					$line='';
				else
					$this->socket_buffer=substr($this->socket_buffer,$crlf+2);
			} else {
				$line=$this->socket_buffer;
				if ((getParName($line)=='Event')&&($this->socket_buffer_last!==''))
					$line='';
				else
					$this->socket_buffer='';
			}
		}
		$this->socket_buffer_last=$line;
		//echo "Giving line:$line\n";
		return $line;
	}

	function read_socket_force($timeout=300)
	{//принудительно ждет данных в сокете
		$wtime=0;
        do{
			$buffer=$this->read_socket();
			if ($buffer===false) {
				usleep(20000);
				$wtime+=20;
				//echo ".";
			}
		} while (($buffer===false)&&(!$this->socket_error)&&($wtime<$timeout));
		//print_r($this->socket_buffer);
		return $buffer;
	}

	/**
	 * Читает одно сообщение из буффера и кладет его в кучку
	 */
	function read_message()
	{
		$type = NULL;
		$parameters = array();
	
		$buffer = trim($this->read_socket());
		while(strlen($buffer)&&!$this->socket_error) {
			$a = strpos($buffer, ':');
			if($a) {
				if(!count($parameters)) // first line in a response?
				{
					$type = strtolower(substr($buffer, 0, $a));
					if(substr($buffer, $a + 2) == 'Follows') {
						// A follows response means there is a multiline field that follows.
						$parameters['data'] = '';
						$buff = $this->read_socket_force();
						while(strlen($buff)&&(substr($buff, 0, 6) != '--END ')&&!$this->socket_error){
							$parameters['data'] .= $buff;
							$buff = $this->read_socket_force();
						}
					}
				}
				$parameters[substr($buffer, 0, $a)] = substr($buffer, $a + 2);
			}
			//если начали читать, то должны дочитать сообщения до конца
			$buffer = trim($this->read_socket_force());
		}
		
		// кладем сообщения в кучи
		switch($type) {
			case 'event':
				$this->log("Event: ".dumpEvent($parameters,true),EVENTS_LOG_LEVEL);
				$this->events_queue[count($this->events_queue)]=$parameters;
				break;
			
			case 'response':
				if (isset($parameters['ActionID']))
					$this->responses_heap[$parameters['ActionID']]=$parameters;
				else{
					$this->log('WARNING: Got respose with an empty Action ID! : ' . print_r($parameters, true));
					$this->responses_heap[]=$parameters;
				}
				$this->log("Response: ".dumpEvent($parameters,true),RESPONCES_LOG_LEVEL);
				break;
			
			default:
				if (isset($parameters['0'])&&strlen($parameters['0'])) //чтото есть, но хз что это
					$this->log('Unhandled message from Manager: ' . print_r($parameters, true));
				break;
		}
	}

	/*
	 * Вытаскивает евент из кучи по принципу fifo и удаляет его там
	 * @return array parameters
	 */
	function fetch_event(){
		if (!isset($this->events_queue[0])) return NULL;
		$parameters=$this->events_queue[0];
		for ($i=0;$i<count($this->events_queue)-2;$i++)
			$this->events_queue[$i]=$this->events_queue[$i+1];
		unset($this->events_queue[count($this->events_queue)-1]);
		$this->log($parameters['Event'].' fetched from queue',IGNORED_EVENTS_LOG_LEVEL);
		return $parameters;
	}
	
   /**
    * Wait for a response
    *
    * If a request was just sent, this will return the response.
    * Otherwise, it will loop forever, handling events.
    *
    * @param string $ID wait for exact response with exact actionid
    * @param boolean $allow_timeout if the socket times out, return an empty array
    * @return array of parameters, empty on timeout
    */
    function wait_response($ID=NULL)
    {
    	$this->read_message();
		if ($ID!==NULL){//если ждем конкретный ответ 
			do {//проверяем кучу
				//если есть такой ответ
				if (isset($this->responses_heap[$ID])) {
					$parameters=$this->responses_heap[$ID];
					unset ($this->responses_heap[$ID]);
					return $parameters;
				} else $this->read_message();
			} while (TRUE);
		} else {//иначе обрабатываем ивенты
			do {
				$this->process_event($this->fetch_event());
    			$this->read_message();
			} while (count($this->events_queue));
		}
    }

   /**
    * Connect to Asterisk
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    *
    * @param string $server
    * @param string $username
    * @param string $secret
    * @return boolean true on success
    */
	function connect($server=NULL, $username=NULL, $secret=NULL)
    {
		// use config if not specified
		if(is_null($server)) $server = $this->config['asmanager']['server'];
		if(is_null($username)) $username = $this->config['asmanager']['username'];
		if(is_null($secret)) $secret = $this->config['asmanager']['secret'];

		// get port from server if specified
		if(strpos($server, ':') !== false) {
			$c = explode(':', $server);
			$this->server = $c[0];
			$this->port = $c[1];
		} else {
			$this->server = $server;
			$this->port = $this->config['asmanager']['port'];
		}

		// connect the socket
		$errno = $errstr = NULL;
		$this->socket = socket_create(AF_INET,SOCK_STREAM,SOL_TCP);
		//$this->socket = @fsockopen($this->server, $this->port, $errno, $errstr);
		if(socket_connect($this->socket,$this->server, $this->port) === false) {
			$this->log("Unable to connect to manager {$this->server}:{$this->port} ($errno): $errstr");
			return false;
		}
		$this->log("Socket opened");
		socket_set_nonblock ($this->socket);
	  
	  //stream_set_timeout ($this->socket,30);	
	  /* 2013-05-17 добавил, чтобы найти проблему в зависании менеджера.
	  ибо с утра висит, что последнее обновление статуса было в 20-55 вчера, 
	  очень вероятно программа зависает в случае таймаута
	  в таком случае надо обнаруживать таймаут и пересоединяться
	  30 секунд достаточно медленно чтобы не было дикого флуда ночью 
	  и достаточно быстро чтобы в случае реальной ошибки восстановить соединение*/
	  

      // read the header
      $str = $this->read_socket_force(1000);
      if($str == false)
      {
        // a problem.
        $this->log("Asterisk Manager header not received.");
        return false;
      }
      else
      {
        // note: don't $this->log($str) until someone looks to see why it mangles the logging
      }
      //$this->log("Socket opened");

      // login
      $res = $this->send_request('login', array('Username'=>$username, 'Secret'=>$secret));
      if($res['Response'] != 'Success')
      {
        $this->_logged_in = FALSE;
        $this->log("Failed to login.");
        $this->disconnect();
        print_r($res);
        return false;
      }
      $this->_logged_in = TRUE;
      return true;
    }

   /**
    * Disconnect
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    */
    function disconnect()
    {
      if($this->_logged_in==TRUE)
        $this->logoff();
      //fclose($this->socket);
      socket_close($this->socket);
    }

   // *********************************************************************************************************
   // **                       COMMANDS                                                                      **
   // *********************************************************************************************************

   /**
    * Set Absolute Timeout
    *
    * Hangup a channel after a certain time.
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+AbsoluteTimeout
    * @param string $channel Channel name to hangup
    * @param integer $timeout Maximum duration of the call (sec)
    */
    function AbsoluteTimeout($channel, $timeout)
    {
      return $this->send_request('AbsoluteTimeout', array('Channel'=>$channel, 'Timeout'=>$timeout));
    }

   /**
    * Change monitoring filename of a channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ChangeMonitor
    * @param string $channel the channel to record.
    * @param string $file the new name of the file created in the monitor spool directory.
    */
    function ChangeMonitor($channel, $file)
    {
      return $this->send_request('ChangeMontior', array('Channel'=>$channel, 'File'=>$file));
    }

   /**
    * Execute Command
    *
    * @example examples/sip_show_peer.php Get information about a sip peer
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Command
    * @link http://www.voip-info.org/wiki-Asterisk+CLI
    * @param string $command
    * @param string $actionid message matching variable
    */
    function Command($command, $actionid=NULL)
    {
      $parameters = array('Command'=>$command);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('Command', $parameters);
    }

   /**
    * Enable/Disable sending of events to this manager
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Events
    * @param string $eventmask is either 'on', 'off', or 'system,call,log'
    */
    function Events($eventmask)
    {
      return $this->send_request('Events', array('EventMask'=>$eventmask));
    }

   /**
    * Check Extension Status
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ExtensionState
    * @param string $exten Extension to check state on
    * @param string $context Context for extension
    * @param string $actionid message matching variable
    */
    function ExtensionState($exten, $context, $actionid=NULL)
    {
      $parameters = array('Exten'=>$exten, 'Context'=>$context);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('ExtensionState', $parameters);
    }

   /**
    * Gets a Channel Variable
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+GetVar
    * @link http://www.voip-info.org/wiki-Asterisk+variables
    * @param string $channel Channel to read variable from
    * @param string $variable
    * @param string $actionid message matching variable
    */
    function GetVar($channel, $variable, $actionid=NULL)
    {
      $parameters = array('Channel'=>$channel, 'Variable'=>$variable);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('GetVar', $parameters);
    }

   /**
    * Hangup Channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Hangup
    * @param string $channel The channel name to be hungup
    */
    function Hangup($channel)
    {
      return $this->send_request('Hangup', array('Channel'=>$channel));
    }

   /**
    * List IAX Peers
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+IAXpeers
    */
    function IAXPeers()
    {
      return $this->send_request('IAXPeers');
    }

   /**
    * List available manager commands
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ListCommands
    * @param string $actionid message matching variable
    */
    function ListCommands($actionid=NULL)
    {
      if($actionid)
        return $this->send_request('ListCommands', array('ActionID'=>$actionid));
      else
        return $this->send_request('ListCommands');
    }

   /**
    * Logoff Manager
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Logoff
    */
    function Logoff()
    {
      return $this->send_request('Logoff');
    }

   /**
    * Check Mailbox Message Count
    *
    * Returns number of new and old messages.
    *   Message: Mailbox Message Count
    *   Mailbox: <mailboxid>
    *   NewMessages: <count>
    *   OldMessages: <count>
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxCount
    * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
    * @param string $actionid message matching variable
    */
    function MailboxCount($mailbox, $actionid=NULL)
    {
      $parameters = array('Mailbox'=>$mailbox);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('MailboxCount', $parameters);
    }

   /**
    * Check Mailbox
    *
    * Returns number of messages.
    *   Message: Mailbox Status
    *   Mailbox: <mailboxid>
    *   Waiting: <count>
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+MailboxStatus
    * @param string $mailbox Full mailbox ID <mailbox>@<vm-context>
    * @param string $actionid message matching variable
    */
    function MailboxStatus($mailbox, $actionid=NULL)
    {	
      $parameters = array('Mailbox'=>$mailbox);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('MailboxStatus', $parameters);
    }

   /**
    * Monitor a channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Monitor
    * @param string $channel
    * @param string $file
    * @param string $format
    * @param boolean $mix
    */
    function Monitor($channel, $file=NULL, $format=NULL, $mix=NULL)
    {
      $parameters = array('Channel'=>$channel);
      if($file) $parameters['File'] = $file;
      if($format) $parameters['Format'] = $format;
      if(!is_null($file)) $parameters['Mix'] = ($mix) ? 'true' : 'false';
      return $this->send_request('Monitor', $parameters);
    }

   /**
    * Originate Call
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Originate
    * @param string $channel Channel name to call
    * @param string $exten Extension to use (requires 'Context' and 'Priority')
    * @param string $context Context to use (requires 'Exten' and 'Priority')
    * @param string $priority Priority to use (requires 'Exten' and 'Context')
    * @param string $application Application to use
    * @param string $data Data to use (requires 'Application')
    * @param integer $timeout How long to wait for call to be answered (in ms)
    * @param string $callerid Caller ID to be set on the outgoing channel
    * @param string $variable Channel variable to set (VAR1=value1|VAR2=value2)
    * @param string $account Account code
    * @param boolean $async true fast origination
    * @param string $actionid message matching variable
    */
    function Originate($channel,
                       $exten=NULL, $context=NULL, $priority=NULL,
                       $application=NULL, $data=NULL,
                       $timeout=NULL, $callerid=NULL, $variable=NULL, $account=NULL, $async=NULL, $actionid=NULL)
    {
      $parameters = array('Channel'=>$channel);

      if($exten) $parameters['Exten'] = $exten;
      if($context) $parameters['Context'] = $context;
      if($priority) $parameters['Priority'] = $priority;

      if($application) $parameters['Application'] = $application;
      if($data) $parameters['Data'] = $data;

      if($timeout) $parameters['Timeout'] = $timeout;
      if($callerid) $parameters['CallerID'] = $callerid;
      if($variable) $parameters['Variable'] = $variable;
      if($account) $parameters['Account'] = $account;
      if(!is_null($async)) $parameters['Async'] = ($async) ? 'true' : 'false';
      if($actionid) $parameters['ActionID'] = $actionid;

      return $this->send_request('Originate', $parameters);
    }	

   /**
    * List parked calls
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ParkedCalls
    * @param string $actionid message matching variable
    */
    function ParkedCalls($actionid=NULL)
    {
      if($actionid)
        return $this->send_request('ParkedCalls', array('ActionID'=>$actionid));
      else
        return $this->send_request('ParkedCalls');
    }

   /**
    * Ping
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Ping
    */
    function Ping()
    {
      return $this->send_request('Ping');
    }

   /**
    * Queue Add
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueAdd
    * @param string $queue
    * @param string $interface
    * @param integer $penalty
    */
    function QueueAdd($queue, $interface, $penalty=0)
    {
      $parameters = array('Queue'=>$queue, 'Interface'=>$interface);
      if($penalty) $parameters['Penalty'] = $penalty;
      return $this->send_request('QueueAdd', $parameters);
    }

   /**
    * Queue Remove
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueRemove
    * @param string $queue
    * @param string $interface
    */
    function QueueRemove($queue, $interface)
    {
      return $this->send_request('QueueRemove', array('Queue'=>$queue, 'Interface'=>$interface));
    }

   /**
    * Queues
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Queues
    */
    function Queues()
    {
      return $this->send_request('Queues');
    }

   /**
    * Queue Status
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+QueueStatus
    * @param string $actionid message matching variable
    */
    function QueueStatus($actionid=NULL)
    {
      if($actionid)
        return $this->send_request('QueueStatus', array('ActionID'=>$actionid));
      else
        return $this->send_request('QueueStatus');
    }

   /**
    * Redirect
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Redirect
    * @param string $channel
    * @param string $extrachannel
    * @param string $exten
    * @param string $context
    * @param string $priority
    */
    function Redirect($channel, $extrachannel, $exten, $context, $priority)
    {
      return $this->send_request('Redirect', array('Channel'=>$channel, 'ExtraChannel'=>$extrachannel, 'Exten'=>$exten,
                                                   'Context'=>$context, 'Priority'=>$priority));
    }

   /**
    * Set the CDR UserField
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetCDRUserField
    * @param string $userfield
    * @param string $channel
    * @param string $append
    */
    function SetCDRUserField($userfield, $channel, $append=NULL)
    {
      $parameters = array('UserField'=>$userfield, 'Channel'=>$channel);
      if($append) $parameters['Append'] = $append;
      return $this->send_request('SetCDRUserField', $parameters);
    }

   /**
    * Set Channel Variable
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+SetVar
    * @param string $channel Channel to set variable for
    * @param string $variable name
    * @param string $value
    */
    function SetVar($channel, $variable, $value)
    {
      return $this->send_request('SetVar', array('Channel'=>$channel, 'Variable'=>$variable, 'Value'=>$value));
    }

   /**
    * Channel Status
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+Status
    * @param string $channel
    * @param string $actionid message matching variable
    */
    function Status($channel, $actionid=NULL)
    {
      $parameters = array('Channel'=>$channel);
      if($actionid) $parameters['ActionID'] = $actionid;
      return $this->send_request('Status', $parameters);
    }

   /**
    * Stop monitoring a channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+StopMonitor
    * @param string $channel
    */
    function StopMonitor($channel)
    {
      return $this->send_request('StopMonitor', array('Channel'=>$channel));
    }

   /**
    * Dial over Zap channel while offhook
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDialOffhook
    * @param string $zapchannel
    * @param string $number
    */
    function ZapDialOffhook($zapchannel, $number)
    {
      return $this->send_request('ZapDialOffhook', array('ZapChannel'=>$zapchannel, 'Number'=>$number));
    }

   /**
    * Toggle Zap channel Do Not Disturb status OFF
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDoff
    * @param string $zapchannel
    */
    function ZapDNDoff($zapchannel)
    {
      return $this->send_request('ZapDNDoff', array('ZapChannel'=>$zapchannel));
    }

   /**
    * Toggle Zap channel Do Not Disturb status ON
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapDNDon
    * @param string $zapchannel
    */
    function ZapDNDon($zapchannel)
    {
      return $this->send_request('ZapDNDon', array('ZapChannel'=>$zapchannel));
    }

   /**
    * Hangup Zap Channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapHangup
    * @param string $zapchannel
    */
    function ZapHangup($zapchannel)
    {
      return $this->send_request('ZapHangup', array('ZapChannel'=>$zapchannel));
    }

   /**
    * Transfer Zap Channel
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapTransfer
    * @param string $zapchannel
    */
    function ZapTransfer($zapchannel)
    {
      return $this->send_request('ZapTransfer', array('ZapChannel'=>$zapchannel));
    }

   /**
    * Zap Show Channels
    *
    * @link http://www.voip-info.org/wiki-Asterisk+Manager+API+Action+ZapShowChannels
    * @param string $actionid message matching variable
    */
    function ZapShowChannels($actionid=NULL)
    {
      if($actionid)
        return $this->send_request('ZapShowChannels', array('ActionID'=>$actionid));
      else
        return $this->send_request('ZapShowChannels');
    }

   // *********************************************************************************************************
   // **                       MISC                                                                          **
   // *********************************************************************************************************

   /*
    * Log a message
    *
    * @param string $message
    * @param integer $level from 1 to 4
    */
    function log($message, $level=1)
    {
      if($this->pagi != false)
        $this->pagi->conlog($message, $level);
      else
        msg('AstManager('.$this->getStatus().'): ' . $message,$level);
    }

   /**
    * Add event handler
    *
    * Known Events include ( http://www.voip-info.org/wiki-asterisk+manager+events )
    *   Link - Fired when two voice channels are linked together and voice data exchange commences.
    *   Unlink - Fired when a link between two voice channels is discontinued, for example, just before call completion.
    *   Newexten -
    *   Hangup -
    *   Newchannel -
    *   Newstate -
    *   Reload - Fired when the "RELOAD" console command is executed.
    *   Shutdown -
    *   ExtensionStatus -
    *   Rename -
    *   Newcallerid -
    *   Alarm -
    *   AlarmClear -
    *   Agentcallbacklogoff -
    *   Agentcallbacklogin -
    *   Agentlogoff -
    *   MeetmeJoin -
    *   MessageWaiting -
    *   join -
    *   leave -
    *   AgentCalled -
    *   ParkedCall - Fired after ParkedCalls
    *   Cdr -
    *   ParkedCallsComplete -
    *   QueueParams -
    *   QueueMember -
    *   QueueStatusEnd -
    *   Status -
    *   StatusComplete -
    *   ZapShowChannels - Fired after ZapShowChannels
    *   ZapShowChannelsComplete -
    *
    * @param string $event type or * for default handler
    * @param string $callback function
    * @return boolean sucess
    */
    function add_event_handler($event, $callback)
    {
      $event = strtolower($event);
      if(isset($this->event_handlers[$event]))
      {
        $this->log("$event handler is already defined, not over-writing.");
        return false;
      }
      $this->event_handlers[$event] = $callback;
      return true;
    }

   /**
    * Process event
    *
    * @access private
    * @param array $parameters
    * @return mixed result of event handler or false if no handler was found
    */
    function process_event($parameters)
    {
    	
    	// для логирования используется IGNORED_EVENTS_LOG_LEVEL, поскольку неясно отфильтруются они далее или нет
      $ret = false;
      $e = strtolower($parameters['Event']);
      //$this->log("Got event.. $e");		

      $handler = '';
      if(isset($this->event_handlers[$e])) $handler = $this->event_handlers[$e];
      elseif(isset($this->event_handlers['*'])) $handler = $this->event_handlers['*'];

	  
      if(is_array($handler)&&method_exists($handler[0],$handler[1]))
      {
		$this->log("processing $e => ".get_class($handler[0])."::$handler[1]",IGNORED_EVENTS_LOG_LEVEL);
		$ret=$handler[0]->$handler[1]($e, $parameters, $this->server, $this->port);
	  }
      elseif(function_exists($handler))
      {
      	 $this->log("processing $e => $handler",IGNORED_EVENTS_LOG_LEVEL);
      	 $ret = $handler($e, $parameters, $this->server, $this->port);
      } elseif(function_exists('AMI_defaultevent_handler')) {
        $ret = AMI_defaultevent_handler($e, $parameters, $this->server, $this->port);
      }
      else
		$this->log("No event handler for event '$e'",REQUESTS_LOG_LEVEL);
      return $ret;
    }
  }
?>
