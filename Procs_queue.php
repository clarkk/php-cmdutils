<?php

namespace Utils\Procs_queue;

class Procs_queue {
	private $procs = [];
	
	public function __construct(){
		$nproc = (int)shell_exec('nproc');
		echo "nproc: $nproc\n";
	}
	
	
}

class Error extends \Error {}