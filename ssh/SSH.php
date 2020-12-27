<?php

namespace Utils\SSH;

class SSH extends \Utils\Net\Net_error_codes {
	private $output 		= '';
	
	private $is_stream 		= false;
	private $pipes 			= [];
	
	private $session;
	private $sftp;
	
	private $time_last_exec;
	
	const PIPE_STDOUT 		= SSH2_STREAM_STDIO;
	const PIPE_STDERR 		= SSH2_STREAM_STDERR;
	
	const RSA_PRIVATE 		= '/var/ini/.ssh/id_rsa';
	const RSA_PUBLIC 		= '/var/ini/.ssh/id_rsa.pub';
	
	public function __construct(string $user, string $host, bool $is_stream=false){
		$this->is_stream = $is_stream;
		if(!is_readable(self::RSA_PRIVATE) || !is_readable(self::RSA_PUBLIC)){
			throw new SSH_error('RSA keys not found', self::ERR_INIT);
		}
		
		if(!$this->session = ssh2_connect($host)){
			throw new SSH_error("Could not connect to '$host'", self::ERR_NETWORK);
		}
		
		if(!ssh2_auth_pubkey_file($this->session, $user, self::RSA_PUBLIC, self::RSA_PRIVATE)){
			throw new SSH_error("Could not authenticate to '$host'", self::ERR_AUTH);
		}
		
		$this->time_last_exec = time();
	}
	
	public function get_idle_time(): int{
		return time() - $this->time_last_exec;
	}
	
	public function output(bool $trim=false, bool $stream_wait=false): string{
		if($stream_wait){
			while(true){
				if($output = stream_get_contents($this->pipes[self::PIPE_STDOUT])){
					return $trim ? trim($output) : $output;
				}
			}
		}
		else{
			return $trim ? trim($this->output) : $this->output;
		}
	}
	
	public function get_pipe_stream(int $pipe): string{
		return stream_get_contents($this->pipes[$pipe]);
	}
	
	public function exec(string $command, bool $trim=false){
		$stream = ssh2_exec($this->session, $command);
		
		$this->pipes[self::PIPE_STDOUT] = ssh2_fetch_stream($stream, self::PIPE_STDOUT);
		$this->pipes[self::PIPE_STDERR] = ssh2_fetch_stream($stream, self::PIPE_STDERR);
		
		$this->time_last_exec = time();
		
		if($this->is_stream){
			stream_set_read_buffer($this->pipes[self::PIPE_STDOUT], 0);
			stream_set_read_buffer($this->pipes[self::PIPE_STDERR], 0);
		}
		else{
			stream_set_blocking($this->pipes[self::PIPE_STDOUT], true);
			stream_set_blocking($this->pipes[self::PIPE_STDERR], true);
			
			$this->output 	= stream_get_contents($this->pipes[self::PIPE_STDOUT]);
			$stderr 		= stream_get_contents($this->pipes[self::PIPE_STDERR]);
			
			return $trim ? trim($stderr) : $stderr;
		}
	}
	
	//	Issue when using SFTP via SSH2 results in segmentation fault
	public function upload(string $local, string $remote){
		if(!$this->sftp){
			$this->sftp = ssh2_sftp($this->session);
		}
		
		stream_copy_to_stream(fopen($local, 'r'), fopen('ssh2.sftp://'.intval($this->sftp).$remote, 'w'));
	}
	
	public function disconnect(){
		if($this->sftp){
			unset($this->sftp);
		}
		
		//ssh2_disconnect($this->session);
		$this->session = null;
		unset($this->session);
	}
}

class SSH_error extends \Error {}