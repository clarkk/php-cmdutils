<?php

namespace Utils\Net\Oauth2;

// https://www.tutorialspoint.com/oauth2.0/oauth2.0_iana_considerations.htm

class Oauth2 extends \Utils\Net\Net {
	const PREFIX_TOKEN_CACHE 	= 'OAUTH_TOKEN_';
	
	const TOKEN_CLIENT_ID 		= 'client_id';
	const TOKEN_CLIENT_SECRET 	= 'client_secret';
	const TOKEN_GRANT_TYPE 		= 'grant_type';
	const TOKEN_SCOPE 			= 'scope';
	
	private $token;
	
	private $token_name;
	private $token_url;
	private $token_params;
	
	public function token_request(string $token_name, string $url, array $params, bool $force_token=false){
		$this->token_name 	= $token_name;
		$this->token_url 	= $url;
		$this->token_params = $params;
		
		$apc_key = self::PREFIX_TOKEN_CACHE.$token_name;
		if($force_token || !$this->token = apcu_fetch($apc_key)){
			$expires_in = $this->fetch_token($url, $params);
			apcu_store($apc_key, $this->token, $expires_in);
		}
	}
	
	public function auth_request(string $url, string $post='', array $headers=[], array $return_codes=[200,404]): array{
		$headers[] = 'Authorization: '.$this->token;
		
		$response = parent::request($url, $post, $headers);
		
		if(in_array($response['code'], $return_codes)){
			return $response;
		}
		else{
			$this->token_request($this->token_name, $this->token_url, $this->token_params, true);
			
			return parent::request($url, $post, $headers);
		}
	}
	
	private function fetch_token(string $url, array $params): int{
		$this->keep_alive();
		$this->decode_type();
		$response = parent::request($url, http_build_query($params), [
			self::CONTENT_TYPE.': '.self::CONTENT_TYPE_FORM
		]);
		
		$this->token = $response['response']['token_type'].' '.$response['response']['access_token'];
		
		return $response['response']['expires_in'];
	}
}