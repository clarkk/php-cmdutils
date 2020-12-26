<?php

namespace Utils\Cronjob;

class Cronjob_status extends \Utils\cmd\Cmd {
	const PROCSTAT_UTIME 		= 13;
	const PROCSTAT_STIME 		= 14;
	const PROCSTAT_CUTIME 		= 15;
	const PROCSTAT_CSTIME 		= 16;
	const PROCSTAT_STARTTIME 	= 21;
	
	public function scan(string $task_name){
		$procs = [];
		
		$this->exec("ps --noheader o pid,ppid,cmd -C php | grep 'cronjob.php $task_name'");
		
		foreach(array_filter(explode("\n", $this->output(true))) as $proc){
			$pid = (int)$proc;
			
			$cmd = [
				'pid'	=> $pid,
				'ppid'	=> (int)substr($proc, strpos($proc, ' ')),
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php')),
				'cpu' 	=> $this->cpu_usage($pid).'%',
				'mem' 	=> $this->mem_usage($pid)
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
	
	private function mem_usage(int $pid): string{
		return trim(substr(shell_exec('pmap '.$pid.' | tail -1 | grep total'), 8));
	}
	
	private function cpu_usage(int $pid): float{
		$uptime 	= explode(' ', shell_exec('cat /proc/uptime'))[0];
		$procstat 	= explode(' ', shell_exec('cat /proc/'.$pid.'/stat'));
		$hertz 		= (int)shell_exec('getconf CLK_TCK');
		
		$cputime 	= $procstat[self::PROCSTAT_UTIME] + $procstat[self::PROCSTAT_STIME] + $procstat[self::PROCSTAT_CUTIME] + $procstat[self::PROCSTAT_CSTIME];
		$starttime 	= $procstat[self::PROCSTAT_STARTTIME];
		
		$seconds 	= $uptime - ($starttime / $hertz);
		
		return round(($cputime / $hertz / $seconds) * 100, 1);
	}
}