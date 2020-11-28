<?php

namespace Utils\Cmd;

class Cmd {
	private $output 		= '';
	
	private $is_stream 		= false;
	private $proc;
	private $pipes 			= [];
	
	private $pid;
	private $exitcode 		= -1;
	private $termsig 		= false;
	
	const PIPE_STDIN 		= 0;
	const PIPE_STDOUT 		= 1;
	const PIPE_STDERR 		= 2;
	
	public function __construct(bool $is_stream=false){
		$this->is_stream = $is_stream;
	}
	
	public function output(bool $trim=false): string{
		return $trim ? trim($this->output) : $this->output;
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
		else{
			$this->termsig = $status['termsig'] == 9;
			
			if($this->exitcode < 0){
				$this->exitcode = $status['exitcode'];
			}
			
			return false;
		}
	}
	
	public function get_pipe_stream(int $pipe): string{
		$output = '';
		$handle = $this->pipes[$pipe];
		
		$arr = [$handle];
		$lulz1 = [];
		$lulz2 = [];
		
		while(true){
			if(stream_select($arr, $lulz1, $lulz2, 0, 100000) < 1){
				break;
			}
			
			$time_start = microtime(true);
			$new 		= stream_get_contents($handle, 1);
			$time_used 	= microtime(true) - $time_start;
			
			if(!is_string($new) || !strlen($new)){
				break;
			}
			
			$output .= $new;
			
			if($time_used > 0.1){
				break;
			}
		}
		
		return $output;
	}
	
	public function exec(string $command, bool $trim=false){
		$this->proc = proc_open($command, [
			self::PIPE_STDIN 	=> ['pipe', 'r'],
			self::PIPE_STDOUT 	=> ['pipe', 'w'],
			self::PIPE_STDERR 	=> ['pipe', 'w']
		], $this->pipes);
		fclose($this->pipes[0]);
		
		if($this->is_stream){
			stream_set_read_buffer($this->pipes[self::PIPE_STDOUT], 0);
			stream_set_read_buffer($this->pipes[self::PIPE_STDERR], 0);
			
			stream_set_blocking($this->pipes[self::PIPE_STDOUT], false);
			stream_set_blocking($this->pipes[self::PIPE_STDERR], false);
		}
		else{
			$this->output 	= stream_get_contents($this->pipes[self::PIPE_STDOUT]);
			$stderr 		= stream_get_contents($this->pipes[self::PIPE_STDERR]);
			$this->close();
			
			return $trim ? trim($stderr) : $stderr;
		}
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