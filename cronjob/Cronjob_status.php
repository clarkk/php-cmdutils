<?php

namespace Utils\Cronjob;

class Cronjob_status extends \Utils\cmd\Cmd {
	public function scan(string $task_name){
		$procs = [];
		
		$this->exec("ps --noheader o pid,ppid,cmd -C php | grep 'cronjob.php $task_name'");
		
		foreach(array_filter(explode("\n", $this->output(true))) as $proc){
			$pid = (int)$proc;
			
			$cmd = [
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php')),
				'ppid'	=> (int)substr($proc, strpos($proc, ' '))
			];
			
			if(strpos($proc, ' -process=')){
				$procs[$pid] = $cmd;
			}
			else{
				$procs = [$pid => $cmd] + $procs;
			}
			
		}
		
		return $procs;
	}
}