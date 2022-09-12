<?php

// https://github.com/arthurkushman/php-wss
// https://github.com/ghedipunk/PHP-Websockets
// https://web.archive.org/web/20120918000731/http://srchea.com/blog/2011/12/build-a-real-time-application-using-html5-websockets/

namespace Utils\WSS;

abstract class Server extends \Utils\Verbose {
	protected $clients 			= [];
	
	private $host 				= '0.0.0.0';
	private $port;
	private $stream_select_timeout;
	
	private $server_socket;
	private $client_sockets 	= [];
	
	private const SOCKET_SERVER = 'server';
	
	public function __construct(string $task_name, int $verbose, int $port=9000, int $stream_select_timeout=1){
		$this->task_name 				= $task_name;
		$this->verbose 					= $verbose;
		
		$this->port 					= $port;
		$this->stream_select_timeout 	= $stream_select_timeout;
		
		ini_set('default_socket_timeout', 5);
		
		parent::__construct();
	}
	
	abstract public function onopen(Client $client): void;
	
	abstract public function onping(Client $client): void;
	
	abstract public function onpong(Client $client): void;
	
	abstract public function onmessage(Client $client, array $message): void;
	
	abstract public function onclose(Client $client): void;
	
	abstract public function push(): void;
	
	public function run(): void{
		$this->listen();
		
		while(true){
			if($this->verbose){
				$this->verbose('Check push query', self::COLOR_GRAY);
			}
			
			$this->push();
			
			$sockets = $this->client_sockets;
			$sockets[self::SOCKET_SERVER] = &$this->server_socket;
			
			if($this->verbose){
				$this->verbose('Waiting for incoming socket data (Clients: '.(count($sockets)-1).')', self::COLOR_GRAY);
			}
			
			if(!stream_select($sockets, $write, $except, $this->stream_select_timeout)){
				continue;
			}
			
			if(!empty($sockets[self::SOCKET_SERVER])){
				$this->new_client();
				unset($sockets[self::SOCKET_SERVER]);
			}
			
			$this->read_messages($sockets);
		}
	}
	
	protected function close(Client $client, bool $send=false): void{
		if($this->verbose){
			$this->verbose('Connection close'.($send ? ' (Send close to client)' : ''), self::COLOR_YELLOW);
		}
		
		$this->onclose($client);
		
		$socket_id = $client->socket_id();
		$client->close($send);
		unset($this->clients[$socket_id], $this->client_sockets[$socket_id]);
	}
	
	private function read_messages(array $sockets): void{
		foreach($sockets as $socket_id => $socket){
			try{
				$client = $this->clients[$socket_id];
				if(!$data = $client->receive()){
					if($this->verbose){
						$this->verbose("#$socket_id -> Chunked data buffered", self::COLOR_BLUE);
					}
					
					continue;
				}
				
				if($this->verbose){
					$this->verbose("#$socket_id ".$data[Protocol::DATA_TYPE]." ->", self::COLOR_BLUE);
				}
				
				switch($data[Protocol::DATA_TYPE]){
					case Protocol::TYPE_PING:
						$this->onping($client);
						break;
					
					case Protocol::TYPE_PONG:
						$this->onpong($client);
						break;
					
					case Protocol::TYPE_TEXT:
						if($this->verbose){
							$this->verbose($data[Protocol::DATA_MESSAGE], self::COLOR_PURPLE);
						}
						
						$this->onmessage($client, json_decode($data[Protocol::DATA_MESSAGE], true));
						break;
					
					case Protocol::TYPE_CLOSE:
						$this->close($client);
						break;
				}
			}
			catch(Protocol_error $e){
				$error = $e->getMessage();
				
				if($this->verbose){
					$this->verbose($error, self::COLOR_RED);
				}
				
				$client->error($error);
			}
		}
	}
	
	private function new_client(): void{
		if(!$socket = stream_socket_accept($this->server_socket, 0)){
			return;
		}
		
		$socket_id 	= get_resource_id($socket);
		$client 	= new Client($this->task_name, $this->verbose, $socket, $socket_id);
		
		if(!$connection = $client->handshake()){
			if($this->verbose){
				$this->verbose('Client failed to connect', self::COLOR_RED);
			}
			
			return;
		}
		
		$this->clients[$socket_id]			= $client;
		$this->client_sockets[$socket_id]	= $socket;
		
		if($this->verbose){
			$this->verbose("New client #$socket_id\nkey: ".$connection['key']."\nversion: ".$connection['version']."\npath: ".$connection['path'], self::COLOR_GREEN);
		}
		
		$this->onopen($client);
	}
	
	private function listen(): void{
		if(!$this->server_socket = stream_socket_server("tcp://$this->host:$this->port", $errno, $message)){
			$error = "Could not bind to socket: $errno - $message";
			
			if($this->verbose){
				$this->verbose($error, self::COLOR_RED);
			}
			
			throw new Socket_error($error);
		}
		
		if($this->verbose){
			$this->verbose("WS server started listening on: $this->host:$this->port", self::COLOR_GREEN);
		}
	}
}

class Socket_error extends \Error {}