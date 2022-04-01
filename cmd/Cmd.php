<?php

namespace Utils\Cmd;

class Cmd extends Proc {
	private $output 		= '';
	
	private $is_stream 		= false;
	private $use_stdin 		= false;
	
	protected $pipes 		= [];
	
	private $exitcode 		= -1;
	private $termsig 		= false;
	
	const PIPE_STDIN 		= 0;
	const PIPE_STDOUT 		= 1;
	const PIPE_STDERR 		= 2;
	
	const SIGKILL 			= 9;
	const SIGTERM 			= 15;
	
	public function __construct(bool $is_stream=false, bool $use_stdin=false){
		$this->is_stream = $is_stream;
		$this->use_stdin = $use_stdin;
	}
	
	public function output(bool $trim=false, bool $stream_wait=false): string{
		//	Blocking call: Return stdout
		if(!$this->is_stream){
			return $trim ? trim($this->output) : $this->output;
		}
		
		//	Non-blocking call: Wait until stdout returned data
		if($stream_wait){
			while(true){
				if($output = stream_get_contents($this->pipes[self::PIPE_STDOUT])){
					return $trim ? trim($output) : $output;
				}
			}
		}
	}
	
	public function input(string $data){
		fwrite($this->pipes[self::PIPE_STDIN], $data);
	}
	
	public function is_success(): bool{
		return !$this->exitcode;
	}
	
	public function is_terminated(): bool{
		return $this->termsig;
	}
	
	public function get_pid(): int{
		return $this->pid ?: proc_get_status($this->proc)['pid'];
	}
	
	public function get_exitcode(): int{
		return $this->exitcode;
	}
	
	public function is_running(): bool{
		$status 	= proc_get_status($this->proc);
		$this->pid 	= $status['pid'];
		
		if($status['running']){
			return true;
		}
		
		$this->termsig 	= $status['termsig'] == self::SIGKILL || $status['termsig'] == self::SIGTERM;
		$this->exitcode = $status['termsig'];
		
		return false;
	}
	
	public function get_pipe_stream(int $pipe=self::PIPE_STDOUT): string{
		return stream_get_contents($this->pipes[$pipe]);
	}
	
	public function exec(string $command, bool $trim=false){
		$this->proc = proc_open($command, [
			self::PIPE_STDIN 	=> ['pipe', 'r'],
			self::PIPE_STDOUT 	=> ['pipe', 'w'],
			self::PIPE_STDERR 	=> ['pipe', 'w']
		], $this->pipes);
		
		if(!$this->use_stdin){
			fclose($this->pipes[self::PIPE_STDIN]);
		}
		
		//	Blocking call: Return stderr
		if(!$this->is_stream){
			$this->output 	= stream_get_contents($this->pipes[self::PIPE_STDOUT]);
			$stderr 		= stream_get_contents($this->pipes[self::PIPE_STDERR]);
			$this->close();
			
			return $trim ? trim($stderr) : $stderr;
		}
		
		stream_set_read_buffer($this->pipes[self::PIPE_STDOUT], 0);
		stream_set_read_buffer($this->pipes[self::PIPE_STDERR], 0);
		
		stream_set_blocking($this->pipes[self::PIPE_STDOUT], false);
		stream_set_blocking($this->pipes[self::PIPE_STDERR], false);
	}
	
	public function close(){
		foreach($this->pipes as $pipe){
			if(is_resource($pipe)){
				fclose($pipe);
			}
		}
		
		$this->exitcode = proc_close($this->proc);
	}
}

class Error extends \Error {}