<?php

namespace Utils\Procs_queue;

class Worker_init extends \Utils\SSH\SSH {
	public function get_nproc(): int{
		$this->exec('nproc');
		
		if(!$nproc = (int)$this->output()){
			throw new \Utils\SSH\Error('Could not determine nproc', self::ERR_PROCESS);
		}
		
		return $nproc;
	}
	
	public function check_proc_path(string $proc_path): void{
		if(!$this->check_path($proc_path, true)){
			throw new \Utils\SSH\Error("proc path not found: $proc_path", self::ERR_PROCESS);
		}
	}
	
	public function check_tmp_path(string $tmp_path): void{
		if(!$this->check_path($tmp_path)){
			throw new \Utils\SSH\Error("tmp path not found: $tmp_path", self::ERR_PROCESS);
		}
	}
	
	private function check_path(string $path, bool $is_file=false): bool{
		$this->exec('test '.($is_file ? '-f' : '-d')." '$path' && echo 'OK'");
		
		return $this->output(true) == 'OK';
	}
}