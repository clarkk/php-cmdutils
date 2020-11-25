<?php

namespace Utils\Net;

class Net {
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
	
	public function keep_alive(){
		$this->keep_alive = true;
	}
	
	public function decode_type(){
		$this->decode_type = true;
	}
	
	public function close(){
		if($this->verbose){
			fclose($this->verbose_output);
		}
		
		curl_close($this->curl);
	}
	
	public function request(string $url, string $post='', array $headers=[], array $options=[], bool $multipart=false): array{
		if(!strpos($url, '://')){
			throw new Error("Protocol missing in URL '$url'");
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
			CURLOPT_HTTP_VERSION 	=> strpos($url, 'https://') === 0 ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_1_1
		]);
		
		if($post){
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
		}
		
		foreach($options as $key => $value){
			curl_setopt($this->curl, $key, $value);
		}
		
		/*$response 	= curl_exec($this->curl);
		$code 		= curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
		$error 		= curl_error($this->curl);
		$type 		= $this->get_content_type();
		
		if(!$this->keep_alive){
			$this->close();
		}
		
		if($this->decode_type){
			if($type == self::CONTENT_TYPE_JSON){
				$response = json_decode($response, true) ?? $response;
			}
		}
		
		return [
			'code'		=> $code,
			'error'		=> $error,
			'type'		=> $type,
			'response'	=> $response
		];*/
	}
}

class Error extends \Error {}