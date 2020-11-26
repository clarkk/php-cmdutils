<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';

use \Utils\Cmd\Cmd;

class Procs_queue {
	private $procs 		= [];
	private $nproc;
	
	private $verbose 	= false;
	
	const COLOR_GRAY 	= '1;30';
	const COLOR_GREEN 	= '0;32';
	const COLOR_YELLOW 	= '1;33';
	const COLOR_RED 	= '0;31';
	const COLOR_PURPLE 	= '0;35';
	
	const VERBOSE_PLAIN = 1;
	const VERBOSE_COLOR = 2;
	
	public function __construct(int $verbose=0){
		$this->nproc 	= (int)shell_exec('nproc');
		$this->verbose 	= $verbose;
		
		$this->nproc++;
	}
	
	public function put(string $command){
		if($this->free_proc_slots()){
			$proc = new Cmd(true);
			$proc->exec($command);
			
			$pid = $proc->get_pid();
			
			if($this->verbose){
				$this->verbose("Process start (pid: $pid)", self::COLOR_GREEN);
			}
		}
		
		$this->get_streams();
	}
	
	private function free_proc_slots(): bool{
		return count($this->procs) < $this->nproc;
	}
	
	private function get_streams(){
		foreach($this->procs as $pid => $proc){
			
		}
	}
	
	private function verbose(string $string, string $color){
		if($this->verbose == self::VERBOSE_COLOR){
			$string = "\033[".$color.'m'.$string."\033[0m";
		}
		
		echo "$string\n";
	}
}

class Error extends \Error {}