<?php

namespace Utils\Net;

class Net extends Net_error_codes {
	private $curl;	
	private $keep_alive 	= false;
	private $decode_type 	= false;
	
	private $boundary;
	
	private $verbose 		= false;
	private $verbose_output;
	
	const CONTENT_TYPE 			= 'Content-Type';
	const CONTENT_TYPE_JSON 	= 'application/json';
	const CONTENT_TYPE_TEXT 	= 'text/plain';
	const CONTENT_TYPE_FORM 	= 'application/x-www-form-urlencoded';
	
	const CONTENT_LENGTH 		= 'Content-Length';
	
	const CONTENT_DISPOSITION 	= 'Content-Disposition';
	
	const METHOD_POST 			= 'POST';
	const METHOD_PUT 			= 'PUT';
	
	const CRLF 					= "\r\n";
	
	public function __construct(bool $ssl_verify=true, bool $verbose=false){
		$this->curl = curl_init();
		
		if(!$ssl_verify){
			curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
		}
		
		if($this->verbose = $verbose){
			$this->verbose_output = fopen('php://output', 'w');
			curl_setopt($this->curl, CURLOPT_VERBOSE, true);
			curl_setopt($this->curl, CURLOPT_STDERR, $this->verbose_output);
		}
	}
	
	public function keep_alive(): self{
		$this->keep_alive = true;
		
		return $this;
	}
	
	public function decode_type(): self{
		$this->decode_type = true;
		
		return $this;
	}
	
	public function close(){
		if($this->verbose){
			fclose($this->verbose_output);
		}
		
		curl_close($this->curl);
	}
	
	public function request(string $url, string $post='', array $headers=[], array $options=[], bool $multipart=false): array{
		if(!strpos($url, '://')){
			throw new Error("Protocol missing in URL '$url'", self::ERR_NETWORK);
		}
		
		if($multipart){
			$headers[] = self::CONTENT_TYPE.': multipart/form-data; boundary='.$this->boundary;
		}
		
		curl_setopt_array($this->curl, [
			CURLOPT_HTTPHEADER 		=> array_merge([
				'Accept-Encoding: gzip'
			], $headers),
			CURLOPT_URL 			=> $url,
			CURLOPT_RETURNTRANSFER 	=> true,
			CURLOPT_ENCODING 		=> '',
			CURLOPT_FOLLOWLOCATION 	=> true,
			CURLOPT_HTTP_VERSION 	=> strpos($url, 'https://') === 0 ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_1_1
		]);
		
		if($post){
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
		}
		else{
			curl_setopt($this->curl, CURLOPT_POST, false);
		}
		
		foreach($options as $key => $value){
			curl_setopt($this->curl, $key, $value);
		}
		
		$response = curl_exec($this->curl);
		
		if($response === false){
			throw new Error(curl_error($this->curl), self::ERR_NETWORK);
		}
		
		$code = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		$type = $this->get_content_type();
		
		if($this->decode_type){
			$this->decode_response($type, $response);
		}
		
		if(!$this->keep_alive){
			$this->close();
		}
		
		return [
			'code'		=> $code,
			'type'		=> $type,
			'response'	=> $response
		];
	}
	
	public function multipart_value(string $key, string $value, string $file_name='', string $content_type=''): string{
		if(!$this->boundary){
			$this->boundary = md5(time());
		}
		
		return '--'.$this->boundary.self::CRLF
			.self::CONTENT_DISPOSITION.': form-data; name="'.$key.'"'.($file_name ? '; filename="'.$file_name.'"' : '').self::CRLF
			.($content_type ? self::CONTENT_TYPE.': '.$content_type.self::CRLF : '')
			.self::CONTENT_LENGTH.': '.strlen($value).self::CRLF.self::CRLF
			.$value.self::CRLF;
	}
	
	public function multipart_end(): string{
		return '--'.$this->boundary.'--';
	}
	
	protected function decode_response(string $type, string &$response){
		switch($type){
			case self::CONTENT_TYPE_JSON:
				try{
					$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
				}
				catch(\Exception $e){
					throw new Error('JSON decode error', self::ERR_RESPONSE);
				}
				break;
		}
	}
	
	private function get_content_type(): string{
		$type 	= curl_getinfo($this->curl, CURLINFO_CONTENT_TYPE);
		$pos 	= strpos($type, ';');
		
		return $pos ? substr($type, 0, $pos) : $type;
	}
}

class Error extends \Error {}