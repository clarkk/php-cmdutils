<?php

// https://github.com/arthurkushman/php-wss
// https://github.com/ghedipunk/PHP-Websockets
// https://web.archive.org/web/20120918000731/http://srchea.com/blog/2011/12/build-a-real-time-application-using-html5-websockets/
// https://www.hashbangcode.com/article/fibers-php-81

/*
	Find and kill the process who uses port 9000
	# netstat -nlp | grep :9000
*/

namespace Utils\WSS;

abstract class Server extends \Utils\Verbose {
	protected array $clients 		= [];
	
	private int $num_main_fibers	= 0;
	private array $fibers 			= [];
	
	private string $host 			= '0.0.0.0';
	private int $port;
	
	private $server_socket;
	private array $client_sockets 	= [];
	
	private float $loop_idle_sleep 	= 0.5;
	
	const TIMEOUT_WRITE				= 30;
	const TIMEOUT_READ 				= 30;
	const BUFFER_WRITE 				= 1024 * 5;
	const BUFFER_READ 				= 1024 * 5;
	
	const USEC 						= 1000000;
	
	public function __construct(string $task_name, int $verbose, int $port=9000){
		$this->task_name 		= $task_name;
		$this->verbose 			= $verbose;
		
		$this->port 			= $port;
		
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
		$this->loop_idle_sleep *= self::USEC;
		
		$this->listen();
		
		$new_clients = new \Fiber(function(): void{
			while(true){
				$read = [$this->server_socket];
				while(true){
					if(!stream_select($read, $write, $except, 0)){
						break;
					}
					
					$socket 	= stream_socket_accept($this->server_socket, 0);
					$client 	= new Client($this->task_name, $this->verbose, $socket);
					stream_set_blocking($socket, false);
					
					if(!$handshake = $client->handshake()){
						if($this->verbose){
							$this->verbose('Client failed to connect', self::COLOR_RED);
						}
						
						continue;
					}
					
					$this->write($socket, $handshake, function() use ($socket, $client): void{
						$socket_id 							= $client->socket_id();
						$this->clients[$socket_id]			= $client;
						$this->client_sockets[$socket_id]	= $socket;
						
						if($this->verbose){
							$connection = $client->connection();
							$this->verbose("New client #$socket_id\nkey: ".$connection['key']."\nversion: ".$connection['version']."\npath: ".$connection['path'], self::COLOR_GREEN);
						}
						
						$this->onopen($client);
					});
				}
				
				\Fiber::suspend();
			}
		});
		
		$read = new \Fiber(function(): void{
			while(true){
				if($read = $this->client_sockets){
					if(stream_select($read, $write, $except, 0)){
						foreach($read as $socket_id => $socket){
							$client = $this->clients[$socket_id];
							
							//	Check if another fiber is already reading from the client
							if(!$client->is_buffering()){
								$this->read($socket, $client);
							}
						}
					}
				}
				
				\Fiber::suspend();
			}
		});
		
		$push = new \Fiber(function(): void{
			while(true){
				$this->push();
				
				\Fiber::suspend();
			}
		});
		
		$write = new \Fiber(function():void{
			while(true){
				foreach($this->clients as $client){
					$socket = $client->socket();
					while($data = $client->send()){
						$this->write($socket, $data);
					}
				}
				
				\Fiber::suspend();
			}
		});
		
		$this->fibers = [
			$new_clients,
			$read,
			$push,
			$write
		];
		
		$this->num_main_fibers = count($this->fibers);
		
		foreach($this->fibers as $fiber){
			$fiber->start();
		}
		
		$time_status = time();
		while(true){
			if($this->verbose){
				$time = time();
				if($time - $time_status > 10){
					$this->verbose(self::VERBOSE_INDENTATION.'Clients: '.count($this->client_sockets).', Fibers: '.count($this->fibers).' ('.$this->num_main_fibers.')', self::COLOR_BLUE);
					$time_status = $time;
				}
			}
			
			foreach($this->fibers as $i => $fiber){
				if($fiber->isTerminated()){
					unset($this->fibers[$i]);
				}
				elseif($fiber->isSuspended()){
					$fiber->resume();
				}
			}
			
			//	Sleep if no fibers to process
			if(!$this->is_processing_fibers()){
				if($this->verbose){
					$this->verbose('Sleep '.($this->loop_idle_sleep / self::USEC).' secs...', self::COLOR_GRAY);
				}
				
				usleep($this->loop_idle_sleep);
			}
		}
	}
	
