<?php

namespace Utils\Procs_queue;

require_once 'Cmd.php';

use \Utils\Cmd\Cmd;

class Procs_queue {
	private $procs = [];
	private $nproc;
	
	public function __construct(){
		$this->nproc = (int)shell_exec('nproc');
		$this->nproc++;
	}
	
	public function put(string $command){
		$this->get_streams();
		
		$proc = new Cmd(true);
		$proc->exec($command);
		
	}
	
	private function get_streams(){
		foreach($this->procs as $p => $proc){
			
		}
	}
}

class Error extends \Error {}