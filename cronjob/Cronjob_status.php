<?php

namespace Utils\Cronjob;

class Cronjob_status extends \Utils\cmd\Cmd {
	const PROCSTAT_UTIME 		= 13;
	const PROCSTAT_STIME 		= 14;
	const PROCSTAT_CUTIME 		= 15;
	const PROCSTAT_CSTIME 		= 16;
	const PROCSTAT_STARTTIME 	= 21;
	const PROCSTAT_RSS 			= 23;
	
	public function scan(string $task_name){
		$procs = [];
		
		$this->exec("ps --noheader o pid,ppid,cmd -C php | grep 'cronjob.php $task_name'");
		
		foreach(array_filter(explode("\n", $this->output(true))) as $proc){
			$pid = (int)$proc;
			
			$pid_stat = $this->pid_stat($pid);
			
			$cmd = [
				'pid'	=> $pid,
				'ppid'	=> (int)substr($proc, strpos($proc, ' ')),
				'cmd'	=> substr($proc, strpos($proc, 'cronjob.php')),
				'cpu' 	=> $pid_stat['cpu'],
				'mem' 	=> $pid_stat['mem']
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
	
	private function pid_stat(int $pid): array{
		$apc_tck 	= 'SYS_CLK_TCK';
		$apc_page 	= 'SYS_PAGESIZE';
		
		$cache_timeout = 60*60*24;
		
		if(!$hertz = apcu_fetch($apc_tck)){
			$hertz = (int)shell_exec('getconf CLK_TCK');
			apcu_store($apc_tck, $hertz, $cache_timeout);
		}
		
		if(!$pagesize = apcu_fetch($apc_page)){
			$pagesize = (int)shell_exec('getconf PAGESIZE') / 1024;
			apcu_store($apc_page, $pagesize, $cache_timeout);
		}
		
		$uptime = explode(' ', shell_exec('cat /proc/uptime'))[0];
		
		if(!$procstat = explode(' ', shell_exec('cat /proc/'.$pid.'/stat'))){
			return [
				'cpu' => '0%',
				'mem' => '0M'
			];
		}
		
		$cputime 	= $procstat[self::PROCSTAT_UTIME] + $procstat[self::PROCSTAT_STIME] + $procstat[self::PROCSTAT_CUTIME] + $procstat[self::PROCSTAT_CSTIME];
		$starttime 	= $procstat[self::PROCSTAT_STARTTIME];
		
		$seconds 	= $uptime - ($starttime / $hertz);
		
		return [
			'cpu' => round(($cputime / $hertz / $seconds) * 100, 1).'%',
			'mem' => round($procstat[self::PROCSTAT_RSS] * $pagesize / 1024, 2).'M'
		];
	}
}