	protected function is_processing_fibers(): bool{
		return count($this->fibers) > $this->num_main_fibers;
	}
	
	protected function send(Client $client, array $message): void{
		if(!$message){
			return;
		}
		
		$type 		= Protocol::TYPE_TEXT;
		$message 	= json_encode($message);
		
		if($this->verbose){
			$this->verbose('#'.$client->socket_id()." <- $type", self::COLOR_BLUE);
			$this->verbose($message, self::COLOR_PURPLE);
		}
		
		$client->queue($message, $type);
	}
	
	public function error(Client $client, string $error): void{
		if($this->verbose){
			$this->verbose($error, self::COLOR_RED);
		}
		
		$this->send($client, [
			'error' => $error
		]);
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
	
	private function read($socket, Client $client): void{
		$fiber = new \Fiber(function($socket, Client $client): void{
			$read 	= [$socket];
			$write 	= [];
			
			$time = time();
			do{
				if(stream_select($read, $write, $except, 0)){
					if(($data = fread($socket, self::BUFFER_READ)) !== false){
						try{
							if($message = $client->buffer($data)){
								if($this->verbose){
									$this->verbose('#'.$client->socket_id().' '.$message[Protocol::DATA_TYPE].' ->', self::COLOR_BLUE);
								}
								
								switch($message[Protocol::DATA_TYPE]){
									case Protocol::TYPE_PING:
										$this->onping($client);
										break;
									
									case Protocol::TYPE_PONG:
										$this->onpong($client);
										break;
									
									case Protocol::TYPE_TEXT:
										if($this->verbose){
											$this->verbose($message[Protocol::DATA_MESSAGE], self::COLOR_PURPLE);
										}
										
										$this->onmessage($client, json_decode($message[Protocol::DATA_MESSAGE], true));
										break;
									
									case Protocol::TYPE_CLOSE:
										$this->close($client);
										break;
								}
								
								return;
							}
							
							if($this->verbose){
								$this->verbose('#'.$client->socket_id().' -> Chunked data buffered', self::COLOR_BLUE);
							}
						}
						catch(Protocol_error $e){
							$error = $e->getMessage();
							
							if($this->verbose){
								$this->verbose($error, self::COLOR_RED);
							}
							
							$this->error($client, $error);
						}
					}
				}
				
				\Fiber::suspend();
			}
			while(time() - $time <= self::TIMEOUT_READ);
		});
		
		$fiber->start($socket, $client);
		$this->fibers[] = $fiber;
	}
	
	private function write($socket, string $data, $success=null): void{
		if(!$data){
			return;
		}
		
		$fiber = new \Fiber(function($socket, string $data, $success): void{
			$read 	= [];
			$write 	= [$socket];
			
			$time = time();
			do{
				if(stream_select($read, $write, $except, 0)){
					if(fwrite($socket, $data, self::BUFFER_WRITE) !== false){
						if(($data = substr($data, self::BUFFER_WRITE)) === ''){
							if($success){
								$success();
							}
							return;
						}
					}
				}
				
				\Fiber::suspend();
			}
			while(time() - $time <= self::TIMEOUT_WRITE);
		});
		
		$fiber->start($socket, $data, $success);
		$this->fibers[] = $fiber;
	}
	
	private function listen(): void{
		if(!$this->server_socket = stream_socket_server("tcp://$this->host:$this->port", $errno, $error)){
			$error = "Could not bind to socket: $errno - $error";
			
			if($this->verbose){
				$this->verbose($error, self::COLOR_RED);
			}
			
			throw new Socket_error($error);
		}
		
		stream_set_blocking($this->server_socket, false);
		
		if($this->verbose){
			$this->verbose("WS server started listening on: $this->host:$this->port", self::COLOR_GREEN);
		}
	}
}

class Socket_error extends \Error {}