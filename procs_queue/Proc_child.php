<?php

namespace Utils\Procs_queue;

abstract class Proc_child {
	protected bool $verbose = false;
	
	public function __construct(int $verbose){
		$this->verbose = (bool)$verbose;
	}
	
	protected function output(string $tmp, array $data): void{
		file_put_contents($tmp.'/'.Procs_queue::OUTPUT_FILE, json_encode($data));
	}
}