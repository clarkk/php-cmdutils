<?php

namespace Utils\Cmd;

class Proc {
	protected $pid;
	protected $proc;
	
	const PROCSTAT_UTIME 		= 13;
	const PROCSTAT_STIME 		= 14;
	const PROCSTAT_CUTIME 		= 15;
	const PROCSTAT_CSTIME 		= 16;
	const PROCSTAT_PRIORITY 	= 17;
	const PROCSTAT_STARTTIME 	= 21;
	const PROCSTAT_RSS 			= 23;
	
	const APC_CACHE 			= 60*60*24;
	
	static public function proc_name(string $name, string $filter=''): array{
		if(!$output = rtrim(shell_exec('ps --noheader -o pid,ppid,cmd -C '.$name.($filter ? ' | grep "'.$filter.'"' : '')))){
			return [];
		}
		
		$procs = explode("\n", preg_replace('/ +/', ' ', $output));
		foreach($procs as &$proc){
			$proc 		= trim($proc);
			$pid 		= (int)$proc;
			$pid_len 	= strlen($pid)+1;
			$ppid 		= (int)substr($proc, $pid_len);
			
			$proc = [
				'pid'	=> $pid,
				'ppid'	=> $ppid,
				'cmd'	=> substr($proc, $pid_len+strlen($ppid)+1)
			];
		}
		
		return $procs;
	}
	
	static public function proc_cmd(int $pid): string{
		return rtrim(str_replace("\x00", ' ', file_get_contents("/proc/$pid/cmdline") ?: ''));
	}
	
	static public function proc_stat(int $pid): array{
		return explode(' ', file_get_contents("/proc/$pid/stat") ?: '');
	}
	
	/*static public function get_nice(int $pid): int{
		if($nice = self::proc_stat($pid)[self::PROCSTAT_PRIORITY] ?? 0){
			return $nice - 20;
		}
		
		return 0;
	}*/
	
	static public function stat(int $pid): array{
		if(!$procstat = self::proc_stat($pid)){
			return [
				'cpu'	=> '0%',
				'mem'	=> '0M',
				'start'	=> 0,
				'time'	=> 0
			];
		}
		
		$hertz 			= self::getconf('CLK_TCK');
		$pagesize_kb 	= self::getconf('PAGESIZE') / 1024;
		
		$uptime 	= explode(' ', file_get_contents('/proc/uptime'))[0];
		$cputime 	= $procstat[self::PROCSTAT_UTIME] + $procstat[self::PROCSTAT_STIME] + $procstat[self::PROCSTAT_CUTIME] + $procstat[self::PROCSTAT_CSTIME];
		$starttime 	= $procstat[self::PROCSTAT_STARTTIME];
		$seconds 	= $uptime - ($starttime / $hertz);
		
		return [
			'cpu'	=> round(($cputime / $hertz / $seconds) * 100, 1).'%',
			'mem'	=> round($procstat[self::PROCSTAT_RSS] * $pagesize_kb / 1024, 2).'M',
			'start'	=> (int)(time() - ($starttime / $hertz)),
			'time'	=> (int)$seconds
		];
	}
	
	static private function getconf(string $value): int{
		$apc_key = "SYS_$value";
		if(!$output = apcu_fetch($apc_key)){
			$output = (int)shell_exec("getconf $value");
			apcu_store($apc_key, $output, self::APC_CACHE);
		}
		
		return $output;
	}
}