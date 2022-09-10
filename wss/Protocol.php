<?php

namespace Utils\WSS;

abstract class Protocol {
	const TYPE_PING				= 'ping';
	const TYPE_PONG				= 'pong';
	const TYPE_TEXT 			= 'text';
	const TYPE_CLOSE			= 'close';
	const TYPE_BINARY			= 'binary';
	const TYPE_CONTINUATION		= 'continuation';
	
	const DECODE_TEXT			= 1;
	const DECODE_BINARY 		= 2;
	const DECODE_CLOSE			= 8;
	const DECODE_PING			= 9;
	const DECODE_PONG			= 10;
	
	const ENCODE_TEXT			= 129;
	const ENCODE_CLOSE			= 136;
	const ENCODE_PING 			= 137;
	const ENCODE_PONG			= 138;
	
	const SEND_LIMIT			= 65535;
	const PAYLOAD_CHUNK 		= 8;
	
	const DATA_TYPE 			= 'type';
	const DATA_MESSAGE 			= 'message';
	
	protected $buffer = '';
	
	protected function decode(): ?array{
		if(!$this->buffer){
			return [
				self::DATA_TYPE		=> self::TYPE_CLOSE,
				self::DATA_MESSAGE	=> null
			];
		}
		
		$first_byte 	= $this->binchar($this->buffer[0]);
		$second_byte 	= $this->binchar($this->buffer[1]);
		
		if(!$type = $this->get_decode_type($first_byte)){
			$this->buffer = '';
			
			throw new Protocol_error('Unknown upcode received');
		}
		
		if($second_byte[0] !== '1'){
			$this->buffer = '';
			
			throw new Protocol_error('Unmasked message received');
		}
		
		switch($length = ord($this->buffer[1]) & 127){
			case 126:
				$mask 			= substr($this->buffer, 4, 4);
				$offset 		= 8;
				$data_length 	= bindec($this->binchar($this->buffer[2]).$this->binchar($this->buffer[3])) + $offset;
				break;
			
			case 127:
				$mask 			= substr($this->buffer, 10, 4);
				$offset 		= 14;
				$tmp 			= '';
				for($i=0; $i<8; $i++){
					$tmp .= $this->binchar($this->buffer[$i + 2]);
				}
				$data_length 	= bindec($tmp) + $offset;
				break;
			
			default:
				$mask 			= substr($this->buffer, 2, 4);
				$offset 		= 6;
				$data_length 	= $length + $offset;
		}
		
		//	Buffer data and wait for remaining data if data is chunked
		if(strlen($this->buffer) < $data_length){
			return null;
		}
		
		$unmasked_data = '';
		for($i=$offset; $i<$data_length; $i++){
			$j = $i - $offset;
			if(isset($this->buffer[$i])){
				$unmasked_data .= $this->buffer[$i] ^ $mask[$j % 4];
			}
		}
		
		$this->buffer = '';
		
		return [
			self::DATA_TYPE		=> $type,
			self::DATA_MESSAGE	=> $unmasked_data
		];
	}
	
	protected function encode(string $message, string $type=self::TYPE_TEXT, bool $masked=false): string{
		$header = $this->get_encode_type($type);
		$length = strlen($message);
		
		if($length > self::SEND_LIMIT){
			$binlength = str_split(sprintf('%064b', $length), self::PAYLOAD_CHUNK);
			$header[1] = $masked ? 255 : 127;
			
			for($i=0; $i<8; $i++){
				$header[$i + 2] = bindec($binlength[$i]);
			}
			
			if($header[2] > 127){
				throw new Protocol_error('Frame too large');
			}
		}
		elseif($length > 125){
			$binlength = str_split(sprintf('%016b', $length), self::PAYLOAD_CHUNK);
			$header[1] = $masked ? 254 : 126;
			$header[2] = bindec($binlength[0]);
			$header[3] = bindec($binlength[1]);
		}
		else{
			$header[1] = $masked ? $length + 128 : $length;
		}
		
		return $this->compose_frame($header, $message, $length, $masked);
	}
	
	private function compose_frame(array $header, string $message, int $length, bool $masked): string{
		foreach($header as &$value){
			$value = chr($value);
		}
		
		$mask = [];
		if($masked){
			for($i=0; $i<4; $i++){
				$mask[$i] = chr(random_int(0, 255));
			}
			$header = array_merge($header, $mask);
		}
		$frame = implode('', $header);
		
		for($i=0; $i<$length; $i++){
			$frame .= $masked ? $message[$i] ^ $mask[$i % 4] : $message[$i];
		}
		
		return $frame;
	}
	
	private function get_encode_type(string $type): array{
		$header = [];
		switch($type){
			case self::TYPE_TEXT:
				$header[] = self::ENCODE_TEXT;
				break;
			
			case self::TYPE_CLOSE:
				$header[] = self::ENCODE_CLOSE;
				break;
			
			case self::TYPE_PING:
				$header[] = self::ENCODE_PING;
				break;
			
			case self::TYPE_PONG:
				$header[] = self::ENCODE_PONG;
				break;
		}
		
		return $header;
	}
	
	private function get_decode_type(string $first_byte): string{
		switch(bindec(substr($first_byte, 4, 4))){
			case self::DECODE_TEXT:
				return self::TYPE_TEXT;
			
			case self::DECODE_BINARY:
				return self::TYPE_BINARY;
			
			case self::DECODE_CLOSE:
				return self::TYPE_CLOSE;
			
			case self::DECODE_PING:
				return self::TYPE_PING;
			
			case self::DECODE_PONG:
				return self::TYPE_PONG;
			
			default:
				return '';
		}
	}
	
	private function binchar(string $char): string{
		return sprintf('%08b', ord($char));
	}
}

class Protocol_error extends \Error {}