<?php

namespace Utils\Net;

class Net implements Error_codes {
	private $curl;
	private bool $keep_alive 		= false;
	private bool $decode_type 		= false;
	private string $auth 			= '';
	private string $auth_bearer 	= '';
	
	private string $boundary 		= '';
	
	private bool $verbose 			= false;
	private $verbose_output;
	
	const CONTENT_TYPE 				= 'Content-Type';
	const CONTENT_TYPE_JSON 		= 'application/json';
	const CONTENT_TYPE_XML 			= 'application/xml';
	const CONTENT_TYPE_TEXT 		= 'text/plain';
	const CONTENT_TYPE_FORM 		= 'application/x-www-form-urlencoded';
	
	const CONTENT_LENGTH 			= 'Content-Length';
	
	const CONTENT_DISPOSITION 		= 'Content-Disposition';
	
	const METHOD_GET 				= 'GET';
	const METHOD_POST 				= 'POST';
	const METHOD_PUT 				= 'PUT';
	const METHOD_DELETE 			= 'DELETE';
	
	const CRLF 						= "\r\n";
	
	public function __construct(bool $ssl_verify=true, bool $verbose=false){
		$this->curl = curl_init();
		
		if(!$ssl_verify){
			curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
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
	
	public function auth(string $user, string $pass): self{
		$this->auth = "$user:$pass";
		
		return $this;
	}
	
	public function auth_bearer(string $token): self{
		$this->auth_bearer = $token;
		
		return $this;
	}
	
	public function auth_cert(string $path): self{
		curl_setopt($this->curl, CURLOPT_SSLCERT, $path);
		
		return $this;
	}
	
	public function decode_type(): self{
		$this->decode_type = true;
		
		return $this;
	}
	
	public function close(): void{
		if($this->verbose){
			fclose($this->verbose_output);
		}
		
		curl_close($this->curl);
	}
	
	public function request(string $url, string $post='', array $headers=[], array $options=[]): array{
		$this->check_url($url);
		
		return $this->send($post ? self::METHOD_POST : self::METHOD_GET, $url, $post, $headers, $options);
	}
	
	public function request_delete(string $url, string $post='', array $headers=[], array $options=[]): array{
		$this->check_url($url);
		
		return $this->send(self::METHOD_DELETE, $url, $post, $headers, $options);
	}
	
	public function request_put(string $url, string $post='', array $headers=[], array $options=[]): array{
		$this->check_url($url);
		
		return $this->send(self::METHOD_PUT, $url, $post, $headers, $options);
	}
	
	public function request_multipart(string $url, string $post='', array $headers=[], array $options=[]): array{
		$this->check_url($url);
		
		$headers[] = self::CONTENT_TYPE.': multipart/form-data; boundary='.$this->boundary;
		
		return $this->send(self::METHOD_POST, $url, $post, $headers, $options);
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
	
	protected function decode_response(string $type, string &$response): void{
		switch($type){
			case self::CONTENT_TYPE_JSON:
				try{
					$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
				}
				catch(\Exception $e){
					throw new Error('JSON decode error', self::ERR_RESPONSE);
				}
				break;
			
			case self::CONTENT_TYPE_XML:
				try{
					$response = \Str\Format::xml_decode($response);
				}
				catch(\Exception $e){
					throw new Error('XML decode > JSON decode error', self::ERR_RESPONSE);
				}
				break;
		}
	}
	
	private function send(string $method, string $url, string $post, array $headers, array $options): array{
		if($this->auth_bearer){
			$headers[] = 'Authorization: Bearer '.$this->auth_bearer;
		}
		
		curl_setopt_array($this->curl, [
			CURLOPT_CUSTOMREQUEST 	=> $method,
			CURLOPT_HTTPHEADER 		=> array_merge([
				'Accept-Encoding: gzip'
			], $headers),
			CURLOPT_URL 			=> $url,
			CURLOPT_RETURNTRANSFER 	=> true,
			CURLOPT_ENCODING 		=> '',
			CURLOPT_FOLLOWLOCATION 	=> true,
			CURLOPT_HTTP_VERSION 	=> strpos($url, 'https://') === 0 ? CURL_HTTP_VERSION_2_0 : CURL_HTTP_VERSION_1_1
		]);
		
		if($this->auth){
			curl_setopt($this->curl, CURLOPT_USERPWD, $this->auth);
		}
		
		if($post){
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
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
	
	private function check_url(string $url): void{
		if(!strpos($url, '://')){
			throw new Error("Protocol missing in URL '$url'", self::ERR_NETWORK);
		}
	}
	
	private function get_content_type(): string{
		$type 	= curl_getinfo($this->curl, CURLINFO_CONTENT_TYPE);
		$pos 	= strpos($type, ';');
		
		return $pos ? substr($type, 0, $pos) : $type;
	}
}

class Error extends \Error {}