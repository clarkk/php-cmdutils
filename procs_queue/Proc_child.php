<?php

namespace Utils\Procs_queue;

abstract class Proc_child {
	protected $verbose = false;
	
	public function __construct(int $verbose){
		$this->verbose = (bool)$verbose;
	}
	
	protected function max_execution_time(int $max_execution_minutes=2){
		ini_set('max_execution_time', 60 * $max_execution_minutes);
	}
	
	protected function output(string $tmp, array $data){
		file_put_contents($tmp.'/'.Procs_queue::OUTPUT_FILE, json_encode($data));
	}
}