<?php

namespace Utils\Procs_queue;

use \Utils\SSH\SSH_error;

class Worker_init extends \Utils\SSH\SSH {
	public function get_nproc(): int{
		$this->exec('nproc');
		
		if(!$nproc = (int)$this->output()){
			throw new SSH_error('Could not determine nproc', self::ERR_PROCESS);
		}
		
		return $nproc;
	}
	
	public function check_tmpdir(string $tmp_dir){
		$this->exec("test -d '$tmp_dir' && echo 'OK'");
		if(trim($this->output()) != 'OK'){
			throw new SSH_error("tmpdir not found: $tmp_dir", self::ERR_PROCESS);
		}
		
		$this->exec("mountpoint -q '$tmp_dir' || mount -t tmpfs -o size=512m tmpfs '$tmp_dir' && echo 'OK'");
		if(trim($this->output()) != 'OK'){
			throw new SSH_error("tmpfs could not be mounted: $tmp_dir", self::ERR_PROCESS);
		}
	}
}