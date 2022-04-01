<?php

namespace Utils\Cmd;

trait Cmd_common {
	private $is_stream 		= false;
	private $output 		= '';
	private $pipes 			= [];
	
	public function output(bool $trim=false, bool $stream_wait=false): string{
		//	Non-blocking call
		if($this->is_stream){
			//	Wait until stdout returns data
			if($stream_wait){
				while(true){
					if($output = stream_get_contents($this->pipes[self::PIPE_STDOUT])){
						return $trim ? trim($output) : $output;
					}
				}
			}
			
			$output = stream_get_contents($this->pipes[self::PIPE_STDOUT]);
			
			return $trim ? trim($output) : $output;
		}
		
		//	Blocking call: Return stdout
		return $trim ? trim($this->output) : $this->output;
	}
	
	public function get_pipe_stream(int $pipe=self::PIPE_STDOUT): string{
		return stream_get_contents($this->pipes[$pipe]);
	}
}