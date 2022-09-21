<?php

namespace Utils\WSS;

class Client extends Protocol {
	private $socket;
	private $socket_id;
	private $path;
	private $version;
	private $data 			= [];
	
	const HEADER_BYTES_READ	= 1024;
	
	public function __construct(string $task_name, int $verbose, $socket){
		$this->task_name 	= $task_name;
		$this->verbose 		= $verbose;
		
		$headers = fread($socket, self::HEADER_BYTES_READ);
		if(!preg_match('/GET (.*?) /', $headers, $match)){
			return;
		}
		
		$this->socket 		= $socket;
		$this->socket_id 	= get_resource_id($socket);
		$this->path 		= trim($match[1]);
		
		if(preg_match('/^Sec-WebSocket-Key: (.*)\R/m', $headers, $match)){
			$this->key 		= trim($match[1]);
		}
		
		if(preg_match('/^Sec-WebSocket-Version: (.*)\R/m', $headers, $match)){
			$this->version 	= trim($match[1]);
		}
		
		parent::__construct();
	}
	
	public function connection(): array{
		return [
			'key'		=> $this->key,
			'version'	=> $this->version,
			'path'		=> $this->path
		];
	}
	
	public function socket_id(): int{
		return $this->socket_id;
	}
	
	public function socket(){
		return $this->socket;
	}
	
	public function set_data(string $key, $value): void{
		$this->data[$key] = $value;
	}
	
	public function update_data(array $data): void{
		$this->data = $data;
	}
	
	public function data(?string $key=null){
		return is_null($key) ? $this->data : $this->data[$key];
	}
	
	public function close(bool $send=false): void{
		if(is_resource($this->socket)){
			if($send){
				fwrite($this->socket, $this->encode('', self::TYPE_CLOSE));
			}
			fclose($this->socket);
		}
	}
}