<?php

namespace Utils\SSH;

require_once 'Net_error_codes.php';

class SSH extends \Utils\Net\Net_error_codes {
	private $output 		= '';
	
	private $session;
	
	const RSA_PRIVATE 		= '/var/www/.ssh/id_rsa';
	const RSA_PUBLIC 		= '/var/www/.ssh/id_rsa.pub';
	
	public function __construct(string $user, string $host){
		if(!is_readable(self::RSA_PRIVATE) || !is_readable(self::RSA_PUBLIC)){
			throw new SSH_error('RSA keys not found', self::ERR_INIT);
		}
		
		if(!$this->session = ssh2_connect($host)){
			throw new SSH_error("Could not connect to '$host'", self::ERR_NETWORK);
		}
		
		if(!ssh2_auth_pubkey_file($this->session, $user, self::RSA_PUBLIC, self::RSA_PRIVATE)){
			throw new SSH_error("Could not authenticate to '$host'", self::ERR_AUTH);
		}
	}
	
	public function output(): string{
		return $this->output;
	}
	
	public function exec(string $command, bool $is_stream=false){
		$stream = ssh2_exec($this->session, $command);
		$pipe_stdout = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
		$pipe_stderr = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
		
		// 'sh -c \'echo $$; echo $PPID; nproc\''
		
		if($is_stream){
			//stream_set_read_buffer($pipe_stdout, 0);
			//stream_set_read_buffer($pipe_stderr, 0);
			
			//stream_set_blocking($pipe_stdout, false);
			//stream_set_blocking($pipe_stderr, false);
		}
		else{
			stream_set_blocking($pipe_stdout, true);
			stream_set_blocking($pipe_stderr, true);
			
			$this->output = stream_get_contents($pipe_stdout);
			
			return stream_get_contents($pipe_stderr);
		}
	}
	
	//$sftp = ssh2_sftp($this->session);
	//stream_copy_to_stream(fopen("/root/test.pdf", 'r'), fopen("ssh2.sftp://$sftp/root/test.pdf", 'w'));
	
	public function disconnect(){
		ssh2_disconnect($this->session);
	}
}

class SSH_error extends \Error {}