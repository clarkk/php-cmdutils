<?php

namespace Utils\Cronjob;

class Cronjob_status extends \Utils\cmd\Cmd {
	public function scan(string $task_name){
		$procs = [];
		
		$this->exec("ps --noheader o pid,ppid,cmd -C php | grep 'cronjob.php $task_name'");
		
		foreach(array_filter(explode("\n", $this->output(true))) as $proc){
			$cmd = [
				'pid'	=> (int)$proc,
				'ppid'	=> (int)substr($proc, strpos($proc, ' ')),
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php')),
				'cpu' 	=> 0,
				'mem' 	=> 0
			];
			
			if(strpos($proc, ' -process=')){
				$procs[] = $cmd;
			}
			else{
				array_unshift($procs, $cmd);
			}
			
		}
		
		return $procs;
	}
}