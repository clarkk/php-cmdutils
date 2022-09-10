<?php

namespace Utils\WSS;

class Client extends Protocol {
	private $socket;
	private $socket_id;
	private $path;
	private $key;
	private $version;
	private $data = [];
	
	const HEADER_WEBSOCKET_ACCEPT_HASH		= '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	
	const HEADER_BYTES_READ					= 1024;
	const MAX_BYTES_READ 					= 1024 * 8;
	
	const CRLF 								= "\r\n";
	
	public function __construct($socket, int $socket_id){
		$headers = fread($socket, self::HEADER_BYTES_READ);
		if(!preg_match('/GET (.*?) /', $headers, $match)){
			return;
		}
		
		$this->socket 		= $socket;
		$this->socket_id 	= $socket_id;
		$this->path 		= trim($match[1]);
		
		if(preg_match('/^Sec-WebSocket-Key: (.*)\R/m', $headers, $match)){
			$this->key 		= trim($match[1]);
		}
		
		if(preg_match('/^Sec-WebSocket-Version: (.*)\R/m', $headers, $match)){
			$this->version 	= trim($match[1]);
		}
	}
	
	public function handshake(): ?array{
		if(!$this->key){
			return null;
		}
		
		fwrite($this->socket, 'HTTP/1.1 101 Web Socket Protocol Handshake'.self::CRLF
			.'Upgrade: websocket'.self::CRLF
			.'Connection: Upgrade'.self::CRLF
			.'Sec-WebSocket-Accept:  '.base64_encode(sha1($this->key.self::HEADER_WEBSOCKET_ACCEPT_HASH, true)).self::CRLF.self::CRLF);
		
		return [
			'key'		=> $this->key,
			'version'	=> $this->version,
			'path'		=> $this->path
		];
	}
	
	public function socket_id(): int{
		return $this->socket_id;
	}
	
	public function receive(): ?array{
		$this->buffer .= fread($this->socket, self::MAX_BYTES_READ);
		return $this->decode();
	}
	
	public function send(array $message): void{
		fwrite($this->socket, $this->encode(json_encode($message)));
	}
	
	public function error(string $error): void{
		$this->send([
			'error' => $error
		]);
	}
	
	public function set_data(string $key, string $value): void{
		$this->data[$key] = $value;
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