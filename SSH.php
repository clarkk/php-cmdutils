<?php

namespace Utils\SSH;

class SSH {
	private $session;
	
	public function __construct(string $user, string $host){
		if(!$this->session = ssh2_connect($host)){
			throw new Error();
		}
		
		ssh2_auth_pubkey_file($this->session, $user, '/var/www/.ssh/id_rsa.pub', '/var/www/.ssh/id_rsa');
	}
}

class Error extends \Error {}