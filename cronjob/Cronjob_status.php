<?php

namespace Utils\Cronjob;

class Cronjob_status {
	public function scan(string $task_name){
		$procs = [];
		
		$output = trim(shell_exec('ps --noheader -o pid,ppid,cmd -C php | grep "cronjob\.php '.$task_name.'\b"'));
		
		foreach(array_filter(explode("\n", $output)) as $proc){
			$pid 	= (int)$proc;
			$ppid 	= (int)substr($proc, strpos($proc, ' '));
			
			$cmd = [
				'pid'	=> $pid,
				'ppid'	=> $ppid,
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php')),
				'pcmd'	=> trim(shell_exec('ps --noheader -p '.$ppid.' -o cmd'))
			] + (new \Utils\Cmd\Proc)->stat($pid);
			
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